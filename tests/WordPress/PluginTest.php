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
use TTLeague\Infrastructure\Cache\DataVersion;
use TTLeague\Infrastructure\Database\Installer;
use TTLeague\Infrastructure\Database\Schema;
use TTLeague\Plugin;
use TTLeague\Season\SeasonActions;

/**
 * Der Bootstrap installiert wie die Aktivierung (Installer::activate()).
 */
final class PluginTest extends TestCase {

	public function test_plugin_is_loaded_and_booted(): void {
		self::assertTrue( defined( 'TTLEAGUE_VERSION' ) );
		self::assertSame( 10, has_action( 'after_setup_theme', array( \TTLeague\Plugin::class, 'boot' ) ) );
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

	public function test_an_incomplete_upgrade_keeps_the_old_version_until_it_can_retry(): void {
		global $wpdb;
		$table = $this->db->table( 'rate_limit' );
		// Die temporäre Tabelle verdeckt das richtige Schema nur für diese Verbindung.
		$this->db->query( "CREATE TEMPORARY TABLE {$table} (bucket_key char(64) NOT NULL, expires_at datetime NOT NULL, PRIMARY KEY (bucket_key))" );
		$deny   = static function ( string $query ) use ( $table ): string {
			if ( str_starts_with( $query, "ALTER TABLE {$table} ADD COLUMN" ) ) {
				return 'SELECT ttl_missing_column';
			}
			// Ein erfolgreicher Folgeauftrag überschreibt den Fehler der ersten Anweisung.
			return str_starts_with( $query, "ALTER TABLE {$table} ADD KEY" ) ? 'SELECT 1' : $query;
		};
		$before = DataVersion::get();
		update_option( Installer::OPTION, '2026010101', false );
		$suppress = $wpdb->suppress_errors();
		add_filter( 'query', $deny );
		try {
			self::assertFalse( Installer::maybe_upgrade() );
			self::assertSame( '2026010101', get_option( Installer::OPTION ) );
			self::assertSame( $before, DataVersion::get() );
			self::assertSame( Schema::VERSION, get_transient( Installer::RETRY_TRANSIENT ) );
		} finally {
			remove_filter( 'query', $deny );
			$wpdb->suppress_errors( $suppress );
			$this->db->query( "DROP TEMPORARY TABLE {$table}" );
		}
		delete_transient( Installer::RETRY_TRANSIENT );
		Installer::maybe_upgrade();
		self::assertSame( Schema::VERSION, get_option( Installer::OPTION ) );
		self::assertSame( $before + 1, DataVersion::get() );
	}

	public function test_boot_with_outstanding_migration_pauses_league_actions_and_notifies_the_tk(): void {
		update_option( Installer::OPTION, '2026010101', false );
		set_transient( Installer::RETRY_TRANSIENT, Schema::VERSION, MINUTE_IN_SECONDS );
		$action  = 'admin_post_' . SeasonActions::CLUB_ADD;
		$handler = array( SeasonActions::class, 'club_add' );
		remove_action( $action, $handler );
		// Einen neuen Request simulieren. WordPress hat das Plugin für die Tests bereits gestartet.
		$booted = new \ReflectionProperty( Plugin::class, 'booted' );
		$booted->setValue( null, false );
		try {
			Plugin::boot();
			self::assertFalse( has_action( $action, $handler ) );
			self::assertSame( 10, has_action( 'admin_notices', array( Plugin::class, 'schema_notice' ) ) );
			self::assertSame( 10, has_action( 'deactivate_' . plugin_basename( TTLEAGUE_FILE ), array( Plugin::class, 'deactivate' ) ) );
			wp_set_current_user( $this->account( 'ttl_tk', 'tk@example.invalid' ) );
			ob_start();
			Plugin::schema_notice();
			self::assertStringContainsString( 'Datenbank-Update unvollständig', (string) ob_get_clean() );
			wp_set_current_user( $this->account( 'subscriber', 'spieler@example.invalid' ) );
			ob_start();
			Plugin::schema_notice();
			self::assertSame( '', ob_get_clean() );
		} finally {
			add_action( $action, $handler );
			$booted->setValue( null, true );
		}
	}

	public function test_old_duplicate_person_links_allow_the_rest_of_the_schema_to_upgrade(): void {
		global $wpdb;
		$table = $this->db->table( 'person' );
		$sql   = Schema::statements( $wpdb->prefix, $wpdb->get_charset_collate(), false )[ $table ];
		$this->db->query( str_replace( 'CREATE TABLE', 'CREATE TEMPORARY TABLE', $sql ) );
		// information_schema meldet nur die dauerhafte Tabelle. Den Index der temporären Tabelle abbilden.
		$index = static fn( string $query ): string => str_contains( $query, 'information_schema.STATISTICS' ) && str_contains( $query, 'NON_UNIQUE = 0' ) ? 'SELECT 0' : $query;
		add_filter( 'query', $index );
		try {
			$this->db->query( "INSERT INTO {$table} (user_id, last_name, created_at, updated_at) VALUES (77, 'A', NOW(), NOW()), (77, 'B', NOW(), NOW())" );
			update_option( Installer::OPTION, '2026010101', false );
			Installer::maybe_upgrade();
			self::assertSame( Schema::VERSION, get_option( Installer::OPTION ) );
			self::assertSame( '0', get_option( Installer::USER_UNIQUE_OPTION ) );
			self::assertSame( '2', $this->db->get_var( "SELECT COUNT(*) FROM {$table} WHERE user_id = 77" ) );
		} finally {
			remove_filter( 'query', $index );
			$this->db->query( "DROP TEMPORARY TABLE {$table}" );
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
