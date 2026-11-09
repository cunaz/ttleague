<?php
/**
 * Einstieg der Benachrichtigungen in WordPress.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Notifications;

use TTLeague\Accounts\Services;
use TTLeague\Results\ResultRepository;

/**
 * Dünne Anbindung: Hängt die Sofortmeldungen an die Aktionen der Erfassung und baut die
 * Dienste mit der Datenbank, der Uhr und den Kanälen der Website zusammen. Fehler beim
 * Versand brechen die auslösende Aktion nie ab, sie landen im Fehlerprotokoll und in der
 * Aktion ttleague_notification_error.
 */
final class Notifications {

	public static function register(): void {
		add_action( 'ttleague_result_entered', array( self::class, 'on_entered' ), 10, 3 );
		add_action( 'ttleague_result_confirmed', array( self::class, 'on_confirmed' ), 10, 3 );
		add_action( 'ttleague_result_disputed', array( self::class, 'on_disputed' ), 10, 3 );
		add_action( 'ttleague_result_decided', array( self::class, 'on_decided' ), 10, 4 );
	}

	public static function on_entered( int $fixture_id, int $user_id, bool $edited ): void {
		self::guard( static fn() => self::results()->entered( $fixture_id, $user_id, $edited ) );
	}

	public static function on_confirmed( int $fixture_id, int $user_id, bool $by_tk ): void {
		self::guard( static fn() => self::results()->confirmed( $fixture_id, $user_id, $by_tk ) );
	}

	public static function on_disputed( int $fixture_id, int $user_id, string $reason ): void {
		self::guard( static fn() => self::results()->disputed( $fixture_id, $user_id, $reason ) );
	}

	public static function on_decided( int $fixture_id, int $user_id, string $action, ?string $comment ): void {
		self::guard( static fn() => self::results()->decided( $fixture_id, $user_id, $action, $comment ) );
	}

	public static function results(): ResultNotifications {
		return new ResultNotifications(
			new ResultRepository( Services::db() ),
			new FixtureQueries( Services::db() ),
			self::recipients(),
			self::notifier(),
			self::messages(),
			static function ( int $user_id ): ?string {
				$user = get_userdata( $user_id );
				return false === $user ? null : $user->display_name;
			}
		);
	}

	public static function notifier(): Notifier {
		return new Notifier(
			Services::preferences(),
			new NotificationLog( Services::db(), Services::clock() ),
			self::channels(),
			array( self::class, 'report_error' )
		);
	}

	public static function recipients(): RecipientResolver {
		return new RecipientResolver( Services::db(), new WordPressTkDirectory() );
	}

	public static function messages(): Messages {
		return new Messages( new WordPressLinks() );
	}

	/**
	 * Versandkanäle der Website.
	 *
	 * @return list<Channel>
	 */
	public static function channels(): array {
		$mailer = Services::mailer();
		return array( new EmailChannel( array( $mailer, 'send' ) ) );
	}

	/**
	 * Fehler eines Kanals oder Handlers melden.
	 */
	public static function report_error( \Throwable $error, string $context = '' ): void {
		/**
		 * Fehler beim Versand einer Benachrichtigung.
		 *
		 * @param \Throwable $error   Fehler.
		 * @param string     $context Kanal, Art und Empfänger.
		 */
		do_action( 'ttleague_notification_error', $error, $context );
		error_log( 'ttleague Benachrichtigung: ' . $error->getMessage() . ( '' === $context ? '' : ' (' . $context . ')' ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Betriebsprotokoll, ohne Personendaten.
	}

	/**
	 * Fehler abfangen: Die auslösende Aktion (z. B. die Erfassung) darf nie scheitern.
	 *
	 * @param callable():mixed $work Arbeit.
	 */
	private static function guard( callable $work ): void {
		try {
			$work();
		} catch ( \Throwable $e ) {
			self::report_error( $e );
		}
	}
}
