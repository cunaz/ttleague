<?php
/**
 * Tests der Konto-Verknüpfung von Personen.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Tests\Integration\Accounts;

use TTLeague\Accounts\AccountService;
use TTLeague\Infrastructure\AuditLog;
use TTLeague\Tests\Integration\Frontend\Seeder;
use TTLeague\Tests\Integration\IntegrationTestCase;

/**
 * Erfundene Personen. Konto 90 hat erweiterte Rechte (Administrator, Redaktion oder TK),
 * Konto 77 ist ein gewöhnliches Spielerkonto.
 */
final class AccountServiceTest extends IntegrationTestCase {

	private AccountService $accounts;

	private int $first;

	private int $second;

	protected function setUp(): void {
		parent::setUp();
		$seeder         = new Seeder( $this->db );
		$this->first    = $seeder->person( 'Muster', 'Hans' );
		$this->second   = $seeder->person( 'Beispiel', 'Erika' );
		$this->accounts = new AccountService( $this->db, static fn( int $user_id ): bool => 90 === $user_id );
	}

	private function user_of( int $person_id ): ?string {
		return $this->db->get_var( "SELECT user_id FROM {$this->db->table( 'person' )} WHERE id = %d", array( $person_id ) );
	}

	public function test_linked_person_ignores_the_person_itself(): void {
		$this->accounts->link_user( $this->first, 77 );

		self::assertSame( $this->first, $this->accounts->linked_person( 77, $this->second ), 'Konto gehört schon einer anderen Person' );
		self::assertNull( $this->accounts->linked_person( 77, $this->first ), 'eigene Verknüpfung zählt nicht' );
		self::assertNull( $this->accounts->linked_person( 78, $this->second ) );
	}

	public function test_links_an_ordinary_free_account(): void {
		$this->accounts->link_existing( $this->second, 77 );

		self::assertSame( '77', $this->user_of( $this->second ) );
	}

	public function test_refuses_an_account_of_another_person(): void {
		$this->accounts->link_user( $this->first, 77 );

		try {
			$this->accounts->link_existing( $this->second, 77 );
			self::fail( 'Verknüpfung hätte abgelehnt werden müssen.' );
		} catch ( \RuntimeException $e ) {
			self::assertStringContainsString( 'anderen Person', $e->getMessage() );
		}
		self::assertSame( '77', $this->user_of( $this->first ), 'bestehende Verknüpfung unverändert' );
		self::assertNull( $this->user_of( $this->second ) );
	}

	public function test_refuses_an_account_with_extended_rights(): void {
		try {
			$this->accounts->link_existing( $this->second, 90 );
			self::fail( 'Verknüpfung hätte abgelehnt werden müssen.' );
		} catch ( \RuntimeException $e ) {
			self::assertStringContainsString( 'erweiterte Rechte', $e->getMessage() );
		}
		self::assertNull( $this->user_of( $this->second ) );
	}

	public function test_database_rejects_a_second_link_to_the_same_account(): void {
		$this->accounts->link_user( $this->first, 77 );

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'bereits mit einer anderen Person verknüpft' );
		$this->accounts->link_user( $this->second, 77 );
	}

	public function test_unlink_keeps_the_person(): void {
		$this->accounts->link_user( $this->first, 77 );
		$this->accounts->unlink_user( $this->first );

		self::assertNull( $this->user_of( $this->first ) );
		$this->accounts->link_existing( $this->second, 77 );
		self::assertSame( '77', $this->user_of( $this->second ), 'Konto ist wieder frei' );
	}

	public function test_link_and_audit_are_rolled_back_together(): void {
		$audit = new AuditLog( $this->db );
		try {
			$this->db->transaction(
				function () use ( $audit ): void {
					$this->accounts->link_user( $this->first, 77 );
					$audit->record( 'person', $this->first, 'linked', null, null, array( 'user_id' => 77 ) );
					throw new \RuntimeException( 'Protokoll konnte nicht geschrieben werden.' );
				}
			);
			self::fail( 'Fehler erwartet.' );
		} catch ( \RuntimeException $e ) {
			self::assertSame( 'Protokoll konnte nicht geschrieben werden.', $e->getMessage() );
		}

		self::assertNull( $this->user_of( $this->first ), 'Verknüpfung zurückgerollt' );
		self::assertSame( '0', (string) $this->db->get_var( "SELECT COUNT(*) FROM {$this->db->table( 'audit_log' )}" ), 'Protokoll zurückgerollt' );
	}

	public function test_tables_use_a_transactional_engine(): void {
		$engines = $this->db->get_col(
			'SELECT DISTINCT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN (%s, %s)',
			array( $this->db->table( 'person' ), $this->db->table( 'audit_log' ) )
		);
		self::assertSame( array( 'InnoDB' ), $engines );
	}

	public function test_salutation(): void {
		self::assertSame(
			'Erika',
			AccountService::salutation(
				array(
					'first_name' => ' Erika ',
					'last_name'  => 'Beispiel',
				)
			)
		);
		self::assertSame(
			'Beispiel',
			AccountService::salutation(
				array(
					'first_name' => '',
					'last_name'  => 'Beispiel',
				)
			)
		);
	}
}
