<?php
/**
 * REST-API der Verschiebung über den REST-Server von WordPress.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Tests\WordPress\Postponement;

use TTLeague\Infrastructure\Cache\DataVersion;

/**
 * Daten siehe PostponementTestCase. Anmeldung über wp_set_current_user() statt Cookie und Nonce:
 * Die Prüfung des Nonce gehört zu serve_request(), nicht zum Routing.
 */
final class RestControllerTest extends PostponementTestCase {

	public function set_up(): void {
		parent::set_up();
		// Neuer REST-Server für jeden Test (Spy_REST_Server der Testsuite), rest_api_init registriert die Routen.
		$GLOBALS['wp_rest_server'] = null; // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- REST-Server von WordPress, im Test neu aufgebaut.
		rest_get_server();
	}

	public function tear_down(): void {
		$GLOBALS['wp_rest_server'] = null; // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- siehe set_up().
		parent::tear_down();
	}

	public function test_routes_are_registered(): void {
		$routes = rest_get_server()->get_routes( 'ttleague/v1' );

		self::assertArrayHasKey( '/ttleague/v1/fixtures/(?P<id>\d+)/postponement', $routes );
		self::assertSame( array( 'GET', 'POST' ), array_merge( ...array_map( static fn( array $handler ): array => array_keys( $handler['methods'] ), $routes['/ttleague/v1/fixtures/(?P<id>\d+)/postponement'] ) ) );
		foreach ( array( 'accept', 'decline', 'withdraw', 'decision' ) as $action ) {
			self::assertArrayHasKey( '/ttleague/v1/postponements/(?P<id>\d+)/' . $action, $routes );
		}
	}

	public function test_logged_out_requests_are_refused_with_401(): void {
		$id = $this->request_as( 'captain_h1' )['id'];
		wp_set_current_user( 0 );

		$this->assert_error( 401, 'rest_forbidden', $this->fixture_request( 'GET' ) );
		$this->assert_error( 401, 'rest_forbidden', $this->fixture_request( 'POST', $this->payload( 9 ) ) );
		foreach ( array( 'accept', 'decline', 'withdraw', 'decision' ) as $action ) {
			$this->assert_error( 401, 'rest_forbidden', $this->action( $id, $action, array( 'action' => 'approve' ) ), $action );
		}
		self::assertSame( 'requested', $this->postponement( $id )['status'] );
	}

	public function test_accounts_without_a_side_are_refused_with_403(): void {
		$id = $this->request_as( 'captain_h1' )['id'];

		foreach ( array( $this->users['nobody'], $this->account( 'subscriber', 'ohne.person@example.invalid' ) ) as $user ) {
			wp_set_current_user( $user );
			$this->assert_error( 403, 'ttl_forbidden', $this->fixture_request( 'GET' ) );
			$this->assert_error( 403, 'ttl_forbidden', $this->fixture_request( 'POST', $this->payload( 9 ) ) );
			$this->assert_error( 403, 'ttl_forbidden', $this->action( $id, 'accept' ) );
			$this->assert_error( 403, 'ttl_forbidden', $this->action( $id, 'decline' ) );
			$this->assert_error( 403, 'ttl_forbidden', $this->action( $id, 'withdraw' ) );
			$this->assert_error( 403, 'ttl_forbidden', $this->action( $id, 'decision', array( 'action' => 'approve' ) ) );
		}
		self::assertSame( 'requested', $this->postponement( $id )['status'] );
		self::assertSame( 1, $this->count_postponements() );
	}

