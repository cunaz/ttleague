<?php
/**
 * Benachrichtigungen des Ranglistenturniers: Aufgebot und Erinnerungen.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\RankingTournament;

use TTLeague\Domain\Ranking\RlPlayerStatus;
use TTLeague\Frontend\Support\Format;
use TTLeague\Infrastructure\Database\Db;
use TTLeague\Infrastructure\Time\Clock;
use TTLeague\Notifications\EventTypes;
use TTLeague\Notifications\Notification;
use TTLeague\Notifications\Notifier;
use TTLeague\Notifications\RecipientResolver;

/**
 * - rl_callup: Aufgebot an die Aufgebotenen einer Gruppe (nicht an Abgemeldete) nach dem
 *   Freigeben der Runde und nach jeder Änderung der Gruppe. Nicht im Aufruf der Freigabe, sondern
 *   danach in begrenzten Schritten (send_callups()): Bei vielen Empfängern und langsamem Versand
 *   bräche sonst das Zeitlimit der Anfrage ab, und der Rest erhielte nichts. Bezug ist die Gruppe,
 *   der Zusatz eine Kennung aus Inhalt und letzter Änderung (callup_key()): Dasselbe Aufgebot
 *   geht nie doppelt hinaus, ein geändertes immer.
 * - rl_reminder: Angemeldete ohne gemeldete Sperrdaten für die nächste Runde in Planung, in den
 *   Tagen vor dem Anmeldeschluss (format_json reminder_days, Standard 3) und vor jeder Runde, die
 *   noch nicht eingeteilt ist (blocked_reminder_days vor Beginn der Rundenwoche, Standard 10).
 *   Pro Person, Runde und Anlass einmal. Läuft im stündlichen Fristenlauf, ausserhalb der Ruhezeit.
 */
final class Notices {

	public const CALLUP   = 'rl_callup';
	public const REMINDER = 'rl_reminder';

	/** Bezug des Aufgebots im Versandprotokoll: die Gruppe. */
	public const REF_GROUP = 'rl_group';

	/** Höchstzahl der Empfänger, an die ein Schritt Aufgebote versendet. */
	public const CALLUP_BATCH = 25;

	/** Tage vor dem Anmeldeschluss, ab denen erinnert wird (format_json reminder_days). */
	public const REMINDER_DAYS = 3;

	/** Tage vor Beginn einer nicht eingeteilten Runde, ab denen erinnert wird (format_json blocked_reminder_days). */
	public const BLOCKED_REMINDER_DAYS = 10;

	private Repository $repo;

	/** @var callable(\Throwable,string):void */
	private $on_error;

	/**
	 * @param callable(\Throwable,string):void|null $on_error Fehler bei einem Empfänger melden.
	 */
	public function __construct(
		private readonly Db $db,
		private readonly Clock $clock,
		private readonly \DateTimeZone $timezone,
		private readonly RecipientResolver $recipients,
		private readonly Notifier $notifier,
		private readonly Links $links,
		?callable $on_error = null,
	) {
		$this->repo     = new Repository( $db );
		$this->on_error = $on_error ?? static function (): void {};
	}

