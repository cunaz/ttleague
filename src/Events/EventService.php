<?php
/**
 * Anlässe anlegen, ändern, schliessen, wieder öffnen und absagen (nur die TK).
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Events;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Meldungen sind Klartext für die Rückmeldung (Flash) und werden bei der Ausgabe escaped.

use TTLeague\Domain\Events\RegistrationRules as Rules;
use TTLeague\Infrastructure\AuditLog;
use TTLeague\Infrastructure\Database\Db;
use TTLeague\Results\Actor;

/**
 * Jeder Schreibvorgang läuft in einer Transaktion unter der Sperre des Anlasses, schreibt das
 * Änderungsprotokoll (audit_log) und meldet danach das Ereignis. Die TK-Berechtigung kommt mit
 * dem Actor aus der WordPress-Schicht (ttl_tk) und wird hier fachlich geprüft.
 */
final class EventService {

	private EventRepository $repo;

	private Waitlist $waitlist;

	public function __construct(
		private readonly Db $db,
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
	 * Anlass anlegen; die Anmeldung ist danach offen.
	 *
	 * @param array<string,mixed> $input Felder wie in EventInput::validate().
	 * @return int ID des Anlasses.
	 * @throws EventException 403 ohne TK-Berechtigung, 422 bei ungültiger Eingabe.
	 */
	public function create( array $input, Actor $actor ): int {
		$this->require_tk( $actor );
		$data = $this->validated( $input );
		$id   = $this->db->transaction(
			function () use ( $data, $actor ): int {
				$id = $this->repo->insert(
					$data + array(
						'status'     => Rules::OPEN,
						'created_by' => $actor->user_id,
					)
				);
				$this->audit->record( 'event', $id, 'created', $actor->user_id, null, $this->repo->find( $id ) );
				return $id;
			}
		);
		$this->listener->event_changed( $id, $actor->user_id, 'created' );
		return $id;
	}

	/**
	 * Anlass ändern. Wird die Höchstzahl erhöht oder aufgehoben, rücken die ältesten Anmeldungen
	 * der Warteliste nach. Wird sie gesenkt, bleiben bestehende Anmeldungen, und neue kommen auf
	 * die Warteliste, bis wieder Plätze frei sind.
	 *
	 * @param array<string,mixed> $input Felder wie in EventInput::validate().
	 * @return array{promoted:list<int>} Personen, die nachgerückt sind.
	 * @throws EventException 403, 404, 409 (abgesagt) oder 422.
	 */
	public function update( int $event_id, array $input, Actor $actor ): array {
		$this->require_tk( $actor );
		$this->existing( $event_id );
		$data     = $this->validated( $input );
		$promoted = $this->db->transaction(
			function () use ( $event_id, $data, $actor ): array {
				$before = $this->locked( $event_id );
				if ( Rules::CANCELLED === $before['status'] ) {
					throw EventException::conflict( __( 'Ein abgesagter Anlass lässt sich nicht mehr ändern.', 'ttleague' ) );
				}
				$this->repo->update( $event_id, $data );
				$after = $this->repo->find( $event_id ) ?? $before;
				$this->audit->record( 'event', $event_id, 'updated', $actor->user_id, $before, $after );
				return $this->waitlist->fill( $after, $actor->user_id );
			}
		);
		$this->listener->event_changed( $event_id, $actor->user_id, 'updated' );
		foreach ( $promoted as $row ) {
			$this->listener->promoted( $event_id, $row['id'], $row['person_id'] );
		}
		return array( 'promoted' => array_column( $promoted, 'person_id' ) );
	}

	/**
	 * Anmeldung schliessen: Danach ändert nur noch die TK.
	 *
	 * @throws EventException 403, 404 oder 409 (nicht offen).
	 */
	public function close( int $event_id, Actor $actor ): void {
		$this->change_status( $event_id, $actor, Rules::OPEN, Rules::CLOSED, 'closed', __( 'Die Anmeldung ist nicht offen.', 'ttleague' ) );
		$this->listener->event_changed( $event_id, $actor->user_id, 'closed' );
	}

	/**
	 * Geschlossene Anmeldung wieder öffnen.
	 *
	 * @throws EventException 403, 404 oder 409 (nicht geschlossen).
	 */
	public function reopen( int $event_id, Actor $actor ): void {
		$this->change_status( $event_id, $actor, Rules::CLOSED, Rules::OPEN, 'reopened', __( 'Die Anmeldung ist nicht geschlossen.', 'ttleague' ) );
		$this->listener->event_changed( $event_id, $actor->user_id, 'reopened' );
	}

	/**
	 * Anlass absagen. Die Anmeldungen bleiben stehen; alle Teilnehmenden und die Warteliste
	 * erhalten eine Mitteilung. Eine Absage lässt sich nicht zurücknehmen.
	 *
	 * @throws EventException 403, 404 oder 409 (schon abgesagt).
	 */
	public function cancel( int $event_id, Actor $actor ): void {
		$this->require_tk( $actor );
		$this->existing( $event_id );
		$this->db->transaction(
			function () use ( $event_id, $actor ): void {
				$before = $this->locked( $event_id );
				if ( Rules::CANCELLED === $before['status'] ) {
					throw EventException::conflict( __( 'Der Anlass ist bereits abgesagt.', 'ttleague' ) );
				}
				$this->repo->update( $event_id, array( 'status' => Rules::CANCELLED ) );
				$this->audit->record( 'event', $event_id, 'cancelled', $actor->user_id, $before, array( 'status' => Rules::CANCELLED ) + $before );
			}
		);
		$this->listener->cancelled( $event_id, $actor->user_id );
	}

	private function change_status( int $event_id, Actor $actor, string $from, string $to, string $action, string $conflict ): void {
		$this->require_tk( $actor );
		$this->existing( $event_id );
		$this->db->transaction(
			function () use ( $event_id, $actor, $from, $to, $action, $conflict ): void {
				$before = $this->locked( $event_id );
				if ( $from !== $before['status'] ) {
					throw EventException::conflict( $conflict );
				}
				$this->repo->update( $event_id, array( 'status' => $to ) );
				$this->audit->record( 'event', $event_id, $action, $actor->user_id, $before, array( 'status' => $to ) + $before );
			}
		);
	}

	/**
	 * @param array<string,mixed> $input Eingabe.
	 * @return array<string,int|string|null>
	 */
	private function validated( array $input ): array {
		$data = EventInput::validate( $input );
		if ( null !== $data['season_id'] && ! $this->repo->season_exists( $data['season_id'] ) ) {
			$message = __( 'Diese Saison gibt es nicht.', 'ttleague' );
			throw EventException::invalid(
				array(
					array(
						'field'   => 'season_id',
						'message' => $message,
					),
				),
				$message
			);
		}
		return $data;
	}

	private function require_tk( Actor $actor ): void {
		if ( ! $actor->is_tk || $actor->user_id <= 0 ) {
			throw EventException::forbidden( __( 'Anlässe verwaltet die TK.', 'ttleague' ) );
		}
	}

	/**
	 * @return array<string,mixed>
	 */
	private function existing( int $event_id ): array {
		return $this->repo->find( $event_id ) ?? throw EventException::not_found( __( 'Diesen Anlass gibt es nicht.', 'ttleague' ) );
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
