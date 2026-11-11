<?php
/**
 * Ausweichvorschlag für eine Begegnung mit Kollision.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Domain\Schedule;

final class ScheduleSuggestion {

	/**
	 * @param PlannedFixture     $fixture   Betroffene Begegnung am bisherigen Termin.
	 * @param \DateTimeImmutable $starts_at Vorgeschlagener Termin: Heimspieltag des Heimteams zur Anspielzeit.
	 */
	public function __construct(
		public readonly PlannedFixture $fixture,
		public readonly \DateTimeImmutable $starts_at,
	) {}

	/**
	 * Die Begegnung am vorgeschlagenen Termin. Der Plan selbst bleibt unverändert; wer den
	 * Vorschlag übernimmt, ersetzt die Begegnung und prüft neu.
	 */
	public function moved(): PlannedFixture {
		return $this->fixture->moved_to( $this->starts_at );
	}
}
