<?php
/**
 * Gemeinsame Daten der WordPress-Tests der Meldung.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Tests\WordPress\Registration;

use TTLeague\Tests\Integration\Frontend\Seeder;
use TTLeague\Tests\WordPress\League;
use TTLeague\Tests\WordPress\TestCase;

/**
 * Liga siehe League (aktive Saison 2026/27, Seefeld mit TK-Chefin Charlotte Chef und den
 * Spielern h1 bis h3, Wollishofen mit a1 bis a3). Dazu die geplante Saison 2027/28 mit offenem
 * Meldefenster (vor fünf Tagen bis in zehn Tagen), Meldungen beider Clubs, Charlotte Chef als
 * vorgetragene TK-Chefin, Wanda Wolli als TK-Chefin von Wollishofen und einem Lokal von Seefeld.
 * Nachmeldungen der aktiven Saison sind bis in dreissig Tagen möglich.
 *
 * Konten: chef (Charlotte Chef), wolli (Wanda Wolli), nobody (Nina Niemand), tk (Rolle ttl_tk).
 */
abstract class RegistrationWpTestCase extends TestCase {

	protected League $league;

	protected Seeder $seed;

	/** @var array<string,int> IDs: next (geplante Saison), venue, person_wolli und die der League. */
	protected array $id = array();

	/** @var array<string,int> Konten. */
	protected array $user = array();

	public function set_up(): void {
		parent::set_up();
		$this->league = new League( $this->db, '2026-11-17 20:00:00' );
		$this->seed   = new Seeder( $this->db );
		$next         = $this->seed->season( '2027-28', 'planned' );
		$this->db->update(
			'season',
			array(
				'registration_opens'  => self::day( '-5 days' ),
				'registration_closes' => self::day( '+10 days' ),
			),
			array( 'id' => $next )
		);
		$this->db->update( 'season', array( 'late_registration_until' => self::day( '+30 days' ) ), array( 'id' => $this->league->id['season'] ) );
		$wolli = $this->seed->person( 'Wolli', 'Wanda' );
		$this->seed->role( $next, $this->league->id['club_home'], $this->league->id['chef_home'], 'tk_chef' );
		$this->seed->role( $next, $this->league->id['club_away'], $wolli, 'tk_chef' );
		$this->seed->role( $this->league->id['season'], $this->league->id['club_away'], $wolli, 'tk_chef' );
		foreach ( array( 'club_home', 'club_away' ) as $club ) {
			$this->db->insert(
				'club_season',
				array(
					'season_id' => $next,
					'club_id'   => $this->league->id[ $club ],
					'status'    => 'open',
				)
			);
		}
		$this->db->update( 'person', array( 'email' => 'chef@example.invalid' ), array( 'id' => $this->league->id['chef_home'] ) );
		$this->db->update( 'person', array( 'email' => 'wolli@example.invalid' ), array( 'id' => $wolli ) );
		$this->db->update( 'person', array( 'email' => 'spieler1@example.invalid' ), array( 'id' => $this->league->id['person_h1'] ) );

		$this->id   = $this->league->id + array(
			'next'         => $next,
			'person_wolli' => $wolli,
			'venue'        => $this->seed->venue( $this->league->id['club_home'], 'Turnhalle Seefeld', array( 'tables_count' => 4 ) ),
		);
		$this->user = array(
			'chef'   => $this->account( 'subscriber', 'chef@example.invalid', $this->league->id['chef_home'] ),
			'wolli'  => $this->account( 'subscriber', 'wolli@example.invalid', $wolli ),
			'nobody' => $this->account( 'subscriber', 'niemand@example.invalid', $this->league->id['nobody'] ),
			'tk'     => $this->account( 'ttl_tk', 'tk@example.invalid' ),
		);
	}

	public function tear_down(): void {
		$_POST    = array();
		$_GET     = array();
		$_REQUEST = array();
		parent::tear_down();
	}

	/**
	 * Kalendertag in der Zeitzone der Website, relativ zu heute.
	 */
	protected static function day( string $modifier ): string {
		return ( new \DateTimeImmutable( 'now', wp_timezone() ) )->modify( $modifier )->format( 'Y-m-d' );
	}

	/**
	 * Formular an admin-post.php absenden; liefert das Ziel der Weiterleitung.
	 *
	 * @param array<string,mixed> $fields   Felder (ohne Nonce).
	 * @param int|null            $nonce_id Datensatz des Nonce; ohne Angabe der Club.
	 */
	protected function post( string $action, array $fields, ?int $nonce_id = null ): string {
		$this->fill( $action, $fields, wp_create_nonce( $action . '_' . ( $nonce_id ?? (int) ( $fields['club'] ?? 0 ) ) ) );
		return $this->redirect_of( static fn() => do_action( 'admin_post_' . $action ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Aktion von WordPress (admin-post.php).
	}

	/**
	 * Formular absenden, das mit wp_die() enden muss.
	 *
	 * @param array<string,mixed> $fields Felder.
	 * @param string|null         $nonce  Nonce; ohne Angabe der gültige für Aktion und Club.
	 */
	protected function denied( string $action, array $fields, ?string $nonce = null, ?int $nonce_id = null ): \WPDieException {
		$this->fill( $action, $fields, $nonce ?? wp_create_nonce( $action . '_' . ( $nonce_id ?? (int) ( $fields['club'] ?? 0 ) ) ) );
		try {
			do_action( 'admin_post_' . $action ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Aktion von WordPress (admin-post.php).
		} catch ( \WPDieException $e ) {
			return $e;
		}
		self::fail( 'wp_die() erwartet.' );
	}

	/**
	 * @param array<string,mixed> $fields Felder.
	 */
	private function fill( string $action, array $fields, string $nonce ): void {
		$_POST    = wp_slash(
			$fields + array(
				'action'   => $action,
				'_wpnonce' => $nonce,
			)
		);
		$_REQUEST = $_POST; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Der Test setzt die Formulardaten, der Nonce ist enthalten.
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
	 * Felder, die jedes Formular eines Clubs mitschickt.
	 *
	 * @param array<string,mixed> $more Weitere Felder.
	 * @return array<string,mixed>
	 */
	protected function home( array $more = array(), ?int $season = null ): array {
		return $more + array(
			'club'   => (string) $this->id['club_home'],
			'season' => (string) ( $season ?? $this->id['next'] ),
		);
	}
}
