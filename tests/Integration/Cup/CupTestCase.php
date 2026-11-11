<?php
/**
 * Gemeinsame Testdaten des Mannschaftscups.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Tests\Integration\Cup;

use TTLeague\Access\AccessPolicy;
use TTLeague\Cup\Bracket;
use TTLeague\Cup\CupRepository;
use TTLeague\Cup\DrawService;
use TTLeague\Cup\Entries;
use TTLeague\Infrastructure\AuditLog;
use TTLeague\Infrastructure\Time\FixedClock;
use TTLeague\Results\Actor;
use TTLeague\Results\ResultException;
use TTLeague\Results\ResultService;
use TTLeague\Tests\Integration\Frontend\Seeder;
use TTLeague\Tests\Integration\IntegrationTestCase;

/**
 * Aktive Saison 2026/27 (1.8.2026 bis 31.7.2027) mit neun erfundenen Clubs. Jeder Club hat vier
 * gemeldete Spieler (Schlüssel 1 bis 4) und eine TK-Chefin oder einen TK-Chef mit Konto.
 *
 * Klassierungen: Spieler 1 hat 14 minus den Rang des Clubs (Seefeld 14, Wollishofen 13, …,
 * Affoltern 6), Spieler 2 hat 6, Spieler 3 hat 5, Spieler 4 hat 3. Die erste Mannschaft eines
 * Clubs (Spieler 1 und 2) hat damit die Punktesumme 20 (Seefeld) bis 12 (Affoltern).
 *
 * Konkurrenzen: «Mannschaftscup A» mit Punktelimite 20 und Meldeschluss 10.11.2026 sowie
 * «Mannschaftscup B» mit Punktelimite 10 ohne Meldeschluss.
 *
 * Die Uhr steht auf Montag, 2.11.2026, 09:00 Ortszeit (08:00 UTC), Zeitzone Europe/Zurich.
 */
abstract class CupTestCase extends IntegrationTestCase {

	protected const ZONE = 'Europe/Zurich';

	protected const CLUBS = array( 'Seefeld', 'Wollishofen', 'Oerlikon', 'Altstetten', 'Bergwiese', 'Lindenhof', 'Wiedikon', 'Enge', 'Affoltern' );

	protected FixedClock $clock;

	protected RecordingCupEvents $events;

	protected Seeder $seeder;

	protected CupRepository $repo;

	protected Entries $entries;

	protected DrawService $draw;

	protected Bracket $bracket;

	protected ResultService $results;

	protected BracketResultEvents $result_events;

	protected Actor $tk;

	protected int $season;

	protected int $cup_a;

	protected int $cup_b;

	/** @var array<string,int> Club nach Schlüssel (kleingeschriebener Name). */
	protected array $club = array();

	/** @var array<string,array<int,int>> Spieler: Club => Nummer 1 bis 4 => Spieler-ID. */
	protected array $player = array();

	/** @var array<string,Actor> TK-Chefs nach Club. */
	protected array $chef = array();

	public static function setUpBeforeClass(): void {
		require_once dirname( __DIR__, 2 ) . '/Unit/Frontend/Stubs/WordPress.php';
	}

	protected function setUp(): void {
		parent::setUp();
		$this->clock  = new FixedClock( '2026-11-02 08:00:00' );
		$this->events = new RecordingCupEvents();
		$this->seeder = new Seeder( $this->db );
		$this->repo   = new CupRepository( $this->db );
		$this->tk     = new Actor( 900, null, true, 'TK-Konto' );

		$this->season = $this->seeder->season( '2026-27' );
		foreach ( self::CLUBS as $rank => $name ) {
			$key                = strtolower( $name );
			$club               = $this->seeder->club( $name, 901 + $rank );
			$this->club[ $key ] = $club;
			foreach ( array( 14 - $rank, 6, 5, 3 ) as $i => $classification ) {
				$player                         = $this->seeder->player( 'Cupspieler', $name . ( $i + 1 ) );
				$this->player[ $key ][ $i + 1 ] = $player;
				$this->seeder->register( $player, $this->season, $club, $classification );
			}
			$person = $this->seeder->person( 'Clubleitung', $name );
			$this->db->update(
				'person',
				array(
					'user_id' => 300 + $rank,
					'email'   => 'chef.' . $key . '@example.org',
				),
				array( 'id' => $person )
			);
			$this->seeder->role( $this->season, $club, $person, 'tk_chef' );
			$this->chef[ $key ] = new Actor( 300 + $rank, $person, false );
		}
		$this->cup_a = $this->seeder->competition( $this->season, 'CUP', 'Mannschaftscup A', 'A', null, 1 );
		$this->cup_b = $this->seeder->competition( $this->season, 'CUP', 'Mannschaftscup B', 'B', null, 2 );
		$this->format(
			$this->cup_a,
			array(
				'points_limit'          => 20,
				'registration_deadline' => '2026-11-10',
			)
		);
		$this->format( $this->cup_b, array( 'points_limit' => 10 ) );

		$this->build_services();
	}

