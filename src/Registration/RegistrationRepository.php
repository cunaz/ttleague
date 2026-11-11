<?php
/**
 * Datenzugriff der Meldung: Meldestand, Spieler, Neumeldungen, Mannschaften, Lokale.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Registration;

use TTLeague\Infrastructure\Database\Db;

/**
 * Liest und schreibt ohne eigene Regeln; Rechte, Fristen und Prüfungen liegen in den Diensten.
 * Zeilen kommen als Arrays mit passenden Typen zurück (IDs als int, fehlende Werte als null).
 */
final class RegistrationRepository {

	public function __construct( private readonly Db $db ) {}

	/**
	 * @return array{id:int,name:string,short_name:string}|null
	 */
	public function club( int $club_id ): ?array {
		$row = $this->db->get_row( "SELECT id, name, short_name FROM {$this->db->table( 'club' )} WHERE id = %d", array( $club_id ) );
		return null === $row ? null : array(
			'id'         => (int) $row['id'],
			'name'       => (string) $row['name'],
			'short_name' => (string) $row['short_name'],
		);
	}

	/**
	 * Sperrt den Club bis zum Ende der Transaktion: Schreibvorgänge an der Meldung eines Clubs
	 * laufen hintereinander (z. B. Ändern und Einreichen in zwei Fenstern).
	 */
	public function lock_club( int $club_id ): void {
		$this->db->get_var( "SELECT id FROM {$this->db->table( 'club' )} WHERE id = %d FOR UPDATE", array( $club_id ) );
	}

	/**
	 * Sperrt den Spieler: Zwei Clubs melden denselben Spieler nicht gleichzeitig.
	 */
	public function lock_player( int $player_id ): void {
		$this->db->get_var( "SELECT id FROM {$this->db->table( 'player' )} WHERE id = %d FOR UPDATE", array( $player_id ) );
	}

	/**
	 * @return array{id:int,season_id:int,club_id:int,status:string,submitted_at:string|null,submitted_by:int|null,reviewed_at:string|null,reviewed_by:int|null,note:string|null}|null
	 */
	public function club_season( int $season_id, int $club_id ): ?array {
		$row = $this->db->get_row(
			"SELECT id, season_id, club_id, status, submitted_at, submitted_by, reviewed_at, reviewed_by, note
			FROM {$this->db->table( 'club_season' )} WHERE season_id = %d AND club_id = %d",
			array( $season_id, $club_id )
		);
		return null === $row ? null : array(
			'id'           => (int) $row['id'],
			'season_id'    => (int) $row['season_id'],
			'club_id'      => (int) $row['club_id'],
			'status'       => (string) $row['status'],
			'submitted_at' => $row['submitted_at'],
			'submitted_by' => self::int_or_null( $row['submitted_by'] ),
			'reviewed_at'  => $row['reviewed_at'],
			'reviewed_by'  => self::int_or_null( $row['reviewed_by'] ),
			'note'         => $row['note'],
		);
	}

	/**
	 * @param array<string,int|string|null> $data Neue Werte.
	 */
	public function update_club_season( int $id, array $data ): void {
		$this->db->update( 'club_season', $data, array( 'id' => $id ) );
	}

	/**
	 * Stand je Club einer Saison: Clubs mit Meldung (club_season), gemeldeten Spielern,
	 * Mannschaften oder Neumeldungen, nach Name.
	 *
	 * @return list<array{club_id:int,name:string,short_name:string,status:string|null,submitted_at:string|null,reviewed_at:string|null,note:string|null,players:int,provisional:int,teams:int,requests:int}>
	 */
	public function overview( int $season_id ): array {
		$ps   = $this->db->table( 'player_season' );
		$pr   = $this->db->table( 'player_request' );
		$team = $this->db->table( 'team' );
		$rows = $this->db->get_results(
			"SELECT c.id AS club_id, c.name, c.short_name, cs.status, cs.submitted_at, cs.reviewed_at, cs.note,
				( SELECT COUNT(*) FROM {$ps} a WHERE a.season_id = %d AND a.club_id = c.id ) AS players,
				( SELECT COUNT(*) FROM {$ps} b WHERE b.season_id = %d AND b.club_id = c.id AND b.status = 'provisional' ) AS provisional,
				( SELECT COUNT(*) FROM {$team} t WHERE t.season_id = %d AND t.club_id = c.id ) AS teams,
				( SELECT COUNT(*) FROM {$pr} r WHERE r.season_id = %d AND r.club_id = c.id AND r.status = 'requested' ) AS requests
			FROM {$this->db->table( 'club' )} c
			LEFT JOIN {$this->db->table( 'club_season' )} cs ON cs.club_id = c.id AND cs.season_id = %d
			WHERE cs.id IS NOT NULL
				OR EXISTS ( SELECT 1 FROM {$ps} e WHERE e.season_id = %d AND e.club_id = c.id )
				OR EXISTS ( SELECT 1 FROM {$team} f WHERE f.season_id = %d AND f.club_id = c.id )
				OR EXISTS ( SELECT 1 FROM {$pr} g WHERE g.season_id = %d AND g.club_id = c.id )
			ORDER BY c.name, c.id",
			array_fill( 0, 8, $season_id )
		);
		return array_map(
			static fn( array $row ): array => array(
				'club_id'      => (int) $row['club_id'],
				'name'         => (string) $row['name'],
				'short_name'   => (string) $row['short_name'],
				'status'       => $row['status'],
				'submitted_at' => $row['submitted_at'],
				'reviewed_at'  => $row['reviewed_at'],
				'note'         => $row['note'],
				'players'      => (int) $row['players'],
				'provisional'  => (int) $row['provisional'],
				'teams'        => (int) $row['teams'],
				'requests'     => (int) $row['requests'],
			),
			$rows
		);
	}

