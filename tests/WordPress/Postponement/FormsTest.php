<?php
/**
 * Formulare der Verschiebung an admin-post.php: Nonce, Rechte, Rückmeldung, Weiterleitung.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Tests\WordPress\Postponement;

use TTLeague\Infrastructure\Flash;
use TTLeague\Postponement\Forms;
use TTLeague\Tk\Tk;

/**
 * Daten siehe PostponementTestCase. Die Formulare rufen denselben Dienst auf wie die REST-API.
 */
final class FormsTest extends PostponementTestCase {

	public function test_actions_are_only_available_to_logged_in_accounts(): void {
		foreach ( array( Forms::REQUEST, Forms::RESPOND, Forms::DECIDE ) as $action ) {
			self::assertNotFalse( has_action( 'admin_post_' . $action ), $action );
			self::assertFalse( has_action( 'admin_post_nopriv_' . $action ), $action . ' nur angemeldet' );
		}
	}

	public function test_a_side_requests_from_the_entry_page(): void {
		wp_set_current_user( $this->users['captain_h1'] );
		$this->post(
			Forms::REQUEST . '_' . $this->league->id['f1'],
			array(
				'fixture'  => (string) $this->league->id['f1'],
				// Wie das Feld datetime-local sendet.
				'new_date' => str_replace( ' ', 'T', substr( $this->day( 8 ), 0, 16 ) ),
				'reason'   => 'Die Halle ist an diesem Abend "belegt".',
				'back'     => Forms::BACK_ENTRY,
			)
		);

		$location = $this->redirect_of( array( Forms::class, 'request' ) );

		self::assertSame( 'http://example.org/erfassen/?begegnung=' . $this->league->id['f1'] . '#ttl-termin', $location );
		$row = (array) $this->db->get_row( "SELECT * FROM {$this->db->table( 'postponement' )}" );
		self::assertSame( array( 'requested', $this->day( 8 ), 'Die Halle ist an diesem Abend "belegt".', (string) $this->users['captain_h1'] ), array( $row['status'], $row['new_date'], $row['reason'], $row['requested_by'] ) );
		$flash = Flash::take( Forms::FLASH, $this->users['captain_h1'] );
		self::assertSame( 'success', $flash['type'] ?? null );
		self::assertStringContainsString( 'mit ihrer Zustimmung gilt der neue Termin', $flash['message'] );
		self::assertSame( $this->scheduled, $this->fixture()['scheduled_at'] );
	}

	public function test_the_home_side_proposes_a_date_for_a_cup_fixture_without_one(): void {
		$cup = $this->league->id['cup'];
		$this->db->update( 'fixture', array( 'scheduled_at' => null ), array( 'id' => $cup ) );
		wp_set_current_user( $this->users['chef_home'] );
		$this->post(
			Forms::REQUEST . '_' . $cup,
			array(
				'fixture'  => (string) $cup,
				'new_date' => $this->day( 12 ),
				'reason'   => '',
			)
		);

		$location = $this->redirect_of( array( Forms::class, 'request' ) );

		self::assertSame( 'http://example.org/erfassen/?begegnung=' . $cup . '#ttl-termin', $location );
		$row = (array) $this->db->get_row( "SELECT * FROM {$this->db->table( 'postponement' )}" );
		self::assertSame( array( 'propose', 'requested', null, null, $this->day( 12 ) ), array( $row['kind'], $row['status'], $row['old_date'], $row['reason'], $row['new_date'] ) );

		// Ein Spieler der Gast-Cup-Mannschaft stimmt zu: Die Begegnung hat ihren ersten Termin und bleibt «scheduled».
		wp_set_current_user( $this->account( 'subscriber', 'spieler.a1@example.invalid', $this->league->id['person_a1'] ) );
		$this->post(
			Forms::RESPOND . '_' . $row['id'],
			array(
				'postponement' => (string) $row['id'],
				'response'     => 'accept',
			)
		);
		$this->redirect_of( array( Forms::class, 'respond' ) );
		self::assertSame(
			array(
				'status'       => 'scheduled',
				'scheduled_at' => $this->day( 12 ),
			),
			$this->fixture( 'cup' )
		);
	}

