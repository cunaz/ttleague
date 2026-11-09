<?php
/**
 * Verschlüsselung einer Push-Nachricht mit aes128gcm (RFC 8291, RFC 8188).
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Notifications\Push;

/**
 * Pro Nachricht ein neues Schlüsselpaar des Servers (as) und ein zufälliges Salz:
 *
 *     ecdh_secret = ECDH(as_private, ua_public)
 *     IKM   = HKDF(salt = auth_secret, ikm = ecdh_secret, info = "WebPush: info" 0x00 ua_public as_public, 32)
 *     CEK   = HKDF(salt, IKM, "Content-Encoding: aes128gcm" 0x00, 16)
 *     NONCE = HKDF(salt, IKM, "Content-Encoding: nonce" 0x00, 12)
 *     Body  = salt (16) | rs (4, big endian) | idlen (1) | as_public (65) | AES-128-GCM(Nachricht 0x02) | Tag (16)
 *
 * Ein einziger Datensatz mit dem Begrenzer 0x02 und ohne weitere Auffüllung. Der Push-Dienst
 * sieht nur den verschlüsselten Inhalt.
 */
final class Encryption {

	public const RECORD_SIZE = 4096;

	/** Höchstlänge des Klartexts in einem Datensatz (Datensatz minus Tag und Begrenzer). */
	public const MAX_PLAINTEXT = self::RECORD_SIZE - 17;

	/**
	 * @param string      $payload    Klartext.
	 * @param string      $ua_public  Öffentlicher Schlüssel des Abos (p256dh, 65 Byte).
	 * @param string      $auth       Authentisierungsgeheimnis des Abos (16 Byte).
	 * @param string|null $as_private Privater Schlüssel des Servers (32 Byte); nur für Tests, sonst neu.
	 * @param string|null $salt       Salz (16 Byte); nur für Tests, sonst zufällig.
	 * @return string|null Body der Anfrage oder null, wenn die Schlüssel ungültig sind.
	 */
	public static function encrypt( string $payload, string $ua_public, string $auth, ?string $as_private = null, ?string $salt = null ): ?string {
		if ( strlen( $payload ) > self::MAX_PLAINTEXT || 16 !== strlen( $auth ) ) {
			return null;
		}
		$as_private = $as_private ?? P256::generate()['private'] ?? null;
		$key        = null === $as_private ? null : P256::private_key( $as_private );
		if ( null === $key ) {
			return null;
		}
		$as_public = P256::point( $key );
		$secret    = P256::shared_secret( $key, $ua_public );
		if ( null === $as_public || null === $secret ) {
			return null;
		}
		$salt = $salt ?? random_bytes( 16 );
		if ( 16 !== strlen( $salt ) ) {
			return null;
		}

		$ikm   = hash_hkdf( 'sha256', $secret, 32, "WebPush: info\x00" . $ua_public . $as_public, $auth );
		$cek   = hash_hkdf( 'sha256', $ikm, 16, "Content-Encoding: aes128gcm\x00", $salt );
		$nonce = hash_hkdf( 'sha256', $ikm, 12, "Content-Encoding: nonce\x00", $salt );

		$tag    = '';
		$cipher = openssl_encrypt( $payload . "\x02", 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, $tag, '', 16 );
		if ( false === $cipher ) {
			return null;
		}
		return $salt . pack( 'N', self::RECORD_SIZE ) . chr( strlen( $as_public ) ) . $as_public . $cipher . $tag;
	}
}