	/**
	 * @return array{id:int,person_id:int,license_no:int,first_name:string,last_name:string,name:string,email:string|null}|null
	 */
	public function player( int $player_id ): ?array {
		return $this->player_where( 'p.id = %d', $player_id );
	}

	/**
	 * @return array{id:int,person_id:int,license_no:int,first_name:string,last_name:string,name:string,email:string|null}|null
	 */
	public function player_by_license( int $license_no ): ?array {
		return $this->player_where( 'p.license_no = %d', $license_no );
	}

	/**
	 * Erster Spieler (Lizenz) einer Person, falls sie schon einmal gemeldet war.
	 *
	 * @return array{id:int,person_id:int,license_no:int,first_name:string,last_name:string,name:string,email:string|null}|null
	 */
	public function player_of_person( int $person_id ): ?array {
		return $this->player_where( 'p.person_id = %d', $person_id );
	}

	/**
	 * Meldung eines Spielers in einer Saison, mit dem Club.
	 *
	 * @return array{id:int,player_id:int,season_id:int,club_id:int,club:string,classification:int,status:string,registered_at:string|null,registered_by:int|null,approved_by:int|null,approved_at:string|null}|null
	 */
	public function player_season( int $season_id, int $player_id ): ?array {
		$row = $this->db->get_row(
			"SELECT ps.id, ps.player_id, ps.season_id, ps.club_id, c.short_name AS club, ps.classification, ps.status, ps.registered_at, ps.registered_by, ps.approved_by, ps.approved_at
			FROM {$this->db->table( 'player_season' )} ps
			JOIN {$this->db->table( 'club' )} c ON c.id = ps.club_id
			WHERE ps.season_id = %d AND ps.player_id = %d",
			array( $season_id, $player_id )
		);
		return null === $row ? null : array(
			'id'             => (int) $row['id'],
			'player_id'      => (int) $row['player_id'],
			'season_id'      => (int) $row['season_id'],
			'club_id'        => (int) $row['club_id'],
			'club'           => (string) $row['club'],
			'classification' => (int) $row['classification'],
			'status'         => (string) $row['status'],
			'registered_at'  => $row['registered_at'],
			'registered_by'  => self::int_or_null( $row['registered_by'] ),
			'approved_by'    => self::int_or_null( $row['approved_by'] ),
			'approved_at'    => $row['approved_at'],
		);
	}

	/**
	 * Für den Club in der Saison gemeldete Spieler, nach Name.
	 *
	 * @return list<array{player_id:int,person_id:int,license_no:int,name:string,email:string|null,classification:int,status:string}>
	 */
	public function players( int $season_id, int $club_id ): array {
		$rows = $this->db->get_results(
			"SELECT p.id AS player_id, p.person_id, p.license_no, pe.first_name, pe.last_name, pe.email, ps.classification, ps.status
			FROM {$this->db->table( 'player_season' )} ps
			JOIN {$this->db->table( 'player' )} p ON p.id = ps.player_id
			JOIN {$this->db->table( 'person' )} pe ON pe.id = p.person_id
			WHERE ps.season_id = %d AND ps.club_id = %d
			ORDER BY pe.last_name, pe.first_name, p.id",
			array( $season_id, $club_id )
		);
		return array_map(
			static fn( array $row ): array => array(
				'player_id'      => (int) $row['player_id'],
				'person_id'      => (int) $row['person_id'],
				'license_no'     => (int) $row['license_no'],
				'name'           => self::name( $row ),
				'email'          => $row['email'],
				'classification' => (int) $row['classification'],
				'status'         => (string) $row['status'],
			),
			$rows
		);
	}