	public function test_an_invalid_request_comes_back_with_the_errors(): void {
		wp_set_current_user( $this->users['captain_a1'] );
		$this->post(
			Forms::REQUEST . '_' . $this->league->id['f1'],
			array(
				'fixture'  => (string) $this->league->id['f1'],
				'new_date' => '',
				'reason'   => '',
				'back'     => 'https://boese.example.org/',
			)
		);

		$location = $this->redirect_of( array( Forms::class, 'request' ) );

		self::assertSame( 'http://example.org/erfassen/?begegnung=' . $this->league->id['f1'] . '#ttl-termin', $location, 'nur feste Ziele, nie eine Adresse aus der Anfrage' );
		self::assertSame( 0, $this->count_postponements() );
		$flash = Flash::take( Forms::FLASH, $this->users['captain_a1'] );
		self::assertSame( 'error', $flash['type'] ?? null );
		self::assertCount( 2, $flash['details'] );
	}

	public function test_requests_need_a_valid_nonce_and_the_right_for_the_fixture(): void {
		$fields = array(
			'fixture'  => (string) $this->league->id['f1'],
			'new_date' => $this->day( 8 ),
			'reason'   => 'Bitte verschieben.',
		);

		// Nonce einer anderen Begegnung.
		wp_set_current_user( $this->users['captain_h1'] );
		$this->post( Forms::REQUEST . '_' . $this->league->id['cup'], $fields );
		self::assertSame( 403, $this->die_of( array( Forms::class, 'request' ) )->getCode() );

		// Gültiger Nonce, aber keine Seite der Begegnung.
		wp_set_current_user( $this->users['nobody'] );
		$this->post( Forms::REQUEST . '_' . $this->league->id['f1'], $fields );
		self::assertSame( 403, $this->die_of( array( Forms::class, 'request' ) )->getCode() );

		// Vorsaison: Hanna Heim war Captain, hat dort aber kein Recht mehr.
		wp_set_current_user( $this->users['captain_h1'] );
		$this->post( Forms::REQUEST . '_' . $this->league->id['old_fixture'], array( 'fixture' => (string) $this->league->id['old_fixture'] ) + $fields );
		self::assertSame( 403, $this->die_of( array( Forms::class, 'request' ) )->getCode() );

		self::assertSame( 0, $this->count_postponements() );
	}

	public function test_the_other_side_accepts_from_my_area(): void {
		$id = $this->request_as( 'captain_h1' )['id'];
		wp_set_current_user( $this->users['captain_a1'] );
		$this->post(
			Forms::RESPOND . '_' . $id,
			array(
				'postponement' => (string) $id,
				'response'     => 'accept',
				'back'         => Forms::BACK_AREA,
			)
		);

		$location = $this->redirect_of( array( Forms::class, 'respond' ) );

		self::assertSame( 'http://example.org/mein-bereich/#ttl-termine', $location );
		self::assertSame( 'approved', $this->postponement( $id )['status'] );
		self::assertSame(
			array(
				'status'       => 'postponed',
				'scheduled_at' => $this->day( 8 ),
			),
			$this->fixture()
		);
		$flash = Flash::take( Forms::FLASH, $this->users['captain_a1'] );
		self::assertSame( 'success', $flash['type'] ?? null );
		self::assertStringStartsWith( 'Du hast zugestimmt. Neuer Termin:', $flash['message'] );
	}

