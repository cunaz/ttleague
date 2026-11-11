<?php
/**
 * Integrationstests der Prüfung durch die TK gegen MariaDB.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Tests\Integration\Registration;

use TTLeague\Registration\Decision;
use TTLeague\Registration\RegistrationException as Refusal;

/**
 * Daten siehe RegistrationTestCase.
 */
final class ReviewTest extends RegistrationTestCase {

	/**
	 * Neumeldung von Seefeld für die geplante Saison.
	 *
	 * @param array<string,mixed> $change Abweichende Angaben.
	 */
	private function request( array $change = array() ): int {
		return $this->registration->request_player(
			$this->actor['chef'],
			$this->id['next'],
			$this->id['home'],
			$change + array(
				'last_name'      => 'Neuling',
				'first_name'     => 'Noah',
				'email'          => 'noah.neuling@example.invalid',
				'classification' => 3,
				'stt_license'    => '123456',
			)
		);
	}

	/**
	 * @return array<string,string|null>
	 */
	private function request_row( int $id ): array {
		return (array) $this->db->get_row( "SELECT * FROM {$this->db->table( 'player_request' )} WHERE id = %d", array( $id ) );
	}

	public function test_approving_a_request_creates_person_player_and_registration(): void {
		$request = $this->request();
		$persons = $this->rows( 'person' );
		$players = $this->rows( 'player' );
		$this->at( '2027-05-12 08:00:00' );

		$player_id = $this->review->approve_request(
			$this->actor['tk'],
			$request,
			array(
				'license_no'     => 95000,
				'classification' => 4,
			)
		);

		self::assertSame( $persons + 1, $this->rows( 'person' ) );
		self::assertSame( $players + 1, $this->rows( 'player' ) );
		$player = $this->db->get_row(
			"SELECT p.license_no, p.stt_license, pe.last_name, pe.first_name, pe.email, pe.status FROM {$this->db->table( 'player' )} p JOIN {$this->db->table( 'person' )} pe ON pe.id = p.person_id WHERE p.id = %d",
			array( $player_id )
		);
		self::assertSame(
			array(
				'license_no'  => '95000',
				'stt_license' => '123456',
				'last_name'   => 'Neuling',
				'first_name'  => 'Noah',
				'email'       => 'noah.neuling@example.invalid',
				'status'      => 'active',
			),
			$player
		);
		$season = $this->player_season( $this->id['next'], $player_id );
		self::assertSame( (string) $this->id['home'], $season['club_id'] );
		self::assertSame( '4', $season['classification'], 'die TK kann die Klassierung ändern' );
		self::assertSame( 'active', $season['status'] );
		self::assertSame( '301', $season['registered_by'] );
		self::assertSame( '303', $season['approved_by'] );
		self::assertSame( '2027-05-12 08:00:00', $season['approved_at'] );
		$row = $this->request_row( $request );
		self::assertSame( 'approved', $row['status'] );
		self::assertSame( (string) $player_id, $row['player_id'] );
		self::assertSame( '303', $row['decided_by'] );
		self::assertSame( 1, $this->rows( 'audit_log', "entity = 'player_request' AND action = 'approved' AND user_id = 303" ) );

		self::assertCount( 1, $this->events->decisions );
		$decision = $this->events->decisions[0];
		self::assertSame( Decision::REQUEST_APPROVED, $decision->kind );
		self::assertSame( 'Noah Neuling', $decision->subject );
		self::assertSame( 'TTC Seefeld', $decision->club_name );
		self::assertSame( '2027/28', $decision->season_name );
		self::assertSame( 303, $decision->user_id );

		$e = $this->refused( fn() => $this->review->approve_request( $this->actor['tk'], $request, array( 'license_no' => 95001 ) ), Refusal::CONFLICT );
		self::assertStringContainsString( 'schon entschieden', $e->getMessage() );
		self::assertSame( $players + 1, $this->rows( 'player' ) );
	}

	public function test_without_changes_the_classification_of_the_club_applies(): void {
		$request = $this->request();

		$player_id = $this->review->approve_request( $this->actor['tk'], $request, array( 'license_no' => 95000 ) );

		self::assertSame( '3', $this->player_season( $this->id['next'], $player_id )['classification'] );
	}

