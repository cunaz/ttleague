<?php
/**
 * Tests des Web-App-Manifests: Namen, Adressen, Farben aus der Palette, Icons.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Tests\Unit\Pwa;

use PHPUnit\Framework\TestCase;
use TTLeague\Pwa\Manifest;
use TTLeague\Pwa\Pwa;

final class ManifestTest extends TestCase {

	private const ASSETS = 'https://example.org/wp-content/plugins/ttleague/assets/pwa/';

	public function test_manifest_for_a_site_in_the_root(): void {
		$manifest = Manifest::build(
			array(
				'name'             => 'Tischtennisliga   Seefeld',
				'description'      => 'Resultate und Spielpläne',
				'lang'             => 'de-CH',
				'base'             => '/',
				'theme_color'      => '#0B4A9E',
				'background_color' => '#ffffff',
				'icons'            => Manifest::icons( array(), self::ASSETS ),
			)
		);
		self::assertSame( 'Tischtennisliga Seefeld', $manifest['name'] );
		self::assertSame( 'Tischtennisl', $manifest['short_name'], 'kein Wort passt: abgeschnitten' );
		self::assertSame( 'Resultate und Spielpläne', $manifest['description'] );
		self::assertSame( 'de-CH', $manifest['lang'] );
		self::assertSame( 'ltr', $manifest['dir'] );
		self::assertSame( '/', $manifest['id'] );
		self::assertSame( '/?quelle=app', $manifest['start_url'] );
		self::assertSame( '/', $manifest['scope'] );
		self::assertSame( 'standalone', $manifest['display'] );
		self::assertSame( '#0b4a9e', $manifest['theme_color'] );
		self::assertSame( '#ffffff', $manifest['background_color'] );
		self::assertCount( 4, $manifest['icons'] );

		$json = json_encode( $manifest, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Test ohne WordPress.
		self::assertIsString( $json );
		self::assertStringContainsString( '"start_url":"/?quelle=app"', $json );
	}

	public function test_manifest_in_a_subdirectory_with_fallbacks(): void {
		$manifest = Manifest::build(
			array(
				'name'        => 'TTC Seefeld',
				'short_name'  => 'Seefeld',
				'base'        => 'liga',
				'theme_color' => 'color-mix(in srgb, red 50%, blue)',
				'dir'         => 'rtl',
			)
		);
		self::assertSame( 'Seefeld', $manifest['short_name'] );
		self::assertSame( '/liga/', $manifest['scope'] );
		self::assertSame( '/liga/?quelle=app', $manifest['start_url'] );
		self::assertSame( '/liga/', $manifest['id'] );
		self::assertSame( 'rtl', $manifest['dir'] );
		self::assertSame( Manifest::THEME_COLOR, $manifest['theme_color'], 'keine Hex-Farbe: Rückfallwert' );
		self::assertSame( Manifest::BACKGROUND_COLOR, $manifest['background_color'] );
		self::assertArrayNotHasKey( 'description', $manifest, 'leere Beschreibung entfällt' );
		self::assertSame( 'Spielbetrieb', Manifest::build( array( 'name' => '  ' ) )['name'] );
	}

	public function test_short_names_keep_whole_words(): void {
		self::assertSame( 'Freizeitliga', Manifest::short_name( 'Freizeitliga Tischtennis Seefeld' ) );
		self::assertSame( 'TTC Seefeld', Manifest::short_name( 'TTC Seefeld Liga' ) );
		self::assertSame( 'Liga Zürich', Manifest::short_name( 'Liga Zürich' ) );
		self::assertSame( 'Mannschaftsm', Manifest::short_name( 'Mannschaftsmeisterschaft' ) );
		self::assertSame( 'TT Liga', Manifest::short_name( 'TT Liga Seefeld', 8 ) );
	}

	public function test_colors_come_from_the_theme_palette(): void {
		$palette = array(
			array(
				'slug'  => 'base',
				'color' => '#FFFFFF',
			),
			array(
				'slug'  => 'contrast',
				'color' => '#13213A',
			),
			array(
				'slug'  => 'accent-1',
				'color' => 'var(--wp--preset--color--contrast)',
			),
			array(
				'slug'  => 'primary',
				'color' => '#C2410C',
			),
			array(
				'slug'  => 'base',
				'color' => '#000000',
			),
			'kaputt',
		);
		self::assertSame( '#c2410c', Manifest::color( $palette, Manifest::THEME_SLUGS, '#123456' ), 'accent-1 ist keine Hex-Farbe: primary' );
		self::assertSame( '#ffffff', Manifest::color( $palette, Manifest::BACKGROUND_SLUGS, '#123456' ), 'erster Eintrag pro Slug gewinnt' );
		self::assertSame( '#13213a', Manifest::color( $palette, Manifest::TEXT_SLUGS, '#123456' ) );
		self::assertSame( '#123456', Manifest::color( array(), Manifest::THEME_SLUGS, '#123456' ) );
		self::assertSame( '#abc', Manifest::hex( ' #ABC ' ) );
		self::assertNull( Manifest::hex( '#abcd' ) );
		self::assertNull( Manifest::hex( 'blue' ) );
	}

	public function test_bundled_icons_without_site_icon(): void {
		$icons = Manifest::icons( array(), rtrim( self::ASSETS, '/' ) );
		self::assertSame(
			array(
				array(
					'src'     => self::ASSETS . 'icon-192.png',
					'sizes'   => '192x192',
					'type'    => 'image/png',
					'purpose' => 'any',
				),
				array(
					'src'     => self::ASSETS . 'icon-512.png',
					'sizes'   => '512x512',
					'type'    => 'image/png',
					'purpose' => 'any',
				),
				array(
					'src'     => self::ASSETS . 'icon-maskable-192.png',
					'sizes'   => '192x192',
					'type'    => 'image/png',
					'purpose' => 'maskable',
				),
				array(
					'src'     => self::ASSETS . 'icon-maskable-512.png',
					'sizes'   => '512x512',
					'type'    => 'image/png',
					'purpose' => 'maskable',
				),
			),
			$icons
		);
		$dir   = dirname( __DIR__, 3 ) . '/assets/pwa/';
		$sizes = array(
			'icon-192.png'          => 192,
			'icon-512.png'          => 512,
			'icon-maskable-192.png' => 192,
			'icon-maskable-512.png' => 512,
			'apple-touch-icon.png'  => 180,
		);
		foreach ( $sizes as $file => $size ) {
			$info = getimagesize( $dir . $file );
			self::assertIsArray( $info, $file );
			self::assertSame( array( $size, $size ), array( $info[0], $info[1] ), $file );
			self::assertSame( 'image/png', $info['mime'], $file );
		}
	}

	public function test_site_icon_replaces_the_bundled_icons(): void {
		$icons = Manifest::icons(
			array(
				array(
					'src'    => 'https://example.org/wp-content/uploads/2026/08/logo-192x192.jpg?ver=2',
					'width'  => 192,
					'height' => 192,
				),
				array(
					'src'    => 'https://example.org/wp-content/uploads/2026/08/logo.png',
					'width'  => 512,
					'height' => 512,
				),
			),
			self::ASSETS
		);
		self::assertCount( 2, $icons );
		self::assertSame( 'image/jpeg', $icons[0]['type'] );
		self::assertSame( '192x192', $icons[0]['sizes'] );
		self::assertSame( 'image/png', $icons[1]['type'] );
		self::assertSame( '512x512', $icons[1]['sizes'] );
		self::assertSame( array( 'any', 'any' ), array_column( $icons, 'purpose' ) );

		// Ohne zugeschnittene Grössen liefert WordPress das nächstgrössere Bild: tatsächliche Abmessungen, ohne Doppel.
		$uncropped = Manifest::icons(
			array(
				array(
					'src'    => 'https://example.org/wp-content/uploads/logo-300x300.png',
					'width'  => 300,
					'height' => 300,
				),
				array(
					'src'    => 'https://example.org/wp-content/uploads/logo-300x300.png',
					'width'  => 300,
					'height' => 300,
				),
			),
			self::ASSETS
		);
		self::assertSame( array( '300x300' ), array_column( $uncropped, 'sizes' ) );

		self::assertCount( 4, Manifest::icons( array( array( 'src' => '' ) ), self::ASSETS ), 'unbrauchbares Site-Icon' );
	}

	public function test_rewrite_rules(): void {
		$rules = Pwa::rules();
		self::assertSame( 'index.php?ttl_pwa=manifest', $rules['^manifest\.webmanifest$'] );
		self::assertSame( 'index.php?ttl_pwa=sw', $rules['^sw\.js$'] );
		foreach ( array_keys( $rules ) as $regex ) {
			self::assertSame( 0, preg_match( '#' . $regex . '#', 'assets/sw.js' ) );
		}
		self::assertSame( 1, preg_match( '#' . array_keys( $rules )[1] . '#', 'sw.js' ) );
	}
}
