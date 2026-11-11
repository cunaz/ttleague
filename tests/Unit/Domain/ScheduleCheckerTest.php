<?php
declare(strict_types=1);

namespace TTLeague\Tests\Unit\Domain;

use TTLeague\Domain\Schedule\ConflictKind;
use TTLeague\Domain\Schedule\PlannedFixture;
use TTLeague\Domain\Schedule\ScheduleChecker;
use TTLeague\Domain\Schedule\ScheduleConflict;
use TTLeague\Domain\Schedule\ScheduleGroup;
use TTLeague\Domain\Schedule\ScheduleInput;
use TTLeague\Domain\Schedule\ScheduleSuggestion;
use TTLeague\Domain\Schedule\VenueClosure;

/**
 * Spielwochen der Vorrunde: 7., 14., 21. und 28. September 2026 (Montage). Seefeld 1, Seefeld 2
 * und Enge 1 spielen am Dienstag, Altstetten 1 am Mittwoch, Oerlikon 1 am Donnerstag und
 * Wiedikon 1 am Montag.
 */
final class ScheduleCheckerTest extends ScheduleTestCase {

	private const TABLES = array(
		'Halle Seefeld'    => 4,
		'Halle Altstetten' => 4,
		'Halle Oerlikon'   => 4,
		'Halle Wiedikon'   => 4,
		'Halle Enge'       => 4,
	);

	/**
	 * @param list<VenueClosure>    $closures    Hallensperren.
	 * @param array<string,int|null> $tables     Tische je Lokal.
	 * @param int                   $first_weeks Anzahl Spielwochen der Vorrunde.
	 */
	private function input( array $closures = array(), array $tables = self::TABLES, int $first_weeks = 4 ): ScheduleInput {
		return new ScheduleInput(
			array(
				new ScheduleGroup( 'A', array( $this->team( 'Seefeld 1', 2 ), $this->team( 'Altstetten 1', 3, '19:45' ), $this->team( 'Oerlikon 1', 4 ) ) ),
				new ScheduleGroup( 'B', array( $this->team( 'Seefeld 2', 2 ), $this->team( 'Wiedikon 1', 1 ), $this->team( 'Enge 1', 2 ) ) ),
			),
			$this->mondays( '2026-09-07', $first_weeks ),
			$this->mondays( '2027-01-04', 2 ),
			$closures,
			$tables
		);
	}

	private function closure( string $venue, string $from, string $to, string $reason = '' ): VenueClosure {
		return new VenueClosure( $venue, new \DateTimeImmutable( $from ), new \DateTimeImmutable( $to ), $reason );
	}

	/**
	 * @return list<string> «Heim – Gast: neuer Termin».
	 */
	private function suggested( ScheduleConflict $conflict ): array {
		return array_map(
			static fn( ScheduleSuggestion $s ) => $s->fixture->home_team . ' – ' . $s->fixture->away_team . ': ' . $s->starts_at->format( 'Y-m-d H:i' ),
			$conflict->suggestions
		);
	}

	/**
	 * @param list<ScheduleConflict> $conflicts Kollisionen.
	 * @return list<string> Arten.
	 */
	private function kinds( array $conflicts ): array {
		return array_map( static fn( ScheduleConflict $conflict ) => $conflict->kind->value, $conflicts );
	}

	public function test_plan_without_collisions_gives_empty_report(): void {
		$input  = $this->input();
		$report = ( new ScheduleChecker() )->check(
			$input,
			array(
				$this->fixture( $input, 'Seefeld 1', 'Altstetten 1', '2026-09-08 19:30' ),
				$this->fixture( $input, 'Wiedikon 1', 'Seefeld 2', '2026-09-07 19:30' ),
				$this->fixture( $input, 'Seefeld 2', 'Enge 1', '2026-09-15 19:30' ),
			)
		);
		self::assertSame( array(), $report->conflicts );
		self::assertFalse( $report->has_hard_conflicts() );
		self::assertSame( array(), ( new ScheduleChecker() )->check( $input, array() )->conflicts );
	}

