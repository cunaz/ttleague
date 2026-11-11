<?php
/**
 * Datenzugriff des Ranglistenturniers.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\RankingTournament;

use TTLeague\Domain\Rules\SeasonRules;
use TTLeague\Infrastructure\Database\Db;

/**
 * Liest ohne Cache, auch Runden mit Status «planned». Die öffentliche Darstellung verwendet
 * ReadModel\RankingTournament.
 */
final class Repository {

	public function __construct( private readonly Db $db ) {}

	/**
	 * Konkurrenz «Ranglistenturnier» einer Saison.
	 *
	 * @return array{id:int,season_id:int,season_status:string,name:string,format:array<string,mixed>,categories:list<array{no:int,min:int}>,deadline:string|null,start_time:string|null,rules:array<string,mixed>}|null
	 */
	public function competition_of_season( int $season_id ): ?array {
		return $this->competition_where( "c.season_id = %d AND c.type = 'RL' ORDER BY c.id LIMIT 1", $season_id );
	}

	/**
	 * @return array{id:int,season_id:int,season_status:string,name:string,format:array<string,mixed>,categories:list<array{no:int,min:int}>,deadline:string|null,start_time:string|null,rules:array<string,mixed>}|null
	 */
	public function competition( int $competition_id ): ?array {
		return $this->competition_where( "c.id = %d AND c.type = 'RL'", $competition_id );
	}

	/**
	 * Aktive Saison (ID) oder null.
	 */
	public function active_season_id(): ?int {
		$id = $this->db->get_var( "SELECT id FROM {$this->db->table( 'season' )} WHERE status = 'active' ORDER BY start_date DESC, id DESC LIMIT 1" );
		return null === $id ? null : (int) $id;
	}

	/**
	 * Regeln einer Konkurrenz; ungültige Regeln der Saison sind ein Fehler.
	 *
	 * @param array{rules:array<string,mixed>} $competition Konkurrenz.
	 * @throws \InvalidArgumentException Wenn rules_json ungültige Werte enthält.
	 */
	public static function rules( array $competition ): SeasonRules {
		return SeasonRules::from_array( $competition['rules'] );
	}

	/**
	 * Spieler mit Meldung in der Saison.
	 *
	 * @return array{player_id:int,person_id:int,club_id:int,club_short:string,classification:int,status:string,name:string}|null
	 */
	public function player( int $player_id, int $season_id ): ?array {
		$row = $this->db->get_row(
			"SELECT p.id AS player_id, p.person_id, ps.club_id, ps.classification, ps.status, pe.first_name, pe.last_name, c.short_name AS club_short
			FROM {$this->db->table( 'player' )} p
			JOIN {$this->db->table( 'person' )} pe ON pe.id = p.person_id
			JOIN {$this->db->table( 'player_season' )} ps ON ps.player_id = p.id AND ps.season_id = %d
			LEFT JOIN {$this->db->table( 'club' )} c ON c.id = ps.club_id
			WHERE p.id = %d",
			array( $season_id, $player_id )
		);
		if ( null === $row ) {
			return null;
		}
		return array(
			'player_id'      => (int) $row['player_id'],
			'person_id'      => (int) $row['person_id'],
			'club_id'        => (int) $row['club_id'],
			'club_short'     => (string) $row['club_short'],
			'classification' => (int) $row['classification'],
			'status'         => (string) $row['status'],
			'name'           => self::name( $row ),
		);
	}

	/**
	 * Spieler-IDs einer Person mit Meldung in der Saison (in der Regel eine).
	 *
	 * @return list<int>
	 */
	public function players_of_person( int $person_id, int $season_id ): array {
		return array_map(
			'intval',
			$this->db->get_col(
				"SELECT p.id FROM {$this->db->table( 'player' )} p
				JOIN {$this->db->table( 'player_season' )} ps ON ps.player_id = p.id AND ps.season_id = %d
				WHERE p.person_id = %d AND ps.status = 'active' ORDER BY p.id",
				array( $season_id, $person_id )
			)
		);
	}

