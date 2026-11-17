<?php
/**
 * Anmeldung per Link im Browser: anfordern, einlösen, Herkunftsprüfung.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Tests\WordPress\Accounts;

use PHPUnit\Framework\Attributes\DataProvider;
use TTLeague\Accounts\AccountService;
use TTLeague\Accounts\LoginFlow;
use TTLeague\Accounts\MagicLinks;
use TTLeague\Accounts\Services;
use TTLeague\Infrastructure\Time\FixedClock;
use TTLeague\Notifications\EventTypes;
use TTLeague\Tests\Integration\Frontend\Seeder;
use TTLeague\Tests\WordPress\TestCase;

/**
 * Hanna Heim (erfunden) hat eine E-Mail-Adresse, aber noch kein Konto. Die Website läuft
 * unter http://example.org; Anmeldeseite /anmelden/, persönlicher Bereich /mein-bereich/.
 */
final class LoginFlowTest extends TestCase {

	private const EMAIL = 'hanna.heim@example.invalid';

	private const SENT = 'http://example.org/anmelden/?gesendet=1';

	private const MY_AREA = 'http://example.org/mein-bereich/';

	private int $person;

	/** @var list<int> Ablaufzeit der Anmelde-Cookies (Unix-Zeit). */
	private array $cookie_expirations = array();

	public function set_up(): void {
		parent::set_up();
		$this->person = ( new Seeder( $this->db ) )->person( 'Heim', 'Hanna' );
		( new AccountService( $this->db ) )->set_email( $this->person, self::EMAIL );
		$this->cookie_expirations = array();
		add_action(
			'set_auth_cookie',
			function ( string $cookie, int $expire, int $expiration ): void {
				$this->cookie_expirations[] = $expiration;
			},
			10,
			3
		);
	}

	public function test_link_is_sent_to_a_known_address(): void {
		self::assertSame( self::SENT, $this->request_link( self::EMAIL ) );

		self::assertCount( 1, $this->mails );
		$mail = $this->mails[0];
		self::assertSame( 'magic_link', $mail->type );
		self::assertSame( self::EMAIL, $mail->to );
		self::assertStringContainsString( 'Hallo Hanna', $mail->text );
		self::assertSame( 1, preg_match( '#http://example\.org/anmelden/\?anmeldung=([A-Za-z0-9_-]{43})#', $mail->text, $match ) );
		self::assertSame( $this->person, Services::links()->peek( $match[1] ), 'Token im Link gehört zur Person' );

		// Versand über wp_mail: HTML mit Knopf, Textfassung als Alternative.
		$sent = tests_retrieve_phpmailer_instance()->get_sent();
		self::assertSame( self::EMAIL, $sent->to[0][0] );
		self::assertStringContainsString( 'Jetzt anmelden', $sent->body );
		self::assertStringContainsString( 'anmeldung=' . $match[1], $sent->body );
		self::assertStringContainsString( 'text/plain', $sent->body );
	}

	public function test_unknown_address_gets_the_same_answer_and_no_mail(): void {
		self::assertSame( self::SENT, $this->request_link( 'unbekannt@example.invalid' ) );

		self::assertSame( array(), $this->mails );
		self::assertSame( 0, $this->tokens() );
	}

	/**
	 * @return array<string,array{0:string}>
	 */
	public static function elevated_roles(): array {
		return array(
			'Verwaltung' => array( 'administrator' ),
			'Redaktion'  => array( 'editor' ),
			'TK'         => array( 'ttl_tk' ),
		);
	}

	#[DataProvider( 'elevated_roles' )]
	public function test_account_with_extended_rights_gets_a_password_hint_instead_of_a_link( string $role ): void {
		$this->account( $role, self::EMAIL, $this->person );

		self::assertSame( self::SENT, $this->request_link( self::EMAIL ), 'gleiche Antwort wie immer' );

		self::assertCount( 1, $this->mails );
		self::assertSame( 'password_hint', $this->mails[0]->type );
		self::assertStringContainsString( wp_login_url(), $this->mails[0]->text );
		self::assertStringNotContainsString( 'anmeldung=', $this->mails[0]->text );
		self::assertSame( 0, $this->tokens(), 'kein Token erzeugt' );
	}

