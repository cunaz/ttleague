<?php
/**
 * Integrationstests: Saison anlegen, Regeln, Meldefenster, Clubs, Löschen.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Tests\Integration\Season;

use PHPUnit\Framework\Attributes\DataProvider;
use TTLeague\Domain\Rules\SeasonRules;
use TTLeague\Domain\Season\RulesInput;
use TTLeague\Infrastructure\AuditLog;
use TTLeague\Season\SeasonException as Refusal;
use TTLeague\Season\SeasonSetup;

/**
 * Daten siehe SeasonTestCase.
 */
final class SeasonSetupTest extends SeasonTestCase {

	/**
	 * @return array<string,mixed>
	 */
	private function form( array $change = array() ): array {
		$form = array();
		foreach ( ( new SeasonRules() )->to_array() as $field => $value ) {
			$form[ $field ] = RulesInput::text( $field, $value );
		}
		unset( $form['set_points_enabled'] );
		return $change + $form;
	}

	public function test_the_next_season_is_created_from_the_active_one(): void {
		$this->league();
		self::assertSame(
			array(
				'name'       => '2027/28',
				'slug'       => '2027-28',
				'start_date' => '2027-08-01',
				'end_date'   => '2028-07-31',
			),
			$this->setup->preview()
		);

		$next   = $this->setup->create( $this->tk );
		$season = $this->seasons->by_id( $next );

		self::assertSame( '2027/28', $season['name'] );
		self::assertSame( '2027-28', $season['slug'] );
		self::assertSame( '2027-08-01', $season['start_date'] );
		self::assertSame( '2028-07-31', $season['end_date'] );
		self::assertSame( 'planned', $season['status'] );
		self::assertSame( array( 'substitute_max' => 2 ), $season['rules'], 'Die Regeln der aktiven Saison sind übernommen.' );
		self::assertNull( $season['registration_opens'] );
		self::assertSame( $next, $this->seasons->planned()['id'] );
		self::assertSame( $this->id['active'], $this->seasons->active()['id'], 'Die aktive Saison bleibt aktiv.' );
		self::assertSame( array( $next ), $this->events->changed );
	}

	public function test_every_club_with_an_active_team_gets_an_open_registration(): void {
		$this->league();
		$idle = $this->seed->club( 'Ohne Mannschaft', 9 );
		$this->db->update( 'team', array( 'status' => 'withdrawn' ), array( 'id' => $this->id['altstetten_1'] ) );

		$next = $this->setup->create( $this->tk );

		$clubs = array_map( 'intval', $this->db->get_col( "SELECT club_id FROM {$this->db->table( 'club_season' )} WHERE season_id = %d AND status = 'open' ORDER BY club_id", array( $next ) ) );
		self::assertSame( array( $this->id['club_seefeld'], $this->id['club_wollishofen'], $this->id['club_oerlikon'] ), $clubs );
		self::assertNotContains( $idle, $clubs );
		self::assertNotContains( $this->id['club_altstetten'], $clubs, 'Ein Club, dessen einzige Mannschaft zurückgezogen ist, wird nicht vorgetragen.' );
		self::assertSame( 3, $this->rows( 'club_season' ) );
	}

	public function test_the_club_officers_are_carried_over_but_not_the_captains(): void {
		$this->league();
		$second = $this->seed->person( 'Zweit', 'Zora' );
		$this->seed->role( $this->id['active'], $this->id['club_seefeld'], $second, 'tk_chef' );

		$next = $this->setup->create( $this->tk );

		$roles = $this->db->get_results( "SELECT club_id, person_id, role, team_id FROM {$this->db->table( 'club_role' )} WHERE season_id = %d ORDER BY club_id, person_id", array( $next ) );
		self::assertCount( 5, $roles );
		self::assertSame( array( 'tk_chef' ), array_values( array_unique( array_column( $roles, 'role' ) ) ) );
		self::assertSame( array( null ), array_values( array_unique( array_column( $roles, 'team_id' ) ) ) );
		$seefeld = array_map( 'intval', array_column( array_filter( $roles, fn( array $role ): bool => (int) $role['club_id'] === $this->id['club_seefeld'] ), 'person_id' ) );
		self::assertSame( array( $this->id['chef_seefeld'], $second ), $seefeld );
		self::assertSame( 4 + 1 + 6, $this->rows( 'club_role', 'season_id = ' . $this->id['active'] ), 'Die Funktionen der aktiven Saison bleiben.' );
	}

