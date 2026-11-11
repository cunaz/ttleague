<?php
/**
 * Datenzugriff der Klassierung: Vorschläge einer Saison lesen und schreiben.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Classification;

use TTLeague\Infrastructure\Database\Db;

/**
 * Eine Zeile (classification_proposal mit Spieler und Club) ist ein Array
 * array{id:int,player_id:int,license_no:int,name:string,club_id:int|null,club:string,current:int,
 *   rating:float|null,games:int,proposed:int,decided:int|null,decided_by:int|null,
 *   decided_at:string|null,note:string|null}.
 *
 * Die Spalte rating ist NOT NULL. Ein Spieler ohne Wertungszahl steht mit 0 und ohne Spiele in
 * der Tabelle und wird hier als null gelesen.
 */
final class ProposalRepository {

	/** Wert der Spalte rating für Spieler ohne Wertungszahl. */
	public const NO_RATING = 0.0;

	private const COLUMNS = 'cp.id, cp.player_id, cp.current_value, cp.rating, cp.games_count, cp.proposed, cp.decided, cp.decided_by, cp.decided_at, cp.note';

	public function __construct( private readonly Db $db ) {}

	/**
	 * Sperrt die Saison bis zum Ende der Transaktion und liefert ihren Stand. Muss die erste
	 * Abfrage der Transaktion sein (wie ResultRepository::lock()): Alle Schreibvorgänge der
	 * Klassierung laufen so hintereinander und sehen den Stand nach dem vorherigen.
	 *
	 * @return array{status:string,classification_closed_at:string|null}|null
	 */
	public function lock_season( int $season_id ): ?array {
		$row = $this->db->get_row( "SELECT status, classification_closed_at FROM {$this->db->table( 'season' )} WHERE id = %d FOR UPDATE", array( $season_id ) );
		if ( null === $row ) {
			return null;
		}
		return array(
			'status'                   => (string) $row['status'],
			'classification_closed_at' => $row['classification_closed_at'],
		);
	}

	/**
	 * Alle in der Saison gemeldeten Spieler mit Klassierung, Wertungszahl, gewerteten Spielen
	 * dieser Saison und dem bisherigen Vorschlag, in einer Abfrage. Gewertet ist ein Spiel mit
	 * Eintrag in rating_event (Einzel und Doppel, wie in der Wertungszahl); die Saison ergibt
	 * sich aus der Konkurrenz der Begegnung oder der Runde des Ranglistenturniers.
	 *
	 * @return list<array{player_id:int,classification:int,rating:float|null,games:int,proposal:array{id:int,current:int,rating:float,games:int,proposed:int}|null}>
	 */
	public function candidates( int $season_id ): array {
		$t    = fn( string $name ): string => $this->db->table( $name );
		$rows = $this->db->get_results(
			"SELECT ps.player_id, ps.classification, pr.rating, COALESCE(gc.games, 0) AS games,
				cp.id AS proposal_id, cp.current_value, cp.rating AS proposal_rating, cp.games_count, cp.proposed
			FROM {$t( 'player_season' )} ps
			LEFT JOIN {$t( 'player_rating' )} pr ON pr.player_id = ps.player_id
			LEFT JOIN (
				SELECT re.player_id, COUNT(*) AS games
				FROM {$t( 'rating_event' )} re
				JOIN {$t( 'game' )} g ON g.id = re.game_id
				LEFT JOIN {$t( 'fixture' )} f ON f.id = g.fixture_id
				LEFT JOIN {$t( 'rl_group' )} rg ON rg.id = g.rl_group_id
				LEFT JOIN {$t( 'rl_round' )} rr ON rr.id = rg.rl_round_id
				JOIN {$t( 'competition' )} c ON c.id = COALESCE(f.competition_id, rr.competition_id)
				WHERE c.season_id = %d
				GROUP BY re.player_id
			) gc ON gc.player_id = ps.player_id
			LEFT JOIN {$t( 'classification_proposal' )} cp ON cp.season_id = ps.season_id AND cp.player_id = ps.player_id
			WHERE ps.season_id = %d
			ORDER BY ps.player_id",
			array( $season_id, $season_id )
		);
		return array_map(
			static fn( array $row ): array => array(
				'player_id'      => (int) $row['player_id'],
				'classification' => (int) $row['classification'],
				'rating'         => null === $row['rating'] ? null : (float) $row['rating'],
				'games'          => (int) $row['games'],
				'proposal'       => null === $row['proposal_id'] ? null : array(
					'id'       => (int) $row['proposal_id'],
					'current'  => (int) $row['current_value'],
					'rating'   => (float) $row['proposal_rating'],
					'games'    => (int) $row['games_count'],
					'proposed' => (int) $row['proposed'],
				),
			),
			$rows
		);
	}

	/**
	 * Legt Vorschläge neuer Spieler an.
	 *
	 * @param list<array{player_id:int,current:int,rating:float,games:int,proposed:int}> $rows Vorschläge.
	 */
	public function insert( int $season_id, array $rows ): int {
		return $this->db->insert_many(
			'classification_proposal',
			array_map(
				static fn( array $row ): array => array(
					'season_id'     => $season_id,
					'player_id'     => $row['player_id'],
					'current_value' => $row['current'],
					'rating'        => $row['rating'],
					'games_count'   => $row['games'],
					'proposed'      => $row['proposed'],
				),
				$rows
			)
		);
	}

