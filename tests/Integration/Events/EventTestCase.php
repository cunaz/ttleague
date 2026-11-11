<?php
/**
 * Gemeinsame Testdaten der Anlässe.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Tests\Integration\Events;

use TTLeague\Access\AccessPolicy;
use TTLeague\Events\EventListener;
use TTLeague\Events\EventService;
use TTLeague\Events\RegistrationService;
use TTLeague\Infrastructure\AuditLog;
use TTLeague\Infrastructure\Database\Db;
use TTLeague\Infrastructure\Time\Clock;
use TTLeague\Infrastructure\Time\FixedClock;
use TTLeague\Results\Actor;
use TTLeague\Tests\Integration\Frontend\Seeder;
use TTLeague\Tests\Integration\IntegrationTestCase;

/**
 * Erfundene Clubs Seefeld (Spieler h1 bis h4) und Wollishofen (a1, a2) in der aktiven Saison
 * 2026/27. Charlotte Chef ist TK-Chefin von Seefeld, Wilma Gastchef von Wollishofen, Hanna Heim
 * Captain ohne Lizenz, Nina Niemand ohne Funktion, Tamara Kommission Mitglied der TK. Alle
 * haben ein Konto; dazu ein TK-Konto ohne Person (208) und ein Konto ohne Person (300).
 *
 * Die Uhr steht auf Dienstag, 10. November 2026, 12:00 UTC (13:00 Ortszeit Zürich). Der Anlass
 * aus event() beginnt am 20. November um 19:30, Anmeldeschluss ist der 15. November, 18:00
 * Ortszeit (17:00 UTC).
 */
abstract class EventTestCase extends IntegrationTestCase {

	protected const DEADLINE_UTC = '2026-11-15 17:00:00';

	protected FixedClock $clock;

	protected RecordingListener $listener;

	protected EventService $events;

	protected RegistrationService $registrations;

	protected Seeder $seeder;

	/** @var array<string,int> */
	protected array $id = array();

	/** @var array<string,int> Personen nach Schlüssel. */
	protected array $person = array();

	/** @var array<string,Actor> */
	protected array $actor = array();

	public static function setUpBeforeClass(): void {
		require_once dirname( __DIR__, 2 ) . '/Unit/Frontend/Stubs/WordPress.php';
	}

	protected function setUp(): void {
		parent::setUp();
		$this->seeder = new Seeder( $this->db );
		$s            = $this->seeder;
		$season       = $s->season( '2026-27' );
		$home         = $s->club( 'Seefeld', 1 );
		$away         = $s->club( 'Wollishofen', 2 );

		$players = array();
		foreach ( array( 'h1', 'h2', 'h3', 'h4' ) as $i => $key ) {
			$players[ $key ] = $s->player( 'Seefelder', 'Spieler' . ( $i + 1 ) );
			$s->register( $players[ $key ], $season, $home, 10 - $i );
		}
		foreach ( array( 'a1', 'a2' ) as $i => $key ) {
			$players[ $key ] = $s->player( 'Wollishofer', 'Spieler' . ( $i + 1 ) );
			$s->register( $players[ $key ], $season, $away, 10 - $i );
		}

		$this->person = array(
			'chef_home' => $s->person( 'Chef', 'Charlotte' ),
			'chef_away' => $s->person( 'Gastchef', 'Wilma' ),
			'captain'   => $s->person( 'Heim', 'Hanna' ),
			'nobody'    => $s->person( 'Niemand', 'Nina' ),
			'tk'        => $s->person( 'Kommission', 'Tamara' ),
		);
		foreach ( $players as $key => $player_id ) {
			$this->person[ $key ] = $s->person_of( $player_id );
		}
		$s->role( $season, $home, $this->person['chef_home'], 'tk_chef' );
		$s->role( $season, $away, $this->person['chef_away'], 'tk_chef' );
		$s->role( $season, $home, $this->person['captain'], 'captain' );

		$user = 100;
		foreach ( $this->person as $key => $person_id ) {
			++$user;
			$this->db->update(
				'person',
				array(
					'user_id' => $user,
					'email'   => str_replace( '_', '.', $key ) . '@example.org',
				),
				array( 'id' => $person_id )
			);
			$this->actor[ $key ] = new Actor( $user, $person_id, 'tk' === $key );
		}
		$this->actor['tk_account'] = new Actor( 208, null, true, 'TK-Konto' );
		$this->actor['unlinked']   = new Actor( 300, null, false, 'Konto ohne Person' );

		$this->id = $players + array(
			'season'    => $season,
			'club_home' => $home,
			'club_away' => $away,
		);

		$this->clock         = new FixedClock( '2026-11-10 12:00:00' );
		$this->listener      = new RecordingListener();
		$this->events        = $this->event_service( $this->listener );
		$this->registrations = $this->registration_service( $this->clock, $this->listener );
	}

