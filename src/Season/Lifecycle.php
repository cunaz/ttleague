<?php
/**
 * Freigabe der geplanten Saison, Rückzug einer Mannschaft, Abschluss der aktiven Saison.
 *
 * @package TTLeague
 */

declare(strict_types=1);

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Meldungen der Ausnahmen sind Klartext; escaped wird bei der Ausgabe (Flash).

namespace TTLeague\Season;

use TTLeague\Domain\Match\FixtureWorkflow as Flow;
use TTLeague\Domain\Registration\RegistrationRules;
use TTLeague\Domain\Rules\SeasonRules;
use TTLeague\Domain\Season\LifecycleRules;
use TTLeague\Infrastructure\AuditLog;
use TTLeague\Infrastructure\Database\Db;
use TTLeague\Registration\RegistrationRepository;
use TTLeague\Results\Actor;
use TTLeague\Results\ResultException;
use TTLeague\Results\ResultService;

/**
 * Schritte 6 und 7 des Assistenten und der Rückzug während der Saison (docs/saison.md). Alles
 * verlangt die TK.
 *
 * Freigabe und Abschluss sind über die Oberfläche nicht umkehrbar und verlangen deshalb den
 * Namen der Saison als Bestätigung. Beide prüfen ihre Voraussetzungen in der Transaktion unter
 * der Sperre der Saisons (LifecycleRules). «Höchstens eine aktive Saison»: Die Freigabe
 * archiviert in derselben Transaktion jede bisher aktive Saison.
 *
 * Der Rückzug wertet die Begegnungen über die Erfassung (ResultService::decide(), Forfait),
 * damit Aufstellungen, Spiele, Bindungen, Verlauf und Protokoll genau so geschrieben werden wie
 * bei einem Forfait von Hand. Dazu erhält dieser Dienst einen ResultService mit stillen
 * Ereignissen (SilentResultEvents).
 */
final class Lifecycle {

	private SeasonGuard $guard;

	private SeasonRepository $seasons;

	private RegistrationRepository $registrations;

	public function __construct(
		private readonly Db $db,
		private readonly AuditLog $audit,
		private readonly SeasonEvents $events,
		private readonly Scheduling $scheduling,
		private readonly ResultService $results,
	) {
		$this->guard         = new SeasonGuard( $db );
		$this->seasons       = new SeasonRepository( $db );
		$this->registrations = new RegistrationRepository( $db );
	}

	/**
	 * Was der Freigabe der Saison noch fehlt (LifecycleRules::publish_blockers()), mit dem Stand,
	 * aus dem es folgt.
	 *
	 * @param list<string> $confirmed Was die TK ausdrücklich bestätigt (LifecycleRules::CONFIRMABLE).
	 * @return array{facts:array<string,mixed>,open:list<array{code:string,message:string,confirmable:bool}>}
	 * @throws SeasonException Wenn es die Saison nicht gibt.
	 */
	public function readiness( int $season_id, array $confirmed = array() ): array {
		$season      = $this->guard->season( $season_id );
		$rules_error = null;
		try {
			SeasonRules::from_array( $season['rules'] );
		} catch ( \InvalidArgumentException $e ) {
			$rules_error = $e->getMessage();
		}
		$unreviewed = array();
		foreach ( $this->registrations->overview( $season_id ) as $row ) {
			if ( RegistrationRules::REVIEWED !== $row['status'] ) {
				$unreviewed[] = $row['name'];
			}
		}
		$teams      = 0;
		$unassigned = array();
		foreach ( $this->db->get_results(
			"SELECT t.name, c.id AS group_id FROM {$this->db->table( 'team' )} t
			LEFT JOIN {$this->db->table( 'competition' )} c ON c.id = t.competition_id AND c.season_id = t.season_id AND c.type = 'MM'
			WHERE t.season_id = %d ORDER BY t.name, t.id",
			array( $season_id )
		) as $row ) {
			++$teams;
			if ( null === $row['group_id'] ) {
				$unassigned[] = (string) $row['name'];
			}
		}
		$schedule = $this->scheduling->overview( $season_id );
		$error    = $schedule['error'];
		if ( null === $error && $schedule['unscheduled'] > 0 ) {
			/* translators: %d: Anzahl */
			$error = sprintf( __( '%d Begegnungen haben keinen Termin oder kein Lokal.', 'ttleague' ), $schedule['unscheduled'] );
		}
		$active = $this->seasons->active();
		if ( null !== $active && $active['id'] === $season['id'] ) {
			$active = null;
		}
		$facts = array(
			'rules_error'                       => $rules_error,
			'unreviewed'                        => $unreviewed,
			'teams'                             => $teams,
			'unassigned'                        => $unassigned,
			'groups_without_schedule'           => array_values( array_column( array_filter( $schedule['groups'], static fn( array $group ): bool => ! $group['complete'] ), 'name' ) ),
			'schedule_error'                    => $error,
			'hard_conflicts'                    => $schedule['hard'],
			'predecessor'                       => null === $active ? null : (string) $active['name'],
			'predecessor_open_fixtures'         => null === $active ? 0 : array_sum( $this->open_fixtures( (int) $active['id'] ) ),
			'predecessor_classification_closed' => null === $active || null !== $active['classification_closed_at'],
		);
		return array(
			'facts' => $facts,
			'open'  => LifecycleRules::publish_blockers( $facts, $confirmed ),
		);
	}

