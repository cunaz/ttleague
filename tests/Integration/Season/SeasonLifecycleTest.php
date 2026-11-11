<?php
/**
 * Integrationstests: Freigabe, Rückzug einer Mannschaft, Abschluss der Saison.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Tests\Integration\Season;

use PHPUnit\Framework\Attributes\DataProvider;
use TTLeague\Access\AccessPolicy;
use TTLeague\Domain\Season\LifecycleRules;
use TTLeague\Infrastructure\AuditLog;
use TTLeague\Infrastructure\Time\FixedClock;
use TTLeague\ReadModel\Cache\NullCache;
use TTLeague\ReadModel\ReadModel;
use TTLeague\Results\ResultException;
use TTLeague\Results\ResultService;
use TTLeague\Season\SeasonException as Refusal;
use TTLeague\Tests\Integration\Results\RecordingEvents;

/**
 * Daten siehe SeasonTestCase.
 */
final class SeasonLifecycleTest extends SeasonTestCase {

	/**
	 * Geplante Saison, die sich freigeben lässt: geprüfte Meldungen, Einteilung, Spielplan ohne
	 * harte Kollision; die aktive Saison ist fertig gespielt und klassiert.
	 */
	private function ready(): int {
		$next = $this->scheduled();
		$this->db->update( 'season', array( 'classification_closed_at' => '2027-06-20 18:00:00' ), array( 'id' => $this->id['active'] ) );
		return $next;
	}

	/**
	 * @return list<string>
	 */
	private function open( int $season_id ): array {
		return array_column( $this->lifecycle->readiness( $season_id )['open'], 'code' );
	}

	/**
	 * Begegnung der aktiven Saison.
	 *
	 * @param array<string,int|string|null> $data Abweichende Felder.
	 */
	private function active_fixture( string $home, string $away, array $data = array() ): int {
		return $this->seed->fixture(
			$this->id[ 'group_' . strtolower( self::TEAMS[ $home ][2] ) ],
			$data + array(
				'home_team_id' => $this->id[ $home ],
				'away_team_id' => $this->id[ $away ],
				'scheduled_at' => '2026-10-06 20:00:00',
				'status'       => 'scheduled',
			)
		);
	}

	/**
	 * Erfassung mit Benachrichtigungen, wie sie die Website verwendet.
	 */
	private function result_service( RecordingEvents $events ): ResultService {
		return new ResultService( $this->db, new AccessPolicy( $this->db ), new FixedClock( '2027-09-08 20:30:00' ), new \DateTimeZone( 'Europe/Zurich' ), new AuditLog( $this->db ), $events );
	}

	public function test_a_complete_season_is_published_and_the_active_one_archived(): void {
		$this->league();
		$next = $this->ready();
		self::assertSame( array(), $this->open( $next ) );
		$this->events->changed = array();

		$this->lifecycle->publish( $this->tk, $next, '2027/28' );

		self::assertSame( 'active', $this->season_status( $next ) );
		self::assertSame( 'archived', $this->season_status( $this->id['active'] ) );
		self::assertSame( $next, $this->seasons->active()['id'] );
		self::assertNull( $this->seasons->planned() );
		self::assertSame( 1, $this->rows( 'season', "status = 'active'" ) );
		self::assertSame( array( array( $next, $this->id['active'], 900 ) ), $this->events->published );
		self::assertSame( 12, $this->rows( 'fixture', "status = 'scheduled'" ), 'Die Begegnungen des Entwurfs bleiben, wie sie sind.' );
	}

	public function test_the_publication_is_logged_for_both_seasons(): void {
		$this->league();
		$next = $this->ready();

		$this->lifecycle->publish( $this->tk, $next, '2027/28' );

		$published = (array) $this->db->get_row( "SELECT * FROM {$this->db->table( 'audit_log' )} WHERE entity = 'season' AND action = 'published'" );
		self::assertSame( (string) $next, $published['entity_id'] );
		self::assertSame( '900', $published['user_id'] );
		self::assertSame( array( 'status' => 'planned' ), json_decode( (string) $published['before_json'], true ) );
		$after = json_decode( (string) $published['after_json'], true );
		self::assertSame( 'active', $after['status'] );
		self::assertSame( $this->id['active'], $after['archived'] );
		$archived = (array) $this->db->get_row( "SELECT * FROM {$this->db->table( 'audit_log' )} WHERE entity = 'season' AND action = 'archived'" );
		self::assertSame( (string) $this->id['active'], $archived['entity_id'] );
		self::assertStringContainsString( '2027/28', (string) $archived['note'] );
	}

	public function test_the_name_of_the_season_confirms_the_publication(): void {
		$this->league();
		$next = $this->ready();

		foreach ( array( '', '2026/27', '2027-28', 'ja' ) as $wrong ) {
			$refusal = $this->refused( fn() => $this->lifecycle->publish( $this->tk, $next, $wrong ), Refusal::INVALID );
			self::assertSame( array( 'confirm' ), array_column( $refusal->errors, 'field' ) );
		}
		self::assertSame( 'planned', $this->season_status( $next ) );
		self::assertSame( 'active', $this->season_status( $this->id['active'] ) );
		self::assertSame( array(), $this->events->published );

		$this->lifecycle->publish( $this->tk, $next, ' 2027/28 ' );
		self::assertSame( 'active', $this->season_status( $next ) );
	}