	/**
	 * Gemeldete Spieler der Saison, auf Wunsch eines Clubs, mit ihrer Anmeldung zum Turnier.
	 *
	 * @return list<array{player_id:int,person_id:int,club_id:int,club_short:string,classification:int,name:string,participant:array{id:int,category:int,status:string}|null}>
	 */
	public function season_players( int $season_id, int $competition_id, ?int $club_id = null ): array {
		$where = null === $club_id ? '' : ' AND ps.club_id = %d';
		$args  = null === $club_id ? array( $competition_id, $season_id ) : array( $competition_id, $season_id, $club_id );
		$rows  = array();
		foreach ( $this->db->get_results(
			"SELECT p.id AS player_id, p.person_id, ps.club_id, ps.classification, pe.first_name, pe.last_name, c.short_name AS club_short,
				rp.id AS participant_id, rp.category, rp.status AS participant_status
			FROM {$this->db->table( 'player_season' )} ps
			JOIN {$this->db->table( 'player' )} p ON p.id = ps.player_id
			JOIN {$this->db->table( 'person' )} pe ON pe.id = p.person_id
			LEFT JOIN {$this->db->table( 'club' )} c ON c.id = ps.club_id
			LEFT JOIN {$this->db->table( 'rl_participant' )} rp ON rp.player_id = p.id AND rp.competition_id = %d
			WHERE ps.season_id = %d AND ps.status = 'active'{$where}
			ORDER BY c.short_name, pe.last_name, pe.first_name, p.id",
			$args
		) as $row ) {
			$rows[] = array(
				'player_id'      => (int) $row['player_id'],
				'person_id'      => (int) $row['person_id'],
				'club_id'        => (int) $row['club_id'],
				'club_short'     => (string) $row['club_short'],
				'classification' => (int) $row['classification'],
				'name'           => self::name( $row ),
				'participant'    => null === $row['participant_id'] ? null : array(
					'id'       => (int) $row['participant_id'],
					'category' => (int) $row['category'],
					'status'   => (string) $row['participant_status'],
				),
			);
		}
		return $rows;
	}

	/**
	 * @return array{id:int,competition_id:int,player_id:int,category:int,status:string,registered_by:int|null,registered_at:string|null}|null
	 */
	public function participant( int $competition_id, int $player_id ): ?array {
		$row = $this->db->get_row(
			"SELECT id, competition_id, player_id, category, status, registered_by, registered_at FROM {$this->db->table( 'rl_participant' )} WHERE competition_id = %d AND player_id = %d",
			array( $competition_id, $player_id )
		);
		if ( null === $row ) {
			return null;
		}
		return array(
			'id'             => (int) $row['id'],
			'competition_id' => (int) $row['competition_id'],
			'player_id'      => (int) $row['player_id'],
			'category'       => (int) $row['category'],
			'status'         => (string) $row['status'],
			'registered_by'  => self::int_or_null( $row['registered_by'] ),
			'registered_at'  => $row['registered_at'],
		);
	}

	/**
	 * Teilnahmen einer Konkurrenz mit Person, Club und Klassierung: stärkste Klassierung zuerst,
	 * dann nach Name. Diese Reihenfolge gilt als «erstgenannt».
	 *
	 * @return list<array{id:int,player_id:int,person_id:int,category:int,status:string,registered_by:int|null,registered_at:string|null,name:string,first_name:string,last_name:string,club_id:int|null,club_short:string|null,classification:int|null,license_no:int}>
	 */
	public function participants( int $competition_id, int $season_id, bool $only_registered = false ): array {
		$rows = array();
		foreach ( $this->db->get_results(
			"SELECT rp.id, rp.player_id, rp.category, rp.status, rp.registered_by, rp.registered_at, p.person_id, p.license_no,
				pe.first_name, pe.last_name, ps.club_id, ps.classification, c.short_name AS club_short
			FROM {$this->db->table( 'rl_participant' )} rp
			JOIN {$this->db->table( 'player' )} p ON p.id = rp.player_id
			JOIN {$this->db->table( 'person' )} pe ON pe.id = p.person_id
			LEFT JOIN {$this->db->table( 'player_season' )} ps ON ps.player_id = rp.player_id AND ps.season_id = %d
			LEFT JOIN {$this->db->table( 'club' )} c ON c.id = ps.club_id
			WHERE rp.competition_id = %d" . ( $only_registered ? " AND rp.status = 'registered'" : '' ) . '
			ORDER BY rp.category, ps.classification DESC, pe.last_name, pe.first_name, rp.player_id',
			array( $season_id, $competition_id )
		) as $row ) {
			$rows[] = array(
				'id'             => (int) $row['id'],
				'player_id'      => (int) $row['player_id'],
				'person_id'      => (int) $row['person_id'],
				'category'       => (int) $row['category'],
				'status'         => (string) $row['status'],
				'registered_by'  => self::int_or_null( $row['registered_by'] ),
				'registered_at'  => $row['registered_at'],
				'name'           => self::name( $row ),
				'first_name'     => (string) $row['first_name'],
				'last_name'      => (string) $row['last_name'],
				'club_id'        => self::int_or_null( $row['club_id'] ),
				'club_short'     => $row['club_short'],
				'classification' => self::int_or_null( $row['classification'] ),
				'license_no'     => (int) $row['license_no'],
			);
		}
		return $rows;
	}

