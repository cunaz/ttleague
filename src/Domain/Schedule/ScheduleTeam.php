<?php
/**
 * Team als Eingabe für den Spielplan: Club, Heimlokal, Heimspieltag und Anspielzeit.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Domain\Schedule;

final class ScheduleTeam {

	/**
	 * @param int|string $id           Team-ID, eindeutig über alle Gruppen.
	 * @param int|string $club_id      Club-ID.
	 * @param int|string $venue_id     Heimlokal.
	 * @param int        $home_weekday Heimspieltag (1 = Montag … 7 = Sonntag).
	 * @param string     $start_time   Anspielzeit «HH:MM».
	 */
	public function __construct(
		public readonly int|string $id,
		public readonly int|string $club_id,
		public readonly int|string $venue_id,
		public readonly int $home_weekday,
		public readonly string $start_time,
	) {
		if ( $home_weekday < 1 || $home_weekday > 7 ) {
			throw new \InvalidArgumentException( 'Heimspieltag muss zwischen 1 und 7 liegen.' );
		}
		if ( 1 !== preg_match( '/^(?:[01]\d|2[0-3]):[0-5]\d$/D', $start_time ) ) {
			throw new \InvalidArgumentException( 'Anspielzeit muss das Format «HH:MM» haben: ' . $start_time );
		}
	}

	/**
	 * Heimtermin in einer Spielwoche: Montag plus Heimspieltag, zur Anspielzeit.
	 *
	 * @param \DateTimeImmutable $monday Montag der Spielwoche.
	 */
	public function home_date( \DateTimeImmutable $monday ): \DateTimeImmutable {
		return $this->at_start_time( $monday->modify( '+' . ( $this->home_weekday - 1 ) . ' days' ) );
	}

	/**
	 * Setzt die Anspielzeit auf einen Tag.
	 */
	public function at_start_time( \DateTimeImmutable $day ): \DateTimeImmutable {
		return $day->setTime( (int) substr( $this->start_time, 0, 2 ), (int) substr( $this->start_time, 3, 2 ) );
	}
}
