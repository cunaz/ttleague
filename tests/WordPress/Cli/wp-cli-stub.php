<?php
/**
 * Ersatz für die Klasse WP_CLI in den WordPress-Tests (WP-CLI ist dort nicht geladen).
 *
 * @package TTLeague
 */

declare(strict_types=1);

use TTLeague\Tests\WordPress\Cli\CliStop;

if ( ! class_exists( 'WP_CLI' ) ) {
	/**
	 * Zeichnet Ausgaben auf. error() und eine Rückfrage ohne --yes beenden den Befehl mit
	 * CliStop, wie WP-CLI ihn mit exit beendet.
	 */
	// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound -- Name, den die Befehle des Plugins aufrufen.
	final class WP_CLI {

		/** @var list<array{0:string,1:string}> Art und Text jeder Ausgabe. */
		public static array $output = array();

		public static function log( string $message ): void {
			self::$output[] = array( 'log', $message );
		}

		public static function success( string $message ): void {
			self::$output[] = array( 'success', $message );
		}

		public static function warning( string $message ): void {
			self::$output[] = array( 'warning', $message );
		}

		/**
		 * @throws CliStop Immer.
		 */
		public static function error( string $message ): void {
			self::$output[] = array( 'error', $message );
			throw new CliStop( $message ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Ausnahme im Test, keine Ausgabe.
		}

		/**
		 * @param array<string,mixed> $assoc_args Optionen; mit --yes entfällt die Rückfrage.
		 * @throws CliStop Ohne --yes (im Test antwortet niemand).
		 */
		public static function confirm( string $question, array $assoc_args = array() ): void {
			if ( empty( $assoc_args['yes'] ) ) {
				self::$output[] = array( 'confirm', $question );
				throw new CliStop( 'Rückfrage nicht bestätigt.' );
			}
		}
	}
}
