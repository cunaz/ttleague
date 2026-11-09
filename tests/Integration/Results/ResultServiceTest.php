<?php
/**
 * Integrationstests der Resultaterfassung: erfassen, bestätigen, Einsprache, Entscheide der TK.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Tests\Integration\Results;

use PHPUnit\Framework\Attributes\DataProvider;
use TTLeague\Access\AccessPolicy;
use TTLeague\Domain\Match\GameOrder;
use TTLeague\Infrastructure\AuditLog;
use TTLeague\Infrastructure\Time\Clock;
use TTLeague\Infrastructure\Time\FixedClock;
use TTLeague\Results\Actor;
use TTLeague\Results\ResultException;
use TTLeague\Results\ResultService;
use TTLeague\Tests\Integration\Frontend\Seeder;
use TTLeague\Tests\Integration\IntegrationTestCase;

/**
 * Erfundene Clubs Seefeld (Teams 1 und 2) und Wollishofen (Team 1) in einer Gruppe der
 * Kategorie B, dazu ein Cup. Die Uhr steht auf Dienstag, 10. November 2026, 22:30 Ortszeit,
 * eineinhalb Stunden nach Beginn der ersten Begegnung.
 */
final class ResultServiceTest extends IntegrationTestCase {

	private FixedClock $clock;

	private RecordingEvents $events;

	private ResultService $service;

	/** @var array<string,int> */
	private array $id = array();

	/** @var array<string,Actor> */
	private array $actor = array();

	public static function setUpBeforeClass(): void {
		require_once dirname( __DIR__, 2 ) . '/Unit/Frontend/Stubs/WordPress.php';
	}

	protected function setUp(): void {
		parent::setUp();
		$this->seed( array() );
	}

	/**
	 * @param array<string,mixed> $rules Saisonregeln.
	 */
	private function seed( array $rules ): void {
		$this->truncate_all();
		$s      = new Seeder( $this->db );
		$season = $s->season( '2026-27', 'active', $rules );
		$home   = $s->club( 'Seefeld', 1 );
		$away   = $s->club( 'Wollishofen', 2 );
		$group  = $s->competition( $season, 'MM', 'Gruppe B1', 'B', 1 );
		$cup    = $s->competition( $season, 'CUP', 'Cup A', 'A' );
		$h1     = $s->team( $season, $home, 1, 'Seefeld 1', $group );
		$h2     = $s->team( $season, $home, 2, 'Seefeld 2', $group );
		$a1     = $s->team( $season, $away, 1, 'Wollishofen 1', $group );

		$players = array();
		foreach ( array( 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'h7' ) as $i => $key ) {
			$players[ $key ] = $s->player( 'Seefelder', 'Spieler' . ( $i + 1 ) );
			$s->register( $players[ $key ], $season, $home, 10 - $i );
		}
		foreach ( array( 'a1', 'a2', 'a3', 'o1' ) as $i => $key ) {
			$players[ $key ] = $s->player( 'Wollishofer', 'Spieler' . ( $i + 1 ) );
			$s->register( $players[ $key ], $season, $away, 10 - $i );
		}
		$s->roster( $h1, array( $players['h1'], $players['h2'], $players['h3'] ) );
		$s->roster( $h2, array( $players['h4'], $players['h5'], $players['h6'] ) );
		$s->roster( $a1, array( $players['a1'], $players['a2'], $players['a3'] ) );

		$people = array(
			'captain_h1' => $s->person( 'Heim', 'Hanna' ),
			'captain_h2' => $s->person( 'Zweit', 'Zora' ),
			'captain_a1' => $s->person( 'Gast', 'Gustav' ),
			'chef_home'  => $s->person( 'Chef', 'Charlotte' ),
			'nobody'     => $s->person( 'Niemand', 'Nina' ),
		);
		$s->role( $season, $home, $people['captain_h1'], 'captain', $h1 );
		$s->role( $season, $home, $people['captain_h2'], 'captain', $h2 );
		$s->role( $season, $away, $people['captain_a1'], 'captain', $a1 );
		$s->role( $season, $home, $people['chef_home'], 'tk_chef' );

		$entry_home = $this->db->insert(
			'cup_entry',
			array(
				'competition_id' => $cup,
				'club_id'        => $home,
				'number'         => 1,
				'name'           => 'Seefeld 1',
				'player1_id'     => $players['h1'],
				'player2_id'     => $players['h2'],
			)
		);
		$entry_away = $this->db->insert(
			'cup_entry',
			array(
				'competition_id' => $cup,
				'club_id'        => $away,
				'number'         => 1,
				'name'           => 'Wollishofen 1',
				'player1_id'     => $players['a1'],
				'player2_id'     => $players['a2'],
			)
		);

		$this->id = $players + array(
			'season' => $season,
			'h1team' => $h1,
			'h2team' => $h2,
			'f1'     => $s->fixture(
				$group,
				array(
					'home_team_id' => $h1,
					'away_team_id' => $a1,
					'scheduled_at' => '2026-11-10 20:00:00',
				)
			),
			'derby'  => $s->fixture(
				$group,
				array(
					'home_team_id' => $h1,
					'away_team_id' => $h2,
					'scheduled_at' => '2026-11-17 20:00:00',
				)
			),
			'f3'     => $s->fixture(
				$group,
				array(
					'home_team_id' => $h1,
					'away_team_id' => $a1,
					'scheduled_at' => '2026-11-24 20:00:00',
				)
			),
			'f4'     => $s->fixture(
				$group,
				array(
					'home_team_id' => $h1,
					'away_team_id' => $a1,
					'scheduled_at' => '2026-12-01 20:00:00',
				)
			),
			'cup'    => $s->fixture(
				$cup,
				array(
					'home_entry_id' => $entry_home,
					'away_entry_id' => $entry_away,
					'scheduled_at'  => '2026-11-10 19:30:00',
				)
			),
		);

		$users = array(
			'captain_h1' => 101,
			'captain_a1' => 102,
			'chef_home'  => 104,
			'captain_h2' => 105,
			'nobody'     => 106,
		);
		foreach ( $users as $key => $user_id ) {
			$this->db->update( 'person', array( 'user_id' => $user_id ), array( 'id' => $people[ $key ] ) );
			$this->actor[ $key ] = new Actor( $user_id, $people[ $key ], false );
		}
		$this->actor['tk'] = new Actor( 103, null, true, 'TK' );

		$this->clock   = new FixedClock( '2026-11-10 21:30:00' );
		$this->events  = new RecordingEvents();
		$this->service = $this->service_with( $this->clock );
	}

