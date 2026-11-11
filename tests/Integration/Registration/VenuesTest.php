<?php
/**
 * Integrationstests der Lokale und Hallensperren gegen MariaDB.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Tests\Integration\Registration;

use TTLeague\Registration\RegistrationException as Refusal;

/**
 * Daten siehe RegistrationTestCase: Seefeld hat das Lokal «Turnhalle Seefeld», in dem Seefeld 1
 * der aktiven Saison spielt.
 */
final class VenuesTest extends RegistrationTestCase {

	private const HALL = array(
		'name'         => 'Sporthalle Riesbach',
		'street'       => 'Musterstrasse 12',
		'zip'          => '8008',
		'city'         => 'Zürich',
		'phone'        => '044 000 00 00',
		'weekday'      => 3,
		'open_time'    => '19:00',
		'close_time'   => '22:30',
		'tables_count' => 6,
	);

	/**
	 * @return array<string,string|null>
	 */
	private function venue_row( int $id ): array {
		return (array) $this->db->get_row( "SELECT * FROM {$this->db->table( 'venue' )} WHERE id = %d", array( $id ) );
	}

	public function test_the_tk_chef_adds_and_changes_a_venue_at_any_time(): void {
		$this->at( '2027-03-01 10:00:00' );

		$id = $this->venues->save( $this->actor['chef'], $this->id['home'], null, self::HALL );

		$row = $this->venue_row( $id );
		self::assertSame( (string) $this->id['home'], $row['club_id'] );
		self::assertSame( 'Sporthalle Riesbach', $row['name'] );
		self::assertSame( '3', $row['weekday'] );
		self::assertSame( '19:00:00', $row['open_time'] );
		self::assertSame( '22:30:00', $row['close_time'] );
		self::assertSame( '6', $row['tables_count'] );
		self::assertSame( '1', $row['active'] );
		self::assertSame( array( array( null, $this->id['home'] ) ), $this->events->changed );
		self::assertSame( 1, $this->rows( 'audit_log', "entity = 'venue' AND action = 'created' AND entity_id = {$id} AND user_id = 301" ) );

		$this->venues->save( $this->actor['chef'], $this->id['home'], $id, array( 'tables_count' => 8 ) + self::HALL );

		self::assertSame( '8', $this->venue_row( $id )['tables_count'] );
		self::assertSame( 1, $this->rows( 'audit_log', "entity = 'venue' AND action = 'updated' AND entity_id = {$id}" ) );
	}

	public function test_venue_data_is_checked(): void {
		$venues = $this->rows( 'venue' );

		$e = $this->refused(
			fn() => $this->venues->save(
				$this->actor['chef'],
				$this->id['home'],
				null,
				array(
					'name'         => '',
					'weekday'      => 6,
					'tables_count' => 0,
				) + self::HALL
			),
			Refusal::INVALID
		);
		self::assertSame( array( 'name', 'weekday', 'tables_count' ), array_column( $e->errors, 'field' ) );
		$e = $this->refused( fn() => $this->venues->save( $this->actor['chef'], $this->id['home'], null, array( 'open_time' => '7 Uhr' ) + self::HALL ), Refusal::INVALID );
		self::assertSame( 'open_time', $e->errors[0]['field'] );
		$e = $this->refused( fn() => $this->venues->save( $this->actor['chef'], $this->id['home'], null, array( 'zip' => '8008 Zürich Riesbach' ) + self::HALL ), Refusal::INVALID );
		self::assertSame( 'zip', $e->errors[0]['field'] );
		$e = $this->refused( fn() => $this->venues->save( $this->actor['chef'], $this->id['home'], null, array( 'tables_count' => 300 ) + self::HALL ), Refusal::INVALID );
		self::assertSame( 'tables_count', $e->errors[0]['field'] );

		self::assertSame( $venues, $this->rows( 'venue' ) );
		self::assertSame( 0, $this->rows( 'audit_log' ) );
	}