	public function test_the_creation_is_logged(): void {
		$this->league();
		$next  = $this->setup->create( $this->tk );
		$entry = (array) $this->db->get_row( "SELECT * FROM {$this->db->table( 'audit_log' )} WHERE entity = 'season' AND entity_id = %d", array( $next ) );
		self::assertSame( 'created', $entry['action'] );
		self::assertSame( '900', $entry['user_id'] );
		$after = json_decode( (string) $entry['after_json'], true );
		self::assertSame( $this->id['active'], $after['from_season'] );
		self::assertSame( 4, $after['clubs'] );
		self::assertSame( 4, $after['tk_chefs'] );
	}

	public function test_a_second_planned_season_is_refused(): void {
		$this->league();
		$this->setup->create( $this->tk );

		$refusal = $this->refused( fn() => $this->setup->create( $this->tk ), Refusal::CONFLICT );

		self::assertStringContainsString( '2027/28', $refusal->getMessage() );
		self::assertSame( 1, $this->rows( 'season', "status = 'planned'" ) );
		self::assertSame( 2, $this->rows( 'season' ) );
		self::assertSame( 4, $this->rows( 'club_season' ), 'Der abgelehnte Aufruf hat nichts angelegt.' );
	}

	public function test_only_the_tk_creates_a_season(): void {
		$this->league();
		$this->refused( fn() => $this->setup->create( $this->chef ), Refusal::FORBIDDEN );
		self::assertSame( 1, $this->rows( 'season' ) );
	}

	public function test_an_existing_season_with_the_same_name_is_refused(): void {
		$this->league();
		$this->seed->season( '2027-28', 'archived' );
		$this->refused( fn() => $this->setup->create( $this->tk ), Refusal::CONFLICT );
		self::assertSame( 0, $this->rows( 'season', "status = 'planned'" ) );
	}

	public function test_the_seasons_are_locked_while_one_is_created(): void {
		$this->league();
		$other = self::another_connection();
		$other->pdo()->exec( 'SET SESSION innodb_lock_wait_timeout = 1' );
		$error = '';
		$this->db->transaction(
			function () use ( $other, &$error ): void {
				$this->db->get_col( "SELECT id FROM {$this->db->table( 'season' )} ORDER BY id FOR UPDATE" );
				try {
					( new SeasonSetup( $other, new AuditLog( $other ), new RecordingSeasonEvents() ) )->create( $this->tk );
				} catch ( \Throwable $e ) {
					$error = $e->getMessage();
				}
			}
		);
		self::assertMatchesRegularExpression( '/lock wait timeout/i', $error, 'Solange eine andere Transaktion die Saisons sperrt, wartet das Anlegen.' );
		self::assertSame( 0, $this->rows( 'season', "status = 'planned'" ) );

		( new SeasonSetup( $other, new AuditLog( $other ), new RecordingSeasonEvents() ) )->create( $this->tk );
		self::assertSame( 1, $this->rows( 'season', "status = 'planned'" ), 'Ohne die Sperre legt dieselbe Verbindung an.' );
	}

	public function test_a_fresh_installation_gets_an_empty_season_with_default_rules(): void {
		self::assertNull( $this->setup->preview() );

		$next = $this->setup->create(
			$this->tk,
			array(
				'name'       => '2027/28',
				'start_date' => '2027-08-01',
				'end_date'   => '2028-07-31',
			)
		);

		$season = $this->seasons->by_id( $next );
		self::assertSame( '2027-28', $season['slug'] );
		self::assertSame( 'planned', $season['status'] );
		self::assertEquals( ( new SeasonRules() )->to_array(), $season['rules'] );
		self::assertSame( 0, $this->rows( 'club_season' ) );
		self::assertSame( 0, $this->rows( 'club_role' ) );
	}

