<?php
/**
 * ETag und bedingte Anfragen (If-None-Match) für die Kalender-Abos.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Calendar;

/**
 * Reine Funktionen ohne WordPress. Das ETag ist schwach (W/"…"): Es ändert sich mit der
 * Datenversion und den Parametern, nicht mit dem Erstellungszeitpunkt (DTSTAMP) im Inhalt.
 */
final class ConditionalGet {

	/**
	 * Schwaches ETag aus den Teilen, die den Inhalt bestimmen.
	 *
	 * @param list<int|string|null> $parts Datenversion, Feed-Typ, Parameter, Format.
	 */
	public static function etag( array $parts ): string {
		return 'W/"' . substr( hash( 'sha256', implode( "\x1F", array_map( 'strval', $parts ) ) ), 0, 32 ) . '"';
	}

	/**
	 * Passt das ETag zu einem If-None-Match-Kopf? Schwacher Vergleich nach RFC 9110:
	 * «W/» wird ignoriert, mehrere Werte sind durch Kommas getrennt, «*» passt immer.
	 */
	public static function matches( string $header, string $etag ): bool {
		$header = trim( $header );
		if ( '' === $header ) {
			return false;
		}
		if ( '*' === $header ) {
			return true;
		}
		$wanted = self::opaque( $etag );
		foreach ( explode( ',', $header ) as $candidate ) {
			if ( self::opaque( $candidate ) === $wanted ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Wert eines ETags ohne Schwach-Kennzeichen, z. B. «"abc"».
	 */
	private static function opaque( string $etag ): string {
		$etag = trim( $etag );
		if ( 0 === strncasecmp( $etag, 'W/', 2 ) ) {
			$etag = substr( $etag, 2 );
		}
		return trim( $etag );
	}
}