	public function test_the_license_number_is_required_and_unique_and_the_next_free_one_is_suggested(): void {
		$request = $this->request();
		$players = $this->rows( 'player' );
		$highest = (int) $this->db->get_var( "SELECT MAX(license_no) FROM {$this->db->table( 'player' )}" );

		self::assertSame( $highest + 1, $this->review->suggestions( $this->actor['tk'], $request )['license_no'] );
		$e = $this->refused( fn() => $this->review->approve_request( $this->actor['tk'], $request, array() ), Refusal::INVALID );
		self::assertSame( 'license_no', $e->errors[0]['field'] );
		$e = $this->refused( fn() => $this->review->approve_request( $this->actor['tk'], $request, array( 'license_no' => $highest ) ), Refusal::INVALID );
		self::assertSame( 'license_no', $e->errors[0]['field'] );
		self::assertStringContainsString( 'Die nächste freie ist ' . ( $highest + 1 ), $e->getMessage() );
		$e = $this->refused(
			fn() => $this->review->approve_request(
				$this->actor['tk'],
				$request,
				array(
					'license_no'     => $highest + 1,
					'classification' => 0,
				)
			),
			Refusal::INVALID
		);
		self::assertSame( 'classification', $e->errors[0]['field'] );

		self::assertSame( $players, $this->rows( 'player' ) );
		self::assertSame( 'requested', $this->request_row( $request )['status'] );
		self::assertSame( array(), $this->events->decisions );
	}

	public function test_persons_with_the_same_name_or_email_are_suggested(): void {
		$namesake = $this->seed->person( 'neuling', 'NOAH' );
		$this->db->update( 'person', array( 'email' => 'noah.neuling@example.invalid' ), array( 'id' => $this->id['person_niemand'] ) );
		$request = $this->request();

		$persons = $this->review->suggestions( $this->actor['tk'], $request )['persons'];

		self::assertSame( array( $this->id['person_niemand'], $namesake ), array_column( $persons, 'person_id' ) );
		self::assertSame( array( true, false ), array_column( $persons, 'same_email' ) );
		self::assertSame( array( null, null ), array_column( $persons, 'license_no' ) );
		self::assertSame(
			array(),
			$this->review->suggestions(
				$this->actor['tk'],
				$this->request(
					array(
						'last_name' => 'Einmalig',
						'email'     => '',
					)
				)
			)['persons']
		);
	}

	public function test_approving_with_an_existing_person_creates_no_second_person(): void {
		// Hanna Heim ist Captain, aber bisher keine Spielerin.
		$request = $this->request(
			array(
				'last_name'  => 'Heim',
				'first_name' => 'Hanna',
				'email'      => 'hanna.privat@example.invalid',
			)
		);
		$persons = $this->rows( 'person' );
		$players = $this->rows( 'player' );
		self::assertSame( array( $this->id['person_heim'] ), array_column( $this->review->suggestions( $this->actor['tk'], $request )['persons'], 'person_id' ) );

		$player_id = $this->review->approve_request(
			$this->actor['tk'],
			$request,
			array(
				'license_no' => 95000,
				'person_id'  => $this->id['person_heim'],
			)
		);

		self::assertSame( $persons, $this->rows( 'person' ), 'keine zweite Person' );
		self::assertSame( $players + 1, $this->rows( 'player' ) );
		self::assertSame( (string) $this->id['person_heim'], $this->db->get_var( "SELECT person_id FROM {$this->db->table( 'player' )} WHERE id = %d", array( $player_id ) ) );
		self::assertSame( 'heim@example.invalid', $this->db->get_var( "SELECT email FROM {$this->db->table( 'person' )} WHERE id = %d", array( $this->id['person_heim'] ) ), 'die Adresse der bestehenden Person bleibt' );
		self::assertSame( 'active', $this->player_season( $this->id['next'], $player_id )['status'] );
	}

	public function test_approving_with_a_person_who_already_has_a_license_creates_no_second_player(): void {
		$request = $this->request(
			array(
				'last_name'  => 'Ehemalig',
				'first_name' => 'Erika',
				'email'      => '',
			)
		);
		$players = $this->rows( 'player' );
		$person  = $this->seed->person_of( $this->id['p_ehemalig'] );
		$found   = $this->review->suggestions( $this->actor['tk'], $request )['persons'];
		self::assertSame( array( $this->id['p_ehemalig'] ), array_column( $found, 'player_id' ) );

		$player_id = $this->review->approve_request( $this->actor['tk'], $request, array( 'person_id' => $person ) );

		self::assertSame( $this->id['p_ehemalig'], $player_id );
		self::assertSame( $players, $this->rows( 'player' ), 'kein zweiter Spieler, keine zweite Lizenznummer' );
		self::assertSame( 'active', $this->player_season( $this->id['next'], $player_id )['status'] );
		self::assertSame( (string) $player_id, $this->request_row( $request )['player_id'] );
	}

