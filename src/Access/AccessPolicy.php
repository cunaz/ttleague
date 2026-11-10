<?php
/**
 * Fachliche Rechte aus den Daten der aktiven Saison (Clubverwaltung auch aus der geplanten).
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Access;

use TTLeague\Infrastructure\Database\Db;

/**
 * Entscheidet ohne WordPress-Abhängigkeit, ob eine Person für eine Begegnung erfassen oder
 * bestätigen darf und ob sie einen Club verwalten bzw. Personen einladen darf.
 * Grundlage: club_role (Captain, TK-Chef) und cup_entry (Spieler einer Cup-Mannschaft)
 * der Saison der Begegnung, die aktiv sein muss. Die TK-Berechtigung prüft die WordPress-Schicht.
 */
final class AccessPolicy {

	public const HOME = 'home';
	public const AWAY = 'away';

	/** @var array<int,array<string,mixed>|null> */
	private array $fixtures = array();

	/** @var array<string,array{captain_teams:list<int>,tk_chef_clubs:list<int>,cup_entries:list<int>}> */
	private array $roles = array();

	public function __construct( private readonly Db $db ) {}

	/**
	 * Person zum Konto. Ist ein Konto (aus Altdaten) mehreren Personen zugeordnet, ist die
	 * Zuordnung mehrdeutig und es gibt keine Person: lieber keine Rechte als die einer anderen.
	 */
	public function person_for_user( int $user_id ): ?int {
		if ( $user_id <= 0 ) {
			return null;
		}
		$ids = $this->db->get_col( "SELECT id FROM {$this->db->table( 'person' )} WHERE user_id = %d ORDER BY id LIMIT 2", array( $user_id ) );
		return 1 === count( $ids ) ? (int) $ids[0] : null;
	}

	public function active_season_id(): ?int {
		$id = $this->db->get_var( "SELECT id FROM {$this->db->table( 'season' )} WHERE status = 'active' ORDER BY start_date DESC LIMIT 1" );
		return null === $id ? null : (int) $id;
	}

	/**
	 * Die Saison, die die TK aufsetzt und für die die Clubs melden.
	 */
	public function planned_season_id(): ?int {
		$id = $this->db->get_var( "SELECT id FROM {$this->db->table( 'season' )} WHERE status = 'planned' ORDER BY start_date DESC, id DESC LIMIT 1" );
		return null === $id ? null : (int) $id;
	}

	public function can_enter_result( int $person_id, int $fixture_id ): bool {
		return $this->represents( $person_id, $fixture_id, self::HOME );
	}

	public function can_confirm_result( int $person_id, int $fixture_id ): bool {
		return $this->represents( $person_id, $fixture_id, self::AWAY );
	}

	/**
	 * Für welche Seite einer Begegnung tritt die Person auf? Heim hat Vorrang (z. B. TK-Chef beider Teams).
	 */
	public function side_for( int $person_id, int $fixture_id ): ?string {
		if ( $this->represents( $person_id, $fixture_id, self::HOME ) ) {
			return self::HOME;
		}
		return $this->represents( $person_id, $fixture_id, self::AWAY ) ? self::AWAY : null;
	}

	/**
	 * TK-Chef des Clubs in der aktiven oder in der geplanten Saison (Meldung für die nächste Saison).
	 */
	public function can_manage_club( int $person_id, int $club_id ): bool {
		return in_array( $club_id, $this->managed_clubs( $person_id ), true );
	}

	/**
	 * Clubs, die die Person als TK-Chef verwaltet: aus der aktiven und der geplanten Saison.
	 *
	 * @return list<int>
	 */
	public function managed_clubs( int $person_id ): array {
		$clubs = array();
		foreach ( array( $this->active_season_id(), $this->planned_season_id() ) as $season ) {
			if ( null !== $season ) {
				$clubs = array_merge( $clubs, $this->roles( $person_id, $season )['tk_chef_clubs'] );
			}
		}
		return array_values( array_unique( $clubs ) );
	}

