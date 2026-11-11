<?php
/**
 * Benachrichtigungen des Mannschaftscups: Auslosung und neue Runde, Frist läuft ab.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Cup;

use TTLeague\Domain\Cup\BracketRules as Rules;
use TTLeague\Infrastructure\Time\Clock;
use TTLeague\Notifications\Deadlines;
use TTLeague\Notifications\Notification;
use TTLeague\Notifications\Notifier;
use TTLeague\Notifications\RecipientResolver;

/**
 * Empfänger sind immer die Spieler der betroffenen Cupmannschaften und die TK-Chefs ihrer Clubs
 * (CupRepository::entry_people()), nur aktive Personen; die Kanäle wählt jede Person für die
 * Ereignistypen cup_round und cup_deadline.
 *
 * - cup_round: Nach der Freigabe der Auslosung erhalten alle Mannschaften ihre Paarung oder ihr
 *   Freilos. Das sind viele Personen, darum läuft der Versand nicht in der Anfrage der TK,
 *   sondern in begrenzten Schritten (send_round()), die die WordPress-Schicht über WP-Cron
 *   einplant. Entsteht später eine einzelne Begegnung (Nachrücken) oder setzt die TK eine an,
 *   geht die Mitteilung an die wenigen Betroffenen direkt hinaus (announce()).
 * - cup_deadline: REMINDER_DAYS Tage vor der Frist einer Paarung, wenn ihre Begegnung weder
 *   Termin noch Resultat hat. Hängt am stündlichen Lauf der Fristen und beachtet dessen
 *   Versandfenster. Bis und mit dem Tag der Frist; danach steht die Begegnung bei der TK.
 */
final class CupNotifications {

	/** So viele Tage vor der Frist geht die Erinnerung hinaus (docs/cup.md). */
	public const REMINDER_DAYS = 7;

	/** Höchstzahl der Empfänger, an die ein Schritt Mitteilungen zur Runde versendet. */
	public const ROUND_BATCH = 25;

	/** So viele Tage nach einer neuen Paarung holt der stündliche Lauf ausstehende Mitteilungen nach. */
	public const CATCH_UP_DAYS = 7;

	/** Für höchstens so viele Konkurrenzen plant ein stündlicher Lauf den Versand erneut ein. */
	public const CATCH_UP_COMPETITIONS = 10;

	private int $from_hour;

	private int $until_hour;

	/** @var callable(\Throwable,string):void */
	private $on_error;

	/**
	 * @param array{from_hour?:int,until_hour?:int}  $hours    Versandfenster der Erinnerung (Ortszeit), wie bei den Fristen.
	 * @param callable(\Throwable,string):void|null $on_error Fehler bei einem Empfänger melden.
	 */
	public function __construct(
		private readonly CupRepository $repo,
		private readonly RecipientResolver $recipients,
		private readonly Notifier $notifier,
		private readonly CupMessages $messages,
		private readonly Clock $clock,
		private readonly \DateTimeZone $timezone,
		array $hours = array(),
		?callable $on_error = null,
	) {
		$this->from_hour  = max( 0, min( 23, (int) ( $hours['from_hour'] ?? Deadlines::FROM_HOUR ) ) );
		$this->until_hour = max( $this->from_hour + 1, min( 24, (int) ( $hours['until_hour'] ?? Deadlines::UNTIL_HOUR ) ) );
		$this->on_error   = $on_error ?? static function (): void {};
	}

	/**
	 * Mitteilung zu einzelnen Paarungen direkt versenden (neue Begegnung, angesetzt, Heimrecht).
	 *
	 * @param list<int> $tie_ids Paarungen.
	 * @return array{sent:int,failed:int}
	 */
	public function announce( int $competition_id, array $tie_ids ): array {
		$result = $this->deliver( $competition_id, array_values( array_unique( array_map( 'intval', $tie_ids ) ) ), PHP_INT_MAX );
		return array(
			'sent'   => $result['sent'],
			'failed' => $result['failed'],
		);
	}

