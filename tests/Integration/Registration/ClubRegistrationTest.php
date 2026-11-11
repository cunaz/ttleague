<?php
/**
 * Integrationstests der Meldung eines Clubs gegen MariaDB.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Tests\Integration\Registration;

use PHPUnit\Framework\Attributes\DataProvider;
use TTLeague\Registration\RegistrationException as Refusal;

/**
 * Daten siehe RegistrationTestCase.
 */
final class ClubRegistrationTest extends RegistrationTestCase {

	public function test_confirming_a_player_registers_him_provisionally_with_his_classification(): void {
		$id = $this->registration->confirm_player( $this->actor['chef'], $this->id['next'], $this->id['home'], $this->id['p_muster'] );

		$row = $this->player_season( $this->id['next'], $this->id['p_muster'] );
		self::assertSame( (string) $id, $row['id'] ?? null );
		self::assertSame( (string) $this->id['home'], $row['club_id'] );
		self::assertSame( '8', $row['classification'] );
		self::assertSame( 'provisional', $row['status'] );
		self::assertSame( '301', $row['registered_by'] );
		self::assertSame( '2027-05-10 10:00:00', $row['registered_at'] );
		self::assertNull( $row['approved_by'] );
		self::assertSame( 1, $this->rows( 'audit_log', "entity = 'player_season' AND action = 'registered' AND user_id = 301" ) );
		self::assertSame( array( array( $this->id['next'], $this->id['home'] ) ), $this->events->changed );
	}

	public function test_previous_players_are_offered_until_they_are_confirmed(): void {
		$state = $this->registration->state( $this->actor['chef'], $this->id['next'], $this->id['home'] );
		self::assertSame( array( 'Berta Beispiel', 'Hans Muster', 'Paul Probe', 'Vera Vorlage' ), array_column( $state['candidates'], 'name' ) );
		self::assertSame( array(), $state['players'] );

		$this->registration->confirm_player( $this->actor['chef'], $this->id['next'], $this->id['home'], $this->id['p_muster'] );

		$state = $this->registration->state( $this->actor['chef'], $this->id['next'], $this->id['home'] );
		self::assertSame( array( 'Berta Beispiel', 'Paul Probe', 'Vera Vorlage' ), array_column( $state['candidates'], 'name' ) );
		self::assertSame( array( 'Hans Muster' ), array_column( $state['players'], 'name' ) );
	}

	public function test_withdrawing_removes_the_registration_and_the_player_can_be_confirmed_again(): void {
		$this->registration->confirm_player( $this->actor['chef'], $this->id['next'], $this->id['home'], $this->id['p_muster'] );

		$this->registration->withdraw_player( $this->actor['chef'], $this->id['next'], $this->id['home'], $this->id['p_muster'] );

		self::assertNull( $this->player_season( $this->id['next'], $this->id['p_muster'] ) );
		self::assertSame( 1, $this->rows( 'audit_log', "entity = 'player_season' AND action = 'withdrawn'" ) );
		self::assertNotNull( $this->player_season( $this->id['now'], $this->id['p_muster'] ), 'die aktive Saison bleibt unberührt' );
		$this->registration->confirm_player( $this->actor['chef'], $this->id['next'], $this->id['home'], $this->id['p_muster'] );
		self::assertNotNull( $this->player_season( $this->id['next'], $this->id['p_muster'] ) );
		$this->refused( fn() => $this->registration->withdraw_player( $this->actor['chef'], $this->id['next'], $this->id['home'], $this->id['p_gast'] ), Refusal::NOT_FOUND );
	}

	public function test_classification_follows_the_decision_once_the_active_season_is_closed(): void {
		$this->db->insert(
			'classification_proposal',
			array(
				'season_id'     => $this->id['now'],
				'player_id'     => $this->id['p_muster'],
				'current_value' => 8,
				'rating'        => 912.5,
				'proposed'      => 9,
				'decided'       => 9,
			)
		);
		$this->db->insert(
			'classification_proposal',
			array(
				'season_id'     => $this->id['now'],
				'player_id'     => $this->id['p_probe'],
				'current_value' => 6,
				'rating'        => 480.0,
				'proposed'      => 5,
				'decided'       => null,
			)
		);

		// Solange die Klassierung nicht abgeschlossen ist, gilt die bisherige.
		$this->registration->confirm_player( $this->actor['chef'], $this->id['next'], $this->id['home'], $this->id['p_muster'] );
		self::assertSame( '8', $this->player_season( $this->id['next'], $this->id['p_muster'] )['classification'] );
		$this->registration->withdraw_player( $this->actor['chef'], $this->id['next'], $this->id['home'], $this->id['p_muster'] );

		$this->db->update( 'season', array( 'classification_closed_at' => '2027-05-09 18:00:00' ), array( 'id' => $this->id['now'] ) );
		$candidates = array_column( $this->registration->state( $this->actor['chef'], $this->id['next'], $this->id['home'] )['candidates'], 'classification', 'name' );
		self::assertSame( 9, $candidates['Hans Muster'] );
		self::assertSame( 6, $candidates['Paul Probe'], 'ohne Entscheid bleibt die bisherige' );

		$this->registration->confirm_player( $this->actor['chef'], $this->id['next'], $this->id['home'], $this->id['p_muster'] );
		$this->registration->confirm_player( $this->actor['chef'], $this->id['next'], $this->id['home'], $this->id['p_probe'] );
		$this->registration->confirm_player( $this->actor['chef'], $this->id['next'], $this->id['home'], $this->id['p_beispiel'] );

		self::assertSame( '9', $this->player_season( $this->id['next'], $this->id['p_muster'] )['classification'] );
		self::assertSame( '6', $this->player_season( $this->id['next'], $this->id['p_probe'] )['classification'] );
		self::assertSame( '7', $this->player_season( $this->id['next'], $this->id['p_beispiel'] )['classification'], 'ohne Vorschlag' );
	}

