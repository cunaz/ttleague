<?php
/**
 * Integrationstests: Spielwochen, Entwurf des Spielplans, Prüfung, Verlegen, Heimrecht.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Tests\Integration\Season;

use TTLeague\Season\SeasonException as Refusal;

/**
 * Daten siehe SeasonTestCase. Der Entwurf der Gruppe A (Oerlikon 1, Seefeld 1, Wollishofen 1,
 * in dieser Reihenfolge) mit den Spielwochen aus with_weeks():
 *
 * | Nr. | Begegnung                  | Termin                  |
 * |-----|----------------------------|-------------------------|
 * | 1   | Wollishofen 1 – Seefeld 1  | Mi, 08.09.2027 19:30    |
 * | 2   | Oerlikon 1 – Wollishofen 1 | Do, 16.09.2027 20:00    |
 * | 3   | Seefeld 1 – Oerlikon 1     | Di, 21.09.2027 20:00    |
 * | 4   | Seefeld 1 – Wollishofen 1  | Di, 11.01.2028 20:00    |
 * | 5   | Wollishofen 1 – Oerlikon 1 | Mi, 19.01.2028 19:30    |
 * | 6   | Oerlikon 1 – Seefeld 1     | Do, 27.01.2028 20:00    |
 *
 * Die Gruppe B spielt am 8. September und am 19. Januar ebenfalls in Wollishofen, am
 * 21. September und am 11. Januar ebenfalls in Seefeld: mit zwei Tischen pro Lokal vier harte
 * Kollisionen.
 */
final class SeasonSchedulingTest extends SeasonTestCase {

	private const GROUP_A = array(
		'Wollishofen 1 – Seefeld 1 @ 2027-09-08 19:30:00',
		'Oerlikon 1 – Wollishofen 1 @ 2027-09-16 20:00:00',
		'Seefeld 1 – Oerlikon 1 @ 2027-09-21 20:00:00',
		'Seefeld 1 – Wollishofen 1 @ 2028-01-11 20:00:00',
		'Wollishofen 1 – Oerlikon 1 @ 2028-01-19 19:30:00',
		'Oerlikon 1 – Seefeld 1 @ 2028-01-27 20:00:00',
	);

	/**
	 * Begegnung der geplanten Saison nach Heim und Gast.
	 *
	 * @return array<string,string|null>
	 */
	private function fixture( string $home, string $away ): array {
		return (array) $this->db->get_row(
			"SELECT * FROM {$this->db->table( 'fixture' )} WHERE home_team_id = %d AND away_team_id = %d",
			array( $this->id[ 'next_' . $home ], $this->id[ 'next_' . $away ] )
		);
	}

	/**
	 * @return list<array<string,mixed>>
	 */
	private function hard( int $season_id ): array {
		return array_values( array_filter( $this->scheduling->overview( $season_id )['conflicts'], static fn( array $conflict ): bool => $conflict['hard'] ) );
	}

	public function test_the_proposal_offers_every_week_and_splits_at_the_turn_of_the_year(): void {
		$this->league();
		$next = $this->grouped();

		$weeks = $this->scheduling->weeks( $next );

		self::assertFalse( $weeks['saved'] );
		self::assertCount( 53, $weeks['weeks'] );
		self::assertSame(
			array(
				'monday'  => '2027-08-02',
				'leg'     => 1,
				'blocked' => false,
			),
			$weeks['weeks'][0]
		);
		$by_monday = array_column( $weeks['weeks'], 'leg', 'monday' );
		self::assertSame( 1, $by_monday['2027-12-27'] );
		self::assertSame( 2, $by_monday['2028-01-03'] );
		self::assertSame( array(), $weeks['first_leg_weeks'], 'Gespeichert ist noch nichts.' );
	}

	public function test_weeks_of_the_ranking_tournament_of_the_planned_season_are_not_proposed(): void {
		$this->league();
		$next = $this->grouped();
		$rl   = $this->seed->competition( $next, 'RL', 'Ranglistenturnier' );
		$old  = $this->seed->competition( $this->id['active'], 'RL', 'Ranglistenturnier' );
		foreach ( array( array( $rl, '2027-10-11', '2027-10-15' ), array( $old, '2027-11-08', '2027-11-12' ) ) as [ $competition, $from, $to ] ) {
			$this->db->insert(
				'rl_round',
				array(
					'competition_id' => $competition,
					'round_no'       => 1,
					'date_from'      => $from,
					'date_to'        => $to,
					'status'         => 'planned',
				)
			);
		}

		$weeks = array_column( $this->scheduling->weeks( $next )['weeks'], null, 'monday' );

		self::assertSame( 0, $weeks['2027-10-11']['leg'] );
		self::assertTrue( $weeks['2027-10-11']['blocked'] );
		self::assertSame( 1, $weeks['2027-10-18']['leg'] );
		self::assertSame( 1, $weeks['2027-11-08']['leg'], 'Die Runden der aktiven Saison zählen nicht.' );
		self::assertFalse( $weeks['2027-11-08']['blocked'] );
	}

