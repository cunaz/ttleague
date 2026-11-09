<?php
/**
 * Tests der Index-Umwandlung bei Datenbankfehlern.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Tests\Unit\Infrastructure;

use PHPUnit\Framework\TestCase;
use TTLeague\Infrastructure\Database\Db;
use TTLeague\Infrastructure\Database\DbException;
use TTLeague\Infrastructure\Database\PersonUserIndex;

final class PersonUserIndexTest extends TestCase {

	public function test_failed_alter_table_returns_false_instead_of_throwing(): void {
		$db    = new class() implements Db {
			public function table( string $name ): string {
				return 'wp_ttl_' . $name;
			}
			public function prefix(): string {
				return 'wp_';
			}
			public function get_results( string $sql, array $args = array() ): array {
				return array();
			}
			public function get_row( string $sql, array $args = array() ): ?array {
				return null;
			}
			public function get_var( string $sql, array $args = array() ): ?string {
				return '0';
			}
			public function get_col( string $sql, array $args = array() ): array {
				return array();
			}
			public function query( string $sql, array $args = array() ): int {
				throw new DbException( 'Datenbankfehler: Lock wait timeout exceeded' );
			}
			public function insert( string $table, array $data ): int {
				return 0;
			}
			public function insert_many( string $table, array $rows, int $chunk = 500 ): int {
				return 0;
			}
			public function update( string $table, array $data, array $where ): int {
				return 0;
			}
			public function delete( string $table, array $where ): int {
				return 0;
			}
			public function transaction( callable $work ): mixed {
				return $work( $this );
			}
			public function now(): string {
				return '2026-11-10 12:00:00';
			}
		};
		$index = new PersonUserIndex( $db );

		self::assertFalse( $index->migrate() );
		self::assertStringContainsString( 'Lock wait timeout', $index->last_error );
	}
}
