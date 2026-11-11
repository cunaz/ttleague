<?php
/**
 * Einstieg des Moduls «Anlässe mit Anmeldung» in WordPress (docs/anlaesse.md).
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Events;

use TTLeague\Access\Capabilities;
use TTLeague\Accounts\Services;
use TTLeague\Notifications\EventTypes;
use TTLeague\Notifications\Notifications;

/**
 * Hängt nur Hooks ein: Backend-Seite «Spielbetrieb → Anlässe», Formulare an admin-post.php
 * (Backend und Block), Benachrichtigungstyp, Sofortmeldungen an den Aktionen ttleague_event_*,
 * Versand der Absagemitteilungen in Schritten über WP-Cron, CSV-Export. Den Block ttleague/event-registration und die Shortcode-Ansicht registriert die
 * Darstellung über Frontend\Blocks::VIEWS (Ansicht RegistrationView).
 */
final class Events {

	/** Einmaliges Cron-Ereignis: ein Schritt des Versands der Absagemitteilungen (Anlass, Person davor). */
	public const CANCELLATION_HOOK = 'ttleague_event_cancellation';

	public static function register(): void {
		add_filter( 'ttleague_tk_pages', array( AdminPage::class, 'pages' ) );
		add_filter( 'ttleague_notification_types', array( self::class, 'notification_types' ) );
		add_filter( 'ttleague_exports', array( Export::class, 'register' ) );
		add_action( 'enqueue_block_editor_assets', array( RegistrationView::class, 'editor_data' ), 20 );

		add_action( 'admin_post_' . AdminActions::SAVE, array( AdminActions::class, 'save' ) );
		add_action( 'admin_post_' . AdminActions::STATUS, array( AdminActions::class, 'status' ) );
		add_action( 'admin_post_' . AdminActions::REGISTER, array( AdminActions::class, 'register' ) );
		add_action( 'admin_post_' . AdminActions::UNREGISTER, array( AdminActions::class, 'unregister' ) );

		add_action( 'admin_post_' . BlockActions::REGISTER, array( BlockActions::class, 'register' ) );
		add_action( 'admin_post_' . BlockActions::UNREGISTER, array( BlockActions::class, 'unregister' ) );
		add_action( 'admin_post_nopriv_' . BlockActions::REGISTER, array( BlockActions::class, 'login_first' ) );
		add_action( 'admin_post_nopriv_' . BlockActions::UNREGISTER, array( BlockActions::class, 'login_first' ) );

		add_action( 'ttleague_event_registered', array( self::class, 'on_registered' ), 10, 5 );
		add_action( 'ttleague_event_unregistered', array( self::class, 'on_unregistered' ), 10, 5 );
		add_action( 'ttleague_event_promoted', array( self::class, 'on_promoted' ), 10, 3 );
		add_action( 'ttleague_event_cancelled', array( self::class, 'on_cancelled' ), 10, 2 );
		add_action( self::CANCELLATION_HOOK, array( self::class, 'send_cancellations' ), 10, 2 );
		add_action( Notifications::CRON_HOOK, array( self::class, 'catch_up_cancellations' ), 20 );
		if ( defined( 'TTLEAGUE_FILE' ) ) {
			register_deactivation_hook( TTLEAGUE_FILE, array( self::class, 'deactivate' ) );
		}
	}

	public static function deactivate(): void {
		wp_unschedule_hook( self::CANCELLATION_HOOK );
	}

	public static function repository(): EventRepository {
		return new EventRepository( Services::db() );
	}

	public static function events(): EventService {
		return new EventService( Services::db(), Services::audit(), new WordPressEventListener() );
	}

	public static function registrations(): RegistrationService {
		return new RegistrationService( Services::db(), Capabilities::policy(), Services::clock(), wp_timezone(), Services::audit(), new WordPressEventListener() );
	}

	/**
	 * Filter ttleague_notification_types: Typ für die Einstellungen in «Mein Bereich».
	 *
	 * @param array<string,string> $types Typ => Bezeichnung.
	 * @return array<string,string>
	 */
	public static function notification_types( $types ): array {
		$types = is_array( $types ) ? $types : array();
		return $types + array( EventTypes::EVENT_REGISTRATION => __( 'Anlässe: An- und Abmeldung, Nachrücken, Absage', 'ttleague' ) );
	}

	public static function notifications(): EventNotifications {
		return new EventNotifications(
			self::repository(),
			Notifications::recipients(),
			Notifications::notifier(),
			new EventMessages( new WordPressLinks() ),
			static function ( int $user_id ): ?string {
				$user = get_userdata( $user_id );
				return false === $user ? null : $user->display_name;
			},
			array( Notifications::class, 'report_error' )
		);
	}

	public static function on_registered( int $event_id, int $registration_id, int $person_id, string $status, int $user_id ): void {
		self::guard( static fn() => self::notifications()->registered( $event_id, $registration_id, $person_id, $status, $user_id ) );
	}

	public static function on_unregistered( int $event_id, int $registration_id, int $person_id, string $status, int $user_id ): void {
		self::guard( static fn() => self::notifications()->unregistered( $event_id, $registration_id, $person_id, $status, $user_id ) );
	}

