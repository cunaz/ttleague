<?php
declare(strict_types=1);

namespace TTLeague\Tests\Unit\Domain;

use PHPUnit\Framework\TestCase;
use TTLeague\Domain\Match\GameResult;
use TTLeague\Domain\Match\Side;
use TTLeague\Domain\Match\TeamMatchResult;
use TTLeague\Domain\Rules\SeasonRules;

final class TeamMatchResultTest extends TestCase {

	/** @return list<GameResult> */
	private function ten_games( int $home_wins ): array {
		$games = array();
		for ( $i = 1; $i <= 10; $i++ ) {
			$games[] = $i <= $home_wins ? new GameResult( $i, 3, 1, is_double: 7 === $i ) : new GameResult( $i, 0, 3, is_double: 7 === $i );
		}
		return $games;
	}

	public function test_team_match_counts_all_games(): void {
		$result = TeamMatchResult::from_team_match( $this->ten_games( 5 ), new SeasonRules() );
		self::assertSame( 5, $result->home_score );
		self::assertSame( 5, $result->away_score );
		self::assertNull( $result->winner() );
		self::assertTrue( $result->decided );
		self::assertSame( 15, $result->home_sets );
		self::assertSame( 20, $result->away_sets );
		self::assertNull( $result->home_points, 'Ohne Satzpunkte bleibt das Punkteverhältnis unbekannt.' );
	}

	public function test_balanced_match_five_five(): void {
		// Satzresultate einer 5:5-Begegnung in Spielfolge, das Doppel an siebter Stelle.
		$sets  = array( array( 1, 3 ), array( 2, 3 ), array( 3, 2 ), array( 2, 3 ), array( 2, 3 ), array( 3, 2 ), array( 3, 2 ), array( 3, 0 ), array( 3, 0 ), array( 1, 3 ) );
		$games = array();
		foreach ( $sets as $i => $s ) {
			$games[] = new GameResult( $i + 1, $s[0], $s[1], is_double: 6 === $i );
		}
		$result = TeamMatchResult::from_team_match( $games, new SeasonRules() );
		self::assertSame( array( 5, 5 ), array( $result->home_score, $result->away_score ) );
		self::assertSame( array( 23, 21 ), array( $result->home_sets, $result->away_sets ) );
	}

	public function test_set_points_are_summed_when_complete(): void {
		$games  = array(
			new GameResult( 1, 3, 0, points_home: 33, points_away: 20 ),
			new GameResult( 2, 1, 3, points_home: 30, points_away: 40 ),
		);
		$result = TeamMatchResult::from_team_match( $games, new SeasonRules() );
		self::assertSame( 63, $result->home_points );
		self::assertSame( 60, $result->away_points );
		self::assertFalse( $result->decided, 'Mit zwei von zehn Spielen ist nichts entschieden.' );
	}

	public function test_cup_first_to_three_ignores_games_after_decision(): void {
		$games  = array(
			new GameResult( 1, 3, 0 ),
			new GameResult( 2, 3, 1 ),
			new GameResult( 3, 3, 2, is_double: true ),
			new GameResult( 4, 0, 3 ),
			new GameResult( 5, 1, 3 ),
		);
		$result = TeamMatchResult::from_cup( $games, new SeasonRules() );
		self::assertSame( array( 3, 0 ), array( $result->home_score, $result->away_score ) );
		self::assertSame( array( 4, 5 ), $result->not_counted );
		self::assertTrue( $result->decided );
		self::assertSame( Side::Home, $result->winner() );
		self::assertSame( 10, $result->home_sets, 'Ausgespielte Partien zählen für die Statistik weiter.' );
	}

	public function test_cup_all_games_mode_counts_everything(): void {
		$games  = array(
			new GameResult( 1, 3, 0 ),
			new GameResult( 2, 3, 1 ),
			new GameResult( 3, 3, 2, is_double: true ),
			new GameResult( 4, 0, 3 ),
			new GameResult( 5, 1, 3 ),
		);
		$result = TeamMatchResult::from_cup( $games, new SeasonRules( cup_mode: SeasonRules::CUP_ALL_GAMES ) );
		self::assertSame( array( 3, 2 ), array( $result->home_score, $result->away_score ) );
		self::assertSame( array(), $result->not_counted );
	}

	public function test_cup_undecided_after_two_games(): void {
		$result = TeamMatchResult::from_cup( array( new GameResult( 1, 3, 0 ), new GameResult( 2, 0, 3 ) ), new SeasonRules() );
		self::assertFalse( $result->decided );
	}

	public function test_forfeit(): void {
		$result = TeamMatchResult::forfeit( Side::Away, new SeasonRules() );
		self::assertSame( array( 0, 10 ), array( $result->home_score, $result->away_score ) );
		self::assertTrue( $result->decided );
	}

	public function test_not_played_game_gives_no_point(): void {
		$games    = $this->ten_games( 7 );
		$games[2] = GameResult::not_played( 3 );
		$result   = TeamMatchResult::from_team_match( $games, new SeasonRules() );
		self::assertSame( array( 6, 3 ), array( $result->home_score, $result->away_score ) );
	}

	public function test_duplicate_sequence_is_rejected(): void {
		$this->expectException( \InvalidArgumentException::class );
		TeamMatchResult::from_team_match( array( new GameResult( 1, 3, 0 ), new GameResult( 1, 3, 0 ) ), new SeasonRules() );
	}
}
