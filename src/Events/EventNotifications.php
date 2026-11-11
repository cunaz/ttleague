<?php
/**
 * Benachrichtigungen zu den Ereignissen der Anlässe.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Events;

use TTLeague\Domain\Events\RegistrationRules;
use TTLeague\Notifications\Notifier;
use TTLeague\Notifications\RecipientResolver;

/**
 * - An- und Abmeldung: Bestätigung an die betroffene Person, auch wenn sie selbst gehandelt
 *   hat. Hat jemand anderes gehandelt (TK-Chef, TK), nennt die Nachricht den Namen.
 * - Nachrücken: Mitteilung an die nachgerückte Person.
 * - Absage: an alle Teilnehmenden und die Warteliste, ausser an das Konto, das abgesagt hat.
 *   Nicht im Aufruf der Absage, sondern danach in begrenzten Schritten (send_cancellations()):
 *   Bei vielen Empfängern und langsamem Versand bräche sonst das Zeitlimit der Anfrage ab, und
 *   der Rest erhielte nichts.
 *
 * Nur aktive Personen; die Kanäle wählt jede Person für den Ereignistyp event_registration.
 * In WordPress hängt die Klasse an den Aktionen ttleague_event_*; in Tests kann sie den
 * Diensten direkt als EventListener übergeben werden.
 */
final class EventNotifications implements EventListener {

	/** Höchstzahl der Empfänger, an die ein Lauf Absagemitteilungen versendet. */
	public const CANCEL_BATCH = 25;

	/** So viele Tage nach der Absage holt der stündliche Lauf ausstehende Mitteilungen nach. */
	public const CANCEL_CATCH_UP_DAYS = 7;

	/** Für höchstens so viele Anlässe plant ein stündlicher Lauf den Versand erneut ein. */
	public const CANCEL_CATCH_UP_EVENTS = 10;

	/** @var callable(int):?string */
	private $user_name;

	/** @var callable(\Throwable,string):void */
	private $on_error;

	/**
	 * @param callable(int):?string|null             $user_name Anzeigename eines Kontos ohne Person.
	 * @param callable(\Throwable,string):void|null $on_error  Fehler bei einem Empfänger melden.
	 */
	public function __construct(
		private readonly EventRepository $repo,
		private readonly RecipientResolver $recipients,
		private readonly Notifier $notifier,
		private readonly EventMessages $messages,
		?callable $user_name = null,
		?callable $on_error = null,
	) {
		$this->user_name = $user_name ?? static fn(): ?string => null;
		$this->on_error  = $on_error ?? static function (): void {};
	}

	public function event_changed( int $event_id, int $user_id, string $action ): void {
		unset( $event_id, $user_id, $action ); // Keine Benachrichtigung.
	}

	/**
	 * Die Absage selbst versendet nichts: Die WordPress-Schicht plant den Versand ein
	 * (Events::on_cancelled()), der dann über send_cancellations() läuft.
	 */
	public function cancelled( int $event_id, int $user_id ): void {
		unset( $event_id, $user_id );
	}

