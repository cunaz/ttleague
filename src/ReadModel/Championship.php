<?php
/**
 * Mannschaftsmeisterschaft: Gruppen, Teams, Begegnungen und berechnete Tabellen einer Saison.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\ReadModel;

use TTLeague\Domain\Match\FixtureWorkflow;
use TTLeague\Domain\Standings\MatchSummary;
use TTLeague\Domain\Standings\StandingsCalculator;
use TTLeague\Infrastructure\Database\Db;
use TTLeague\ReadModel\Cache\Cache;
use TTLeague\ReadModel\Support\Slug;
use TTLeague\ReadModel\Support\Sql;

/**
 * Liefert pro Saison eine Struktur mit allen Gruppen (inklusive Tabelle), Teams und
 * Begegnungen. Die Tabelle wird immer aus den Begegnungen mit dem StandingsCalculator
 * berechnet; es zählen die Status aus Sql::COUNTED_STATUSES mit erfasstem Resultat.
 *
 * Gruppe:   array{id:int,name:string,category:?string,group_no:int,slug:string,sort_order:int,
 *                 team_ids:list<int>,standings:list<array<string,mixed>>,played:int,total:int,has_provisional:bool}
 * Team:     array{id:int,name:string,slug:string,number:int,club_id:int,club_name:string,club_short:string,
 *                 club_slug:string,competition_id:?int,home_venue_id:?int,default_start_time:?string,status:string}
 * Begegnung: array{id:int,competition_id:int,round:?int,leg:?int,match_no:?int,scheduled_at:?string,
 *                 home_team_id:?int,away_team_id:?int,venue_id:?int,venue_name:?string,status:string,
 *                 home_score:?int,away_score:?int,sets_home:int,sets_away:int,games:int,counted:bool}
 */
final class Championship {

	public function __construct( private readonly Db $db, private readonly Cache $cache ) {}

	/**
	 * Alle Meisterschaftsdaten einer Saison.
	 *
	 * @return array{groups:list<array<string,mixed>>,teams:array<int,array<string,mixed>>,fixtures:list<array<string,mixed>>}
	 */
	public function season( int $season_id ): array {
		return $this->cache->remember(
			'mm_season',
			array( $season_id ),
			fn(): array => $this->load( $season_id )
		);
	}

	/**
	 * Gruppe nach Slug («a», «a-2»).
	 *
	 * @return array<string,mixed>|null
	 */
	public function group_by_slug( int $season_id, string $slug ): ?array {
		foreach ( $this->season( $season_id )['groups'] as $group ) {
			if ( $group['slug'] === $slug ) {
				return $group;
			}
		}
		return null;
	}

	/**
	 * Gruppe nach Konkurrenz-ID.
	 *
	 * @return array<string,mixed>|null
	 */
	public function group_by_id( int $season_id, int $competition_id ): ?array {
		foreach ( $this->season( $season_id )['groups'] as $group ) {
			if ( $group['id'] === $competition_id ) {
				return $group;
			}
		}
		return null;
	}

	/**
	 * Begegnungen einer Gruppe, chronologisch.
	 *
	 * @return list<array<string,mixed>>
	 */
	public function group_fixtures( int $season_id, int $competition_id ): array {
		return array_values(
			array_filter(
				$this->season( $season_id )['fixtures'],
				static fn( array $f ): bool => $f['competition_id'] === $competition_id
			)
		);
	}

	/**
	 * Begegnungen eines Teams, chronologisch.
	 *
	 * @return list<array<string,mixed>>
	 */
	public function team_fixtures( int $season_id, int $team_id ): array {
		return array_values(
			array_filter(
				$this->season( $season_id )['fixtures'],
				static fn( array $f ): bool => $f['home_team_id'] === $team_id || $f['away_team_id'] === $team_id
			)
		);
	}

	/**
	 * Team nach Slug des Namens («seefeld-1»).
	 *
	 * @return array<string,mixed>|null
	 */
	public function team_by_slug( int $season_id, string $slug ): ?array {
		foreach ( $this->season( $season_id )['teams'] as $team ) {
			if ( $team['slug'] === $slug ) {
				return $team;
			}
		}
		return null;
	}

	/**
	 * Gruppenslug: Kategorie klein, ab group_no 2 mit Nummer («a», «a-2»).
	 */
	public static function group_slug( ?string $category, int $group_no, int $id ): string {
		$base = null === $category || '' === $category ? 'g' . $id : Slug::make( $category );
		return $group_no > 1 ? $base . '-' . $group_no : $base;
	}

