<?php
/**
 * Öffentliche Saisons: Liste, aktive Saison, Regeln.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\ReadModel;

use TTLeague\Domain\Rules\SeasonRules;
use TTLeague\Infrastructure\Database\Db;
use TTLeague\ReadModel\Cache\Cache;

/**
 * Eine Saison ist ein Array
 * array{id:int,name:string,slug:string,start_date:string,end_date:string,status:string,rules:array<string,mixed>}.
 *
 * Geplante Saisons (Status «planned») sind nicht öffentlich und kommen hier nicht vor: weder in
 * der Saisonwahl noch über Slug oder ID. Die Schreibseite liest sie über Season\SeasonRepository.
 */
final class Seasons {

	public function __construct( private readonly Db $db, private readonly Cache $cache ) {}

	/**
	 * Alle öffentlichen Saisons (aktiv oder archiviert), neueste zuerst.
	 *
	 * @return list<array{id:int,name:string,slug:string,start_date:string,end_date:string,status:string,rules:array<string,mixed>}>
	 */
	public function all(): array {
		return $this->cache->remember(
			'seasons',
			array(),
			function (): array {
				$rows    = $this->db->get_results( "SELECT id, name, slug, start_date, end_date, status, rules_json FROM {$this->db->table( 'season' )} WHERE status <> 'planned' ORDER BY start_date DESC, id DESC" );
				$seasons = array();
				foreach ( $rows as $row ) {
					$rules     = json_decode( (string) $row['rules_json'], true );
					$seasons[] = array(
						'id'         => (int) $row['id'],
						'name'       => (string) $row['name'],
						'slug'       => (string) $row['slug'],
						'start_date' => (string) $row['start_date'],
						'end_date'   => (string) $row['end_date'],
						'status'     => (string) $row['status'],
						'rules'      => is_array( $rules ) ? $rules : array(),
					);
				}
				return $seasons;
			}
		);
	}

	/**
	 * @return array{id:int,name:string,slug:string,start_date:string,end_date:string,status:string,rules:array<string,mixed>}|null
	 */
	public function by_slug( string $slug ): ?array {
		foreach ( $this->all() as $season ) {
			if ( $season['slug'] === $slug ) {
				return $season;
			}
		}
		return null;
	}

	/**
	 * @return array{id:int,name:string,slug:string,start_date:string,end_date:string,status:string,rules:array<string,mixed>}|null
	 */
	public function by_id( int $id ): ?array {
		foreach ( $this->all() as $season ) {
			if ( $season['id'] === $id ) {
				return $season;
			}
		}
		return null;
	}

	/**
	 * Die aktive Saison; ohne aktive die neueste archivierte.
	 *
	 * @return array{id:int,name:string,slug:string,start_date:string,end_date:string,status:string,rules:array<string,mixed>}|null
	 */
	public function active(): ?array {
		$all = $this->all();
		foreach ( $all as $season ) {
			if ( 'active' === $season['status'] ) {
				return $season;
			}
		}
		return $all[0] ?? null;
	}

	/**
	 * Regeln einer Saison aus rules_json; fehlende Werte erhalten die Standardwerte.
	 *
	 * @param array{rules:array<string,mixed>} $season Saison.
	 */
	public function rules( array $season ): SeasonRules {
		try {
			return SeasonRules::from_array( $season['rules'] );
		} catch ( \InvalidArgumentException ) {
			return new SeasonRules();
		}
	}
}
