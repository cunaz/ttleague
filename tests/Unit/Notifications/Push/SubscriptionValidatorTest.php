<?php
/**
 * Prüfung der Push-Abos aus dem Browser.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Tests\Unit\Notifications\Push;

use PHPUnit\Framework\TestCase;
use TTLeague\Notifications\Push\Base64Url;
use TTLeague\Notifications\Push\P256;
use TTLeague\Notifications\Push\SubscriptionValidator;

final class SubscriptionValidatorTest extends TestCase {

	public static function setUpBeforeClass(): void {
		require_once dirname( __DIR__, 3 ) . '/Unit/Frontend/Stubs/WordPress.php';
	}

	protected function setUp(): void {
		if ( ! P256::supported() ) {
			self::markTestSkipped( 'OpenSSL ohne P-256.' );
		}
	}

	/**
	 * @param array<string,mixed> $data Abo.
	 * @return list<string>
	 */
	private static function fields( array $data, ?SubscriptionValidator $validator = null ): array {
		return array_column( ( $validator ?? new SubscriptionValidator() )->validate( $data )['errors'], 'field' );
	}

	/**
	 * @return array<string,mixed>
	 */
	private static function data( string $endpoint ): array {
		return PushTestKit::browser( $endpoint )['json'];
	}

	public function test_subscriptions_of_known_push_services_are_accepted(): void {
		foreach ( array(
			'https://fcm.googleapis.com/fcm/send/dXk3:APA91b',
			'https://updates.push.services.mozilla.com/wpush/v2/gAAAAAB',
			'https://web.push.apple.com/QGuQyavXutnMH',
			'https://wns2-par02p.notify.windows.com/w/?token=BQYAAAB',
		) as $endpoint ) {
			$browser = PushTestKit::browser( $endpoint );
			$result  = ( new SubscriptionValidator() )->validate( $browser['json'] );
			self::assertSame( array(), $result['errors'], $endpoint );
			self::assertSame( $endpoint, $result['subscription']->endpoint );
			self::assertSame( $browser['subscription']->p256dh, $result['subscription']->p256dh );
			self::assertSame( $browser['subscription']->auth, $result['subscription']->auth );
		}
	}

	public function test_endpoint_must_be_https_at_a_push_service(): void {
		foreach ( array(
			'http://fcm.googleapis.com/fcm/send/abc',
			'https://example.org/push',
			'https://fcm.googleapis.com.example.org/fcm/send/abc',
			'https://evilfcm.googleapis.com/fcm/send/abc',
			'https://nutzer:geheim@' . 'fcm.googleapis.com/fcm/send/abc',
			'https://fcm.googleapis.com/fcm/send/a b',
			'fcm.googleapis.com/fcm/send/abc',
			'https://fcm.googleapis.com/fcm/send/' . str_repeat( 'a', 2048 ),
			'',
		) as $endpoint ) {
			$data             = self::data( 'https://fcm.googleapis.com/fcm/send/abc' );
			$data['endpoint'] = $endpoint;
			self::assertSame( array( 'endpoint' ), self::fields( $data ), $endpoint );
		}
	}

	public function test_local_endpoints_can_be_allowed_for_tests(): void {
		$validator = new SubscriptionValidator( static fn( string $endpoint, bool $standard ): bool => $standard || str_starts_with( $endpoint, 'http://127.0.0.1:8095/' ) );
		self::assertSame( array(), self::fields( self::data( 'http://127.0.0.1:8095/push/1' ), $validator ) );
		self::assertSame( array( 'endpoint' ), self::fields( self::data( 'http://127.0.0.1:9000/push/1' ), $validator ) );
		self::assertSame( array(), self::fields( self::data( 'https://fcm.googleapis.com/fcm/send/abc' ), $validator ) );
	}

	public function test_keys_are_checked(): void {
		$data                  = self::data( 'https://fcm.googleapis.com/fcm/send/abc' );
		$bad                   = $data;
		$bad['keys']['p256dh'] = Base64Url::encode( "\x04" . str_repeat( "\x01", 64 ) );
		$bad['keys']['auth']   = Base64Url::encode( random_bytes( 15 ) );
		self::assertSame( array( 'keys.p256dh', 'keys.auth' ), self::fields( $bad ) );

		$short                   = $data;
		$short['keys']['p256dh'] = substr( $data['keys']['p256dh'], 0, 40 );
		self::assertSame( array( 'keys.p256dh' ), self::fields( $short ) );

		self::assertSame( array( 'endpoint', 'keys.p256dh', 'keys.auth' ), self::fields( array() ) );
		self::assertSame( array( 'endpoint', 'keys.p256dh', 'keys.auth' ), array_column( ( new SubscriptionValidator() )->validate( 'kein Abo' )['errors'], 'field' ) );
		self::assertNull( ( new SubscriptionValidator() )->validate( $bad )['subscription'] );
	}

	public function test_base64url_accepts_both_alphabets_and_rejects_garbage(): void {
		self::assertSame( "\xfb\xff", Base64Url::decode( '-_8' ) );
		self::assertSame( "\xfb\xff", Base64Url::decode( '+/8=' ) );
		self::assertNull( Base64Url::decode( 'ab$c' ) );
		self::assertNull( Base64Url::decode( 'a' ) );
		self::assertNull( Base64Url::decode( '' ) );
	}
}
