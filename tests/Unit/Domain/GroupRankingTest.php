<?php
declare(strict_types=1);

namespace TTLeague\Tests\Unit\Domain;

use PHPUnit\Framework\TestCase;
use TTLeague\Domain\Ranking\GroupRanking;
use TTLeague\Domain\Ranking\RlGame;
use TTLeague\Domain\Ranking\RlPlayerStatus;
use TTLeague\Domain\Rules\SeasonRules;

final class GroupRankingTest extends TestCase {

	public function test_group_of_five_with_absent_player(): void {
		// Fünfergruppe, eine Person spielt nicht: Punkte 6, 5, 4, 3 und 2 für die abwesende Person.
		$players = array(
			'Keller' => RlPlayerStatus::Played,
			'Ammann'    => RlPlayerStatus::Played,
			'Moser'  => RlPlayerStatus::Played,
			'Weber'  => RlPlayerStatus::Played,
			'Pfister'    => RlPlayerStatus::Played,
		);
		$games   = array(
			new RlGame( 'Keller', 'Weber', 3, 1 ),
			new RlGame( 'Ammann', 'Pfister', 3, 2 ),
			new RlGame( 'Ammann', 'Weber', 2, 3 ),
			new RlGame( 'Keller', 'Pfister', 2, 3 ),
			new RlGame( 'Weber', 'Pfister', 3, 0 ),
			new RlGame( 'Keller', 'Ammann', 1, 3 ),
		);
		$rows    = ( new GroupRanking( new SeasonRules() ) )->rank( $players, $games );

		self::assertSame( array( 'Weber', 'Ammann', 'Pfister', 'Keller', 'Moser' ), array_map( static fn( $r ) => $r->player, $rows ) );
		self::assertSame( array( 6.0, 5.0, 4.0, 3.0, 2.0 ), array_map( static fn( $r ) => $r->round_points, $rows ) );
		self::assertSame( array( 2, 2, 1, 1, 0 ), array_map( static fn( $r ) => $r->wins, $rows ) );
		self::assertSame( array( 7, 5 ), array( $rows[0]->sets_won, $rows[0]->sets_lost ) );
		self::assertFalse( $rows[0]->shared_rank );
	}

	public function test_absent_player_games_are_annulled(): void {
		$players = array(
			'a' => RlPlayerStatus::Played,
			'b' => RlPlayerStatus::Played,
			'c' => RlPlayerStatus::Forfeit,
		);
		$games   = array(
			new RlGame( 'a', 'b', 3, 2 ),
			new RlGame( 'c', 'a', 3, 0 ),
			new RlGame( 'c', 'b', 3, 0 ),
		);
		$rows    = ( new GroupRanking( new SeasonRules() ) )->rank( $players, $games );
		self::assertSame( array( 'a', 'b', 'c' ), array_map( static fn( $r ) => $r->player, $rows ) );
		self::assertSame( array( 1, 0 ), array( $rows[0]->wins, $rows[0]->losses ) );
		self::assertNull( $rows[2]->rank );
		self::assertSame( 0.0, $rows[2]->round_points );
	}

	public function test_seven_player_group_bonus(): void {
		$players = array();
		for ( $i = 1; $i <= 7; $i++ ) {
			$players[ 'p' . $i ] = RlPlayerStatus::Played;
		}
		$games = array();
		for ( $i = 1; $i <= 7; $i++ ) {
			for ( $j = $i + 1; $j <= 7; $j++ ) {
				$games[] = new RlGame( 'p' . $i, 'p' . $j, 3, 0 ); // der kleinere Index gewinnt immer
			}
		}
		$rows = ( new GroupRanking( new SeasonRules() ) )->rank( $players, $games );
		self::assertSame( 7.0, $rows[0]->round_points );
		self::assertSame( 1.0, $rows[6]->round_points );
	}

	public function test_three_way_tie_by_mutual_set_ratio(): void {
		$players = array(
			'a' => RlPlayerStatus::Played,
			'b' => RlPlayerStatus::Played,
			'c' => RlPlayerStatus::Played,
			'd' => RlPlayerStatus::Played,
		);
		$games   = array(
			new RlGame( 'a', 'b', 3, 0 ),
			new RlGame( 'b', 'c', 3, 1 ),
			new RlGame( 'c', 'a', 3, 2 ),
			new RlGame( 'a', 'd', 3, 0 ),
			new RlGame( 'b', 'd', 3, 0 ),
			new RlGame( 'c', 'd', 3, 0 ),
		);
		// Untereinander: a 5:3, b 3:4, c 4:5 -> a, c, b
		$rows = ( new GroupRanking( new SeasonRules() ) )->rank( $players, $games );
		self::assertSame( array( 'a', 'c', 'b', 'd' ), array_map( static fn( $r ) => $r->player, $rows ) );
	}

	public function test_unresolved_tie_shares_rank_and_points(): void {
		$players = array(
			'a' => RlPlayerStatus::Played,
			'b' => RlPlayerStatus::Played,
			'c' => RlPlayerStatus::Played,
		);
		// Kreis mit identischen Satzverhältnissen und ohne Ballpunkte.
		$games = array(
			new RlGame( 'a', 'b', 3, 1 ),
			new RlGame( 'b', 'c', 3, 1 ),
			new RlGame( 'c', 'a', 3, 1 ),
		);
		$rows  = ( new GroupRanking( new SeasonRules() ) )->rank( $players, $games );
		self::assertSame( array( 1, 1, 1 ), array_map( static fn( $r ) => $r->rank, $rows ) );
		self::assertTrue( $rows[0]->shared_rank );
		self::assertSame( array( 5.0, 5.0, 5.0 ), array_map( static fn( $r ) => $r->round_points, $rows ), 'Punkte von Rang 1 bis 3 werden geteilt.' );
	}

	public function test_two_way_tie_without_direct_game_splits_points(): void {
		$players = array(
			'a' => RlPlayerStatus::Played,
			'b' => RlPlayerStatus::Played,
			'c' => RlPlayerStatus::Played,
			'd' => RlPlayerStatus::Played,
		);
		// a und b gewinnen je gegen c und d, spielen aber nicht gegeneinander; gleiche Sätze.
		$games = array(
			new RlGame( 'a', 'c', 3, 1 ),
			new RlGame( 'a', 'd', 3, 1 ),
			new RlGame( 'b', 'c', 3, 1 ),
			new RlGame( 'b', 'd', 3, 1 ),
			new RlGame( 'c', 'd', 3, 0 ),
		);
		$rows  = ( new GroupRanking( new SeasonRules() ) )->rank( $players, $games );
		self::assertSame( array( 5.5, 5.5, 4.0, 3.0 ), array_map( static fn( $r ) => $r->round_points, $rows ) );
		self::assertSame( array( 1, 1, 3, 4 ), array_map( static fn( $r ) => $r->rank, $rows ) );
	}

	public function test_seven_listed_but_one_absent_gives_no_bonus(): void {
		$players = array();
		for ( $i = 1; $i <= 7; $i++ ) {
			$players[ 'p' . $i ] = RlPlayerStatus::Played;
		}
		$players['p7'] = RlPlayerStatus::Absent;
		$games         = array();
		for ( $i = 1; $i <= 6; $i++ ) {
			for ( $j = $i + 1; $j <= 6; $j++ ) {
				$games[] = new RlGame( 'p' . $i, 'p' . $j, 3, 0 );
			}
		}
		$rows = ( new GroupRanking( new SeasonRules() ) )->rank( $players, $games );
		self::assertSame( 6.0, $rows[0]->round_points );
		self::assertSame( 1.0, $rows[5]->round_points );
	}
}