	public function test_a_person_registered_for_another_club_cannot_be_approved(): void {
		$this->registration->confirm_player( $this->actor['wolli'], $this->id['next'], $this->id['away'], $this->id['p_gast'] );
		$request = $this->request(
			array(
				'last_name'  => 'Gast',
				'first_name' => 'Gustav',
				'email'      => '',
			)
		);

		$e = $this->refused( fn() => $this->review->approve_request( $this->actor['tk'], $request, array( 'person_id' => $this->seed->person_of( $this->id['p_gast'] ) ) ), Refusal::CONFLICT );

		self::assertStringContainsString( 'schon für Wollishofen gemeldet', $e->getMessage() );
		self::assertSame( 'requested', $this->request_row( $request )['status'] );
		self::assertSame( (string) $this->id['away'], $this->player_season( $this->id['next'], $this->id['p_gast'] )['club_id'] );
	}

	public function test_an_email_address_of_another_person_blocks_the_approval_until_the_tk_decides(): void {
		$request = $this->request( array( 'email' => 'chef@example.invalid' ) );
		$persons = $this->rows( 'person' );

		$e = $this->refused( fn() => $this->review->approve_request( $this->actor['tk'], $request, array( 'license_no' => 95000 ) ), Refusal::INVALID );
		self::assertSame( 'email', $e->errors[0]['field'] );
		self::assertStringContainsString( 'gehört schon zu Charlotte Chef', $e->getMessage() );
		self::assertSame( $persons, $this->rows( 'person' ) );

		$player_id = $this->review->approve_request(
			$this->actor['tk'],
			$request,
			array(
				'license_no' => 95000,
				'email'      => '',
			)
		);
		self::assertNull( $this->db->get_var( "SELECT pe.email FROM {$this->db->table( 'player' )} p JOIN {$this->db->table( 'person' )} pe ON pe.id = p.person_id WHERE p.id = %d", array( $player_id ) ) );
		self::assertSame( $persons + 1, $this->rows( 'person' ) );
	}

	public function test_rejecting_a_request_needs_a_note(): void {
		$request = $this->request();

		$e = $this->refused( fn() => $this->review->reject_request( $this->actor['tk'], $request, '  ' ), Refusal::INVALID );
		self::assertSame( 'note', $e->errors[0]['field'] );
		self::assertSame( 'requested', $this->request_row( $request )['status'] );

		$this->review->reject_request( $this->actor['tk'], $request, 'Bitte mit dem richtigen Namen melden.' );

		$row = $this->request_row( $request );
		self::assertSame( 'rejected', $row['status'] );
		self::assertSame( 'Bitte mit dem richtigen Namen melden.', $row['note'] );
		self::assertNull( $row['player_id'] );
		self::assertSame( Decision::REQUEST_REJECTED, $this->events->decisions[0]->kind );
		self::assertSame( 'Bitte mit dem richtigen Namen melden.', $this->events->decisions[0]->note );
		$this->refused( fn() => $this->review->approve_request( $this->actor['tk'], $request, array( 'license_no' => 95000 ) ), Refusal::CONFLICT );
		$this->refused( fn() => $this->registration->cancel_request( $this->actor['chef'], $request ), Refusal::CONFLICT );
	}

