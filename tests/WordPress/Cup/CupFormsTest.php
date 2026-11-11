<?php
/**
 * Formulare des Mannschaftscups an admin-post.php: Nonce, Rechte, Rückmeldung, Weiterleitung.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Tests\WordPress\Cup;

use TTLeague\Access\Capabilities;
use TTLeague\Cup\AdminPage;
use TTLeague\Cup\Cup;
use TTLeague\Cup\Forms;
use TTLeague\Infrastructure\Flash;
use TTLeague\Tk\Tk;

/**
 * Daten siehe CupWpTestCase. Die Formulare rufen dieselben Dienste auf wie die Integrationstests.
 */
final class CupFormsTest extends CupWpTestCase {

	private const TK_ACTIONS = array( Forms::CONFIGURE, Forms::SEEDS, Forms::PROPOSE, Forms::CHANGE, Forms::RELEASE, Forms::ROUND_DEADLINE, Forms::TIE_DEADLINE, Forms::SCHEDULE, Forms::SWAP_HOME );

	/**
	 * Methode der Formularaktion (Forms::register() hängt sie an admin_post_<Aktion>).
	 *
	 * @return callable():void
	 */
	private static function handler( string $action ): callable {
		self::assertSame( 10, has_action( 'admin_post_' . $action, array( Forms::class, Forms::HANDLERS[ $action ] ) ), $action );
		return array( Forms::class, Forms::HANDLERS[ $action ] );
	}

	public function test_actions_are_only_available_to_logged_in_accounts(): void {
		foreach ( array_merge( array( Forms::REGISTER, Forms::WITHDRAW ), self::TK_ACTIONS ) as $action ) {
			self::assertNotFalse( has_action( 'admin_post_' . $action ), $action );
			self::assertFalse( has_action( 'admin_post_nopriv_' . $action ), $action . ' nur angemeldet' );
		}
	}

	public function test_a_tk_chef_registers_a_team_from_my_area(): void {
		$club = $this->club['seefeld'];
		wp_set_current_user( $this->users['chef_seefeld'] );
		$this->post(
			Forms::REGISTER . '_' . $this->cup . '_' . $club,
			array(
				'competition' => (string) $this->cup,
				'club'        => (string) $club,
				'player1'     => (string) $this->player['seefeld'][1],
				'player2'     => (string) $this->player['seefeld'][2],
				'back'        => Forms::BACK_AREA,
			)
		);

		$location = $this->redirect_of( self::handler( Forms::REGISTER ) );

		self::assertSame( 'http://example.org/mein-bereich/#ttl-cup-' . $club, $location );
		$entries = Cup::repository()->entries( $this->cup );
		self::assertSame( array( 'Seefeld 1', 18, $this->users['chef_seefeld'] ), array( $entries[0]['name'], $entries[0]['points_total'], $entries[0]['registered_by'] ) );
		$flash = Flash::take( Forms::flash_scope( $club ), $this->users['chef_seefeld'] );
		self::assertSame( array( 'success', 'Seefeld 1 ist gemeldet (Punktesumme 18).' ), array( $flash['type'] ?? null, $flash['message'] ?? null ) );
	}

	public function test_an_invalid_registration_comes_back_with_the_reason(): void {
		$club = $this->club['seefeld'];
		// Punktelimite 17: Seefeld 1 (12 + 6) liegt einen Punkt darüber.
		$this->db->update( 'competition', array( 'format_json' => '{"points_limit":17}' ), array( 'id' => $this->cup ) );
		wp_set_current_user( $this->users['chef_seefeld'] );
		$this->post(
			Forms::REGISTER . '_' . $this->cup . '_' . $club,
			array(
				'competition' => (string) $this->cup,
				'club'        => (string) $club,
				'player1'     => (string) $this->player['seefeld'][1],
				'player2'     => (string) $this->player['seefeld'][2],
				'back'        => 'https://boese.example.org/',
			)
		);

		$location = $this->redirect_of( self::handler( Forms::REGISTER ) );

		self::assertSame( 'http://example.org/mein-bereich/#ttl-cup-' . $club, $location, 'nur feste Ziele, nie eine Adresse aus der Anfrage' );
		self::assertSame( array(), Cup::repository()->entries( $this->cup ) );
		$flash = Flash::take( Forms::flash_scope( $club ), $this->users['chef_seefeld'] );
		self::assertSame( 'error', $flash['type'] ?? null );
		self::assertStringContainsString( 'höchstens 17', $flash['message'] );
	}