	/**
	 * Geplante Saison freigeben: In einer Transaktion wird jede bisher aktive Saison archiviert
	 * und die geplante aktiv. Ihre Begegnungen sind danach gewöhnliche Begegnungen; gemeldete
	 * Spieler bleiben, wie sie sind (auch «provisional»). Danach meldet der Dienst
	 * SeasonEvents::published().
	 *
	 * @param string       $confirmation Name der Saison als Bestätigung.
	 * @param list<string> $confirmed    Was die TK ausdrücklich in Kauf nimmt: verbleibende harte
	 *                                   Kollisionen (LifecycleRules::CONFLICTS), eine nicht abgeschlossene
	 *                                   Vorgängerin (LifecycleRules::PREDECESSOR).
	 * @throws SeasonException Ohne TK-Recht, ausserhalb der geplanten Saison, mit offenen
	 *                         Voraussetzungen (als Fehlerliste) oder ohne passende Bestätigung.
	 */
	public function publish( Actor $actor, int $season_id, string $confirmation, array $confirmed = array() ): void {
		SeasonGuard::require_tk( $actor );
		$confirmed = array_values( array_intersect( LifecycleRules::CONFIRMABLE, $confirmed ) );
		$archived  = $this->db->transaction(
			function () use ( $actor, $season_id, $confirmation, $confirmed ): ?int {
				$this->guard->lock();
				// Meldungen der Clubs ändern sich während der Freigabe nicht: Die Meldung sperrt ebenfalls den Club.
				$this->db->get_col(
					"SELECT c.id FROM {$this->db->table( 'club' )} c JOIN {$this->db->table( 'club_season' )} cs ON cs.club_id = c.id WHERE cs.season_id = %d ORDER BY c.id FOR UPDATE",
					array( $season_id )
				);
				$season = $this->guard->planned( $season_id );
				$state  = $this->readiness( $season_id, $confirmed );
				if ( array() !== $state['open'] ) {
					throw SeasonException::invalid( self::as_errors( $state['open'] ), __( 'Die Saison lässt sich noch nicht freigeben.', 'ttleague' ) );
				}
				self::require_confirmation( $confirmation, $season );

				$now      = $this->db->now();
				$archived = null;
				foreach ( $this->db->get_results( "SELECT id, name FROM {$this->db->table( 'season' )} WHERE status = %s AND id <> %d ORDER BY start_date, id", array( SeasonRepository::ACTIVE, $season_id ) ) as $old ) {
					$archived = (int) $old['id'];
					$this->set_status( $archived, SeasonRepository::ARCHIVED, $now );
					/* translators: %s: Saison */
					$this->audit->record( 'season', $archived, 'archived', $actor->user_id, array( 'status' => SeasonRepository::ACTIVE ), array( 'status' => SeasonRepository::ARCHIVED ), sprintf( __( 'Freigabe der Saison %s', 'ttleague' ), $season['name'] ) );
				}
				$this->set_status( $season_id, SeasonRepository::ACTIVE, $now );
				$this->audit->record(
					'season',
					$season_id,
					'published',
					$actor->user_id,
					array( 'status' => SeasonRepository::PLANNED ),
					array(
						'status'         => SeasonRepository::ACTIVE,
						'archived'       => $archived,
						'confirmed'      => $confirmed,
						'hard_conflicts' => $state['facts']['hard_conflicts'],
					)
				);
				return $archived;
			}
		);
		$this->events->published( $season_id, $archived, $actor->user_id );
	}