	public function test_a_fresh_installation_needs_valid_name_and_dates(): void {
		$refusal = $this->refused(
			fn() => $this->setup->create(
				$this->tk,
				array(
					'name'       => 'Saison 27',
					'start_date' => '2027-08-01',
					'end_date'   => '2027-07-31',
				)
			),
			Refusal::INVALID
		);
		self::assertSame( array( 'name' ), array_column( $refusal->errors, 'field' ) );
		self::assertSame( 0, $this->rows( 'season' ) );
	}

	public function test_rules_are_saved_when_every_field_is_valid(): void {
		$this->league();
		$next = $this->setup->create( $this->tk );

		$rules = $this->setup->save_rules(
			$this->tk,
			$next,
			$this->form(
				array(
					'substitute_max'            => '4',
					'schedule_tables_per_match' => '3',
					'set_points_enabled'        => '1',
				)
			)
		);

		$stored = $this->seasons->by_id( $next )['rules'];
		self::assertSame( 4, $stored['substitute_max'] );
		self::assertSame( 3, $stored['schedule_tables_per_match'] );
		self::assertTrue( $stored['set_points_enabled'] );
		self::assertEquals( $rules, $stored );
		self::assertSame( 3, $this->seasons->rules( $this->seasons->by_id( $next ) )->schedule_tables_per_match );
		self::assertSame( array( 'substitute_max' => 2 ), $this->seasons->active()['rules'], 'Die Regeln der aktiven Saison ändern sich nicht.' );
		self::assertSame( 1, $this->rows( 'audit_log', "entity = 'season' AND action = 'rules_saved'" ) );
	}

	public function test_invalid_rules_are_reported_at_the_field_and_nothing_is_saved(): void {
		$this->league();
		$next = $this->setup->create( $this->tk );

		$refusal = $this->refused(
			fn() => $this->setup->save_rules(
				$this->tk,
				$next,
				$this->form(
					array(
						'substitute_max' => '9',
						'sets_to_win'    => '0',
						'cup_mode'       => 'nie',
					)
				)
			),
			Refusal::INVALID
		);

		self::assertSame( array( 'sets_to_win', 'cup_mode' ), array_column( $refusal->errors, 'field' ) );
		self::assertSame( array( 'substitute_max' => 2 ), $this->seasons->by_id( $next )['rules'], 'Auch das gültige Feld ist nicht gespeichert.' );
		self::assertSame( 0, $this->rows( 'audit_log', "action = 'rules_saved'" ) );
	}

	public function test_rules_of_the_active_season_are_not_edited_here(): void {
		$this->league();
		$this->refused( fn() => $this->setup->save_rules( $this->tk, $this->id['active'], $this->form() ), Refusal::CONFLICT );
		$this->refused( fn() => $this->setup->save_rules( $this->chef, $this->id['active'], $this->form() ), Refusal::FORBIDDEN );
		$this->refused( fn() => $this->setup->save_rules( $this->tk, 999, $this->form() ), Refusal::NOT_FOUND );
	}

	public function test_the_registration_window_is_saved(): void {
		$this->league();
		$next = $this->setup->create( $this->tk );

		$this->setup->save_window(
			$this->tk,
			$next,
			array(
				'registration_opens'      => '2027-05-01',
				'registration_closes'     => '2027-05-31',
				'late_registration_until' => '2028-01-31',
			)
		);

		$season = $this->seasons->by_id( $next );
		self::assertSame( '2027-05-01', $season['registration_opens'] );
		self::assertSame( '2027-05-31', $season['registration_closes'] );
		self::assertSame( '2028-01-31', $season['late_registration_until'] );

		$this->setup->save_window( $this->tk, $next, array( 'registration_closes' => '' ) );
		$season = $this->seasons->by_id( $next );
		self::assertNull( $season['registration_closes'], 'Eine leere Angabe löscht das Datum.' );
		self::assertSame( '2027-05-01', $season['registration_opens'], 'Was nicht mitkommt, bleibt.' );
	}

