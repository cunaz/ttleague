<?php
/**
 * Kategorien des Ranglistenturniers: Grenzen der Klassierung.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Domain\Ranking;

/**
 * Die Grenzen stehen in competition.format_json unter «categories», z. B.
 * [{"no":1,"min":16},{"no":2,"min":9},{"no":3,"min":1}]: Eine Klassierung ab «min» gehört zur
 * Kategorie; passen mehrere, gilt die höchste Grenze. Kategorie 0 heisst «keine Kategorie».
 */
final class Categories {

	/** Keine Kategorie: Die TK setzt sie von Hand. */
	public const NONE = 0;

	/**
	 * Gültige Kategorien aus den Rohdaten, höchste Grenze zuerst. Ungültige Einträge und
	 * doppelte Nummern fallen weg.
	 *
	 * @param mixed $raw Wert aus format_json.
	 * @return list<array{no:int,min:int}>
	 */
	public static function normalize( mixed $raw ): array {
		$categories = array();
		foreach ( is_array( $raw ) ? $raw : array() as $entry ) {
			if ( ! is_array( $entry ) || ! is_numeric( $entry['no'] ?? null ) || ! is_numeric( $entry['min'] ?? null ) ) {
				continue;
			}
			$no  = (int) $entry['no'];
			$min = (int) $entry['min'];
			if ( $no < 1 || $no > 255 || $min < 0 || isset( $categories[ $no ] ) ) {
				continue;
			}
			$categories[ $no ] = array(
				'no'  => $no,
				'min' => $min,
			);
		}
		$categories = array_values( $categories );
		usort( $categories, static fn( array $a, array $b ): int => array( $b['min'], $a['no'] ) <=> array( $a['min'], $b['no'] ) );
		return $categories;
	}

	/**
	 * Kategorie einer Klassierung, NONE ohne passende Grenze.
	 *
	 * @param list<array{no:int,min:int}> $categories Kategorien aus normalize().
	 */
	public static function for_classification( int $classification, array $categories ): int {
		foreach ( self::normalize( $categories ) as $category ) {
			if ( $classification >= $category['min'] ) {
				return $category['no'];
			}
		}
		return self::NONE;
	}

	/**
	 * Nummern der Kategorien, aufsteigend.
	 *
	 * @param list<array{no:int,min:int}> $categories Kategorien.
	 * @return list<int>
	 */
	public static function numbers( array $categories ): array {
		$numbers = array_column( self::normalize( $categories ), 'no' );
		sort( $numbers );
		return $numbers;
	}
}
