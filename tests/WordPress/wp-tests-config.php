<?php
/**
 * Konfiguration der WordPress-Testumgebung (geladen über WP_PHPUNIT__TESTS_CONFIG).
 *
 * Verbindung über Umgebungsvariablen (Standardwerte für die lokale Entwicklung):
 * TTLEAGUE_WP_TEST_DB_NAME (ttleague_wptest)
 * TTLEAGUE_WP_TEST_DB_USER (ttleague)
 * TTLEAGUE_WP_TEST_DB_PASS (ttleague)
 * TTLEAGUE_WP_TEST_DB_HOST (127.0.0.1, auch mit Port: 127.0.0.1:3306)
 *
 * Die Testsuite löscht und installiert die WordPress-Tabellen (Präfix wptests_) bei jedem
 * Lauf; deshalb eine eigene Datenbank, nie die einer Website.
 *
 * @package TTLeague
 */

declare(strict_types=1);

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals -- Konstanten und Variablen, die WordPress und die Testbibliothek erwarten.

$ttleague_env = static function ( string $name, string $fallback ): string {
	$value = getenv( $name );
	return false === $value || '' === $value ? $fallback : $value;
};

define( 'ABSPATH', dirname( __DIR__, 2 ) . '/vendor/roots/wordpress-full/' );

define( 'DB_NAME', $ttleague_env( 'TTLEAGUE_WP_TEST_DB_NAME', 'ttleague_wptest' ) );
define( 'DB_USER', $ttleague_env( 'TTLEAGUE_WP_TEST_DB_USER', 'ttleague' ) );
define( 'DB_PASSWORD', false === getenv( 'TTLEAGUE_WP_TEST_DB_PASS' ) ? 'ttleague' : (string) getenv( 'TTLEAGUE_WP_TEST_DB_PASS' ) );
define( 'DB_HOST', $ttleague_env( 'TTLEAGUE_WP_TEST_DB_HOST', '127.0.0.1' ) );
define( 'DB_CHARSET', 'utf8mb4' );
define( 'DB_COLLATE', '' );

define( 'WP_TESTS_DOMAIN', 'example.org' );
define( 'WP_TESTS_EMAIL', 'admin@example.invalid' );
define( 'WP_TESTS_TITLE', 'ttleague' );
define( 'WP_PHP_BINARY', PHP_BINARY );
define( 'WPLANG', '' );
define( 'WP_DEBUG', true );

unset( $ttleague_env );
