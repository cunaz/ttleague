<?php
/**
 * Empfänger der fachlichen Ereignisse der Anlässe (WordPress-Aktionen, in Tests aufgezeichnet).
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Events;

/**
 * Die Dienste melden jedes Ereignis erst nach dem Speichern (nach der Transaktion).
 */
interface EventListener {

	/**
	 * Anlass angelegt, geändert, geschlossen oder wieder geöffnet.
	 *
	 * @param string $action created, updated, closed oder reopened.
	 */
	public function event_changed( int $event_id, int $user_id, string $action ): void;

	/**
	 * Anlass abgesagt.
	 */
	public function cancelled( int $event_id, int $user_id ): void;

	/**
	 * Person angemeldet.
	 *
	 * @param string $status registered oder waitlist.
	 */
	public function registered( int $event_id, int $registration_id, int $person_id, string $status, int $user_id ): void;

	/**
	 * Person abgemeldet; die Zeile der Anmeldung ist gelöscht.
	 *
	 * @param string $status Status der Anmeldung vor der Abmeldung.
	 */
	public function unregistered( int $event_id, int $registration_id, int $person_id, string $status, int $user_id ): void;

	/**
	 * Person von der Warteliste nachgerückt.
	 */
	public function promoted( int $event_id, int $registration_id, int $person_id ): void;
}
