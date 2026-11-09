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
 * - Besser: Konstante `TTLEAGUE_PRIVATE_UPLOADS` mit einem Verzeichnis ausserhalb des Web-Roots.
 * - Standard: `uploads/ttleague-private/` mit `.htaccess` (Apache) und `index.php`. Ob der
 *   Webserver das Verzeichnis wirklich sperrt (nginx liest keine `.htaccess`), prüft exposure()
 *   mit einer Testdatei über HTTP. Ist es erreichbar, nimmt die Erfassung keine Fotos an.
 *
 * Ausgeliefert wird nur über `GET /wp-json/ttleague/v1/fixtures/{id}/photo` mit Rechteprüfung.
 */
final class PhotoStorage {

	public const DIR = 'ttleague-private';

	/** Ergebnis der Prüfung über HTTP (protected, exposed, unknown), 12 Stunden gemerkt. */
	public const CHECK_TRANSIENT = 'ttleague_photo_storage_check';

	public const PROTECTED = 'protected';
	public const EXPOSED   = 'exposed';
	public const UNKNOWN   = 'unknown';

	private const HTACCESS = "# ttleague: Fotos der Spielberichte nur über die REST-API.\nRequire all denied\n<IfModule !mod_authz_core.c>\n\tOrder allow,deny\n\tDeny from all\n</IfModule>\n";

	private const INDEX = "<?php\n// Silence is golden.\n";

	public static function register(): void {
		add_filter( 'site_status_tests', array( self::class, 'site_status_tests' ) );
	}