	public function test_the_window_must_be_in_order(): void {
		$this->league();
		$next = $this->setup->create( $this->tk );

		$refusal = $this->refused(
			fn() => $this->setup->save_window(
				$this->tk,
				$next,
				array(
					'registration_opens'  => '2027-06-01',
					'registration_closes' => '2027-05-31',
				)
			),
			Refusal::INVALID
		);

		self::assertSame( array( 'registration_closes' ), array_column( $refusal->errors, 'field' ) );
		self::assertNull( $this->seasons->by_id( $next )['registration_opens'] );
		$this->refused( fn() => $this->setup->save_window( $this->chef, $next, array() ), Refusal::FORBIDDEN );
	}

	public function test_the_late_registration_deadline_can_be_changed_in_the_active_season_but_not_in_an_archived_one(): void {
		$this->league();
		$old = $this->seed->season( '2025-26', 'archived' );

		$this->setup->save_window( $this->tk, $this->id['active'], array( 'late_registration_until' => '2027-02-28' ) );
		self::assertSame( '2027-02-28', $this->seasons->active()['late_registration_until'] );

		$this->refused( fn() => $this->setup->save_window( $this->tk, $old, array( 'late_registration_until' => '2026-02-28' ) ), Refusal::CONFLICT );
	}

	public function test_a_club_can_be_added_and_gets_its_officers(): void {
		$this->league();
		$this->db->update( 'team', array( 'status' => 'withdrawn' ), array( 'id' => $this->id['altstetten_1'] ) );
		$next = $this->setup->create( $this->tk );

		$this->setup->add_club( $this->tk, $next, $this->id['club_altstetten'] );

		self::assertSame( 1, $this->rows( 'club_season', "status = 'open' AND season_id = {$next} AND club_id = " . $this->id['club_altstetten'] ) );
		self::assertSame( 1, $this->rows( 'club_role', "role = 'tk_chef' AND season_id = {$next} AND person_id = " . $this->id['chef_altstetten'] ) );
		$this->refused( fn() => $this->setup->add_club( $this->tk, $next, $this->id['club_altstetten'] ), Refusal::CONFLICT );
		$this->refused( fn() => $this->setup->add_club( $this->tk, $next, 999 ), Refusal::NOT_FOUND );
		$this->refused( fn() => $this->setup->add_club( $this->chef, $next, $this->id['club_altstetten'] ), Refusal::FORBIDDEN );
		$this->refused( fn() => $this->setup->add_club( $this->tk, $this->id['active'], $this->id['club_altstetten'] ), Refusal::CONFLICT );
	}

	public function test_a_club_without_registration_can_be_removed(): void {
		$this->league();
		$next = $this->setup->create( $this->tk );
		$this->seed->team( $next, $this->id['club_seefeld'], 1, 'Seefeld 1', null );

		$this->setup->remove_club( $this->tk, $next, $this->id['club_oerlikon'] );

		self::assertSame( 0, $this->rows( 'club_season', "season_id = {$next} AND club_id = " . $this->id['club_oerlikon'] ) );
		self::assertSame( 0, $this->rows( 'club_role', "season_id = {$next} AND club_id = " . $this->id['club_oerlikon'] ) );
		self::assertSame( 1, $this->rows( 'club_role', 'season_id = ' . $this->id['active'] . ' AND person_id = ' . $this->id['chef_oerlikon'] ) );
		$this->refused( fn() => $this->setup->remove_club( $this->tk, $next, $this->id['club_seefeld'] ), Refusal::CONFLICT );
		$this->refused( fn() => $this->setup->remove_club( $this->tk, $next, $this->id['club_oerlikon'] ), Refusal::NOT_FOUND );
	}

