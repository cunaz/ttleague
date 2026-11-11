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
 * Bereich», Seite und REST-Routen für den Gruppenzettel, Benachrichtigungstypen, Exporte und die
 * Erinnerungen im stündlichen Fristenlauf. Die Regeln stehen in den Diensten Registrations,
 * Rounds und GroupEntry.
 */
final class RankingTournament {

	/** Seite im Backend. */
	public const PAGE = 'ttleague-tk-ranglistenturnier';

	/** Abfrageparameter der Seite «Resultat erfassen» für den Gruppenzettel. */
	public const PARAM = 'rl-gruppe';

	public static function register(): void {
		add_filter( 'ttleague_tk_pages', array( self::class, 'pages' ) );
		add_filter( 'ttleague_exports', array( Exports::class, 'lists' ) );
		add_filter( 'ttleague_notification_types', array( self::class, 'notification_types' ) );
		add_action( 'rest_api_init', array( RestController::class, 'register_routes' ) );
		// Erinnerungen laufen im stündlichen Fristenlauf der Benachrichtigungen mit, ohne eigenen Cron.
		add_action( Notifications::CRON_HOOK, array( self::class, 'run_reminders' ), 20 );
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
			new WordPressLinks()
		);
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
