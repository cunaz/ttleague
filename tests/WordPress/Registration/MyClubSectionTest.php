<?php
/**
 * Abschnitt «Meldung» in «Mein Bereich» und seine Formulare, mit echtem WordPress.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Tests\WordPress\Registration;

use TTLeague\Access\Capabilities;
use TTLeague\Accounts\LoginFlow;
use TTLeague\Accounts\MyAreaContext;
use TTLeague\Accounts\Services;
use TTLeague\Infrastructure\Cache\DataVersion;
use TTLeague\Infrastructure\Flash;
use TTLeague\Registration\Actions;
use TTLeague\Registration\MyClubSection;
use TTLeague\Registration\Registration;
use TTLeague\Results\Results;

/**
 * Daten siehe RegistrationWpTestCase.
 */
final class MyClubSectionTest extends RegistrationWpTestCase {

	private function section( string $user, string $club = 'club_home' ): string {
		wp_set_current_user( $this->user[ $user ] );
		return MyClubSection::section( $this->id[ $club ], Results::current_actor(), Flash::take( Registration::flash_scope( $this->id[ $club ] ), $this->user[ $user ] ) );
	}

	public function test_the_tk_chef_sees_the_registration_for_the_planned_season_with_forms(): void {
		$html = $this->section( 'chef' );

		self::assertStringContainsString( 'id="ttl-meldung-' . $this->id['club_home'] . '"', $html );
		self::assertStringContainsString( 'Meldung: TTC Seefeld', $html );
		self::assertStringContainsString( 'Meldung für die Saison 2027/28', $html );
		self::assertStringContainsString( 'Laufende Saison 2026/27: Nachmeldungen und Captains', $html );
		self::assertStringContainsString( 'Spielerinnen und Spieler der letzten Saison, noch nicht gemeldet (3)', $html );
		self::assertStringContainsString( 'Spieler1 Seefelder', $html );
		self::assertStringContainsString( 'Turnhalle Seefeld', $html );
		self::assertStringContainsString( 'action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"', $html );
		foreach ( array( Actions::CONFIRM, Actions::CONFIRM_ALL, Actions::LICENSE, Actions::REQUEST, Actions::TEAM_ADD, Actions::VENUE, Actions::CLOSURE_ADD, Actions::CAPTAIN ) as $action ) {
			self::assertStringContainsString( 'name="action" value="' . $action . '"', $html, $action );
		}
		self::assertStringContainsString( 'name="_wpnonce" value="' . wp_create_nonce( Actions::CONFIRM . '_' . $this->id['club_home'] ) . '"', $html );
		self::assertStringNotContainsString( 'name="back"', $html, 'zurück geht es in «Mein Bereich»' );
		self::assertStringNotContainsString( Actions::REQUEST_APPROVE, $html, 'Entscheide sieht nur die TK' );
		self::assertStringNotContainsString( 'Wollishofer', $html, 'keine Spieler anderer Clubs' );

		preg_match_all( '/\sid="([^"]+)"/', $html, $ids );
		self::assertSame( array_values( array_unique( $ids[1] ) ), $ids[1], 'jede ID kommt einmal vor' );
		preg_match_all( '/<label for="([^"]+)"/', $html, $labels );
		self::assertSame( array(), array_values( array_diff( $labels[1], $ids[1] ) ), 'jedes Label zeigt auf ein Feld' );
	}

	public function test_names_are_escaped(): void {
		$this->db->update( 'person', array( 'last_name' => 'Seefelder <script>alert(1)</script>' ), array( 'id' => $this->id['person_h1'] ) );
		$this->db->update( 'venue', array( 'name' => 'Halle "Nord" <b>' ), array( 'id' => $this->id['venue'] ) );

		$html = $this->section( 'chef' );

		self::assertStringNotContainsString( '<script>alert(1)</script>', $html );
		self::assertStringContainsString( 'Seefelder &lt;script&gt;alert(1)&lt;/script&gt;', $html );
		self::assertStringNotContainsString( '<b>', $html );
	}

