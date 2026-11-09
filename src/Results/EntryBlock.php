<?php
/**
 * WordPress-Anbindung der Erfassungsseite: Block, Skriptmodul, Stil, Kopfzeilen.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Results;

use TTLeague\Accounts\LoginFlow;
use TTLeague\Frontend\Assets;
use TTLeague\Frontend\Frontend;
use TTLeague\Frontend\PageInstaller;
use TTLeague\Rest\ResultController;

/**
 * Block ttleague/result-entry auf der Seite «Resultat erfassen» (Schlüssel entry, /erfassen/).
 * Die Seite wird nicht indexiert und nicht zwischengespeichert; das Formular läuft mit der
 * Interactivity API (Skriptmodul @ttleague/entry) und spricht die REST-API mit X-WP-Nonce an.
 */
final class EntryBlock {

	public const SCRIPT = '@ttleague/entry';
	public const VIEW   = '@ttleague/entry-view';
	public const STYLE  = 'ttleague-entry';

	/** Abfrageparameter mit der Nummer der Begegnung. */
	public const PARAM = 'begegnung';

	public static function register(): void {
		add_action( 'init', array( self::class, 'register_assets' ), 5 );
		add_action( 'init', array( self::class, 'register_block' ) );
		add_action( 'template_redirect', array( self::class, 'private_page' ) );
		add_filter( 'wp_robots', array( self::class, 'robots' ) );
	}

	/**
	 * Stil und Skriptmodul (init, vor dem Block).
	 */
	public static function register_assets(): void {
		wp_register_style( self::STYLE, TTLEAGUE_URL . 'assets/css/entry.css', array( Assets::STYLE ), self::version( 'assets/css/entry.css' ) );
		wp_register_script_module( self::VIEW, TTLEAGUE_URL . 'assets/js/entry-view.js', array(), self::version( 'assets/js/entry-view.js' ) );
		wp_register_script_module( self::SCRIPT, TTLEAGUE_URL . 'assets/js/entry.js', array( '@wordpress/interactivity', self::VIEW ), self::version( 'assets/js/entry.js' ) );
	}

	public static function register_block(): void {
		register_block_type( TTLEAGUE_DIR . 'blocks/result-entry', array( 'render_callback' => array( self::class, 'render' ) ) );
	}

	/**
	 * Render-Callback (der Block hat keine Attribute; die Begegnung kommt aus ?begegnung=).
	 */
	public static function render(): string {
		$fixture_id = self::requested_fixture();
		$actor      = is_user_logged_in() ? Results::current_actor() : null;
		$page       = new EntryPage( Results::service(), wp_timezone() );
		$result     = $page->build(
			$fixture_id,
			$actor,
			self::links( $fixture_id ),
			array( Results::class, 'photo_url' )
		);
		if ( null !== $result['state'] && null !== $result['config'] ) {
			wp_interactivity_state( EntryMarkup::NS, $result['state'] );
			wp_interactivity_config( EntryMarkup::NS, $result['config'] + array( 'nonce' => wp_create_nonce( 'wp_rest' ) ) );
			wp_enqueue_script_module( self::SCRIPT );
		}
		wp_enqueue_style( self::STYLE );
		return '<div ' . get_block_wrapper_attributes( array( 'class' => 'ttl ttl-result-entry' ) ) . '>' . $result['html'] . '</div>';
	}

	/**
	 * Adressen für die Seite einer Begegnung.
	 *
	 * @return array{login:string,my_area:string,report:string,rest:string}
	 */
	public static function links( int $fixture_id ): array {
		$entry = $fixture_id > 0 ? Results::entry_url( $fixture_id ) : Frontend::urls()->page( 'entry' );
		return array(
			'login'   => LoginFlow::page_url( array( 'redirect_to' => $entry ) ),
			'my_area' => LoginFlow::my_area_url(),
			'report'  => Frontend::urls()->fixture( max( 0, $fixture_id ) ),
			'rest'    => rest_url( ResultController::NAMESPACE . '/fixtures/' . $fixture_id . '/' ),
		);
	}

	/**
	 * Erfassungsseite nie zwischenspeichern und keine Adresse als Referrer weitergeben
	 * (wie Anmelden und Mein Bereich).
	 */
	public static function private_page(): void {
		if ( self::is_entry_page() ) {
			nocache_headers();
			header( 'Referrer-Policy: same-origin' );
		}
	}

	/**
	 * @param array<string,bool|string> $robots Robots-Anweisungen.
	 * @return array<string,bool|string>
	 */
	public static function robots( array $robots ): array {
		if ( self::is_entry_page() ) {
			$robots['noindex']  = true;
			$robots['nofollow'] = true;
		}
		return $robots;
	}

	public static function is_entry_page(): bool {
		$id = PageInstaller::page_id( 'entry' );
		return is_page( $id > 0 ? $id : 'erfassen' );
	}

	private static function requested_fixture(): int {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- nur lesend; Rechte prüft der ResultService.
		$raw = isset( $_GET[ self::PARAM ] ) && is_string( $_GET[ self::PARAM ] ) ? sanitize_text_field( wp_unslash( $_GET[ self::PARAM ] ) ) : '';
		return 1 === preg_match( '/^[1-9][0-9]{0,9}$/', $raw ) ? (int) $raw : 0;
	}

	private static function version( string $file ): string {
		$path = TTLEAGUE_DIR . $file;
		return is_readable( $path ) ? TTLEAGUE_VERSION . '.' . (string) filemtime( $path ) : TTLEAGUE_VERSION;
	}
}
