<?php
/**
 * Web-App-Manifest der installierbaren Web-App.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Pwa;

/**
 * Reine Klasse ohne WordPress. Pwa sammelt die Angaben der Website (Seitentitel, Sprache,
 * Farben aus den Presets des Themes, Site-Icon) und übergibt sie an build().
 *
 * Icons: Mit Site-Icon dessen Bilder für 192 und 512 px mit den tatsächlichen Abmessungen
 * (Zweck «any»; ein Site-Icon ist nicht für das Beschneiden auf Kreis oder Tropfen gestaltet).
 * Ohne Site-Icon die mitgelieferten, neutralen Icons aus assets/pwa/ in 192 und 512 px,
 * je «any» und «maskable».
 */
final class Manifest {

	public const THEME_COLOR      = '#0b4a9e';
	public const BACKGROUND_COLOR = '#ffffff';
	public const TEXT_COLOR       = '#13213a';

	/**
	 * Presets, aus denen die Farben stammen (erster gültiger Wert gewinnt).
	 */
	public const THEME_SLUGS      = array( 'accent-1', 'primary', 'accent' );
	public const BACKGROUND_SLUGS = array( 'base', 'background', 'white' );
	public const TEXT_SLUGS       = array( 'contrast', 'foreground', 'black' );

	public const SHORT_NAME_LENGTH = 12;

	/**
	 * Manifest als Array (für JSON).
	 *
	 * @param array{
	 *   name:string,
	 *   short_name?:string,
	 *   description?:string,
	 *   lang?:string,
	 *   dir?:string,
	 *   base?:string,
	 *   theme_color?:string,
	 *   background_color?:string,
	 *   icons?:list<array<string,string>>
	 * } $site Angaben der Website; base ist der Pfad der Startadresse, z. B. / oder /liga/.
	 * @return array<string,mixed>
	 */
	public static function build( array $site ): array {
		$base = self::base( (string) ( $site['base'] ?? '/' ) );
		$name = trim( (string) preg_replace( '/\s+/u', ' ', $site['name'] ) );
		if ( '' === $name ) {
			$name = 'Spielbetrieb';
		}
		$short = trim( (string) ( $site['short_name'] ?? '' ) );

		$manifest = array(
			'name'             => $name,
			'short_name'       => '' === $short ? self::short_name( $name ) : $short,
			'description'      => trim( (string) ( $site['description'] ?? '' ) ),
			'lang'             => (string) ( $site['lang'] ?? 'de-CH' ),
			'dir'              => 'rtl' === ( $site['dir'] ?? 'ltr' ) ? 'rtl' : 'ltr',
			'id'               => $base,
			'start_url'        => $base . '?quelle=app',
			'scope'            => $base,
			'display'          => 'standalone',
			'background_color' => self::hex( (string) ( $site['background_color'] ?? '' ) ) ?? self::BACKGROUND_COLOR,
			'theme_color'      => self::hex( (string) ( $site['theme_color'] ?? '' ) ) ?? self::THEME_COLOR,
			'categories'       => array( 'sports' ),
			'icons'            => $site['icons'] ?? array(),
		);
		if ( '' === $manifest['description'] ) {
			unset( $manifest['description'] );
		}
		return $manifest;
	}

	/**
	 * Kurzname für den Startbildschirm: ganze Wörter vom Anfang, höchstens zwölf Zeichen.
	 */
	public static function short_name( string $name, int $max = self::SHORT_NAME_LENGTH ): string {
		$name = trim( (string) preg_replace( '/\s+/u', ' ', $name ) );
		if ( mb_strlen( $name ) <= $max ) {
			return $name;
		}
		$short = '';
		foreach ( explode( ' ', $name ) as $word ) {
			$next = '' === $short ? $word : $short . ' ' . $word;
			if ( mb_strlen( $next ) > $max ) {
				break;
			}
			$short = $next;
		}
		return '' === $short ? mb_substr( $name, 0, $max ) : $short;
	}

