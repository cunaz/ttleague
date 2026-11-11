<?php
/**
 * Eingaben des Mannschaftscups prüfen: Datum, Termin.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Cup;

final class CupInput {

	/**
	 * Datum «2026-11-30»; null, wenn die Eingabe kein gültiges Datum ist.
	 */
	public static function date( mixed $value ): ?string {
		if ( ! is_string( $value ) || 1 !== preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/D', trim( $value ), $m ) ) {
			return null;
		}
		return checkdate( (int) $m[2], (int) $m[3], (int) $m[1] ) ? trim( $value ) : null;
	}

	/**
	 * Termin «2026-11-30 19:30» oder «2026-11-30T19:30» (Feld datetime-local), auch mit Sekunden.
	 * Liefert «Y-m-d H:i:00» oder null, wenn es den Zeitpunkt in der Zeitzone nicht gibt.
	 */
	public static function datetime( mixed $value, \DateTimeZone $timezone ): ?string {
		if ( ! is_string( $value ) || 1 !== preg_match( '/^(\d{4}-\d{2}-\d{2})[ T](\d{2}:\d{2})(?::\d{2})?$/D', trim( $value ), $m ) ) {
			return null;
		}
		$local = $m[1] . ' ' . $m[2];
		$time  = \DateTimeImmutable::createFromFormat( '!Y-m-d H:i', $local, $timezone );
		if ( false === $time || $time->format( 'Y-m-d H:i' ) !== $local ) {
			return null;
		}
		return $local . ':00';
	}

	/**
	 * Leere Eingabe (null, leere Zeichenkette)?
	 */
	public static function blank( mixed $value ): bool {
		return null === $value || ( is_string( $value ) && '' === trim( $value ) );
	}
}
