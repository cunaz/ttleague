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
			return;
		}
		// Fehlt der UNIQUE-Index auf person.user_id noch, höchstens stündlich erneut versuchen.
		if ( false === get_transient( 'ttleague_person_user_index' ) ) {
			set_transient( 'ttleague_person_user_index', 1, HOUR_IN_SECONDS );
			self::ensure_unique_person_user();
		}
	}

	public static function install(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		// Vor dbDelta umwandeln, damit dbDelta den gleichnamigen Index nicht ein zweites Mal anlegen will.
		$person = Schema::table( 'person', $wpdb->prefix );
		if ( $person === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $person ) ) ) {
			self::ensure_unique_person_user();
		}
		foreach ( Schema::statements( $wpdb->prefix, $wpdb->get_charset_collate() ) as $sql ) {
			dbDelta( $sql );
		}
		update_option( self::OPTION, Schema::VERSION, false );
	}

	/**
	 * Ältere Installationen: Index person.user_id in UNIQUE umwandeln. Bestehen doppelte
	 * Verknüpfungen, bleibt der Index vorerst, Verknüpfungen sind gesperrt, und der Versuch
	 * wird stündlich (und nach «wp ttleague account unlink») wiederholt.
	 *
	 * @return bool true, wenn der Index eindeutig ist.
	 */
	public static function ensure_unique_person_user(): bool {
		$index = new PersonUserIndex( WpdbDb::from_global() );
		if ( $index->migrate() ) {
			return true;
		}
		error_log( 'ttleague: Konten mit mehreren Personen verknüpft (user_id ' . implode( ', ', $index->duplicates() ) . '). Neue Verknüpfungen sind gesperrt, bis das mit «wp ttleague account unlink» bereinigt ist.' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Hinweis für die Verwaltung.
		return false;
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
