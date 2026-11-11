<?php
/**
 * Gesamtrangliste des Ranglistenturniers für die TK: Finalqualifikation und Export.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\RankingTournament;

use TTLeague\Domain\Ranking\FinalQualification;
use TTLeague\Domain\Ranking\RlPlayerStatus;

/**
 * Addiert pro Kategorie die festgeschriebenen Punkte der abgeschlossenen Runden vor dem Final
 * und ordnet mit FinalQualification (Punkte, dann Satz- und Ballquotient über alle gewerteten
 * Partien). Ohne Cache, direkt aus den Tabellen.
 */
final class Standings {

	public function __construct( private readonly Repository $repo ) {}

	/**
	 * Gesamtrangliste je Kategorie.
	 *
	 * @param array<string,mixed> $competition Konkurrenz aus Repository::competition().
	 * @return array<int,list<array{player_id:int,name:string,club_short:string|null,classification:int|null,rank:int,points:float,needs_draw:bool,qualified:bool,rounds:array<int,float|null>,sets_won:int,sets_lost:int}>>
	 */
	public function overall( array $competition ): array {
		$rules   = Repository::rules( $competition );
		$totals  = array();
		$players = array();
		$detail  = array();
		foreach ( $this->repo->rounds( $competition['id'] ) as $round ) {
			if ( $round['is_final'] || Rounds::CLOSED !== $round['status'] ) {
				continue;
			}
			foreach ( $this->repo->groups( $round['id'] ) as $group ) {
				$category = $group['category'];
				$statuses = array_column( $group['members'], 'status', 'player_id' );
				$rows     = Sheet::ranking( $rules, $statuses, $group['games'] );
				foreach ( $group['members'] as $member ) {
					// Aufgebotene einer Gruppe ohne Resultat haben nicht gespielt.
					if ( RlPlayerStatus::Scheduled->value === $member['status'] ) {
						continue;
					}
					$id  = $member['player_id'];
					$row = $rows[ $id ] ?? null;
					if ( ! isset( $totals[ $category ][ $id ] ) ) {
						$totals[ $category ][ $id ]  = array(
							'points'         => 0.0,
							'sets_won'       => 0,
							'sets_lost'      => 0,
							'points_for'     => 0,
							'points_against' => 0,
							'points_known'   => true,
						);
						$players[ $category ][ $id ] = $member;
						$detail[ $category ][ $id ]  = array();
					}
					$played                                = RlPlayerStatus::Played->value === $member['status'];
					$points                                = (float) ( $member['points'] ?? 0.0 );
					$totals[ $category ][ $id ]['points'] += $points;
					if ( null !== $row ) {
						$totals[ $category ][ $id ]['sets_won']       += $row->sets_won;
						$totals[ $category ][ $id ]['sets_lost']      += $row->sets_lost;
						$totals[ $category ][ $id ]['points_for']     += $row->points_for;
						$totals[ $category ][ $id ]['points_against'] += $row->points_against;
						$totals[ $category ][ $id ]['points_known']    = $totals[ $category ][ $id ]['points_known'] && $row->points_known;
					}
					$detail[ $category ][ $id ][ $round['round_no'] ] = $played ? $points : null;
				}
			}
		}

		$qualification = new FinalQualification( $rules );
		$overall       = array();
		ksort( $totals );
		foreach ( $totals as $category => $category_totals ) {
			$input = array();
			foreach ( $category_totals as $id => $total ) {
				$input[ $id ] = array(
					'points'    => $total['points'],
					'sets_won'  => $total['sets_won'],
					'sets_lost' => $total['sets_lost'],
				);
				// Der Ballquotient zählt nur, wenn für alle Partien Satzpunkte erfasst sind.
				if ( $total['points_known'] && $total['sets_won'] + $total['sets_lost'] > 0 ) {
					$input[ $id ]['points_for']     = $total['points_for'];
					$input[ $id ]['points_against'] = $total['points_against'];
				}
			}
			foreach ( $qualification->order( $input ) as $row ) {
				$id                     = (int) $row['player'];
				$overall[ $category ][] = array(
					'player_id'      => $id,
					'name'           => $players[ $category ][ $id ]['name'],
					'club_short'     => $players[ $category ][ $id ]['club_short'],
					'classification' => $players[ $category ][ $id ]['classification'],
					'rank'           => $row['rank'],
					'points'         => (float) $row['points'],
					'needs_draw'     => $row['needs_draw'],
					'qualified'      => $row['qualified'],
					'rounds'         => $detail[ $category ][ $id ],
					'sets_won'       => $category_totals[ $id ]['sets_won'],
					'sets_lost'      => $category_totals[ $id ]['sets_lost'],
				);
			}
		}
		return $overall;
	}
}
