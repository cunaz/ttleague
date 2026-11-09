<?php
/**
 * Einzelpartie in einer Ranglistenturnier-Gruppe.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Domain\Ranking;

final class RlGame {

	/**
	 * @param int|string $player_a Spieler A.
	 * @param int|string $player_b Spieler B.
	 * @param int        $sets_a   Sätze A.
	 * @param int        $sets_b   Sätze B.
	 * @param int|null   $points_a Satzpunkte A (optional).
	 * @param int|null   $points_b Satzpunkte B (optional).
	 */
	public function __construct(
		public readonly int|string $player_a,
		public readonly int|string $player_b,
		public readonly int $sets_a,
		public readonly int $sets_b,
		public readonly ?int $points_a = null,
		public readonly ?int $points_b = null,
	) {
		if ( $player_a === $player_b ) {
			throw new \InvalidArgumentException( 'Ein Spieler kann nicht gegen sich selbst spielen.' );
		}
		if ( $sets_a === $sets_b ) {
			throw new \InvalidArgumentException( 'Eine Partie braucht einen Sieger.' );
		}
	}

	public function winner(): int|string {
		return $this->sets_a > $this->sets_b ? $this->player_a : $this->player_b;
	}

	public function involves( int|string $player ): bool {
		return $this->player_a === $player || $this->player_b === $player;
	}
}