	public function test_a_player_who_paused_keeps_the_last_known_classification(): void {
		$license = (int) $this->db->get_var( "SELECT license_no FROM {$this->db->table( 'player' )} WHERE id = %d", array( $this->id['p_ehemalig'] ) );

		$this->registration->register_license( $this->actor['chef'], $this->id['next'], $this->id['home'], $license );

		self::assertSame( '4', $this->player_season( $this->id['next'], $this->id['p_ehemalig'] )['classification'] );
		$e = $this->refused( fn() => $this->registration->register_license( $this->actor['chef'], $this->id['next'], $this->id['home'], 12345 ), Refusal::INVALID );
		self::assertSame( 'license_no', $e->errors[0]['field'] );
	}

	public function test_the_club_cannot_choose_a_classification_but_the_tk_can(): void {
		$this->registration->confirm_player( $this->actor['chef'], $this->id['next'], $this->id['home'], $this->id['p_muster'], 20 );
		self::assertSame( '8', $this->player_season( $this->id['next'], $this->id['p_muster'] )['classification'] );

		$this->registration->confirm_player( $this->actor['tk'], $this->id['next'], $this->id['home'], $this->id['p_probe'], 10 );
		$row = $this->player_season( $this->id['next'], $this->id['p_probe'] );
		self::assertSame( '10', $row['classification'] );
		self::assertSame( 'active', $row['status'], 'eine Klassierung der TK ist ein Entscheid' );
		self::assertSame( '303', $row['approved_by'] );

		$this->registration->confirm_player( $this->actor['tk'], $this->id['next'], $this->id['home'], $this->id['p_beispiel'] );
		self::assertSame( 'provisional', $this->player_season( $this->id['next'], $this->id['p_beispiel'] )['status'], 'ohne eigene Klassierung bis zur Freigabe der Meldung provisorisch' );
		$e = $this->refused( fn() => $this->registration->confirm_player( $this->actor['tk'], $this->id['next'], $this->id['home'], $this->id['p_vorlage'], 23 ), Refusal::INVALID );
		self::assertSame( 'classification', $e->errors[0]['field'] );
	}

	public function test_a_player_is_registered_for_one_club_per_season(): void {
		$license = (int) $this->db->get_var( "SELECT license_no FROM {$this->db->table( 'player' )} WHERE id = %d", array( $this->id['p_vorlage'] ) );

		// Vereinswechsel: Der neue Club meldet die Spielerin mit ihrer Lizenznummer.
		$this->registration->register_license( $this->actor['wolli'], $this->id['next'], $this->id['away'], $license );

		$row = $this->player_season( $this->id['next'], $this->id['p_vorlage'] );
		self::assertSame( (string) $this->id['away'], $row['club_id'] );
		self::assertSame( '5', $row['classification'] );
		$candidates = array_column( $this->registration->state( $this->actor['chef'], $this->id['next'], $this->id['home'] )['candidates'], 'other_club', 'name' );
		self::assertSame( 'Wollishofen', $candidates['Vera Vorlage'] );
		self::assertNull( $candidates['Hans Muster'] );

		$e = $this->refused( fn() => $this->registration->confirm_player( $this->actor['chef'], $this->id['next'], $this->id['home'], $this->id['p_vorlage'] ), Refusal::CONFLICT );
		self::assertStringContainsString( 'schon für Wollishofen gemeldet', $e->getMessage() );
		self::assertStringContainsString( 'Art. 27.2', $e->getMessage() );
		self::assertSame( 1, $this->rows( 'player_season', 'season_id = ' . $this->id['next'] ) );

		$e = $this->refused( fn() => $this->registration->register_license( $this->actor['wolli'], $this->id['next'], $this->id['away'], $license ), Refusal::CONFLICT );
		self::assertStringContainsString( 'ist schon gemeldet', $e->getMessage() );
	}

