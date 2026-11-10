<?php
/**
 * WP-CLI: Listen als CSV (`wp ttleague export …`).
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Cli;

use TTLeague\Export\Exports;

final class ExportCommand {

	/**
	 * Gibt eine Liste als CSV aus (UTF-8 mit BOM, Semikolon). Ohne Namen: zeigt die verfügbaren Listen.
	 *
	 * ## OPTIONS
	 *
	 * [<liste>]
	 * : Name der Liste.
	 *
	 * [--file=<pfad>]
	 * : In diese Datei schreiben statt auf die Standardausgabe.
	 *
	 * [--<field>=<value>]
	 * : Filter der Liste, z. B. --club=12.
	 *
	 * ## EXAMPLES
	 *
	 *     wp ttleague export
	 *     wp ttleague export classification --file=klassierung.csv
	 *
	 * @param array<int,string>    $args       Positionsargumente.
	 * @param array<string,string> $assoc_args Benannte Argumente.
	 */
	public function __invoke( array $args, array $assoc_args ): void {
		$name = (string) ( $args[0] ?? '' );
		if ( '' === $name ) {
			$rows = array();
			foreach ( Exports::all() as $key => $export ) {
				$rows[] = array(
					'Liste'       => $key,
					'Bezeichnung' => $export['title'],
				);
			}
			if ( array() === $rows ) {
				\WP_CLI::log( 'Keine Listen angemeldet.' );
				return;
			}
			\WP_CLI\Utils\format_items( 'table', $rows, array( 'Liste', 'Bezeichnung' ) );
			return;
		}
		$file = (string) ( $assoc_args['file'] ?? '' );
		unset( $assoc_args['file'] );
		$csv = Exports::csv( $name, array_map( 'strval', $assoc_args ) );
		if ( null === $csv ) {
			\WP_CLI::error( 'Diese Liste gibt es nicht: ' . $name );
		}
		if ( '' === $file ) {
			echo $csv; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CSV auf die Standardausgabe.
			return;
		}
		if ( false === file_put_contents( $file, $csv ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- CLI schreibt in die gewählte Datei.
			\WP_CLI::error( 'Datei liess sich nicht schreiben: ' . $file );
		}
		\WP_CLI::success( 'Geschrieben: ' . $file );
	}
}
