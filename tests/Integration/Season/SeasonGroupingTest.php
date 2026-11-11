<?php
/**
 * Integrationstests: Kategorien und Gruppen der geplanten Saison.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Tests\Integration\Season;

use TTLeague\Domain\Season\GroupingRules;
use TTLeague\Season\SeasonException as Refusal;

/**
 * Daten siehe SeasonTestCase.
 */
final class SeasonGroupingTest extends SeasonTestCase {

	/**
	 * @return array<string,mixed>
	 */
	private function team_state( int $season_id, int $team_id ): array {
		foreach ( $this->grouping->state( $season_id )['teams'] as $team ) {
			if ( $team['id'] === $team_id ) {
				return $team;
			}
		}
		self::fail( 'Mannschaft fehlt in der Einteilung.' );
	}

	private function group_of( int $team_id ): ?int {
		$id = $this->db->get_var( "SELECT competition_id FROM {$this->db->table( 'team' )} WHERE id = %d", array( $team_id ) );
		return null === $id ? null : (int) $id;
	}

	/**
	 * Gespielte Begegnung der aktiven Saison.
	 */
	private function played( string $group, string $home, string $away, int $home_score, int $away_score ): void {
		$this->seed->fixture(
			$this->id[ $group ],
			array(
				'home_team_id' => $this->id[ $home ],
				'away_team_id' => $this->id[ $away ],
				'scheduled_at' => '2026-10-06 20:00:00',
				'status'       => 'final',
				'home_score'   => $home_score,
				'away_score'   => $away_score,
			)
		);
	}

	public function test_a_group_is_created_with_category_number_and_default_name(): void {
		$this->league();
		$next = $this->plan();

		$b2 = $this->grouping->create_group( $this->tk, $next, 'b', 2 );
		$a  = $this->grouping->create_group( $this->tk, $next, 'A', 1, 'Gruppe A (Elite)' );

		$groups = $this->grouping->groups( $next );
		self::assertSame( array( $a, $b2 ), array_column( $groups, 'id' ), 'Sortiert nach Kategorie und Gruppennummer.' );
		self::assertSame( array( 'Gruppe A (Elite)', 'Gruppe B2' ), array_column( $groups, 'name' ) );
		$row = $this->row( 'competition', $b2 );
		self::assertSame( 'MM', $row['type'] );
		self::assertSame( 'B', $row['category'] );
		self::assertSame( '2', $row['group_no'] );
		self::assertSame( '2', $row['sort_order'] );
		self::assertSame( (string) $next, $row['season_id'] );
		self::assertSame( 2, $this->rows( 'audit_log', "entity = 'competition' AND action = 'created'" ) );
	}

	public function test_a_group_exists_only_once_per_category_and_number(): void {
		$this->league();
		$next = $this->plan();
		$this->grouping->create_group( $this->tk, $next, 'A', 1 );

		$refusal = $this->refused( fn() => $this->grouping->create_group( $this->tk, $next, 'A', 1, 'Zweite A' ), Refusal::INVALID );

		self::assertSame( array( 'group_no' ), array_column( $refusal->errors, 'field' ) );
		self::assertCount( 1, $this->grouping->groups( $next ) );
		$this->grouping->create_group( $this->tk, $next, 'A', 2 );
		self::assertCount( 2, $this->grouping->groups( $next ) );
	}

