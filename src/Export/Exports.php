<?php
/**
 * Listen als CSV: Verzeichnis der Exporte, Download im Backend.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Export;

use TTLeague\Access\Capabilities;
use TTLeague\Infrastructure\Csv;

/**
 * Module melden ihre Listen über den Filter «ttleague_exports» an:
 * Name => array{title:string,header:list<string>,rows:callable(array<string,string>):iterable}.
 * Der Name besteht aus Kleinbuchstaben, Ziffern und Bindestrichen. «rows» erhält die Filter
 * der Anfrage (z. B. club, season) als Zeichenketten und prüft sie selbst.
 * Abruf im Backend über admin-post.php (Nonce und ttl_tk) und mit «wp ttleague export <liste>».
 */
final class Exports {

	public const ACTION = 'ttleague_export';

	/** Parameter der Anfrage, die nicht als Filter an die Liste gehen. */
	private const RESERVED = array( 'action', 'list', '_wpnonce', '_wp_http_referer' );

	public static function register(): void {
		add_action( 'admin_post_' . self::ACTION, array( self::class, 'download' ) );
	}

	/**
	 * Alle angemeldeten Listen; ungültige Einträge fallen weg.
	 *
	 * @return array<string,array{title:string,header:list<string>,rows:callable}>
	 */
	public static function all(): array {
		/**
		 * Listen für den CSV-Export.
		 *
		 * @param array<string,array{title:string,header:list<string>,rows:callable}> $exports Name => Liste.
		 */
		$exports = apply_filters( 'ttleague_exports', array() );
		$valid   = array();
		foreach ( is_array( $exports ) ? $exports : array() as $name => $export ) {
			if ( ! is_string( $name ) || 1 !== preg_match( '/^[a-z0-9][a-z0-9-]{0,39}$/', $name ) || ! is_array( $export ) ) {
				continue;
			}
			if ( ! is_string( $export['title'] ?? null ) || ! is_array( $export['header'] ?? null ) || ! is_callable( $export['rows'] ?? null ) ) {
				continue;
			}
			$valid[ $name ] = array(
				'title'  => $export['title'],
				'header' => array_values( array_map( 'strval', $export['header'] ) ),
				'rows'   => $export['rows'],
			);
		}
		ksort( $valid );
		return $valid;
	}

	/**
	 * CSV einer Liste, null bei unbekanntem Namen.
	 *
	 * @param string               $name Name der Liste.
	 * @param array<string,string> $args Filter.
	 */
	public static function csv( string $name, array $args = array() ): ?string {
		$export = self::all()[ $name ] ?? null;
		if ( null === $export ) {
			return null;
		}
		$rows = ( $export['rows'] )( $args );
		return Csv::render( $export['header'], is_iterable( $rows ) ? $rows : array() );
	}

	/**
	 * Adresse des Downloads im Backend, mit Nonce. Nicht escaped: bei der Ausgabe esc_url() verwenden.
	 *
	 * @param string               $name Name der Liste.
	 * @param array<string,string> $args Filter.
	 */
	public static function url( string $name, array $args = array() ): string {
		$args = array_diff_key( $args, array_flip( self::RESERVED ) );
		return add_query_arg(
			array_map( 'rawurlencode', $args ) + array(
				'action'   => self::ACTION,
				'list'     => $name,
				'_wpnonce' => wp_create_nonce( self::ACTION . '_' . $name ),
			),
			admin_url( 'admin-post.php' )
		);
	}

	public static function filename( string $name, string $date ): string {
		return 'ttleague-' . $name . '-' . $date . '.csv';
	}

	/**
	 * Download: prüft Nonce und ttl_tk, sendet die Datei.
	 */
	public static function download(): void {
		$file = self::requested();
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $file['filename'] . '"' );
		header( 'X-Content-Type-Options: nosniff' );
		echo $file['csv']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CSV-Datei, kein HTML.
		exit;
	}

	/**
	 * Die angefragte Liste nach Prüfung von Nonce und ttl_tk; bricht sonst mit wp_die() ab.
	 *
	 * @return array{filename:string,csv:string}
	 */
	public static function requested(): array {
		$name = isset( $_GET['list'] ) ? sanitize_key( wp_unslash( (string) $_GET['list'] ) ) : '';
		check_admin_referer( self::ACTION . '_' . $name );
		if ( ! current_user_can( Capabilities::TK ) ) {
			wp_die( esc_html__( 'Dazu fehlt dir die Berechtigung.', 'ttleague' ), '', array( 'response' => 403 ) );
		}
		$args = array();
		foreach ( $_GET as $key => $value ) {
			if ( is_string( $key ) && is_string( $value ) && ! in_array( $key, self::RESERVED, true ) ) {
				$args[ sanitize_key( $key ) ] = sanitize_text_field( wp_unslash( $value ) );
			}
		}
		$csv = self::csv( $name, $args );
		if ( null === $csv ) {
			wp_die( esc_html__( 'Diese Liste gibt es nicht.', 'ttleague' ), '', array( 'response' => 404 ) );
		}
		return array(
			'filename' => self::filename( $name, wp_date( 'Y-m-d' ) ),
			'csv'      => $csv,
		);
	}
}
