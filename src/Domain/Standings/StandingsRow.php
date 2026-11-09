<?php
/**
 * Zeile einer Tabelle.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Domain\Standings;

final class StandingsRow {

	public int $rank           = 0;
	public int $played         = 0;
	public int $match_wins     = 0;
	public int $match_draws    = 0;
	public int $match_losses   = 0;
	public int $games_won      = 0;
	public int $games_lost     = 0;
	public int $sets_won       = 0;
	public int $sets_lost      = 0;
	public int $points_for     = 0;
	public int $points_against = 0;
	public bool $points_known  = true;
	/** Hinweis, wenn die Rangfolge nicht eindeutig ist (Art. 15). */
	public ?string $tiebreak_note = null;

	public function __construct( public readonly int|string $team ) {}

	/**
	 * Tabellenpunkte: Summe der gewonnenen Spiele (Art. 14.4).
	 */
	public function points(): int {
		return $this->games_won;
	}

	public function add( MatchSummary $summary ): void {
		$is_home = $summary->home_team === $this->team;
		$for     = $is_home ? $summary->home_score : $summary->away_score;
		$against = $is_home ? $summary->away_score : $summary->home_score;

		++$this->played;
		$this->games_won  += $for;
		$this->games_lost += $against;
		$this->sets_won   += $is_home ? $summary->home_sets : $summary->away_sets;
		$this->sets_lost  += $is_home ? $summary->away_sets : $summary->home_sets;

		if ( null === $summary->home_points || null === $summary->away_points ) {
			$this->points_known = false;
		} else {
			$this->points_for     += $is_home ? $summary->home_points : $summary->away_points;
			$this->points_against += $is_home ? $summary->away_points : $summary->home_points;
		}

		if ( $for > $against ) {
			++$this->match_wins;
		} elseif ( $for < $against ) {
			++$this->match_losses;
		} else {
			++$this->match_draws;
		}
	}
}
