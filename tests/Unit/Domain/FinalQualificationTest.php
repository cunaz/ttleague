<?php
declare(strict_types=1);

namespace TTLeague\Tests\Unit\Domain;

use PHPUnit\Framework\TestCase;
use TTLeague\Domain\Ranking\FinalQualification;
use TTLeague\Domain\Rules\SeasonRules;

final class FinalQualificationTest extends TestCase {

	public function test_top_six_qualify_and_ties_at_cut_need_draw(): void {
		$totals = array(
			'p1' => array(
				'points'    => 18,
				'sets_won'  => 40,
				'sets_lost' => 10,
			),
			'p2' => array(
				'points'    => 15,
				'sets_won'  => 30,
				'sets_lost' => 20,
			),
			'p3' => array(
				'points'    => 15,
				'sets_won'  => 28,
				'sets_lost' => 20,
			),
			'p4' => array(
				'points'    => 12,
				'sets_won'  => 25,
				'sets_lost' => 25,
			),
			'p5' => array(
				'points'    => 11,
				'sets_won'  => 20,
				'sets_lost' => 25,
			),
			'p6' => array(
				'points'    => 10,
				'sets_won'  => 20,
				'sets_lost' => 20,
			),
			'p7' => array(
				'points'    => 10,
				'sets_won'  => 20,
				'sets_lost' => 20,
			),
			'p8' => array(
				'points'    => 4,
				'sets_won'  => 5,
				'sets_lost' => 30,
			),
		);
		$order  = ( new FinalQualification( new SeasonRules() ) )->order( $totals );

		self::assertSame( array( 'p1', 'p2', 'p3', 'p4', 'p5' ), array_column( array_slice( $order, 0, 5 ), 'player' ) );
		self::assertSame( array( 1, 2, 3, 4, 5, 6, 6, 8 ), array_column( $order, 'rank' ) );
		self::assertFalse( $order[1]['needs_draw'], 'p2 und p3 sind durch den Satzquotienten getrennt.' );
		self::assertTrue( $order[5]['needs_draw'], 'Platz 6 und 7 gleich: Los an der Qualifikationsgrenze.' );
		self::assertTrue( $order[6]['needs_draw'] );
		self::assertSame( array( true, true, true, true, true, true, false, false ), array_column( $order, 'qualified' ) );
	}

	public function test_tie_away_from_cut_needs_no_draw(): void {
		$totals = array(
			'a' => array(
				'points'    => 10,
				'sets_won'  => 9,
				'sets_lost' => 3,
			),
			'b' => array(
				'points'    => 10,
				'sets_won'  => 9,
				'sets_lost' => 3,
			),
			'c' => array(
				'points'    => 1,
				'sets_won'  => 1,
				'sets_lost' => 9,
			),
		);
		$order  = ( new FinalQualification( new SeasonRules( rl_finalists: 6 ) ) )->order( $totals );
		self::assertSame( array( 1, 1, 3 ), array_column( $order, 'rank' ) );
		self::assertFalse( $order[0]['needs_draw'] );
	}

	public function test_ball_quotient_breaks_tie_when_available(): void {
		$totals = array(
			'a' => array(
				'points'         => 10,
				'sets_won'       => 9,
				'sets_lost'      => 3,
				'points_for'     => 120,
				'points_against' => 100,
			),
			'b' => array(
				'points'         => 10,
				'sets_won'       => 9,
				'sets_lost'      => 3,
				'points_for'     => 110,
				'points_against' => 100,
			),
		);
		$order  = ( new FinalQualification( new SeasonRules() ) )->order( $totals );
		self::assertSame( array( 'a', 'b' ), array_column( $order, 'player' ) );
		self::assertSame( array( 1, 2 ), array_column( $order, 'rank' ) );
	}
}