	public function test_only_the_tk_publishes_and_only_the_planned_season(): void {
		$this->league();
		$next = $this->ready();

		$this->refused( fn() => $this->lifecycle->publish( $this->chef, $next, '2027/28' ), Refusal::FORBIDDEN );
		$this->refused( fn() => $this->lifecycle->publish( $this->tk, $this->id['active'], '2026/27' ), Refusal::CONFLICT );
		$this->refused( fn() => $this->lifecycle->publish( $this->tk, 9999, '2027/28' ), Refusal::NOT_FOUND );
		self::assertSame( 'planned', $this->season_status( $next ) );

		$this->lifecycle->publish( $this->tk, $next, '2027/28' );
		$this->refused( fn() => $this->lifecycle->publish( $this->tk, $next, '2027/28' ), Refusal::CONFLICT );
		self::assertCount( 1, $this->events->published );
	}

	/**
	 * @return array<string,array{0:string,1:string}>
	 */
	public static function missing_prerequisites(): array {
		return array(
			'Meldung nur eingereicht' => array( 'submitted', LifecycleRules::REGISTRATIONS ),
			'Meldung offen'           => array( 'open', LifecycleRules::REGISTRATIONS ),
			'Mannschaft ohne Meldung' => array( 'club_without_registration', LifecycleRules::REGISTRATIONS ),
			'Mannschaft ohne Gruppe'  => array( 'unassigned', LifecycleRules::UNASSIGNED ),
			'Gruppe ohne Spielplan'   => array( 'no_schedule', LifecycleRules::NO_SCHEDULE ),
			'Spielplan passt nicht'   => array( 'stale_schedule', LifecycleRules::NO_SCHEDULE ),
			'Begegnung ohne Termin'   => array( 'no_date', LifecycleRules::UNCHECKED ),
			'ungültige Regeln'        => array( 'rules', LifecycleRules::RULES ),
		);
	}

	#[DataProvider( 'missing_prerequisites' )]
	public function test_each_missing_prerequisite_prevents_the_publication( string $what, string $code ): void {
		$this->league();
		$next = $this->ready();
		switch ( $what ) {
			case 'submitted':
			case 'open':
				$this->db->update(
					'club_season',
					array( 'status' => $what ),
					array(
						'season_id' => $next,
						'club_id'   => $this->id['club_oerlikon'],
					)
				);
				break;
			case 'club_without_registration':
				$this->db->delete(
					'club_season',
					array(
						'season_id' => $next,
						'club_id'   => $this->id['club_oerlikon'],
					)
				);
				break;
			case 'unassigned':
				$this->seed->team( $next, $this->id['club_altstetten'], 2, 'Altstetten 2', null, $this->id['venue_altstetten'] );
				break;
			case 'no_schedule':
				$this->scheduling->clear( $this->tk, $next, $this->id['next_group_b'] );
				break;
			case 'stale_schedule':
				$this->seed->team( $next, $this->id['club_altstetten'], 2, 'Altstetten 2', $this->id['next_group_b'], $this->id['venue_altstetten'], '19:45:00' );
				break;
			case 'no_date':
				$this->db->update( 'fixture', array( 'scheduled_at' => null ), array( 'home_team_id' => $this->id['next_oerlikon_1'] ) );
				break;
			default:
				$this->db->update( 'season', array( 'rules_json' => '{"sets_to_win":0}' ), array( 'id' => $next ) );
		}

		self::assertContains( $code, $this->open( $next ) );
		$refusal = $this->refused( fn() => $this->lifecycle->publish( $this->tk, $next, '2027/28', LifecycleRules::CONFIRMABLE ), Refusal::INVALID );

		self::assertContains( $code, array_column( $refusal->errors, 'field' ) );
		self::assertSame( 'planned', $this->season_status( $next ) );
		self::assertSame( 'active', $this->season_status( $this->id['active'] ) );
		self::assertSame( array(), $this->events->published );
		self::assertSame( 0, $this->rows( 'audit_log', "action IN ( 'published', 'archived' )" ) );
	}

	public function test_fixtures_without_date_are_counted_in_singular_and_plural(): void {
		$this->league();
		$next = $this->ready();
		$ids  = $this->db->get_col( "SELECT id FROM {$this->db->table( 'fixture' )} WHERE home_team_id = %d ORDER BY id", array( $this->id['next_oerlikon_1'] ) );
		self::assertGreaterThanOrEqual( 2, count( $ids ) );

		$this->db->update( 'fixture', array( 'scheduled_at' => null ), array( 'id' => (int) $ids[0] ) );
		self::assertSame( '1 Begegnung hat keinen Termin oder kein Lokal.', $this->lifecycle->readiness( $next )['facts']['schedule_error'] );

		$this->db->update( 'fixture', array( 'venue_id' => null ), array( 'id' => (int) $ids[1] ) );
		self::assertSame( '2 Begegnungen haben keinen Termin oder kein Lokal.', $this->lifecycle->readiness( $next )['facts']['schedule_error'] );
	}

