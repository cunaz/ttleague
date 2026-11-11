<?php
/**
 * Ereignisse der Meldung im Test: merkt sich Änderungen und Entscheide.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Tests\Integration\Registration;

use TTLeague\Registration\Decision;
use TTLeague\Registration\RegistrationEvents;

final class RecordingEvents implements RegistrationEvents {

	/** @var list<array{0:int|null,1:int}> Saison und Club. */
	public array $changed = array();

	/** @var list<Decision> */
	public array $decisions = array();

	public function changed( ?int $season_id, int $club_id ): void {
		$this->changed[] = array( $season_id, $club_id );
	}

	public function decided( Decision $decision ): void {
		$this->decisions[] = $decision;
	}
}
