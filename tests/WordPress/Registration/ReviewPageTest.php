<?php
/**
 * Seite «Meldungen» der TK, ihre Entscheide, die Listen der Übersicht, Benachrichtigungen und
 * Exporte, mit echtem WordPress.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Tests\WordPress\Registration;

use TTLeague\Export\Exports;
use TTLeague\Infrastructure\Csv;
use TTLeague\Infrastructure\Flash;
use TTLeague\Infrastructure\Time\FixedClock;
use TTLeague\Notifications\EventTypes;
use TTLeague\Notifications\Notifications;
use TTLeague\Registration\Actions;
use TTLeague\Registration\MyClubSection;
use TTLeague\Registration\Registration;
use TTLeague\Registration\RegistrationExport;
use TTLeague\Registration\ReviewPage;
use TTLeague\Results\Actor;
use TTLeague\Results\Results;
use TTLeague\Tk\DashboardPage;
use TTLeague\Tk\Tk;

/**
 * Daten siehe RegistrationWpTestCase.
 */
final class ReviewPageTest extends RegistrationWpTestCase {

	private function chef(): Actor {
		return new Actor( $this->user['chef'], $this->id['chef_home'], false );
	}

	private function page( int $club = 0, ?int $season = null ): string {
		wp_set_current_user( $this->user['tk'] );
		return ReviewPage::html( $season ?? $this->id['next'], $club, Results::current_actor(), null );
	}

	/**
	 * Seefeld meldet drei Spieler und eine vollständige Mannschaft und reicht ein.
	 */
	private function submitted(): int {
		$service = Registration::registration();
		$chef    = $this->chef();
		foreach ( array( 'h1', 'h2', 'h3' ) as $key ) {
			$service->confirm_player( $chef, $this->id['next'], $this->id['club_home'], $this->id[ $key ] );
		}
		$team = $service->add_team( $chef, $this->id['next'], $this->id['club_home'] );
		$service->save_team(
			$chef,
			$team,
			array(
				'players'           => array( $this->id['h1'], $this->id['h2'], $this->id['h3'] ),
				'home_venue_id'     => $this->id['venue'],
				'start_time'        => '20:00',
				'captain_person_id' => $this->id['person_h1'],
			)
		);
		$service->submit( $chef, $this->id['next'], $this->id['club_home'] );
		return $team;
	}

	private function request(): int {
		return Registration::registration()->request_player(
			$this->chef(),
			$this->id['next'],
			$this->id['club_home'],
			array(
				'last_name'      => 'Neuling',
				'first_name'     => 'Noah',
				'email'          => 'noah.neuling@example.invalid',
				'classification' => 3,
			)
		);
	}

	public function test_the_page_is_in_the_menu_of_the_tk_and_needs_its_capability(): void {
		$pages = array_column( Tk::pages(), null, 'slug' );
		self::assertSame( 'Meldungen', $pages[ Registration::PAGE ]['title'] ?? null );
		self::assertLessThan( Tk::ACCOUNTS_POSITION, $pages[ Registration::PAGE ]['position'] );

		wp_set_current_user( $this->user['chef'] );
		try {
			ReviewPage::render();
			self::fail( 'wp_die() erwartet.' );
		} catch ( \WPDieException $e ) {
			self::assertStringContainsString( 'Dazu fehlt dir die Berechtigung', $e->getMessage() );
		}

		wp_set_current_user( $this->user['tk'] );
		ob_start();
		ReviewPage::render();
		self::assertStringContainsString( 'Spielbetrieb: Meldungen', (string) ob_get_clean() );
	}

