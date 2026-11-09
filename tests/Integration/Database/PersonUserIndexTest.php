<?php
/**
 * Tests der Umwandlung von person.user_id in einen eindeutigen Index.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Tests\Integration\Database;

use TTLeague\Access\AccessPolicy;
use TTLeague\Accounts\AccountService;
use TTLeague\Infrastructure\Database\PersonUserIndex;
use TTLeague\Tests\Integration\Frontend\Seeder;
use TTLeague\Tests\Integration\IntegrationTestCase;

/**
 * Simuliert eine ältere Installation: gewöhnlicher Index und doppelte Verknüpfungen.
 * Erfundene Personen.
 */
final class PersonUserIndexTest extends IntegrationTestCase {

	private PersonUserIndex $index;

	protected function setUp(): void {
		parent::setUp();
		$this->index = new PersonUserIndex( $this->db );
	}

	protected function tearDown(): void {
		// Für die übrigen Tests wieder den Zustand neuer Installationen herstellen.
		$this->truncate_all();
		$this->index->migrate();
		parent::tearDown();
	}

	private function make_index_non_unique(): void {
		$table = $this->db->table( 'person' );
		$this->db->query( "ALTER TABLE `{$table}` DROP INDEX `user_id`, ADD INDEX `user_id` (`user_id`)" );
	}

	public function test_fresh_schema_is_unique(): void {
		self::assertTrue( $this->index->is_unique() );
		self::assertTrue( $this->index->migrate() );
	}

	public function test_migration_waits_for_cleanup_and_blocks_linking_meanwhile(): void {
		$seeder = new Seeder( $this->db );
		$first  = $seeder->person( 'Muster', 'Hans' );
		$second = $seeder->person( 'Beispiel', 'Erika' );
		$third  = $seeder->person( 'Probe', 'Paula' );
		$this->make_index_non_unique();
		$this->db->update( 'person', array( 'user_id' => 77 ), array( 'id' => $first ) );
		$this->db->update( 'person', array( 'user_id' => 77 ), array( 'id' => $second ) );

		self::assertFalse( $this->index->is_unique() );
		self::assertSame( array( 77 ), $this->index->duplicates() );
		self::assertFalse( $this->index->migrate(), 'Doppelte Verknüpfung verhindert die Umwandlung' );

		$accounts = new AccountService( $this->db, static fn(): bool => false );
		try {
			$accounts->link_user( $third, 88 );
			self::fail( 'Verknüpfen hätte gesperrt sein müssen.' );
		} catch ( \RuntimeException $e ) {
			self::assertStringContainsString( 'bereinigt', $e->getMessage() );
		}

		$policy = new AccessPolicy( $this->db );
		self::assertNull( $policy->person_for_user( 77 ), 'mehrdeutige Zuordnung ergibt keine Person' );

		$accounts->unlink_user( $second );
		$policy->reset();
		self::assertSame( $first, $policy->person_for_user( 77 ) );
		self::assertTrue( $this->index->migrate(), 'Nach der Bereinigung gelingt die Umwandlung' );
		self::assertTrue( $this->index->is_unique() );

		$accounts->link_user( $third, 88 );
		self::assertSame( '88', $this->db->get_var( "SELECT user_id FROM {$this->db->table( 'person' )} WHERE id = %d", array( $third ) ) );
	}

	public function test_migration_without_any_index(): void {
		$table = $this->db->table( 'person' );
		$this->db->query( "ALTER TABLE `{$table}` DROP INDEX `user_id`" );

		self::assertFalse( $this->index->is_unique() );
		self::assertTrue( $this->index->migrate() );
		self::assertTrue( $this->index->is_unique() );
	}
}
