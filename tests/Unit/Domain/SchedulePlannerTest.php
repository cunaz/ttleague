<?php
declare(strict_types=1);

namespace TTLeague\Tests\Unit\Domain;

use TTLeague\Domain\Schedule\ConflictKind;
use TTLeague\Domain\Schedule\PlannedFixture;
use TTLeague\Domain\Schedule\ScheduleGenerator;
use TTLeague\Domain\Schedule\ScheduleGroup;
use TTLeague\Domain\Schedule\ScheduleInput;
use TTLeague\Domain\Schedule\SchedulePlanner;
use TTLeague\Domain\Schedule\VenueClosure;

/**
 * Gruppe A mit sechs Teams, jedes in der eigenen Halle mit vier Tischen. Fünf Runden pro
 * Halbserie, sechs Spielwochen: Die Woche vom 12. Oktober 2026 ist Reserve.
 */
final class SchedulePlannerTest extends ScheduleTestCase {

	private const CLUBS = array( 'Seefeld', 'Altstetten', 'Oerlikon', 'Wiedikon', 'Enge', 'Albisrieden' );

	/**
	 * @param list<VenueClosure> $closures Hallensperren.
	 */
	private function input( array $closures = array() ): ScheduleInput {
		return new ScheduleInput(
			array( $this->group( 'A', self::CLUBS ) ),
			$this->mondays( '2026-09-07', 6 ),
			$this->mondays( '2027-01-04', 6 ),
			$closures,
			array_fill_keys( array_map( static fn( string $club ) => 'Halle ' . $club, self::CLUBS ), 4 )
		);
	}

	/**
	 * @param list<PlannedFixture> $fixtures Plan.
	 */
	private function position( array $fixtures, string $home, string $away ): int {
		foreach ( $fixtures as $position => $fixture ) {
			if ( $home === $fixture->home_team && $away === $fixture->away_team ) {
				return $position;
			}
		}
		self::fail( "Begegnung $home – $away fehlt." );
	}

	public function test_draft_of_a_free_season_has_no_collisions(): void {
		$draft = ( new SchedulePlanner() )->plan( $this->input() );
		self::assertCount( 30, $draft->fixtures );
		self::assertSame( array(), $draft->report->conflicts );
	}

	public function test_planner_reports_collisions_but_leaves_the_draft_as_generated(): void {
		$input = $this->input( array( new VenueClosure( 'Halle Oerlikon', new \DateTimeImmutable( '2026-09-07' ), new \DateTimeImmutable( '2026-09-11' ), 'Parkett' ) ) );
		$draft = ( new SchedulePlanner() )->plan( $input );

		self::assertEquals( ( new ScheduleGenerator() )->generate( $input ), $draft->fixtures );
		self::assertCount( 1, $draft->report->conflicts );
		$conflict = $draft->report->conflicts[0];
		$fixture  = $draft->fixtures[ $this->position( $draft->fixtures, 'Oerlikon 1', 'Wiedikon 1' ) ];
		self::assertSame( ConflictKind::VenueClosed, $conflict->kind );
		self::assertSame( array( $fixture ), $conflict->fixtures );
		self::assertSame( '2026-09-09 19:30', $fixture->starts_at->format( 'Y-m-d H:i' ), 'Der Entwurf behält den gesperrten Termin.' );
		self::assertSame( '2026-10-14 19:30', $conflict->suggestion_for( $fixture )->starts_at->format( 'Y-m-d H:i' ), 'Vorschlag: Mittwoch der Reservewoche.' );
	}

