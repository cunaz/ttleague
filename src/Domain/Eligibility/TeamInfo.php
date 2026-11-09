<?php
/**
 * Stammdaten eines Teams für die Spielberechtigungsprüfung.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Domain\Eligibility;

final class TeamInfo {

	/**
	 * @param int|string $id       Team-ID.
	 * @param int|string $club_id  Club.
	 * @param int        $number   Mannschaftsnummer (1 = stärkste).
	 * @param string     $category Kategorie A, B, C, D.
	 */
	public function __construct(
		public readonly int|string $id,
		public readonly int|string $club_id,
		public readonly int $number,
		public readonly string $category,
	) {}

	/**
	 * Ist dieses Team «tiefer klassiert» als das andere (tiefere Kategorie oder höhere Nummer)?
	 */
	public function is_lower_than( TeamInfo $other ): bool {
		$cmp = strcmp( strtoupper( $this->category ), strtoupper( $other->category ) );
		if ( 0 !== $cmp ) {
			return $cmp > 0; // «D» > «A» bedeutet tiefer.
		}
		return $this->number > $other->number;
	}
}
