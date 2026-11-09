<?php
/**
 * Ruft die reinen Funktionen aus assets/js/entry-view.js mit Node auf (für Vergleichstests).
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Tests\Unit\Results;

/**
 * Ohne Node werden die Tests übersprungen; das Plugin selbst braucht Node nicht.
 */
final class EntryScript {

	private static ?bool $available = null;

	public static function available(): bool {
		if ( null === self::$available ) {
			try {
				self::$available = 'Spiel 3' === self::call( 'fmt', array( 'Spiel %d', 3 ) );
			} catch ( \RuntimeException ) {
				self::$available = false;
			}
		}
		return self::$available;
	}

	/**
	 * Exportierte Funktion mit JSON-Argumenten aufrufen; Rückgabe als PHP-Wert.
	 *
	 * @param string      $name Name der Funktion in entry-view.js.
	 * @param list<mixed> $args Argumente.
	 * @throws \RuntimeException Wenn Node fehlt oder das Skript scheitert.
	 */
	public static function call( string $name, array $args ): mixed {
		$module  = 'file://' . dirname( __DIR__, 3 ) . '/assets/js/entry-view.js';
		$encode  = static fn( mixed $value ): string => (string) json_encode( $value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Tests laufen ohne WordPress.
		$script  = 'import * as view from ' . $encode( $module ) . ';'
			. 'const args = ' . $encode( $args ) . ';'
			. 'process.stdout.write( JSON.stringify( view[' . $encode( $name ) . ']( ...args ) ) );';
		$process = @proc_open( // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.PHP.DiscouragedPHPFunctions.system_calls_proc_open -- Testwerkzeug: Node für das Skriptmodul.
			array( 'node', '--input-type=module' ),
			array(
				0 => array( 'pipe', 'r' ),
				1 => array( 'pipe', 'w' ),
				2 => array( 'pipe', 'w' ),
			),
			$pipes
		);
		if ( ! is_resource( $process ) ) {
			throw new \RuntimeException( 'Node ist nicht verfügbar.' );
		}
		fwrite( $pipes[0], $script ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
		fclose( $pipes[0] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		$output = (string) stream_get_contents( $pipes[1] );
		$errors = (string) stream_get_contents( $pipes[2] );
		fclose( $pipes[1] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		fclose( $pipes[2] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		if ( 0 !== proc_close( $process ) ) {
			throw new \RuntimeException( 'Skript gescheitert: ' . $errors ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Meldung im Test, keine Ausgabe.
		}
		return json_decode( $output, true );
	}

	/**
	 * Arrays mit sortierten Schlüsseln (für assertSame unabhängig von der Reihenfolge).
	 */
	public static function canonical( mixed $value ): mixed {
		if ( ! is_array( $value ) ) {
			return $value;
		}
		$value = array_map( array( self::class, 'canonical' ), $value );
		if ( ! array_is_list( $value ) ) {
			ksort( $value );
		}
		return $value;
	}
}
