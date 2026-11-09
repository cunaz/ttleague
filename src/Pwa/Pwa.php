<?php
/**
 * Grundlage der installierbaren Web-App (PWA, ohne Push): Manifest, Service Worker, Kopfzeilen.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Pwa;

/**
 * Manifest unter /manifest.webmanifest und Service Worker unter /sw.js, beide über Routen
 * und bei parse_request beantwortet. Im <head> stehen Links auf Manifest, theme-color und
 * apple-touch-icon; ein schlankes Skript meldet den Service Worker an, ausser in der
 * Ansicht angemeldeter Redaktorinnen und Administratoren, in Vorschau und Customizer.
 *
 * Filter: ttleague_pwa_name, ttleague_pwa_short_name, ttleague_pwa_manifest,
 * ttleague_pwa_never_cache (Pfade), ttleague_pwa_service_worker (false schaltet die
 * Anmeldung des Service Workers ab).
 */
final class Pwa {

	public const QUERY_VAR = 'ttl_pwa';

	public const SCRIPT = 'ttleague-pwa';

	public const ROUTES_VERSION = '1';

	public const ROUTES_OPTION = 'ttleague_pwa_routes';

	/**
	 * Wird von TTLeague\Plugin::boot() aufgerufen.
	 */
	public static function register(): void {
		add_filter( 'query_vars', array( self::class, 'query_vars' ) );
		add_action( 'init', array( self::class, 'add_rewrite_rules' ) );
		add_action( 'init', array( self::class, 'maybe_flush' ), 99 );
		add_action( 'parse_request', array( self::class, 'parse_request' ) );
		add_action( 'wp_head', array( self::class, 'head' ), 2 );
		add_action( 'wp_enqueue_scripts', array( self::class, 'enqueue' ) );
	}

	/**
	 * @param list<string> $vars Öffentliche Query-Variablen.
	 * @return list<string>
	 */
	public static function query_vars( array $vars ): array {
		return array_values( array_unique( array_merge( $vars, array( self::QUERY_VAR ) ) ) );
	}

	/**
	 * Rewrite-Regeln als Regex => Query (relativ zur Startadresse); reine Funktion.
	 *
	 * @return array<string,string>
	 */
	public static function rules(): array {
		return array(
			'^manifest\.webmanifest$' => 'index.php?' . self::QUERY_VAR . '=manifest',
			'^sw\.js$'                => 'index.php?' . self::QUERY_VAR . '=sw',
		);
	}

	public static function add_rewrite_rules(): void {
		foreach ( self::rules() as $regex => $query ) {
			add_rewrite_rule( $regex, $query, 'top' );
		}
	}

	/**
	 * Nach einem Update mit neuen Regeln die Rewrite-Regeln einmalig erneuern.
	 */
	public static function maybe_flush(): void {
		if ( self::ROUTES_VERSION !== get_option( self::ROUTES_OPTION ) ) {
			flush_rewrite_rules( false );
			update_option( self::ROUTES_OPTION, self::ROUTES_VERSION, true );
		}
	}

	/**
	 * parse_request: Manifest oder Service Worker ausliefern.
	 */
	public static function parse_request( \WP $wp ): void {
		$what = (string) ( $wp->query_vars[ self::QUERY_VAR ] ?? '' );
		if ( 'manifest' === $what ) {
			self::send_manifest();
			exit;
		}
		if ( 'sw' === $what ) {
			self::send_service_worker();
			exit;
		}
	}

