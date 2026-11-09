<?php
/**
 * Kleine Helfer für SQL mit Platzhaltern.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\ReadModel\Support;

final class Sql {

	/**
	 * Begegnungsstatus, die in Tabellen zählen (siehe docs/datenmodell.md).
	 */
	public const COUNTED_STATUSES = array( 'provisional', 'confirmed', 'disputed', 'final', 'forfeit' );

	/**
	 * Platzhalterliste «%d, %d, %d» für IN (…); die Liste darf nicht leer sein.
	 *
	 * @param list<int|string> $values Werte.
	 */
	public static function ints( array $values ): string {
		return implode( ', ', array_fill( 0, max( 1, count( $values ) ), '%d' ) );
	}

	/**
	 * Platzhalterliste «%s, %s» für IN (…).
	 *
	 * @param list<int|string> $values Werte.
	 */
	public static function strings( array $values ): string {
		return implode( ', ', array_fill( 0, max( 1, count( $values ) ), '%s' ) );
	}

	/**
	 * Ganzzahl oder null aus einem Datenbankwert.
	 */
	public static function int_or_null( ?string $value ): ?int {
		return null === $value || '' === $value ? null : (int) $value;
	}

	/**
	 * Eindeutige, positive Ganzzahlen (z. B. Spieler-IDs) ohne null.
	 *
	 * @param iterable<int|string|null> $values Werte.
	 * @return list<int>
	 */
	public static function ids( iterable $values ): array {
		$ids = array();
		foreach ( $values as $value ) {
			if ( null !== $value && '' !== $value && (int) $value > 0 ) {
				$ids[ (int) $value ] = true;
			}
		}
		return array_keys( $ids );
	}
}
