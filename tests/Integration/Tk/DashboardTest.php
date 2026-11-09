<?php
/**
 * Integrationstests des TK-Dashboards: Listen, Fristgrenzen, nur aktive Saison.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Tests\Integration\Tk;

use TTLeague\Access\AccessPolicy;
use TTLeague\Infrastructure\AuditLog;
use TTLeague\Infrastructure\Time\FixedClock;
use TTLeague\Results\Actor;
use TTLeague\Results\ResultException;
use TTLeague\Results\ResultRepository;
use TTLeague\Results\ResultService;
use TTLeague\Tests\Integration\Frontend\Seeder;
use TTLeague\Tests\Integration\IntegrationTestCase;
use TTLeague\Tests\Integration\Results\RecordingEvents;
use TTLeague\Tk\Dashboard;

/**
 * Erfundene Clubs Seefeld und Wollishofen in Gruppe B1 der aktiven Saison 2026/27, dazu eine
 * archivierte Saison mit offenen Begegnungen, die nie erscheinen dürfen. Die Uhr steht auf
 * Freitag, 20. November 2026, 12:00 Ortszeit (11:00 UTC). Regeln: 48 Stunden zum Nachtragen,
 * 7 Tage bis zur Bestätigung, Forfait-Vorschlag nach 16 Tagen.
 */
final class DashboardTest extends IntegrationTestCase {

	private const NOW_UTC = '2026-11-20 11:00:00';

	private FixedClock $clock;

	private Dashboard $dashboard;

	private ResultRepository $repo;

	private Seeder $seed;

	/** @var array<string,int> */
	private array $id = array();

	public static function setUpBeforeClass(): void {
		require_once dirname( __DIR__, 2 ) . '/Unit/Frontend/Stubs/WordPress.php';
	}

	protected function setUp(): void {
		parent::setUp();
		$this->seed  = new Seeder( $this->db );
		$this->repo  = new ResultRepository( $this->db );
		$this->clock = new FixedClock( self::NOW_UTC );

		$old    = $this->seed->season( '2025-26', 'archived' );
		$season = $this->seed->season( '2026-27', 'active' );
		$home   = $this->seed->club( 'Seefeld', 1 );
		$away   = $this->seed->club( 'Wollishofen', 2 );
		$group  = $this->seed->competition( $season, 'MM', 'Gruppe B1', 'B', 1 );
		$cup    = $this->seed->competition( $season, 'CUP', 'Mannschaftscup A', 'A' );
		$oldg   = $this->seed->competition( $old, 'MM', 'Gruppe B1', 'B', 1 );

		$this->id = array(
			'season'   => $season,
			'old'      => $old,
			'group'    => $group,
			'cup'      => $cup,
			'oldgroup' => $oldg,
			'home'     => $this->seed->team( $season, $home, 1, 'Seefeld 1', $group ),
			'away'     => $this->seed->team( $season, $away, 1, 'Wollishofen 1', $group ),
			'oldhome'  => $this->seed->team( $old, $home, 1, 'Seefeld 1', $oldg ),
			'oldaway'  => $this->seed->team( $old, $away, 1, 'Wollishofen 1', $oldg ),
			'captain'  => $this->seed->person( 'Gast', 'Gustav' ),
			'entry_h'  => $this->db->insert(
				'cup_entry',
				array(
					'competition_id' => $cup,
					'club_id'        => $home,
					'number'         => 1,
					'name'           => 'Seefeld Cup 1',
				)
			),
			'entry_a'  => $this->db->insert(
				'cup_entry',
				array(
					'competition_id' => $cup,
					'club_id'        => $away,
					'number'         => 1,
					'name'           => 'Wollishofen Cup 1',
				)
			),
		);

		$this->dashboard = new Dashboard(
			$this->db,
			$this->clock,
			new \DateTimeZone( 'Europe/Zurich' ),
			static fn( array $ids ): array => array_fill_keys( $ids, 'Tamara TK' )
		);
	}

