<?php
/**
 * Lesemodell der Kalender-Abos: Begegnungen und Ranglistenturnier-Gruppen pro Feed.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Calendar;

use TTLeague\Frontend\Support\Urls;
use TTLeague\Infrastructure\Database\Db;
use TTLeague\ReadModel\Cache\Cache;
use TTLeague\ReadModel\ReadModel;
use TTLeague\ReadModel\Support\Slug;
use TTLeague\ReadModel\Support\Sql;

/**
 * Ein Feed ist ein Array
 * array{type:string ('team'|'club'|'player'),id:int,name:string,slug:string,season:?array<string,mixed>,
 *       group:?string,club:?string,license:?int}.
 * team() und club() und player() lösen die Adresse auf (null = unbekannt, HTTP 404);
 * rows() lädt die Ereignisse. Die Abfragen laufen über den Cache des Lesemodells
 * (Schlüssel mit DataVersion::key()), die Texte der Ereignisse baut EventFactory.
 *
 * Zeile einer Begegnung:
 * array{type:'fixture',id:int,kind:string ('MM'|'CUP'),competition:string,season_slug:string,season_name:string,
 *       start:string,status:string,home:string,away:string,home_score:?int,away_score:?int,venue:?array}
 * Zeile einer Ranglistenturnier-Gruppe:
 * array{type:'rl_group',id:int,season_slug:string,season_name:string,start:string,round_no:int,is_final:bool,
 *       category:int,group_no:int,host:?string,venue:?array}
 * Lokal: array{name:string,street:?string,zip:?string,city:?string,lat:?float,lng:?float}.
 */
final class Feeds {

	public function __construct(
		private readonly Db $db,
		private readonly Cache $cache,
		private readonly ReadModel $model,
	) {}

	/**
	 * Mannschaft einer Saison, z. B. 2026-27 und seefeld-1.
	 *
	 * @return array<string,mixed>|null
	 */
	public function team( string $season_slug, string $team_slug ): ?array {
		if ( ! Urls::is_season_slug( $season_slug ) ) {
			return null;
		}
		$season = $this->model->seasons->by_slug( $season_slug );
		if ( null === $season ) {
			return null;
		}
		$team = $this->model->championship->team_by_slug( $season['id'], $team_slug );
		if ( null === $team ) {
			return null;
		}
		$group = null === $team['competition_id'] ? null : $this->model->championship->group_by_id( $season['id'], $team['competition_id'] );
		return array(
			'type'    => 'team',
			'id'      => (int) $team['id'],
			'name'    => (string) $team['name'],
			'slug'    => $team['slug'] . '-' . $season['slug'],
			'season'  => $season,
			'group'   => null === $group ? null : (string) $group['name'],
			'club'    => (string) $team['club_name'],
			'license' => null,
		);
	}

	/**
	 * Club mit allen Teams der aktiven Saison.
	 *
	 * @return array<string,mixed>|null
	 */
	public function club( string $club_slug ): ?array {
		$club = $this->model->clubs->by_slug( $club_slug );
		if ( null === $club ) {
			return null;
		}
		$season = $this->model->seasons->active();
		return array(
			'type'    => 'club',
			'id'      => $club['id'],
			'name'    => $club['name'],
			'slug'    => $club['slug'] . ( null === $season ? '' : '-' . $season['slug'] ),
			'season'  => $season,
			'group'   => null,
			'club'    => $club['name'],
			'license' => null,
		);
	}

	/**
	 * Spieler über die Lizenznummer, Ereignisse der aktiven Saison.
	 *
	 * @return array<string,mixed>|null
	 */
	public function player( int $license ): ?array {
		if ( $license <= 0 ) {
			return null;
		}
		$player = $this->model->players->by_license( $license );
		if ( null === $player ) {
			return null;
		}
		$season = $this->model->seasons->active();
		return array(
			'type'    => 'player',
			'id'      => $player['id'],
			'name'    => $player['name'],
			'slug'    => $license . ( '' === $player['slug'] ? '' : '-' . $player['slug'] ) . ( null === $season ? '' : '-' . $season['slug'] ),
			'season'  => $season,
			'group'   => null,
			'club'    => null,
			'license' => $license,
		);
	}

