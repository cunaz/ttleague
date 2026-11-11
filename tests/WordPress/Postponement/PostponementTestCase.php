<?php
/**
 * Gemeinsame Daten der WordPress-Tests zur Verschiebung.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Tests\WordPress\Postponement;

use TTLeague\ReadModel\ReadModel;
use TTLeague\Tests\WordPress\League;
use TTLeague\Tests\WordPress\TestCase;

/**
 * Liga siehe League. Die Tests laufen mit der echten Uhr: Die Begegnung f1 ist in fünf Tagen um
 * 20:00 angesetzt, und die aktive Saison reicht von vor 60 Tagen bis in 300 Tagen, damit neue
 * Termine unabhängig vom Tag des Testlaufs in der Zukunft und in der Saison liegen.
 *
 * Konten: Hanna Heim (Captain Seefeld 1, Heim), Gustav Gast (Captain Wollishofen 1, Gast),
 * Charlotte Chef (TK-Chefin Seefeld), Nina Niemand (ohne Funktion) und ein Konto der TK.
 */
abstract class PostponementTestCase extends TestCase {

	protected League $league;

	/** @var array<string,int> */
	protected array $users = array();

	/** Bisheriger Termin von f1 (Ortszeit der Website). */
	protected string $scheduled;

	public function set_up(): void {
		parent::set_up();
		ReadModel::set_instance( null );
		$this->scheduled = $this->day( 5 );
		$this->league    = new League( $this->db, $this->scheduled );
		$this->db->update(
			'season',
			array(
				'start_date' => wp_date( 'Y-m-d', time() - 60 * DAY_IN_SECONDS ),
				'end_date'   => wp_date( 'Y-m-d', time() + 300 * DAY_IN_SECONDS ),
			),
			array( 'id' => $this->league->id['season'] )
		);
		$this->users = array();
		foreach ( array( 'captain_h1', 'captain_a1', 'chef_home', 'nobody' ) as $person ) {
			$email = str_replace( '_', '.', $person ) . '@example.invalid';
			$this->db->update( 'person', array( 'email' => $email ), array( 'id' => $this->league->id[ $person ] ) );
			$this->users[ $person ] = $this->account( 'subscriber', $email, $this->league->id[ $person ] );
		}
		$this->users['tk'] = $this->account( 'ttl_tk', 'tk@example.invalid' );
	}

	public function tear_down(): void {
		ReadModel::set_instance( null );
		$_GET     = array();
		$_POST    = array();
		$_REQUEST = array();
		parent::tear_down();
	}

	/**
	 * Termin in so vielen Tagen um 20:00 Ortszeit, «Y-m-d H:i:s».
	 */
	protected function day( int $days ): string {
		return wp_date( 'Y-m-d', time() + $days * DAY_IN_SECONDS ) . ' 20:00:00';
	}

	/**
	 * Antrag über den Dienst, als das angegebene Konto.
	 *
	 * @return array<string,mixed>
	 */
	protected function request_as( string $user, int $days = 8, string $fixture = 'f1' ): array {
		wp_set_current_user( $this->users[ $user ] );
		return \TTLeague\Postponement\Postponements::service()->request(
			$this->league->id[ $fixture ],
			\TTLeague\Results\Results::current_actor(),
			array(
				'new_date' => $this->day( $days ),
				'reason'   => 'Die Halle ist an diesem Abend belegt.',
			)
		);
	}

	/**
	 * @return array<string,string|null>
	 */
	protected function fixture( string $key = 'f1' ): array {
		return (array) $this->db->get_row( "SELECT status, scheduled_at FROM {$this->db->table( 'fixture' )} WHERE id = %d", array( $this->league->id[ $key ] ) );
	}

	/**
	 * @return array<string,string|null>
	 */
	protected function postponement( int $id ): array {
		return (array) $this->db->get_row( "SELECT * FROM {$this->db->table( 'postponement' )} WHERE id = %d", array( $id ) );
	}

	protected function count_postponements(): int {
		return (int) $this->db->get_var( "SELECT COUNT(*) FROM {$this->db->table( 'postponement' )}" );
	}
}
