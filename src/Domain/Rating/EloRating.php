<?php
/**
 * Elo-artige Wertungszahl: transparent, kalibrierbar, pro Spiel.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Domain\Rating;

use TTLeague\Domain\Rules\SeasonRules;

final class EloRating {

	public function __construct( private readonly SeasonRules $rules ) {}

	/**
	 * Erwarteter Spielausgang für A gegen B (0..1).
	 */
	public function expected( float $rating_a, float $rating_b ): float {
		return 1.0 / ( 1.0 + 10 ** ( ( $rating_b - $rating_a ) / $this->rules->rating_scale ) );
	}

	/**
	 * Änderung der Wertungszahl von A nach einem Spiel.
	 *
	 * @param float $score_a 1 Sieg, 0 Niederlage.
	 * @param float $weight  Gewicht, z. B. 0.5 für Doppel.
	 */
	public function delta( float $rating_a, float $rating_b, float $score_a, float $weight = 1.0 ): float {
		return $this->rules->rating_k * $weight * ( $score_a - $this->expected( $rating_a, $rating_b ) );
	}

	/**
	 * Neue Wertungszahlen beider Seiten nach einem Einzel.
	 *
	 * @return array{0:float,1:float} Neue Werte für A und B.
	 */
	public function update_single( float $rating_a, float $rating_b, bool $a_won ): array {
		$d = $this->delta( $rating_a, $rating_b, $a_won ? 1.0 : 0.0 );
		return array( $rating_a + $d, $rating_b - $d );
	}

	/**
	 * Doppel: Jede Seite wird durch den Durchschnitt ihres Paares vertreten,
	 * jede Person erhält die gewichtete Änderung.
	 *
	 * @param array{0:float,1:float} $pair_a Wertungszahlen Paar A.
	 * @param array{0:float,1:float} $pair_b Wertungszahlen Paar B.
	 * @return array{0:array{0:float,1:float},1:array{0:float,1:float}} Neue Werte.
	 */
	public function update_double( array $pair_a, array $pair_b, bool $a_won ): array {
		$avg_a = ( $pair_a[0] + $pair_a[1] ) / 2;
		$avg_b = ( $pair_b[0] + $pair_b[1] ) / 2;
		$d     = $this->delta( $avg_a, $avg_b, $a_won ? 1.0 : 0.0, $this->rules->rating_doubles_weight );
		return array(
			array( $pair_a[0] + $d, $pair_a[1] + $d ),
			array( $pair_b[0] - $d, $pair_b[1] - $d ),
		);
	}

	/**
	 * Startwert aus der offiziellen Klassierung.
	 */
	public function initial_rating( int $classification ): float {
		return ClassificationScale::rating_for( $classification, $this->rules->rating_points_per_class );
	}

	/**
	 * Klassierungsvorschlag aus der Wertungszahl.
	 */
	public function classification_for( float $rating ): int {
		return ClassificationScale::from_rating( $rating, $this->rules->rating_points_per_class );
	}
}