	public function test_groups_are_only_managed_by_the_tk_in_the_planned_season(): void {
		$this->league();
		$next  = $this->plan();
		$group = $this->grouping->create_group( $this->tk, $next, 'A', 1 );

		$this->refused( fn() => $this->grouping->create_group( $this->chef, $next, 'B', 1 ), Refusal::FORBIDDEN );
		$this->refused( fn() => $this->grouping->create_group( $this->tk, $this->id['active'], 'C', 1 ), Refusal::CONFLICT );
		$this->refused( fn() => $this->grouping->create_group( $this->tk, $next, '1', 1 ), Refusal::INVALID );
		$this->refused( fn() => $this->grouping->rename_group( $this->chef, $group, 'Neu' ), Refusal::FORBIDDEN );
		$this->refused( fn() => $this->grouping->rename_group( $this->tk, $this->id['group_a'], 'Neu' ), Refusal::CONFLICT );
		$this->refused( fn() => $this->grouping->delete_group( $this->chef, $group ), Refusal::FORBIDDEN );
		$this->refused( fn() => $this->grouping->delete_group( $this->tk, $this->id['group_a'] ), Refusal::CONFLICT );
		$this->refused( fn() => $this->grouping->delete_group( $this->tk, 9999 ), Refusal::NOT_FOUND );
		$this->refused( fn() => $this->grouping->assign( $this->chef, $this->id['next_seefeld_1'], $group ), Refusal::FORBIDDEN );
		$this->refused( fn() => $this->grouping->assign( $this->tk, $this->id['seefeld_1'], $this->id['group_b'] ), Refusal::CONFLICT );

		self::assertSame( 'Gruppe A', $this->row( 'competition', $this->id['group_a'] )['name'] );
		self::assertSame( $this->id['group_a'], $this->group_of( $this->id['seefeld_1'] ) );
		self::assertNull( $this->group_of( $this->id['next_seefeld_1'] ) );
	}

	public function test_a_group_is_renamed(): void {
		$this->league();
		$next  = $this->plan();
		$group = $this->grouping->create_group( $this->tk, $next, 'A', 1 );

		$this->grouping->rename_group( $this->tk, $group, ' Gruppe A West ' );

		self::assertSame( 'Gruppe A West', $this->row( 'competition', $group )['name'] );
		$refusal = $this->refused( fn() => $this->grouping->rename_group( $this->tk, $group, '  ' ), Refusal::INVALID );
		self::assertSame( array( 'name' ), array_column( $refusal->errors, 'field' ) );
		self::assertSame( 'Gruppe A West', $this->row( 'competition', $group )['name'] );
	}

	public function test_only_an_empty_group_is_deleted(): void {
		$this->league();
		$next  = $this->plan();
		$a     = $this->grouping->create_group( $this->tk, $next, 'A', 1 );
		$b     = $this->grouping->create_group( $this->tk, $next, 'B', 1 );
		$empty = $this->grouping->create_group( $this->tk, $next, 'C', 1 );
		$this->grouping->assign( $this->tk, $this->id['next_seefeld_1'], $a );
		$this->seed->fixture( $b, array( 'status' => 'scheduled' ) );

		$this->refused( fn() => $this->grouping->delete_group( $this->tk, $a ), Refusal::CONFLICT );
		$this->refused( fn() => $this->grouping->delete_group( $this->tk, $b ), Refusal::CONFLICT );
		$this->grouping->delete_group( $this->tk, $empty );

		self::assertSame( array( $a, $b ), array_column( $this->grouping->groups( $next ), 'id' ) );
		self::assertSame( 1, $this->rows( 'audit_log', "entity = 'competition' AND action = 'deleted'" ) );
	}

	public function test_teams_are_assigned_and_taken_out_of_their_group(): void {
		$this->league();
		$next = $this->plan();
		$a    = $this->grouping->create_group( $this->tk, $next, 'A', 1 );
		$b    = $this->grouping->create_group( $this->tk, $next, 'B', 1 );

		$this->grouping->assign( $this->tk, $this->id['next_seefeld_1'], $a );
		$changed = $this->grouping->assign_many(
			$this->tk,
			$next,
			array(
				$this->id['next_seefeld_1']  => $a,
				$this->id['next_seefeld_2']  => $b,
				$this->id['next_oerlikon_1'] => null,
			)
		);

		self::assertSame( 1, $changed, 'Nur die Mannschaft, deren Gruppe sich ändert, zählt.' );
		self::assertSame( $a, $this->group_of( $this->id['next_seefeld_1'] ) );
		self::assertSame( $b, $this->group_of( $this->id['next_seefeld_2'] ) );
		$this->grouping->assign( $this->tk, $this->id['next_seefeld_1'], null );
		self::assertNull( $this->group_of( $this->id['next_seefeld_1'] ) );
		self::assertSame( 3, $this->rows( 'audit_log', "entity = 'team' AND action = 'assigned'" ) );
	}

