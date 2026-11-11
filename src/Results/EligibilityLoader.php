<?php
/**
 * Baut die Spielberechtigung eines Clubs aus Kader, Meldungen und bisherigen Einsätzen auf.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Results;

use TTLeague\Domain\Eligibility\Eligibility;
use TTLeague\Domain\Eligibility\EligibilityChecker;
use TTLeague\Domain\Eligibility\EligibilityReason;
use TTLeague\Domain\Eligibility\TeamInfo;
use TTLeague\Domain\Rating\ClassificationScale;
use TTLeague\Domain\Rules\SeasonRules;
use TTLeague\Infrastructure\Database\Db;

/**
 * Grundlage: Teams des Clubs in der Saison (Kategorie aus der Gruppe), gemeldete Spieler
 * (player_season), Stammspieler und Bindungen (team_roster) sowie die Einsätze in
 * Aufstellungen der Saison vor der Begegnung, die gerade erfasst wird (Art. 12.4 und 12.5
 * zählen frühere Einsätze). Bei gleicher Anspielzeit entscheidet die kleinere ID.
 */
final class EligibilityLoader {

	public function __construct( private readonly Db $db ) {}

	/**
	 * @param string|null $before Stichtag (Ortszeit): Anspielzeit der Begegnung, ohne Termin der
	 *                            Zeitpunkt der Erfassung. Null zählt alle übrigen Einsätze.
	 * @return array{checker:EligibilityChecker,players:array<int,array{player_id:int,first_name:string,last_name:string,license_no:int,classification:int|null,stamm_team_id:int|null}>}
	 */
	public function for_club( int $season_id, int $club_id, SeasonRules $rules, int $exclude_fixture_id, ?string $before = null ): array {
		$checker = new EligibilityChecker( $rules );
		$teams   = $this->teams( $season_id, $club_id, $checker );

		// Bindungen gelten ab dem Tag nach dem bindenden Einsatz; am selben Tag zählen die Einsätze.
		$bound_filter = null === $before ? '' : " AND ( r.kind <> 'bound' OR r.valid_from IS NULL OR r.valid_from < %s )";
		$args         = null === $before ? array( $season_id, $club_id ) : array( $season_id, $club_id, substr( $before, 0, 10 ) );
		$stamm        = array();
		$bound        = array();
		foreach ( $this->db->get_results(
			"SELECT r.player_id, r.team_id, r.kind FROM {$this->db->table( 'team_roster' )} r
			JOIN {$this->db->table( 'team' )} t ON t.id = r.team_id
			WHERE t.season_id = %d AND t.club_id = %d{$bound_filter}",
			$args
		) as $row ) {
			if ( 'bound' === $row['kind'] ) {
				$bound[] = array( (int) $row['player_id'], (int) $row['team_id'] );
			} else {
				$stamm[ (int) $row['player_id'] ] = (int) $row['team_id'];
			}
		}

		$players = array();
		foreach ( $this->db->get_results(
			"SELECT ps.player_id, ps.classification, p.license_no, pe.first_name, pe.last_name
			FROM {$this->db->table( 'player_season' )} ps
			JOIN {$this->db->table( 'player' )} p ON p.id = ps.player_id
			JOIN {$this->db->table( 'person' )} pe ON pe.id = p.person_id
			WHERE ps.season_id = %d AND ps.club_id = %d AND ps.status = 'active'
			ORDER BY pe.last_name, pe.first_name",
			array( $season_id, $club_id )
		) as $row ) {
			$id             = (int) $row['player_id'];
			$stamm_team     = isset( $stamm[ $id ], $teams[ $stamm[ $id ] ] ) ? $stamm[ $id ] : null;
			$players[ $id ] = array(
				'player_id'      => $id,
				'first_name'     => (string) $row['first_name'],
				'last_name'      => (string) $row['last_name'],
				'license_no'     => (int) $row['license_no'],
				'classification' => null === $row['classification'] ? null : (int) $row['classification'],
				'stamm_team_id'  => $stamm_team,
			);
			$checker->add_player( $id, $club_id, $stamm_team );
		}

		$earlier = null === $before ? '' : ' AND f.scheduled_at IS NOT NULL AND ( f.scheduled_at < %s OR ( f.scheduled_at = %s AND f.id < %d ) )';
		$args    = null === $before
			? array( $season_id, $club_id, $exclude_fixture_id )
			: array( $season_id, $club_id, $exclude_fixture_id, $before, $before, $exclude_fixture_id );
		foreach ( $this->db->get_results(
			"SELECT l.player_id, t.id AS team_id FROM {$this->db->table( 'lineup' )} l
			JOIN {$this->db->table( 'fixture' )} f ON f.id = l.fixture_id
			JOIN {$this->db->table( 'team' )} t ON t.id = CASE WHEN l.side = 'home' THEN f.home_team_id ELSE f.away_team_id END
			WHERE t.season_id = %d AND t.club_id = %d AND f.id <> %d{$earlier}
			ORDER BY f.scheduled_at, f.id",
			$args
		) as $row ) {
			$player_id = (int) $row['player_id'];
			if ( isset( $players[ $player_id ] ) ) {
				$checker->add_appearance( $player_id, (int) $row['team_id'] );
			}
		}

		// Bindungen aus dem Kader zuletzt: bind() hebt die Zahl nur auf das Maximum an, wenn die
		// gezählten Einsätze es nicht schon erreichen (sonst würden sie doppelt gezählt).
		foreach ( $bound as [ $player_id, $team_id ] ) {
			if ( isset( $players[ $player_id ], $teams[ $team_id ] ) ) {
				$checker->bind( $player_id, $team_id );
			}
		}

		return array(
			'checker' => $checker,
			'players' => $players,
		);
	}

