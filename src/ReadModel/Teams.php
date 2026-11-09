<?php
/**
 * Mannschaftsseite: Stammspieler, Einsatzmatrix, Captain, Heimlokal.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\ReadModel;

use TTLeague\Infrastructure\Database\Db;
use TTLeague\ReadModel\Cache\Cache;
use TTLeague\ReadModel\Support\Names;
use TTLeague\ReadModel\Support\Sql;

final class Teams {

	public const MARK_REGULAR    = 'S';
	public const MARK_SUBSTITUTE = 'E';
	public const MARK_NONE       = '-';

	public function __construct(
		private readonly Db $db,
		private readonly Cache $cache,
		private readonly Championship $championship,
		private readonly Players $players,
	) {}

	/**
	 * Zusatzdaten der Teamseite.
	 *
	 * @return array{
	 *   roster:list<array{player:array<string,mixed>,classification:?int,kind:string}>,
	 *   captains:list<array{name:string,player:?array<string,mixed>}>,
	 *   venue:?array{id:int,name:string,street:?string,zip:?string,city:?string,weekday:?int},
	 *   matrix:array{fixtures:list<array<string,mixed>>,rows:list<array<string,mixed>>}
	 * }
	 */
	public function details( int $season_id, int $team_id ): array {
		return $this->cache->remember(
			'team_details',
			array( $season_id, $team_id ),
			fn(): array => $this->load( $season_id, $team_id )
		);
	}

	/**
	 * Einsatzmatrix aus Aufstellungen: pro gespielte Begegnung «S» (Stammspieler), «E» (Ersatz)
	 * oder «-» (nicht eingesetzt). Reine Funktion.
	 *
	 * @param list<array<string,mixed>>         $fixtures Begegnungen des Teams (chronologisch).
	 * @param array<int,array<int,bool>>        $lineups  fixture_id => player_id => is_substitute.
	 * @param list<int>                         $roster   Stammspieler in Anzeigereihenfolge.
	 * @param array<int,array<string,mixed>>    $refs     player_id => Spielerreferenz (mit classification).
	 * @return array{fixtures:list<array<string,mixed>>,rows:list<array{player:array<string,mixed>,classification:?int,is_roster:bool,marks:list<string>,regular:int,substitute:int}>}
	 */
	public static function matrix( array $fixtures, array $lineups, array $roster, array $refs ): array {
		$columns = array();
		foreach ( $fixtures as $fixture ) {
			if ( isset( $lineups[ $fixture['id'] ] ) && array() !== $lineups[ $fixture['id'] ] ) {
				$columns[] = $fixture;
			}
		}

		$order = $roster;
		foreach ( $columns as $fixture ) {
			foreach ( array_keys( $lineups[ $fixture['id'] ] ) as $player_id ) {
				if ( ! in_array( $player_id, $order, true ) ) {
					$order[] = $player_id;
				}
			}
		}

		$rows = array();
		foreach ( $order as $player_id ) {
			if ( ! isset( $refs[ $player_id ] ) ) {
				continue;
			}
			$marks      = array();
			$regular    = 0;
			$substitute = 0;
			foreach ( $columns as $fixture ) {
				$lineup = $lineups[ $fixture['id'] ];
				if ( ! array_key_exists( $player_id, $lineup ) ) {
					$marks[] = self::MARK_NONE;
				} elseif ( $lineup[ $player_id ] ) {
					$marks[] = self::MARK_SUBSTITUTE;
					++$substitute;
				} else {
					$marks[] = self::MARK_REGULAR;
					++$regular;
				}
			}
			$rows[] = array(
				'player'         => $refs[ $player_id ],
				'classification' => $refs[ $player_id ]['classification'] ?? null,
				'is_roster'      => in_array( $player_id, $roster, true ),
				'marks'          => $marks,
				'regular'        => $regular,
				'substitute'     => $substitute,
			);
		}

		return array(
			'fixtures' => $columns,
			'rows'     => $rows,
		);
	}

	/**
	 * @return array<string,mixed>
	 */
	private function load( int $season_id, int $team_id ): array {
		$roster     = array();
		$roster_ids = array();
		foreach ( $this->db->get_results(
			"SELECT r.player_id, r.kind, p.license_no, pe.first_name, pe.last_name, ps.classification
			FROM {$this->db->table( 'team_roster' )} r
			JOIN {$this->db->table( 'player' )} p ON p.id = r.player_id
			JOIN {$this->db->table( 'person' )} pe ON pe.id = p.person_id
			LEFT JOIN {$this->db->table( 'player_season' )} ps ON ps.player_id = r.player_id AND ps.season_id = %d
			WHERE r.team_id = %d
			ORDER BY r.kind = 'stamm' DESC, ps.classification DESC, pe.last_name, pe.first_name",
			array( $season_id, $team_id )
		) as $row ) {
			$roster[] = array(
				'player'         => Names::player( $row ),
				'classification' => Sql::int_or_null( $row['classification'] ),
				'kind'           => 'bound' === $row['kind'] ? 'bound' : 'stamm',
			);
			if ( 'bound' !== $row['kind'] ) {
				$roster_ids[] = (int) $row['player_id'];
			}
		}

		$captains = array();
		foreach ( $this->db->get_results(
			"SELECT pe.first_name, pe.last_name, p.id AS player_id, p.license_no
			FROM {$this->db->table( 'club_role' )} cr
			JOIN {$this->db->table( 'person' )} pe ON pe.id = cr.person_id
			LEFT JOIN {$this->db->table( 'player' )} p ON p.person_id = pe.id
			WHERE cr.role = 'captain' AND cr.team_id = %d AND cr.season_id = %d
			ORDER BY pe.last_name, pe.first_name",
			array( $team_id, $season_id )
		) as $row ) {
			$captains[] = array(
				'name'   => Names::full( $row['first_name'], $row['last_name'] ),
				'player' => null === $row['player_id'] ? null : Names::player( $row ),
			);
		}

		$team  = $this->championship->season( $season_id )['teams'][ $team_id ] ?? null;
		$venue = null;
		if ( null !== $team && null !== $team['home_venue_id'] ) {
			$row = $this->db->get_row(
				"SELECT id, name, street, zip, city, weekday FROM {$this->db->table( 'venue' )} WHERE id = %d",
				array( $team['home_venue_id'] )
			);
			if ( null !== $row ) {
				$venue = array(
					'id'      => (int) $row['id'],
					'name'    => (string) $row['name'],
					'street'  => $row['street'],
					'zip'     => $row['zip'],
					'city'    => $row['city'],
					'weekday' => Sql::int_or_null( $row['weekday'] ),
				);
			}
		}

		$lineups = array();
		foreach ( $this->db->get_results(
			"SELECT l.fixture_id, l.player_id, l.is_substitute
			FROM {$this->db->table( 'lineup' )} l
			JOIN {$this->db->table( 'fixture' )} f ON f.id = l.fixture_id
			WHERE ( f.home_team_id = %d AND l.side = 'home' ) OR ( f.away_team_id = %d AND l.side = 'away' )",
			array( $team_id, $team_id )
		) as $row ) {
			$lineups[ (int) $row['fixture_id'] ][ (int) $row['player_id'] ] = '1' === (string) $row['is_substitute'];
		}

		$player_ids = $roster_ids;
		foreach ( $lineups as $lineup ) {
			foreach ( array_keys( $lineup ) as $player_id ) {
				$player_ids[] = $player_id;
			}
		}
		$refs = $this->players->refs( Sql::ids( $player_ids ), $season_id );

		return array(
			'roster'   => $roster,
			'captains' => $captains,
			'venue'    => $venue,
			'matrix'   => self::matrix( $this->championship->team_fixtures( $season_id, $team_id ), $lineups, $roster_ids, $refs ),
		);
	}
}
