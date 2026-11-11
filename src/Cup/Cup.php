<?php
/**
 * Einstieg des Moduls «Mannschaftscup» in WordPress (docs/cup.md).
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Cup;

use TTLeague\Access\Capabilities;
use TTLeague\Accounts\Services;
use TTLeague\Notifications\Deadlines;
use TTLeague\Notifications\EventTypes;
use TTLeague\Notifications\Notifications;
use TTLeague\Notifications\WordPressLinks;
use TTLeague\Results\Actor;
use TTLeague\Results\Results;

/**
 * Hängt nur Hooks ein (docs/saisonbetrieb.md):
 *
 * - Formulare an admin-post.php (Forms); sie rufen die Dienste Entries, DrawService und Bracket auf.
 * - Abschnitt «Cup» in «Mein Bereich» für TK-Chefs (Aktion ttleague_my_area_sections), Seite
 *   «Spielbetrieb → Cup» (Filter ttleague_tk_pages), Listen in der Übersicht der TK (Filter
 *   ttleague_tk_overview_sections) und CSV-Export der Meldungen (Filter ttleague_exports).
 * - Nachrücken im Tableau an den Ereignissen der Erfassung (ttleague_result_confirmed,
 *   ttleague_result_decided).
 * - Benachrichtigungstypen cup_round und cup_deadline. Die Mitteilungen nach der Auslosung
 *   gehen über WP-Cron in Schritten hinaus, einzelne neue Paarungen direkt; die Erinnerung vor
 *   der Frist hängt am stündlichen Lauf der Fristen (Notifications::CRON_HOOK), ohne eigenen Cron.
 */
final class Cup {

	/** Seite «Spielbetrieb → Cup». */
	public const PAGE = 'ttleague-tk-cup';

	/** Platz der Seite im Menü «Spielbetrieb». */
	public const POSITION = 60;

	/** Nach «Mein Club» (Priorität 20). */
	public const AREA_PRIORITY = 25;

	/** Einmaliges Cron-Ereignis: ein Schritt des Versands der Mitteilungen zur Runde (Konkurrenz, Paarung davor). */
	public const ROUND_HOOK = 'ttleague_cup_round_notices';

	/** Sekunden, um die ein Versandschritt verschoben wird, wenn in der Anfrage schon einer lief. */
	public const DEFER_SECONDS = 60;

	/** Bis zu so vielen Paarungen gehen Mitteilungen direkt hinaus, darüber in Schritten über WP-Cron. */
	public const DIRECT_TIES = 2;

	/** Lief in dieser Anfrage (diesem PHP-Prozess) schon ein Versandschritt? */
	private static bool $batch_ran = false;

	public static function register(): void {
		Forms::register();
		add_filter( 'ttleague_tk_pages', array( AdminPage::class, 'pages' ) );
		add_filter( 'ttleague_tk_overview_sections', array( AdminPage::class, 'overview_sections' ) );
		add_action( 'ttleague_my_area_sections', array( MyAreaSection::class, 'render' ), self::AREA_PRIORITY );
		add_filter( 'ttleague_exports', array( Export::class, 'register' ) );

		add_action( 'ttleague_result_confirmed', array( self::class, 'on_result' ), 10, 2 );
		add_action( 'ttleague_result_decided', array( self::class, 'on_result' ), 10, 2 );

		add_filter( 'ttleague_notification_types', array( self::class, 'notification_types' ) );
		add_action( 'ttleague_cup_changed', array( self::class, 'on_changed' ), 10, 4 );
		add_action( self::ROUND_HOOK, array( self::class, 'send_round' ), 10, 2 );
		add_action( Notifications::CRON_HOOK, array( self::class, 'run_deadlines' ) );
		add_action( Notifications::CRON_HOOK, array( self::class, 'catch_up_rounds' ), 20 );
		if ( defined( 'TTLEAGUE_FILE' ) ) {
			register_deactivation_hook( TTLEAGUE_FILE, array( self::class, 'deactivate' ) );
		}
	}

	public static function deactivate(): void {
		wp_unschedule_hook( self::ROUND_HOOK );
	}

	public static function repository(): CupRepository {
		return new CupRepository( Services::db() );
	}

	/**
	 * Tableau; Rückzüge wertet es über die Erfassung forfait (mit Verlauf und Mitteilungen).
	 */
	public static function bracket(): Bracket {
		return new Bracket(
			Services::db(),
			Services::clock(),
			wp_timezone(),
			Services::audit(),
			new WordPressCupEvents(),
			static function ( int $fixture_id, string $winner, Actor $actor, string $comment ): void {
				Results::service()->decide(
					$fixture_id,
					$actor,
					array(
						'action'  => 'forfeit',
						'winner'  => $winner,
						'comment' => $comment,
					)
				);
			}
		);
	}

