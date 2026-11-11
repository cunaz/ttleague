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
 * - rl_callup: Aufgebot an die Aufgebotenen einer Gruppe (nicht an Abgemeldete) beim Freigeben
 *   der Runde und nach jeder Änderung der Gruppe. Bezug ist die Gruppe, der Zusatz eine Kennung
 *   aus Inhalt und letzter Änderung: Dasselbe Aufgebot geht nie doppelt hinaus, ein geändertes
 *   immer.
 * - rl_reminder: Angemeldete ohne gemeldete Sperrdaten für die nächste Runde in Planung, in den
 *   Tagen vor dem Anmeldeschluss (format_json reminder_days, Standard 3) und vor jeder Runde, die
 *   noch nicht eingeteilt ist (blocked_reminder_days vor Beginn der Rundenwoche, Standard 10).
 *   Pro Person, Runde und Anlass einmal. Läuft im stündlichen Fristenlauf, ausserhalb der Ruhezeit.
 */
final class Notices {

	public const CALLUP   = 'rl_callup';
	public const REMINDER = 'rl_reminder';

	/** Tage vor dem Anmeldeschluss, ab denen erinnert wird (format_json reminder_days). */
	public const REMINDER_DAYS = 3;

	/** Tage vor Beginn einer nicht eingeteilten Runde, ab denen erinnert wird (format_json blocked_reminder_days). */
	public const BLOCKED_REMINDER_DAYS = 10;

	private Repository $repo;

	public function __construct(
		private readonly Db $db,
		private readonly Clock $clock,
		private readonly \DateTimeZone $timezone,
		private readonly RecipientResolver $recipients,
		private readonly Notifier $notifier,
		private readonly Links $links,
	) {
		$this->repo = new Repository( $db );
	}

	/**
	 * Aufgebot an die Mitglieder der Gruppen.
	 *
	 * @param list<int> $group_ids Gruppen.
	 * @param bool      $changed   Änderung nach der Freigabe.
	 * @return list<array{event:string,ref:int,deliveries:list<array{recipient:string,channel:string,status:string}>}>
	 */
	public function callup( array $group_ids, bool $changed ): array {
		$report = array();
		foreach ( $group_ids as $group_id ) {
			$group = $this->repo->group( (int) $group_id );
			if ( null === $group || Rounds::PUBLISHED !== $group['round_status'] ) {
				continue;
			}
			$persons = array();
			foreach ( $group['members'] as $member ) {
				if ( RlPlayerStatus::Scheduled->value === $member['status'] ) {
					$persons[] = $member['person_id'];
				}
			}
			$notification = $this->callup_message( $group, $changed );
			$report[]     = array(
				'event'      => $notification->event,
				'ref'        => $notification->ref_id,
				'deliveries' => $this->notifier->send( $notification, $this->recipients->persons( $persons ) ),
			);
		}
		return $report;
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

		// Kennung aus Inhalt und letzter Änderung der Gruppe: gleiches Aufgebot nie doppelt, geändertes immer.
		$stamp   = (string) $this->db->get_var(
			"SELECT COALESCE(MAX(id), 0) FROM {$this->db->table( 'audit_log' )} WHERE entity = 'rl_group' AND entity_id = %d AND action IN ('created', 'changed')",
			array( $group['id'] )
		);
		$content = implode( '|', array( (string) $group['start_at'], (string) $group['venue_id'], (string) $group['host_club_id'], (string) $group['chief_player_id'], implode( ',', array_column( $group['members'], 'status', 'player_id' ) ), implode( ',', array_column( $group['members'], 'player_id' ) ), $stamp ) );

		return new Notification(
			EventTypes::RL_CALLUP,
			self::CALLUP,
			'rl_group',
			(int) $group['id'],
			substr( hash( 'sha256', $content ), 0, 16 ),
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
