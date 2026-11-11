<?php
/**
 * Lesen und Schreiben der Anträge auf Verschiebung und der Terminvorschläge.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Postponement;

use TTLeague\Domain\Match\FixtureWorkflow as Flow;
use TTLeague\Domain\Match\PostponementRules as Rules;
use TTLeague\Infrastructure\Database\Db;

/**
 * Ein Antrag ist ein Array
 * array{id:int,fixture_id:int,requested_by:int,kind:string,old_date:?string,new_date:string,
 *   status:string,reason:?string,accepted_by:?int,response_note:?string,responded_at:?string,
 *   approved_by:?int,approved_at:?string,created_at:string,updated_at:string}.
 * Termine (old_date, new_date) sind Ortszeit der Website, alle anderen Zeitpunkte UTC.
 */
final class PostponementRepository {

	private const COLUMNS = 'p.id, p.fixture_id, p.requested_by, p.kind, p.old_date, p.new_date, p.status, p.reason, p.accepted_by, p.response_note, p.responded_at, p.approved_by, p.approved_at, p.created_at, p.updated_at';

	public function __construct( private readonly Db $db ) {}

	/**
	 * @return array<string,mixed>|null
	 */
	public function find( int $id ): ?array {
		$row = $this->db->get_row( 'SELECT ' . self::COLUMNS . " FROM {$this->db->table( 'postponement' )} p WHERE p.id = %d", array( $id ) );
		return null === $row ? null : self::row( $row );
	}

	/**
	 * Der offene Antrag einer Begegnung (requested oder accepted).
	 *
	 * @return array<string,mixed>|null
	 */
	public function open_for_fixture( int $fixture_id ): ?array {
		$row = $this->db->get_row(
			'SELECT ' . self::COLUMNS . " FROM {$this->db->table( 'postponement' )} p
			WHERE p.fixture_id = %d AND p.status IN (%s, %s) ORDER BY p.id DESC LIMIT 1",
			array( $fixture_id, Rules::REQUESTED, Rules::ACCEPTED )
		);
		return null === $row ? null : self::row( $row );
	}

	/**
	 * Alle Anträge einer Begegnung, neueste zuerst.
	 *
	 * @return list<array<string,mixed>>
	 */
	public function for_fixture( int $fixture_id ): array {
		return array_map(
			array( self::class, 'row' ),
			$this->db->get_results(
				'SELECT ' . self::COLUMNS . " FROM {$this->db->table( 'postponement' )} p WHERE p.fixture_id = %d ORDER BY p.id DESC",
				array( $fixture_id )
			)
		);
	}

