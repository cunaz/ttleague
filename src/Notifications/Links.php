<?php
/**
 * Adressen in Benachrichtigungen (in Tests ohne WordPress ersetzbar).
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Notifications;

interface Links {

	/**
	 * Erfassungsseite einer Begegnung (Results::entry_url).
	 */
	public function entry( int $fixture_id ): string;

	/**
	 * «Mein Bereich» mit den Einstellungen.
	 */
	public function my_area(): string;
}
