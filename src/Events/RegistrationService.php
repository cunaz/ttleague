<?php
/**
 * An- und Abmeldung zu Anlässen mit Höchstzahl, Warteliste und Nachrücken.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Events;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Meldungen sind Klartext für die Rückmeldung (Flash) und werden bei der Ausgabe escaped.

use TTLeague\Access\AccessPolicy;
use TTLeague\Domain\Events\RegistrationRules as Rules;
use TTLeague\Infrastructure\AuditLog;
use TTLeague\Infrastructure\Database\Db;
use TTLeague\Infrastructure\Time\Clock;
use TTLeague\Results\Actor;

/**
 * Wer darf für wen handeln (docs/saisonbetrieb.md)?
 *
 * - Für sich selbst jede angemeldete Person mit verknüpfter Person.
 * - Für andere der TK-Chef des Clubs, für den die Person in der Saison des Anlasses gemeldet
 *   ist (ohne Saison am Anlass: in der aktiven Saison), und die TK.
 *
 * Clubs und Personen handeln, solange die Anmeldung offen und die Frist nicht verstrichen ist
 * (Anmeldeschluss, ohne Anmeldeschluss der Beginn); danach nur noch die TK. Ein abgesagter
 * Anlass ist für alle gesperrt.
 *
 * Jeder Schreibvorgang sperrt zuerst die Zeile des Anlasses und zählt die Anmeldungen erst
 * danach. Zwei gleichzeitige Anmeldungen laufen so nacheinander und überschreiten die
 * Höchstzahl nicht. Termine des Anlasses sind Ortszeit der Website, created_at ist UTC.
 */
final class RegistrationService {

	private EventRepository $repo;

	private Waitlist $waitlist;

	public function __construct(
		private readonly Db $db,
		private readonly AccessPolicy $policy,
		private readonly Clock $clock,
		private readonly \DateTimeZone $timezone,
		private readonly AuditLog $audit,
		private readonly EventListener $listener,
	) {
		$this->repo     = new EventRepository( $db );
		$this->waitlist = new Waitlist( $this->repo, $audit );
	}

	public function repository(): EventRepository {
		return $this->repo;
	}

	/**
	 * Person anmelden. Ist die Höchstzahl erreicht, kommt die Anmeldung auf die Warteliste.
	 *
	 * @param string|null $note Kurze Bemerkung.
	 * @return array{id:int,status:string} Anmeldung mit Status registered oder waitlist.
	 * @throws EventException 403 ohne Recht, 404 ohne Anlass oder Person, 409 wenn die Anmeldung
	 *                        nicht (mehr) möglich ist oder schon besteht.
	 */
	public function register( int $event_id, int $person_id, Actor $actor, ?string $note = null ): array {
		$event = $this->authorize( $event_id, $person_id, $actor );
		$note  = EventInput::note( $note );
		$now   = $this->clock->now();
		$this->require_open( $event, $actor, $now );
		$person = $this->repo->person( $person_id );
		if ( null === $person ) {
			throw EventException::not_found( __( 'Diese Person gibt es nicht.', 'ttleague' ) );
		}
		if ( 'active' !== $person['status'] ) {
			throw EventException::conflict( __( 'Diese Person ist nicht mehr aktiv.', 'ttleague' ) );
		}

		$result = $this->db->transaction(
			function () use ( $event_id, $person_id, $actor, $note, $now ): array {
				$current = $this->locked( $event_id );
				// Unter der Sperre erneut: Die TK kann die Saison des Anlasses inzwischen geändert haben.
				$this->require_may_act( $actor, $person_id, $current );
				$this->require_open( $current, $actor, $now );
				$existing = $this->repo->registration( $event_id, $person_id );
				if ( null !== $existing ) {
					throw EventException::conflict(
						Rules::WAITLIST === $existing['status']
							? __( 'Diese Person steht bereits auf der Warteliste.', 'ttleague' )
							: __( 'Diese Person ist bereits angemeldet.', 'ttleague' )
					);
				}
				$status = Rules::placement( $current['max_participants'], $this->repo->counts( $event_id )[ Rules::REGISTERED ] );
				$row    = array(
					'event_id'   => $event_id,
					'person_id'  => $person_id,
					'status'     => $status,
					'note'       => $note,
					'created_at' => $now->format( 'Y-m-d H:i:s' ),
				);
				$id     = $this->repo->insert_registration( $row );
				$this->audit->record( 'registration', $id, 'registered', $actor->user_id, null, array( 'id' => $id ) + $row );
				return array(
					'id'     => $id,
					'status' => $status,
				);
			}
		);
		$this->listener->registered( $event_id, $result['id'], $person_id, $result['status'], $actor->user_id );
		return $result;
	}

