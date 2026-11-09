<?php
/**
 * Uhr als Abhängigkeit, damit Fristen und Ablaufzeiten testbar sind.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Infrastructure\Time;

interface Clock {

	/**
	 * Aktueller Zeitpunkt in UTC.
	 */
	public function now(): \DateTimeImmutable;
}
