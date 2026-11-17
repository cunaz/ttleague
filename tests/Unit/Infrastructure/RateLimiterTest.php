<?php
/**
 * Das Rate-Limit bleibt bei Datenbankfehlern geschlossen.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Tests\Unit\Infrastructure;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TTLeague\Infrastructure\Database\Db;
use TTLeague\Infrastructure\Database\DbException;
use TTLeague\Infrastructure\RateLimiter;
use TTLeague\Infrastructure\Time\FixedClock;

final class RateLimiterTest extends TestCase {

	/** @return array<string,array{0:int,1:bool}> */
	public static function database_failures(): array {
		return array(
			'wpdb insert' => array( 1, false ),
			'wpdb update' => array( 2, false ),
			'pdo insert'  => array( 1, true ),
			'pdo update'  => array( 2, true ),
		);
	}

	#[DataProvider( 'database_failures' )]
	public function test_storage_errors_never_admit_a_request( int $fail_at, bool $pdo ): void {
		$db = $this->createMock( Db::class );
		$db->method( 'table' )->willReturn( 'wp_ttl_rate_limit' );
		$calls = 0;
		$db->expects( self::exactly( $fail_at ) )->method( 'query' )->willReturnCallback(
			static function () use ( &$calls, $fail_at, $pdo ): int {
				if ( ++$calls === $fail_at ) {
					throw $pdo ? new \PDOException( 'Datenbank nicht erreichbar' ) : new DbException( 'Datenbank nicht erreichbar' );
				}
				return 1;
			}
		);
		$limiter = new RateLimiter( $db, new FixedClock( '2026-11-10 12:00:00' ) );
		self::assertFalse( $limiter->hit( 'mail', 'a@example.invalid', 5, 3600 ) );
	}
}
