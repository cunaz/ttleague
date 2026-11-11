<?php
/**
 * Terminvorschlag für Begegnungen ohne Termin und Verschiebung im Cup mit Frist der Runde.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Tests\Integration\Postponement;

/**
 * Cup Seefeld 1 (Spieler h1, h2) gegen Wollishofen 1 (a1, a2), siehe NotificationTestCase. Das
 * Modul Cup entsteht später; die Tests legen Begegnung und cup_tie selbst an. Die Uhr steht auf
 * Montag, 2.11.2026, 09:00 Ortszeit.
 */
final class TerminvorschlagTest extends PostponementTestCase {

	/**
	 * @return array<string,mixed>
	 */
	private function propose( int $fixture_id, string $new_date, string $actor = 'cup_h1' ): array {
		return $this->postponements->request( $fixture_id, $this->cup_actor( $actor ), array( 'new_date' => $new_date ) );
	}

	/**
	 * @return array<string,string|null>
	 */
	private function fixture_row( int $fixture_id ): array {
		return (array) $this->db->get_row( "SELECT status, scheduled_at FROM {$this->db->table( 'fixture' )} WHERE id = %d", array( $fixture_id ) );
	}

	public function test_the_home_side_proposes_and_the_date_on_the_deadline_needs_no_approval(): void {
		$fixture = $this->cup_without_date( '2026-11-30' );

		$away = self::expect_error( fn() => $this->propose( $fixture, '2026-11-30 19:00', 'cup_a1' ), 403 );
		self::assertStringContainsString( 'Heimmannschaft', $away->getMessage() );
		self::expect_error( fn() => $this->postponements->request( $fixture, $this->actor['chef_away'], array( 'new_date' => '2026-11-30 19:00' ) ), 403 );
		self::expect_error( fn() => $this->postponements->request( $fixture, $this->actor['captain_h1'], array( 'new_date' => '2026-11-30 19:00' ) ), 403 );

		$request = $this->propose( $fixture, '2026-11-30 19:00' );
		self::assertSame( 'requested', $request['status'] );
		self::assertSame( 'propose', $request['kind'] );
		self::assertNull( $request['old_date'] );
		self::assertNull( $request['reason'], 'ohne Begründung' );
		self::assertSame( 'home', $request['requester_side'] );
		self::assertFalse( $request['needs_approval'], 'am Tag der Frist' );
		self::assertSame( '2026-11-30', $request['fixture']['deadline'] );
		self::assertNull( $this->fixture_row( $fixture )['scheduled_at'] );

		$accepted = $this->postponements->accept( $request['id'], $this->cup_actor( 'cup_a2' ) );

		self::assertSame( 'approved', $accepted['status'] );
		self::assertSame(
			array(
				'status'       => 'scheduled',
				'scheduled_at' => '2026-11-30 19:00:00',
			),
			$this->fixture_row( $fixture ),
			'erster Termin: die Begegnung bleibt «scheduled»'
		);
		$event = (array) $this->db->get_row( "SELECT type, comment, data_json FROM {$this->db->table( 'fixture_event' )} WHERE fixture_id = %d", array( $fixture ) );
		self::assertSame( 'postponed', $event['type'] );
		self::assertSame( 'Termin: Mo, 30.11.2026, 19:00.', $event['comment'] );
		self::assertSame( 'propose', json_decode( (string) $event['data_json'], true )['action'] );
		self::assertSame( 'date_set', $this->db->get_var( "SELECT action FROM {$this->db->table( 'audit_log' )} WHERE entity = 'fixture' AND entity_id = %d", array( $fixture ) ) );
	}