	/**
	 * Basisverzeichnis der Fotos (ohne abschliessenden Schrägstrich).
	 */
	public static function base_dir(): string {
		if ( self::configured_outside() ) {
			return untrailingslashit( (string) TTLEAGUE_PRIVATE_UPLOADS );
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
	 * @throws ResultException 503, wenn die Ablage nicht geschützt ist oder von aussen erreichbar wäre.
	 */
	public static function in_private_dir( callable $upload ): mixed {
		$base = self::base_dir();
		if ( ! self::protect( $base ) ) {
			throw ResultException::unavailable( __( 'Die Ablage für Fotos lässt sich nicht schützen. Bitte wende dich an die TK.', 'ttleague' ) );
		}
		if ( self::EXPOSED === self::exposure() ) {
			throw ResultException::unavailable( __( 'Die Ablage für Fotos ist von aussen erreichbar; Fotos sind deshalb gesperrt. Bitte wende dich an die TK.', 'ttleague' ) );
		}
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
	 * Legt das Verzeichnis mit Schutzdateien an und stellt deren Inhalt wieder her, falls er
	 * fehlt oder abweicht: `.htaccess` sperrt den Zugriff unter Apache, `index.php` verhindert
	 * Verzeichnislisten.
	 *
	 * @return bool false, wenn Verzeichnis oder Schutzdatei nicht geschrieben werden konnten.
	 */
	public static function protect( string $dir ): bool {
		if ( ! wp_mkdir_p( $dir ) ) {
			return false;
		}
		foreach ( array(
			'.htaccess' => self::HTACCESS,
			'index.php' => self::INDEX,
		) as $name => $content ) {
			$path = $dir . '/' . $name;
			if ( is_file( $path ) && file_get_contents( $path ) === $content ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- lokale Schutzdatei.
				continue;
			}
			if ( false === file_put_contents( $path, $content ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Schutzdatei, kein Benutzerinhalt.
				return false;
			}
		}
		return true;
	}

	/**
	 * Ist die Ablage von aussen erreichbar? Legt eine Testdatei mit zufälligem Inhalt ab und ruft
	 * sie über HTTP ab, wie die Prüfungen von «Website-Zustand». Liefert der Server den Inhalt,
	 * ist das Verzeichnis offen. Ein eigenes Verzeichnis per Konstante gilt als geschützt.
	 *
	 * @param bool $fresh Gemerktes Ergebnis ignorieren.
	 * @return string self::PROTECTED, self::EXPOSED oder self::UNKNOWN (z. B. kein Zugriff auf die eigene Website).
	 */
	public static function exposure( bool $fresh = false ): string {
		if ( self::configured_outside() ) {
			return self::PROTECTED;
		}
		$cached = get_transient( self::CHECK_TRANSIENT );
		if ( ! $fresh && is_string( $cached ) && in_array( $cached, array( self::PROTECTED, self::EXPOSED, self::UNKNOWN ), true ) ) {
			return $cached;
		}

		$base   = self::base_dir();
		$name   = 'pruefung-' . wp_generate_password( 16, false ) . '.txt';
		$secret = wp_generate_password( 32, false );
		$result = self::UNKNOWN;
		if ( self::protect( $base ) && false !== file_put_contents( $base . '/' . $name, $secret ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Testdatei, wird sofort gelöscht.
			$response = wp_remote_get(
				untrailingslashit( wp_upload_dir( null, false )['baseurl'] ) . '/' . self::DIR . '/' . $name,
				array(
					'timeout'     => 5,
					'redirection' => 0,
					'sslverify'   => apply_filters( 'https_local_ssl_verify', false ), // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Filter von WordPress für Anfragen an die eigene Website.
				)
			);
			wp_delete_file( $base . '/' . $name );
			if ( ! is_wp_error( $response ) ) {
				$code   = (int) wp_remote_retrieve_response_code( $response );
				$result = match ( true ) {
					200 === $code && trim( (string) wp_remote_retrieve_body( $response ) ) === $secret => self::EXPOSED,
					in_array( $code, array( 401, 403, 404 ), true ) => self::PROTECTED,
					default => self::UNKNOWN,
				};
			}
		}
		set_transient( self::CHECK_TRANSIENT, $result, 12 * HOUR_IN_SECONDS );
		return $result;
	}

	/**
	 * Test für «Werkzeuge → Website-Zustand».
	 *
	 * @param array<string,mixed> $tests Tests von WordPress.
	 * @return array<string,mixed>
	 */
	public static function site_status_tests( array $tests ): array {
		$tests['direct']['ttleague_photo_storage'] = array(
			'label' => __( 'Fotos der Spielberichte', 'ttleague' ),
			'test'  => array( self::class, 'site_status' ),
		);
		return $tests;
	}

	/**
	 * @return array<string,mixed>
	 */
	public static function site_status(): array {
		$state  = self::exposure( true );
		$status = match ( $state ) {
			self::PROTECTED => 'good',
			self::EXPOSED   => 'critical',
			default         => 'recommended',
		};
		$label = match ( $state ) {
			self::PROTECTED => __( 'Die Fotos der Spielberichte sind vor direktem Zugriff geschützt', 'ttleague' ),
			self::EXPOSED   => __( 'Die Fotos der Spielberichte wären direkt erreichbar; Uploads sind gesperrt', 'ttleague' ),
			default         => __( 'Der Schutz der Fotos der Spielberichte liess sich nicht prüfen', 'ttleague' ),
		};
		return array(
			'label'       => $label,
			'status'      => $status,
			'badge'       => array(
				'label' => __( 'Sicherheit', 'ttleague' ),
				'color' => 'critical' === $status ? 'red' : 'blue',
			),
			'description' => '<p>' . esc_html__( 'Fotos liegen im Verzeichnis uploads/ttleague-private und werden nur über die REST-API mit Rechteprüfung ausgeliefert. Unter nginx braucht es dafür eine Regel im Server, z. B. «location ^~ /wp-content/uploads/ttleague-private/ { deny all; }», oder ein Verzeichnis ausserhalb des Web-Roots mit der Konstante TTLEAGUE_PRIVATE_UPLOADS.', 'ttleague' ) . '</p>',
			'actions'     => '',
			'test'        => 'ttleague_photo_storage',
		);
	}

	/**
	 * Eigenes Verzeichnis per Konstante (ausserhalb des Web-Roots, Verantwortung der Verwaltung).
	 */
	private static function configured_outside(): bool {
		return defined( 'TTLEAGUE_PRIVATE_UPLOADS' ) && is_string( TTLEAGUE_PRIVATE_UPLOADS ) && '' !== TTLEAGUE_PRIVATE_UPLOADS;
	}
}
