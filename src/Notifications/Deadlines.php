<?php
/**
 * Fristen und Erinnerungen: stündlicher Lauf mit einstellbarer Uhr.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Notifications;

use TTLeague\Domain\Rules\SeasonRules;
use TTLeague\Infrastructure\Time\Clock;
use TTLeague\Results\FixtureContext;
use TTLeague\Results\ResultRepository;

/**
 * Prüft bei jedem Lauf alle offenen Begegnungen der aktiven Saison und versendet, was fällig
 * ist. Jede Nachricht geht dank notification_log nur einmal hinaus; ein verpasster Lauf wird
 * beim nächsten nachgeholt. Werte aus den SeasonRules der Saison:
 *
 * - Spiel steht an: match_reminder_days vor der Anspielzeit an beide Seiten.
 * - Resultat fehlt: result_reminder_hours nach Anspielzeit an die Heimseite (bis zum
 *   Forfait-Vorschlag), entry_deadline_hours danach an die TK.
 * - Forfait-Vorschlag: forfeit_after_days nach Anspielzeit ohne Resultat an die TK.
 * - Bestätigung ausstehend: confirm_reminder_days nach der letzten Erfassung an die Gastseite
 *   (nur die höchste fällige Stufe), objection_days danach an die TK.
 * - Übersicht für die TK (tk_digest): einmal täglich ab digest_hour, nur mit offenen Punkten.
 *
 * Spieltermine sind Ortszeit, entered_at ist UTC. Zwischen until_hour und from_hour (Ortszeit)
 * geht nichts hinaus; fällige Nachrichten folgen am Morgen.
 */
final class Deadlines {

	public const FROM_HOUR   = 7;
	public const UNTIL_HOUR  = 22;
	public const DIGEST_HOUR = 7;

	private int $from_hour;

	private int $until_hour;

	private int $digest_hour;

	/** @var list<Recipient>|null TK-Empfänger des laufenden Laufs. */
	private ?array $tk = null;

	/**
	 * @param array{from_hour?:int,until_hour?:int,digest_hour?:int} $options Versandfenster und Stunde der Übersicht (Ortszeit).
	 */
	public function __construct(
		private readonly Clock $clock,
		private readonly \DateTimeZone $timezone,
		private readonly FixtureQueries $queries,
		private readonly ResultRepository $repo,
		private readonly RecipientResolver $recipients,
		private readonly Notifier $notifier,
		private readonly Messages $messages,
		private readonly ?NotificationLog $log = null,
		array $options = array(),
	) {
		$this->from_hour   = max( 0, min( 23, (int) ( $options['from_hour'] ?? self::FROM_HOUR ) ) );
		$this->until_hour  = max( $this->from_hour + 1, min( 24, (int) ( $options['until_hour'] ?? self::UNTIL_HOUR ) ) );
		$this->digest_hour = max( 0, min( 23, (int) ( $options['digest_hour'] ?? self::DIGEST_HOUR ) ) );
	}

	/**
	 * Ein Lauf; liefert, was versendet wurde.
	 *
	 * @return list<array{event:string,ref:int,deliveries:list<array{recipient:string,channel:string,status:string}>}>
	 */
	public function run(): array {
		$this->tk = null;
		$now      = $this->clock->now()->setTimezone( new \DateTimeZone( 'UTC' ) );
		$local    = $now->setTimezone( $this->timezone );
		$this->log?->purge();

		$hour = (int) $local->format( 'G' );
		if ( $hour < $this->from_hour || $hour >= $this->until_hour ) {
			return array();
		}

		$report = array();
		$open   = array(
			'missing'  => array(),
			'forfeit'  => array(),
			'overdue'  => array(),
			'disputed' => array(),
		);
		foreach ( $this->queries->active_seasons() as $season ) {
			$this->match_reminders( $season['id'], $season['rules'], $local, $report );
			$this->missing_results( $season['id'], $season['rules'], $local, $report, $open );
			$this->confirmations( $season['id'], $season['rules'], $now, $report, $open );
			foreach ( $this->queries->disputed( $season['id'] ) as $fixture_id ) {
				$ctx = $this->repo->context( $fixture_id );
				if ( null !== $ctx ) {
					$open['disputed'][] = $ctx;
				}
			}
		}

		$count = count( $open['missing'] ) + count( $open['forfeit'] ) + count( $open['overdue'] ) + count( $open['disputed'] );
		if ( $hour >= $this->digest_hour && $count > 0 ) {
			$notification = $this->messages->tk_digest( $local->format( 'Y-m-d' ), $open );
			$report[]     = self::entry( $notification, $this->notifier->send( $notification, $this->tk() ) );
		}
		return $report;
	}

	/**
	 * TK-Empfänger, einmal pro Lauf aufgelöst (jede offene Begegnung braucht sie wieder).
	 *
	 * @return list<Recipient>
	 */
	private function tk(): array {
		return $this->tk ??= $this->recipients->tk();
	}

