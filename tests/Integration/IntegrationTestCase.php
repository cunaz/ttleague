<?php
/**
 * Basis für Integrationstests gegen eine echte MariaDB/MySQL-Datenbank.
 *
 * Verbindung über Umgebungsvariablen (Standardwerte für die lokale Entwicklung):
 * TTLEAGUE_TEST_DB_DSN  (mysql:host=127.0.0.1;dbname=ttleague_test;charset=utf8mb4)
 * TTLEAGUE_TEST_DB_USER (ttleague)
 * TTLEAGUE_TEST_DB_PASS (ttleague)
 * Ist keine Datenbank erreichbar, werden die Tests übersprungen.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Tests\Integration;

use PHPUnit\Framework\TestCase;
use TTLeague\Infrastructure\Database\PdoDb;
use TTLeague\Infrastructure\Database\Schema;

abstract class IntegrationTestCase extends TestCase {

	protected const PREFIX = 'wptest_';

	private static ?PdoDb $shared = null;

	private static bool $schema_created = false;

	protected PdoDb $db;

	protected function setUp(): void {
		parent::setUp();
		$this->db = self::connection();
		$this->truncate_all();
	}

	protected static function connection(): PdoDb {
		if ( null !== self::$shared ) {
			return self::$shared;
		}
		$dsn  = getenv( 'TTLEAGUE_TEST_DB_DSN' ) ?: 'mysql:host=127.0.0.1;dbname=ttleague_test;charset=utf8mb4';
		$user = getenv( 'TTLEAGUE_TEST_DB_USER' ) ?: 'ttleague';
		$pass = getenv( 'TTLEAGUE_TEST_DB_PASS' );
		$pass = false === $pass ? 'ttleague' : $pass;
		try {
			self::$shared = PdoDb::connect( $dsn, $user, $pass, self::PREFIX );
		} catch ( \PDOException $e ) {
			self::markTestSkipped( 'Keine Testdatenbank erreichbar: ' . $e->getMessage() );
		}
		if ( ! self::$schema_created ) {
			self::create_schema( self::$shared );
			self::$schema_created = true;
		}
		return self::$shared;
	}

	private static function create_schema( PdoDb $db ): void {
		$pdo = $db->pdo();
		foreach ( array_reverse( Schema::TABLES ) as $name ) {
			$pdo->exec( 'DROP TABLE IF EXISTS `' . Schema::table( $name, self::PREFIX ) . '`' );
		}
		foreach ( Schema::statements( self::PREFIX, 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci' ) as $sql ) {
			$pdo->exec( $sql );
		}
	}

	protected function truncate_all(): void {
		foreach ( Schema::TABLES as $name ) {
			$this->db->pdo()->exec( 'TRUNCATE TABLE `' . Schema::table( $name, self::PREFIX ) . '`' );
		}
	}
}
