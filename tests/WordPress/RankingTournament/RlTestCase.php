<?php
/**
 * Gemeinsame Daten der WordPress-Tests des Ranglistenturniers.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Tests\WordPress\RankingTournament;

use TTLeague\RankingTournament\RankingTournament;
use TTLeague\Results\Actor;
use TTLeague\Tests\Integration\Frontend\Seeder;
use TTLeague\Tests\WordPress\League;
use TTLeague\Tests\WordPress\TestCase;

/**
 * Liga siehe League (Seefeld mit h1 bis h3, Wollishofen mit a1 bis a3, Klassierungen 10, 9, 8;
 * Charlotte Chef ist TK-Chefin von Seefeld, Nina Niemand ohne Funktion). Dazu ein
 * Ranglistenturnier mit Kategorie 1 ab Klassierung 9 und Kategorie 2 ab 1, Anmeldeschluss in
 * zehn Tagen, je ein Lokal (Seefeld Montag, Wollishofen Dienstag) und Konten für alle
 * Spielerinnen und Spieler, die TK-Chefin, Nina Niemand und die TK.
 */
abstract class RlTestCase extends TestCase {

	protected League $league;

	/** @var array<string,int> Konto nach Schlüssel: h1 bis h3, a1 bis a3, chef, nobody, tk. */
	protected array $users = array();

	protected int $competition = 0;

	/** @var array<string,int> */
	protected array $venue = array();

	public function set_up(): void {
		parent::set_up();
		$this->league = new League( $this->db, wp_date( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS ) );
		$seeder       = new Seeder( $this->db );
		$this->users  = array();
		foreach ( array( 'h1', 'h2', 'h3', 'a1', 'a2', 'a3' ) as $key ) {
			$person = $seeder->person_of( $this->league->id[ $key ] );
			$this->db->update( 'person', array( 'email' => $key . '@example.invalid' ), array( 'id' => $person ) );
			$this->users[ $key ] = $this->account( 'subscriber', $key . '@example.invalid', $person );
		}
		$this->users['chef']   = $this->account( 'subscriber', 'chef@example.invalid', $this->league->id['chef_home'] );
		$this->users['nobody'] = $this->account( 'subscriber', 'niemand@example.invalid', $this->league->id['nobody'] );
		$this->users['tk']     = $this->account( 'ttl_tk', 'tk@example.invalid' );
		$this->venue           = array(
			'home' => $seeder->venue( $this->league->id['club_home'], 'Halle Seefeld', array( 'weekday' => 1 ) ),
			'away' => $seeder->venue( $this->league->id['club_away'], 'Halle Wollishofen', array( 'weekday' => 2 ) ),
		);
		$this->competition     = $this->db->insert(
			'competition',
			array(
				'season_id'   => $this->league->id['season'],
				'type'        => 'RL',
				'name'        => 'Ranglistenturnier',
				'format_json' => (string) wp_json_encode(
					array(
						'categories'            => array(
							array(
								'no'  => 1,
								'min' => 9,
							),
							array(
								'no'  => 2,
								'min' => 1,
							),
						),
						'registration_deadline' => wp_date( 'Y-m-d', time() + 10 * DAY_IN_SECONDS ),
					)
				),
			)
		);
	}

	protected function tk(): Actor {
		return new Actor( $this->users['tk'], null, true );
	}

	/**
	 * Montag der übernächsten Woche (Ortszeit) als J-M-T.
	 */
	protected function monday(): string {
		return ( new \DateTimeImmutable( 'monday next week', wp_timezone() ) )->modify( '+7 days' )->format( 'Y-m-d' );
	}

	/**
	 * Legt eine Runde in Planung an (Montag bis Donnerstag).
	 */
	protected function planned_round(): int {
		$monday = new \DateTimeImmutable( $this->monday() );
		return RankingTournament::rounds()->create( $this->tk(), $this->competition, $monday->format( 'Y-m-d' ), $monday->modify( '+3 days' )->format( 'Y-m-d' ) );
	}

	/**
	 * Meldet Spieler durch die TK an.
	 *
	 * @param list<string> $keys Schlüssel wie h1.
	 */
	protected function register( array $keys ): void {
		foreach ( $keys as $key ) {
			RankingTournament::registrations()->register( $this->tk(), $this->league->id[ $key ] );
		}
	}

