<?php
/**
 * Matchreport einer Begegnung (Meisterschaft und Cup).
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\ReadModel;

use TTLeague\Domain\Match\GameOrder;
use TTLeague\Infrastructure\Database\Db;
use TTLeague\ReadModel\Cache\Cache;
use TTLeague\ReadModel\Support\Names;
use TTLeague\ReadModel\Support\Slug;
use TTLeague\ReadModel\Support\Sql;

/**
 * Struktur:
 * array{
 *   id:int, kind:string ('MM'|'CUP'), season:array{id:int,slug:string,name:string},
 *   competition:array{id:int,name:string,category:?string,group_no:int,slug:?string},
 *   round:?int, leg:?int, match_no:?int, scheduled_at:?string, status:string, note:?string,
 *   home:array{name:string,team_id:?int,entry_id:?int,slug:?string,club_short:?string,club_slug:?string},
 *   away:array{…}, venue:?array{name:string,street:?string,zip:?string,city:?string},
 *   home_score:?int, away_score:?int,
 *   lineup:array{home:list<array>,away:list<array>},
 *   games:list<array>, totals:array{sets_home:int,sets_away:int,wins_home:int,wins_away:int,points_home:?int,points_away:?int}
 * }
 */
final class MatchReports {

	public function __construct( private readonly Db $db, private readonly Cache $cache, private readonly Players $players ) {}

	/**
	 * @return array<string,mixed>|null
	 */
	public function get( int $fixture_id ): ?array {
		return $this->cache->remember(
			'match_report',
			array( $fixture_id ),
			fn(): ?array => $this->load( $fixture_id )
		);
	}

