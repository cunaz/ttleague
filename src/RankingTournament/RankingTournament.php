<?php
/**
 * Einstieg des Moduls Ranglistenturnier in WordPress.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\RankingTournament;

use TTLeague\Access\Capabilities;
use TTLeague\Accounts\Services;
use TTLeague\Frontend\Frontend;
use TTLeague\Notifications\EventTypes;
use TTLeague\Notifications\Notifications;

/**
 * Hängt nur Hooks ein: Backend-Seite «Spielbetrieb → Ranglistenturnier», Abschnitt in «Mein
 * Bereich», Seite und REST-Routen für den Gruppenzettel, Benachrichtigungstypen, Exporte, den
 * Versand der Aufgebote über WP-Cron und die Erinnerungen im stündlichen Fristenlauf. Die Regeln
 * stehen in den Diensten Registrations, Rounds und GroupEntry.
 */
final class RankingTournament {

	/** Seite im Backend. */
	public const PAGE = 'ttleague-tk-ranglistenturnier';

	/** Abfrageparameter der Seite «Resultat erfassen» für den Gruppenzettel. */
	public const PARAM = 'rl-gruppe';

	/** Einmaliges Cron-Ereignis: ein Schritt des Versands der Aufgebote (Runde, Person-ID der Fortsetzung). */
	public const CALLUP_HOOK = 'ttleague_rl_callups';

	public static function register(): void {
		add_filter( 'ttleague_tk_pages', array( self::class, 'pages' ) );
		add_filter( 'ttleague_exports', array( Exports::class, 'lists' ) );
		add_filter( 'ttleague_notification_types', array( self::class, 'notification_types' ) );
		add_action( 'rest_api_init', array( RestController::class, 'register_routes' ) );
		// Erinnerungen laufen im stündlichen Fristenlauf der Benachrichtigungen mit, ohne eigenen Cron.
		add_action( Notifications::CRON_HOOK, array( self::class, 'run_reminders' ), 20 );
		add_action( Notifications::CRON_HOOK, array( self::class, 'catch_up_callups' ), 20 );
		add_action( self::CALLUP_HOOK, array( self::class, 'send_callups' ), 10, 2 );
		if ( defined( 'TTLEAGUE_FILE' ) ) {
			register_deactivation_hook( TTLEAGUE_FILE, array( self::class, 'deactivate' ) );
		}
		AdminActions::register();
		MyArea::register();
		EntryPage::register();
	}

	/**
	 * Filter ttleague_tk_pages.
	 *
	 * @param mixed $pages Seiten.
	 * @return list<array<string,mixed>>
	 */
	public static function pages( mixed $pages ): array {
		$pages   = is_array( $pages ) ? array_values( $pages ) : array();
		$pages[] = array(
			'slug'     => self::PAGE,
			'title'    => __( 'Ranglistenturnier', 'ttleague' ),
			'render'   => array( AdminPage::class, 'render' ),
			'position' => 40,
		);
		return $pages;
	}

	/**
	 * Filter ttleague_notification_types.
	 *
	 * @param mixed $types Typ => Bezeichnung.
	 * @return array<string,string>
	 */
	public static function notification_types( mixed $types ): array {
		$types                            = is_array( $types ) ? $types : array();
		$types[ EventTypes::RL_CALLUP ]   = __( 'Ranglistenturnier: Aufgebot und Änderungen', 'ttleague' );
		$types[ EventTypes::RL_REMINDER ] = __( 'Ranglistenturnier: Erinnerung an Anmeldeschluss und Sperrdaten', 'ttleague' );
		return $types;
	}

	public static function events(): Events {
		return new WordPressEvents();
	}

	public static function registrations(): Registrations {
		return new Registrations( Services::db(), Capabilities::policy(), Services::clock(), wp_timezone(), Services::audit(), self::events() );
	}

	public static function rounds(): Rounds {
		return new Rounds( Services::db(), Services::clock(), Services::audit(), self::events() );
	}

	public static function entry(): GroupEntry {
		return new GroupEntry(
			Services::db(),
			Capabilities::policy(),
			Services::clock(),
			Services::audit(),
			self::events(),
			static function ( int $user_id ): ?string {
				$user = get_userdata( $user_id );
				return false === $user ? null : $user->display_name;
			}
		);
	}

	public static function repository(): Repository {
		return new Repository( Services::db() );
	}

	public static function notices(): Notices {
		return new Notices(
			Services::db(),
			Services::clock(),
			wp_timezone(),
			Notifications::recipients(),
			Notifications::notifier(),
			new WordPressLinks(),
			array( Notifications::class, 'report_error' )
		);
	}

	public static function deactivate(): void {
		wp_unschedule_hook( self::CALLUP_HOOK );
	}

