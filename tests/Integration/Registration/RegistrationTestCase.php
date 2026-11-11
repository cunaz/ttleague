<?php
/**
 * Gemeinsame Testdaten der Meldung.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Tests\Integration\Registration;

use TTLeague\Access\AccessPolicy;
use TTLeague\Infrastructure\AuditLog;
use TTLeague\Infrastructure\Time\FixedClock;
use TTLeague\Registration\ClubRegistration;
use TTLeague\Registration\RegistrationAccess;
use TTLeague\Registration\RegistrationException;
use TTLeague\Registration\Review;
use TTLeague\Registration\Venues;
use TTLeague\Results\Actor;
use TTLeague\Tests\Integration\Frontend\Seeder;
use TTLeague\Tests\Integration\IntegrationTestCase;

/**
 * Erfundene Clubs Seefeld und Wollishofen in drei Saisons: 2025/26 archiviert, 2026/27 aktiv
 * (Nachmeldungen bis 31.1.2027), 2027/28 geplant mit Meldefenster 1. bis 31. Mai 2027.
 *
 * Aktive Saison: Seefeld mit den Spielern Hans Muster (8), Berta Beispiel (7), Paul Probe (6)
 * und Vera Vorlage (5), der Mannschaft Seefeld 1 (Muster, Beispiel, Probe; Captain Hanna Heim)
 * und TK-Chefin Charlotte Chef. Wollishofen mit Gustav Gast (9) und TK-Chef Walter Wolli.
 * Erika Ehemalig war nur 2025/26 für Seefeld gemeldet (4).
 *
 * Geplante Saison: beide Clubs mit offener Meldung, die TK-Chefs sind vorgetragen. Nina Neu
 * ist nur dort TK-Chefin von Wollishofen, Alma Alt nur in der aktiven Saison (Seefeld).
 *
 * Die Uhr steht zu Beginn auf dem 10. Mai 2027, 12 Uhr Ortszeit (Europe/Zurich).
 */
abstract class RegistrationTestCase extends IntegrationTestCase {

	protected const IN_WINDOW = '2027-05-10 10:00:00';

	protected FixedClock $clock;

	protected RecordingEvents $events;

	protected ClubRegistration $registration;

	protected Venues $venues;

	protected Review $review;

	protected Seeder $seed;

	/** @var array<string,int> */
	protected array $id = array();

	/** @var array<string,Actor> */
	protected array $actor = array();

	public static function setUpBeforeClass(): void {
		require_once dirname( __DIR__, 2 ) . '/Unit/Frontend/Stubs/WordPress.php';
	}

	protected function setUp(): void {
		parent::setUp();
		$s          = new Seeder( $this->db );
		$this->seed = $s;
		$old        = $s->season( '2025-26', 'archived' );
		$now        = $s->season( '2026-27', 'active' );
		$next       = $s->season( '2027-28', 'planned' );
		$this->db->update( 'season', array( 'late_registration_until' => '2027-01-31' ), array( 'id' => $now ) );
		$this->db->update(
			'season',
			array(
				'registration_opens'  => '2027-05-01',
				'registration_closes' => '2027-05-31',
			),
			array( 'id' => $next )
		);
		$home  = $s->club( 'Seefeld', 1 );
		$away  = $s->club( 'Wollishofen', 2 );
		$group = $s->competition( $now, 'MM', 'Gruppe B1', 'B', 1 );
		$venue = $s->venue( $home, 'Turnhalle Seefeld', array( 'tables_count' => 4 ) );
		$team  = $s->team( $now, $home, 1, 'Seefeld 1', $group, $venue );

		$players = array();
		foreach ( array(
			'muster'   => array( 'Muster', 'Hans', $home, 8 ),
			'beispiel' => array( 'Beispiel', 'Berta', $home, 7 ),
			'probe'    => array( 'Probe', 'Paul', $home, 6 ),
			'vorlage'  => array( 'Vorlage', 'Vera', $home, 5 ),
			'gast'     => array( 'Gast', 'Gustav', $away, 9 ),
		) as $key => [ $last, $first, $club, $classification ] ) {
			$players[ $key ] = $s->player( $last, $first );
			$s->register( $players[ $key ], $now, $club, $classification );
		}
		$players['ehemalig'] = $s->player( 'Ehemalig', 'Erika' );
		$s->register( $players['ehemalig'], $old, $home, 4 );
		$s->roster( $team, array( $players['muster'], $players['beispiel'], $players['probe'] ) );

		$people = array(
			'chef'    => $s->person( 'Chef', 'Charlotte' ),
			'wolli'   => $s->person( 'Wolli', 'Walter' ),
			'neu'     => $s->person( 'Neu', 'Nina' ),
			'alt'     => $s->person( 'Alt', 'Alma' ),
			'heim'    => $s->person( 'Heim', 'Hanna' ),
			'niemand' => $s->person( 'Niemand', 'Nora' ),
		);
		foreach ( array( 'chef', 'heim' ) as $key ) {
			$this->db->update( 'person', array( 'email' => $key . '@example.invalid' ), array( 'id' => $people[ $key ] ) );
		}
		$this->db->update( 'person', array( 'email' => 'hans.muster@example.invalid' ), array( 'id' => $s->person_of( $players['muster'] ) ) );

		$s->role( $now, $home, $people['chef'], 'tk_chef' );
		$s->role( $now, $home, $people['alt'], 'tk_chef' );
		$s->role( $now, $away, $people['wolli'], 'tk_chef' );
		$s->role( $now, $home, $people['heim'], 'captain', $team );
		$s->role( $next, $home, $people['chef'], 'tk_chef' );
		$s->role( $next, $away, $people['wolli'], 'tk_chef' );
		$s->role( $next, $away, $people['neu'], 'tk_chef' );
		foreach ( array( $home, $away ) as $club ) {
			$this->db->insert(
				'club_season',
				array(
					'season_id' => $next,
					'club_id'   => $club,
					'status'    => 'open',
				)
			);
		}

		$this->id = array(
			'old'   => $old,
			'now'   => $now,
			'next'  => $next,
			'home'  => $home,
			'away'  => $away,
			'venue' => $venue,
			'team'  => $team,
		);
		foreach ( $players as $key => $player ) {
			$this->id[ 'p_' . $key ] = $player;
		}
		foreach ( $people as $key => $person ) {
			$this->id[ 'person_' . $key ] = $person;
		}
		$this->actor = array(
			'chef'    => new Actor( 301, $people['chef'], false, 'Charlotte Chef' ),
			'wolli'   => new Actor( 302, $people['wolli'], false, 'Walter Wolli' ),
			'neu'     => new Actor( 304, $people['neu'], false, 'Nina Neu' ),
			'alt'     => new Actor( 305, $people['alt'], false, 'Alma Alt' ),
			'niemand' => new Actor( 306, $people['niemand'], false, 'Nora Niemand' ),
			'tk'      => new Actor( 303, null, true, 'TK' ),
		);
		$this->at( self::IN_WINDOW );
	}

