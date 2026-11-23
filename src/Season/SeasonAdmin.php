<?php
/**
 * Einstieg des Moduls «Saison anlegen, einteilen, freigeben, abschliessen» in WordPress.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Season;

use TTLeague\Accounts\Services;
use TTLeague\Notifications\EventTypes;
use TTLeague\Notifications\Notifications;
use TTLeague\Results\ResultService;

/**
 * Hängt nur Hooks ein (docs/saison.md): die Seite «Spielbetrieb → Saison», ihre Formulare an
 * admin-post.php, die Liste «Saison: nächste Schritte» in der Übersicht der TK, den
 * Benachrichtigungen zur Freigabe des Spielplans und zum Rückzug einer Mannschaft.
 *
 * Mitteilungen nach der Freigabe: Die Freigabe merkt den Versand nur vor (einmaliges
 * Cron-Ereignis). Jeder Schritt versendet höchstens einen Stapel und plant den Rest ein. Der
 * stündliche Lauf der Benachrichtigungen versendet selbst nichts; er plant nur nach, falls für
 * eine kürzlich freigegebene Saison Mitteilungen ausstehen und kein Schritt wartet.
 */
final class SeasonAdmin {

	/** Seite «Spielbetrieb → Saison». */
	public const PAGE = 'ttleague-tk-saison';

	/** Platz im Menü: gleich nach der Übersicht, vor den Meldungen (10). */
	public const POSITION = 5;

	/** Einmaliges Cron-Ereignis: ein Schritt des Versands (Saison, Person davor). */
	public const PUBLISHED_HOOK = 'ttleague_season_published_step';

	public const WITHDRAWN_HOOK = 'ttleague_team_withdrawn_step';

	public static function register(): void {
		add_filter( 'ttleague_tk_pages', array( self::class, 'pages' ) );
		add_filter( 'ttleague_tk_overview_sections', array( SeasonPage::class, 'overview_sections' ) );
		add_filter( 'ttleague_notification_types', array( self::class, 'notification_types' ) );
		add_action( 'ttleague_team_withdrawn', array( self::class, 'on_withdrawn' ) );
		add_action( self::WITHDRAWN_HOOK, array( self::class, 'send_withdrawal' ), 10, 2 );
		add_action( Notifications::CRON_HOOK, array( self::class, 'catch_up_withdrawals' ), 20 );
		add_action( 'admin_enqueue_scripts', array( SeasonPage::class, 'styles' ), 20 );
		add_action( self::PUBLISHED_HOOK, array( self::class, 'send_published' ), 10, 2 );
		add_action( Notifications::CRON_HOOK, array( self::class, 'catch_up_published' ), 20 );
		SeasonActions::register();
	}

	public static function deactivate(): void {
		wp_unschedule_hook( self::PUBLISHED_HOOK );
		wp_unschedule_hook( self::WITHDRAWN_HOOK );
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
			'title'    => __( 'Saison', 'ttleague' ),
			'render'   => array( SeasonPage::class, 'render' ),
			'position' => self::POSITION,
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
		$types                                 = is_array( $types ) ? $types : array();
		$types[ EventTypes::SEASON_PUBLISHED ] = __( 'Spielplan der neuen Saison freigegeben', 'ttleague' );
		$types[ EventTypes::TEAM_WITHDRAWN ]   = __( 'Rückzug einer gegnerischen Mannschaft', 'ttleague' );
		return $types;
	}

	public static function setup(): SeasonSetup {
		return new SeasonSetup( Services::db(), Services::audit(), new WordPressSeasonEvents() );
	}

	public static function club_setup(): ClubSetup {
		return new ClubSetup( Services::db(), Services::audit(), new WordPressSeasonEvents(), Services::users() );
	}

	public static function grouping(): Grouping {
		return new Grouping( Services::db(), Services::audit(), new WordPressSeasonEvents() );
	}

	public static function scheduling(): Scheduling {
		return new Scheduling( Services::db(), Services::audit(), new WordPressSeasonEvents() );
	}

	public static function lifecycle(): Lifecycle {
		return new Lifecycle(
			Services::db(),
			Services::audit(),
			new WordPressSeasonEvents(),
			self::scheduling(),
			// Stille Ereignisse: Der Rückzug meldet sich einmal über SeasonEvents, nicht je Begegnung.
			new ResultService( Services::db(), Services::policy(), Services::clock(), wp_timezone(), Services::audit(), new SilentResultEvents() )
		);
	}

	public static function notifications(): SeasonNotifications {
		return new SeasonNotifications(
			Services::db(),
			Notifications::recipients(),
			Notifications::notifier(),
			new SeasonMessages( new WordPressSeasonLinks() ),
			array( Notifications::class, 'report_error' )
		);
	}

	public static function on_withdrawn( int $team_id ): void {
		self::schedule_step( self::WITHDRAWN_HOOK, $team_id, 0 );
	}