	public function test_the_list_names_what_is_missing(): void {
		$this->league();
		$next = $this->plan();
		$this->db->update(
			'club_season',
			array( 'status' => 'open' ),
			array(
				'season_id' => $next,
				'club_id'   => $this->id['club_seefeld'],
			)
		);

		$state = $this->lifecycle->readiness( $next );

		self::assertSame( array( LifecycleRules::REGISTRATIONS, LifecycleRules::UNASSIGNED, LifecycleRules::PREDECESSOR ), array_column( $state['open'], 'code' ) );
		self::assertStringContainsString( 'TTC Seefeld', $state['open'][0]['message'] );
		self::assertStringContainsString( 'Seefeld 1', $state['open'][1]['message'] );
		self::assertSame( 6, $state['facts']['teams'] );
		self::assertCount( 6, $state['facts']['unassigned'] );
	}

	public function test_a_season_without_teams_cannot_be_published(): void {
		$this->league();
		$next = $this->plan( array() );
		self::assertContains( LifecycleRules::NO_TEAMS, $this->open( $next ) );
		$this->refused( fn() => $this->lifecycle->publish( $this->tk, $next, '2027/28', LifecycleRules::CONFIRMABLE ), Refusal::INVALID );
	}

	public function test_hard_collisions_must_be_resolved_or_confirmed_explicitly(): void {
		$this->league( 2 );
		$next = $this->ready();
		self::assertSame( array( LifecycleRules::CONFLICTS ), $this->open( $next ) );

		$refusal = $this->refused( fn() => $this->lifecycle->publish( $this->tk, $next, '2027/28' ), Refusal::INVALID );
		self::assertStringContainsString( '4 harte Kollisionen', $refusal->errors[0]['message'] );
		$this->refused( fn() => $this->lifecycle->publish( $this->tk, $next, '2027/28', array( LifecycleRules::PREDECESSOR ) ), Refusal::INVALID );
		self::assertSame( 'planned', $this->season_status( $next ) );

		$this->lifecycle->publish( $this->tk, $next, '2027/28', array( LifecycleRules::CONFLICTS ) );

		self::assertSame( 'active', $this->season_status( $next ) );
		$after = json_decode( (string) $this->db->get_var( "SELECT after_json FROM {$this->db->table( 'audit_log' )} WHERE action = 'published'" ), true );
		self::assertSame( array( LifecycleRules::CONFLICTS ), $after['confirmed'] );
		self::assertSame( 4, $after['hard_conflicts'] );
	}

	public function test_resolved_collisions_need_no_confirmation(): void {
		$this->league( 2 );
		$next = $this->ready();
		$hard = static fn( array $overview ): array => array_values( array_filter( $overview['conflicts'], static fn( array $conflict ): bool => $conflict['hard'] ) );
		$open = $hard( $this->scheduling->overview( $next ) );
		while ( array() !== $open ) {
			$this->scheduling->apply_suggestion( $this->tk, $open[0]['suggestions'][0]['fixture_id'], $open[0]['suggestions'][0]['starts_at'] );
			$open = $hard( $this->scheduling->overview( $next ) );
		}

		$this->lifecycle->publish( $this->tk, $next, '2027/28' );

		self::assertSame( 'active', $this->season_status( $next ) );
	}

	public function test_an_unfinished_active_season_must_be_confirmed(): void {
		$this->league();
		$next = $this->scheduled();
		$this->active_fixture( 'seefeld_1', 'oerlikon_1' );
		$this->active_fixture( 'oerlikon_1', 'seefeld_1', array( 'status' => 'final' ) );

		$state = $this->lifecycle->readiness( $next );
		self::assertSame( array( LifecycleRules::PREDECESSOR ), array_column( $state['open'], 'code' ) );
		self::assertSame( 1, $state['facts']['predecessor_open_fixtures'] );
		self::assertFalse( $state['facts']['predecessor_classification_closed'] );
		$this->refused( fn() => $this->lifecycle->publish( $this->tk, $next, '2027/28' ), Refusal::INVALID );
		$this->refused( fn() => $this->lifecycle->publish( $this->tk, $next, '2027/28', array( LifecycleRules::CONFLICTS ) ), Refusal::INVALID );
		self::assertSame( 'active', $this->season_status( $this->id['active'] ) );

		$this->lifecycle->publish( $this->tk, $next, '2027/28', array( LifecycleRules::PREDECESSOR ) );

		self::assertSame( 'archived', $this->season_status( $this->id['active'] ) );
		self::assertSame( 'active', $this->season_status( $next ) );
	}

	public function test_a_failure_in_the_middle_leaves_both_seasons_as_they_were(): void {
		$this->league();
		$next = $this->ready();
		// Die bisher aktive Saison ist schon archiviert, wenn das Aktivieren der neuen scheitert.
		$archived = false;
		$this->services(
			new FailingDb(
				$this->db,
				static function ( string $kind, string $table, array $data ) use ( &$archived ): bool {
					if ( 'update' !== $kind || 'season' !== $table ) {
						return false;
					}
					$archived = $archived || 'archived' === ( $data['status'] ?? '' );
					return 'active' === ( $data['status'] ?? '' );
				}
			)
		);

		try {
			$this->lifecycle->publish( $this->tk, $next, '2027/28' );
			self::fail( 'Der eingestreute Fehler muss durchschlagen.' );
		} catch ( \RuntimeException $e ) {
			self::assertStringContainsString( 'Eingestreuter Datenbankfehler', $e->getMessage() );
		}

		self::assertTrue( $archived, 'Der Fehler kam nach dem Archivieren.' );
		self::assertSame( 'active', $this->season_status( $this->id['active'] ), 'Das Archivieren ist zurückgerollt.' );
		self::assertSame( 'planned', $this->season_status( $next ) );
		self::assertSame( 0, $this->rows( 'audit_log', "action IN ( 'published', 'archived' )" ) );
		self::assertSame( array(), $this->events->published, 'Ohne Freigabe keine Mitteilungen.' );

		$this->services( $this->db );
		$this->lifecycle->publish( $this->tk, $next, '2027/28' );
		self::assertSame( 'active', $this->season_status( $next ), 'Danach lässt sich die Freigabe wiederholen.' );
	}

