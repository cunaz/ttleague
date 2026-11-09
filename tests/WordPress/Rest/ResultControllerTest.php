<?php
/**
 * REST-API der Resultaterfassung über den REST-Server von WordPress.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Tests\WordPress\Rest;

use TTLeague\Results\Results;
use TTLeague\Tests\WordPress\League;
use TTLeague\Tests\WordPress\TestCase;

/**
 * Liga siehe League. Die Meisterschaftsbegegnung f1 hat vor einer Stunde begonnen; Hanna Heim
 * (Captain Seefeld 1, Heim) erfasst, Gustav Gast (Captain Wollishofen 1, Gast) bestätigt,
 * Nina Niemand hat keine Funktion. Anmeldung über wp_set_current_user() statt Cookie und
 * Nonce: Die Prüfung des Nonce gehört zu serve_request(), nicht zum Routing.
 */
final class ResultControllerTest extends TestCase {

	private League $league;

	/** @var array<string,int> */
	private array $users = array();

	public function set_up(): void {
		parent::set_up();
		// Neuer REST-Server für jeden Test (Spy_REST_Server der Testsuite), rest_api_init registriert die Routen.
		$GLOBALS['wp_rest_server'] = null; // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- REST-Server von WordPress, im Test neu aufgebaut.
		rest_get_server();

		$this->league = new League( $this->db, wp_date( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS ) );
		$this->users  = array();
		foreach ( array( 'captain_h1', 'captain_a1', 'nobody' ) as $person ) {
			$this->users[ $person ] = $this->account( 'subscriber', str_replace( '_', '.', $person ) . '@example.invalid', $this->league->id[ $person ] );
		}
	}

	public function tear_down(): void {
		$GLOBALS['wp_rest_server'] = null; // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- siehe set_up().
		parent::tear_down();
	}

	public function test_routes_are_registered(): void {
		$routes = rest_get_server()->get_routes( 'ttleague/v1' );

		foreach ( array( 'entry', 'result', 'confirm', 'objection', 'decision', 'photo' ) as $action ) {
			self::assertArrayHasKey( '/ttleague/v1/fixtures/(?P<id>\d+)/' . $action, $routes );
		}
		self::assertArrayHasKey( '/ttleague/v1/me/fixtures', $routes );
	}

	public function test_logged_out_requests_are_refused_with_401(): void {
		$entry  = $this->request( 'GET', 'entry' );
		$result = $this->request( 'POST', 'result', $this->league->six_four() );

		$this->assert_error( 401, 'rest_forbidden', $entry );
		$this->assert_error( 401, 'rest_forbidden', $result );
		self::assertSame( 'scheduled', $this->fixture()['status'] );
	}

	public function test_accounts_without_role_are_refused_with_403(): void {
		wp_set_current_user( $this->users['nobody'] );
		$this->assert_error( 403, 'ttl_forbidden', $this->request( 'GET', 'entry' ) );

		wp_set_current_user( $this->account( 'subscriber', 'ohne.person@example.invalid' ) );
		$this->assert_error( 403, 'ttl_forbidden', $this->request( 'GET', 'entry' ) );

		wp_set_current_user( $this->users['captain_a1'] );
		$this->assert_error( 403, 'ttl_forbidden', $this->request( 'POST', 'result', $this->league->six_four() ), 'Gastseite erfasst nicht' );
		self::assertSame( 'scheduled', $this->fixture()['status'] );
	}

	public function test_home_captain_gets_the_entry_form(): void {
		wp_set_current_user( $this->users['captain_h1'] );

		$response = $this->request( 'GET', 'entry' );

		self::assertSame( 200, $response->get_status() );
		self::assertSame( 'no-store', $response->get_headers()['Cache-Control'] ?? null );
		$data = $response->get_data();
		self::assertSame( $this->league->id['f1'], $data['fixture']['id'] );
		self::assertSame( 'Seefeld 1', $data['fixture']['home']['name'] );
		self::assertSame( array( 'home' ), $data['roles'] );
		self::assertTrue( $data['can']['enter'] );
		self::assertFalse( $data['can']['confirm'] );
		self::assertFalse( $data['can']['decide'] );
		self::assertCount( 10, $data['order'] );
		self::assertCount( 3, $data['candidates']['home'] );
		self::assertMatchesRegularExpression( '/^[0-9a-f]{16}$/', $data['version'] );
	}

	public function test_unknown_fixture_is_404(): void {
		wp_set_current_user( $this->account( 'ttl_tk', 'tk@example.invalid' ) );

		$this->assert_error( 404, 'ttl_not_found', $this->request( 'GET', 'entry', null, 999999 ) );
	}

	public function test_invalid_result_is_refused_with_422_and_field_errors(): void {
		wp_set_current_user( $this->users['captain_h1'] );
		$payload                          = $this->league->six_four();
		$payload['games'][0]['sets_away'] = 3;
		array_pop( $payload['games'] );

		$response = $this->request( 'POST', 'result', $payload );

		$this->assert_error( 422, 'ttl_invalid', $response );
		$errors = $response->get_data()['data']['errors'];
		self::assertSame( array( 'games.1', 'games.10' ), array_column( $errors, 'field' ) );
		self::assertNotSame( '', $errors[0]['message'] );
		self::assertSame( 'scheduled', $this->fixture()['status'], 'nichts gespeichert' );
	}