	public static function entries(): Entries {
		return new Entries( Services::db(), Capabilities::policy(), Services::clock(), wp_timezone(), Services::audit(), new WordPressCupEvents(), self::bracket() );
	}

	public static function draw(): DrawService {
		return new DrawService( Services::db(), Services::clock(), wp_timezone(), Services::audit(), new WordPressCupEvents(), self::bracket() );
	}

	public static function notifications(): CupNotifications {
		/** Dokumentiert in Notifications::deadlines(). */
		$hours = (array) apply_filters(
			'ttleague_notification_hours',
			array(
				'from_hour'   => Deadlines::FROM_HOUR,
				'until_hour'  => Deadlines::UNTIL_HOUR,
				'digest_hour' => Deadlines::DIGEST_HOUR,
			)
		);
		return new CupNotifications(
			self::repository(),
			Notifications::recipients(),
			Notifications::notifier(),
			new CupMessages( new WordPressLinks() ),
			Services::clock(),
			wp_timezone(),
			$hours,
			array( Notifications::class, 'report_error' )
		);
	}

	/**
	 * Filter ttleague_notification_types: Typ => Bezeichnung für die Einstellungen.
	 *
	 * @param mixed $types Typen der anderen Module.
	 * @return array<string,string>
	 */
	public static function notification_types( $types ): array {
		$types = is_array( $types ) ? $types : array();
		return $types + array(
			EventTypes::CUP_ROUND    => __( 'Cup: Auslosung, neue Runde, angesetzte Begegnung', 'ttleague' ),
			EventTypes::CUP_DEADLINE => __( 'Cup: Erinnerung vor Ablauf der Rundenfrist', 'ttleague' ),
		);
	}