	public function test_decline_with_note_and_withdrawal(): void {
		$first = $this->request_as( 'captain_h1' )['id'];
		wp_set_current_user( $this->users['captain_a1'] );
		$this->post(
			Forms::RESPOND . '_' . $first,
			array(
				'postponement' => (string) $first,
				'response'     => 'decline',
				'note'         => "An dem Abend\nhaben wir Training.",
			)
		);
		$this->redirect_of( array( Forms::class, 'respond' ) );
		$row = $this->postponement( $first );
		self::assertSame( array( 'declined', "An dem Abend\nhaben wir Training." ), array( $row['status'], $row['response_note'] ) );

		$second = $this->request_as( 'captain_h1', 9 )['id'];
		// Die Gegenseite kann nicht zurückziehen: Der Dienst lehnt ab, die Meldung kommt zurück.
		wp_set_current_user( $this->users['captain_a1'] );
		$this->post(
			Forms::RESPOND . '_' . $second,
			array(
				'postponement' => (string) $second,
				'response'     => 'withdraw',
			)
		);
		$this->redirect_of( array( Forms::class, 'respond' ) );
		self::assertSame( 'error', Flash::take( Forms::FLASH, $this->users['captain_a1'] )['type'] ?? null );
		self::assertSame( 'requested', $this->postponement( $second )['status'] );

		wp_set_current_user( $this->users['chef_home'] );
		$this->post(
			Forms::RESPOND . '_' . $second,
			array(
				'postponement' => (string) $second,
				'response'     => 'withdraw',
			)
		);
		$this->redirect_of( array( Forms::class, 'respond' ) );
		self::assertSame( 'withdrawn', $this->postponement( $second )['status'] );

		// Unbekannte Antwort und fremdes Konto.
		$third = $this->request_as( 'captain_h1', 10 )['id'];
		wp_set_current_user( $this->users['captain_a1'] );
		$this->post(
			Forms::RESPOND . '_' . $third,
			array(
				'postponement' => (string) $third,
				'response'     => 'vielleicht',
			)
		);
		$this->redirect_of( array( Forms::class, 'respond' ) );
		self::assertSame( 'error', Flash::take( Forms::FLASH, $this->users['captain_a1'] )['type'] ?? null );
		wp_set_current_user( $this->users['nobody'] );
		$this->post(
			Forms::RESPOND . '_' . $third,
			array(
				'postponement' => (string) $third,
				'response'     => 'accept',
			)
		);
		self::assertSame( 403, $this->die_of( array( Forms::class, 'respond' ) )->getCode() );
		$this->post(
			'falsch',
			array(
				'postponement' => (string) $third,
				'response'     => 'accept',
			)
		);
		self::assertSame( 403, $this->die_of( array( Forms::class, 'respond' ) )->getCode(), 'falscher Nonce' );
		self::assertSame( 'requested', $this->postponement( $third )['status'] );
	}

	public function test_the_tk_decides_from_the_overview(): void {
		$id     = $this->request_as( 'captain_h1', 25 )['id'];
		$fields = array(
			'postponement' => (string) $id,
			'decision'     => 'reject',
			'comment'      => '',
			'back'         => Forms::BACK_TK,
		);

		// Nur die TK entscheidet, auch mit gültigem Nonce.
		wp_set_current_user( $this->users['captain_a1'] );
		$this->post( Forms::DECIDE . '_' . $id, $fields );
		self::assertSame( 403, $this->die_of( array( Forms::class, 'decide' ) )->getCode() );

		wp_set_current_user( $this->users['tk'] );
		$this->post( Forms::DECIDE . '_' . $id, $fields );
		$location = $this->redirect_of( array( Forms::class, 'decide' ) );
		self::assertSame( admin_url( 'admin.php?page=ttleague-tk' ) . '#ttl-verschiebungen', $location );
		$flash = Flash::take( Tk::FLASH, $this->users['tk'] );
		self::assertSame( 'error', $flash['type'] ?? null, 'Ablehnen ohne Bemerkung' );
		self::assertSame( 'requested', $this->postponement( $id )['status'] );

		$this->post( Forms::DECIDE . '_' . $id, array( 'decision' => 'approve' ) + $fields );
		$this->redirect_of( array( Forms::class, 'decide' ) );
		$flash = Flash::take( Tk::FLASH, $this->users['tk'] );
		self::assertSame( 'success', $flash['type'] ?? null );
		self::assertStringStartsWith( 'Seefeld 1 – Wollishofen 1: Termin gutgeheissen', $flash['message'] );
		self::assertSame( 'approved', $this->postponement( $id )['status'] );
		self::assertSame( $this->day( 25 ), $this->fixture()['scheduled_at'] );

		// Unbekannter Antrag: Meldung statt Fehlerseite.
		$this->post( Forms::DECIDE . '_999999', array( 'postponement' => '999999' ) + $fields );
		$this->redirect_of( array( Forms::class, 'decide' ) );
		self::assertSame( 'error', Flash::take( Tk::FLASH, $this->users['tk'] )['type'] ?? null );
	}

	/**
	 * Formulardaten mit Nonce für die Aktion, wie WordPress sie liefert (mit Backslashes maskiert).
	 *
	 * @param array<string,string> $fields Felder.
	 */
	private function post( string $nonce_action, array $fields ): void {
		$_POST    = wp_slash( $fields + array( '_wpnonce' => wp_create_nonce( $nonce_action ) ) );
		$_REQUEST = $_POST; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Der Test setzt die Formulardaten, der Nonce ist enthalten.
	}

	/**
	 * @param callable():mixed $flow Ablauf, der mit wp_die() enden muss.
	 */
	private function die_of( callable $flow ): \WPDieException {
		try {
			$flow();
		} catch ( \WPDieException $e ) {
			return $e;
		}
		self::fail( 'wp_die() erwartet.' );
	}
}
