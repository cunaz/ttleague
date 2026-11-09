<?php
declare(strict_types=1);

namespace TTLeague\Tests\Unit\Domain;

use PHPUnit\Framework\TestCase;
use TTLeague\Domain\Match\GameResult;
use TTLeague\Domain\Match\GameStatus;
use TTLeague\Domain\Match\Side;

final class GameResultTest extends TestCase {

	public function test_valid_result_and_winner(): void {
		$g = new GameResult( 1, 3, 1 );
		self::assertSame( Side::Home, $g->winner() );
		self::assertTrue( $g->counts_for_ratios() );
		self::assertSame( Side::Away, ( new GameResult( 2, 2, 3 ) )->winner() );
	}

	public function test_invalid_set_results_are_rejected(): void {
		$this->expectException( \InvalidArgumentException::class );
		new GameResult( 1, 3, 3 );
	}

	public function test_unfinished_game_is_rejected(): void {
		$this->expectException( \InvalidArgumentException::class );
		new GameResult( 1, 2, 1 );
	}

	public function test_walkover_and_not_played(): void {
		$wo = GameResult::walkover( 3, Side::Away );
		self::assertSame( GameStatus::Walkover, $wo->status );
		self::assertSame( Side::Away, $wo->winner() );
		self::assertFalse( $wo->counts_for_ratios() );

		$np = GameResult::not_played( 3 );
		self::assertNull( $np->winner() );
		self::assertFalse( $np->is_win_for( Side::Home ) );
	}
}