	/**
	 * Manifest mit Namen, Farben und Icons der Website.
	 *
	 * @return array<string,mixed>
	 */
	public static function manifest(): array {
		$name     = (string) apply_filters( 'ttleague_pwa_name', wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES ) );
		$palette  = self::palette();
		$manifest = Manifest::build(
			array(
				'name'             => $name,
				'short_name'       => (string) apply_filters( 'ttleague_pwa_short_name', Manifest::short_name( $name ), $name ),
				'description'      => wp_specialchars_decode( (string) get_bloginfo( 'description' ), ENT_QUOTES ),
				'lang'             => (string) get_bloginfo( 'language' ),
				'dir'              => is_rtl() ? 'rtl' : 'ltr',
				'base'             => self::base(),
				'theme_color'      => Manifest::color( $palette, Manifest::THEME_SLUGS, Manifest::THEME_COLOR ),
				'background_color' => Manifest::color( $palette, Manifest::BACKGROUND_SLUGS, Manifest::BACKGROUND_COLOR ),
				'icons'            => Manifest::icons( self::site_icon(), TTLEAGUE_URL . 'assets/pwa/' ),
			)
		);
		$filtered = apply_filters( 'ttleague_pwa_manifest', $manifest );
		return is_array( $filtered ) ? $filtered : $manifest;
	}

	/**
	 * wp_head: Manifest, Themenfarbe, Name und Icon für den Startbildschirm.
	 */
	public static function head(): void {
		$manifest = self::manifest();
		echo '<link rel="manifest" href="' . esc_url( home_url( '/manifest.webmanifest' ) ) . '">' . "\n";
		echo '<meta name="theme-color" content="' . esc_attr( (string) $manifest['theme_color'] ) . '">' . "\n";
		echo '<meta name="apple-mobile-web-app-title" content="' . esc_attr( (string) $manifest['short_name'] ) . '">' . "\n";
		if ( ! has_site_icon() ) {
			// Mit Site-Icon gibt WordPress selbst ein apple-touch-icon aus.
			echo '<link rel="apple-touch-icon" href="' . esc_url( TTLEAGUE_URL . 'assets/pwa/apple-touch-icon.png' ) . '">' . "\n";
		}
	}

	/**
	 * Anmeldung des Service Workers, nicht in der Ansicht angemeldeter Redaktion und Verwaltung.
	 */
	public static function enqueue(): void {
		if ( ! self::should_register() ) {
			return;
		}
		wp_enqueue_script(
			self::SCRIPT,
			TTLEAGUE_URL . 'assets/pwa/register.js',
			array(),
			self::version( 'assets/pwa/register.js' ),
			array(
				'in_footer' => true,
				'strategy'  => 'defer',
			)
		);
		wp_add_inline_script(
			self::SCRIPT,
			'window.ttleaguePwa = ' . wp_json_encode(
				array(
					'sw'    => self::path( 'sw.js' ),
					'scope' => self::base(),
				)
			) . ';',
			'before'
		);
	}

	/**
	 * Service Worker anmelden? Nicht für angemeldete Personen mit Redaktionsrechten, nicht in
	 * Vorschau und Customizer; per Filter abschaltbar.
	 */
	private static function should_register(): bool {
		if ( is_admin() || is_preview() || is_customize_preview() ) {
			return false;
		}
		if ( is_user_logged_in() && current_user_can( 'edit_posts' ) ) {
			return false;
		}
		return (bool) apply_filters( 'ttleague_pwa_service_worker', true );
	}

	private static function send_manifest(): void {
		$json = (string) wp_json_encode( self::manifest(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT );
		status_header( 200 );
		header( 'Content-Type: application/manifest+json; charset=utf-8' );
		header( 'Cache-Control: public, max-age=3600' );
		header( 'X-Content-Type-Options: nosniff' );
		echo $json; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON aus wp_json_encode.
	}

	private static function send_service_worker(): void {
		$file     = TTLEAGUE_DIR . 'assets/pwa/sw.js';
		$template = is_readable( $file ) ? (string) file_get_contents( $file ) : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- lokale Datei des Plugins.
		$palette  = self::palette();
		$config   = ServiceWorker::config(
			self::base(),
			self::path( '?quelle=app' ),
			(array) apply_filters( 'ttleague_pwa_never_cache', ServiceWorker::NEVER_CACHE ),
			array(
				'lang'    => (string) get_bloginfo( 'language' ),
				'site'    => wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES ),
				'title'   => __( 'Offline', 'ttleague' ),
				'heading' => __( 'Keine Verbindung', 'ttleague' ),
				'text'    => __( 'Diese Seite ist ohne Internetverbindung nicht verfügbar. Bereits besuchte Seiten lassen sich weiterhin öffnen.', 'ttleague' ),
				'retry'   => __( 'Erneut versuchen', 'ttleague' ),
				'home'    => __( 'Zur Startseite', 'ttleague' ),
			),
			array(
				'background' => Manifest::color( $palette, Manifest::BACKGROUND_SLUGS, Manifest::BACKGROUND_COLOR ),
				'text'       => Manifest::color( $palette, Manifest::TEXT_SLUGS, Manifest::TEXT_COLOR ),
				'accent'     => Manifest::color( $palette, Manifest::THEME_SLUGS, Manifest::THEME_COLOR ),
			)
		);

		status_header( 200 );
		header( 'Content-Type: application/javascript; charset=utf-8' );
		header( 'Service-Worker-Allowed: ' . self::base() );
		header( 'Cache-Control: no-cache, max-age=0' );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'X-Robots-Tag: noindex' );
		echo ServiceWorker::script( $template, $config ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JavaScript, Konfiguration als JSON.
	}

	/**
	 * Bilder des Site-Icons für 192 und 512 px mit ihren tatsächlichen Abmessungen: die passende
	 * Zwischengrösse (beim Zuschneiden im Dialog «Website-Icon» exakt), sonst das Originalbild.
	 *
	 * @return list<array{src:string,width:int,height:int}>
	 */
	private static function site_icon(): array {
		$id = (int) get_option( 'site_icon' );
		if ( $id <= 0 || ! has_site_icon() ) {
			return array();
		}
		$meta     = wp_get_attachment_metadata( $id );
		$variants = is_array( $meta ) && is_array( $meta['sizes'] ?? null ) ? $meta['sizes'] : array();
		$images   = array();
		foreach ( array( 192, 512 ) as $size ) {
			$sub = image_get_intermediate_size( $id, array( $size, $size ) );
			if ( is_array( $sub ) && ! empty( $sub['url'] ) ) {
				// WordPress verkleinert width/height auf die angefragte Grösse; massgeblich ist die Datei.
				$real = array( (int) $sub['width'], (int) $sub['height'] );
				foreach ( $variants as $variant ) {
					if ( is_array( $variant ) && ( $variant['file'] ?? '' ) === $sub['file'] ) {
						$real = array( (int) $variant['width'], (int) $variant['height'] );
					}
				}
				$images[] = array(
					'src'    => (string) $sub['url'],
					'width'  => $real[0],
					'height' => $real[1],
				);
			} elseif ( is_array( $meta ) && isset( $meta['width'], $meta['height'] ) ) {
				$images[] = array(
					'src'    => (string) wp_get_attachment_url( $id ),
					'width'  => (int) $meta['width'],
					'height' => (int) $meta['height'],
				);
			}
		}
		return $images;
	}

	/**
	 * Farbpalette des aktiven Themes (Theme-Presets vor eigenen und Standardfarben).
	 *
	 * @return list<array<string,mixed>>
	 */
	private static function palette(): array {
		$settings = function_exists( 'wp_get_global_settings' ) ? wp_get_global_settings( array( 'color', 'palette' ) ) : array();
		$palette  = array();
		foreach ( array( 'theme', 'custom', 'default' ) as $origin ) {
			if ( isset( $settings[ $origin ] ) && is_array( $settings[ $origin ] ) ) {
				$palette = array_merge( $palette, array_values( $settings[ $origin ] ) );
			}
		}
		return $palette;
	}

	/**
	 * Pfad der Startadresse, z. B. / oder /liga/.
	 */
	private static function base(): string {
		return Manifest::base( (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH ) );
	}

	/**
	 * Pfad relativ zur Startadresse, z. B. /sw.js.
	 */
	private static function path( string $relative ): string {
		return self::base() . ltrim( $relative, '/' );
	}

	private static function version( string $file ): string {
		$path = TTLEAGUE_DIR . $file;
		return is_readable( $path ) ? TTLEAGUE_VERSION . '.' . (string) filemtime( $path ) : TTLEAGUE_VERSION;
	}
}