	/**
	 * Ein Schritt des Versands nach der Auslosung: an höchstens $limit Empfänger, in der
	 * Reihenfolge der Paarungen ab der Paarung nach $after_tie. Beliebig oft wiederholbar: Wer
	 * die Mitteilung hat, erhält sie nicht noch einmal (NotificationLog). Ein Fehler bei einem
	 * Empfänger wird gemeldet und hält die übrigen nicht auf; er zählt zum Schritt, damit jeder
	 * Lauf begrenzt bleibt. Personen ohne Kanal kosten keinen Versand und zählen nicht.
	 *
	 * @return array{sent:int,failed:int,next:int|null} Empfänger mit versendeter Mitteilung,
	 *         Empfänger mit gescheitertem Versand und die Paarung, nach der der nächste Schritt
	 *         weitermacht (null, wenn dieser Durchgang alle besucht hat).
	 */
	public function send_round( int $competition_id, int $after_tie = 0, int $limit = self::ROUND_BATCH ): array {
		return $this->deliver( $competition_id, $this->repo->ties_announced_since( $competition_id, $this->since(), $after_tie ), $limit, $after_tie );
	}

	/**
	 * Konkurrenzen aktiver Saisons, in denen zu einer höchstens CATCH_UP_DAYS Tage alten Paarung
	 * noch Mitteilungen ausstehen: Für sie plant der stündliche Lauf den Versand erneut ein.
	 *
	 * @return list<int>
	 */
	public function competitions_to_catch_up(): array {
		$pending = array();
		foreach ( $this->repo->active_competitions_with_draw() as $competition_id ) {
			$ties = $this->repo->ties_announced_since( $competition_id, $this->since() );
			if ( array() === $ties ) {
				continue;
			}
			foreach ( $this->notices( $competition_id, $ties ) as $notice ) {
				foreach ( $this->recipients->persons( $notice['people'] ) as $recipient ) {
					if ( ! $this->repo->was_notified( (int) $recipient->person_id, $notice['notification']->event, $notice['notification']->ref_type, $notice['notification']->ref_id, $notice['notification']->ref_key ) ) {
						$pending[] = $competition_id;
						continue 3;
					}
				}
			}
		}
		return $pending;
	}

	/**
	 * Stündlicher Lauf: Erinnerung an beide Mannschaften, wenn die Frist ihrer Paarung in
	 * höchstens REMINDER_DAYS Tagen abläuft und die Begegnung weder Termin noch Resultat hat.
	 * Ausserhalb des Versandfensters geht nichts hinaus; der nächste Lauf holt es nach.
	 *
	 * @return list<array{event:string,ref:int,deliveries:list<array{recipient:string,channel:string,status:string}>}>
	 */
	public function remind_deadlines(): array {
		$local = $this->clock->now()->setTimezone( $this->timezone );
		$hour  = (int) $local->format( 'G' );
		if ( $hour < $this->from_hour || $hour >= $this->until_hour ) {
			return array();
		}
		$today  = $local->format( 'Y-m-d' );
		$until  = $local->modify( '+' . self::REMINDER_DAYS . ' days' )->format( 'Y-m-d' );
		$report = array();
		foreach ( $this->repo->active_competitions_with_draw() as $competition_id ) {
			$competition = $this->repo->competition( $competition_id );
			$ties        = $this->repo->ties( $competition_id );
			if ( null === $competition || array() === $ties ) {
				continue;
			}
			$rounds = max( array_column( $ties, 'round' ) );
			$names  = array_column( $this->repo->entries( $competition_id ), 'name', 'id' );
			foreach ( $ties as $tie ) {
				$fixture = $tie['fixture'];
				if ( null === $tie['deadline'] || $tie['deadline'] < $today || $tie['deadline'] > $until ) {
					continue;
				}
				if ( null === $fixture || null !== $fixture['scheduled_at'] || ! Rules::fixture_untouched( $fixture['status'], $fixture['home_score'], $fixture['away_score'] ) ) {
					continue;
				}
				// Ein Fehler bei einer Paarung wird gemeldet und hält die übrigen nicht auf.
				try {
					$notification = $this->messages->deadline( $competition, $tie, $rounds, $names );
					$report[]     = array(
						'event'      => $notification->event,
						'ref'        => $notification->ref_id,
						'deliveries' => $this->notifier->send( $notification, $this->recipients->persons( $this->people( $fixture ) ) ),
					);
				} catch ( \Throwable $e ) {
					( $this->on_error )( $e, CupMessages::DEADLINE . ' ' . $tie['id'] );
				}
			}
		}
		return $report;
	}

