<?php
/**
 * Tests für das Rate-Limit mit festen Zeitfenstern.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Tests\Unit\Infrastructure;

use PHPUnit\Framework\TestCase;
use TTLeague\Infrastructure\RateLimiter;
use TTLeague\Infrastructure\Time\FixedClock;

final class RateLimiterTest extends TestCase {

	/** @var array<string,mixed> */
	private array $store = array();

	/** @var array<string,int> */
	private array $ttl = array();

	private FixedClock $clock;

	private RateLimiter $limiter;

	protected function setUp(): void {
		$this->store   = array();
		$this->clock   = new FixedClock( '2026-11-10 12:00:00' );
		$this->limiter = new RateLimiter(
			$this->clock,
			fn( string $key ) => $this->store[ $key ] ?? false,
			function ( string $key, $value, int $ttl ): void {
				$this->store[ $key ] = $value;
				$this->ttl[ $key ]   = $ttl;
			}
		);
	}

	public function test_allows_up_to_limit_then_blocks(): void {
		for ( $i = 0; $i < 5; $i++ ) {
			self::assertTrue( $this->limiter->hit( 'magic_mail', 'a@example.org', 5, 3600 ), 'Versuch ' . ( $i + 1 ) );
		}
		self::assertFalse( $this->limiter->hit( 'magic_mail', 'a@example.org', 5, 3600 ) );
		self::assertFalse( $this->limiter->hit( 'magic_mail', 'a@example.org', 5, 3600 ) );
	}

	public function test_subjects_and_buckets_are_independent(): void {
		self::assertTrue( $this->limiter->hit( 'magic_mail', 'a@example.org', 1, 3600 ) );
		self::assertFalse( $this->limiter->hit( 'magic_mail', 'a@example.org', 1, 3600 ) );
		self::assertTrue( $this->limiter->hit( 'magic_mail', 'b@example.org', 1, 3600 ) );
		self::assertTrue( $this->limiter->hit( 'magic_ip', 'a@example.org', 1, 3600 ) );
	}

	public function test_new_window_resets_the_count(): void {
		self::assertTrue( $this->limiter->hit( 'magic_ip', '192.0.2.1', 2, 3600 ) );
		self::assertTrue( $this->limiter->hit( 'magic_ip', '192.0.2.1', 2, 3600 ) );
		self::assertFalse( $this->limiter->hit( 'magic_ip', '192.0.2.1', 2, 3600 ) );

		$this->clock->advance( '+1 hour' );
		self::assertTrue( $this->limiter->hit( 'magic_ip', '192.0.2.1', 2, 3600 ) );
	}

	public function test_blocked_attempts_are_not_counted(): void {
		self::assertTrue( $this->limiter->hit( 'x', 's', 1, 60 ) );
		self::assertFalse( $this->limiter->hit( 'x', 's', 1, 60 ) );
		self::assertSame( array( 1 ), array_values( $this->store ) );
		self::assertSame( array( 60 ), array_values( $this->ttl ), 'Ablauf entspricht dem Zeitfenster' );
	}
}