	/**
	 * Überschreibt den berechneten Teil einer Zeile; der Entscheid bleibt.
	 *
	 * @param array{current:int,rating:float,games:int,proposed:int} $row Neu berechnete Werte.
	 */
	public function refresh( int $id, array $row ): void {
		$this->db->update(
			'classification_proposal',
			array(
				'current_value' => $row['current'],
				'rating'        => $row['rating'],
				'games_count'   => $row['games'],
				'proposed'      => $row['proposed'],
			),
			array( 'id' => $id )
		);
	}

	/**
	 * Entfernt Zeilen ohne Entscheid, deren Spieler in der Saison nicht mehr gemeldet ist.
	 */
	public function delete_orphans( int $season_id ): int {
		return $this->db->query(
			"DELETE cp FROM {$this->db->table( 'classification_proposal' )} cp
			LEFT JOIN {$this->db->table( 'player_season' )} ps ON ps.season_id = cp.season_id AND ps.player_id = cp.player_id
			WHERE cp.season_id = %d AND cp.decided IS NULL AND ps.id IS NULL",
			array( $season_id )
		);
	}

	/**
	 * Zeilen der Saison nach Club und Name. Spieler, die nicht mehr gemeldet sind, haben keinen Club.
	 *
	 * @return list<array<string,mixed>>
	 */
	public function rows( int $season_id, ProposalFilter $filter ): array {
		[ $where, $args ] = self::where( $season_id, $filter );
		return $this->select( $where, $args );
	}

	/**
	 * Eine Zeile der Saison mit Spieler und Club.
	 *
	 * @return array<string,mixed>|null
	 */
	public function one( int $season_id, int $id ): ?array {
		return $this->select( 'cp.season_id = %d AND cp.id = %d', array( $season_id, $id ) )[0] ?? null;
	}

	/**
	 * Zeilen ohne Entscheid, die zum Filter passen, gesperrt bis zum Ende der Transaktion.
	 *
	 * @return list<array{id:int,proposed:int,note:string|null}>
	 */
	public function open_for_update( int $season_id, ProposalFilter $filter ): array {
		[ $where, $args ] = self::where( $season_id, $filter->with_open() );
		$rows             = $this->db->get_results(
			"SELECT cp.id, cp.proposed, cp.note
			FROM {$this->db->table( 'classification_proposal' )} cp
			LEFT JOIN {$this->db->table( 'player_season' )} ps ON ps.season_id = cp.season_id AND ps.player_id = cp.player_id
			WHERE $where
			ORDER BY cp.id
			FOR UPDATE",
			$args
		);
		return array_map(
			static fn( array $row ): array => array(
				'id'       => (int) $row['id'],
				'proposed' => (int) $row['proposed'],
				'note'     => $row['note'],
			),
			$rows
		);
	}

	/**
	 * Eine Zeile der Saison, gesperrt bis zum Ende der Transaktion.
	 *
	 * @return array{id:int,player_id:int,current:int,proposed:int,decided:int|null,note:string|null}|null
	 */
	public function find_for_update( int $season_id, int $id ): ?array {
		$row = $this->db->get_row(
			"SELECT id, player_id, current_value, proposed, decided, note FROM {$this->db->table( 'classification_proposal' )} WHERE id = %d AND season_id = %d FOR UPDATE",
			array( $id, $season_id )
		);
		if ( null === $row ) {
			return null;
		}
		return array(
			'id'        => (int) $row['id'],
			'player_id' => (int) $row['player_id'],
			'current'   => (int) $row['current_value'],
			'proposed'  => (int) $row['proposed'],
			'decided'   => null === $row['decided'] ? null : (int) $row['decided'],
			'note'      => $row['note'],
		);
	}

	/**
	 * Entscheid einer Zeile setzen.
	 */
	public function decide( int $id, int $value, ?string $note, int $user_id, string $now ): void {
		$this->db->update(
			'classification_proposal',
			array(
				'decided'    => $value,
				'decided_by' => $user_id,
				'decided_at' => $now,
				'note'       => $note,
			),
			array( 'id' => $id )
		);
	}