	/**
	 * Begegnung der aktiven Saison (Seefeld 1 gegen Wollishofen 1).
	 *
	 * @param array<string,int|string|null> $data Felder.
	 */
	private function fixture( array $data, bool $archived = false ): int {
		return $this->seed->fixture(
			$archived ? $this->id['oldgroup'] : $this->id['group'],
			$data + array(
				'home_team_id' => $archived ? $this->id['oldhome'] : $this->id['home'],
				'away_team_id' => $archived ? $this->id['oldaway'] : $this->id['away'],
				'status'       => 'scheduled',
			)
		);
	}

	/**
	 * Provisorisch erfasste Begegnung.
	 */
	private function provisional( string $entered_at, string $status = 'provisional', bool $archived = false ): int {
		return $this->fixture(
			array(
				'scheduled_at' => '2026-11-03 20:00:00',
				'status'       => $status,
				'home_score'   => 6,
				'away_score'   => 4,
				'entered_by'   => 101,
				'entered_at'   => $entered_at,
			),
			$archived
		);
	}

	/**
	 * @param list<array<string,mixed>> $rows Zeilen.
	 * @return list<int>
	 */
	private static function ids( array $rows ): array {
		return array_column( $rows, 'id' );
	}

	public function test_disputed_with_reason_and_objector(): void {
		$disputed = $this->provisional( '2026-11-10 21:30:00', 'disputed' );
		$this->repo->add_event( $disputed, 'entered', 101, null, null, array( 'score' => '6:4' ), '2026-11-10 21:30:00' );
		$this->repo->add_event( $disputed, 'disputed', 102, $this->id['captain'], 'Erste Einsprache.', array(), '2026-11-11 08:00:00' );
		$this->repo->add_event( $disputed, 'disputed', 102, $this->id['captain'], 'Spiel 7 ging 3:2 an uns.', array(), '2026-11-12 09:00:00' );
		$without_event = $this->provisional( '2026-11-15 10:00:00', 'disputed' );
		$this->provisional( '2026-11-01 10:00:00', 'disputed', true );

		$rows = $this->dashboard->disputed( $this->id['season'] );

		self::assertSame( array( $disputed, $without_event ), self::ids( $rows ) );
		self::assertSame( 'Spiel 7 ging 3:2 an uns.', $rows[0]['reason'], 'letzte Einsprache' );
		self::assertSame( 'Gustav Gast', $rows[0]['by'] );
		self::assertSame( '2026-11-12 09:00:00', $rows[0]['since'] );
		self::assertSame( 8 * 24 + 2, $rows[0]['age_hours'] );
		self::assertSame( array( 'Seefeld 1', 'Wollishofen 1', 'Gruppe B1', 'disputed', 6, 4 ), array( $rows[0]['home'], $rows[0]['away'], $rows[0]['competition'], $rows[0]['status'], $rows[0]['home_score'], $rows[0]['away_score'] ) );
		self::assertNull( $rows[1]['reason'] );
		self::assertSame( '2026-11-15 10:00:00', $rows[1]['since'], 'ohne Ereignis gilt die Erfassung' );
	}

	public function test_confirmation_due_after_objection_days(): void {
		$due      = $this->provisional( '2026-11-13 10:59:00' );
		$exactly  = $this->provisional( '2026-11-13 11:00:00' );
		$recent   = $this->provisional( '2026-11-19 20:00:00' );
		$archived = $this->provisional( '2026-10-01 10:00:00', 'provisional', true );
		$this->provisional( '2026-11-01 10:00:00', 'confirmed' );
		$rules = $this->dashboard->season()['rules'];

		$rows = $this->dashboard->confirmation_due( $this->id['season'], $rules );

		self::assertSame( array( $due ), self::ids( $rows ), 'mehr als 7 Tage, nicht genau 7 Tage' );
		self::assertNotContains( $exactly, self::ids( $rows ) );
		self::assertNotContains( $recent, self::ids( $rows ) );
		self::assertNotContains( $archived, self::ids( $rows ) );
		self::assertSame( 7 * 24, $rows[0]['age_hours'] );
	}

