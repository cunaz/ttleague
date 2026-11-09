<?php
/**
 * Base64url ohne Auffüllzeichen (RFC 4648, Abschnitt 5), wie in Web Push und JWT üblich.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Notifications\Push;

final class Base64Url {

	public static function encode( string $data ): string {
		return rtrim( strtr( base64_encode( $data ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Kodierung von Schlüsseln und Nachrichten.
	}

	/**
	 * Dekodieren; null bei ungültigen Zeichen. Standard-Base64 mit +, / und = wird ebenfalls angenommen.
	 */
	public static function decode( string $data ): ?string {
		$data = trim( $data );
		if ( '' === $data || 1 !== preg_match( '/^[A-Za-z0-9\-_+\/]+=*$/', $data ) ) {
			return null;
		}
		$data = strtr( rtrim( $data, '=' ), '-_', '+/' );
		$pad  = strlen( $data ) % 4;
		if ( 1 === $pad ) {
			return null;
		}
		$decoded = base64_decode( $data . str_repeat( '=', ( 4 - $pad ) % 4 ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Kodierung von Schlüsseln und Nachrichten.
		return false === $decoded ? null : $decoded;
	}
}