	public function test_a_new_player_is_only_a_request_until_the_tk_approves(): void {
		$persons = $this->rows( 'person' );
		$players = $this->rows( 'player' );

		$id = $this->registration->request_player(
			$this->actor['chef'],
			$this->id['next'],
			$this->id['home'],
			array(
				'last_name'      => ' Neuling ',
				'first_name'     => 'Noah',
				'email'          => 'Noah.Neuling@example.invalid',
				'classification' => 3,
				'stt_license'    => '123456',
			)
		);

		$row = $this->db->get_row( "SELECT * FROM {$this->db->table( 'player_request' )} WHERE id = %d", array( $id ) );
		self::assertSame( 'Neuling', $row['last_name'] );
		self::assertSame( 'noah.neuling@example.invalid', $row['email'] );
		self::assertSame( '3', $row['classification'] );
		self::assertSame( 'requested', $row['status'] );
		self::assertNull( $row['player_id'] );
		self::assertSame( '301', $row['requested_by'] );
		self::assertSame( $persons, $this->rows( 'person' ), 'keine Person vor der Freigabe' );
		self::assertSame( $players, $this->rows( 'player' ), 'kein Spieler vor der Freigabe' );
		self::assertSame( 0, $this->rows( 'player_season', 'season_id = ' . $this->id['next'] ) );
		self::assertSame( array( 'Noah Neuling' ), array_column( $this->registration->state( $this->actor['chef'], $this->id['next'], $this->id['home'] )['requests'], 'name' ) );

		$e = $this->refused(
			fn() => $this->registration->request_player(
				$this->actor['chef'],
				$this->id['next'],
				$this->id['home'],
				array(
					'last_name'      => 'neuling',
					'first_name'     => 'noah',
					'classification' => 3,
				)
			),
			Refusal::CONFLICT
		);
		self::assertStringContainsString( 'wartet auf die Freigabe', $e->getMessage() );

		$this->registration->cancel_request( $this->actor['chef'], $id );
		self::assertSame( 0, $this->rows( 'player_request' ) );
	}

	public function test_an_invalid_request_names_the_fields(): void {
		$e = $this->refused(
			fn() => $this->registration->request_player(
				$this->actor['chef'],
				$this->id['next'],
				$this->id['home'],
				array(
					'last_name'      => '',
					'first_name'     => 'Noah',
					'email'          => 'keine-adresse',
					'classification' => 0,
				)
			),
			Refusal::INVALID
		);

		self::assertSame( array( 'last_name', 'email', 'classification' ), array_column( $e->errors, 'field' ) );
		self::assertSame( 0, $this->rows( 'player_request' ) );
	}

	public function test_teams_are_numbered_and_only_the_last_one_can_be_removed(): void {
		$first  = $this->registration->add_team( $this->actor['chef'], $this->id['next'], $this->id['home'] );
		$second = $this->registration->add_team( $this->actor['chef'], $this->id['next'], $this->id['home'] );

		$teams = $this->registration->state( $this->actor['chef'], $this->id['next'], $this->id['home'] )['teams'];
		self::assertSame( array( 'Seefeld 1', 'Seefeld 2' ), array_column( $teams, 'name' ) );
		self::assertSame( array( 1, 2 ), array_column( $teams, 'number' ) );
		self::assertSame( array( null, null ), array_column( $teams, 'competition_id' ), 'Kategorie und Gruppe legt die TK fest' );

		$this->refused( fn() => $this->registration->remove_team( $this->actor['chef'], $first ), Refusal::CONFLICT );
		$this->registration->remove_team( $this->actor['chef'], $second );
		self::assertSame( 1, $this->rows( 'team', 'season_id = ' . $this->id['next'] ) );
	}

	public function test_a_team_the_tk_has_grouped_is_removed_only_by_the_tk_and_never_with_fixtures(): void {
		$team  = $this->registration->add_team( $this->actor['chef'], $this->id['next'], $this->id['home'] );
		$group = $this->seed->competition( $this->id['next'], 'MM', 'Gruppe B1', 'B', 1 );
		$this->db->update( 'team', array( 'competition_id' => $group ), array( 'id' => $team ) );

		$this->refused( fn() => $this->registration->remove_team( $this->actor['chef'], $team ), Refusal::CONFLICT );

		$fixture = $this->seed->fixture( $group, array( 'home_team_id' => $team ) );
		$this->refused( fn() => $this->registration->remove_team( $this->actor['tk'], $team ), Refusal::CONFLICT );
		$this->db->delete( 'fixture', array( 'id' => $fixture ) );
		$this->registration->remove_team( $this->actor['tk'], $team );
		self::assertSame( 0, $this->rows( 'team', 'season_id = ' . $this->id['next'] ) );
	}

