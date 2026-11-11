<?php
/**
 * Adressen in den Benachrichtigungen zu Anlässen (in Tests ohne WordPress ersetzbar).
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Events;

interface Links {

	/**
	 * Seite mit der Ausschreibung und der Anmeldung, oder null ohne veröffentlichte Ausschreibung.
	 *
	 * @param array<string,mixed> $event Anlass.
	 */
	public function event( array $event ): ?string;

	/**
	 * «Mein Bereich» mit den Einstellungen.
	 */
	public function my_area(): string;
}