	/**
	 * Die Saison vor der angegebenen (ohne geplante): Ihre Spieler sind die «Vorjahresspieler».
	 *
	 * @param array{id:int,start_date:string} $season Saison.
	 * @return array{id:int,name:string,closed:bool}|null
	 */
	public function previous_season( array $season ): ?array {
		$row = $this->db->get_row(
			"SELECT id, name, classification_closed_at FROM {$this->db->table( 'season' )}
			WHERE id <> %d AND status <> 'planned' AND start_date < %s ORDER BY start_date DESC, id DESC LIMIT 1",
			array( $season['id'], $season['start_date'] )
		);
		return null === $row ? null : array(
			'id'     => (int) $row['id'],
			'name'   => (string) $row['name'],
			'closed' => null !== $row['classification_closed_at'],
		);
	}

	/**
	 * Vorjahresspieler des Clubs, die er in dieser Saison noch nicht gemeldet hat. Hat ein anderer
	 * Club den Spieler schon gemeldet, steht dessen Name dabei. Die Klassierung ist die, mit der
	 * der Spieler gemeldet würde (siehe carried_classification()).
	 *
	 * @param array{id:int,start_date:string} $season Saison der Meldung.
	 * @return list<array{player_id:int,license_no:int,name:string,classification:int,other_club:string|null}>
	 */
	public function candidates( array $season, int $club_id ): array {
		$previous = $this->previous_season( $season );
		if ( null === $previous ) {
			return array();
		}
		$rows = $this->db->get_results(
			"SELECT p.id AS player_id, p.license_no, pe.first_name, pe.last_name, ps.classification, cp.decided, cur.club_id AS current_club_id, cc.short_name AS current_club
			FROM {$this->db->table( 'player_season' )} ps
			JOIN {$this->db->table( 'player' )} p ON p.id = ps.player_id
			JOIN {$this->db->table( 'person' )} pe ON pe.id = p.person_id
			LEFT JOIN {$this->db->table( 'classification_proposal' )} cp ON cp.season_id = ps.season_id AND cp.player_id = ps.player_id
			LEFT JOIN {$this->db->table( 'player_season' )} cur ON cur.player_id = ps.player_id AND cur.season_id = %d
			LEFT JOIN {$this->db->table( 'club' )} cc ON cc.id = cur.club_id
			WHERE ps.season_id = %d AND ps.club_id = %d AND ps.status = 'active' AND ( cur.id IS NULL OR cur.club_id <> %d )
			ORDER BY pe.last_name, pe.first_name, p.id",
			array( $season['id'], $previous['id'], $club_id, $club_id )
		);
		return array_map(
			static fn( array $row ): array => array(
				'player_id'      => (int) $row['player_id'],
				'license_no'     => (int) $row['license_no'],
				'name'           => self::name( $row ),
				'classification' => $previous['closed'] && null !== $row['decided'] ? (int) $row['decided'] : (int) $row['classification'],
				'other_club'     => $row['current_club'],
			),
			$rows
		);
	}

	/**
	 * Klassierung, mit der ein bestehender Spieler gemeldet wird: aus der letzten früheren Saison,
	 * in der er gemeldet war. Ist deren Klassierung abgeschlossen (season.classification_closed_at),
	 * gilt der Entscheid der TK (classification_proposal.decided), sonst die Klassierung jener
	 * Saison. Null, wenn der Spieler noch nie gemeldet war.
	 *
	 * @param array{id:int,start_date:string} $season Saison der Meldung.
	 */
	public function carried_classification( int $player_id, array $season ): ?int {
		$row = $this->db->get_row(
			"SELECT ps.classification, s.classification_closed_at, cp.decided
			FROM {$this->db->table( 'player_season' )} ps
			JOIN {$this->db->table( 'season' )} s ON s.id = ps.season_id
			LEFT JOIN {$this->db->table( 'classification_proposal' )} cp ON cp.season_id = ps.season_id AND cp.player_id = ps.player_id
			WHERE ps.player_id = %d AND s.id <> %d AND s.status <> 'planned' AND s.start_date < %s
			ORDER BY s.start_date DESC, s.id DESC LIMIT 1",
			array( $player_id, $season['id'], $season['start_date'] )
		);
		if ( null === $row ) {
			return null;
		}
		return null !== $row['classification_closed_at'] && null !== $row['decided'] ? (int) $row['decided'] : (int) $row['classification'];
	}

	/**
	 * Neumeldungen eines Clubs, neueste zuerst.
	 *
	 * @param string|null $status Nur dieser Status (requested, approved, rejected).
	 * @return list<array<string,mixed>>
	 */
	public function requests( int $season_id, int $club_id, ?string $status = null ): array {
		$sql  = "SELECT * FROM {$this->db->table( 'player_request' )} WHERE season_id = %d AND club_id = %d";
		$args = array( $season_id, $club_id );
		if ( null !== $status ) {
			$sql   .= ' AND status = %s';
			$args[] = $status;
		}
		return array_map( array( self::class, 'request_row' ), $this->db->get_results( $sql . ' ORDER BY id DESC', $args ) );
	}