	public function test_a_failure_while_logging_rolls_everything_back(): void {
		$this->league();
		$next = $this->ready();
		$this->services(
			new FailingDb(
				$this->db,
				static fn( string $kind, string $table, array $data ): bool => 'insert' === $kind && 'audit_log' === $table && 'published' === ( $data['action'] ?? '' )
			)
		);

		try {
			$this->lifecycle->publish( $this->tk, $next, '2027/28' );
			self::fail( 'Der eingestreute Fehler muss durchschlagen.' );
		} catch ( \RuntimeException $e ) {
			unset( $e );
		}

		self::assertSame( 'active', $this->season_status( $this->id['active'] ) );
		self::assertSame( 'planned', $this->season_status( $next ) );
		self::assertSame( array(), $this->events->published );
	}

	public function test_the_planned_season_is_public_only_after_the_publication(): void {
		$this->league();
		$next    = $this->ready();
		$fixture = (int) $this->db->get_var( "SELECT id FROM {$this->db->table( 'fixture' )} WHERE home_team_id = %d ORDER BY id LIMIT 1", array( $this->id['next_wollishofen_1'] ) );

		$before = new ReadModel( $this->db, new NullCache() );
		self::assertSame( array( '2026-27' ), array_column( $before->seasons->all(), 'slug' ) );
		self::assertNull( $before->seasons->by_slug( '2027-28' ) );
		self::assertSame( '2026-27', $before->seasons->active()['slug'] );
		self::assertNull( $before->reports->get( $fixture ) );
		self::assertSame( array(), $before->fixtures->upcoming( 500, '2027-08-01 00:00:00' ) );

		$this->lifecycle->publish( $this->tk, $next, '2027/28' );

		$after = new ReadModel( $this->db, new NullCache() );
		self::assertSame( array( '2027-28', '2026-27' ), array_column( $after->seasons->all(), 'slug' ) );
		self::assertSame( '2027-28', $after->seasons->active()['slug'] );
		self::assertNotNull( $after->reports->get( $fixture ) );
		self::assertCount( 12, $after->fixtures->upcoming( 500, '2027-08-01 00:00:00' ) );
		$groups = $after->championship->season( $next )['groups'];
		self::assertSame( array( 'Gruppe A', 'Gruppe B' ), array_column( $groups, 'name' ) );
		self::assertSame( array( 6, 6 ), array_column( $groups, 'total' ) );
	}

	public function test_draft_fixtures_take_no_result_until_the_publication(): void {
		$this->league();
		$next    = $this->ready();
		$fixture = (int) $this->db->get_var( "SELECT id FROM {$this->db->table( 'fixture' )} WHERE home_team_id = %d ORDER BY id LIMIT 1", array( $this->id['next_wollishofen_1'] ) );
		$results = $this->result_service( new RecordingEvents() );
		$forfeit = array(
			'action'  => 'forfeit',
			'winner'  => 'home',
			'comment' => 'Gast nicht angetreten',
		);

		try {
			$results->decide( $fixture, $this->tk, $forfeit );
			self::fail( 'Vor der Freigabe nimmt die Erfassung nichts an.' );
		} catch ( ResultException $e ) {
			self::assertSame( 'ttl_conflict', $e->error_code );
		}

		$this->lifecycle->publish( $this->tk, $next, '2027/28' );

		self::assertSame( 'forfeit', $results->decide( $fixture, $this->tk, $forfeit )['status'] );
	}

	public function test_captains_of_the_new_season_get_their_rights_with_the_publication(): void {
		$this->league();
		$next    = $this->ready();
		$captain = $this->seed->person( 'Neu', 'Nina' );
		$this->seed->role( $next, $this->id['club_wollishofen'], $captain, 'captain', $this->id['next_wollishofen_1'] );
		$fixture = (int) $this->db->get_var( "SELECT id FROM {$this->db->table( 'fixture' )} WHERE home_team_id = %d ORDER BY id LIMIT 1", array( $this->id['next_wollishofen_1'] ) );

		self::assertFalse( ( new AccessPolicy( $this->db ) )->can_enter_result( $captain, $fixture ) );

		$this->lifecycle->publish( $this->tk, $next, '2027/28' );

		self::assertTrue( ( new AccessPolicy( $this->db ) )->can_enter_result( $captain, $fixture ) );
		self::assertFalse( ( new AccessPolicy( $this->db ) )->can_enter_result( $this->id['captain_wollishofen_1'], $fixture ), 'Der Captain der Vorsaison hat ohne neue Funktion kein Recht.' );
	}