	/**
	 * Bindung eines Spielers aus seinen Einsätzen der Saison (Art. 12.5): das Team, in dem er
	 * zuerst die Höchstzahl der Ersatzeinsätze erreicht, ab dem Tag dieses Einsatzes. Einsätze in
	 * Begegnungen ohne Termin zählen nicht (wie in for_club()), sonst hätte die Bindung kein Datum.
	 *
	 * @return array{team_id:int,valid_from:string}|null
	 */
	public function binding( int $season_id, int $player_id, SeasonRules $rules ): ?array {
		$club = $this->db->get_var(
			"SELECT club_id FROM {$this->db->table( 'player_season' )} WHERE season_id = %d AND player_id = %d",
			array( $season_id, $player_id )
		);
		if ( null === $club ) {
			return null;
		}
		$club_id = (int) $club;
		$checker = new EligibilityChecker( $rules );
		$teams   = $this->teams( $season_id, $club_id, $checker );
		$stamm   = $this->db->get_var(
			"SELECT r.team_id FROM {$this->db->table( 'team_roster' )} r
			JOIN {$this->db->table( 'team' )} t ON t.id = r.team_id
			WHERE r.player_id = %d AND r.kind <> 'bound' AND t.season_id = %d AND t.club_id = %d
			ORDER BY r.id LIMIT 1",
			array( $player_id, $season_id, $club_id )
		);
		$checker->add_player( $player_id, $club_id, null === $stamm || ! isset( $teams[ (int) $stamm ] ) ? null : (int) $stamm );

		foreach ( $this->db->get_results(
			"SELECT t.id AS team_id, f.scheduled_at FROM {$this->db->table( 'lineup' )} l
			JOIN {$this->db->table( 'fixture' )} f ON f.id = l.fixture_id
			JOIN {$this->db->table( 'team' )} t ON t.id = CASE WHEN l.side = 'home' THEN f.home_team_id ELSE f.away_team_id END
			WHERE l.player_id = %d AND t.season_id = %d AND t.club_id = %d AND f.scheduled_at IS NOT NULL
			ORDER BY f.scheduled_at, f.id",
			array( $player_id, $season_id, $club_id )
		) as $row ) {
			$checker->add_appearance( $player_id, (int) $row['team_id'] );
			$team = $checker->bound_team( $player_id );
			if ( null !== $team ) {
				return array(
					'team_id'    => (int) $team,
					'valid_from' => substr( (string) $row['scheduled_at'], 0, 10 ),
				);
			}
		}
		return null;
	}

