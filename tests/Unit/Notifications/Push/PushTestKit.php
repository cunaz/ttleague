<?php
/**
 * Hilfen für Tests von Web Push: Abo eines Browsers erzeugen, Nachricht entschlüsseln.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Tests\Unit\Notifications\Push;

use TTLeague\Notifications\Push\Base64Url;
use TTLeague\Notifications\Push\P256;
use TTLeague\Notifications\Push\Subscription;

/**
 * Spielt die Rolle des Browsers (user agent): eigenes Schlüsselpaar und Geheimnis, entschlüsselt
 * nach RFC 8291 unabhängig vom Code des Servers.
 */
final class PushTestKit {

	/**
	 * Abo mit eigenem Schlüsselpaar.
	 *
	 * @return array{subscription:Subscription,private:string,json:array{endpoint:string,keys:array{p256dh:string,auth:string}}}
	 */
	public static function browser( string $endpoint = 'https://fcm.googleapis.com/fcm/send/test-abo' ): array {
		$pair = P256::generate();
		if ( null === $pair ) {
			throw new \RuntimeException( 'Kein Schlüsselpaar.' );
		}
		$auth = random_bytes( 16 );
		return array(
			'subscription' => new Subscription( $endpoint, $pair['public'], $auth ),
			'private'      => $pair['private'],
			'json'         => array(
				'endpoint' => $endpoint,
				'keys'     => array(
					'p256dh' => Base64Url::encode( $pair['public'] ),
					'auth'   => Base64Url::encode( $auth ),
				),
			),
		);
	}

	/**
	 * Entschlüsseln wie ein Browser (ein Datensatz, aes128gcm).
	 *
	 * @return array{plaintext:string,rs:int,keyid:string}
	 */
	public static function decrypt( string $body, string $ua_private, string $auth ): array {
		$salt   = substr( $body, 0, 16 );
		$rs     = (int) unpack( 'N', substr( $body, 16, 4 ) )[1];
		$idlen  = ord( $body[20] );
		$keyid  = substr( $body, 21, $idlen );
		$cipher = substr( $body, 21 + $idlen );

		$key       = P256::private_key( $ua_private );
		$ua_public = null === $key ? null : P256::point( $key );
		$secret    = null === $key ? null : P256::shared_secret( $key, $keyid );
		if ( null === $ua_public || null === $secret ) {
			throw new \RuntimeException( 'Ungültige Schlüssel.' );
		}
		$ikm    = hash_hkdf( 'sha256', $secret, 32, "WebPush: info\x00" . $ua_public . $keyid, $auth );
		$cek    = hash_hkdf( 'sha256', $ikm, 16, "Content-Encoding: aes128gcm\x00", $salt );
		$nonce  = hash_hkdf( 'sha256', $ikm, 12, "Content-Encoding: nonce\x00", $salt );
		$padded = openssl_decrypt( substr( $cipher, 0, -16 ), 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, substr( $cipher, -16 ) );
		if ( false === $padded ) {
			throw new \RuntimeException( 'Entschlüsselung fehlgeschlagen.' );
		}
		$end = strrpos( $padded, "\x02" );
		if ( false === $end || '' !== trim( substr( $padded, $end + 1 ), "\x00" ) ) {
			throw new \RuntimeException( 'Kein Begrenzer am Ende.' );
		}
		return array(
			'plaintext' => substr( $padded, 0, $end ),
			'rs'        => $rs,
			'keyid'     => $keyid,
		);
	}

	/**
	 * JWT aus «vapid t=…, k=…» prüfen; liefert die Claims.
	 *
	 * @return array<string,mixed>
	 */
	public static function verify_vapid( string $authorization ): array {
		if ( 1 !== preg_match( '/^vapid t=([A-Za-z0-9_-]+)\.([A-Za-z0-9_-]+)\.([A-Za-z0-9_-]+), k=([A-Za-z0-9_-]+)$/', $authorization, $m ) ) {
			throw new \RuntimeException( 'Kein VAPID-Header.' );
		}
		$public = P256::public_key( (string) Base64Url::decode( $m[4] ) );
		$der    = P256::raw_to_der( (string) Base64Url::decode( $m[3] ) );
		if ( null === $public || null === $der || 1 !== openssl_verify( $m[1] . '.' . $m[2], $der, $public, OPENSSL_ALGO_SHA256 ) ) {
			throw new \RuntimeException( 'Signatur ungültig.' );
		}
		return (array) json_decode( (string) Base64Url::decode( $m[2] ), true );
	}
}