	public function test_saving_a_team_stores_roster_venue_time_and_captain(): void {
		$team = $this->complete_registration();

		$saved = $this->registration->state( $this->actor['chef'], $this->id['next'], $this->id['home'] )['teams'][0];
		self::assertSame( array( $this->id['p_muster'], $this->id['p_beispiel'], $this->id['p_probe'] ), $saved['players'] );
		self::assertSame( $this->id['venue'], $saved['home_venue_id'] );
		self::assertSame( '20:00:00', $saved['start_time'] );
		self::assertSame( 'Hans Muster', $saved['captain']['name'] ?? null );
		self::assertSame( 3, $this->rows( 'team_roster', "team_id = {$team} AND kind = 'stamm'" ) );
		self::assertSame( 1, $this->rows( 'club_role', "season_id = {$this->id['next']} AND role = 'captain' AND team_id = {$team}" ) );
		self::assertSame( 1, $this->rows( 'audit_log', "entity = 'team' AND action = 'updated' AND entity_id = {$team}" ) );
		self::assertSame( 3, $this->rows( 'team_roster', "team_id = {$this->id['team']}" ), 'die Mannschaft der aktiven Saison bleibt' );
	}

	public function test_an_incomplete_team_is_saved_and_reported_as_violation(): void {
		$this->registration->confirm_player( $this->actor['chef'], $this->id['next'], $this->id['home'], $this->id['p_muster'] );
		$this->registration->confirm_player( $this->actor['chef'], $this->id['next'], $this->id['home'], $this->id['p_beispiel'] );
		$first  = $this->registration->add_team( $this->actor['chef'], $this->id['next'], $this->id['home'] );
		$second = $this->registration->add_team( $this->actor['chef'], $this->id['next'], $this->id['home'] );

		$this->registration->save_team( $this->actor['chef'], $first, array( 'players' => array( $this->id['p_muster'], $this->id['p_beispiel'] ) ) );
		$this->registration->save_team( $this->actor['chef'], $second, array( 'players' => array( $this->id['p_muster'] ) ) );

		$violations = $this->registration->violations( $this->id['next'], $this->id['home'] );
		$by_field   = array();
		foreach ( $violations as $violation ) {
			$by_field[ $violation['field'] ][] = $violation['code'];
		}
		self::assertSame( array( 'stamm_count' ), $by_field[ 'team.' . $first . '.players' ] );
		self::assertSame( array( 'stamm_count', 'stamm_duplicate' ), $by_field[ 'team.' . $second . '.players' ] );
		self::assertSame( array( 'venue_missing' ), $by_field[ 'team.' . $first . '.venue' ] );
		self::assertSame( array( 'start_missing' ), $by_field[ 'team.' . $first . '.start' ] );
		self::assertSame( array( 'captain_missing' ), $by_field[ 'team.' . $first . '.captain' ] );
	}

	public function test_a_withdrawn_regular_player_becomes_a_violation(): void {
		$team = $this->complete_registration();
		self::assertSame( array(), $this->registration->violations( $this->id['next'], $this->id['home'] ) );

		$this->registration->withdraw_player( $this->actor['chef'], $this->id['next'], $this->id['home'], $this->id['p_probe'] );

		$violations = $this->registration->violations( $this->id['next'], $this->id['home'] );
		self::assertSame( array( 'stamm_not_registered' ), array_column( $violations, 'code' ) );
		self::assertSame( 'team.' . $team . '.players', $violations[0]['field'] );
		self::assertStringContainsString( 'Paul Probe', $violations[0]['message'] );
	}

