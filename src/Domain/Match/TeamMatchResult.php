<?php
/**
 * Gesamtresultat einer Begegnung aus den einzelnen Spielen.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Domain\Match;

use TTLeague\Domain\Rules\SeasonRules;

final class TeamMatchResult {

	/**
	 * @param int        $home_score        Gewonnene Spiele Heim (nur gewertete).
	 * @param int        $away_score        Gewonnene Spiele Gast (nur gewertete).
	 * @param int        $home_sets         Gewonnene Sätze Heim (gespielte Spiele).
	 * @param int        $away_sets         Gewonnene Sätze Gast (gespielte Spiele).
	 * @param int|null   $home_points       Satzpunkte Heim, null wenn nicht vollständig erfasst.
	 * @param int|null   $away_points       Satzpunkte Gast.
	 * @param list<int>  $not_counted       Sequenznummern, die nach der Entscheidung nicht mehr zählen (Cup).
	 * @param bool       $decided           Begegnung entschieden.
	 */
	public function __construct(
		public readonly int $home_score,
		public readonly int $away_score,
		public readonly int $home_sets,
		public readonly int $away_sets,
		public readonly ?int $home_points,
		public readonly ?int $away_points,
		public readonly array $not_counted,
		public readonly bool $decided,
	) {}

	/**
	 * Meisterschaftsbegegnung: alle Spiele zählen.
	 *
	 * @param list<GameResult> $games Spiele in beliebiger Reihenfolge.
	 */
	public static function from_team_match( array $games, SeasonRules $rules ): self {
		return self::compute( $games, null, $rules->team_match_games );
	}

	/**
	 * Cup-Begegnung: je nach Modus endet die Wertung beim dritten Sieg.
	 *
	 * @param list<GameResult> $games Spiele in beliebiger Reihenfolge.
	 */
	public static function from_cup( array $games, SeasonRules $rules ): self {
		$target = SeasonRules::CUP_FIRST_TO_THREE === $rules->cup_mode
			? intdiv( $rules->cup_games, 2 ) + 1
			: null;
		return self::compute( $games, $target, $rules->cup_games );
	}

	/**
	 * Forfait-Resultat (0:10 bzw. 10:0).
	 */
	public static function forfeit( Side $winner, SeasonRules $rules ): self {
		$score = $rules->forfeit_score;
		return new self(
			Side::Home === $winner ? $score : 0,
			Side::Away === $winner ? $score : 0,
			0,
			0,
			null,
			null,
			array(),
			true
		);
	}

	public function winner(): ?Side {
		if ( $this->home_score === $this->away_score ) {
			return null;
		}
		return $this->home_score > $this->away_score ? Side::Home : Side::Away;
	}

	/**
	 * @param list<GameResult> $games        Spiele.
	 * @param int|null         $target_wins  Siege, bei denen die Wertung endet (null: alle zählen).
	 * @param int              $max_games    Höchstzahl Spiele, zur Plausibilisierung.
	 */
	private static function compute( array $games, ?int $target_wins, int $max_games ): self {
		if ( count( $games ) > $max_games ) {
			throw new \InvalidArgumentException( sprintf( 'Mehr als %d Spiele übergeben.', $max_games ) );
		}
		$sequences = array_map( static fn( GameResult $g ) => $g->sequence, $games );
		if ( count( $sequences ) !== count( array_unique( $sequences ) ) ) {
			throw new \InvalidArgumentException( 'Doppelte Sequenznummer in den Spielen.' );
		}
		usort( $games, static fn( GameResult $a, GameResult $b ) => $a->sequence <=> $b->sequence );

		$home        = 0;
		$away        = 0;
		$home_sets   = 0;
		$away_sets   = 0;
		$home_points = 0;
		$away_points = 0;
		$points_ok   = true;
		$not_counted = array();
		$decided     = false;

		foreach ( $games as $game ) {
			if ( $decided ) {
				$not_counted[] = $game->sequence;
			} else {
				$winner = $game->winner();
				if ( Side::Home === $winner ) {
					++$home;
				} elseif ( Side::Away === $winner ) {
					++$away;
				}
				if ( null !== $target_wins && ( $home >= $target_wins || $away >= $target_wins ) ) {
					$decided = true;
				}
			}

			if ( $game->counts_for_ratios() ) {
				$home_sets += $game->sets_home;
				$away_sets += $game->sets_away;
				if ( null === $game->points_home || null === $game->points_away ) {
					$points_ok = false;
				} else {
					$home_points += $game->points_home;
					$away_points += $game->points_away;
				}
			}
		}

		if ( null === $target_wins && count( $games ) === $max_games ) {
			$decided = true;
		}

		return new self(
			$home,
			$away,
			$home_sets,
			$away_sets,
			$points_ok && $home_sets + $away_sets > 0 ? $home_points : null,
			$points_ok && $home_sets + $away_sets > 0 ? $away_points : null,
			$not_counted,
			$decided
		);
	}
}