	public function test_before_the_window_opens_only_the_active_season_is_shown(): void {
		$this->db->update( 'season', array( 'registration_opens' => self::day( '+1 day' ) ), array( 'id' => $this->id['next'] ) );

		$html = $this->section( 'chef' );

		self::assertStringNotContainsString( 'Meldung für die Saison 2027/28', $html );
		self::assertStringContainsString( 'Laufende Saison 2026/27: Nachmeldungen und Captains', $html );
		self::assertStringContainsString( 'name="action" value="' . Actions::LICENSE . '"', $html );
		self::assertStringNotContainsString( 'name="action" value="' . Actions::TEAM_ADD . '"', $html, 'Mannschaften der aktiven Saison ändert nur die TK' );

		$this->db->delete( 'season', array( 'id' => $this->id['next'] ) );
		Results::current_actor();
		self::assertStringNotContainsString( '2027/28', $this->section( 'chef' ), 'ohne geplante Saison' );
	}

	public function test_after_the_window_the_registration_stays_readable_without_forms(): void {
		$this->db->update(
			'season',
			array(
				'registration_opens'  => self::day( '-20 days' ),
				'registration_closes' => self::day( '-1 day' ),
			),
			array( 'id' => $this->id['next'] )
		);

		$html    = $this->section( 'chef' );
		$planned = substr( $html, 0, (int) strpos( $html, 'Laufende Saison' ) );

		self::assertStringContainsString( 'Meldung für die Saison 2027/28', $planned );
		self::assertStringContainsString( 'Das Meldefenster ist geschlossen', $planned );
		self::assertStringNotContainsString( '<form', $planned );
	}

	public function test_people_without_a_club_see_nothing_and_other_clubs_stay_hidden(): void {
		self::assertSame( '', $this->section( 'nobody' ) );
		self::assertSame( '', $this->section( 'wolli', 'club_home' ) );
		self::assertStringContainsString( 'Meldung: TTC Wollishofen', $this->section( 'wolli', 'club_away' ) );

		wp_set_current_user( $this->user['nobody'] );
		ob_start();
		do_action( 'ttleague_my_area_sections', new MyAreaContext( $this->user['nobody'], $this->id['nobody'], array(), $this->id['season'], Services::policy()->roles( $this->id['nobody'], $this->id['season'] ), false ) );
		self::assertStringNotContainsString( 'ttl-meldung-', (string) ob_get_clean() );
	}

	public function test_the_section_is_hooked_into_my_area(): void {
		wp_set_current_user( $this->user['chef'] );
		$person = $this->id['chef_home'];
		ob_start();
		do_action( 'ttleague_my_area_sections', new MyAreaContext( $this->user['chef'], $person, array(), $this->id['season'], Services::policy()->roles( $person, $this->id['season'] ), false ) );
		$html = (string) ob_get_clean();

		self::assertStringContainsString( 'Meldung: TTC Seefeld', $html );
		self::assertTrue( wp_style_is( MyClubSection::STYLE, 'enqueued' ) );
		self::assertLessThan( strpos( $html, 'ttl-meldung-' ), strpos( $html, 'ttl-mein-club-' ), 'nach «Mein Club»' );
	}

	public function test_confirming_a_player_saves_and_returns_to_my_area_with_a_message(): void {
		wp_set_current_user( $this->user['chef'] );
		$version = DataVersion::get();

		$target = $this->post( Actions::CONFIRM, $this->home( array( 'player' => (string) $this->id['h1'] ) ) );

		self::assertSame( LoginFlow::my_area_url() . '#ttl-meldung-' . $this->id['club_home'], $target );
		self::assertGreaterThan( $version, DataVersion::get(), 'abgeleitete Daten gelten als geändert' );
		self::assertSame( 1, (int) $this->db->get_var( "SELECT COUNT(*) FROM {$this->db->table( 'audit_log' )} WHERE entity = 'player_season' AND action = 'registered' AND user_id = %d", array( $this->user['chef'] ) ) );
		$row = $this->player_season( $this->id['next'], $this->id['h1'] );
		self::assertSame( 'provisional', $row['status'] ?? null );
		self::assertSame( '10', $row['classification'] );
		self::assertSame( (string) $this->user['chef'], $row['registered_by'] );
		$html = $this->section( 'chef' );
		self::assertStringContainsString( 'ttl-notice--success', $html );
		self::assertStringContainsString( 'Gemeldet.', $html );
		self::assertStringContainsString( 'Gemeldete Spielerinnen und Spieler (1)', $html );
		self::assertStringNotContainsString( 'ttl-notice--success', $this->section( 'chef' ), 'die Rückmeldung erscheint einmal' );
	}

