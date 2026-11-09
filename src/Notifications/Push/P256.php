<?php
/**
 * Schlüssel auf der Kurve P-256 (prime256v1) mit der OpenSSL-Erweiterung von PHP.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Notifications\Push;

/**
 * Öffentliche Schlüssel sind unkomprimierte Punkte (65 Byte: 0x04, X, Y), private Schlüssel der
 * Skalar d (32 Byte), beides wie in Web Push (RFC 8291, RFC 8292). OpenSSL erwartet PEM; die
 * DER-Strukturen werden hier gebaut: SubjectPublicKeyInfo (RFC 5480) und ECPrivateKey
 * (RFC 5915, ohne öffentlichen Teil, OpenSSL berechnet ihn beim Laden).
 */
final class P256 {

	public const PUBLIC_LENGTH  = 65;
	public const PRIVATE_LENGTH = 32;

	/** SubjectPublicKeyInfo mit id-ecPublicKey und prime256v1, danach BIT STRING mit 66 Byte. */
	private const SPKI_PREFIX = "\x30\x59\x30\x13\x06\x07\x2a\x86\x48\xce\x3d\x02\x01\x06\x08\x2a\x86\x48\xce\x3d\x03\x01\x07\x03\x42\x00";

	/** ECPrivateKey: SEQUENCE, Version 1, OCTET STRING mit 32 Byte. */
	private const SEC1_PREFIX = "\x30\x31\x02\x01\x01\x04\x20";

	/** [0] Parameter: prime256v1. */
	private const SEC1_CURVE = "\xa0\x0a\x06\x08\x2a\x86\x48\xce\x3d\x03\x01\x07";

	/**
	 * Stehen die nötigen Funktionen bereit (P-256, ECDH, AES-128-GCM)?
	 */
	public static function supported(): bool {
		if ( ! function_exists( 'openssl_pkey_new' ) || ! function_exists( 'openssl_pkey_derive' ) || ! defined( 'OPENSSL_KEYTYPE_EC' ) ) {
			return false;
		}
		$curves = openssl_get_curve_names();
		return is_array( $curves ) && in_array( 'prime256v1', $curves, true )
			&& in_array( 'aes-128-gcm', openssl_get_cipher_methods(), true );
	}

	/**
	 * Neues Schlüsselpaar.
	 *
	 * @return array{private:string,public:string}|null Rohschlüssel.
	 */
	public static function generate(): ?array {
		$key = openssl_pkey_new(
			array(
				'private_key_type' => OPENSSL_KEYTYPE_EC,
				'curve_name'       => 'prime256v1',
			)
		);
		if ( false === $key ) {
			self::clear_errors();
			return null;
		}
		$details = openssl_pkey_get_details( $key );
		if ( ! is_array( $details ) || ! isset( $details['ec']['d'], $details['ec']['x'], $details['ec']['y'] ) ) {
			return null;
		}
		return array(
			'private' => self::pad( (string) $details['ec']['d'] ),
			'public'  => "\x04" . self::pad( (string) $details['ec']['x'] ) . self::pad( (string) $details['ec']['y'] ),
		);
	}

	/**
	 * Privater Schlüssel aus dem Skalar d.
	 */
	public static function private_key( string $scalar ): ?\OpenSSLAsymmetricKey {
		if ( self::PRIVATE_LENGTH !== strlen( $scalar ) ) {
			return null;
		}
		$key = openssl_pkey_get_private( self::pem( 'EC PRIVATE KEY', self::SEC1_PREFIX . $scalar . self::SEC1_CURVE ) );
		if ( false === $key ) {
			self::clear_errors();
			return null;
		}
		return $key;
	}