	protected function event_service( EventListener $listener, ?Db $db = null ): EventService {
		$db ??= $this->db;
		return new EventService( $db, new AuditLog( $db ), $listener );
	}

	protected function registration_service( Clock $clock, EventListener $listener, ?Db $db = null ): RegistrationService {
		$db ??= $this->db;
		return new RegistrationService( $db, new AccessPolicy( $db ), $clock, new \DateTimeZone( 'Europe/Zurich' ), new AuditLog( $db ), $listener );
	}

	/**
	 * Eingabe eines Turniers am 20. November 2026.
	 *
	 * @param array<string,mixed> $overrides Abweichende Felder.
	 * @return array<string,mixed>
	 */
	protected function input( array $overrides = array() ): array {
		return $overrides + array(
			'season_id'        => (string) $this->id['season'],
			'type'             => 'tournament',
			'title'            => 'Plauschturnier',
			'starts_at'        => '2026-11-20T19:30',
			'ends_at'          => '2026-11-20T22:00',
			'location'         => 'Turnhalle Seefeld',
			'deadline'         => '2026-11-15T18:00',
			'max_participants' => '',
			'post_id'          => '',
		);
	}

	/**
	 * Legt einen Anlass an (als TK) und vergisst die dabei gemeldeten Ereignisse.
	 *
	 * @param array<string,mixed> $overrides Abweichende Felder.
	 */
	protected function event( array $overrides = array() ): int {
		$id                    = $this->events->create( $this->input( $overrides ), $this->actor['tk'] );
		$this->listener->calls = array();
		return $id;
	}

	/**
	 * Meldet die Personen selbst an, im Abstand von einer Minute.
	 *
	 * @param list<string> $keys Schlüssel der Personen.
	 */
	protected function register_all( int $event_id, array $keys ): void {
		foreach ( $keys as $key ) {
			$this->registrations->register( $event_id, $this->person[ $key ], $this->actor[ $key ] );
			$this->clock->advance( '+1 minute' );
		}
	}

	/**
	 * Anmeldungen eines Anlasses: Schlüssel der Person => Status, in der Reihenfolge der Liste.
	 *
	 * @return array<string,string>
	 */
	protected function list_of( int $event_id ): array {
		$keys = array_flip( $this->person );
		$list = array();
		foreach ( $this->events->repository()->registrations( $event_id, $this->id['season'] ) as $row ) {
			$list[ (string) $keys[ $row['person_id'] ] ] = $row['status'];
		}
		return $list;
	}

	protected function status_of( int $event_id ): string {
		return (string) $this->db->get_var( "SELECT status FROM {$this->db->table( 'event' )} WHERE id = %d", array( $event_id ) );
	}

	/**
	 * Aktionen im Änderungsprotokoll, die ältesten zuerst.
	 *
	 * @return list<string>
	 */
	protected function audit_actions( string $entity ): array {
		return array_map( 'strval', $this->db->get_col( "SELECT action FROM {$this->db->table( 'audit_log' )} WHERE entity = %s ORDER BY id", array( $entity ) ) );
	}

	/**
	 * Führt die Aktion aus und liefert den fachlichen Fehler.
	 *
	 * @param callable():mixed $action Aktion.
	 */
	protected function failure( callable $action ): \TTLeague\Events\EventException {
		try {
			$action();
		} catch ( \TTLeague\Events\EventException $e ) {
			return $e;
		}
		self::fail( 'Fachlicher Fehler erwartet.' );
	}
}
