<?php
/**
 * Fristen in Stunden als verstrichene Zeit, auch über Zeitumstellungen.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Infrastructure\Time;

/**
 * DateTimeImmutable::modify( '+24 hours' ) rechnet in einer Zeitzone mit Sommerzeit auf der
 * Wanduhr: Über die Umstellung im März vergehen so nur 23 Stunden, im Oktober 25. Fristen in
 * Stunden sind echte Stunden. Deshalb rechnet after() in UTC und gibt das Ergebnis in der
 * Zeitzone des Startzeitpunkts zurück. Fristen in Tagen bleiben Kalendertage in Ortszeit.
 */
final class Hours {

	/**
	 * Zeitpunkt so viele verstrichene Stunden nach $start.
	 */
	public static function after( \DateTimeImmutable $start, int $hours ): \DateTimeImmutable {
		return $start->setTimezone( new \DateTimeZone( 'UTC' ) )->modify( sprintf( '%+d hours', $hours ) )->setTimezone( $start->getTimezone() );
	}
}