	private function service_with( Clock $clock ): ResultService {
		return new ResultService(
			$this->db,
			new AccessPolicy( $this->db ),
			$clock,
			new \DateTimeZone( 'Europe/Zurich' ),
			new AuditLog( $this->db ),
			$this->events,
			static fn( int $id ): ?string => 103 === $id ? 'TK' : null
		);
	}

	/**
	 * Bindung eines Spielers im Kader: Team und Datum, oder null.
	 *
	 * @return array{0:int,1:string|null}|null
	 */
	private function binding_of( string $player ): ?array {
		$row = $this->db->get_row(
			"SELECT team_id, valid_from FROM {$this->db->table( 'team_roster' )} WHERE player_id = %d AND kind = 'bound'",
			array( $this->id[ $player ] )
		);
		return null === $row ? null : array( (int) $row['team_id'], $row['valid_from'] );
	}

	/**
	 * Seefeld 1 mit h4 (Stamm Seefeld 2) als Ersatz auf C gegen Wollishofen 1.
	 *
	 * @return array<string,mixed>
	 */
	private function with_substitute_h4(): array {
		return $this->team_payload(
			array(
				'A' => 'h1',
				'B' => 'h2',
				'C' => 'h4',
			),
			array(
				'X' => 'a1',
				'Y' => 'a2',
				'Z' => 'a3',
			),
			array( '3:1', '3:0', '3:2', '1:3', '3:1', '0:3', '3:2', '2:3', '1:3', '3:0' )
		);
	}

	/**
	 * Erfassung einer Meisterschaftsbegegnung. Resultate: «3:1», «wo:h», «wo:a», «np».
	 *
	 * @param array<string,string> $home    Slot => Spielerschlüssel.
	 * @param array<string,string> $away    Slot => Spielerschlüssel.
	 * @param list<string>         $results Zehn Resultate.
	 * @return array<string,mixed>
	 */
	private function team_payload( array $home, array $away, array $results ): array {
		$home  = array_map( fn( string $k ): int => $this->id[ $k ], $home );
		$away  = array_map( fn( string $k ): int => $this->id[ $k ], $away );
		$games = array();
		foreach ( GameOrder::team_match() as $i => $slot ) {
			$game = self::game( $slot['sequence'], $results[ $i ] );
			if ( GameOrder::DOUBLE === $slot['type'] ) {
				// Doppel: die ersten beiden Spieler jeder Seite.
				$game['home_players'] = array_slice( array_values( $home ), 0, 2 );
				$game['away_players'] = array_slice( array_values( $away ), 0, 2 );
			}
			$games[] = $game;
		}
		return array(
			'lineup' => array(
				'home' => $home,
				'away' => $away,
			),
			'games'  => $games,
		);
	}

	/**
	 * @return array<string,mixed>
	 */
	private static function game( int $sequence, string $result ): array {
		if ( 'np' === $result ) {
			return array(
				'sequence' => $sequence,
				'status'   => 'not_played',
			);
		}
		if ( str_starts_with( $result, 'wo:' ) ) {
			return array(
				'sequence' => $sequence,
				'status'   => 'walkover',
				'winner'   => 'wo:h' === $result ? 'home' : 'away',
			);
		}
		[ $h, $a ] = array_map( 'intval', explode( ':', $result ) );
		return array(
			'sequence'  => $sequence,
			'status'    => 'played',
			'sets_home' => $h,
			'sets_away' => $a,
		);
	}

	/**
	 * Standardaufstellung Seefeld 1 gegen Wollishofen 1, Heim gewinnt 6:4.
	 *
	 * @return array<string,mixed>
	 */
	private function six_four(): array {
		return $this->team_payload(
			array(
				'A' => 'h1',
				'B' => 'h2',
				'C' => 'h3',
			),
			array(
				'X' => 'a1',
				'Y' => 'a2',
				'Z' => 'a3',
			),
			array( '3:1', '3:0', '3:2', '1:3', '3:1', '0:3', '3:2', '2:3', '1:3', '3:0' )
		);
	}