	public function test_saving_a_team_rejects_foreign_players_venues_and_captains(): void {
		$this->registration->confirm_player( $this->actor['chef'], $this->id['next'], $this->id['home'], $this->id['p_muster'] );
		$team        = $this->registration->add_team( $this->actor['chef'], $this->id['next'], $this->id['home'] );
		$other_venue = $this->seed->venue( $this->id['away'], 'Halle Wollishofen' );
		$closed      = $this->seed->venue( $this->id['home'], 'Alte Halle', array( 'active' => 0 ) );

		$e = $this->refused( fn() => $this->registration->save_team( $this->actor['chef'], $team, array( 'players' => array( $this->id['p_muster'], $this->id['p_gast'] ) ) ), Refusal::INVALID );
		self::assertStringContainsString( 'Gustav Gast ist für den Club in dieser Saison nicht gemeldet', $e->getMessage() );
		$e = $this->refused( fn() => $this->registration->save_team( $this->actor['chef'], $team, array( 'home_venue_id' => $other_venue ) ), Refusal::INVALID );
		self::assertSame( 'team.' . $team . '.venue', $e->errors[0]['field'] );
		$this->refused( fn() => $this->registration->save_team( $this->actor['chef'], $team, array( 'home_venue_id' => $closed ) ), Refusal::INVALID );
		$e = $this->refused( fn() => $this->registration->save_team( $this->actor['chef'], $team, array( 'start_time' => '8 Uhr' ) ), Refusal::INVALID );
		self::assertSame( 'team.' . $team . '.start', $e->errors[0]['field'] );

		// Captain: braucht eine E-Mail-Adresse und gehört zum Club.
		$this->registration->confirm_player( $this->actor['chef'], $this->id['next'], $this->id['home'], $this->id['p_beispiel'] );
		$e = $this->refused( fn() => $this->registration->set_captain( $this->actor['chef'], $team, $this->seed->person_of( $this->id['p_beispiel'] ) ), Refusal::INVALID );
		self::assertStringContainsString( 'Berta Beispiel hat keine E-Mail-Adresse', $e->getMessage() );
		$this->db->update( 'person', array( 'email' => 'gustav.gast@example.invalid' ), array( 'id' => $this->seed->person_of( $this->id['p_gast'] ) ) );
		$e = $this->refused( fn() => $this->registration->set_captain( $this->actor['chef'], $team, $this->seed->person_of( $this->id['p_gast'] ) ), Refusal::INVALID );
		self::assertStringContainsString( 'Gustav Gast gehört nicht zum Club', $e->getMessage() );

		self::assertSame( 0, $this->rows( 'team_roster', "team_id = {$team}" ), 'nichts gespeichert' );
		self::assertSame( 0, $this->rows( 'club_role', "team_id = {$team}" ) );
		$this->registration->set_captain( $this->actor['chef'], $team, $this->id['person_heim'] );
		self::assertSame( 'Hanna Heim', $this->registration->state( $this->actor['chef'], $this->id['next'], $this->id['home'] )['teams'][0]['captain']['name'], 'Captain der aktiven Saison gehört zum Club' );
	}

	public function test_submitting_needs_a_registration_without_violation(): void {
		$this->registration->confirm_player( $this->actor['chef'], $this->id['next'], $this->id['home'], $this->id['p_vorlage'] );
		$team = $this->registration->add_team( $this->actor['chef'], $this->id['next'], $this->id['home'] );

		$e = $this->refused( fn() => $this->registration->submit( $this->actor['chef'], $this->id['next'], $this->id['home'] ), Refusal::INVALID );
		self::assertContains( 'team.' . $team . '.players', array_column( $e->errors, 'field' ) );
		self::assertSame( 'open', $this->registration_status( $this->id['home'] ) );

		$this->registration->remove_team( $this->actor['chef'], $team );
		$this->complete_registration();
		$this->registration->submit( $this->actor['chef'], $this->id['next'], $this->id['home'] );

		$row = $this->db->get_row( "SELECT * FROM {$this->db->table( 'club_season' )} WHERE season_id = %d AND club_id = %d", array( $this->id['next'], $this->id['home'] ) );
		self::assertSame( 'submitted', $row['status'] );
		self::assertSame( '2027-05-10 10:00:00', $row['submitted_at'] );
		self::assertSame( '301', $row['submitted_by'] );
		self::assertSame( 1, $this->rows( 'audit_log', "entity = 'club_season' AND action = 'submitted'" ) );
		$this->refused( fn() => $this->registration->submit( $this->actor['chef'], $this->id['next'], $this->id['home'] ), Refusal::CONFLICT );
	}

	public function test_a_club_without_teams_may_submit_its_players(): void {
		$this->registration->confirm_player( $this->actor['wolli'], $this->id['next'], $this->id['away'], $this->id['p_gast'] );

		$this->registration->submit( $this->actor['wolli'], $this->id['next'], $this->id['away'] );

		self::assertSame( 'submitted', $this->registration_status( $this->id['away'] ) );
		self::assertSame( 'open', $this->registration_status( $this->id['home'] ) );
	}

	public function test_after_submitting_the_club_changes_nothing_but_the_tk_still_can(): void {
		$team = $this->complete_registration();
		$this->registration->submit( $this->actor['chef'], $this->id['next'], $this->id['home'] );
		$chef = $this->actor['chef'];
		$next = $this->id['next'];
		$home = $this->id['home'];

		$locked = array(
			'bestätigen'   => fn() => $this->registration->confirm_player( $chef, $next, $home, $this->id['p_vorlage'] ),
			'abmelden'     => fn() => $this->registration->withdraw_player( $chef, $next, $home, $this->id['p_muster'] ),
			'neu melden'   => fn() => $this->registration->request_player(
				$chef,
				$next,
				$home,
				array(
					'last_name'      => 'Neuling',
					'first_name'     => 'Noah',
					'classification' => 3,
				)
			),
			'Mannschaft +' => fn() => $this->registration->add_team( $chef, $next, $home ),
			'Mannschaft -' => fn() => $this->registration->remove_team( $chef, $team ),
			'Mannschaft'   => fn() => $this->registration->save_team( $chef, $team, array( 'players' => array() ) ),
			'Captain'      => fn() => $this->registration->set_captain( $chef, $team, $this->id['person_heim'] ),
		);
		foreach ( $locked as $what => $action ) {
			$e = $this->refused( $action, Refusal::FORBIDDEN );
			self::assertStringContainsString( 'eingereicht', $e->getMessage(), $what );
		}
		self::assertSame( 3, $this->rows( 'player_season', "season_id = {$next}" ) );
		self::assertSame( 3, $this->rows( 'team_roster', "team_id = {$team}" ) );

		$this->registration->confirm_player( $this->actor['tk'], $next, $home, $this->id['p_vorlage'] );
		$this->registration->set_captain( $this->actor['tk'], $team, $this->id['person_heim'] );
		self::assertSame( 4, $this->rows( 'player_season', "season_id = {$next}" ) );
	}