	public function test_provisional_players_stay_provisional(): void {
		$this->league();
		$next        = $this->ready();
		$provisional = $this->seed->player( 'Probe', 'Paul' );
		$approved    = $this->seed->player( 'Muster', 'Hans' );
		$this->seed->register( $provisional, $next, $this->id['club_seefeld'], 7 );
		$this->seed->register( $approved, $next, $this->id['club_seefeld'], 8 );
		$this->db->update( 'player_season', array( 'status' => 'provisional' ), array( 'player_id' => $provisional ) );
		$before = $this->db->get_results( "SELECT * FROM {$this->db->table( 'player_season' )} ORDER BY id" );

		$this->lifecycle->publish( $this->tk, $next, '2027/28' );

		self::assertSame( $before, $this->db->get_results( "SELECT * FROM {$this->db->table( 'player_season' )} ORDER BY id" ) );
		self::assertSame( 'provisional', $this->db->get_var( "SELECT status FROM {$this->db->table( 'player_season' )} WHERE player_id = %d", array( $provisional ) ) );
	}

	public function test_the_first_season_of_an_installation_is_published_without_a_predecessor(): void {
		$this->league();
		$this->db->update( 'season', array( 'status' => 'archived' ), array( 'id' => $this->id['active'] ) );
		$next = $this->seed->season( '2027-28', 'planned' );
		foreach ( array( 'seefeld', 'wollishofen', 'oerlikon' ) as $club ) {
			$this->setup->add_club( $this->tk, $next, $this->id[ 'club_' . $club ] );
			$this->id[ 'next_' . $club . '_1' ] = $this->seed->team( $next, $this->id[ 'club_' . $club ], 1, ucfirst( $club ) . ' 1', null, $this->id[ 'venue_' . $club ] );
		}
		$this->db->update( 'club_season', array( 'status' => 'reviewed' ), array( 'season_id' => $next ) );
		$this->grouping->apply_proposal( $this->tk, $next );
		// Die Gruppe B des Vorjahrs bleibt ohne Mannschaft; eine leere Gruppe hat keinen Spielplan.
		$groups = array_column( $this->grouping->groups( $next ), 'id', 'category' );
		self::assertContains( LifecycleRules::NO_SCHEDULE, $this->open( $next ) );
		$this->grouping->delete_group( $this->tk, $groups['B'] );
		$this->scheduling->save_weeks( $this->tk, $next, array( '2027-09-06', '2027-09-13', '2027-09-20' ), array( '2028-01-10', '2028-01-17', '2028-01-24' ) );
		$this->scheduling->generate( $this->tk, $next );
		self::assertNull( $this->lifecycle->readiness( $next )['facts']['predecessor'] );

		$this->lifecycle->publish( $this->tk, $next, '2027/28' );

		self::assertSame( 'active', $this->season_status( $next ) );
		self::assertSame( array( array( $next, null, 900 ) ), $this->events->published );
	}

	/**
	 * Gruppe A der aktiven Saison mit gespielten und offenen Begegnungen von Seefeld 1.
	 *
	 * @return array<string,int> Begegnungen.
	 */
	private function season_in_progress(): array {
		$players = array();
		foreach ( array( 's1', 's2', 's3', 'w1', 'w2', 'w3' ) as $index => $key ) {
			$players[ $key ] = $this->seed->player( 'Muster', 'Spieler' . ( $index + 1 ) );
			$this->seed->register( $players[ $key ], $this->id['active'], $this->id[ $index < 3 ? 'club_seefeld' : 'club_wollishofen' ], 8 );
		}
		$this->seed->roster( $this->id['seefeld_1'], array( $players['s1'], $players['s2'], $players['s3'] ) );
		$this->seed->roster( $this->id['wollishofen_1'], array( $players['w1'], $players['w2'], $players['w3'] ) );
		$played = $this->active_fixture(
			'seefeld_1',
			'wollishofen_1',
			array(
				'status'     => 'final',
				'home_score' => 10,
				'away_score' => 0,
			)
		);
		$this->seed->team_match(
			$played,
			'2026-10-06 20:00:00',
			array(
				'A' => $players['s1'],
				'B' => $players['s2'],
				'C' => $players['s3'],
			),
			array(
				'X' => $players['w1'],
				'Y' => $players['w2'],
				'Z' => $players['w3'],
			),
			array_fill( 0, 10, '3:0' )
		);
		return array(
			'played'      => $played,
			'away'        => $this->active_fixture(
				'oerlikon_1',
				'seefeld_1',
				array(
					'status'       => 'provisional',
					'home_score'   => 4,
					'away_score'   => 6,
					'scheduled_at' => '2026-10-15 20:00:00',
				)
			),
			'scheduled'   => $this->active_fixture( 'seefeld_1', 'oerlikon_1', array( 'scheduled_at' => '2027-02-02 20:00:00' ) ),
			'cancelled'   => $this->active_fixture(
				'wollishofen_1',
				'seefeld_1',
				array(
					'status'       => 'cancelled',
					'scheduled_at' => '2027-02-10 19:30:00',
				)
			),
			'other_teams' => $this->active_fixture(
				'wollishofen_1',
				'oerlikon_1',
				array(
					'status'       => 'final',
					'home_score'   => 2,
					'away_score'   => 8,
					'scheduled_at' => '2026-10-21 19:30:00',
				)
			),
		);
	}