	/**
	 * Runden einer Konkurrenz nach Nummer.
	 *
	 * @return list<array{id:int,competition_id:int,round_no:int,date_from:string|null,date_to:string|null,is_final:bool,status:string,closed_by:int|null,closed_at:string|null}>
	 */
	public function rounds( int $competition_id ): array {
		return array_map(
			array( self::class, 'round_row' ),
			$this->db->get_results(
				"SELECT id, competition_id, round_no, date_from, date_to, is_final, status, closed_by, closed_at FROM {$this->db->table( 'rl_round' )} WHERE competition_id = %d ORDER BY round_no",
				array( $competition_id )
			)
		);
	}

	/**
	 * @return array{id:int,competition_id:int,round_no:int,date_from:string|null,date_to:string|null,is_final:bool,status:string,closed_by:int|null,closed_at:string|null}|null
	 */
	public function round( int $round_id ): ?array {
		$row = $this->db->get_row(
			"SELECT id, competition_id, round_no, date_from, date_to, is_final, status, closed_by, closed_at FROM {$this->db->table( 'rl_round' )} WHERE id = %d",
			array( $round_id )
		);
		return null === $row ? null : self::round_row( $row );
	}

	/**
	 * Sperrt Saison und Runde bis zum Ende der Transaktion, immer in dieser Reihenfolge (wie
	 * die Resultaterfassung: zuerst die Saison). Muss die erste Abfrage der Transaktion sein.
	 */
	public function lock_round( int $season_id, int $round_id ): void {
		$this->db->get_var( "SELECT id FROM {$this->db->table( 'season' )} WHERE id = %d FOR UPDATE", array( $season_id ) );
		$this->db->get_var( "SELECT id FROM {$this->db->table( 'rl_round' )} WHERE id = %d FOR UPDATE", array( $round_id ) );
	}

	/**
	 * Gruppen einer Runde mit Mitgliedern, nach Kategorie und Nummer.
	 *
	 * @return list<array<string,mixed>> Siehe group().
	 */
	public function groups( int $round_id ): array {
		$groups = array();
		foreach ( $this->db->get_col( "SELECT id FROM {$this->db->table( 'rl_group' )} WHERE rl_round_id = %d ORDER BY category, group_no, id", array( $round_id ) ) as $id ) {
			$group = $this->group( (int) $id );
			if ( null !== $group ) {
				$groups[] = $group;
			}
		}
		return $groups;
	}

