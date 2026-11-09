<?php
/**
 * Versandkanal einer Benachrichtigung (E-Mail, Push).
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Notifications;

interface Channel {

	public const EMAIL = 'email';
	public const PUSH  = 'push';

	/**
	 * Name des Kanals wie in notification_pref und notification_log (email, push).
	 */
	public function name(): string;

	/**
	 * Lässt sich die Person über diesen Kanal überhaupt erreichen (Adresse, Abo)?
	 */
	public function reaches( Recipient $recipient ): bool;

	/**
	 * Versenden; true, wenn die Nachricht angenommen wurde.
	 */
	public function deliver( Recipient $recipient, Notification $notification ): bool;
}