	/**
	 * @return iterable<string,array{string,bool}>
	 */
	public static function window_days(): iterable {
		yield 'am Tag vor dem Fenster' => array( '2027-04-30 21:59:00', false );
		yield 'am ersten Tag ab Mitternacht Ortszeit' => array( '2027-04-30 22:00:00', true );
		yield 'am letzten Tag kurz vor Mitternacht Ortszeit' => array( '2027-05-31 21:59:00', true );
		yield 'nach dem letzten Tag' => array( '2027-05-31 22:00:00', false );
	}

	#[DataProvider( 'window_days' )]
	public function test_the_club_registers_only_inside_the_window( string $utc, bool $open ): void {
		$this->at( $utc );
		$action = fn() => $this->registration->confirm_player( $this->actor['chef'], $this->id['next'], $this->id['home'], $this->id['p_muster'] );

		if ( $open ) {
			$action();
			self::assertNotNull( $this->player_season( $this->id['next'], $this->id['p_muster'] ) );
			return;
		}
		$e = $this->refused( $action, Refusal::FORBIDDEN );
		self::assertStringContainsString( 'Meldefenster', $e->getMessage() );
		self::assertNull( $this->player_season( $this->id['next'], $this->id['p_muster'] ) );
	}

	public function test_after_the_window_only_the_tk_changes_and_submits(): void {
		$this->complete_registration();
		$this->at( '2027-06-15 10:00:00' );

		$this->refused( fn() => $this->registration->submit( $this->actor['chef'], $this->id['next'], $this->id['home'] ), Refusal::FORBIDDEN );
		$this->refused( fn() => $this->registration->add_team( $this->actor['chef'], $this->id['next'], $this->id['home'] ), Refusal::FORBIDDEN );
		self::assertFalse( $this->registration->state( $this->actor['chef'], $this->id['next'], $this->id['home'] )['context']->can( 'players' ) );

		$this->registration->confirm_player( $this->actor['tk'], $this->id['next'], $this->id['home'], $this->id['p_vorlage'] );
		$this->registration->submit( $this->actor['tk'], $this->id['next'], $this->id['home'] );
		self::assertSame( 'submitted', $this->registration_status( $this->id['home'] ) );
	}

	public function test_without_a_window_the_club_cannot_register(): void {
		$this->db->update( 'season', array( 'registration_opens' => null ), array( 'id' => $this->id['next'] ) );

		$this->refused( fn() => $this->registration->confirm_player( $this->actor['chef'], $this->id['next'], $this->id['home'], $this->id['p_muster'] ), Refusal::FORBIDDEN );
	}

	public function test_rights_follow_the_role_in_the_season_of_the_registration(): void {
		$next   = $this->id['next'];
		$now    = $this->id['now'];
		$home   = $this->id['home'];
		$away   = $this->id['away'];
		$muster = $this->id['p_muster'];

		// Fremder Club, Person ohne Funktion, Konto ohne Person.
		$this->refused( fn() => $this->registration->confirm_player( $this->actor['wolli'], $next, $home, $muster ), Refusal::FORBIDDEN );
		$this->refused( fn() => $this->registration->state( $this->actor['wolli'], $next, $home ), Refusal::FORBIDDEN );
		$this->refused( fn() => $this->registration->confirm_player( $this->actor['niemand'], $next, $home, $muster ), Refusal::FORBIDDEN );
		$this->refused( fn() => $this->registration->submit( $this->actor['niemand'], $next, $home ), Refusal::FORBIDDEN );

		// Alma Alt ist nur in der aktiven Saison TK-Chefin von Seefeld: keine Meldung für die geplante.
		$this->refused( fn() => $this->registration->confirm_player( $this->actor['alt'], $next, $home, $muster ), Refusal::FORBIDDEN );
		// Nina Neu ist nur in der geplanten Saison TK-Chefin von Wollishofen: Meldung ja, Nachmeldung nein.
		$this->registration->confirm_player( $this->actor['neu'], $next, $away, $this->id['p_gast'] );
		$this->at( '2026-12-01 10:00:00' );
		$this->refused( fn() => $this->registration->register_license( $this->actor['neu'], $now, $away, 90001 ), Refusal::FORBIDDEN );

		self::assertSame( 1, $this->rows( 'player_season', "season_id = {$next}" ) );
	}

