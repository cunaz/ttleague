<?php
/**
 * Tests des Service-Worker-Skripts: Konfiguration, Version, ausgeschlossene Pfade.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Tests\Unit\Pwa;

use PHPUnit\Framework\TestCase;
use TTLeague\Pwa\ServiceWorker;

final class ServiceWorkerTest extends TestCase {

	/**
	 * @return array<string,mixed>
	 */
	private static function config( string $base = '/' ): array {
		return ServiceWorker::config(
			$base,
			$base . '?quelle=app',
			ServiceWorker::NEVER_CACHE,
			array(
				'lang'    => 'de-CH',
				'site'    => 'Liga «Seefeld»',
				'title'   => 'Offline',
				'heading' => 'Keine Verbindung',
				'text'    => 'Text',
				'retry'   => 'Erneut versuchen',
				'home'    => 'Zur Startseite',
			),
			array(
				'background' => '#ffffff',
				'text'       => '#13213a',
				'accent'     => '#0b4a9e',
			)
		);
	}

	public function test_private_and_technical_paths_are_never_cached(): void {
		foreach ( array( 'wp-admin', 'wp-login.php', 'wp-json', 'anmelden', 'mein-bereich', 'erfassen' ) as $path ) {
			self::assertContains( $path, ServiceWorker::NEVER_CACHE );
		}
		self::assertSame( array( 'mein-bereich', 'wp-admin', 'konto/daten' ), ServiceWorker::never( array( '/mein-bereich/', 'wp-admin', '', ' / ', 'mein-bereich', '/konto/daten/' ) ) );
	}

	public function test_config_and_cache_prefix_depend_on_the_base_path(): void {
		$root = self::config();
		self::assertSame( '/', $root['base'] );
		self::assertSame( 'ttleague:/:', $root['prefix'] );
		self::assertSame( '/?quelle=app', $root['start'] );
		self::assertSame( ServiceWorker::MAX_PAGES, $root['maxPages'] );

		$sub = self::config( '/liga' );
		self::assertSame( '/liga/', $sub['base'] );
		self::assertSame( 'ttleague:/liga/:', $sub['prefix'], 'eigene Caches pro Website auf derselben Domain' );
		self::assertStringStartsNotWith( $root['prefix'], $sub['prefix'] );
	}

	public function test_script_starts_with_the_configuration(): void {
		$template = "self.addEventListener( 'fetch', () => {} );\n";
		$script   = ServiceWorker::script( $template, self::config() );
		self::assertStringStartsWith( '/* ttleague:', $script );
		self::assertStringEndsWith( $template, $script );
		self::assertSame( 1, preg_match( '/^const CONFIG = (\{.*\});$/m', $script, $m ) );
		$config = json_decode( $m[1], true );
		self::assertIsArray( $config );
		self::assertMatchesRegularExpression( '/^[0-9a-f]{12}$/', $config['version'] );
		self::assertSame( 'Liga «Seefeld»', $config['offline']['site'] );
		self::assertSame( '/', $config['base'] );
		self::assertContains( 'mein-bereich', $config['never'] );
	}

	public function test_version_changes_with_template_and_configuration(): void {
		$config  = self::config();
		$version = ServiceWorker::version( 'a', $config );
		self::assertSame( $version, ServiceWorker::version( 'a', $config + array( 'version' => 'alt' ) ), 'die Version selbst zählt nicht' );
		self::assertNotSame( $version, ServiceWorker::version( 'b', $config ) );
		$config['offline']['heading'] = 'Ohne Netz';
		self::assertNotSame( $version, ServiceWorker::version( 'a', $config ) );
	}

	public function test_template_uses_the_expected_strategies(): void {
		$template = (string) file_get_contents( dirname( __DIR__, 3 ) . '/assets/pwa/sw.js' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- lokale Datei im Test.
		foreach ( array( 'CONFIG.never', 'skipWaiting', 'clients.claim', 'caches.delete', "'GET' !== request.method", 'no-store', 'private', 'stale', 'offlinePage' ) as $needle ) {
			self::assertStringContainsString( $needle, $template );
		}
		foreach ( array( "addEventListener( 'push'", "addEventListener( 'notificationclick'", 'showNotification', 'openWindow', 'self.location.origin' ) as $needle ) {
			self::assertStringContainsString( $needle, $template, 'Push anzeigen und Klick nur auf gleiche Herkunft' );
		}
	}
}