	/**
	 * Gruppe mit Runde, Saison, Lokal, Mitgliedern (Reihenfolge der Einteilung) und Partien.
	 *
	 * @return array{id:int,round_id:int,category:int,group_no:int,host_club_id:int|null,venue_id:int|null,start_at:string|null,chief_player_id:int|null,entered_by:int|null,entered_at:string|null,note:string|null,round_no:int,is_final:bool,round_status:string,date_from:string|null,date_to:string|null,competition_id:int,season_id:int,season_status:string,rules:array<string,mixed>,host:array{id:int,name:string,short:string}|null,venue:array{id:int,name:string,street:string|null,zip:string|null,city:string|null}|null,members:list<array{id:int,player_id:int,person_id:int,name:string,first_name:string,last_name:string,club_id:int|null,club_short:string|null,classification:int|null,status:string,rank_no:int|null,points:float|null}>,games:list<array{id:int,sequence:int,a:int,b:int,sets_a:int,sets_b:int,counts:bool,set_points:list<array{0:int,1:int}>}>}|null
	 */
	public function group( int $group_id ): ?array {
		$row = $this->db->get_row(
			"SELECT g.id, g.rl_round_id, g.category, g.group_no, g.host_club_id, g.venue_id, g.start_at, g.chief_player_id, g.entered_by, g.entered_at, g.note,
				r.round_no, r.is_final, r.status AS round_status, r.date_from, r.date_to, r.competition_id, co.season_id, s.status AS season_status, s.rules_json,
				c.name AS host_name, c.short_name AS host_short, v.name AS venue_name, v.street, v.zip, v.city
			FROM {$this->db->table( 'rl_group' )} g
			JOIN {$this->db->table( 'rl_round' )} r ON r.id = g.rl_round_id
			JOIN {$this->db->table( 'competition' )} co ON co.id = r.competition_id
			JOIN {$this->db->table( 'season' )} s ON s.id = co.season_id
			LEFT JOIN {$this->db->table( 'club' )} c ON c.id = g.host_club_id
			LEFT JOIN {$this->db->table( 'venue' )} v ON v.id = g.venue_id
			WHERE g.id = %d",
			array( $group_id )
		);
		if ( null === $row ) {
			return null;
		}
		$season_id = (int) $row['season_id'];
		$members   = array();
		foreach ( $this->db->get_results(
			"SELECT gp.id, gp.player_id, gp.status, gp.rank_no, gp.points, p.person_id, pe.first_name, pe.last_name, ps.club_id, ps.classification, c.short_name AS club_short
			FROM {$this->db->table( 'rl_group_player' )} gp
			JOIN {$this->db->table( 'player' )} p ON p.id = gp.player_id
			JOIN {$this->db->table( 'person' )} pe ON pe.id = p.person_id
			LEFT JOIN {$this->db->table( 'player_season' )} ps ON ps.player_id = gp.player_id AND ps.season_id = %d
			LEFT JOIN {$this->db->table( 'club' )} c ON c.id = ps.club_id
			WHERE gp.rl_group_id = %d ORDER BY gp.id",
			array( $season_id, $group_id )
		) as $member ) {
			$members[] = array(
				'id'             => (int) $member['id'],
				'player_id'      => (int) $member['player_id'],
				'person_id'      => (int) $member['person_id'],
				'name'           => self::name( $member ),
				'first_name'     => (string) $member['first_name'],
				'last_name'      => (string) $member['last_name'],
				'club_id'        => self::int_or_null( $member['club_id'] ),
				'club_short'     => $member['club_short'],
				'classification' => self::int_or_null( $member['classification'] ),
				'status'         => (string) $member['status'],
				'rank_no'        => self::int_or_null( $member['rank_no'] ),
				'points'         => null === $member['points'] ? null : (float) $member['points'],
			);
		}
		$rules = json_decode( (string) $row['rules_json'], true );
		return array(
			'id'              => (int) $row['id'],
			'round_id'        => (int) $row['rl_round_id'],
			'category'        => (int) $row['category'],
			'group_no'        => (int) $row['group_no'],
			'host_club_id'    => self::int_or_null( $row['host_club_id'] ),
			'venue_id'        => self::int_or_null( $row['venue_id'] ),
			'start_at'        => $row['start_at'],
			'chief_player_id' => self::int_or_null( $row['chief_player_id'] ),
			'entered_by'      => self::int_or_null( $row['entered_by'] ),
			'entered_at'      => $row['entered_at'],
			'note'            => $row['note'],
			'round_no'        => (int) $row['round_no'],
			'is_final'        => '1' === (string) $row['is_final'],
			'round_status'    => (string) $row['round_status'],
			'date_from'       => $row['date_from'],
			'date_to'         => $row['date_to'],
			'competition_id'  => (int) $row['competition_id'],
			'season_id'       => $season_id,
			'season_status'   => (string) $row['season_status'],
			'rules'           => is_array( $rules ) ? $rules : array(),
			'host'            => null === $row['host_name'] ? null : array(
				'id'    => (int) $row['host_club_id'],
				'name'  => (string) $row['host_name'],
				'short' => (string) $row['host_short'],
			),
			'venue'           => null === $row['venue_name'] ? null : array(
				'id'     => (int) $row['venue_id'],
				'name'   => (string) $row['venue_name'],
				'street' => $row['street'],
				'zip'    => $row['zip'],
				'city'   => $row['city'],
			),
			'members'         => $members,
			'games'           => $this->games( $group_id ),
		);
	}

	/**
	 * Sperrt Saison und Gruppe bis zum Ende der Transaktion (zuerst die Saison).
	 */
	public function lock_group( int $season_id, int $group_id ): void {
		$this->db->get_var( "SELECT id FROM {$this->db->table( 'season' )} WHERE id = %d FOR UPDATE", array( $season_id ) );
		$this->db->get_var( "SELECT id FROM {$this->db->table( 'rl_group' )} WHERE id = %d FOR UPDATE", array( $group_id ) );
	}