	/**
	 * Rückzug einer Mannschaft in der aktiven Saison (Art. 13.3): Die Mannschaft ist
	 * «withdrawn», und alle ihre Begegnungen werden als Forfait gegen sie gewertet, auch schon
	 * gespielte. Abgesagte Begegnungen und Begegnungen gegen eine ebenfalls zurückgezogene
	 * Mannschaft bleiben, wie sie sind. In der Tabelle steht die Mannschaft am Ende
	 * (StandingsCalculator).
	 *
	 * @param string $reason Begründung (Pflicht); steht im audit_log und im Verlauf jeder Begegnung.
	 * @return int Anzahl der forfait gewerteten Begegnungen.
	 * @throws SeasonException Ohne TK-Recht, ohne Begründung, ausserhalb der aktiven Saison oder
	 *                         wenn die Mannschaft schon zurückgezogen ist.
	 */
	public function withdraw_team( Actor $actor, int $team_id, string $reason ): int {
		SeasonGuard::require_tk( $actor );
		$reason = trim( $reason );
		if ( '' === $reason ) {
			throw SeasonException::field( 'reason', __( 'Bitte begründe den Rückzug.', 'ttleague' ) );
		}
		if ( mb_strlen( $reason ) > 500 ) {
			throw SeasonException::field( 'reason', __( 'Die Begründung ist zu lang (höchstens 500 Zeichen).', 'ttleague' ) );
		}
		[ $season_id, $count ] = $this->db->transaction(
			function () use ( $actor, $team_id, $reason ): array {
				$this->guard->lock();
				$team = $this->db->get_row( "SELECT id, season_id, name, status, competition_id FROM {$this->db->table( 'team' )} WHERE id = %d", array( $team_id ) );
				if ( null === $team ) {
					throw SeasonException::not_found( __( 'Diese Mannschaft gibt es nicht.', 'ttleague' ) );
				}
				$season = $this->guard->season( (int) $team['season_id'] );
				if ( SeasonRepository::ACTIVE !== $season['status'] ) {
					throw SeasonException::conflict( __( 'Zurückziehen lässt sich eine Mannschaft nur in der aktiven Saison. In der geplanten Saison entfernst du sie in der Meldung des Clubs.', 'ttleague' ) );
				}
				if ( 'active' !== $team['status'] ) {
					/* translators: %s: Mannschaft */
					throw SeasonException::conflict( sprintf( __( '%s ist schon zurückgezogen.', 'ttleague' ), $team['name'] ) );
				}
				$this->db->update( 'team', array( 'status' => 'withdrawn' ), array( 'id' => $team_id ) );

				/* translators: 1: Mannschaft, 2: Begründung */
				$comment  = sprintf( __( 'Rückzug von %1$s (Art. 13.3): %2$s', 'ttleague' ), $team['name'], $reason );
				$fixtures = array();
				$partner  = $this->db->table( 'team' );
				foreach ( $this->db->get_results(
					"SELECT f.id, f.home_team_id, f.away_team_id, f.status, f.home_score, f.away_score, h.status AS home_status, a.status AS away_status
					FROM {$this->db->table( 'fixture' )} f
					LEFT JOIN {$partner} h ON h.id = f.home_team_id
					LEFT JOIN {$partner} a ON a.id = f.away_team_id
					WHERE ( f.home_team_id = %d OR f.away_team_id = %d ) AND f.status <> %s
					ORDER BY f.scheduled_at IS NULL, f.scheduled_at, f.id",
					array( $team_id, $team_id, Flow::CANCELLED )
				) as $row ) {
					$is_home = (int) $row['home_team_id'] === $team_id;
					// Die eigene Zeile ist in dieser Abfrage schon «withdrawn»; es zählt die andere Seite.
					if ( 'withdrawn' === ( $is_home ? $row['away_status'] : $row['home_status'] ) ) {
						continue;
					}
					$own   = $is_home ? $row['home_score'] : $row['away_score'];
					$other = $is_home ? $row['away_score'] : $row['home_score'];
					if ( Flow::FORFEIT === $row['status'] && null !== $own && null !== $other && (int) $own < (int) $other ) {
						continue;
					}
					try {
						$this->results->decide(
							(int) $row['id'],
							$actor,
							array(
								'action'  => 'forfeit',
								'winner'  => $is_home ? 'away' : 'home',
								'comment' => $comment,
							)
						);
					} catch ( ResultException $e ) {
						/* translators: %s: Fehlermeldung */
						throw SeasonException::conflict( sprintf( __( 'Eine Begegnung liess sich nicht forfait werten: %s', 'ttleague' ), $e->getMessage() ) );
					}
					$fixtures[] = (int) $row['id'];
				}
				$this->audit->record(
					'team',
					$team_id,
					'withdrawn',
					$actor->user_id,
					array( 'status' => 'active' ),
					array(
						'status'   => 'withdrawn',
						'fixtures' => $fixtures,
					),
					$reason
				);
				return array( (int) $team['season_id'], count( $fixtures ) );
			}
		);
		$this->events->team_withdrawn( $season_id, $team_id );
		return $count;
	}

