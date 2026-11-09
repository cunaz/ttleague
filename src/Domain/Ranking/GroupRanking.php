<?php
/**
 * Rangliste einer Ranglistenturnier-Gruppe nach dem Spielmodus der Region Zürich.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Domain\Ranking;

use TTLeague\Domain\Rules\SeasonRules;
use TTLeague\Domain\Standings\Ratio;

/**
 * Wertung: Siege. Bei zwei Sieggleichen die direkte Begegnung. Bei drei und mehr die
 * Partien der Sieggleichen untereinander: Satzquotient, dann Ballquotient. Partien
 * abwesender, disqualifizierter oder forfait gebender Personen werden annulliert.
 * Punkte pro Rang: 6-5-4-3-2-1, in einer 7er-Gruppe je ein Zusatzpunkt.
 */
final class GroupRanking {

	public function __construct( private readonly SeasonRules $rules ) {}

	/**
	 * @param array<int|string,RlPlayerStatus> $players Spieler der Gruppe mit Status.
	 * @param list<RlGame>                     $games   Gespielte Partien.
	 * @return list<RlRankRow> Sortiert; annullierte Personen am Ende ohne Rang.
	 */
	public function rank( array $players, array $games ): array {
		$rows = array();
		foreach ( $players as $player => $status ) {
			$rows[ $player ] = new RlRankRow( $player, $status );
		}

		$annulled = array_keys( array_filter( $players, static fn( RlPlayerStatus $s ) => ! $s->is_ranked() ) );
		$valid    = array_values(
			array_filter(
				$games,
				static function ( RlGame $g ) use ( $annulled, $rows ) {
					if ( ! isset( $rows[ $g->player_a ], $rows[ $g->player_b ] ) ) {
						throw new \InvalidArgumentException( 'Partie mit unbekanntem Spieler.' );
					}
					return ! in_array( $g->player_a, $annulled, true ) && ! in_array( $g->player_b, $annulled, true );
				}
			)
		);

		foreach ( $valid as $g ) {
			$this->apply( $rows[ $g->player_a ], $g->sets_a, $g->sets_b, $g->points_a, $g->points_b );
			$this->apply( $rows[ $g->player_b ], $g->sets_b, $g->sets_a, $g->points_b, $g->points_a );
		}

		$ranked = array_values( array_filter( $rows, static fn( RlRankRow $r ) => $r->status->is_ranked() ) );
		$by_win = array();
		foreach ( $ranked as $row ) {
			$by_win[ $row->wins ][] = $row;
		}
		krsort( $by_win, SORT_NUMERIC );

		$ordered = array();
		foreach ( $by_win as $group ) {
			foreach ( $this->split( $group, $valid ) as $sub ) {
				$ordered[] = $sub;
			}
		}

		$group_size = count( $players );
		$result     = array();
		$position   = 0;
		foreach ( $ordered as $sub ) {
			$rank = $position + 1;
			foreach ( $sub as $row ) {
				$row->rank         = $rank;
				$row->shared_rank  = count( $sub ) > 1;
				$row->round_points = $this->rules->rl_points_for_rank( $rank, $group_size );
				$result[]          = $row;
				++$position;
			}
		}
		foreach ( $rows as $row ) {
			if ( ! $row->status->is_ranked() ) {
				$result[] = $row;
			}
		}
		return $result;
	}

	private function apply( RlRankRow $row, int $sets_for, int $sets_against, ?int $points_for, ?int $points_against ): void {
		if ( $sets_for > $sets_against ) {
			++$row->wins;
		} else {
			++$row->losses;
		}
		$row->sets_won  += $sets_for;
		$row->sets_lost += $sets_against;
		if ( null === $points_for || null === $points_against ) {
			$row->points_known = false;
		} else {
			$row->points_for     += $points_for;
			$row->points_against += $points_against;
		}
	}

	/**
	 * @param list<RlRankRow> $group Sieggleiche.
	 * @param list<RlGame>    $games Gültige Partien.
	 * @return list<list<RlRankRow>>
	 */
	private function split( array $group, array $games ): array {
		if ( count( $group ) < 2 ) {
			return array( $group );
		}
		if ( 2 === count( $group ) ) {
			foreach ( $games as $g ) {
				if ( $g->involves( $group[0]->player ) && $g->involves( $group[1]->player ) ) {
					return $g->winner() === $group[0]->player
						? array( array( $group[0] ), array( $group[1] ) )
						: array( array( $group[1] ), array( $group[0] ) );
				}
			}
			return array( $group );
		}

		$ids = array_map( static fn( RlRankRow $r ) => $r->player, $group );
		foreach ( array( 'sets', 'points' ) as $kind ) {
			$totals = array();
			foreach ( $group as $row ) {
				$totals[ $row->player ] = $this->mutual_totals( $row->player, $ids, $games );
			}
			if ( 'points' === $kind ) {
				foreach ( $totals as $t ) {
					if ( ! $t['points_known'] ) {
						continue 2;
					}
				}
			}
			$cmp    = static fn( RlRankRow $a, RlRankRow $b ): int => Ratio::compare(
				$totals[ $a->player ][ $kind . '_for' ],
				$totals[ $a->player ][ $kind . '_against' ],
				$totals[ $b->player ][ $kind . '_for' ],
				$totals[ $b->player ][ $kind . '_against' ]
			);
			$sorted = $group;
			usort( $sorted, static fn( $a, $b ) => $cmp( $b, $a ) );
			$buckets = array( array( $sorted[0] ) );
			$count   = count( $sorted );
			for ( $j = 1; $j < $count; $j++ ) {
				if ( 0 === $cmp( $sorted[ $j - 1 ], $sorted[ $j ] ) ) {
					$buckets[ count( $buckets ) - 1 ][] = $sorted[ $j ];
				} else {
					$buckets[] = array( $sorted[ $j ] );
				}
			}
			if ( count( $buckets ) > 1 ) {
				$result = array();
				foreach ( $buckets as $bucket ) {
					foreach ( $this->split( $bucket, $games ) as $sub ) {
						$result[] = $sub;
					}
				}
				return $result;
			}
		}
		return array( $group );
	}

	/**
	 * @param int|string       $player Spieler.
	 * @param list<int|string> $ids    Sieggleiche.
	 * @param list<RlGame>     $games  Partien.
	 * @return array{sets_for:int,sets_against:int,points_for:int,points_against:int,points_known:bool}
	 */
	private function mutual_totals( int|string $player, array $ids, array $games ): array {
		$t = array(
			'sets_for'       => 0,
			'sets_against'   => 0,
			'points_for'     => 0,
			'points_against' => 0,
			'points_known'   => true,
		);
		foreach ( $games as $g ) {
			if ( ! $g->involves( $player ) ) {
				continue;
			}
			$opponent = $g->player_a === $player ? $g->player_b : $g->player_a;
			if ( ! in_array( $opponent, $ids, true ) ) {
				continue;
			}
			$is_a               = $g->player_a === $player;
			$t['sets_for']     += $is_a ? $g->sets_a : $g->sets_b;
			$t['sets_against'] += $is_a ? $g->sets_b : $g->sets_a;
			if ( null === $g->points_a || null === $g->points_b ) {
				$t['points_known'] = false;
			} else {
				$t['points_for']     += $is_a ? $g->points_a : $g->points_b;
				$t['points_against'] += $is_a ? $g->points_b : $g->points_a;
			}
		}
		return $t;
	}
}
