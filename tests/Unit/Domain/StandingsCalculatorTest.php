<?php
declare(strict_types=1);

namespace TTLeague\Tests\Unit\Domain;

use PHPUnit\Framework\TestCase;
use TTLeague\Domain\Standings\MatchSummary;
use TTLeague\Domain\Standings\StandingsCalculator;
use TTLeague\Domain\Standings\StandingsRow;

final class StandingsCalculatorTest extends TestCase {

	/** @return list<int|string> */
	private function order( array $rows ): array {
		return array_map( static fn( StandingsRow $r ) => $r->team, $rows );
	}

	public function test_points_are_games_won(): void {
		$rows = ( new StandingsCalculator() )->calculate(
			array( 'A', 'B', 'C' ),
			array(
				new MatchSummary( 'A', 'B', 7, 3 ),
				new MatchSummary( 'A', 'C', 6, 4 ),
				new MatchSummary( 'B', 'C', 5, 5 ),
			)
		);
		self::assertSame( array( 'A', 'C', 'B' ), $this->order( $rows ) );
		self::assertSame( array( 13, 9, 8 ), array_map( static fn( $r ) => $r->points(), $rows ) );
		self::assertSame( array( 1, 2, 3 ), array_map( static fn( $r ) => $r->rank, $rows ) );
		self::assertSame( 2, $rows[0]->played );
		self::assertSame( 1, $rows[1]->match_draws );
	}

	public function test_team_without_matches_is_listed(): void {
		$rows = ( new StandingsCalculator() )->calculate( array( 'A', 'B', 'C' ), array( new MatchSummary( 'A', 'B', 6, 4 ) ) );
		self::assertSame( array( 'A', 'B', 'C' ), $this->order( $rows ) );
		self::assertSame( 0, $rows[2]->played );
	}

	public function test_tie_is_broken_by_head_to_head(): void {
		$rows = ( new StandingsCalculator() )->calculate(
			array( 'A', 'B', 'C', 'D' ),
			array(
				new MatchSummary( 'A', 'B', 6, 4 ),
				new MatchSummary( 'A', 'C', 4, 6 ),
				new MatchSummary( 'B', 'D', 6, 4 ),
			)
		);
		self::assertSame( array( 'A', 'B', 'C', 'D' ), $this->order( $rows ) );
		self::assertSame( array( 1, 2, 3, 4 ), array_map( static fn( $r ) => $r->rank, $rows ) );
	}

	public function test_head_to_head_sets_decide_when_games_are_equal(): void {
		$rows = ( new StandingsCalculator() )->calculate(
			array( 'A', 'B' ),
			array(
				new MatchSummary( 'A', 'B', 5, 5, 17, 15 ),
				new MatchSummary( 'B', 'A', 5, 5, 16, 16 ),
			)
		);
		self::assertSame( array( 'A', 'B' ), $this->order( $rows ) );
		self::assertNull( $rows[0]->tiebreak_note );
	}

	public function test_unresolvable_tie_shares_rank(): void {
		$rows = ( new StandingsCalculator() )->calculate(
			array( 'A', 'B', 'C' ),
			array( new MatchSummary( 'A', 'B', 5, 5, 16, 16 ) )
		);
		self::assertSame( array( 1, 1, 3 ), array_map( static fn( $r ) => $r->rank, $rows ) );
		self::assertNotNull( $rows[0]->tiebreak_note );
		self::assertNull( $rows[2]->tiebreak_note );
	}

	public function test_three_way_tie_uses_mutual_set_ratio(): void {
		$rows = ( new StandingsCalculator() )->calculate(
			array( 'A', 'B', 'C', 'D' ),
			array(
				new MatchSummary( 'A', 'B', 6, 4, 18, 14 ),
				new MatchSummary( 'B', 'C', 6, 4, 18, 15 ),
				new MatchSummary( 'C', 'A', 6, 4, 18, 16 ),
				new MatchSummary( 'A', 'D', 4, 6, 14, 18 ),
				new MatchSummary( 'B', 'D', 4, 6, 14, 18 ),
				new MatchSummary( 'C', 'D', 4, 6, 14, 18 ),
			)
		);
		self::assertSame( array( 'D', 'A', 'C', 'B' ), $this->order( $rows ) );
		self::assertSame( array( 1, 2, 3, 4 ), array_map( static fn( $r ) => $r->rank, $rows ) );
	}

	public function test_overall_ratio_breaks_tie_without_mutual_match(): void {
		// A und B haben nicht gegeneinander gespielt, gleiche Punkte, A hat das bessere Gesamtverhältnis.
		$rows = ( new StandingsCalculator() )->calculate(
			array( 'A', 'B', 'C', 'D' ),
			array(
				new MatchSummary( 'A', 'C', 7, 3 ),
				new MatchSummary( 'B', 'D', 7, 3 ),
				new MatchSummary( 'A', 'D', 3, 7 ),
				new MatchSummary( 'B', 'C', 3, 7 ),
				new MatchSummary( 'C', 'D', 6, 4 ),
			)
		);
		// Punkte: A und B je zehn, C sechzehn, D vierzehn.
		self::assertSame( 'C', $rows[0]->team );
		self::assertSame( 'D', $rows[1]->team );
		self::assertSame( array( 3, 3 ), array( $rows[2]->rank, $rows[3]->rank ), 'A und B sind vollständig gleich.' );
	}
}
