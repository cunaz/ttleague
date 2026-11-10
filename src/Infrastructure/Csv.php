<?php
/**
 * CSV für Exporte, die Excel direkt öffnet.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Infrastructure;

/**
 * UTF-8 mit BOM, Semikolon als Trennzeichen, CRLF als Zeilenende. Textzellen, die eine
 * Tabellenkalkulation als Formel lesen würde (Beginn mit =, +, -, @, Tabulator oder
 * Wagenrücklauf), erhalten ein vorangestelltes Hochkomma.
 */
final class Csv {

	public const BOM       = "\xEF\xBB\xBF";
	public const SEPARATOR = ';';

	/**
	 * @param list<string>                                    $header Spaltentitel.
	 * @param iterable<array<int|string,string|int|float|bool|null>> $rows   Zeilen; Werte in der Reihenfolge der Spalten.
	 */
	public static function render( array $header, iterable $rows ): string {
		$out = self::BOM . self::line( $header );
		foreach ( $rows as $row ) {
			$out .= self::line( array_values( $row ) );
		}
		return $out;
	}

	/**
	 * @param list<string|int|float|bool|null> $cells Zellen einer Zeile.
	 */
	private static function line( array $cells ): string {
		return implode( self::SEPARATOR, array_map( array( self::class, 'cell' ), $cells ) ) . "\r\n";
	}

	private static function cell( string|int|float|bool|null $value ): string {
		if ( null === $value ) {
			return '';
		}
		if ( is_bool( $value ) ) {
			return $value ? '1' : '0';
		}
		if ( is_int( $value ) || is_float( $value ) ) {
			return (string) $value;
		}
		if ( '' !== $value && false !== strpos( "=+-@\t\r", $value[0] ) && ! is_numeric( $value ) ) {
			$value = "'" . $value;
		}
		if ( false !== strpbrk( $value, self::SEPARATOR . "\"\r\n" ) ) {
			return '"' . str_replace( '"', '""', $value ) . '"';
		}
		return $value;
	}
}