	public function test_the_selection_of_the_tk_is_stored_in_the_season(): void {
		$this->league();
		$next = $this->grouped();

		$this->scheduling->save_weeks( $this->tk, $next, array( '2027-09-13', '2027-09-06' ), array( '2028-01-10' ) );

		self::assertSame(
			array(
				'first_leg_weeks'  => array( '2027-09-06', '2027-09-13' ),
				'second_leg_weeks' => array( '2028-01-10' ),
			),
			$this->seasons->by_id( $next )['schedule']
		);
		$weeks = $this->scheduling->weeks( $next );
		self::assertTrue( $weeks['saved'] );
		$by_monday = array_column( $weeks['weeks'], 'leg', 'monday' );
		self::assertSame( 1, $by_monday['2027-09-06'] );
		self::assertSame( 0, $by_monday['2027-09-20'], 'Gestrichene Wochen bleiben gestrichen.' );
		self::assertSame( 2, $by_monday['2028-01-10'] );
		self::assertSame( 1, $this->rows( 'audit_log', "entity = 'season' AND action = 'weeks_saved'" ) );
	}

	public function test_invalid_weeks_are_refused_and_nothing_is_stored(): void {
		$this->league();
		$next = $this->grouped();

		$refusal = $this->refused( fn() => $this->scheduling->save_weeks( $this->tk, $next, array( '2027-09-07' ), array( '2028-01-10' ) ), Refusal::INVALID );
		self::assertSame( array( 'first_leg_weeks' ), array_column( $refusal->errors, 'field' ) );
		$this->refused( fn() => $this->scheduling->save_weeks( $this->tk, $next, array( '2028-01-10' ), array( '2027-09-06' ) ), Refusal::INVALID );
		$this->refused( fn() => $this->scheduling->save_weeks( $this->chef, $next, array( '2027-09-06' ), array( '2028-01-10' ) ), Refusal::FORBIDDEN );
		$this->refused( fn() => $this->scheduling->save_weeks( $this->tk, $this->id['active'], array( '2026-09-07' ), array( '2027-01-11' ) ), Refusal::CONFLICT );

		self::assertSame( array(), $this->seasons->by_id( $next )['schedule'] );
	}

	public function test_the_draft_writes_the_fixtures_of_every_group(): void {
		$this->league();
		$next = $this->with_weeks();

		$result = $this->scheduling->generate( $this->tk, $next );

		self::assertSame( 12, $result['fixtures'] );
		self::assertSame( 0, $result['hard'] );
		self::assertSame( self::GROUP_A, $this->plan_of( $this->id['next_group_a'] ) );
		$first = $this->fixture( 'wollishofen_1', 'seefeld_1' );
		self::assertSame( 'scheduled', $first['status'] );
		self::assertSame( '1', $first['round'] );
		self::assertSame( '1', $first['leg'] );
		self::assertSame( '1', $first['match_no'] );
		self::assertSame( (string) $this->id['venue_wollishofen'], $first['venue_id'] );
		self::assertNull( $first['home_score'] );
		$return = $this->fixture( 'seefeld_1', 'wollishofen_1' );
		self::assertSame( '4', $return['round'] );
		self::assertSame( '2', $return['leg'] );
		self::assertSame( '4', $return['match_no'] );
		self::assertSame( (string) $this->id['venue_seefeld'], $return['venue_id'] );
		self::assertSame(
			array( '1', '2', '3', '4', '5', '6' ),
			$this->db->get_col( "SELECT match_no FROM {$this->db->table( 'fixture' )} WHERE competition_id = %d ORDER BY match_no", array( $this->id['next_group_b'] ) ),
			'Die Nummern laufen je Gruppe.'
		);
		self::assertSame( 0, $this->rows( 'fixture', 'competition_id IN ( ' . $this->id['group_a'] . ', ' . $this->id['group_b'] . ' )' ), 'Die aktive Saison erhält keine Begegnungen.' );
		self::assertSame( 1, $this->rows( 'audit_log', "entity = 'season' AND action = 'schedule_generated'" ) );

		$overview = $this->scheduling->overview( $next );
		self::assertSame( array( true, true ), array_column( $overview['groups'], 'complete' ) );
		self::assertNull( $overview['error'] );
	}

