<?php
declare(strict_types=1);

namespace TTLeague\Tests\Unit\Domain;

use TTLeague\Domain\Schedule\PlannedFixture;
use TTLeague\Domain\Schedule\ScheduleComparator;
use TTLeague\Domain\Schedule\ScheduleGenerator;
use TTLeague\Domain\Schedule\ScheduleInput;

final class ScheduleComparatorTest extends ScheduleTestCase {

	private function match( int|string $home, int|string $away, string $starts_at, int $leg = 1 ): PlannedFixture {
		return new PlannedFixture( 'A', null, $leg, $home, $away, new \DateTimeImmutable( $starts_at ), 'Halle' );
	}

	public function test_identical_plans_agree_completely(): void {
		$plan   = array(
			$this->match( 'Seefeld 1', 'Altstetten 1', '2026-09-08 19:30' ),
			$this->match( 'Altstetten 1', 'Seefeld 1', '2027-01-06 19:45', 2 ),
		);
		$result = ( new ScheduleComparator() )->compare( $plan, array_reverse( $plan ) );

		self::assertSame( array( 2, 2, 2, 2 ), array( $result->matched, $result->same_date, $result->same_week, $result->same_weekday ) );
		self::assertSame( array( 1.0, 1.0, 1.0 ), array( $result->same_date_share(), $result->same_week_share(), $result->same_weekday_share() ) );
		self::assertSame( array(), $result->only_in_plan );
		self::assertSame( array(), $result->only_in_reference );
	}

	public function test_counts_same_date_same_week_and_same_weekday_separately(): void {
		$plan      = array(
			$this->match( 'Seefeld 1', 'Altstetten 1', '2026-09-08 19:30' ),
			$this->match( 'Seefeld 1', 'Oerlikon 1', '2026-09-15 19:30' ),
			$this->match( 'Seefeld 1', 'Wiedikon 1', '2026-09-22 19:30' ),
			$this->match( 'Seefeld 1', 'Enge 1', '2026-09-29 19:30' ),
		);
		$reference = array(
			// Gleicher Tag, andere Anspielzeit.
			$this->match( 'Seefeld 1', 'Altstetten 1', '2026-09-08 20:15' ),
			// Gleiche Woche, Donnerstag statt Dienstag.
			$this->match( 'Seefeld 1', 'Oerlikon 1', '2026-09-17 19:30' ),
			// Zwei Wochen später, wieder am Dienstag.
			$this->match( 'Seefeld 1', 'Wiedikon 1', '2026-10-06 19:30' ),
			// Andere Woche, anderer Wochentag.
			$this->match( 'Seefeld 1', 'Enge 1', '2026-10-08 19:30' ),
		);
		$result    = ( new ScheduleComparator() )->compare( $plan, $reference );

		self::assertSame( 4, $result->matched );
		self::assertSame( 1, $result->same_date );
		self::assertSame( 2, $result->same_week );
		self::assertSame( 2, $result->same_weekday );
		self::assertSame( array( 0.25, 0.5, 0.5 ), array( $result->same_date_share(), $result->same_week_share(), $result->same_weekday_share() ) );
	}

	public function test_pairing_is_home_away_and_leg(): void {
		$plan      = array(
			$this->match( 'Seefeld 1', 'Altstetten 1', '2026-09-08 19:30', 1 ),
			$this->match( 'Seefeld 1', 'Oerlikon 1', '2026-09-15 19:30', 1 ),
			$this->match( 'Oerlikon 1', 'Altstetten 1', '2026-09-24 19:30', 1 ),
		);
		$reference = array(
			// Getauschtes Heimrecht ist eine andere Begegnung.
			$this->match( 'Altstetten 1', 'Seefeld 1', '2026-09-08 19:30', 1 ),
			// Gleiche Teams, aber Rückspiel.
			$this->match( 'Seefeld 1', 'Oerlikon 1', '2026-09-15 19:30', 2 ),
			$this->match( 'Oerlikon 1', 'Altstetten 1', '2026-09-24 19:30', 1 ),
		);
		$result    = ( new ScheduleComparator() )->compare( $plan, $reference );

		self::assertSame( 1, $result->matched );
		self::assertSame( 1.0, $result->same_date_share() );
		self::assertSame( array( $plan[0], $plan[1] ), $result->only_in_plan );
		self::assertSame( array( $reference[0], $reference[1] ), $result->only_in_reference );
	}