	/**
	 * @param callable():mixed $call Aufruf.
	 */
	private static function expect_error( callable $call, int $status ): ResultException {
		try {
			$call();
		} catch ( ResultException $e ) {
			self::assertSame( $status, $e->status, $e->getMessage() . ' ' . json_encode( $e->errors ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
			return $e;
		}
		self::fail( 'ResultException mit Status ' . $status . ' erwartet.' );
	}

	public function test_entry_payload_for_home_captain(): void {
		$entry = $this->service->entry( $this->id['f1'], $this->actor['captain_h1'] );

		self::assertTrue( $entry['can']['enter'] );
		self::assertFalse( $entry['can']['confirm'] );
		self::assertFalse( $entry['can']['decide'] );
		self::assertSame( array( 'home' ), $entry['roles'] );
		self::assertSame( 'Seefeld 1', $entry['fixture']['home']['name'] );
		self::assertCount( 10, $entry['order'] );
		self::assertSame( array( 'A', 'B', 'C' ), $entry['rules']['slots']['home'] );
		self::assertNotSame( '', $entry['version'] );

		$home = $entry['candidates']['home'];
		self::assertSame( array( 'stamm', 'stamm', 'stamm' ), array_column( array_slice( $home, 0, 3 ), 'reason' ) );
		$by_id = array_column( $home, null, 'player_id' );
		self::assertSame( 'substitute', $by_id[ $this->id['h4'] ]['reason'], 'Stamm Seefeld 2 spielt als Ersatz in Seefeld 1' );
		self::assertTrue( $by_id[ $this->id['h4'] ]['eligible'] );
		self::assertSame( 3, $by_id[ $this->id['h4'] ]['remaining'] );
		self::assertArrayNotHasKey( $this->id['a1'], $by_id );
		self::assertCount( 4, $entry['candidates']['away'] );
	}

	public function test_entry_requires_a_role(): void {
		self::expect_error( fn() => $this->service->entry( $this->id['f1'], $this->actor['nobody'] ), 403 );
		self::expect_error( fn() => $this->service->entry( 999999, $this->actor['tk'] ), 404 );
	}

	public function test_home_captain_enters_result(): void {
		$result = $this->service->submit( $this->id['f1'], $this->six_four(), $this->actor['captain_h1'] );

		self::assertSame( 'provisional', $result['status'] );
		self::assertSame( 6, $result['home_score'] );
		self::assertSame( 4, $result['away_score'] );
		self::assertSame( array(), $result['warnings'] );

		$fixture = $this->db->get_row( "SELECT * FROM {$this->db->table( 'fixture' )} WHERE id = %d", array( $this->id['f1'] ) );
		self::assertSame( 'provisional', $fixture['status'] );
		self::assertSame( '101', $fixture['entered_by'] );
		self::assertSame( '2026-11-10 21:30:00', $fixture['entered_at'] );
		self::assertSame( '6', (string) $this->db->get_var( "SELECT COUNT(*) FROM {$this->db->table( 'lineup' )} WHERE fixture_id = %d", array( $this->id['f1'] ) ) );
		self::assertSame( '10', (string) $this->db->get_var( "SELECT COUNT(*) FROM {$this->db->table( 'game' )} WHERE fixture_id = %d", array( $this->id['f1'] ) ) );
		$double = $this->db->get_row( "SELECT * FROM {$this->db->table( 'game' )} WHERE fixture_id = %d AND sequence = 7", array( $this->id['f1'] ) );
		self::assertSame( (string) $this->id['h2'], $double['a2_player_id'] );

		$events = $this->service->repository()->events( $this->id['f1'] );
		self::assertSame( array( 'entered' ), array_column( $events, 'type' ) );
		self::assertSame( 'Hanna Heim', $events[0]['name'] );
		self::assertSame( '1', (string) $this->db->get_var( "SELECT COUNT(*) FROM {$this->db->table( 'audit_log' )} WHERE entity = 'fixture' AND action = 'result_entered'" ) );
		self::assertSame( array( array( 'entered', $this->id['f1'], 101, false ) ), $this->events->calls );
	}

	public function test_double_pairing_from_lineup_is_stored(): void {
		$payload                             = $this->six_four();
		$payload['games'][6]['home_players'] = array( $this->id['h1'], $this->id['h3'] );
		$payload['games'][6]['away_players'] = array( $this->id['a2'], $this->id['a3'] );
		$this->service->submit( $this->id['f1'], $payload, $this->actor['captain_h1'] );

		$double = $this->db->get_row( "SELECT * FROM {$this->db->table( 'game' )} WHERE fixture_id = %d AND sequence = 7", array( $this->id['f1'] ) );
		self::assertSame( array( (string) $this->id['h1'], (string) $this->id['h3'], (string) $this->id['a2'], (string) $this->id['a3'] ), array( $double['a1_player_id'], $double['a2_player_id'], $double['b1_player_id'], $double['b2_player_id'] ) );

		$payload['games'][6]['home_players'] = array( $this->id['h1'], $this->id['h7'] );
		$error                               = self::expect_error( fn() => $this->service->submit( $this->id['f1'], $payload, $this->actor['captain_h1'] ), 422 );
		self::assertSame( array( 'games.7.home_players' ), array_column( $error->errors, 'field' ), 'Spieler nicht in der Aufstellung' );

		$payload['games'][6]['home_players'] = array();
		$error                               = self::expect_error( fn() => $this->service->submit( $this->id['f1'], $payload, $this->actor['captain_h1'] ), 422 );
		self::assertSame( array( 'games.7.home_players' ), array_column( $error->errors, 'field' ), 'Paarung fehlt bei drei Spielern' );
	}

	public function test_only_home_side_or_tk_enters(): void {
		self::expect_error( fn() => $this->service->submit( $this->id['f1'], $this->six_four(), $this->actor['captain_a1'] ), 403 );
		self::expect_error( fn() => $this->service->submit( $this->id['f1'], $this->six_four(), $this->actor['nobody'] ), 403 );
		self::assertSame( 'provisional', $this->service->submit( $this->id['f1'], $this->six_four(), $this->actor['chef_home'] )['status'] );
		self::assertSame( 'provisional', $this->service->submit( $this->id['f1'], $this->six_four(), $this->actor['tk'] )['status'] );
	}

	public function test_ineligible_players_are_rejected(): void {
		$payload                             = $this->six_four();
		$payload['lineup']['home']['A']      = $this->id['a1'];
		$payload['lineup']['away']['X']      = $this->id['o1'];
		$payload['games'][6]['home_players'] = array( $this->id['a1'], $this->id['h2'] );
		$payload['games'][6]['away_players'] = array( $this->id['o1'], $this->id['a2'] );
		$error                               = self::expect_error( fn() => $this->service->submit( $this->id['f1'], $payload, $this->actor['captain_h1'] ), 422 );

		self::assertSame( array( 'lineup.home.A' ), array_column( $error->errors, 'field' ) );
		self::assertStringContainsString( 'Spieler1 Wollishofer', $error->errors[0]['message'] );
		self::assertStringContainsString( 'nicht für diesen Club gemeldet', $error->errors[0]['message'] );
	}

	public function test_missing_player_means_walkovers(): void {
		$results = array( '3:1', '3:0', '3:2', '1:3', '3:1', '0:3', '3:2', '2:3', '1:3', '3:0' );
		$payload = $this->team_payload(
			array(
				'A' => 'h1',
				'B' => 'h2',
			),
			array(
				'X' => 'a1',
				'Y' => 'a2',
				'Z' => 'a3',
			),
			$results
		);
		$error   = self::expect_error( fn() => $this->service->submit( $this->id['f1'], $payload, $this->actor['captain_h1'] ), 422 );
		self::assertSame( array( 'games.3', 'games.6', 'games.9' ), array_column( $error->errors, 'field' ) );

		$results[2] = 'wo:a';
		$results[5] = 'wo:a';
		$results[8] = 'wo:a';
		$payload    = $this->team_payload(
			array(
				'A' => 'h1',
				'B' => 'h2',
			),
			array(
				'X' => 'a1',
				'Y' => 'a2',
				'Z' => 'a3',
			),
			$results
		);
		$result     = $this->service->submit( $this->id['f1'], $payload, $this->actor['captain_h1'] );
		self::assertSame( array( 5, 5 ), array( $result['home_score'], $result['away_score'] ) );

		$double = $this->db->get_row( "SELECT * FROM {$this->db->table( 'game' )} WHERE fixture_id = %d AND sequence = 7", array( $this->id['f1'] ) );
		self::assertSame( array( (string) $this->id['h1'], (string) $this->id['h2'] ), array( $double['a1_player_id'], $double['a2_player_id'] ) );
	}

	public function test_invalid_set_results_are_rejected(): void {
		$payload                          = $this->six_four();
		$payload['games'][0]['sets_away'] = 3;
		$error                            = self::expect_error( fn() => $this->service->submit( $this->id['f1'], $payload, $this->actor['captain_h1'] ), 422 );
		self::assertSame( array( 'games.1' ), array_column( $error->errors, 'field' ) );

		$payload = $this->six_four();
		unset( $payload['games'][9] );
		$error = self::expect_error( fn() => $this->service->submit( $this->id['f1'], $payload, $this->actor['captain_h1'] ), 422 );
		self::assertSame( array( 'games.10' ), array_column( $error->errors, 'field' ) );
	}

	public function test_edit_and_confirm(): void {
		$this->service->submit( $this->id['f1'], $this->six_four(), $this->actor['captain_h1'] );
		$this->clock->advance( '+10 minutes' );
		$this->service->submit( $this->id['f1'], $this->six_four(), $this->actor['captain_h1'] );

		$entry = $this->service->entry( $this->id['f1'], $this->actor['captain_a1'] );
		self::assertTrue( $entry['can']['confirm'] );
		self::assertTrue( $entry['can']['object'] );
		self::assertFalse( $entry['can']['enter'] );
		self::assertSame( 'Hanna Heim', $entry['current']['entered_by'] );
		self::assertSame( '2026-11-10T21:40:00Z', $entry['current']['entered_at'] );

		$result = $this->service->confirm( $this->id['f1'], $this->actor['captain_a1'], $entry['version'] );
		self::assertSame( 'confirmed', $result['status'] );
		self::assertSame( array( 'entered', 'edited', 'confirmed' ), array_column( $this->service->repository()->events( $this->id['f1'] ), 'type' ) );
		self::assertSame( array( 'confirmed', $this->id['f1'], 102, false ), $this->events->calls[2] );

		self::expect_error( fn() => $this->service->confirm( $this->id['f1'], $this->actor['captain_a1'] ), 409 );
		self::expect_error( fn() => $this->service->submit( $this->id['f1'], $this->six_four(), $this->actor['captain_h1'] ), 409 );
	}

	public function test_stale_version_is_rejected(): void {
		$this->service->submit( $this->id['f1'], $this->six_four(), $this->actor['captain_h1'] );
		$old = $this->service->entry( $this->id['f1'], $this->actor['captain_a1'] )['version'];

		$payload                          = $this->six_four();
		$payload['games'][9]['sets_home'] = 2;
		$payload['games'][9]['sets_away'] = 3;
		$this->service->submit( $this->id['f1'], $payload, $this->actor['captain_h1'] );

		self::expect_error( fn() => $this->service->confirm( $this->id['f1'], $this->actor['captain_a1'], $old ), 409 );
	}

	public function test_nobody_confirms_own_entry_in_club_derby(): void {
		$payload = $this->team_payload(
			array(
				'A' => 'h1',
				'B' => 'h2',
				'C' => 'h3',
			),
			array(
				'X' => 'h4',
				'Y' => 'h5',
				'Z' => 'h6',
			),
			array( '3:1', '3:0', '3:2', '1:3', '3:1', '0:3', '3:2', '2:3', '1:3', '3:0' )
		);
		$this->service->submit( $this->id['derby'], $payload, $this->actor['chef_home'] );

		$entry = $this->service->entry( $this->id['derby'], $this->actor['chef_home'] );
		self::assertSame( array( 'home', 'away' ), $entry['roles'] );
		self::assertFalse( $entry['can']['confirm'] );
		self::expect_error( fn() => $this->service->confirm( $this->id['derby'], $this->actor['chef_home'] ), 403 );
		self::assertSame( 'confirmed', $this->service->confirm( $this->id['derby'], $this->actor['captain_h2'] )['status'] );
	}

	public function test_objection_then_tk_decides(): void {
		$this->service->submit( $this->id['f1'], $this->six_four(), $this->actor['captain_h1'] );

		$error = self::expect_error( fn() => $this->service->object( $this->id['f1'], $this->actor['captain_a1'], 'Falsch' ), 422 );
		self::assertSame( 'reason', $error->errors[0]['field'] );
		self::expect_error( fn() => $this->service->object( $this->id['f1'], $this->actor['captain_h1'], 'Das Doppel ging an uns.' ), 403 );

		self::assertSame( 'disputed', $this->service->object( $this->id['f1'], $this->actor['captain_a1'], 'Spiel 7 ging 3:2 an uns.' )['status'] );
		self::expect_error( fn() => $this->service->confirm( $this->id['f1'], $this->actor['captain_a1'] ), 409 );
		self::expect_error( fn() => $this->service->decide( $this->id['f1'], $this->actor['captain_a1'], array( 'action' => 'finalize' ) ), 403 );

		$result = $this->service->decide(
			$this->id['f1'],
			$this->actor['tk'],
			array(
				'action'  => 'finalize',
				'comment' => 'Spielbericht geprüft.',
			)
		);
		self::assertSame( 'final', $result['status'] );
		self::assertSame( array( 'entered', 'disputed', 'decided' ), array_column( $this->service->repository()->events( $this->id['f1'] ), 'type' ) );
		self::assertSame( array( 'decided', $this->id['f1'], 103, 'finalize', 'Spielbericht geprüft.' ), end( $this->events->calls ) );

		$history = $this->service->entry( $this->id['f1'], $this->actor['tk'] )['history'];
		self::assertSame( 'Spiel 7 ging 3:2 an uns.', $history[1]['comment'] );
		self::assertSame( 'TK', $history[2]['by'] );
	}

	public function test_tk_corrects_forfeits_and_resets(): void {
		$this->service->submit( $this->id['f1'], $this->six_four(), $this->actor['captain_h1'] );

		$corrected                          = $this->six_four();
		$corrected['games'][6]['sets_home'] = 1;
		$corrected['games'][6]['sets_away'] = 3;
		$result                             = $this->service->decide(
			$this->id['f1'],
			$this->actor['tk'],
			array(
				'action'  => 'correct',
				'result'  => $corrected,
				'comment' => 'Doppel korrigiert.',
			)
		);
		self::assertSame( array( 'final', 5, 5 ), array( $result['status'], $result['home_score'], $result['away_score'] ) );

		$result = $this->service->decide(
			$this->id['f1'],
			$this->actor['tk'],
			array(
				'action' => 'forfeit',
				'winner' => 'away',
			)
		);
		self::assertSame( array( 'forfeit', 0, 10 ), array( $result['status'], $result['home_score'], $result['away_score'] ) );
		self::assertSame( '0', (string) $this->db->get_var( "SELECT COUNT(*) FROM {$this->db->table( 'game' )} WHERE fixture_id = %d", array( $this->id['f1'] ) ) );

		$result = $this->service->decide( $this->id['f1'], $this->actor['tk'], array( 'action' => 'reset' ) );
		self::assertSame( array( 'scheduled', null, null ), array( $result['status'], $result['home_score'], $result['away_score'] ) );
		$fixture = $this->db->get_row( "SELECT entered_by, confirmed_by FROM {$this->db->table( 'fixture' )} WHERE id = %d", array( $this->id['f1'] ) );
		self::assertSame( array( null, null ), array( $fixture['entered_by'], $fixture['confirmed_by'] ) );
		self::assertSame( '4', (string) $this->db->get_var( "SELECT COUNT(*) FROM {$this->db->table( 'audit_log' )} WHERE entity = 'fixture' AND entity_id = %d", array( $this->id['f1'] ) ) );

		self::expect_error( fn() => $this->service->decide( $this->id['f1'], $this->actor['tk'], array( 'action' => 'finalize' ) ), 409 );
		self::expect_error( fn() => $this->service->decide( $this->id['f1'], $this->actor['tk'], array( 'action' => 'forfeit' ) ), 422 );
		self::expect_error( fn() => $this->service->decide( $this->id['f1'], $this->actor['tk'], array( 'action' => 'delete' ) ), 422 );
	}

	public function test_tk_correction_turns_eligibility_errors_into_warnings(): void {
		$payload = $this->team_payload(
			array(
				'A' => 'h4',
				'B' => 'h2',
				'C' => 'h3',
			),
			array(
				'X' => 'h1',
				'Y' => 'h5',
				'Z' => 'h6',
			),
			array( '3:1', '3:0', '3:2', '1:3', '3:1', '0:3', '3:2', '2:3', '1:3', '3:0' )
		);
		$error   = self::expect_error( fn() => $this->service->submit( $this->id['derby'], $payload, $this->actor['captain_h1'] ), 422 );
		self::assertSame( array( 'lineup.away.X' ), array_column( $error->errors, 'field' ), 'Stamm Seefeld 1 darf nicht in Seefeld 2 spielen' );

		$result = $this->service->decide(
			$this->id['derby'],
			$this->actor['tk'],
			array(
				'action' => 'correct',
				'result' => $payload,
			)
		);
		self::assertSame( 'final', $result['status'] );
		$warnings = $this->service->entry( $this->id['derby'], $this->actor['tk'] )['current']['warnings'];
		self::assertStringContainsString( 'Art. 12.7', implode( ' ', $warnings ) );
	}

	public function test_forfeit_and_reset_discard_warnings_and_reset_unlinks_the_photo(): void {
		$payload = $this->team_payload(
			array(
				'A' => 'h4',
				'B' => 'h2',
				'C' => 'h3',
			),
			array(
				'X' => 'h1',
				'Y' => 'h5',
				'Z' => 'h6',
			),
			array( '3:1', '3:0', '3:2', '1:3', '3:1', '0:3', '3:2', '2:3', '1:3', '3:0' )
		);
		$this->service->decide(
			$this->id['derby'],
			$this->actor['tk'],
			array(
				'action' => 'correct',
				'result' => $payload,
			)
		);
		$this->service->attach_photo( $this->id['derby'], $this->actor['tk'], 555 );
		self::assertNotSame( array(), $this->service->entry( $this->id['derby'], $this->actor['tk'] )['current']['warnings'] );

		$this->service->decide(
			$this->id['derby'],
			$this->actor['tk'],
			array(
				'action' => 'forfeit',
				'winner' => 'home',
			)
		);
		$current = $this->service->entry( $this->id['derby'], $this->actor['tk'] )['current'];
		self::assertSame( array(), $current['warnings'], 'das Forfait ersetzt die erfassten Spiele samt Hinweisen' );
		self::assertSame( 555, $current['photo_id'], 'das Foto bleibt beim Forfait als Beleg' );

		$this->service->decide( $this->id['derby'], $this->actor['tk'], array( 'action' => 'reset' ) );
		$entry = $this->service->entry( $this->id['derby'], $this->actor['tk'] );
		self::assertSame( array(), $entry['current']['warnings'] );
		self::assertNull( $entry['current']['photo_id'], 'zurücksetzen löst das Foto von der Begegnung' );
		self::assertContains( 'photo', array_column( $entry['history'], 'type' ), 'der Verlauf nennt das Foto weiterhin' );
	}

	public function test_third_substitute_appearance_binds_player(): void {
		$warnings = array();
		foreach ( array( 'f1', 'f3', 'f4' ) as $fixture ) {
			$warnings[ $fixture ] = implode( ' ', $this->service->submit( $this->id[ $fixture ], $this->with_substitute_h4(), $this->actor['captain_h1'] )['warnings'] );
		}
		self::assertStringContainsString( '1. von höchstens 3', $warnings['f1'] );
		self::assertStringContainsString( '2. von höchstens 3', $warnings['f3'] );
		self::assertStringContainsString( '3. Ersatzeinsatz', $warnings['f4'] );
		self::assertStringContainsString( 'erst am 01.12.2026 angesetzt', $warnings['f4'] );
		self::assertSame( array( $this->id['h1team'], '2026-12-01' ), $this->binding_of( 'h4' ) );
		self::assertSame( '1', (string) $this->db->get_var( "SELECT is_substitute FROM {$this->db->table( 'lineup' )} WHERE fixture_id = %d AND player_id = %d", array( $this->id['f4'], $this->id['h4'] ) ) );

		// Das Derby am 17.11. liegt vor dem dritten Einsatz: Dort spielt h4 noch frei für sein Stammteam.
		$away = array_column( $this->service->entry( $this->id['derby'], $this->actor['captain_h1'] )['candidates']['away'], null, 'player_id' );
		self::assertTrue( $away[ $this->id['h4'] ]['eligible'], 'spätere Einsätze zählen nicht (Art. 12.4)' );

		// Nach dem dritten Einsatz ist er an Seefeld 1 gebunden.
		$this->db->update( 'fixture', array( 'scheduled_at' => '2026-12-08 20:00:00' ), array( 'id' => $this->id['derby'] ) );
		$away = array_column( $this->service->entry( $this->id['derby'], $this->actor['captain_h1'] )['candidates']['away'], null, 'player_id' );
		self::assertFalse( $away[ $this->id['h4'] ]['eligible'], 'gebunden an Seefeld 1' );
		self::assertSame( 'blocked_bound_elsewhere', $away[ $this->id['h4'] ]['reason'] );
	}

	public function test_only_earlier_appearances_count_when_results_come_out_of_order(): void {
		$warnings = array();
		foreach ( array( 'f4', 'f3', 'f1' ) as $fixture ) {
			$warnings[ $fixture ] = implode( ' ', $this->service->submit( $this->id[ $fixture ], $this->with_substitute_h4(), $this->actor['captain_h1'] )['warnings'] );
		}
		foreach ( $warnings as $fixture => $text ) {
			self::assertStringContainsString( '1. von höchstens 3', $text, $fixture . ': keine früheren Einsätze erfasst' );
		}
		self::assertSame( array( $this->id['h1team'], '2026-12-01' ), $this->binding_of( 'h4' ), 'gebunden ab dem dritten Einsatz in zeitlicher Folge' );
	}

	public function test_reset_and_correction_recompute_the_binding(): void {
		foreach ( array( 'f1', 'f3', 'f4' ) as $fixture ) {
			$this->service->submit( $this->id[ $fixture ], $this->with_substitute_h4(), $this->actor['captain_h1'] );
		}
		self::assertNotNull( $this->binding_of( 'h4' ) );

		$this->service->decide( $this->id['f3'], $this->actor['tk'], array( 'action' => 'reset' ) );
		self::assertNull( $this->binding_of( 'h4' ), 'ohne den zweiten Einsatz keine Bindung' );

		$this->service->submit( $this->id['f3'], $this->with_substitute_h4(), $this->actor['captain_h1'] );
		self::assertSame( array( $this->id['h1team'], '2026-12-01' ), $this->binding_of( 'h4' ) );

		$this->service->decide(
			$this->id['f4'],
			$this->actor['tk'],
			array(
				'action' => 'correct',
				'result' => $this->six_four(),
			)
		);
		self::assertNull( $this->binding_of( 'h4' ), 'die Korrektur ohne h4 hebt die Bindung auf' );
	}

	public function test_a_change_between_check_and_write_is_rejected_even_with_matching_version(): void {
		$clock   = new TriggerClock( new FixedClock( '2026-11-10 21:30:00' ) );
		$service = $this->service_with( $clock );
		$service->submit( $this->id['f1'], $this->six_four(), $this->actor['captain_h1'] );
		$version = $service->entry( $this->id['f1'], $this->actor['captain_a1'] )['version'];

		// Die Heimseite ändert das Resultat, während die Gastseite die alte Fassung bestätigt.
		$changed                          = $this->six_four();
		$changed['games'][6]['sets_home'] = 1;
		$changed['games'][6]['sets_away'] = 3;
		$clock->before_next               = fn() => $this->service->submit( $this->id['f1'], $changed, $this->actor['captain_h1'] );
		self::expect_error( fn() => $service->confirm( $this->id['f1'], $this->actor['captain_a1'], $version ), 409 );

		$fixture = $this->db->get_row( "SELECT status, home_score, away_score, confirmed_by FROM {$this->db->table( 'fixture' )} WHERE id = %d", array( $this->id['f1'] ) );
		self::assertSame( array( 'provisional', '5', '5', null ), array( $fixture['status'], (string) $fixture['home_score'], (string) $fixture['away_score'], $fixture['confirmed_by'] ) );

		// Ebenso ohne mitgeschickte Version: Einsprache und Entscheid der TK überschneiden sich.
		$clock->before_next = fn() => $this->service->decide( $this->id['f1'], $this->actor['tk'], array( 'action' => 'reset' ) );
		self::expect_error( fn() => $service->object( $this->id['f1'], $this->actor['captain_a1'], 'Das Doppel ging an uns.' ), 409 );
		self::assertSame( 'scheduled', $this->db->get_var( "SELECT status FROM {$this->db->table( 'fixture' )} WHERE id = %d", array( $this->id['f1'] ) ) );
	}

	/**
	 * @return array<string,array{0:string}>
	 */
	public static function write_paths(): array {
		$paths = array();
		foreach ( array( 'submit', 'confirm', 'tk_confirm', 'object', 'correct', 'finalize', 'forfeit', 'reset', 'photo' ) as $path ) {
			$paths[ $path ] = array( $path );
		}
		return $paths;
	}

	/**
	 * Jeder Schreibvorgang liest die Begegnung in seiner Transaktion neu. Ändert die Heimseite das
	 * Resultat genau zwischen Prüfung und Schreiben, wird er mit 409 abgewiesen und schreibt nichts.
	 */
	#[DataProvider( 'write_paths' )]
	public function test_every_write_rejects_a_change_between_check_and_write( string $path ): void {
		$clock   = new TriggerClock( new FixedClock( '2026-11-10 21:30:00' ) );
		$service = $this->service_with( $clock );
		$service->submit( $this->id['f1'], $this->six_four(), $this->actor['captain_h1'] );
		$events = count( $service->repository()->events( $this->id['f1'] ) );

		$changed                          = $this->six_four();
		$changed['games'][6]['sets_home'] = 1;
		$changed['games'][6]['sets_away'] = 3;
		$clock->before_next               = fn() => $this->service->submit( $this->id['f1'], $changed, $this->actor['captain_h1'] );
		$f1                               = $this->id['f1'];
		$tk                               = $this->actor['tk'];
		$call                             = match ( $path ) {
			'submit'   => fn() => $service->submit( $f1, $this->six_four(), $this->actor['chef_home'] ),
			'confirm'  => fn() => $service->confirm( $f1, $this->actor['captain_a1'] ),
			'tk_confirm' => fn() => $service->decide( $f1, $tk, array( 'action' => 'confirm' ) ),
			'object'   => fn() => $service->object( $f1, $this->actor['captain_a1'], 'Das Doppel ging an uns.' ),
			'correct'  => fn() => $service->decide(
				$f1,
				$tk,
				array(
					'action' => 'correct',
					'result' => $this->six_four(),
				)
			),
			'finalize' => fn() => $service->decide( $f1, $tk, array( 'action' => 'finalize' ) ),
			'forfeit'  => fn() => $service->decide(
				$f1,
				$tk,
				array(
					'action' => 'forfeit',
					'winner' => 'away',
				)
			),
			'reset'    => fn() => $service->decide( $f1, $tk, array( 'action' => 'reset' ) ),
			'photo'    => fn() => $service->attach_photo( $f1, $this->actor['captain_h1'], 555 ),
		};
		self::expect_error( $call, 409 );

		$fixture = $this->db->get_row( "SELECT status, home_score, away_score, confirmed_by, photo_attachment_id FROM {$this->db->table( 'fixture' )} WHERE id = %d", array( $f1 ) );
		self::assertSame( array( 'provisional', '5', '5', null, null ), array( $fixture['status'], (string) $fixture['home_score'], (string) $fixture['away_score'], $fixture['confirmed_by'], $fixture['photo_attachment_id'] ) );
		self::assertCount( $events + 1, $service->repository()->events( $f1 ), 'nur das Ändern der Heimseite steht im Verlauf' );
	}

	public function test_stale_version_is_rejected_for_submit_and_decisions(): void {
		$this->service->submit( $this->id['f1'], $this->six_four(), $this->actor['captain_h1'] );
		$old                              = $this->service->entry( $this->id['f1'], $this->actor['tk'] )['version'];
		$changed                          = $this->six_four();
		$changed['games'][9]['sets_home'] = 2;
		$changed['games'][9]['sets_away'] = 3;
		$this->service->submit( $this->id['f1'], $changed, $this->actor['captain_h1'] );

		$stale            = $this->six_four();
		$stale['version'] = $old;
		self::expect_error( fn() => $this->service->submit( $this->id['f1'], $stale, $this->actor['captain_h1'] ), 409 );
		foreach ( array( 'confirm', 'finalize', 'reset' ) as $action ) {
			self::expect_error(
				fn() => $this->service->decide(
					$this->id['f1'],
					$this->actor['tk'],
					array(
						'action'  => $action,
						'version' => $old,
					)
				),
				409
			);
		}
		self::assertSame( 'provisional', $this->db->get_var( "SELECT status FROM {$this->db->table( 'fixture' )} WHERE id = %d", array( $this->id['f1'] ) ) );
	}

	public function test_appearances_without_date_do_not_bind(): void {
		$this->db->update( 'fixture', array( 'scheduled_at' => null ), array( 'id' => $this->id['f3'] ) );
		foreach ( array( 'f1', 'f3', 'f4' ) as $fixture ) {
			$this->service->submit( $this->id[ $fixture ], $this->with_substitute_h4(), $this->actor['captain_h1'] );
		}
		self::assertNull( $this->binding_of( 'h4' ), 'zwei Einsätze mit Termin binden nicht' );
	}

	public function test_confirmation_by_the_tk_is_a_decision_with_comment(): void {
		$this->service->submit( $this->id['f1'], $this->six_four(), $this->actor['captain_h1'] );
		// Ein TK-Mitglied, das zugleich Captain der Gastseite ist, bestätigt als TK.
		$tk_away = new Actor( 102, $this->actor['captain_a1']->person_id, true );
		$this->service->decide(
			$this->id['f1'],
			$tk_away,
			array(
				'action'  => 'confirm',
				'comment' => 'Matchblatt geprüft.',
			)
		);
		$events = $this->service->repository()->events( $this->id['f1'] );
		$last   = $events[ count( $events ) - 1 ];
		self::assertSame( array( 'confirmed', 'Matchblatt geprüft.', true ), array( $last['type'], $last['comment'], $last['data']['by_tk'] ) );
		self::assertSame( array( 'confirmed', $this->id['f1'], 102, true ), $this->events->calls[ count( $this->events->calls ) - 1 ] );
	}

	public function test_cup_side_needs_two_players(): void {
		$error = self::expect_error(
			fn() => $this->service->submit(
				$this->id['cup'],
				array(
					'lineup' => array(
						'home' => array(
							'A' => $this->id['h1'],
							'B' => null,
						),
						'away' => array(
							'Y' => $this->id['a1'],
							'Z' => $this->id['a2'],
						),
					),
					'games'  => array( self::game( 1, '3:0' ), self::game( 2, 'wo:a' ), self::game( 3, 'wo:a' ), self::game( 4, 'wo:a' ) ),
				),
				$this->actor['chef_home']
			),
			422
		);
		self::assertContains( 'lineup.home', array_column( $error->errors, 'field' ) );
	}

	public function test_cup_is_decided_at_third_win(): void {
		$lineup = array(
			'home' => array(
				'A' => $this->id['h1'],
				'B' => $this->id['h2'],
			),
			'away' => array(
				'Y' => $this->id['a1'],
				'Z' => $this->id['a2'],
			),
		);
		$result = $this->service->submit(
			$this->id['cup'],
			array(
				'lineup' => $lineup,
				'games'  => array( self::game( 1, '3:0' ), self::game( 2, '3:1' ), self::game( 3, '3:2' ) ),
			),
			$this->actor['chef_home']
		);
		self::assertSame( array( 3, 0 ), array( $result['home_score'], $result['away_score'] ) );
		self::assertSame( array(), $result['warnings'] );
		self::expect_error(
			fn() => $this->service->submit(
				$this->id['cup'],
				array(
					'lineup' => $lineup,
					'games'  => array(),
				),
				$this->actor['captain_h1']
			),
			403
		);

		$result = $this->service->submit(
			$this->id['cup'],
			array(
				'lineup' => $lineup,
				'games'  => array( self::game( 1, '3:0' ), self::game( 2, '3:1' ), self::game( 3, '3:2' ), self::game( 4, '0:3' ), self::game( 5, 'np' ) ),
			),
			$this->actor['chef_home']
		);
		self::assertSame( array( 3, 0 ), array( $result['home_score'], $result['away_score'] ), 'Spiele nach dem dritten Sieg zählen nicht' );
		self::assertSame(
			array( '1', '1', '1', '0', '0' ),
			$this->db->get_col( "SELECT counts_for_result FROM {$this->db->table( 'game' )} WHERE fixture_id = %d ORDER BY sequence", array( $this->id['cup'] ) )
		);

		$error = self::expect_error(
			fn() => $this->service->submit(
				$this->id['cup'],
				array(
					'lineup' => $lineup,
					'games'  => array( self::game( 1, '3:0' ), self::game( 2, '3:1' ), self::game( 3, '2:3' ) ),
				),
				$this->actor['chef_home']
			),
			422
		);
		self::assertSame( array( 'games.4', 'games.5' ), array_column( $error->errors, 'field' ), 'Bei 2:1 fehlen Spiele' );
	}

	public function test_cup_substitutes_from_club_are_noted(): void {
		$result = $this->service->submit(
			$this->id['cup'],
			array(
				'lineup' => array(
					'home' => array(
						'A' => $this->id['h1'],
						'B' => $this->id['h5'],
					),
					'away' => array(
						'Y' => $this->id['a1'],
						'Z' => $this->id['a2'],
					),
				),
				'games'  => array( self::game( 1, '3:0' ), self::game( 2, '3:1' ), self::game( 3, '3:2' ) ),
			),
			$this->actor['chef_home']
		);
		self::assertStringContainsString( 'nicht für diese Cup-Mannschaft gemeldet', implode( ' ', $result['warnings'] ) );

		$error = self::expect_error(
			fn() => $this->service->submit(
				$this->id['cup'],
				array(
					'lineup' => array(
						'home' => array(
							'A' => $this->id['h1'],
							'B' => $this->id['a3'],
						),
						'away' => array(
							'Y' => $this->id['a1'],
							'Z' => $this->id['a2'],
						),
					),
					'games'  => array( self::game( 1, '3:0' ), self::game( 2, '3:1' ), self::game( 3, '3:2' ) ),
				),
				$this->actor['chef_home']
			),
			422
		);
		self::assertContains( 'lineup.home.B', array_column( $error->errors, 'field' ) );
	}

	public function test_set_points_when_enabled(): void {
		$this->seed( array( 'set_points_enabled' => true ) );
		$payload                           = $this->six_four();
		$payload['games'][0]['set_points'] = array( array( 11, 8 ), array( 9, 11 ), array( 11, 5 ), array( 12, 10 ) );
		$this->service->submit( $this->id['f1'], $payload, $this->actor['captain_h1'] );

		self::assertSame(
			array( '11', '9', '11', '12' ),
			$this->db->get_col(
				"SELECT gs.points_a FROM {$this->db->table( 'game_set' )} gs JOIN {$this->db->table( 'game' )} g ON g.id = gs.game_id WHERE g.fixture_id = %d AND g.sequence = 1 ORDER BY gs.set_no",
				array( $this->id['f1'] )
			)
		);
		$games = $this->service->repository()->games( $this->id['f1'] );
		self::assertSame( array( array( 11, 8 ), array( 9, 11 ), array( 11, 5 ), array( 12, 10 ) ), $games[0]['set_points'] );

		$payload['games'][0]['set_points'] = array( array( 11, 8 ), array( 9, 11 ), array( 11, 5 ), array( 11, 10 ) );
		$error                             = self::expect_error( fn() => $this->service->submit( $this->id['f1'], $payload, $this->actor['captain_h1'] ), 422 );
		self::assertSame( array( 'games.1.set_points' ), array_column( $error->errors, 'field' ) );
	}

	public function test_late_entry_is_noted(): void {
		$this->clock->advance( '+3 days' );
		$warnings = $this->service->submit( $this->id['f1'], $this->six_four(), $this->actor['captain_h1'] )['warnings'];
		self::assertSame( array( 'Erfasst nach Ablauf der Frist von 48 Stunden.' ), $warnings );
	}

	public function test_my_fixtures(): void {
		$home = $this->service->my_fixtures( $this->actor['captain_h1'] );
		self::assertSame( array( $this->id['f1'] ), array_column( $home['to_enter'], 'id' ) );
		self::assertSame( array( $this->id['derby'], $this->id['f3'], $this->id['f4'] ), array_column( $home['upcoming'], 'id' ) );
		self::assertSame( array(), $this->service->my_fixtures( $this->actor['captain_a1'] )['to_confirm'] );

		$this->service->submit( $this->id['f1'], $this->six_four(), $this->actor['captain_h1'] );
		$away = $this->service->my_fixtures( $this->actor['captain_a1'] );
		self::assertSame( array( $this->id['f1'] ), array_column( $away['to_confirm'], 'id' ) );
		self::assertSame( 'away', $away['to_confirm'][0]['side'] );
		self::assertSame( array(), $this->service->my_fixtures( $this->actor['captain_h1'] )['to_enter'] );
		self::assertSame( array(), $this->service->my_fixtures( $this->actor['nobody'] )['upcoming'] );
	}

	public function test_photo_link(): void {
		$this->service->attach_photo( $this->id['f1'], $this->actor['captain_h1'], 555 );
		self::assertSame( '555', $this->db->get_var( "SELECT photo_attachment_id FROM {$this->db->table( 'fixture' )} WHERE id = %d", array( $this->id['f1'] ) ) );
		self::expect_error( fn() => $this->service->attach_photo( $this->id['f1'], $this->actor['captain_a1'], 556 ), 403 );
	}
}