	/**
	 * Ein Schritt des Versands der Aufgebote einer freigegebenen Runde: an höchstens $limit
	 * Aufgebotene, denen laut Versandprotokoll das aktuelle Aufgebot ihrer Gruppe fehlt, in der
	 * Reihenfolge der Person-ID ab der Person nach $after_person. Beliebig oft wiederholbar: Wer
	 * das aktuelle Aufgebot hat, erhält es nicht noch einmal (NotificationLog). Wer schon ein
	 * früheres Aufgebot dieser Runde hat, erhält es als «geändertes Aufgebot».
	 *
	 * Ein Fehler bei einem Empfänger wird gemeldet und hält die übrigen nicht auf; er zählt zum
	 * Schritt, damit jeder Lauf begrenzt bleibt. Personen ohne Kanal (inaktiv, keine gültige
	 * Adresse, Benachrichtigung abgewählt) kosten keinen Versand und zählen nicht.
	 *
	 * @return array{sent:int,failed:int,next:int|null} Empfänger mit versendetem Aufgebot,
	 *         Empfänger mit gescheitertem Versand und die Person, nach der der nächste Schritt
	 *         weitermacht (null, wenn dieser Durchgang alle besucht hat).
	 */
	public function send_callups( int $round_id, int $after_person = 0, int $limit = self::CALLUP_BATCH ): array {
		$result = array(
			'sent'   => 0,
			'failed' => 0,
			'next'   => null,
		);
		$round  = $this->repo->round( $round_id );
		if ( null === $round || Rounds::PUBLISHED !== $round['status'] ) {
			return $result;
		}
		$pending = array_values( array_filter( $this->pending_callups( $round['id'] ), static fn( array $entry ): bool => $entry['person_id'] > $after_person ) );
		if ( array() === $pending ) {
			return $result;
		}
		$recipients = array();
		foreach ( $this->recipients->persons( array_column( $pending, 'person_id' ) ) as $recipient ) {
			$recipients[ (int) $recipient->person_id ] = $recipient;
		}
		$informed = $this->repo->notified( array_column( $pending, 'person_id' ), self::CALLUP, self::REF_GROUP, array_column( $this->repo->groups( $round['id'] ), 'id' ) );
		$messages = array();
		$attempts = 0;
		$last     = $after_person;
		foreach ( $pending as $entry ) {
			$person_id = $entry['person_id'];
			if ( $attempts >= $limit ) {
				$result['next'] = $last;
				break;
			}
			$last = $person_id;
			if ( ! isset( $recipients[ $person_id ] ) ) {
				continue;
			}
			$changed = in_array( $person_id, $informed, true );
			$cache   = $entry['group']['id'] . ( $changed ? ':changed' : ':first' );
			try {
				$messages[ $cache ] ??= $this->callup_message( $entry['group'], $changed );
				$report               = $this->notifier->send( $messages[ $cache ], array( $recipients[ $person_id ] ) );
			} catch ( \Throwable $e ) {
				( $this->on_error )( $e, self::CALLUP . ' p' . $person_id );
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
	 * Freigegebene Runden der aktiven Saison, in denen einer erreichbaren Person das aktuelle
	 * Aufgebot fehlt. Für sie plant der stündliche Lauf einen Schritt ein.
	 *
	 * @return list<int>
	 */
	public function rounds_with_pending_callups(): array {
		$season      = $this->repo->active_season_id();
		$competition = null === $season ? null : $this->repo->competition_of_season( $season );
		if ( null === $competition ) {
			return array();
		}
		$rounds = array();
		foreach ( $this->repo->rounds( $competition['id'] ) as $round ) {
			if ( Rounds::PUBLISHED !== $round['status'] ) {
				continue;
			}
			$pending = array_column( $this->pending_callups( $round['id'] ), 'person_id' );
			if ( array() !== $pending && array() !== $this->recipients->persons( $pending ) ) {
				$rounds[] = $round['id'];
			}
		}
		return $rounds;
	}

	/**
	 * Aufgebotene einer Runde, denen das aktuelle Aufgebot ihrer Gruppe fehlt, nach Person-ID.
	 * Abgemeldete erhalten keines, ebenso wenig Gruppen, deren Termin vorbei ist.
	 *
	 * @return list<array{person_id:int,group:array<string,mixed>}>
	 */
	private function pending_callups( int $round_id ): array {
		$now     = $this->clock->now()->setTimezone( $this->timezone )->format( 'Y-m-d H:i:s' );
		$pending = array();
		foreach ( $this->repo->groups( $round_id ) as $group ) {
			if ( null !== $group['start_at'] && $group['start_at'] < $now ) {
				continue;
			}
			$persons = array();
			foreach ( $group['members'] as $member ) {
				if ( RlPlayerStatus::Scheduled->value === $member['status'] ) {
					$persons[] = $member['person_id'];
				}
			}
			$have = $this->repo->notified( $persons, self::CALLUP, self::REF_GROUP, array( $group['id'] ), $this->callup_key( $group ) );
			foreach ( array_diff( $persons, $have ) as $person_id ) {
				$pending[] = array(
					'person_id' => $person_id,
					'group'     => $group,
				);
			}
		}
		usort( $pending, static fn( array $a, array $b ): int => array( $a['person_id'], $a['group']['id'] ) <=> array( $b['person_id'], $b['group']['id'] ) );
		return $pending;
	}

	/**
	 * Kennung des aktuellen Aufgebots einer Gruppe (notification_log.ref_key): Termin, Lokal,
	 * Platzclub, Gruppenchef, Mitglieder und die letzte Änderung der Gruppe durch die TK. Dasselbe
	 * Aufgebot geht so nie doppelt hinaus, ein geändertes immer, auch wenn die TK eine Änderung
	 * wieder zurücknimmt. Meldet sich jemand für die Runde ab, bleibt die Kennung gleich.
	 *
	 * @param array<string,mixed> $group Gruppe aus Repository::group().
	 */
	private function callup_key( array $group ): string {
		$stamp   = (string) $this->db->get_var(
			"SELECT COALESCE(MAX(id), 0) FROM {$this->db->table( 'audit_log' )} WHERE entity = 'rl_group' AND entity_id = %d AND action IN ('created', 'changed')",
			array( $group['id'] )
		);
		$content = implode( '|', array( (string) $group['start_at'], (string) $group['venue_id'], (string) $group['host_club_id'], (string) $group['chief_player_id'], implode( ',', array_column( $group['members'], 'player_id' ) ), $stamp ) );
		return substr( hash( 'sha256', $content ), 0, 16 );
	}

	/**
	 * Fällige Erinnerungen versenden. Zwischen until_hour und from_hour (Ortszeit) geht nichts hinaus.
	 *
	 * @return list<array{event:string,ref:int,deliveries:list<array{recipient:string,channel:string,status:string}>}>
	 */
	public function reminders( int $from_hour = 7, int $until_hour = 22 ): array {
		$local = $this->clock->now()->setTimezone( $this->timezone );
		$hour  = (int) $local->format( 'G' );
		if ( $hour < $from_hour || $hour >= $until_hour ) {
			return array();
		}
		$season      = $this->repo->active_season_id();
		$competition = null === $season ? null : $this->repo->competition_of_season( $season );
		if ( null === $competition ) {
			return array();
		}
		$today        = $local->format( 'Y-m-d' );
		$participants = $this->repo->participants( $competition['id'], $competition['season_id'], true );
		if ( array() === $participants ) {
			return array();
		}
		$report  = array();
		$planned = array();
		foreach ( $this->repo->rounds( $competition['id'] ) as $round ) {
			if ( Rounds::PLANNED === $round['status'] && null !== $round['date_from'] && null !== $round['date_to'] && $today < $round['date_from'] && array() === $this->repo->groups( $round['id'] ) ) {
				$planned[] = $round;
			}
		}

		// Vor jeder Einteilung: Runden, die bald beginnen und noch keine Gruppen haben.
		$before  = self::days( $competition['format']['blocked_reminder_days'] ?? null, self::BLOCKED_REMINDER_DAYS );
		$handled = array();
		foreach ( $planned as $round ) {
			if ( $before > 0 && $today >= self::minus_days( $round['date_from'], $before ) ) {
				$handled[ $round['id'] ] = true;
				$entry                   = $this->remind( $competition, $round, $participants, 'blocked' );
				if ( null !== $entry ) {
					$report[] = $entry;
				}
			}
		}

		// Vor dem Anmeldeschluss: Sperrdaten für die nächste Runde in Planung.
		$days     = self::days( $competition['format']['reminder_days'] ?? null, self::REMINDER_DAYS );
		$deadline = $competition['deadline'];
		$next     = $planned[0] ?? null;
		if ( null !== $deadline && null !== $next && $days > 0 && ! isset( $handled[ $next['id'] ] ) && $today <= $deadline && $today >= self::minus_days( $deadline, $days ) ) {
			$entry = $this->remind( $competition, $next, $participants, 'deadline' );
			if ( null !== $entry ) {
				$report[] = $entry;
			}
		}
		return $report;
	}

	/**
	 * Erinnerung an Angemeldete ohne gemeldete Sperrdaten für die Runde.
	 *
	 * @param array<string,mixed>       $competition  Konkurrenz.
	 * @param array<string,mixed>       $round        Runde.
	 * @param list<array<string,mixed>> $participants Angemeldete.
	 * @param string                    $occasion     «deadline» oder «blocked».
	 * @return array{event:string,ref:int,deliveries:list<array{recipient:string,channel:string,status:string}>}|null
	 */
	private function remind( array $competition, array $round, array $participants, string $occasion ): ?array {
		$persons  = array_column( $participants, 'person_id' );
		$answered = $this->repo->answered( $persons, (string) $round['date_from'], (string) $round['date_to'] );
		$missing  = array_values( array_diff( $persons, $answered ) );
		if ( array() === $missing ) {
			return null;
		}
		$label = self::round_label( $round );
		$week  = Format::date_range( $round['date_from'], $round['date_to'] );
		if ( 'deadline' === $occasion ) {
			$deadline = Format::date_plain( $competition['deadline'] );
			/* translators: %s: Datum des Anmeldeschlusses */
			$subject = sprintf( __( 'Ranglistenturnier: Sperrdaten bis zum Anmeldeschluss am %s', 'ttleague' ), $deadline );
			/* translators: 1: Runde, 2: Woche, 3: Datum des Anmeldeschlusses */
			$first = sprintf( __( 'Du bist zum Ranglistenturnier angemeldet. Für die %1$s (%2$s) fehlen deine Sperrdaten. Anmeldeschluss ist am %3$s.', 'ttleague' ), $label, $week, $deadline );
		} else {
			/* translators: %s: Runde */
			$subject = sprintf( __( 'Ranglistenturnier: Sperrdaten für die %s fehlen', 'ttleague' ), $label );
			/* translators: 1: Runde, 2: Woche */
			$first = sprintf( __( 'Die TK teilt die %1$s des Ranglistenturniers (%2$s) bald ein. Deine Sperrdaten fehlen noch.', 'ttleague' ), $label, $week );
		}
		$notification = new Notification(
			EventTypes::RL_REMINDER,
			self::REMINDER,
			'rl_round',
			(int) $round['id'],
			$occasion,
			$subject,
			array(
				$first,
				__( 'Trage in «Mein Bereich» ein, an welchen Abenden du nicht spielen kannst. Kannst du an jedem Abend, bestätige das dort; dann erinnern wir dich nicht mehr.', 'ttleague' ),
			),
			array(),
			array(
				'url'   => $this->links->my_area(),
				'label' => __( 'Sperrdaten melden', 'ttleague' ),
			),
			$this->links->my_area(),
			array(
				'title' => $subject,
				/* translators: 1: Runde, 2: Woche */
				'body'  => sprintf( __( 'Bitte melde deine Sperrdaten für die %1$s (%2$s).', 'ttleague' ), $label, $week ),
				'url'   => $this->links->my_area(),
				'tag'   => 'ttleague-rl-round-' . $round['id'],
			)
		);
		return array(
			'event'      => $notification->event,
			'ref'        => $notification->ref_id,
			'deliveries' => $this->notifier->send( $notification, $this->recipients->persons( $missing ) ),
		);
	}

	/**
	 * Text des Aufgebots: Lokal, Adresse, Abend, Zeit, Mitspielende, Gruppenchef.
	 *
	 * @param array<string,mixed> $group Gruppe aus Repository::group().
	 */
	private function callup_message( array $group, bool $changed ): Notification {
		/* translators: 1: Runde, 2: Kategorie, 3: Gruppe */
		$title = sprintf( __( '%1$s, Kategorie %2$d, Gruppe %3$d', 'ttleague' ), self::round_label( $group ), $group['category'], $group['group_no'] );
		$when  = Format::datetime( $group['start_at'] );
		$where = array();
		if ( null !== $group['venue'] ) {
			$where[] = $group['venue']['name'];
			$address = Format::address( $group['venue']['street'], $group['venue']['zip'], $group['venue']['city'] );
			if ( '' !== $address ) {
				$where[] = $address;
			}
		}
		$place   = implode( ', ', $where );
		$chief   = null;
		$members = array();
		foreach ( $group['members'] as $member ) {
			if ( $member['player_id'] === $group['chief_player_id'] ) {
				$chief = $member['name'];
			}
			if ( RlPlayerStatus::Absent->value === $member['status'] ) {
				continue;
			}
			$members[] = $member['name'] . ( null === $member['club_short'] ? '' : ' (' . $member['club_short'] . ')' );
		}

		/* translators: %s: Runde, Kategorie und Gruppe */
		$subject = sprintf( $changed ? __( 'Geändertes Aufgebot Ranglistenturnier: %s', 'ttleague' ) : __( 'Aufgebot Ranglistenturnier: %s', 'ttleague' ), $title );
		/* translators: %s: Runde, Kategorie und Gruppe */
		$paragraphs = array( sprintf( $changed ? __( 'Dein Aufgebot für das Ranglistenturnier hat sich geändert. Du spielst in: %s.', 'ttleague' ) : __( 'Du bist für das Ranglistenturnier aufgeboten: %s.', 'ttleague' ), $title ) );
		/* translators: %s: Datum und Zeit */
		$paragraphs[] = sprintf( __( 'Wann: %s', 'ttleague' ), '' === $when ? __( 'Termin folgt', 'ttleague' ) : $when );
		if ( '' !== $place || null !== $group['host'] ) {
			/* translators: 1: Lokal mit Adresse, 2: Platzclub */
			$paragraphs[] = null === $group['host'] ? sprintf( __( 'Wo: %s', 'ttleague' ), $place ) : trim( sprintf( __( 'Wo: %1$s (Platzclub %2$s)', 'ttleague' ), $place, $group['host']['short'] ) );
		}
		/* translators: %s: Namen */
		$paragraphs[] = sprintf( __( 'Mitspielende: %s', 'ttleague' ), implode( ', ', $members ) );
		if ( null !== $chief ) {
			/* translators: %s: Name */
			$paragraphs[] = sprintf( __( 'Gruppenchef: %s. Der Gruppenchef trägt den Gruppenzettel nach dem Spielabend in «Mein Bereich» nach.', 'ttleague' ), $chief );
		}
		$paragraphs[] = __( 'Kannst du nicht spielen, melde dich in «Mein Bereich» für diese Runde ab.', 'ttleague' );

		return new Notification(
			EventTypes::RL_CALLUP,
			self::CALLUP,
			self::REF_GROUP,
			(int) $group['id'],
			$this->callup_key( $group ),
			$subject,
			$paragraphs,
			array(),
			array(
				'url'   => $this->links->my_area(),
				'label' => __( 'Zu meinem Bereich', 'ttleague' ),
			),
			$this->links->my_area(),
			array(
				'title' => $subject,
				'body'  => trim( $when . ( '' === $place ? '' : ', ' . $place ), ', ' ),
				'url'   => $this->links->my_area(),
				'tag'   => 'ttleague-rl-group-' . $group['id'],
			)
		);
	}

	/**
	 * «1. Runde» oder «Finalrunde».
	 *
	 * @param array{round_no:int,is_final:bool} $round Runde oder Gruppe mit Rundendaten.
	 */
	public static function round_label( array $round ): string {
		/* translators: %d: Nummer der Runde */
		return $round['is_final'] ? __( 'Finalrunde', 'ttleague' ) : sprintf( __( '%d. Runde', 'ttleague' ), $round['round_no'] );
	}

	/**
	 * Ganze Tage ab 0 aus format_json, sonst der Standard. 0 schaltet die Erinnerung aus.
	 */
	private static function days( mixed $value, int $fallback ): int {
		return is_numeric( $value ) && (int) $value >= 0 ? (int) $value : $fallback;
	}

	private static function minus_days( string $date, int $days ): string {
		return ( new \DateTimeImmutable( $date ) )->modify( '-' . $days . ' days' )->format( 'Y-m-d' );
	}
}
