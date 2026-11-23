<?php
/**
 * WP-CLI-Befehle des Plugins (`wp ttleague …`).
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Cli;

use TTLeague\Infrastructure\Database\Installer;
use TTLeague\Infrastructure\Database\Schema;
use TTLeague\Infrastructure\Database\WpdbDb;

/**
 * Werkzeuge für den Spielbetrieb. Weitere Unterbefehle (z. B. `wp ttleague pages`)
 * registrieren die jeweiligen Module selbst unter diesem Namen.
 */
final class Command {

	/**
	 * Zeigt Schemaversion, aktive Saison und die Anzahl Zeilen pro Tabelle.
	 *
	 * ## EXAMPLES
	 *
	 *     wp ttleague info
	 *
	 * @param array<int,string>    $args       Positionsargumente (keine).
	 * @param array<string,string> $assoc_args Benannte Argumente (keine).
	 */
	public function info( array $args, array $assoc_args ): void {
		$version = (string) get_option( Installer::OPTION, '–' );
		\WP_CLI::log( 'Schemaversion: ' . $version . ' (Code: ' . Schema::VERSION . ')' );
		if ( Schema::VERSION !== $version ) {
			\WP_CLI::error( 'Das Datenbank-Update ist unvollständig. Bitte das PHP-Fehlerprotokoll und die Datenbankrechte prüfen.' );
		}
		$db     = WpdbDb::from_global();
		$season = $db->get_row( "SELECT name, slug FROM {$db->table( 'season' )} WHERE status = 'active' ORDER BY start_date DESC LIMIT 1" );
		\WP_CLI::log( 'Aktive Saison: ' . ( null === $season ? '–' : $season['name'] . ' (' . $season['slug'] . ')' ) );
		$rows = array();
		foreach ( Schema::TABLES as $name ) {
			$rows[] = array(
				'Tabelle' => $db->table( $name ),
				'Zeilen'  => (int) $db->get_var( "SELECT COUNT(*) FROM {$db->table( $name )}" ),
			);
		}
		\WP_CLI\Utils\format_items( 'table', $rows, array( 'Tabelle', 'Zeilen' ) );
	}
}