	public function test_missing_results_and_forfeit_proposals_split_at_deadlines(): void {
		$missing      = $this->fixture( array( 'scheduled_at' => '2026-11-18 11:59:00' ) );
		$at_deadline  = $this->fixture( array( 'scheduled_at' => '2026-11-18 12:00:00' ) );
		$postponed    = $this->fixture(
			array(
				'scheduled_at' => '2026-11-15 20:00:00',
				'status'       => 'postponed',
			)
		);
		$before_limit = $this->fixture( array( 'scheduled_at' => '2026-11-04 12:00:00' ) );
		$forfeit      = $this->fixture( array( 'scheduled_at' => '2026-11-04 11:59:00' ) );
		$cup          = $this->seed->fixture(
			$this->id['cup'],
			array(
				'home_entry_id' => $this->id['entry_h'],
				'away_entry_id' => $this->id['entry_a'],
				'scheduled_at'  => '2026-10-20 19:30:00',
			)
		);
		$this->fixture( array( 'scheduled_at' => '2026-11-27 20:00:00' ) );
		$this->fixture( array( 'scheduled_at' => null ) );
		$this->fixture(
			array(
				'scheduled_at' => '2026-10-01 20:00:00',
				'status'       => 'final',
			)
		);
		$this->fixture(
			array(
				'scheduled_at' => '2026-10-01 20:00:00',
				'status'       => 'cancelled',
			)
		);
		$this->fixture( array( 'scheduled_at' => '2026-03-01 20:00:00' ), true );
		$rules = $this->dashboard->season()['rules'];

		$missing_rows = $this->dashboard->missing_results( $this->id['season'], $rules );
		$forfeit_rows = $this->dashboard->forfeit_proposals( $this->id['season'], $rules );

		self::assertSame( array( $before_limit, $postponed, $missing ), self::ids( $missing_rows ) );
		self::assertNotContains( $at_deadline, self::ids( $missing_rows ), 'genau 48 Stunden: Frist noch nicht vorbei' );
		self::assertSame( array( $cup, $forfeit ), self::ids( $forfeit_rows ) );
		self::assertSame( array( 'Seefeld Cup 1', 'Wollishofen Cup 1', 'Mannschaftscup A' ), array( $forfeit_rows[0]['home'], $forfeit_rows[0]['away'], $forfeit_rows[0]['competition'] ) );
		self::assertSame( 48, $missing_rows[2]['age_hours'] );
		self::assertSame( '2026-11-18 10:59:00', $missing_rows[2]['since'], 'Anspielzeit in UTC' );
	}

	public function test_deadline_counts_elapsed_hours_across_daylight_saving_change(): void {
		// Sonntag, 25. Oktober 2026: Uhren von 03:00 auf 02:00 zurück. 48 Stunden nach Samstag
		// 20:00 (Sommerzeit, 18:00 UTC) ist Montag 19:00 Winterzeit (18:00 UTC).
		$fixture = $this->fixture( array( 'scheduled_at' => '2026-10-24 20:00:00' ) );
		$rules   = $this->dashboard->season()['rules'];

		$this->clock = new FixedClock( '2026-10-26 17:59:00' );
		$dashboard   = new Dashboard( $this->db, $this->clock, new \DateTimeZone( 'Europe/Zurich' ) );
		self::assertSame( array(), self::ids( $dashboard->missing_results( $this->id['season'], $rules ) ), 'Montag 18:59 Ortszeit: erst 47 Stunden 59 Minuten' );

		$this->clock->advance( '+2 minutes' );
		self::assertSame( array( $fixture ), self::ids( $dashboard->missing_results( $this->id['season'], $rules ) ), 'Montag 19:01 Ortszeit' );
	}

