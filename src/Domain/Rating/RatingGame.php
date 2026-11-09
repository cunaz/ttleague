<?php
/**
 * Ein für die Wertungszahl relevantes Spiel (Einzel oder Doppel).
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Domain\Rating;

final class RatingGame {

	/**
	 * @param int       $id          Spiel-ID.
	 * @param string    $played_at   Zeitpunkt 'Y-m-d H:i:s' (Ortszeit), bestimmt die Reihenfolge.
	 * @param int       $season_id   Saison des Spiels.
	 * @param list<int> $side_a      Spieler Seite A (einer im Einzel, zwei im Doppel).
	 * @param list<int> $side_b      Spieler Seite B.
	 * @param bool      $a_won       Seite A hat gewonnen.
	 * @param string    $competition MM, CUP oder RL.
	 */
	public function __construct(
		public readonly int $id,
		public readonly string $played_at,
		public readonly int $season_id,
		public readonly array $side_a,
		public readonly array $side_b,
		public readonly bool $a_won,
		public readonly string $competition = 'MM',
	) {
		$size = count( $side_a );
		if ( $size < 1 || $size > 2 || count( $side_b ) !== $size ) {
			throw new \InvalidArgumentException( 'Ein Spiel braucht auf beiden Seiten gleich viele Spieler (1 oder 2).' );
		}
		if ( array() !== array_intersect( $side_a, $side_b ) ) {
			throw new \InvalidArgumentException( 'Ein Spieler kann nicht auf beiden Seiten stehen.' );
		}
	}

	public function is_double(): bool {
		return 2 === count( $this->side_a );
	}
}