	/**
	 * Aktionen ttleague_result_confirmed und ttleague_result_decided: Das Tableau rückt nach.
	 * Ein Fehler dabei bricht die gespeicherte Erfassung nicht ab und hält die übrigen Listener
	 * nicht auf; er steht im Fehlerprotokoll.
	 *
	 * @param int|string $fixture_id Begegnung.
	 * @param int|string $user_id    Handelndes Konto.
	 */
	public static function on_result( $fixture_id, $user_id = 0 ): void {
		try {
			self::bracket()->fixture_changed( (int) $fixture_id, (int) $user_id );
		} catch ( \Throwable $e ) {
			error_log( sprintf( 'ttleague: Cup-Tableau nach Begegnung %d nicht abgeglichen: %s: %s', (int) $fixture_id, $e::class, $e->getMessage() ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Hinweis für die Verwaltung.
		}
	}

	/**
	 * Aktion ttleague_cup_changed: Mitteilungen an die Cupmannschaften.
	 *
	 * Die Auslosung betrifft alle Mannschaften: Ihr Versand wird nur vorgemerkt und läuft danach
	 * über WP-Cron in begrenzten Schritten (send_round()), damit die Anfrage der TK nicht am
	 * Zeitlimit scheitert. Einzelne neue oder geänderte Paarungen (höchstens DIRECT_TIES) gehen
	 * direkt an ihre wenigen Empfänger. Ein Fehler beim Versand bricht die Aktion nie ab.
	 *
	 * @param int|string $competition_id Konkurrenz.
	 * @param mixed      $action         Art der Änderung (CupEvents).
	 * @param int|string $user_id        Handelndes Konto.
	 * @param mixed      $tie_ids        Betroffene Paarungen.
	 */
	public static function on_changed( $competition_id, $action, $user_id = 0, $tie_ids = array() ): void {
		unset( $user_id );
		$competition_id = (int) $competition_id;
		$tie_ids        = is_array( $tie_ids ) ? array_values( array_map( 'intval', $tie_ids ) ) : array();
		if ( CupEvents::DRAW_RELEASED === $action ) {
			self::schedule_round( $competition_id, 0 );
			return;
		}
		if ( array() === $tie_ids || ! in_array( $action, array( CupEvents::ADVANCED, CupEvents::SCHEDULED, CupEvents::HOME_SWAPPED ), true ) ) {
			return;
		}
		if ( count( $tie_ids ) > self::DIRECT_TIES ) {
			self::schedule_round( $competition_id, 0 );
			return;
		}
		self::guard( static fn() => self::notifications()->announce( $competition_id, $tie_ids ) );
	}

	/**
	 * Cron: ein Schritt des Versands der Mitteilungen zur Runde. Bleibt ein Rest, plant sich der
	 * Schritt für die Paarungen nach $after_tie erneut ein.
	 *
	 * Höchstens ein Schritt pro Anfrage: wp-cron.php führt alle fälligen Ereignisse im selben
	 * PHP-Prozess aus. Lief darin schon ein Schritt (gleich für welche Konkurrenz), versendet
	 * dieser Aufruf nichts und plant sich mit DEFER_SECONDS Abstand neu ein.
	 *
	 * @param int|string $competition_id Konkurrenz.
	 * @param int|string $after_tie      Paarung, nach der dieser Schritt weitermacht (0: von vorn).
	 */
	public static function send_round( $competition_id, $after_tie = 0 ): void {
		$competition_id = (int) $competition_id;
		$after_tie      = (int) $after_tie;
		if ( self::$batch_ran ) {
			self::schedule_round( $competition_id, $after_tie, self::DEFER_SECONDS );
			return;
		}
		self::$batch_ran = true;
		self::guard(
			static function () use ( $competition_id, $after_tie ): void {
				$result = self::notifications()->send_round( $competition_id, $after_tie );
				if ( null !== $result['next'] ) {
					self::schedule_round( $competition_id, $result['next'] );
				}
			}
		);
	}

	/**
	 * Vergisst, dass in diesem PHP-Prozess schon ein Versandschritt lief (Tests, lang laufende
	 * Prozesse wie WP-CLI, die mehrere Schritte bewusst nacheinander ausführen).
	 */
	public static function reset_request(): void {
		self::$batch_ran = false;
	}

	/**
	 * Stündlicher Lauf der Benachrichtigungen: Auffangnetz für die Mitteilungen zur Runde. Der
	 * Lauf versendet selbst nichts. Für Konkurrenzen mit ausstehenden Mitteilungen zu jungen
	 * Paarungen plant er nur den Versandschritt neu ein (dasselbe einmalige Ereignis wie nach
	 * der Auslosung), sofern für die Konkurrenz nicht schon ein Schritt wartet. Pro Lauf höchstens
	 * CupNotifications::CATCH_UP_COMPETITIONS Versuche im Abstand von einer Minute, auch wenn das
	 * Einplanen scheitert. Fängt ein verlorenes Ereignis, einen abgebrochenen Lauf und
	 * gescheiterte Zustellungen auf.
	 */
	public static function catch_up_rounds(): void {
		self::guard(
			static function (): void {
				// Eine Basiszeit für alle Schritte dieses Laufs: genau eine Minute Abstand.
				$now      = time();
				$attempts = 0;
				foreach ( self::notifications()->competitions_to_catch_up() as $competition_id ) {
					if ( $attempts >= CupNotifications::CATCH_UP_COMPETITIONS ) {
						break;
					}
					if ( self::round_scheduled( $competition_id ) ) {
						continue;
					}
					self::schedule_round( $competition_id, 0, $attempts * MINUTE_IN_SECONDS, $now );
					++$attempts;
				}
			}
		);
	}

	/**
	 * Stündlicher Lauf der Fristen: Erinnerung vor Ablauf der Rundenfrist.
	 */
	public static function run_deadlines(): void {
		self::guard( static fn() => self::notifications()->remind_deadlines() );
	}

	/**
	 * Wartet für die Konkurrenz schon ein Versandschritt, gleich ab welcher Paarung?
	 */
	public static function round_scheduled( int $competition_id ): bool {
		$crons = _get_cron_array();
		foreach ( is_array( $crons ) ? $crons : array() as $hooks ) {
			foreach ( $hooks[ self::ROUND_HOOK ] ?? array() as $event ) {
				if ( (int) ( $event['args'][0] ?? 0 ) === $competition_id ) {
					return true;
				}
			}
		}
		return false;
	}

	/**
	 * Versandschritt einplanen. Scheitert das, wird es gemeldet; der stündliche Lauf plant die
	 * Konkurrenz dann erneut ein. Ein schon gleich eingeplanter Schritt ist kein Fehler.
	 *
	 * @param int      $delay Sekunden bis zur Fälligkeit.
	 * @param int|null $now   Basiszeit (Unix); ohne Angabe jetzt.
	 * @return bool Ob der Schritt eingeplant ist.
	 */
	private static function schedule_round( int $competition_id, int $after_tie, int $delay = 0, ?int $now = null ): bool {
		$result = wp_schedule_single_event( ( $now ?? time() ) + $delay, self::ROUND_HOOK, array( $competition_id, $after_tie ), true );
		if ( true === $result ) {
			return true;
		}
		if ( is_wp_error( $result ) && 'duplicate_event' === $result->get_error_code() ) {
			return false;
		}
		$reason = is_wp_error( $result ) ? $result->get_error_code() . ': ' . $result->get_error_message() : 'wp_schedule_single_event() lieferte false';
		Notifications::report_error( new \RuntimeException( 'Versand der Cup-Mitteilungen liess sich nicht einplanen (' . $reason . ').' ), 'Cup-Konkurrenz ' . $competition_id );
		return false;
	}

	/**
	 * Fehler beim Versand abfangen und melden: Die auslösende Aktion ist gespeichert und bleibt es.
	 *
	 * @param callable():mixed $work Arbeit.
	 */
	private static function guard( callable $work ): void {
		try {
			$work();
		} catch ( \Throwable $e ) {
			Notifications::report_error( $e, 'Cup' );
		}
	}
}
