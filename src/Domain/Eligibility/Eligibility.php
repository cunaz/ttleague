<?php
/**
 * Ergebnis einer Spielberechtigungsprüfung.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Domain\Eligibility;

final class Eligibility {

	/**
	 * @param bool              $allowed              Einsatz erlaubt.
	 * @param EligibilityReason $reason               Begründung.
	 * @param int               $substitute_appearances Bisherige Ersatzeinsätze im geprüften Team.
	 * @param int|null          $remaining            Verbleibende Ersatzeinsätze vor der Bindung (null wenn nicht anwendbar).
	 * @param string            $message              Erklärung für die Oberfläche.
	 */
	public function __construct(
		public readonly bool $allowed,
		public readonly EligibilityReason $reason,
		public readonly int $substitute_appearances = 0,
		public readonly ?int $remaining = null,
		public readonly string $message = '',
	) {}
}