	public function test_a_new_draft_replaces_the_fixtures(): void {
		$this->league();
		$next  = $this->scheduled();
		$moved = (int) $this->fixture( 'wollishofen_1', 'seefeld_1' )['id'];
		$this->scheduling->move( $this->tk, $moved, '2027-09-29', '19:30' );
		$group_b = $this->plan_of( $this->id['next_group_b'] );
		$ids_b   = $this->db->get_col( "SELECT id FROM {$this->db->table( 'fixture' )} WHERE competition_id = %d ORDER BY id", array( $this->id['next_group_b'] ) );

		$result = $this->scheduling->generate( $this->tk, $next, $this->id['next_group_a'] );

		self::assertSame( 6, $result['fixtures'] );
		self::assertSame( self::GROUP_A, $this->plan_of( $this->id['next_group_a'] ), 'Die Verlegung von Hand ist mit dem neuen Entwurf weg.' );
		self::assertSame( 0, $this->rows( 'fixture', 'id = ' . $moved ) );
		self::assertSame( $group_b, $this->plan_of( $this->id['next_group_b'] ) );
		self::assertSame( $ids_b, $this->db->get_col( "SELECT id FROM {$this->db->table( 'fixture' )} WHERE competition_id = %d ORDER BY id", array( $this->id['next_group_b'] ) ), 'Die andere Gruppe bleibt unberührt.' );

		$this->scheduling->generate( $this->tk, $next );
		self::assertSame( 12, $this->rows( 'fixture' ), 'Ein Entwurf für alle Gruppen ersetzt alle Begegnungen.' );
	}

	public function test_after_the_first_result_the_draft_is_not_replaced_anymore(): void {
		$this->league();
		$next    = $this->scheduled();
		$fixture = (int) $this->fixture( 'wollishofen_1', 'seefeld_1' )['id'];
		$this->db->update(
			'fixture',
			array(
				'status'     => 'provisional',
				'home_score' => 6,
				'away_score' => 4,
			),
			array( 'id' => $fixture )
		);

		$this->refused( fn() => $this->scheduling->generate( $this->tk, $next ), Refusal::CONFLICT );
		$this->refused( fn() => $this->scheduling->generate( $this->tk, $next, $this->id['next_group_a'] ), Refusal::CONFLICT );
		$this->refused( fn() => $this->scheduling->clear( $this->tk, $next ), Refusal::CONFLICT );

		self::assertSame( 12, $this->rows( 'fixture' ) );
		self::assertSame( 'provisional', $this->row( 'fixture', $fixture )['status'] );

		$this->scheduling->generate( $this->tk, $next, $this->id['next_group_b'] );
		self::assertSame( 12, $this->rows( 'fixture' ), 'Die Gruppe ohne Resultat lässt sich weiter neu erzeugen.' );
	}

	public function test_a_fixture_with_an_entered_lineup_counts_as_a_result(): void {
		$this->league();
		$next    = $this->scheduled();
		$fixture = (int) $this->fixture( 'wollishofen_1', 'seefeld_1' )['id'];
		$this->db->insert(
			'lineup',
			array(
				'fixture_id' => $fixture,
				'side'       => 'home',
				'slot'       => 'A',
				'player_id'  => $this->seed->player( 'Muster', 'Hans' ),
			)
		);
		$this->refused( fn() => $this->scheduling->generate( $this->tk, $next ), Refusal::CONFLICT );
		self::assertSame( 1, $this->rows( 'fixture', 'id = ' . $fixture ) );
	}

	public function test_missing_data_is_listed_before_anything_is_generated(): void {
		$this->league();
		$next = $this->grouped();
		$this->db->update( 'team', array( 'home_venue_id' => null ), array( 'id' => $this->id['next_seefeld_1'] ) );
		$this->db->update( 'venue', array( 'weekday' => null ), array( 'id' => $this->id['venue_oerlikon'] ) );
		$this->db->update( 'team', array( 'default_start_time' => null ), array( 'id' => $this->id['next_altstetten_1'] ) );

		$missing = $this->scheduling->missing( $next );

		self::assertSame(
			array(
				'first_leg_weeks',
				'second_leg_weeks',
				'team.' . $this->id['next_altstetten_1'] . '.start',
				'team.' . $this->id['next_oerlikon_1'] . '.weekday',
				'team.' . $this->id['next_seefeld_1'] . '.venue',
			),
			array_column( $missing, 'field' )
		);
		$text = implode( ' ', array_column( $missing, 'message' ) );
		self::assertStringContainsString( 'Seefeld 1: Das Heimlokal fehlt.', $text );
		self::assertStringContainsString( 'Oerlikon 1: Dem Lokal «Halle Oerlikon» fehlt der Spieltag.', $text );
		self::assertStringContainsString( 'Altstetten 1: Die Anspielzeit fehlt.', $text );

		$refusal = $this->refused( fn() => $this->scheduling->generate( $this->tk, $next ), Refusal::INVALID );
		self::assertCount( 5, $refusal->errors );
		self::assertSame( 0, $this->rows( 'fixture' ) );
	}

	public function test_a_group_with_one_team_and_a_season_without_groups_cannot_be_planned(): void {
		$this->league();
		$next = $this->plan();
		self::assertContains( 'groups', array_column( $this->scheduling->missing( $next ), 'field' ) );

		$group = $this->grouping->create_group( $this->tk, $next, 'A', 1 );
		$this->grouping->assign( $this->tk, $this->id['next_seefeld_1'], $group );
		self::assertContains( 'group.' . $group, array_column( $this->scheduling->missing( $next ), 'field' ) );

		$this->grouping->assign( $this->tk, $this->id['next_oerlikon_1'], $group );
		self::assertNotContains( 'group.' . $group, array_column( $this->scheduling->missing( $next ), 'field' ), 'Zwei Mannschaften genügen.' );
	}