	public function test_requests_per_address_are_limited(): void {
		for ( $i = 0; $i < 6; $i++ ) {
			self::assertSame( self::SENT, $this->request_link( self::EMAIL ) );
		}

		self::assertCount( 5, $this->mails, 'höchstens fünf Links pro Adresse und Stunde' );
		self::assertSame( 5, $this->tokens() );
	}

	public function test_the_ip_limit_applies_across_different_email_addresses(): void {
		$_SERVER['REMOTE_ADDR'] = '192.0.2.1';
		for ( $i = 0; $i < 19; ++$i ) {
			self::assertSame( self::SENT, $this->request_link( 'unknown' . $i . '@example.invalid' ) );
		}
		self::assertSame( self::SENT, $this->request_link( self::EMAIL ) );
		self::assertSame( self::SENT, $this->request_link( self::EMAIL ) );
		self::assertCount( 1, $this->mails, 'der 21. Versuch verschickt keinen Link' );
	}

	public function test_flushing_caches_and_changing_ip_cannot_reset_the_email_limit(): void {
		for ( $i = 0; $i < 6; ++$i ) {
			wp_cache_flush();
			$_SERVER['REMOTE_ADDR'] = '192.0.2.' . ( $i + 1 );
			self::assertSame( self::SENT, $this->request_link( 0 === $i % 2 ? self::EMAIL : strtoupper( self::EMAIL ) ) );
		}
		self::assertCount( 5, $this->mails );
		self::assertSame( 5, $this->tokens() );
	}

	#[DataProvider( 'elevated_roles' )]
	public function test_remembered_password_sessions_keep_the_wordpress_lifetime( string $role ): void {
		$user = $this->account( $role, self::EMAIL, $this->person );
		wp_set_auth_cookie( $user, true );
		self::assertEqualsWithDelta( time() + 14 * DAY_IN_SECONDS, $this->cookie_expirations[0], 5 );
	}

	public function test_other_subscribers_and_custom_session_policies_are_not_extended(): void {
		$user = $this->account( 'subscriber', self::EMAIL );
		add_filter( 'auth_cookie_expiration', static fn(): int => HOUR_IN_SECONDS, 5 );
		wp_set_auth_cookie( $user, true );
		self::assertEqualsWithDelta( time() + HOUR_IN_SECONDS, $this->cookie_expirations[0], 5 );
	}

	public function test_an_explicitly_allowed_privileged_magic_login_keeps_its_session_policy(): void {
		$this->account( 'ttl_tk', self::EMAIL, $this->person );
		add_filter( 'ttleague_magic_link_allowed', '__return_true' );
		add_filter( 'auth_cookie_expiration', static fn(): int => HOUR_IN_SECONDS, 5 );
		$this->log_in( Services::links()->create( $this->person, MagicLinks::LOGIN_TTL ), array( 'HTTP_ORIGIN' => 'http://example.org' ) );
		self::assertEqualsWithDelta( time() + HOUR_IN_SECONDS, $this->cookie_expirations[0], 5 );
	}

	public function test_valid_token_logs_in_and_creates_the_account(): void {
		$token = Services::links()->create( $this->person, MagicLinks::LOGIN_TTL );

		$location = $this->log_in( $token, array( 'HTTP_ORIGIN' => 'http://example.org' ) );

		self::assertSame( self::MY_AREA, $location, 'ohne Einwilligung zuerst in den persönlichen Bereich' );
		$user_id = get_current_user_id();
		self::assertGreaterThan( 0, $user_id );
		self::assertSame( $user_id, $this->linked_user( $this->person ) );
		self::assertSame( 'ttl-' . $this->person, wp_get_current_user()->user_login );
		self::assertSame( 1, did_action( 'wp_login' ) );
		self::assertNull( Services::links()->peek( $token ), 'Token verbraucht' );
		self::assertSame( 'magic_link', $this->db->get_var( "SELECT note FROM {$this->db->table( 'audit_log' )} WHERE entity = 'person' AND entity_id = %d AND action = 'login'", array( $this->person ) ) );
		self::assertCount( 1, $this->cookie_expirations );
		self::assertEqualsWithDelta( time() + 60 * DAY_IN_SECONDS, $this->cookie_expirations[0], 60, 'angemeldet bleiben: 60 Tage' );
		wp_set_auth_cookie( $user_id, true );
		self::assertEqualsWithDelta( time() + 14 * DAY_IN_SECONDS, $this->cookie_expirations[1], 5, 'Filter danach entfernt, auch für dasselbe Konto' );
	}

