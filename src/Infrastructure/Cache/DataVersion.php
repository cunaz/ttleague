<?php
/**
 * Versionszähler der Spielbetriebsdaten für die Cache-Invalidierung.
 *
 * Jeder schreibende Vorgang (Import, Resultaterfassung, Korrektur) ruft bump() auf.
 * Lesende Komponenten nehmen get() in ihre Cache-Schlüssel auf; damit werden alle
 * abgeleiteten Daten (Tabellen, Statistiken, Ranglisten) nach einer Änderung neu berechnet.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Infrastructure\Cache;

final class DataVersion {

	public const OPTION = 'ttleague_data_version';

	public static function get(): int {
		return (int) get_option( self::OPTION, 1 );
	}

	public static function bump(): int {
		$next = self::get() + 1;
		update_option( self::OPTION, $next, true );
		wp_cache_delete( self::OPTION, 'options' );
		return $next;
	}

	/**
	 * Cache-Schlüssel mit eingebauter Datenversion.
	 *
	 * @param string                          $name  Name des Ergebnisses.
	 * @param array<int|string,scalar|null>   $parts Parameter, die das Ergebnis bestimmen.
	 */
	public static function key( string $name, array $parts = array() ): string {
		return 'ttl_' . $name . '_' . self::get() . '_' . md5( (string) wp_json_encode( $parts ) );
	}
}
