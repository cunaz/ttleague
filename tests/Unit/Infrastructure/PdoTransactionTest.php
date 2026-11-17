<?php
/**
 * Verbindungsfehler beim Commit dürfen den Transaktionszustand nicht beschädigen.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Tests\Unit\Infrastructure;

use PHPUnit\Framework\TestCase;
use TTLeague\Infrastructure\Database\PdoDb;

final class PdoTransactionTest extends TestCase {

	public function test_a_failed_commit_does_not_break_the_next_transaction(): void {
		$pdo = $this->createMock( \PDO::class ); // phpcs:ignore WordPress.DB.RestrictedClasses.mysql__PDO -- Test der PDO-Implementierung, keine WordPress-Datenbank.
		$pdo->method( 'setAttribute' )->willReturn( true );
		$pdo->expects( self::exactly( 2 ) )->method( 'beginTransaction' )->willReturn( true );
		$pdo->method( 'inTransaction' )->willReturn( true );
		$pdo->expects( self::once() )->method( 'rollBack' )->willReturn( true );
		$commits = 0;
		$pdo->expects( self::exactly( 2 ) )->method( 'commit' )->willReturnCallback(
			static function () use ( &$commits ): bool {
				if ( 1 === ++$commits ) {
					throw new \PDOException( 'Commit fehlgeschlagen' );
				}
				return true;
			}
		);
		$db = new PdoDb( $pdo );
		try {
			$db->transaction( static fn() => 1 );
			self::fail( 'Commit-Fehler erwartet.' );
		} catch ( \PDOException $e ) {
			self::assertSame( 'Commit fehlgeschlagen', $e->getMessage() );
		}
		self::assertSame( 2, $db->transaction( static fn() => 2 ) );
	}
}