	/**
	 * Teams des Clubs in der Saison, im Checker angemeldet.
	 *
	 * @return array<int,true>
	 */
	private function teams( int $season_id, int $club_id, EligibilityChecker $checker ): array {
		$teams = array();
		foreach ( $this->db->get_results(
			"SELECT t.id, t.number, COALESCE(c.category, '') AS category FROM {$this->db->table( 'team' )} t
			LEFT JOIN {$this->db->table( 'competition' )} c ON c.id = t.competition_id
			WHERE t.season_id = %d AND t.club_id = %d",
			array( $season_id, $club_id )
		) as $row ) {
			$teams[ (int) $row['id'] ] = true;
			$checker->add_team( new TeamInfo( (int) $row['id'], $club_id, (int) $row['number'], (string) $row['category'] ) );
		}
		return $teams;
	}

	/**
	 * Gemeldete Spieler eines Clubs (für Cup-Mannschaften).
	 *
	 * @return array<int,array{player_id:int,first_name:string,last_name:string,license_no:int,classification:int|null,stamm_team_id:int|null}>
	 */
	public function club_players( int $season_id, int $club_id ): array {
		$players = array();
		foreach ( $this->db->get_results(
			"SELECT ps.player_id, ps.classification, p.license_no, pe.first_name, pe.last_name
			FROM {$this->db->table( 'player_season' )} ps
			JOIN {$this->db->table( 'player' )} p ON p.id = ps.player_id
			JOIN {$this->db->table( 'person' )} pe ON pe.id = p.person_id
			WHERE ps.season_id = %d AND ps.club_id = %d AND ps.status = 'active'
			ORDER BY pe.last_name, pe.first_name",
			array( $season_id, $club_id )
		) as $row ) {
			$players[ (int) $row['player_id'] ] = array(
				'player_id'      => (int) $row['player_id'],
				'first_name'     => (string) $row['first_name'],
				'last_name'      => (string) $row['last_name'],
				'license_no'     => (int) $row['license_no'],
				'classification' => null === $row['classification'] ? null : (int) $row['classification'],
				'stamm_team_id'  => null,
			);
		}
		return $players;
	}

	/**
	 * Spieler einer Cup-Mannschaft.
	 *
	 * @return list<int>
	 */
	public function entry_players( int $entry_id ): array {
		$row = $this->db->get_row( "SELECT player1_id, player2_id FROM {$this->db->table( 'cup_entry' )} WHERE id = %d", array( $entry_id ) );
		if ( null === $row ) {
			return array();
		}
		return array_values( array_map( 'intval', array_filter( array( $row['player1_id'], $row['player2_id'] ), static fn( $v ) => null !== $v ) ) );
	}

	/**
	 * Punktesumme der Meldung einer Cup-Mannschaft (Summe der Klassierungen bei der Meldung).
	 */
	public function entry_points( int $entry_id ): ?int {
		$points = $this->db->get_var( "SELECT points_total FROM {$this->db->table( 'cup_entry' )} WHERE id = %d", array( $entry_id ) );
		return null === $points ? null : (int) $points;
	}