	/**
	 * Dienste mit einer Uhr auf diesem Zeitpunkt (UTC) neu aufbauen.
	 */
	protected function at( string $utc ): void {
		$this->clock        = new FixedClock( $utc );
		$this->events       = new RecordingEvents();
		$access             = new RegistrationAccess( $this->db, new AccessPolicy( $this->db ), $this->clock, new \DateTimeZone( 'Europe/Zurich' ) );
		$audit              = new AuditLog( $this->db );
		$this->registration = new ClubRegistration( $this->db, $access, $audit, $this->events );
		$this->venues       = new Venues( $this->db, $access, $audit, $this->events );
		$this->review       = new Review( $this->db, $access, $this->registration, $audit, $this->events );
	}

	/**
	 * Führt die Aktion aus und liefert die erwartete Ablehnung.
	 *
	 * @param callable():mixed $action Aktion.
	 */
	protected function refused( callable $action, string $code ): RegistrationException {
		try {
			$action();
		} catch ( RegistrationException $e ) {
			self::assertSame( $code, $e->error_code, $e->getMessage() );
			return $e;
		}
		self::fail( 'Ablehnung ' . $code . ' erwartet.' );
	}

	protected function rows( string $table, string $where = '1 = 1' ): int {
		return (int) $this->db->get_var( "SELECT COUNT(*) FROM {$this->db->table( $table )} WHERE {$where}" );
	}

	/**
	 * @return array<string,string|null>|null
	 */
	protected function player_season( int $season_id, int $player_id ): ?array {
		return $this->db->get_row( "SELECT * FROM {$this->db->table( 'player_season' )} WHERE season_id = %d AND player_id = %d", array( $season_id, $player_id ) );
	}

	protected function registration_status( int $club_id ): string {
		return (string) $this->db->get_var( "SELECT status FROM {$this->db->table( 'club_season' )} WHERE season_id = %d AND club_id = %d", array( $this->id['next'], $club_id ) );
	}

	/**
	 * Vollständige Meldung von Seefeld für die geplante Saison: drei bestätigte Spieler, eine
	 * Mannschaft mit Lokal, Anspielzeit und Captain Hans Muster. Liefert die Mannschaft.
	 */
	protected function complete_registration(): int {
		$next = $this->id['next'];
		$home = $this->id['home'];
		foreach ( array( 'muster', 'beispiel', 'probe' ) as $key ) {
			$this->registration->confirm_player( $this->actor['chef'], $next, $home, $this->id[ 'p_' . $key ] );
		}
		$team = $this->registration->add_team( $this->actor['chef'], $next, $home );
		$this->registration->save_team(
			$this->actor['chef'],
			$team,
			array(
				'players'           => array( $this->id['p_muster'], $this->id['p_beispiel'], $this->id['p_probe'] ),
				'home_venue_id'     => $this->id['venue'],
				'start_time'        => '20:00',
				'captain_person_id' => $this->seed->person_of( $this->id['p_muster'] ),
			)
		);
		return $team;
	}
}
