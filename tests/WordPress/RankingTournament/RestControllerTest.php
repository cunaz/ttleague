<?php
/**
 * REST-API des Gruppenzettels über den REST-Server von WordPress.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Tests\WordPress\RankingTournament;

use TTLeague\Infrastructure\Cache\DataVersion;
use TTLeague\RankingTournament\RankingTournament;
use TTLeague\Results\Results;

/**
 * Gruppe siehe RlTestCase::published_group(): h1 (Gruppenchef, Seefeld), h2, a1, a2 bei
 * Wollishofen. Charlotte Chef ist TK-Chefin von Seefeld, dem Club des Gruppenchefs.
 */
final class RestControllerTest extends RlTestCase {

	private int $round = 0;

	private int $group = 0;

	public function set_up(): void {
		parent::set_up();
		$GLOBALS['wp_rest_server'] = null; // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- REST-Server von WordPress, im Test neu aufgebaut.
		rest_get_server();
		[ $this->round, $this->group ] = $this->published_group();
	}

	public function tear_down(): void {
		$GLOBALS['wp_rest_server'] = null; // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- siehe set_up().
		parent::tear_down();
	}

	public function test_routes_are_registered(): void {
		$routes = rest_get_server()->get_routes( 'ttleague/v1' );
		self::assertArrayHasKey( '/ttleague/v1/rl/groups/(?P<id>\d+)/entry', $routes );
		self::assertArrayHasKey( '/ttleague/v1/rl/groups/(?P<id>\d+)/result', $routes );
	}

	public function test_logged_out_requests_are_refused_with_401(): void {
		$this->assert_error( 401, 'rest_forbidden', $this->request( 'GET', 'entry' ) );
		$this->assert_error( 401, 'rest_forbidden', $this->request( 'POST', 'result', $this->sheet( $this->group ) ) );
		self::assertNull( RankingTournament::repository()->group( $this->group )['entered_at'] );
	}

	public function test_accounts_without_right_are_refused_with_403(): void {
		// Mitspieler ohne Funktion, Unbeteiligte und ein Konto ohne Person.
		foreach ( array( $this->users['h2'], $this->users['a1'], $this->users['nobody'], $this->account( 'subscriber', 'ohne.person@example.invalid' ) ) as $user ) {
			wp_set_current_user( $user );
			$this->assert_error( 403, 'ttl_forbidden', $this->request( 'GET', 'entry' ) );
			$this->assert_error( 403, 'ttl_forbidden', $this->request( 'POST', 'result', $this->sheet( $this->group ) ) );
		}
		self::assertNull( RankingTournament::repository()->group( $this->group )['entered_at'] );
	}

	public function test_unknown_group_is_404(): void {
		wp_set_current_user( $this->users['tk'] );
		$this->assert_error( 404, 'ttl_not_found', $this->request( 'GET', 'entry', null, 999999 ) );
		$this->assert_error( 404, 'ttl_not_found', $this->request( 'POST', 'result', $this->sheet( $this->group ), 999999 ) );
	}

	public function test_chief_gets_the_form_data(): void {
		wp_set_current_user( $this->users['h1'] );
		$response = $this->request( 'GET', 'entry' );

		self::assertSame( 200, $response->get_status() );
		self::assertSame( 'no-store', $response->get_headers()['Cache-Control'] ?? null );
		$data = $response->get_data();
		self::assertSame( $this->group, $data['group']['id'] );
		self::assertSame( 'published', $data['group']['status'] );
		self::assertTrue( $data['can']['enter'] );
		self::assertCount( 4, $data['players'] );
		self::assertSame( 'Spieler1 Seefelder', $data['players'][0]['name'] );
		self::assertTrue( $data['players'][0]['is_chief'] );
		self::assertCount( 6, $data['order'] );
		self::assertSame( array( 'sequence', 'a', 'b' ), array_keys( $data['order'][0] ) );
		self::assertMatchesRegularExpression( '/^[0-9a-f]{16}$/', $data['version'] );
	}

	public function test_chief_and_tk_chef_save_the_sheet(): void {
		wp_set_current_user( $this->users['h1'] );
		$version  = $this->request( 'GET', 'entry' )->get_data()['version'];
		$before   = DataVersion::get();
		$response = $this->request( 'POST', 'result', $this->sheet( $this->group ) + array( 'version' => $version ) );

		self::assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		self::assertSame( array( 1, 2, 3, 4 ), array_column( $data['ranking'], 'rank' ) );
		self::assertEquals( array( 6, 5, 4, 3 ), array_column( $data['ranking'], 'points' ) );
		self::assertNotSame( $version, $data['version'] );
		$group = RankingTournament::repository()->group( $this->group );
		self::assertSame( $this->users['h1'], $group['entered_by'] );
		self::assertCount( 6, $group['games'] );
		// Wie bei der Erfassung der Meisterschaft: Cache-Version erhöht, Neuberechnung der Wertungszahl geplant.
		self::assertGreaterThan( $before, DataVersion::get() );
		self::assertNotFalse( wp_next_scheduled( Results::RECALC_HOOK ) );

		// Die TK-Chefin des Clubs des Gruppenchefs ändert das Resultat.
		wp_set_current_user( $this->users['chef'] );
		$sheet                       = $this->sheet( $this->group );
		$sheet['games'][0]['sets_a'] = 1;
		$sheet['games'][0]['sets_b'] = 3;
		self::assertSame( 200, $this->request( 'POST', 'result', $sheet + array( 'version' => $data['version'] ) )->get_status() );
		self::assertSame( $this->users['chef'], RankingTournament::repository()->group( $this->group )['entered_by'] );
	}

