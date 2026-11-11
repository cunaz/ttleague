<?php
/**
 * Zurückgezogene Mannschaften stehen am Tabellenende (Art. 13.3).
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Tests\Unit\Domain;

use PHPUnit\Framework\TestCase;
use TTLeague\Domain\Standings\MatchSummary;
use TTLeague\Domain\Standings\StandingsCalculator;
use TTLeague\Domain\Standings\StandingsRow;
use TTLeague\ReadModel\Championship;

final class SeasonWithdrawnStandingsTest extends TestCase {

	/**
	 * @param list<StandingsRow> $rows Tabelle.
	 * @return list<int|string>
	 */
	private static function order( array $rows ): array {
		return array_map( static fn( StandingsRow $row ) => $row->team, $rows );
	}

	public function test_a_withdrawn_team_ranks_below_teams_with_fewer_points(): void {
		$matches = array(
			new MatchSummary( 'A', 'B', 9, 1 ),
			new MatchSummary( 'A', 'C', 8, 2 ),
			new MatchSummary( 'B', 'C', 5, 5 ),
		);
		$normal  = ( new StandingsCalculator() )->calculate( array( 'A', 'B', 'C' ), $matches );
		self::assertSame( array( 'A', 'C', 'B' ), self::order( $normal ) );

		$rows = ( new StandingsCalculator() )->calculate( array( 'A', 'B', 'C' ), $matches, array( 'A' ) );
		self::assertSame( array( 'C', 'B', 'A' ), self::order( $rows ) );
		self::assertSame( array( 1, 2, 3 ), array_map( static fn( StandingsRow $row ) => $row->rank, $rows ) );
		self::assertSame( 17, $rows[2]->points(), 'Die Punkte bleiben stehen; nur der Platz ändert.' );
	}

	public function test_a_withdrawn_team_without_matches_is_last(): void {
		$rows = ( new StandingsCalculator() )->calculate( array( 'A', 'B', 'C' ), array(), array( 'B' ) );
		self::assertSame( 'B', $rows[2]->team );
		self::assertSame( 3, $rows[2]->rank );
		self::assertNull( $rows[2]->tiebreak_note, 'Kein geteilter Rang mit den aktiven Mannschaften.' );
		self::assertSame( 1, $rows[0]->rank );
		self::assertSame( 1, $rows[1]->rank );
	}

	public function test_several_withdrawn_teams_are_ordered_among_themselves(): void {
		$rows = ( new StandingsCalculator() )->calculate(
			array( 'A', 'B', 'C', 'D' ),
			array(
				new MatchSummary( 'A', 'B', 10, 0 ),
				new MatchSummary( 'C', 'D', 0, 10 ),
				new MatchSummary( 'A', 'D', 4, 6 ),
			),
			array( 'A', 'D' )
		);
		self::assertSame( array( 'B', 'C', 'D', 'A' ), self::order( $rows ) );
		self::assertSame( array( 1, 1, 3, 4 ), array_map( static fn( StandingsRow $row ) => $row->rank, $rows ) );
	}

	public function test_without_withdrawn_teams_nothing_changes(): void {
		$matches = array( new MatchSummary( 1, 2, 6, 4 ) );
		self::assertSame(
			self::order( ( new StandingsCalculator() )->calculate( array( 1, 2, 3 ), $matches ) ),
			self::order( ( new StandingsCalculator() )->calculate( array( 1, 2, 3 ), $matches, array() ) )
		);
	}

	public function test_the_read_model_marks_withdrawn_teams(): void {
		$fixtures = array(
			array(
				'counted'      => true,
				'home_team_id' => 1,
				'away_team_id' => 2,
				'home_score'   => 0,
				'away_score'   => 10,
				'sets_home'    => 0,
				'sets_away'    => 0,
			),
			array(
				'counted'      => true,
				'home_team_id' => 2,
				'away_team_id' => 3,
				'home_score'   => 0,
				'away_score'   => 10,
				'sets_home'    => 0,
				'sets_away'    => 0,
			),
		);
		$rows     = Championship::standings( array( 1, 2, 3 ), $fixtures, array( 3 ) );
		self::assertSame( array( 2, 1, 3 ), array_column( $rows, 'team_id' ) );
		self::assertSame( array( false, false, true ), array_column( $rows, 'withdrawn' ) );
		self::assertSame( 3, $rows[2]['rank'] );
	}
}
