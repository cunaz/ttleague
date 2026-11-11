<?php
/**
 * Gemeinsame Daten der WordPress-Tests des Saison-Assistenten.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Tests\WordPress\Season;

use TTLeague\Results\Actor;
use TTLeague\Season\SeasonAdmin;
use TTLeague\Tests\Integration\Frontend\Seeder;
use TTLeague\Tests\Integration\Season\SeasonLeague;
use TTLeague\Tests\WordPress\TestCase;

/**
 * Liga siehe SeasonLeague: aktive Saison 2026/27 mit vier Clubs, zwei Gruppen und sechs
 * Mannschaften; alle Personen haben eine E-Mail-Adresse.
 *
 * Konten: tk (Rolle ttl_tk, ohne Person), chef (Charlotte Chef, TK-Chefin von Seefeld).
 */
abstract class SeasonWpTestCase extends TestCase {

	protected Seeder $seed;

	/** @var array<string,int> IDs der Liga, nach plan() auch «next» und «next_<team>». */
	protected array $id = array();

	/** @var array<string,int> Konten. */
	protected array $user = array();

	public function set_up(): void {
		parent::set_up();
		$this->seed = new Seeder( $this->db );
		$this->id   = ( new SeasonLeague( $this->db ) )->league( $this->tables() );
		$this->db->query( "UPDATE {$this->db->table( 'person' )} SET email = CONCAT( 'person', id, '@example.invalid' )" );
		$this->user = array(
			'tk'   => $this->account( 'ttl_tk', 'tk@example.invalid' ),
			'chef' => $this->account( 'subscriber', 'person' . $this->id['chef_seefeld'] . '@example.invalid', $this->id['chef_seefeld'] ),
		);
		wp_set_current_user( $this->user['tk'] );
	}

	public function tear_down(): void {
		$_POST    = array();
		$_GET     = array();
		$_REQUEST = array();
		parent::tear_down();
	}

	/**
	 * Tische pro Lokal der Liga.
	 */
	protected function tables(): ?int {
		return 4;
	}

	protected function tk(): Actor {
		return new Actor( $this->user['tk'], null, true, 'TK' );
	}

	/**
	 * Geplante Saison 2027/28 mit den gemeldeten Mannschaften und geprüften Meldungen.
	 */
	protected function plan(): int {
		$next     = SeasonAdmin::setup()->create( $this->tk() );
		$this->id = ( new SeasonLeague( $this->db ) )->register( $next, $this->id ) + $this->id;
		return $next;
	}

	/**
	 * Geplante Saison mit Einteilung aus dem Vorjahr und Spielwochen.
	 */
	protected function with_weeks(): int {
		$next = $this->plan();
		SeasonAdmin::grouping()->apply_proposal( $this->tk(), $next );
		foreach ( SeasonAdmin::grouping()->groups( $next ) as $group ) {
			$this->id[ 'next_group_' . strtolower( (string) $group['category'] ) ] = $group['id'];
		}
		SeasonAdmin::scheduling()->save_weeks(
			$this->tk(),
			$next,
			array( '2027-09-06', '2027-09-13', '2027-09-20', '2027-09-27' ),
			array( '2028-01-10', '2028-01-17', '2028-01-24', '2028-01-31' )
		);
		return $next;
	}

	/**
	 * Geplante Saison, die sich freigeben lässt (bei vier Tischen ohne harte Kollision); die
	 * aktive Saison ist klassiert.
	 */
	protected function ready(): int {
		$next = $this->with_weeks();
		SeasonAdmin::scheduling()->generate( $this->tk(), $next );
		$this->db->update( 'season', array( 'classification_closed_at' => '2027-06-20 18:00:00' ), array( 'id' => $this->id['active'] ) );
		return $next;
	}

	/**
	 * Formular an admin-post.php absenden; liefert das Ziel der Weiterleitung.
	 *
	 * @param array<string,mixed> $fields   Felder (ohne Nonce).
	 * @param int                 $nonce_id Datensatz des Nonce.
	 */
	protected function post( string $action, array $fields, int $nonce_id ): string {
		$this->fill( $action, $fields, wp_create_nonce( $action . '_' . $nonce_id ) );
		return $this->redirect_of( static fn() => do_action( 'admin_post_' . $action ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Aktion von WordPress (admin-post.php).
	}

	/**
	 * Formular absenden, das mit wp_die() enden muss.
	 *
	 * @param array<string,mixed> $fields Felder.
	 */
	protected function denied( string $action, array $fields, string $nonce ): \WPDieException {
		$this->fill( $action, $fields, $nonce );
		try {
			do_action( 'admin_post_' . $action ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Aktion von WordPress (admin-post.php).
		} catch ( \WPDieException $e ) {
			return $e;
		}
		self::fail( 'wp_die() erwartet.' );
	}

	protected function season_status( int $season_id ): string {
		return (string) $this->db->get_var( "SELECT status FROM {$this->db->table( 'season' )} WHERE id = %d", array( $season_id ) );
	}

	protected function rows( string $table, string $where = '1 = 1' ): int {
		return (int) $this->db->get_var( "SELECT COUNT(*) FROM {$this->db->table( $table )} WHERE {$where}" );
	}

	/**
	 * Begegnung der geplanten Saison nach Heim und Gast.
	 */
	protected function fixture( string $home, string $away ): int {
		return (int) $this->db->get_var(
			"SELECT id FROM {$this->db->table( 'fixture' )} WHERE home_team_id = %d AND away_team_id = %d",
			array( $this->id[ 'next_' . $home ], $this->id[ 'next_' . $away ] )
		);
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
}