	public function test_only_the_own_club_and_the_tk_change_venues(): void {
		$before = $this->venue_row( $this->id['venue'] );

		$this->refused( fn() => $this->venues->save( $this->actor['wolli'], $this->id['home'], $this->id['venue'], self::HALL ), Refusal::FORBIDDEN );
		$this->refused( fn() => $this->venues->save( $this->actor['wolli'], $this->id['home'], null, self::HALL ), Refusal::FORBIDDEN );
		$this->refused( fn() => $this->venues->save( $this->actor['niemand'], $this->id['home'], null, self::HALL ), Refusal::FORBIDDEN );
		// Das Lokal eines anderen Clubs lässt sich nicht über den eigenen Club ändern.
		$this->refused( fn() => $this->venues->save( $this->actor['wolli'], $this->id['away'], $this->id['venue'], self::HALL ), Refusal::NOT_FOUND );
		$this->refused( fn() => $this->venues->add_closure( $this->actor['wolli'], $this->id['venue'], '2027-10-04', '2027-10-15' ), Refusal::FORBIDDEN );
		self::assertSame( $before, $this->venue_row( $this->id['venue'] ) );
		self::assertSame( 0, $this->rows( 'venue_closure' ) );

		// TK-Chefs der aktiven oder der geplanten Saison und die TK.
		$this->venues->save( $this->actor['alt'], $this->id['home'], $this->id['venue'], array( 'name' => 'Turnhalle Seefeld Nord' ) + self::HALL );
		$this->venues->save( $this->actor['neu'], $this->id['away'], null, self::HALL );
		$this->venues->save( $this->actor['tk'], $this->id['home'], $this->id['venue'], array( 'name' => 'Turnhalle Seefeld' ) + self::HALL );
		self::assertSame( 'Turnhalle Seefeld', $this->venue_row( $this->id['venue'] )['name'] );
		self::assertSame( 1, $this->rows( 'venue', 'club_id = ' . $this->id['away'] ) );
	}

	public function test_a_venue_in_use_cannot_be_deactivated(): void {
		$e = $this->refused( fn() => $this->venues->save( $this->actor['chef'], $this->id['home'], $this->id['venue'], array( 'active' => false ) + self::HALL ), Refusal::INVALID );
		self::assertSame( 'active', $e->errors[0]['field'] );
		self::assertSame( '1', $this->venue_row( $this->id['venue'] )['active'] );

		$spare = $this->venues->save( $this->actor['chef'], $this->id['home'], null, self::HALL );
		$this->venues->save( $this->actor['chef'], $this->id['home'], $spare, array( 'active' => false ) + self::HALL );
		self::assertSame( '0', $this->venue_row( $spare )['active'] );

		// Nur eine Mannschaft der archivierten Saison spielt noch dort: Das Lokal darf weg.
		$this->db->update( 'team', array( 'season_id' => $this->id['old'] ), array( 'id' => $this->id['team'] ) );
		$this->venues->save( $this->actor['chef'], $this->id['home'], $this->id['venue'], array( 'active' => false ) + self::HALL );
		self::assertSame( '0', $this->venue_row( $this->id['venue'] )['active'] );
	}

	public function test_closures_are_added_checked_and_removed(): void {
		$chef  = $this->actor['chef'];
		$venue = $this->id['venue'];

		$id = $this->venues->add_closure( $chef, $venue, '2027-10-04', '2027-10-15', 'Herbstferien' );

		self::assertSame(
			array(
				'venue_id'  => (string) $venue,
				'date_from' => '2027-10-04',
				'date_to'   => '2027-10-15',
				'reason'    => 'Herbstferien',
			),
			$this->db->get_row( "SELECT venue_id, date_from, date_to, reason FROM {$this->db->table( 'venue_closure' )} WHERE id = %d", array( $id ) )
		);
		self::assertSame( 1, $this->rows( 'audit_log', "entity = 'venue_closure' AND action = 'created'" ) );

		$single = $this->venues->add_closure( $chef, $venue, '2027-12-08', '2027-12-08' );
		self::assertNull( $this->db->get_var( "SELECT reason FROM {$this->db->table( 'venue_closure' )} WHERE id = %d", array( $single ) ) );
		$e = $this->refused( fn() => $this->venues->add_closure( $chef, $venue, '2027-10-04', '2027-10-03' ), Refusal::INVALID );
		self::assertSame( 'date_to', $e->errors[0]['field'] );
		$this->refused( fn() => $this->venues->add_closure( $chef, $venue, 'morgen', '2027-10-03' ), Refusal::INVALID );
		$this->refused( fn() => $this->venues->add_closure( $chef, 999, '2027-10-04', '2027-10-05' ), Refusal::NOT_FOUND );
		self::assertSame( 2, $this->rows( 'venue_closure' ) );

		$state = $this->registration->state( $chef, $this->id['next'], $this->id['home'] );
		self::assertSame( array( '2027-10-04', '2027-12-08' ), array_column( $state['venues'][0]['closures'], 'date_from' ) );

		$this->refused( fn() => $this->venues->delete_closure( $this->actor['wolli'], $id ), Refusal::FORBIDDEN );
		$this->venues->delete_closure( $chef, $id );
		self::assertSame( 1, $this->rows( 'venue_closure' ) );
		self::assertSame( 1, $this->rows( 'audit_log', "entity = 'venue_closure' AND action = 'deleted'" ) );
		$this->refused( fn() => $this->venues->delete_closure( $chef, $id ), Refusal::NOT_FOUND );
	}
}