	public function test_the_list_shows_the_state_of_each_club(): void {
		$this->submitted();
		$this->db->update( 'club_season', array( 'status' => 'open' ), array( 'club_id' => $this->id['club_home'] ) );
		$this->request();
		$this->db->update( 'club_season', array( 'status' => 'submitted' ), array( 'club_id' => $this->id['club_home'] ) );

		$html = $this->page();

		self::assertStringContainsString( '2027/28 (geplant)', $html );
		self::assertStringContainsString( '2026/27 (aktiv)', $html );
		self::assertMatchesRegularExpression( '#<strong>TTC Seefeld</strong></td><td><span class="ttl-tk-status ttl-reg-status--submitted">eingereicht</span>.*?</td><td>3 \(3 provisorisch\)</td><td>1</td><td>1</td>#s', $html );
		self::assertMatchesRegularExpression( '#<strong>TTC Wollishofen</strong></td><td><span class="ttl-tk-status ttl-reg-status--open">offen</span></td><td>0</td><td>0</td><td>0</td>#', $html );
		self::assertStringContainsString( esc_url( ReviewPage::url( $this->id['next'], $this->id['club_home'] ) ), $html );
		self::assertStringContainsString( 'list=' . RegistrationExport::CLUBS, $html );

		$active = $this->page( 0, $this->id['season'] );
		self::assertStringContainsString( '<strong>TTC Seefeld</strong></td><td>3</td><td>1</td><td>0</td>', $active, 'aktive Saison ohne Meldestand' );
	}

	public function test_the_detail_offers_the_decisions_of_the_tk(): void {
		$this->submitted();

		$html = $this->page( $this->id['club_home'] );

		self::assertStringContainsString( 'TTC Seefeld, Saison 2027/28', $html );
		self::assertStringContainsString( 'name="action" value="' . Actions::APPROVE . '"', $html );
		self::assertStringContainsString( 'name="action" value="' . Actions::RETURN . '"', $html );
		self::assertStringContainsString( 'name="_wpnonce" value="' . wp_create_nonce( Actions::APPROVE . '_' . $this->id['club_home'] ) . '"', $html );
		self::assertStringContainsString( 'name="back" value="tk"', $html );
		self::assertStringContainsString( 'name="action" value="' . Actions::TEAM_SAVE . '"', $html, 'die TK bearbeitet die Meldung selbst' );
		self::assertStringContainsString( 'Spieler1 Seefelder', $html );

		$unknown = $this->page( 99999 );
		self::assertStringContainsString( 'Diese Meldung gibt es nicht.', $unknown );
	}

	public function test_a_request_is_approved_with_the_suggested_license_and_the_club_is_notified(): void {
		$request = $this->request();
		$next    = (int) $this->db->get_var( "SELECT MAX(license_no) + 1 FROM {$this->db->table( 'player' )}" );
		$html    = $this->page( $this->id['club_home'] );
		self::assertStringContainsString( 'name="license_no" min="1" step="1" value="' . $next . '"', $html );
		self::assertStringContainsString( 'name="_wpnonce" value="' . wp_create_nonce( Actions::REQUEST_APPROVE . '_' . $request ) . '"', $html );
		self::assertFalse( str_contains( $html, 'Diese Person gibt es vielleicht schon' ) );
		self::assertFalse( str_contains( $html, 'name="action" value="' . Actions::APPROVE . '"' ), 'mit offener Neumeldung keine Freigabe der Meldung' );

		$target = $this->post(
			Actions::REQUEST_APPROVE,
			$this->home(
				array(
					'request'        => (string) $request,
					'license_no'     => (string) $next,
					'classification' => '4',
					'person_id'      => '0',
					'email'          => 'noah.neuling@example.invalid',
					'back'           => 'tk',
				)
			),
			$request
		);

		self::assertSame( ReviewPage::url( $this->id['next'], $this->id['club_home'] ), $target );
		$player = $this->db->get_row( "SELECT p.id, pe.email FROM {$this->db->table( 'player' )} p JOIN {$this->db->table( 'person' )} pe ON pe.id = p.person_id WHERE p.license_no = %d", array( $next ) );
		self::assertSame( 'noah.neuling@example.invalid', $player['email'] ?? null );
		$row = $this->player_season( $this->id['next'], (int) $player['id'] );
		self::assertSame( 'active', $row['status'] );
		self::assertSame( '4', $row['classification'] );
		self::assertSame( (string) $this->user['tk'], $row['approved_by'] );

		self::assertCount( 1, $this->mails );
		self::assertSame( 'chef@example.invalid', $this->mails[0]->to );
		self::assertSame( 'Neumeldung freigegeben: Noah Neuling', $this->mails[0]->subject );
		self::assertSame( 'notification_registration_decided', $this->mails[0]->type );
		self::assertStringContainsString( '#ttl-meldung-' . $this->id['club_home'], $this->mails[0]->text );
		self::assertStringContainsString( 'Freigegeben. Der Club ist benachrichtigt.', ReviewPage::html( $this->id['next'], $this->id['club_home'], Results::current_actor(), Flash::take( Tk::FLASH, $this->user['tk'] ) ) );
	}