	public function test_registering_needs_a_valid_nonce_and_the_right_for_the_club(): void {
		$club   = $this->club['seefeld'];
		$fields = array(
			'competition' => (string) $this->cup,
			'club'        => (string) $club,
			'player1'     => (string) $this->player['seefeld'][1],
			'player2'     => (string) $this->player['seefeld'][2],
		);

		// Nonce eines anderen Clubs.
		wp_set_current_user( $this->users['chef_seefeld'] );
		$this->post( Forms::REGISTER . '_' . $this->cup . '_' . $this->club['oerlikon'], $fields );
		$this->die_of( self::handler( Forms::REGISTER ) );

		// TK-Chefin eines anderen Clubs und ein Konto ohne Funktion, jeweils mit eigenem gültigem Nonce.
		foreach ( array( 'chef_oerlikon', 'nobody' ) as $user ) {
			wp_set_current_user( $this->users[ $user ] );
			$this->post( Forms::REGISTER . '_' . $this->cup . '_' . $club, $fields );
			$died = $this->die_of( self::handler( Forms::REGISTER ) );
			self::assertStringContainsString( 'Berechtigung', $died->getMessage(), $user );
		}
		self::assertSame( array(), Cup::repository()->entries( $this->cup ) );
	}

	public function test_a_tk_chef_withdraws_before_the_draw_and_not_for_other_clubs(): void {
		$ids = $this->teams( 2 );

		wp_set_current_user( $this->users['chef_oerlikon'] );
		$this->post( Forms::WITHDRAW . '_' . $ids[0], array( 'entry' => (string) $ids[0] ) );
		$this->die_of( self::handler( Forms::WITHDRAW ) );
		self::assertCount( 2, Cup::repository()->entries( $this->cup ) );

		wp_set_current_user( $this->users['chef_seefeld'] );
		$this->post( Forms::WITHDRAW . '_' . $ids[0], array( 'entry' => (string) $ids[0] ) );
		$location = $this->redirect_of( self::handler( Forms::WITHDRAW ) );

		self::assertSame( 'http://example.org/mein-bereich/#ttl-cup-' . $this->club['seefeld'], $location );
		self::assertSame( array( 'Wollishofen 1' ), array_column( Cup::repository()->entries( $this->cup ), 'name' ) );
		self::assertSame( 'Die Meldung von Seefeld 1 ist gelöscht.', Flash::take( Forms::flash_scope( $this->club['seefeld'] ), $this->users['chef_seefeld'] )['message'] ?? null );
	}

	public function test_the_tk_runs_the_draw_in_the_backend(): void {
		$ids  = $this->teams( 8 );
		$page = AdminPage::url( $this->cup );

		$this->post(
			Forms::CONFIGURE . '_' . $this->cup,
			array(
				'competition'           => (string) $this->cup,
				'points_limit'          => '20',
				'registration_deadline' => $this->date( 3 ),
				'min_entries'           => '6',
			)
		);
		self::assertSame( $page, $this->redirect_of( self::handler( Forms::CONFIGURE ) ) );
		self::assertSame( 20, Cup::repository()->competition( $this->cup )['format']['points_limit'] );
		self::assertSame( 'Die Einstellungen sind gespeichert.', Flash::take( Tk::FLASH, $this->users['tk'] )['message'] ?? null );

		// Altstetten 2 (schwächste Summe) wird als Nummer 1 gesetzt.
		$this->post(
			Forms::SEEDS . '_' . $this->cup,
			array(
				'competition' => (string) $this->cup,
				'seed'        => array( $ids[7] => '1' ),
			)
		);
		self::assertSame( $page, $this->redirect_of( self::handler( Forms::SEEDS ) ) );
		self::assertSame( 1, Cup::repository()->entry( $ids[7] )['seed'] );

		$this->post(
			Forms::PROPOSE . '_' . $this->cup,
			array(
				'competition' => (string) $this->cup,
				'seed'        => '4711',
			)
		);
		self::assertSame( $page, $this->redirect_of( self::handler( Forms::PROPOSE ) ) );
		self::assertSame( 4711, Cup::draw()->overview( $this->cup )['proposal']['seed'] );
		self::assertStringContainsString( 'Startwert 4711', Flash::take( Tk::FLASH, $this->users['tk'] )['message'] ?? '' );

		$this->post(
			Forms::CHANGE . '_' . $this->cup,
			array(
				'competition' => (string) $this->cup,
				'positions'   => array_map( 'strval', $ids ),
			)
		);
		self::assertSame( $page, $this->redirect_of( self::handler( Forms::CHANGE ) ) );
		self::assertTrue( Cup::draw()->overview( $this->cup )['proposal']['changed'] );

		$this->post(
			Forms::RELEASE . '_' . $this->cup,
			array(
				'competition' => (string) $this->cup,
				'deadline'    => $this->date( 20 ),
			)
		);
		self::assertSame( $page, $this->redirect_of( self::handler( Forms::RELEASE ) ) );

		$flash = Flash::take( Tk::FLASH, $this->users['tk'] );
		self::assertSame( array( 'success', 'Die Auslosung ist freigegeben: 4 Begegnungen angelegt. Die Mannschaften erhalten eine Nachricht.' ), array( $flash['type'] ?? null, $flash['message'] ?? null ) );
		self::assertCount( 7, Cup::repository()->ties( $this->cup ) );
		self::assertSame( array( $ids[0], $ids[1], $this->date( 20 ) ), array( $this->tie( 1, 1 )['home'], $this->tie( 1, 1 )['away'], $this->tie( 1, 1 )['deadline'] ) );
		self::assertTrue( Cup::round_scheduled( $this->cup ), 'der Versand der Mitteilungen ist vorgemerkt' );
		self::assertSame( array(), $this->mails, 'in der Anfrage der TK geht nichts hinaus' );

		// Eine zweite Freigabe kommt mit der Meldung des Dienstes zurück.
		$this->post( Forms::RELEASE . '_' . $this->cup, array( 'competition' => (string) $this->cup ) );
		$this->redirect_of( self::handler( Forms::RELEASE ) );
		self::assertSame( array( 'error', 'Die Auslosung ist schon freigegeben.' ), array_slice( array_values( (array) Flash::take( Tk::FLASH, $this->users['tk'] ) ), 0, 2 ) );
	}