	/**
	 * @return array<string,mixed>|null
	 */
	public function request( int $request_id ): ?array {
		$row = $this->db->get_row( "SELECT * FROM {$this->db->table( 'player_request' )} WHERE id = %d", array( $request_id ) );
		return null === $row ? null : self::request_row( $row );
	}

	/**
	 * Mannschaften des Clubs mit Stammspielern und Captain, nach Nummer.
	 *
	 * @return list<array{id:int,season_id:int,club_id:int,number:int,name:string,competition_id:int|null,home_venue_id:int|null,start_time:string|null,status:string,players:list<int>,captain:array{person_id:int,name:string,email:string|null}|null}>
	 */
	public function teams( int $season_id, int $club_id ): array {
		return $this->teams_where( 't.season_id = %d AND t.club_id = %d', array( $season_id, $club_id ) );
	}

	/**
	 * @return array{id:int,season_id:int,club_id:int,number:int,name:string,competition_id:int|null,home_venue_id:int|null,start_time:string|null,status:string,players:list<int>,captain:array{person_id:int,name:string,email:string|null}|null}|null
	 */
	public function team( int $team_id ): ?array {
		return $this->teams_where( 't.id = %d', array( $team_id ) )[0] ?? null;
	}

	public function team_has_fixtures( int $team_id ): bool {
		return null !== $this->db->get_var(
			"SELECT id FROM {$this->db->table( 'fixture' )} WHERE home_team_id = %d OR away_team_id = %d LIMIT 1",
			array( $team_id, $team_id )
		);
	}

	/**
	 * Stammspieler einer Mannschaft ersetzen. Bindungen (kind = bound) anderer Spieler bleiben;
	 * ein gebundener Spieler, der Stammspieler wird, behält seine Zeile.
	 *
	 * @param list<int> $player_ids Stammspieler.
	 */
	public function replace_stamm( int $team_id, array $player_ids ): void {
		$this->db->delete(
			'team_roster',
			array(
				'team_id' => $team_id,
				'kind'    => 'stamm',
			)
		);
		foreach ( $player_ids as $player_id ) {
			$existing = $this->db->get_var( "SELECT id FROM {$this->db->table( 'team_roster' )} WHERE team_id = %d AND player_id = %d", array( $team_id, $player_id ) );
			if ( null !== $existing ) {
				$this->db->update(
					'team_roster',
					array(
						'kind'       => 'stamm',
						'valid_from' => null,
					),
					array( 'id' => (int) $existing )
				);
				continue;
			}
			$this->db->insert(
				'team_roster',
				array(
					'team_id'   => $team_id,
					'player_id' => $player_id,
					'kind'      => 'stamm',
				)
			);
		}
	}

	/**
	 * Captain einer Mannschaft setzen (genau einer) oder entfernen.
	 */
	public function replace_captain( int $season_id, int $club_id, int $team_id, ?int $person_id ): void {
		$this->db->delete(
			'club_role',
			array(
				'season_id' => $season_id,
				'team_id'   => $team_id,
				'role'      => 'captain',
			)
		);
		if ( null !== $person_id ) {
			$this->db->insert(
				'club_role',
				array(
					'season_id' => $season_id,
					'club_id'   => $club_id,
					'person_id' => $person_id,
					'role'      => 'captain',
					'team_id'   => $team_id,
				)
			);
		}
	}

	/**
	 * @return array{id:int,first_name:string,last_name:string,name:string,email:string|null,user_id:int|null}|null
	 */
	public function person( int $person_id ): ?array {
		$row = $this->db->get_row( "SELECT id, first_name, last_name, email, user_id FROM {$this->db->table( 'person' )} WHERE id = %d", array( $person_id ) );
		return null === $row ? null : array(
			'id'         => (int) $row['id'],
			'first_name' => (string) $row['first_name'],
			'last_name'  => (string) $row['last_name'],
			'name'       => self::name( $row ),
			'email'      => $row['email'],
			'user_id'    => self::int_or_null( $row['user_id'] ),
		);
	}

	/**
	 * Personen, die Captain einer Mannschaft des Clubs werden können: für den Club in der Saison
	 * gemeldete Spieler und Personen mit einer Funktion im Club (in dieser oder der aktiven Saison).
	 *
	 * @return list<array{person_id:int,name:string,email:string|null}>
	 */
	public function captain_candidates( int $season_id, int $club_id, ?int $active_season_id ): array {
		$rows = $this->db->get_results(
			"SELECT pe.id, pe.first_name, pe.last_name, pe.email FROM {$this->db->table( 'person' )} pe
			WHERE pe.status = 'active' AND (
				pe.id IN ( SELECT p.person_id FROM {$this->db->table( 'player_season' )} ps JOIN {$this->db->table( 'player' )} p ON p.id = ps.player_id WHERE ps.season_id = %d AND ps.club_id = %d )
				OR pe.id IN ( SELECT cr.person_id FROM {$this->db->table( 'club_role' )} cr WHERE cr.club_id = %d AND cr.season_id IN ( %d, %d ) )
			)
			ORDER BY pe.last_name, pe.first_name, pe.id",
			array( $season_id, $club_id, $club_id, $season_id, $active_season_id ?? $season_id )
		);
		return array_map(
			static fn( array $row ): array => array(
				'person_id' => (int) $row['id'],
				'name'      => self::name( $row ),
				'email'     => $row['email'],
			),
			$rows
		);
	}