	public function test_an_assignment_with_a_foreign_team_or_group_changes_nothing(): void {
		$this->league();
		$next = $this->plan();
		$a    = $this->grouping->create_group( $this->tk, $next, 'A', 1 );

		$this->refused(
			fn() => $this->grouping->assign_many(
				$this->tk,
				$next,
				array(
					$this->id['next_seefeld_1'] => $a,
					$this->id['next_seefeld_2'] => $this->id['group_b'],
				)
			),
			Refusal::NOT_FOUND
		);
		$this->refused(
			fn() => $this->grouping->assign_many(
				$this->tk,
				$next,
				array(
					$this->id['next_seefeld_1'] => $a,
					$this->id['seefeld_2']      => $a,
				)
			),
			Refusal::NOT_FOUND
		);

		self::assertNull( $this->group_of( $this->id['next_seefeld_1'] ), 'Alles oder nichts.' );
		self::assertSame( $this->id['group_b'], $this->group_of( $this->id['seefeld_2'] ) );
	}

	public function test_a_team_with_fixtures_keeps_its_group(): void {
		$this->league();
		$next = $this->scheduled();

		$this->refused( fn() => $this->grouping->assign( $this->tk, $this->id['next_seefeld_1'], $this->id['next_group_b'] ), Refusal::CONFLICT );
		self::assertSame( $this->id['next_group_a'], $this->group_of( $this->id['next_seefeld_1'] ) );

		$this->scheduling->clear( $this->tk, $next, $this->id['next_group_a'] );
		$this->grouping->assign( $this->tk, $this->id['next_seefeld_1'], $this->id['next_group_b'] );
		self::assertSame( $this->id['next_group_b'], $this->group_of( $this->id['next_seefeld_1'] ) );
	}

	public function test_the_proposal_keeps_every_team_of_last_year_in_its_group(): void {
		$this->league();
		$next = $this->plan();
		$new  = $this->seed->team( $next, $this->id['club_altstetten'], 2, 'Altstetten 2', null, $this->id['venue_altstetten'] );

		$state = $this->grouping->state( $next );
		self::assertSame( array(), $state['groups'] );
		$seefeld = $this->team_state( $next, $this->id['next_seefeld_2'] );
		self::assertSame( 'B', $seefeld['proposal']['category'] );
		self::assertSame( 1, $seefeld['proposal']['group_no'] );
		self::assertNull( $seefeld['proposal']['competition_id'], 'Die Gruppe gibt es in der geplanten Saison noch nicht.' );
		self::assertSame( 'Gruppe B', $seefeld['previous']['group'] );
		self::assertNull( $this->team_state( $next, $new )['proposal'] );
		self::assertNull( $this->team_state( $next, $new )['previous'] );

		$result = $this->grouping->apply_proposal( $this->tk, $next );

		self::assertSame(
			array(
				'groups' => 2,
				'teams'  => 6,
			),
			$result
		);
		$groups = array_column( $this->grouping->groups( $next ), null, 'category' );
		self::assertSame( array( 'A', 'B' ), array_keys( $groups ) );
		self::assertSame( 'Gruppe A', $groups['A']['name'] );
		foreach ( self::TEAMS as $key => [ , , $category ] ) {
			self::assertSame( $groups[ $category ]['id'], $this->group_of( $this->id[ 'next_' . $key ] ), $key );
		}
		self::assertNull( $this->group_of( $new ), 'Eine neue Mannschaft bleibt ohne Gruppe.' );
		self::assertSame( $this->id['group_a'], $this->group_of( $this->id['seefeld_1'] ), 'Die aktive Saison bleibt unberührt.' );
	}