	public function test_the_tk_sets_deadlines_swaps_home_right_and_schedules_the_semi_final(): void {
		$ids  = $this->released();
		$page = AdminPage::url( $this->cup );

		$this->post(
			Forms::ROUND_DEADLINE . '_' . $this->cup . '_1',
			array(
				'competition' => (string) $this->cup,
				'round'       => '1',
				'deadline'    => $this->date( 25 ),
			)
		);
		self::assertSame( $page, $this->redirect_of( self::handler( Forms::ROUND_DEADLINE ) ) );
		self::assertSame( $this->date( 25 ), $this->tie( 1, 4 )['deadline'] );

		// Aus der Übersicht der TK führt der Weg dorthin zurück.
		$tie = $this->tie( 1, 2 );
		$this->post(
			Forms::TIE_DEADLINE . '_' . $tie['id'],
			array(
				'tie'      => (string) $tie['id'],
				'deadline' => $this->date( 30 ),
				'back'     => Forms::BACK_TK,
			)
		);
		self::assertSame( Tk::overview_url( AdminPage::SECTION_OVERDUE ), $this->redirect_of( self::handler( Forms::TIE_DEADLINE ) ) );
		self::assertSame( $this->date( 30 ), $this->tie( 1, 2 )['deadline'] );

		$this->post( Forms::SWAP_HOME . '_' . $tie['id'], array( 'tie' => (string) $tie['id'] ) );
		self::assertSame( $page, $this->redirect_of( self::handler( Forms::SWAP_HOME ) ) );
		self::assertSame( $ids[3], $this->tie( 1, 2 )['fixture']['home_entry'], 'Altstetten 1 hat jetzt Heimrecht' );
		self::assertTrue( user_can( $this->users['chef_altstetten'], Capabilities::ENTER_RESULT, $tie['fixture']['id'] ) );
		self::assertFalse( user_can( $this->users['chef_oerlikon'], Capabilities::ENTER_RESULT, $tie['fixture']['id'] ) );

		// Viertelfinal: Den Termin vereinbaren die Mannschaften, die TK setzt ihn hier nicht an.
		$this->post(
			Forms::SCHEDULE . '_' . $tie['id'],
			array(
				'tie'          => (string) $tie['id'],
				'scheduled_at' => $this->date( 12 ) . 'T19:30',
			)
		);
		$this->redirect_of( self::handler( Forms::SCHEDULE ) );
		$flash = Flash::take( Tk::FLASH, $this->users['tk'] );
		self::assertSame( 'error', $flash['type'] ?? null );
		self::assertStringContainsString( 'Nur Halbfinal und Final', $flash['message'] );

		// Nach zwei bestätigten Resultaten steht der Halbfinal (das Tableau hängt an der Erfassung).
		$this->play( $this->tie( 1, 1 )['fixture']['id'], 'home' );
		$this->play( $tie['fixture']['id'], 'away' );
		$semi = $this->tie( 2, 1 );
		self::assertSame( array( $ids[0], $ids[2] ), array( $semi['home'], $semi['away'] ), 'Seefeld 1 und Oerlikon 1 (auswärts in Altstetten gewonnen)' );
		$venue = $this->seeder->venue( $this->club['altstetten'], 'Halle Altstetten' );

		wp_set_current_user( $this->users['tk'] );
		$this->post(
			Forms::SCHEDULE . '_' . $semi['id'],
			array(
				'tie'          => (string) $semi['id'],
				'scheduled_at' => $this->date( 40 ) . 'T14:00',
				'venue_id'     => (string) $venue,
			)
		);
		self::assertSame( $page, $this->redirect_of( self::handler( Forms::SCHEDULE ) ) );
		$fixture = $this->tie( 2, 1 )['fixture'];
		self::assertSame( array( $this->date( 40 ) . ' 14:00:00', $venue ), array( $fixture['scheduled_at'], $fixture['venue_id'] ) );
	}