	public function test_cookie_filter_is_removed_when_a_login_hook_throws(): void {
		$fail = static function (): void {
			throw new \RuntimeException( 'Cookie-Hook fehlgeschlagen' );
		};
		add_action( 'set_auth_cookie', $fail );
		try {
			$this->log_in( Services::links()->create( $this->person, MagicLinks::LOGIN_TTL ), array( 'HTTP_ORIGIN' => 'http://example.org' ) );
			self::fail( 'Ausnahme erwartet' );
		} catch ( \RuntimeException $e ) {
			self::assertSame( 'Cookie-Hook fehlgeschlagen', $e->getMessage() );
		} finally {
			remove_action( 'set_auth_cookie', $fail );
		}
		wp_set_auth_cookie( get_current_user_id(), true );
		self::assertEqualsWithDelta( time() + 14 * DAY_IN_SECONDS, $this->cookie_expirations[1], 5 );
	}

	public function test_login_redirects_only_to_pages_of_this_site(): void {
		( new AccountService( $this->db ) )->set_consent( $this->person );
		$own     = 'http://example.org/erfassen/?begegnung=7';
		$foreign = 'https://example.com/erfassen/';

		self::assertSame( $own, $this->log_in( Services::links()->create( $this->person, MagicLinks::LOGIN_TTL ), array( 'HTTP_ORIGIN' => 'http://example.org' ), $own ) );
		wp_set_current_user( 0 );
		self::assertSame( self::MY_AREA, $this->log_in( Services::links()->create( $this->person, MagicLinks::LOGIN_TTL ), array( 'HTTP_ORIGIN' => 'http://example.org' ), $foreign ) );
	}

	public function test_referer_of_this_site_is_enough(): void {
		$token = Services::links()->create( $this->person, MagicLinks::LOGIN_TTL );

		$this->log_in( $token, array( 'HTTP_REFERER' => 'http://example.org/anmelden/?anmeldung=' . $token ) );

		self::assertTrue( is_user_logged_in() );
	}

	public function test_expired_token_is_refused(): void {
		$issued = new FixedClock( gmdate( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS ) );
		$token  = ( new MagicLinks( $this->db, $issued ) )->create( $this->person, MagicLinks::LOGIN_TTL );

		$location = $this->log_in( $token, array( 'HTTP_ORIGIN' => 'http://example.org' ) );

		self::assertSame( 'http://example.org/anmelden/?fehler=link', $location );
		self::assertFalse( is_user_logged_in() );
		self::assertNull( $this->linked_user( $this->person ), 'kein Konto angelegt' );
	}

	public function test_used_token_is_refused(): void {
		$token = Services::links()->create( $this->person, MagicLinks::LOGIN_TTL );
		$this->log_in( $token, array( 'HTTP_ORIGIN' => 'http://example.org' ) );
		wp_set_current_user( 0 );

		$location = $this->log_in( $token, array( 'HTTP_ORIGIN' => 'http://example.org' ) );

		self::assertSame( 'http://example.org/anmelden/?fehler=link', $location );
		self::assertFalse( is_user_logged_in() );
	}