	/**
	 * Ereignisse eines Feeds, chronologisch, mit Zeitpunkt der Berechnung (UTC).
	 *
	 * @param array<string,mixed> $feed Feed aus team(), club() oder player().
	 * @return array{generated:string,rows:list<array<string,mixed>>}
	 */
	public function rows( array $feed ): array {
		$season_id = null === $feed['season'] ? 0 : (int) $feed['season']['id'];
		$id        = (int) $feed['id'];
		return $this->cache->remember(
			'ical_' . $feed['type'],
			array( $id, $season_id ),
			function () use ( $feed, $id, $season_id ): array {
				$rows = array();
				if ( 'team' === $feed['type'] ) {
					$rows = $this->fixtures( '( f.home_team_id = %d OR f.away_team_id = %d )', array( $id, $id ) );
				} elseif ( 'club' === $feed['type'] && $season_id > 0 ) {
					$rows = $this->fixtures(
						'co.season_id = %d AND ( ht.club_id = %d OR at.club_id = %d OR he.club_id = %d OR ae.club_id = %d )',
						array( $season_id, $id, $id, $id, $id )
					);
				} elseif ( 'player' === $feed['type'] && $season_id > 0 ) {
					$rows = $this->player_rows( $id, $season_id );
				}
				return array(
					'generated' => gmdate( 'Y-m-d H:i:s' ),
					'rows'      => $rows,
				);
			}
		);
	}

	/**
	 * Begegnungen aller Teams und Cup-Mannschaften, in denen der Spieler Stammspieler, gebunden,
	 * gemeldet oder eingesetzt ist, und seine Ranglistenturnier-Gruppen mit Anspielzeit. Gruppen
	 * einer Runde in Planung (Vorschlag der TK, Status «planned») gehören nicht dazu.
	 *
	 * @return list<array<string,mixed>>
	 */
	private function player_rows( int $player_id, int $season_id ): array {
		$teams   = $this->db->get_col(
			"SELECT r.team_id
			FROM {$this->db->table( 'team_roster' )} r
			JOIN {$this->db->table( 'team' )} t ON t.id = r.team_id
			WHERE r.player_id = %d AND t.season_id = %d",
			array( $player_id, $season_id )
		);
		$entries = $this->db->get_col(
			"SELECT e.id
			FROM {$this->db->table( 'cup_entry' )} e
			JOIN {$this->db->table( 'competition' )} co ON co.id = e.competition_id
			WHERE co.season_id = %d AND ( e.player1_id = %d OR e.player2_id = %d )",
			array( $season_id, $player_id, $player_id )
		);
		foreach ( $this->db->get_results(
			"SELECT DISTINCT l.side, f.home_team_id, f.away_team_id, f.home_entry_id, f.away_entry_id
			FROM {$this->db->table( 'lineup' )} l
			JOIN {$this->db->table( 'fixture' )} f ON f.id = l.fixture_id
			JOIN {$this->db->table( 'competition' )} co ON co.id = f.competition_id
			WHERE l.player_id = %d AND co.season_id = %d",
			array( $player_id, $season_id )
		) as $row ) {
			$home      = 'away' !== $row['side'];
			$teams[]   = $home ? $row['home_team_id'] : $row['away_team_id'];
			$entries[] = $home ? $row['home_entry_id'] : $row['away_entry_id'];
		}
		$teams   = Sql::ids( $teams );
		$entries = Sql::ids( $entries );

		$rows = array();
		if ( array() !== $teams || array() !== $entries ) {
			$where = array();
			$args  = array( $season_id );
			if ( array() !== $teams ) {
				$where[] = 'f.home_team_id IN (' . Sql::ints( $teams ) . ') OR f.away_team_id IN (' . Sql::ints( $teams ) . ')';
				$args    = array_merge( $args, $teams, $teams );
			}
			if ( array() !== $entries ) {
				$where[] = 'f.home_entry_id IN (' . Sql::ints( $entries ) . ') OR f.away_entry_id IN (' . Sql::ints( $entries ) . ')';
				$args    = array_merge( $args, $entries, $entries );
			}
			$rows = $this->fixtures( 'co.season_id = %d AND ( ' . implode( ' OR ', $where ) . ' )', $args );
		}

		$season = $this->model->seasons->by_id( $season_id );
		foreach ( $this->db->get_results(
			"SELECT rg.id, rg.category, rg.group_no, rg.start_at, rr.round_no, rr.is_final, c.short_name AS host,
				v.name AS venue_name, v.street, v.zip, v.city, v.lat, v.lng
			FROM {$this->db->table( 'rl_group_player' )} gp
			JOIN {$this->db->table( 'rl_group' )} rg ON rg.id = gp.rl_group_id
			JOIN {$this->db->table( 'rl_round' )} rr ON rr.id = rg.rl_round_id
			JOIN {$this->db->table( 'competition' )} co ON co.id = rr.competition_id
			LEFT JOIN {$this->db->table( 'club' )} c ON c.id = rg.host_club_id
			LEFT JOIN {$this->db->table( 'venue' )} v ON v.id = rg.venue_id
			WHERE gp.player_id = %d AND co.season_id = %d AND co.type = 'RL' AND rg.start_at IS NOT NULL AND rr.status <> 'planned'",
			array( $player_id, $season_id )
		) as $row ) {
			$rows[] = array(
				'type'        => 'rl_group',
				'id'          => (int) $row['id'],
				'season_slug' => null === $season ? '' : (string) $season['slug'],
				'season_name' => null === $season ? '' : (string) $season['name'],
				'start'       => (string) $row['start_at'],
				'round_no'    => (int) $row['round_no'],
				'is_final'    => '1' === (string) $row['is_final'],
				'category'    => (int) $row['category'],
				'group_no'    => (int) $row['group_no'],
				'host'        => $row['host'],
				'venue'       => self::venue( $row ),
			);
		}

		usort(
			$rows,
			static fn( array $a, array $b ): int => array( $a['start'], $a['type'], $a['id'] ) <=> array( $b['start'], $b['type'], $b['id'] )
		);
		return $rows;
	}