	public function test_closed_venue_is_hard_and_suggests_next_week_free_for_both_teams(): void {
		$input    = $this->input( array( $this->closure( 'Halle Seefeld', '2026-09-08', '2026-09-08', 'Hallenreinigung' ) ) );
		$fixtures = array(
			$this->fixture( $input, 'Seefeld 1', 'Altstetten 1', '2026-09-08 19:30' ),
			// Altstetten 1 spielt in der zweiten Spielwoche schon: Der Vorschlag fällt in die dritte.
			$this->fixture( $input, 'Altstetten 1', 'Oerlikon 1', '2026-09-16 19:45' ),
		);
		$report   = ( new ScheduleChecker() )->check( $input, $fixtures );

		self::assertCount( 1, $report->conflicts );
		$conflict = $report->conflicts[0];
		self::assertSame( ConflictKind::VenueClosed, $conflict->kind );
		self::assertTrue( $conflict->is_hard() );
		self::assertTrue( $report->has_hard_conflicts() );
		self::assertSame( 'Lokal am 08.09.2026 gesperrt (Hallenreinigung).', $conflict->message );
		self::assertSame( array( $fixtures[0] ), $conflict->fixtures );
		self::assertSame( array( '2026-09-08', 'Halle Seefeld' ), array( $conflict->day, $conflict->venue_id ) );
		self::assertSame( array( 'Seefeld 1 – Altstetten 1: 2026-09-22 19:30' ), $this->suggested( $conflict ) );

		$suggestion = $conflict->suggestion_for( $fixtures[0] );
		self::assertNotNull( $suggestion );
		self::assertSame( '2026-09-22 19:30', $suggestion->moved()->starts_at->format( 'Y-m-d H:i' ) );
		self::assertSame( 'Halle Seefeld', $suggestion->moved()->venue_id );
		self::assertNull( $conflict->suggestion_for( $fixtures[1] ) );
	}

	public function test_closure_without_reason_has_plain_message(): void {
		$input  = $this->input( array( $this->closure( 'Halle Seefeld', '2026-09-08', '2026-09-08' ) ) );
		$report = ( new ScheduleChecker() )->check( $input, array( $this->fixture( $input, 'Seefeld 1', 'Altstetten 1', '2026-09-08 19:30' ) ) );
		self::assertSame( 'Lokal am 08.09.2026 gesperrt.', $report->conflicts[0]->message );
	}

	public function test_closed_venue_without_suggestion_when_no_week_of_the_leg_is_free(): void {
		$input    = $this->input( array( $this->closure( 'Halle Seefeld', '2026-09-08', '2026-09-08' ) ) );
		$fixtures = array(
			$this->fixture( $input, 'Seefeld 1', 'Altstetten 1', '2026-09-08 19:30' ),
			$this->fixture( $input, 'Seefeld 1', 'Oerlikon 1', '2026-09-15 19:30' ),
			$this->fixture( $input, 'Oerlikon 1', 'Seefeld 1', '2026-09-24 19:30' ),
			$this->fixture( $input, 'Altstetten 1', 'Oerlikon 1', '2026-09-30 19:45' ),
		);
		$report   = ( new ScheduleChecker() )->check( $input, $fixtures );

		self::assertSame( array( 'venue_closed' ), $this->kinds( $report->conflicts ) );
		self::assertSame( array(), $report->conflicts[0]->suggestions, 'Die Spielwochen der Rückrunde sind frei, kommen für ein Hinspiel aber nicht in Frage.' );
		self::assertNull( $report->conflicts[0]->suggestion_for( $fixtures[0] ) );
	}

	public function test_closed_venue_without_suggestion_when_closure_covers_all_home_dates(): void {
		$input  = $this->input( array( $this->closure( 'Halle Seefeld', '2026-09-01', '2026-12-31', 'Umbau' ) ) );
		$report = ( new ScheduleChecker() )->check( $input, array( $this->fixture( $input, 'Seefeld 1', 'Altstetten 1', '2026-09-08 19:30' ) ) );
		self::assertSame( array( 'venue_closed' ), $this->kinds( $report->conflicts ) );
		self::assertSame( array(), $report->conflicts[0]->suggestions );
	}