	public function test_warnings_of_last_30_days_without_repetitions(): void {
		$f1  = $this->provisional( '2026-11-15 21:00:00' );
		$f2  = $this->provisional( '2026-11-16 21:00:00', 'final' );
		$f3  = $this->provisional( '2026-10-10 21:00:00' );
		$f4  = $this->provisional( '2026-10-10 21:00:00', 'provisional', true );
		$sub = 'Sara Probe spielt als Ersatz (1. von höchstens 3 Einsätzen, danach gebunden).';
		$this->repo->add_event( $f1, 'entered', 101, null, null, array( 'warnings' => array( $sub ) ), '2026-11-15 21:00:00' );
		$this->repo->add_event( $f1, 'edited', 101, $this->id['captain'], null, array( 'warnings' => array( $sub, 'Spiel 9 wurde nicht gespielt.' ) ), '2026-11-18 21:00:00' );
		$this->repo->add_event( $f1, 'confirmed', 102, null, null, array( 'by_tk' => false ), '2026-11-19 08:00:00' );
		$this->repo->add_event(
			$f2,
			'decided',
			103,
			null,
			'Aufstellung korrigiert.',
			array(
				'action'   => 'correct',
				'warnings' => array( '3. Ersatzeinsatz von Sara Probe: ab jetzt an Seefeld 1 gebunden (Art. 12.5).' ),
			),
			'2026-11-17 09:00:00'
		);
		$this->repo->add_event( $f2, 'entered', 101, null, null, array( 'warnings' => array() ), '2026-11-16 21:00:00' );
		$this->repo->add_event( $f3, 'entered', 101, null, null, array( 'warnings' => array( 'Alt.' ) ), '2026-10-21 10:59:00' );
		$this->repo->add_event( $f3, 'edited', 101, null, null, array( 'warnings' => array( 'Gerade noch.' ) ), '2026-10-21 11:00:00' );
		$this->repo->add_event( $f4, 'entered', 101, null, null, array( 'warnings' => array( 'Archiv.' ) ), '2026-11-19 10:00:00' );

		$rows = $this->dashboard->warnings( $this->id['season'] );

		self::assertSame( array( $f1, $f2, $f3 ), self::ids( $rows ) );
		self::assertSame( array( 'edited', 'decided', 'edited' ), array_column( $rows, 'event' ) );
		self::assertSame( array( $sub, 'Spiel 9 wurde nicht gespielt.' ), $rows[0]['warnings'], 'die ältere Erfassung wiederholt nur denselben Hinweis' );
		self::assertSame( 'Gustav Gast', $rows[0]['by'] );
		self::assertSame( 'correct', $rows[1]['action'] );
		self::assertSame( 'Tamara TK', $rows[1]['by'], 'Konto ohne Person über den Namensdienst' );
		self::assertSame( array( 'Gerade noch.' ), $rows[2]['warnings'], 'genau 30 Tage alt zählt noch' );
	}

	public function test_recent_decisions_newest_first(): void {
		$f1 = $this->provisional( '2026-11-10 21:00:00', 'final' );
		$f2 = $this->fixture(
			array(
				'scheduled_at' => '2026-10-20 20:00:00',
				'status'       => 'forfeit',
				'home_score'   => 0,
				'away_score'   => 10,
			)
		);
		$f3 = $this->provisional( '2026-11-12 21:00:00', 'confirmed' );
		$f4 = $this->provisional( '2026-11-12 21:00:00', 'confirmed' );
		$f5 = $this->provisional( '2026-11-12 21:00:00', 'final', true );
		$this->repo->add_event( $f1, 'decided', 103, null, 'Spielbericht geprüft.', array( 'action' => 'finalize' ), '2026-11-18 10:00:00' );
		$this->repo->add_event(
			$f2,
			'forfeit',
			103,
			null,
			'Nicht angetreten.',
			array(
				'action' => 'forfeit',
				'winner' => 'away',
			),
			'2026-11-19 10:00:00'
		);
		$this->repo->add_event( $f3, 'confirmed', 103, null, null, array( 'by_tk' => true ), '2026-11-17 10:00:00' );
		$this->repo->add_event( $f4, 'confirmed', 102, $this->id['captain'], null, array( 'by_tk' => false ), '2026-11-19 12:00:00' );
		$this->repo->add_event( $f3, 'reset', 103, null, null, array( 'action' => 'reset' ), '2026-11-16 10:00:00' );
		$this->repo->add_event( $f5, 'decided', 103, null, null, array( 'action' => 'finalize' ), '2026-11-19 11:00:00' );

		$rows = $this->dashboard->decisions( $this->id['season'] );

		self::assertSame( array( $f2, $f1, $f3, $f3 ), self::ids( $rows ) );
		self::assertSame( array( 'forfeit', 'finalize', 'confirm', 'reset' ), array_column( $rows, 'action' ) );
		self::assertSame( 'away', $rows[0]['winner'] );
		self::assertSame( 'Nicht angetreten.', $rows[0]['comment'] );
		self::assertSame( 'Tamara TK', $rows[0]['by'] );
		self::assertSame( 25, $rows[0]['age_hours'] );
		self::assertCount( 2, $this->dashboard->decisions( $this->id['season'], 2 ) );
	}