	public function test_all_fixtures_of_a_withdrawn_team_count_as_forfeit_against_it(): void {
		$this->league();
		$fixtures = $this->season_in_progress();

		$count = $this->lifecycle->withdraw_team( $this->tk, $this->id['seefeld_1'], 'Zu wenige Spieler' );

		self::assertSame( 3, $count );
		self::assertSame( 'withdrawn', $this->row( 'team', $this->id['seefeld_1'] )['status'] );
		foreach ( array(
			'played'    => array( '0', '10' ),
			'away'      => array( '10', '0' ),
			'scheduled' => array( '0', '10' ),
		) as $key => [ $home, $away ] ) {
			$row = $this->row( 'fixture', $fixtures[ $key ] );
			self::assertSame( 'forfeit', $row['status'], $key );
			self::assertSame( $home, $row['home_score'], $key );
			self::assertSame( $away, $row['away_score'], $key );
		}
		self::assertSame( 0, $this->rows( 'game', 'fixture_id = ' . $fixtures['played'] ), 'Auch die gespielte Begegnung verliert ihre Spiele.' );
		self::assertSame( 0, $this->rows( 'lineup', 'fixture_id = ' . $fixtures['played'] ) );
		self::assertSame( 'cancelled', $this->row( 'fixture', $fixtures['cancelled'] )['status'], 'Abgesagte Begegnungen bleiben abgesagt.' );
		$other = $this->row( 'fixture', $fixtures['other_teams'] );
		self::assertSame( array( 'final', '2', '8' ), array( $other['status'], $other['home_score'], $other['away_score'] ), 'Begegnungen anderer Mannschaften bleiben.' );
		self::assertSame( array( array( $this->id['active'], $this->id['seefeld_1'] ) ), $this->events->withdrawn );
	}

	public function test_the_withdrawal_is_logged_with_its_reason(): void {
		$this->league();
		$fixtures = $this->season_in_progress();

		$this->lifecycle->withdraw_team( $this->tk, $this->id['seefeld_1'], 'Zu wenige Spieler' );

		$entry = (array) $this->db->get_row( "SELECT * FROM {$this->db->table( 'audit_log' )} WHERE entity = 'team' AND action = 'withdrawn'" );
		self::assertSame( (string) $this->id['seefeld_1'], $entry['entity_id'] );
		self::assertSame( 'Zu wenige Spieler', $entry['note'] );
		self::assertSame( '900', $entry['user_id'] );
		$after = json_decode( (string) $entry['after_json'], true );
		self::assertSame( 'withdrawn', $after['status'] );
		self::assertEqualsCanonicalizing( array( $fixtures['played'], $fixtures['away'], $fixtures['scheduled'] ), $after['fixtures'] );
		$event = (array) $this->db->get_row( "SELECT type, comment, user_id FROM {$this->db->table( 'fixture_event' )} WHERE fixture_id = %d ORDER BY id DESC LIMIT 1", array( $fixtures['played'] ) );
		self::assertSame( 'forfeit', $event['type'] );
		self::assertStringContainsString( 'Rückzug von Seefeld 1', (string) $event['comment'] );
		self::assertStringContainsString( 'Zu wenige Spieler', (string) $event['comment'] );
		self::assertSame( 3, $this->rows( 'audit_log', "entity = 'fixture' AND action = 'decision_forfeit'" ) );
	}

	public function test_a_withdrawn_team_stands_at_the_bottom_of_the_table(): void {
		$this->league();
		$this->season_in_progress();
		$before = ( new ReadModel( $this->db, new NullCache() ) )->championship->group_by_id( $this->id['active'], $this->id['group_a'] );
		self::assertSame( $this->id['seefeld_1'], $before['standings'][0]['team_id'], 'Vor dem Rückzug führt Seefeld 1 mit 16 Punkten.' );

		$this->lifecycle->withdraw_team( $this->tk, $this->id['seefeld_1'], 'Zu wenige Spieler' );

		$group = ( new ReadModel( $this->db, new NullCache() ) )->championship->group_by_id( $this->id['active'], $this->id['group_a'] );
		self::assertSame( array( $this->id['oerlikon_1'], $this->id['wollishofen_1'], $this->id['seefeld_1'] ), array_column( $group['standings'], 'team_id' ) );
		self::assertSame( array( 28, 12, 0 ), array_column( $group['standings'], 'points' ) );
		self::assertSame( array( false, false, true ), array_column( $group['standings'], 'withdrawn' ) );
		self::assertSame( 3, $group['standings'][2]['rank'] );
	}

	public function test_a_withdrawn_team_is_last_even_with_more_points_than_others(): void {
		$this->league();
		// Wollishofen 1 hat noch nicht gespielt und stünde mit 0 Punkten gleichauf.
		$this->active_fixture(
			'seefeld_1',
			'oerlikon_1',
			array(
				'status'     => 'final',
				'home_score' => 9,
				'away_score' => 1,
			)
		);

		$this->lifecycle->withdraw_team( $this->tk, $this->id['seefeld_1'], 'Rückzug nach der Vorrunde' );

		$group = ( new ReadModel( $this->db, new NullCache() ) )->championship->group_by_id( $this->id['active'], $this->id['group_a'] );
		self::assertSame( array( $this->id['oerlikon_1'], $this->id['wollishofen_1'], $this->id['seefeld_1'] ), array_column( $group['standings'], 'team_id' ) );
		self::assertSame( array( 1, 2, 3 ), array_column( $group['standings'], 'rank' ) );
		self::assertFalse( $group['standings'][2]['shared'], 'Kein geteilter Rang mit einer Mannschaft ohne Punkte.' );
	}

