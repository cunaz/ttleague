<?php
/**
 * Einstieg des Kontenmoduls.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Accounts;

final class Accounts {

	public const PURGE_HOOK = 'ttleague_purge_tokens';

	/** Benutzer-Metadatum: Zeitpunkt der letzten Anmeldung (Unix-Zeit). */
	public const LAST_LOGIN_META = 'ttleague_last_login';

	public static function register(): void {
		LoginFlow::register();
		MyClub::register();
		add_action( 'wp_login', array( self::class, 'remember_login' ), 10, 2 );
		add_action( 'init', array( Blocks::class, 'register' ) );
		add_action( 'init', array( self::class, 'schedule' ) );
		add_action( self::PURGE_HOOK, array( self::class, 'purge_tokens' ) );
		add_filter( 'show_admin_bar', array( self::class, 'show_admin_bar' ) );
		add_action( 'admin_init', array( self::class, 'keep_out_of_admin' ) );
		if ( defined( 'TTLEAGUE_FILE' ) ) {
			register_deactivation_hook( TTLEAGUE_FILE, array( self::class, 'deactivate' ) );
		}
	}

	/**
	 * Tägliches Aufräumen abgelaufener Anmeldelinks einplanen.
	 */
	public static function schedule(): void {
		if ( ! wp_next_scheduled( self::PURGE_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::PURGE_HOOK );
		}
	}

	/**
	 * Spielerinnen und Spieler brauchen das Backend nicht: keine Werkzeugleiste.
	 */
	public static function show_admin_bar( bool $show ): bool {
		return $show && self::uses_backend();
	}

	/**
	 * Konten ohne erweiterte Rechte (Verwaltung, Redaktion, TK) landen in «Mein Bereich» statt im
	 * Backend. admin-ajax.php und admin-post.php bleiben erreichbar.
	 */
	public static function keep_out_of_admin(): void {
		if ( wp_doing_ajax() || ! is_user_logged_in() || self::uses_backend() ) {
			return;
		}
		$script = isset( $_SERVER['SCRIPT_NAME'] ) ? basename( sanitize_text_field( wp_unslash( (string) $_SERVER['SCRIPT_NAME'] ) ) ) : '';
		if ( 'admin-post.php' === $script ) {
			return;
		}
		wp_safe_redirect( LoginFlow::my_area_url() );
		exit;
	}

	/**
	 * Dieselbe Grenze wie beim Anmeldelink (AccountService::is_elevated): Wer sich wegen
	 * erweiterter Rechte nur mit Passwort anmeldet, behält auch das Backend.
	 */
	private static function uses_backend(): bool {
		return AccountService::is_elevated( get_current_user_id() );
	}

	/**
	 * Letzte Anmeldung festhalten (Passwort und Anmeldelink lösen beide wp_login aus), für den
	 * Kontostatus in «Konten und Einladungen» und «Mein Club».
	 *
	 * @param string $user_login Anmeldename.
	 * @param mixed  $user       Konto (WP_User); fehlt, wenn Drittcode die Aktion ohne Konto auslöst.
	 */
	public static function remember_login( string $user_login, mixed $user = null ): void {
		unset( $user_login );
		if ( $user instanceof \WP_User ) {
			update_user_meta( $user->ID, self::LAST_LOGIN_META, Services::clock()->now()->getTimestamp() );
		}
	}

	public static function purge_tokens(): void {
		Services::links()->purge();
	}

	public static function deactivate(): void {
		wp_clear_scheduled_hook( self::PURGE_HOOK );
	}
}