	public function test_approving_the_registration_activates_all_players_of_the_club(): void {
		$this->complete_registration();
		$this->registration->confirm_player( $this->actor['wolli'], $this->id['next'], $this->id['away'], $this->id['p_gast'] );
		$this->registration->submit( $this->actor['chef'], $this->id['next'], $this->id['home'] );
		$this->at( '2027-05-20 09:00:00' );

		$this->review->approve( $this->actor['tk'], $this->id['next'], $this->id['home'] );

		$row = $this->db->get_row( "SELECT * FROM {$this->db->table( 'club_season' )} WHERE season_id = %d AND club_id = %d", array( $this->id['next'], $this->id['home'] ) );
		self::assertSame( 'reviewed', $row['status'] );
		self::assertSame( '2027-05-20 09:00:00', $row['reviewed_at'] );
		self::assertSame( '303', $row['reviewed_by'] );
		self::assertSame( 3, $this->rows( 'player_season', "season_id = {$this->id['next']} AND club_id = {$this->id['home']} AND status = 'active' AND approved_by = 303" ) );
		self::assertSame( 'provisional', $this->player_season( $this->id['next'], $this->id['p_gast'] )['status'], 'der andere Club bleibt unberührt' );
		self::assertSame( Decision::REGISTRATION_APPROVED, $this->events->decisions[0]->kind );
		self::assertSame( 1, $this->rows( 'audit_log', "entity = 'club_season' AND action = 'reviewed'" ) );
		$this->refused( fn() => $this->review->approve( $this->actor['tk'], $this->id['next'], $this->id['home'] ), Refusal::CONFLICT );
		$e = $this->refused( fn() => $this->registration->confirm_player( $this->actor['chef'], $this->id['next'], $this->id['home'], $this->id['p_vorlage'] ), Refusal::FORBIDDEN );
		self::assertStringContainsString( 'geprüft', $e->getMessage() );

		// Was die TK nach der Prüfung selbst meldet, ist freigegeben.
		$this->registration->confirm_player( $this->actor['tk'], $this->id['next'], $this->id['home'], $this->id['p_vorlage'] );
		self::assertSame( 'active', $this->player_season( $this->id['next'], $this->id['p_vorlage'] )['status'] );
	}

	public function test_a_registration_with_violations_or_open_requests_is_not_approved(): void {
		$team = $this->complete_registration();
		$this->registration->save_team( $this->actor['chef'], $team, array( 'players' => array( $this->id['p_muster'] ) ) );

		$e = $this->refused( fn() => $this->review->approve( $this->actor['tk'], $this->id['next'], $this->id['home'] ), Refusal::INVALID );
		self::assertContains( 'team.' . $team . '.players', array_column( $e->errors, 'field' ) );

		$this->registration->save_team(
			$this->actor['chef'],
			$team,
			array(
				'players'       => array( $this->id['p_muster'], $this->id['p_beispiel'], $this->id['p_probe'] ),
				'home_venue_id' => $this->id['venue'],
				'start_time'    => '20:00',
			)
		);
		$request = $this->request();
		$e       = $this->refused( fn() => $this->review->approve( $this->actor['tk'], $this->id['next'], $this->id['home'] ), Refusal::CONFLICT );
		self::assertStringContainsString( 'offene Neumeldungen', $e->getMessage() );
		self::assertSame( 'open', $this->registration_status( $this->id['home'] ) );
		self::assertSame( 0, $this->rows( 'player_season', "season_id = {$this->id['next']} AND status = 'active'" ) );

		$this->review->reject_request( $this->actor['tk'], $request, 'Doppelt gemeldet.' );
		$this->review->approve( $this->actor['tk'], $this->id['next'], $this->id['home'] );
		self::assertSame( 'reviewed', $this->registration_status( $this->id['home'] ), 'die TK gibt auch eine nicht eingereichte Meldung frei' );
	}

	public function test_approval_applies_the_classification_decided_in_the_meantime(): void {
		$this->complete_registration();
		$this->registration->confirm_player( $this->actor['tk'], $this->id['next'], $this->id['home'], $this->id['p_vorlage'], 12 );
		$this->registration->submit( $this->actor['chef'], $this->id['next'], $this->id['home'] );
		foreach ( array(
			'p_muster'  => 9,
			'p_vorlage' => 6,
		) as $key => $decided ) {
			$this->db->insert(
				'classification_proposal',
				array(
					'season_id'     => $this->id['now'],
					'player_id'     => $this->id[ $key ],
					'current_value' => 8,
					'rating'        => 900.0,
					'proposed'      => $decided,
					'decided'       => $decided,
				)
			);
		}
		$this->db->update( 'season', array( 'classification_closed_at' => '2027-06-20 18:00:00' ), array( 'id' => $this->id['now'] ) );

		$this->review->approve( $this->actor['tk'], $this->id['next'], $this->id['home'] );

		self::assertSame( '9', $this->player_season( $this->id['next'], $this->id['p_muster'] )['classification'], 'beim Bestätigen galt noch 8' );
		self::assertSame( '7', $this->player_season( $this->id['next'], $this->id['p_beispiel'] )['classification'] );
		self::assertSame( '12', $this->player_season( $this->id['next'], $this->id['p_vorlage'] )['classification'], 'ein Entscheid der TK bleibt' );
	}

