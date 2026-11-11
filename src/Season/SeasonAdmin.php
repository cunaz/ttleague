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
 * Benachrichtigungstyp season_published und den Versand dieser Mitteilungen in Schritten über
 * WP-Cron.
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

	public static function register(): void {
		add_filter( 'ttleague_tk_pages', array( self::class, 'pages' ) );
		add_filter( 'ttleague_tk_overview_sections', array( SeasonPage::class, 'overview_sections' ) );
		add_filter( 'ttleague_notification_types', array( self::class, 'notification_types' ) );
		add_action( 'admin_enqueue_scripts', array( SeasonPage::class, 'styles' ), 20 );
		add_action( self::PUBLISHED_HOOK, array( self::class, 'send_published' ), 10, 2 );
		add_action( Notifications::CRON_HOOK, array( self::class, 'catch_up_published' ), 20 );
		if ( defined( 'TTLEAGUE_FILE' ) ) {
			register_deactivation_hook( TTLEAGUE_FILE, array( self::class, 'deactivate' ) );
		}
		SeasonActions::register();
	}

	public static function deactivate(): void {
		wp_unschedule_hook( self::PUBLISHED_HOOK );
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
		return $types;
	}

	public static function setup(): SeasonSetup {
		return new SeasonSetup( Services::db(), Services::audit(), new WordPressSeasonEvents() );
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

	/**
	 * Nach der Freigabe: den Versand der Mitteilungen nur vormerken. Das Ereignis ist sofort
	 * fällig; WP-Cron startet es mit der nächsten Anfrage (schon mit der Weiterleitung nach dem
	 * Formular), mit DISABLE_WP_CRON der Cron des Servers. Geht es verloren oder lässt es sich
	 * nicht einplanen, plant der stündliche Lauf nach (catch_up_published()).
	 */
	public static function on_published( int $season_id ): void {
		self::schedule_published( $season_id, 0 );
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
				self::schedule_published( $season_id, $result['next'] );
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
					self::schedule_published( $season_id, 0 );
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
		$crons = _get_cron_array();
		foreach ( is_array( $crons ) ? $crons : array() as $hooks ) {
			foreach ( $hooks[ self::PUBLISHED_HOOK ] ?? array() as $event ) {
				if ( (int) ( $event['args'][0] ?? 0 ) === $season_id ) {
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
	private static function schedule_published( int $season_id, int $after_person ): bool {
		$result = wp_schedule_single_event( time(), self::PUBLISHED_HOOK, array( $season_id, $after_person ), true );
		if ( true === $result ) {
			return true;
		}
		if ( is_wp_error( $result ) && 'duplicate_event' === $result->get_error_code() ) {
			return false;
		}
		$reason = is_wp_error( $result ) ? $result->get_error_code() . ': ' . $result->get_error_message() : 'wp_schedule_single_event() lieferte false';
		Notifications::report_error( new \RuntimeException( 'Versand der Mitteilungen zur Freigabe liess sich nicht einplanen (' . $reason . ').' ), 'Saison ' . $season_id ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Meldung für das Fehlerprotokoll, keine Ausgabe.
		return false;
	}
}