	public function test_an_empty_planned_season_can_be_deleted_with_its_name(): void {
		$this->league();
		$next = $this->setup->create( $this->tk );
		$this->grouping->create_group( $this->tk, $next, 'A', 1 );

		$refusal = $this->refused( fn() => $this->setup->delete( $this->tk, $next, '2026/27' ), Refusal::INVALID );
		self::assertSame( array( 'confirm' ), array_column( $refusal->errors, 'field' ) );
		self::assertSame( 'planned', $this->season_status( $next ) );

		$this->setup->delete( $this->tk, $next, ' 2027/28 ' );

		self::assertNull( $this->seasons->by_id( $next ) );
		self::assertSame( 0, $this->rows( 'club_season' ) );
		self::assertSame( 0, $this->rows( 'club_role', "season_id = {$next}" ) );
		self::assertSame( 0, $this->rows( 'competition', "season_id = {$next}" ) );
		self::assertSame( 2, $this->rows( 'competition' ), 'Die Gruppen der aktiven Saison bleiben.' );
		self::assertSame( 'active', $this->season_status( $this->id['active'] ) );
		self::assertSame( 1, $this->rows( 'audit_log', "entity = 'season' AND action = 'deleted'" ) );
		self::assertNotNull( $this->setup->preview(), 'Danach lässt sich wieder eine Saison anlegen.' );
		$this->setup->create( $this->tk );
	}

	/**
	 * @return array<string,array{0:string}>
	 */
	public static function contents(): array {
		return array(
			'gemeldeter Spieler'   => array( 'player' ),
			'Neumeldung'           => array( 'request' ),
			'eingereichte Meldung' => array( 'submitted' ),
			'Mannschaft'           => array( 'team' ),
			'Begegnung'            => array( 'fixture' ),
			'Cup-Mannschaft'       => array( 'cup' ),
		);
	}

	#[DataProvider( 'contents' )]
	public function test_a_season_with_content_cannot_be_deleted( string $what ): void {
		$this->league();
		$next = $this->setup->create( $this->tk );
		$club = $this->id['club_seefeld'];
		switch ( $what ) {
			case 'player':
				$this->seed->register( $this->seed->player( 'Muster', 'Hans' ), $next, $club, 8 );
				break;
			case 'request':
				$this->db->insert(
					'player_request',
					array(
						'season_id'    => $next,
						'club_id'      => $club,
						'last_name'    => 'Beispiel',
						'first_name'   => 'Berta',
						'requested_by' => 1,
						'requested_at' => '2027-05-02 10:00:00',
					)
				);
				break;
			case 'submitted':
				$this->db->update( 'club_season', array( 'status' => 'submitted' ), array( 'club_id' => $club ) );
				break;
			case 'team':
				$this->seed->team( $next, $club, 1, 'Seefeld 1', null );
				break;
			case 'fixture':
				$this->seed->fixture( $this->grouping->create_group( $this->tk, $next, 'A', 1 ), array( 'status' => 'scheduled' ) );
				break;
			default:
				$this->db->insert(
					'cup_entry',
					array(
						'competition_id' => $this->seed->competition( $next, 'CUP', 'Mannschaftscup A', 'A' ),
						'club_id'        => $club,
						'number'         => 1,
						'name'           => 'Seefeld 1',
					)
				);
		}

		$this->refused( fn() => $this->setup->delete( $this->tk, $next, '2027/28' ), Refusal::CONFLICT );

		self::assertSame( 'planned', $this->season_status( $next ) );
		self::assertSame( 4, $this->rows( 'club_season' ) );
	}

	public function test_only_a_planned_season_can_be_deleted_and_only_by_the_tk(): void {
		$this->league();
		$next = $this->setup->create( $this->tk );
		$this->refused( fn() => $this->setup->delete( $this->chef, $next, '2027/28' ), Refusal::FORBIDDEN );
		$this->refused( fn() => $this->setup->delete( $this->tk, $this->id['active'], '2026/27' ), Refusal::CONFLICT );
		self::assertSame( 2, $this->rows( 'season' ) );
	}
}