	public function test_too_few_weeks_show_the_message_of_the_generator(): void {
		$this->league();
		$next = $this->grouped();
		$this->scheduling->save_weeks( $this->tk, $next, array( '2027-09-06', '2027-09-13', '2027-09-20' ), array( '2028-01-10', '2028-01-17' ) );

		$refusal = $this->refused( fn() => $this->scheduling->generate( $this->tk, $next ), Refusal::CONFLICT );

		self::assertSame( 'Zu wenige Spielwochen für die Anzahl Runden: Gruppe A braucht in der Rückrunde 3, vorhanden sind 2; Gruppe B braucht in der Rückrunde 3, vorhanden sind 2.', $refusal->getMessage() );
		self::assertSame( 0, $this->rows( 'fixture' ) );

		$this->scheduling->save_weeks( $this->tk, $next, array( '2027-09-06', '2027-09-13', '2027-09-20' ), array( '2028-01-10', '2028-01-17', '2028-01-24' ) );
		self::assertSame( 12, $this->scheduling->generate( $this->tk, $next )['fixtures'], 'Genau so viele Wochen wie Runden genügen.' );
	}

	public function test_only_the_tk_generates_and_only_in_the_planned_season(): void {
		$this->league();
		$next = $this->with_weeks();
		$this->refused( fn() => $this->scheduling->generate( $this->chef, $next ), Refusal::FORBIDDEN );
		$this->refused( fn() => $this->scheduling->generate( $this->tk, $this->id['active'] ), Refusal::CONFLICT );
		$this->refused( fn() => $this->scheduling->generate( $this->tk, $next, $this->id['group_a'] ), Refusal::NOT_FOUND );
		$this->refused( fn() => $this->scheduling->clear( $this->chef, $next ), Refusal::FORBIDDEN );
		self::assertSame( 0, $this->rows( 'fixture' ) );
	}

	public function test_the_report_lists_collisions_with_a_suggestion(): void {
		$this->league( 2 );
		$next = $this->scheduled();

		$overview = $this->scheduling->overview( $next );

		self::assertSame( 4, $overview['hard'] );
		$hard = $this->hard( $next );
		self::assertSame( array( 'venue_capacity', 'venue_capacity', 'venue_capacity', 'venue_capacity' ), array_column( $hard, 'kind' ) );
		self::assertSame( array( '2027-09-08', '2027-09-21', '2028-01-11', '2028-01-19' ), array_column( $hard, 'day' ) );
		self::assertSame( 'Halle Wollishofen', $hard[0]['venue'] );
		$a = (int) $this->fixture( 'wollishofen_1', 'seefeld_1' )['id'];
		$b = (int) $this->fixture( 'wollishofen_2', 'seefeld_2' )['id'];
		self::assertSame( array( $a, $b ), $hard[0]['fixtures'] );
		self::assertSame(
			array(
				array(
					'fixture_id' => $a,
					'starts_at'  => '2027-09-29 19:30:00',
				),
				array(
					'fixture_id' => $b,
					'starts_at'  => '2027-09-29 19:30:00',
				),
			),
			$hard[0]['suggestions'],
			'Die Reservewoche vom 27. September, am Heimspieltag des Heimteams.'
		);
		self::assertGreaterThan( 0, $overview['hints'] );
		$hint = array_values( array_filter( $overview['conflicts'], static fn( array $conflict ): bool => 'club_same_evening' === $conflict['kind'] ) )[0];
		self::assertFalse( $hint['hard'] );
		self::assertNotNull( $hint['club'] );
	}

	public function test_a_closed_venue_is_a_hard_collision(): void {
		$this->league();
		$next = $this->with_weeks();
		$this->db->insert(
			'venue_closure',
			array(
				'venue_id'  => $this->id['venue_oerlikon'],
				'date_from' => '2027-09-16',
				'date_to'   => '2027-09-16',
				'reason'    => 'Hallenreinigung',
			)
		);

		$result = $this->scheduling->generate( $this->tk, $next );

		self::assertSame( 1, $result['hard'] );
		$hard = $this->hard( $next );
		self::assertSame( 'venue_closed', $hard[0]['kind'] );
		self::assertSame( 'Halle Oerlikon', $hard[0]['venue'] );
		self::assertStringContainsString( 'Hallenreinigung', $hard[0]['message'] );
		self::assertSame( array( (int) $this->fixture( 'oerlikon_1', 'wollishofen_1' )['id'] ), $hard[0]['fixtures'] );
		self::assertSame( '2027-09-30 20:00:00', $hard[0]['suggestions'][0]['starts_at'] );
	}

