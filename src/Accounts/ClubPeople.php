<?php
/**
 * Personen eines Clubs in einer Saison mit Funktion, E-Mail-Adresse und Kontostatus.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Accounts;

use TTLeague\Infrastructure\Database\Db;
use TTLeague\Infrastructure\Time\Clock;
use TTLeague\ReadModel\Support\Slug;
use TTLeague\ReadModel\Support\Sql;

/**
 * Grundlage der Listen «Konten und Einladungen» (TK) und «Mein Club» (TK-Chef): gemeldete
 * Spieler (player_season) und Funktionsträger (club_role) eines Clubs. Der Kontostatus ist
 * einer von:
 *
 * - no_email: keine gültige E-Mail-Adresse hinterlegt
 * - no_account: E-Mail-Adresse, aber kein Konto
 * - invited: offene Einladung (Einladungstoken weder verbraucht noch abgelaufen)
 * - account: Konto ohne bekannte Anmeldung und ohne offene Einladung
 * - active: Konto mit letzter Anmeldung (user_meta ttleague_last_login); für Anmeldungen vor
 *   diesem Zeitstempel genügt die Einwilligung (person.consent_at), die bei der ersten
 *   Anmeldung erteilt wird
 *
 * Für eine Liste braucht es vier Abfragen und einen Aufruf von UserDirectory::lookup().
 */
final class ClubPeople {

	public const NO_EMAIL   = 'no_email';
	public const NO_ACCOUNT = 'no_account';
	public const INVITED    = 'invited';
	public const ACCOUNT    = 'account';
	public const ACTIVE     = 'active';

	/** Tokens mit längerer Gültigkeit als ein Anmeldelink sind Einladungen. */
	private const INVITATION_MIN_TTL = MagicLinks::LOGIN_TTL;

	public function __construct(
		private readonly Db $db,
		private readonly UserDirectory $users,
		private readonly Clock $clock,
	) {}

	/**
	 * Clubs mit gemeldeten Spielern oder Funktionen in der Saison, nach Name.
	 *
	 * @return list<array{id:int,name:string,short_name:string}>
	 */
	public function clubs( int $season_id ): array {
		$rows = $this->db->get_results(
			"SELECT c.id, c.name, c.short_name FROM {$this->db->table( 'club' )} c
			WHERE c.id IN ( SELECT ps.club_id FROM {$this->db->table( 'player_season' )} ps WHERE ps.season_id = %d )
				OR c.id IN ( SELECT cr.club_id FROM {$this->db->table( 'club_role' )} cr WHERE cr.season_id = %d )
			ORDER BY c.name, c.id",
			array( $season_id, $season_id )
		);
		return array_map(
			static fn( array $row ): array => array(
				'id'         => (int) $row['id'],
				'name'       => (string) $row['name'],
				'short_name' => (string) $row['short_name'],
			),
			$rows
		);
	}

	/**
	 * Name eines Clubs oder null.
	 */
	public function club_name( int $club_id ): ?string {
		return $this->db->get_var( "SELECT name FROM {$this->db->table( 'club' )} WHERE id = %d", array( $club_id ) );
	}

	/**
	 * Gemeldete Spieler und Funktionsträger: zuerst TK-Chefs, dann Captains, dann Spieler, je nach Name.
	 *
	 * @return list<array{person_id:int,first_name:string,last_name:string,name:string,email:string|null,user_id:int|null,consent_at:string|null,functions:list<array{role:string,team:string|null}>,player:bool,classification:int|null,status:string,invited_at:string|null,invited_until:string|null,last_login:int|null,privileged:bool,admin:bool,link_allowed:bool}>
	 */
	public function for_club( int $season_id, int $club_id ): array {
		$people = array();
		foreach ( $this->db->get_results(
			"SELECT pe.id, pe.first_name, pe.last_name, pe.email, pe.user_id, pe.consent_at, ps.classification
			FROM {$this->db->table( 'player_season' )} ps
			JOIN {$this->db->table( 'player' )} p ON p.id = ps.player_id
			JOIN {$this->db->table( 'person' )} pe ON pe.id = p.person_id
			WHERE ps.season_id = %d AND ps.club_id = %d",
			array( $season_id, $club_id )
		) as $row ) {
			$person                     = $people[ (int) $row['id'] ] ?? self::person( $row );
			$person['player']           = true;
			$person['classification']   = null === $row['classification'] ? null : (int) $row['classification'];
			$people[ (int) $row['id'] ] = $person;
		}
		foreach ( $this->db->get_results(
			"SELECT pe.id, pe.first_name, pe.last_name, pe.email, pe.user_id, pe.consent_at, cr.role, t.name AS team
			FROM {$this->db->table( 'club_role' )} cr
			JOIN {$this->db->table( 'person' )} pe ON pe.id = cr.person_id
			LEFT JOIN {$this->db->table( 'team' )} t ON t.id = cr.team_id
			WHERE cr.season_id = %d AND cr.club_id = %d
			ORDER BY cr.role DESC, t.name",
			array( $season_id, $club_id )
		) as $row ) {
			$person                     = $people[ (int) $row['id'] ] ?? self::person( $row );
			$person['functions'][]      = array(
				'role' => (string) $row['role'],
				'team' => $row['team'],
			);
			$people[ (int) $row['id'] ] = $person;
		}
		if ( array() === $people ) {
			return array();
		}

		$invitations = $this->open_invitations( array_keys( $people ) );
		$accounts    = $this->users->lookup( array_values( array_filter( array_column( $people, 'user_id' ) ) ) );
		foreach ( $people as $id => $person ) {
			$account = null === $person['user_id'] ? null : ( $accounts[ $person['user_id'] ] ?? null );
			if ( null === $account ) {
				$person['user_id'] = null;
			}
			$person['invited_at']    = $invitations[ $id ]['created_at'] ?? null;
			$person['invited_until'] = $invitations[ $id ]['expires_at'] ?? null;
			$person['last_login']    = $account['last_login'] ?? null;
			$person['privileged']    = $account['privileged'] ?? false;
			$person['admin']         = $account['admin'] ?? false;
			$person['link_allowed']  = $account['link_allowed'] ?? true;
			$person['status']        = self::status( $person, null !== $account );
			$people[ $id ]           = $person;
		}

		$people = array_values( $people );
		usort( $people, static fn( array $a, array $b ): int => array( self::rank( $a ), self::sort_name( $a ) ) <=> array( self::rank( $b ), self::sort_name( $b ) ) );
		return $people;
	}