	public function test_the_proposal_never_overrides_a_decision_of_the_tk(): void {
		$this->league();
		$next = $this->plan();
		$this->grouping->apply_proposal( $this->tk, $next );
		$groups = array_column( $this->grouping->groups( $next ), 'id', 'category' );
		// Aufstieg von Hand: Seefeld 2 spielt neu in der Kategorie A.
		$this->grouping->assign( $this->tk, $this->id['next_seefeld_2'], $groups['A'] );

		$again = $this->grouping->apply_proposal( $this->tk, $next );

		self::assertSame(
			array(
				'groups' => 0,
				'teams'  => 0,
			),
			$again
		);
		self::assertSame( $groups['A'], $this->group_of( $this->id['next_seefeld_2'] ) );
		self::assertCount( 2, $this->grouping->groups( $next ) );
	}

	public function test_the_final_standings_of_last_year_stand_beside_the_proposal(): void {
		$this->league();
		$this->played( 'group_a', 'seefeld_1', 'wollishofen_1', 3, 7 );
		$this->played( 'group_a', 'oerlikon_1', 'seefeld_1', 4, 6 );
		$this->played( 'group_a', 'wollishofen_1', 'oerlikon_1', 8, 2 );
		$next = $this->plan();

		$state = $this->grouping->state( $next );

		self::assertSame( '2026/27', $state['previous']['name'] );
		$a = $state['previous']['groups'][0];
		self::assertSame( 'Gruppe A', $a['name'] );
		self::assertSame( array( 'Wollishofen 1', 'Seefeld 1', 'Oerlikon 1' ), array_column( $a['rows'], 'team' ) );
		self::assertSame( array( 15, 9, 6 ), array_column( $a['rows'], 'points' ) );
		self::assertSame( 1, $this->team_state( $next, $this->id['next_wollishofen_1'] )['previous']['rank'] );
		self::assertSame( 3, $this->team_state( $next, $this->id['next_oerlikon_1'] )['previous']['rank'] );
	}

	public function test_without_an_earlier_season_there_is_no_proposal(): void {
		$next = $this->setup->create(
			$this->tk,
			array(
				'name'       => '2027/28',
				'start_date' => '2027-08-01',
				'end_date'   => '2028-07-31',
			)
		);
		self::assertNull( $this->grouping->state( $next )['previous'] );
		$this->refused( fn() => $this->grouping->apply_proposal( $this->tk, $next ), Refusal::CONFLICT );
		$this->refused( fn() => $this->grouping->copy_competitions( $this->tk, $next ), Refusal::CONFLICT );
	}

	public function test_three_teams_of_a_club_in_one_category_are_a_hint_except_in_the_lowest(): void {
		$this->league();
		$next = $this->grouped();
		$this->seed->team( $next, $this->id['club_seefeld'], 3, 'Seefeld 3', $this->id['next_group_a'], $this->id['venue_seefeld'] );
		$this->grouping->assign( $this->tk, $this->id['next_seefeld_2'], $this->id['next_group_a'] );
		$this->seed->team( $next, $this->id['club_wollishofen'], 3, 'Wollishofen 3', $this->id['next_group_b'], $this->id['venue_wollishofen'] );
		$this->seed->team( $next, $this->id['club_wollishofen'], 4, 'Wollishofen 4', $this->id['next_group_b'], $this->id['venue_wollishofen'] );

		$hints = $this->grouping->state( $next )['hints'];

		$limits = array_values( array_filter( $hints, static fn( array $hint ): bool => GroupingRules::CLUB_LIMIT === $hint['code'] ) );
		self::assertCount( 1, $limits, 'Drei Mannschaften von Wollishofen in der untersten Kategorie B sind erlaubt.' );
		self::assertSame( $this->id['club_seefeld'], $limits[0]['club_id'] );
		self::assertStringContainsString( 'TTC Seefeld hat 3 Mannschaften in der Kategorie A', $limits[0]['message'] );

		// Der Hinweis ist weich: Die TK kann trotzdem so einteilen (Art. 11.5).
		self::assertSame( $this->id['next_group_a'], $this->group_of( $this->id['next_seefeld_2'] ) );
	}