	public function test_each_side_only_does_what_its_role_allows(): void {
		$id = $this->request_as( 'captain_h1' )['id'];

		// Die antragstellende Seite beantwortet den eigenen Antrag nicht und entscheidet nicht.
		foreach ( array( 'captain_h1', 'chef_home' ) as $user ) {
			wp_set_current_user( $this->users[ $user ] );
			$this->assert_error( 403, 'ttl_forbidden', $this->action( $id, 'accept' ), $user );
			$this->assert_error( 403, 'ttl_forbidden', $this->action( $id, 'decline' ), $user );
			$this->assert_error( 403, 'ttl_forbidden', $this->action( $id, 'decision', array( 'action' => 'approve' ) ), $user );
		}
		// Die Gegenseite zieht nicht zurück und entscheidet nicht; die TK antwortet nicht als Gegenseite.
		wp_set_current_user( $this->users['captain_a1'] );
		$this->assert_error( 403, 'ttl_forbidden', $this->action( $id, 'withdraw' ) );
		$this->assert_error(
			403,
			'ttl_forbidden',
			$this->action(
				$id,
				'decision',
				array(
					'action'  => 'reject',
					'comment' => 'Nein.',
				)
			)
		);
		wp_set_current_user( $this->users['tk'] );
		$this->assert_error( 403, 'ttl_forbidden', $this->action( $id, 'accept' ) );
		$this->assert_error( 403, 'ttl_forbidden', $this->action( $id, 'withdraw' ) );

		self::assertSame( 'requested', $this->postponement( $id )['status'] );
		self::assertSame( $this->scheduled, $this->fixture()['scheduled_at'] );
	}

	public function test_home_requests_and_away_accepts(): void {
		$version = DataVersion::get();
		wp_set_current_user( $this->users['captain_h1'] );

		$before = $this->fixture_request( 'GET' );
		self::assertSame( 200, $before->get_status() );
		self::assertSame( 'no-store', $before->get_headers()['Cache-Control'] ?? null );
		self::assertNull( $before->get_data()['open'] );
		self::assertTrue( $before->get_data()['can']['request'] );
		self::assertSame( 14, $before->get_data()['rules']['approval_days'] );

		$requested = $this->fixture_request( 'POST', $this->payload( 8 ) );
		self::assertSame( 200, $requested->get_status() );
		$data = $requested->get_data();
		self::assertSame( 'requested', $data['status'] );
		self::assertSame( $this->day( 8 ), $data['new_date'] );
		self::assertSame( $this->scheduled, $data['old_date'] );
		self::assertSame( 'Hanna Heim', $data['requested_by'] );
		self::assertFalse( $data['needs_approval'] );
		self::assertSame( 'scheduled', $data['fixture']['status'] );
		self::assertSame( 1, did_action( 'ttleague_postponement_changed' ) );
		self::assertGreaterThan( $version, DataVersion::get() );

		wp_set_current_user( $this->users['captain_a1'] );
		$seen = $this->fixture_request( 'GET' )->get_data();
		self::assertSame( $data['id'], $seen['open']['id'] );
		self::assertSame( 'Die Halle ist an diesem Abend belegt.', $seen['open']['reason'] );
		self::assertTrue( $seen['can']['accept'] );
		self::assertTrue( $seen['can']['decline'] );
		self::assertFalse( $seen['can']['withdraw'] );
		self::assertFalse( $seen['can']['request'] );

		$accepted = $this->action( $data['id'], 'accept' );
		self::assertSame( 200, $accepted->get_status() );
		self::assertSame( 'approved', $accepted->get_data()['status'] );
		self::assertSame( 'postponed', $accepted->get_data()['fixture']['status'] );
		self::assertSame(
			array(
				'status'       => 'postponed',
				'scheduled_at' => $this->day( 8 ),
			),
			$this->fixture()
		);
		self::assertSame( array( 'postponed' ), $this->db->get_col( "SELECT type FROM {$this->db->table( 'fixture_event' )} WHERE fixture_id = %d", array( $this->league->id['f1'] ) ) );
		self::assertSame( 2, did_action( 'ttleague_postponement_changed' ) );

		$after = $this->fixture_request( 'GET' )->get_data();
		self::assertNull( $after['open'] );
		self::assertSame( array( 'approved' ), array_column( $after['history'], 'status' ) );
		self::assertSame( $this->day( 8 ), $after['fixture']['scheduled_at'] );
	}

