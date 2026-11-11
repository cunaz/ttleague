<?php
/**
 * Anlegen und Nachführen des Schemas über dbDelta().
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Infrastructure\Database;

use TTLeague\Infrastructure\Cache\DataVersion;

final class Installer {

	public const OPTION = 'ttleague_db_version';

	/** Merkt sich (automatisch geladen), dass person.user_id eindeutig ist. */
	public const USER_UNIQUE_OPTION = 'ttleague_person_user_unique';

	/**
	 * Aktivierungshook: Tabellen und Rollen anlegen.
	 */
	public static function activate(): void {
		self::install();
		\TTLeague\Access\Roles::install();
	}

	/**
	 * Bei jedem Laden prüfen, ob eine neue Schemaversion vorliegt. Nach einem Upgrade gelten
	 * zwischengespeicherte Lesemodelle nicht mehr: Eine neue Version kann ihnen Felder hinzufügen.
	 */
	public static function maybe_upgrade(): void {
		if ( get_option( self::OPTION ) !== Schema::VERSION ) {
			self::install();
			DataVersion::bump();
			return;
		}
		// Fehlt der UNIQUE-Index auf person.user_id noch, höchstens stündlich erneut versuchen.
		// Die Option ist automatisch geladen; im Normalfall kostet die Prüfung keine Abfrage.
		if ( '1' !== get_option( self::USER_UNIQUE_OPTION ) && false === get_transient( 'ttleague_person_user_index' ) ) {
			set_transient( 'ttleague_person_user_index', 1, HOUR_IN_SECONDS );
			self::ensure_unique_person_user();
		}
	}

	public static function install(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		self::drop_obsolete_indexes();
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
			update_option( self::USER_UNIQUE_OPTION, '1', true );
			return true;
		}
		update_option( self::USER_UNIQUE_OPTION, '0', true );
		$duplicates = $index->duplicates();
		$message    = array() !== $duplicates
			? 'Konten mit mehreren Personen verknüpft (user_id ' . implode( ', ', $duplicates ) . '). Neue Verknüpfungen sind gesperrt, bis das mit «wp ttleague account unlink» bereinigt ist.'
			: 'Index person.user_id liess sich nicht in UNIQUE umwandeln (' . $index->last_error . '). Neue Verknüpfungen bleiben gesperrt; neuer Versuch in einer Stunde.';
		error_log( 'ttleague: ' . $message ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Hinweis für die Verwaltung.
		return false;
	}

	/**
	 * Ersetzte Indizes löschen (Schema::OBSOLETE_INDEXES); dbDelta() kann das nicht.
	 */
	private static function drop_obsolete_indexes(): void {
		global $wpdb;
		foreach ( Schema::OBSOLETE_INDEXES as $name => $indexes ) {
			$table = Schema::table( $name, $wpdb->prefix );
			foreach ( $indexes as $index ) {
				$exists = $wpdb->get_var(
					$wpdb->prepare(
						'SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND INDEX_NAME = %s',
						$table,
						$index
					)
				);
				if ( (int) $exists > 0 ) {
					$wpdb->query( "ALTER TABLE `{$table}` DROP INDEX `{$index}`" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.SchemaChange -- Namen aus Schema-Konstanten.
				}
			}
		}
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