	public function test_a_club_cannot_set_the_classification(): void {
		wp_set_current_user( $this->user['chef'] );

		$this->post(
			Actions::CONFIRM,
			$this->home(
				array(
					'player'         => (string) $this->id['h1'],
					'classification' => '20',
				)
			)
		);

		self::assertSame( '10', $this->player_season( $this->id['next'], $this->id['h1'] )['classification'] );
	}

	public function test_forms_need_the_nonce_of_action_and_club(): void {
		wp_set_current_user( $this->user['chef'] );
		$fields = $this->home( array( 'player' => (string) $this->id['h1'] ) );

		$this->denied( Actions::CONFIRM, $fields, 'falsch' );
		$this->denied( Actions::CONFIRM, $fields, wp_create_nonce( Actions::WITHDRAW . '_' . $this->id['club_home'] ) );
		$this->denied( Actions::CONFIRM, $fields, wp_create_nonce( Actions::CONFIRM . '_' . $this->id['club_away'] ) );

		self::assertNull( $this->player_season( $this->id['next'], $this->id['h1'] ) );
	}

	public function test_forms_need_the_right_to_manage_the_club(): void {
		$fields = $this->home( array( 'player' => (string) $this->id['h1'] ) );

		foreach ( array( 'wolli', 'nobody' ) as $user ) {
			wp_set_current_user( $this->user[ $user ] );
			$e = $this->denied( Actions::CONFIRM, $fields );
			self::assertStringContainsString( 'Dazu fehlt dir die Berechtigung', $e->getMessage(), $user );
			$this->denied( Actions::VENUE, $this->home( array( 'name' => 'Fremde Halle' ) ) );
			$this->denied( Actions::SUBMIT, $this->home() );
			$this->denied( Actions::TEAM_ADD, $this->home() );
		}
		wp_set_current_user( 0 );
		$this->denied( Actions::CONFIRM, $fields );

		self::assertNull( $this->player_season( $this->id['next'], $this->id['h1'] ) );
		self::assertSame( 0, (int) $this->db->get_var( "SELECT COUNT(*) FROM {$this->db->table( 'team' )} WHERE season_id = %d", array( $this->id['next'] ) ) );
		self::assertSame( 1, (int) $this->db->get_var( "SELECT COUNT(*) FROM {$this->db->table( 'venue' )}" ) );
	}

	public function test_the_club_field_does_not_open_objects_of_another_club(): void {
		// Wanda Wolli schickt den eigenen Club mit, aber Mannschaft, Lokal und Spieler von Seefeld.
		wp_set_current_user( $this->user['tk'] );
		$team = Registration::registration()->add_team( Results::current_actor(), $this->id['next'], $this->id['club_home'] );
		wp_set_current_user( $this->user['wolli'] );
		$own = array(
			'club'   => (string) $this->id['club_away'],
			'season' => (string) $this->id['next'],
		);

		$this->post( Actions::TEAM_REMOVE, $own + array( 'team' => (string) $team ) );
		$this->post(
			Actions::CAPTAIN,
			$own + array(
				'team'    => (string) $this->id['team_h1'],
				'captain' => (string) $this->id['person_wolli'],
			)
		);
		$this->post(
			Actions::VENUE,
			$own + array(
				'venue'        => (string) $this->id['venue'],
				'name'         => 'Umbenannt',
				'weekday'      => '2',
				'tables_count' => '3',
			)
		);
		$this->post(
			Actions::CLOSURE_ADD,
			$own + array(
				'venue'     => (string) $this->id['venue'],
				'date_from' => '2027-10-04',
				'date_to'   => '2027-10-08',
			)
		);

		self::assertSame( 1, (int) $this->db->get_var( "SELECT COUNT(*) FROM {$this->db->table( 'team' )} WHERE id = %d", array( $team ) ) );
		self::assertSame( (string) $this->id['captain_h1'], $this->db->get_var( "SELECT person_id FROM {$this->db->table( 'club_role' )} WHERE role = 'captain' AND team_id = %d", array( $this->id['team_h1'] ) ) );
		self::assertSame( 'Turnhalle Seefeld', $this->db->get_var( "SELECT name FROM {$this->db->table( 'venue' )} WHERE id = %d", array( $this->id['venue'] ) ) );
		self::assertSame( 0, (int) $this->db->get_var( "SELECT COUNT(*) FROM {$this->db->table( 'venue_closure' )}" ) );
		self::assertStringContainsString( 'ttl-notice--error', $this->section( 'wolli', 'club_away' ) );
	}