	public function test_a_club_without_registration_row_does_not_take_part(): void {
		$this->db->delete(
			'club_season',
			array(
				'season_id' => $this->id['next'],
				'club_id'   => $this->id['home'],
			)
		);

		$this->refused( fn() => $this->registration->confirm_player( $this->actor['chef'], $this->id['next'], $this->id['home'], $this->id['p_muster'] ), Refusal::NOT_FOUND );
		$this->refused( fn() => $this->registration->confirm_player( $this->actor['tk'], $this->id['next'], $this->id['home'], $this->id['p_muster'] ), Refusal::NOT_FOUND );
		$this->refused( fn() => $this->registration->state( $this->actor['tk'], 999, $this->id['home'] ), Refusal::NOT_FOUND );
	}

	/**
	 * @return iterable<string,array{string,bool}>
	 */
	public static function late_days(): iterable {
		yield 'mitten in der Saison' => array( '2026-12-01 10:00:00', true );
		yield 'am letzten Tag kurz vor Mitternacht Ortszeit' => array( '2027-01-31 22:59:00', true );
		yield 'am Tag danach' => array( '2027-01-31 23:00:00', false );
	}

	#[DataProvider( 'late_days' )]
	public function test_late_registration_in_the_active_season_ends_with_the_deadline( string $utc, bool $open ): void {
		$this->at( $utc );
		$license = (int) $this->db->get_var( "SELECT license_no FROM {$this->db->table( 'player' )} WHERE id = %d", array( $this->id['p_ehemalig'] ) );
		$action  = fn() => $this->registration->register_license( $this->actor['chef'], $this->id['now'], $this->id['home'], $license );

		if ( ! $open ) {
			$e = $this->refused( $action, Refusal::FORBIDDEN );
			self::assertStringContainsString( 'Frist für Nachmeldungen', $e->getMessage() );
			self::assertNull( $this->player_season( $this->id['now'], $this->id['p_ehemalig'] ) );
			return;
		}
		$action();
		$row = $this->player_season( $this->id['now'], $this->id['p_ehemalig'] );
		self::assertSame( 'provisional', $row['status'], 'spielberechtigt erst nach Freigabe' );
		self::assertSame( '4', $row['classification'] );
	}

	public function test_without_a_date_late_registration_ends_on_january_31st(): void {
		$this->db->update( 'season', array( 'late_registration_until' => null ), array( 'id' => $this->id['now'] ) );
		$license = (int) $this->db->get_var( "SELECT license_no FROM {$this->db->table( 'player' )} WHERE id = %d", array( $this->id['p_ehemalig'] ) );

		$this->at( '2027-02-01 10:00:00' );
		$this->refused( fn() => $this->registration->register_license( $this->actor['chef'], $this->id['now'], $this->id['home'], $license ), Refusal::FORBIDDEN );
		$this->at( '2027-01-31 10:00:00' );
		$this->registration->register_license( $this->actor['chef'], $this->id['now'], $this->id['home'], $license );
		self::assertNotNull( $this->player_season( $this->id['now'], $this->id['p_ehemalig'] ) );
	}

	public function test_after_the_deadline_the_tk_registers_and_that_is_the_approval(): void {
		$this->at( '2027-03-01 10:00:00' );

		$this->registration->confirm_player( $this->actor['tk'], $this->id['now'], $this->id['home'], $this->id['p_ehemalig'] );

		$row = $this->player_season( $this->id['now'], $this->id['p_ehemalig'] );
		self::assertSame( 'active', $row['status'] );
		self::assertSame( '303', $row['approved_by'] );
		self::assertSame( '2027-03-01 10:00:00', $row['approved_at'] );
	}

	public function test_in_the_active_season_only_a_pending_late_registration_can_be_withdrawn(): void {
		$this->at( '2026-12-01 10:00:00' );
		$this->registration->confirm_player( $this->actor['chef'], $this->id['now'], $this->id['home'], $this->id['p_ehemalig'] );

		$this->refused( fn() => $this->registration->withdraw_player( $this->actor['chef'], $this->id['now'], $this->id['home'], $this->id['p_muster'] ), Refusal::CONFLICT );
		$this->refused( fn() => $this->registration->withdraw_player( $this->actor['tk'], $this->id['now'], $this->id['home'], $this->id['p_muster'] ), Refusal::CONFLICT );
		$this->registration->withdraw_player( $this->actor['chef'], $this->id['now'], $this->id['home'], $this->id['p_ehemalig'] );

		self::assertNotNull( $this->player_season( $this->id['now'], $this->id['p_muster'] ) );
		self::assertNull( $this->player_season( $this->id['now'], $this->id['p_ehemalig'] ) );
	}

