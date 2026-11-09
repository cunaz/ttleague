<?php
/**
 * Tests der Herkunftsprüfung für die Anmeldung per Link.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Tests\Unit\Accounts;

use PHPUnit\Framework\TestCase;
use TTLeague\Accounts\LoginFlow;

final class OriginTest extends TestCase {

	public static function setUpBeforeClass(): void {
		require_once dirname( __DIR__ ) . '/Frontend/Stubs/WordPress.php';
	}

	public function test_same_origin(): void {
		self::assertTrue( LoginFlow::origin_matches( 'https://liga.example.org', 'https://liga.example.org' ) );
		self::assertTrue( LoginFlow::origin_matches( 'https://Liga.Example.org/anmelden/?anmeldung=x', 'https://liga.example.org/' ) );
		self::assertTrue( LoginFlow::origin_matches( 'https://liga.example.org:443', 'https://liga.example.org' ), 'Standardport' );
		self::assertTrue( LoginFlow::origin_matches( 'http://localhost:8080', 'http://localhost:8080' ) );
	}

	public function test_other_origin(): void {
		self::assertFalse( LoginFlow::origin_matches( 'http://liga.example.org', 'https://liga.example.org' ), 'anderes Schema' );
		self::assertFalse( LoginFlow::origin_matches( 'https://evil.example', 'https://liga.example.org' ) );
		self::assertFalse( LoginFlow::origin_matches( 'https://liga.example.org.evil.example', 'https://liga.example.org' ) );
		self::assertFalse( LoginFlow::origin_matches( 'http://localhost:8081', 'http://localhost:8080' ), 'anderer Port' );
		self::assertFalse( LoginFlow::origin_matches( 'null', 'https://liga.example.org' ) );
		self::assertFalse( LoginFlow::origin_matches( '', 'https://liga.example.org' ) );
	}
}
