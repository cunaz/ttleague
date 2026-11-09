<?php
/**
 * Blöcke «Anmelden», «Mein Bereich» und «Anmelden / Mein Bereich (Link)» für Gäste und Angemeldete.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Tests\WordPress\Accounts;

use TTLeague\Accounts\AccountService;
use TTLeague\Accounts\LoginFlow;
use TTLeague\Accounts\MagicLinks;
use TTLeague\Accounts\MyAreaContext;
use TTLeague\Accounts\Services;
use TTLeague\Tests\WordPress\League;
use TTLeague\Tests\WordPress\TestCase;

/**
 * Liga siehe League; Hanna Heim ist Captain von Seefeld 1 in der aktiven Saison.
 */
final class BlocksTest extends TestCase {

	private League $league;

	private int $hanna;

	public function set_up(): void {
		parent::set_up();
		$this->league = new League( $this->db, '2026-11-10 20:00:00' );
		$this->hanna  = $this->league->id['captain_h1'];
		( new AccountService( $this->db ) )->set_email( $this->hanna, 'hanna.heim@example.invalid' );
	}

	public function test_blocks_are_registered_with_render_callbacks(): void {
		$registry = \WP_Block_Type_Registry::get_instance();

		foreach ( array( 'ttleague/login', 'ttleague/my-area', 'ttleague/account-link' ) as $name ) {
			self::assertTrue( $registry->is_registered( $name ), $name );
			self::assertTrue( $registry->get_registered( $name )->is_dynamic(), $name );
		}
	}

	public function test_login_for_guests_offers_the_link_request(): void {
		$html = $this->render( 'login' );

		self::assertStringContainsString( 'class="ttl ttl-login wp-block-ttleague-login"', $html );
		self::assertStringContainsString( 'action="http://example.org/wp-admin/admin-post.php"', $html );
		self::assertStringContainsString( 'name="action" value="' . LoginFlow::REQUEST . '"', $html );
		self::assertStringContainsString( 'name="_wpnonce" value="' . wp_create_nonce( LoginFlow::REQUEST ) . '"', $html );
		self::assertStringContainsString( 'type="email" name="email"', $html );
		self::assertStringContainsString( 'Mit Passwort anmelden', $html );
		self::assertStringContainsString( esc_url( wp_login_url( 'http://example.org/mein-bereich/' ) ), $html );
		self::assertStringNotContainsString( 'ttl-notice', $html );
	}

	public function test_login_shows_the_outcome_of_a_request(): void {
		$_GET['gesendet'] = '1';
		self::assertStringContainsString( 'haben wir dir soeben einen Anmeldelink geschickt', $this->render( 'login' ) );

		$_GET = array( 'fehler' => 'passwort' );
		self::assertStringContainsString( 'melden sich mit Passwort an', $this->render( 'login' ) );

		$_GET = array( 'fehler' => 'konto' );
		self::assertStringContainsString( 'kann kein Konto angelegt werden', $this->render( 'login' ) );
	}

	public function test_link_from_the_mail_asks_for_confirmation_without_using_the_token(): void {
		$this->db->update( 'person', array( 'first_name' => '<b>Hanna</b>' ), array( 'id' => $this->hanna ) );
		$token               = Services::links()->create( $this->hanna, MagicLinks::LOGIN_TTL );
		$_GET['anmeldung']   = $token;
		$_GET['redirect_to'] = 'http://example.org/erfassen/?begegnung=7';

		$html = $this->render( 'login' );

		self::assertStringContainsString( 'Du meldest dich als &lt;b&gt;Hanna&lt;/b&gt; Heim an.', $html, 'Name maskiert' );
		self::assertStringContainsString( 'name="action" value="' . LoginFlow::LOGIN . '"', $html );
		self::assertStringContainsString( 'name="token" value="' . $token . '"', $html );
		self::assertStringContainsString( 'name="redirect_to" value="http://example.org/erfassen/?begegnung=7"', $html );
		self::assertStringContainsString( 'name="_wpnonce" value="' . wp_create_nonce( LoginFlow::LOGIN ) . '"', $html );
		self::assertSame( $this->hanna, Services::links()->peek( $token ), 'erst der POST verbraucht das Token' );
	}

	public function test_unknown_or_used_link_offers_a_new_one(): void {
		$_GET['anmeldung']   = str_repeat( 'x', 43 );
		$_GET['redirect_to'] = 'https://example.com/';

		$html = $this->render( 'login' );

		self::assertStringContainsString( 'abgelaufen oder wurde bereits verwendet', $html );
		self::assertStringContainsString( 'name="action" value="' . LoginFlow::REQUEST . '"', $html );
		self::assertStringContainsString( 'name="redirect_to" value=""', $html, 'fremdes Ziel verworfen' );
	}

