<?php
/**
 * Entwurf eines Spielplans mit dem Bericht seiner Prüfung.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Domain\Schedule;

final class ScheduleDraft {

	/**
	 * @param list<PlannedFixture> $fixtures Alle Begegnungen, nach Gruppe und Runde.
	 * @param ScheduleReport       $report   Kollisionen und Hinweise zu genau diesen Begegnungen.
	 */
	public function __construct(
		public readonly array $fixtures,
		public readonly ScheduleReport $report,
	) {}
}
