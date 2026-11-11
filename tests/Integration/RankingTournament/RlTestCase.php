<?php
/**
 * Gemeinsame Testdaten des Ranglistenturniers.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Tests\Integration\RankingTournament;

use TTLeague\Access\AccessPolicy;
use TTLeague\Infrastructure\AuditLog;
use TTLeague\Infrastructure\Time\FixedClock;
use TTLeague\RankingTournament\GroupEntry;
use TTLeague\RankingTournament\Registrations;
use TTLeague\RankingTournament\Repository;
use TTLeague\RankingTournament\Rounds;
use TTLeague\Results\Actor;
use TTLeague\Tests\Integration\Frontend\Seeder;
use TTLeague\Tests\Integration\IntegrationTestCase;

/**
 * Aktive Saison 2026/27 mit den erfundenen Clubs Seefeld, Wollishofen, Oerlikon und Altstetten.
 * Jeder Club hat ein Lokal mit Spieltag Montag bis Donnerstag (in dieser Reihenfolge) und sechs
 * gemeldete Spieler mit den Klassierungen 18, 16, 12, 9, 8 und 3. Kategorien des Turniers:
 * 1 ab Klassierung 16, 2 ab 9, 3 ab 1. Anmeldeschluss ist der 10. September 2026.
 *
 * Schlüssel der Spieler: s1 bis s6 (Seefeld), w1 bis w6, o1 bis o6, a1 bis a6. Dazu die TK
 * (Konto 900), die TK-Chefin von Seefeld (Konto 901) und eine Person ohne Funktion (Konto 902).
 * Jeder Spieler hat das Konto 100 + Spieler-ID. Die Uhr steht auf dem 1. September 2026,
 * 12 Uhr Ortszeit Zürich.
 */
abstract class RlTestCase extends IntegrationTestCase {

	protected const CLUBS = array(
		's' => 'Seefeld',
		'w' => 'Wollishofen',
		'o' => 'Oerlikon',
		'a' => 'Altstetten',
	);

	protected const CLASSIFICATIONS = array( 18, 16, 12, 9, 8, 3 );

	protected FixedClock $clock;

	protected RecordingEvents $events;

	protected Seeder $seeder;

	protected AccessPolicy $policy;

	/** @var array<string,int> */
	protected array $id = array();

	/** @var array<string,int> Spieler-ID nach Schlüssel. */
	protected array $player = array();

	protected Actor $tk;

	public static function setUpBeforeClass(): void {
		require_once dirname( __DIR__, 2 ) . '/Unit/Frontend/Stubs/WordPress.php';
	}

	protected function setUp(): void {
		parent::setUp();
		$this->seed();
	}

	/**
	 * @param array<string,mixed> $rules  Saisonregeln.
	 * @param array<string,mixed> $format format_json der Konkurrenz.
	 */
	protected function seed( array $rules = array(), ?array $format = null ): void {
		$this->truncate_all();
		$this->clock  = new FixedClock( '2026-09-01 10:00:00' );
		$this->events = new RecordingEvents();
		$this->policy = new AccessPolicy( $this->db );
		$this->seeder = new Seeder( $this->db );
		$s            = $this->seeder;
		$season       = $s->season( '2026-27', 'active', $rules );
		$this->id     = array( 'season' => $season );
		$this->player = array();
		$weekday      = 1;
		foreach ( self::CLUBS as $key => $name ) {
			$club                        = $s->club( $name, $weekday );
			$this->id[ 'club_' . $key ]  = $club;
			$this->id[ 'venue_' . $key ] = $s->venue(
				$club,
				'Halle ' . $name,
				array(
					'weekday'   => $weekday,
					'open_time' => '19:15:00',
				)
			);
			foreach ( self::CLASSIFICATIONS as $i => $classification ) {
				$player                            = $s->player( $name . 'er', 'Spieler' . ( $i + 1 ) );
				$this->player[ $key . ( $i + 1 ) ] = $player;
				$s->register( $player, $season, $club, $classification );
				$this->db->update( 'person', array( 'user_id' => 100 + $player ), array( 'id' => $s->person_of( $player ) ) );
			}
			++$weekday;
		}
		$this->id['competition'] = $this->db->insert(
			'competition',
			array(
				'season_id'   => $season,
				'type'        => 'RL',
				'name'        => 'Ranglistenturnier',
				'format_json' => (string) json_encode( // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Tests laufen ohne WordPress.
					$format ?? array(
						'categories'            => array(
							array(
								'no'  => 1,
								'min' => 16,
							),
							array(
								'no'  => 2,
								'min' => 9,
							),
							array(
								'no'  => 3,
								'min' => 1,
							),
						),
						'registration_deadline' => '2026-09-10',
					)
				),
			)
		);

		$this->id['person_tk'] = $s->person( 'Kommission', 'Tina' );
		$this->db->update( 'person', array( 'user_id' => 900 ), array( 'id' => $this->id['person_tk'] ) );
		$this->id['person_chef'] = $s->person( 'Chef', 'Charlotte' );
		$this->db->update( 'person', array( 'user_id' => 901 ), array( 'id' => $this->id['person_chef'] ) );
		$s->role( $season, $this->id['club_s'], $this->id['person_chef'], 'tk_chef' );
		$this->id['person_nobody'] = $s->person( 'Niemand', 'Nina' );
		$this->db->update( 'person', array( 'user_id' => 902 ), array( 'id' => $this->id['person_nobody'] ) );

		$this->tk = new Actor( 900, $this->id['person_tk'], true );
	}

