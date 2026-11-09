<?php
/**
 * Spielerkonten bleiben aus dem Backend: Umleitung und Werkzeugleiste.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Tests\WordPress\Accounts;

use PHPUnit\Framework\Attributes\DataProvider;
use TTLeague\Accounts\Accounts;
use TTLeague\Tests\WordPress\Redirect;
use TTLeague\Tests\WordPress\TestCase;

final class AdminAccessTest extends TestCase {

	private const MY_AREA = 'http://example.org/mein-bereich/';

	public function set_up(): void {
		parent::set_up();
		$_SERVER['SCRIPT_NAME'] = '/wp-admin/index.php';
	}

	public function test_hook_is_registered_on_admin_init(): void {
		self::assertSame( 10, has_action( 'admin_init', array( Accounts::class, 'keep_out_of_admin' ) ) );
	}

	/**
	 * @return array<string,array{0:string,1:string|null}>
	 */
	public static function player_accounts(): array {
		return array(
			'Abonnent'                   => array( 'subscriber', null ),
			'Abonnent mit Medien-Upload' => array( 'subscriber', 'upload_files' ),
		);
	}

	/**
	 * Massgebend sind manage_options, edit_posts und ttl_tk; ein Recht wie upload_files öffnet
	 * das Backend nicht.
	 */
	#[DataProvider( 'player_accounts' )]
	public function test_accounts_without_editorial_or_tk_rights_are_sent_to_my_area( string $role, ?string $capability ): void {
		$user_id = $this->account( $role, 'spielerin@example.invalid' );
		if ( null !== $capability ) {
			get_userdata( $user_id )->add_cap( $capability );
		}
		wp_set_current_user( $user_id );

		self::assertSame( self::MY_AREA, $this->redirect_of( array( Accounts::class, 'keep_out_of_admin' ) ) );
		self::assertFalse( Accounts::show_admin_bar( true ), 'keine Werkzeugleiste' );
	}

	/**
	 * @return array<string,array{0:string}>
	 */
	public static function backend_roles(): array {
		return array(
			'Administrator' => array( 'administrator' ),
			'Redaktion'     => array( 'editor' ),
			'Mitarbeit'     => array( 'contributor' ),
			'TK'            => array( 'ttl_tk' ),
		);
	}

	/**
	 * Ein Konto, das nur manage_options hat, meldet sich mit Passwort an (kein Anmeldelink) und
	 * muss deshalb auch ins Backend dürfen.
	 */
	public function test_account_with_only_manage_options_stays_in_the_backend(): void {
		$user_id = $this->account( 'subscriber', 'verwaltung@example.invalid' );
		get_userdata( $user_id )->add_cap( 'manage_options' );
		wp_set_current_user( $user_id );

		$this->assert_stays( 'nur manage_options' );
		self::assertTrue( Accounts::show_admin_bar( true ) );
	}

	#[DataProvider( 'backend_roles' )]
	public function test_editorial_and_tk_accounts_stay_in_the_backend( string $role ): void {
		wp_set_current_user( $this->account( $role, 'backend@example.invalid' ) );

		$this->assert_stays( 'Backend' );
		self::assertTrue( Accounts::show_admin_bar( true ) );
		self::assertFalse( Accounts::show_admin_bar( false ), 'Einstellung des Kontos bleibt massgebend' );
	}

	public function test_admin_post_and_ajax_stay_reachable_for_players(): void {
		wp_set_current_user( $this->account( 'subscriber', 'spielerin@example.invalid' ) );

		$_SERVER['SCRIPT_NAME'] = '/wp-admin/admin-post.php';
		$this->assert_stays( 'admin-post.php' );

		$_SERVER['SCRIPT_NAME'] = '/wp-admin/admin-ajax.php';
		add_filter( 'wp_doing_ajax', '__return_true' );
		$this->assert_stays( 'admin-ajax.php' );
	}

	public function test_logged_out_visitors_are_left_to_wordpress(): void {
		wp_set_current_user( 0 );

		$this->assert_stays( 'abgemeldet' );
	}

	private function assert_stays( string $label ): void {
		try {
			Accounts::keep_out_of_admin();
		} catch ( Redirect $redirect ) {
			self::fail( $label . ': unerwartete Weiterleitung nach ' . $redirect->location );
		}
		$this->addToAssertionCount( 1 );
	}
}