	public function test_away_requests_home_declines_or_the_requester_withdraws(): void {
		wp_set_current_user( $this->users['captain_a1'] );
		$first = $this->fixture_request( 'POST', $this->payload( 8 ) )->get_data()['id'];

		wp_set_current_user( $this->users['chef_home'] );
		$declined = $this->action( $first, 'decline', array( 'note' => 'An dem Abend haben wir Training.' ) );
		self::assertSame( 200, $declined->get_status() );
		self::assertSame( 'declined', $declined->get_data()['status'] );
		self::assertSame( 'An dem Abend haben wir Training.', $declined->get_data()['response_note'] );

		wp_set_current_user( $this->users['captain_a1'] );
		$second = $this->fixture_request( 'POST', $this->payload( 9 ) )->get_data()['id'];
		self::assertSame( 'withdrawn', $this->action( $second, 'withdraw' )->get_data()['status'] );
		self::assertSame( $this->scheduled, $this->fixture()['scheduled_at'] );
	}

	public function test_invalid_requests_are_refused_with_422_and_field_errors(): void {
		wp_set_current_user( $this->users['captain_h1'] );

		$empty = $this->fixture_request( 'POST', array() );
		$this->assert_error( 422, 'ttl_invalid', $empty );
		self::assertSame( array( 'new_date', 'reason' ), array_column( $empty->get_data()['data']['errors'], 'field' ) );
		self::assertNotSame( '', $empty->get_data()['data']['errors'][0]['message'] );

		$past = $this->fixture_request( 'POST', $this->payload( -1 ) );
		$this->assert_error( 422, 'ttl_invalid', $past );
		self::assertSame( array( 'new_date' ), array_column( $past->get_data()['data']['errors'], 'field' ) );

		$late = $this->fixture_request( 'POST', $this->payload( 301 ) );
		$this->assert_error( 422, 'ttl_invalid', $late, 'nach dem Ende der Saison' );
		self::assertSame( 0, $this->count_postponements(), 'nichts gespeichert' );

		// Die TK lehnt nur mit Bemerkung ab.
		$id = $this->fixture_request( 'POST', $this->payload( 25 ) )->get_data()['id'];
		wp_set_current_user( $this->users['tk'] );
		$missing = $this->action( $id, 'decision', array( 'action' => 'reject' ) );
		$this->assert_error( 422, 'ttl_invalid', $missing );
		self::assertSame( array( 'comment' ), array_column( $missing->get_data()['data']['errors'], 'field' ) );
		$this->assert_error( 422, 'ttl_invalid', $this->action( $id, 'decision', array( 'action' => 'später' ) ) );
		self::assertSame( 'requested', $this->postponement( $id )['status'] );
	}

	public function test_conflicts_are_refused_with_409(): void {
		wp_set_current_user( $this->users['captain_h1'] );
		$id = $this->fixture_request( 'POST', $this->payload( 8 ) )->get_data()['id'];

		$this->assert_error( 409, 'ttl_conflict', $this->fixture_request( 'POST', $this->payload( 9 ) ), 'zweiter offener Antrag' );
		wp_set_current_user( $this->users['captain_a1'] );
		$this->assert_error( 409, 'ttl_conflict', $this->fixture_request( 'POST', $this->payload( 9 ) ), 'auch von der Gegenseite' );
		self::assertSame( 1, $this->count_postponements() );

		self::assertSame( 'declined', $this->action( $id, 'decline' )->get_data()['status'] );
		$this->assert_error( 409, 'ttl_conflict', $this->action( $id, 'accept' ), 'schon beantwortet' );
		wp_set_current_user( $this->users['tk'] );
		$this->assert_error( 409, 'ttl_conflict', $this->action( $id, 'decision', array( 'action' => 'approve' ) ) );

		// Mit erfasstem Resultat lässt sich der Termin nicht mehr ändern.
		$this->db->update( 'fixture', array( 'status' => 'provisional' ), array( 'id' => $this->league->id['f1'] ) );
		$this->assert_error( 409, 'ttl_conflict', $this->fixture_request( 'POST', $this->payload( 9 ) ) );
		self::assertSame( $this->scheduled, $this->fixture()['scheduled_at'] );
	}