	public static function send_withdrawal( int $team_id, int $after_person ): void {
		try {
			$next = self::notifications()->send_withdrawal( $team_id, $after_person );
			if ( null !== $next ) {
				self::schedule_step( self::WITHDRAWN_HOOK, $team_id, $next );
			}
		} catch ( \Throwable $e ) {
			Notifications::report_error( $e, EventTypes::TEAM_WITHDRAWN . ' ' . $team_id );
		}
	}

	public static function catch_up_withdrawals(): void {
		try {
			foreach ( self::notifications()->withdrawals_to_catch_up() as $team_id ) {
				if ( ! self::step_scheduled( self::WITHDRAWN_HOOK, $team_id ) ) {
					self::on_withdrawn( $team_id );
				}
			}
		} catch ( \Throwable $e ) {
			Notifications::report_error( $e, EventTypes::TEAM_WITHDRAWN );
		}
	}

	/**
	 * Nach der Freigabe: den Versand der Mitteilungen nur vormerken. Das Ereignis ist sofort
	 * fällig; WP-Cron startet es mit der nächsten Anfrage (schon mit der Weiterleitung nach dem
	 * Formular), mit DISABLE_WP_CRON der Cron des Servers. Geht es verloren oder lässt es sich
	 * nicht einplanen, plant der stündliche Lauf nach (catch_up_published()).
	 */
	public static function on_published( int $season_id ): void {
		self::schedule_step( self::PUBLISHED_HOOK, $season_id, 0 );
	}

	/**
	 * Cron: ein Schritt des Versands. Bleibt ein Rest, plant sich der Schritt für die Personen
	 * nach $after_person erneut ein.
	 *
	 * @param int|string $season_id    Freigegebene Saison.
	 * @param int|string $after_person Person, nach der dieser Schritt weitermacht (0: von vorn).
	 */
	public static function send_published( $season_id, $after_person = 0 ): void {
		$season_id = (int) $season_id;
		try {
			$result = self::notifications()->send_published( $season_id, (int) $after_person );
			if ( null !== $result['next'] ) {
				self::schedule_step( self::PUBLISHED_HOOK, $season_id, $result['next'] );
			}
		} catch ( \Throwable $e ) {
			Notifications::report_error( $e, SeasonMessages::PUBLISHED . ' ' . $season_id );
		}
	}

	/**
	 * Stündlicher Lauf der Benachrichtigungen: Auffangnetz. Der Lauf versendet selbst nichts.
	 * Für eine kürzlich freigegebene Saison mit ausstehenden Mitteilungen plant er den
	 * Versandschritt neu ein, sofern nicht schon einer wartet. So bleibt jede Anfrage auf einen
	 * Stapel begrenzt. Fängt ein verlorenes Ereignis, einen abgebrochenen Lauf und gescheiterte
	 * Zustellungen auf.
	 */
	public static function catch_up_published(): void {
		try {
			foreach ( self::notifications()->to_catch_up( Services::clock()->now() ) as $season_id ) {
				if ( ! self::published_scheduled( $season_id ) ) {
					self::schedule_step( self::PUBLISHED_HOOK, $season_id, 0 );
				}
			}
		} catch ( \Throwable $e ) {
			Notifications::report_error( $e, SeasonMessages::PUBLISHED );
		}
	}

	/**
	 * Wartet für die Saison schon ein Versandschritt, gleich ab welcher Person?
	 */
	public static function published_scheduled( int $season_id ): bool {
		return self::step_scheduled( self::PUBLISHED_HOOK, $season_id );
	}

	private static function step_scheduled( string $hook, int $id ): bool {
		$crons = _get_cron_array();
		foreach ( is_array( $crons ) ? $crons : array() as $hooks ) {
			foreach ( $hooks[ $hook ] ?? array() as $event ) {
				if ( (int) ( $event['args'][0] ?? 0 ) === $id ) {
					return true;
				}
			}
		}
		return false;
	}

	/**
	 * Versandschritt einplanen. Scheitert das, wird es gemeldet; der stündliche Lauf plant die
	 * Saison dann erneut ein. Ein schon gleich eingeplanter Schritt ist kein Fehler.
	 *
	 * @return bool Ob der Schritt eingeplant ist.
	 */
	private static function schedule_step( string $hook, int $id, int $after_person ): bool {
		$result = wp_schedule_single_event( time(), $hook, array( $id, $after_person ), true );
		if ( true === $result ) {
			return true;
		}
		if ( is_wp_error( $result ) && 'duplicate_event' === $result->get_error_code() ) {
			return false;
		}
		$reason = is_wp_error( $result ) ? $result->get_error_code() . ': ' . $result->get_error_message() : 'wp_schedule_single_event() lieferte false';
		Notifications::report_error( new \RuntimeException( 'Versand der Mitteilungen liess sich nicht einplanen (' . $reason . ').' ), $hook . ' ' . $id ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Meldung für das Fehlerprotokoll, keine Ausgabe.
		return false;
	}
}