	public function test_login_for_logged_in_users_links_to_my_area(): void {
		wp_set_current_user( $this->member() );

		$html = $this->render( 'login' );

		self::assertStringContainsString( 'Du bist angemeldet als Hanna Heim.', $html );
		self::assertStringContainsString( 'href="http://example.org/mein-bereich/"', $html );
		self::assertStringContainsString( 'Abmelden', $html );
		self::assertStringNotContainsString( '<form', $html );
	}

	public function test_my_area_for_guests_asks_to_log_in(): void {
		$html = $this->render( 'my-area' );

		self::assertStringContainsString( 'class="ttl ttl-my-area wp-block-ttleague-my-area"', $html );
		self::assertStringContainsString( 'Melde dich an', $html );
		self::assertStringContainsString( 'name="action" value="' . LoginFlow::REQUEST . '"', $html );
		self::assertStringContainsString( 'name="redirect_to" value="http://example.org/mein-bereich/"', $html );
	}

	public function test_my_area_asks_for_consent_first(): void {
		wp_set_current_user( $this->member() );

		$html = $this->render( 'my-area' );

		self::assertStringContainsString( 'Willkommen', $html );
		self::assertStringContainsString( 'name="action" value="' . LoginFlow::CONSENT . '"', $html );
		self::assertStringContainsString( 'name="einverstanden"', $html );
		self::assertStringNotContainsString( 'Profil und Benachrichtigungen', $html );
	}

	public function test_my_area_shows_functions_profile_and_sections_of_other_modules(): void {
		( new AccountService( $this->db ) )->set_consent( $this->hanna );
		$user_id  = $this->member();
		$contexts = array();
		add_action(
			'ttleague_my_area_sections',
			static function ( MyAreaContext $context ) use ( &$contexts ): void {
				$contexts[] = $context;
				echo '<section class="ttl-test-section">Meine Spiele</section>';
			}
		);
		wp_set_current_user( $user_id );

		$html = $this->render( 'my-area' );

		self::assertStringContainsString( 'Hallo Hanna', $html );
		self::assertStringContainsString( 'Captain Seefeld 1', $html );
		self::assertStringContainsString( '<section class="ttl-test-section">Meine Spiele</section>', $html );
		self::assertStringContainsString( 'hanna.heim@example.invalid', $html );
		self::assertStringContainsString( 'name="action" value="' . LoginFlow::PROFILE . '"', $html );
		self::assertStringContainsString( 'name="notify_email[', $html );
		self::assertCount( 1, $contexts );
		self::assertSame( $user_id, $contexts[0]->user_id );
		self::assertSame( $this->hanna, $contexts[0]->person_id );
		self::assertSame( $this->league->id['season'], $contexts[0]->season_id );
		self::assertSame( array( $this->league->id['team_h1'] ), $contexts[0]->roles['captain_teams'] );
		self::assertFalse( $contexts[0]->is_tk );
	}

	public function test_my_area_for_accounts_without_person(): void {
		wp_set_current_user( $this->account( 'subscriber', 'ohne.person@example.invalid' ) );

		self::assertStringContainsString( 'Dein Konto ist mit keiner Person verknüpft', $this->render( 'my-area' ) );
	}

	public function test_account_link_for_guests_and_logged_in_users(): void {
		$guest = $this->render( 'account-link' );
		self::assertStringContainsString( 'href="http://example.org/anmelden/"', $guest );
		self::assertStringContainsString( '<span>Anmelden</span>', $guest );

		wp_set_current_user( $this->member() );
		$member = $this->render( 'account-link' );
		self::assertStringContainsString( 'href="http://example.org/mein-bereich/"', $member );
		self::assertStringContainsString( '<span>Mein Bereich</span>', $member );
	}

	/**
	 * Abonnenten-Konto von Hanna Heim.
	 */
	private function member(): int {
		$user_id = $this->account( 'subscriber', 'hanna.heim@example.invalid', $this->hanna );
		wp_update_user(
			array(
				'ID'           => $user_id,
				'display_name' => 'Hanna Heim',
			)
		);
		return $user_id;
	}

	private function render( string $block ): string {
		return do_blocks( '<!-- wp:ttleague/' . $block . ' /-->' );
	}
}
