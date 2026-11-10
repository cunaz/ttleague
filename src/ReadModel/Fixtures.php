<?php
/**
 * Begegnungslisten über alle Konkurrenzen: letzte Resultate und nächste Termine.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\ReadModel;

use TTLeague\Infrastructure\Database\Db;
use TTLeague\ReadModel\Cache\Cache;
use TTLeague\ReadModel\Support\Slug;
use TTLeague\ReadModel\Support\Sql;

/**
 * Eintrag: array{id:int,kind:string,season_slug:string,season_name:string,competition:string,
 * category:?string,group_no:int,scheduled_at:?string,status:string,home:string,away:string,
 * home_slug:string,away_slug:string,home_score:?int,away_score:?int,venue:?string}
 */
final class Fixtures {

	public function __construct( private readonly Db $db, private readonly Cache $cache ) {}

	/**
	 * Zuletzt gespielte Begegnungen (Meisterschaft und Cup), neueste zuerst.
	 *
	 * @param int      $limit     Anzahl.
	 * @param int|null $season_id Nur diese Saison.
	 * @return list<array<string,mixed>>
	 */
	public function latest( int $limit, ?int $season_id = null ): array {
		return $this->cache->remember(
			'fixtures_latest',
			array( $limit, $season_id ),
			function () use ( $limit, $season_id ): array {
				$statuses = Sql::COUNTED_STATUSES;
				$where    = 'f.status IN (' . Sql::strings( $statuses ) . ') AND f.home_score IS NOT NULL';
				$args     = $statuses;
				if ( null !== $season_id ) {
					$where .= ' AND co.season_id = %d';
					$args[] = $season_id;
				}
				return $this->query( $where, 'f.scheduled_at DESC, f.id DESC', $args, $limit );
			}
		);
	}

	/**
	 * Nächste angesetzte Begegnungen ab einem Zeitpunkt (Ortszeit), früheste zuerst.
	 *
	 * @param int      $limit     Anzahl.
	 * @param string   $now       Zeitpunkt «Y-m-d H:i:s» in Ortszeit.
	 * @param int|null $season_id Nur diese Saison.
	 * @return list<array<string,mixed>>
	 */
	public function upcoming( int $limit, string $now, ?int $season_id = null ): array {
		$day = substr( $now, 0, 10 ) . ' 00:00:00';
		return $this->cache->remember(
			'fixtures_upcoming',
			array( $limit, $day, $season_id ),
			function () use ( $limit, $day, $season_id ): array {
				$where = "f.status IN ('scheduled', 'postponed') AND f.scheduled_at >= %s";
				$args  = array( $day );
				if ( null !== $season_id ) {
					$where .= ' AND co.season_id = %d';
					$args[] = $season_id;
				}
				return $this->query( $where, 'f.scheduled_at ASC, f.id ASC', $args, $limit );
			}
		);
	}

	/**
	 * @param string           $where Bedingung mit Platzhaltern.
	 * @param string           $order Sortierung (fest im Code).
	 * @param list<int|string> $args  Werte für die Platzhalter.
	 * @param int              $limit Anzahl.
	 * @return list<array<string,mixed>>
	 */
	private function query( string $where, string $order, array $args, int $limit ): array {
		$limit  = max( 1, $limit );
		$rows   = $this->db->get_results(
			"SELECT f.id, f.scheduled_at, f.status, f.home_score, f.away_score, co.type, co.name AS competition, co.category, co.group_no,
				s.slug AS season_slug, s.name AS season_name,
				COALESCE(ht.name, he.name) AS home_name, COALESCE(at.name, ae.name) AS away_name, v.name AS venue_name
			FROM {$this->db->table( 'fixture' )} f
			JOIN {$this->db->table( 'competition' )} co ON co.id = f.competition_id
			JOIN {$this->db->table( 'season' )} s ON s.id = co.season_id
			LEFT JOIN {$this->db->table( 'team' )} ht ON ht.id = f.home_team_id
			LEFT JOIN {$this->db->table( 'team' )} at ON at.id = f.away_team_id
			LEFT JOIN {$this->db->table( 'cup_entry' )} he ON he.id = f.home_entry_id
			LEFT JOIN {$this->db->table( 'cup_entry' )} ae ON ae.id = f.away_entry_id
			LEFT JOIN {$this->db->table( 'venue' )} v ON v.id = COALESCE(f.venue_id, ht.home_venue_id)
			WHERE co.type IN ('MM', 'CUP') AND s.status <> 'planned' AND {$where}
			ORDER BY {$order}
			LIMIT %d",
			array_merge( $args, array( $limit ) )
		);
		$result = array();
		foreach ( $rows as $row ) {
			$result[] = array(
				'id'           => (int) $row['id'],
				'kind'         => (string) $row['type'],
				'season_slug'  => (string) $row['season_slug'],
				'season_name'  => (string) $row['season_name'],
				'competition'  => (string) $row['competition'],
				'category'     => $row['category'],
				'group_no'     => max( 1, (int) $row['group_no'] ),
				'scheduled_at' => $row['scheduled_at'],
				'status'       => (string) $row['status'],
				'home'         => (string) $row['home_name'],
				'away'         => (string) $row['away_name'],
				'home_slug'    => Slug::make( (string) $row['home_name'] ),
				'away_slug'    => Slug::make( (string) $row['away_name'] ),
				'home_score'   => Sql::int_or_null( $row['home_score'] ),
				'away_score'   => Sql::int_or_null( $row['away_score'] ),
				'venue'        => $row['venue_name'],
			);
		}
		return $result;
	}
}
