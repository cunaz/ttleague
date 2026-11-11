<?php
declare(strict_types=1);

namespace TTLeague\Tests\Unit\Domain;

use PHPUnit\Framework\Attributes\DataProvider;
use TTLeague\Domain\Schedule\PlannedFixture;
use TTLeague\Domain\Schedule\ScheduleGroup;
use TTLeague\Domain\Schedule\ScheduleInput;
use TTLeague\Domain\Schedule\ScheduleTeam;
use TTLeague\Domain\Schedule\VenueClosure;

final class ScheduleInputTest extends ScheduleTestCase {

	public function test_home_date_is_monday_plus_home_weekday_at_start_time(): void {
		$team = new ScheduleTeam( 3, 1, 7, 4, '19:45' );
		self::assertSame( '2026-09-10 19:45', $team->home_date( new \DateTimeImmutable( '2026-09-07' ) )->format( 'Y-m-d H:i' ) );
		self::assertSame( '2026-09-13 09:05', ( new ScheduleTeam( 3, 1, 7, 7, '09:05' ) )->home_date( new \DateTimeImmutable( '2026-09-07 12:00' ) )->format( 'Y-m-d H:i' ) );
	}

	public function test_home_date_keeps_local_time_across_the_end_of_daylight_saving(): void {
		$monday = new \DateTimeImmutable( '2026-10-19', new \DateTimeZone( 'Europe/Zurich' ) );
		$sunday = ( new ScheduleTeam( 3, 1, 7, 7, '19:30' ) )->home_date( $monday );
		self::assertSame( '2026-10-25T19:30:00+01:00', $sunday->format( 'c' ), 'Die Uhren wurden am Morgen dieses Sonntags zurückgestellt.' );
		self::assertSame( '2026-10-22T19:30:00+02:00', ( new ScheduleTeam( 3, 1, 7, 4, '19:30' ) )->home_date( $monday )->format( 'c' ) );
	}