	/**
	 * Dienste mit der aktuellen Uhr und ohne Zwischenspeicher der Rechteprüfung. Die Erfassung
	 * meldet Bestätigungen und Entscheide an das Tableau, das Tableau wertet Rückzüge über die
	 * Erfassung forfait.
	 */
	protected function build_services(): void {
		$zone                = new \DateTimeZone( self::ZONE );
		$audit               = new AuditLog( $this->db );
		$this->bracket       = new Bracket(
			$this->db,
			$this->clock,
			$zone,
			$audit,
			$this->events,
			function ( int $fixture_id, string $winner, Actor $actor, string $comment ): void {
				$this->results->decide(
					$fixture_id,
					$actor,
					array(
						'action'  => 'forfeit',
						'winner'  => $winner,
						'comment' => $comment,
					)
				);
			}
		);
		$this->result_events = new BracketResultEvents( $this->bracket );
		$this->results       = new ResultService( $this->db, new AccessPolicy( $this->db ), $this->clock, $zone, $audit, $this->result_events );
		$this->entries       = new Entries( $this->db, new AccessPolicy( $this->db ), $this->clock, $zone, $audit, $this->events, $this->bracket );
		$this->draw          = new DrawService( $this->db, $this->clock, $zone, $audit, $this->events, $this->bracket, static fn(): int => 4711 );
	}

