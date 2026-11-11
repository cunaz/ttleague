<?php
/**
 * Abfragen des Mannschaftscups: Konkurrenzen, Meldungen, Tableau.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Cup;

use TTLeague\Infrastructure\Database\Db;

/**
 * Liest und schreibt ohne Cache (Schreibseite und Backend); die öffentliche Darstellung
 * verwendet ReadModel\Cup. Zeitpunkte der Meldung sind UTC, Fristen und Termine Ortszeit.
 */
final class CupRepository {

	public const ACTIVE    = 'active';
	public const WITHDRAWN = 'withdrawn';

	public function __construct( private readonly Db $db ) {}

	/**
	 * Konkurrenz vom Typ CUP mit Saison und Parametern (format_json).
	 *
	 * @return array{id:int,name:string,category:string,season_id:int,season_name:string,season_slug:string,season_status:string,season_start:string,season_end:string,format:array<string,mixed>}|null
	 */
	public function competition( int $id ): ?array {
		$row = $this->db->get_row(
			"SELECT c.id, c.name, COALESCE(c.category, '') AS category, c.season_id, c.format_json,
				s.name AS season_name, s.slug AS season_slug, s.status AS season_status, s.start_date, s.end_date
			FROM {$this->db->table( 'competition' )} c
			JOIN {$this->db->table( 'season' )} s ON s.id = c.season_id
			WHERE c.id = %d AND c.type = 'CUP'",
			array( $id )
		);
		return null === $row ? null : self::competition_row( $row );
	}

	/**
	 * Cup-Konkurrenzen einer Saison nach Kategorie.
	 *
	 * @return list<array{id:int,name:string,category:string,season_id:int,season_name:string,season_slug:string,season_status:string,season_start:string,season_end:string,format:array<string,mixed>}>
	 */
	public function competitions( int $season_id ): array {
		return array_map(
			array( self::class, 'competition_row' ),
			$this->db->get_results(
				"SELECT c.id, c.name, COALESCE(c.category, '') AS category, c.season_id, c.format_json,
					s.name AS season_name, s.slug AS season_slug, s.status AS season_status, s.start_date, s.end_date
				FROM {$this->db->table( 'competition' )} c
				JOIN {$this->db->table( 'season' )} s ON s.id = c.season_id
				WHERE c.season_id = %d AND c.type = 'CUP'
				ORDER BY c.category, c.sort_order, c.id",
				array( $season_id )
			)
		);
	}

	/**
	 * Saisons mit dem Status, neueste zuerst (es soll höchstens eine aktive und eine geplante geben).
	 *
	 * @return list<int>
	 */
	public function seasons( string $status ): array {
		return array_map(
			'intval',
			$this->db->get_col( "SELECT id FROM {$this->db->table( 'season' )} WHERE status = %s ORDER BY start_date DESC, id DESC", array( $status ) )
		);
	}

	/**
	 * @param array<string,mixed> $format Parameter der Konkurrenz.
	 */
	public function save_format( int $competition_id, array $format ): void {
		$this->db->update(
			'competition',
			array( 'format_json' => array() === $format ? null : (string) json_encode( $format, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) ), // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- läuft auch ohne WordPress.
			array( 'id' => $competition_id )
		);
	}

	/**
	 * Sperrt die Saison bis zum Ende der Transaktion, wie die Erfassung (ResultRepository::lock):
	 * Meldungen, Auslosung und Nachrücken einer Saison laufen so hintereinander.
	 */
	public function lock_season( int $season_id ): void {
		$this->db->get_var( "SELECT id FROM {$this->db->table( 'season' )} WHERE id = %d FOR UPDATE", array( $season_id ) );
	}

	/**
	 * @return array{id:int,name:string,short_name:string}|null
	 */
	public function club( int $club_id ): ?array {
		$row = $this->db->get_row( "SELECT id, name, short_name FROM {$this->db->table( 'club' )} WHERE id = %d", array( $club_id ) );
		if ( null === $row ) {
			return null;
		}
		return array(
			'id'         => (int) $row['id'],
			'name'       => (string) $row['name'],
			'short_name' => (string) $row['short_name'],
		);
	}

