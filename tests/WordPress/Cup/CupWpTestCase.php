<?php
/**
 * Gemeinsame Daten der WordPress-Tests zum Mannschaftscup.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Tests\WordPress\Cup;

use TTLeague\Cup\Cup;
use TTLeague\ReadModel\ReadModel;
use TTLeague\Results\Results;
use TTLeague\Tests\Integration\Frontend\Seeder;
use TTLeague\Tests\WordPress\TestCase;

/**
 * Aktive Saison von vor 60 Tagen bis in 300 Tagen (die Tests laufen mit der echten Uhr) mit den
 * Clubs Seefeld, Wollishofen, Oerlikon und Altstetten. Jeder Club hat vier gemeldete Spieler
 * (Klassierungen 12 minus Rang des Clubs, 6, 5 und 3) und eine TK-Chefin oder einen TK-Chef mit
 * Konto («chef_seefeld» usw.). Dazu ein Konto der TK («tk») und eines ohne Funktion («nobody»).
 *
 * Konkurrenz «Mannschaftscup A» mit Punktelimite 18 (Seefeld 1 mit 12 + 6 liegt genau darauf)
 * und Meldeschluss in zehn Tagen.
 */
abstract class CupWpTestCase extends TestCase {

	protected const CLUBS = array( 'Seefeld', 'Wollishofen', 'Oerlikon', 'Altstetten' );

	protected Seeder $seeder;

	protected int $season;

	protected int $cup;

	/** @var array<string,int> */
	protected array $club = array();

	/** @var array<string,array<int,int>> Club => Nummer 1 bis 4 => Spieler. */
	protected array $player = array();

	/** @var array<string,int> Konten: chef_<club>, tk, nobody. */
	protected array $users = array();

	public function set_up(): void {
		parent::set_up();
		ReadModel::set_instance( null );
		Cup::reset_request();
		$this->seeder = new Seeder( $this->db );
		$this->season = $this->seeder->season( '2026-27' );
		$this->db->update(
			'season',
			array(
				'start_date' => wp_date( 'Y-m-d', time() - 60 * DAY_IN_SECONDS ),
				'end_date'   => wp_date( 'Y-m-d', time() + 300 * DAY_IN_SECONDS ),
			),
			array( 'id' => $this->season )
		);
		foreach ( self::CLUBS as $rank => $name ) {
			$key                = strtolower( $name );
			$club               = $this->seeder->club( $name, 901 + $rank );
			$this->club[ $key ] = $club;
			foreach ( array( 12 - $rank, 6, 5, 3 ) as $i => $classification ) {
				$player                         = $this->seeder->player( 'Cupspieler', $name . ( $i + 1 ) );
				$this->player[ $key ][ $i + 1 ] = $player;
				$this->seeder->register( $player, $this->season, $club, $classification );
				$this->db->update( 'person', array( 'email' => 'spieler.' . $key . ( $i + 1 ) . '@example.invalid' ), array( 'id' => $this->seeder->person_of( $player ) ) );
			}
			$email  = 'chef.' . $key . '@example.invalid';
			$person = $this->seeder->person( 'Clubleitung', $name );
			$this->db->update( 'person', array( 'email' => $email ), array( 'id' => $person ) );
			$this->seeder->role( $this->season, $club, $person, 'tk_chef' );
			$this->users[ 'chef_' . $key ] = $this->account( 'subscriber', $email, $person );
		}
		$this->cup = $this->seeder->competition( $this->season, 'CUP', 'Mannschaftscup A', 'A', null, 1 );
		$this->db->update(
			'competition',
			array(
				'format_json' => (string) wp_json_encode(
					array(
						'points_limit'          => 18,
						'registration_deadline' => $this->date( 10 ),
					)
				),
			),
			array( 'id' => $this->cup )
		);
		$nobody = $this->seeder->person( 'Niemand', 'Nina' );
		$this->db->update( 'person', array( 'email' => 'nobody@example.invalid' ), array( 'id' => $nobody ) );
		$this->users['nobody'] = $this->account( 'subscriber', 'nobody@example.invalid', $nobody );
		$this->users['tk']     = $this->account( 'ttl_tk', 'tk@example.invalid' );
	}

