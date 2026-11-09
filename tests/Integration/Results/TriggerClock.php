<?php
/**
 * Uhr für Tests, die beim nächsten Ablesen einmal eine Aktion ausführt.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Tests\Integration\Results;

use TTLeague\Infrastructure\Time\Clock;
use TTLeague\Infrastructure\Time\FixedClock;

/**
 * Der ResultService liest die Uhr nach der Prüfung von Rechten, Status und Version und vor der
 * Schreibtransaktion. Eine hier hinterlegte Aktion läuft genau in diesem Moment und stellt so
 * eine gleichzeitige Änderung durch eine andere Person nach.
 */
final class TriggerClock implements Clock {

	/** @var (\Closure():void)|null */
	public ?\Closure $before_next = null;

	public function __construct( private readonly FixedClock $clock ) {}

	public function now(): \DateTimeImmutable {
		$action = $this->before_next;
		if ( null !== $action ) {
			$this->before_next = null;
			$action();
		}
		return $this->clock->now();
	}
}