	public function test_in_the_active_season_the_club_changes_the_captain_but_not_the_teams(): void {
		$this->at( '2027-03-01 10:00:00' );
		$chef   = $this->actor['chef'];
		$team   = $this->id['team'];
		$muster = $this->seed->person_of( $this->id['p_muster'] );

		$this->registration->set_captain( $chef, $team, $muster );

		self::assertSame(
			array( (string) $muster ),
			$this->db->get_col( "SELECT person_id FROM {$this->db->table( 'club_role' )} WHERE season_id = %d AND role = 'captain' AND team_id = %d", array( $this->id['now'], $team ) ),
			'gilt sofort, der bisherige Captain ist ersetzt'
		);
		self::assertSame( 1, $this->rows( 'audit_log', "entity = 'team' AND action = 'captain' AND entity_id = {$team}" ) );
		$this->refused( fn() => $this->registration->set_captain( $chef, $team, null ), Refusal::INVALID );
		$this->refused( fn() => $this->registration->set_captain( $this->actor['wolli'], $team, $muster ), Refusal::FORBIDDEN );

		$this->refused( fn() => $this->registration->save_team( $chef, $team, array( 'players' => array( $this->id['p_muster'], $this->id['p_beispiel'], $this->id['p_vorlage'] ) ) ), Refusal::FORBIDDEN );
		$this->refused( fn() => $this->registration->add_team( $chef, $this->id['now'], $this->id['home'] ), Refusal::FORBIDDEN );
		$this->refused( fn() => $this->registration->add_team( $this->actor['tk'], $this->id['now'], $this->id['home'] ), Refusal::FORBIDDEN );
		$this->refused( fn() => $this->registration->remove_team( $this->actor['tk'], $team ), Refusal::FORBIDDEN );
		$this->refused( fn() => $this->registration->submit( $this->actor['tk'], $this->id['now'], $this->id['home'] ), Refusal::CONFLICT );
	}

	public function test_in_the_active_season_the_tk_changes_regular_players_only_to_a_valid_roster(): void {
		$tk   = $this->actor['tk'];
		$team = $this->id['team'];
		$this->db->insert(
			'team_roster',
			array(
				'team_id'    => $team,
				'player_id'  => $this->id['p_vorlage'],
				'kind'       => 'bound',
				'valid_from' => '2026-11-03',
			)
		);
		$data = array(
			'home_venue_id' => $this->id['venue'],
			'start_time'    => '19:45',
		);

		$e = $this->refused( fn() => $this->registration->save_team( $tk, $team, array( 'players' => array( $this->id['p_muster'], $this->id['p_beispiel'] ) ) + $data ), Refusal::INVALID );
		self::assertStringContainsString( 'genau 3 Stammspieler', $e->getMessage() );
		self::assertSame( 3, $this->rows( 'team_roster', "team_id = {$team} AND kind = 'stamm'" ) );

		$this->registration->save_team( $tk, $team, array( 'players' => array( $this->id['p_muster'], $this->id['p_beispiel'], $this->id['p_vorlage'] ) ) + $data );

		self::assertSame(
			array( (string) $this->id['p_muster'], (string) $this->id['p_beispiel'], (string) $this->id['p_vorlage'] ),
			$this->db->get_col( "SELECT player_id FROM {$this->db->table( 'team_roster' )} WHERE team_id = %d AND kind = 'stamm' ORDER BY player_id", array( $team ) )
		);
		self::assertSame( 0, $this->rows( 'team_roster', "team_id = {$team} AND kind = 'bound'" ), 'die Bindung ist zur Stammspielerin geworden' );
		self::assertSame( '19:45:00', $this->db->get_var( "SELECT default_start_time FROM {$this->db->table( 'team' )} WHERE id = %d", array( $team ) ) );
		self::assertSame( 1, $this->rows( 'club_role', "role = 'captain' AND team_id = {$team}" ), 'ohne Angabe bleibt der Captain' );
	}

	public function test_nobody_changes_an_archived_season(): void {
		$license = (int) $this->db->get_var( "SELECT license_no FROM {$this->db->table( 'player' )} WHERE id = %d", array( $this->id['p_gast'] ) );
		$this->seed->role( $this->id['old'], $this->id['home'], $this->id['person_chef'], 'tk_chef' );

		$this->refused( fn() => $this->registration->register_license( $this->actor['chef'], $this->id['old'], $this->id['home'], $license ), Refusal::FORBIDDEN );
		$e = $this->refused( fn() => $this->registration->register_license( $this->actor['tk'], $this->id['old'], $this->id['home'], $license ), Refusal::FORBIDDEN );
		self::assertStringContainsString( 'abgeschlossen', $e->getMessage() );
		self::assertNull( $this->player_season( $this->id['old'], $this->id['p_gast'] ) );
	}
}
