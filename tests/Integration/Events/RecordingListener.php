<?php
/**
 * Testdouble für die Ereignisse der Anlässe.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Tests\Integration\Events;

use TTLeague\Events\EventListener;

/**
 * Zeichnet die gemeldeten Ereignisse auf.
 */
final class RecordingListener implements EventListener {

	/** @var list<list<mixed>> */
	public array $calls = array();

	public function event_changed( int $event_id, int $user_id, string $action ): void {
		$this->calls[] = array( 'event_changed', $event_id, $user_id, $action );
	}

	public function cancelled( int $event_id, int $user_id ): void {
		$this->calls[] = array( 'cancelled', $event_id, $user_id );
	}

	public function registered( int $event_id, int $registration_id, int $person_id, string $status, int $user_id ): void {
		$this->calls[] = array( 'registered', $event_id, $registration_id, $person_id, $status, $user_id );
	}

	public function unregistered( int $event_id, int $registration_id, int $person_id, string $status, int $user_id ): void {
		$this->calls[] = array( 'unregistered', $event_id, $registration_id, $person_id, $status, $user_id );
	}

	public function promoted( int $event_id, int $registration_id, int $person_id ): void {
		$this->calls[] = array( 'promoted', $event_id, $registration_id, $person_id );
	}

	/**
	 * Namen der Ereignisse in der Reihenfolge der Meldung.
	 *
	 * @return list<string>
	 */
	public function names(): array {
		return array_column( $this->calls, 0 );
	}
}