	public function test_returning_reopens_the_registration_with_a_note(): void {
		$this->complete_registration();
		$this->refused( fn() => $this->review->return_to_club( $this->actor['tk'], $this->id['next'], $this->id['home'], 'Noch nicht eingereicht.' ), Refusal::CONFLICT );
		$this->registration->submit( $this->actor['chef'], $this->id['next'], $this->id['home'] );

		$e = $this->refused( fn() => $this->review->return_to_club( $this->actor['tk'], $this->id['next'], $this->id['home'], '' ), Refusal::INVALID );
		self::assertSame( 'note', $e->errors[0]['field'] );
		self::assertSame( 'submitted', $this->registration_status( $this->id['home'] ) );

		$this->review->return_to_club( $this->actor['tk'], $this->id['next'], $this->id['home'], 'Bitte Vera Vorlage noch melden.' );

		self::assertSame( 'open', $this->registration_status( $this->id['home'] ) );
		$state = $this->registration->state( $this->actor['chef'], $this->id['next'], $this->id['home'] );
		self::assertSame( 'Bitte Vera Vorlage noch melden.', $state['context']->club_season['note'] );
		self::assertSame( Decision::REGISTRATION_RETURNED, $this->events->decisions[0]->kind );
		self::assertSame( 'Bitte Vera Vorlage noch melden.', $this->events->decisions[0]->note );

		// Der Club kann wieder ändern und erneut einreichen.
		$this->registration->confirm_player( $this->actor['chef'], $this->id['next'], $this->id['home'], $this->id['p_vorlage'] );
		$this->registration->submit( $this->actor['chef'], $this->id['next'], $this->id['home'] );
		$this->review->approve( $this->actor['tk'], $this->id['next'], $this->id['home'] );
		self::assertNull( $this->db->get_var( "SELECT note FROM {$this->db->table( 'club_season' )} WHERE season_id = %d AND club_id = %d", array( $this->id['next'], $this->id['home'] ) ) );

		// Auch eine geprüfte Meldung lässt sich wieder öffnen.
		$this->review->return_to_club( $this->actor['tk'], $this->id['next'], $this->id['home'], 'Mannschaft 2 fehlt.' );
		self::assertSame( 'open', $this->registration_status( $this->id['home'] ) );
		self::assertSame( 4, $this->rows( 'player_season', "season_id = {$this->id['next']} AND status = 'active'" ), 'freigegebene Spieler bleiben freigegeben' );
	}

	public function test_the_tk_approves_or_rejects_a_late_registration(): void {
		$this->at( '2026-12-01 10:00:00' );
		$now  = $this->id['now'];
		$home = $this->id['home'];
		$this->registration->confirm_player( $this->actor['chef'], $now, $home, $this->id['p_ehemalig'] );
		self::assertSame( array( 'Erika Ehemalig' ), array_column( $this->review->pending( $this->actor['tk'] )['players'], 'name' ) );

		$e = $this->refused( fn() => $this->review->reject_player( $this->actor['tk'], $now, $home, $this->id['p_ehemalig'], '' ), Refusal::INVALID );
		self::assertSame( 'note', $e->errors[0]['field'] );
		$this->refused( fn() => $this->review->approve_player( $this->actor['tk'], $now, $home, $this->id['p_muster'] ), Refusal::CONFLICT );
		$this->refused( fn() => $this->review->approve_player( $this->actor['tk'], $now, $this->id['away'], $this->id['p_ehemalig'] ), Refusal::NOT_FOUND );

		$this->review->approve_player( $this->actor['tk'], $now, $home, $this->id['p_ehemalig'] );

		$row = $this->player_season( $now, $this->id['p_ehemalig'] );
		self::assertSame( 'active', $row['status'] );
		self::assertSame( '303', $row['approved_by'] );
		self::assertSame( Decision::PLAYER_APPROVED, $this->events->decisions[0]->kind );
		self::assertSame( 'Erika Ehemalig', $this->events->decisions[0]->subject );
		self::assertSame( array(), $this->review->pending( $this->actor['tk'] )['players'] );
		$this->refused( fn() => $this->review->reject_player( $this->actor['tk'], $now, $home, $this->id['p_ehemalig'], 'Zu spät.' ), Refusal::CONFLICT );

		$this->db->update( 'player_season', array( 'status' => 'provisional' ), array( 'id' => (int) $row['id'] ) );
		$this->review->reject_player( $this->actor['tk'], $now, $home, $this->id['p_ehemalig'], 'Die Lizenz ist gesperrt.' );
		self::assertNull( $this->player_season( $now, $this->id['p_ehemalig'] ) );
		self::assertSame( 1, $this->rows( 'audit_log', "entity = 'player_season' AND action = 'rejected' AND note = 'Die Lizenz ist gesperrt.'" ) );
		self::assertSame( Decision::PLAYER_REJECTED, $this->events->decisions[1]->kind );
	}

