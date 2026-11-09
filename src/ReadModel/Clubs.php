<?php
/**
 * Clubs: Liste einer Saison und Club-Detail mit Teams, Lokalen, Funktionen und Spielern.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\ReadModel;

use TTLeague\Infrastructure\Database\Db;
use TTLeague\ReadModel\Cache\Cache;
use TTLeague\ReadModel\Support\Names;
use TTLeague\ReadModel\Support\Sql;

final class Clubs {

	public function __construct(
		private readonly Db $db,
		private readonly Cache $cache,
		private readonly Championship $championship,
		private readonly Players $players,
	) {}

	/**
	 * Alle Clubs (auch ohne Teams), alphabetisch nach Kurzname.
	 *
	 * @return list<array{id:int,number:int,name:string,short_name:string,slug:string,active:bool}>
	 */
	public function all(): array {
		return $this->cache->remember(
			'clubs_all',
			array(),
			function (): array {
				$clubs = array();
				foreach ( $this->db->get_results( "SELECT id, number, name, short_name, slug, active FROM {$this->db->table( 'club' )} ORDER BY short_name, name" ) as $row ) {
					$clubs[] = array(
						'id'         => (int) $row['id'],
						'number'     => (int) $row['number'],
						'name'       => (string) $row['name'],
						'short_name' => (string) $row['short_name'],
						'slug'       => (string) $row['slug'],
						'active'     => '1' === (string) $row['active'],
					);
				}
				return $clubs;
			}
		);
	}

	/**
	 * @return array{id:int,number:int,name:string,short_name:string,slug:string,active:bool}|null
	 */
	public function by_slug( string $slug ): ?array {
		foreach ( $this->all() as $club ) {
			if ( $club['slug'] === $slug ) {
				return $club;
			}
		}
		return null;
	}

	/**
	 * Clubs mit Teams oder gemeldeten Spielern in der Saison.
	 *
	 * @return list<array{club:array<string,mixed>,teams:list<array<string,mixed>>,players:int}>
	 */
	public function season_list( int $season_id ): array {
		$season  = $this->championship->season( $season_id );
		$players = array();
		foreach ( $this->players->season_list( $season_id ) as $row ) {
			$players[ $row['club_id'] ] = ( $players[ $row['club_id'] ] ?? 0 ) + 1;
		}
		$list = array();
		foreach ( $this->all() as $club ) {
			$teams = array_values( array_filter( $season['teams'], static fn( array $t ): bool => $t['club_id'] === $club['id'] ) );
			usort( $teams, static fn( array $a, array $b ): int => $a['number'] <=> $b['number'] );
			$count = $players[ $club['id'] ] ?? 0;
			if ( array() === $teams && 0 === $count ) {
				continue;
			}
			$list[] = array(
				'club'    => $club,
				'teams'   => $teams,
				'players' => $count,
			);
		}
		return $list;
	}

	/**
	 * Club-Detail für eine Saison.
	 *
	 * @return array{
	 *   teams:list<array<string,mixed>>,
	 *   venues:list<array<string,mixed>>,
	 *   roles:list<array{role:string,name:string,team_id:?int,player:?array<string,mixed>}>,
	 *   players:list<array<string,mixed>>
	 * }
	 */
	public function details( int $club_id, int $season_id ): array {
		return $this->cache->remember(
			'club_details',
			array( $club_id, $season_id ),
			function () use ( $club_id, $season_id ): array {
				$season = $this->championship->season( $season_id );
				$teams  = array_values( array_filter( $season['teams'], static fn( array $t ): bool => $t['club_id'] === $club_id ) );
				usort( $teams, static fn( array $a, array $b ): int => $a['number'] <=> $b['number'] );

				$venues = array();
				foreach ( $this->db->get_results(
					"SELECT id, name, street, zip, city, weekday, open_time, close_time, tables_count, note, active
					FROM {$this->db->table( 'venue' )} WHERE club_id = %d ORDER BY active DESC, name",
					array( $club_id )
				) as $row ) {
					$id         = (int) $row['id'];
					$venue_team = array_values( array_filter( $season['teams'], static fn( array $t ): bool => $t['home_venue_id'] === $id ) );
					$times      = array();
					foreach ( $venue_team as $t ) {
						if ( null !== $t['default_start_time'] && '' !== $t['default_start_time'] ) {
							$times[ substr( (string) $t['default_start_time'], 0, 5 ) ] = true;
						}
					}
					ksort( $times );
					if ( '1' !== (string) $row['active'] && array() === $venue_team ) {
						continue;
					}
					$venues[] = array(
						'id'          => $id,
						'name'        => (string) $row['name'],
						'street'      => $row['street'],
						'zip'         => $row['zip'],
						'city'        => $row['city'],
						'weekday'     => Sql::int_or_null( $row['weekday'] ),
						'open_time'   => null === $row['open_time'] ? null : substr( (string) $row['open_time'], 0, 5 ),
						'close_time'  => null === $row['close_time'] ? null : substr( (string) $row['close_time'], 0, 5 ),
						'start_times' => array_keys( $times ),
						'tables'      => Sql::int_or_null( $row['tables_count'] ),
						'note'        => $row['note'],
						'teams'       => $venue_team,
					);
				}

				$roles = array();
				foreach ( $this->db->get_results(
					"SELECT cr.role, cr.team_id, pe.first_name, pe.last_name, p.id AS player_id, p.license_no
					FROM {$this->db->table( 'club_role' )} cr
					JOIN {$this->db->table( 'person' )} pe ON pe.id = cr.person_id
					LEFT JOIN {$this->db->table( 'player' )} p ON p.person_id = pe.id
					WHERE cr.season_id = %d AND cr.club_id = %d
					ORDER BY cr.role = 'tk_chef' DESC, pe.last_name, pe.first_name",
					array( $season_id, $club_id )
				) as $row ) {
					$roles[] = array(
						'role'    => (string) $row['role'],
						'name'    => Names::full( $row['first_name'], $row['last_name'] ),
						'team_id' => Sql::int_or_null( $row['team_id'] ),
						'player'  => null === $row['player_id'] ? null : Names::player( $row ),
					);
				}

				return array(
					'teams'   => $teams,
					'venues'  => $venues,
					'roles'   => $roles,
					'players' => Players::filter( $this->players->season_list( $season_id ), $club_id, '' ),
				);
			}
		);
	}
}
