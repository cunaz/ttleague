<?php
/**
 * Gemeinsame Testdaten des Saison-Assistenten.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Tests\Integration\Season;

use TTLeague\Access\AccessPolicy;
use TTLeague\Infrastructure\AuditLog;
use TTLeague\Infrastructure\Database\Db;
use TTLeague\Infrastructure\Time\FixedClock;
use TTLeague\Results\Actor;
use TTLeague\Results\ResultService;
use TTLeague\Season\Grouping;
use TTLeague\Season\Lifecycle;
use TTLeague\Season\Scheduling;
use TTLeague\Season\SeasonException;
use TTLeague\Season\SeasonRepository;
use TTLeague\Season\SeasonSetup;
use TTLeague\Season\SilentResultEvents;
use TTLeague\Tests\Integration\Frontend\Seeder;
use TTLeague\Tests\Integration\IntegrationTestCase;

/**
 * Erfundene Liga mit den Clubs Seefeld, Wollishofen, Oerlikon und Altstetten.
 *
 * league() legt die aktive Saison 2026/27 an: Gruppe A mit Seefeld 1, Wollishofen 1 und
 * Oerlikon 1, Gruppe B mit Seefeld 2, Altstetten 1 und Wollishofen 2. Jeder Club hat ein Lokal
 * mit vier Tischen (Seefeld und Altstetten am Dienstag, Wollishofen am Mittwoch, Oerlikon am
 * Donnerstag), eine TK-Chefin oder einen TK-Chef und je Mannschaft einen Captain.
 *
 * plan() legt daraus über SeasonSetup die geplante Saison 2027/28 an und meldet dieselben
 * Mannschaften mit geprüfter Meldung, wie es die Clubs über die Meldung täten.
 */
abstract class SeasonTestCase extends IntegrationTestCase {

	protected const TEAMS = array(
		'seefeld_1'     => array( 'seefeld', 1, 'A', '20:00:00' ),
		'wollishofen_1' => array( 'wollishofen', 1, 'A', '19:30:00' ),
		'oerlikon_1'    => array( 'oerlikon', 1, 'A', '20:00:00' ),
		'seefeld_2'     => array( 'seefeld', 2, 'B', '20:00:00' ),
		'altstetten_1'  => array( 'altstetten', 1, 'B', '19:45:00' ),
		'wollishofen_2' => array( 'wollishofen', 2, 'B', '19:30:00' ),
	);

	protected Seeder $seed;

	protected RecordingSeasonEvents $events;

	protected SeasonSetup $setup;

	protected Grouping $grouping;

	protected Scheduling $scheduling;

	protected Lifecycle $lifecycle;

	protected SeasonRepository $seasons;

	protected Actor $tk;

	protected Actor $chef;

	/** @var array<string,int> */
	protected array $id = array();

	public static function setUpBeforeClass(): void {
		require_once dirname( __DIR__, 2 ) . '/Unit/Frontend/Stubs/WordPress.php';
	}

	protected function setUp(): void {
		parent::setUp();
		$this->seed    = new Seeder( $this->db );
		$this->tk      = new Actor( 900, null, true, 'TK' );
		$this->chef    = new Actor( 901, null, false, 'Charlotte Chef' );
		$this->seasons = new SeasonRepository( $this->db );
		$this->id      = array();
		$this->services( $this->db );
	}

	/**
	 * Dienste auf einer Verbindung aufbauen (auch auf einer, die Fehler einstreut).
	 */
	protected function services( Db $db ): void {
		$this->events     = new RecordingSeasonEvents();
		$audit            = new AuditLog( $db );
		$this->setup      = new SeasonSetup( $db, $audit, $this->events );
		$this->grouping   = new Grouping( $db, $audit, $this->events );
		$this->scheduling = new Scheduling( $db, $audit, $this->events );
		$this->lifecycle  = new Lifecycle(
			$db,
			$audit,
			$this->events,
			$this->scheduling,
			new ResultService( $db, new AccessPolicy( $db ), new FixedClock( '2027-03-01 12:00:00' ), new \DateTimeZone( 'Europe/Zurich' ), $audit, new SilentResultEvents() )
		);
	}

	/**
	 * Aktive Saison 2026/27 mit vier Clubs, zwei Gruppen und sechs Mannschaften.
	 *
	 * @param int|null $tables Tische pro Lokal; null: unbekannt.
	 */
	protected function league( ?int $tables = 4 ): void {
		$s                  = $this->seed;
		$season             = $s->season( '2026-27', 'active', array( 'substitute_max' => 2 ) );
		$clubs              = array(
			'seefeld'     => array( 'Seefeld', 1, 2, 'Chef', 'Charlotte' ),
			'wollishofen' => array( 'Wollishofen', 2, 3, 'Wolli', 'Walter' ),
			'oerlikon'    => array( 'Oerlikon', 3, 4, 'Oerli', 'Olga' ),
			'altstetten'  => array( 'Altstetten', 4, 2, 'Alt', 'Alma' ),
		);
		$this->id['active'] = $season;
		foreach ( $clubs as $key => [ $name, $number, $weekday, $last, $first ] ) {
			$club                        = $s->club( $name, $number );
			$this->id[ 'club_' . $key ]  = $club;
			$this->id[ 'venue_' . $key ] = $s->venue(
				$club,
				'Halle ' . $name,
				array(
					'weekday'      => $weekday,
					'tables_count' => $tables,
				)
			);
			$this->id[ 'chef_' . $key ]  = $s->person( $last, $first );
			$s->role( $season, $club, $this->id[ 'chef_' . $key ], 'tk_chef' );
		}
		$this->id['group_a'] = $s->competition( $season, 'MM', 'Gruppe A', 'A', 1, 1 );
		$this->id['group_b'] = $s->competition( $season, 'MM', 'Gruppe B', 'B', 1, 2 );
		foreach ( self::TEAMS as $key => [ $club, $number, $category, $start ] ) {
			$team                          = $s->team(
				$season,
				$this->id[ 'club_' . $club ],
				$number,
				ucfirst( $club ) . ' ' . $number,
				$this->id[ 'group_' . strtolower( $category ) ],
				$this->id[ 'venue_' . $club ],
				$start
			);
			$this->id[ $key ]              = $team;
			$this->id[ 'captain_' . $key ] = $s->person( 'Captain', ucfirst( $club ) . $number );
			$s->role( $season, $this->id[ 'club_' . $club ], $this->id[ 'captain_' . $key ], 'captain', $team );
		}
	}