	/**
	 * Begegnungen der Meisterschaft und des Cups mit Datum, Namen und Lokal (Lokal der
	 * Begegnung, sonst Heimlokal des Heimteams), chronologisch.
	 *
	 * @param string           $where Bedingung mit Platzhaltern (fest im Code).
	 * @param list<int|string> $args  Werte für die Platzhalter.
	 * @return list<array<string,mixed>>
	 */
	private function fixtures( string $where, array $args ): array {
		$rows = array();
		foreach ( $this->db->get_results(
			"SELECT f.id, f.scheduled_at, f.status, f.home_score, f.away_score, co.type, co.name AS competition,
				s.slug AS season_slug, s.name AS season_name,
				COALESCE(ht.name, he.name) AS home_name, COALESCE(at.name, ae.name) AS away_name,
				v.name AS venue_name, v.street, v.zip, v.city, v.lat, v.lng
			FROM {$this->db->table( 'fixture' )} f
			JOIN {$this->db->table( 'competition' )} co ON co.id = f.competition_id
			JOIN {$this->db->table( 'season' )} s ON s.id = co.season_id
			LEFT JOIN {$this->db->table( 'team' )} ht ON ht.id = f.home_team_id
			LEFT JOIN {$this->db->table( 'team' )} at ON at.id = f.away_team_id
			LEFT JOIN {$this->db->table( 'cup_entry' )} he ON he.id = f.home_entry_id
			LEFT JOIN {$this->db->table( 'cup_entry' )} ae ON ae.id = f.away_entry_id
			LEFT JOIN {$this->db->table( 'venue' )} v ON v.id = COALESCE(f.venue_id, ht.home_venue_id)
			WHERE co.type IN ('MM', 'CUP') AND f.scheduled_at IS NOT NULL AND {$where}
			ORDER BY f.scheduled_at, f.id",
			$args
		) as $row ) {
			$rows[] = array(
				'type'        => 'fixture',
				'id'          => (int) $row['id'],
				'kind'        => 'CUP' === $row['type'] ? 'CUP' : 'MM',
				'competition' => (string) $row['competition'],
				'season_slug' => (string) $row['season_slug'],
				'season_name' => (string) $row['season_name'],
				'start'       => (string) $row['scheduled_at'],
				'status'      => (string) $row['status'],
				'home'        => (string) $row['home_name'],
				'away'        => (string) $row['away_name'],
				'home_score'  => Sql::int_or_null( $row['home_score'] ),
				'away_score'  => Sql::int_or_null( $row['away_score'] ),
				'venue'       => self::venue( $row ),
			);
		}
		return $rows;
	}

	/**
	 * Lokal aus einer Zeile mit venue_name, street, zip, city, lat, lng.
	 *
	 * @param array<string,string|null> $row Datenbankzeile.
	 * @return array{name:string,street:?string,zip:?string,city:?string,lat:?float,lng:?float}|null
	 */
	private static function venue( array $row ): ?array {
		if ( null === $row['venue_name'] || '' === trim( (string) $row['venue_name'] ) ) {
			return null;
		}
		$known = null !== $row['lat'] && null !== $row['lng'] && '' !== $row['lat'] && '' !== $row['lng'];
		return array(
			'name'   => (string) $row['venue_name'],
			'street' => $row['street'],
			'zip'    => $row['zip'],
			'city'   => $row['city'],
			'lat'    => $known ? (float) $row['lat'] : null,
			'lng'    => $known ? (float) $row['lng'] : null,
		);
	}

	/**
	 * Dateiname eines Feeds, z. B. seefeld-1-2026-27.ics.
	 *
	 * @param array<string,mixed> $feed Feed.
	 */
	public static function filename( array $feed ): string {
		$slug = Slug::make( (string) $feed['slug'] );
		return ( '' === $slug ? 'kalender' : $slug ) . '.ics';
	}
}
