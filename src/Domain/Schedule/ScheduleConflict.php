<?php
/**
 * Kollision oder Hinweis im Spielplan mit den betroffenen Begegnungen.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Domain\Schedule;

final class ScheduleConflict {

	/**
	 * Die Meldung nennt keine IDs; Lokal, Club und Team stehen in den Feldern, damit die
	 * Oberfläche Namen einsetzen kann.
	 *
	 * @param ConflictKind             $kind        Art der Kollision.
	 * @param string                   $message     Meldung für die TK.
	 * @param list<PlannedFixture>     $fixtures    Betroffene Begegnungen in der Reihenfolge des Plans.
	 * @param list<ScheduleSuggestion> $suggestions Ausweichvorschläge, höchstens einer pro betroffene Begegnung.
	 * @param string|null              $day         Betroffener Tag (Y-m-d), null wenn die Kollision keinen Tag hat.
	 * @param int|string|null          $venue_id    Betroffenes Lokal.
	 * @param int|string|null          $club_id     Betroffener Club.
	 * @param int|string|null          $team_id     Betroffenes Team.
	 */
	public function __construct(
		public readonly ConflictKind $kind,
		public readonly string $message,
		public readonly array $fixtures,
		public readonly array $suggestions = array(),
		public readonly ?string $day = null,
		public readonly int|string|null $venue_id = null,
		public readonly int|string|null $club_id = null,
		public readonly int|string|null $team_id = null,
	) {}

	public function is_hard(): bool {
		return $this->kind->is_hard();
	}

	/**
	 * Ausweichvorschlag für eine der betroffenen Begegnungen, null wenn es keinen gibt.
	 */
	public function suggestion_for( PlannedFixture $fixture ): ?ScheduleSuggestion {
		foreach ( $this->suggestions as $suggestion ) {
			if ( $suggestion->fixture === $fixture ) {
				return $suggestion;
			}
		}
		return null;
	}
}
