<?php
/**
 * Spieler: Saisonliste, Suche über Lizenznummer, Namensauflösung.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\ReadModel;

use TTLeague\Infrastructure\Database\Db;
use TTLeague\ReadModel\Cache\Cache;
use TTLeague\ReadModel\Support\Names;
use TTLeague\ReadModel\Support\Slug;
use TTLeague\ReadModel\Support\Sql;

final class Players {

	public function __construct( private readonly Db $db, private readonly Cache $cache ) {}

	/**
	 * Gemeldete Spieler einer Saison mit Club, Klassierung und aktueller Wertungszahl,
	 * sortiert nach Nachname und Vorname. Provisorisch gemeldete (Nachmeldung, die die TK noch
	 * nicht freigegeben hat, docs/meldung.md) gelten öffentlich noch nicht als gemeldet.
	 *
	 * @return list<array{player:array<string,mixed>,classification:int,status:string,club_id:int,club_short:string,club_slug:string,rating:?float,rating_games:int}>
	 */
	public function season_list( int $season_id ): array {
		return $this->cache->remember(
			'players_season',
			array( $season_id ),
			function () use ( $season_id ): array {
				$rows = $this->db->get_results(
					"SELECT p.id AS player_id, p.license_no, pe.first_name, pe.last_name, ps.classification, ps.status, ps.club_id,
						c.short_name AS club_short, c.slug AS club_slug, r.rating, r.games_count
					FROM {$this->db->table( 'player_season' )} ps
					JOIN {$this->db->table( 'player' )} p ON p.id = ps.player_id
					JOIN {$this->db->table( 'person' )} pe ON pe.id = p.person_id
					JOIN {$this->db->table( 'club' )} c ON c.id = ps.club_id
					LEFT JOIN {$this->db->table( 'player_rating' )} r ON r.player_id = p.id
					WHERE ps.season_id = %d AND ps.status <> 'provisional'",
					array( $season_id )
				);
				$list = array();
				foreach ( $rows as $row ) {
					$list[] = array(
						'player'         => Names::player( $row ),
						'classification' => (int) $row['classification'],
						'status'         => (string) $row['status'],
						'club_id'        => (int) $row['club_id'],
						'club_short'     => (string) $row['club_short'],
						'club_slug'      => (string) $row['club_slug'],
						'rating'         => null === $row['rating'] ? null : (float) $row['rating'],
						'rating_games'   => (int) $row['games_count'],
					);
				}
				usort( $list, static fn( array $a, array $b ): int => strcmp( Names::sort_key( $a['player'] ), Names::sort_key( $b['player'] ) ) );
				return $list;
			}
		);
	}

	/**
	 * Filtert eine Saisonliste nach Club-ID und Namensteil (ohne Gross-/Kleinschreibung und Akzente).
	 *
	 * @param list<array<string,mixed>> $rows    Saisonliste.
	 * @param int|null                  $club_id Club oder null.
	 * @param string                    $search  Suchtext.
	 * @return list<array<string,mixed>>
	 */
	public static function filter( array $rows, ?int $club_id, string $search ): array {
		$needles = array_unique( array_filter( array( Slug::make( $search ), Slug::fold( $search ) ) ) );
		return array_values(
			array_filter(
				$rows,
				static function ( array $row ) use ( $club_id, $needles ): bool {
					if ( null !== $club_id && $row['club_id'] !== $club_id ) {
						return false;
					}
					if ( array() === $needles ) {
						return true;
					}
					$name = $row['player']['first_name'] . ' ' . $row['player']['last_name'];
					$rev  = $row['player']['last_name'] . ' ' . $row['player']['first_name'];
					$hay  = implode( '|', array( Slug::make( $name ), Slug::make( $rev ), Slug::fold( $name ), Slug::fold( $rev ), (string) $row['player']['license'] ) );
					foreach ( $needles as $needle ) {
						if ( str_contains( $hay, $needle ) ) {
							return true;
						}
					}
					return false;
				}
			)
		);
	}

	/**
	 * Spieler über die Lizenznummer.
	 *
	 * @return array{id:int,license:int,first_name:string,last_name:string,name:string,slug:string,person_id:int}|null
	 */
	public function by_license( int $license ): ?array {
		return $this->cache->remember(
			'player_license',
			array( $license ),
			function () use ( $license ): ?array {
				$row = $this->db->get_row(
					"SELECT p.id AS player_id, p.license_no, p.person_id, pe.first_name, pe.last_name
					FROM {$this->db->table( 'player' )} p
					JOIN {$this->db->table( 'person' )} pe ON pe.id = p.person_id
					WHERE p.license_no = %d",
					array( $license )
				);
				if ( null === $row ) {
					return null;
				}
				return Names::player( $row ) + array( 'person_id' => (int) $row['person_id'] );
			}
		);
	}

	/**
	 * Spielerreferenzen zu IDs, optional mit Klassierung einer Saison.
	 *
	 * @param list<int> $ids       Spieler-IDs.
	 * @param int|null  $season_id Saison für die Klassierung.
	 * @return array<int,array{id:int,license:int,first_name:string,last_name:string,name:string,slug:string,classification:?int,club_id:?int}>
	 */
	public function refs( array $ids, ?int $season_id = null ): array {
		$ids = Sql::ids( $ids );
		if ( array() === $ids ) {
			return array();
		}
		$args = $ids;
		if ( null === $season_id ) {
			$sql = "SELECT p.id AS player_id, p.license_no, pe.first_name, pe.last_name, NULL AS classification, NULL AS club_id
				FROM {$this->db->table( 'player' )} p
				JOIN {$this->db->table( 'person' )} pe ON pe.id = p.person_id
				WHERE p.id IN (" . Sql::ints( $ids ) . ')';
		} else {
			$sql = "SELECT p.id AS player_id, p.license_no, pe.first_name, pe.last_name, ps.classification, ps.club_id
				FROM {$this->db->table( 'player' )} p
				JOIN {$this->db->table( 'person' )} pe ON pe.id = p.person_id
				LEFT JOIN {$this->db->table( 'player_season' )} ps ON ps.player_id = p.id AND ps.season_id = %d
				WHERE p.id IN (" . Sql::ints( $ids ) . ')';
			array_unshift( $args, $season_id );
		}
		$refs = array();
		foreach ( $this->db->get_results( $sql, $args ) as $row ) {
			$ref                = Names::player( $row );
			$refs[ $ref['id'] ] = $ref + array(
				'classification' => Sql::int_or_null( $row['classification'] ),
				'club_id'        => Sql::int_or_null( $row['club_id'] ),
			);
		}
		return $refs;
	}
}
