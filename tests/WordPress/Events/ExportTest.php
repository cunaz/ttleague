<?php
/**
 * CSV der Teilnehmerliste eines Anlasses: Inhalt, Filter «event», Download nur mit ttl_tk.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Tests\WordPress\Events;

use TTLeague\Events\Events;
use TTLeague\Events\Export;
use TTLeague\Export\Exports;

/**
 * Daten siehe EventsTestCase.
 */
final class ExportTest extends EventsTestCase {

	private const HEADER = "\xEF\xBB\xBFAnlass;Beginn;Nr.;Status;Nachname;Vorname;Club;Angemeldet am;Bemerkung\r\n";

	private function seeded(): int {
		update_option( 'timezone_string', 'Europe/Zurich' );
		$event = $this->event(
			array(
				'starts_at'        => '2036-11-20T19:30',
				'deadline'         => '',
				'max_participants' => '2',
			)
		);
		Events::registrations()->register( $event, $this->person['a1'], $this->actor( 'a1' ), '=SUMME(A1); "Gast"' );
		$this->register_all( $event, array( 'captain', 'nobody' ) );
		$table = $this->db->table( 'registration' );
		$this->db->query( "UPDATE {$table} SET created_at = %s WHERE person_id = %d", array( '2026-11-10 08:00:00', $this->person['a1'] ) );
		$this->db->query( "UPDATE {$table} SET created_at = %s WHERE person_id = %d", array( '2026-11-10 09:15:00', $this->person['captain'] ) );
		$this->db->query( "UPDATE {$table} SET created_at = %s WHERE person_id = %d", array( '2026-11-11 23:30:00', $this->person['nobody'] ) );
		return $event;
	}

	private function request( string $url ): void {
		$query = array();
		parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $query );
		$_GET     = $query;
		$_REQUEST = $query;
	}

	public function test_the_list_is_offered_and_contains_participants_then_the_waitlist(): void {
		$event = $this->seeded();

		self::assertArrayHasKey( Export::NAME, Exports::all() );
		self::assertSame(
			self::HEADER
			. "Plauschturnier;2036-11-20 19:30;1;angemeldet;Wollishofer;Spieler1;Wollishofen;2026-11-10 09:00;\"'=SUMME(A1); \"\"Gast\"\"\"\r\n"
			. "Plauschturnier;2036-11-20 19:30;2;angemeldet;Heim;Hanna;Seefeld;2026-11-10 10:15;\r\n"
			. "Plauschturnier;2036-11-20 19:30;1;Warteliste;Niemand;Nina;;2026-11-12 00:30;\r\n",
			Exports::csv( Export::NAME, array( 'event' => (string) $event ) ),
			'Zeitpunkt in Ortszeit, Formel entschärft, Warteliste neu nummeriert'
		);
	}

	public function test_without_a_valid_event_the_list_is_empty(): void {
		$event = $this->seeded();

		self::assertSame( self::HEADER, Exports::csv( Export::NAME ) );
		self::assertSame( self::HEADER, Exports::csv( Export::NAME, array( 'event' => '99999' ) ) );
		self::assertSame( self::HEADER, Exports::csv( Export::NAME, array( 'event' => $event . ' OR 1=1' ) ) );
	}

	public function test_the_tk_downloads_the_list(): void {
		$event = $this->seeded();
		wp_set_current_user( $this->user['tk'] );
		$this->request( Exports::url( Export::NAME, array( 'event' => (string) $event ) ) );

		$file = Exports::requested();

		self::assertSame( 'ttleague-event-participants-' . wp_date( 'Y-m-d' ) . '.csv', $file['filename'] );
		self::assertStringContainsString( ';Heim;Hanna;Seefeld;', $file['csv'] );
	}

	public function test_a_tk_chef_cannot_download_the_list(): void {
		$event = $this->seeded();
		wp_set_current_user( $this->user['chef'] );
		$this->request( Exports::url( Export::NAME, array( 'event' => (string) $event ) ) );

		$error = $this->die_of( array( Exports::class, 'requested' ) );

		self::assertSame( 'Dazu fehlt dir die Berechtigung.', $error->getMessage() );
	}
}
