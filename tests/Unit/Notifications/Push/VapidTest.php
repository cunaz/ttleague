<?php
/**
 * VAPID (RFC 8292): Schlüssel, JWT mit ES256, Umwandlung der Signatur.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Tests\Unit\Notifications\Push;

use PHPUnit\Framework\TestCase;
use TTLeague\Notifications\Push\Base64Url;
use TTLeague\Notifications\Push\P256;
use TTLeague\Notifications\Push\Vapid;

final class VapidTest extends TestCase {

	protected function setUp(): void {
		if ( ! P256::supported() ) {
			self::markTestSkipped( 'OpenSSL ohne P-256.' );
		}
	}

	public function test_rfc_8292_example_token_verifies_with_openssl(): void {
		$header = 'vapid t=eyJ0eXAiOiJKV1QiLCJhbGciOiJFUzI1NiJ9.eyJhdWQiOiJodHRwczovL3B1c2guZXhhbXBsZS5uZXQiLCJleHAiOjE0NTM1MjM3NjgsInN1YiI6Im1haWx0bzpwdXNoQGV4YW1wbGUuY29tIn0.i3CYb7t4xfxCDquptFOepC9GAu_HLGkMlMuCGSK2rpiUfnK9ojFwDXb1JrErtmysazNjjvW2L9OkSSHzvoD1oA, k=BA1Hxzyi1RUM1b5wjxsn7nGxAszw2u61m164i3MrAIxHF6YK5h4SDYic-dRuU_RCPCfA5aq9ojSwk5Y2EmClBPs';
		$claims = PushTestKit::verify_vapid( $header );
		self::assertSame( 'https://push.example.net', $claims['aud'] );
		self::assertSame( 'mailto:push@example.com', $claims['sub'] );
	}

	public function test_generated_keys_sign_a_verifiable_jwt(): void {
		$keys  = Vapid::generate();
		$vapid = Vapid::from_base64url( $keys['private'], $keys['public'] );
		self::assertNotNull( $vapid );
		self::assertSame( $keys['public'], $vapid->public_key() );
		self::assertSame( 65, strlen( (string) Base64Url::decode( $keys['public'] ) ) );
		self::assertSame( 32, strlen( (string) Base64Url::decode( $keys['private'] ) ) );

		$header = $vapid->authorization( 'https://fcm.googleapis.com/fcm/send/abc:def', 'mailto:liga@example.org', 1_800_000_000 );
		self::assertStringStartsWith( 'vapid t=', $header );
		self::assertStringEndsWith( ', k=' . $keys['public'], $header );

		$claims = PushTestKit::verify_vapid( $header );
		self::assertSame(
			array(
				'aud' => 'https://fcm.googleapis.com',
				'exp' => 1_800_000_000 + Vapid::TTL,
				'sub' => 'mailto:liga@example.org',
			),
			$claims
		);
		self::assertLessThanOrEqual( 86400, Vapid::TTL, 'höchstens 24 Stunden' );

		$parts = explode( '.', substr( $header, 8, strpos( $header, ',' ) - 8 ) );
		self::assertSame( '{"typ":"JWT","alg":"ES256"}', Base64Url::decode( $parts[0] ) );
		self::assertSame( 64, strlen( (string) Base64Url::decode( $parts[2] ) ), 'Signatur r||s statt DER' );
	}

	public function test_tampered_jwt_does_not_verify(): void {
		$keys   = Vapid::generate();
		$vapid  = Vapid::from_base64url( $keys['private'] );
		$header = $vapid->authorization( 'https://updates.push.services.mozilla.com/wpush/v2/abc', 'mailto:liga@example.org', 1_800_000_000 );
		$other  = str_replace( 'mozilla', 'mozillx', $header );
		$this->expectException( \RuntimeException::class );
		PushTestKit::verify_vapid( preg_replace( '/t=([^.]+)\.([^.]+)\./', 't=$1.' . Base64Url::encode( '{"aud":"https://example.org"}' ) . '.', $other ) );
	}

	public function test_public_key_is_derived_and_must_match(): void {
		$keys    = Vapid::generate();
		$another = Vapid::generate();
		self::assertSame( $keys['public'], Vapid::from_base64url( $keys['private'] )->public_key(), 'ohne öffentlichen Schlüssel berechnet' );
		self::assertNull( Vapid::from_base64url( $keys['private'], $another['public'] ), 'passt nicht zusammen' );
		self::assertNull( Vapid::from_base64url( 'kein-schlüssel' ) );
		self::assertNull( Vapid::from_base64url( Base64Url::encode( str_repeat( "\x00", 32 ) ) ), 'd = 0 ist kein Schlüssel' );
		self::assertNull( Vapid::from_base64url( $keys['private'], '%%%' ) );
	}

	public function test_audience_is_the_origin_of_the_endpoint(): void {
		self::assertSame( 'https://web.push.apple.com', Vapid::audience( 'https://web.push.apple.com/QGuQyavXutnMH/abc' ) );
		self::assertSame( 'http://127.0.0.1:8095', Vapid::audience( 'http://127.0.0.1:8095/push/1' ) );
		self::assertNull( Vapid::audience( '/push/1' ) );
	}

	public function test_der_signature_conversion_handles_padding(): void {
		$r   = "\x00\xff" . str_repeat( "\x11", 31 );
		$s   = str_repeat( "\x22", 31 );
		$der = "\x30\x44\x02\x21" . $r . "\x02\x1f" . $s;
		$raw = P256::der_to_raw( $der );
		self::assertSame( "\xff" . str_repeat( "\x11", 31 ) . "\x00" . $s, $raw, 'r ohne Vorzeichenbyte, s auf 32 Byte aufgefüllt' );
		self::assertSame( $der, P256::raw_to_der( (string) $raw ) );
		self::assertNull( P256::der_to_raw( 'kein DER' ) );
		self::assertNull( P256::der_to_raw( "\x30\x06\x02\x01\x01\x03\x01\x01" ) );
	}
}
