<?php
/**
 * Einstieg der Benachrichtigungen in WordPress.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Notifications;

use TTLeague\Accounts\Services;
use TTLeague\Infrastructure\Time\Clock;
use TTLeague\Notifications\Push\PushChannel;
use TTLeague\Notifications\Push\PushUi;
use TTLeague\Notifications\Push\SubscriptionController;
use TTLeague\Notifications\Push\Subscriptions;
use TTLeague\Notifications\Push\SubscriptionValidator;
use TTLeague\Notifications\Push\VapidKeys;
use TTLeague\Notifications\Push\WebPush;
use TTLeague\Notifications\Push\WordPressTransport;
use TTLeague\Results\ResultRepository;

/**
 * Dünne Anbindung: Hängt die Sofortmeldungen an die Aktionen der Erfassung, plant den
 * stündlichen Lauf der Fristen (eigener Cron-Hook, bei der Deaktivierung entfernt) und baut
 * die Dienste mit der Datenbank, der Uhr und den Kanälen der Website zusammen. Fehler beim
 * Versand brechen die auslösende Aktion nie ab, sie landen im Fehlerprotokoll und in der
 * Aktion ttleague_notification_error.
 *
 * Web Push ist verfügbar, sobald VAPID-Schlüssel bereitstehen (Filter ttleague_push_available);
 * dann gibt es die REST-API der Abos und in «Mein Bereich» den Knopf für das Gerät.
 *
 * Filter: ttleague_notification_hours (Versandfenster und Stunde der TK-Übersicht),
 * ttleague_push_endpoint_allowed (zulässige Endpunkte), ttleague_push_max_subscriptions
 * (Abos pro Person), ttleague_vapid_subject (Kontakt im VAPID-JWT).
 */
final class Notifications {

	public const CRON_HOOK = 'ttleague_notifications_hourly';

	public static function register(): void {
		add_action( 'ttleague_result_entered', array( self::class, 'on_entered' ), 10, 3 );
		add_action( 'ttleague_result_confirmed', array( self::class, 'on_confirmed' ), 10, 3 );
		add_action( 'ttleague_result_disputed', array( self::class, 'on_disputed' ), 10, 3 );
		add_action( 'ttleague_result_decided', array( self::class, 'on_decided' ), 10, 4 );
		add_action( 'init', array( self::class, 'schedule' ) );
		add_action( self::CRON_HOOK, array( self::class, 'run_deadlines' ) );
		add_filter( 'ttleague_push_available', array( self::class, 'push_available' ) );
		add_filter( 'ttleague_push_controls', array( PushUi::class, 'controls' ), 10, 2 );
		add_action( 'rest_api_init', array( SubscriptionController::class, 'register_routes' ) );
		if ( defined( 'TTLEAGUE_FILE' ) ) {
			register_deactivation_hook( TTLEAGUE_FILE, array( self::class, 'deactivate' ) );
		}
	}

	/**
	 * Stündlichen Lauf der Fristen einplanen.
	 */
	public static function schedule(): void {
		if ( false === wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + 5 * MINUTE_IN_SECONDS, 'hourly', self::CRON_HOOK );
		}
	}

	public static function deactivate(): void {
		wp_clear_scheduled_hook( self::CRON_HOOK );
	}

	/**
	 * Filter ttleague_push_available: Push ist verfügbar, wenn VAPID-Schlüssel bereitstehen.
	 */
	public static function push_available( bool $available ): bool {
		return $available || null !== VapidKeys::get();
	}

	/**
	 * Cron: Fristen prüfen und fällige Erinnerungen versenden.
	 */
	public static function run_deadlines(): void {
		self::guard( static fn() => self::deadlines()->run() );
	}

	/**
	 * Fristen mit der Uhr der Website oder einer festen Uhr (WP-CLI, Tests).
	 */
	public static function deadlines( ?Clock $clock = null ): Deadlines {
		$clock = $clock ?? Services::clock();
		$log   = new NotificationLog( Services::db(), $clock );
		/**
		 * Versandfenster der Erinnerungen und Stunde der täglichen TK-Übersicht (Ortszeit).
		 *
		 * @param array{from_hour:int,until_hour:int,digest_hour:int} $hours Standard 7 bis 22 Uhr, Übersicht ab 7 Uhr.
		 */
		$hours = (array) apply_filters(
			'ttleague_notification_hours',
			array(
				'from_hour'   => Deadlines::FROM_HOUR,
				'until_hour'  => Deadlines::UNTIL_HOUR,
				'digest_hour' => Deadlines::DIGEST_HOUR,
			)
		);
		return new Deadlines(
			$clock,
			wp_timezone(),
			new FixtureQueries( Services::db() ),
			new ResultRepository( Services::db() ),
			self::recipients(),
			self::notifier( $log ),
			self::messages(),
			$log,
			$hours
		);
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

	public static function notifier( ?NotificationLog $log = null ): Notifier {
		return new Notifier(
			Services::preferences(),
			$log ?? new NotificationLog( Services::db(), Services::clock() ),
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
		$mailer   = Services::mailer();
		$channels = array( new EmailChannel( array( $mailer, 'send' ) ) );
		$vapid    = VapidKeys::get();
		if ( null !== $vapid ) {
			$icon       = get_site_icon_url( 192 );
			$channels[] = new PushChannel(
				self::subscriptions(),
				new WebPush( $vapid, new WordPressTransport(), Services::clock(), VapidKeys::subject() ),
				'' !== $icon ? $icon : TTLEAGUE_URL . 'assets/pwa/icon-192.png'
			);
		}
		return $channels;
	}

	public static function subscriptions(): Subscriptions {
		/**
		 * Höchstzahl der Push-Abos pro Person; ältere fallen beim Überschreiten weg.
		 *
		 * @param int $max Standard 10.
		 */
		return new Subscriptions( Services::db(), Services::clock(), max( 1, (int) apply_filters( 'ttleague_push_max_subscriptions', Subscriptions::MAX_PER_PERSON ) ) );
	}

	public static function validator(): SubscriptionValidator {
		return new SubscriptionValidator(
			static function ( string $endpoint, bool $allowed ): bool {
				/**
				 * Darf an diesen Endpunkt gesendet werden? Standard: https bei einem bekannten
				 * Push-Dienst (Google, Mozilla, Apple, Microsoft). Für lokale Tests erweiterbar.
				 *
				 * @param bool   $allowed  Standardentscheid.
				 * @param string $endpoint Adresse des Abos.
				 */
				return (bool) apply_filters( 'ttleague_push_endpoint_allowed', $allowed, $endpoint );
			}
		);
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
