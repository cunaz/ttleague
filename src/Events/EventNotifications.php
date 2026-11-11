<?php
/**
 * Benachrichtigungen zu den Ereignissen der Anlässe.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Events;

use TTLeague\Notifications\Notifier;
use TTLeague\Notifications\RecipientResolver;

/**
 * - An- und Abmeldung: Bestätigung an die betroffene Person, auch wenn sie selbst gehandelt
 *   hat. Hat jemand anderes gehandelt (TK-Chef, TK), nennt die Nachricht den Namen.
 * - Nachrücken: Mitteilung an die nachgerückte Person.
 * - Absage: an alle Teilnehmenden und die Warteliste, ausser an das Konto, das abgesagt hat.
 *
 * Nur aktive Personen; die Kanäle wählt jede Person für den Ereignistyp event_registration.
 * In WordPress hängt die Klasse an den Aktionen ttleague_event_*; in Tests kann sie den
 * Diensten direkt als EventListener übergeben werden.
 */
final class EventNotifications implements EventListener {

	/** @var callable(int):?string */
	private $user_name;

	/**
	 * @param callable(int):?string|null $user_name Anzeigename eines Kontos ohne Person.
	 */
	public function __construct(
		private readonly EventRepository $repo,
		private readonly RecipientResolver $recipients,
		private readonly Notifier $notifier,
		private readonly EventMessages $messages,
		?callable $user_name = null,
	) {
		$this->user_name = $user_name ?? static fn(): ?string => null;
	}

	public function event_changed( int $event_id, int $user_id, string $action ): void {
		unset( $event_id, $user_id, $action ); // Keine Benachrichtigung.
	}

	public function cancelled( int $event_id, int $user_id ): void {
		$event = $this->repo->find( $event_id );
		if ( null === $event ) {
			return;
		}
		$this->notifier->send( $this->messages->cancelled( $event ), $this->recipients->persons( $this->repo->registered_people( $event_id ) ), $user_id );
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
