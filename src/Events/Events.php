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
 * CSV-Export. Den Block ttleague/event-registration und die Shortcode-Ansicht registriert die
 * Darstellung über Frontend\Blocks::VIEWS (Ansicht RegistrationView).
 */
final class Events {

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
			}
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

	public static function on_cancelled( int $event_id, int $user_id ): void {
		self::guard( static fn() => self::notifications()->cancelled( $event_id, $user_id ) );
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
