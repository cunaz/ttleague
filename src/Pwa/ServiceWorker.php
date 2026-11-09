<?php
/**
 * Skript des Service Workers: Vorlage aus assets/pwa/sw.js plus Konfiguration.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Pwa;

/**
 * Reine Klasse ohne WordPress. Der Service Worker liest seine Einstellungen aus der Konstante
 * CONFIG, die vor die Vorlage gesetzt wird. Die Version (Teil der Cache-Namen) ist ein Hash
 * aus Vorlage und Konfiguration: Jede Änderung erzeugt neue Bytes, der Browser installiert
 * den neuen Service Worker, und dieser räumt die alten Caches auf.
 */
final class ServiceWorker {

	/**
	 * Pfade relativ zur Startadresse, die nie gespeichert werden: Verwaltung, Anmeldung,
	 * REST-API, Seiten mit Formularen oder persönlichen Daten, Kalender-Feeds.
	 */
	public const NEVER_CACHE = array(
		'wp-admin',
		'wp-login.php',
		'wp-json',
		'wp-cron.php',
		'xmlrpc.php',
		'anmelden',
		'mein-bereich',
		'erfassen',
		'kalender',
	);

	/**
	 * Höchstzahl gespeicherter Seiten und statischer Dateien; die ältesten fallen weg.
	 */
	public const MAX_PAGES  = 60;
	public const MAX_ASSETS = 120;

	/**
	 * Fertiges Skript: Kopfkommentar, CONFIG mit Version, Vorlage.
	 *
	 * @param string              $template Inhalt von assets/pwa/sw.js.
	 * @param array<string,mixed> $config   Einstellungen aus config().
	 */
	public static function script( string $template, array $config ): string {
		$config['version'] = self::version( $template, $config );
		return "/* ttleague: Service Worker der Web-App, von WordPress ausgeliefert. */\n"
			. 'const CONFIG = ' . self::json( $config ) . ";\n\n"
			. $template;
	}

	/**
	 * Einstellungen des Service Workers.
	 *
	 * @param string               $base    Pfad der Startadresse, z. B. / oder /liga/.
	 * @param string               $start   Startadresse der App, z. B. /?quelle=app.
	 * @param list<string>         $exclude Pfade, die nie gespeichert werden.
	 * @param array<string,string> $offline Texte der Offline-Seite (lang, title, heading, text, retry, home, site).
	 * @param array<string,string> $colors  Farben der Offline-Seite (background, text, accent).
	 * @return array<string,mixed>
	 */
	public static function config( string $base, string $start, array $exclude, array $offline, array $colors ): array {
		$base = Manifest::base( $base );
		return array(
			'prefix'    => 'ttleague:' . $base . ':',
			'base'      => $base,
			'start'     => $start,
			'never'     => self::never( $exclude ),
			'maxPages'  => self::MAX_PAGES,
			'maxAssets' => self::MAX_ASSETS,
			'offline'   => $offline,
			'colors'    => $colors,
		);
	}

	/**
	 * Pfade ohne Schrägstriche am Rand, eindeutig, ohne leere Einträge.
	 *
	 * @param list<string> $paths Pfade, z. B. /mein-bereich/.
	 * @return list<string>
	 */
	public static function never( array $paths ): array {
		$clean = array();
		foreach ( $paths as $path ) {
			$path = trim( (string) $path, "/ \t\n\r\0\x0B" );
			if ( '' !== $path ) {
				$clean[ $path ] = true;
			}
		}
		return array_keys( $clean );
	}

	/**
	 * Kurzer Hash aus Vorlage und Konfiguration.
	 *
	 * @param string              $template Vorlage.
	 * @param array<string,mixed> $config   Einstellungen ohne Version.
	 */
	public static function version( string $template, array $config ): string {
		unset( $config['version'] );
		return substr( hash( 'sha256', $template . "\0" . self::json( $config ) ), 0, 12 );
	}

	/**
	 * @param array<string,mixed> $data Daten.
	 */
	private static function json( array $data ): string {
		return (string) json_encode( $data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- reine Klasse ohne WordPress.
	}
}