	public function test_a_date_after_the_deadline_needs_the_approval_of_the_tk(): void {
		$fixture = $this->cup_without_date( '2026-11-30' );

		$request = $this->propose( $fixture, '2026-12-01 00:00' );
		self::assertTrue( $request['needs_approval'], 'am Tag nach der Frist' );
		$accepted = $this->postponements->accept( $request['id'], $this->cup_actor( 'cup_a1' ) );

		self::assertSame( 'accepted', $accepted['status'] );
		self::assertSame(
			array(
				'status'       => 'scheduled',
				'scheduled_at' => null,
			),
			$this->fixture_row( $fixture )
		);

		$approved = $this->postponements->decide( $request['id'], $this->actor['tk'], array( 'action' => 'approve' ) );
		self::assertSame( 'approved', $approved['status'] );
		self::assertSame(
			array(
				'status'       => 'scheduled',
				'scheduled_at' => '2026-12-01 00:00:00',
			),
			$this->fixture_row( $fixture )
		);
	}

	public function test_without_a_deadline_the_acceptance_is_enough(): void {
		$open = $this->cup_without_date( null );
		$far  = $this->propose( $open, '2027-06-15 19:00' );
		self::assertFalse( $far['needs_approval'] );
		self::assertNull( $far['fixture']['deadline'] );
		self::assertSame( 'approved', $this->postponements->accept( $far['id'], $this->actor['chef_away'] )['status'], 'auch die TK-Chefin der Gegenseite stimmt zu' );
		self::assertSame( '2027-06-15 19:00:00', $this->fixture_row( $open )['scheduled_at'] );
	}

	public function test_a_fixture_without_cup_tie_has_no_deadline(): void {
		$row     = (array) $this->db->get_row( "SELECT competition_id, home_entry_id, away_entry_id FROM {$this->db->table( 'fixture' )} WHERE id = %d", array( $this->id['cup'] ) );
		$fixture = $this->db->insert( 'fixture', array_map( 'intval', $row ) + array( 'status' => 'scheduled' ) );

		$request = $this->propose( $fixture, '2027-06-15 19:00' );
		self::assertSame( 'approved', $this->postponements->accept( $request['id'], $this->cup_actor( 'cup_a1' ) )['status'] );
	}

	public function test_the_tk_sets_the_first_date_directly(): void {
		$fixture = $this->cup_without_date( '2026-11-30' );

		$result = $this->postponements->request( $fixture, $this->actor['tk'], array( 'new_date' => '2026-12-10 19:00' ) );

		self::assertSame( 'approved', $result['status'], 'auch nach der Frist: der Antrag der TK gilt sofort' );
		self::assertSame( 'propose', $result['kind'] );
		self::assertSame(
			array(
				'status'       => 'scheduled',
				'scheduled_at' => '2026-12-10 19:00:00',
			),
			$this->fixture_row( $fixture )
		);
	}

	public function test_a_further_change_is_a_postponement_with_both_rules(): void {
		$fixture = $this->cup_without_date( '2026-11-30' );
		$first   = $this->propose( $fixture, '2026-11-12 19:00' );
		$this->postponements->accept( $first['id'], $this->cup_actor( 'cup_a1' ) );

		// Jetzt hat die Begegnung einen Termin: Auch die Gastseite kann verschieben.
		$payload = array( 'reason' => 'Ferien.' );
		$missing = self::expect_error( fn() => $this->propose( $fixture, '2026-11-26 19:00', 'cup_a1' ), 422 );
		self::assertSame( array( 'reason' ), array_column( $missing->errors, 'field' ), 'eine Verschiebung braucht eine Begründung' );

		$within = $this->postponements->request( $fixture, $this->cup_actor( 'cup_a1' ), array( 'new_date' => '2026-11-26 19:00' ) + $payload );
		self::assertSame( 'postpone', $within['kind'] );
		self::assertSame( '2026-11-12 19:00:00', $within['old_date'] );
		self::assertFalse( $within['needs_approval'], 'genau 14 Tage und vor der Frist' );
		$this->postponements->withdraw( $within['id'], $this->cup_actor( 'cup_a1' ) );

		$on_deadline = $this->postponements->request( $fixture, $this->cup_actor( 'cup_a1' ), array( 'new_date' => '2026-11-30 22:00' ) + $payload );
		self::assertTrue( $on_deadline['needs_approval'], 'am Tag der Frist, aber 18 Tage später' );
		$this->postponements->withdraw( $on_deadline['id'], $this->cup_actor( 'cup_a1' ) );

		$approved = $this->postponements->request( $fixture, $this->cup_actor( 'cup_a1' ), array( 'new_date' => '2026-11-20 19:00' ) + $payload );
		$this->postponements->accept( $approved['id'], $this->cup_actor( 'cup_h2' ) );
		self::assertSame(
			array(
				'status'       => 'postponed',
				'scheduled_at' => '2026-11-20 19:00:00',
			),
			$this->fixture_row( $fixture ),
			'eine Verschiebung macht die Begegnung «postponed»'
		);
	}

