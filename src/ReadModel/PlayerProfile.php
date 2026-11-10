<?php
/**
 * Spielerprofil: Klassierungen, Wertungszahl mit Verlauf, alle Spiele und Statistik.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\ReadModel;

use TTLeague\Infrastructure\Database\Db;
use TTLeague\ReadModel\Cache\Cache;
use TTLeague\ReadModel\Support\Sql;

/**
 * Ein Spiel aus Sicht des Spielers:
 * array{id:int,season_id:int,season_slug:string,season_name:string,date:?string,kind:string ('MM'|'CUP'|'RL'),
 *       competition:string,category:?string,group_no:?int,fixture_id:?int,rl_round_no:?int,rl_is_final:bool,
 *       rl_category:?int,rl_group_no:?int,type:string,status:string,counts_for_result:bool,
 *       partner:?array,opponents:list<array>,sets_for:int,sets_against:int,won:?bool,
 *       team:?string,opponent_team:?string}
 *
 * Bilanzen zählen nur Spiele mit status «played» (siehe docs/frontend.md).
 */
final class PlayerProfile {

	public function __construct( private readonly Db $db, private readonly Cache $cache, private readonly Players $players ) {}

	/**
	 * @return array{
	 *   seasons:list<array<string,mixed>>,
	 *   rating:?array{rating:float,games:int,updated_at:string},
	 *   history:list<array{at:string,before:float,after:float,delta:float,game_id:int}>,
	 *   games:list<array<string,mixed>>
	 * }
	 */
	public function get( int $player_id ): array {
		return $this->cache->remember(
			'player_profile',
			array( $player_id ),
			fn(): array => $this->load( $player_id )
		);
	}

	/**
	 * Bilanz pro Saison und Konkurrenz (Einzel, Doppel, Sätze), neueste Saison zuerst.
	 *
	 * @param list<array<string,mixed>> $games Spiele aus get().
	 * @return list<array{season_id:int,season_slug:string,season_name:string,kind:string,singles_won:int,singles_lost:int,doubles_won:int,doubles_lost:int,sets_won:int,sets_lost:int}>
	 */
	public static function balance( array $games ): array {
		$rows  = array();
		$order = array( 'MM', 'CUP', 'RL' );
		foreach ( $games as $game ) {
			if ( 'played' !== $game['status'] || null === $game['won'] ) {
				continue;
			}
			$key = $game['season_id'] . '|' . $game['kind'];
			if ( ! isset( $rows[ $key ] ) ) {
				$rows[ $key ] = array(
					'season_id'    => $game['season_id'],
					'season_slug'  => $game['season_slug'],
					'season_name'  => $game['season_name'],
					'kind'         => $game['kind'],
					'singles_won'  => 0,
					'singles_lost' => 0,
					'doubles_won'  => 0,
					'doubles_lost' => 0,
					'sets_won'     => 0,
					'sets_lost'    => 0,
				);
			}
			$prefix = 'double' === $game['type'] ? 'doubles_' : 'singles_';
			++$rows[ $key ][ $prefix . ( $game['won'] ? 'won' : 'lost' ) ];
			$rows[ $key ]['sets_won']  += $game['sets_for'];
			$rows[ $key ]['sets_lost'] += $game['sets_against'];
		}
		$rows = array_values( $rows );
		usort(
			$rows,
			static function ( array $a, array $b ) use ( $order ): int {
				$cmp = strcmp( $b['season_slug'], $a['season_slug'] );
				return 0 !== $cmp ? $cmp : array_search( $a['kind'], $order, true ) <=> array_search( $b['kind'], $order, true );
			}
		);
		return $rows;
	}

	/**
	 * Klassierungsauswertung: Einzel gegen jede Gegner-Klassierung (Klassierung des Gegners
	 * in der Saison des Spiels), stärkste Klassierung zuerst.
	 *
	 * @param list<array<string,mixed>> $games Spiele aus get().
	 * @return list<array{classification:int,won:int,lost:int}>
	 */
	public static function classification_analysis( array $games ): array {
		$rows = array();
		foreach ( $games as $game ) {
			if ( 'single' !== $game['type'] || 'played' !== $game['status'] || null === $game['won'] ) {
				continue;
			}
			$classification = $game['opponents'][0]['classification'] ?? null;
			if ( null === $classification ) {
				continue;
			}
			if ( ! isset( $rows[ $classification ] ) ) {
				$rows[ $classification ] = array(
					'classification' => $classification,
					'won'            => 0,
					'lost'           => 0,
				);
			}
			++$rows[ $classification ][ $game['won'] ? 'won' : 'lost' ];
		}
		krsort( $rows );
		return array_values( $rows );
	}