	public function test_the_forfeit_score_comes_from_the_rules_of_the_season(): void {
		$this->league();
		$this->db->update( 'season', array( 'rules_json' => '{"forfeit_score":7}' ), array( 'id' => $this->id['active'] ) );
		$fixture = $this->active_fixture( 'seefeld_1', 'oerlikon_1' );

		$this->lifecycle->withdraw_team( $this->tk, $this->id['seefeld_1'], 'Zu wenige Spieler' );

		$row = $this->row( 'fixture', $fixture );
		self::assertSame( array( '0', '7' ), array( $row['home_score'], $row['away_score'] ) );
	}

	public function test_fixtures_between_two_withdrawn_teams_stay_as_they_are(): void {
		$this->league();
		$fixture = $this->active_fixture( 'seefeld_1', 'oerlikon_1' );
		$this->lifecycle->withdraw_team( $this->tk, $this->id['seefeld_1'], 'Zu wenige Spieler' );
		self::assertSame( array( '0', '10' ), array( $this->row( 'fixture', $fixture )['home_score'], $this->row( 'fixture', $fixture )['away_score'] ) );

		$count = $this->lifecycle->withdraw_team( $this->tk, $this->id['oerlikon_1'], 'Ebenfalls zu wenige Spieler' );

		self::assertSame( 0, $count );
		self::assertSame( array( '0', '10' ), array( $this->row( 'fixture', $fixture )['home_score'], $this->row( 'fixture', $fixture )['away_score'] ) );
		self::assertSame( 1, $this->rows( 'fixture_event', 'fixture_id = ' . $fixture ) );
	}

	public function test_a_withdrawal_needs_a_reason_the_tk_and_the_active_season(): void {
		$this->league();
		$fixture = $this->active_fixture( 'seefeld_1', 'oerlikon_1' );
		$next    = $this->plan();

		$refusal = $this->refused( fn() => $this->lifecycle->withdraw_team( $this->tk, $this->id['seefeld_1'], '   ' ), Refusal::INVALID );
		self::assertSame( array( 'reason' ), array_column( $refusal->errors, 'field' ) );
		$this->refused( fn() => $this->lifecycle->withdraw_team( $this->tk, $this->id['seefeld_1'], str_repeat( 'x', 501 ) ), Refusal::INVALID );
		$this->refused( fn() => $this->lifecycle->withdraw_team( $this->chef, $this->id['seefeld_1'], 'Zu wenige Spieler' ), Refusal::FORBIDDEN );
		$this->refused( fn() => $this->lifecycle->withdraw_team( $this->tk, $this->id['next_seefeld_1'], 'Zu wenige Spieler' ), Refusal::CONFLICT );
		$this->refused( fn() => $this->lifecycle->withdraw_team( $this->tk, 99999, 'Zu wenige Spieler' ), Refusal::NOT_FOUND );

		self::assertSame( 'active', $this->row( 'team', $this->id['seefeld_1'] )['status'] );
		self::assertSame( 'active', $this->row( 'team', $this->id['next_seefeld_1'] )['status'] );
		self::assertSame( 'scheduled', $this->row( 'fixture', $fixture )['status'] );
		self::assertSame( array(), $this->events->withdrawn );

		$this->lifecycle->withdraw_team( $this->tk, $this->id['seefeld_1'], str_repeat( 'x', 500 ) );
		$this->refused( fn() => $this->lifecycle->withdraw_team( $this->tk, $this->id['seefeld_1'], 'nochmals' ), Refusal::CONFLICT );
		unset( $next );
	}

	public function test_a_failure_during_the_withdrawal_leaves_team_and_fixtures_untouched(): void {
		$this->league();
		$first  = $this->active_fixture( 'seefeld_1', 'oerlikon_1' );
		$second = $this->active_fixture( 'wollishofen_1', 'seefeld_1', array( 'scheduled_at' => '2026-11-04 19:30:00' ) );
		$this->services(
			new FailingDb(
				$this->db,
				static fn( string $kind, string $table, array $data ): bool => 'insert' === $kind && 'audit_log' === $table && 'withdrawn' === ( $data['action'] ?? '' )
			)
		);

		try {
			$this->lifecycle->withdraw_team( $this->tk, $this->id['seefeld_1'], 'Zu wenige Spieler' );
			self::fail( 'Der eingestreute Fehler muss durchschlagen.' );
		} catch ( \RuntimeException $e ) {
			unset( $e );
		}

		self::assertSame( 'active', $this->row( 'team', $this->id['seefeld_1'] )['status'] );
		self::assertSame( 'scheduled', $this->row( 'fixture', $first )['status'] );
		self::assertSame( 'scheduled', $this->row( 'fixture', $second )['status'] );
		self::assertSame( 0, $this->rows( 'fixture_event' ) );
		self::assertSame( array(), $this->events->withdrawn );
	}

