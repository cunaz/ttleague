<?php
/**
 * Adressen in den Benachrichtigungen des Ranglistenturniers (in Tests ersetzbar).
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\RankingTournament;

interface Links {

	/**
	 * «Mein Bereich»: Anmeldung, Sperrdaten, Aufgebot, Einstellungen.
	 */
	public function my_area(): string;
}