	/**
	 * Verschieben oder einen Termin vorschlagen darf, wer eine der beiden Seiten vertritt.
	 */
	public function can_reschedule( int $person_id, int $fixture_id ): bool {
		return null !== $this->side_for( $person_id, $fixture_id );
	}

	/**
	 * Den Gruppenzettel einer RL-Gruppe trägt der Gruppenchef nach, ersatzweise der TK-Chef
	 * seines Clubs oder der TK-Chef des Platzclubs. Nur in der aktiven Saison.
	 */
	public function can_enter_rl_group( int $person_id, int $group_id ): bool {
		$row = $this->db->get_row(
			"SELECT g.host_club_id, c.season_id, s.status AS season_status, p.person_id AS chief_person_id, ps.club_id AS chief_club_id
			FROM {$this->db->table( 'rl_group' )} g
			JOIN {$this->db->table( 'rl_round' )} r ON r.id = g.rl_round_id
			JOIN {$this->db->table( 'competition' )} c ON c.id = r.competition_id
			JOIN {$this->db->table( 'season' )} s ON s.id = c.season_id
			LEFT JOIN {$this->db->table( 'player' )} p ON p.id = g.chief_player_id
			LEFT JOIN {$this->db->table( 'player_season' )} ps ON ps.player_id = g.chief_player_id AND ps.season_id = c.season_id
			WHERE g.id = %d",
			array( $group_id )
		);
		if ( null === $row || 'active' !== $row['season_status'] ) {
			return false;
		}
		if ( null !== $row['chief_person_id'] && (int) $row['chief_person_id'] === $person_id ) {
			return true;
		}
		$own = $this->roles( $person_id, (int) $row['season_id'] )['tk_chef_clubs'];
		foreach ( array( $row['chief_club_id'], $row['host_club_id'] ) as $club ) {
			if ( null !== $club && in_array( (int) $club, $own, true ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * TK-Chefs laden Personen ihres Clubs ein (gemeldet oder mit Funktion in der aktiven Saison).
	 */
	public function can_invite( int $person_id, int $target_person_id ): bool {
		$season = $this->active_season_id();
		if ( null === $season ) {
			return false;
		}
		$own = $this->roles( $person_id, $season )['tk_chef_clubs'];
		if ( array() === $own ) {
			return false;
		}
		$clubs = array_map(
			'intval',
			$this->db->get_col(
				"SELECT ps.club_id FROM {$this->db->table( 'player_season' )} ps JOIN {$this->db->table( 'player' )} p ON p.id = ps.player_id
				WHERE ps.season_id = %d AND p.person_id = %d
				UNION SELECT club_id FROM {$this->db->table( 'club_role' )} WHERE season_id = %d AND person_id = %d",
				array( $season, $target_person_id, $season, $target_person_id )
			)
		);
		return array() !== array_intersect( $own, $clubs );
	}

	/**
	 * Funktionen einer Person in einer Saison.
	 *
	 * @return array{captain_teams:list<int>,tk_chef_clubs:list<int>,cup_entries:list<int>}
	 */
	public function roles( int $person_id, int $season_id ): array {
		$key = $person_id . '|' . $season_id;
		if ( isset( $this->roles[ $key ] ) ) {
			return $this->roles[ $key ];
		}
		$captain = array();
		$tk_chef = array();
		foreach ( $this->db->get_results(
			"SELECT role, club_id, team_id FROM {$this->db->table( 'club_role' )} WHERE season_id = %d AND person_id = %d",
			array( $season_id, $person_id )
		) as $row ) {
			if ( 'captain' === $row['role'] && null !== $row['team_id'] ) {
				$captain[] = (int) $row['team_id'];
			} elseif ( 'tk_chef' === $row['role'] ) {
				$tk_chef[] = (int) $row['club_id'];
			}
		}
		$entries             = array_map(
			'intval',
			$this->db->get_col(
				"SELECT ce.id FROM {$this->db->table( 'cup_entry' )} ce
				JOIN {$this->db->table( 'competition' )} c ON c.id = ce.competition_id
				JOIN {$this->db->table( 'player' )} p ON p.id = ce.player1_id OR p.id = ce.player2_id
				WHERE c.season_id = %d AND p.person_id = %d",
				array( $season_id, $person_id )
			)
		);
		$this->roles[ $key ] = array(
			'captain_teams' => array_values( array_unique( $captain ) ),
			'tk_chef_clubs' => array_values( array_unique( $tk_chef ) ),
			'cup_entries'   => array_values( array_unique( $entries ) ),
		);
		return $this->roles[ $key ];
	}

	/**
	 * Eckdaten einer Begegnung für Rechte und Statusprüfung.
	 *
	 * @return array{id:int,status:string,type:string,season_id:int,season_status:string,home_team_id:int|null,away_team_id:int|null,home_entry_id:int|null,away_entry_id:int|null,home_club_id:int|null,away_club_id:int|null}|null
	 */
	public function fixture_context( int $fixture_id ): ?array {
		if ( array_key_exists( $fixture_id, $this->fixtures ) ) {
			return $this->fixtures[ $fixture_id ];
		}
		$row = $this->db->get_row(
			"SELECT f.id, f.status, f.home_team_id, f.away_team_id, f.home_entry_id, f.away_entry_id,
				c.type, c.season_id, s.status AS season_status,
				COALESCE(ht.club_id, he.club_id) AS home_club_id, COALESCE(at.club_id, ae.club_id) AS away_club_id
			FROM {$this->db->table( 'fixture' )} f
			JOIN {$this->db->table( 'competition' )} c ON c.id = f.competition_id
			JOIN {$this->db->table( 'season' )} s ON s.id = c.season_id
			LEFT JOIN {$this->db->table( 'team' )} ht ON ht.id = f.home_team_id
			LEFT JOIN {$this->db->table( 'team' )} at ON at.id = f.away_team_id
			LEFT JOIN {$this->db->table( 'cup_entry' )} he ON he.id = f.home_entry_id
			LEFT JOIN {$this->db->table( 'cup_entry' )} ae ON ae.id = f.away_entry_id
			WHERE f.id = %d",
			array( $fixture_id )
		);
		if ( null === $row ) {
			$this->fixtures[ $fixture_id ] = null;
			return null;
		}
		$int = static fn( ?string $v ): ?int => null === $v ? null : (int) $v;

		$this->fixtures[ $fixture_id ] = array(
			'id'            => (int) $row['id'],
			'status'        => (string) $row['status'],
			'type'          => (string) $row['type'],
			'season_id'     => (int) $row['season_id'],
			'season_status' => (string) $row['season_status'],
			'home_team_id'  => $int( $row['home_team_id'] ),
			'away_team_id'  => $int( $row['away_team_id'] ),
			'home_entry_id' => $int( $row['home_entry_id'] ),
			'away_entry_id' => $int( $row['away_entry_id'] ),
			'home_club_id'  => $int( $row['home_club_id'] ),
			'away_club_id'  => $int( $row['away_club_id'] ),
		);
		return $this->fixtures[ $fixture_id ];
	}

	/**
	 * Zwischenspeicher leeren (nach Änderungen an Rollen oder Begegnungen in derselben Anfrage).
	 */
	public function reset(): void {
		$this->fixtures = array();
		$this->roles    = array();
	}

	private function represents( int $person_id, int $fixture_id, string $side ): bool {
		$ctx = $this->fixture_context( $fixture_id );
		if ( null === $ctx || 'active' !== $ctx['season_status'] ) {
			return false;
		}
		$roles = $this->roles( $person_id, $ctx['season_id'] );
		$club  = $ctx[ $side . '_club_id' ];
		if ( null !== $club && in_array( $club, $roles['tk_chef_clubs'], true ) ) {
			return true;
		}
		if ( 'CUP' === $ctx['type'] ) {
			$entry = $ctx[ $side . '_entry_id' ];
			return null !== $entry && in_array( $entry, $roles['cup_entries'], true );
		}
		$team = $ctx[ $side . '_team_id' ];
		return null !== $team && in_array( $team, $roles['captain_teams'], true );
	}
}
