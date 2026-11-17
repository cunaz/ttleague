<?php
/**
 * Der Transaktionsvertrag gilt auch mit WordPress und wpdb.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Tests\WordPress\Database;

use TTLeague\Infrastructure\Database\DbException;
use TTLeague\Infrastructure\Database\WpdbDb;
use TTLeague\Tests\Integration\Database\TransactionAssertions;
use TTLeague\Tests\WordPress\TestCase;

final class TransactionsTest extends TestCase {

	use TransactionAssertions;

	public function test_a_failed_commit_preserves_the_database_error_and_allows_a_new_transaction(): void {
		$wpdb     = $this->createMock( \wpdb::class );
		$commands = array();
		$commits  = 0;
		$wpdb->method( 'query' )->willReturnCallback(
			static function ( string $sql ) use ( $wpdb, &$commands, &$commits ): int|false {
				$commands[]       = $sql;
				$wpdb->last_error = '';
				if ( 'COMMIT' === $sql && 1 === ++$commits ) {
					$wpdb->last_error = 'Verbindung beim Commit verloren';
					return false;
				}
				return 0;
			}
		);
		$db = new WpdbDb( $wpdb );
		try {
			$db->transaction( static fn() => 1 );
			self::fail( 'Commit-Fehler erwartet.' );
		} catch ( DbException $e ) {
			self::assertStringContainsString( 'Verbindung beim Commit verloren', $e->getMessage() );
		}
		self::assertSame( 2, $db->transaction( static fn() => 2 ) );
		self::assertSame( array( 'START TRANSACTION', 'COMMIT', 'ROLLBACK', 'START TRANSACTION', 'COMMIT' ), $commands );
	}
}
