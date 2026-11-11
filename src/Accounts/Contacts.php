<?php
/**
 * Kontaktdaten der Captains und TK-Chefs für angemeldete Funktionärinnen und Funktionäre.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Accounts;

use TTLeague\Infrastructure\Database\Db;

/**
 * Liest E-Mail und freigegebene Telefonnummer bei jeder Anfrage direkt aus der Datenbank,
 * bewusst an den gecachten Lesemodellen vorbei: Persönliches gehört nie in einen geteilten
 * Cache. Wer die Daten sehen darf, prüft die WordPress-Schicht (ttl_view_contacts). Geliefert
 * werden nur Funktionen der aktiven Saison und nur aktive Personen; die Telefonnummer nur mit
 * person.phone_visible. Ohne WordPress-Abhängigkeit.
 */
final class Contacts {

	public function __construct( private readonly Db $db ) {}

	/**
	 * Captains und TK-Chefs der Clubs in der Saison, TK-Chefs zuerst. Ist die Saison nicht
	 * aktiv, bleibt die Liste leer.
	 *
	 * @param list<int> $club_ids Clubs.
	 * @return list<array{person_id:int,role:string,club_id:int,team_id:int|null,name:string,email:string|null,phone:string|null}>
	 */
	public function for_clubs( int $season_id, array $club_ids ): array {
		$club_ids = array_values( array_unique( array_filter( array_map( 'intval', $club_ids ), static fn( int $id ): bool => $id > 0 ) ) );
		if ( $season_id <= 0 || array() === $club_ids ) {
			return array();
		}
		$list = array();
		foreach ( $this->db->get_results(
			"SELECT cr.person_id, cr.role, cr.club_id, cr.team_id, pe.first_name, pe.last_name, pe.email, pe.phone, pe.phone_visible
			FROM {$this->db->table( 'club_role' )} cr
			JOIN {$this->db->table( 'season' )} s ON s.id = cr.season_id AND s.status = 'active'
			JOIN {$this->db->table( 'person' )} pe ON pe.id = cr.person_id AND pe.status = 'active'
			WHERE cr.season_id = %d AND cr.role IN ('captain', 'tk_chef') AND cr.club_id IN (" . implode( ', ', array_fill( 0, count( $club_ids ), '%d' ) ) . ")
			ORDER BY cr.role = 'tk_chef' DESC, pe.last_name, pe.first_name, cr.id",
			array_merge( array( $season_id ), $club_ids )
		) as $row ) {
			$email  = trim( (string) ( $row['email'] ?? '' ) );
			$phone  = '1' === (string) $row['phone_visible'] ? trim( (string) ( $row['phone'] ?? '' ) ) : '';
			$list[] = array(
				'person_id' => (int) $row['person_id'],
				'role'      => (string) $row['role'],
				'club_id'   => (int) $row['club_id'],
				'team_id'   => null === $row['team_id'] ? null : (int) $row['team_id'],
				'name'      => trim( trim( (string) $row['first_name'] ) . ' ' . trim( (string) $row['last_name'] ) ),
				'email'     => '' === $email ? null : $email,
				'phone'     => '' === $phone ? null : $phone,
			);
		}
		return $list;
	}

	/**
	 * Kontaktdaten nach Person, für die Liste der Funktionen eines Clubs.
	 *
	 * @param list<int> $club_ids Clubs.
	 * @return array<int,array{email:string|null,phone:string|null}>
	 */
	public function by_person( int $season_id, array $club_ids ): array {
		$people = array();
		foreach ( $this->for_clubs( $season_id, $club_ids ) as $row ) {
			$people[ $row['person_id'] ] = array(
				'email' => $row['email'],
				'phone' => $row['phone'],
			);
		}
		return $people;
	}

	/**
	 * Captains und TK-Chefs beider Seiten einer Begegnung der aktiven Saison: Captains des
	 * Teams (Meisterschaft) und TK-Chefs des Clubs (Meisterschaft und Cup). Null, wenn es die
	 * Begegnung nicht gibt oder ihre Saison nicht aktiv ist.
	 *
	 * @return array{home:array{name:string,people:list<array<string,mixed>>},away:array{name:string,people:list<array<string,mixed>>}}|null
	 */
	public function for_fixture( int $fixture_id ): ?array {
		$row = $this->db->get_row(
			"SELECT c.season_id, f.home_team_id, f.away_team_id,
				COALESCE(ht.club_id, he.club_id) AS home_club_id, COALESCE(at.club_id, ae.club_id) AS away_club_id,
				COALESCE(ht.name, he.name, '') AS home_name, COALESCE(at.name, ae.name, '') AS away_name
			FROM {$this->db->table( 'fixture' )} f
			JOIN {$this->db->table( 'competition' )} c ON c.id = f.competition_id
			JOIN {$this->db->table( 'season' )} s ON s.id = c.season_id AND s.status = 'active'
			LEFT JOIN {$this->db->table( 'team' )} ht ON ht.id = f.home_team_id
			LEFT JOIN {$this->db->table( 'team' )} at ON at.id = f.away_team_id
			LEFT JOIN {$this->db->table( 'cup_entry' )} he ON he.id = f.home_entry_id
			LEFT JOIN {$this->db->table( 'cup_entry' )} ae ON ae.id = f.away_entry_id
			WHERE f.id = %d",
			array( $fixture_id )
		);
		if ( null === $row ) {
			return null;
		}
		$sides = array();
		foreach ( array( 'home', 'away' ) as $side ) {
			$sides[ $side ] = array(
				'name'    => (string) $row[ $side . '_name' ],
				'club_id' => (int) ( $row[ $side . '_club_id' ] ?? 0 ),
				'team_id' => (int) ( $row[ $side . '_team_id' ] ?? 0 ),
				'people'  => array(),
			);
		}
		foreach ( $this->for_clubs( (int) $row['season_id'], array( $sides['home']['club_id'], $sides['away']['club_id'] ) ) as $person ) {
			foreach ( array( 'home', 'away' ) as $side ) {
				if ( $person['club_id'] !== $sides[ $side ]['club_id'] ) {
					continue;
				}
				if ( 'tk_chef' === $person['role'] || ( $sides[ $side ]['team_id'] > 0 && $person['team_id'] === $sides[ $side ]['team_id'] ) ) {
					$sides[ $side ]['people'][] = $person;
				}
			}
		}
		return array(
			'home' => array(
				'name'   => $sides['home']['name'],
				'people' => $sides['home']['people'],
			),
			'away' => array(
				'name'   => $sides['away']['name'],
				'people' => $sides['away']['people'],
			),
		);
	}
}
