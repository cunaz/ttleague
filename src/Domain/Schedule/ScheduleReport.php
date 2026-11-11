<?php
/**
 * Bericht der Spielplanprüfung.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Domain\Schedule;

final class ScheduleReport {

	/**
	 * @param list<ScheduleConflict> $conflicts Kollisionen und Hinweise: nach Tag, innerhalb eines Tages nach Art; Hinweise ohne Tag am Schluss.
	 */
	public function __construct( public readonly array $conflicts ) {}

	/**
	 * Harte Kollisionen: Der Plan ist so nicht spielbar.
	 *
	 * @return list<ScheduleConflict>
	 */
	public function hard(): array {
		return array_values( array_filter( $this->conflicts, static fn( ScheduleConflict $conflict ) => $conflict->is_hard() ) );
	}

	/**
	 * Hinweise: Die TK entscheidet, ob sie etwas ändert.
	 *
	 * @return list<ScheduleConflict>
	 */
	public function hints(): array {
		return array_values( array_filter( $this->conflicts, static fn( ScheduleConflict $conflict ) => ! $conflict->is_hard() ) );
	}

	public function has_hard_conflicts(): bool {
		return array() !== $this->hard();
	}

	/**
	 * @return list<ScheduleConflict>
	 */
	public function of_kind( ConflictKind $kind ): array {
		return array_values( array_filter( $this->conflicts, static fn( ScheduleConflict $conflict ) => $kind === $conflict->kind ) );
	}
}
