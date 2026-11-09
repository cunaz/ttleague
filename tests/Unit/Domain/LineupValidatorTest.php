<?php
declare(strict_types=1);

namespace TTLeague\Tests\Unit\Domain;

use PHPUnit\Framework\TestCase;
use TTLeague\Domain\Match\GameOrder;
use TTLeague\Domain\Match\GameResult;
use TTLeague\Domain\Match\LineupValidator;
use TTLeague\Domain\Match\Side;
use TTLeague\Domain\Rules\SeasonRules;

final class LineupValidatorTest extends TestCase {

	private const HOME = array(
		'A' => 1,
		'B' => 2,
		'C' => 3,
	);
	private const AWAY = array(
		'X' => 4,
		'Y' => 5,
		'Z' => 6,
	);

	/** @return list<GameResult> */
	private function all_home_wins(): array {
		$games = array();
		foreach ( GameOrder::team_match() as $g ) {
			$games[] = new GameResult( $g['sequence'], 3, 0, is_double: GameOrder::DOUBLE === $g['type'] );
		}
		return $games;
	}

	public function test_complete_lineups_and_games_are_valid(): void {
		self::assertSame( array(), LineupValidator::validate_team_match( self::HOME, self::AWAY, $this->all_home_wins(), new SeasonRules() ) );
	}

	public function test_missing_player_requires_walkovers(): void {
		$home  = array(
			'A' => 1,
			'B' => 2,
			'C' => null,
		);
		$games = $this->all_home_wins();
		foreach ( GameOrder::singles_for_slot( 'C' ) as $seq ) {
			$games[ $seq - 1 ] = GameResult::walkover( $seq, Side::Away );
		}
		self::assertSame( array(), LineupValidator::validate_team_match( $home, self::AWAY, $games, new SeasonRules() ) );
		// Ohne w.o. gibt es Fehler je betroffenem Spiel und wegen 7:3-Grenze.
		$errors = LineupValidator::validate_team_match( $home, self::AWAY, $this->all_home_wins(), new SeasonRules() );
		self::assertCount( 4, $errors );
		self::assertStringContainsString( 'höchstens 7', end( $errors ) );
	}

	public function test_both_missing_means_not_played(): void {
		$home  = array(
			'A' => 1,
			'B' => 2,
			'C' => null,
		);
		$away  = array(
			'X' => 4,
			'Y' => 5,
			'Z' => null,
		);
		$games = $this->all_home_wins();
		foreach ( GameOrder::singles_for_slot( 'C' ) as $seq ) {
			$games[ $seq - 1 ] = GameResult::walkover( $seq, Side::Away );
		}
		foreach ( GameOrder::singles_for_slot( 'Z' ) as $seq ) {
			$games[ $seq - 1 ] = GameResult::walkover( $seq, Side::Home );
		}
		$games[2] = GameResult::not_played( 3 ); // C gegen Z
		self::assertSame( array(), LineupValidator::validate_team_match( $home, $away, $games, new SeasonRules() ) );
	}

	public function test_wrong_game_count_is_reported(): void {
		$errors = LineupValidator::validate_team_match( self::HOME, self::AWAY, array_slice( $this->all_home_wins(), 0, 9 ), new SeasonRules() );
		self::assertNotEmpty( $errors );
		self::assertStringContainsString( 'genau 10', $errors[0] );
	}
}