	/**
	 * Spiel steht an: beide Seiten, match_reminder_days vor der Anspielzeit.
	 *
	 * @param list<array<string,mixed>> $report Bericht.
	 */
	private function match_reminders( int $season_id, SeasonRules $rules, \DateTimeImmutable $local, array &$report ): void {
		$days = $rules->match_reminder_days;
		if ( $days <= 0 ) {
			return;
		}
		$rows = $this->queries->open_between( $season_id, $local->format( 'Y-m-d H:i:s' ), $local->modify( '+' . ( $days + 1 ) . ' days' )->format( 'Y-m-d H:i:s' ) );
		foreach ( $rows as $row ) {
			$start = $this->local_time( $row['scheduled_at'] );
			if ( null === $start || $local >= $start || $local < $start->modify( '-' . $days . ' days' ) ) {
				continue;
			}
			$ctx = $this->repo->context( $row['id'] );
			if ( null === $ctx ) {
				continue;
			}
			$notification = $this->messages->match_reminder( $ctx, $this->queries->venue( $ctx->id ) );
			$report[]     = self::entry( $notification, $this->notifier->send( $notification, $this->recipients->sides( $ctx ) ) );
		}
	}

	/**
	 * Resultat fehlt (Heimseite, TK) und Forfait-Vorschlag (TK).
	 *
	 * @param list<array<string,mixed>>          $report Bericht.
	 * @param array<string,list<FixtureContext>> $open   Offene Punkte für die Übersicht.
	 */
	private function missing_results( int $season_id, SeasonRules $rules, \DateTimeImmutable $local, array &$report, array &$open ): void {
		foreach ( $this->queries->open_until( $season_id, $local->format( 'Y-m-d H:i:s' ) ) as $row ) {
			$start = $this->local_time( $row['scheduled_at'] );
			if ( null === $start ) {
				continue;
			}
			$home_due = $local >= $start->modify( '+' . $rules->result_reminder_hours . ' hours' );
			$tk_due   = $local >= $start->modify( '+' . $rules->entry_deadline_hours . ' hours' );
			$forfeit  = $local >= $start->modify( '+' . $rules->forfeit_after_days . ' days' );
			if ( ! $home_due && ! $tk_due ) {
				continue;
			}
			$ctx = $this->repo->context( $row['id'] );
			if ( null === $ctx ) {
				continue;
			}
			if ( $home_due && ! $forfeit ) {
				$deadline     = $start->modify( '+' . $rules->entry_deadline_hours . ' hours' );
				$notification = $this->messages->result_missing( $ctx, $deadline->format( 'Y-m-d H:i:s' ), $local >= $deadline );
				$report[]     = self::entry( $notification, $this->notifier->send( $notification, $this->recipients->side( $ctx, 'home' ) ) );
			}
			if ( $forfeit ) {
				$open['forfeit'][] = $ctx;
				$notification      = $this->messages->forfeit_proposal( $ctx );
			} elseif ( $tk_due ) {
				$open['missing'][] = $ctx;
				$notification      = $this->messages->result_missing_tk( $ctx );
			} else {
				continue;
			}
			$report[] = self::entry( $notification, $this->notifier->send( $notification, $this->tk() ) );
		}
	}

	/**
	 * Bestätigung ausstehend (Gastseite) und Bestätigung durch die TK nötig.
	 *
	 * @param list<array<string,mixed>>          $report Bericht.
	 * @param array<string,list<FixtureContext>> $open   Offene Punkte für die Übersicht.
	 */
	private function confirmations( int $season_id, SeasonRules $rules, \DateTimeImmutable $now, array &$report, array &$open ): void {
		$steps = array_values( array_unique( array_filter( $rules->confirm_reminder_days, static fn( int $d ): bool => $d > 0 ) ) );
		sort( $steps );
		foreach ( $this->queries->provisional( $season_id ) as $row ) {
			$entered = \DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s', $row['entered_at'], new \DateTimeZone( 'UTC' ) );
			if ( false === $entered ) {
				continue;
			}
			$step = null;
			foreach ( $steps as $days ) {
				if ( $now >= $entered->modify( '+' . $days . ' days' ) ) {
					$step = $days;
				}
			}
			$overdue = $rules->objection_days > 0 && $now >= $entered->modify( '+' . $rules->objection_days . ' days' );
			if ( null === $step && ! $overdue ) {
				continue;
			}
			$ctx   = $this->repo->context( $row['id'] );
			$event = $this->queries->latest_event( $row['id'], array( 'entered', 'edited' ) );
			if ( null === $ctx || null === $event ) {
				continue;
			}
			if ( null !== $step ) {
				$notification = $this->messages->confirm_reminder( $ctx, $event['id'], $step );
				$report[]     = self::entry( $notification, $this->notifier->send( $notification, $this->recipients->side( $ctx, 'away' ), $event['user_id'], $event['person_id'] ) );
			}
			if ( $overdue ) {
				$open['overdue'][] = $ctx;
				$notification      = $this->messages->confirm_overdue( $ctx, $event['id'] );
				$report[]          = self::entry( $notification, $this->notifier->send( $notification, $this->tk() ) );
			}
		}
	}

	/**
	 * Spieltermin (Ortszeit) als Zeitpunkt.
	 */
	private function local_time( string $value ): ?\DateTimeImmutable {
		$time = \DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s', $value, $this->timezone );
		return false === $time ? null : $time;
	}

	/**
	 * @param list<array{recipient:string,channel:string,status:string}> $deliveries Zustellungen.
	 * @return array{event:string,ref:int,deliveries:list<array{recipient:string,channel:string,status:string}>}
	 */
	private static function entry( Notification $notification, array $deliveries ): array {
		return array(
			'event'      => $notification->event,
			'ref'        => $notification->ref_id,
			'deliveries' => $deliveries,
		);
	}
}