	/**
	 * Clubs mit gemeldeten Spielern in der Saison (für das Formular der TK).
	 *
	 * @return list<array{id:int,short_name:string}>
	 */
	public function clubs_of_season( int $season_id ): array {
		$clubs = array();
		foreach ( $this->db->get_results(
			"SELECT DISTINCT c.id, c.short_name FROM {$this->db->table( 'player_season' )} ps
			JOIN {$this->db->table( 'club' )} c ON c.id = ps.club_id
			WHERE ps.season_id = %d AND ps.status = 'active'
			ORDER BY c.short_name, c.id",
			array( $season_id )
		) as $row ) {
			$clubs[] = array(
				'id'         => (int) $row['id'],
				'short_name' => (string) $row['short_name'],
			);
		}
		return $clubs;
	}

	/**
	 * Für den Club gemeldete Spieler der Saison (Status active) mit Klassierung.
	 *
	 * @return array<int,array{player_id:int,name:string,classification:int,club_id:int}>
	 */
	public function club_players( int $season_id, int $club_id ): array {
		$players = array();
		foreach ( $this->db->get_results(
			"SELECT ps.player_id, ps.classification, ps.club_id, pe.first_name, pe.last_name
			FROM {$this->db->table( 'player_season' )} ps
			JOIN {$this->db->table( 'player' )} p ON p.id = ps.player_id
			JOIN {$this->db->table( 'person' )} pe ON pe.id = p.person_id
			WHERE ps.season_id = %d AND ps.club_id = %d AND ps.status = 'active'
			ORDER BY pe.last_name, pe.first_name, ps.player_id",
			array( $season_id, $club_id )
		) as $row ) {
			$players[ (int) $row['player_id'] ] = array(
				'player_id'      => (int) $row['player_id'],
				'name'           => trim( $row['first_name'] . ' ' . $row['last_name'] ),
				'classification' => (int) $row['classification'],
				'club_id'        => (int) $row['club_id'],
			);
		}
		return $players;
	}

	/**
	 * Club, für den ein Spieler in der Saison gemeldet ist (Status active), oder null.
	 */
	public function club_of_player( int $season_id, int $player_id ): ?int {
		$club = $this->db->get_var(
			"SELECT club_id FROM {$this->db->table( 'player_season' )} WHERE season_id = %d AND player_id = %d AND status = 'active'",
			array( $season_id, $player_id )
		);
		return null === $club ? null : (int) $club;
	}

	/**
	 * Cupmannschaften der Saison, in denen die Spieler gemeldet sind, über alle Kategorien
	 * (auch zurückgezogene: Die Meldung bindet den Spieler für die Saison, Art. 20.2).
	 *
	 * @param list<int> $player_ids Spieler.
	 * @return array<int,array{entry_id:int,name:string,competition:string}> Spieler => Mannschaft.
	 */
	public function entries_of_players( int $season_id, array $player_ids ): array {
		$ids = array_values( array_unique( array_filter( array_map( 'intval', $player_ids ) ) ) );
		if ( array() === $ids ) {
			return array();
		}
		$in    = implode( ',', $ids ); // Nur Ganzzahlen.
		$found = array();
		foreach ( $this->db->get_results(
			"SELECT e.id, e.name, e.player1_id, e.player2_id, c.name AS competition
			FROM {$this->db->table( 'cup_entry' )} e
			JOIN {$this->db->table( 'competition' )} c ON c.id = e.competition_id
			WHERE c.season_id = %d AND c.type = 'CUP' AND ( e.player1_id IN ({$in}) OR e.player2_id IN ({$in}) )
			ORDER BY e.id",
			array( $season_id )
		) as $row ) {
			foreach ( array( $row['player1_id'], $row['player2_id'] ) as $player ) {
				if ( null !== $player && in_array( (int) $player, $ids, true ) ) {
					$found[ (int) $player ] ??= array(
						'entry_id'    => (int) $row['id'],
						'name'        => (string) $row['name'],
						'competition' => (string) $row['competition'],
					);
				}
			}
		}
		return $found;
	}

	/**
	 * Meldungen einer Konkurrenz mit Club und Spielern, in der Reihenfolge der Meldung.
	 *
	 * @return list<array<string,mixed>>
	 */
	public function entries( int $competition_id ): array {
		return array_map( array( self::class, 'entry_row' ), $this->db->get_results( $this->entry_sql( 'e.competition_id = %d ORDER BY e.id' ), array( $competition_id ) ) );
	}