	public function test_an_existing_person_is_offered_and_rejecting_needs_a_note(): void {
		$this->db->update(
			'person',
			array(
				'last_name'  => 'Neuling',
				'first_name' => 'Noah',
			),
			array( 'id' => $this->id['nobody'] )
		);
		$request = $this->request();

		$html = $this->page( $this->id['club_home'] );
		self::assertStringContainsString( 'Diese Person gibt es vielleicht schon', $html );
		self::assertStringContainsString( '<option value="' . $this->id['nobody'] . '">Noah Neuling · ohne Lizenz</option>', $html );

		$this->post(
			Actions::REQUEST_REJECT,
			$this->home(
				array(
					'request' => (string) $request,
					'note'    => ' ',
					'back'    => 'tk',
				)
			),
			$request
		);
		self::assertSame( 'requested', $this->db->get_var( "SELECT status FROM {$this->db->table( 'player_request' )} WHERE id = %d", array( $request ) ) );
		$flash = Flash::take( Tk::FLASH, $this->user['tk'] );
		self::assertSame( 'error', $flash['type'] ?? null );
		self::assertSame( array(), $this->mails );

		$this->post(
			Actions::REQUEST_REJECT,
			$this->home(
				array(
					'request' => (string) $request,
					'note'    => 'Bitte mit Geburtsjahr melden.',
					'back'    => 'tk',
				)
			),
			$request
		);
		self::assertSame( 'rejected', $this->db->get_var( "SELECT status FROM {$this->db->table( 'player_request' )} WHERE id = %d", array( $request ) ) );
		self::assertSame( 'Neumeldung abgelehnt: Noah Neuling', $this->mails[0]->subject );
		self::assertStringContainsString( 'Bemerkung der TK: Bitte mit Geburtsjahr melden.', $this->mails[0]->text );
	}

	public function test_the_tk_approves_and_returns_a_registration(): void {
		$this->submitted();
		wp_set_current_user( $this->user['tk'] );
		$fields = $this->home( array( 'back' => 'tk' ) );

		$this->denied( Actions::APPROVE, $fields, 'falsch' );
		self::assertSame( 'submitted', $this->registration_status( $this->id['club_home'] ) );

		$this->post( Actions::RETURN, $fields + array( 'note' => 'Bitte die Anspielzeit prüfen.' ) );
		self::assertSame( 'open', $this->registration_status( $this->id['club_home'] ) );
		self::assertSame( 'Meldung 2027/28 zurückgegeben: TTC Seefeld', $this->mails[0]->subject );
		wp_set_current_user( $this->user['chef'] );
		self::assertStringContainsString( 'Die TK hat die Meldung zurückgegeben: Bitte die Anspielzeit prüfen.', MyClubSection::section( $this->id['club_home'], Results::current_actor(), null ) );

		wp_set_current_user( $this->user['tk'] );
		$target = $this->post( Actions::APPROVE, $fields );
		self::assertSame( ReviewPage::url( $this->id['next'], $this->id['club_home'] ), $target );
		self::assertSame( 'reviewed', $this->registration_status( $this->id['club_home'] ) );
		self::assertSame( 3, (int) $this->db->get_var( "SELECT COUNT(*) FROM {$this->db->table( 'player_season' )} WHERE season_id = %d AND status = 'active'", array( $this->id['next'] ) ) );
		self::assertSame( 'Meldung 2027/28 geprüft: TTC Seefeld', $this->mails[1]->subject );
		self::assertStringContainsString( 'Die Meldung ist geprüft.', $this->page( $this->id['club_home'] ) );
	}

	public function test_the_tk_edits_a_registration_after_the_window_and_returns_to_the_backend(): void {
		$this->db->update(
			'season',
			array(
				'registration_opens'  => self::day( '-20 days' ),
				'registration_closes' => self::day( '-1 day' ),
			),
			array( 'id' => $this->id['next'] )
		);
		wp_set_current_user( $this->user['tk'] );

		$target = $this->post(
			Actions::CONFIRM,
			$this->home(
				array(
					'player'         => (string) $this->id['h1'],
					'classification' => '12',
					'back'           => 'tk',
				)
			)
		);

		self::assertSame( ReviewPage::url( $this->id['next'], $this->id['club_home'] ), $target );
		$row = $this->player_season( $this->id['next'], $this->id['h1'] );
		self::assertSame( '12', $row['classification'] ?? null );
		self::assertSame( 'active', $row['status'] );
	}

