<?php
/**
 * Verschlüsselung aes128gcm: Testvektor aus RFC 8291, Anhang A, und Gegenprobe.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Tests\Unit\Notifications\Push;

use PHPUnit\Framework\TestCase;
use TTLeague\Notifications\Push\Base64Url;
use TTLeague\Notifications\Push\Encryption;
use TTLeague\Notifications\Push\P256;

final class EncryptionTest extends TestCase {

	private const PLAINTEXT  = 'When I grow up, I want to be a watermelon';
	private const AS_PUBLIC  = 'BP4z9KsN6nGRTbVYI_c7VJSPQTBtkgcy27mlmlMoZIIgDll6e3vCYLocInmYWAmS6TlzAC8wEqKK6PBru3jl7A8';
	private const AS_PRIVATE = 'yfWPiYE-n46HLnH0KqZOF1fJJU3MYrct3AELtAQ-oRw';
	private const UA_PUBLIC  = 'BCVxsr7N_eNgVRqvHtD0zTZsEc6-VV-JvLexhqUzORcxaOzi6-AYWXvTBHm4bjyPjs7Vd8pZGH6SRpkNtoIAiw4';
	private const UA_PRIVATE = 'q1dXpw3UpT5VOmu_cf_v6ih07Aems3njxI-JWgLcM94';
	private const SALT       = 'DGv6ra1nlYgDCS1FRnbzlw';
	private const AUTH       = 'BTBZMqHH6r4Tts7J_aSIgg';
	private const BODY       = 'DGv6ra1nlYgDCS1FRnbzlwAAEABBBP4z9KsN6nGRTbVYI_c7VJSPQTBtkgcy27mlmlMoZIIgDll6e3vCYLocInmYWAmS6TlzAC8wEqKK6PBru3jl7A_yl95bQpu6cVPTpK4Mqgkf1CXztLVBSt2Ks3oZwbuwXPXLWyouBWLVWGNWQexSgSxsj_Qulcy4a-fN';

	protected function setUp(): void {
		if ( ! P256::supported() ) {
			self::markTestSkipped( 'OpenSSL ohne P-256 oder AES-128-GCM.' );
		}
	}

	private static function raw( string $value ): string {
		return (string) Base64Url::decode( $value );
	}

	public function test_rfc_8291_appendix_a(): void {
		$body = Encryption::encrypt( self::PLAINTEXT, self::raw( self::UA_PUBLIC ), self::raw( self::AUTH ), self::raw( self::AS_PRIVATE ), self::raw( self::SALT ) );
		self::assertSame( self::BODY, Base64Url::encode( (string) $body ) );
	}

	public function test_rfc_8291_appendix_a_decrypts_with_the_private_key_of_the_browser(): void {
		$result = PushTestKit::decrypt( self::raw( self::BODY ), self::raw( self::UA_PRIVATE ), self::raw( self::AUTH ) );
		self::assertSame( self::PLAINTEXT, $result['plaintext'] );
		self::assertSame( 4096, $result['rs'] );
		self::assertSame( self::raw( self::AS_PUBLIC ), $result['keyid'] );
	}

	public function test_each_message_uses_a_new_key_and_salt(): void {
		$browser = PushTestKit::browser();
		$sub     = $browser['subscription'];
		$payload = '{"title":"Bitte bestätigen","body":"Resultat 6:4"}';
		$first   = (string) Encryption::encrypt( $payload, $sub->p256dh, $sub->auth );
		$second  = (string) Encryption::encrypt( $payload, $sub->p256dh, $sub->auth );

		self::assertNotSame( substr( $first, 0, 16 ), substr( $second, 0, 16 ), 'Salz' );
		self::assertNotSame( substr( $first, 21, 65 ), substr( $second, 21, 65 ), 'Schlüssel des Servers' );
		self::assertSame( 86 + strlen( $payload ) + 1 + 16, strlen( $first ), 'Kopf, Klartext, Begrenzer, Tag' );
		self::assertSame( $payload, PushTestKit::decrypt( $first, $browser['private'], $sub->auth )['plaintext'] );
		self::assertSame( $payload, PushTestKit::decrypt( $second, $browser['private'], $sub->auth )['plaintext'] );
	}

	public function test_invalid_keys_and_oversized_payloads_are_refused(): void {
		$sub = PushTestKit::browser()['subscription'];
		self::assertNull( Encryption::encrypt( 'x', $sub->p256dh, substr( $sub->auth, 0, 15 ) ) );
		self::assertNull( Encryption::encrypt( 'x', "\x04" . str_repeat( "\x01", 64 ), $sub->auth ), 'Punkt nicht auf der Kurve' );
		self::assertNull( Encryption::encrypt( 'x', substr( $sub->p256dh, 1 ), $sub->auth ) );
		self::assertNull( Encryption::encrypt( str_repeat( 'x', Encryption::MAX_PLAINTEXT + 1 ), $sub->p256dh, $sub->auth ) );
		self::assertNotNull( Encryption::encrypt( str_repeat( 'x', Encryption::MAX_PLAINTEXT ), $sub->p256dh, $sub->auth ) );
	}
}
