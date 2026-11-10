<?php
declare(strict_types=1);

namespace TTLeague\Tests\Unit\Domain;

use TTLeague\Domain\Schedule\PlannedFixture;
use TTLeague\Domain\Schedule\RoundRobinScheduler;
use TTLeague\Domain\Schedule\ScheduleGenerator;
use TTLeague\Domain\Schedule\ScheduleGroup;
use TTLeague\Domain\Schedule\ScheduleInput;

final class ScheduleGeneratorTest extends ScheduleTestCase {

	private const SIX   = array( 'Seefeld', 'Altstetten', 'Oerlikon', 'Wiedikon', 'Enge', 'Albisrieden' );
	private const SEVEN = array( 'Seebach', 'Affoltern', 'Leimbach', 'Witikon', 'Hirslanden', 'Schwamendingen', 'Wipkingen' );

	/**
	 * Gruppe A mit sechs, Gruppe B mit sieben Teams; sieben Spielwochen pro Halbserie.
	 */
	private function input(): ScheduleInput {
		return new ScheduleInput(
			array( $this->group( 'A', self::SIX ), $this->group( 'B', self::SEVEN ) ),
			$this->mondays( '2026-09-07', 7 ),
			$this->mondays( '2027-01-04', 7 )
		);
	}

	/**
	 * @param list<PlannedFixture> $fixtures Plan.
	 * @return list<PlannedFixture> Begegnungen einer Gruppe.
	 */
	private function of_group( array $fixtures, string $group ): array {
		return array_values( array_filter( $fixtures, static fn( PlannedFixture $fixture ) => $group === $fixture->group_id ) );
	}

	public function test_six_teams_play_ten_rounds_of_three_fixtures(): void {
		$fixtures = $this->of_group( ( new ScheduleGenerator() )->generate( $this->input() ), 'A' );
		self::assertCount( 30, $fixtures );

		$by_round = array();
		foreach ( $fixtures as $fixture ) {
			$by_round[ $fixture->round ][] = $fixture;
		}
		self::assertSame( range( 1, 10 ), array_keys( $by_round ) );
		foreach ( $by_round as $round => $in_round ) {
			$teams = array_merge( array_column( $in_round, 'home_team' ), array_column( $in_round, 'away_team' ) );
			self::assertCount( 6, array_unique( $teams ), "Runde $round: jedes Team spielt genau einmal." );
			self::assertSame( array( $round <= 5 ? 1 : 2 ), array_values( array_unique( array_column( $in_round, 'leg' ) ) ) );
		}
		self::assertCount( 30, array_unique( $this->pairings( $fixtures ) ), 'Jedes Team empfängt jedes andere genau einmal.' );
	}

	public function test_seven_teams_have_one_bye_per_round(): void {
		$fixtures = $this->of_group( ( new ScheduleGenerator() )->generate( $this->input() ), 'B' );
		self::assertCount( 42, $fixtures );

		$by_round = array();
		$home     = array();
		$played   = array();
		foreach ( $fixtures as $fixture ) {
			$by_round[ $fixture->round ][] = $fixture;
			$home[ $fixture->home_team ]   = ( $home[ $fixture->home_team ] ?? 0 ) + 1;
			foreach ( array( $fixture->home_team, $fixture->away_team ) as $team ) {
				$played[ $team ] = ( $played[ $team ] ?? 0 ) + 1;
			}
		}
		self::assertSame( range( 1, 14 ), array_keys( $by_round ) );
		foreach ( $by_round as $round => $in_round ) {
			$teams = array_merge( array_column( $in_round, 'home_team' ), array_column( $in_round, 'away_team' ) );
			self::assertCount( 6, array_unique( $teams ), "Runde $round: sechs Teams spielen, eines ist spielfrei." );
		}
		self::assertSame( array( 12 ), array_values( array_unique( $played ) ) );
		self::assertSame( array( 6 ), array_values( array_unique( $home ) ), 'Über beide Halbserien hat jedes Team gleich viele Heimspiele.' );
	}

	public function test_second_leg_mirrors_first_leg_with_swapped_home_right(): void {
		$input    = $this->input();
		$fixtures = ( new ScheduleGenerator() )->generate( $input );
		foreach ( array( 'A', 'B' ) as $index => $group ) {
			$per_leg = $input->groups[ $index ]->rounds_per_leg();
			$first   = array();
			$second  = array();
			foreach ( $this->of_group( $fixtures, $group ) as $fixture ) {
				if ( 1 === $fixture->leg ) {
					$first[] = $fixture->round . ': ' . $fixture->home_team . ' – ' . $fixture->away_team;
				} else {
					$second[] = ( $fixture->round - $per_leg ) . ': ' . $fixture->away_team . ' – ' . $fixture->home_team;
				}
			}
			self::assertNotSame( array(), $first );
			self::assertSame( $first, $second, "Gruppe $group: Rückrunde in derselben Reihenfolge, Heimrecht getauscht." );
		}
	}

	public function test_pairings_come_from_the_round_robin_scheduler(): void {
		$input    = $this->input();
		$expected = array();
		foreach ( ( new RoundRobinScheduler() )->double_round_robin( $input->groups[1]->team_ids() ) as $round ) {
			foreach ( $round as $pair ) {
				$expected[] = $pair['home'] . ' – ' . $pair['away'];
			}
		}
		self::assertSame( $expected, $this->pairings( $this->of_group( ( new ScheduleGenerator() )->generate( $input ), 'B' ) ) );
	}

