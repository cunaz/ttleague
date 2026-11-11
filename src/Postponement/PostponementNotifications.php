<?php
/**
 * Benachrichtigungen zu Verschiebung und Terminvorschlag: Sofortmeldungen und Erinnerung.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Postponement;

use TTLeague\Domain\Match\PostponementRules as Rules;
use TTLeague\Infrastructure\Time\Clock;
use TTLeague\Notifications\Deadlines;
use TTLeague\Notifications\Notification;
use TTLeague\Notifications\Notifier;
use TTLeague\Notifications\RecipientResolver;
use TTLeague\Results\FixtureContext;
use TTLeague\Results\ResultRepository;

/**
 * - Antrag: an die Gegenseite. Stellt ihn die TK, gilt er sofort und geht als Entscheid an beide.
 * - Rückzug: an die Gegenseite, die den Antrag erhalten hat.
 * - Antwort der Gegenseite (Zustimmung, Ablehnung): an den Antragsteller. Nach einer Zustimmung
 *   dazu an die TK: zur Kenntnis oder zur Bewilligung.
 * - Entscheid der TK: an beide Seiten.
 * - Erinnerung an die Gegenseite, wenn sie nach REMINDER_DAYS Tagen nicht geantwortet hat
 *   (stündlicher Lauf der Fristen, einmal pro Antrag, nur solange die Begegnung offen ist).
 *
 * «Antragsteller» und «Gegenseite» sind Seiten der Begegnung (Captains bzw. Cup-Mannschaft und
 * TK-Chefs wie in der Erfassung). Die handelnde Person erhält nie eine Nachricht über ihre
 * eigene Aktion. In WordPress hängt die Klasse an der Aktion ttleague_postponement_changed; in
 * Tests kann sie dem PostponementService direkt als PostponementEvents übergeben werden.
 */
final class PostponementNotifications implements PostponementEvents {

	private int $from_hour;

	private int $until_hour;

	/** @var callable(int):?string */
	private $user_name;

	/**
	 * @param array{from_hour?:int,until_hour?:int} $hours     Versandfenster der Erinnerung (Ortszeit), wie bei den Fristen.
	 * @param callable(int):?string|null            $user_name Anzeigename eines Kontos ohne Person.
	 */
	public function __construct(
		private readonly PostponementRepository $repo,
		private readonly ResultRepository $fixtures,
		private readonly Parties $parties,
		private readonly RecipientResolver $recipients,
		private readonly Notifier $notifier,
		private readonly PostponementMessages $messages,
		private readonly Clock $clock,
		private readonly \DateTimeZone $timezone,
		array $hours = array(),
		?callable $user_name = null,
	) {
		$this->from_hour  = max( 0, min( 23, (int) ( $hours['from_hour'] ?? Deadlines::FROM_HOUR ) ) );
		$this->until_hour = max( $this->from_hour + 1, min( 24, (int) ( $hours['until_hour'] ?? Deadlines::UNTIL_HOUR ) ) );
		$this->user_name  = $user_name ?? static fn(): ?string => null;
	}

