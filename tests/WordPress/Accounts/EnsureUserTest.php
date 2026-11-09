<?php
/**
 * AccountService::ensure_user() mit echten WordPress-Konten und Rollen.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Tests\WordPress\Accounts;

use PHPUnit\Framework\Attributes\DataProvider;
use TTLeague\Accounts\AccountService;
use TTLeague\Tests\Integration\Frontend\Seeder;
use TTLeague\Tests\WordPress\TestCase;

/**
 * Hans Muster (erfunden) meldet sich zum ersten Mal an. Mit seiner E-Mail-Adresse gibt es
 * schon ein WordPress-Konto oder keines. Konten mit erweiterten Rechten und Konten anderer
 * Personen dürfen nie automatisch verknüpft werden: Wer die E-Mail-Adresse einer Person
 * setzen kann, könnte sonst über einen Anmeldelink ein fremdes Konto übernehmen.
 */
final class EnsureUserTest extends TestCase {

	private const EMAIL = 'hans.muster@example.invalid';

	private AccountService $accounts;

	private Seeder $seeder;

	private int $person;

	public function set_up(): void {
		parent::set_up();
		$this->accounts = new AccountService( $this->db );
		$this->seeder   = new Seeder( $this->db );
		$this->person   = $this->seeder->person( 'Muster', 'Hans' );
		$this->accounts->set_email( $this->person, self::EMAIL );
	}

	/**
	 * Echte Rollen mit erweiterten Rechten und Abonnenten-Konten mit je einem einzelnen
	 * erweiterten Recht: Verwaltung (manage_options), Redaktion (edit_posts), TK (ttl_tk).
	 * Die TK-Berechtigung heisst wie die Rolle; am Konto ist sie deshalb immer die Rolle.
	 *
	 * @return array<string,array{0:string,1:string|null}>
	 */
	public static function elevated_accounts(): array {
		return array(
			'Rolle administrator'                    => array( 'administrator', null ),
			'Rolle editor'                           => array( 'editor', null ),
			'Rolle contributor (edit_posts)'         => array( 'contributor', null ),
			'Rolle ttl_tk'                           => array( 'ttl_tk', null ),
			'Abonnent mit manage_options'            => array( 'subscriber', 'manage_options' ),
			'Abonnent mit edit_posts'                => array( 'subscriber', 'edit_posts' ),
			'Abonnent mit zusätzlicher Rolle ttl_tk' => array( 'subscriber', 'ttl_tk' ),
		);
	}

	#[DataProvider( 'elevated_accounts' )]
	public function test_account_with_extended_rights_is_not_linked( string $role, ?string $capability ): void {
		$user_id = $this->account( $role, self::EMAIL );
		if ( null !== $capability ) {
			get_userdata( $user_id )->add_cap( $capability );
		}
		$caps = get_userdata( $user_id )->caps;
		self::assertTrue( AccountService::is_elevated( $user_id ), 'Konto hat erweiterte Rechte' );

		$error = $this->refusal();

		self::assertStringContainsString( 'erweiterte Rechte', $error->getMessage() );
		self::assertNull( $this->linked_user( $this->person ), 'Person bleibt ohne Konto' );
		self::assertNull( $this->accounts->linked_person( $user_id ), 'Konto bleibt ohne Person' );
		self::assertSame( $caps, get_userdata( $user_id )->caps, 'Rollen und Rechte des Kontos unverändert' );
		self::assertSame( 1, $this->accounts_with_email(), 'kein zweites Konto angelegt' );
	}

	public function test_account_of_another_person_is_not_linked(): void {
		$other   = $this->seeder->person( 'Beispiel', 'Erika' );
		$user_id = $this->account( 'subscriber', self::EMAIL, $other );

		$error = $this->refusal();

		self::assertStringContainsString( 'anderen Person', $error->getMessage() );
		self::assertSame( $user_id, $this->linked_user( $other ), 'bestehende Verknüpfung unverändert' );
		self::assertNull( $this->linked_user( $this->person ) );
		self::assertSame( 1, $this->accounts_with_email() );
	}

	public function test_free_subscriber_account_is_linked(): void {
		// Gross-/Kleinschreibung der Adresse spielt keine Rolle.
		$user_id = $this->account( 'subscriber', 'Hans.Muster@example.invalid' );

		self::assertSame( $user_id, $this->accounts->ensure_user( $this->person ) );
		self::assertSame( $user_id, $this->linked_user( $this->person ) );
		self::assertSame( array( 'subscriber' ), get_userdata( $user_id )->roles );
		self::assertSame( $user_id, $this->accounts->ensure_user( $this->person ), 'zweiter Aufruf liefert dasselbe Konto' );
	}

	public function test_account_is_created_when_none_exists(): void {
		self::assertSame( 0, $this->accounts_with_email() );

		$user_id = $this->accounts->ensure_user( $this->person );

		$user = get_userdata( $user_id );
		self::assertInstanceOf( \WP_User::class, $user );
		self::assertSame( 'ttl-' . $this->person, $user->user_login );
		self::assertSame( self::EMAIL, $user->user_email );
		self::assertSame( 'Hans Muster', $user->display_name );
		self::assertSame( 'Hans', $user->first_name );
		self::assertSame( 'Muster', $user->last_name );
		self::assertSame( array( 'subscriber' ), $user->roles );
		self::assertFalse( AccountService::is_elevated( $user_id ) );
		self::assertSame( $user_id, $this->linked_user( $this->person ) );
		self::assertSame( $user_id, $this->accounts->ensure_user( $this->person ), 'kein zweites Konto' );
		self::assertSame( 1, $this->accounts_with_email() );
	}

	public function test_link_to_a_deleted_account_is_replaced(): void {
		$deleted = $this->account( 'subscriber', 'alt.muster@example.invalid', $this->person );
		require_once ABSPATH . 'wp-admin/includes/user.php';
		wp_delete_user( $deleted );

		$user_id = $this->accounts->ensure_user( $this->person );

		self::assertNotSame( $deleted, $user_id );
		self::assertSame( self::EMAIL, get_userdata( $user_id )->user_email );
		self::assertSame( $user_id, $this->linked_user( $this->person ) );
	}

	public function test_linked_account_is_returned_as_it_is(): void {
		// Von Hand verknüpft (wp ttleague account link): ensure_user prüft nicht erneut; die
		// Anmeldung per Link sperrt solche Konten separat (LoginFlowTest).
		$user_id = $this->account( 'ttl_tk', 'tk.muster@example.invalid', $this->person );

		self::assertSame( $user_id, $this->accounts->ensure_user( $this->person ) );
	}

	public function test_person_without_valid_address_gets_no_account(): void {
		$this->accounts->set_email( $this->person, 'keine-adresse' );

		self::assertStringContainsString( 'keine gültige E-Mail-Adresse', $this->refusal()->getMessage() );
		self::assertNull( $this->linked_user( $this->person ) );
	}

	/**
	 * ensure_user() muss mit einer RuntimeException ablehnen.
	 */
	private function refusal(): \RuntimeException {
		$error = null;
		try {
			$this->accounts->ensure_user( $this->person );
		} catch ( \RuntimeException $e ) {
			$error = $e;
		}
		self::assertInstanceOf( \RuntimeException::class, $error, 'ensure_user() hätte ablehnen müssen.' );
		return $error;
	}

	private function accounts_with_email(): int {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->users} WHERE user_email = %s", self::EMAIL ) );
	}
}