	public function test_a_late_registration_is_approved_from_the_page_of_the_active_season(): void {
		$old    = $this->seed->season( '2024-25', 'archived' );
		$player = $this->db->insert(
			'player',
			array(
				'person_id'  => $this->seed->person( 'Ehemalig', 'Erika' ),
				'license_no' => 91001,
			)
		);
		$this->seed->register( $player, $old, $this->id['club_home'], 5 );
		Registration::registration()->confirm_player( $this->chef(), $this->id['season'], $this->id['club_home'], $player );

		$html = $this->page( $this->id['club_home'], $this->id['season'] );
		self::assertStringContainsString( 'name="action" value="' . Actions::PLAYER_APPROVE . '"', $html );
		self::assertStringContainsString( 'name="action" value="' . Actions::PLAYER_REJECT . '"', $html );
		self::assertFalse( str_contains( $html, 'name="action" value="' . Actions::APPROVE . '"' ), 'die aktive Saison kennt keine Meldung zum Freigeben' );

		$target = $this->post(
			Actions::PLAYER_APPROVE,
			$this->home(
				array(
					'player' => (string) $player,
					'back'   => 'tk',
				),
				$this->id['season']
			),
			$player
		);

		self::assertSame( ReviewPage::url( $this->id['season'], $this->id['club_home'] ), $target );
		self::assertSame( 'active', $this->player_season( $this->id['season'], $player )['status'] );
		self::assertSame( 'Meldung freigegeben: Erika Ehemalig', $this->mails[0]->subject );
	}

	public function test_the_overview_of_the_tk_lists_what_is_to_review_and_changed_venues(): void {
		$this->submitted();
		wp_set_current_user( $this->user['chef'] );
		$this->post(
			Actions::CLOSURE_ADD,
			$this->home(
				array(
					'venue'     => (string) $this->id['venue'],
					'date_from' => '2027-10-04',
					'date_to'   => '2027-10-15',
				)
			)
		);

		self::assertSame( array(), array_filter( DashboardPage::module_sections(), static fn( array $s ): bool => str_starts_with( $s['id'], 'ttl-meldungen' ) || 'ttl-lokale' === $s['id'] ), 'nur für die TK' );

		wp_set_current_user( $this->user['tk'] );
		$sections = array_column( DashboardPage::module_sections(), null, 'id' );

		self::assertSame( 'Meldungen zu prüfen', $sections[ ReviewPage::SECTION_PENDING ]['title'] ?? null );
		self::assertSame( 1, $sections[ ReviewPage::SECTION_PENDING ]['count'] );
		self::assertStringContainsString( 'Meldung eingereicht: 3 Spieler, 1 Mannschaften', $sections[ ReviewPage::SECTION_PENDING ]['html'] );
		self::assertStringContainsString( esc_url( ReviewPage::url( $this->id['next'], $this->id['club_home'] ) ), $sections[ ReviewPage::SECTION_PENDING ]['html'] );
		self::assertSame( 1, $sections[ ReviewPage::SECTION_VENUES ]['count'] );
		self::assertStringContainsString( '<td>Seefeld</td><td>Turnhalle Seefeld</td><td>Hallensperre 4.–15.10.2027 eingetragen</td>', $sections[ ReviewPage::SECTION_VENUES ]['html'] );
		$html = DashboardPage::html( Tk::dashboard()->overview(), null, DashboardPage::module_sections() );
		self::assertStringContainsString( '<h2 id="ttl-meldungen">Meldungen zu prüfen <span class="count">(1)</span></h2>', $html );
	}

	public function test_notification_types_are_registered_with_labels(): void {
		$labels = EventTypes::labels();

		self::assertSame( 'Erinnerung vor Ablauf des Meldefensters', $labels[ EventTypes::REGISTRATION_REMINDER ] ?? null );
		self::assertSame( 'Entscheid der TK zur Meldung des Clubs', $labels[ EventTypes::REGISTRATION_DECIDED ] ?? null );
	}

