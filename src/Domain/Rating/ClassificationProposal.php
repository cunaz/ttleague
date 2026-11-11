<?php
/**
 * Vorschlag für die Klassierung der nächsten Saison aus der Wertungszahl.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Domain\Rating;

use TTLeague\Domain\Rules\SeasonRules;

/**
 * Reine Regel (docs/klassierung.md): Der Vorschlag ist die Klassierungsstufe zur Wertungszahl.
 * Wer keine Wertungszahl hat oder in der Saison weniger als classification_min_games gewertete
 * Spiele bestritten hat, behält seine Klassierung. Den Entscheid trifft die TK.
 */
final class ClassificationProposal {

	/**
	 * @param int        $current Klassierung dieser Saison (1 bis 22).
	 * @param float|null $rating  Wertungszahl, null ohne Wertungszahl.
	 * @param int        $games   Gewertete Spiele in dieser Saison (Einzel und Doppel).
	 */
	public static function propose( int $current, ?float $rating, int $games, SeasonRules $rules ): int {
		$current = ClassificationScale::clamp( $current );
		if ( null === $rating || $games < $rules->classification_min_games ) {
			return $current;
		}
		return ClassificationScale::from_rating( $rating, $rules->rating_points_per_class );
	}

	/**
	 * Abweichung des Vorschlags von der bisherigen Klassierung in Stufen (positiv: höher).
	 */
	public static function deviation( int $current, int $proposed ): int {
		return $proposed - $current;
	}
}