	public function test_venues_without_table_count_are_a_hint_not_a_collision(): void {
		$this->league( null );
		$next = $this->scheduled();

		$overview = $this->scheduling->overview( $next );

		self::assertSame( 0, $overview['hard'] );
		self::assertContains( 'tables_unknown', array_column( $overview['conflicts'], 'kind' ) );
	}

	public function test_the_tables_per_match_come_from_the_rules_of_the_season(): void {
		$this->league( 4 );
		$next = $this->scheduled();
		self::assertSame( 0, $this->scheduling->overview( $next )['hard'], 'Zwei Begegnungen mit je zwei Tischen passen an vier Tische.' );

		$rules = array( 'schedule_tables_per_match' => 3 );
		$this->db->update( 'season', array( 'rules_json' => (string) json_encode( $rules ) ), array( 'id' => $next ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Test ohne WordPress.

		self::assertSame( 4, $this->scheduling->overview( $next )['hard'], 'Mit drei Tischen pro Begegnung reichen vier Tische nicht mehr.' );
	}

	public function test_accepting_a_suggestion_moves_the_fixture_and_checks_again(): void {
		$this->league( 2 );
		$next    = $this->scheduled();
		$fixture = (int) $this->fixture( 'wollishofen_1', 'seefeld_1' )['id'];

		$this->scheduling->apply_suggestion( $this->tk, $fixture, '2027-09-29 19:30:00' );

		$row = $this->row( 'fixture', $fixture );
		self::assertSame( '2027-09-29 19:30:00', $row['scheduled_at'] );
		self::assertSame( (string) $this->id['venue_wollishofen'], $row['venue_id'] );
		self::assertSame( array( '2027-09-21', '2028-01-11', '2028-01-19' ), array_column( $this->hard( $next ), 'day' ), 'Die Kollision vom 8. September ist behoben.' );
		self::assertSame( 1, $this->rows( 'audit_log', "entity = 'fixture' AND action = 'suggestion_applied' AND entity_id = {$fixture}" ) );
	}

	public function test_a_suggestion_that_is_no_longer_in_the_report_is_refused(): void {
		$this->league( 2 );
		$next  = $this->scheduled();
		$first = (int) $this->fixture( 'wollishofen_1', 'seefeld_1' )['id'];
		$other = (int) $this->fixture( 'wollishofen_2', 'seefeld_2' )['id'];
		$this->scheduling->apply_suggestion( $this->tk, $first, '2027-09-29 19:30:00' );

		// Im zweiten Fenster stand derselbe Vorschlag noch für die andere Begegnung.
		$this->refused( fn() => $this->scheduling->apply_suggestion( $this->tk, $other, '2027-09-29 19:30:00' ), Refusal::CONFLICT );
		$this->refused( fn() => $this->scheduling->apply_suggestion( $this->tk, $first, '2027-12-24 19:30:00' ), Refusal::CONFLICT );
		$this->refused( fn() => $this->scheduling->apply_suggestion( $this->chef, $other, '2027-09-29 19:30:00' ), Refusal::FORBIDDEN );

		self::assertSame( '2027-09-08 19:30:00', $this->row( 'fixture', $other )['scheduled_at'] );
		self::assertSame( 12, $this->rows( 'fixture' ) );
		unset( $next );
	}

	public function test_moving_by_hand_is_checked_again(): void {
		$this->league();
		$next    = $this->scheduled();
		$fixture = (int) $this->fixture( 'oerlikon_1', 'wollishofen_1' )['id'];
		self::assertSame( 0, $this->scheduling->overview( $next )['hard'] );

		// Wollishofen 1 spielt am 8. September schon gegen Seefeld 1.
		$this->scheduling->move( $this->tk, $fixture, '2027-09-08', '18:45', $this->id['venue_altstetten'] );

		$row = $this->row( 'fixture', $fixture );
		self::assertSame( '2027-09-08 18:45:00', $row['scheduled_at'] );
		self::assertSame( (string) $this->id['venue_altstetten'], $row['venue_id'] );
		$hard = $this->hard( $next );
		self::assertSame( array( 'team_double_booked' ), array_column( $hard, 'kind' ) );
		self::assertSame( 'Wollishofen 1', $hard[0]['team'] );
		self::assertContains( $fixture, $hard[0]['fixtures'] );

		$this->scheduling->move( $this->tk, $fixture, '2027-09-16', '20:00', $this->id['venue_oerlikon'] );
		self::assertSame( array(), $this->hard( $next ), 'Zurück am alten Termin ist der Plan wieder spielbar.' );
		self::assertSame( 2, $this->rows( 'audit_log', "entity = 'fixture' AND action = 'moved'" ) );
	}

	public function test_a_move_needs_a_date_in_the_season_a_time_and_a_venue(): void {
		$this->league();
		$next    = $this->scheduled();
		$fixture = (int) $this->fixture( 'oerlikon_1', 'wollishofen_1' )['id'];
		$closed  = $this->seed->venue( $this->id['club_oerlikon'], 'Alte Halle', array( 'active' => 0 ) );

		$this->scheduling->move( $this->tk, $fixture, '2027-08-01', '00:00' );
		$this->scheduling->move( $this->tk, $fixture, '2028-07-31', '23:59' );
		self::assertSame( '2028-07-31 23:59:00', $this->row( 'fixture', $fixture )['scheduled_at'] );
		self::assertSame( (string) $this->id['venue_oerlikon'], $this->row( 'fixture', $fixture )['venue_id'], 'Ohne Lokal bleibt das bisherige.' );

		foreach ( array(
			'date'   => array( '2027-07-31', '20:00', null ),
			'date '  => array( '2028-08-01', '20:00', null ),
			'date  ' => array( '16.09.2027', '20:00', null ),
			'time'   => array( '2027-09-16', '24:00', null ),
			'time '  => array( '2027-09-16', '8 Uhr', null ),
			'venue'  => array( '2027-09-16', '20:00', 99999 ),
			'venue ' => array( '2027-09-16', '20:00', $closed ),
		) as $field => [ $date, $time, $venue ] ) {
			$refusal = $this->refused( fn() => $this->scheduling->move( $this->tk, $fixture, $date, $time, $venue ), Refusal::INVALID );
			self::assertSame( array( trim( $field ) ), array_column( $refusal->errors, 'field' ) );
		}
		self::assertSame( '2028-07-31 23:59:00', $this->row( 'fixture', $fixture )['scheduled_at'] );
		$this->refused( fn() => $this->scheduling->move( $this->chef, $fixture, '2027-09-16', '20:00' ), Refusal::FORBIDDEN );
		$this->refused( fn() => $this->scheduling->move( $this->tk, 99999, '2027-09-16', '20:00' ), Refusal::NOT_FOUND );
		unset( $next );
	}

	public function test_swapping_the_home_right_moves_the_fixture_to_the_new_home_team(): void {
		$this->league();
		$next    = $this->scheduled();
		$fixture = (int) $this->fixture( 'wollishofen_1', 'seefeld_1' )['id'];
		$return  = (int) $this->fixture( 'seefeld_1', 'wollishofen_1' )['id'];

		$this->scheduling->swap_home( $this->tk, $fixture );

		$row = $this->row( 'fixture', $fixture );
		self::assertSame( (string) $this->id['next_seefeld_1'], $row['home_team_id'] );
		self::assertSame( (string) $this->id['next_wollishofen_1'], $row['away_team_id'] );
		self::assertSame( (string) $this->id['venue_seefeld'], $row['venue_id'] );
		self::assertSame( '2027-09-07 20:00:00', $row['scheduled_at'], 'Gleiche Woche, Dienstag und Anspielzeit von Seefeld.' );
		self::assertSame( '1', $row['leg'] );
		self::assertSame( (string) $this->id['next_seefeld_1'], $this->row( 'fixture', $return )['home_team_id'], 'Das Rückspiel bleibt ohne ausdrücklichen Wunsch.' );
		self::assertFalse( array_column( $this->scheduling->overview( $next )['groups'], 'complete', 'id' )[ $this->id['next_group_a'] ], 'Seefeld hat jetzt zweimal Heimrecht: Die Paarung fehlt.' );

		$this->scheduling->swap_home( $this->tk, $return );
		self::assertTrue( array_column( $this->scheduling->overview( $next )['groups'], 'complete', 'id' )[ $this->id['next_group_a'] ] );
		self::assertSame( '2028-01-12 19:30:00', $this->row( 'fixture', $return )['scheduled_at'] );
	}

	public function test_both_legs_can_be_swapped_together(): void {
		$this->league();
		$next    = $this->scheduled();
		$fixture = (int) $this->fixture( 'wollishofen_1', 'seefeld_1' )['id'];
		$return  = (int) $this->fixture( 'seefeld_1', 'wollishofen_1' )['id'];

		$this->scheduling->swap_home( $this->tk, $fixture, true );

		self::assertSame( (string) $this->id['next_seefeld_1'], $this->row( 'fixture', $fixture )['home_team_id'] );
		self::assertSame( (string) $this->id['next_wollishofen_1'], $this->row( 'fixture', $return )['home_team_id'] );
		self::assertSame( (string) $this->id['venue_wollishofen'], $this->row( 'fixture', $return )['venue_id'] );
		self::assertSame( array( true, true ), array_column( $this->scheduling->overview( $next )['groups'], 'complete' ) );
		self::assertSame( 2, $this->rows( 'audit_log', "entity = 'fixture' AND action = 'home_swapped'" ) );
		$this->refused( fn() => $this->scheduling->swap_home( $this->chef, $fixture ), Refusal::FORBIDDEN );
	}

	public function test_a_plan_that_no_longer_fits_the_teams_is_incomplete(): void {
		$this->league();
		$next = $this->scheduled();
		$this->seed->team( $next, $this->id['club_altstetten'], 2, 'Altstetten 2', $this->id['next_group_b'], $this->id['venue_altstetten'], '19:45:00' );

		$groups = array_column( $this->scheduling->overview( $next )['groups'], null, 'id' );

		self::assertTrue( $groups[ $this->id['next_group_a'] ]['complete'] );
		self::assertFalse( $groups[ $this->id['next_group_b'] ]['complete'] );
		self::assertSame( 12, $groups[ $this->id['next_group_b'] ]['expected'] );
		self::assertSame( 6, $groups[ $this->id['next_group_b'] ]['fixtures'] );
	}

	public function test_a_fixture_the_planner_cannot_take_does_not_break_the_report(): void {
		$this->league();
		$next = $this->scheduled();
		$this->seed->fixture(
			$this->id['next_group_a'],
			array(
				'home_team_id' => $this->id['next_seefeld_1'],
				'away_team_id' => $this->id['next_seefeld_1'],
				'scheduled_at' => '2027-10-05 20:00:00',
				'venue_id'     => $this->id['venue_seefeld'],
				'status'       => 'scheduled',
			)
		);
		$this->db->update( 'fixture', array( 'venue_id' => null ), array( 'home_team_id' => $this->id['next_altstetten_1'] ) );

		$overview = $this->scheduling->overview( $next );

		self::assertSame( 3, $overview['unscheduled'], 'Heim gleich Gast und zwei Begegnungen ohne Lokal.' );
		self::assertNull( $overview['error'] );
		self::assertFalse( array_column( $overview['groups'], 'complete', 'id' )[ $this->id['next_group_a'] ], 'Eine überzählige Begegnung passt nicht zur Gruppe.' );
		self::assertTrue( array_column( $overview['groups'], 'complete', 'id' )[ $this->id['next_group_b'] ] );
	}

	public function test_the_draft_can_be_cleared(): void {
		$this->league();
		$next = $this->scheduled();

		self::assertSame( 6, $this->scheduling->clear( $this->tk, $next, $this->id['next_group_a'] ) );
		self::assertSame( 6, $this->rows( 'fixture' ) );
		self::assertSame( 6, $this->scheduling->clear( $this->tk, $next ) );
		self::assertSame( 0, $this->rows( 'fixture' ) );
		self::assertSame( array( false, false ), array_column( $this->scheduling->overview( $next )['groups'], 'complete' ) );
	}

	public function test_a_published_plan_is_no_draft_anymore(): void {
		$this->league();
		$next    = $this->scheduled();
		$fixture = (int) $this->fixture( 'oerlikon_1', 'wollishofen_1' )['id'];
		$this->db->update( 'season', array( 'status' => 'archived' ), array( 'id' => $this->id['active'] ) );
		$this->db->update( 'season', array( 'status' => 'active' ), array( 'id' => $next ) );

		$this->refused( fn() => $this->scheduling->move( $this->tk, $fixture, '2027-09-30', '20:00' ), Refusal::CONFLICT );
		$this->refused( fn() => $this->scheduling->swap_home( $this->tk, $fixture ), Refusal::CONFLICT );
		$this->refused( fn() => $this->scheduling->apply_suggestion( $this->tk, $fixture, '2027-09-30 20:00:00' ), Refusal::CONFLICT );
		$this->refused( fn() => $this->scheduling->generate( $this->tk, $next ), Refusal::CONFLICT );
		$this->refused( fn() => $this->scheduling->clear( $this->tk, $next ), Refusal::CONFLICT );

		self::assertSame( '2027-09-16 20:00:00', $this->row( 'fixture', $fixture )['scheduled_at'] );
		self::assertSame( 12, $this->rows( 'fixture' ) );
	}

	public function test_a_deciding_match_is_scheduled_in_the_active_season(): void {
		$this->league();

		$fixture = $this->scheduling->add_fixture( $this->tk, $this->id['group_a'], $this->id['seefeld_1'], $this->id['oerlikon_1'], '2027-05-11', '20:00' );

		$row = $this->row( 'fixture', $fixture );
		self::assertSame( 'scheduled', $row['status'] );
		self::assertSame( '2027-05-11 20:00:00', $row['scheduled_at'] );
		self::assertSame( (string) $this->id['venue_seefeld'], $row['venue_id'], 'Ohne Angabe das Heimlokal der Heimmannschaft.' );
		self::assertSame( '1', $row['match_no'] );
		self::assertNull( $row['leg'] );

		$second = $this->scheduling->add_fixture( $this->tk, $this->id['group_a'], $this->id['oerlikon_1'], $this->id['seefeld_1'], '2027-05-20', '19:00', $this->id['venue_altstetten'] );
		self::assertSame( '2', $this->row( 'fixture', $second )['match_no'] );
		self::assertSame( (string) $this->id['venue_altstetten'], $this->row( 'fixture', $second )['venue_id'] );

		$refusal = $this->refused( fn() => $this->scheduling->add_fixture( $this->tk, $this->id['group_a'], $this->id['seefeld_1'], $this->id['seefeld_2'], '2027-08-01', '20', null ), Refusal::INVALID );
		self::assertSame( array( 'away', 'date', 'time' ), array_column( $refusal->errors, 'field' ) );
		$this->refused( fn() => $this->scheduling->add_fixture( $this->tk, $this->id['group_a'], $this->id['seefeld_1'], $this->id['seefeld_1'], '2027-05-11', '20:00' ), Refusal::INVALID );
		$this->refused( fn() => $this->scheduling->add_fixture( $this->chef, $this->id['group_a'], $this->id['seefeld_1'], $this->id['oerlikon_1'], '2027-05-11', '20:00' ), Refusal::FORBIDDEN );
		self::assertSame( 2, $this->rows( 'fixture' ) );
	}

	public function test_a_withdrawn_team_gets_no_new_fixture(): void {
		$this->league();
		$this->db->update( 'team', array( 'status' => 'withdrawn' ), array( 'id' => $this->id['oerlikon_1'] ) );

		$as_away = $this->refused( fn() => $this->scheduling->add_fixture( $this->tk, $this->id['group_a'], $this->id['seefeld_1'], $this->id['oerlikon_1'], '2027-05-11', '20:00' ), Refusal::INVALID );
		self::assertSame( array( 'away' ), array_column( $as_away->errors, 'field' ) );
		self::assertSame( 'Die Mannschaft ist zurückgezogen.', $as_away->errors[0]['message'] );
		$as_home = $this->refused( fn() => $this->scheduling->add_fixture( $this->tk, $this->id['group_a'], $this->id['oerlikon_1'], $this->id['seefeld_1'], '2027-05-11', '20:00' ), Refusal::INVALID );
		self::assertSame( array( 'home' ), array_column( $as_home->errors, 'field' ) );
		self::assertSame( 0, $this->rows( 'fixture' ) );
	}

	public function test_single_fixtures_are_not_added_to_the_draft_or_to_an_archived_season(): void {
		$this->league();
		$next = $this->scheduled();
		$old  = $this->seed->season( '2025-26', 'archived' );
		$past = $this->seed->competition( $old, 'MM', 'Gruppe A', 'A', 1 );
		$this->refused( fn() => $this->scheduling->add_fixture( $this->tk, $this->id['next_group_a'], $this->id['next_seefeld_1'], $this->id['next_oerlikon_1'], '2027-10-05', '20:00' ), Refusal::CONFLICT );
		$this->refused( fn() => $this->scheduling->add_fixture( $this->tk, $past, 1, 2, '2026-05-05', '20:00' ), Refusal::CONFLICT );
		self::assertSame( 12, $this->rows( 'fixture' ) );
		unset( $next );
	}

	public function test_a_fixture_without_result_is_cancelled_with_a_reason(): void {
		$this->league();
		$fixture = $this->scheduling->add_fixture( $this->tk, $this->id['group_a'], $this->id['seefeld_1'], $this->id['oerlikon_1'], '2027-05-11', '20:00' );
		$played  = $this->seed->fixture(
			$this->id['group_a'],
			array(
				'home_team_id' => $this->id['oerlikon_1'],
				'away_team_id' => $this->id['seefeld_1'],
				'status'       => 'final',
				'home_score'   => 6,
				'away_score'   => 4,
			)
		);

		$this->refused( fn() => $this->scheduling->cancel_fixture( $this->tk, $fixture, '  ' ), Refusal::INVALID );
		$this->refused( fn() => $this->scheduling->cancel_fixture( $this->chef, $fixture, 'Rang steht fest' ), Refusal::FORBIDDEN );
		$this->refused( fn() => $this->scheduling->cancel_fixture( $this->tk, $played, 'Irrtum' ), Refusal::CONFLICT );
		self::assertSame( 'scheduled', $this->row( 'fixture', $fixture )['status'] );

		$this->scheduling->cancel_fixture( $this->tk, $fixture, 'Rang steht fest' );

		self::assertSame( 'cancelled', $this->row( 'fixture', $fixture )['status'] );
		self::assertSame( 'final', $this->row( 'fixture', $played )['status'] );
		$entry = (array) $this->db->get_row( "SELECT note FROM {$this->db->table( 'audit_log' )} WHERE entity = 'fixture' AND action = 'cancelled'" );
		self::assertSame( 'Rang steht fest', $entry['note'] );
		$this->refused( fn() => $this->scheduling->cancel_fixture( $this->tk, $fixture, 'nochmals' ), Refusal::CONFLICT );
	}

	public function test_every_change_reports_the_season(): void {
		$this->league();
		$next                  = $this->with_weeks();
		$this->events->changed = array();

		$this->scheduling->generate( $this->tk, $next );
		$this->scheduling->move( $this->tk, (int) $this->fixture( 'oerlikon_1', 'wollishofen_1' )['id'], '2027-09-30', '20:00' );
		$this->scheduling->clear( $this->tk, $next );

		self::assertSame( array( $next, $next, $next ), $this->events->changed );
	}
}