	/**
	 * @param array<string,mixed> $format Parameter der Konkurrenz.
	 */
	protected function format( int $competition_id, array $format ): void {
		$this->db->update( 'competition', array( 'format_json' => (string) json_encode( $format ) ), array( 'id' => $competition_id ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Tests laufen ohne WordPress.
	}

	/**
	 * Meldet als TK die Mannschaft eines Clubs aus den Spielern mit den Nummern $first und $second.
	 *
	 * @return array<string,mixed> Meldung.
	 */
	protected function team( string $club, int $first = 1, int $second = 2, ?int $competition_id = null ): array {
		return $this->entries->register( $competition_id ?? $this->cup_a, $this->club[ $club ], $this->tk, $this->player[ $club ][ $first ], $this->player[ $club ][ $second ] );
	}

	/**
	 * Meldet die ersten Mannschaften der ersten $count Clubs im Cup A, im Abstand einer Minute.
	 *
	 * @return array<string,int> Club => Meldung.
	 */
	protected function teams( int $count ): array {
		$ids = array();
		foreach ( array_slice( self::CLUBS, 0, $count ) as $name ) {
			$key         = strtolower( $name );
			$ids[ $key ] = $this->team( $key )['id'];
			$this->clock->advance( '+1 minute' );
		}
		return $ids;
	}

	/**
	 * Legt das Tableau von Hand fest und gibt es frei: Plätze paarweise, Club oder null (Freilos).
	 *
	 * @param list<string|null>  $positions Clubs der gemeldeten Mannschaften in der Reihenfolge der Plätze.
	 * @param array<string,int>  $ids       Club => Meldung.
	 * @return array<string,mixed> Ergebnis von DrawService::release().
	 */
	protected function release( array $positions, array $ids, ?string $deadline = '2026-11-30' ): array {
		$this->draw->propose( $this->cup_a, $this->tk, 1 );
		$this->draw->change( $this->cup_a, $this->tk, array_map( static fn( ?string $club ): ?int => null === $club ? null : $ids[ $club ], $positions ) );
		return $this->draw->release( $this->cup_a, $this->tk, $deadline );
	}

	/**
	 * Acht Mannschaften, Tableau in der Reihenfolge der Clubs: Seefeld – Wollishofen,
	 * Oerlikon – Altstetten, Bergwiese – Lindenhof, Wiedikon – Enge.
	 *
	 * @return array<string,int> Club => Meldung.
	 */
	protected function eight(): array {
		$ids = $this->teams( 8 );
		$this->release( array_keys( $ids ), $ids );
		return $ids;
	}

	/**
	 * Paarung nach Runde und Platz.
	 *
	 * @return array<string,mixed>
	 */
	protected function tie( int $round, int $slot, ?int $competition_id = null ): array {
		foreach ( $this->repo->ties( $competition_id ?? $this->cup_a ) as $tie ) {
			if ( $tie['round'] === $round && $tie['slot'] === $slot ) {
				return $tie;
			}
		}
		self::fail( 'Paarung ' . $round . '/' . $slot . ' fehlt.' );
	}

	/**
	 * Begegnung der Paarung.
	 */
	protected function fixture_of( int $round, int $slot ): int {
		$fixture = $this->tie( $round, $slot )['fixture'];
		self::assertNotNull( $fixture, 'Paarung ' . $round . '/' . $slot . ' hat keine Begegnung.' );
		return $fixture['id'];
	}

	/**
	 * Erfassung einer Cup-Begegnung in der gemeldeten Aufstellung; die Siegerseite gewinnt die
	 * ersten drei Spiele 3:0.
	 *
	 * @param array{home?:array<string,int>,away?:array<string,int>} $lineup Abweichende Aufstellung je Seite.
	 * @return array<string,mixed> Eingabe wie POST result.
	 */
	protected function payload( int $fixture_id, string $winner, array $lineup = array() ): array {
		$row   = (array) $this->db->get_row( "SELECT home_entry_id, away_entry_id FROM {$this->db->table( 'fixture' )} WHERE id = %d", array( $fixture_id ) );
		$home  = (array) $this->repo->entry( (int) $row['home_entry_id'] );
		$away  = (array) $this->repo->entry( (int) $row['away_entry_id'] );
		$games = array();
		foreach ( array( 1, 2, 3 ) as $sequence ) {
			$games[] = array(
				'sequence'  => $sequence,
				'status'    => 'played',
				'sets_home' => 'home' === $winner ? 3 : 0,
				'sets_away' => 'home' === $winner ? 0 : 3,
			);
		}
		return array(
			'lineup' => array(
				'home' => $lineup['home'] ?? array(
					'A' => $home['players'][0]['player_id'],
					'B' => $home['players'][1]['player_id'],
				),
				'away' => $lineup['away'] ?? array(
					'Y' => $away['players'][0]['player_id'],
					'Z' => $away['players'][1]['player_id'],
				),
			),
			'games'  => $games,
		);
	}

	/**
	 * Die TK erfasst und bestätigt das Resultat einer Begegnung.
	 */
	protected function play( int $fixture_id, string $winner ): void {
		$this->results->submit( $fixture_id, $this->payload( $fixture_id, $winner ), $this->tk );
		$this->results->decide( $fixture_id, $this->tk, array( 'action' => 'confirm' ) );
	}

	/**
	 * Person eines Spielers als handelnde Person mit eigenem Konto.
	 */
	protected function player_actor( string $club, int $number ): Actor {
		$person = $this->seeder->person_of( $this->player[ $club ][ $number ] );
		$user   = 500 + $person;
		$this->db->update(
			'person',
			array(
				'user_id' => $user,
				'email'   => 'spieler.' . $person . '@example.org',
			),
			array( 'id' => $person )
		);
		return new Actor( $user, $person, false );
	}

	/**
	 * @return array<string,string|null>
	 */
	protected function fixture_row( int $fixture_id ): array {
		return (array) $this->db->get_row(
			"SELECT status, scheduled_at, venue_id, round, match_no, home_entry_id, away_entry_id, home_score, away_score FROM {$this->db->table( 'fixture' )} WHERE id = %d",
			array( $fixture_id )
		);
	}

	/**
	 * Einträge des Änderungsprotokolls zu einem Objekt, älteste zuerst.
	 *
	 * @return list<array{action:string,user_id:int|null,before:mixed,after:mixed}>
	 */
	protected function audit( string $entity, int $entity_id ): array {
		$rows = array();
		foreach ( $this->db->get_results(
			"SELECT action, user_id, before_json, after_json FROM {$this->db->table( 'audit_log' )} WHERE entity = %s AND entity_id = %d ORDER BY id",
			array( $entity, $entity_id )
		) as $row ) {
			$rows[] = array(
				'action'  => (string) $row['action'],
				'user_id' => null === $row['user_id'] ? null : (int) $row['user_id'],
				'before'  => null === $row['before_json'] ? null : json_decode( $row['before_json'], true ),
				'after'   => null === $row['after_json'] ? null : json_decode( $row['after_json'], true ),
			);
		}
		return $rows;
	}

	/**
	 * @param callable():mixed $call Aufruf, der scheitern soll.
	 */
	protected static function expect_error( callable $call, int $status ): ResultException {
		try {
			$call();
		} catch ( ResultException $e ) {
			self::assertSame( $status, $e->status, $e->getMessage() );
			return $e;
		}
		self::fail( 'Fehler ' . $status . ' erwartet.' );
	}
}