	/**
	 * @return array<string,mixed>|null
	 */
	private function load( int $fixture_id ): ?array {
		$row = $this->db->get_row(
			"SELECT f.*, co.type AS competition_type, co.name AS competition_name, co.category AS competition_category,
				co.group_no AS competition_group_no, co.season_id, s.slug AS season_slug, s.name AS season_name,
				ht.name AS home_team_name, ht.home_venue_id AS home_team_venue_id, hc.short_name AS home_club_short, hc.slug AS home_club_slug,
				at.name AS away_team_name, ac.short_name AS away_club_short, ac.slug AS away_club_slug,
				he.name AS home_entry_name, hec.short_name AS home_entry_club_short, hec.slug AS home_entry_club_slug,
				ae.name AS away_entry_name, aec.short_name AS away_entry_club_short, aec.slug AS away_entry_club_slug
			FROM {$this->db->table( 'fixture' )} f
			JOIN {$this->db->table( 'competition' )} co ON co.id = f.competition_id
			JOIN {$this->db->table( 'season' )} s ON s.id = co.season_id
			LEFT JOIN {$this->db->table( 'team' )} ht ON ht.id = f.home_team_id
			LEFT JOIN {$this->db->table( 'club' )} hc ON hc.id = ht.club_id
			LEFT JOIN {$this->db->table( 'team' )} at ON at.id = f.away_team_id
			LEFT JOIN {$this->db->table( 'club' )} ac ON ac.id = at.club_id
			LEFT JOIN {$this->db->table( 'cup_entry' )} he ON he.id = f.home_entry_id
			LEFT JOIN {$this->db->table( 'club' )} hec ON hec.id = he.club_id
			LEFT JOIN {$this->db->table( 'cup_entry' )} ae ON ae.id = f.away_entry_id
			LEFT JOIN {$this->db->table( 'club' )} aec ON aec.id = ae.club_id
			WHERE f.id = %d",
			array( $fixture_id )
		);
		if ( null === $row || ! in_array( $row['competition_type'], array( 'MM', 'CUP' ), true ) ) {
			return null;
		}

		$kind      = (string) $row['competition_type'];
		$season_id = (int) $row['season_id'];
		$group_no  = max( 1, (int) $row['competition_group_no'] );

		$venue_id = Sql::int_or_null( $row['venue_id'] ) ?? Sql::int_or_null( $row['home_team_venue_id'] );
		$venue    = null;
		if ( null !== $venue_id ) {
			$venue_row = $this->db->get_row( "SELECT name, street, zip, city FROM {$this->db->table( 'venue' )} WHERE id = %d", array( $venue_id ) );
			if ( null !== $venue_row ) {
				$venue = array(
					'name'   => (string) $venue_row['name'],
					'street' => $venue_row['street'],
					'zip'    => $venue_row['zip'],
					'city'   => $venue_row['city'],
				);
			}
		}

		$lineup = array(
			'home' => array(),
			'away' => array(),
		);
		foreach ( $this->db->get_results(
			"SELECT l.side, l.slot, l.player_id, l.is_substitute, p.license_no, pe.first_name, pe.last_name, ps.classification
			FROM {$this->db->table( 'lineup' )} l
			JOIN {$this->db->table( 'player' )} p ON p.id = l.player_id
			JOIN {$this->db->table( 'person' )} pe ON pe.id = p.person_id
			LEFT JOIN {$this->db->table( 'player_season' )} ps ON ps.player_id = l.player_id AND ps.season_id = %d
			WHERE l.fixture_id = %d
			ORDER BY l.slot",
			array( $season_id, $fixture_id )
		) as $lrow ) {
			$side = 'away' === $lrow['side'] ? 'away' : 'home';

			$lineup[ $side ][] = array(
				'slot'           => (string) $lrow['slot'],
				'is_substitute'  => '1' === (string) $lrow['is_substitute'],
				'player'         => Names::player( $lrow ),
				'classification' => Sql::int_or_null( $lrow['classification'] ),
			);
		}

		$game_rows  = $this->db->get_results(
			"SELECT id, sequence, type, a1_player_id, a2_player_id, b1_player_id, b2_player_id, sets_a, sets_b, status, counts_for_result
			FROM {$this->db->table( 'game' )} WHERE fixture_id = %d ORDER BY sequence, id",
			array( $fixture_id )
		);
		$set_scores = array();
		if ( array() !== $game_rows ) {
			$game_ids = array_map( static fn( array $g ): int => (int) $g['id'], $game_rows );
			foreach ( $this->db->get_results(
				"SELECT game_id, set_no, points_a, points_b FROM {$this->db->table( 'game_set' )} WHERE game_id IN (" . Sql::ints( $game_ids ) . ') ORDER BY game_id, set_no',
				$game_ids
			) as $srow ) {
				$set_scores[ (int) $srow['game_id'] ][] = array(
					'a' => (int) $srow['points_a'],
					'b' => (int) $srow['points_b'],
				);
			}
		}

		$player_ids = array();
		foreach ( $game_rows as $g ) {
			foreach ( array( 'a1_player_id', 'a2_player_id', 'b1_player_id', 'b2_player_id' ) as $col ) {
				$player_ids[] = $g[ $col ];
			}
		}
		$refs = $this->players->refs( Sql::ids( $player_ids ), $season_id );

		$order = array();
		foreach ( 'CUP' === $kind ? GameOrder::cup() : GameOrder::team_match() as $slot ) {
			$order[ $slot['sequence'] ] = $slot;
		}

		$games       = array();
		$totals      = array(
			'sets_home'   => 0,
			'sets_away'   => 0,
			'wins_home'   => 0,
			'wins_away'   => 0,
			'points_home' => null,
			'points_away' => null,
		);
		$points_home = 0;
		$points_away = 0;
		$points_ok   = true;
		$played      = 0;
		foreach ( $game_rows as $g ) {
			$id       = (int) $g['id'];
			$status   = (string) $g['status'];
			$sets_a   = (int) $g['sets_a'];
			$sets_b   = (int) $g['sets_b'];
			$counts   = '1' === (string) $g['counts_for_result'];
			$sequence = (int) $g['sequence'];
			$winner   = null;
			if ( 'not_played' !== $status && $sets_a !== $sets_b ) {
				$winner = $sets_a > $sets_b ? 'a' : 'b';
			}
			if ( 'played' === $status ) {
				++$played;
				$totals['sets_home'] += $sets_a;
				$totals['sets_away'] += $sets_b;
				if ( isset( $set_scores[ $id ] ) ) {
					foreach ( $set_scores[ $id ] as $set ) {
						$points_home += $set['a'];
						$points_away += $set['b'];
					}
				} else {
					$points_ok = false;
				}
			}
			if ( $counts && 'a' === $winner ) {
				++$totals['wins_home'];
			} elseif ( $counts && 'b' === $winner ) {
				++$totals['wins_away'];
			}
			$side_a = array();
			$side_b = array();
			foreach ( array( 'a1_player_id', 'a2_player_id' ) as $col ) {
				$pid = Sql::int_or_null( $g[ $col ] );
				if ( null !== $pid && isset( $refs[ $pid ] ) ) {
					$side_a[] = $refs[ $pid ];
				}
			}
			foreach ( array( 'b1_player_id', 'b2_player_id' ) as $col ) {
				$pid = Sql::int_or_null( $g[ $col ] );
				if ( null !== $pid && isset( $refs[ $pid ] ) ) {
					$side_b[] = $refs[ $pid ];
				}
			}
			$games[] = array(
				'sequence'          => $sequence,
				'type'              => 'double' === $g['type'] ? 'double' : 'single',
				'status'            => $status,
				'counts_for_result' => $counts,
				'sets_a'            => $sets_a,
				'sets_b'            => $sets_b,
				'winner'            => $winner,
				'a'                 => $side_a,
				'b'                 => $side_b,
				'slot_home'         => $order[ $sequence ]['home'] ?? null,
				'slot_away'         => $order[ $sequence ]['away'] ?? null,
				'set_scores'        => $set_scores[ $id ] ?? array(),
			);
		}
		if ( $points_ok && $played > 0 ) {
			$totals['points_home'] = $points_home;
			$totals['points_away'] = $points_away;
		}

		$home_name = 'CUP' === $kind ? (string) $row['home_entry_name'] : (string) $row['home_team_name'];
		$away_name = 'CUP' === $kind ? (string) $row['away_entry_name'] : (string) $row['away_team_name'];

		return array(
			'id'           => (int) $row['id'],
			'kind'         => $kind,
			'season'       => array(
				'id'   => $season_id,
				'slug' => (string) $row['season_slug'],
				'name' => (string) $row['season_name'],
			),
			'competition'  => array(
				'id'       => (int) $row['competition_id'],
				'name'     => (string) $row['competition_name'],
				'category' => $row['competition_category'],
				'group_no' => $group_no,
				'slug'     => 'MM' === $kind ? Championship::group_slug( $row['competition_category'], $group_no, (int) $row['competition_id'] ) : null,
			),
			'round'        => Sql::int_or_null( $row['round'] ),
			'leg'          => Sql::int_or_null( $row['leg'] ),
			'match_no'     => Sql::int_or_null( $row['match_no'] ),
			'scheduled_at' => $row['scheduled_at'],
			'status'       => (string) $row['status'],
			'note'         => $row['note'],
			'home'         => array(
				'name'       => $home_name,
				'team_id'    => Sql::int_or_null( $row['home_team_id'] ),
				'entry_id'   => Sql::int_or_null( $row['home_entry_id'] ),
				'slug'       => '' === $home_name ? null : Slug::make( $home_name ),
				'club_short' => 'CUP' === $kind ? $row['home_entry_club_short'] : $row['home_club_short'],
				'club_slug'  => 'CUP' === $kind ? $row['home_entry_club_slug'] : $row['home_club_slug'],
			),
			'away'         => array(
				'name'       => $away_name,
				'team_id'    => Sql::int_or_null( $row['away_team_id'] ),
				'entry_id'   => Sql::int_or_null( $row['away_entry_id'] ),
				'slug'       => '' === $away_name ? null : Slug::make( $away_name ),
				'club_short' => 'CUP' === $kind ? $row['away_entry_club_short'] : $row['away_club_short'],
				'club_slug'  => 'CUP' === $kind ? $row['away_entry_club_slug'] : $row['away_club_slug'],
			),
			'venue'        => $venue,
			'home_score'   => Sql::int_or_null( $row['home_score'] ),
			'away_score'   => Sql::int_or_null( $row['away_score'] ),
			'lineup'       => $lineup,
			'games'        => $games,
			'totals'       => $totals,
		);
	}
}
