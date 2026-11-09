<?php
/**
 * Protokoll der versendeten Benachrichtigungen (notification_log) gegen doppelten Versand.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Notifications;

use TTLeague\Infrastructure\Database\Db;
use TTLeague\Infrastructure\Time\Clock;

/**
 * Eine Nachricht geht pro Empfänger (Person, sonst Konto), Art, Bezug, Zusatz und Kanal
 * höchstens einmal pro Reservierung hinaus. Vor dem Versand wird der Eintrag reserviert
 * (INSERT IGNORE auf den eindeutigen Schlüssel, Status pending), nach dem Versand bestätigt
 * (sent) und nach einem Fehlschlag wieder freigegeben. Zwei gleichzeitige Cron-Läufe
 * versenden so nichts doppelt.
 *
 * Endet der Prozess zwischen Reservierung und Bestätigung (Zeitlimit, schwerer Fehler), bleibt
 * die Reservierung offen. Nach LEASE_MINUTES darf genau ein späterer Lauf sie übernehmen.
 * Lieber doppelt als gar nicht: Brach der Prozess erst nach dem Versand ab oder dauerte ein
 * Versuch länger als LEASE_MINUTES, geht die Nachricht ein zweites Mal hinaus. Den zweiten Fall
 * meldet Notifier, weil confirm() dann false liefert.
 */
final class NotificationLog {

	/** Einträge werden nach so vielen Tagen gelöscht. */
	public const RETENTION_DAYS = 365;

	/**
	 * Eine offene Reservierung gilt nach so vielen Minuten als verwaist. Ein Versand dauert
	 * normalerweise Sekunden (Push: Zeitlimit 10 Sekunden; E-Mail über SMTP: Zeitlimit von
	 * PHPMailer 5 Minuten je Verbindungsschritt), ein laufender Versuch wird also in aller Regel
	 * nicht doppelt ausgeführt. Ein späterer Lauf holt eine noch fällige Fristmeldung nach.
	 */
	public const LEASE_MINUTES = 15;

	public const PENDING = 'pending';
	public const SENT    = 'sent';

	public function __construct( private readonly Db $db, private readonly Clock $clock ) {}

	/**
	 * Eintrag reservieren. Liefert das Kennzeichen der Reservierung für confirm() und release(),
	 * oder null, wenn die Nachricht schon versendet ist oder gerade versendet wird. Eine offene
	 * Reservierung, die älter als LEASE_MINUTES ist, wird übernommen: ein einziges UPDATE mit
	 * Bedingung, gleichzeitige Läufe übernehmen sie also höchstens einmal.
	 */
	public function claim( Recipient $recipient, Notification $notification, string $channel ): ?string {
		[ $person, $user ] = self::identity( $recipient );
		$now               = $this->clock->now();
		$token             = $now->format( 'Y-m-d H:i:s' );
		$inserted          = $this->db->query(
			"INSERT IGNORE INTO {$this->db->table( 'notification_log' )} (person_id, user_id, event_type, ref_type, ref_id, ref_key, channel, status, sent_at)
			VALUES (%d, %d, %s, %s, %d, %s, %s, %s, %s)",
			array( $person, $user, $notification->event, $notification->ref_type, $notification->ref_id, $notification->ref_key, $channel, self::PENDING, $token )
		);
		if ( 1 === $inserted ) {
			return $token;
		}
		$taken = $this->db->query(
			"UPDATE {$this->db->table( 'notification_log' )} SET sent_at = %s
			WHERE person_id = %d AND user_id = %d AND event_type = %s AND ref_type = %s AND ref_id = %d AND ref_key = %s AND channel = %s
			AND status = %s AND sent_at <= %s",
			array( $token, $person, $user, $notification->event, $notification->ref_type, $notification->ref_id, $notification->ref_key, $channel, self::PENDING, $now->modify( '-' . self::LEASE_MINUTES . ' minutes' )->format( 'Y-m-d H:i:s' ) )
		);
		return 1 === $taken ? $token : null;
	}

	/**
	 * Versand bestätigen. Nur die eigene Reservierung: Hat ein späterer Lauf sie übernommen,
	 * bleibt sie dessen, und das Ergebnis ist false.
	 */
	public function confirm( Recipient $recipient, Notification $notification, string $channel, string $token ): bool {
		[ $person, $user ] = self::identity( $recipient );
		return 1 === $this->db->query(
			"UPDATE {$this->db->table( 'notification_log' )} SET status = %s, sent_at = %s
			WHERE person_id = %d AND user_id = %d AND event_type = %s AND ref_type = %s AND ref_id = %d AND ref_key = %s AND channel = %s
			AND status = %s AND sent_at = %s",
			array( self::SENT, $this->clock->now()->format( 'Y-m-d H:i:s' ), $person, $user, $notification->event, $notification->ref_type, $notification->ref_id, $notification->ref_key, $channel, self::PENDING, $token )
		);
	}

	/**
	 * Reservierung nach gescheitertem Versand freigeben, nur die eigene (siehe confirm()).
	 */
	public function release( Recipient $recipient, Notification $notification, string $channel, string $token ): bool {
		[ $person, $user ] = self::identity( $recipient );
		return 1 === $this->db->query(
			"DELETE FROM {$this->db->table( 'notification_log' )}
			WHERE person_id = %d AND user_id = %d AND event_type = %s AND ref_type = %s AND ref_id = %d AND ref_key = %s AND channel = %s
			AND status = %s AND sent_at = %s",
			array( $person, $user, $notification->event, $notification->ref_type, $notification->ref_id, $notification->ref_key, $channel, self::PENDING, $token )
		);
	}

	/**
	 * Ist die Nachricht bestätigt versendet? Offene Reservierungen zählen nicht.
	 */
	public function was_sent( Recipient $recipient, Notification $notification, string $channel ): bool {
		[ $person, $user ] = self::identity( $recipient );
		return null !== $this->db->get_var(
			"SELECT id FROM {$this->db->table( 'notification_log' )}
			WHERE person_id = %d AND user_id = %d AND event_type = %s AND ref_type = %s AND ref_id = %d AND ref_key = %s AND channel = %s AND status = %s",
			array( $person, $user, $notification->event, $notification->ref_type, $notification->ref_id, $notification->ref_key, $channel, self::SENT )
		);
	}

	/**
	 * Alte Einträge löschen; liefert die Anzahl.
	 */
	public function purge( int $days = self::RETENTION_DAYS ): int {
		return $this->db->query(
			"DELETE FROM {$this->db->table( 'notification_log' )} WHERE sent_at < %s",
			array( $this->clock->now()->modify( '-' . $days . ' days' )->format( 'Y-m-d H:i:s' ) )
		);
	}

	/**
	 * Person, sonst Konto (das andere Feld ist 0).
	 *
	 * @return array{0:int,1:int}
	 */
	private static function identity( Recipient $recipient ): array {
		return null !== $recipient->person_id ? array( $recipient->person_id, 0 ) : array( 0, (int) $recipient->user_id );
	}
}