	/**
	 * TK-Chefs eines Clubs in einer Saison (Personen-IDs).
	 *
	 * @return list<int>
	 */
	public function tk_chefs( int $season_id, int $club_id ): array {
		return array_map(
			'intval',
			$this->db->get_col(
				"SELECT person_id FROM {$this->db->table( 'club_role' )} WHERE season_id = %d AND club_id = %d AND role = 'tk_chef' ORDER BY id",
				array( $season_id, $club_id )
			)
		);
	}

	/**
	 * Lokale eines Clubs mit ihren Hallensperren ab dem angegebenen Tag, aktive zuerst.
	 *
	 * @param string|null $closures_from Sperren, die an diesem Tag oder später enden; null für alle.
	 * @return list<array{id:int,club_id:int,name:string,street:string|null,zip:string|null,city:string|null,phone:string|null,weekday:int|null,open_time:string|null,close_time:string|null,tables_count:int|null,active:bool,closures:list<array{id:int,venue_id:int,date_from:string,date_to:string,reason:string|null}>}>
	 */
	public function venues( int $club_id, ?string $closures_from = null ): array {
		$venues = array();
		foreach ( $this->db->get_results(
			"SELECT id, club_id, name, street, zip, city, phone, weekday, open_time, close_time, tables_count, active
			FROM {$this->db->table( 'venue' )} WHERE club_id = %d ORDER BY active DESC, name, id",
			array( $club_id )
		) as $row ) {
			$venues[ (int) $row['id'] ] = self::venue_row( $row ) + array( 'closures' => array() );
		}
		if ( array() === $venues ) {
			return array();
		}
		$sql  = "SELECT id, venue_id, date_from, date_to, reason FROM {$this->db->table( 'venue_closure' )} WHERE venue_id IN (" . implode( ',', array_keys( $venues ) ) . ')'; // Nur Ganzzahlen.
		$args = array();
		if ( null !== $closures_from ) {
			$sql   .= ' AND date_to >= %s';
			$args[] = $closures_from;
		}
		foreach ( $this->db->get_results( $sql . ' ORDER BY date_from, id', $args ) as $row ) {
			$venues[ (int) $row['venue_id'] ]['closures'][] = self::closure_row( $row );
		}
		return array_values( $venues );
	}

	/**
	 * @return array{id:int,club_id:int,name:string,street:string|null,zip:string|null,city:string|null,phone:string|null,weekday:int|null,open_time:string|null,close_time:string|null,tables_count:int|null,active:bool}|null
	 */
	public function venue( int $venue_id ): ?array {
		$row = $this->db->get_row(
			"SELECT id, club_id, name, street, zip, city, phone, weekday, open_time, close_time, tables_count, active FROM {$this->db->table( 'venue' )} WHERE id = %d",
			array( $venue_id )
		);
		return null === $row ? null : self::venue_row( $row );
	}

	/**
	 * Hallensperre mit dem Club ihres Lokals.
	 *
	 * @return array{id:int,venue_id:int,club_id:int,date_from:string,date_to:string,reason:string|null}|null
	 */
	public function closure( int $closure_id ): ?array {
		$row = $this->db->get_row(
			"SELECT vc.id, vc.venue_id, v.club_id, vc.date_from, vc.date_to, vc.reason
			FROM {$this->db->table( 'venue_closure' )} vc JOIN {$this->db->table( 'venue' )} v ON v.id = vc.venue_id WHERE vc.id = %d",
			array( $closure_id )
		);
		return null === $row ? null : self::closure_row( $row ) + array( 'club_id' => (int) $row['club_id'] );
	}

	/**
	 * Ist das Lokal Heimlokal einer Mannschaft in einer dieser Saisons?
	 *
	 * @param list<int> $season_ids Saisons.
	 */
	public function venue_in_use( int $venue_id, array $season_ids ): bool {
		$season_ids = array_values( array_filter( array_map( 'intval', $season_ids ) ) );
		if ( array() === $season_ids ) {
			return false;
		}
		return null !== $this->db->get_var(
			"SELECT id FROM {$this->db->table( 'team' )} WHERE home_venue_id = %d AND season_id IN (" . implode( ',', $season_ids ) . ') LIMIT 1', // Nur Ganzzahlen.
			array( $venue_id )
		);
	}