	public function test_small_groups_and_unassigned_teams_are_hints(): void {
		$this->league();
		$next = $this->grouped();
		$this->grouping->assign( $this->tk, $this->id['next_oerlikon_1'], null );

		$hints = $this->grouping->state( $next )['hints'];

		self::assertSame( array( GroupingRules::UNASSIGNED, GroupingRules::SMALL_GROUP ), array_column( $hints, 'code' ) );
		self::assertSame( $this->id['next_oerlikon_1'], $hints[0]['team_id'] );
		self::assertSame( $this->id['next_group_a'], $hints[1]['group_id'] );

		$this->grouping->assign( $this->tk, $this->id['next_oerlikon_1'], $this->id['next_group_a'] );
		self::assertSame( array(), $this->grouping->state( $next )['hints'], 'Mit drei Mannschaften pro Gruppe ist nichts offen.' );
	}

	public function test_cup_and_ranking_tournament_are_carried_over_without_deadlines(): void {
		$this->league();
		$format = '{"points_limit":20,"registration_deadline":"2026-09-30"}';
		$cup_a  = $this->seed->competition( $this->id['active'], 'CUP', 'Mannschaftscup A', 'A' );
		$this->seed->competition( $this->id['active'], 'CUP', 'Mannschaftscup B', 'B' );
		$rl = $this->seed->competition( $this->id['active'], 'RL', 'Ranglistenturnier' );
		$this->db->update( 'competition', array( 'format_json' => $format ), array( 'id' => $cup_a ) );
		$this->db->update( 'competition', array( 'format_json' => '{"categories":{"1":[1,5]},"registration_deadline":"2026-09-15","reminder_days":3}' ), array( 'id' => $rl ) );
		$next = $this->plan();
		self::assertCount( 3, $this->grouping->state( $next )['copyable'] );

		$created = $this->grouping->copy_competitions( $this->tk, $next );

		self::assertSame( 3, $created );
		$rows = $this->db->get_results( "SELECT type, name, category, format_json FROM {$this->db->table( 'competition' )} WHERE season_id = %d ORDER BY type, category", array( $next ) );
		self::assertSame( array( 'CUP', 'CUP', 'RL' ), array_column( $rows, 'type' ) );
		self::assertSame( array( 'Mannschaftscup A', 'Mannschaftscup B', 'Ranglistenturnier' ), array_column( $rows, 'name' ) );
		self::assertSame( array( 'A', 'B', null ), array_column( $rows, 'category' ) );
		self::assertSame( array( 'points_limit' => 20 ), json_decode( (string) $rows[0]['format_json'], true ) );
		self::assertNull( $rows[1]['format_json'] );
		self::assertSame(
			array(
				'categories'    => array( '1' => array( 1, 5 ) ),
				'reminder_days' => 3,
			),
			json_decode( (string) $rows[2]['format_json'], true )
		);
		self::assertSame( $format, $this->row( 'competition', $cup_a )['format_json'], 'Die Konkurrenz der aktiven Saison bleibt, wie sie ist.' );
		self::assertSame( array(), $this->grouping->groups( $next ), 'Gruppen der Meisterschaft kommen nicht mit.' );

		self::assertSame( 0, $this->grouping->copy_competitions( $this->tk, $next ), 'Ein zweiter Aufruf legt nichts doppelt an.' );
		self::assertSame( array(), $this->grouping->state( $next )['copyable'] );
		self::assertCount( 3, $this->grouping->state( $next )['other'] );
		$this->refused( fn() => $this->grouping->copy_competitions( $this->chef, $next ), Refusal::FORBIDDEN );
	}
}
