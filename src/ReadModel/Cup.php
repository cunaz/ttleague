<?php
/**
 * Mannschaftscup: Meldelisten und Resultate pro Kategorie.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\ReadModel;

use TTLeague\Infrastructure\Database\Db;
use TTLeague\ReadModel\Cache\Cache;
use TTLeague\ReadModel\Support\Sql;

final class Cup {

	public function __construct( private readonly Db $db, private readonly Cache $cache, private readonly Players $players ) {}

	/**
	 * Kategorien des Cups einer Saison mit Meldungen (nach Setzung), Begegnungen (chronologisch)
	 * und dem Tableau (Paarungen nach Runde und Platz). Ohne Auslosung, etwa in importierten
	 * Saisons, ist das Tableau leer («ties» ohne Einträge, «rounds» 0).
	 *
	 * @return list<array{id:int,name:string,category:string,entries:list<array<string,mixed>>,fixtures:list<array<string,mixed>>,ties:list<array<string,mixed>>,rounds:int}>
	 */
	public function season( int $season_id ): array {
		return $this->cache->remember(
			'cup_season',
			array( $season_id ),
			fn(): array => $this->load( $season_id )
		);
	}

	/**
	 * @return list<array<string,mixed>>
	 */
	private function load( int $season_id ): array {
		$categories = array();
		foreach ( $this->db->get_results(
			"SELECT id, name, category FROM {$this->db->table( 'competition' )} WHERE season_id = %d AND type = 'CUP' ORDER BY category, sort_order, id",
			array( $season_id )
		) as $row ) {
			$categories[ (int) $row['id'] ] = array(
				'id'       => (int) $row['id'],
				'name'     => (string) $row['name'],
				'category' => strtolower( (string) $row['category'] ),
				'entries'  => array(),
				'fixtures' => array(),
				'ties'     => array(),
				'rounds'   => 0,
			);
		}
		if ( array() === $categories ) {
			return array();
		}
		$ids = array_keys( $categories );

		$entry_rows = $this->db->get_results(
			"SELECT e.id, e.competition_id, e.name, e.number, e.player1_id, e.player2_id, e.points_total, e.seed, e.status,
				c.short_name AS club_short, c.slug AS club_slug
			FROM {$this->db->table( 'cup_entry' )} e
			JOIN {$this->db->table( 'club' )} c ON c.id = e.club_id
			WHERE e.competition_id IN (" . Sql::ints( $ids ) . ')
			ORDER BY e.seed IS NULL, e.seed, e.name',
			$ids
		);
		$player_ids = array();
		foreach ( $entry_rows as $row ) {
			$player_ids[] = $row['player1_id'];
			$player_ids[] = $row['player2_id'];
		}
		$refs    = $this->players->refs( Sql::ids( $player_ids ), $season_id );
		$entries = array();
		foreach ( $entry_rows as $row ) {
			$members = array();
			foreach ( array( 'player1_id', 'player2_id' ) as $col ) {
				$pid = Sql::int_or_null( $row[ $col ] );
				if ( null !== $pid && isset( $refs[ $pid ] ) ) {
					$members[] = $refs[ $pid ];
				}
			}
			$entry = array(
				'id'           => (int) $row['id'],
				'name'         => (string) $row['name'],
				'seed'         => Sql::int_or_null( $row['seed'] ),
				'points_total' => Sql::int_or_null( $row['points_total'] ),
				'status'       => (string) $row['status'],
				'club_short'   => (string) $row['club_short'],
				'club_slug'    => (string) $row['club_slug'],
				'players'      => $members,
			);

			$entries[ $entry['id'] ]                                 = $entry;
			$categories[ (int) $row['competition_id'] ]['entries'][] = $entry;
		}

		foreach ( $this->db->get_results(
			"SELECT f.id, f.competition_id, f.round, f.match_no, f.scheduled_at, f.status, f.home_score, f.away_score,
				f.home_entry_id, f.away_entry_id,
				(SELECT COUNT(*) FROM {$this->db->table( 'game' )} g WHERE g.fixture_id = f.id) AS games
			FROM {$this->db->table( 'fixture' )} f
			WHERE f.competition_id IN (" . Sql::ints( $ids ) . ')
			ORDER BY f.scheduled_at IS NULL, f.scheduled_at, f.match_no, f.id',
			$ids
		) as $row ) {
			$home = Sql::int_or_null( $row['home_entry_id'] );
			$away = Sql::int_or_null( $row['away_entry_id'] );

			$categories[ (int) $row['competition_id'] ]['fixtures'][] = array(
				'id'           => (int) $row['id'],
				'round'        => Sql::int_or_null( $row['round'] ),
				'scheduled_at' => $row['scheduled_at'],
				'status'       => (string) $row['status'],
				'home_score'   => Sql::int_or_null( $row['home_score'] ),
				'away_score'   => Sql::int_or_null( $row['away_score'] ),
				'home'         => null === $home ? null : ( $entries[ $home ] ?? null ),
				'away'         => null === $away ? null : ( $entries[ $away ] ?? null ),
				'games'        => (int) $row['games'],
			);
		}

		foreach ( $this->db->get_results(
			"SELECT t.id, t.competition_id, t.round, t.slot, t.home_entry_id, t.away_entry_id, t.deadline,
				f.id AS fixture_id, f.status, f.scheduled_at, f.home_score, f.away_score,
				f.home_entry_id AS fixture_home, f.away_entry_id AS fixture_away, v.name AS venue
			FROM {$this->db->table( 'cup_tie' )} t
			LEFT JOIN {$this->db->table( 'fixture' )} f ON f.id = t.fixture_id
			LEFT JOIN {$this->db->table( 'venue' )} v ON v.id = f.venue_id
			WHERE t.competition_id IN (" . Sql::ints( $ids ) . ')
			ORDER BY t.round, t.slot',
			$ids
		) as $row ) {
			$home    = Sql::int_or_null( $row['home_entry_id'] );
			$away    = Sql::int_or_null( $row['away_entry_id'] );
			$fixture = null;
			if ( null !== $row['fixture_id'] ) {
				$fixture = array(
					'id'           => (int) $row['fixture_id'],
					'status'       => (string) $row['status'],
					'scheduled_at' => $row['scheduled_at'],
					'home_score'   => Sql::int_or_null( $row['home_score'] ),
					'away_score'   => Sql::int_or_null( $row['away_score'] ),
					'home_id'      => Sql::int_or_null( $row['fixture_home'] ),
					'away_id'      => Sql::int_or_null( $row['fixture_away'] ),
					'venue'        => null === $row['venue'] ? null : (string) $row['venue'],
				);
			}
			$competition = (int) $row['competition_id'];
			$round       = (int) $row['round'];

			$categories[ $competition ]['rounds'] = max( $categories[ $competition ]['rounds'], $round );
			$categories[ $competition ]['ties'][] = array(
				'id'       => (int) $row['id'],
				'round'    => $round,
				'slot'     => (int) $row['slot'],
				'deadline' => null === $row['deadline'] ? null : substr( $row['deadline'], 0, 10 ),
				'home'     => null === $home ? null : ( $entries[ $home ] ?? null ),
				'away'     => null === $away ? null : ( $entries[ $away ] ?? null ),
				'fixture'  => $fixture,
			);
		}

		return array_values( $categories );
	}
}
