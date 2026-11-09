<?php
/**
 * Klassierungsskala nach Swiss Table Tennis (Sportreglement 2026): D1 bis A22.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Domain\Rating;

final class ClassificationScale {

	public const MIN = 1;
	public const MAX = 22;

	/**
	 * Bezeichnung mit Buchstaben, z. B. 5 => D5, 6 => C6, 11 => B11, 16 => A16.
	 */
	public static function label( int $classification ): string {
		$c = self::clamp( $classification );
		if ( $c <= 5 ) {
			return 'D' . $c;
		}
		if ( $c <= 10 ) {
			return 'C' . $c;
		}
		if ( $c <= 15 ) {
			return 'B' . $c;
		}
		return 'A' . $c;
	}

	/**
	 * Liest eine Bezeichnung wie "D5", "d5", "A22" oder "5".
	 */
	public static function parse( string $label ): int {
		$label = strtoupper( trim( $label ) );
		if ( ! preg_match( '/^(?:[ABCD])?(\d{1,2})$/', $label, $m ) ) {
			throw new \InvalidArgumentException( 'Ungültige Klassierung: ' . $label );
		}
		$value = (int) $m[1];
		if ( $value < self::MIN || $value > self::MAX ) {
			throw new \InvalidArgumentException( 'Klassierung ausserhalb der Skala: ' . $label );
		}
		return $value;
	}

	/**
	 * Wertungszahl, die der Mitte einer Klassierungsstufe entspricht.
	 */
	public static function rating_for( int $classification, float $points_per_class = 100.0 ): float {
		return self::clamp( $classification ) * $points_per_class;
	}

	/**
	 * Klassierungsstufe zu einer Wertungszahl (gerundet, begrenzt auf die Skala).
	 */
	public static function from_rating( float $rating, float $points_per_class = 100.0 ): int {
		return self::clamp( (int) round( $rating / $points_per_class ) );
	}

	public static function clamp( int $classification ): int {
		return max( self::MIN, min( self::MAX, $classification ) );
	}
}
