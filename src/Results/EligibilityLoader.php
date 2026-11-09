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
 * (player_season), Stammspieler und Bindungen (team_roster) sowie alle Einsätze in
 * Aufstellungen der Saison ausser in der Begegnung, die gerade erfasst wird.
 */
final class EligibilityLoader {

	public function __construct( private readonly Db $db ) {}

	/**
	 * @return array{checker:EligibilityChecker,players:array<int,array{player_id:int,first_name:string,last_name:string,license_no:int,classification:int|null,stamm_team_id:int|null}>}
	 */
	public function for_club( int $season_id, int $club_id, SeasonRules $rules, int $exclude_fixture_id ): array {
		$checker = new EligibilityChecker( $rules );
		$teams   = array();
		foreach ( $this->db->get_results(
			"SELECT t.id, t.number, COALESCE(c.category, '') AS category FROM {$this->db->table( 'team' )} t
			LEFT JOIN {$this->db->table( 'competition' )} c ON c.id = t.competition_id
			WHERE t.season_id = %d AND t.club_id = %d",
			array( $season_id, $club_id )
		) as $row ) {
			$teams[ (int) $row['id'] ] = true;
			$checker->add_team( new TeamInfo( (int) $row['id'], $club_id, (int) $row['number'], (string) $row['category'] ) );
		}

		$stamm = array();
		$bound = array();
		foreach ( $this->db->get_results(
			"SELECT r.player_id, r.team_id, r.kind FROM {$this->db->table( 'team_roster' )} r
			JOIN {$this->db->table( 'team' )} t ON t.id = r.team_id
			WHERE t.season_id = %d AND t.club_id = %d",
			array( $season_id, $club_id )
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

		foreach ( $bound as [ $player_id, $team_id ] ) {
			if ( isset( $players[ $player_id ], $teams[ $team_id ] ) ) {
				$checker->bind( $player_id, $team_id );
			}
		}

		foreach ( $this->db->get_results(
			"SELECT l.player_id, t.id AS team_id FROM {$this->db->table( 'lineup' )} l
			JOIN {$this->db->table( 'fixture' )} f ON f.id = l.fixture_id
			JOIN {$this->db->table( 'team' )} t ON t.id = CASE WHEN l.side = 'home' THEN f.home_team_id ELSE f.away_team_id END
			WHERE t.season_id = %d AND t.club_id = %d AND f.id <> %d
			ORDER BY f.scheduled_at, f.id",
			array( $season_id, $club_id, $exclude_fixture_id )
		) as $row ) {
			$player_id = (int) $row['player_id'];
			if ( isset( $players[ $player_id ] ) ) {
				$checker->add_appearance( $player_id, (int) $row['team_id'] );
			}
		}

		return array(
			'checker' => $checker,
			'players' => $players,
		);
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
