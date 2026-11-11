<?php
/**
 * Testdouble für die Ereignisse der Verschiebung.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Tests\Integration\Postponement;

use TTLeague\Postponement\PostponementEvents;

/**
 * Zeichnet die gemeldeten Ereignisse auf.
 */
final class RecordingPostponementEvents implements PostponementEvents {

	/** @var list<array{0:int,1:string,2:int,3:int|null}> */
	public array $calls = array();

	public function changed( int $postponement_id, string $action, int $user_id, ?int $person_id ): void {
		$this->calls[] = array( $postponement_id, $action, $user_id, $person_id );
	}
}