	/**
	 * Tabelle aus Begegnungen; reine Funktion für Tests und andere Lesemodelle.
	 *
	 * @param list<int>                  $team_ids  Teams der Gruppe.
	 * @param list<array<string,mixed>> $fixtures  Begegnungen der Gruppe.
	 * @param list<int>                  $withdrawn Zurückgezogene Teams; sie stehen am Tabellenende (Art. 13.3).
	 * @return list<array{rank:int,shared:bool,team_id:int,played:int,wins:int,draws:int,losses:int,games_won:int,games_lost:int,sets_won:int,sets_lost:int,points:int,tiebreak_note:?string,withdrawn:bool}>
	 */
	public static function standings( array $team_ids, array $fixtures, array $withdrawn = array() ): array {
		$matches = array();
		foreach ( $fixtures as $fixture ) {
			if ( ! $fixture['counted'] || null === $fixture['home_team_id'] || null === $fixture['away_team_id'] || $fixture['home_team_id'] === $fixture['away_team_id'] ) {
				continue;
			}
			$matches[] = new MatchSummary(
				$fixture['home_team_id'],
				$fixture['away_team_id'],
				(int) $fixture['home_score'],
				(int) $fixture['away_score'],
				$fixture['sets_home'],
				$fixture['sets_away'],
				$fixture['points_home'] ?? null,
				$fixture['points_away'] ?? null
			);
		}

		$rows   = ( new StandingsCalculator() )->calculate( $team_ids, $matches, $withdrawn );
		$counts = array();
		foreach ( $rows as $row ) {
			$counts[ $row->rank ] = ( $counts[ $row->rank ] ?? 0 ) + 1;
		}

		$result = array();
		foreach ( $rows as $row ) {
			$result[] = array(
				'rank'          => $row->rank,
				'shared'        => $counts[ $row->rank ] > 1,
				'team_id'       => (int) $row->team,
				'played'        => $row->played,
				'wins'          => $row->match_wins,
				'draws'         => $row->match_draws,
				'losses'        => $row->match_losses,
				'games_won'     => $row->games_won,
				'games_lost'    => $row->games_lost,
				'sets_won'      => $row->sets_won,
				'sets_lost'     => $row->sets_lost,
				'points'        => $row->points(),
				'tiebreak_note' => $row->tiebreak_note,
				'withdrawn'     => in_array( $row->team, $withdrawn, true ),
			);
		}
		return $result;
	}