	public function test_overview_without_active_season_is_empty(): void {
		$this->provisional( '2026-11-01 10:00:00', 'disputed' );
		$this->db->update( 'season', array( 'status' => 'archived' ), array( 'id' => $this->id['season'] ) );

		$overview = $this->dashboard->overview();

		self::assertNull( $overview['season'] );
		self::assertSame( array(), $overview['disputed'] );
	}

	public function test_overview_uses_rules_of_the_season(): void {
		$this->db->update( 'season', array( 'rules_json' => '{"entry_deadline_hours":24,"objection_days":3,"forfeit_after_days":10}' ), array( 'id' => $this->id['season'] ) );
		$missing = $this->fixture( array( 'scheduled_at' => '2026-11-19 11:00:00' ) );
		$forfeit = $this->fixture( array( 'scheduled_at' => '2026-11-09 20:00:00' ) );
		$due     = $this->provisional( '2026-11-16 10:00:00' );

		$overview = $this->dashboard->overview();

		self::assertSame( '2026/27', $overview['season']['name'] );
		self::assertSame( array( $missing ), self::ids( $overview['missing'] ) );
		self::assertSame( array( $forfeit ), self::ids( $overview['forfeit'] ) );
		self::assertSame( array( $due ), self::ids( $overview['confirmation_due'] ) );
	}

	public function test_version_matches_service_so_quick_actions_apply(): void {
		$fixture = $this->provisional( '2026-11-10 21:30:00' );
		foreach ( array( 1, 2 ) as $sequence ) {
			$this->db->insert(
				'game',
				array(
					'fixture_id' => $fixture,
					'sequence'   => $sequence,
					'sets_a'     => 3,
					'sets_b'     => 1,
				)
			);
		}
		$service = new ResultService( $this->db, new AccessPolicy( $this->db ), $this->clock, new \DateTimeZone( 'Europe/Zurich' ), new AuditLog( $this->db ), new RecordingEvents() );
		$tk      = new Actor( 103, null, true, 'Tamara TK' );
		$row     = $this->dashboard->confirmation_due( $this->id['season'], $this->dashboard->season()['rules'] )[0];

		self::assertSame( $service->context( $fixture )->version(), $row['version'] );

		$this->db->update( 'fixture', array( 'entered_at' => '2026-11-10 21:31:00' ), array( 'id' => $fixture ) );
		try {
			$service->decide(
				$fixture,
				$tk,
				array(
					'action'  => 'confirm',
					'version' => $row['version'],
				)
			);
			self::fail( 'Veralteter Stand muss abgelehnt werden.' );
		} catch ( ResultException $e ) {
			self::assertSame( 409, $e->status );
		}

		$row    = $this->dashboard->confirmation_due( $this->id['season'], $this->dashboard->season()['rules'] )[0];
		$result = $service->decide(
			$fixture,
			$tk,
			array(
				'action'  => 'confirm',
				'version' => $row['version'],
			)
		);
		self::assertSame( 'confirmed', $result['status'] );
		self::assertSame( array( 'confirm' ), array_column( $this->dashboard->decisions( $this->id['season'] ), 'action' ) );
	}

	public function test_each_list_is_one_query(): void {
		foreach ( range( 1, 6 ) as $i ) {
			$f = $this->provisional( '2026-11-0' . $i . ' 10:00:00', 'disputed' );
			$this->repo->add_event( $f, 'disputed', 102, $this->id['captain'], 'Grund ' . $i, array(), '2026-11-1' . $i . ' 10:00:00' );
			$this->repo->add_event( $f, 'entered', 101, null, null, array( 'warnings' => array( 'Hinweis ' . $i ) ), '2026-11-1' . $i . ' 09:00:00' );
		}
		$before = $this->queries();
		$this->dashboard->disputed( $this->id['season'] );
		$this->dashboard->warnings( $this->id['season'] );
		self::assertSame( 2, $this->queries() - $before );
	}

	/**
	 * Anzahl SELECT-Anweisungen dieser Verbindung (SHOW zählt nicht mit).
	 */
	private function queries(): int {
		$row = $this->db->get_row( "SHOW SESSION STATUS LIKE 'Com_select'" );
		return (int) ( $row['Value'] ?? 0 );
	}
}
