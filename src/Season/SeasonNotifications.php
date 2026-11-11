<?php
/**
 * Mitteilung «Spielplan freigegeben» an Captains und TK-Chefs, in begrenzten Schritten.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Season;

use TTLeague\Infrastructure\Database\Db;
use TTLeague\Notifications\NotificationLog;
use TTLeague\Notifications\Notifier;
use TTLeague\Notifications\RecipientResolver;
use TTLeague\ReadModel\Support\Slug;

/**
 * Empfänger sind die Captains und TK-Chefs der freigegebenen Saison (club_role), nur aktive
 * Personen. Captains erhalten den Spielplan ihrer Mannschaft, TK-Chefs den aller Mannschaften
 * ihres Clubs. Die Kanäle wählt jede Person für den Ereignistyp season_published.
 *
 * Nie im Aufruf der Freigabe: Bei vielen Empfängern und langsamem Versand bräche sonst das
 * Zeitlimit der Anfrage ab, und der Rest erhielte nichts. send_published() versendet höchstens
 * einen Stapel und ist beliebig oft wiederholbar; wer die Mitteilung hat, erhält sie nicht noch
 * einmal (NotificationLog). Die WordPress-Schicht plant die Schritte ein (SeasonAdmin).
 *
 * Mitteilungen gibt es nur für eine Saison, die über den Assistenten freigegeben wurde (Eintrag
 * «published» im audit_log), nicht für eine importierte aktive Saison.
 */
final class SeasonNotifications {

	/** Höchstzahl der Empfänger, an die ein Schritt versendet. */
	public const BATCH = 25;

	/** So viele Tage nach der Freigabe holt der stündliche Lauf ausstehende Mitteilungen nach. */
	public const CATCH_UP_DAYS = 7;

	/** @var callable(\Throwable,string):void */
	private $on_error;

	/**
	 * @param callable(\Throwable,string):void|null $on_error Fehler bei einem Empfänger melden.
	 */
	public function __construct(
		private readonly Db $db,
		private readonly RecipientResolver $recipients,
		private readonly Notifier $notifier,
		private readonly SeasonMessages $messages,
		?callable $on_error = null,
	) {
		$this->on_error = $on_error ?? static function (): void {};
	}

	/**
	 * Ein Schritt des Versands: an höchstens $limit Empfänger, die laut Versandprotokoll noch
	 * keine Mitteilung haben, in der Reihenfolge der Person-ID ab der Person nach $after_person.
	 * Ein Fehler bei einem Empfänger wird gemeldet und hält die übrigen nicht auf; er zählt zum
	 * Schritt, damit jeder Lauf begrenzt bleibt. Personen ohne Kanal (keine gültige Adresse,
	 * Benachrichtigung abgewählt) kosten keinen Versand und zählen nicht.
	 *
	 * @return array{sent:int,failed:int,next:int|null} Empfänger mit versendeter Mitteilung,
	 *         Empfänger mit gescheitertem Versand und die Person, nach der der nächste Schritt
	 *         weitermacht (null, wenn dieser Durchgang alle besucht hat).
	 */
	public function send_published( int $season_id, int $after_person = 0, int $limit = self::BATCH ): array {
		$result = array(
			'sent'   => 0,
			'failed' => 0,
			'next'   => null,
		);
		$season = $this->published_season( $season_id );
		if ( null === $season ) {
			return $result;
		}
		$pending = $this->pending( $season_id, $after_person );
		if ( array() === $pending ) {
			return $result;
		}
		$recipients = array();
		foreach ( $this->recipients->persons( $pending ) as $recipient ) {
			$recipients[ (int) $recipient->person_id ] = $recipient;
		}
		$plan     = $this->plan( $season_id );
		$attempts = 0;
		$last     = $after_person;
		foreach ( $pending as $person_id ) {
			if ( $attempts >= $limit ) {
				$result['next'] = $last;
				break;
			}
			$last = $person_id;
			if ( ! isset( $recipients[ $person_id ] ) ) {
				continue;
			}
			[ $teams, $clubs ] = self::for_person( $plan, $person_id );
			try {
				$report = $this->notifier->send( $this->messages->published( $season, $teams, $clubs ), array( $recipients[ $person_id ] ) );
			} catch ( \Throwable $e ) {
				( $this->on_error )( $e, SeasonMessages::PUBLISHED . ' p' . $person_id );
				$report = array( array( 'status' => Notifier::FAILED ) );
			}
			if ( array() === $report ) {
				continue;
			}
			++$attempts;
			$statuses = array_column( $report, 'status' );
			if ( in_array( Notifier::SENT, $statuses, true ) ) {
				++$result['sent'];
			} elseif ( in_array( Notifier::FAILED, $statuses, true ) ) {
				++$result['failed'];
			}
		}
		return $result;
	}