	public function tear_down(): void {
		ReadModel::set_instance( null );
		Cup::reset_request();
		wp_unschedule_hook( Cup::ROUND_HOOK );
		$_GET     = array();
		$_POST    = array();
		$_REQUEST = array();
		parent::tear_down();
	}

	/**
	 * Datum in so vielen Tagen (Ortszeit der Website), «Y-m-d».
	 */
	protected function date( int $days ): string {
		return wp_date( 'Y-m-d', time() + $days * DAY_IN_SECONDS );
	}

	/**
	 * Die TK meldet Mannschaften: zuerst die ersten Mannschaften der Clubs (Spieler 1 und 2), ab
	 * der fünften die zweiten (Spieler 3 und 4).
	 *
	 * @return list<int> Meldungen in der Reihenfolge Seefeld 1, Wollishofen 1, Oerlikon 1,
	 *                   Altstetten 1, Seefeld 2, Wollishofen 2, Oerlikon 2, Altstetten 2.
	 */
	protected function teams( int $count ): array {
		wp_set_current_user( $this->users['tk'] );
		$ids = array();
		for ( $i = 0; $i < $count; $i++ ) {
			$key    = strtolower( self::CLUBS[ $i % 4 ] );
			$second = $i >= 4;
			$ids[]  = Cup::entries()->register( $this->cup, $this->club[ $key ], Results::current_actor(), $this->player[ $key ][ $second ? 3 : 1 ], $this->player[ $key ][ $second ? 4 : 2 ] )['id'];
		}
		return $ids;
	}

	/**
	 * Acht Mannschaften, das Tableau in der Reihenfolge der Meldungen freigegeben: Seefeld 1 –
	 * Wollishofen 1, Oerlikon 1 – Altstetten 1, Seefeld 2 – Wollishofen 2, Oerlikon 2 –
	 * Altstetten 2. Frist der ersten Runde in 20 Tagen.
	 *
	 * @return list<int> Meldungen.
	 */
	protected function released(): array {
		$ids   = $this->teams( 8 );
		$actor = Results::current_actor();
		Cup::draw()->propose( $this->cup, $actor, 1 );
		Cup::draw()->change( $this->cup, $actor, $ids );
		Cup::draw()->release( $this->cup, $actor, $this->date( 20 ) );
		return $ids;
	}

	/**
	 * @return array<string,mixed>
	 */
	protected function tie( int $round, int $slot ): array {
		foreach ( Cup::repository()->ties( $this->cup ) as $tie ) {
			if ( $tie['round'] === $round && $tie['slot'] === $slot ) {
				return $tie;
			}
		}
		self::fail( 'Paarung ' . $round . '/' . $slot . ' fehlt.' );
	}

	/**
	 * Die TK erfasst und bestätigt eine Begegnung in der gemeldeten Aufstellung über die
	 * Erfassung von WordPress (mit ihren Aktionen).
	 */
	protected function play( int $fixture_id, string $winner ): void {
		wp_set_current_user( $this->users['tk'] );
		$row   = (array) $this->db->get_row( "SELECT home_entry_id, away_entry_id FROM {$this->db->table( 'fixture' )} WHERE id = %d", array( $fixture_id ) );
		$home  = (array) Cup::repository()->entry( (int) $row['home_entry_id'] );
		$away  = (array) Cup::repository()->entry( (int) $row['away_entry_id'] );
		$games = array();
		foreach ( array( 1, 2, 3 ) as $sequence ) {
			$games[] = array(
				'sequence'  => $sequence,
				'status'    => 'played',
				'sets_home' => 'home' === $winner ? 3 : 0,
				'sets_away' => 'home' === $winner ? 0 : 3,
			);
		}
		$service = Results::service();
		$service->submit(
			$fixture_id,
			array(
				'lineup' => array(
					'home' => array(
						'A' => $home['players'][0]['player_id'],
						'B' => $home['players'][1]['player_id'],
					),
					'away' => array(
						'Y' => $away['players'][0]['player_id'],
						'Z' => $away['players'][1]['player_id'],
					),
				),
				'games'  => $games,
			),
			Results::current_actor()
		);
		$service->decide( $fixture_id, Results::current_actor(), array( 'action' => 'confirm' ) );
	}

	/**
	 * Formulardaten mit gültigem Nonce.
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
}