	/**
	 * Spieler, die in der Saison zu einer anderen Cup-Mannschaft gehören (Art. 20.2): dort
	 * gemeldet (über alle Kategorien, auch nach einem Rückzug) oder in einer anderen Begegnung
	 * für sie eingesetzt.
	 *
	 * @param int $entry_id           Cup-Mannschaft, für die geprüft wird.
	 * @param int $exclude_fixture_id Begegnung, die gerade erfasst wird.
	 * @return array<int,string> Spieler => andere Cup-Mannschaft mit Konkurrenz, z. B. «Seefeld 2 (Mannschaftscup A)».
	 */
	public function cup_commitments( int $season_id, int $entry_id, int $exclude_fixture_id ): array {
		$other = array();
		foreach ( $this->db->get_results(
			"SELECT CONCAT(e.name, ' (', c.name, ')') AS name, e.player1_id, e.player2_id FROM {$this->db->table( 'cup_entry' )} e
			JOIN {$this->db->table( 'competition' )} c ON c.id = e.competition_id
			WHERE c.season_id = %d AND c.type = 'CUP' AND e.id <> %d
			ORDER BY e.id",
			array( $season_id, $entry_id )
		) as $row ) {
			foreach ( array( $row['player1_id'], $row['player2_id'] ) as $player_id ) {
				if ( null !== $player_id ) {
					$other[ (int) $player_id ] ??= (string) $row['name'];
				}
			}
		}
		foreach ( $this->db->get_results(
			"SELECT l.player_id, CONCAT(e.name, ' (', c.name, ')') AS name FROM {$this->db->table( 'lineup' )} l
			JOIN {$this->db->table( 'fixture' )} f ON f.id = l.fixture_id
			JOIN {$this->db->table( 'competition' )} c ON c.id = f.competition_id
			JOIN {$this->db->table( 'cup_entry' )} e ON e.id = CASE WHEN l.side = 'home' THEN f.home_entry_id ELSE f.away_entry_id END
			WHERE c.season_id = %d AND c.type = 'CUP' AND e.id <> %d AND f.id <> %d
			ORDER BY f.id, l.id",
			array( $season_id, $entry_id, $exclude_fixture_id )
		) as $row ) {
			$other[ (int) $row['player_id'] ] ??= (string) $row['name'];
		}
		return $other;
	}

	/**
	 * Kandidatenliste für das Formular, sortiert: Stamm, gebunden, Ersatz, nicht berechtigt;
	 * innerhalb nach Klassierung (stärkere zuerst) und Name.
	 *
	 * @param array<int,array{player_id:int,first_name:string,last_name:string,license_no:int,classification:int|null,stamm_team_id:int|null}> $players Spieler.
	 * @param callable(int):Eligibility                                                                                                         $check   Prüfung pro Spieler.
	 * @return list<array<string,mixed>>
	 */
	public static function candidates( array $players, callable $check ): array {
		$rank = array(
			EligibilityReason::Stamm->value      => 0,
			EligibilityReason::Bound->value      => 1,
			EligibilityReason::Substitute->value => 2,
		);
		$list = array();
		foreach ( $players as $player ) {
			$eligibility = $check( $player['player_id'] );
			$list[]      = array(
				'player_id'              => $player['player_id'],
				'name'                   => trim( $player['first_name'] . ' ' . $player['last_name'] ),
				'license_no'             => $player['license_no'],
				'classification'         => $player['classification'],
				'label'                  => null === $player['classification'] || $player['classification'] < 1 ? '' : ClassificationScale::label( $player['classification'] ),
				'eligible'               => $eligibility->allowed,
				'reason'                 => $eligibility->reason->value,
				'message'                => $eligibility->message,
				'substitute_appearances' => $eligibility->substitute_appearances,
				'remaining'              => $eligibility->remaining,
				'_rank'                  => $rank[ $eligibility->reason->value ] ?? 3,
			);
		}
		usort(
			$list,
			static fn( array $a, array $b ): int => array( $a['_rank'], -( $a['classification'] ?? 0 ), $a['name'] ) <=> array( $b['_rank'], -( $b['classification'] ?? 0 ), $b['name'] )
		);
		return array_map(
			static function ( array $row ): array {
				unset( $row['_rank'] );
				return $row;
			},
			$list
		);
	}
}
