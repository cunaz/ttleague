<?php
/**
 * Öffentliche Darstellung: Routing, Seiten, Blöcke, Shortcode, Assets.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Frontend;

use TTLeague\Frontend\Support\Urls;

final class Frontend {

	private static ?Urls $urls = null;

	/**
	 * Wird von TTLeague\Plugin::boot() aufgerufen.
	 */
	public static function register(): void {
		add_filter( 'query_vars', array( Routes::class, 'query_vars' ) );
		add_action( 'init', array( Assets::class, 'register' ), 5 );
		add_action( 'init', array( Routes::class, 'add_rewrite_rules' ) );
		add_action( 'init', array( Blocks::class, 'register' ) );
		add_action( 'init', array( Shortcode::class, 'register' ) );
		add_action( 'init', array( PageInstaller::class, 'maybe_install' ), 20 );
		add_filter( 'block_categories_all', array( Blocks::class, 'category' ) );
		add_action( 'enqueue_block_editor_assets', array( Assets::class, 'editor_data' ) );
		add_action( 'wp_enqueue_scripts', array( Assets::class, 'enqueue_frontend' ) );
		add_action( 'template_redirect', array( Router::class, 'template_redirect' ), 5 );
		add_filter( 'document_title_parts', array( Router::class, 'document_title' ) );
		add_filter( 'the_title', array( Router::class, 'the_title' ), 10, 2 );
		add_filter( 'get_canonical_url', array( Router::class, 'canonical' ) );
		add_filter( 'wp_robots', array( Router::class, 'robots' ) );
		add_action( 'save_post_page', array( PageInstaller::class, 'on_page_saved' ) );
		add_filter( 'site_status_tests', array( PageInstaller::class, 'site_status_tests' ) );
		add_action( 'save_post_page', array( self::class, 'reset_urls' ) );

		if ( defined( 'TTLEAGUE_FILE' ) ) {
			register_deactivation_hook( TTLEAGUE_FILE, array( PageInstaller::class, 'deactivate' ) );
		}
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			\WP_CLI::add_command( 'ttleague pages', PagesCommand::class );
		}
	}

	/**
	 * Adressbauer mit den aktuellen Seitenpfaden.
	 */
	public static function urls(): Urls {
		if ( null === self::$urls ) {
			self::$urls = new Urls( home_url( '/' ), PageInstaller::paths() );
		}
		return self::$urls;
	}

	/**
	 * Nach dem Speichern einer Seite die Pfade neu lesen.
	 */
	public static function reset_urls(): void {
		self::$urls = null;
	}

	/**
	 * Aktuelle Ortszeit «Y-m-d H:i:s» (Zeitzone der Website).
	 */
	public static function now(): string {
		return current_time( 'mysql' );
	}
}