	public function test_plan_changed_by_hand_is_checked_again_until_it_is_clean(): void {
		$input   = $this->input( array( new VenueClosure( 'Halle Oerlikon', new \DateTimeImmutable( '2026-10-12' ), new \DateTimeImmutable( '2026-10-16' ), 'Herbstferien' ) ) );
		$planner = new SchedulePlanner();
		$draft   = $planner->plan( $input );
		self::assertSame( array(), $draft->report->conflicts, 'Die Sperre trifft nur die Reservewoche.' );

		// Die TK verlegt Oerlikon 1 – Wiedikon 1 in die Reservewoche, mitten in die Sperre.
		$changed              = $draft->fixtures;
		$position             = $this->position( $changed, 'Oerlikon 1', 'Wiedikon 1' );
		$changed[ $position ] = $changed[ $position ]->moved_to( new \DateTimeImmutable( '2026-10-14 19:30' ) );
		$report               = $planner->check( $input, $changed );

		self::assertCount( 1, $report->conflicts );
		self::assertSame( ConflictKind::VenueClosed, $report->conflicts[0]->kind );
		self::assertSame( 'Lokal am 14.10.2026 gesperrt (Herbstferien).', $report->conflicts[0]->message );
		self::assertSame( array( $changed[ $position ] ), $report->conflicts[0]->fixtures );
		$suggestion = $report->conflicts[0]->suggestion_for( $changed[ $position ] );
		self::assertSame( '2026-09-09 19:30', $suggestion->starts_at->format( 'Y-m-d H:i' ), 'Später gibt es keine Spielwoche mehr, früher ist nur die ursprüngliche Woche für beide frei.' );

		// Vorschlag übernommen: Der Plan ist wieder der Entwurf und ohne Kollision.
		$changed[ $position ] = $suggestion->moved();
		self::assertEquals( $draft->fixtures, $changed );
		self::assertSame( array(), $planner->check( $input, $changed )->conflicts );
	}

	public function test_fixture_moved_onto_another_evening_of_the_same_team_is_found(): void {
		$input   = $this->input();
		$planner = new SchedulePlanner();
		$changed = $planner->plan( $input )->fixtures;
		$moved   = $this->position( $changed, 'Enge 1', 'Altstetten 1' );
		$other   = $this->position( $changed, 'Enge 1', 'Seefeld 1' );
		self::assertSame( '2026-09-07', $changed[ $moved ]->day() );
		self::assertSame( '2026-09-14', $changed[ $other ]->day() );

		$changed[ $moved ] = $changed[ $moved ]->moved_to( new \DateTimeImmutable( '2026-09-14 19:30' ) );
		$report            = $planner->check( $input, $changed );

		self::assertCount( 1, $report->conflicts, 'Zwei Begegnungen brauchen vier Tische, die Halle hat vier: keine Kapazitätskollision.' );
		$conflict = $report->conflicts[0];
		self::assertSame( ConflictKind::TeamDoubleBooked, $conflict->kind );
		self::assertSame( 'Enge 1', $conflict->team_id );
		self::assertSame( array( $changed[ $moved ], $changed[ $other ] ), $conflict->fixtures );
		self::assertSame( '2026-10-12 19:30', $conflict->suggestion_for( $changed[ $moved ] )->starts_at->format( 'Y-m-d H:i' ) );
		self::assertSame( '2026-10-12 19:30', $conflict->suggestion_for( $changed[ $other ] )->starts_at->format( 'Y-m-d H:i' ) );
	}

	public function test_tables_per_fixture_reaches_the_checker(): void {
		$input = new ScheduleInput(
			array(
				$this->group( 'A', array( 'Seefeld', 'Altstetten' ) ),
				new ScheduleGroup( 'B', array( $this->team( 'Seefeld 2', 1 ), $this->team( 'Oerlikon 1', 3 ) ) ),
			),
			$this->mondays( '2026-09-07', 1 ),
			$this->mondays( '2027-01-04', 1 ),
			array(),
			array(
				'Halle Seefeld'    => 4,
				'Halle Altstetten' => 4,
				'Halle Oerlikon'   => 4,
			)
		);

		// Seefeld 1 und Seefeld 2 haben am selben Montag ein Heimspiel in derselben Halle.
		$default = ( new SchedulePlanner() )->plan( $input )->report;
		self::assertSame( array( ConflictKind::ClubSameEvening ), array_map( static fn( $conflict ) => $conflict->kind, $default->conflicts ) );
		self::assertSame( array( 'Seefeld 1 – Altstetten 1', 'Seefeld 2 – Oerlikon 1' ), $this->pairings( $default->conflicts[0]->fixtures ) );

		$three = ( new SchedulePlanner( 3 ) )->plan( $input )->report;
		self::assertSame(
			array( ConflictKind::VenueCapacity, ConflictKind::ClubSameEvening ),
			array_map( static fn( $conflict ) => $conflict->kind, $three->conflicts )
		);
	}

	public function test_too_few_weeks_stop_the_plan(): void {
		$input = new ScheduleInput( array( $this->group( 'A', self::CLUBS ) ), $this->mondays( '2026-09-07', 5 ), $this->mondays( '2027-01-04', 4 ) );
		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'Zu wenige Spielwochen für die Anzahl Runden: Gruppe A braucht in der Rückrunde 5, vorhanden sind 4.' );
		( new SchedulePlanner() )->plan( $input );
	}
}
