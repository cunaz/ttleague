<?php
/**
 * Anlegen und Nachführen des Schemas über dbDelta().
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Infrastructure\Database;

final class Installer {

	public const OPTION = 'ttleague_db_version';

	/**
	 * Aktivierungshook: Tabellen und Rollen anlegen.
	 */
	public static function activate(): void {
		self::install();
		\TTLeague\Access\Roles::install();
	}

	/**
	 * Bei jedem Laden prüfen, ob eine neue Schemaversion vorliegt.
	 */
	public static function maybe_upgrade(): void {
		if ( get_option( self::OPTION ) !== Schema::VERSION ) {
			self::install();
		}
	}

	public static function install(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		self::make_person_user_unique();
		foreach ( Schema::statements( $wpdb->prefix, $wpdb->get_charset_collate() ) as $sql ) {
			dbDelta( $sql );
		}
		update_option( self::OPTION, Schema::VERSION, false );
	}

	/**
	 * Ein Konto gehört höchstens einer Person: Ältere Installationen haben auf person.user_id
	 * einen gewöhnlichen Index. dbDelta ändert bestehende Indizes nicht, deshalb wird er hier
	 * entfernt, damit dbDelta ihn als UNIQUE neu anlegt. Gibt es doppelte Verknüpfungen, bleibt
	 * der alte Index stehen und eine Meldung landet im Fehlerprotokoll; die Doppelungen löst
	 * ein Administrator mit «wp ttleague account unlink» auf.
	 */
	private static function make_person_user_unique(): void {
		global $wpdb;
		$table = Schema::table( 'person', $wpdb->prefix );
		if ( $table !== $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) ) {
			return;
		}
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- Tabellenname aus Schema, Migration beim Update.
		$non_unique = $wpdb->get_var( "SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '{$table}' AND INDEX_NAME = 'user_id' AND NON_UNIQUE = 1" );
		if ( '0' === (string) $non_unique || null === $non_unique ) {
			return;
		}
		$duplicates = $wpdb->get_col( "SELECT user_id FROM `{$table}` WHERE user_id IS NOT NULL GROUP BY user_id HAVING COUNT(*) > 1" );
		if ( array() !== $duplicates ) {
			error_log( 'ttleague: Konten mit mehreren Personen verknüpft (user_id ' . implode( ', ', array_map( 'intval', $duplicates ) ) . '). Index person.user_id bleibt vorerst ohne UNIQUE.' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Hinweis für die Verwaltung.
			return;
		}
		$wpdb->query( "ALTER TABLE `{$table}` DROP INDEX `user_id`" );
		// phpcs:enable
	}

	/**
	 * Nur für die Deinstallation mit ausdrücklicher Option.
	 */
	public static function drop_all(): void {
		global $wpdb;
		foreach ( array_reverse( Schema::TABLES ) as $name ) {
			$table = Schema::table( $name, $wpdb->prefix );
			$wpdb->query( "DROP TABLE IF EXISTS `{$table}`" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}
	}
}