	public function test_actions_of_the_tk_are_forbidden_for_tk_chefs(): void {
		$this->released();
		$tie = $this->tie( 1, 1 );
		wp_set_current_user( $this->users['chef_seefeld'] );

		foreach ( self::TK_ACTIONS as $action ) {
			$object = in_array( $action, array( Forms::TIE_DEADLINE, Forms::SCHEDULE, Forms::SWAP_HOME ), true ) ? array( 'tie' => (string) $tie['id'] ) : array( 'competition' => (string) $this->cup );
			$nonce  = Forms::ROUND_DEADLINE === $action ? $action . '_' . $this->cup . '_1' : $action . '_' . reset( $object );
			$this->post(
				$nonce,
				$object + array(
					'round'    => '1',
					'deadline' => $this->date( 30 ),
				)
			);
			$died = $this->die_of( self::handler( $action ) );
			self::assertStringContainsString( 'Berechtigung', $died->getMessage(), $action );
		}
		self::assertSame( $this->date( 20 ), $this->tie( 1, 1 )['deadline'] );
		self::assertSame( $tie['fixture']['home_entry'], $this->tie( 1, 1 )['fixture']['home_entry'] );
	}

	public function test_withdrawing_after_the_draw_is_for_the_tk_and_forfeits_the_fixture(): void {
		$ids = $this->released();
		$tie = $this->tie( 1, 1 );

		// Der TK-Chef hat das Recht für seinen Club, der Dienst lässt nach der Auslosung aber nur die TK zu.
		wp_set_current_user( $this->users['chef_seefeld'] );
		$this->post( Forms::WITHDRAW . '_' . $ids[0], array( 'entry' => (string) $ids[0] ) );
		$this->redirect_of( self::handler( Forms::WITHDRAW ) );
		$flash = Flash::take( Forms::flash_scope( $this->club['seefeld'] ), $this->users['chef_seefeld'] );
		self::assertSame( array( 'error', 'Nach der Auslosung zieht nur die TK eine Cupmannschaft zurück.' ), array( $flash['type'] ?? null, $flash['message'] ?? null ) );
		self::assertSame( 'active', Cup::repository()->entry( $ids[0] )['status'] );

		wp_set_current_user( $this->users['tk'] );
		$this->post(
			Forms::WITHDRAW . '_' . $ids[0],
			array(
				'entry' => (string) $ids[0],
				'back'  => Forms::BACK_ADMIN,
			)
		);
		self::assertSame( AdminPage::url( $this->cup ), $this->redirect_of( self::handler( Forms::WITHDRAW ) ) );

		self::assertSame( 'withdrawn', Cup::repository()->entry( $ids[0] )['status'] );
		self::assertSame( 'forfeit', $this->db->get_var( "SELECT status FROM {$this->db->table( 'fixture' )} WHERE id = %d", array( $tie['fixture']['id'] ) ) );
		self::assertSame( $ids[1], $this->tie( 2, 1 )['home'], 'der Gegner steht kampflos in der nächsten Runde' );
		self::assertSame( 'Seefeld 1 ist zurückgezogen. Der Gegner kommt kampflos weiter.', Flash::take( Tk::FLASH, $this->users['tk'] )['message'] ?? null );
	}
}