	public function test_the_tk_decides_and_requests_directly(): void {
		$first = $this->request_as( 'captain_h1', 25 );
		self::assertTrue( $first['needs_approval'] );
		wp_set_current_user( $this->users['captain_a1'] );
		self::assertSame( 'accepted', $this->action( $first['id'], 'accept' )->get_data()['status'] );
		self::assertSame( $this->scheduled, $this->fixture()['scheduled_at'] );

		wp_set_current_user( $this->users['tk'] );
		$seen = $this->fixture_request( 'GET' )->get_data();
		self::assertTrue( $seen['can']['approve'] );
		self::assertTrue( $seen['can']['reject'] );
		$approved = $this->action(
			$first['id'],
			'decision',
			array(
				'action'  => 'approve',
				'comment' => 'In Ordnung.',
			)
		);
		self::assertSame( 200, $approved->get_status() );
		self::assertSame( 'approved', $approved->get_data()['status'] );
		self::assertSame( $this->day( 25 ), $this->fixture()['scheduled_at'] );
		self::assertSame( (string) $this->users['tk'], $this->postponement( $first['id'] )['approved_by'] );

		// Die TK verschiebt selbst: sofort wirksam.
		$own = $this->fixture_request( 'POST', $this->payload( 40 ) );
		self::assertSame( 200, $own->get_status() );
		self::assertSame( 'approved', $own->get_data()['status'] );
		self::assertSame(
			array(
				'status'       => 'postponed',
				'scheduled_at' => $this->day( 40 ),
			),
			$this->fixture()
		);

		// An Stelle der Gegenseite ablehnen.
		$third = $this->request_as( 'captain_a1', 45 )['id'];
		wp_set_current_user( $this->users['tk'] );
		$rejected = $this->action(
			$third,
			'decision',
			array(
				'action'  => 'reject',
				'comment' => 'Jetzt ist genug verschoben.',
			)
		);
		self::assertSame( 'rejected', $rejected->get_data()['status'] );
		self::assertSame( $this->day( 40 ), $this->fixture()['scheduled_at'] );
	}

	public function test_unknown_fixture_or_request_is_404(): void {
		wp_set_current_user( $this->users['tk'] );
		$this->assert_error( 404, 'ttl_not_found', $this->fixture_request( 'GET', null, 999999 ) );
		$this->assert_error( 404, 'ttl_not_found', $this->fixture_request( 'POST', $this->payload( 8 ), 999999 ) );
		$this->assert_error( 404, 'ttl_not_found', $this->action( 999999, 'decision', array( 'action' => 'approve' ) ) );

		wp_set_current_user( $this->users['captain_h1'] );
		$this->assert_error( 404, 'ttl_not_found', $this->fixture_request( 'GET', null, 999999 ) );
		$this->assert_error( 404, 'ttl_not_found', $this->action( 999999, 'accept' ) );
	}