	/**
	 * @return array<string,mixed>|null
	 */
	public function entry( int $entry_id ): ?array {
		$row = $this->db->get_row( $this->entry_sql( 'e.id = %d' ), array( $entry_id ) );
		return null === $row ? null : self::entry_row( $row );
	}

	/**
	 * Nächste Mannschaftsnummer des Clubs in der Konkurrenz.
	 */
	public function next_number( int $competition_id, int $club_id ): int {
		return 1 + (int) $this->db->get_var(
			"SELECT MAX(number) FROM {$this->db->table( 'cup_entry' )} WHERE competition_id = %d AND club_id = %d",
			array( $competition_id, $club_id )
		);
	}

	/**
	 * Ist die Auslosung freigegeben (gibt es ein Tableau)?
	 */
	public function has_draw( int $competition_id ): bool {
		return null !== $this->db->get_var( "SELECT id FROM {$this->db->table( 'cup_tie' )} WHERE competition_id = %d LIMIT 1", array( $competition_id ) );
	}

	/**
	 * Paarungen einer Konkurrenz mit ihrer Begegnung, nach Runde und Platz.
	 *
	 * @return list<array{id:int,competition_id:int,round:int,slot:int,home:int|null,away:int|null,deadline:string|null,fixture:array{id:int,status:string,scheduled_at:string|null,venue_id:int|null,home_entry:int|null,away_entry:int|null,home_score:int|null,away_score:int|null}|null}>
	 */
	public function ties( int $competition_id ): array {
		return array_map( array( self::class, 'tie_row' ), $this->db->get_results( $this->tie_sql( 't.competition_id = %d ORDER BY t.round, t.slot' ), array( $competition_id ) ) );
	}

	/**
	 * @return array{id:int,competition_id:int,round:int,slot:int,home:int|null,away:int|null,deadline:string|null,fixture:array<string,mixed>|null}|null
	 */
	public function tie( int $tie_id ): ?array {
		$row = $this->db->get_row( $this->tie_sql( 't.id = %d' ), array( $tie_id ) );
		return null === $row ? null : self::tie_row( $row );
	}

	/**
	 * Konkurrenz einer Begegnung, wenn sie zu einem Cup mit Tableau gehört.
	 */
	public function competition_of_fixture( int $fixture_id ): ?int {
		$id = $this->db->get_var(
			"SELECT f.competition_id FROM {$this->db->table( 'fixture' )} f
			JOIN {$this->db->table( 'competition' )} c ON c.id = f.competition_id
			WHERE f.id = %d AND c.type = 'CUP'
				AND EXISTS ( SELECT 1 FROM {$this->db->table( 'cup_tie' )} t WHERE t.competition_id = c.id )",
			array( $fixture_id )
		);
		return null === $id ? null : (int) $id;
	}

	/**
	 * Aktive Lokale für das Ansetzen von Halbfinal und Final.
	 *
	 * @return array<int,string> Lokal => «Club: Name».
	 */
	public function venues(): array {
		$venues = array();
		foreach ( $this->db->get_results(
			"SELECT v.id, v.name, c.short_name FROM {$this->db->table( 'venue' )} v
			JOIN {$this->db->table( 'club' )} c ON c.id = v.club_id
			WHERE v.active = 1 ORDER BY c.short_name, v.name, v.id"
		) as $row ) {
			$venues[ (int) $row['id'] ] = $row['short_name'] . ': ' . $row['name'];
		}
		return $venues;
	}

	/**
	 * Personen einer Cupmannschaft für Mitteilungen: ihre beiden Spieler und die TK-Chefs des
	 * Clubs in der Saison der Konkurrenz.
	 *
	 * @return list<int> Personen.
	 */
	public function entry_people( int $entry_id ): array {
		return array_values(
			array_unique(
				array_map(
					'intval',
					$this->db->get_col(
						"SELECT p.person_id FROM {$this->db->table( 'cup_entry' )} e
						JOIN {$this->db->table( 'player' )} p ON p.id = e.player1_id OR p.id = e.player2_id
						WHERE e.id = %d
						UNION
						SELECT r.person_id FROM {$this->db->table( 'cup_entry' )} e
						JOIN {$this->db->table( 'competition' )} c ON c.id = e.competition_id
						JOIN {$this->db->table( 'club_role' )} r ON r.season_id = c.season_id AND r.club_id = e.club_id AND r.role = 'tk_chef'
						WHERE e.id = %d",
						array( $entry_id, $entry_id )
					)
				)
			)
		);
	}

