<?php
/**
 * Gruppenzettel: Spielfolge und Rangliste einer Gruppe aus ihren gespeicherten Daten.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\RankingTournament;

use TTLeague\Domain\Ranking\GroupGameOrder;
use TTLeague\Domain\Ranking\GroupRanking;
use TTLeague\Domain\Ranking\RlGame;
use TTLeague\Domain\Ranking\RlPlayerStatus;
use TTLeague\Domain\Ranking\RlRankRow;
use TTLeague\Domain\Rules\SeasonRules;

/**
 * Die Position einer Person ist ihr Platz in der Gruppe (Reihenfolge der Einteilung). Die
 * Rangliste rechnet GroupRanking; Satzpunkte zählen nur, wenn sie für eine Partie erfasst sind.
 */
final class Sheet {

	/**
	 * Partien in der Reihenfolge des Spielplans.
	 *
	 * @param list<int> $player_ids Personen in der Reihenfolge der Gruppe.
	 * @return list<array{sequence:int,a:int,b:int}>
	 */
	public static function order( array $player_ids ): array {
		return GroupGameOrder::for_players( array_map( 'intval', $player_ids ) );
	}

	/**
	 * Rangliste einer Gruppe.
	 *
	 * @param array<int,string>                                                                          $statuses Spieler => Status (played, absent, disqualified, forfeit, scheduled).
	 * @param list<array{a:int,b:int,sets_a:int,sets_b:int,set_points?:list<array{0:int,1:int}>}> $games    Partien.
	 * @return array<int,RlRankRow> Spieler => Zeile, in der Reihenfolge der Rangliste.
	 */
	public static function ranking( SeasonRules $rules, array $statuses, array $games ): array {
		$players = array();
		foreach ( $statuses as $player_id => $status ) {
			$players[ (int) $player_id ] = RlPlayerStatus::tryFrom( $status ) ?? RlPlayerStatus::Scheduled;
		}
		$rl_games = array();
		foreach ( $games as $game ) {
			$a = (int) $game['a'];
			$b = (int) $game['b'];
			if ( $a === $b || ! isset( $players[ $a ], $players[ $b ] ) || (int) $game['sets_a'] === (int) $game['sets_b'] ) {
				continue;
			}
			$points_a = null;
			$points_b = null;
			if ( array() !== ( $game['set_points'] ?? array() ) ) {
				$points_a = 0;
				$points_b = 0;
				foreach ( $game['set_points'] as $set ) {
					$points_a += (int) $set[0];
					$points_b += (int) $set[1];
				}
			}
			$rl_games[] = new RlGame( $a, $b, (int) $game['sets_a'], (int) $game['sets_b'], $points_a, $points_b );
		}
		$rows = array();
		foreach ( ( new GroupRanking( $rules ) )->rank( $players, $rl_games ) as $row ) {
			$rows[ (int) $row->player ] = $row;
		}
		return $rows;
	}
}
