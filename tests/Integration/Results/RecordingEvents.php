<?php
/**
 * Testdouble für die Ereignisse der Erfassung.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Tests\Integration\Results;

use TTLeague\Results\ResultEvents;

/**
 * Zeichnet die gemeldeten Ereignisse auf.
 */
final class RecordingEvents implements ResultEvents {

	/** @var list<list<mixed>> */
	public array $calls = array();

	public function entered( int $fixture_id, int $user_id, bool $edited ): void {
		$this->calls[] = array( 'entered', $fixture_id, $user_id, $edited );
	}

	public function confirmed( int $fixture_id, int $user_id, bool $by_tk ): void {
		$this->calls[] = array( 'confirmed', $fixture_id, $user_id, $by_tk );
	}

	public function disputed( int $fixture_id, int $user_id, string $reason ): void {
		$this->calls[] = array( 'disputed', $fixture_id, $user_id, $reason );
	}

	public function decided( int $fixture_id, int $user_id, string $action, ?string $comment ): void {
		$this->calls[] = array( 'decided', $fixture_id, $user_id, $action, $comment );
	}

	public function photo_added( int $fixture_id, int $user_id, int $attachment_id ): void {
		$this->calls[] = array( 'photo', $fixture_id, $user_id, $attachment_id );
	}
}
