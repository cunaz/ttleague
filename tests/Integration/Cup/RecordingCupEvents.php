<?php
/**
 * Testdouble für die Ereignisse des Mannschaftscups.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Tests\Integration\Cup;

use TTLeague\Cup\CupEvents;

/**
 * Zeichnet die gemeldeten Ereignisse auf und reicht sie auf Wunsch weiter (Benachrichtigungen).
 */
final class RecordingCupEvents implements CupEvents {

	/** @var list<array{0:int,1:string,2:int,3:list<int>}> */
	public array $calls = array();

	public ?CupEvents $forward = null;

	public function changed( int $competition_id, string $action, int $user_id, array $tie_ids = array() ): void {
		$this->calls[] = array( $competition_id, $action, $user_id, $tie_ids );
		$this->forward?->changed( $competition_id, $action, $user_id, $tie_ids );
	}

	/**
	 * Arten der gemeldeten Ereignisse in ihrer Reihenfolge.
	 *
	 * @return list<string>
	 */
	public function actions(): array {
		return array_column( $this->calls, 1 );
	}
}
