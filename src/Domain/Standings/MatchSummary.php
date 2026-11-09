<?php
/**
 * Gewertete Begegnung als Eingabe für den Tabellenrechner.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Domain\Standings;

final class MatchSummary {

	/**
	 * @param int|string $home_team   Team-ID Heim.
	 * @param int|string $away_team   Team-ID Gast.
	 * @param int        $home_score  Gewonnene Spiele Heim.
	 * @param int        $away_score  Gewonnene Spiele Gast.
	 * @param int        $home_sets   Gewonnene Sätze Heim.
	 * @param int        $away_sets   Gewonnene Sätze Gast.
	 * @param int|null   $home_points Satzpunkte Heim, null wenn nicht erfasst.
	 * @param int|null   $away_points Satzpunkte Gast.
	 */
	public function __construct(
		public readonly int|string $home_team,
		public readonly int|string $away_team,
		public readonly int $home_score,
		public readonly int $away_score,
		public readonly int $home_sets = 0,
		public readonly int $away_sets = 0,
		public readonly ?int $home_points = null,
		public readonly ?int $away_points = null,
	) {
		if ( $home_team === $away_team ) {
			throw new \InvalidArgumentException( 'Heim und Gast dürfen nicht dasselbe Team sein.' );
		}
	}

	public function involves( int|string $team ): bool {
		return $this->home_team === $team || $this->away_team === $team;
	}
}
