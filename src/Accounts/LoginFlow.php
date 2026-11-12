<?php
/**
 * Abläufe im Browser: Anmeldelink anfordern, Anmeldung bestätigen, Einwilligung, Profil.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Accounts;

use TTLeague\Frontend\Frontend;
use TTLeague\Frontend\PageInstaller;
use TTLeague\Infrastructure\Mail\Mailer;
use TTLeague\Infrastructure\Mail\Message;
use TTLeague\Notifications\EventTypes;

final class LoginFlow {

	public const REQUEST = 'ttleague_request_link';
	public const LOGIN   = 'ttleague_magic_login';
	public const CONSENT = 'ttleague_consent';
	public const PROFILE = 'ttleague_profile';

	public const LOGIN_SLUG   = 'anmelden';
	public const MY_AREA_SLUG = 'mein-bereich';

	private const SESSION_DAYS = 60;

	public static function register(): void {
		foreach ( array(
			self::REQUEST => 'handle_request',
			self::LOGIN   => 'handle_login',
		) as $action => $method ) {
			add_action( 'admin_post_nopriv_' . $action, array( self::class, $method ) );
			add_action( 'admin_post_' . $action, array( self::class, $method ) );
		}
		add_action( 'admin_post_' . self::CONSENT, array( self::class, 'handle_consent' ) );
		add_action( 'admin_post_' . self::PROFILE, array( self::class, 'handle_profile' ) );
		add_action( 'template_redirect', array( self::class, 'private_pages' ) );
		add_filter( 'wp_robots', array( self::class, 'robots' ) );
	}

	/**
	 * Adresse der Anmeldeseite (folgt Umbenennungen der Seite).
	 *
	 * @param array<string,string> $args Parameter (Werte unkodiert).
	 */
	public static function page_url( array $args = array() ): string {
		return add_query_arg( array_map( 'rawurlencode', $args ), Frontend::urls()->page( 'login' ) );
	}

	/**
	 * Adresse von «Mein Bereich».
	 *
	 * @param array<string,string> $args Parameter (Werte unkodiert).
	 */
	public static function my_area_url( array $args = array() ): string {
		return add_query_arg( array_map( 'rawurlencode', $args ), Frontend::urls()->page( 'my_area' ) );
	}

	public static function link_url( string $token, string $redirect = '' ): string {
		return self::page_url(
			array_filter(
				array(
					'anmeldung'   => $token,
					'redirect_to' => $redirect,
				)
			)
		);
	}

	/**
	 * Anmeldelink anfordern. Antwort immer gleich, damit niemand Adressen ausprobieren kann.
	 */
	public static function handle_request(): void {
		check_admin_referer( self::REQUEST );
		$email    = sanitize_email( wp_unslash( (string) ( $_POST['email'] ?? '' ) ) );
		$redirect = self::safe_redirect( (string) wp_unslash( $_POST['redirect_to'] ?? '' ) );
		$ip       = self::client_ip();
		$limiter  = Services::limiter();
		$allowed  = $limiter->hit( 'magic_ip', $ip, 20, HOUR_IN_SECONDS ) && $limiter->hit( 'magic_mail', strtolower( $email ), 5, HOUR_IN_SECONDS );

		if ( $allowed && is_email( $email ) ) {
			$person = Services::accounts()->find_person_by_email( $email );
			if ( null !== $person && ! empty( $person['user_id'] ) && ! self::link_allowed( (int) $person['user_id'] ) ) {
				self::send_password_hint( $person );
			} elseif ( null !== $person ) {
				$token = Services::links()->create( (int) $person['id'], MagicLinks::LOGIN_TTL, $ip );
				self::send_link( $person, $token, $redirect );
			}
		}
		wp_safe_redirect(
			self::page_url(
				array_filter(
					array(
						'gesendet'    => '1',
						'redirect_to' => $redirect,
					)
				)
			)
		);
		exit;
	}

	/**
	 * Anmeldung mit Token (nur POST von der eigenen Seite, damit Link-Scanner nichts auslösen).
	 */
	public static function handle_login(): void {
		check_admin_referer( self::LOGIN );
		if ( ! self::same_origin() ) {
			wp_die( esc_html__( 'Die Anmeldung muss von dieser Seite aus erfolgen.', 'ttleague' ), '', array( 'response' => 403 ) );
		}
		$token    = sanitize_text_field( wp_unslash( (string) ( $_POST['token'] ?? '' ) ) );
		$redirect = self::safe_redirect( (string) wp_unslash( $_POST['redirect_to'] ?? '' ) );
		$person   = Services::links()->consume( $token );
		if ( null === $person ) {
			wp_safe_redirect( self::page_url( array( 'fehler' => 'link' ) ) );
			exit;
		}
		try {
			$user_id = Services::accounts()->ensure_user( $person );
		} catch ( \RuntimeException $e ) {
			wp_safe_redirect( self::page_url( array( 'fehler' => 'konto' ) ) );
			exit;
		}
		$user = get_userdata( $user_id );
		if ( false === $user ) {
			wp_safe_redirect( self::page_url( array( 'fehler' => 'konto' ) ) );
			exit;
		}
		if ( ! self::link_allowed( $user_id ) ) {
			wp_safe_redirect( self::page_url( array( 'fehler' => 'passwort' ) ) );
			exit;
		}
		wp_set_current_user( $user_id );
		$expiration = static fn( int $length, int $id, bool $remember ): int => $id === $user_id
			? self::cookie_expiration( $length, $id, $remember ) : $length;
		add_filter( 'auth_cookie_expiration', $expiration, 10, 3 );
		try {
			wp_set_auth_cookie( $user_id, true, is_ssl() );
		} finally {
			remove_filter( 'auth_cookie_expiration', $expiration, 10 );
		}
		do_action( 'wp_login', $user->user_login, $user ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WordPress-Kernaktion.
		Services::audit()->record( 'person', $person, 'login', $user_id, null, null, 'magic_link' );

		$data = Services::accounts()->person( $person );
		if ( null === $data || empty( $data['consent_at'] ) ) {
			wp_safe_redirect( self::my_area_url( array_filter( array( 'redirect_to' => $redirect ) ) ) );
			exit;
		}
		wp_safe_redirect( '' !== $redirect ? $redirect : self::my_area_url() );
		exit;
	}

	public static function handle_consent(): void {
		check_admin_referer( self::CONSENT );
		$person = Services::policy()->person_for_user( get_current_user_id() );
		if ( null === $person || empty( $_POST['einverstanden'] ) ) {
			wp_safe_redirect( self::my_area_url( array( 'fehler' => 'einwilligung' ) ) );
			exit;
		}
		Services::accounts()->set_consent( $person );
		Services::audit()->record( 'person', $person, 'consent', get_current_user_id() );
		$redirect = self::safe_redirect( (string) wp_unslash( $_POST['redirect_to'] ?? '' ) );
		wp_safe_redirect( '' !== $redirect ? $redirect : self::my_area_url() );
		exit;
	}

	public static function handle_profile(): void {
		check_admin_referer( self::PROFILE );
		$person = Services::policy()->person_for_user( get_current_user_id() );
		if ( null === $person ) {
			wp_safe_redirect( self::my_area_url() );
			exit;
		}
		$phone = sanitize_text_field( wp_unslash( (string) ( $_POST['phone'] ?? '' ) ) );
		Services::accounts()->update_contact( $person, $phone, ! empty( $_POST['phone_visible'] ) );
		$email = isset( $_POST['notify_email'] ) && is_array( $_POST['notify_email'] ) ? array_map( 'sanitize_key', array_keys( wp_unslash( $_POST['notify_email'] ) ) ) : array();
		$push  = isset( $_POST['notify_push'] ) && is_array( $_POST['notify_push'] ) ? array_map( 'sanitize_key', array_keys( wp_unslash( $_POST['notify_push'] ) ) ) : array();
		$prefs = Services::preferences();
		foreach ( array_keys( EventTypes::labels() ) as $type ) {
			$prefs->set( $person, $type, in_array( $type, $email, true ), in_array( $type, $push, true ) );
		}
		wp_safe_redirect( self::my_area_url( array( 'gespeichert' => '1' ) ) );
		exit;
	}

	/**
	 * Darf sich dieses Konto per Link anmelden? Konten mit erweiterten Rechten (Verwaltung,
	 * Redaktion, TK) nicht: Wer ihr Postfach liest, bekäme sonst diese Rechte. Sie melden sich
	 * mit Passwort (und gegebenenfalls zweitem Faktor) an.
	 */
	public static function link_allowed( int $user_id ): bool {
		/**
		 * Anmeldung per Link für ein Konto erlauben oder sperren.
		 *
		 * @param bool $allowed Standard: nur Konten ohne erweiterte Rechte (keine Verwaltung,
		 *                      Redaktion oder TK), siehe AccountService::is_elevated().
		 * @param int  $user_id Konto.
		 */
		return (bool) apply_filters( 'ttleague_magic_link_allowed', ! AccountService::is_elevated( $user_id ), $user_id );
	}

	/**
	 * Nur gewöhnliche Spielerkonten erhalten beim Anmeldelink 60 Tage.
	 */
	public static function cookie_expiration( int $length, int $user_id, bool $remember ): int {
		return $remember && ! AccountService::is_elevated( $user_id ) ? self::SESSION_DAYS * DAY_IN_SECONDS : $length;
	}

	/**
	 * Anmelde- und persönliche Seiten nie zwischenspeichern.
	 */
	public static function private_pages(): void {
		if ( self::is_private_page() ) {
			nocache_headers();
			// Das Token in der Adresse nie als Referrer an fremde Seiten weitergeben.
			header( 'Referrer-Policy: same-origin' );
		}
	}

	/**
	 * @param array<string,bool|string> $robots Robots-Anweisungen.
	 * @return array<string,bool|string>
	 */
	public static function robots( array $robots ): array {
		if ( self::is_private_page() ) {
			$robots['noindex']  = true;
			$robots['nofollow'] = true;
		}
		return $robots;
	}

	public static function is_private_page(): bool {
		$ids = array_filter( array( PageInstaller::page_id( 'login' ), PageInstaller::page_id( 'my_area' ) ) );
		return is_page( array() === $ids ? array( self::LOGIN_SLUG, self::MY_AREA_SLUG ) : array_values( $ids ) );
	}

	/**
	 * Nur Ziele auf dieser Seite, sonst leer.
	 */
	public static function safe_redirect( string $url ): string {
		$url = trim( $url );
		if ( '' === $url ) {
			return '';
		}
		$valid = wp_validate_redirect( $url, '' );
		return is_string( $valid ) ? $valid : '';
	}

	/**
	 * @param array<string,string|null> $person Person.
	 */
	private static function send_link( array $person, string $token, string $redirect ): void {
		$url   = self::link_url( $token, $redirect );
		$site  = wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES );
		$first = AccountService::salutation( $person );
		/* translators: 1: Vorname, 2: Name der Seite, 3: Link */
		$text = sprintf( __( "Hallo %1\$s\n\nMit diesem Link meldest du dich bei %2\$s an. Er ist 15 Minuten gültig und funktioniert einmal:\n%3\$s\n\nFalls du keinen Link angefordert hast, kannst du diese E-Mail ignorieren.", 'ttleague' ), $first, $site, $url );
		$html = '<p>' . esc_html( sprintf( /* translators: %s: Vorname */ __( 'Hallo %s', 'ttleague' ), $first ) ) . '</p>'
			. '<p>' . esc_html( sprintf( /* translators: %s: Name der Seite */ __( 'Mit diesem Link meldest du dich bei %s an. Er ist 15 Minuten gültig und funktioniert einmal.', 'ttleague' ), $site ) ) . '</p>'
			. Mailer::button( $url, __( 'Jetzt anmelden', 'ttleague' ) )
			. '<p>' . esc_html__( 'Falls du keinen Link angefordert hast, kannst du diese E-Mail ignorieren.', 'ttleague' ) . '</p>';
		Services::mailer()->send(
			new Message(
				(string) $person['email'],
				/* translators: %s: Name der Seite */
				sprintf( __( 'Dein Anmeldelink für %s', 'ttleague' ), $site ),
				$text,
				$html,
				'magic_link'
			)
		);
	}

	/**
	 * Hinweis an Konten mit erweiterten Rechten, die einen Link angefordert haben.
	 *
	 * @param array<string,string|null> $person Person.
	 */
	private static function send_password_hint( array $person ): void {
		$site  = wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES );
		$first = AccountService::salutation( $person );
		$url   = wp_login_url();
		/* translators: 1: Vorname, 2: Name der Seite, 3: Adresse der Passwort-Anmeldung */
		$text = sprintf( __( "Hallo %1\$s\n\nDu hast einen Anmeldelink für %2\$s angefordert. Für Konten mit erweiterten Rechten (Verwaltung, Redaktion, TK) ist die Anmeldung per Link aus Sicherheitsgründen abgeschaltet. Bitte melde dich mit deinem Passwort an:\n%3\$s", 'ttleague' ), $first, $site, $url );
		$html = '<p>' . esc_html( sprintf( /* translators: %s: Vorname */ __( 'Hallo %s', 'ttleague' ), $first ) ) . '</p>'
			. '<p>' . esc_html( sprintf( /* translators: %s: Name der Seite */ __( 'Du hast einen Anmeldelink für %s angefordert. Für Konten mit erweiterten Rechten (Verwaltung, Redaktion, TK) ist die Anmeldung per Link aus Sicherheitsgründen abgeschaltet. Bitte melde dich mit deinem Passwort an.', 'ttleague' ), $site ) ) . '</p>'
			. Mailer::button( $url, __( 'Mit Passwort anmelden', 'ttleague' ) );
		Services::mailer()->send(
			new Message(
				(string) $person['email'],
				/* translators: %s: Name der Seite */
				sprintf( __( 'Anmeldung bei %s', 'ttleague' ), $site ),
				$text,
				$html,
				'password_hint'
			)
		);
	}

	/**
	 * Kommt die Anfrage von dieser Website (Schema, Host und Port aus Origin oder Referer)?
	 * Schützt vor Login-CSRF; der Nonce allein genügt nicht, weil er für alle abgemeldeten
	 * Besucher gleich ist.
	 */
	private static function same_origin(): bool {
		$source = (string) ( $_SERVER['HTTP_ORIGIN'] ?? $_SERVER['HTTP_REFERER'] ?? '' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- nur Vergleich von Schema, Host und Port.
		return self::origin_matches( $source, home_url() );
	}

	/**
	 * Gleiche Herkunft: Schema und Host ohne Beachtung der Gross-/Kleinschreibung, Port mit
	 * Standardport des Schemas.
	 */
	public static function origin_matches( string $source, string $home ): bool {
		if ( '' === $source ) {
			return false;
		}
		$a = wp_parse_url( $source );
		$b = wp_parse_url( $home );
		if ( ! is_array( $a ) || ! is_array( $b ) || empty( $a['scheme'] ) || empty( $a['host'] ) || empty( $b['scheme'] ) || empty( $b['host'] ) ) {
			return false;
		}
		$scheme = strtolower( (string) $a['scheme'] );
		$port   = static fn( array $url, string $scheme ): int => (int) ( $url['port'] ?? ( 'https' === $scheme ? 443 : 80 ) );
		return strtolower( (string) $b['scheme'] ) === $scheme
			&& strtolower( (string) $a['host'] ) === strtolower( (string) $b['host'] )
			&& $port( $a, $scheme ) === $port( $b, strtolower( (string) $b['scheme'] ) );
	}

	private static function client_ip(): string {
		$ip = (string) ( $_SERVER['REMOTE_ADDR'] ?? '' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		return false === filter_var( $ip, FILTER_VALIDATE_IP ) ? '0.0.0.0' : $ip;
	}
}