	/**
	 * Partien einer Gruppe mit Satzpunkten.
	 *
	 * @return list<array{id:int,sequence:int,a:int,b:int,sets_a:int,sets_b:int,counts:bool,set_points:list<array{0:int,1:int}>}>
	 */
	public function games( int $group_id ): array {
		$sets = array();
		foreach ( $this->db->get_results(
			"SELECT gs.game_id, gs.points_a, gs.points_b FROM {$this->db->table( 'game_set' )} gs
			JOIN {$this->db->table( 'game' )} g ON g.id = gs.game_id WHERE g.rl_group_id = %d ORDER BY gs.game_id, gs.set_no",
			array( $group_id )
		) as $set ) {
			$sets[ (int) $set['game_id'] ][] = array( (int) $set['points_a'], (int) $set['points_b'] );
		}
		$games = array();
		foreach ( $this->db->get_results(
			"SELECT id, sequence, a1_player_id, b1_player_id, sets_a, sets_b, counts_for_result FROM {$this->db->table( 'game' )}
			WHERE rl_group_id = %d AND a1_player_id IS NOT NULL AND b1_player_id IS NOT NULL ORDER BY sequence, id",
			array( $group_id )
		) as $game ) {
			$games[] = array(
				'id'         => (int) $game['id'],
				'sequence'   => (int) $game['sequence'],
				'a'          => (int) $game['a1_player_id'],
				'b'          => (int) $game['b1_player_id'],
				'sets_a'     => (int) $game['sets_a'],
				'sets_b'     => (int) $game['sets_b'],
				'counts'     => '1' === (string) $game['counts_for_result'],
				'set_points' => $sets[ (int) $game['id'] ] ?? array(),
			);
		}
		return $games;
	}

	/**
	 * Löscht die Partien einer Gruppe mit ihren Satzpunkten.
	 */
	public function delete_games( int $group_id ): void {
		$this->db->query(
			"DELETE gs FROM {$this->db->table( 'game_set' )} gs JOIN {$this->db->table( 'game' )} g ON g.id = gs.game_id WHERE g.rl_group_id = %d",
			array( $group_id )
		);
		$this->db->delete( 'game', array( 'rl_group_id' => $group_id ) );
	}

	/**
	 * Frühere Paarungen: Personen, die in einer früheren Runde in derselben Gruppe gespielt haben.
	 *
	 * @return array<string,int> Paarung (GroupProposal::pair_key) => Anzahl.
	 */
	public function pairings_before( int $competition_id, int $round_no ): array {
		$pairs = array();
		foreach ( $this->db->get_results(
			"SELECT a.player_id AS a, b.player_id AS b, COUNT(*) AS meetings
			FROM {$this->db->table( 'rl_group_player' )} a
			JOIN {$this->db->table( 'rl_group_player' )} b ON b.rl_group_id = a.rl_group_id AND b.player_id > a.player_id
			JOIN {$this->db->table( 'rl_group' )} g ON g.id = a.rl_group_id
			JOIN {$this->db->table( 'rl_round' )} r ON r.id = g.rl_round_id
			WHERE r.competition_id = %d AND r.round_no < %d AND a.status = 'played' AND b.status = 'played'
			GROUP BY a.player_id, b.player_id",
			array( $competition_id, $round_no )
		) as $row ) {
			$pairs[ \TTLeague\Domain\Ranking\GroupProposal::pair_key( (int) $row['a'], (int) $row['b'] ) ] = (int) $row['meetings'];
		}
		return $pairs;
	}

	/**
	 * Wie oft war ein Club in früheren Runden Platzclub?
	 *
	 * @return array<int,int> Club => Anzahl Gruppen.
	 */
	public function hosted_before( int $competition_id, int $round_no ): array {
		$hosted = array();
		foreach ( $this->db->get_results(
			"SELECT g.host_club_id, COUNT(*) AS hosted FROM {$this->db->table( 'rl_group' )} g
			JOIN {$this->db->table( 'rl_round' )} r ON r.id = g.rl_round_id
			WHERE r.competition_id = %d AND r.round_no < %d AND g.host_club_id IS NOT NULL GROUP BY g.host_club_id",
			array( $competition_id, $round_no )
		) as $row ) {
			$hosted[ (int) $row['host_club_id'] ] = (int) $row['hosted'];
		}
		return $hosted;
	}

