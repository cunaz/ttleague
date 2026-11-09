<?php
/**
 * Versandkanal Web Push.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Notifications\Push;

use TTLeague\Notifications\Channel;
use TTLeague\Notifications\Notification;
use TTLeague\Notifications\Recipient;

/**
 * Sendet an alle Abos der Person. Abos, die der Push-Dienst mit 404 oder 410 ablehnt, werden
 * gelöscht. Zugestellt ist die Nachricht, wenn mindestens ein Abo sie angenommen hat; sonst
 * meldet der Kanal einen Fehler (bei anderen Antworten mit dem Status), und der Notifier
 * fällt auf E-Mail zurück.
 */
final class PushChannel implements Channel {

	public function __construct(
		private readonly Subscriptions $subscriptions,
		private readonly WebPush $push,
		private readonly string $icon = '',
	) {}

	public function name(): string {
		return self::PUSH;
	}

	public function reaches( Recipient $recipient ): bool {
		return null !== $recipient->person_id && $this->subscriptions->count( $recipient->person_id ) > 0;
	}

	public function deliver( Recipient $recipient, Notification $notification ): bool {
		if ( null === $recipient->person_id ) {
			return false;
		}
		$message   = $notification->push + array( 'icon' => $this->icon );
		$delivered = false;
		$failures  = array();
		foreach ( $this->subscriptions->for_person( $recipient->person_id ) as $subscription ) {
			$status = $this->push->send( $subscription, $message, $notification->ttl, $notification->urgency );
			if ( $status >= 200 && $status < 300 ) {
				$delivered = true;
			} elseif ( 404 === $status || 410 === $status ) {
				$this->subscriptions->delete( $subscription->id );
			} else {
				$failures[] = $status;
			}
		}
		if ( ! $delivered && array() !== $failures ) {
			throw new \RuntimeException( 'Push-Dienst antwortete mit Status ' . (int) $failures[0] . ' (' . count( $failures ) . ' Abo).' );
		}
		return $delivered;
	}
}