	/**
	 * @return array<string,array{0:array<string,string>}>
	 */
	public static function foreign_origins(): array {
		return array(
			'ohne Origin und Referer' => array( array() ),
			'andere Website'          => array( array( 'HTTP_ORIGIN' => 'https://example.com' ) ),
			'anderes Schema'          => array( array( 'HTTP_ORIGIN' => 'https://example.org' ) ),
			'anderer Port'            => array( array( 'HTTP_ORIGIN' => 'http://example.org:8080' ) ),
			'Referer anderer Website' => array( array( 'HTTP_REFERER' => 'https://example.com/anmelden/' ) ),
		);
	}

	/**
	 * @param array<string,string> $headers Origin oder Referer.
	 */
	#[DataProvider( 'foreign_origins' )]
	public function test_post_from_elsewhere_is_refused( array $headers ): void {
		$token = Services::links()->create( $this->person, MagicLinks::LOGIN_TTL );

		$error = $this->die_of( fn() => $this->log_in( $token, $headers ) );

		self::assertSame( 403, $error->getCode() );
		self::assertFalse( is_user_logged_in() );
		self::assertSame( $this->person, Services::links()->peek( $token ), 'Token nicht verbraucht' );
		self::assertNull( $this->linked_user( $this->person ) );
	}

	public function test_post_without_valid_nonce_is_refused(): void {
		$token = Services::links()->create( $this->person, MagicLinks::LOGIN_TTL );
		$this->post( LoginFlow::LOGIN, array( 'token' => $token ) );
		$_POST['_wpnonce']      = 'falsch';
		$_REQUEST['_wpnonce']   = 'falsch';
		$_SERVER['HTTP_ORIGIN'] = 'http://example.org';

		self::assertSame( 403, $this->die_of( array( LoginFlow::class, 'handle_login' ) )->getCode() );
		self::assertSame( $this->person, Services::links()->peek( $token ) );
	}

	public function test_account_with_extended_rights_is_not_logged_in_by_link(): void {
		$tk    = $this->account( 'ttl_tk', self::EMAIL, $this->person );
		$token = Services::links()->create( $this->person, MagicLinks::LOGIN_TTL );

		$location = $this->log_in( $token, array( 'HTTP_ORIGIN' => 'http://example.org' ) );

		self::assertSame( 'http://example.org/anmelden/?fehler=passwort', $location );
		self::assertFalse( is_user_logged_in() );
		self::assertSame( $tk, $this->linked_user( $this->person ) );
	}

	public function test_address_of_an_account_with_extended_rights_is_reported(): void {
		// Das Konto mit derselben Adresse gehört einer Administratorin und wird nicht verknüpft.
		$admin = $this->account( 'administrator', self::EMAIL );
		$token = Services::links()->create( $this->person, MagicLinks::LOGIN_TTL );

		$location = $this->log_in( $token, array( 'HTTP_ORIGIN' => 'http://example.org' ) );

		self::assertSame( 'http://example.org/anmelden/?fehler=konto', $location );
		self::assertFalse( is_user_logged_in() );
		self::assertNull( $this->linked_user( $this->person ) );
		self::assertNull( ( new AccountService( $this->db ) )->linked_person( $admin ) );
	}

	public function test_guests_reach_only_link_request_and_login(): void {
		foreach ( array( LoginFlow::REQUEST, LoginFlow::LOGIN ) as $action ) {
			self::assertNotFalse( has_action( 'admin_post_nopriv_' . $action ), $action );
		}
		foreach ( array( LoginFlow::CONSENT, LoginFlow::PROFILE ) as $action ) {
			self::assertFalse( has_action( 'admin_post_nopriv_' . $action ), $action . ' nur angemeldet' );
			self::assertNotFalse( has_action( 'admin_post_' . $action ), $action );
		}
	}

	public function test_consent_is_recorded_and_leads_on(): void {
		wp_set_current_user( $this->account( 'subscriber', self::EMAIL, $this->person ) );
		$this->post(
			LoginFlow::CONSENT,
			array(
				'einverstanden' => '1',
				'redirect_to'   => 'http://example.org/erfassen/?begegnung=7',
			)
		);

		self::assertSame( 'http://example.org/erfassen/?begegnung=7', $this->redirect_of( array( LoginFlow::class, 'handle_consent' ) ) );
		self::assertNotEmpty( Services::accounts()->person( $this->person )['consent_at'] );
		self::assertSame( '1', $this->db->get_var( "SELECT COUNT(*) FROM {$this->db->table( 'audit_log' )} WHERE entity = 'person' AND entity_id = %d AND action = 'consent'", array( $this->person ) ) );
	}