	/**
	 * Löscht alle Gruppen einer Runde mit Mitgliedern und Partien.
	 */
	public function delete_groups( int $round_id ): void {
		foreach ( $this->db->get_col( "SELECT id FROM {$this->db->table( 'rl_group' )} WHERE rl_round_id = %d", array( $round_id ) ) as $group_id ) {
			$this->delete_group( (int) $group_id );
		}
	}

	/**
	 * Löscht eine Gruppe mit Mitgliedern und Partien.
	 */
	public function delete_group( int $group_id ): void {
		$this->delete_games( $group_id );
		$this->db->delete( 'rl_group_player', array( 'rl_group_id' => $group_id ) );
		$this->db->delete( 'rl_group', array( 'id' => $group_id ) );
	}

	/**
	 * @return array{id:int,club_id:int,name:string,open_time:string|null}|null
	 */
	public function venue( int $venue_id ): ?array {
		$row = $this->db->get_row( "SELECT id, club_id, name, open_time FROM {$this->db->table( 'venue' )} WHERE id = %d", array( $venue_id ) );
		return null === $row ? null : array(
			'id'        => (int) $row['id'],
			'club_id'   => (int) $row['club_id'],
			'name'      => (string) $row['name'],
			'open_time' => $row['open_time'],
		);
	}

	public function club_exists( int $club_id ): bool {
		return null !== $this->db->get_var( "SELECT id FROM {$this->db->table( 'club' )} WHERE id = %d", array( $club_id ) );
	}

	/**
	 * Aktive Lokale der Clubs mit Spieltag und Öffnungszeit.
	 *
	 * @param list<int> $club_ids Clubs.
	 * @return list<array{id:int,club:int,weekday:int|null,name:string,open_time:string|null,tables:int|null}>
	 */
	public function venues( array $club_ids ): array {
		$ids = self::ids( $club_ids );
		if ( array() === $ids ) {
			return array();
		}
		$venues = array();
		foreach ( $this->db->get_results(
			"SELECT id, club_id, weekday, name, open_time, tables_count FROM {$this->db->table( 'venue' )} WHERE active = 1 AND club_id IN (" . implode( ',', $ids ) . ') ORDER BY club_id, id' // Nur Ganzzahlen.
		) as $row ) {
			$venues[] = array(
				'id'        => (int) $row['id'],
				'club'      => (int) $row['club_id'],
				'weekday'   => self::int_or_null( $row['weekday'] ),
				'name'      => (string) $row['name'],
				'open_time' => $row['open_time'],
				'tables'    => self::int_or_null( $row['tables_count'] ),
			);
		}
		return $venues;
	}

	/**
	 * Hallensperren, die den Zeitraum berühren.
	 *
	 * @return list<array{venue:int,from:string,to:string}>
	 */
	public function closures( string $from, string $to ): array {
		$closures = array();
		foreach ( $this->db->get_results(
			"SELECT venue_id, date_from, date_to FROM {$this->db->table( 'venue_closure' )} WHERE date_from <= %s AND date_to >= %s ORDER BY id",
			array( $to, $from )
		) as $row ) {
			$closures[] = array(
				'venue' => (int) $row['venue_id'],
				'from'  => (string) $row['date_from'],
				'to'    => (string) $row['date_to'],
			);
		}
		return $closures;
	}

	/**
	 * Clubs mit aktiven Lokalen, für die Auswahl von Platzclub und Lokal.
	 *
	 * @return list<array{id:int,club_id:int,club_short:string,name:string,weekday:int|null}>
	 */
	public function all_venues(): array {
		$venues = array();
		foreach ( $this->db->get_results(
			"SELECT v.id, v.club_id, v.name, v.weekday, c.short_name FROM {$this->db->table( 'venue' )} v
			JOIN {$this->db->table( 'club' )} c ON c.id = v.club_id WHERE v.active = 1 ORDER BY c.short_name, v.name, v.id"
		) as $row ) {
			$venues[] = array(
				'id'         => (int) $row['id'],
				'club_id'    => (int) $row['club_id'],
				'club_short' => (string) $row['short_name'],
				'name'       => (string) $row['name'],
				'weekday'    => self::int_or_null( $row['weekday'] ),
			);
		}
		return $venues;
	}