	public function test_pending_lists_what_the_tk_has_to_review(): void {
		$this->complete_registration();
		$this->request();
		$this->registration->submit( $this->actor['chef'], $this->id['next'], $this->id['home'] );
		$this->registration->confirm_player( $this->actor['wolli'], $this->id['next'], $this->id['away'], $this->id['p_gast'] );

		$pending = $this->review->pending( $this->actor['tk'] );

		self::assertSame( array( 'Seefeld' ), array_column( $pending['submitted'], 'short_name' ) );
		self::assertSame( array( 'Noah Neuling' ), array_column( $pending['requests'], 'name' ) );
		self::assertSame( array( '2027/28' ), array_column( $pending['requests'], 'season' ) );
		self::assertSame( array(), $pending['players'], 'provisorische Spieler der geplanten Saison gehören zur Meldung' );

		$overview = array_column( $this->review->overview( $this->actor['tk'], $this->id['next'] ), null, 'short_name' );
		self::assertSame( 'submitted', $overview['Seefeld']['status'] );
		self::assertSame( 3, $overview['Seefeld']['players'] );
		self::assertSame( 1, $overview['Seefeld']['teams'] );
		self::assertSame( 1, $overview['Seefeld']['requests'] );
		self::assertSame( 'open', $overview['Wollishofen']['status'] );
		self::assertSame( 1, $overview['Wollishofen']['provisional'] );
	}

	public function test_only_the_tk_reviews(): void {
		$this->complete_registration();
		$request = $this->request();
		$this->registration->submit( $this->actor['chef'], $this->id['next'], $this->id['home'] );
		$chef = $this->actor['chef'];
		$next = $this->id['next'];
		$home = $this->id['home'];

		$this->refused( fn() => $this->review->approve( $chef, $next, $home ), Refusal::FORBIDDEN );
		$this->refused( fn() => $this->review->return_to_club( $chef, $next, $home, 'Nochmals öffnen.' ), Refusal::FORBIDDEN );
		$this->refused( fn() => $this->review->approve_request( $chef, $request, array( 'license_no' => 95000 ) ), Refusal::FORBIDDEN );
		$this->refused( fn() => $this->review->reject_request( $chef, $request, 'Nein.' ), Refusal::FORBIDDEN );
		$this->refused( fn() => $this->review->approve_player( $chef, $next, $home, $this->id['p_muster'] ), Refusal::FORBIDDEN );
		$this->refused( fn() => $this->review->reject_player( $chef, $next, $home, $this->id['p_muster'], 'Nein.' ), Refusal::FORBIDDEN );
		$this->refused( fn() => $this->review->pending( $chef ), Refusal::FORBIDDEN );
		$this->refused( fn() => $this->review->overview( $chef, $next ), Refusal::FORBIDDEN );
		$this->refused( fn() => $this->review->suggestions( $chef, $request ), Refusal::FORBIDDEN );

		self::assertSame( 'submitted', $this->registration_status( $home ) );
		self::assertSame( 'requested', $this->request_row( $request )['status'] );
		self::assertSame( 3, $this->rows( 'player_season', "season_id = {$next} AND status = 'provisional'" ) );
	}

	public function test_requests_of_an_archived_season_are_closed(): void {
		$request = $this->request();
		$this->db->update( 'season', array( 'status' => 'archived' ), array( 'id' => $this->id['next'] ) );

		$this->refused( fn() => $this->review->approve_request( $this->actor['tk'], $request, array( 'license_no' => 95000 ) ), Refusal::CONFLICT );
		$this->refused( fn() => $this->review->approve( $this->actor['tk'], $this->id['next'], $this->id['home'] ), Refusal::CONFLICT );
	}
}