	public function test_reminders_run_with_the_hourly_deadlines_and_go_out_once(): void {
		self::assertSame( 20, has_action( Notifications::CRON_HOOK, array( Registration::class, 'run_reminders' ) ) );
		self::assertFalse( wp_next_scheduled( 'ttleague_registration_reminders' ), 'kein eigener Cron' );
		$clock = new FixedClock( self::day( '+8 days' ) . ' 10:00:00' );

		$report = Registration::notifications( $clock )->remind();
		Registration::notifications( $clock )->remind();

		self::assertCount( 2, $report, 'beide Clubs haben noch nicht eingereicht' );
		self::assertSame( array( 'chef@example.invalid', 'wolli@example.invalid' ), array_map( static fn( $mail ): string => $mail->to, $this->mails ) );
		self::assertSame( 'Meldung 2027/28: noch 2 Tage', $this->mails[0]->subject );
		self::assertSame( 'notification_registration_reminder', $this->mails[0]->type );
		self::assertSame( 2, (int) $this->db->get_var( "SELECT COUNT(*) FROM {$this->db->table( 'notification_log' )} WHERE event_type = 'registration_reminder' AND ref_type = 'club_season' AND status = 'sent'" ) );

		// Die Stufen sind über einen Filter einstellbar.
		self::assertSame( array(), Registration::notifications( new FixedClock( self::day( '+1 day' ) . ' 10:00:00' ) )->remind(), 'neun Tage vorher ist noch keine Stufe fällig' );
		add_filter( 'ttleague_registration_reminder_days', static fn(): array => array( 9 ) );
		Registration::notifications( new FixedClock( self::day( '+1 day' ) . ' 10:00:00' ) )->remind();
		self::assertCount( 4, $this->mails );
		self::assertSame( 'Meldung 2027/28: noch 9 Tage', $this->mails[2]->subject );
	}

	public function test_exports_list_clubs_and_players(): void {
		$this->submitted();
		$exports = Exports::all();
		self::assertSame( 'Meldungen: Stand je Club', $exports[ RegistrationExport::CLUBS ]['title'] ?? null );
		self::assertArrayHasKey( RegistrationExport::PLAYERS, $exports );

		$clubs = (string) Exports::csv( RegistrationExport::CLUBS );
		self::assertStringStartsWith( Csv::BOM . 'Saison;Club;Stand;', $clubs );
		self::assertMatchesRegularExpression( '#^2027/28;TTC Seefeld;eingereicht;\d\d\.\d\d\.\d{4} \d\d:\d\d;;3;3;1;0;\r$#m', $clubs );
		self::assertStringContainsString( "2027/28;TTC Wollishofen;offen;;;0;0;0;0;\r\n", $clubs );

		$players = (string) Exports::csv( RegistrationExport::PLAYERS, array( 'season' => '2027-28' ) );
		self::assertStringContainsString( "2027/28;TTC Seefeld;90001;Seefelder;Spieler1;C10;provisorisch;Seefeld 1\r\n", $players );
		self::assertSame( 4, substr_count( $players, "\r\n" ), 'Kopfzeile und drei Spieler' );

		$active = (string) Exports::csv(
			RegistrationExport::PLAYERS,
			array(
				'season' => (string) $this->id['season'],
				'club'   => (string) $this->id['club_away'],
			)
		);
		self::assertStringContainsString( '2026/27;TTC Wollishofen;90004;Wollishofer;Spieler1;C10;freigegeben;Wollishofen 1', $active );
		self::assertFalse( str_contains( $active, 'Seefelder' ) );
		self::assertSame( 1, substr_count( (string) Exports::csv( RegistrationExport::PLAYERS, array( 'season' => 'gibt-es-nicht' ) ), "\r\n" ), 'unbekannte Saison: nur die Kopfzeile' );
	}

	public function test_exports_need_the_capability_of_the_tk(): void {
		wp_set_current_user( $this->user['chef'] );
		$_GET     = array(
			'list'     => RegistrationExport::CLUBS,
			'_wpnonce' => wp_create_nonce( Exports::ACTION . '_' . RegistrationExport::CLUBS ),
		);
		$_REQUEST = $_GET; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Der Test setzt die Anfrage, der Nonce ist enthalten.

		$this->expectException( \WPDieException::class );
		Exports::requested();
	}
}