	public function test_consent_requires_the_checkbox(): void {
		wp_set_current_user( $this->account( 'subscriber', self::EMAIL, $this->person ) );
		$this->post( LoginFlow::CONSENT, array() );

		self::assertSame( self::MY_AREA . '?fehler=einwilligung', $this->redirect_of( array( LoginFlow::class, 'handle_consent' ) ) );
		self::assertNull( Services::accounts()->person( $this->person )['consent_at'] );
	}

	public function test_profile_and_notification_settings_are_saved(): void {
		wp_set_current_user( $this->account( 'subscriber', self::EMAIL, $this->person ) );
		$this->post(
			LoginFlow::PROFILE,
			array(
				'phone'         => '+41 44 000 00 00 <b>',
				'phone_visible' => '1',
				'notify_email'  => array( EventTypes::CONFIRM_REQUEST => '1' ),
				'notify_push'   => array( EventTypes::RESULT_DECIDED => '1' ),
			)
		);

		self::assertSame( self::MY_AREA . '?gespeichert=1', $this->redirect_of( array( LoginFlow::class, 'handle_profile' ) ) );
		$person = Services::accounts()->person( $this->person );
		self::assertSame( '+41 44 000 00 00', $person['phone'] );
		self::assertSame( '1', $person['phone_visible'] );
		$prefs = Services::preferences()->for_person( $this->person );
		self::assertTrue( $prefs[ EventTypes::CONFIRM_REQUEST ]['email'] );
		self::assertFalse( $prefs[ EventTypes::MATCH_REMINDER ]['email'], 'nicht angekreuzt: aus' );
		self::assertTrue( $prefs[ EventTypes::RESULT_DECIDED ]['push'] );
	}

	/**
	 * Formular «Anmeldelink senden» abschicken; liefert das Ziel der Weiterleitung.
	 */
	private function request_link( string $email ): string {
		$this->post( LoginFlow::REQUEST, array( 'email' => $email ) );
		return $this->redirect_of( array( LoginFlow::class, 'handle_request' ) );
	}

	/**
	 * Knopf «Jetzt anmelden» abschicken; liefert das Ziel der Weiterleitung.
	 *
	 * @param array<string,string> $headers Herkunft der Anfrage (Origin, Referer).
	 */
	private function log_in( string $token, array $headers, string $redirect = '' ): string {
		$this->post(
			LoginFlow::LOGIN,
			array(
				'token'       => $token,
				'redirect_to' => $redirect,
			)
		);
		foreach ( $headers as $name => $value ) {
			$_SERVER[ $name ] = $value;
		}
		return $this->redirect_of( array( LoginFlow::class, 'handle_login' ) );
	}

	/**
	 * Formulardaten mit gültigem Nonce, wie WordPress sie liefert (mit Backslashes maskiert).
	 *
	 * @param array<string,string|array<string,string>> $fields Felder.
	 */
	private function post( string $action, array $fields ): void {
		unset( $_SERVER['HTTP_ORIGIN'], $_SERVER['HTTP_REFERER'] );
		$_POST    = wp_slash( $fields + array( '_wpnonce' => wp_create_nonce( $action ) ) );
		$_REQUEST = $_POST; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Der Test setzt die Formulardaten, der Nonce ist enthalten.
	}

	/**
	 * @param callable():mixed $flow Ablauf, der mit wp_die() enden muss.
	 */
	private function die_of( callable $flow ): \WPDieException {
		try {
			$flow();
		} catch ( \WPDieException $e ) {
			return $e;
		}
		self::fail( 'wp_die() erwartet.' );
	}

	private function tokens(): int {
		return (int) $this->db->get_var( "SELECT COUNT(*) FROM {$this->db->table( 'login_token' )} WHERE person_id = %d", array( $this->person ) );
	}
}