	public function test_postponing_a_cup_fixture_beyond_the_deadline_needs_approval(): void {
		// Cup-Begegnung am Donnerstag, 12.11.2026, 19:30; Frist der Runde 20.11.
		$this->cup_tie( $this->id['cup'], '2026-11-20' );
		$payload = array( 'reason' => 'Beide Spieler sind krank.' );

		$on = $this->postponements->request( $this->id['cup'], $this->cup_actor( 'cup_h1' ), array( 'new_date' => '2026-11-20 19:30' ) + $payload );
		self::assertFalse( $on['needs_approval'], 'am Tag der Frist' );
		$this->postponements->withdraw( $on['id'], $this->cup_actor( 'cup_h2' ) );

		$after = $this->postponements->request( $this->id['cup'], $this->cup_actor( 'cup_h1' ), array( 'new_date' => '2026-11-21 19:30' ) + $payload );
		self::assertTrue( $after['needs_approval'], 'nach der Frist, obwohl nur 9 Tage später' );
		self::assertSame( 'accepted', $this->postponements->accept( $after['id'], $this->cup_actor( 'cup_a1' ) )['status'] );
		self::assertSame( '2026-11-12 19:30:00', $this->fixture_row( $this->id['cup'] )['scheduled_at'] );
	}

	public function test_my_area_lists_fixtures_without_a_date(): void {
		$fixture = $this->cup_without_date( '2026-11-30' );

		$home = $this->postponements->mine( $this->cup_actor( 'cup_h1' ) );
		self::assertSame( array( $fixture ), array_map( static fn( array $item ): int => $item['fixture']['id'], $home['unscheduled'] ) );
		self::assertTrue( $home['unscheduled'][0]['can']['request'] );
		self::assertSame( '2026-11-30', $home['unscheduled'][0]['fixture']['deadline'] );

		$away = $this->postponements->mine( $this->cup_actor( 'cup_a1' ) );
		self::assertSame( array( $fixture ), array_map( static fn( array $item ): int => $item['fixture']['id'], $away['unscheduled'] ) );
		self::assertFalse( $away['unscheduled'][0]['can']['request'], 'die Heimseite schlägt vor' );
		self::assertSame( 'away', $away['unscheduled'][0]['side'] );

		$overview = $this->postponements->overview( $fixture, $this->cup_actor( 'cup_a1' ) );
		self::assertSame( 'propose', $overview['kind'] );
		self::assertFalse( $overview['can']['request'] );
		self::assertTrue( $this->postponements->overview( $fixture, $this->actor['chef_home'] )['can']['request'] );

		// Mit offenem Vorschlag steht die Begegnung unter den Anträgen, nicht mehr unter «ohne Termin».
		$request = $this->propose( $fixture, '2026-11-25 19:00' );
		$away    = $this->postponement_service()->mine( $this->cup_actor( 'cup_a1' ) );
		self::assertSame( array(), $away['unscheduled'] );
		self::assertSame( array( $request['id'] ), array_map( static fn( array $item ): int => $item['postponement']['id'], $away['requests'] ) );
		self::assertTrue( $away['requests'][0]['can']['accept'] );

		// Captains der Meisterschaft ohne Cup-Mannschaft sehen sie nicht.
		self::assertSame( array(), $this->postponement_service()->mine( $this->actor['captain_a1'] )['unscheduled'] );
	}
}