	/**
	 * Nächste freie Lizenznummer als Vorschlag für die TK.
	 */
	public function next_license(): int {
		return 1 + (int) $this->db->get_var( "SELECT MAX(license_no) FROM {$this->db->table( 'player' )}" );
	}

	/**
	 * Personen mit gleichem Namen oder gleicher E-Mail-Adresse wie die Neumeldung, mit ihrer
	 * Lizenznummer, falls sie schon Spieler sind.
	 *
	 * @return list<array{person_id:int,name:string,email:string|null,player_id:int|null,license_no:int|null,same_email:bool}>
	 */
	public function similar_persons( string $last_name, string $first_name, ?string $email ): array {
		$email = null === $email ? '' : trim( $email );
		$rows  = $this->db->get_results(
			"SELECT pe.id, pe.first_name, pe.last_name, pe.email, p.id AS player_id, p.license_no
			FROM {$this->db->table( 'person' )} pe
			LEFT JOIN {$this->db->table( 'player' )} p ON p.person_id = pe.id
			WHERE ( LOWER(pe.last_name) = LOWER(%s) AND LOWER(pe.first_name) = LOWER(%s) ) OR ( %s <> '' AND LOWER(pe.email) = LOWER(%s) )
			ORDER BY pe.id, p.id",
			array( trim( $last_name ), trim( $first_name ), $email, $email )
		);
		$list  = array();
		foreach ( $rows as $row ) {
			$id = (int) $row['id'];
			if ( isset( $list[ $id ] ) ) {
				continue;
			}
			$list[ $id ] = array(
				'person_id'  => $id,
				'name'       => self::name( $row ),
				'email'      => $row['email'],
				'player_id'  => self::int_or_null( $row['player_id'] ),
				'license_no' => self::int_or_null( $row['license_no'] ),
				'same_email' => '' !== $email && null !== $row['email'] && 0 === strcasecmp( $email, (string) $row['email'] ),
			);
		}
		return array_values( $list );
	}

	/**
	 * Person mit dieser E-Mail-Adresse (person.email ist eindeutig).
	 */
	public function person_by_email( string $email ): ?int {
		$id = $this->db->get_var( "SELECT id FROM {$this->db->table( 'person' )} WHERE LOWER(email) = LOWER(%s) LIMIT 1", array( trim( $email ) ) );
		return null === $id ? null : (int) $id;
	}

	/**
	 * Provisorisch gemeldete Spieler einer Saison (Nachmeldungen, die die TK freigibt), nach Club.
	 *
	 * @return list<array{player_id:int,club_id:int,club:string,name:string,license_no:int,classification:int,registered_at:string|null}>
	 */
	public function provisional_players( int $season_id ): array {
		$rows = $this->db->get_results(
			"SELECT ps.player_id, ps.club_id, c.short_name AS club, pe.first_name, pe.last_name, p.license_no, ps.classification, ps.registered_at
			FROM {$this->db->table( 'player_season' )} ps
			JOIN {$this->db->table( 'player' )} p ON p.id = ps.player_id
			JOIN {$this->db->table( 'person' )} pe ON pe.id = p.person_id
			JOIN {$this->db->table( 'club' )} c ON c.id = ps.club_id
			WHERE ps.season_id = %d AND ps.status = 'provisional'
			ORDER BY c.name, pe.last_name, pe.first_name, ps.id",
			array( $season_id )
		);
		return array_map(
			static fn( array $row ): array => array(
				'player_id'      => (int) $row['player_id'],
				'club_id'        => (int) $row['club_id'],
				'club'           => (string) $row['club'],
				'name'           => self::name( $row ),
				'license_no'     => (int) $row['license_no'],
				'classification' => (int) $row['classification'],
				'registered_at'  => $row['registered_at'],
			),
			$rows
		);
	}

	/**
	 * Offene Neumeldungen einer Saison mit dem Club, älteste zuerst.
	 *
	 * @return list<array<string,mixed>>
	 */
	public function open_requests( int $season_id ): array {
		$rows = $this->db->get_results(
			"SELECT r.*, c.short_name AS club FROM {$this->db->table( 'player_request' )} r JOIN {$this->db->table( 'club' )} c ON c.id = r.club_id
			WHERE r.season_id = %d AND r.status = 'requested' ORDER BY r.id",
			array( $season_id )
		);
		return array_map( static fn( array $row ): array => self::request_row( $row ) + array( 'club' => (string) $row['club'] ), $rows );
	}