	/**
	 * Aktive Saisons, die höchstens CATCH_UP_DAYS Tage zuvor freigegeben wurden und noch
	 * Empfänger ohne Mitteilung haben: Für sie plant der stündliche Lauf den Versand erneut ein.
	 *
	 * @param \DateTimeImmutable $now Jetzt (UTC).
	 * @return list<int>
	 */
	public function to_catch_up( \DateTimeImmutable $now ): array {
		$since   = $now->modify( '-' . self::CATCH_UP_DAYS . ' days' )->format( 'Y-m-d H:i:s' );
		$seasons = array();
		foreach ( $this->db->get_col(
			"SELECT DISTINCT s.id FROM {$this->db->table( 'season' )} s
			JOIN {$this->db->table( 'audit_log' )} a ON a.entity = 'season' AND a.entity_id = s.id AND a.action = 'published'
			WHERE s.status = %s AND a.created_at >= %s ORDER BY s.id",
			array( SeasonRepository::ACTIVE, $since )
		) as $season_id ) {
			if ( array() !== $this->pending( (int) $season_id, 0 ) ) {
				$seasons[] = (int) $season_id;
			}
		}
		return $seasons;
	}

	/**
	 * Aktive Captains und TK-Chefs der Saison ohne versendete Mitteilung, nach Person-ID.
	 *
	 * @return list<int>
	 */
	public function pending( int $season_id, int $after_person = 0 ): array {
		return array_map(
			'intval',
			$this->db->get_col(
				"SELECT DISTINCT r.person_id FROM {$this->db->table( 'club_role' )} r
				JOIN {$this->db->table( 'person' )} p ON p.id = r.person_id
				WHERE r.season_id = %d AND r.role IN ( 'captain', 'tk_chef' ) AND p.status = 'active' AND r.person_id > %d
				AND NOT EXISTS (
					SELECT 1 FROM {$this->db->table( 'notification_log' )} l
					WHERE l.person_id = r.person_id AND l.event_type = %s AND l.ref_type = %s AND l.ref_id = %d AND l.status = %s
				)
				ORDER BY r.person_id",
				array( $season_id, $after_person, SeasonMessages::PUBLISHED, SeasonMessages::REF_SEASON, $season_id, NotificationLog::SENT )
			)
		);
	}

	/**
	 * Die Saison, wenn sie aktiv ist und über den Assistenten freigegeben wurde.
	 *
	 * @return array{id:int,name:string,slug:string}|null
	 */
	private function published_season( int $season_id ): ?array {
		$row = $this->db->get_row(
			"SELECT s.id, s.name, s.slug FROM {$this->db->table( 'season' )} s
			WHERE s.id = %d AND s.status = %s
			AND EXISTS ( SELECT 1 FROM {$this->db->table( 'audit_log' )} a WHERE a.entity = 'season' AND a.entity_id = s.id AND a.action = 'published' )",
			array( $season_id, SeasonRepository::ACTIVE )
		);
		return null === $row ? null : array(
			'id'   => (int) $row['id'],
			'name' => (string) $row['name'],
			'slug' => (string) $row['slug'],
		);
	}