	/**
	 * Was dem Abschluss der Saison noch fehlt (LifecycleRules::close_blockers()).
	 *
	 * @return list<array{code:string,message:string,confirmable:bool}>
	 * @throws SeasonException Wenn es die Saison nicht gibt.
	 */
	public function closing( int $season_id ): array {
		$season = $this->guard->season( $season_id );
		return LifecycleRules::close_blockers( $this->open_fixtures( $season_id ), null !== $season['classification_closed_at'] );
	}

	/**
	 * Aktive Saison abschliessen: Status «archived». Das geht auch ohne geplante Folgesaison;
	 * dann gibt es bis zur nächsten Freigabe keine aktive Saison.
	 *
	 * @param string $confirmation Name der Saison als Bestätigung.
	 * @throws SeasonException Ohne TK-Recht, ausserhalb der aktiven Saison, mit offenen
	 *                         Voraussetzungen (als Fehlerliste) oder ohne passende Bestätigung.
	 */
	public function close( Actor $actor, int $season_id, string $confirmation ): void {
		SeasonGuard::require_tk( $actor );
		$this->db->transaction(
			function () use ( $actor, $season_id, $confirmation ): void {
				$this->guard->lock();
				$season = $this->guard->active( $season_id );
				$open   = $this->closing( $season_id );
				if ( array() !== $open ) {
					throw SeasonException::invalid( self::as_errors( $open ), __( 'Die Saison lässt sich noch nicht abschliessen.', 'ttleague' ) );
				}
				self::require_confirmation( $confirmation, $season );
				$this->set_status( $season_id, SeasonRepository::ARCHIVED, $this->db->now() );
				$this->audit->record( 'season', $season_id, 'closed', $actor->user_id, array( 'status' => SeasonRepository::ACTIVE ), array( 'status' => SeasonRepository::ARCHIVED ) );
			}
		);
		$this->events->closed( $season_id );
	}

	/**
	 * Begegnungen der Saison (Meisterschaft und Cup) ohne Resultat oder Entscheid, je Status.
	 *
	 * @return array<string,int>
	 */
	private function open_fixtures( int $season_id ): array {
		$counts = array();
		$in     = "'" . implode( "','", LifecycleRules::OPEN_STATUSES ) . "'"; // Feste Werte.
		foreach ( $this->db->get_results(
			"SELECT f.status, COUNT(*) AS fixtures FROM {$this->db->table( 'fixture' )} f
			JOIN {$this->db->table( 'competition' )} c ON c.id = f.competition_id
			WHERE c.season_id = %d AND f.status IN ( {$in} ) GROUP BY f.status",
			array( $season_id )
		) as $row ) {
			$counts[ (string) $row['status'] ] = (int) $row['fixtures'];
		}
		return $counts;
	}

	private function set_status( int $season_id, string $status, string $now ): void {
		$this->db->update(
			'season',
			array(
				'status'     => $status,
				'updated_at' => $now,
			),
			array( 'id' => $season_id )
		);
	}

	/**
	 * @param array<string,mixed> $season Saison.
	 * @throws SeasonException Wenn die Bestätigung nicht der Name der Saison ist.
	 */
	private static function require_confirmation( string $confirmation, array $season ): void {
		if ( trim( $confirmation ) !== $season['name'] ) {
			/* translators: %s: Saison */
			throw SeasonException::field( 'confirm', sprintf( __( 'Bitte bestätige mit dem Namen der Saison: %s', 'ttleague' ), $season['name'] ) );
		}
	}

	/**
	 * @param list<array{code:string,message:string,confirmable:bool}> $open Offene Punkte.
	 * @return list<array{field:string,message:string}>
	 */
	private static function as_errors( array $open ): array {
		return array_map(
			static fn( array $item ): array => array(
				'field'   => $item['code'],
				'message' => $item['message'],
			),
			$open
		);
	}
}
