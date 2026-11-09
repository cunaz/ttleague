<?php
/**
 * Einstiegspunkt des Plugins: registriert Hooks und Dienste.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague;

use TTLeague\Infrastructure\Database\Installer;

final class Plugin {

	private static bool $booted = false;

	public static function boot(): void {
		if ( self::$booted ) {
			return;
		}
		self::$booted = true;

		load_plugin_textdomain( 'ttleague', false, dirname( plugin_basename( TTLEAGUE_FILE ) ) . '/languages' );

		// Schema nachziehen, wenn eine neue Plugin-Version eine neue DB-Version mitbringt.
		add_action( 'init', array( Installer::class, 'maybe_upgrade' ), 1 );

		// Öffentliche Darstellung (Routing, Seiten, Blöcke, Shortcodes, Assets).
		if ( class_exists( \TTLeague\Frontend\Frontend::class ) ) {
			\TTLeague\Frontend\Frontend::register();
		}

		// Fachliche Rechte (Meta-Capabilities) und Konten (Anmeldelink, Mein Bereich).
		\TTLeague\Access\Capabilities::register();
		\TTLeague\Accounts\Accounts::register();

		// Redaktionelle Inhalte (Dokumente).
		if ( class_exists( \TTLeague\Content\Content::class ) ) {
			\TTLeague\Content\Content::register();
		}

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			\WP_CLI::add_command( 'ttleague', \TTLeague\Cli\Command::class );
			\WP_CLI::add_command( 'ttleague rating', \TTLeague\Cli\RatingCommand::class );
			\WP_CLI::add_command( 'ttleague account', \TTLeague\Cli\AccountCommand::class );
		}
	}
}
