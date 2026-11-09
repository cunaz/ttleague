<?php
/**
 * Blöcke «Anmelden» und «Mein Bereich».
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Accounts;

use TTLeague\Access\Capabilities;
use TTLeague\Notifications\EventTypes;

final class Blocks {

	public static function register(): void {
		register_block_type( TTLEAGUE_DIR . 'blocks/login', array( 'render_callback' => array( self::class, 'render_login' ) ) );
		register_block_type( TTLEAGUE_DIR . 'blocks/my-area', array( 'render_callback' => array( self::class, 'render_my_area' ) ) );
		register_block_type( TTLEAGUE_DIR . 'blocks/account-link', array( 'render_callback' => array( self::class, 'render_account_link' ) ) );
	}

	/**
	 * Link für die Kopfzeile: «Anmelden» oder «Mein Bereich».
	 */
	public static function render_account_link(): string {
		$logged_in = is_user_logged_in();
		$url       = $logged_in ? LoginFlow::my_area_url() : LoginFlow::page_url();
		$label     = $logged_in ? __( 'Mein Bereich', 'ttleague' ) : __( 'Anmelden', 'ttleague' );
		return '<div ' . get_block_wrapper_attributes( array( 'class' => 'ttl-account-link' ) ) . '><a href="' . esc_url( $url ) . '" title="' . esc_attr( $label ) . '">'
			. '<svg aria-hidden="true" focusable="false" width="18" height="18" viewBox="0 0 24 24"><path fill="currentColor" d="M12 12a4.5 4.5 0 1 0 0-9 4.5 4.5 0 0 0 0 9Zm0 2c-4.4 0-8 2.2-8 5v2h16v-2c0-2.8-3.6-5-8-5Z"/></svg>'
			. '<span>' . esc_html( $label ) . '</span></a></div>';
	}

	/**
	 * Render-Callback (der Block hat keine Attribute).
	 */
	public static function render_login(): string {
		return '<div ' . get_block_wrapper_attributes( array( 'class' => 'ttl ttl-login' ) ) . '>' . self::login_content( self::requested_redirect() ) . '</div>';
	}

	/**
	 * Render-Callback (der Block hat keine Attribute).
	 */
	public static function render_my_area(): string {
		return '<div ' . get_block_wrapper_attributes( array( 'class' => 'ttl ttl-my-area' ) ) . '>' . self::my_area_content() . '</div>';
	}

	private static function login_content( string $redirect ): string {
		if ( is_user_logged_in() ) {
			$user = wp_get_current_user();
			return '<p>' . esc_html( sprintf( /* translators: %s: Name */ __( 'Du bist angemeldet als %s.', 'ttleague' ), $user->display_name ) ) . '</p>'
				. '<p class="ttl-actions"><a class="ttl-button" href="' . esc_url( LoginFlow::my_area_url() ) . '">' . esc_html__( 'Zu meinem Bereich', 'ttleague' ) . '</a> '
				. '<a href="' . esc_url( wp_logout_url( home_url( '/' ) ) ) . '">' . esc_html__( 'Abmelden', 'ttleague' ) . '</a></p>';
		}

		$token = isset( $_GET['anmeldung'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['anmeldung'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Token aus dem Link, Verarbeitung erst per POST.
		if ( '' !== $token ) {
			$person_id = Services::links()->peek( $token );
			if ( null !== $person_id ) {
				$person = Services::accounts()->person( $person_id );
				$name   = null === $person ? '' : trim( $person['first_name'] . ' ' . $person['last_name'] );
				return '<p>' . esc_html( sprintf( /* translators: %s: Name */ __( 'Du meldest dich als %s an.', 'ttleague' ), $name ) ) . '</p>'
					. '<form class="ttl-form" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">'
					. '<input type="hidden" name="action" value="' . esc_attr( LoginFlow::LOGIN ) . '">'
					. '<input type="hidden" name="token" value="' . esc_attr( $token ) . '">'
					. '<input type="hidden" name="redirect_to" value="' . esc_attr( $redirect ) . '">'
					. wp_nonce_field( LoginFlow::LOGIN, '_wpnonce', true, false )
					. '<button type="submit" class="ttl-button ttl-button--primary">' . esc_html__( 'Jetzt anmelden', 'ttleague' ) . '</button>'
					. '</form>';
			}
			$notice = __( 'Dieser Anmeldelink ist abgelaufen oder wurde bereits verwendet. Fordere einfach einen neuen an.', 'ttleague' );
			return self::notice( $notice, 'warning' ) . self::request_form( $redirect );
		}

		$out = '';
		if ( isset( $_GET['gesendet'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$out .= self::notice( __( 'Falls die Adresse bei uns hinterlegt ist, haben wir dir soeben einen Anmeldelink geschickt. Schau auch im Spam-Ordner nach.', 'ttleague' ), 'success' );
		}
		$error = isset( $_GET['fehler'] ) ? sanitize_key( wp_unslash( (string) $_GET['fehler'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( 'link' === $error ) {
			$out .= self::notice( __( 'Dieser Anmeldelink ist abgelaufen oder wurde bereits verwendet.', 'ttleague' ), 'warning' );
		} elseif ( 'konto' === $error ) {
			$out .= self::notice( __( 'Für diese Person kann kein Konto angelegt werden. Bitte wende dich an deinen TK-Chef.', 'ttleague' ), 'warning' );
		} elseif ( 'passwort' === $error ) {
			$out .= self::notice( __( 'Konten mit erweiterten Rechten (Verwaltung, Redaktion, TK) melden sich mit Passwort an.', 'ttleague' ), 'warning' );
		}
		return $out . self::request_form( $redirect );
	}

	private static function request_form( string $redirect ): string {
		return '<p>' . esc_html__( 'Kein Passwort nötig: Wir schicken dir einen Link, mit dem du dich anmeldest. Er ist 15 Minuten gültig.', 'ttleague' ) . '</p>'
			. '<form class="ttl-form" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">'
			. '<input type="hidden" name="action" value="' . esc_attr( LoginFlow::REQUEST ) . '">'
			. '<input type="hidden" name="redirect_to" value="' . esc_attr( $redirect ) . '">'
			. wp_nonce_field( LoginFlow::REQUEST, '_wpnonce', true, false )
			. '<p class="ttl-field"><label for="ttl-login-email">' . esc_html__( 'E-Mail-Adresse', 'ttleague' ) . '</label>'
			. '<input id="ttl-login-email" type="email" name="email" autocomplete="email" inputmode="email" required></p>'
			. '<button type="submit" class="ttl-button ttl-button--primary">' . esc_html__( 'Anmeldelink senden', 'ttleague' ) . '</button>'
			. '</form>'
			. '<p class="ttl-muted"><a href="' . esc_url( wp_login_url( '' !== $redirect ? $redirect : LoginFlow::my_area_url() ) ) . '">' . esc_html__( 'Mit Passwort anmelden', 'ttleague' ) . '</a></p>';
	}

	private static function my_area_content(): string {
		if ( ! is_user_logged_in() ) {
			return '<p>' . esc_html__( 'Melde dich an, um deine Spiele, Resultate und Einstellungen zu sehen.', 'ttleague' ) . '</p>'
				. self::login_content( LoginFlow::my_area_url() );
		}
		$user_id   = get_current_user_id();
		$policy    = Services::policy();
		$person_id = $policy->person_for_user( $user_id );
		if ( null === $person_id ) {
			return self::notice( __( 'Dein Konto ist mit keiner Person verknüpft. Bitte wende dich an die TK.', 'ttleague' ), 'warning' );
		}
		$person = Services::accounts()->person( $person_id ) ?? array();
		if ( empty( $person['consent_at'] ) ) {
			return self::consent_form();
		}

		$season  = $policy->active_season_id();
		$roles   = null === $season ? array(
			'captain_teams' => array(),
			'tk_chef_clubs' => array(),
			'cup_entries'   => array(),
		) : $policy->roles( $person_id, $season );
		$context = new MyAreaContext( $user_id, $person_id, $person, $season, $roles, user_can( $user_id, Capabilities::TK ) );

		$out = '<h2>' . esc_html( sprintf( /* translators: %s: Vorname */ __( 'Hallo %s', 'ttleague' ), AccountService::salutation( $person ) ) ) . '</h2>';
		if ( isset( $_GET['gespeichert'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$out .= self::notice( __( 'Gespeichert.', 'ttleague' ), 'success' );
		}
		$labels = Services::accounts()->function_labels( $person_id );
		if ( $context->is_tk ) {
			array_unshift( $labels, __( 'Mitglied der Technischen Kommission', 'ttleague' ) );
		}
		if ( array() !== $labels ) {
			$out .= '<p class="ttl-functions">' . esc_html( implode( ' · ', $labels ) ) . '</p>';
		}

		ob_start();
		/**
		 * Weitere Abschnitte im persönlichen Bereich (z. B. Meine Spiele, Mein Club).
		 *
		 * @param MyAreaContext $context Kontext der angemeldeten Person.
		 */
		do_action( 'ttleague_my_area_sections', $context );
		$out .= (string) ob_get_clean();

		$out .= self::profile_form( $person_id, $person );
		$out .= '<p class="ttl-actions"><a href="' . esc_url( wp_logout_url( home_url( '/' ) ) ) . '">' . esc_html__( 'Abmelden', 'ttleague' ) . '</a></p>';
		return $out;
	}

	private static function consent_form(): string {
		$policy = get_privacy_policy_url();
		$link   = '' === $policy ? '' : ' <a href="' . esc_url( $policy ) . '">' . esc_html__( 'Datenschutzerklärung lesen', 'ttleague' ) . '</a>';
		$error  = isset( $_GET['fehler'] ) && 'einwilligung' === sanitize_key( wp_unslash( (string) $_GET['fehler'] ) ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			? self::notice( __( 'Bitte bestätige zuerst den Datenschutzhinweis.', 'ttleague' ), 'warning' )
			: '';
		return '<h2>' . esc_html__( 'Willkommen', 'ttleague' ) . '</h2>' . $error
			. '<p>' . esc_html__( 'Öffentlich sichtbar sind dein Name, dein Club, deine Klassierung, deine Wertungszahl, deine Resultate und deine Funktionen. Deine E-Mail-Adresse und Telefonnummer sehen nur angemeldete Captains, TK-Chefs und die TK, die Telefonnummer nur, wenn du das erlaubst.', 'ttleague' ) . $link . '</p>'
			. '<form class="ttl-form" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">'
			. '<input type="hidden" name="action" value="' . esc_attr( LoginFlow::CONSENT ) . '">'
			. '<input type="hidden" name="redirect_to" value="' . esc_attr( self::requested_redirect() ) . '">'
			. wp_nonce_field( LoginFlow::CONSENT, '_wpnonce', true, false )
			. '<p class="ttl-field ttl-field--check"><input id="ttl-consent" type="checkbox" name="einverstanden" value="1" required> '
			. '<label for="ttl-consent">' . esc_html__( 'Ich habe den Datenschutzhinweis gelesen und bin einverstanden.', 'ttleague' ) . '</label></p>'
			. '<button type="submit" class="ttl-button ttl-button--primary">' . esc_html__( 'Weiter', 'ttleague' ) . '</button>'
			. '</form>';
	}

	/**
	 * @param array<string,string|null> $person Personendaten.
	 */
	private static function profile_form( int $person_id, array $person ): string {
		$prefs = Services::preferences()->for_person( $person_id );
		$push  = (bool) apply_filters( 'ttleague_push_available', false );
		$rows  = '';
		foreach ( EventTypes::labels() as $type => $label ) {
			$rows .= '<tr><th scope="row">' . esc_html( $label ) . '</th>'
				. '<td><input type="checkbox" name="notify_email[' . esc_attr( $type ) . ']" value="1" aria-label="' . esc_attr( $label . ': ' . __( 'E-Mail', 'ttleague' ) ) . '"' . checked( $prefs[ $type ]['email'] ?? true, true, false ) . '></td>'
				. '<td><input type="checkbox" name="notify_push[' . esc_attr( $type ) . ']" value="1" aria-label="' . esc_attr( $label . ': ' . __( 'Push', 'ttleague' ) ) . '"' . checked( $prefs[ $type ]['push'] ?? false, true, false ) . disabled( $push, false, false ) . '></td></tr>';
		}
		return '<section class="ttl-section"><h3>' . esc_html__( 'Profil und Benachrichtigungen', 'ttleague' ) . '</h3>'
			. '<form class="ttl-form" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">'
			. '<input type="hidden" name="action" value="' . esc_attr( LoginFlow::PROFILE ) . '">'
			. wp_nonce_field( LoginFlow::PROFILE, '_wpnonce', true, false )
			. '<p class="ttl-field"><span class="ttl-label">' . esc_html__( 'E-Mail-Adresse', 'ttleague' ) . '</span> ' . esc_html( (string) ( $person['email'] ?? '' ) ) . '<br><small class="ttl-muted">' . esc_html__( 'Ändern kann sie dein TK-Chef oder die TK.', 'ttleague' ) . '</small></p>'
			. '<p class="ttl-field"><label for="ttl-phone">' . esc_html__( 'Telefon', 'ttleague' ) . '</label>'
			. '<input id="ttl-phone" type="tel" name="phone" autocomplete="tel" value="' . esc_attr( (string) ( $person['phone'] ?? '' ) ) . '"></p>'
			. '<p class="ttl-field ttl-field--check"><input id="ttl-phone-visible" type="checkbox" name="phone_visible" value="1"' . checked( '1', (string) ( $person['phone_visible'] ?? '0' ), false ) . '> '
			. '<label for="ttl-phone-visible">' . esc_html__( 'Captains, TK-Chefs und die TK dürfen meine Telefonnummer sehen.', 'ttleague' ) . '</label></p>'
			. '<table class="ttl-table ttl-prefs"><caption>' . esc_html__( 'Benachrichtigungen', 'ttleague' ) . '</caption><thead><tr><th scope="col">' . esc_html__( 'Ereignis', 'ttleague' ) . '</th><th scope="col">' . esc_html__( 'E-Mail', 'ttleague' ) . '</th><th scope="col">' . esc_html__( 'Push', 'ttleague' ) . '</th></tr></thead><tbody>' . $rows . '</tbody></table>'
			. ( $push ? '' : '<p class="ttl-muted">' . esc_html__( 'Push-Benachrichtigungen folgen mit der App.', 'ttleague' ) . '</p>' )
			. '<button type="submit" class="ttl-button">' . esc_html__( 'Speichern', 'ttleague' ) . '</button>'
			. '</form></section>';
	}

	private static function requested_redirect(): string {
		$raw = isset( $_GET['redirect_to'] ) ? (string) wp_unslash( $_GET['redirect_to'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- wird mit wp_validate_redirect geprüft.
		return LoginFlow::safe_redirect( $raw );
	}

	private static function notice( string $text, string $variant ): string {
		return '<p class="ttl-notice ttl-notice--' . esc_attr( $variant ) . '" role="status">' . esc_html( $text ) . '</p>';
	}
}
