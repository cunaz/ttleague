<?php
declare(strict_types=1);

namespace TTLeague\Tests\Unit\Domain;

use PHPUnit\Framework\TestCase;
use TTLeague\Domain\Match\GameOrder;

final class GameOrderTest extends TestCase {

	public function test_team_match_has_ten_games_in_reglement_order(): void {
		$order = GameOrder::team_match();
		self::assertCount( 10, $order );
		self::assertSame( range( 1, 10 ), array_column( $order, 'sequence' ) );
		self::assertSame( array( 'A', 'X' ), array( $order[0]['home'], $order[0]['away'] ) );
		self::assertSame( array( 'C', 'Y' ), array( $order[5]['home'], $order[5]['away'] ) );
		self::assertSame( GameOrder::DOUBLE, $order[6]['type'] );
		self::assertSame( array( 'A', 'Y' ), array( $order[9]['home'], $order[9]['away'] ) );
	}

	public function test_cup_has_five_games_with_double_third(): void {
		$order = GameOrder::cup();
		self::assertCount( 5, $order );
		self::assertSame( GameOrder::DOUBLE, $order[2]['type'] );
		self::assertSame( array( 'A', 'Y' ), array( $order[0]['home'], $order[0]['away'] ) );
		self::assertSame( array( 'B', 'Y' ), array( $order[4]['home'], $order[4]['away'] ) );
	}

	public function test_singles_for_slot(): void {
		self::assertSame( array( 3, 6, 9 ), GameOrder::singles_for_slot( 'C' ) );
		self::assertSame( array( 1, 4, 9 ), GameOrder::singles_for_slot( 'X' ) );
		self::assertSame( array( 2, 4 ), GameOrder::singles_for_slot( 'Z', true ) );
	}
}
