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

	/**
	 * Module des Saisonbetriebs (docs/saisonbetrieb.md). Jedes meldet in register() seine Hooks,
	 * Backend-Seiten, Abschnitte in «Mein Bereich» und Benachrichtigungstypen an.
	 *
	 * @var list<class-string>
	 */
	public const MODULES = array(
		'TTLeague\\Registration\\Registration',
		'TTLeague\\Season\\SeasonAdmin',
		'TTLeague\\Postponement\\Postponements',
		'TTLeague\\RankingTournament\\RankingTournament',
		'TTLeague\\Cup\\Cup',
		'TTLeague\\Classification\\Classification',
		'TTLeague\\Events\\Events',
	);

	private static bool $booted = false;

	public static function boot(): void {
		if ( self::$booted || wp_installing() ) {
			return;
		}
		self::$booted = true;

		load_plugin_textdomain( 'ttleague', false, dirname( plugin_basename( TTLEAGUE_FILE ) ) . '/languages' );

		// Auch mit unvollständigem Schema muss sich die Version abfragen lassen.
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			\WP_CLI::add_command( 'ttleague', \TTLeague\Cli\Command::class );
		}
		if ( ! Installer::maybe_upgrade() ) {
			add_action( 'admin_notices', array( self::class, 'schema_notice' ) );
			add_action( 'network_admin_notices', array( self::class, 'schema_notice' ) );
			return;
		}

		// Öffentliche Darstellung (Routing, Seiten, Blöcke, Shortcodes, Assets).
		if ( class_exists( \TTLeague\Frontend\Frontend::class ) ) {
			\TTLeague\Frontend\Frontend::register();
		}

		// Fachliche Rechte (Meta-Capabilities) und Konten (Anmeldelink, Mein Bereich).
		\TTLeague\Access\Capabilities::register();
		\TTLeague\Accounts\Accounts::register();

		// Resultaterfassung (REST-API, Neuberechnung der Wertungszahl).
		\TTLeague\Results\Results::register();

		// Benachrichtigungen per E-Mail und Web-Push, Fristen und Erinnerungen.
		\TTLeague\Notifications\Notifications::register();
		// Backend der TK: Menü «Spielbetrieb» mit Übersicht sowie Konten und Einladungen.
		\TTLeague\Tk\Tk::register();

		// Saisonbetrieb: Meldung, Saison aufsetzen, Verschiebung, Ranglistenturnier, Cup, Klassierung, Anlässe.
		foreach ( self::MODULES as $module ) {
			if ( class_exists( $module ) ) {
				$module::register();
			}
		}

		// Redaktionelle Inhalte (Dokumente).
		if ( class_exists( \TTLeague\Content\Content::class ) ) {
			\TTLeague\Content\Content::register();
		}

		// Kalender-Abos (iCal) für Team, Club und Spieler.
		if ( class_exists( \TTLeague\Calendar\Calendar::class ) ) {
			\TTLeague\Calendar\Calendar::register();
		}

		// Installierbare Web-App: Manifest, Service Worker, Kopfzeilen.
		if ( class_exists( \TTLeague\Pwa\Pwa::class ) ) {
			\TTLeague\Pwa\Pwa::register();
		}

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			\WP_CLI::add_command( 'ttleague rating', \TTLeague\Cli\RatingCommand::class );
			\WP_CLI::add_command( 'ttleague account', \TTLeague\Cli\AccountCommand::class );
			\WP_CLI::add_command( 'ttleague notifications', \TTLeague\Cli\NotificationCommand::class );
			\WP_CLI::add_command( 'ttleague export', \TTLeague\Cli\ExportCommand::class );
		}
	}

	/** Cron-Aufträge und Routing auch bei unvollständigem Datenbankschema aufräumen. */
	public static function deactivate(): void {
		Frontend\PageInstaller::deactivate();
		Accounts\Accounts::deactivate();
		Results\Results::deactivate();
		Notifications\Notifications::deactivate();
		Season\SeasonAdmin::deactivate();
		RankingTournament\RankingTournament::deactivate();
		Cup\Cup::deactivate();
		Events\Events::deactivate();
	}

	public static function schema_notice(): void {
		if ( current_user_can( 'manage_options' ) || current_user_can( \TTLeague\Access\Capabilities::TK ) ) {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'ttleague pausiert den Spielbetrieb, weil das Datenbank-Update unvollständig ist. Die Website-Verwaltung sollte das PHP-Fehlerprotokoll und die Datenbankrechte prüfen. Das Plugin versucht das Update nach einer Minute erneut.', 'ttleague' ) . '</p></div>';
		}
	}
}