	public function test_a_refusal_of_the_service_is_shown_as_an_error(): void {
		wp_set_current_user( $this->user['chef'] );
		$this->post( Actions::TEAM_ADD, $this->home() );

		$this->post( Actions::SUBMIT, $this->home() );

		self::assertSame( 'open', $this->registration_status( $this->id['club_home'] ) );
		$html = $this->section( 'chef' );
		self::assertStringContainsString( 'ttl-notice--error', $html );
		self::assertStringContainsString( 'Die Meldung lässt sich noch nicht einreichen', $html );
		self::assertStringContainsString( 'Seefeld 1: Es braucht genau 3 Stammspieler, gemeldet sind 0.', $html );
		self::assertStringContainsString( 'Einreichen kannst du, sobald die offenen Punkte oben behoben sind.', $html );
		self::assertStringNotContainsString( 'name="action" value="' . Actions::SUBMIT . '"', $html );
	}

	public function test_the_whole_registration_through_the_forms(): void {
		wp_set_current_user( $this->user['chef'] );
		$next = $this->id['next'];

		$this->post( Actions::CONFIRM_ALL, $this->home() );
		self::assertSame( 3, (int) $this->db->get_var( "SELECT COUNT(*) FROM {$this->db->table( 'player_season' )} WHERE season_id = %d AND status = 'provisional'", array( $next ) ) );
		$this->post(
			Actions::REQUEST,
			$this->home(
				array(
					'last_name'      => "O'Neuling",
					'first_name'     => 'Noah',
					'email'          => 'noah@example.invalid',
					'classification' => '3',
				)
			)
		);
		$request = $this->db->get_row( "SELECT * FROM {$this->db->table( 'player_request' )}" );
		self::assertSame( "O'Neuling", $request['last_name'] ?? null, 'ohne Backslash gespeichert' );
		$this->post( Actions::REQUEST_CANCEL, $this->home( array( 'request' => (string) $request['id'] ) ) );
		self::assertSame( 0, (int) $this->db->get_var( "SELECT COUNT(*) FROM {$this->db->table( 'player_request' )}" ) );

		$this->post( Actions::TEAM_ADD, $this->home() );
		$team = (int) $this->db->get_var( "SELECT id FROM {$this->db->table( 'team' )} WHERE season_id = %d", array( $next ) );
		$this->post(
			Actions::TEAM_SAVE,
			$this->home(
				array(
					'team'          => (string) $team,
					'players'       => array( (string) $this->id['h1'], (string) $this->id['h2'], (string) $this->id['h3'] ),
					'home_venue_id' => (string) $this->id['venue'],
					'start_time'    => '20:00',
					'captain'       => (string) $this->id['person_h1'],
				)
			)
		);
		self::assertSame( 3, (int) $this->db->get_var( "SELECT COUNT(*) FROM {$this->db->table( 'team_roster' )} WHERE team_id = %d AND kind = 'stamm'", array( $team ) ) );
		self::assertSame( (string) $this->id['person_h1'], $this->db->get_var( "SELECT person_id FROM {$this->db->table( 'club_role' )} WHERE role = 'captain' AND team_id = %d", array( $team ) ) );

		$html = $this->section( 'chef' );
		self::assertFalse( str_contains( $html, 'offene Punkte' ), 'keine offenen Punkte; die der aktiven Saison sieht nur die TK' );
		self::assertStringContainsString( 'name="action" value="' . Actions::SUBMIT . '"', $html );

		$this->post( Actions::SUBMIT, $this->home() );
		self::assertSame( 'submitted', $this->registration_status( $this->id['club_home'] ) );
		$html    = $this->section( 'chef' );
		$planned = substr( $html, 0, (int) strpos( $html, 'Laufende Saison' ) );
		self::assertStringContainsString( 'eingereicht', $planned );
		self::assertStringNotContainsString( '<form', $planned, 'nach dem Einreichen gibt es nichts mehr zu ändern' );

		$this->post( Actions::WITHDRAW, $this->home( array( 'player' => (string) $this->id['h1'] ) ) );
		self::assertNotNull( $this->player_season( $next, $this->id['h1'] ), 'der Dienst sperrt auch ein nachgebautes Formular' );
	}