	/**
	 * @return array{groups:list<array<string,mixed>>,teams:array<int,array<string,mixed>>,fixtures:list<array<string,mixed>>}
	 */
	private function load( int $season_id ): array {
		$groups = array();
		foreach ( $this->db->get_results(
			"SELECT id, name, category, group_no, sort_order FROM {$this->db->table( 'competition' )} WHERE season_id = %d AND type = 'MM' ORDER BY sort_order, category, group_no, id",
			array( $season_id )
		) as $row ) {
			$id       = (int) $row['id'];
			$group_no = max( 1, (int) $row['group_no'] );
			$groups[] = array(
				'id'              => $id,
				'name'            => (string) $row['name'],
				'category'        => $row['category'],
				'group_no'        => $group_no,
				'slug'            => self::group_slug( $row['category'], $group_no, $id ),
				'sort_order'      => (int) $row['sort_order'],
				'team_ids'        => array(),
				'standings'       => array(),
				'played'          => 0,
				'total'           => 0,
				'has_provisional' => false,
			);
		}

		$teams = array();
		foreach ( $this->db->get_results(
			"SELECT t.id, t.name, t.number, t.club_id, t.competition_id, t.home_venue_id, t.default_start_time, t.status,
				c.name AS club_name, c.short_name AS club_short, c.slug AS club_slug
			FROM {$this->db->table( 'team' )} t
			JOIN {$this->db->table( 'club' )} c ON c.id = t.club_id
			WHERE t.season_id = %d
			ORDER BY t.name, t.id",
			array( $season_id )
		) as $row ) {
			$teams[ (int) $row['id'] ] = array(
				'id'                 => (int) $row['id'],
				'name'               => (string) $row['name'],
				'slug'               => Slug::make( (string) $row['name'] ),
				'number'             => (int) $row['number'],
				'club_id'            => (int) $row['club_id'],
				'club_name'          => (string) $row['club_name'],
				'club_short'         => (string) $row['club_short'],
				'club_slug'          => (string) $row['club_slug'],
				'competition_id'     => Sql::int_or_null( $row['competition_id'] ),
				'home_venue_id'      => Sql::int_or_null( $row['home_venue_id'] ),
				'default_start_time' => $row['default_start_time'],
				'status'             => (string) $row['status'],
			);
		}

		$sets = array();
		foreach ( $this->db->get_results(
			"SELECT g.fixture_id,
				SUM(CASE WHEN g.status = 'played' THEN g.sets_a ELSE 0 END) AS sets_home,
				SUM(CASE WHEN g.status = 'played' THEN g.sets_b ELSE 0 END) AS sets_away,
				SUM(CASE WHEN g.status = 'played' THEN 1 ELSE 0 END) AS played_games,
				COUNT(*) AS games
			FROM {$this->db->table( 'game' )} g
			JOIN {$this->db->table( 'fixture' )} f ON f.id = g.fixture_id
			JOIN {$this->db->table( 'competition' )} co ON co.id = f.competition_id
			WHERE co.season_id = %d AND co.type = 'MM'
			GROUP BY g.fixture_id",
			array( $season_id )
		) as $row ) {
			$sets[ (int) $row['fixture_id'] ] = $row;
		}

		$points = array();
		foreach ( $this->db->get_results(
			"SELECT g.fixture_id, SUM(s.points_a) AS points_home, SUM(s.points_b) AS points_away, COUNT(DISTINCT g.id) AS games_with_points
			FROM {$this->db->table( 'game_set' )} s
			JOIN {$this->db->table( 'game' )} g ON g.id = s.game_id
			JOIN {$this->db->table( 'fixture' )} f ON f.id = g.fixture_id
			JOIN {$this->db->table( 'competition' )} co ON co.id = f.competition_id
			WHERE co.season_id = %d AND co.type = 'MM' AND g.status = 'played'
			GROUP BY g.fixture_id",
			array( $season_id )
		) as $row ) {
			$points[ (int) $row['fixture_id'] ] = $row;
		}

		$fixtures = array();
		foreach ( $this->db->get_results(
			"SELECT f.id, f.competition_id, f.round, f.leg, f.match_no, f.scheduled_at, f.home_team_id, f.away_team_id,
				f.venue_id, f.status, f.home_score, f.away_score, v.name AS venue_name
			FROM {$this->db->table( 'fixture' )} f
			JOIN {$this->db->table( 'competition' )} co ON co.id = f.competition_id
			LEFT JOIN {$this->db->table( 'venue' )} v ON v.id = f.venue_id
			WHERE co.season_id = %d AND co.type = 'MM'
			ORDER BY f.scheduled_at IS NULL, f.scheduled_at, f.match_no, f.id",
			array( $season_id )
		) as $row ) {
			$id          = (int) $row['id'];
			$home_score  = Sql::int_or_null( $row['home_score'] );
			$away_score  = Sql::int_or_null( $row['away_score'] );
			$status      = (string) $row['status'];
			$set_row     = $sets[ $id ] ?? null;
			$point_row   = $points[ $id ] ?? null;
			$played      = null === $set_row ? 0 : (int) $set_row['played_games'];
			$with_points = null === $point_row ? 0 : (int) $point_row['games_with_points'];
			$known       = $played > 0 && $with_points === $played;

			$fixtures[] = array(
				'id'             => $id,
				'competition_id' => (int) $row['competition_id'],
				'round'          => Sql::int_or_null( $row['round'] ),
				'leg'            => Sql::int_or_null( $row['leg'] ),
				'match_no'       => Sql::int_or_null( $row['match_no'] ),
				'scheduled_at'   => $row['scheduled_at'],
				'home_team_id'   => Sql::int_or_null( $row['home_team_id'] ),
				'away_team_id'   => Sql::int_or_null( $row['away_team_id'] ),
				'venue_id'       => Sql::int_or_null( $row['venue_id'] ),
				'venue_name'     => $row['venue_name'],
				'status'         => $status,
				'home_score'     => $home_score,
				'away_score'     => $away_score,
				'sets_home'      => null === $set_row ? 0 : (int) $set_row['sets_home'],
				'sets_away'      => null === $set_row ? 0 : (int) $set_row['sets_away'],
				'points_home'    => $known ? (int) $point_row['points_home'] : null,
				'points_away'    => $known ? (int) $point_row['points_away'] : null,
				'games'          => null === $set_row ? 0 : (int) $set_row['games'],
				'counted'        => in_array( $status, Sql::COUNTED_STATUSES, true ) && null !== $home_score && null !== $away_score,
			);
		}

		foreach ( $groups as &$group ) {
			$team_ids = array();
			foreach ( $teams as $team ) {
				if ( $team['competition_id'] === $group['id'] ) {
					$team_ids[] = $team['id'];
				}
			}
			$group_fixtures = array();
			foreach ( $fixtures as $fixture ) {
				if ( $fixture['competition_id'] !== $group['id'] ) {
					continue;
				}
				$group_fixtures[] = $fixture;
				foreach ( array( $fixture['home_team_id'], $fixture['away_team_id'] ) as $team_id ) {
					if ( null !== $team_id && ! in_array( $team_id, $team_ids, true ) ) {
						$team_ids[] = $team_id;
					}
				}
				if ( 'cancelled' !== $fixture['status'] ) {
					++$group['total'];
				}
				if ( $fixture['counted'] ) {
					++$group['played'];
				}
				// Vorläufig sind provisorische und strittige Resultate (docs/erfassung.md).
				if ( FixtureWorkflow::is_provisional( (string) $fixture['status'] ) ) {
					$group['has_provisional'] = true;
				}
			}
			$group['team_ids']  = $team_ids;
			$group['standings'] = self::standings(
				$team_ids,
				$group_fixtures,
				array_values( array_filter( $team_ids, static fn( int $id ): bool => 'withdrawn' === ( $teams[ $id ]['status'] ?? '' ) ) )
			);
		}
		unset( $group );

		return array(
			'groups'   => $groups,
			'teams'    => $teams,
			'fixtures' => $fixtures,
		);
	}
}