	public function test_home_captain_enters_and_away_captain_confirms(): void {
		wp_set_current_user( $this->users['captain_h1'] );
		$entered = $this->request( 'POST', 'result', $this->league->six_four() );

		self::assertSame( 200, $entered->get_status() );
		self::assertSame( 'provisional', $entered->get_data()['status'] );
		self::assertSame( array( 6, 4 ), array( $entered->get_data()['home_score'], $entered->get_data()['away_score'] ) );
		self::assertSame( array(), $entered->get_data()['warnings'] );
		$fixture = $this->fixture();
		self::assertSame( 'provisional', $fixture['status'] );
		self::assertSame( (string) $this->users['captain_h1'], $fixture['entered_by'] );
		self::assertSame( 1, did_action( 'ttleague_result_entered' ) );
		self::assertNotFalse( wp_next_scheduled( Results::RECALC_HOOK ), 'Neuberechnung der Wertungszahl geplant' );

		$this->assert_error( 403, 'ttl_forbidden', $this->request( 'POST', 'confirm', array() ), 'eigene Erfassung nicht bestätigen' );

		wp_set_current_user( $this->users['captain_a1'] );
		$entry = $this->request( 'GET', 'entry' )->get_data();
		self::assertTrue( $entry['can']['confirm'] );
		$confirmed = $this->request( 'POST', 'confirm', array( 'version' => $entry['version'] ) );

		self::assertSame( 200, $confirmed->get_status() );
		self::assertSame( 'confirmed', $confirmed->get_data()['status'] );
		$fixture = $this->fixture();
		self::assertSame( 'confirmed', $fixture['status'] );
		self::assertSame( (string) $this->users['captain_a1'], $fixture['confirmed_by'] );
		self::assertSame( 1, did_action( 'ttleague_result_confirmed' ) );
		self::assertSame( array( 'entered', 'confirmed' ), $this->db->get_col( "SELECT type FROM {$this->db->table( 'fixture_event' )} WHERE fixture_id = %d ORDER BY id", array( $this->league->id['f1'] ) ) );
	}

	public function test_stale_version_is_refused_with_409(): void {
		wp_set_current_user( $this->users['captain_h1'] );
		$this->request( 'POST', 'result', $this->league->six_four() );
		wp_set_current_user( $this->users['captain_a1'] );
		$seen = $this->request( 'GET', 'entry' )->get_data()['version'];

		// Die Heimseite ändert das letzte Spiel, bevor die Gastseite bestätigt.
		wp_set_current_user( $this->users['captain_h1'] );
		$payload                          = $this->league->six_four();
		$payload['games'][9]['sets_home'] = 2;
		$payload['games'][9]['sets_away'] = 3;
		self::assertSame( 200, $this->request( 'POST', 'result', $payload )->get_status() );

		wp_set_current_user( $this->users['captain_a1'] );
		$this->assert_error( 409, 'ttl_conflict', $this->request( 'POST', 'confirm', array( 'version' => $seen ) ) );
		self::assertSame( 'provisional', $this->fixture()['status'] );
		self::assertSame( '5', $this->fixture()['home_score'] );
	}

	public function test_photo_upload_checks_rights_before_the_file(): void {
		// Das Speichern selbst verlangt eine echte Upload-Datei (is_uploaded_file) und bleibt Handarbeit.
		wp_set_current_user( $this->users['captain_a1'] );
		$this->assert_error( 403, 'ttl_forbidden', $this->request( 'POST', 'photo' ), 'Gastseite' );

		wp_set_current_user( $this->users['captain_h1'] );
		$response = $this->request( 'POST', 'photo' );
		$this->assert_error( 422, 'ttl_invalid', $response );
		self::assertSame( array( 'file' ), array_column( $response->get_data()['data']['errors'], 'field' ) );
		self::assertNull( $this->fixture()['photo_attachment_id'] );
	}

	public function test_my_fixtures_lists_the_entry_for_the_home_captain(): void {
		wp_set_current_user( $this->users['captain_h1'] );

		$response = rest_get_server()->dispatch( new \WP_REST_Request( 'GET', '/ttleague/v1/me/fixtures' ) );

		self::assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		self::assertSame( array( $this->league->id['f1'] ), array_column( $data['to_enter'], 'id' ), 'Anspielzeit vorbei, Heimseite' );
		self::assertSame( 'home', $data['to_enter'][0]['side'] );
		self::assertSame( array(), $data['to_confirm'] );
	}

	/**
	 * Anfrage an /ttleague/v1/fixtures/{id}/{action} mit JSON-Inhalt.
	 *
	 * @param array<string,mixed>|null $body Inhalt.
	 */
	private function request( string $method, string $action, ?array $body = null, ?int $fixture = null ): \WP_REST_Response {
		$request = new \WP_REST_Request( $method, '/ttleague/v1/fixtures/' . ( $fixture ?? $this->league->id['f1'] ) . '/' . $action );
		if ( null !== $body ) {
			$request->set_header( 'Content-Type', 'application/json' );
			$request->set_body( (string) wp_json_encode( $body ) );
		}
		return rest_get_server()->dispatch( $request );
	}

	private function assert_error( int $status, string $code, \WP_REST_Response $response, string $message = '' ): void {
		self::assertSame( $status, $response->get_status(), $message );
		self::assertSame( $code, $response->get_data()['code'] ?? null, $message );
		self::assertSame( $status, $response->get_data()['data']['status'] ?? null, $message );
	}

	/**
	 * @return array<string,string|null>
	 */
	private function fixture(): array {
		return (array) $this->db->get_row( "SELECT * FROM {$this->db->table( 'fixture' )} WHERE id = %d", array( $this->league->id['f1'] ) );
	}
}
