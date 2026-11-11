<?php
/**
 * Verhalten von assets/js/share.js mit einem nachgebildeten Dokument (Node).
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Tests\Unit\Frontend;

use PHPUnit\Framework\TestCase;

/**
 * Das Skript läuft in einem Kontext mit einem minimalen document, window und navigator; der
 * Test klickt den Knopf und liest, was geteilt, kopiert und angesagt wurde. Ohne Node werden
 * die Tests übersprungen. Ein Browser wird dabei nicht geprüft.
 */
final class ShareScriptTest extends TestCase {

	private const HARNESS = <<<'JS'
const fs = require( 'fs' );
const vm = require( 'vm' );
const input = JSON.parse( fs.readFileSync( 0, 'utf8' ) );

function element( text ) {
	return { textContent: text, listeners: {}, addEventListener( type, fn ) { this.listeners[ type ] = fn; } };
}
const button = element( '' );
const label = element( 'Teilen' );
const status = element( '' );
const attributes = {
	'data-ttl-share-title': 'Seefeld 1 – Wollishofen 1 6:4',
	'data-ttl-share-url': 'https://liga.example/begegnung/12/',
	'data-ttl-share-copy': 'Link kopieren',
	'data-ttl-share-copied': 'Link kopiert.',
	'data-ttl-share-failed': 'Kopieren nicht möglich.',
};
const box = {
	hidden: true,
	getAttribute: ( name ) => attributes[ name ] ?? null,
	querySelector: ( selector ) => ( { '.ttl-share__button': button, '.ttl-share__label': label, '.ttl-share__status': status }[ selector ] ?? null ),
};
const log = { shared: [], copied: [] };
const navigator = {};
if ( input.share ) {
	navigator.share = ( data ) => {
		log.shared.push( data );
		if ( 'throw' === input.share ) {
			throw new Error( 'nicht erlaubt' );
		}
		if ( 'ok' === input.share ) {
			return Promise.resolve();
		}
		const error = new Error( input.share );
		error.name = 'abort' === input.share ? 'AbortError' : 'NotAllowedError';
		return Promise.reject( error );
	};
}
if ( input.clipboard ) {
	navigator.clipboard = {
		writeText: ( text ) => {
			log.copied.push( text );
			return 'ok' === input.clipboard ? Promise.resolve() : Promise.reject( new Error( 'gesperrt' ) );
		},
	};
}
const context = {
	navigator,
	document: { readyState: 'complete', title: 'Dokument', querySelectorAll: () => [ box ], addEventListener() {} },
	window: { isSecureContext: true, location: { href: 'https://liga.example/' }, setTimeout: ( fn ) => setTimeout( fn, 0 ) },
};
vm.runInNewContext( fs.readFileSync( input.file, 'utf8' ), context );

const visible = ! box.hidden;
const text = label.textContent;
const clicks = Number( input.clicks ?? 1 );
( async () => {
	for ( let i = 0; i < clicks; i++ ) {
		if ( button.listeners.click ) {
			button.listeners.click();
		}
		await new Promise( ( resolve ) => setTimeout( resolve, 20 ) );
	}
	process.stdout.write( JSON.stringify( { visible, label: text, shared: log.shared, copied: log.copied, status: status.textContent } ) );
} )();
JS;

	/**
	 * @param array<string,mixed> $input share (ok, abort, fail, throw oder leer), clipboard (ok, fail oder leer), clicks.
	 * @return array{visible:bool,label:string,shared:list<array<string,string>>,copied:list<string>,status:string}
	 */
	private static function click( array $input ): array {
		$input['file'] = dirname( __DIR__, 3 ) . '/assets/js/share.js';
		$process       = @proc_open( // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.PHP.DiscouragedPHPFunctions.system_calls_proc_open -- Testwerkzeug: Node für das Skript.
			array( 'node', '-e', self::HARNESS ),
			array(
				0 => array( 'pipe', 'r' ),
				1 => array( 'pipe', 'w' ),
				2 => array( 'pipe', 'w' ),
			),
			$pipes
		);
		if ( ! is_resource( $process ) ) {
			self::markTestSkipped( 'Node ist nicht verfügbar.' );
		}
		fwrite( $pipes[0], (string) json_encode( $input ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite, WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Tests laufen ohne WordPress.
		fclose( $pipes[0] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		$output = (string) stream_get_contents( $pipes[1] );
		$errors = (string) stream_get_contents( $pipes[2] );
		fclose( $pipes[1] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		fclose( $pipes[2] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		$code = proc_close( $process );
		if ( 127 === $code ) {
			self::markTestSkipped( 'Node ist nicht verfügbar.' );
		}
		self::assertSame( 0, $code, $errors );
		return json_decode( $output, true );
	}

	public function test_web_share_api_shares_title_and_address(): void {
		$result = self::click(
			array(
				'share'     => 'ok',
				'clipboard' => 'ok',
			)
		);

		self::assertTrue( $result['visible'] );
		self::assertSame( 'Teilen', $result['label'] );
		self::assertSame(
			array(
				array(
					'title' => 'Seefeld 1 – Wollishofen 1 6:4',
					'text'  => 'Seefeld 1 – Wollishofen 1 6:4',
					'url'   => 'https://liga.example/begegnung/12/',
				),
			),
			$result['shared']
		);
		self::assertSame( array(), $result['copied'] );
		self::assertSame( '', $result['status'] );
	}

	public function test_without_web_share_the_button_copies_the_link_and_says_so(): void {
		$result = self::click(
			array(
				'clipboard' => 'ok',
				'clicks'    => 2,
			)
		);

		self::assertTrue( $result['visible'] );
		self::assertSame( 'Link kopieren', $result['label'] );
		self::assertSame( array( 'https://liga.example/begegnung/12/', 'https://liga.example/begegnung/12/' ), $result['copied'] );
		self::assertSame( 'Link kopiert.', $result['status'], 'Rückmeldung in der Live-Region, auch beim zweiten Mal' );
	}

	public function test_button_stays_hidden_without_share_and_clipboard(): void {
		$result = self::click( array() );

		self::assertFalse( $result['visible'] );
		self::assertSame( array(), $result['copied'] );
	}

	public function test_cancelled_share_is_silent_and_failed_share_falls_back_to_copying(): void {
		$cancelled = self::click(
			array(
				'share'     => 'abort',
				'clipboard' => 'ok',
			)
		);
		self::assertSame( array(), $cancelled['copied'], 'Abbruch ist kein Fehler' );
		self::assertSame( '', $cancelled['status'] );

		foreach ( array( 'fail', 'throw' ) as $kind ) {
			$failed = self::click(
				array(
					'share'     => $kind,
					'clipboard' => 'ok',
				)
			);
			self::assertSame( array( 'https://liga.example/begegnung/12/' ), $failed['copied'], $kind );
			self::assertSame( 'Link kopiert.', $failed['status'], $kind );
		}

		$blocked = self::click(
			array(
				'share'     => 'fail',
				'clipboard' => 'fail',
			)
		);
		self::assertSame( 'Kopieren nicht möglich.', $blocked['status'] );

		$only_share = self::click( array( 'share' => 'fail' ) );
		self::assertTrue( $only_share['visible'] );
		self::assertSame( 'Kopieren nicht möglich.', $only_share['status'], 'ohne Zwischenablage' );
	}
}