	/**
	 * Hat die Person eine gültige E-Mail-Adresse?
	 *
	 * @param array{email:string|null} $person Person.
	 */
	public static function has_email( array $person ): bool {
		$email = trim( (string) ( $person['email'] ?? '' ) );
		return '' !== $email && false !== is_email( $email );
	}

	/**
	 * Captain oder TK-Chef?
	 *
	 * @param array{functions:list<array{role:string,team:string|null}>} $person Person.
	 */
	public static function is_official( array $person ): bool {
		return array() !== $person['functions'];
	}

	/**
	 * Offene Einladungen: jüngste pro Person.
	 *
	 * @param list<int> $person_ids Personen.
	 * @return array<int,array{created_at:string,expires_at:string}>
	 */
	private function open_invitations( array $person_ids ): array {
		$rows = $this->db->get_results(
			"SELECT person_id, MAX(created_at) AS created_at, MAX(expires_at) AS expires_at
			FROM {$this->db->table( 'login_token' )}
			WHERE person_id IN (" . Sql::ints( $person_ids ) . ')
				AND used_at IS NULL AND expires_at > %s AND TIMESTAMPDIFF(SECOND, created_at, expires_at) > %d
			GROUP BY person_id',
			array_merge( $person_ids, array( $this->clock->now()->format( 'Y-m-d H:i:s' ), self::INVITATION_MIN_TTL ) )
		);
		$open = array();
		foreach ( $rows as $row ) {
			$open[ (int) $row['person_id'] ] = array(
				'created_at' => (string) $row['created_at'],
				'expires_at' => (string) $row['expires_at'],
			);
		}
		return $open;
	}

	/**
	 * @param array<string,string|null> $row Zeile mit Personendaten.
	 * @return array<string,mixed>
	 */
	private static function person( array $row ): array {
		$first = (string) $row['first_name'];
		$last  = (string) $row['last_name'];
		$email = trim( (string) $row['email'] );
		return array(
			'person_id'      => (int) $row['id'],
			'first_name'     => $first,
			'last_name'      => $last,
			'name'           => trim( $first . ' ' . $last ),
			'email'          => '' === $email ? null : $email,
			'user_id'        => null === $row['user_id'] || (int) $row['user_id'] <= 0 ? null : (int) $row['user_id'],
			'consent_at'     => $row['consent_at'],
			'functions'      => array(),
			'player'         => false,
			'classification' => null,
		);
	}

	/**
	 * @param array<string,mixed> $person Person mit Konto- und Einladungsangaben.
	 */
	private static function status( array $person, bool $has_account ): string {
		if ( ! self::has_email( $person ) ) {
			return self::NO_EMAIL;
		}
		if ( $has_account && ( null !== $person['last_login'] || null !== $person['consent_at'] ) ) {
			return self::ACTIVE;
		}
		if ( null !== $person['invited_until'] ) {
			return self::INVITED;
		}
		return $has_account ? self::ACCOUNT : self::NO_ACCOUNT;
	}

	/**
	 * @param array<string,mixed> $person Person.
	 */
	private static function rank( array $person ): int {
		$roles = array_column( $person['functions'], 'role' );
		if ( in_array( 'tk_chef', $roles, true ) ) {
			return 0;
		}
		return array() === $roles ? 2 : 1;
	}

	/**
	 * @param array<string,mixed> $person Person.
	 */
	private static function sort_name( array $person ): string {
		return Slug::make( $person['last_name'] . ' ' . $person['first_name'] );
	}
}
