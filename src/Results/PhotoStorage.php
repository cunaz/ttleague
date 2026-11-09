<?php
/**
 * Ablage der Fotos von Spielberichten ausserhalb des öffentlichen Zugriffs.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Results;

/**
 * Ein Foto des Spielberichts zeigt Namen, Lizenznummern und Unterschriften. Der Anhangsstatus
 * «private» schützt nur den Beitrag in der Mediathek, nicht die Datei. Deshalb landen die
 * Fotos in einem eigenen Verzeichnis ohne Bildvarianten:
 *
 * - Standard: `uploads/ttleague-private/` mit `.htaccess` (Apache) und `index.php`. Für nginx
 *   braucht es eine Regel im Server, siehe docs/erfassung.md.
 * - Besser: Konstante `TTLEAGUE_PRIVATE_UPLOADS` mit einem Verzeichnis ausserhalb des Web-Roots.
 *
 * Ausgeliefert wird nur über `GET /wp-json/ttleague/v1/fixtures/{id}/photo` mit Rechteprüfung.
 */
final class PhotoStorage {

	public const DIR = 'ttleague-private';

	/**
	 * Basisverzeichnis der Fotos (ohne abschliessenden Schrägstrich).
	 */
	public static function base_dir(): string {
		if ( defined( 'TTLEAGUE_PRIVATE_UPLOADS' ) && is_string( TTLEAGUE_PRIVATE_UPLOADS ) && '' !== TTLEAGUE_PRIVATE_UPLOADS ) {
			return untrailingslashit( TTLEAGUE_PRIVATE_UPLOADS );
		}
		return untrailingslashit( wp_upload_dir( null, false )['basedir'] ) . '/' . self::DIR;
	}

	/**
	 * Führt einen Upload so aus, dass die Datei im geschützten Verzeichnis landet und WordPress
	 * keine Bildvarianten (Vorschaubilder, verkleinerte Fassung) erzeugt.
	 *
	 * @template T
	 * @param callable():T $upload Upload, z. B. media_handle_upload().
	 * @return T
	 */
	public static function in_private_dir( callable $upload ): mixed {
		$base = self::base_dir();
		self::protect( $base );
		$dirs = static function ( array $dirs ) use ( $base ): array {
			$dirs['path'] = $base . $dirs['subdir'];
			$dirs['url']  = $dirs['baseurl'] . '/' . self::DIR . $dirs['subdir'];
			return $dirs;
		};
		add_filter( 'upload_dir', $dirs );
		add_filter( 'intermediate_image_sizes_advanced', '__return_empty_array' );
		add_filter( 'big_image_size_threshold', '__return_false' );
		try {
			return $upload();
		} finally {
			remove_filter( 'upload_dir', $dirs );
			remove_filter( 'intermediate_image_sizes_advanced', '__return_empty_array' );
			remove_filter( 'big_image_size_threshold', '__return_false' );
		}
	}

	/**
	 * Legt das Verzeichnis mit Schutzdateien an: `.htaccess` sperrt den Zugriff unter Apache,
	 * `index.php` verhindert Verzeichnislisten.
	 */
	public static function protect( string $dir ): void {
		wp_mkdir_p( $dir );
		$files = array(
			'.htaccess' => "# ttleague: Fotos der Spielberichte nur über die REST-API.\nRequire all denied\n<IfModule !mod_authz_core.c>\n\tOrder allow,deny\n\tDeny from all\n</IfModule>\n",
			'index.php' => "<?php\n// Silence is golden.\n",
		);
		foreach ( $files as $name => $content ) {
			$path = $dir . '/' . $name;
			if ( ! file_exists( $path ) ) {
				file_put_contents( $path, $content ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Schutzdatei, kein Benutzerinhalt.
			}
		}
	}
}