	/**
	 * Vorschlag als Entscheid für viele Zeilen in einer Anweisung übernehmen.
	 *
	 * @param list<int> $ids Zeilen ohne Entscheid.
	 */
	public function accept_many( array $ids, int $user_id, string $now ): int {
		if ( array() === $ids ) {
			return 0;
		}
		return $this->db->query(
			"UPDATE {$this->db->table( 'classification_proposal' )}
			SET decided = proposed, decided_by = %d, decided_at = %s, note = NULL
			WHERE decided IS NULL AND id IN (" . implode( ', ', array_fill( 0, count( $ids ), '%d' ) ) . ')',
			array_merge( array( $user_id, $now ), $ids )
		);
	}

	/**
	 * Zahlen zur Saison: Zeilen, ohne Entscheid, mit abweichendem Vorschlag und gemeldete
	 * Spieler ohne Zeile (seit der letzten Berechnung dazugekommen).
	 *
	 * @return array{total:int,open:int,deviating:int,missing:int}
	 */
	public function counts( int $season_id ): array {
		$cp  = $this->db->table( 'classification_proposal' );
		$row = $this->db->get_row(
			"SELECT COUNT(*) AS total, SUM(decided IS NULL) AS undecided, SUM(proposed <> current_value) AS deviating FROM $cp WHERE season_id = %d",
			array( $season_id )
		);
		return array(
			'total'     => (int) ( $row['total'] ?? 0 ),
			'open'      => (int) ( $row['undecided'] ?? 0 ),
			'deviating' => (int) ( $row['deviating'] ?? 0 ),
			'missing'   => (int) $this->db->get_var(
				"SELECT COUNT(*) FROM {$this->db->table( 'player_season' )} ps
				LEFT JOIN $cp cp ON cp.season_id = ps.season_id AND cp.player_id = ps.player_id
				WHERE ps.season_id = %d AND cp.id IS NULL",
				array( $season_id )
			),
		);
	}

	/**
	 * Clubs mit gemeldeten Spielern in der Saison, für die Auswahl im Filter.
	 *
	 * @return list<array{id:int,name:string}>
	 */
	public function clubs( int $season_id ): array {
		return array_map(
			static fn( array $row ): array => array(
				'id'   => (int) $row['id'],
				'name' => (string) $row['name'],
			),
			$this->db->get_results(
				"SELECT DISTINCT c.id, c.name FROM {$this->db->table( 'club' )} c
				JOIN {$this->db->table( 'player_season' )} ps ON ps.club_id = c.id
				WHERE ps.season_id = %d
				ORDER BY c.name, c.id",
				array( $season_id )
			)
		);
	}

	public function close( int $season_id, ?string $closed_at, string $now ): void {
		$this->db->update(
			'season',
			array(
				'classification_closed_at' => $closed_at,
				'updated_at'               => $now,
			),
			array( 'id' => $season_id )
		);
	}

	/**
	 * @param string    $where Bedingung mit den Aliasen cp und ps.
	 * @param list<int> $args  Werte der Platzhalter.
	 * @return list<array<string,mixed>>
	 */
	private function select( string $where, array $args ): array {
		$t = fn( string $name ): string => $this->db->table( $name );
		return array_map(
			array( self::class, 'hydrate' ),
			$this->db->get_results(
				'SELECT ' . self::COLUMNS . ", pl.license_no, pe.last_name, pe.first_name, ps.club_id, c.name AS club
				FROM {$t( 'classification_proposal' )} cp
				JOIN {$t( 'player' )} pl ON pl.id = cp.player_id
				JOIN {$t( 'person' )} pe ON pe.id = pl.person_id
				LEFT JOIN {$t( 'player_season' )} ps ON ps.season_id = cp.season_id AND ps.player_id = cp.player_id
				LEFT JOIN {$t( 'club' )} c ON c.id = ps.club_id
				WHERE $where
				ORDER BY c.name IS NULL, c.name, pe.last_name, pe.first_name, cp.id",
				$args
			)
		);
	}

	/**
	 * Bedingung und Werte zum Filter; erwartet die Aliase cp (Vorschlag) und ps (Meldung).
	 *
	 * @return array{0:string,1:list<int>}
	 */
	private static function where( int $season_id, ProposalFilter $filter ): array {
		$sql  = 'cp.season_id = %d';
		$args = array( $season_id );
		if ( $filter->club_id > 0 ) {
			$sql   .= ' AND ps.club_id = %d';
			$args[] = $filter->club_id;
		}
		if ( $filter->deviating ) {
			$sql .= ' AND cp.proposed <> cp.current_value';
		}
		if ( $filter->open ) {
			$sql .= ' AND cp.decided IS NULL';
		}
		return array( $sql, $args );
	}

	/**
	 * @param array<string,string|null> $row Zeile der Abfrage in rows().
	 * @return array<string,mixed>
	 */
	private static function hydrate( array $row ): array {
		$games  = (int) $row['games_count'];
		$rating = (float) $row['rating'];
		return array(
			'id'         => (int) $row['id'],
			'player_id'  => (int) $row['player_id'],
			'license_no' => (int) $row['license_no'],
			'name'       => trim( $row['last_name'] . ' ' . $row['first_name'] ),
			'club_id'    => null === $row['club_id'] ? null : (int) $row['club_id'],
			'club'       => (string) $row['club'],
			'current'    => (int) $row['current_value'],
			'rating'     => 0 === $games && self::NO_RATING === $rating ? null : $rating,
			'games'      => $games,
			'proposed'   => (int) $row['proposed'],
			'decided'    => null === $row['decided'] ? null : (int) $row['decided'],
			'decided_by' => null === $row['decided_by'] ? null : (int) $row['decided_by'],
			'decided_at' => $row['decided_at'],
			'note'       => null === $row['note'] || '' === $row['note'] ? null : (string) $row['note'],
		);
	}
}
