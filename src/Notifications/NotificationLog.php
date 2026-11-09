<?php
/**
 * Protokoll der versendeten Benachrichtigungen (notification_log) für «genau einmal».
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Notifications;

use TTLeague\Infrastructure\Database\Db;
use TTLeague\Infrastructure\Time\Clock;

/**
 * Eine Nachricht geht pro Empfänger (Person, sonst Konto), Art, Bezug, Zusatz und Kanal genau
 * einmal hinaus. Vor dem Versand wird der Eintrag reserviert (INSERT IGNORE auf den
 * eindeutigen Schlüssel); scheitert der Versand, wird er wieder freigegeben. So versenden
 * auch zwei gleichzeitige Cron-Läufe nichts doppelt.
 */
final class NotificationLog {

	/** Einträge werden nach so vielen Tagen gelöscht. */
	public const RETENTION_DAYS = 365;

	public function __construct( private readonly Db $db, private readonly Clock $clock ) {}

	/**
	 * Eintrag reservieren; false, wenn die Nachricht schon versendet wurde.
	 */
	public function claim( Recipient $recipient, Notification $notification, string $channel ): bool {
		[ $person, $user ] = self::identity( $recipient );
		return 1 === $this->db->query(
			"INSERT IGNORE INTO {$this->db->table( 'notification_log' )} (person_id, user_id, event_type, ref_type, ref_id, ref_key, channel, sent_at)
			VALUES (%d, %d, %s, %s, %d, %s, %s, %s)",
			array( $person, $user, $notification->event, $notification->ref_type, $notification->ref_id, $notification->ref_key, $channel, $this->clock->now()->format( 'Y-m-d H:i:s' ) )
		);
	}

	/**
	 * Reservierung nach gescheitertem Versand freigeben.
	 */
	public function release( Recipient $recipient, Notification $notification, string $channel ): void {
		[ $person, $user ] = self::identity( $recipient );
		$this->db->query(
			"DELETE FROM {$this->db->table( 'notification_log' )}
			WHERE person_id = %d AND user_id = %d AND event_type = %s AND ref_type = %s AND ref_id = %d AND ref_key = %s AND channel = %s",
			array( $person, $user, $notification->event, $notification->ref_type, $notification->ref_id, $notification->ref_key, $channel )
		);
	}

	public function was_sent( Recipient $recipient, Notification $notification, string $channel ): bool {
		[ $person, $user ] = self::identity( $recipient );
		return null !== $this->db->get_var(
			"SELECT id FROM {$this->db->table( 'notification_log' )}
			WHERE person_id = %d AND user_id = %d AND event_type = %s AND ref_type = %s AND ref_id = %d AND ref_key = %s AND channel = %s",
			array( $person, $user, $notification->event, $notification->ref_type, $notification->ref_id, $notification->ref_key, $channel )
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
