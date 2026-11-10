<?php
/**
 * CSV-Exporte: Verzeichnis über den Filter, Download nur mit Nonce und ttl_tk.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Tests\WordPress\Export;

use TTLeague\Export\Exports;
use TTLeague\Tests\WordPress\TestCase;

final class ExportsTest extends TestCase {

	/** @var array<string,string> Filter des letzten Abrufs. */
	private array $seen = array();

	public function set_up(): void {
		parent::set_up();
		$this->seen = array();
		add_filter( 'ttleague_exports', array( $this, 'exports' ) );
	}

	public function tear_down(): void {
		remove_filter( 'ttleague_exports', array( $this, 'exports' ) );
		$_GET = array();
		parent::tear_down();
	}

	/**
	 * @param array<string,mixed> $exports Bisherige Listen.
	 * @return array<string,mixed>
	 */
	public function exports( array $exports ): array {
		$exports['spieler']      = array(
			'title'  => 'Spielerliste',
			'header' => array( 'Lizenz', 'Name' ),
			'rows'   => function ( array $args ): array {
				$this->seen = $args;
				return array( array( 1201, 'Muster Hans' ) );
			},
		);
		$exports['Ungültig!']    = array(
			'title'  => 'Falscher Name',
			'header' => array(),
			'rows'   => '__return_empty_array',
		);
		$exports['ohne-zeilen']  = array(
			'title'  => 'Ohne Zeilen',
			'header' => array( 'a' ),
		);
		$exports['kein-eintrag'] = 'nichts';
		return $exports;
	}

	public function test_only_valid_lists_are_offered(): void {
		self::assertSame( array( 'spieler' ), array_keys( Exports::all() ) );
		self::assertSame( "\xEF\xBB\xBFLizenz;Name\r\n1201;Muster Hans\r\n", Exports::csv( 'spieler' ) );
		self::assertNull( Exports::csv( 'unbekannt' ) );
	}

	public function test_tk_downloads_a_list_with_filters(): void {
		wp_set_current_user( $this->account( 'ttl_tk', 'tk@example.invalid' ) );
		$url = Exports::url(
			'spieler',
			array(
				'club'   => '12',
				'action' => 'anderes',
			)
		);
		self::assertStringNotContainsString( '&amp;', $url, 'roh, für esc_url() bei der Ausgabe' );
		self::assertStringNotContainsString( 'anderes', $url, 'reservierte Parameter sind keine Filter' );
		$this->request( $url );

		$file = Exports::requested();

		self::assertSame( 'ttleague-spieler-' . wp_date( 'Y-m-d' ) . '.csv', $file['filename'] );
		self::assertStringContainsString( '1201;Muster Hans', $file['csv'] );
		self::assertSame( array( 'club' => '12' ), $this->seen, 'Filter ohne action, list und Nonce' );
	}

	public function test_download_needs_the_tk_capability(): void {
		wp_set_current_user( $this->account( 'subscriber', 'mitglied@example.invalid' ) );
		$this->request( Exports::url( 'spieler' ) );

		$this->expectException( \WPDieException::class );
		try {
			Exports::requested();
		} finally {
			self::assertSame( array(), $this->seen, 'Liste nicht abgerufen' );
		}
	}

	public function test_download_needs_the_nonce_of_this_list(): void {
		wp_set_current_user( $this->account( 'ttl_tk', 'tk@example.invalid' ) );
		$this->request( Exports::url( 'spieler' ) );
		$_GET['list'] = 'andere';

		$this->expectException( \WPDieException::class );
		Exports::requested();
	}

	public function test_unknown_list_is_not_found(): void {
		wp_set_current_user( $this->account( 'ttl_tk', 'tk@example.invalid' ) );
		$this->request( Exports::url( 'unbekannt' ) );

		$this->expectException( \WPDieException::class );
		$this->expectExceptionMessage( 'Diese Liste gibt es nicht.' );
		Exports::requested();
	}

	/**
	 * Legt die Parameter einer Adresse als Anfrage ab.
	 */
	private function request( string $url ): void {
		$query = array();
		parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $query );
		$_GET     = $query;
		$_REQUEST = $query;
	}
}
