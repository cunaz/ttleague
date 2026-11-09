<?php
/**
 * Lesen und Schreiben von Begegnungen, Aufstellungen, Spielen und Verlauf.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Results;

use TTLeague\Domain\Rules\SeasonRules;
use TTLeague\Infrastructure\Database\Db;

final class ResultRepository {

	public function __construct( private readonly Db $db ) {}

	public function context( int $fixture_id ): ?FixtureContext {
		$row = $this->db->get_row(
			"SELECT f.id, f.status, f.scheduled_at, f.home_team_id, f.away_team_id, f.home_entry_id, f.away_entry_id,
				f.home_score, f.away_score, f.entered_by, f.entered_at, f.confirmed_by, f.confirmed_at, f.photo_attachment_id,
				c.type, COALESCE(c.category, '') AS category, c.name AS competition, c.season_id,
				s.slug AS season_slug, s.status AS season_status, s.rules_json,
				COALESCE(ht.club_id, he.club_id) AS home_club_id, COALESCE(at.club_id, ae.club_id) AS away_club_id,
				COALESCE(ht.name, he.name, '') AS home_name, COALESCE(at.name, ae.name, '') AS away_name,
				(SELECT MAX(g.id) FROM {$this->db->table( 'game' )} g WHERE g.fixture_id = f.id) AS result_stamp
			FROM {$this->db->table( 'fixture' )} f
			JOIN {$this->db->table( 'competition' )} c ON c.id = f.competition_id
			JOIN {$this->db->table( 'season' )} s ON s.id = c.season_id
			LEFT JOIN {$this->db->table( 'team' )} ht ON ht.id = f.home_team_id
			LEFT JOIN {$this->db->table( 'team' )} at ON at.id = f.away_team_id
			LEFT JOIN {$this->db->table( 'cup_entry' )} he ON he.id = f.home_entry_id
			LEFT JOIN {$this->db->table( 'cup_entry' )} ae ON ae.id = f.away_entry_id
			WHERE f.id = %d",
			array( $fixture_id )
		);
		if ( null === $row || ! in_array( $row['type'], array( 'MM', 'CUP' ), true ) ) {
			return null;
		}
		$rules = json_decode( (string) $row['rules_json'], true );
		return new FixtureContext(
			(int) $row['id'],
			(string) $row['type'],
			(string) $row['category'],
			(string) $row['competition'],
			(int) $row['season_id'],
			(string) $row['season_slug'],
			(string) $row['season_status'],
			SeasonRules::from_array( is_array( $rules ) ? $rules : array() ),
			(string) $row['status'],
			$row['scheduled_at'],
			array(
				'team_id'  => self::int( $row['home_team_id'] ),
				'entry_id' => self::int( $row['home_entry_id'] ),
				'club_id'  => self::int( $row['home_club_id'] ),
				'name'     => (string) $row['home_name'],
			),
			array(
				'team_id'  => self::int( $row['away_team_id'] ),
				'entry_id' => self::int( $row['away_entry_id'] ),
				'club_id'  => self::int( $row['away_club_id'] ),
				'name'     => (string) $row['away_name'],
			),
			self::int( $row['home_score'], true ),
			self::int( $row['away_score'], true ),
			self::int( $row['entered_by'] ),
			$row['entered_at'],
			self::int( $row['confirmed_by'] ),
			$row['confirmed_at'],
			self::int( $row['photo_attachment_id'] ),
			(int) ( $row['result_stamp'] ?? 0 )
		);
	}

	/**
	 * @return array{home:array<string,int>,away:array<string,int>,substitutes:list<int>}
	 */
	public function lineup( int $fixture_id ): array {
		$lineup = array(
			'home'        => array(),
			'away'        => array(),
			'substitutes' => array(),
		);
		foreach ( $this->db->get_results(
			"SELECT side, slot, player_id, is_substitute FROM {$this->db->table( 'lineup' )} WHERE fixture_id = %d ORDER BY side, slot",
			array( $fixture_id )
		) as $row ) {
			$side                                     = 'away' === $row['side'] ? 'away' : 'home';
			$lineup[ $side ][ (string) $row['slot'] ] = (int) $row['player_id'];
			if ( '1' === (string) $row['is_substitute'] ) {
				$lineup['substitutes'][] = (int) $row['player_id'];
			}
		}
		return $lineup;
	}

	/**
	 * @return list<array{sequence:int,type:string,status:string,sets_home:int,sets_away:int,home_players:list<int>,away_players:list<int>,counts:bool,set_points:list<array{0:int,1:int}>}>
	 */
	public function games( int $fixture_id ): array {
		$rows = $this->db->get_results(
			"SELECT id, sequence, type, a1_player_id, a2_player_id, b1_player_id, b2_player_id, sets_a, sets_b, status, counts_for_result
			FROM {$this->db->table( 'game' )} WHERE fixture_id = %d ORDER BY sequence",
			array( $fixture_id )
		);
		$sets = array();
		if ( array() !== $rows ) {
			foreach ( $this->db->get_results(
				"SELECT gs.game_id, gs.set_no, gs.points_a, gs.points_b FROM {$this->db->table( 'game_set' )} gs
				JOIN {$this->db->table( 'game' )} g ON g.id = gs.game_id WHERE g.fixture_id = %d ORDER BY gs.game_id, gs.set_no",
				array( $fixture_id )
			) as $set ) {
				$sets[ (int) $set['game_id'] ][] = array( (int) $set['points_a'], (int) $set['points_b'] );
			}
		}
		$games = array();
		foreach ( $rows as $row ) {
			$games[] = array(
				'sequence'     => (int) $row['sequence'],
				'type'         => (string) $row['type'],
				'status'       => (string) $row['status'],
				'sets_home'    => (int) $row['sets_a'],
				'sets_away'    => (int) $row['sets_b'],
				'home_players' => array_values( array_filter( array( self::int( $row['a1_player_id'] ), self::int( $row['a2_player_id'] ) ) ) ),
				'away_players' => array_values( array_filter( array( self::int( $row['b1_player_id'] ), self::int( $row['b2_player_id'] ) ) ) ),
				'counts'       => '1' === (string) $row['counts_for_result'],
				'set_points'   => $sets[ (int) $row['id'] ] ?? array(),
			);
		}
		return $games;
	}

	/**
	 * Ersetzt Aufstellung und Spiele einer Begegnung.
	 */
	public function save_result( int $fixture_id, ValidatedResult $result, ?string $played_at ): void {
		$this->clear_result( $fixture_id );
		foreach ( $result->lineup as $row ) {
			$this->db->insert(
				'lineup',
				array(
					'fixture_id'    => $fixture_id,
					'side'          => $row['side'],
					'slot'          => $row['slot'],
					'player_id'     => $row['player_id'],
					'is_substitute' => $row['is_substitute'] ? 1 : 0,
				)
			);
		}
		foreach ( $result->games as $game ) {
			$game_id = $this->db->insert(
				'game',
				array(
					'fixture_id'        => $fixture_id,
					'rl_group_id'       => null,
					'sequence'          => $game['sequence'],
					'type'              => $game['type'],
					'a1_player_id'      => $game['a1'],
					'a2_player_id'      => $game['a2'],
					'b1_player_id'      => $game['b1'],
					'b2_player_id'      => $game['b2'],
					'sets_a'            => $game['sets_a'],
					'sets_b'            => $game['sets_b'],
					'status'            => $game['status'],
					'counts_for_result' => $game['counts'] ? 1 : 0,
					'played_at'         => $played_at,
				)
			);
			foreach ( $game['set_points'] as $i => $set ) {
				$this->db->insert(
					'game_set',
					array(
						'game_id'  => $game_id,
						'set_no'   => $i + 1,
						'points_a' => $set[0],
						'points_b' => $set[1],
					)
				);
			}
		}
	}

	public function clear_result( int $fixture_id ): void {
		$this->db->query(
			"DELETE gs FROM {$this->db->table( 'game_set' )} gs JOIN {$this->db->table( 'game' )} g ON g.id = gs.game_id WHERE g.fixture_id = %d",
			array( $fixture_id )
		);
		$this->db->delete( 'game', array( 'fixture_id' => $fixture_id ) );
		$this->db->delete( 'lineup', array( 'fixture_id' => $fixture_id ) );
	}

	/**
	 * @param array<string,int|string|null> $data Felder.
	 */
	public function update_fixture( int $fixture_id, array $data ): void {
		$this->db->update( 'fixture', $data, array( 'id' => $fixture_id ) );
	}

	/**
	 * Fachliches Ereignis im Verlauf der Begegnung.
	 *
	 * @param array<string,mixed> $data Zusatzdaten (z. B. Hinweise).
	 */
	public function add_event( int $fixture_id, string $type, ?int $user_id, ?int $person_id, ?string $comment, array $data, string $now ): int {
		return $this->db->insert(
			'fixture_event',
			array(
				'fixture_id' => $fixture_id,
				'type'       => $type,
				'user_id'    => $user_id,
				'person_id'  => $person_id,
				'comment'    => $comment,
				'data_json'  => array() === $data ? null : (string) json_encode( $data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ), // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- läuft auch ohne WordPress.
				'created_at' => $now,
			)
		);
	}

	/**
	 * Verlauf einer Begegnung, älteste zuerst.
	 *
	 * @return list<array{id:int,type:string,user_id:int|null,person_id:int|null,name:string|null,comment:string|null,data:array<string,mixed>,created_at:string}>
	 */
	public function events( int $fixture_id ): array {
		$events = array();
		foreach ( $this->db->get_results(
			"SELECT e.id, e.type, e.user_id, e.person_id, e.comment, e.data_json, e.created_at, pe.first_name, pe.last_name
			FROM {$this->db->table( 'fixture_event' )} e
			LEFT JOIN {$this->db->table( 'person' )} pe ON pe.id = e.person_id
			WHERE e.fixture_id = %d ORDER BY e.created_at, e.id",
			array( $fixture_id )
		) as $row ) {
			$data     = json_decode( (string) $row['data_json'], true );
			$events[] = array(
				'id'         => (int) $row['id'],
				'type'       => (string) $row['type'],
				'user_id'    => self::int( $row['user_id'] ),
				'person_id'  => self::int( $row['person_id'] ),
				'name'       => null === $row['first_name'] && null === $row['last_name'] ? null : trim( $row['first_name'] . ' ' . $row['last_name'] ),
				'comment'    => $row['comment'],
				'data'       => is_array( $data ) ? $data : array(),
				'created_at' => (string) $row['created_at'],
			);
		}
		return $events;
	}

	/**
	 * Zustand für das Änderungsprotokoll.
	 *
	 * @return array<string,mixed>
	 */
	public function snapshot( int $fixture_id ): array {
		$fixture = $this->db->get_row(
			"SELECT status, home_score, away_score, entered_by, entered_at, confirmed_by, confirmed_at, confirmed_auto, photo_attachment_id
			FROM {$this->db->table( 'fixture' )} WHERE id = %d",
			array( $fixture_id )
		);
		$games   = array();
		foreach ( $this->games( $fixture_id ) as $game ) {
			$games[] = array(
				$game['sequence'],
				$game['status'],
				$game['sets_home'] . ':' . $game['sets_away'],
				$game['home_players'],
				$game['away_players'],
				$game['counts'] ? 1 : 0,
				$game['set_points'],
			);
		}
		return array(
			'fixture' => $fixture,
			'lineup'  => $this->lineup( $fixture_id ),
			'games'   => $games,
		);
	}

	/**
	 * Sperrt Saison und Begegnung bis zum Ende der laufenden Transaktion (SELECT … FOR UPDATE),
	 * immer in dieser Reihenfolge. Die Sperre der Saison reiht alle Schreibvorgänge einer Saison
	 * hintereinander: Spielberechtigung und Bindungen hängen von Einsätzen in anderen Begegnungen
	 * ab. Muss die erste Abfrage der Transaktion sein: Erst danach gelesene Daten zeigen den Stand
	 * nach allen Transaktionen, die die Sperre vorher hielten.
	 */
	public function lock( int $season_id, int $fixture_id ): void {
		$this->db->get_var( "SELECT id FROM {$this->db->table( 'season' )} WHERE id = %d FOR UPDATE", array( $season_id ) );
		$this->db->get_var( "SELECT id FROM {$this->db->table( 'fixture' )} WHERE id = %d FOR UPDATE", array( $fixture_id ) );
	}

	/**
	 * Spieler in der Aufstellung einer Begegnung.
	 *
	 * @return list<int>
	 */
	public function lineup_players( int $fixture_id ): array {
		return array_map(
			'intval',
			$this->db->get_col( "SELECT DISTINCT player_id FROM {$this->db->table( 'lineup' )} WHERE fixture_id = %d", array( $fixture_id ) )
		);
	}

	/**
	 * Setzt die Bindung eines Spielers in der Saison (Kadereintrag «bound») auf den berechneten
	 * Stand: höchstens ein Eintrag; entfällt die Bindung, wird er gelöscht.
	 *
	 * @param array{team_id:int,valid_from:string|null}|null $binding Bindung oder null.
	 */
	public function replace_binding( int $season_id, int $player_id, ?array $binding ): void {
		$kept = false;
		foreach ( $this->db->get_results(
			"SELECT r.id, r.team_id, r.valid_from FROM {$this->db->table( 'team_roster' )} r
			JOIN {$this->db->table( 'team' )} t ON t.id = r.team_id
			WHERE r.player_id = %d AND r.kind = 'bound' AND t.season_id = %d
			ORDER BY r.id",
			array( $player_id, $season_id )
		) as $row ) {
			if ( ! $kept && null !== $binding && (int) $row['team_id'] === $binding['team_id'] ) {
				$kept = true;
				if ( $row['valid_from'] !== $binding['valid_from'] ) {
					$this->db->update( 'team_roster', array( 'valid_from' => $binding['valid_from'] ), array( 'id' => (int) $row['id'] ) );
				}
				continue;
			}
			$this->db->delete( 'team_roster', array( 'id' => (int) $row['id'] ) );
		}
		if ( ! $kept && null !== $binding ) {
			$this->db->insert(
				'team_roster',
				array(
					'team_id'    => $binding['team_id'],
					'player_id'  => $player_id,
					'kind'       => 'bound',
					'valid_from' => $binding['valid_from'],
				)
			);
		}
	}

	/**
	 * Namen zu Spieler-IDs.
	 *
	 * @param list<int> $player_ids Spieler.
	 * @return array<int,string>
	 */
	public function player_names( array $player_ids ): array {
		$ids = array_values( array_unique( array_filter( array_map( 'intval', $player_ids ) ) ) );
		if ( array() === $ids ) {
			return array();
		}
		$names = array();
		foreach ( $this->db->get_results(
			"SELECT p.id, pe.first_name, pe.last_name FROM {$this->db->table( 'player' )} p
			JOIN {$this->db->table( 'person' )} pe ON pe.id = p.person_id
			WHERE p.id IN (" . implode( ',', $ids ) . ')' // Nur Ganzzahlen.
		) as $row ) {
			$names[ (int) $row['id'] ] = trim( $row['first_name'] . ' ' . $row['last_name'] );
		}
		return $names;
	}

	/**
	 * Name einer Person.
	 */
	public function person_name( int $person_id ): ?string {
		$row = $this->db->get_row( "SELECT first_name, last_name FROM {$this->db->table( 'person' )} WHERE id = %d", array( $person_id ) );
		return null === $row ? null : trim( $row['first_name'] . ' ' . $row['last_name'] );
	}

	private static function int( mixed $value, bool $allow_zero = false ): ?int {
		if ( null === $value || '' === $value ) {
			return null;
		}
		$int = (int) $value;
		return $int > 0 || ( $allow_zero && 0 === $int ) ? $int : null;
	}
}
