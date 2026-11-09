<?php
/**
 * Tests der Konto-Verknüpfung von Personen.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Tests\Integration\Accounts;

use TTLeague\Accounts\AccountService;
use TTLeague\Tests\Integration\Frontend\Seeder;
use TTLeague\Tests\Integration\IntegrationTestCase;

final class AccountServiceTest extends IntegrationTestCase {

	public function test_linked_person_ignores_the_person_itself(): void {
		$seeder   = new Seeder( $this->db );
		$first    = $seeder->person( 'Muster', 'Hans' );
		$second   = $seeder->person( 'Beispiel', 'Erika' );
		$accounts = new AccountService( $this->db );
		$accounts->link_user( $first, 77 );

		self::assertSame( $first, $accounts->linked_person( 77, $second ), 'Konto gehört schon einer anderen Person' );
		self::assertNull( $accounts->linked_person( 77, $first ), 'eigene Verknüpfung zählt nicht' );
		self::assertNull( $accounts->linked_person( 78, $second ) );
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
