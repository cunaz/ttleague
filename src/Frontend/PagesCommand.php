<?php
/**
 * WP-CLI: wp ttleague pages.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Frontend;

/**
 * Legt die Seiten der öffentlichen Darstellung an (idempotent) und erneuert die Rewrite-Regeln.
 *
 * ## EXAMPLES
 *
 *     wp ttleague pages
 */
final class PagesCommand {

	/**
	 * Legt fehlende Seiten an, übernimmt vorhandene und erneuert die Rewrite-Regeln.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : Ausgabeformat (table, csv, json).
	 * ---
	 * default: table
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp ttleague pages
	 *
	 * @param array<int,string>    $args       Positionsargumente (keine).
	 * @param array<string,string> $assoc_args Benannte Argumente.
	 */
	public function __invoke( array $args, array $assoc_args ): void {
		$report = PageInstaller::install();
		$rows   = array();
		foreach ( $report as $row ) {
			$rows[] = array(
				'Seite'   => $row['title'],
				'ID'      => $row['id'],
				'Adresse' => $row['url'],
				'Aktion'  => $row['action'],
			);
		}
		\WP_CLI\Utils\format_items( (string) ( $assoc_args['format'] ?? 'table' ), $rows, array( 'Seite', 'ID', 'Adresse', 'Aktion' ) );
		\WP_CLI::success( 'Seiten geprüft, Rewrite-Regeln erneuert.' );
	}
}