	public function test_closure_applies_on_first_and_last_day_only_in_its_venue(): void {
		$input    = $this->input( array( $this->closure( 'Halle Seefeld', '2026-09-08', '2026-09-15', 'Hallenboden' ) ) );
		$fixtures = array(
			$this->fixture( $input, 'Wiedikon 1', 'Enge 1', '2026-09-07 19:30', 1, 'Halle Seefeld' ),
			$this->fixture( $input, 'Seefeld 1', 'Altstetten 1', '2026-09-08 19:30' ),
			$this->fixture( $input, 'Enge 1', 'Oerlikon 1', '2026-09-08 19:30' ),
			$this->fixture( $input, 'Seefeld 2', 'Wiedikon 1', '2026-09-15 19:30' ),
			$this->fixture( $input, 'Altstetten 1', 'Oerlikon 1', '2026-09-16 19:45', 1, 'Halle Seefeld' ),
		);
		$closed   = ( new ScheduleChecker() )->check( $input, $fixtures )->of_kind( ConflictKind::VenueClosed );

		self::assertSame(
			array( array( $fixtures[1] ), array( $fixtures[3] ) ),
			array_map( static fn( ScheduleConflict $conflict ) => $conflict->fixtures, $closed ),
			'Erster und letzter Tag der Sperre zählen, der Tag davor und danach nicht, andere Lokale nie.'
		);
	}

	public function test_second_leg_fixture_is_suggested_within_the_second_leg(): void {
		$input  = $this->input( array( $this->closure( 'Halle Seefeld', '2027-01-05', '2027-01-05' ) ) );
		$report = ( new ScheduleChecker() )->check( $input, array( $this->fixture( $input, 'Seefeld 1', 'Altstetten 1', '2027-01-05 19:30', 2 ) ) );
		self::assertSame( array( 'Seefeld 1 – Altstetten 1: 2027-01-12 19:30' ), $this->suggested( $report->conflicts[0] ) );

		// Als Hinspiel in derselben Woche (von Hand weit nach hinten verlegt) bleibt nur die Vorrunde.
		$report = ( new ScheduleChecker() )->check( $input, array( $this->fixture( $input, 'Seefeld 1', 'Altstetten 1', '2027-01-05 19:30', 1 ) ) );
		self::assertSame( array( 'Seefeld 1 – Altstetten 1: 2026-09-29 19:30' ), $this->suggested( $report->conflicts[0] ) );
	}

	public function test_suggestion_looks_later_first_then_earlier_nearest_first(): void {
		$input    = $this->input( array( $this->closure( 'Halle Seefeld', '2026-09-22', '2026-09-29' ) ) );
		$fixtures = array( $this->fixture( $input, 'Seefeld 1', 'Altstetten 1', '2026-09-22 19:30' ) );
		$report   = ( new ScheduleChecker() )->check( $input, $fixtures );
		self::assertSame( array( 'Seefeld 1 – Altstetten 1: 2026-09-15 19:30' ), $this->suggested( $report->conflicts[0] ), 'Später ist gesperrt, also die nächstfrühere Woche.' );

		$fixtures[] = $this->fixture( $input, 'Oerlikon 1', 'Altstetten 1', '2026-09-17 19:30' );
		$report     = ( new ScheduleChecker() )->check( $input, $fixtures );
		self::assertSame( array( 'Seefeld 1 – Altstetten 1: 2026-09-08 19:30' ), $this->suggested( $report->conflicts[0] ) );

		$open   = $this->input( array( $this->closure( 'Halle Seefeld', '2026-09-22', '2026-09-22' ) ) );
		$report = ( new ScheduleChecker() )->check( $open, array( $this->fixture( $open, 'Seefeld 1', 'Altstetten 1', '2026-09-22 19:30' ) ) );
		self::assertSame( array( 'Seefeld 1 – Altstetten 1: 2026-09-29 19:30' ), $this->suggested( $report->conflicts[0] ), 'Ein späterer Termin geht einem früheren vor, auch wenn der frühere frei ist.' );
	}