	/**
	 * Farbe aus einer Palette (Liste von slug/color); nur Hex-Werte, sonst der Rückfallwert.
	 *
	 * @param array<int,mixed> $palette  Paletteneinträge, z. B. aus wp_get_global_settings().
	 * @param list<string>     $slugs    Gesuchte Slugs in Reihenfolge.
	 * @param string           $fallback Rückfallwert.
	 */
	public static function color( array $palette, array $slugs, string $fallback ): string {
		$colors = array();
		foreach ( $palette as $entry ) {
			if ( is_array( $entry ) && isset( $entry['slug'], $entry['color'] ) && is_string( $entry['color'] ) && ! isset( $colors[ (string) $entry['slug'] ] ) ) {
				$colors[ (string) $entry['slug'] ] = $entry['color'];
			}
		}
		foreach ( $slugs as $slug ) {
			$hex = self::hex( $colors[ $slug ] ?? '' );
			if ( null !== $hex ) {
				return $hex;
			}
		}
		return $fallback;
	}

	/**
	 * Hex-Farbe in Kleinbuchstaben (#abc oder #aabbcc), sonst null.
	 */
	public static function hex( string $color ): ?string {
		$color = strtolower( trim( $color ) );
		return 1 === preg_match( '/^#(?:[0-9a-f]{3}|[0-9a-f]{6})$/', $color ) ? $color : null;
	}

	/**
	 * Icons aus dem Site-Icon oder die mitgelieferten aus assets/pwa/.
	 *
	 * @param list<array{src:string,width:int,height:int}> $site_icon Bilder des Site-Icons mit ihren
	 *                                                               tatsächlichen Abmessungen (leer ohne Site-Icon).
	 * @param string                                       $assets    Adresse des Ordners assets/pwa/.
	 * @return list<array{src:string,sizes:string,type:string,purpose:string}>
	 */
	public static function icons( array $site_icon, string $assets ): array {
		$icons = array();
		foreach ( $site_icon as $image ) {
			$src = (string) ( $image['src'] ?? '' );
			if ( '' === $src || (int) ( $image['width'] ?? 0 ) <= 0 || isset( $icons[ $src ] ) ) {
				continue;
			}
			$icons[ $src ] = self::icon( $src, (int) $image['width'] . 'x' . (int) ( $image['height'] ?? $image['width'] ), 'any' );
		}
		if ( array() !== $icons ) {
			return array_values( $icons );
		}
		$assets   = rtrim( $assets, '/' ) . '/';
		$variants = array(
			'any'      => 'icon-',
			'maskable' => 'icon-maskable-',
		);
		foreach ( $variants as $purpose => $prefix ) {
			foreach ( array( 192, 512 ) as $size ) {
				$icons[] = self::icon( $assets . $prefix . $size . '.png', $size . 'x' . $size, $purpose );
			}
		}
		return $icons;
	}

	/**
	 * Pfad mit Schrägstrich am Anfang und Ende, z. B. /liga/.
	 */
	public static function base( string $path ): string {
		$path = trim( $path, '/' );
		return '' === $path ? '/' : '/' . $path . '/';
	}

	/**
	 * @return array{src:string,sizes:string,type:string,purpose:string}
	 */
	private static function icon( string $src, string $sizes, string $purpose ): array {
		return array(
			'src'     => $src,
			'sizes'   => $sizes,
			'type'    => self::mime( $src ),
			'purpose' => $purpose,
		);
	}

	/**
	 * Bildtyp aus der Dateiendung.
	 */
	private static function mime( string $url ): string {
		$path = (string) preg_replace( '/[?#].*$/', '', $url );
		$ext  = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );
		$map  = array(
			'jpg'  => 'image/jpeg',
			'jpeg' => 'image/jpeg',
			'gif'  => 'image/gif',
			'webp' => 'image/webp',
			'svg'  => 'image/svg+xml',
			'ico'  => 'image/x-icon',
		);
		return $map[ $ext ] ?? 'image/png';
	}
}