	public function test_a_finished_and_classified_season_is_closed(): void {
		$this->league();
		$this->active_fixture( 'seefeld_1', 'oerlikon_1', array( 'status' => 'final' ) );
		$this->active_fixture( 'oerlikon_1', 'seefeld_1', array( 'status' => 'confirmed' ) );
		$this->active_fixture( 'seefeld_1', 'wollishofen_1', array( 'status' => 'forfeit' ) );
		$this->active_fixture( 'wollishofen_1', 'seefeld_1', array( 'status' => 'cancelled' ) );
		$this->db->update( 'season', array( 'classification_closed_at' => '2027-06-20 18:00:00' ), array( 'id' => $this->id['active'] ) );
		self::assertSame( array(), $this->lifecycle->closing( $this->id['active'] ) );

		$this->lifecycle->close( $this->tk, $this->id['active'], '2026/27' );

		self::assertSame( 'archived', $this->season_status( $this->id['active'] ) );
		self::assertNull( $this->seasons->active(), 'Ohne Folgesaison gibt es bis zur nächsten Freigabe keine aktive Saison.' );
		self::assertSame( array( $this->id['active'] ), $this->events->closed );
		self::assertSame( 1, $this->rows( 'audit_log', "entity = 'season' AND action = 'closed'" ) );
	}

	/**
	 * @return array<string,array{0:string}>
	 */
	public static function open_statuses(): array {
		return array(
			'angesetzt'   => array( 'scheduled' ),
			'verschoben'  => array( 'postponed' ),
			'unbestätigt' => array( 'provisional' ),
			'strittig'    => array( 'disputed' ),
		);
	}

	#[DataProvider( 'open_statuses' )]
	public function test_an_open_fixture_prevents_closing( string $status ): void {
		$this->league();
		$this->db->update( 'season', array( 'classification_closed_at' => '2027-06-20 18:00:00' ), array( 'id' => $this->id['active'] ) );
		$fixture = $this->active_fixture( 'seefeld_1', 'oerlikon_1', array( 'status' => $status ) );

		self::assertSame( array( LifecycleRules::OPEN_FIXTURES ), array_column( $this->lifecycle->closing( $this->id['active'] ), 'code' ) );
		$refusal = $this->refused( fn() => $this->lifecycle->close( $this->tk, $this->id['active'], '2026/27' ), Refusal::INVALID );
		self::assertSame( array( LifecycleRules::OPEN_FIXTURES ), array_column( $refusal->errors, 'field' ) );
		self::assertSame( 'active', $this->season_status( $this->id['active'] ) );

		$this->db->update( 'fixture', array( 'status' => 'final' ), array( 'id' => $fixture ) );
		$this->lifecycle->close( $this->tk, $this->id['active'], '2026/27' );
		self::assertSame( 'archived', $this->season_status( $this->id['active'] ) );
	}

	public function test_an_open_cup_fixture_prevents_closing_too(): void {
		$this->league();
		$this->db->update( 'season', array( 'classification_closed_at' => '2027-06-20 18:00:00' ), array( 'id' => $this->id['active'] ) );
		$this->seed->fixture( $this->seed->competition( $this->id['active'], 'CUP', 'Mannschaftscup A', 'A' ), array( 'status' => 'scheduled' ) );
		$this->refused( fn() => $this->lifecycle->close( $this->tk, $this->id['active'], '2026/27' ), Refusal::INVALID );
	}

	public function test_an_open_classification_prevents_closing(): void {
		$this->league();

		$refusal = $this->refused( fn() => $this->lifecycle->close( $this->tk, $this->id['active'], '2026/27' ), Refusal::INVALID );

		self::assertSame( array( LifecycleRules::CLASSIFICATION ), array_column( $refusal->errors, 'field' ) );
		self::assertSame( 'active', $this->season_status( $this->id['active'] ) );
		self::assertSame( array(), $this->events->closed );
	}

	public function test_closing_needs_the_name_the_tk_and_the_active_season(): void {
		$this->league();
		$this->db->update( 'season', array( 'classification_closed_at' => '2027-06-20 18:00:00' ), array( 'id' => $this->id['active'] ) );
		$next = $this->plan();

		$refusal = $this->refused( fn() => $this->lifecycle->close( $this->tk, $this->id['active'], '2027/28' ), Refusal::INVALID );
		self::assertSame( array( 'confirm' ), array_column( $refusal->errors, 'field' ) );
		$this->refused( fn() => $this->lifecycle->close( $this->chef, $this->id['active'], '2026/27' ), Refusal::FORBIDDEN );
		$this->refused( fn() => $this->lifecycle->close( $this->tk, $next, '2027/28' ), Refusal::CONFLICT );
		self::assertSame( 'active', $this->season_status( $this->id['active'] ) );

		$this->lifecycle->close( $this->tk, $this->id['active'], '2026/27' );

		self::assertSame( 'archived', $this->season_status( $this->id['active'] ) );
		self::assertSame( 'planned', $this->season_status( $next ), 'Die geplante Saison bleibt geplant.' );
		$this->refused( fn() => $this->lifecycle->close( $this->tk, $this->id['active'], '2026/27' ), Refusal::CONFLICT );
	}
}