	/**
	 * Paarungen, zu denen seit $since (UTC) eine Mitteilung fällig wurde: neue Begegnung oder
	 * Freilos (Einträge «fixture_created» und «bye» im audit_log).
	 *
	 * @return list<int> Paarungen in aufsteigender Reihenfolge.
	 */
	public function ties_announced_since( int $competition_id, string $since, int $after_tie = 0 ): array {
		return array_map(
			'intval',
			$this->db->get_col(
				"SELECT DISTINCT t.id FROM {$this->db->table( 'cup_tie' )} t
				JOIN {$this->db->table( 'audit_log' )} a ON a.entity = 'cup_tie' AND a.entity_id = t.id AND a.action IN ('fixture_created', 'bye')
				WHERE t.competition_id = %d AND a.created_at >= %s AND t.id > %d
				ORDER BY t.id",
				array( $competition_id, $since, $after_tie )
			)
		);
	}

	/**
	 * Cup-Konkurrenzen aktiver Saisons mit Tableau.
	 *
	 * @return list<int>
	 */
	public function active_competitions_with_draw(): array {
		return array_map(
			'intval',
			$this->db->get_col(
				"SELECT c.id FROM {$this->db->table( 'competition' )} c
				JOIN {$this->db->table( 'season' )} s ON s.id = c.season_id
				WHERE c.type = 'CUP' AND s.status = 'active'
					AND EXISTS ( SELECT 1 FROM {$this->db->table( 'cup_tie' )} t WHERE t.competition_id = c.id )
				ORDER BY c.id"
			)
		);
	}

	/**
	 * Hat die Person zu diesem Bezug schon eine versendete Mitteilung (gleich über welchen Kanal)?
	 */
	public function was_notified( int $person_id, string $event, string $ref_type, int $ref_id, string $ref_key ): bool {
		return null !== $this->db->get_var(
			"SELECT id FROM {$this->db->table( 'notification_log' )}
			WHERE person_id = %d AND event_type = %s AND ref_type = %s AND ref_id = %d AND ref_key = %s AND status = 'sent' LIMIT 1",
			array( $person_id, $event, $ref_type, $ref_id, $ref_key )
		);
	}

	private function entry_sql( string $where ): string {
		return "SELECT e.id, e.competition_id, e.club_id, e.number, e.name, e.player1_id, e.player2_id, e.points_total, e.seed, e.status,
				e.registered_by, e.registered_at, c.short_name AS club_short, c.name AS club_name,
				p1.first_name AS first1, p1.last_name AS last1, s1.classification AS class1,
				p2.first_name AS first2, p2.last_name AS last2, s2.classification AS class2
			FROM {$this->db->table( 'cup_entry' )} e
			JOIN {$this->db->table( 'competition' )} co ON co.id = e.competition_id
			JOIN {$this->db->table( 'club' )} c ON c.id = e.club_id
			LEFT JOIN {$this->db->table( 'player' )} pl1 ON pl1.id = e.player1_id
			LEFT JOIN {$this->db->table( 'person' )} p1 ON p1.id = pl1.person_id
			LEFT JOIN {$this->db->table( 'player_season' )} s1 ON s1.player_id = e.player1_id AND s1.season_id = co.season_id
			LEFT JOIN {$this->db->table( 'player' )} pl2 ON pl2.id = e.player2_id
			LEFT JOIN {$this->db->table( 'person' )} p2 ON p2.id = pl2.person_id
			LEFT JOIN {$this->db->table( 'player_season' )} s2 ON s2.player_id = e.player2_id AND s2.season_id = co.season_id
			WHERE {$where}";
	}

