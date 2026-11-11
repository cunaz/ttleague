<?php
/**
 * Gemeinsamer Transaktionsvertrag für PDO und wpdb, jeweils gegen die echte Datenbank.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Tests\Integration\Database;

use TTLeague\Infrastructure\Database\DbException;
use TTLeague\Tests\Integration\Frontend\Seeder;

trait TransactionAssertions {

	public function test_successful_nested_work_is_rolled_back_with_the_outer_transaction(): void {
		$seeder = new Seeder( $this->db );
		try {
			$this->db->transaction(
				function () use ( $seeder ): void {
					$seeder->club( 'Aussen', 81 );
					$this->db->transaction( static fn() => $seeder->club( 'Innen', 82 ) );
					throw new \RuntimeException( 'Abbruch aussen' );
				}
			);
			self::fail( 'Die Ausnahme muss weitergereicht werden.' );
		} catch ( \RuntimeException $e ) {
			self::assertSame( 'Abbruch aussen', $e->getMessage() );
		}
		self::assertSame( '0', $this->db->get_var( "SELECT COUNT(*) FROM {$this->db->table( 'club' )}" ) );
	}

	public function test_a_caught_nested_failure_cannot_commit_partial_work(): void {
		$seeder = new Seeder( $this->db );
		try {
			$this->db->transaction(
				function () use ( $seeder ): void {
					$seeder->club( 'Vorher', 81 );
					try {
						$this->db->transaction(
							static function () use ( $seeder ): void {
								$seeder->club( 'Unvollständig', 82 );
								throw new \RuntimeException( 'Abbruch innen' );
							}
						);
					} catch ( \RuntimeException $e ) {
						self::assertSame( 'Abbruch innen', $e->getMessage() );
					}
					$seeder->club( 'Nachher', 83 );
				}
			);
			self::fail( 'Eine fehlgeschlagene Teiltransaktion muss die gesamte Transaktion abbrechen.' );
		} catch ( DbException $e ) {
			self::assertStringContainsString( 'Teiltransaktion', $e->getMessage() );
		}
		self::assertSame( '0', $this->db->get_var( "SELECT COUNT(*) FROM {$this->db->table( 'club' )}" ) );

		$id = $this->db->transaction( static fn() => $seeder->club( 'Neuanfang', 84 ) );
		self::assertGreaterThan( 0, $id );
		self::assertSame( array( 'Neuanfang' ), $this->db->get_col( "SELECT short_name FROM {$this->db->table( 'club' )}" ) );
	}
}