	public function test_invalid_sheet_is_refused_with_422_and_field_errors(): void {
		wp_set_current_user( $this->users['h1'] );
		$sheet                       = $this->sheet( $this->group );
		$sheet['games'][0]['sets_b'] = 3;
		array_pop( $sheet['games'] );
		unset( $sheet['players'][ $this->league->id['a2'] ] );

		$response = $this->request( 'POST', 'result', $sheet );

		$this->assert_error( 422, 'ttl_invalid', $response );
		$fields = array_column( $response->get_data()['data']['errors'], 'field' );
		self::assertContains( 'players.' . $this->league->id['a2'], $fields );
		self::assertContains( 'games.1', $fields );
		self::assertNull( RankingTournament::repository()->group( $this->group )['entered_at'] );
	}

	public function test_stale_version_and_closed_round_are_409(): void {
		wp_set_current_user( $this->users['h1'] );
		$version = $this->request( 'GET', 'entry' )->get_data()['version'];
		RankingTournament::entry()->save( $this->group, $this->sheet( $this->group ), $this->tk() );

		$sheet                       = $this->sheet( $this->group );
		$sheet['games'][0]['sets_a'] = 0;
		$sheet['games'][0]['sets_b'] = 3;
		$this->assert_error( 409, 'ttl_conflict', $this->request( 'POST', 'result', $sheet + array( 'version' => $version ) ) );
		self::assertSame( 3, RankingTournament::repository()->group( $this->group )['games'][0]['sets_a'] );

		RankingTournament::rounds()->close( $this->tk(), $this->round );
		$this->assert_error( 409, 'ttl_conflict', $this->request( 'POST', 'result', $sheet ) );
		$closed = $this->request( 'GET', 'entry' );
		self::assertSame( 200, $closed->get_status() );
		self::assertFalse( $closed->get_data()['can']['enter'] );
	}

	public function test_planned_group_is_hidden_from_the_chief(): void {
		RankingTournament::entry()->save( $this->group, $this->sheet( $this->group ), $this->tk() );
		RankingTournament::rounds()->close( $this->tk(), $this->round );
		$monday  = ( new \DateTimeImmutable( $this->monday() ) )->modify( '+28 days' );
		$next    = RankingTournament::rounds()->create( $this->tk(), $this->competition, $monday->format( 'Y-m-d' ), $monday->modify( '+3 days' )->format( 'Y-m-d' ) );
		$planned = RankingTournament::rounds()->save_group(
			$this->tk(),
			$next,
			null,
			array(
				'category'        => 1,
				'members'         => array( $this->league->id['h1'], $this->league->id['a1'] ),
				'chief_player_id' => $this->league->id['h1'],
			)
		);

		wp_set_current_user( $this->users['h1'] );
		$this->assert_error( 404, 'ttl_not_found', $this->request( 'GET', 'entry', null, $planned ) );
		wp_set_current_user( $this->users['tk'] );
		self::assertSame( 200, $this->request( 'GET', 'entry', null, $planned )->get_status() );
		$this->assert_error( 409, 'ttl_conflict', $this->request( 'POST', 'result', $this->sheet( $planned ), $planned ) );
	}

	/**
	 * @param array<string,mixed>|null $body JSON-Inhalt.
	 */
	private function request( string $method, string $action, ?array $body = null, ?int $group = null ): \WP_REST_Response {
		$request = new \WP_REST_Request( $method, '/ttleague/v1/rl/groups/' . ( $group ?? $this->group ) . '/' . $action );
		if ( null !== $body ) {
			$request->set_header( 'Content-Type', 'application/json' );
			$request->set_body( (string) wp_json_encode( $body ) );
		}
		return rest_get_server()->dispatch( $request );
	}

	private function assert_error( int $status, string $code, \WP_REST_Response $response ): void {
		self::assertSame( $status, $response->get_status() );
		self::assertSame( $code, $response->get_data()['code'] ?? null );
		self::assertSame( $status, $response->get_data()['data']['status'] ?? null );
	}
}