	public function test_suggestion_is_on_home_weekday_at_start_time_even_for_a_fixture_moved_by_hand(): void {
		// Von Hand auf den Donnerstag verlegt, dort ist die Halle gesperrt.
		$input  = $this->input( array( $this->closure( 'Halle Altstetten', '2026-09-10', '2026-09-10' ) ) );
		$report = ( new ScheduleChecker() )->check( $input, array( $this->fixture( $input, 'Altstetten 1', 'Seefeld 1', '2026-09-10 20:30' ) ) );
		self::assertSame( array( 'Altstetten 1 – Seefeld 1: 2026-09-16 19:45' ), $this->suggested( $report->conflicts[0] ) );

		// Sind alle späteren Mittwoche gesperrt, bleibt der Mittwoch derselben Woche.
		$input  = $this->input( array( $this->closure( 'Halle Altstetten', '2026-09-10', '2026-09-30' ) ) );
		$report = ( new ScheduleChecker() )->check( $input, array( $this->fixture( $input, 'Altstetten 1', 'Seefeld 1', '2026-09-10 20:30' ) ) );
		self::assertSame( array( 'Altstetten 1 – Seefeld 1: 2026-09-09 19:45' ), $this->suggested( $report->conflicts[0] ) );
	}

	public function test_capacity_is_hard_when_fixtures_need_more_tables_than_the_venue_has(): void {
		$input    = $this->input( array(), array( 'Halle Seefeld' => 3 ) + self::TABLES );
		$fixtures = array(
			$this->fixture( $input, 'Seefeld 1', 'Altstetten 1', '2026-09-08 19:30' ),
			$this->fixture( $input, 'Wiedikon 1', 'Enge 1', '2026-09-07 19:30' ),
			$this->fixture( $input, 'Seefeld 2', 'Wiedikon 1', '2026-09-08 19:30' ),
		);
		$report   = ( new ScheduleChecker() )->check( $input, $fixtures );
		$capacity = $report->of_kind( ConflictKind::VenueCapacity );

		self::assertCount( 1, $capacity );
		self::assertTrue( $capacity[0]->is_hard() );
		self::assertSame( 'Kapazität des Lokals am 08.09.2026 überschritten: 2 × 2 Tische nötig, 3 vorhanden.', $capacity[0]->message );
		self::assertSame( array( $fixtures[0], $fixtures[2] ), $capacity[0]->fixtures );
		self::assertSame( array( '2026-09-08', 'Halle Seefeld' ), array( $capacity[0]->day, $capacity[0]->venue_id ) );
		// Jeder Vorschlag gilt für sich: Es genügt, eine der beiden Begegnungen zu verlegen.
		self::assertSame(
			array( 'Seefeld 1 – Altstetten 1: 2026-09-15 19:30', 'Seefeld 2 – Wiedikon 1: 2026-09-15 19:30' ),
			$this->suggested( $capacity[0] )
		);
	}

	public function test_capacity_exactly_used_is_no_collision(): void {
		$input  = $this->input();
		$report = ( new ScheduleChecker() )->check(
			$input,
			array(
				$this->fixture( $input, 'Seefeld 1', 'Altstetten 1', '2026-09-08 19:30' ),
				$this->fixture( $input, 'Seefeld 2', 'Wiedikon 1', '2026-09-08 19:30' ),
			)
		);
		self::assertSame( array(), $report->of_kind( ConflictKind::VenueCapacity ) );
		self::assertFalse( $report->has_hard_conflicts() );
	}

