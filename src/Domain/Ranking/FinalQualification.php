<?php
/**
 * Qualifikation für die Finalrunde des Ranglistenturniers.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Domain\Ranking;

use TTLeague\Domain\Rules\SeasonRules;
use TTLeague\Domain\Standings\Ratio;

/**
 * Punkte der Runden 1 bis 3 werden addiert. Bei Punktgleichheit entscheiden über alle Spiele
 * der Satzquotient, dann der Ballquotient, sonst das Los.
 */
final class FinalQualification {

	public function __construct( private readonly SeasonRules $rules ) {}

	/**
	 * @param array<int|string,array{points:int,sets_won:int,sets_lost:int,points_for?:int|null,points_against?:int|null}> $totals Spieler => Saisonsummen.
	 * @return list<array{player:int|string,rank:int,points:int,needs_draw:bool,qualified:bool}>
	 */
	public function order( array $totals ): array {
		$players = array_keys( $totals );
		usort(
			$players,
			function ( $a, $b ) use ( $totals ): int {
				return $this->compare( $totals[ $b ], $totals[ $a ] );
			}
		);

		$result = array();
		$count  = count( $players );
		for ( $i = 0; $i < $count; $i++ ) {
			$tied_prev = $i > 0 && 0 === $this->compare( $totals[ $players[ $i - 1 ] ], $totals[ $players[ $i ] ] );
			$tied_next = $i + 1 < $count && 0 === $this->compare( $totals[ $players[ $i ] ], $totals[ $players[ $i + 1 ] ] );
			$result[]  = array(
				'player'     => $players[ $i ],
				'rank'       => $tied_prev ? $result[ $i - 1 ]['rank'] : $i + 1,
				'points'     => $totals[ $players[ $i ] ]['points'],
				'needs_draw' => $tied_prev || $tied_next,
				'qualified'  => false,
			);
		}

		// Qualifiziert sind die ersten rl_finalists; ein Los ist nur nötig, wenn die Grenze durch eine Gleichstandsgruppe läuft.
		$n = $this->rules->rl_finalists;
		foreach ( $result as $i => &$row ) {
			$row['qualified'] = $i < $n;
		}
		unset( $row );
		foreach ( $result as $i => &$row ) {
			if ( ! $row['needs_draw'] ) {
				continue;
			}
			$group_ranks       = array_filter( $result, static fn( $r ) => $r['rank'] === $row['rank'] );
			$indices           = array_keys( $group_ranks );
			$crosses           = min( $indices ) < $n && max( $indices ) >= $n;
			$row['needs_draw'] = $crosses;
		}
		unset( $row );

		return $result;
	}

	/**
	 * @param array{points:int,sets_won:int,sets_lost:int,points_for?:int|null,points_against?:int|null} $a Summen A.
	 * @param array{points:int,sets_won:int,sets_lost:int,points_for?:int|null,points_against?:int|null} $b Summen B.
	 */
	private function compare( array $a, array $b ): int {
		$cmp = $a['points'] <=> $b['points'];
		if ( 0 !== $cmp ) {
			return $cmp;
		}
		$cmp = Ratio::compare( $a['sets_won'], $a['sets_lost'], $b['sets_won'], $b['sets_lost'] );
		if ( 0 !== $cmp ) {
			return $cmp;
		}
		if ( isset( $a['points_for'], $a['points_against'], $b['points_for'], $b['points_against'] ) ) {
			return Ratio::compare( $a['points_for'], $a['points_against'], $b['points_for'], $b['points_against'] );
		}
		return 0;
	}
}
