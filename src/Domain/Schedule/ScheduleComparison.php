<?php
/**
 * Ergebnis des Abgleichs zweier Spielpläne.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Domain\Schedule;

final class ScheduleComparison {

	/**
	 * @param int                  $matched           Begegnungen, die in beiden Plänen vorkommen.
	 * @param int                  $same_date         Davon am selben Kalendertag.
	 * @param int                  $same_week         Davon in derselben Woche (Montag bis Sonntag).
	 * @param int                  $same_weekday      Davon am selben Wochentag.
	 * @param list<PlannedFixture> $only_in_plan      Begegnungen, die nur im Plan stehen.
	 * @param list<PlannedFixture> $only_in_reference Begegnungen, die nur im Vergleichsplan stehen.
	 */
	public function __construct(
		public readonly int $matched,
		public readonly int $same_date,
		public readonly int $same_week,
		public readonly int $same_weekday,
		public readonly array $only_in_plan = array(),
		public readonly array $only_in_reference = array(),
	) {}

	/**
	 * Anteil der zugeordneten Begegnungen am selben Kalendertag (0 bis 1), null ohne Zuordnung.
	 */
	public function same_date_share(): ?float {
		return $this->share( $this->same_date );
	}

	/**
	 * Anteil der zugeordneten Begegnungen in derselben Woche.
	 */
	public function same_week_share(): ?float {
		return $this->share( $this->same_week );
	}

	/**
	 * Anteil der zugeordneten Begegnungen am selben Wochentag.
	 */
	public function same_weekday_share(): ?float {
		return $this->share( $this->same_weekday );
	}

	private function share( int $count ): ?float {
		return 0 === $this->matched ? null : $count / $this->matched;
	}
}