	/**
	 * Änderungen an Lokalen und Hallensperren seit dem Zeitpunkt (UTC), neueste zuerst, für die
	 * Übersicht der TK. Der Club steht im Protokolleintrag (vorher oder nachher).
	 *
	 * @return list<array{entity:string,action:string,created_at:string,user_id:int|null,club_id:int|null,club:string|null,venue_id:int|null,venue:string|null,data:array<string,mixed>}>
	 */
	public function venue_changes( string $since, int $limit = 50 ): array {
		$rows    = $this->db->get_results(
			"SELECT entity, entity_id, action, user_id, created_at, before_json, after_json FROM {$this->db->table( 'audit_log' )}
			WHERE entity IN ('venue', 'venue_closure') AND created_at >= %s ORDER BY id DESC LIMIT %d",
			array( $since, $limit )
		);
		$changes = array();
		foreach ( $rows as $row ) {
			$after     = json_decode( (string) $row['after_json'], true );
			$before    = json_decode( (string) $row['before_json'], true );
			$data      = is_array( $after ) ? $after : ( is_array( $before ) ? $before : array() );
			$club_id   = isset( $data['club_id'] ) ? (int) $data['club_id'] : null;
			$venue_id  = 'venue' === $row['entity'] ? (int) $row['entity_id'] : ( isset( $data['venue_id'] ) ? (int) $data['venue_id'] : null );
			$venue     = null === $venue_id ? null : $this->venue( $venue_id );
			$changes[] = array(
				'entity'     => (string) $row['entity'],
				'action'     => (string) $row['action'],
				'created_at' => (string) $row['created_at'],
				'user_id'    => self::int_or_null( $row['user_id'] ),
				'club_id'    => $club_id,
				'club'       => null === $club_id ? null : ( $this->club( $club_id )['short_name'] ?? null ),
				'venue_id'   => $venue_id,
				'venue'      => null === $venue ? null : $venue['name'],
				'data'       => $data,
			);
		}
		return $changes;
	}

	/**
	 * Gemeldete Spieler einer Saison mit Club und Stammmannschaft, für den Export.
	 *
	 * @param int|null $club_id Nur dieser Club.
	 * @return list<array{club:string,license_no:int,last_name:string,first_name:string,classification:int,status:string,team:string|null}>
	 */
	public function export_players( int $season_id, ?int $club_id = null ): array {
		$sql  = "SELECT c.name AS club, p.license_no, pe.last_name, pe.first_name, ps.classification, ps.status,
				( SELECT t.name FROM {$this->db->table( 'team_roster' )} r JOIN {$this->db->table( 'team' )} t ON t.id = r.team_id
					WHERE r.player_id = ps.player_id AND r.kind = 'stamm' AND t.season_id = ps.season_id ORDER BY t.number LIMIT 1 ) AS team
			FROM {$this->db->table( 'player_season' )} ps
			JOIN {$this->db->table( 'player' )} p ON p.id = ps.player_id
			JOIN {$this->db->table( 'person' )} pe ON pe.id = p.person_id
			JOIN {$this->db->table( 'club' )} c ON c.id = ps.club_id
			WHERE ps.season_id = %d";
		$args = array( $season_id );
		if ( null !== $club_id ) {
			$sql   .= ' AND ps.club_id = %d';
			$args[] = $club_id;
		}
		return array_map(
			static fn( array $row ): array => array(
				'club'           => (string) $row['club'],
				'license_no'     => (int) $row['license_no'],
				'last_name'      => (string) $row['last_name'],
				'first_name'     => (string) $row['first_name'],
				'classification' => (int) $row['classification'],
				'status'         => (string) $row['status'],
				'team'           => $row['team'],
			),
			$this->db->get_results( $sql . ' ORDER BY c.name, pe.last_name, pe.first_name, p.id', $args )
		);
	}

	/**
	 * @param array<string,string|null> $row Zeile mit first_name und last_name.
	 */
	public static function name( array $row ): string {
		return trim( trim( (string) ( $row['first_name'] ?? '' ) ) . ' ' . trim( (string) ( $row['last_name'] ?? '' ) ) );
	}