	/**
	 * Offene Anträge der Saison, älteste zuerst. Nur Meisterschaft und Cup.
	 *
	 * @param list<string> $statuses requested und/oder accepted.
	 * @return list<array<string,mixed>>
	 */
	public function open_in_season( int $season_id, array $statuses = Rules::OPEN ): array {
		$statuses = array_values( array_intersect( Rules::OPEN, $statuses ) );
		if ( array() === $statuses ) {
			return array();
		}
		return array_map(
			array( self::class, 'row' ),
			$this->db->get_results(
				'SELECT ' . self::COLUMNS . " FROM {$this->db->table( 'postponement' )} p
				JOIN {$this->db->table( 'fixture' )} f ON f.id = p.fixture_id
				JOIN {$this->db->table( 'competition' )} c ON c.id = f.competition_id
				WHERE c.season_id = %d AND c.type IN ('MM', 'CUP') AND p.status IN (" . implode( ',', array_fill( 0, count( $statuses ), '%s' ) ) . ')
				ORDER BY p.created_at, p.id',
				array_merge( array( $season_id ), $statuses )
			)
		);
	}

	/**
	 * Offene Begegnungen der Saison ohne Termin, an denen einer der Clubs beteiligt ist
	 * (Cup-Runden, für die noch ein Termin vorzuschlagen ist).
	 *
	 * @param list<int> $club_ids Clubs.
	 * @return list<int> Begegnungen.
	 */
	public function unscheduled( int $season_id, array $club_ids ): array {
		$clubs = array_values( array_unique( array_filter( array_map( 'intval', $club_ids ) ) ) );
		if ( array() === $clubs ) {
			return array();
		}
		$in = implode( ',', $clubs ); // Nur Ganzzahlen.
		return array_map(
			'intval',
			$this->db->get_col(
				"SELECT f.id FROM {$this->db->table( 'fixture' )} f
				JOIN {$this->db->table( 'competition' )} c ON c.id = f.competition_id
				LEFT JOIN {$this->db->table( 'team' )} ht ON ht.id = f.home_team_id
				LEFT JOIN {$this->db->table( 'team' )} at ON at.id = f.away_team_id
				LEFT JOIN {$this->db->table( 'cup_entry' )} he ON he.id = f.home_entry_id
				LEFT JOIN {$this->db->table( 'cup_entry' )} ae ON ae.id = f.away_entry_id
				WHERE c.season_id = %d AND c.type IN ('MM', 'CUP') AND f.scheduled_at IS NULL AND f.status IN (%s, %s)
					AND COALESCE(ht.id, he.id) IS NOT NULL AND COALESCE(at.id, ae.id) IS NOT NULL
					AND ( ht.club_id IN ({$in}) OR at.club_id IN ({$in}) OR he.club_id IN ({$in}) OR ae.club_id IN ({$in}) )
				ORDER BY f.id",
				array( $season_id, Flow::SCHEDULED, Flow::POSTPONED )
			)
		);
	}

	/**
	 * @param array<string,int|string|null> $data Felder.
	 */
	public function insert( array $data ): int {
		return $this->db->insert( 'postponement', $data );
	}

	/**
	 * @param array<string,int|string|null> $data Felder.
	 */
	public function update( int $id, array $data ): void {
		$this->db->update( 'postponement', $data, array( 'id' => $id ) );
	}

	/**
	 * Frist der Cup-Runde, zu der die Begegnung gehört (Datum), oder null.
	 */
	public function deadline( int $fixture_id ): ?string {
		$deadline = $this->db->get_var(
			"SELECT deadline FROM {$this->db->table( 'cup_tie' )} WHERE fixture_id = %d AND deadline IS NOT NULL ORDER BY id LIMIT 1",
			array( $fixture_id )
		);
		return null === $deadline || '' === $deadline ? null : substr( $deadline, 0, 10 );
	}

	/**
	 * Beginn und Ende der Saison (Datum).
	 *
	 * @return array{start:string,end:string}|null
	 */
	public function season_dates( int $season_id ): ?array {
		$row = $this->db->get_row( "SELECT start_date, end_date FROM {$this->db->table( 'season' )} WHERE id = %d", array( $season_id ) );
		if ( null === $row ) {
			return null;
		}
		return array(
			'start' => substr( (string) $row['start_date'], 0, 10 ),
			'end'   => substr( (string) $row['end_date'], 0, 10 ),
		);
	}

	/**
	 * Aktive Saisons (für den stündlichen Lauf und die Übersicht der TK).
	 *
	 * @return list<int>
	 */
	public function active_seasons(): array {
		return array_map( 'intval', $this->db->get_col( "SELECT id FROM {$this->db->table( 'season' )} WHERE status = 'active' ORDER BY start_date DESC, id DESC" ) );
	}

	/**
	 * Alle Anträge einer Saison mit Begegnung für den CSV-Export, älteste zuerst.
	 *
	 * @return list<array<string,string|null>>
	 */
	public function export_rows( int $season_id ): array {
		return $this->db->get_results(
			'SELECT ' . self::COLUMNS . ", c.name AS competition,
				COALESCE(ht.name, he.name, '') AS home_name, COALESCE(at.name, ae.name, '') AS away_name
			FROM {$this->db->table( 'postponement' )} p
			JOIN {$this->db->table( 'fixture' )} f ON f.id = p.fixture_id
			JOIN {$this->db->table( 'competition' )} c ON c.id = f.competition_id
			LEFT JOIN {$this->db->table( 'team' )} ht ON ht.id = f.home_team_id
			LEFT JOIN {$this->db->table( 'team' )} at ON at.id = f.away_team_id
			LEFT JOIN {$this->db->table( 'cup_entry' )} he ON he.id = f.home_entry_id
			LEFT JOIN {$this->db->table( 'cup_entry' )} ae ON ae.id = f.away_entry_id
			WHERE c.season_id = %d
			ORDER BY p.created_at, p.id",
			array( $season_id )
		);
	}

	/**
	 * Saison zum Slug (z. B. «2026-27») oder null.
	 */
	public function season_by_slug( string $slug ): ?int {
		$id = $this->db->get_var( "SELECT id FROM {$this->db->table( 'season' )} WHERE slug = %s", array( $slug ) );
		return null === $id ? null : (int) $id;
	}

	/**
	 * Namen der Personen zu Konten.
	 *
	 * @param list<int> $user_ids Konten.
	 * @return array<int,string> Konto => «Vorname Nachname».
	 */
	public function names_for_users( array $user_ids ): array {
		$ids = array_values( array_unique( array_filter( array_map( 'intval', $user_ids ), static fn( int $id ): bool => $id > 0 ) ) );
		if ( array() === $ids ) {
			return array();
		}
		$names = array();
		foreach ( $this->db->get_results(
			"SELECT user_id, first_name, last_name FROM {$this->db->table( 'person' )} WHERE user_id IN (" . implode( ',', $ids ) . ') ORDER BY id' // Nur Ganzzahlen.
		) as $row ) {
			$names[ (int) $row['user_id'] ] ??= trim( $row['first_name'] . ' ' . $row['last_name'] );
		}
		return $names;
	}

	/**
	 * @param array<string,string|null> $row Zeile.
	 * @return array<string,mixed>
	 */
	private static function row( array $row ): array {
		$int = static fn( ?string $value ): ?int => null === $value ? null : (int) $value;
		return array(
			'id'            => (int) $row['id'],
			'fixture_id'    => (int) $row['fixture_id'],
			'requested_by'  => (int) $row['requested_by'],
			'kind'          => Rules::PROPOSE === $row['kind'] ? Rules::PROPOSE : Rules::POSTPONE,
			'old_date'      => $row['old_date'],
			'new_date'      => (string) $row['new_date'],
			'status'        => (string) $row['status'],
			'reason'        => $row['reason'],
			'accepted_by'   => $int( $row['accepted_by'] ),
			'response_note' => $row['response_note'],
			'responded_at'  => $row['responded_at'],
			'approved_by'   => $int( $row['approved_by'] ),
			'approved_at'   => $row['approved_at'],
			'created_at'    => (string) $row['created_at'],
			'updated_at'    => (string) $row['updated_at'],
		);
	}
}