	#[DataProvider( 'invalid_weekdays' )]
	public function test_home_weekday_outside_one_to_seven_is_rejected( int $weekday ): void {
		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'Heimspieltag muss zwischen 1 und 7 liegen.' );
		new ScheduleTeam( 3, 1, 7, $weekday, '19:30' );
	}

	/** @return array<string,array{int}> */
	public static function invalid_weekdays(): array {
		return array(
			'zero'  => array( 0 ),
			'eight' => array( 8 ),
		);
	}

	#[DataProvider( 'invalid_start_times' )]
	public function test_start_time_must_be_hours_and_minutes( string $start_time ): void {
		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'Anspielzeit muss das Format «HH:MM» haben' );
		new ScheduleTeam( 3, 1, 7, 2, $start_time );
	}

	/** @return array<string,array{string}> */
	public static function invalid_start_times(): array {
		return array(
			'one digit hour'   => array( '7:30' ),
			'hour 24'          => array( '24:00' ),
			'minute 60'        => array( '19:60' ),
			'with seconds'     => array( '19:30:00' ),
			'trailing newline' => array( "19:30\n" ),
			'empty'            => array( '' ),
		);
	}

	public function test_rounds_per_leg_for_six_and_seven_teams(): void {
		self::assertSame( 5, $this->group( 'A', array( 'Seefeld', 'Altstetten', 'Oerlikon', 'Wiedikon', 'Enge', 'Albisrieden' ) )->rounds_per_leg() );
		self::assertSame( 7, $this->group( 'B', array( 'Seefeld', 'Altstetten', 'Oerlikon', 'Wiedikon', 'Enge', 'Albisrieden', 'Leimbach' ) )->rounds_per_leg() );
	}

	public function test_group_needs_two_teams(): void {
		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'Die Gruppe A braucht mindestens zwei Teams.' );
		new ScheduleGroup( 'A', array( $this->team( 'Seefeld 1' ) ) );
	}

	public function test_team_twice_in_a_group_is_rejected(): void {
		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'Team mehrfach in der Gruppe A: Seefeld 1' );
		new ScheduleGroup( 'A', array( $this->team( 'Seefeld 1' ), $this->team( 'Seefeld 1' ) ) );
	}

	public function test_team_in_two_groups_is_rejected(): void {
		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'Team in mehreren Gruppen: Seefeld 1' );
		new ScheduleInput(
			array( $this->group( 'A', array( 'Seefeld', 'Altstetten' ) ), $this->group( 'B', array( 'Oerlikon', 'Seefeld' ) ) ),
			$this->mondays( '2026-09-07', 1 ),
			$this->mondays( '2027-01-04', 1 )
		);
	}

	public function test_closure_includes_first_and_last_day(): void {
		$closure = new VenueClosure( 7, new \DateTimeImmutable( '2026-10-05 12:00' ), new \DateTimeImmutable( '2026-10-16 08:00' ), 'Herbstferien' );
		self::assertFalse( $closure->covers( new \DateTimeImmutable( '2026-10-04 23:59' ) ) );
		self::assertTrue( $closure->covers( new \DateTimeImmutable( '2026-10-05 00:00' ) ), 'Erster Tag, auch vor der Uhrzeit von «von».' );
		self::assertTrue( $closure->covers( new \DateTimeImmutable( '2026-10-16 19:30' ) ), 'Letzter Tag, auch nach der Uhrzeit von «bis».' );
		self::assertFalse( $closure->covers( new \DateTimeImmutable( '2026-10-17 00:00' ) ) );
	}

	public function test_closure_of_a_single_day(): void {
		$day     = new \DateTimeImmutable( '2026-12-31' );
		$closure = new VenueClosure( 7, $day, $day );
		self::assertTrue( $closure->covers( new \DateTimeImmutable( '2026-12-31 19:30' ) ) );
		self::assertFalse( $closure->covers( new \DateTimeImmutable( '2027-01-01 19:30' ) ) );
	}

	public function test_closure_ending_before_it_starts_is_rejected(): void {
		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'Hallensperre: «von» liegt nach «bis».' );
		new VenueClosure( 7, new \DateTimeImmutable( '2026-10-16' ), new \DateTimeImmutable( '2026-10-15' ) );
	}

	public function test_match_week_must_start_on_a_monday(): void {
		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'Spielwoche der Vorrunde beginnt nicht an einem Montag: 2026-09-08' );
		new ScheduleInput( array(), array( new \DateTimeImmutable( '2026-09-08' ) ), array() );
	}

	public function test_match_weeks_must_ascend_without_repetition(): void {
		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'Spielwochen der Rückrunde müssen aufsteigend und ohne Wiederholung sein: 2027-01-04 folgt auf 2027-01-04' );
		new ScheduleInput( array(), array(), array( new \DateTimeImmutable( '2027-01-04' ), new \DateTimeImmutable( '2027-01-04' ) ) );
	}

	public function test_second_leg_must_start_after_first_leg(): void {
		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'Die Rückrunde muss nach der letzten Spielwoche der Vorrunde beginnen.' );
		new ScheduleInput( array(), $this->mondays( '2026-09-07', 3 ), $this->mondays( '2026-09-21', 3 ) );
	}

	public function test_tables_are_null_when_unknown(): void {
		$input = new ScheduleInput(
			array(),
			array(),
			array(),
			array(),
			array(
				7       => 4,
				8       => null,
				'Halle' => 6,
			)
		);
		self::assertSame( 4, $input->tables( 7 ) );
		self::assertSame( 4, $input->tables( '7' ), 'IDs aus der Datenbank sind Strings.' );
		self::assertSame( 6, $input->tables( 'Halle' ) );
		self::assertNull( $input->tables( 8 ) );
		self::assertNull( $input->tables( 9 ) );
	}

	public function test_table_count_below_one_is_rejected(): void {
		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'Tische des Lokals 7: ganze Zahl ab 1 oder null für unbekannt.' );
		new ScheduleInput( array(), array(), array(), array(), array( 7 => 0 ) );
	}

	public function test_fixture_knows_day_and_week_across_the_new_year(): void {
		$fixture = new PlannedFixture( 'A', 4, 1, 'Seefeld 1', 'Altstetten 1', new \DateTimeImmutable( '2027-01-01 19:30' ), 'Halle Seefeld' );
		self::assertSame( '2027-01-01', $fixture->day() );
		self::assertSame( '2026-12-28', $fixture->week() );
		self::assertSame( '2026-12-28', $fixture->moved_to( new \DateTimeImmutable( '2027-01-03 10:00' ) )->week(), 'Sonntag gehört zur Woche des Montags davor.' );
		self::assertSame( '2027-01-04', $fixture->moved_to( new \DateTimeImmutable( '2027-01-04 19:30' ) )->week() );
	}

	public function test_moved_fixture_keeps_pairing_and_venue(): void {
		$fixture = new PlannedFixture( 'A', 4, 2, 'Seefeld 1', 'Altstetten 1', new \DateTimeImmutable( '2027-01-05 19:30' ), 'Halle Seefeld' );
		$moved   = $fixture->moved_to( new \DateTimeImmutable( '2027-01-12 20:00' ) );
		self::assertSame( '2027-01-05 19:30', $fixture->starts_at->format( 'Y-m-d H:i' ), 'Die ursprüngliche Begegnung bleibt unverändert.' );
		self::assertSame( '2027-01-12 20:00', $moved->starts_at->format( 'Y-m-d H:i' ) );
		self::assertSame( $fixture->pairing_key(), $moved->pairing_key() );
		self::assertSame( array( 'A', 4, 2, 'Halle Seefeld' ), array( $moved->group_id, $moved->round, $moved->leg, $moved->venue_id ) );
		self::assertSame( 'Halle Altstetten', $fixture->moved_to( $fixture->starts_at, 'Halle Altstetten' )->venue_id );
	}

	public function test_fixture_rejects_same_team_on_both_sides_and_unknown_leg(): void {
		$date = new \DateTimeImmutable( '2026-09-08 19:30' );
		try {
			new PlannedFixture( 'A', 1, 1, 12, '12', $date, 7 );
			self::fail( 'Heim und Gast gleich.' );
		} catch ( \InvalidArgumentException $e ) {
			self::assertSame( 'Heim und Gast dürfen nicht dasselbe Team sein.', $e->getMessage() );
		}
		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'Halbserie muss 1 (Hinspiel) oder 2 (Rückspiel) sein.' );
		new PlannedFixture( 'A', 1, 3, 12, 13, $date, 7 );
	}
}