	private function tie_sql( string $where ): string {
		return "SELECT t.id, t.competition_id, t.round, t.slot, t.fixture_id, t.home_entry_id, t.away_entry_id, t.deadline,
				f.status, f.scheduled_at, f.venue_id, f.home_entry_id AS fixture_home, f.away_entry_id AS fixture_away, f.home_score, f.away_score
			FROM {$this->db->table( 'cup_tie' )} t
			LEFT JOIN {$this->db->table( 'fixture' )} f ON f.id = t.fixture_id
			WHERE {$where}";
	}

	/**
	 * @param array<string,string|null> $row Zeile.
	 * @return array{id:int,name:string,category:string,season_id:int,season_name:string,season_slug:string,season_status:string,season_start:string,season_end:string,format:array<string,mixed>}
	 */
	private static function competition_row( array $row ): array {
		$format = json_decode( (string) $row['format_json'], true );
		return array(
			'id'            => (int) $row['id'],
			'name'          => (string) $row['name'],
			'category'      => (string) $row['category'],
			'season_id'     => (int) $row['season_id'],
			'season_name'   => (string) $row['season_name'],
			'season_slug'   => (string) $row['season_slug'],
			'season_status' => (string) $row['season_status'],
			'season_start'  => (string) $row['start_date'],
			'season_end'    => (string) $row['end_date'],
			'format'        => is_array( $format ) ? $format : array(),
		);
	}

	/**
	 * @param array<string,string|null> $row Zeile.
	 * @return array<string,mixed>
	 */
	private static function entry_row( array $row ): array {
		$players = array();
		foreach ( array( 1, 2 ) as $n ) {
			if ( null === $row[ 'player' . $n . '_id' ] ) {
				continue;
			}
			$players[] = array(
				'player_id'      => (int) $row[ 'player' . $n . '_id' ],
				'name'           => trim( $row[ 'first' . $n ] . ' ' . $row[ 'last' . $n ] ),
				'classification' => null === $row[ 'class' . $n ] ? null : (int) $row[ 'class' . $n ],
			);
		}
		return array(
			'id'             => (int) $row['id'],
			'competition_id' => (int) $row['competition_id'],
			'club_id'        => (int) $row['club_id'],
			'club_short'     => (string) $row['club_short'],
			'club_name'      => (string) $row['club_name'],
			'number'         => (int) $row['number'],
			'name'           => (string) $row['name'],
			'players'        => $players,
			'points_total'   => null === $row['points_total'] ? null : (int) $row['points_total'],
			'seed'           => null === $row['seed'] ? null : (int) $row['seed'],
			'status'         => (string) $row['status'],
			'registered_by'  => null === $row['registered_by'] ? null : (int) $row['registered_by'],
			'registered_at'  => $row['registered_at'],
		);
	}

	/**
	 * @param array<string,string|null> $row Zeile.
	 * @return array{id:int,competition_id:int,round:int,slot:int,home:int|null,away:int|null,deadline:string|null,fixture:array{id:int,status:string,scheduled_at:string|null,venue_id:int|null,home_entry:int|null,away_entry:int|null,home_score:int|null,away_score:int|null}|null}
	 */
	private static function tie_row( array $row ): array {
		$int     = static fn( ?string $value ): ?int => null === $value ? null : (int) $value;
		$fixture = null;
		if ( null !== $row['fixture_id'] && null !== $row['status'] ) {
			$fixture = array(
				'id'           => (int) $row['fixture_id'],
				'status'       => (string) $row['status'],
				'scheduled_at' => $row['scheduled_at'],
				'venue_id'     => $int( $row['venue_id'] ),
				'home_entry'   => $int( $row['fixture_home'] ),
				'away_entry'   => $int( $row['fixture_away'] ),
				'home_score'   => $int( $row['home_score'] ),
				'away_score'   => $int( $row['away_score'] ),
			);
		}
		return array(
			'id'             => (int) $row['id'],
			'competition_id' => (int) $row['competition_id'],
			'round'          => (int) $row['round'],
			'slot'           => (int) $row['slot'],
			'home'           => $int( $row['home_entry_id'] ),
			'away'           => $int( $row['away_entry_id'] ),
			'deadline'       => null === $row['deadline'] ? null : substr( $row['deadline'], 0, 10 ),
			'fixture'        => $fixture,
		);
	}
}