	/**
	 * @return array<string,mixed>
	 */
	private function load( int $player_id ): array {
		$seasons = array();
		foreach ( $this->db->get_results(
			"SELECT ps.season_id, ps.classification, ps.status, s.slug, s.name, s.start_date, c.short_name AS club_short, c.slug AS club_slug
			FROM {$this->db->table( 'player_season' )} ps
			JOIN {$this->db->table( 'season' )} s ON s.id = ps.season_id
			JOIN {$this->db->table( 'club' )} c ON c.id = ps.club_id
			WHERE ps.player_id = %d AND s.status <> 'planned'
			ORDER BY s.start_date DESC",
			array( $player_id )
		) as $row ) {
			$seasons[ (int) $row['season_id'] ] = array(
				'season_id'      => (int) $row['season_id'],
				'slug'           => (string) $row['slug'],
				'name'           => (string) $row['name'],
				'classification' => (int) $row['classification'],
				'status'         => (string) $row['status'],
				'club_short'     => (string) $row['club_short'],
				'club_slug'      => (string) $row['club_slug'],
				'teams'          => array(),
			);
		}
		foreach ( $this->db->get_results(
			"SELECT t.season_id, t.name, r.kind
			FROM {$this->db->table( 'team_roster' )} r
			JOIN {$this->db->table( 'team' )} t ON t.id = r.team_id
			WHERE r.player_id = %d
			ORDER BY t.name",
			array( $player_id )
		) as $row ) {
			$season_id = (int) $row['season_id'];
			if ( isset( $seasons[ $season_id ] ) ) {
				$seasons[ $season_id ]['teams'][] = array(
					'name' => (string) $row['name'],
					'kind' => (string) $row['kind'],
				);
			}
		}

		$rating_row = $this->db->get_row( "SELECT rating, games_count, updated_at FROM {$this->db->table( 'player_rating' )} WHERE player_id = %d", array( $player_id ) );
		$rating     = null === $rating_row ? null : array(
			'rating'     => (float) $rating_row['rating'],
			'games'      => (int) $rating_row['games_count'],
			'updated_at' => (string) $rating_row['updated_at'],
		);

		$history = array();
		foreach ( $this->db->get_results(
			"SELECT game_id, rating_before, rating_after, delta, played_at FROM {$this->db->table( 'rating_event' )} WHERE player_id = %d ORDER BY played_at, id",
			array( $player_id )
		) as $row ) {
			$history[] = array(
				'at'      => (string) $row['played_at'],
				'before'  => (float) $row['rating_before'],
				'after'   => (float) $row['rating_after'],
				'delta'   => (float) $row['delta'],
				'game_id' => (int) $row['game_id'],
			);
		}

		return array(
			'seasons' => array_values( $seasons ),
			'rating'  => $rating,
			'history' => $history,
			'games'   => $this->games( $player_id ),
		);
	}