	/**
	 * Ein Schritt des Versands der Absagemitteilungen: an höchstens $limit Empfänger, die laut
	 * Versandprotokoll noch keine haben, in der Reihenfolge der Person-ID ab der Person nach
	 * $after_person. Beliebig oft wiederholbar: Wer die Mitteilung hat, erhält sie nicht noch
	 * einmal (NotificationLog). Das Konto, das abgesagt hat, steht im Änderungsprotokoll und
	 * bleibt ausgenommen. Ein Fehler bei einem Empfänger wird gemeldet und hält die übrigen
	 * nicht auf; er zählt zum Schritt, damit auch dann jeder Lauf begrenzt bleibt.
	 *
	 * Personen ohne Kanal (inaktiv, keine gültige Adresse, Benachrichtigung abgewählt) und das
	 * ausgenommene Konto kosten keinen Versand und zählen nicht.
	 *
	 * @return array{sent:int,failed:int,next:int|null} Empfänger mit versendeter Mitteilung,
	 *         Empfänger mit gescheitertem Versand und die Person, nach der der nächste Schritt
	 *         weitermacht (null, wenn dieser Durchgang alle besucht hat).
	 */
	public function send_cancellations( int $event_id, int $after_person = 0, int $limit = self::CANCEL_BATCH ): array {
		$result = array(
			'sent'   => 0,
			'failed' => 0,
			'next'   => null,
		);
		$event  = $this->repo->find( $event_id );
		if ( null === $event || RegistrationRules::CANCELLED !== $event['status'] ) {
			return $result;
		}
		$pending    = $this->repo->people_without_notice( $event_id, EventMessages::CANCELLED, EventMessages::REF_EVENT, $after_person );
		$recipients = array();
		foreach ( $this->recipients->persons( $pending ) as $recipient ) {
			$recipients[ (int) $recipient->person_id ] = $recipient;
		}
		$notification = $this->messages->cancelled( $event );
		$exclude      = $this->repo->cancelled_by( $event_id );
		$attempts     = 0;
		$last         = $after_person;
		foreach ( $pending as $person_id ) {
			if ( $attempts >= $limit ) {
				$result['next'] = $last;
				break;
			}
			$last = $person_id;
			if ( ! isset( $recipients[ $person_id ] ) ) {
				continue;
			}
			try {
				$report = $this->notifier->send( $notification, array( $recipients[ $person_id ] ), $exclude );
			} catch ( \Throwable $e ) {
				( $this->on_error )( $e, EventMessages::CANCELLED . ' p' . $person_id );
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
	 * Abgesagte Anlässe mit ausstehenden Absagemitteilungen, deren Absage höchstens
	 * CANCEL_CATCH_UP_DAYS Tage zurückliegt, die älteste Absage zuerst: Für sie plant der
	 * stündliche Lauf den Versand erneut ein.
	 *
	 * @param \DateTimeImmutable $now Jetzt (UTC).
	 * @return list<int>
	 */
	public function cancellations_to_catch_up( \DateTimeImmutable $now ): array {
		return $this->repo->cancelled_with_pending_notices(
			$now->modify( '-' . self::CANCEL_CATCH_UP_DAYS . ' days' )->format( 'Y-m-d H:i:s' ),
			EventMessages::CANCELLED,
			EventMessages::REF_EVENT
		);
	}

	public function registered( int $event_id, int $registration_id, int $person_id, string $status, int $user_id ): void {
		$event = $this->repo->find( $event_id );
		if ( null === $event ) {
			return;
		}
		$this->notifier->send( $this->messages->registered( $event, $registration_id, $status, $this->other_actor( $person_id, $user_id ) ), $this->recipients->persons( array( $person_id ) ) );
	}

	public function unregistered( int $event_id, int $registration_id, int $person_id, string $status, int $user_id ): void {
		unset( $status );
		$event = $this->repo->find( $event_id );
		if ( null === $event ) {
			return;
		}
		$this->notifier->send( $this->messages->unregistered( $event, $registration_id, $this->other_actor( $person_id, $user_id ) ), $this->recipients->persons( array( $person_id ) ) );
	}

	public function promoted( int $event_id, int $registration_id, int $person_id ): void {
		$event = $this->repo->find( $event_id );
		if ( null === $event ) {
			return;
		}
		$this->notifier->send( $this->messages->promoted( $event, $registration_id ), $this->recipients->persons( array( $person_id ) ) );
	}

	/**
	 * Name der handelnden Person, wenn sie nicht die betroffene Person ist; sonst null.
	 */
	private function other_actor( int $person_id, int $user_id ): ?string {
		$person = $this->repo->person( $person_id );
		if ( null !== $person && null !== $person['user_id'] && $person['user_id'] === $user_id ) {
			return null;
		}
		$name = $this->repo->person_name_for_user( $user_id ) ?? ( $this->user_name )( $user_id );
		return null === $name || '' === trim( $name ) ? __( 'Die TK', 'ttleague' ) : trim( $name );
	}
}
