<?php
/**
 * Ereignisse der Meldung nach dem Speichern (Cache-Version, Benachrichtigungen).
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Registration;

/**
 * Die Dienste kennen WordPress nicht. Sie melden nach jeder gespeicherten Änderung, dass sich
 * die Daten eines Clubs geändert haben, und nach einem Entscheid der TK den Entscheid. In
 * WordPress erhöht WordPressRegistrationEvents die Datenversion und benachrichtigt den TK-Chef.
 */
interface RegistrationEvents {

	/**
	 * Nach jedem gespeicherten Schreibvorgang.
	 *
	 * @param int|null $season_id Saison der Meldung; null bei Lokalen und Hallensperren.
	 */
	public function changed( ?int $season_id, int $club_id ): void;

	/**
	 * Nach einem Entscheid der TK (Meldung geprüft oder zurückgegeben, Spieler freigegeben oder abgelehnt).
	 */
	public function decided( Decision $decision ): void;
}