	public function test_numeric_ids_match_as_integer_and_string(): void {
		$result = ( new ScheduleComparator() )->compare(
			array( $this->match( 3, 12, '2026-09-08 19:30' ), $this->match( 31, 2, '2026-09-08 19:30' ) ),
			array( $this->match( '3', '12', '2026-09-08 19:30' ) )
		);
		self::assertSame( 1, $result->matched );
		self::assertSame( array( 31 ), array_column( $result->only_in_plan, 'home_team' ), '31 gegen 2 ist nicht 3 gegen 12.' );
	}

	public function test_week_spans_the_new_year(): void {
		$result = ( new ScheduleComparator() )->compare(
			array( $this->match( 'Seefeld 1', 'Altstetten 1', '2026-12-31 19:30' ), $this->match( 'Seefeld 1', 'Oerlikon 1', '2027-01-03 10:00' ) ),
			array( $this->match( 'Seefeld 1', 'Altstetten 1', '2027-01-02 14:00' ), $this->match( 'Seefeld 1', 'Oerlikon 1', '2027-01-04 19:30' ) )
		);
		self::assertSame( array( 2, 0, 1, 0 ), array( $result->matched, $result->same_date, $result->same_week, $result->same_weekday ) );
	}

	public function test_shares_are_null_without_common_fixtures(): void {
		$plan   = array( $this->match( 'Seefeld 1', 'Altstetten 1', '2026-09-08 19:30' ) );
		$result = ( new ScheduleComparator() )->compare( $plan, array() );
		self::assertSame( 0, $result->matched );
		self::assertSame( array( null, null, null ), array( $result->same_date_share(), $result->same_week_share(), $result->same_weekday_share() ) );
		self::assertSame( $plan, $result->only_in_plan );
		self::assertSame( $plan, ( new ScheduleComparator() )->compare( array(), $plan )->only_in_reference );
	}

	public function test_pairing_twice_in_a_plan_is_rejected(): void {
		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'Paarung mehrfach im Vergleichsplan: Seefeld 1 gegen Altstetten 1, Halbserie 1.' );
		( new ScheduleComparator() )->compare(
			array(),
			array( $this->match( 'Seefeld 1', 'Altstetten 1', '2026-09-08 19:30' ), $this->match( 'Seefeld 1', 'Altstetten 1', '2026-09-15 19:30' ) )
		);
	}

	public function test_pairing_twice_in_the_plan_itself_is_rejected(): void {
		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'Paarung mehrfach im Plan: Seefeld 1 gegen Altstetten 1, Halbserie 1.' );
		( new ScheduleComparator() )->compare(
			array( $this->match( 'Seefeld 1', 'Altstetten 1', '2026-09-08 19:30' ), $this->match( 'Seefeld 1', 'Altstetten 1', '2026-09-15 19:30' ) ),
			array()
		);
	}

	public function test_entries_that_are_no_fixtures_are_rejected(): void {
		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'Begegnungen im Plan müssen PlannedFixture sein.' );
		( new ScheduleComparator() )->compare( array( array( 'home_team' => 'Seefeld 1' ) ), array() );
	}

	public function test_draft_against_a_plan_with_moved_fixtures(): void {
		$draft  = ( new ScheduleGenerator() )->generate(
			new ScheduleInput(
				array( $this->group( 'A', array( 'Seefeld', 'Altstetten', 'Oerlikon', 'Wiedikon', 'Enge', 'Albisrieden' ) ) ),
				$this->mondays( '2026-09-07', 5 ),
				$this->mondays( '2027-01-04', 5 )
			)
		);
		$played = $draft;
		// Gespielt wurde anders: einmal zwei Tage später, einmal eine Woche später, einmal elf Tage später.
		$played[0]  = $played[0]->moved_to( $played[0]->starts_at->modify( '+2 days' ) );
		$played[7]  = $played[7]->moved_to( $played[7]->starts_at->modify( '+7 days' ) );
		$played[20] = $played[20]->moved_to( $played[20]->starts_at->modify( '+11 days' ) );
		$result     = ( new ScheduleComparator() )->compare( $draft, $played );

		self::assertSame( 30, $result->matched );
		self::assertSame( 27, $result->same_date );
		self::assertSame( 0.9, $result->same_date_share() );
		self::assertSame( 28, $result->same_week );
		self::assertSame( 28, $result->same_weekday );
		self::assertSame( array(), $result->only_in_plan );
		self::assertSame( array(), $result->only_in_reference );
	}
}