	/**
	 * Versendet die Mitteilungen der Paarungen in ihrer Reihenfolge, bis $limit Empfänger
	 * erreicht sind.
	 *
	 * @param list<int> $tie_ids Paarungen, aufsteigend.
	 * @return array{sent:int,failed:int,next:int|null}
	 */
	private function deliver( int $competition_id, array $tie_ids, int $limit, int $after_tie = 0 ): array {
		$result   = array(
			'sent'   => 0,
			'failed' => 0,
			'next'   => null,
		);
		$attempts = 0;
		$done     = $after_tie;
		foreach ( $this->notices( $competition_id, $tie_ids ) as $notice ) {
			foreach ( $this->recipients->persons( $notice['people'] ) as $recipient ) {
				if ( $attempts >= $limit ) {
					// Die angefangene Paarung besucht der nächste Schritt noch einmal; wer die
					// Mitteilung schon hat, wird dabei übersprungen.
					$result['next'] = $done;
					return $result;
				}
				try {
					$report = $this->notifier->send( $notice['notification'], array( $recipient ) );
				} catch ( \Throwable $e ) {
					( $this->on_error )( $e, $notice['notification']->event . ' ' . $recipient->key() );
					$report = array( array( 'status' => Notifier::FAILED ) );
				}
				$statuses = array_column( $report, 'status' );
				if ( in_array( Notifier::SENT, $statuses, true ) ) {
					++$result['sent'];
					++$attempts;
				} elseif ( in_array( Notifier::FAILED, $statuses, true ) ) {
					++$result['failed'];
					++$attempts;
				}
			}
			$done = $notice['tie'];
		}
		return $result;
	}

	/**
	 * Mitteilungen zu Paarungen mit ihren Empfängern: eine offene Begegnung (beide Mannschaften)
	 * oder ein Freilos der ersten Runde. Paarungen ohne Begegnung, mit Resultat oder mit
	 * annullierter Begegnung brauchen keine.
	 *
	 * @param list<int> $tie_ids Paarungen.
	 * @return list<array{tie:int,notification:Notification,people:list<int>}>
	 */
	private function notices( int $competition_id, array $tie_ids ): array {
		$competition = $this->repo->competition( $competition_id );
		if ( null === $competition || array() === $tie_ids ) {
			return array();
		}
		$ties    = array_column( $this->repo->ties( $competition_id ), null, 'id' );
		$rounds  = array() === $ties ? 0 : max( array_column( $ties, 'round' ) );
		$entries = array_column( $this->repo->entries( $competition_id ), null, 'id' );
		$names   = array_column( $entries, 'name', 'id' );
		$venues  = null;
		$notices = array();
		foreach ( $tie_ids as $tie_id ) {
			$tie = $ties[ $tie_id ] ?? null;
			if ( null === $tie ) {
				continue;
			}
			$fixture = $tie['fixture'];
			if ( null !== $fixture ) {
				if ( ! Rules::fixture_untouched( $fixture['status'], $fixture['home_score'], $fixture['away_score'] ) ) {
					continue;
				}
				$venue = null;
				if ( null !== $fixture['venue_id'] ) {
					$venues ??= $this->repo->venues();
					$venue    = $venues[ $fixture['venue_id'] ] ?? null;
				}
				$notices[] = array(
					'tie'          => $tie_id,
					'notification' => $this->messages->round( $competition, $tie, $rounds, $names, $venue ),
					'people'       => $this->people( $fixture ),
				);
				continue;
			}
			$entry = $tie['home'] ?? $tie['away'];
			if ( 1 === $tie['round'] && null !== $entry && ( null === $tie['home'] || null === $tie['away'] ) && CupRepository::ACTIVE === ( $entries[ $entry ]['status'] ?? '' ) ) {
				$notices[] = array(
					'tie'          => $tie_id,
					'notification' => $this->messages->bye( $competition, $tie, $rounds, (string) $names[ $entry ] ),
					'people'       => $this->repo->entry_people( $entry ),
				);
			}
		}
		return $notices;
	}

	/**
	 * Personen beider Mannschaften einer Begegnung, das Heimteam zuerst.
	 *
	 * @param array{home_entry:int|null,away_entry:int|null} $fixture Begegnung.
	 * @return list<int>
	 */
	private function people( array $fixture ): array {
		$people = array();
		foreach ( array( $fixture['home_entry'], $fixture['away_entry'] ) as $entry ) {
			if ( null !== $entry ) {
				$people = array_merge( $people, $this->repo->entry_people( $entry ) );
			}
		}
		return array_values( array_unique( $people ) );
	}

	/**
	 * Älteste Paarung (UTC), zu der noch Mitteilungen nachgeholt werden.
	 */
	private function since(): string {
		return $this->clock->now()->modify( '-' . self::CATCH_UP_DAYS . ' days' )->format( 'Y-m-d H:i:s' );
	}
}
