<?php
/**
 * Feste, verstellbare Uhr für Tests.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Infrastructure\Time;

final class FixedClock implements Clock {

	private \DateTimeImmutable $now;

	public function __construct( string $now = '2026-11-10 12:00:00' ) {
		$this->now = new \DateTimeImmutable( $now, new \DateTimeZone( 'UTC' ) );
	}

	public function now(): \DateTimeImmutable {
		return $this->now;
	}

	public function advance( string $modifier ): void {
		$this->now = $this->now->modify( $modifier );
	}
}