	/**
	 * Geplante Saison 2027/28 über SeasonSetup, dazu die gemeldeten Mannschaften mit Heimlokal
	 * und Anspielzeit und geprüfte Meldungen aller Clubs. IDs unter «next» und «next_<team>».
	 *
	 * @param list<string>|null $teams Schlüssel aus TEAMS; null: alle.
	 */
	protected function plan( ?array $teams = null ): int {
		$next             = $this->setup->create( $this->tk );
		$this->id['next'] = $next;
		foreach ( self::TEAMS as $key => [ $club, $number, , $start ] ) {
			if ( null !== $teams && ! in_array( $key, $teams, true ) ) {
				continue;
			}
			$this->id[ 'next_' . $key ] = $this->seed->team( $next, $this->id[ 'club_' . $club ], $number, ucfirst( $club ) . ' ' . $number, null, $this->id[ 'venue_' . $club ], $start );
		}
		$this->db->update( 'club_season', array( 'status' => 'reviewed' ), array( 'season_id' => $next ) );
		return $next;
	}

	/**
	 * Geplante Saison mit Gruppen und Einteilung aus dem Vorjahr.
	 */
	protected function grouped(): int {
		$next = $this->plan();
		$this->grouping->apply_proposal( $this->tk, $next );
		foreach ( $this->grouping->groups( $next ) as $group ) {
			$this->id[ 'next_group_' . strtolower( (string) $group['category'] ) ] = $group['id'];
		}
		return $next;
	}

	/**
	 * Geplante Saison mit Einteilung und Spielwochen: je vier Wochen ab dem 6. September 2027
	 * und ab dem 10. Januar 2028. Jede Gruppe braucht drei pro Halbserie; eine bleibt Reserve.
	 */
	protected function with_weeks(): int {
		$next = $this->grouped();
		$this->scheduling->save_weeks(
			$this->tk,
			$next,
			array( '2027-09-06', '2027-09-13', '2027-09-20', '2027-09-27' ),
			array( '2028-01-10', '2028-01-17', '2028-01-24', '2028-01-31' )
		);
		return $next;
	}

	/**
	 * Geplante Saison mit erzeugtem Spielplan.
	 */
	protected function scheduled(): int {
		$next = $this->with_weeks();
		$this->scheduling->generate( $this->tk, $next );
		return $next;
	}

	/**
	 * Führt die Aktion aus und liefert die erwartete Ablehnung.
	 *
	 * @param callable():mixed $action Aktion.
	 */
	protected function refused( callable $action, string $code ): SeasonException {
		try {
			$action();
		} catch ( SeasonException $e ) {
			self::assertSame( $code, $e->error_code, $e->getMessage() );
			return $e;
		}
		self::fail( 'Ablehnung ' . $code . ' erwartet.' );
	}

	protected function rows( string $table, string $where = '1 = 1' ): int {
		return (int) $this->db->get_var( "SELECT COUNT(*) FROM {$this->db->table( $table )} WHERE {$where}" );
	}

	/**
	 * @return array<string,string|null>
	 */
	protected function row( string $table, int $id ): array {
		return (array) $this->db->get_row( "SELECT * FROM {$this->db->table( $table )} WHERE id = %d", array( $id ) );
	}

	protected function season_status( int $season_id ): string {
		return (string) $this->db->get_var( "SELECT status FROM {$this->db->table( 'season' )} WHERE id = %d", array( $season_id ) );
	}

	/**
	 * Begegnungen einer Gruppe als «Heim – Gast @ Termin», nach laufender Nummer.
	 *
	 * @return list<string>
	 */
	protected function plan_of( int $competition_id ): array {
		return array_map(
			static fn( array $row ): string => $row['home'] . ' – ' . $row['away'] . ' @ ' . $row['scheduled_at'],
			$this->db->get_results(
				"SELECT h.name AS home, a.name AS away, f.scheduled_at FROM {$this->db->table( 'fixture' )} f
				JOIN {$this->db->table( 'team' )} h ON h.id = f.home_team_id
				JOIN {$this->db->table( 'team' )} a ON a.id = f.away_team_id
				WHERE f.competition_id = %d ORDER BY f.match_no",
				array( $competition_id )
			)
		);
	}
}