	/**
	 * Freigegebene Runde mit einer Gruppe der Kategorie 1: h1, h2, a1, a2 bei Wollishofen am
	 * Dienstag, Gruppenchef h1 (Seefeld).
	 *
	 * @return array{0:int,1:int} Runde und Gruppe.
	 */
	protected function published_group(): array {
		$this->register( array( 'h1', 'h2', 'a1', 'a2' ) );
		$round = $this->planned_round();
		$group = RankingTournament::rounds()->save_group(
			$this->tk(),
			$round,
			null,
			array(
				'category'        => 1,
				'venue_id'        => $this->venue['away'],
				'start_at'        => ( new \DateTimeImmutable( $this->monday() ) )->modify( '+1 day' )->format( 'Y-m-d' ) . ' 19:30',
				'members'         => array( $this->league->id['h1'], $this->league->id['h2'], $this->league->id['a1'], $this->league->id['a2'] ),
				'chief_player_id' => $this->league->id['h1'],
			)
		);
		RankingTournament::rounds()->publish( $this->tk(), $round );
		$this->mails = array();
		return array( $round, $group );
	}

	/**
	 * Gruppenzettel: alle haben gespielt, die Person mit der kleineren Position gewinnt 3:0.
	 *
	 * @return array<string,mixed>
	 */
	protected function sheet( int $group_id ): array {
		$group   = RankingTournament::repository()->group( $group_id );
		$players = array();
		foreach ( $group['members'] as $member ) {
			$players[ $member['player_id'] ] = 'played';
		}
		$games = array();
		foreach ( \TTLeague\RankingTournament\Sheet::order( array_keys( $players ) ) as $game ) {
			$games[] = array(
				'sequence' => $game['sequence'],
				'sets_a'   => 3,
				'sets_b'   => 0,
			);
		}
		return array(
			'players' => $players,
			'games'   => $games,
		);
	}

	/**
	 * Spielt WP-Cron für die wartenden Schritte des Versands der Aufgebote: Jedes Ereignis wird
	 * wie im echten Lauf zuerst ausgetragen und dann ausgeführt. Neu eingeplante Schritte
	 * (Fortsetzung) bleiben für den nächsten Aufruf stehen.
	 *
	 * @return list<array<int,mixed>> Argumente der ausgeführten Ereignisse.
	 */
	protected function run_callup_events(): array {
		$run = array();
		foreach ( (array) _get_cron_array() as $timestamp => $hooks ) {
			foreach ( $hooks[ RankingTournament::CALLUP_HOOK ] ?? array() as $event ) {
				wp_unschedule_event( (int) $timestamp, RankingTournament::CALLUP_HOOK, $event['args'] );
				$run[] = $event['args'];
			}
		}
		self::assertSame( 'ttleague_rl_callups', RankingTournament::CALLUP_HOOK );
		foreach ( $run as $args ) {
			do_action_ref_array( 'ttleague_rl_callups', $args );
		}
		return $run;
	}

	/**
	 * Argumente der wartenden Schritte des Versands der Aufgebote.
	 *
	 * @return list<array<int,mixed>>
	 */
	protected function waiting_callup_events(): array {
		$waiting = array();
		foreach ( (array) _get_cron_array() as $hooks ) {
			foreach ( $hooks[ RankingTournament::CALLUP_HOOK ] ?? array() as $event ) {
				$waiting[] = $event['args'];
			}
		}
		return $waiting;
	}

	/**
	 * Formulardaten mit gültigem Nonce, wie WordPress sie liefert.
	 *
	 * @param array<string,mixed> $fields Felder.
	 */
	protected function post( string $nonce_action, array $fields ): void {
		$_POST    = wp_slash( $fields + array( '_wpnonce' => wp_create_nonce( $nonce_action ) ) );
		$_REQUEST = $_POST; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Der Test setzt die Formulardaten, der Nonce ist enthalten.
	}

	/**
	 * @param callable():mixed $flow Ablauf, der mit wp_die() enden muss.
	 */
	protected function die_of( callable $flow ): \WPDieException {
		try {
			$flow();
		} catch ( \WPDieException $e ) {
			return $e;
		}
		self::fail( 'wp_die() erwartet.' );
	}

	public function tear_down(): void {
		$_POST    = array();
		$_GET     = array();
		$_REQUEST = array();
		parent::tear_down();
	}
}