	/**
	 * @return list<array<string,mixed>>
	 */
	private function games( int $player_id ): array {
		$rows = $this->db->get_results(
			"SELECT g.id, g.fixture_id, g.rl_group_id, g.sequence, g.type, g.a1_player_id, g.a2_player_id, g.b1_player_id, g.b2_player_id,
				g.sets_a, g.sets_b, g.status, g.counts_for_result, g.played_at, f.scheduled_at,
				cf.type AS f_type, cf.name AS f_name, cf.category AS f_category, cf.group_no AS f_group_no, cf.season_id AS f_season_id,
				ht.name AS home_team, at.name AS away_team, he.name AS home_entry, ae.name AS away_entry,
				rg.category AS rl_category, rg.group_no AS rl_group_no, rg.start_at AS rl_start_at,
				rr.round_no AS rl_round_no, rr.is_final AS rl_is_final, cr.season_id AS r_season_id, cr.name AS r_name
			FROM {$this->db->table( 'game' )} g
			LEFT JOIN {$this->db->table( 'fixture' )} f ON f.id = g.fixture_id
			LEFT JOIN {$this->db->table( 'competition' )} cf ON cf.id = f.competition_id
			LEFT JOIN {$this->db->table( 'team' )} ht ON ht.id = f.home_team_id
			LEFT JOIN {$this->db->table( 'team' )} at ON at.id = f.away_team_id
			LEFT JOIN {$this->db->table( 'cup_entry' )} he ON he.id = f.home_entry_id
			LEFT JOIN {$this->db->table( 'cup_entry' )} ae ON ae.id = f.away_entry_id
			LEFT JOIN {$this->db->table( 'rl_group' )} rg ON rg.id = g.rl_group_id
			LEFT JOIN {$this->db->table( 'rl_round' )} rr ON rr.id = rg.rl_round_id
			LEFT JOIN {$this->db->table( 'competition' )} cr ON cr.id = rr.competition_id
			WHERE g.a1_player_id = %d OR g.a2_player_id = %d OR g.b1_player_id = %d OR g.b2_player_id = %d",
			array( $player_id, $player_id, $player_id, $player_id )
		);
		if ( array() === $rows ) {
			return array();
		}

		$ids = array();
		foreach ( $rows as $row ) {
			foreach ( array( 'a1_player_id', 'a2_player_id', 'b1_player_id', 'b2_player_id' ) as $col ) {
				$ids[] = $row[ $col ];
			}
		}
		$ids  = Sql::ids( $ids );
		$refs = $this->players->refs( $ids );

		$classifications = array();
		foreach ( $this->db->get_results(
			"SELECT player_id, season_id, classification FROM {$this->db->table( 'player_season' )} WHERE player_id IN (" . Sql::ints( $ids ) . ')',
			$ids
		) as $row ) {
			$classifications[ (int) $row['player_id'] ][ (int) $row['season_id'] ] = (int) $row['classification'];
		}

		$season_names = array();
		foreach ( $this->db->get_results( "SELECT id, slug, name FROM {$this->db->table( 'season' )}" ) as $row ) {
			$season_names[ (int) $row['id'] ] = $row;
		}

		$games = array();
		foreach ( $rows as $row ) {
			$is_rl     = null !== $row['rl_group_id'];
			$season_id = (int) ( $is_rl ? $row['r_season_id'] : $row['f_season_id'] );
			if ( 0 === $season_id ) {
				continue;
			}
			$on_a    = in_array( $player_id, array( Sql::int_or_null( $row['a1_player_id'] ), Sql::int_or_null( $row['a2_player_id'] ) ), true );
			$mine    = $on_a ? array( 'a1_player_id', 'a2_player_id' ) : array( 'b1_player_id', 'b2_player_id' );
			$theirs  = $on_a ? array( 'b1_player_id', 'b2_player_id' ) : array( 'a1_player_id', 'a2_player_id' );
			$partner = null;
			foreach ( $mine as $col ) {
				$pid = Sql::int_or_null( $row[ $col ] );
				if ( null !== $pid && $pid !== $player_id && isset( $refs[ $pid ] ) ) {
					$partner                   = $refs[ $pid ];
					$partner['classification'] = $classifications[ $pid ][ $season_id ] ?? null;
				}
			}
			$opponents = array();
			foreach ( $theirs as $col ) {
				$pid = Sql::int_or_null( $row[ $col ] );
				if ( null !== $pid && isset( $refs[ $pid ] ) ) {
					$opponent                   = $refs[ $pid ];
					$opponent['classification'] = $classifications[ $pid ][ $season_id ] ?? null;
					$opponents[]                = $opponent;
				}
			}
			$sets_for     = (int) ( $on_a ? $row['sets_a'] : $row['sets_b'] );
			$sets_against = (int) ( $on_a ? $row['sets_b'] : $row['sets_a'] );
			$status       = (string) $row['status'];
			$won          = null;
			if ( 'not_played' !== $status && $sets_for !== $sets_against ) {
				$won = $sets_for > $sets_against;
			}

			if ( $is_rl ) {
				$kind        = 'RL';
				$competition = (string) $row['r_name'];
				$date        = $row['played_at'] ?? $row['rl_start_at'];
				$team        = null;
				$other_team  = null;
			} else {
				$kind        = 'CUP' === $row['f_type'] ? 'CUP' : 'MM';
				$competition = (string) $row['f_name'];
				$date        = $row['played_at'] ?? $row['scheduled_at'];
				$home        = 'CUP' === $kind ? $row['home_entry'] : $row['home_team'];
				$away        = 'CUP' === $kind ? $row['away_entry'] : $row['away_team'];
				$team        = $on_a ? $home : $away;
				$other_team  = $on_a ? $away : $home;
			}

			$games[] = array(
				'id'                => (int) $row['id'],
				'season_id'         => $season_id,
				'season_slug'       => (string) ( $season_names[ $season_id ]['slug'] ?? '' ),
				'season_name'       => (string) ( $season_names[ $season_id ]['name'] ?? '' ),
				'date'              => $date,
				'kind'              => $kind,
				'competition'       => $competition,
				'category'          => $is_rl ? null : $row['f_category'],
				'group_no'          => $is_rl ? null : max( 1, (int) $row['f_group_no'] ),
				'fixture_id'        => Sql::int_or_null( $row['fixture_id'] ),
				'sequence'          => (int) $row['sequence'],
				'rl_round_no'       => Sql::int_or_null( $row['rl_round_no'] ),
				'rl_is_final'       => '1' === (string) $row['rl_is_final'],
				'rl_category'       => Sql::int_or_null( $row['rl_category'] ),
				'rl_group_no'       => Sql::int_or_null( $row['rl_group_no'] ),
				'type'              => 'double' === $row['type'] ? 'double' : 'single',
				'status'            => $status,
				'counts_for_result' => '1' === (string) $row['counts_for_result'],
				'partner'           => $partner,
				'opponents'         => $opponents,
				'sets_for'          => $sets_for,
				'sets_against'      => $sets_against,
				'won'               => $won,
				'team'              => $team,
				'opponent_team'     => $other_team,
			);
		}

		usort(
			$games,
			static function ( array $a, array $b ): int {
				$cmp = strcmp( (string) $b['date'], (string) $a['date'] );
				return 0 !== $cmp ? $cmp : $a['sequence'] <=> $b['sequence'];
			}
		);
		return $games;
	}
}
