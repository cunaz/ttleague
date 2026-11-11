<?php
/**
 * Empfänger der Ereignisse einer Verschiebung (WordPress-Aktionen, in Tests aufgezeichnet).
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Postponement;

interface PostponementEvents {

	/**
	 * Ein Antrag wurde gestellt oder hat den Status gewechselt. Kommt erst nach dem Speichern.
	 *
	 * @param int      $postponement_id Antrag.
	 * @param string   $action          request, withdraw, decline, accept, approve oder reject.
	 * @param int      $user_id         Handelndes Konto.
	 * @param int|null $person_id       Handelnde Person.
	 */
	public function changed( int $postponement_id, string $action, int $user_id, ?int $person_id ): void;
}