	public static function on_promoted( int $event_id, int $registration_id, int $person_id ): void {
		self::guard( static fn() => self::notifications()->promoted( $event_id, $registration_id, $person_id ) );
	}

	/**
	 * Absage: den Versand der Mitteilungen nur vormerken. Er läuft danach über WP-Cron in
	 * begrenzten Schritten (send_cancellations()), damit die Anfrage der TK nicht am Zeitlimit
	 * scheitert. Das Ereignis ist sofort fällig; WP-Cron startet es mit der nächsten Anfrage
	 * (schon mit der Weiterleitung nach dem Formular), mit DISABLE_WP_CRON der Cron des Servers.
	 * Geht es verloren oder lässt es sich nicht einplanen, plant der stündliche Lauf den Versand
	 * erneut ein (catch_up_cancellations()).
	 * Das Konto, das abgesagt hat, steht im Änderungsprotokoll der Absage.
	 */
	public static function on_cancelled( int $event_id, int $user_id ): void {
		unset( $user_id );
		self::schedule_cancellations( $event_id, 0 );
	}

	/**
	 * Cron: ein Schritt des Versands der Absagemitteilungen. Bleibt ein Rest, plant sich der
	 * Schritt für die Personen nach $after_person erneut ein.
	 *
	 * @param int|string $event_id     Anlass.
	 * @param int|string $after_person Person, nach der dieser Schritt weitermacht (0: von vorn).
	 */
	public static function send_cancellations( $event_id, $after_person = 0 ): void {
		$event_id = (int) $event_id;
		self::guard(
			static function () use ( $event_id, $after_person ): void {
				$result = self::notifications()->send_cancellations( $event_id, (int) $after_person );
				if ( null !== $result['next'] ) {
					self::schedule_cancellations( $event_id, $result['next'] );
				}
			}
		);
	}

	/**
	 * Stündlicher Lauf der Benachrichtigungen: Auffangnetz für Absagemitteilungen. Der Lauf
	 * versendet selbst nichts. Für kürzlich abgesagte Anlässe mit ausstehenden Mitteilungen plant
	 * er nur den Versandschritt neu ein (dasselbe einmalige Ereignis wie nach der Absage), sofern
	 * für den Anlass nicht schon ein Schritt wartet. So bleibt jede Anfrage auf einen Stapel
	 * begrenzt. Pro Lauf höchstens EventNotifications::CANCEL_CATCH_UP_EVENTS Anlässe, die älteste
	 * Absage zuerst, im Abstand von einer Minute; die übrigen folgen im nächsten Lauf.
	 * Fängt ein verlorenes Ereignis, einen abgebrochenen Lauf und gescheiterte Zustellungen auf.
	 */
	public static function catch_up_cancellations(): void {
		self::guard(
			static function (): void {
				$planned = 0;
				foreach ( self::notifications()->cancellations_to_catch_up( Services::clock()->now() ) as $event_id ) {
					if ( $planned >= EventNotifications::CANCEL_CATCH_UP_EVENTS ) {
						break;
					}
					if ( self::cancellations_scheduled( $event_id ) ) {
						continue;
					}
					if ( self::schedule_cancellations( $event_id, 0, $planned * MINUTE_IN_SECONDS ) ) {
						++$planned;
					}
				}
			}
		);
	}

	/**
	 * Wartet für den Anlass schon ein Versandschritt, gleich ab welcher Person?
	 */
	public static function cancellations_scheduled( int $event_id ): bool {
		$crons = _get_cron_array();
		foreach ( is_array( $crons ) ? $crons : array() as $hooks ) {
			foreach ( $hooks[ self::CANCELLATION_HOOK ] ?? array() as $event ) {
				if ( (int) ( $event['args'][0] ?? 0 ) === $event_id ) {
					return true;
				}
			}
		}
		return false;
	}

	/**
	 * Versandschritt einplanen. Scheitert das, wird es gemeldet; der stündliche Lauf plant den
	 * Anlass dann erneut ein. Ein schon gleich eingeplanter Schritt ist kein Fehler.
	 *
	 * @param int $delay Sekunden bis zur Fälligkeit.
	 * @return bool Ob der Schritt eingeplant ist.
	 */
	private static function schedule_cancellations( int $event_id, int $after_person, int $delay = 0 ): bool {
		$result = wp_schedule_single_event( time() + $delay, self::CANCELLATION_HOOK, array( $event_id, $after_person ), true );
		if ( true === $result ) {
			return true;
		}
		if ( is_wp_error( $result ) && 'duplicate_event' === $result->get_error_code() ) {
			return false;
		}
		$reason = is_wp_error( $result ) ? $result->get_error_code() . ': ' . $result->get_error_message() : 'wp_schedule_single_event() lieferte false';
		Notifications::report_error( new \RuntimeException( 'Versand der Absagemitteilungen liess sich nicht einplanen (' . $reason . ').' ), 'Anlass ' . $event_id );
		return false;
	}

	/**
	 * Fehler beim Versand abfangen und melden: Die An- oder Abmeldung ist gespeichert und bleibt es.
	 *
	 * @param callable():mixed $work Arbeit.
	 */
	private static function guard( callable $work ): void {
		try {
			$work();
		} catch ( \Throwable $e ) {
			Notifications::report_error( $e, 'Anlass' );
		}
	}
}
