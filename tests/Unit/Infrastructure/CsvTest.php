<?php
/**
 * Tests des CSV-Bausteins für Exporte.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Tests\Unit\Infrastructure;

use PHPUnit\Framework\TestCase;
use TTLeague\Infrastructure\Csv;

final class CsvTest extends TestCase {

	public function test_output_opens_in_excel_bom_semicolon_crlf(): void {
		$csv = Csv::render( array( 'Lizenz', 'Name' ), array( array( 1201, 'Müsterli Anna' ) ) );

		self::assertSame( "\xEF\xBB\xBFLizenz;Name\r\n1201;Müsterli Anna\r\n", $csv );
	}

	public function test_cells_with_separator_quote_or_line_break_are_quoted(): void {
		$csv = Csv::render( array( 'a' ), array( array( 'Muster; Hans' ), array( 'sagt "ja"' ), array( "Zeile 1\nZeile 2" ) ) );

		self::assertSame( "\xEF\xBB\xBFa\r\n\"Muster; Hans\"\r\n\"sagt \"\"ja\"\"\"\r\n\"Zeile 1\nZeile 2\"\r\n", $csv );
	}

	public function test_text_that_looks_like_a_formula_is_defused(): void {
		$csv = Csv::render( array( 'Bemerkung' ), array( array( '=SUMME(A1:A9)' ), array( '+41 44 000 00 00' ), array( '@import' ), array( '-nicht gemeldet' ) ) );

		self::assertSame( "\xEF\xBB\xBFBemerkung\r\n'=SUMME(A1:A9)\r\n'+41 44 000 00 00\r\n'@import\r\n'-nicht gemeldet\r\n", $csv );
	}

	public function test_tab_and_carriage_return_are_defused_and_the_header_too(): void {
		$csv = Csv::render( array( '=Kopf' ), array( array( "\t=1+1" ), array( "\r=1+1" ), array( '+41440000000' ) ) );

		// Wagenrücklauf: erst das Hochkomma, dann die Anführungszeichen. Numerischer Text bleibt.
		self::assertSame( "\xEF\xBB\xBF'=Kopf\r\n'\t=1+1\r\n\"'\r=1+1\"\r\n+41440000000\r\n", $csv );
	}

	public function test_numbers_stay_numbers_also_negative_ones(): void {
		$csv = Csv::render( array( 'Diff', 'Wert', 'Text' ), array( array( -3, 1512.5, '-12.5' ) ) );

		self::assertSame( "\xEF\xBB\xBFDiff;Wert;Text\r\n-3;1512.5;-12.5\r\n", $csv );
	}

	public function test_null_is_empty_and_booleans_are_digits(): void {
		$csv = Csv::render(
			array( 'a', 'b', 'c' ),
			array(
				array(
					'x' => null,
					'y' => true,
					'z' => false,
				),
			)
		);

		self::assertSame( "\xEF\xBB\xBFa;b;c\r\n;1;0\r\n", $csv );
	}

	public function test_rows_may_come_from_a_generator(): void {
		$rows = ( static function () {
			yield array( 1 );
			yield array( 2 );
		} )();

		self::assertSame( "\xEF\xBB\xBFn\r\n1\r\n2\r\n", Csv::render( array( 'n' ), $rows ) );
	}
}
