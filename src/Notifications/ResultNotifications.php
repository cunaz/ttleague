<?php
/**
 * Sofortmeldungen zu den Ereignissen der Erfassung.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Notifications;

use TTLeague\Results\ResultEvents;
use TTLeague\Results\ResultRepository;

/**
 * - Erfassung und Änderung: «Bitte bestätigen» an die Gastseite. Bezug ist der Verlaufseintrag
 *   (entered oder edited), deshalb geht nach jeder Änderung eine neue Nachricht hinaus.
 * - Einsprache: an die TK und die Heimseite.
 * - Entscheid der TK (abschliessen, korrigieren, Forfait, zurücksetzen) und Bestätigung durch
 *   die TK: an beide Seiten.
 *
 * Die handelnde Person erhält nie eine Nachricht über ihre eigene Aktion. In WordPress hängt
 * die Klasse an den Aktionen ttleague_result_*; in Tests kann sie dem ResultService direkt als
 * ResultEvents übergeben werden.
 */
final class ResultNotifications implements ResultEvents {

	/** @var callable(int):?string */
	private $user_name;

	/**
	 * @param callable(int):?string|null $user_name Anzeigename eines Kontos ohne Person.
	 */
	public function __construct(
		private readonly ResultRepository $repo,
		private readonly FixtureQueries $queries,
		private readonly RecipientResolver $recipients,
		private readonly Notifier $notifier,
		private readonly Messages $messages,
		?callable $user_name = null,
	) {
		$this->user_name = $user_name ?? static fn(): ?string => null;
	}

	public function entered( int $fixture_id, int $user_id, bool $edited ): void {
		$ctx   = $this->repo->context( $fixture_id );
		$event = $this->queries->latest_event( $fixture_id, array( $edited ? 'edited' : 'entered' ) );
		if ( null === $ctx || null === $event ) {
			return;
		}
		$this->notifier->send(
			$this->messages->confirm_request( $ctx, $event['id'], $edited, $this->actor_name( $event ) ),
			$this->recipients->side( $ctx, 'away' ),
			$user_id,
			$event['person_id']
		);
	}

	public function confirmed( int $fixture_id, int $user_id, bool $by_tk ): void {
		if ( ! $by_tk ) {
			return;
		}
		$ctx   = $this->repo->context( $fixture_id );
		$event = $this->queries->latest_event( $fixture_id, array( 'confirmed' ) );
		if ( null === $ctx || null === $event ) {
			return;
		}
		$this->notifier->send(
			$this->messages->result_decided( $ctx, $event['id'], 'confirm', null, null ),
			$this->recipients->sides( $ctx ),
			$user_id,
			$event['person_id']
		);
	}

	public function disputed( int $fixture_id, int $user_id, string $reason ): void {
		$ctx   = $this->repo->context( $fixture_id );
		$event = $this->queries->latest_event( $fixture_id, array( 'disputed' ) );
		if ( null === $ctx || null === $event ) {
			return;
		}
		$this->notifier->send(
			$this->messages->result_disputed( $ctx, $event['id'], $reason, $this->actor_name( $event ) ),
			array_merge( $this->recipients->tk(), $this->recipients->side( $ctx, 'home' ) ),
			$user_id,
			$event['person_id']
		);
	}

	public function decided( int $fixture_id, int $user_id, string $action, ?string $comment ): void {
		$types = match ( $action ) {
			'forfeit' => array( 'forfeit' ),
			'reset'   => array( 'reset' ),
			default   => array( 'decided' ),
		};
		$ctx   = $this->repo->context( $fixture_id );
		$event = $this->queries->latest_event( $fixture_id, $types );
		if ( null === $ctx || null === $event ) {
			return;
		}
		$winner = $event['data']['winner'] ?? null;
		$this->notifier->send(
			$this->messages->result_decided( $ctx, $event['id'], $action, $comment, is_string( $winner ) ? $winner : null ),
			$this->recipients->sides( $ctx ),
			$user_id,
			$event['person_id']
		);
	}

	public function photo_added( int $fixture_id, int $user_id, int $attachment_id ): void {
		unset( $fixture_id, $user_id, $attachment_id ); // Keine Benachrichtigung.
	}

	/**
	 * Name der handelnden Person aus dem Verlauf, sonst des Kontos.
	 *
	 * @param array{user_id:int|null,person_id:int|null} $event Verlaufseintrag.
	 */
	private function actor_name( array $event ): ?string {
		if ( null !== $event['person_id'] ) {
			$name = $this->repo->person_name( $event['person_id'] );
			if ( null !== $name && '' !== $name ) {
				return $name;
			}
		}
		return null === $event['user_id'] ? null : ( $this->user_name )( $event['user_id'] );
	}
}