	public function test_capacity_suggestion_skips_an_evening_that_is_already_full(): void {
		$input    = $this->input( array(), array( 'Halle Seefeld' => 3 ) + self::TABLES );
		$fixtures = array(
			$this->fixture( $input, 'Seefeld 1', 'Altstetten 1', '2026-09-08 19:30' ),
			$this->fixture( $input, 'Seefeld 2', 'Wiedikon 1', '2026-09-08 19:30' ),
			// Gastrecht in der Halle Seefeld: Mit drei Tischen ist der Abend damit voll.
			$this->fixture( $input, 'Enge 1', 'Oerlikon 1', '2026-09-15 19:30', 1, 'Halle Seefeld' ),
		);
		$capacity = ( new ScheduleChecker() )->check( $input, $fixtures )->of_kind( ConflictKind::VenueCapacity );

		self::assertCount( 1, $capacity );
		self::assertSame(
			array( 'Seefeld 1 – Altstetten 1: 2026-09-22 19:30', 'Seefeld 2 – Wiedikon 1: 2026-09-22 19:30' ),
			$this->suggested( $capacity[0] )
		);
	}

	public function test_capacity_without_suggestion_when_the_leg_has_no_other_week(): void {
		$input    = $this->input( array(), array( 'Halle Seefeld' => 3 ) + self::TABLES, 1 );
		$fixtures = array(
			$this->fixture( $input, 'Seefeld 1', 'Altstetten 1', '2026-09-08 19:30' ),
			$this->fixture( $input, 'Seefeld 2', 'Wiedikon 1', '2026-09-08 19:30' ),
		);
		$capacity = ( new ScheduleChecker() )->check( $input, $fixtures )->of_kind( ConflictKind::VenueCapacity );

		self::assertCount( 1, $capacity );
		self::assertSame( array(), $capacity[0]->suggestions );
	}