	protected function registrations(): Registrations {
		return new Registrations( $this->db, $this->policy, $this->clock, new \DateTimeZone( 'Europe/Zurich' ), new AuditLog( $this->db ), $this->events );
	}

	protected function rounds(): Rounds {
		return new Rounds( $this->db, $this->clock, new AuditLog( $this->db ), $this->events );
	}

	protected function entry( ?\TTLeague\Infrastructure\Time\Clock $clock = null ): GroupEntry {
		return new GroupEntry( $this->db, $this->policy, $clock ?? $this->clock, new AuditLog( $this->db ), $this->events );
	}

	/**
	 * @param callable():mixed $work Aktion, die mit diesem HTTP-Status scheitern muss.
	 */
	protected function assert_fails( int $status, callable $work ): \TTLeague\Results\ResultException {
		try {
			$work();
		} catch ( \TTLeague\Results\ResultException $e ) {
			self::assertSame( $status, $e->status, $e->getMessage() );
			return $e;
		}
		self::fail( "Fehler $status erwartet." );
	}

	protected function repo(): Repository {
		return new Repository( $this->db );
	}

	/**
	 * Handelnde Person eines Spielers (Konto 100 + Spieler-ID).
	 */
	protected function actor( string $key ): Actor {
		$player = $this->player[ $key ];
		return new Actor( 100 + $player, $this->seeder->person_of( $player ), false );
	}

	protected function chef(): Actor {
		return new Actor( 901, $this->id['person_chef'], false );
	}

	protected function nobody(): Actor {
		return new Actor( 902, $this->id['person_nobody'], false );
	}

	/**
	 * Meldet Spieler durch die TK an.
	 *
	 * @param list<string> $keys Schlüssel, z. B. s1.
	 */
	protected function register( array $keys ): void {
		$service = $this->registrations();
		foreach ( $keys as $key ) {
			$service->register( $this->tk, $this->player[ $key ] );
		}
	}

	/**
	 * Alle 24 Spieler: je Kategorie acht (Klassierung 18 und 16, 12 und 9, 8 und 3).
	 *
	 * @return list<string>
	 */
	protected function everyone(): array {
		return array_keys( $this->player );
	}

	/**
	 * Legt eine Runde an (Montag bis Donnerstag) und liefert ihre ID.
	 */
	protected function round( string $monday, bool $is_final = false ): int {
		$from = new \DateTimeImmutable( $monday );
		return $this->rounds()->create( $this->tk, $this->id['competition'], $from->format( 'Y-m-d' ), $from->modify( '+3 days' )->format( 'Y-m-d' ), $is_final );
	}

	/**
	 * Gruppenzettel, in dem alle gespielt haben und immer die Person mit der kleineren Position 3:0 gewinnt.
	 *
	 * @param array<int,string> $statuses Abweichende Status nach Spieler.
	 * @return array<string,mixed>
	 */
	protected function sheet( int $group_id, array $statuses = array() ): array {
		$group   = $this->repo()->group( $group_id );
		$players = array();
		foreach ( $group['members'] as $member ) {
			$players[ $member['player_id'] ] = $statuses[ $member['player_id'] ] ?? 'played';
		}
		$games = array();
		foreach ( \TTLeague\RankingTournament\Sheet::order( array_keys( $players ) ) as $game ) {
			if ( 'played' === $players[ $game['a'] ] && 'played' === $players[ $game['b'] ] ) {
				$games[] = array(
					'sequence' => $game['sequence'],
					'sets_a'   => 3,
					'sets_b'   => 0,
				);
			}
		}
		return array(
			'players' => $players,
			'games'   => $games,
		);
	}

	/**
	 * Einträge des Protokolls zu einer Entität, älteste zuerst.
	 *
	 * @return list<array<string,mixed>>
	 */
	protected function audit( string $entity, int $entity_id ): array {
		$rows = array();
		foreach ( $this->db->get_results( "SELECT action, user_id, before_json, after_json, note FROM {$this->db->table( 'audit_log' )} WHERE entity = %s AND entity_id = %d ORDER BY id", array( $entity, $entity_id ) ) as $row ) {
			$rows[] = array(
				'action' => $row['action'],
				'user'   => null === $row['user_id'] ? null : (int) $row['user_id'],
				'before' => null === $row['before_json'] ? null : json_decode( $row['before_json'], true ),
				'after'  => null === $row['after_json'] ? null : json_decode( $row['after_json'], true ),
				'note'   => $row['note'],
			);
		}
		return $rows;
	}
}
