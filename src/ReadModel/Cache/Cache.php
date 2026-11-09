<?php
/**
 * Zwischenspeicher für berechnete Lesedaten (Tabellen, Statistiken, Ranglisten).
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\ReadModel\Cache;

/**
 * Die Produktion nutzt WpCache (Object-Cache bzw. Transients mit Datenversion im Schlüssel),
 * die Tests NullCache. Gespeichert werden nur einfache Arrays und Skalare.
 */
interface Cache {

	/**
	 * Liefert den gespeicherten Wert oder berechnet und speichert ihn.
	 *
	 * @template T
	 * @param string                        $name    Name des Ergebnisses, z. B. 'season_mm'.
	 * @param array<int|string,scalar|null> $parts   Parameter, die das Ergebnis bestimmen.
	 * @param callable():T                  $compute Berechnung bei fehlendem Eintrag.
	 * @return T
	 */
	public function remember( string $name, array $parts, callable $compute ): mixed;
}