	public function test_venue_closure_and_captain_of_the_running_season(): void {
		wp_set_current_user( $this->user['chef'] );
		$now = $this->id['season'];

		$this->post(
			Actions::VENUE,
			$this->home(
				array(
					'venue'        => '0',
					'name'         => 'Sporthalle Riesbach',
					'weekday'      => '3',
					'open_time'    => '19:00',
					'tables_count' => '6',
				),
				$now
			)
		);
		$venue = (int) $this->db->get_var( "SELECT id FROM {$this->db->table( 'venue' )} WHERE name = 'Sporthalle Riesbach' AND club_id = %d AND active = 1", array( $this->id['club_home'] ) );
		self::assertGreaterThan( 0, $venue );
		$this->post(
			Actions::CLOSURE_ADD,
			$this->home(
				array(
					'venue'     => (string) $venue,
					'date_from' => '2027-10-04',
					'date_to'   => '',
				),
				$now
			)
		);
		$closure = $this->db->get_row( "SELECT * FROM {$this->db->table( 'venue_closure' )} WHERE venue_id = %d", array( $venue ) );
		self::assertSame( '2027-10-04', $closure['date_to'] ?? null, 'ohne «bis» ein einzelner Tag' );
		$this->post( Actions::CLOSURE_DELETE, $this->home( array( 'closure' => (string) $closure['id'] ), $now ) );
		self::assertSame( 0, (int) $this->db->get_var( "SELECT COUNT(*) FROM {$this->db->table( 'venue_closure' )}" ) );

		$this->post(
			Actions::CAPTAIN,
			$this->home(
				array(
					'team'    => (string) $this->id['team_h1'],
					'captain' => (string) $this->id['person_h1'],
				),
				$now
			)
		);
		self::assertSame( (string) $this->id['person_h1'], $this->db->get_var( "SELECT person_id FROM {$this->db->table( 'club_role' )} WHERE season_id = %d AND role = 'captain' AND team_id = %d", array( $now, $this->id['team_h1'] ) ) );
		self::assertTrue( user_can( $this->account( 'subscriber', 'spieler1@example.invalid', $this->id['person_h1'] ), Capabilities::ENTER_RESULT, $this->id['f1'] ), 'der neue Captain erfasst sofort' );

		$this->post(
			Actions::TEAM_SAVE,
			$this->home(
				array(
					'team'    => (string) $this->id['team_h1'],
					'players' => array( (string) $this->id['h1'] ),
				),
				$now
			)
		);
		self::assertSame( 3, (int) $this->db->get_var( "SELECT COUNT(*) FROM {$this->db->table( 'team_roster' )} WHERE team_id = %d", array( $this->id['team_h1'] ) ), 'Stammspieler der aktiven Saison ändert nur die TK' );
	}

	public function test_a_club_cannot_use_the_decisions_of_the_tk(): void {
		wp_set_current_user( $this->user['chef'] );
		$this->post( Actions::CONFIRM, $this->home( array( 'player' => (string) $this->id['h1'] ) ) );
		$club = $this->id['club_home'];

		$this->denied( Actions::APPROVE, $this->home(), null, $club );
		$this->denied( Actions::RETURN, $this->home( array( 'note' => 'Nochmals.' ) ), null, $club );
		$this->denied( Actions::PLAYER_APPROVE, $this->home( array( 'player' => (string) $this->id['h1'] ) ), null, $this->id['h1'] );
		$this->denied(
			Actions::PLAYER_REJECT,
			$this->home(
				array(
					'player' => (string) $this->id['h1'],
					'note'   => 'Nein.',
				)
			),
			null,
			$this->id['h1']
		);
		$this->denied(
			Actions::REQUEST_APPROVE,
			$this->home(
				array(
					'request'    => '1',
					'license_no' => '95000',
				)
			),
			null,
			1
		);
		$this->denied(
			Actions::REQUEST_REJECT,
			$this->home(
				array(
					'request' => '1',
					'note'    => 'Nein.',
				)
			),
			null,
			1
		);

		self::assertSame( 'open', $this->registration_status( $club ) );
		self::assertSame( 'provisional', $this->player_season( $this->id['next'], $this->id['h1'] )['status'] );
	}

	public function test_back_to_the_backend_only_for_the_tk(): void {
		wp_set_current_user( $this->user['chef'] );

		$target = $this->post(
			Actions::CONFIRM,
			$this->home(
				array(
					'player' => (string) $this->id['h1'],
					'back'   => 'tk',
				)
			)
		);

		self::assertStringStartsWith( LoginFlow::my_area_url(), $target );
	}
}
