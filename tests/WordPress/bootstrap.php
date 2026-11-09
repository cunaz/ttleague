<?php
/**
 * PHPUnit-Bootstrap der WordPress-Tests: lädt die WordPress-Testbibliothek (wp-phpunit),
 * bindet das Plugin vor plugins_loaded ein und legt Schema und Rolle wie bei der
 * Aktivierung an. Konfiguration in wp-tests-config.php.
 *
 * @package TTLeague
 */

declare(strict_types=1);

use TTLeague\Infrastructure\Database\Installer;

$ttleague_root = dirname( __DIR__, 2 );
require $ttleague_root . '/vendor/autoload.php';

// Pfad der Testbibliothek, gesetzt von wp-phpunit beim Laden des Autoloaders.
$ttleague_tests_dir = (string) getenv( 'WP_PHPUNIT__DIR' );
if ( ! is_dir( $ttleague_tests_dir . '/includes' ) ) {
	echo 'WordPress-Testbibliothek nicht gefunden. Bitte zuerst composer install ausführen.' . PHP_EOL;
	exit( 1 );
}

// Die Testbibliothek findet die Konfiguration über die Umgebung, auch im Installationsprozess.
putenv( 'WP_PHPUNIT__TESTS_CONFIG=' . __DIR__ . '/wp-tests-config.php' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_putenv -- Schnittstelle von wp-phpunit.
require_once $ttleague_tests_dir . '/includes/functions.php';

tests_add_filter(
	'muplugins_loaded',
	static function () use ( $ttleague_root ): void {
		// Die Testsuite installiert nur die WordPress-Tabellen neu. Die Tabellen des Plugins
		// aus dem letzten Lauf entfernen, damit jeder Lauf mit dem aktuellen Schema beginnt.
		Installer::drop_all();
		require $ttleague_root . '/ttleague.php';
	}
);

// Schema und Rolle wie bei der Aktivierung, vor init. Nicht früher als after_setup_theme:
// Roles::install() übersetzt den Namen der Rolle, und Übersetzungen dürfen erst ab dann laden.
tests_add_filter( 'after_setup_theme', array( Installer::class, 'activate' ) );

require $ttleague_tests_dir . '/includes/bootstrap.php';
