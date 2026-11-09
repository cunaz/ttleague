<?php
/**
 * Änderung der Wertungszahl eines Spielers durch ein Spiel.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Domain\Rating;

final class RatingEvent {

	public function __construct(
		public readonly int $player_id,
		public readonly int $game_id,
		public readonly float $before,
		public readonly float $after,
		public readonly string $played_at,
	) {}

	public function delta(): float {
		return $this->after - $this->before;
	}
}
