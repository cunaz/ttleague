<?php
/**
 * Personen und ihre WordPress-Konten.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Accounts;

use TTLeague\Infrastructure\Database\Db;

final class AccountService {

	public function __construct( private readonly Db $db ) {}

	/**
	 * @return array<string,string|null>|null
	 */
	public function person( int $person_id ): ?array {
		return $this->db->get_row( "SELECT * FROM {$this->db->table( 'person' )} WHERE id = %d", array( $person_id ) );
	}

	/**
	 * Anrede: Vorname, sonst Nachname.
	 *
	 * @param array<string,string|null> $person Person.
	 */
	public static function salutation( array $person ): string {
		$first = trim( (string) ( $person['first_name'] ?? '' ) );
		return '' !== $first ? $first : trim( (string) ( $person['last_name'] ?? '' ) );
	}

	/**
	 * Person mit dieser E-Mail-Adresse (Gross-/Kleinschreibung egal); mit Konto bevorzugt.
	 *
	 * @return array<string,string|null>|null
	 */
	public function find_person_by_email( string $email ): ?array {
		$email = trim( $email );
		if ( '' === $email ) {
			return null;
		}
		return $this->db->get_row(
			"SELECT * FROM {$this->db->table( 'person' )} WHERE LOWER(email) = LOWER(%s) AND status = 'active' ORDER BY user_id IS NULL, id LIMIT 1",
			array( $email )
		);
	}

	public function set_consent( int $person_id ): void {
		$now = $this->db->now();
		$this->db->update(
			'person',
			array(
				'consent_at' => $now,
				'updated_at' => $now,
			),
			array( 'id' => $person_id )
		);
	}

	public function update_contact( int $person_id, ?string $phone, bool $phone_visible ): void {
		$phone = null === $phone ? null : trim( (string) preg_replace( '/[^0-9+ ()\/.-]/', '', $phone ) );
		$this->db->update(
			'person',
			array(
				'phone'         => '' === $phone ? null : $phone,
				'phone_visible' => $phone_visible ? 1 : 0,
				'updated_at'    => $this->db->now(),
			),
			array( 'id' => $person_id )
		);
	}

	public function set_email( int $person_id, string $email ): void {
		$this->db->update(
			'person',
			array(
				'email'      => '' === trim( $email ) ? null : strtolower( trim( $email ) ),
				'updated_at' => $this->db->now(),
			),
			array( 'id' => $person_id )
		);
	}

	public function link_user( int $person_id, int $user_id ): void {
		$this->db->update(
			'person',
			array(
				'user_id'    => $user_id,
				'updated_at' => $this->db->now(),
			),
			array( 'id' => $person_id )
		);
	}

	/**
	 * Funktionen einer Person in der aktiven Saison als lesbare Texte.
	 *
	 * @return list<string>
	 */
	public function function_labels( int $person_id ): array {
		$rows   = $this->db->get_results(
			"SELECT cr.role, c.name AS club, t.name AS team FROM {$this->db->table( 'club_role' )} cr
			JOIN {$this->db->table( 'season' )} s ON s.id = cr.season_id AND s.status = 'active'
			JOIN {$this->db->table( 'club' )} c ON c.id = cr.club_id
			LEFT JOIN {$this->db->table( 'team' )} t ON t.id = cr.team_id
			WHERE cr.person_id = %d ORDER BY cr.role DESC, t.name",
			array( $person_id )
		);
		$labels = array();
		foreach ( $rows as $row ) {
			$labels[] = 'tk_chef' === $row['role']
				/* translators: %s: Clubname */
				? sprintf( __( 'TK-Chef %s', 'ttleague' ), (string) $row['club'] )
				/* translators: %s: Teamname */
				: sprintf( __( 'Captain %s', 'ttleague' ), (string) $row['team'] );
		}
		return $labels;
	}

	/**
	 * Legt bei Bedarf das WordPress-Konto einer Person an und verknüpft es.
	 *
	 * @throws \RuntimeException Wenn kein Konto angelegt werden kann.
	 */
	public function ensure_user( int $person_id ): int {
		$person = $this->person( $person_id );
		if ( null === $person ) {
			throw new \RuntimeException( 'Person nicht gefunden.' );
		}
		if ( ! empty( $person['user_id'] ) && false !== get_userdata( (int) $person['user_id'] ) ) {
			return (int) $person['user_id'];
		}
		$email = (string) ( $person['email'] ?? '' );
		if ( '' === $email || ! is_email( $email ) ) {
			throw new \RuntimeException( 'Für diese Person ist keine gültige E-Mail-Adresse hinterlegt.' );
		}
		$existing = get_user_by( 'email', $email );
		if ( false !== $existing ) {
			$this->link_user( $person_id, (int) $existing->ID );
			return (int) $existing->ID;
		}
		$user_id = wp_insert_user(
			array(
				'user_login'   => 'ttl-' . $person_id,
				'user_email'   => $email,
				'user_pass'    => wp_generate_password( 32, true, true ),
				'first_name'   => (string) $person['first_name'],
				'last_name'    => (string) $person['last_name'],
				'display_name' => trim( $person['first_name'] . ' ' . $person['last_name'] ),
				'role'         => 'subscriber',
			)
		);
		if ( is_wp_error( $user_id ) ) {
			throw new \RuntimeException( esc_html( $user_id->get_error_message() ) );
		}
		$this->link_user( $person_id, (int) $user_id );
		return (int) $user_id;
	}
}
