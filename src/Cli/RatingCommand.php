<?php
/**
 * WP-CLI-Befehle zur Wertungszahl (`wp ttleague rating …`).
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Cli;

use TTLeague\Infrastructure\Cache\DataVersion;
use TTLeague\Infrastructure\Database\WpdbDb;
use TTLeague\Rating\RatingService;
use TTLeague\Results\Results;

/**
 * Berechnet die Wertungszahl neu oder kalibriert ihre Parameter an vergangenen Klassierungen.
 */
final class RatingCommand {

	/**
	 * Berechnet alle Wertungszahlen aus den gespielten Spielen neu.
	 *
	 * ## OPTIONS
	 *
	 * [--no-reanchor]
	 * : Wertungszahl über Saisons weiterlaufen lassen statt jede Saison bei der Klassierung zu beginnen.
	 *
	 * ## EXAMPLES
	 *
	 *     wp ttleague rating recalc
	 *
	 * @param array<int,string>    $args       Positionsargumente (keine).
	 * @param array<string,string> $assoc_args Benannte Argumente.
	 */
	public function recalc( array $args, array $assoc_args ): void {
		$reanchor = ! ( isset( $assoc_args['reanchor'] ) && false === $assoc_args['reanchor'] );
		$service  = new RatingService( WpdbDb::from_global() );
		$start    = microtime( true );
		// Dieselbe Sperre wie der geplante Lauf: Nie rechnen zwei Läufe gleichzeitig, und wer
		// aktuelle Wertungszahlen braucht (Klassierung), wartet auf diesen.
		$lock = Results::recalc_lock();
		if ( ! $lock->acquire( Results::recalc_lock_wait() ) ) {
			\WP_CLI::error( 'Ein anderer Lauf der Neuberechnung rechnet noch. Bitte später erneut versuchen.' );
		}
		try {
			$token  = Results::rating_pending();
			$result = $service->recalc( $reanchor );
			DataVersion::bump();
			if ( $reanchor ) {
				// Nur die übliche Berechnung erledigt, was nach Resultatänderungen aussteht.
				Results::clear_rating_pending( $token );
			}
		} finally {
			$lock->release();
		}
		\WP_CLI::success(
			sprintf(
				'%d Spieler, %d Ereignisse in %.1f s neu berechnet (Neuverankerung %s).',
				count( $result->ratings ),
				count( $result->events ),
				microtime( true ) - $start,
				$reanchor ? 'an' : 'aus'
			)
		);
	}

	/**
	 * Sucht die Parameter, mit denen die Wertungszahl die Klassierungsentscheide der Vergangenheit am besten trifft.
	 *
	 * ## OPTIONS
	 *
	 * [--k=<liste>]
	 * : K-Faktoren, kommagetrennt.
	 * ---
	 * default: 8,12,16,24,32,48
	 * ---
	 *
	 * [--scale=<liste>]
	 * : Skalen der Erwartungswertformel, kommagetrennt.
	 * ---
	 * default: 200,300,400,600
	 * ---
	 *
	 * [--doubles=<liste>]
	 * : Gewichte der Doppel, kommagetrennt.
	 * ---
	 * default: 0,0.5
	 * ---
	 *
	 * [--top=<anzahl>]
	 * : Anzahl Zeilen.
	 * ---
	 * default: 12
	 * ---
	 *
	 * [--format=<format>]
	 * : Ausgabeformat.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - csv
	 * ---
	 *
	 * @param array<int,string>    $args       Positionsargumente (keine).
	 * @param array<string,string> $assoc_args Benannte Argumente.
	 */
	public function calibrate( array $args, array $assoc_args ): void {
		$list        = static fn( string $value ) => array_map( 'floatval', array_filter( array_map( 'trim', explode( ',', $value ) ), 'strlen' ) );
		$calibration = ( new RatingService( WpdbDb::from_global() ) )->calibration();
		$rows        = $calibration->grid(
			$list( (string) ( $assoc_args['k'] ?? '8,12,16,24,32,48' ) ),
			$list( (string) ( $assoc_args['scale'] ?? '200,300,400,600' ) ),
			$list( (string) ( $assoc_args['doubles'] ?? '0,0.5' ) ),
			array( true, false )
		);
		$base        = $calibration->baseline();
		\WP_CLI::log(
			sprintf(
				'Vergleich «Klassierung bleibt»: %d Fälle, exakt %.1f %%, ±1 %.1f %%, mittlere Abweichung %.3f',
				$base['n'],
				100 * $base['exact'],
				100 * $base['within1'],
				$base['mae']
			)
		);
		$top    = max( 1, (int) ( $assoc_args['top'] ?? 12 ) );
		$format = (string) ( $assoc_args['format'] ?? 'table' );
		$out    = array();
		foreach ( array_slice( $rows, 0, $top ) as $row ) {
			$out[] = array(
				'K'                 => $row['k'],
				'Skala'             => $row['scale'],
				'Doppel'            => $row['doubles'],
				'Neuverankerung'    => $row['reanchor'] ? 'ja' : 'nein',
				'Fälle'             => $row['n'],
				'exakt %'           => round( 100 * $row['exact'], 1 ),
				'±1 %'              => round( 100 * $row['within1'], 1 ),
				'Abweichung'        => round( $row['mae'], 3 ),
				'Wechsel erkannt %' => round( 100 * $row['changes_hit'], 1 ),
				'Fehlalarm %'       => round( 100 * $row['false_moves'], 1 ),
				'Log-Loss'          => round( $row['log_loss'], 4 ),
				'Treffer Spiele %'  => round( 100 * $row['accuracy'], 1 ),
			);
		}
		\WP_CLI\Utils\format_items( $format, $out, array_keys( $out[0] ?? array( 'K' => 0 ) ) );
	}
}