	/**
	 * Versand der Aufgebote einer Runde vormerken. Freigabe und Änderung einer Gruppe versenden
	 * nichts selbst: Bei über 60 Aufgebotenen und langsamem Versand scheiterte die Anfrage der TK
	 * sonst am Zeitlimit. Das Ereignis ist sofort fällig; WP-Cron startet es mit der nächsten
	 * Anfrage (schon mit der Weiterleitung nach dem Formular), mit DISABLE_WP_CRON der Cron des
	 * Servers. Geht es verloren, plant der stündliche Lauf nach (catch_up_callups()).
	 *
	 * Wartet schon ein Schritt mit denselben Angaben, lehnt WordPress den zweiten als Doppel ab.
	 * Das ist kein Fehler: Der wartende Schritt rechnet beim Lauf, wem ein Aufgebot fehlt.
	 * Jedes andere Scheitern wird gemeldet (ttleague_notification_error, Fehlerprotokoll).
	 *
	 * @param int $after_person Person, nach der der Schritt weitermacht (0: von vorn).
	 */
	public static function schedule_callups( int $round_id, int $after_person = 0 ): void {
		$result = wp_schedule_single_event( time(), self::CALLUP_HOOK, array( $round_id, $after_person ), true );
		if ( true === $result || ( is_wp_error( $result ) && 'duplicate_event' === $result->get_error_code() ) ) {
			return;
		}
		$reason = is_wp_error( $result ) ? $result->get_error_message() : 'wp_schedule_single_event';
		Notifications::report_error( new \RuntimeException( 'Der Versand der Aufgebote liess sich nicht einplanen: ' . $reason ), self::CALLUP_HOOK . ' Runde ' . $round_id );
	}

	/**
	 * Cron: ein Schritt des Versands der Aufgebote. Bleibt ein Rest, plant sich der Schritt für
	 * die Personen nach der zuletzt besuchten erneut ein.
	 *
	 * @param int|string $round_id     Runde.
	 * @param int|string $after_person Person, nach der dieser Schritt weitermacht (0: von vorn).
	 */
	public static function send_callups( $round_id, $after_person = 0 ): void {
		try {
			$result = self::notices()->send_callups( (int) $round_id, (int) $after_person );
			if ( null !== $result['next'] ) {
				self::schedule_callups( (int) $round_id, $result['next'] );
			}
		} catch ( \Throwable $e ) {
			Notifications::report_error( $e, Notices::CALLUP );
		}
	}

	/**
	 * Stündlicher Lauf der Benachrichtigungen: versendet selbst nichts, sondern plant für jede
	 * freigegebene Runde mit ausstehenden Aufgeboten einen Schritt ein, falls keiner wartet.
	 * Fängt ein verlorenes Ereignis, einen abgebrochenen Schritt und gescheiterte Zustellungen auf.
	 */
	public static function catch_up_callups(): void {
		try {
			foreach ( self::notices()->rounds_with_pending_callups() as $round_id ) {
				if ( ! self::callups_waiting( $round_id ) ) {
					self::schedule_callups( $round_id );
				}
			}
		} catch ( \Throwable $e ) {
			Notifications::report_error( $e, Notices::CALLUP );
		}
	}

	/**
	 * Wartet für die Runde schon ein Schritt des Versands, gleich mit welcher Fortsetzung?
	 */
	public static function callups_waiting( int $round_id ): bool {
		foreach ( (array) _get_cron_array() as $hooks ) {
			foreach ( is_array( $hooks ) ? ( $hooks[ self::CALLUP_HOOK ] ?? array() ) : array() as $event ) {
				if ( (int) ( $event['args'][0] ?? 0 ) === $round_id ) {
					return true;
				}
			}
		}
		return false;
	}

	/**
	 * Adresse der Seite zum Nachtragen eines Gruppenzettels: die Seite «Resultat erfassen» mit
	 * der Nummer der Gruppe.
	 */
	public static function entry_url( int $group_id ): string {
		return add_query_arg( self::PARAM, (string) $group_id, Frontend::urls()->page( 'entry' ) );
	}

	/**
	 * Cron: Erinnerungen vor Anmeldeschluss und bei fehlenden Sperrdaten. Fehler landen im
	 * Fehlerprotokoll der Benachrichtigungen und brechen den Lauf nicht ab.
	 */
	public static function run_reminders(): void {
		try {
			/** This filter is documented in src/Notifications/Notifications.php */
			$hours = (array) apply_filters(
				'ttleague_notification_hours',
				array(
					'from_hour'  => \TTLeague\Notifications\Deadlines::FROM_HOUR,
					'until_hour' => \TTLeague\Notifications\Deadlines::UNTIL_HOUR,
				)
			);
			self::notices()->reminders( (int) ( $hours['from_hour'] ?? 7 ), (int) ( $hours['until_hour'] ?? 22 ) );
		} catch ( \Throwable $e ) {
			Notifications::report_error( $e, 'rl_reminder' );
		}
	}
}