	/**
	 * @param list<int|string> $args Werte der Bedingung.
	 * @return list<array{id:int,season_id:int,club_id:int,number:int,name:string,competition_id:int|null,home_venue_id:int|null,start_time:string|null,status:string,players:list<int>,captain:array{person_id:int,name:string,email:string|null}|null}>
	 */
	private function teams_where( string $where, array $args ): array {
		$teams = array();
		foreach ( $this->db->get_results(
			"SELECT t.id, t.season_id, t.club_id, t.number, t.name, t.competition_id, t.home_venue_id, t.default_start_time, t.status
			FROM {$this->db->table( 'team' )} t WHERE {$where} ORDER BY t.number, t.id",
			$args
		) as $row ) {
			$teams[ (int) $row['id'] ] = array(
				'id'             => (int) $row['id'],
				'season_id'      => (int) $row['season_id'],
				'club_id'        => (int) $row['club_id'],
				'number'         => (int) $row['number'],
				'name'           => (string) $row['name'],
				'competition_id' => self::int_or_null( $row['competition_id'] ),
				'home_venue_id'  => self::int_or_null( $row['home_venue_id'] ),
				'start_time'     => $row['default_start_time'],
				'status'         => (string) $row['status'],
				'players'        => array(),
				'captain'        => null,
			);
		}
		if ( array() === $teams ) {
			return array();
		}
		$ids = implode( ',', array_keys( $teams ) ); // Nur Ganzzahlen.
		foreach ( $this->db->get_results( "SELECT team_id, player_id FROM {$this->db->table( 'team_roster' )} WHERE team_id IN ({$ids}) AND kind = 'stamm' ORDER BY id" ) as $row ) {
			$teams[ (int) $row['team_id'] ]['players'][] = (int) $row['player_id'];
		}
		foreach ( $this->db->get_results(
			"SELECT cr.team_id, pe.id, pe.first_name, pe.last_name, pe.email
			FROM {$this->db->table( 'club_role' )} cr JOIN {$this->db->table( 'person' )} pe ON pe.id = cr.person_id
			WHERE cr.role = 'captain' AND cr.team_id IN ({$ids}) ORDER BY cr.id"
		) as $row ) {
			$teams[ (int) $row['team_id'] ]['captain'] ??= array(
				'person_id' => (int) $row['id'],
				'name'      => self::name( $row ),
				'email'     => $row['email'],
			);
		}
		return array_values( $teams );
	}

	/**
	 * @return array{id:int,person_id:int,license_no:int,first_name:string,last_name:string,name:string,email:string|null}|null
	 */
	private function player_where( string $where, int $value ): ?array {
		$row = $this->db->get_row(
			"SELECT p.id, p.person_id, p.license_no, pe.first_name, pe.last_name, pe.email
			FROM {$this->db->table( 'player' )} p JOIN {$this->db->table( 'person' )} pe ON pe.id = p.person_id
			WHERE {$where} ORDER BY p.id LIMIT 1",
			array( $value )
		);
		return null === $row ? null : array(
			'id'         => (int) $row['id'],
			'person_id'  => (int) $row['person_id'],
			'license_no' => (int) $row['license_no'],
			'first_name' => (string) $row['first_name'],
			'last_name'  => (string) $row['last_name'],
			'name'       => self::name( $row ),
			'email'      => $row['email'],
		);
	}

	/**
	 * @param array<string,string|null> $row Zeile aus player_request.
	 * @return array<string,mixed>
	 */
	private static function request_row( array $row ): array {
		return array(
			'id'             => (int) $row['id'],
			'season_id'      => (int) $row['season_id'],
			'club_id'        => (int) $row['club_id'],
			'last_name'      => (string) $row['last_name'],
			'first_name'     => (string) $row['first_name'],
			'name'           => self::name( $row ),
			'email'          => $row['email'],
			'classification' => self::int_or_null( $row['classification'] ),
			'stt_license'    => $row['stt_license'],
			'status'         => (string) $row['status'],
			'player_id'      => self::int_or_null( $row['player_id'] ),
			'requested_by'   => (int) $row['requested_by'],
			'requested_at'   => (string) $row['requested_at'],
			'decided_by'     => self::int_or_null( $row['decided_by'] ),
			'decided_at'     => $row['decided_at'],
			'note'           => $row['note'],
		);
	}

	/**
	 * @param array<string,string|null> $row Zeile aus venue.
	 * @return array{id:int,club_id:int,name:string,street:string|null,zip:string|null,city:string|null,phone:string|null,weekday:int|null,open_time:string|null,close_time:string|null,tables_count:int|null,active:bool}
	 */
	private static function venue_row( array $row ): array {
		return array(
			'id'           => (int) $row['id'],
			'club_id'      => (int) $row['club_id'],
			'name'         => (string) $row['name'],
			'street'       => $row['street'],
			'zip'          => $row['zip'],
			'city'         => $row['city'],
			'phone'        => $row['phone'],
			'weekday'      => self::int_or_null( $row['weekday'] ),
			'open_time'    => $row['open_time'],
			'close_time'   => $row['close_time'],
			'tables_count' => self::int_or_null( $row['tables_count'] ),
			'active'       => '1' === (string) $row['active'],
		);
	}

	/**
	 * @param array<string,string|null> $row Zeile aus venue_closure.
	 * @return array{id:int,venue_id:int,date_from:string,date_to:string,reason:string|null}
	 */
	private static function closure_row( array $row ): array {
		return array(
			'id'        => (int) $row['id'],
			'venue_id'  => (int) $row['venue_id'],
			'date_from' => (string) $row['date_from'],
			'date_to'   => (string) $row['date_to'],
			'reason'    => $row['reason'],
		);
	}

	private static function int_or_null( ?string $value ): ?int {
		return null === $value ? null : (int) $value;
	}
}