	/**
	 * Mannschaften mit Begegnungen, Clubs und Funktionen der Saison, einmal pro Schritt gelesen.
	 *
	 * @return array{teams:array<int,array<string,mixed>>,clubs:array<int,array{name:string,slug:string}>,roles:list<array{person_id:int,role:string,club_id:int,team_id:int|null}>}
	 */
	private function plan( int $season_id ): array {
		$teams = array();
		$clubs = array();
		foreach ( $this->db->get_results(
			"SELECT t.id, t.name, t.club_id, t.status, c.name AS club, c.slug AS club_slug, g.name AS group_name
			FROM {$this->db->table( 'team' )} t
			JOIN {$this->db->table( 'club' )} c ON c.id = t.club_id
			LEFT JOIN {$this->db->table( 'competition' )} g ON g.id = t.competition_id
			WHERE t.season_id = %d ORDER BY t.number, t.name, t.id",
			array( $season_id )
		) as $row ) {
			$clubs[ (int) $row['club_id'] ] = array(
				'name' => (string) $row['club'],
				'slug' => (string) $row['club_slug'],
			);
			if ( 'active' !== $row['status'] ) {
				continue;
			}
			$teams[ (int) $row['id'] ] = array(
				'name'     => (string) $row['name'],
				'slug'     => Slug::make( (string) $row['name'] ),
				'group'    => (string) $row['group_name'],
				'club_id'  => (int) $row['club_id'],
				'fixtures' => array(),
			);
		}
		$team = $this->db->table( 'team' );
		foreach ( $this->db->get_results(
			"SELECT f.id, f.scheduled_at, f.home_team_id, f.away_team_id, h.name AS home, a.name AS away, v.name AS venue
			FROM {$this->db->table( 'fixture' )} f
			JOIN {$this->db->table( 'competition' )} c ON c.id = f.competition_id
			JOIN {$team} h ON h.id = f.home_team_id
			JOIN {$team} a ON a.id = f.away_team_id
			LEFT JOIN {$this->db->table( 'venue' )} v ON v.id = f.venue_id
			WHERE c.season_id = %d AND c.type = 'MM' AND f.status <> 'cancelled'
			ORDER BY f.scheduled_at IS NULL, f.scheduled_at, f.id",
			array( $season_id )
		) as $row ) {
			$fixture = array(
				'id'           => (int) $row['id'],
				'scheduled_at' => $row['scheduled_at'],
				'home'         => (string) $row['home'],
				'away'         => (string) $row['away'],
				'venue'        => $row['venue'],
			);
			foreach ( array( (int) $row['home_team_id'], (int) $row['away_team_id'] ) as $team_id ) {
				if ( isset( $teams[ $team_id ] ) ) {
					$teams[ $team_id ]['fixtures'][] = $fixture;
				}
			}
		}
		$roles = array();
		foreach ( $this->db->get_results(
			"SELECT person_id, role, club_id, team_id FROM {$this->db->table( 'club_role' )} WHERE season_id = %d AND role IN ( 'captain', 'tk_chef' ) ORDER BY id",
			array( $season_id )
		) as $row ) {
			$roles[] = array(
				'person_id' => (int) $row['person_id'],
				'role'      => (string) $row['role'],
				'club_id'   => (int) $row['club_id'],
				'team_id'   => null === $row['team_id'] ? null : (int) $row['team_id'],
			);
		}
		return array(
			'teams' => $teams,
			'clubs' => $clubs,
			'roles' => $roles,
		);
	}

	/**
	 * Mannschaften und Clubs einer Person: als Captain die eigene Mannschaft, als TK-Chef alle
	 * Mannschaften des Clubs, jede einmal.
	 *
	 * @param array<string,mixed> $plan Aus plan().
	 * @return array{0:list<array<string,mixed>>,1:list<array{name:string,slug:string}>}
	 */
	private static function for_person( array $plan, int $person_id ): array {
		$team_ids = array();
		$clubs    = array();
		foreach ( $plan['roles'] as $role ) {
			if ( $role['person_id'] !== $person_id ) {
				continue;
			}
			if ( 'tk_chef' === $role['role'] ) {
				if ( isset( $plan['clubs'][ $role['club_id'] ] ) ) {
					$clubs[ $role['club_id'] ] = $plan['clubs'][ $role['club_id'] ];
				}
				foreach ( $plan['teams'] as $team_id => $team ) {
					if ( $team['club_id'] === $role['club_id'] ) {
						$team_ids[ $team_id ] = true;
					}
				}
			} elseif ( null !== $role['team_id'] && isset( $plan['teams'][ $role['team_id'] ] ) ) {
				$team_ids[ $role['team_id'] ] = true;
			}
		}
		$teams = array();
		foreach ( array_keys( $plan['teams'] ) as $team_id ) {
			if ( isset( $team_ids[ $team_id ] ) ) {
				$teams[] = $plan['teams'][ $team_id ];
			}
		}
		return array( $teams, array_values( $clubs ) );
	}
}
