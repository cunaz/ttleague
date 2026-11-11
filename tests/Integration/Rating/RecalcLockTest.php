<?php
/**
 * Sperre der Neuberechnung der Wertungszahl mit zwei Verbindungen.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Tests\Integration\Rating;

use TTLeague\Rating\RecalcLock;
use TTLeague\Tests\Integration\IntegrationTestCase;

/**
 * Die erste Verbindung steht für den Lauf, der gerade rechnet (z. B. WP-Cron), die zweite für
 * die Anfrage der TK, die aktuelle Wertungszahlen braucht.
 */
final class RecalcLockTest extends IntegrationTestCase {

	private RecalcLock $running;

	private RecalcLock $waiting;

	protected function setUp(): void {
		parent::setUp();
		$name          = RecalcLock::name( 'ttleague_test', self::PREFIX . 'sperre_' );
		$this->running = new RecalcLock( $this->db, $name );
		$this->waiting = new RecalcLock( self::another_connection(), $name );
	}

	protected function tearDown(): void {
		$this->running->release();
		$this->running->release();
		$this->waiting->release();
		parent::tearDown();
	}

	public function test_a_second_connection_waits_and_gives_up_after_the_wait(): void {
		self::assertTrue( $this->running->acquire( 0 ) );

		self::assertFalse( $this->waiting->acquire( 0 ), 'belegt: ohne Warten sofort abgelehnt' );
		$start = microtime( true );
		self::assertFalse( $this->waiting->acquire( 1 ) );
		self::assertGreaterThanOrEqual( 0.9, microtime( true ) - $start, 'hat die Wartezeit abgewartet' );

		$this->running->release();
		self::assertTrue( $this->waiting->acquire( 0 ), 'nach der Freigabe frei' );
	}

	public function test_the_lock_must_be_released_as_often_as_it_was_taken(): void {
		self::assertTrue( $this->running->acquire( 0 ) );
		self::assertTrue( $this->running->acquire( 0 ), 'für dieselbe Verbindung wiedereintrittsfähig' );

		$this->running->release();
		self::assertFalse( $this->waiting->acquire( 0 ), 'einmal freigegeben, noch gehalten' );
		$this->running->release();
		self::assertTrue( $this->waiting->acquire( 0 ) );
	}

	public function test_installations_on_the_same_server_do_not_block_each_other(): void {
		$names = array(
			RecalcLock::name( 'liga', 'wp_' ),
			RecalcLock::name( 'liga', 'wp2_' ),
			RecalcLock::name( 'liga_test', 'wp_' ),
		);
		self::assertCount( 3, array_unique( $names ), 'Datenbank und Präfix gehören zum Namen' );
		self::assertSame( $names[0], RecalcLock::name( 'liga', 'wp_' ) );
		self::assertLessThanOrEqual( 64, strlen( RecalcLock::name( str_repeat( 'datenbank', 10 ), str_repeat( 'praefix_', 10 ) ) ), 'Höchstlänge eines Sperrnamens' );

		$other = new RecalcLock( self::another_connection(), RecalcLock::name( 'ttleague_test', self::PREFIX . 'andere_' ) );
		self::assertTrue( $this->running->acquire( 0 ) );
		try {
			self::assertTrue( $other->acquire( 0 ), 'anderes Präfix: eigene Sperre' );
		} finally {
			$other->release();
		}
	}
}