	/**
	 * Person abmelden: Die Anmeldung wird gelöscht. Wird ein Platz frei, rückt nach, wer am
	 * längsten auf der Warteliste steht.
	 *
	 * @return array{status:string,promoted:list<int>} Status der gelöschten Anmeldung und nachgerückte Personen.
	 * @throws EventException 403 ohne Recht, 404 ohne Anlass, 409 wenn die Abmeldung nicht
	 *                        (mehr) möglich ist oder keine Anmeldung besteht.
	 */
	public function unregister( int $event_id, int $person_id, Actor $actor ): array {
		$event = $this->authorize( $event_id, $person_id, $actor );
		$now   = $this->clock->now();
		$this->require_open( $event, $actor, $now );

		[ $removed, $promoted ] = $this->db->transaction(
			function () use ( $event_id, $person_id, $actor, $now ): array {
				$current = $this->locked( $event_id );
				$this->require_may_act( $actor, $person_id, $current );
				$this->require_open( $current, $actor, $now );
				$existing = $this->repo->registration( $event_id, $person_id );
				if ( null === $existing ) {
					throw EventException::conflict( __( 'Diese Person ist nicht angemeldet.', 'ttleague' ) );
				}
				$this->repo->delete_registration( $existing['id'] );
				$this->audit->record( 'registration', $existing['id'], 'unregistered', $actor->user_id, $existing, null );
				return array( $existing, $this->waitlist->fill( $current, $actor->user_id ) );
			}
		);
		$this->listener->unregistered( $event_id, $removed['id'], $person_id, $removed['status'], $actor->user_id );
		foreach ( $promoted as $row ) {
			$this->listener->promoted( $event_id, $row['id'], $row['person_id'] );
		}
		return array(
			'status'   => $removed['status'],
			'promoted' => array_column( $promoted, 'person_id' ),
		);
	}

	/**
	 * Darf das Konto für diese Person an- und abmelden?
	 *
	 * @param array<string,mixed> $event Anlass.
	 */
	public function may_act_for( Actor $actor, int $person_id, array $event ): bool {
		if ( $actor->user_id <= 0 || $person_id <= 0 ) {
			return false;
		}
		if ( $actor->is_tk || $actor->person_id === $person_id ) {
			return true;
		}
		if ( null === $actor->person_id ) {
			return false;
		}
		$season = $this->season_for( $event );
		$own    = $this->policy->managed_clubs( $actor->person_id );
		return null !== $season && array() !== $own && array() !== array_intersect( $own, $this->repo->club_ids( $person_id, $season ) );
	}

	/**
	 * Spieler der Clubs, die die Person als TK-Chef verwaltet, mit dem Stand ihrer Anmeldung.
	 *
	 * @param array<string,mixed> $event Anlass.
	 * @return list<array{person_id:int,name:string,club_id:int,club:string,registration:string|null}>
	 */
	public function club_players( Actor $actor, array $event ): array {
		$season = $this->season_for( $event );
		if ( null === $actor->person_id || null === $season ) {
			return array();
		}
		return $this->repo->club_players( $this->policy->managed_clubs( $actor->person_id ), $season, $event['id'] );
	}

	/**
	 * Warum das Konto im Moment nicht an- und abmelden kann (RegistrationRules::BLOCK_*), oder null.
	 *
	 * @param array<string,mixed> $event Anlass.
	 */
	public function blocked( array $event, bool $by_tk ): ?string {
		return Rules::blocked( $event['status'], $event['deadline'], $event['starts_at'], $this->clock->now(), $this->timezone, $by_tk );
	}

	/**
	 * Saison, in der die Clubzugehörigkeit gilt: die des Anlasses, sonst die aktive.
	 *
	 * @param array<string,mixed> $event Anlass.
	 */
	public function season_for( array $event ): ?int {
		return $event['season_id'] ?? $this->policy->active_season_id();
	}

	/**
	 * Anlass laden und das Recht prüfen, für die Person zu handeln.
	 *
	 * @return array<string,mixed>
	 */
	private function authorize( int $event_id, int $person_id, Actor $actor ): array {
		if ( $actor->user_id <= 0 ) {
			throw EventException::forbidden( __( 'Bitte melde dich zuerst an.', 'ttleague' ) );
		}
		$event = $this->repo->find( $event_id );
		if ( null === $event ) {
			throw EventException::not_found( __( 'Diesen Anlass gibt es nicht.', 'ttleague' ) );
		}
		$this->require_may_act( $actor, $person_id, $event );
		return $event;
	}

	/**
	 * @param array<string,mixed> $event Anlass (bei Schreibvorgängen der gesperrt gelesene Stand).
	 * @throws EventException 403 ohne Recht für diese Person.
	 */
	private function require_may_act( Actor $actor, int $person_id, array $event ): void {
		if ( ! $this->may_act_for( $actor, $person_id, $event ) ) {
			throw EventException::forbidden( __( 'Du kannst nur dich selbst und als TK-Chef die Spieler deines Clubs an- und abmelden.', 'ttleague' ) );
		}
	}

	/**
	 * @param array<string,mixed> $event Anlass.
	 */
	private function require_open( array $event, Actor $actor, \DateTimeImmutable $now ): void {
		$reason = Rules::blocked( $event['status'], $event['deadline'], $event['starts_at'], $now, $this->timezone, $actor->is_tk );
		if ( null !== $reason ) {
			throw EventException::conflict( Labels::blocked( $reason ) );
		}
	}

	/**
	 * Anlass sperren und neu lesen (erste Abfrage der Transaktion).
	 *
	 * @return array<string,mixed>
	 */
	private function locked( int $event_id ): array {
		return $this->repo->lock( $event_id ) ?? throw EventException::not_found( __( 'Diesen Anlass gibt es nicht.', 'ttleague' ) );
	}
}