	public function test_tables_per_fixture_is_a_constructor_parameter(): void {
		$input    = $this->input();
		$fixtures = array(
			$this->fixture( $input, 'Seefeld 1', 'Altstetten 1', '2026-09-08 19:30' ),
			$this->fixture( $input, 'Seefeld 2', 'Wiedikon 1', '2026-09-08 19:30' ),
		);
		self::assertSame( array(), ( new ScheduleChecker( 2 ) )->check( $input, $fixtures )->of_kind( ConflictKind::VenueCapacity ) );

		$capacity = ( new ScheduleChecker( 3 ) )->check( $input, $fixtures )->of_kind( ConflictKind::VenueCapacity );
		self::assertCount( 1, $capacity );
		self::assertSame( 'Kapazität des Lokals am 08.09.2026 überschritten: 2 × 3 Tische nötig, 4 vorhanden.', $capacity[0]->message );

		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'Tische pro Begegnung: mindestens 1.' );
		new ScheduleChecker( 0 );
	}

	public function test_unknown_table_count_gives_one_hint_per_venue_instead_of_a_capacity_check(): void {
		$tables   = array(
			'Halle Seefeld'  => null,
			'Halle Wiedikon' => 4,
			'Halle Oerlikon' => 4,
		);
		$input    = $this->input( array(), $tables );
		$fixtures = array(
			$this->fixture( $input, 'Seefeld 1', 'Altstetten 1', '2026-09-08 19:30' ),
			$this->fixture( $input, 'Seefeld 2', 'Wiedikon 1', '2026-09-08 19:30' ),
			$this->fixture( $input, 'Enge 1', 'Oerlikon 1', '2026-09-08 19:30', 1, 'Halle Seefeld' ),
			$this->fixture( $input, 'Wiedikon 1', 'Enge 1', '2026-09-14 19:30' ),
			$this->fixture( $input, 'Seefeld 1', 'Oerlikon 1', '2026-09-15 19:30' ),
			$this->fixture( $input, 'Altstetten 1', 'Oerlikon 1', '2026-09-23 19:45' ),
		);
		$report   = ( new ScheduleChecker() )->check( $input, $fixtures );

		self::assertSame( array(), $report->of_kind( ConflictKind::VenueCapacity ), 'Ohne Tischzahl keine Kapazitätsprüfung.' );
		$unknown = $report->of_kind( ConflictKind::TablesUnknown );
		self::assertSame( array( 'Halle Seefeld', 'Halle Altstetten' ), array_map( static fn( ScheduleConflict $c ) => $c->venue_id, $unknown ), 'Ein Hinweis pro Lokal, kein Eintrag gilt wie null.' );
		self::assertFalse( $unknown[0]->is_hard() );
		self::assertSame( 'Tischzahl des Lokals unbekannt, Kapazität nicht geprüft (höchstens 3 × 2 Tische an einem Abend nötig).', $unknown[0]->message );
		self::assertSame( array( $fixtures[0], $fixtures[1], $fixtures[2], $fixtures[4] ), $unknown[0]->fixtures );
		self::assertSame( array( null, array() ), array( $unknown[0]->day, $unknown[0]->suggestions ) );
		self::assertSame( 'Tischzahl des Lokals unbekannt, Kapazität nicht geprüft (höchstens 1 × 2 Tische an einem Abend nötig).', $unknown[1]->message );
		self::assertSame( array( $fixtures[5] ), $unknown[1]->fixtures );
		self::assertFalse( $report->has_hard_conflicts() );
	}

	public function test_two_teams_of_a_club_on_the_same_evening_are_a_hint_with_suggestions(): void {
		$input    = $this->input();
		$fixtures = array(
			$this->fixture( $input, 'Seefeld 1', 'Altstetten 1', '2026-09-08 19:30' ),
			$this->fixture( $input, 'Enge 1', 'Seefeld 2', '2026-09-08 19:30' ),
		);
		$report   = ( new ScheduleChecker() )->check( $input, $fixtures );

		self::assertCount( 1, $report->conflicts );
		$conflict = $report->conflicts[0];
		self::assertSame( ConflictKind::ClubSameEvening, $conflict->kind );
		self::assertFalse( $conflict->is_hard() );
		self::assertFalse( $report->has_hard_conflicts() );
		self::assertSame( array( $conflict ), $report->hints() );
		self::assertSame( '2 Mannschaften desselben Clubs spielen am 08.09.2026; Ersatzspieler fehlen dann.', $conflict->message );
		self::assertSame( $fixtures, $conflict->fixtures );
		self::assertSame( array( '2026-09-08', 'Seefeld' ), array( $conflict->day, $conflict->club_id ) );
		self::assertSame(
			array( 'Seefeld 1 – Altstetten 1: 2026-09-15 19:30', 'Enge 1 – Seefeld 2: 2026-09-15 19:30' ),
			$this->suggested( $conflict )
		);
	}

	public function test_club_hint_suggestion_avoids_evenings_on_which_the_club_plays(): void {
		$fixtures = fn( ScheduleInput $input ): array => array(
			$this->fixture( $input, 'Seefeld 1', 'Altstetten 1', '2026-09-08 19:30' ),
			$this->fixture( $input, 'Enge 1', 'Seefeld 2', '2026-09-08 19:30' ),
			$this->fixture( $input, 'Seefeld 2', 'Wiedikon 1', '2026-09-15 19:30' ),
		);

		$input = $this->input();
		$club  = ( new ScheduleChecker() )->check( $input, $fixtures( $input ) )->of_kind( ConflictKind::ClubSameEvening );
		self::assertCount( 1, $club );
		self::assertSame(
			array( 'Seefeld 1 – Altstetten 1: 2026-09-22 19:30', 'Enge 1 – Seefeld 2: 2026-09-22 19:30' ),
			$this->suggested( $club[0] ),
			'Am 15. September spielt Seefeld 2: Für Seefeld 1 bliebe der Hinweis, Seefeld 2 hat die Woche schon belegt.'
		);

		// Bei einer harten Kollision zählt der Club nicht: Der 15. September ist für Seefeld 1 ein gültiger Vorschlag.
		$input  = $this->input( array( $this->closure( 'Halle Seefeld', '2026-09-08', '2026-09-08' ) ) );
		$closed = ( new ScheduleChecker() )->check( $input, $fixtures( $input ) )->of_kind( ConflictKind::VenueClosed );
		self::assertSame( array( 'Seefeld 1 – Altstetten 1: 2026-09-15 19:30' ), $this->suggested( $closed[0] ) );
	}

	public function test_club_hint_without_suggestion_when_the_leg_has_no_other_week(): void {
		$input    = $this->input( array(), self::TABLES, 1 );
		$fixtures = array(
			$this->fixture( $input, 'Seefeld 1', 'Altstetten 1', '2026-09-08 19:30' ),
			$this->fixture( $input, 'Enge 1', 'Seefeld 2', '2026-09-08 19:30' ),
		);
		$report   = ( new ScheduleChecker() )->check( $input, $fixtures );

		self::assertSame( array( 'club_same_evening' ), $this->kinds( $report->conflicts ) );
		self::assertSame( array(), $report->conflicts[0]->suggestions );
	}

	public function test_club_duel_alone_is_no_hint(): void {
		$input  = $this->input();
		$report = ( new ScheduleChecker() )->check( $input, array( $this->fixture( $input, 'Seefeld 1', 'Seefeld 2', '2026-09-08 19:30' ) ) );
		self::assertSame( array(), $report->conflicts );
	}

	public function test_team_with_two_fixtures_on_one_evening_is_hard(): void {
		$input    = $this->input();
		$fixtures = array(
			$this->fixture( $input, 'Seefeld 1', 'Altstetten 1', '2026-09-08 19:30' ),
			// Von Hand vom Donnerstag auf den Dienstag vorgezogen.
			$this->fixture( $input, 'Oerlikon 1', 'Seefeld 1', '2026-09-08 19:30' ),
		);
		$report   = ( new ScheduleChecker() )->check( $input, $fixtures );

		self::assertCount( 1, $report->conflicts, 'Dieselbe Mannschaft zweimal ist kein Hinweis «Club am selben Abend».' );
		$conflict = $report->conflicts[0];
		self::assertSame( ConflictKind::TeamDoubleBooked, $conflict->kind );
		self::assertTrue( $conflict->is_hard() );
		self::assertSame( 'Eine Mannschaft hat am 08.09.2026 2 Begegnungen.', $conflict->message );
		self::assertSame( array( '2026-09-08', 'Seefeld 1' ), array( $conflict->day, $conflict->team_id ) );
		self::assertSame( $fixtures, $conflict->fixtures );
		self::assertSame(
			array( 'Seefeld 1 – Altstetten 1: 2026-09-15 19:30', 'Oerlikon 1 – Seefeld 1: 2026-09-17 19:30' ),
			$this->suggested( $conflict ),
			'Der Donnerstag derselben Woche scheidet aus: Seefeld 1 spielt in dieser Woche schon.'
		);
	}

	public function test_suggestion_counts_weeks_across_the_new_year(): void {
		$input = fn( array $closures ): ScheduleInput => new ScheduleInput(
			array( new ScheduleGroup( 'A', array( $this->team( 'Seefeld 1', 5 ), $this->team( 'Altstetten 1', 3 ), $this->team( 'Oerlikon 1', 7 ) ) ) ),
			$this->mondays( '2026-12-21', 3 ),
			$this->mondays( '2027-02-01', 3 ),
			$closures,
			self::TABLES
		);

		$christmas = $this->closure( 'Halle Seefeld', '2026-12-24', '2026-12-26', 'Weihnachten' );
		$plan      = $input( array( $christmas ) );
		$fixture   = $this->fixture( $plan, 'Seefeld 1', 'Altstetten 1', '2026-12-25 19:30' );

		// Altstetten 1 spielt am Montag, 4. Januar: Die Woche vom 28. Dezember ist frei.
		$report = ( new ScheduleChecker() )->check( $plan, array( $fixture, $this->fixture( $plan, 'Altstetten 1', 'Oerlikon 1', '2027-01-04 19:30' ) ) );
		self::assertSame( 'Lokal am 25.12.2026 gesperrt (Weihnachten).', $report->conflicts[0]->message );
		self::assertSame( array( 'Seefeld 1 – Altstetten 1: 2027-01-01 19:30' ), $this->suggested( $report->conflicts[0] ) );

		// Altstetten 1 spielt am Sonntag, 3. Januar: Das ist noch die Woche vom 28. Dezember.
		$report = ( new ScheduleChecker() )->check( $plan, array( $fixture, $this->fixture( $plan, 'Oerlikon 1', 'Altstetten 1', '2027-01-03 10:00' ) ) );
		self::assertSame( array( 'Seefeld 1 – Altstetten 1: 2027-01-08 19:30' ), $this->suggested( $report->conflicts[0] ) );

		// Sperre über den Jahreswechsel bis und mit Neujahr, die Woche danach ist belegt: kein Vorschlag.
		$plan    = $input( array( $christmas, $this->closure( 'Halle Seefeld', '2026-12-28', '2027-01-01', 'Neujahr' ) ) );
		$fixture = $this->fixture( $plan, 'Seefeld 1', 'Altstetten 1', '2026-12-25 19:30' );
		$report  = ( new ScheduleChecker() )->check( $plan, array( $fixture, $this->fixture( $plan, 'Altstetten 1', 'Oerlikon 1', '2027-01-04 19:30' ) ) );
		self::assertSame( array( 'venue_closed' ), $this->kinds( $report->conflicts ) );
		self::assertSame( array(), $report->conflicts[0]->suggestions );
	}

	public function test_report_is_ordered_by_day_then_kind_with_undated_hints_last(): void {
		$tables = self::TABLES;
		unset( $tables['Halle Enge'] );
		$input    = $this->input( array( $this->closure( 'Halle Seefeld', '2026-09-08', '2026-09-08' ) ), array( 'Halle Seefeld' => 2 ) + $tables );
		$fixtures = array(
			$this->fixture( $input, 'Seefeld 2', 'Enge 1', '2026-09-15 19:30' ),
			$this->fixture( $input, 'Seefeld 1', 'Oerlikon 1', '2026-09-15 19:30' ),
			$this->fixture( $input, 'Enge 1', 'Wiedikon 1', '2026-09-22 19:30' ),
			$this->fixture( $input, 'Seefeld 1', 'Altstetten 1', '2026-09-08 19:30' ),
		);
		$checker  = new ScheduleChecker();
		$report   = $checker->check( $input, $fixtures );

		self::assertSame( array( 'venue_closed', 'venue_capacity', 'club_same_evening', 'tables_unknown' ), $this->kinds( $report->conflicts ) );
		self::assertSame( array( '2026-09-08', '2026-09-15', '2026-09-15', null ), array_map( static fn( ScheduleConflict $c ) => $c->day, $report->conflicts ) );
		self::assertSame( array( 'venue_closed', 'venue_capacity' ), $this->kinds( $report->hard() ) );
		self::assertSame( array( 'club_same_evening', 'tables_unknown' ), $this->kinds( $report->hints() ) );
		self::assertSame( array( $fixtures[0], $fixtures[1] ), $report->conflicts[1]->fixtures, 'Begegnungen in der Reihenfolge des Plans.' );
		self::assertEquals( $report, $checker->check( $input, $fixtures ), 'Gleiche Eingabe, gleicher Bericht.' );
	}

	public function test_fixture_with_unknown_team_is_rejected(): void {
		$input = $this->input();
		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'Begegnung mit unbekanntem Team: Leimbach 1' );
		( new ScheduleChecker() )->check(
			$input,
			array( new PlannedFixture( 'A', 1, 1, 'Seefeld 1', 'Leimbach 1', new \DateTimeImmutable( '2026-09-08 19:30' ), 'Halle Seefeld' ) )
		);
	}
}