	/**
	 * Öffentlicher Schlüssel aus dem unkomprimierten Punkt; null, wenn er nicht auf der Kurve liegt.
	 */
	public static function public_key( string $point ): ?\OpenSSLAsymmetricKey {
		if ( self::PUBLIC_LENGTH !== strlen( $point ) || "\x04" !== $point[0] ) {
			return null;
		}
		$key = openssl_pkey_get_public( self::pem( 'PUBLIC KEY', self::SPKI_PREFIX . $point ) );
		if ( false === $key ) {
			self::clear_errors();
			return null;
		}
		return $key;
	}

	/**
	 * Unkomprimierter Punkt eines geladenen Schlüssels.
	 */
	public static function point( \OpenSSLAsymmetricKey $key ): ?string {
		$details = openssl_pkey_get_details( $key );
		if ( ! is_array( $details ) || ! isset( $details['ec']['x'], $details['ec']['y'] ) ) {
			return null;
		}
		return "\x04" . self::pad( (string) $details['ec']['x'] ) . self::pad( (string) $details['ec']['y'] );
	}

	/**
	 * ECDH: gemeinsames Geheimnis (X-Koordinate, 32 Byte).
	 */
	public static function shared_secret( \OpenSSLAsymmetricKey $own_key, string $peer_point ): ?string {
		$peer = self::public_key( $peer_point );
		if ( null === $peer ) {
			return null;
		}
		$secret = openssl_pkey_derive( $peer, $own_key, self::PRIVATE_LENGTH );
		if ( false === $secret ) {
			self::clear_errors();
			return null;
		}
		return self::pad( $secret );
	}

	/**
	 * Signatur im DER-Format (ECDSA-Sig-Value) in die feste Form r||s (je 32 Byte) für JWS.
	 */
	public static function der_to_raw( string $der ): ?string {
		if ( strlen( $der ) < 8 || "\x30" !== $der[0] ) {
			return null;
		}
		$offset = 2;
		if ( ord( $der[1] ) > 0x80 ) {
			$offset += ord( $der[1] ) - 0x80;
		}
		$parts = array();
		for ( $i = 0; $i < 2; $i++ ) {
			if ( ! isset( $der[ $offset + 1 ] ) || "\x02" !== $der[ $offset ] ) {
				return null;
			}
			$length = ord( $der[ $offset + 1 ] );
			$value  = substr( $der, $offset + 2, $length );
			if ( strlen( $value ) !== $length ) {
				return null;
			}
			$value = ltrim( $value, "\x00" );
			if ( strlen( $value ) > 32 ) {
				return null;
			}
			$parts[] = self::pad( $value );
			$offset += 2 + $length;
		}
		return $parts[0] . $parts[1];
	}

	/**
	 * Signatur r||s (64 Byte) zurück in DER, z. B. zum Prüfen mit openssl_verify().
	 */
	public static function raw_to_der( string $raw ): ?string {
		if ( 64 !== strlen( $raw ) ) {
			return null;
		}
		$body = '';
		foreach ( array( substr( $raw, 0, 32 ), substr( $raw, 32 ) ) as $part ) {
			$part = ltrim( $part, "\x00" );
			if ( '' === $part || ord( $part[0] ) >= 0x80 ) {
				$part = "\x00" . $part;
			}
			$body .= "\x02" . chr( strlen( $part ) ) . $part;
		}
		return "\x30" . chr( strlen( $body ) ) . $body;
	}

	/**
	 * Auf 32 Byte auffüllen (OpenSSL lässt führende Nullbytes weg).
	 */
	private static function pad( string $value ): string {
		return str_pad( $value, self::PRIVATE_LENGTH, "\x00", STR_PAD_LEFT );
	}

	private static function pem( string $label, string $der ): string {
		return '-----BEGIN ' . $label . "-----\n" . chunk_split( base64_encode( $der ), 64, "\n" ) . '-----END ' . $label . "-----\n"; // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- PEM-Kodierung eines Schlüssels.
	}

	/**
	 * Fehlerwarteschlange von OpenSSL leeren, damit spätere Aufrufe keine alten Meldungen sehen.
	 */
	private static function clear_errors(): void {
		while ( false !== openssl_error_string() ) {
			continue;
		}
	}
}