	public function test_date_is_match_week_plus_home_weekday_at_start_time_in_home_venue(): void {
		$input = $this->input();
		$weeks = array_merge( $input->first_leg_weeks, $input->second_leg_weeks );
		foreach ( $this->of_group( ( new ScheduleGenerator() )->generate( $input ), 'B' ) as $fixture ) {
			$home = $input->team( $fixture->home_team );
			self::assertSame( $weeks[ $fixture->round - 1 ]->format( 'Y-m-d' ), $fixture->week() );
			self::assertSame( (string) $home->home_weekday, $fixture->starts_at->format( 'N' ) );
			self::assertSame( $home->start_time, $fixture->starts_at->format( 'H:i' ) );
			self::assertSame( $home->venue_id, $fixture->venue_id );
		}
	}

	public function test_first_fixtures_of_a_group(): void {
		$fixtures = ( new ScheduleGenerator() )->generate(
			new ScheduleInput(
				array(
					new ScheduleGroup(
						'A',
						array(
							$this->team( 'Seefeld 1', 2, '19:30' ),
							$this->team( 'Altstetten 1', 4, '20:00' ),
							$this->team( 'Oerlikon 1', 1, '19:45' ),
						)
					),
				),
				$this->mondays( '2026-09-07', 3 ),
				$this->mondays( '2027-01-04', 3 )
			)
		);
		self::assertSame(
			array(
				'1/1 Oerlikon 1 – Altstetten 1, 2026-09-07 19:45, Halle Oerlikon',
				'2/1 Seefeld 1 – Oerlikon 1, 2026-09-15 19:30, Halle Seefeld',
				'3/1 Altstetten 1 – Seefeld 1, 2026-09-24 20:00, Halle Altstetten',
				'4/2 Altstetten 1 – Oerlikon 1, 2027-01-07 20:00, Halle Altstetten',
				'5/2 Oerlikon 1 – Seefeld 1, 2027-01-11 19:45, Halle Oerlikon',
				'6/2 Seefeld 1 – Altstetten 1, 2027-01-19 19:30, Halle Seefeld',
			),
			array_map(
				static fn( PlannedFixture $f ) => sprintf( '%d/%d %s – %s, %s, %s', $f->round, $f->leg, $f->home_team, $f->away_team, $f->starts_at->format( 'Y-m-d H:i' ), $f->venue_id ),
				$fixtures
			)
		);
	}

	public function test_group_with_fewer_rounds_uses_the_first_weeks_of_each_leg(): void {
		$fixtures = ( new ScheduleGenerator() )->generate( $this->input() );
		$weeks    = static fn( array $group ) => array_values( array_unique( array_map( static fn( PlannedFixture $fixture ) => $fixture->week(), $group ) ) );
		$mondays  = static fn( array $dates ) => array_map( static fn( \DateTimeImmutable $monday ) => $monday->format( 'Y-m-d' ), $dates );

		self::assertSame(
			array_merge( $mondays( $this->mondays( '2026-09-07', 5 ) ), $mondays( $this->mondays( '2027-01-04', 5 ) ) ),
			$weeks( $this->of_group( $fixtures, 'A' ) ),
			'Sechs Teams: fünf Runden pro Halbserie, die letzten zwei Spielwochen bleiben Reserve.'
		);
		self::assertSame(
			array_merge( $mondays( $this->mondays( '2026-09-07', 7 ) ), $mondays( $this->mondays( '2027-01-04', 7 ) ) ),
			$weeks( $this->of_group( $fixtures, 'B' ) )
		);
	}

	public function test_week_across_the_new_year(): void {
		$fixtures = ( new ScheduleGenerator() )->generate(
			new ScheduleInput(
				array( new ScheduleGroup( 'A', array( $this->team( 'Seefeld 1', 5 ), $this->team( 'Altstetten 1', 4 ) ) ) ),
				array( new \DateTimeImmutable( '2026-12-28' ) ),
				array( new \DateTimeImmutable( '2027-12-27' ) )
			)
		);
		self::assertSame(
			array( 'Seefeld 1 2027-01-01 19:30', 'Altstetten 1 2027-12-30 19:30' ),
			array_map( static fn( PlannedFixture $f ) => $f->home_team . ' ' . $f->starts_at->format( 'Y-m-d H:i' ), $fixtures )
		);
		self::assertSame( array( '2026-12-28', '2027-12-27' ), array_map( static fn( PlannedFixture $f ) => $f->week(), $fixtures ) );
	}

	public function test_too_few_weeks_name_group_leg_and_numbers(): void {
		$input = new ScheduleInput(
			array( $this->group( 'A', self::SIX ), $this->group( 'B', self::SEVEN ) ),
			$this->mondays( '2026-09-07', 7 ),
			$this->mondays( '2027-01-04', 6 )
		);
		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'Zu wenige Spielwochen für die Anzahl Runden: Gruppe B braucht in der Rückrunde 7, vorhanden sind 6.' );
		( new ScheduleGenerator() )->generate( $input );
	}

	public function test_too_few_weeks_list_every_group_and_leg(): void {
		$input = new ScheduleInput(
			array( $this->group( 'A', self::SIX ), $this->group( 'B', self::SEVEN ) ),
			$this->mondays( '2026-09-07', 4 ),
			$this->mondays( '2027-01-04', 5 )
		);
		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage(
			'Zu wenige Spielwochen für die Anzahl Runden: Gruppe A braucht in der Vorrunde 5, vorhanden sind 4; '
			. 'Gruppe B braucht in der Vorrunde 7, vorhanden sind 4; Gruppe B braucht in der Rückrunde 7, vorhanden sind 5.'
		);
		( new ScheduleGenerator() )->generate( $input );
	}

	public function test_same_input_gives_same_plan(): void {
		$first  = ( new ScheduleGenerator() )->generate( $this->input() );
		$second = ( new ScheduleGenerator() )->generate( $this->input() );
		self::assertCount( 72, $first );
		self::assertEquals( $first, $second );
	}
}