	public function test_only_the_active_season_can_be_rescheduled(): void {
		// Hanna Heim war in der Vorsaison Captain: kein Recht mehr; die TK erhält 409.
		wp_set_current_user( $this->users['captain_h1'] );
		$this->assert_error( 403, 'ttl_forbidden', $this->fixture_request( 'GET', null, $this->league->id['old_fixture'] ) );
		$this->assert_error( 403, 'ttl_forbidden', $this->fixture_request( 'POST', $this->payload( 8 ), $this->league->id['old_fixture'] ) );

		wp_set_current_user( $this->users['tk'] );
		$this->assert_error( 409, 'ttl_conflict', $this->fixture_request( 'POST', $this->payload( 8 ), $this->league->id['old_fixture'] ) );

		// Geplante Saison: Der Spielplanentwurf gehört in den Saison-Assistenten, auch für die TK.
		$this->db->update( 'season', array( 'status' => 'planned' ), array( 'id' => $this->league->id['season'] ) );
		\TTLeague\Access\Capabilities::use_policy( null );
		$planned = $this->fixture_request( 'POST', $this->payload( 8 ) );
		$this->assert_error( 409, 'ttl_conflict', $planned );
		self::assertStringContainsString( 'noch nicht freigegeben', $planned->get_data()['message'] );
		self::assertFalse( $this->fixture_request( 'GET' )->get_data()['can']['request'] );
		wp_set_current_user( $this->users['captain_h1'] );
		$this->assert_error( 403, 'ttl_forbidden', $this->fixture_request( 'POST', $this->payload( 8 ) ), 'Captains der geplanten Saison haben keine Rechte an Begegnungen' );
		self::assertSame( 0, $this->count_postponements() );
	}

	/**
	 * Die Aktion kommt nach dem Speichern. Wirft ein Listener, bleibt die Antwort erfolgreich,
	 * die Cache-Version ist schon erhöht, und der Fehler steht im Fehlerprotokoll.
	 */
	public function test_a_failing_listener_neither_fails_nor_undoes_the_saved_request(): void {
		add_action(
			'ttleague_postponement_changed',
			static function (): void {
				throw new \RuntimeException( 'Erweiterung defekt' );
			},
			1
		);
		$version  = DataVersion::get();
		$log      = (string) tempnam( get_temp_dir(), 'ttl-log' );
		$previous = ini_set( 'error_log', $log ); // phpcs:ignore WordPress.PHP.IniSet.Risky -- Fehlerprotokoll im Test umleiten.
		wp_set_current_user( $this->users['captain_h1'] );
		try {
			$response = $this->fixture_request( 'POST', $this->payload( 8 ) );
		} finally {
			ini_set( 'error_log', false === $previous ? '' : $previous ); // phpcs:ignore WordPress.PHP.IniSet.Risky -- siehe oben.
		}
		$lines = (string) file_get_contents( $log ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Protokoll aus dem Test.
		wp_delete_file( $log );

		self::assertSame( 200, $response->get_status() );
		self::assertSame( 'requested', $response->get_data()['status'] );
		self::assertSame( 1, $this->count_postponements() );
		self::assertGreaterThan( $version, DataVersion::get(), 'Cache-Version vor der Aktion erhöht' );
		self::assertStringContainsString( 'ttleague: Fehler in ttleague_postponement_changed: RuntimeException: Erweiterung defekt', $lines );
	}

	/**
	 * @return array<string,string>
	 */
	private function payload( int $days ): array {
		return array(
			'new_date' => $this->day( $days ),
			'reason'   => 'Die Halle ist an diesem Abend belegt.',
		);
	}

	/**
	 * Anfrage an /ttleague/v1/fixtures/{id}/postponement mit JSON-Inhalt.
	 *
	 * @param array<string,mixed>|null $body Inhalt.
	 */
	private function fixture_request( string $method, ?array $body = null, ?int $fixture = null ): \WP_REST_Response {
		return $this->dispatch( $method, '/ttleague/v1/fixtures/' . ( $fixture ?? $this->league->id['f1'] ) . '/postponement', $body );
	}

	/**
	 * Anfrage an /ttleague/v1/postponements/{id}/{action}.
	 *
	 * @param array<string,mixed> $body Inhalt.
	 */
	private function action( int $id, string $action, array $body = array() ): \WP_REST_Response {
		return $this->dispatch( 'POST', '/ttleague/v1/postponements/' . $id . '/' . $action, $body );
	}

	/**
	 * @param array<string,mixed>|null $body Inhalt.
	 */
	private function dispatch( string $method, string $route, ?array $body ): \WP_REST_Response {
		$request = new \WP_REST_Request( $method, $route );
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
}