	public function changed( int $postponement_id, string $action, int $user_id, ?int $person_id ): void {
		$row = $this->repo->find( $postponement_id );
		$ctx = null === $row ? null : $this->fixtures->context( $row['fixture_id'] );
		if ( null === $row || null === $ctx ) {
			return;
		}
		$by      = $this->actor_name( $user_id, $person_id );
		$parties = $this->parties->of( $row, $ctx );
		switch ( $action ) {
			case 'request':
				if ( Rules::APPROVED === $row['status'] ) {
					$this->send( $this->messages->decided( $row, $ctx, true ), $this->recipients->sides( $ctx ), $user_id, $person_id );
					break;
				}
				$this->send( $this->messages->request( $row, $ctx, $by, $this->needs_approval( $row, $ctx ) ), $this->recipients->sides( $ctx, $parties['opponent'] ), $user_id, $person_id );
				break;

			case Rules::WITHDRAW:
				$this->send( $this->messages->withdrawn( $row, $ctx, $by ), $this->recipients->sides( $ctx, $parties['opponent'] ), $user_id, $person_id );
				break;

			case Rules::DECLINE:
				$this->send( $this->messages->declined( $row, $ctx, $by ), $this->recipients->sides( $ctx, $parties['requester'] ), $user_id, $person_id );
				break;

			case Rules::ACCEPT:
				$this->send( $this->messages->accepted( $row, $ctx, $by ), $this->recipients->sides( $ctx, $parties['requester'] ), $user_id, $person_id );
				$this->send( $this->messages->tk( $row, $ctx, $this->repo->deadline( $ctx->id ) ), $this->recipients->tk(), $user_id, $person_id );
				break;

			case Rules::APPROVE:
			case Rules::REJECT:
				$this->send( $this->messages->decided( $row, $ctx, false ), $this->recipients->sides( $ctx ), $user_id, $person_id );
				break;
		}
	}

	/**
	 * Erinnerung an die Gegenseite für Anträge, die seit REMINDER_DAYS Tagen unbeantwortet sind.
	 * Ausserhalb des Versandfensters geht nichts hinaus; der nächste Lauf holt es nach.
	 *
	 * @return list<array{event:string,ref:int,deliveries:list<array{recipient:string,channel:string,status:string}>}>
	 */
	public function remind(): array {
		$now  = $this->clock->now();
		$hour = (int) $now->setTimezone( $this->timezone )->format( 'G' );
		if ( $hour < $this->from_hour || $hour >= $this->until_hour ) {
			return array();
		}
		$limit  = $now->modify( '-' . PostponementService::REMINDER_DAYS . ' days' )->format( 'Y-m-d H:i:s' );
		$report = array();
		foreach ( $this->repo->active_seasons() as $season ) {
			foreach ( $this->repo->open_in_season( $season, array( Rules::REQUESTED ) ) as $row ) {
				$ctx = $row['created_at'] <= $limit ? $this->fixtures->context( $row['fixture_id'] ) : null;
				// Ist inzwischen ein Resultat erfasst, kann die Gegenseite nicht mehr zustimmen: keine Erinnerung.
				if ( null === $ctx || ! Rules::fixture_open( $ctx->status ) ) {
					continue;
				}
				$notification = $this->messages->reminder( $row, $ctx, PostponementService::REMINDER_DAYS, PostponementService::UNANSWERED_DAYS );
				$requester    = $this->parties->requester_person( $row );
				$report[]     = array(
					'event'      => $notification->event,
					'ref'        => $notification->ref_id,
					'deliveries' => $this->send( $notification, $this->recipients->sides( $ctx, $this->parties->of( $row, $ctx )['opponent'] ), (int) $row['requested_by'], $requester ),
				);
			}
		}
		return $report;
	}

	/**
	 * @param list<\TTLeague\Notifications\Recipient> $recipients Empfänger.
	 * @return list<array{recipient:string,channel:string,status:string}>
	 */
	private function send( Notification $notification, array $recipients, ?int $user_id, ?int $person_id ): array {
		return $this->notifier->send( $notification, $recipients, $user_id, $person_id );
	}

	/**
	 * @param array<string,mixed> $row Antrag.
	 */
	private function needs_approval( array $row, FixtureContext $ctx ): bool {
		return Rules::needs_approval( $row['kind'], $row['old_date'], $row['new_date'], $ctx->rules->postponement_approval_days, $this->repo->deadline( $ctx->id ) );
	}

	/**
	 * Name der handelnden Person, sonst des Kontos.
	 */
	private function actor_name( int $user_id, ?int $person_id ): ?string {
		if ( null !== $person_id ) {
			$name = $this->fixtures->person_name( $person_id );
			if ( null !== $name && '' !== $name ) {
				return $name;
			}
		}
		return ( $this->user_name )( $user_id );
	}
}
