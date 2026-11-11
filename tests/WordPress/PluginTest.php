<?php
/**
 * Das Plugin in WordPress: geladen, Schema über dbDelta, Rolle der TK.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Tests\WordPress;

use TTLeague\Access\Capabilities;
use TTLeague\Access\Roles;
use TTLeague\Infrastructure\Database\Installer;
use TTLeague\Infrastructure\Database\Schema;

/**
 * Der Bootstrap installiert wie die Aktivierung (Installer::activate()).
 */
final class PluginTest extends TestCase {

	public function test_plugin_is_loaded_and_booted(): void {
		self::assertTrue( defined( 'TTLEAGUE_VERSION' ) );
		self::assertSame( 10, has_action( 'plugins_loaded', array( \TTLeague\Plugin::class, 'boot' ) ) );
		self::assertSame( 10, has_filter( 'map_meta_cap', array( Capabilities::class, 'map' ) ) );
		self::assertSame( Schema::VERSION, get_option( Installer::OPTION ) );
		self::assertSame( '1', get_option( Installer::USER_UNIQUE_OPTION ), 'person.user_id ist eindeutig' );
	}

	public function test_a_schema_upgrade_invalidates_cached_read_models(): void {
		$before = \TTLeague\Infrastructure\Cache\DataVersion::get();

		Installer::maybe_upgrade();
		self::assertSame( $before, \TTLeague\Infrastructure\Cache\DataVersion::get(), 'ohne neue Schemaversion bleibt der Cache gültig' );

		update_option( Installer::OPTION, '2026010101', false );
		Installer::maybe_upgrade();

		self::assertSame( Schema::VERSION, get_option( Installer::OPTION ) );
		self::assertSame( $before + 1, \TTLeague\Infrastructure\Cache\DataVersion::get() );
	}

	public function test_all_tables_exist_with_a_transactional_engine(): void {
		global $wpdb;
		$tables = array_map( static fn( string $name ): string => Schema::table( $name, $wpdb->prefix ), Schema::TABLES );

		$engines = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT TABLE_NAME, ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN (' . implode( ',', array_fill( 0, count( $tables ), '%s' ) ) . ')',
				...$tables
			),
			OBJECT_K
		);

		self::assertSame( count( $tables ), count( $engines ) );
		foreach ( $tables as $table ) {
			self::assertSame( 'InnoDB', $engines[ $table ]->ENGINE ?? null, $table ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Spaltenname.
		}
	}

	public function test_schema_is_stable_under_dbdelta(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		foreach ( Schema::statements( $wpdb->prefix, $wpdb->get_charset_collate() ) as $sql ) {
			// Nur vergleichen (zweites Argument false): Eine Änderung wäre DDL und beendete die Testtransaktion.
			self::assertSame( array(), dbDelta( $sql, false ), 'Ein Update würde die Tabelle ändern: ' . strtok( $sql, '(' ) );
		}
	}

	public function test_tk_role_and_administrator_capability(): void {
		$role = get_role( Roles::ROLE );

		self::assertInstanceOf( \WP_Role::class, $role );
		self::assertSame(
			array(
				'read'           => true,
				'upload_files'   => true,
				Capabilities::TK => true,
			),
			$role->capabilities
		);
		self::assertTrue( get_role( 'administrator' )->has_cap( Capabilities::TK ) );
		self::assertFalse( get_role( 'editor' )->has_cap( Capabilities::TK ) );
		self::assertSame( Roles::VERSION, get_option( Roles::OPTION ) );
	}
}