	/**
	 * Gesperrte Abende (availability mit context «rl») von Personen in einem Zeitraum.
	 *
	 * @param list<int> $person_ids Personen.
	 * @return array<int,list<string>> Person => Tage (J-M-T), aufsteigend.
	 */
	public function blocked_dates( array $person_ids, string $from, string $to ): array {
		$ids = self::ids( $person_ids );
		if ( array() === $ids ) {
			return array();
		}
		$first   = \DateTimeImmutable::createFromFormat( '!Y-m-d', $from );
		$last    = \DateTimeImmutable::createFromFormat( '!Y-m-d', $to );
		$blocked = array();
		if ( false === $first || false === $last ) {
			return $blocked;
		}
		foreach ( $this->db->get_results(
			"SELECT person_id, date_from, date_to FROM {$this->db->table( 'availability' )}
			WHERE context = %s AND date_from <= %s AND date_to >= %s AND person_id IN (" . implode( ',', $ids ) . ') ORDER BY person_id, date_from', // Nur Ganzzahlen.
			array( Registrations::CONTEXT, $to, $from )
		) as $row ) {
			$start = max( $first, \DateTimeImmutable::createFromFormat( '!Y-m-d', (string) $row['date_from'] ) );
			$end   = min( $last, \DateTimeImmutable::createFromFormat( '!Y-m-d', (string) $row['date_to'] ) );
			for ( $day = $start; $day <= $end; $day = $day->modify( '+1 day' ) ) {
				$blocked[ (int) $row['person_id'] ][ $day->format( 'Y-m-d' ) ] = true;
			}
		}
		return array_map(
			static function ( array $days ): array {
				ksort( $days );
				return array_keys( $days );
			},
			$blocked
		);
	}

	/**
	 * Personen, die ihre Sperrdaten für eine Rundenwoche gemeldet haben (auch «keine»).
	 *
	 * @param list<int> $person_ids Personen.
	 * @return list<int>
	 */
	public function answered( array $person_ids, string $from, string $to ): array {
		$ids = self::ids( $person_ids );
		if ( array() === $ids ) {
			return array();
		}
		return array_map(
			'intval',
			$this->db->get_col(
				"SELECT DISTINCT person_id FROM {$this->db->table( 'availability' )}
				WHERE context = %s AND date_from = %s AND date_to = %s AND person_id IN (" . implode( ',', $ids ) . ')', // Nur Ganzzahlen.
				array( Registrations::CONTEXT_ANSWER, $from, $to )
			)
		);
	}

	/**
	 * Ersetzt die gesperrten Abende einer Person in einer Rundenwoche und merkt die Meldung.
	 *
	 * @param list<string> $dates Gesperrte Tage innerhalb der Woche.
	 */
	public function replace_blocked_dates( int $person_id, string $from, string $to, array $dates ): void {
		$this->db->query(
			"DELETE FROM {$this->db->table( 'availability' )} WHERE person_id = %d AND ( ( context = %s AND date_from >= %s AND date_to <= %s ) OR ( context = %s AND date_from = %s AND date_to = %s ) )",
			array( $person_id, Registrations::CONTEXT, $from, $to, Registrations::CONTEXT_ANSWER, $from, $to )
		);
		foreach ( $dates as $date ) {
			$this->db->insert(
				'availability',
				array(
					'person_id' => $person_id,
					'date_from' => $date,
					'date_to'   => $date,
					'context'   => Registrations::CONTEXT,
				)
			);
		}
		$this->db->insert(
			'availability',
			array(
				'person_id' => $person_id,
				'date_from' => $from,
				'date_to'   => $to,
				'context'   => Registrations::CONTEXT_ANSWER,
			)
		);
	}

	/**
	 * Personen, denen laut Versandprotokoll eine Nachricht zugestellt ist (Status «sent», gleich
	 * über welchen Kanal).
	 *
	 * @param list<int>   $person_ids Personen.
	 * @param string      $event      Art der Nachricht (notification_log.event_type).
	 * @param string      $ref_type   Bezug.
	 * @param list<int>   $ref_ids    IDs des Bezugs.
	 * @param string|null $ref_key    Zusatz zum Bezug; null für jeden Zusatz.
	 * @return list<int>
	 */
	public function notified( array $person_ids, string $event, string $ref_type, array $ref_ids, ?string $ref_key = null ): array {
		$persons = self::ids( $person_ids );
		$refs    = self::ids( $ref_ids );
		if ( array() === $persons || array() === $refs ) {
			return array();
		}
		$args = array( $event, $ref_type, \TTLeague\Notifications\NotificationLog::SENT );
		$key  = '';
		if ( null !== $ref_key ) {
			$key    = ' AND ref_key = %s';
			$args[] = $ref_key;
		}
		return array_map(
			'intval',
			$this->db->get_col(
				"SELECT DISTINCT person_id FROM {$this->db->table( 'notification_log' )}
				WHERE event_type = %s AND ref_type = %s AND status = %s{$key}
					AND ref_id IN (" . implode( ',', $refs ) . ') AND person_id IN (' . implode( ',', $persons ) . ')', // Nur Ganzzahlen.
				$args
			)
		);
	}

	/**
	 * Name zu einem Konto über die verknüpfte Person.
	 */
	public function user_name( int $user_id ): ?string {
		$row = $this->db->get_row( "SELECT first_name, last_name FROM {$this->db->table( 'person' )} WHERE user_id = %d ORDER BY id LIMIT 1", array( $user_id ) );
		return null === $row ? null : self::name( $row );
	}

	/**
	 * @return array<string,mixed>|null
	 */
	private function competition_where( string $where, int $id ): ?array {
		$row = $this->db->get_row(
			"SELECT c.id, c.season_id, c.name, c.format_json, s.status AS season_status, s.rules_json
			FROM {$this->db->table( 'competition' )} c JOIN {$this->db->table( 'season' )} s ON s.id = c.season_id WHERE {$where}",
			array( $id )
		);
		if ( null === $row ) {
			return null;
		}
		$format = json_decode( (string) $row['format_json'], true );
		$format = is_array( $format ) ? $format : array();
		$rules  = json_decode( (string) $row['rules_json'], true );
		return array(
			'id'            => (int) $row['id'],
			'season_id'     => (int) $row['season_id'],
			'season_status' => (string) $row['season_status'],
			'name'          => (string) $row['name'],
			'format'        => $format,
			'categories'    => \TTLeague\Domain\Ranking\Categories::normalize( $format['categories'] ?? null ),
			'deadline'      => self::date_or_null( $format['registration_deadline'] ?? null ),
			'start_time'    => self::time_or_null( $format['start_time'] ?? null ),
			'rules'         => is_array( $rules ) ? $rules : array(),
		);
	}

	/**
	 * @param array<string,string|null> $row Zeile aus rl_round.
	 * @return array{id:int,competition_id:int,round_no:int,date_from:string|null,date_to:string|null,is_final:bool,status:string,closed_by:int|null,closed_at:string|null}
	 */
	private static function round_row( array $row ): array {
		return array(
			'id'             => (int) $row['id'],
			'competition_id' => (int) $row['competition_id'],
			'round_no'       => (int) $row['round_no'],
			'date_from'      => $row['date_from'],
			'date_to'        => $row['date_to'],
			'is_final'       => '1' === (string) $row['is_final'],
			'status'         => (string) $row['status'],
			'closed_by'      => self::int_or_null( $row['closed_by'] ),
			'closed_at'      => $row['closed_at'],
		);
	}

	/**
	 * Gültiges Datum J-M-T oder null.
	 */
	public static function date_or_null( mixed $value ): ?string {
		if ( ! is_string( $value ) ) {
			return null;
		}
		$date = \DateTimeImmutable::createFromFormat( '!Y-m-d', $value );
		return false !== $date && $date->format( 'Y-m-d' ) === $value ? $value : null;
	}

	/**
	 * Gültige Uhrzeit als H:i:s oder null (Eingabe H:i oder H:i:s).
	 */
	public static function time_or_null( mixed $value ): ?string {
		if ( ! is_string( $value ) || 1 !== preg_match( '/^([01][0-9]|2[0-3]):([0-5][0-9])(:[0-5][0-9])?$/D', $value ) ) {
			return null;
		}
		return substr( $value . ':00', 0, 8 );
	}

	/**
	 * @param array<string,string|null> $row Zeile mit first_name und last_name.
	 */
	private static function name( array $row ): string {
		return trim( trim( (string) ( $row['first_name'] ?? '' ) ) . ' ' . trim( (string) ( $row['last_name'] ?? '' ) ) );
	}

	private static function int_or_null( ?string $value ): ?int {
		return null === $value ? null : (int) $value;
	}

	/**
	 * @param array<int|string,mixed> $values Werte.
	 * @return list<int> Positive Ganzzahlen ohne Doppel.
	 */
	private static function ids( array $values ): array {
		return array_values( array_unique( array_filter( array_map( 'intval', $values ), static fn( int $id ): bool => $id > 0 ) ) );
	}
}
