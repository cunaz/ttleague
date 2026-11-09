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
 * - Standard: `uploads/ttleague-private/` mit `.htaccess` (Apache) und `index.php`.
 *
 * Ob der Webserver das Verzeichnis wirklich sperrt (nginx liest keine `.htaccess`), prüft
 * exposure() über HTTP mit Testdateien in jedem erlaubten Bildformat, im Unterverzeichnis, in
 * dem auch die Fotos landen. Das gilt auch für ein Verzeichnis der Konstante innerhalb von
 * WordPress oder des Web-Roots (DOCUMENT_ROOT). Uploads gibt es nur, wenn die Prüfung den
 * Schutz bestätigt oder das Verzeichnis der Konstante ausserhalb von WordPress und Web-Root
 * liegt (keine Adresse, Verantwortung der Verwaltung).
 *
 * Ausgeliefert wird nur über `GET /wp-json/ttleague/v1/fixtures/{id}/photo` mit Rechteprüfung.
 */
final class PhotoStorage {

	public const DIR = 'ttleague-private';

	/** Ergebnis der Prüfung mit ihrem Bereich (Verzeichnis, Upload-Unterverzeichnis, Web-Root), 12 Stunden gemerkt. */
	public const CHECK_TRANSIENT = 'ttleague_photo_storage_check';

	/** Über HTTP geprüft: Jede Testdatei wird abgewiesen. */
	public const PROTECTED = 'protected';

	/** Verzeichnis der Konstante ausserhalb von WordPress und Web-Root, ohne Adresse; nicht über HTTP prüfbar. */
	public const OUTSIDE = 'outside';

	public const EXPOSED = 'exposed';
	public const UNKNOWN = 'unknown';

	private const STATES = array( self::PROTECTED, self::OUTSIDE, self::EXPOSED, self::UNKNOWN );

	/** Zustände, in denen Uploads erlaubt sind. */
	private const ACCEPTED = array( self::PROTECTED, self::OUTSIDE );

	/**
	 * Erlaubte Bildformate für Fotos des Spielberichts. Die Prüfung über HTTP ruft für jede
	 * Dateiendung eine Testdatei ab.
	 */
	public const MIMES = array(
		'jpg|jpeg|jpe' => 'image/jpeg',
		'png'          => 'image/png',
		'webp'         => 'image/webp',
		'heic'         => 'image/heic',
		'heif'         => 'image/heif',
	);

	private const HTACCESS = "# ttleague: Fotos der Spielberichte nur über die REST-API.\nRequire all denied\n<IfModule !mod_authz_core.c>\n\tOrder allow,deny\n\tDeny from all\n</IfModule>\n";

	private const INDEX = "<?php\n// Silence is golden.\n";

	public static function register(): void {
		add_filter( 'site_status_tests', array( self::class, 'site_status_tests' ) );
		add_action( 'delete_attachment', array( self::class, 'delete_files' ) );
	}

	/**
	 * Basisverzeichnis der Fotos (ohne abschliessenden Schrägstrich).
	 */
	public static function base_dir(): string {
		if ( self::configured() ) {
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
	 * @throws ResultException 503, wenn die Ablage nicht geschützt ist oder der Schutz nicht bestätigt ist.
	 */
	public static function in_private_dir( callable $upload ): mixed {
		$base = self::base_dir();
		if ( ! self::protect( $base ) ) {
			throw ResultException::unavailable( __( 'Die Ablage für Fotos lässt sich nicht schützen. Bitte wende dich an die TK.', 'ttleague' ) );
		}
		// Uploads gibt es nur bei bestätigtem Schutz oder mit einem Verzeichnis ausserhalb von
		// WordPress und Web-Root. Alles andere prüft der Upload neu, auch ein gemerktes «offen»:
		// Die Serverregel kann inzwischen greifen.
		$state = self::cached();
		if ( ! in_array( $state, self::ACCEPTED, true ) ) {
			$state = self::exposure( true );
		}
		if ( self::EXPOSED === $state ) {
			throw ResultException::unavailable( __( 'Die Ablage für Fotos ist von aussen erreichbar; Fotos sind deshalb gesperrt. Bitte wende dich an die TK.', 'ttleague' ) );
		}
		if ( ! in_array( $state, self::ACCEPTED, true ) ) {
			throw ResultException::unavailable( __( 'Der Schutz der Ablage für Fotos liess sich nicht prüfen; Fotos sind deshalb gesperrt. Bitte wende dich an die TK.', 'ttleague' ) );
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
	 * Löscht die Dateien eines Anhangs im Verzeichnis der Fotos, wenn WordPress den Anhang löscht
	 * (Mediathek, Aufräumen nach einem gescheiterten Upload, Zurücksetzen der Demo). WordPress
	 * selbst löscht nur innerhalb von uploads (wp_delete_file_from_directory()); in einem
	 * Verzeichnis der Konstante ausserhalb davon blieben die Fotos sonst liegen. Gelöscht wird
	 * nur, was nach Auflösen symbolischer Links im Verzeichnis der Fotos liegt.
	 *
	 * @param int $attachment_id Anhang.
	 */
	public static function delete_files( int $attachment_id ): void {
		$file = get_attached_file( $attachment_id, true );
		if ( ! is_string( $file ) || '' === $file ) {
			return;
		}
		$names = array( wp_basename( $file ) );
		$meta  = wp_get_attachment_metadata( $attachment_id, true );
		if ( is_array( $meta ) ) {
			// Fassungen im selben Verzeichnis: Original (z. B. vor der Umwandlung von HEIC), Quelle, Grössen.
			foreach ( array( 'original_image', 'source_image' ) as $key ) {
				if ( isset( $meta[ $key ] ) && is_string( $meta[ $key ] ) && '' !== $meta[ $key ] ) {
					$names[] = wp_basename( $meta[ $key ] );
				}
			}
			foreach ( (array) ( $meta['sizes'] ?? array() ) as $size ) {
				if ( is_array( $size ) && isset( $size['file'] ) && is_string( $size['file'] ) && '' !== $size['file'] ) {
					$names[] = wp_basename( $size['file'] );
				}
			}
		}
		$base = self::resolved( self::base_dir() );
		foreach ( array_unique( $names ) as $name ) {
			$real = realpath( dirname( $file ) . '/' . $name );
			if ( false !== $real && is_file( $real ) && str_starts_with( wp_normalize_path( $real ), $base . '/' ) ) {
				wp_delete_file( $real );
			}
		}
	}

	/**
	 * Ist die Ablage von aussen erreichbar? Legt Testdateien mit zufälligem Inhalt ab und ruft
	 * sie über HTTP ab (probe()), wie die Prüfungen von «Website-Zustand». Liefert der Server
	 * einen Inhalt, ist das Verzeichnis offen. Ein Verzeichnis der Konstante ausserhalb von WordPress und
	 * Web-Root hat keine Adresse: Dass es nicht ausgeliefert wird (auch nicht über einen Alias),
	 * verantwortet die Verwaltung (self::OUTSIDE).
	 *
	 * @param bool $fresh Gemerktes Ergebnis ignorieren.
	 * @return string self::PROTECTED, self::OUTSIDE, self::EXPOSED oder self::UNKNOWN (z. B. kein Zugriff auf die eigene Website).
	 */
	public static function exposure( bool $fresh = false ): string {
		$cached = $fresh ? null : self::cached();
		if ( null !== $cached ) {
			return $cached;
		}

		$base   = self::base_dir();
		$result = self::UNKNOWN;
		if ( self::protect( $base ) ) {
			// Ein Verzeichnis der Konstante ausserhalb von WordPress und Web-Root hat keine Adresse.
			$url    = self::configured() ? self::url_of( $base ) : untrailingslashit( wp_upload_dir( null, false )['baseurl'] ) . '/' . self::DIR;
			$result = null === $url ? self::OUTSIDE : self::probe( $base, $url );
		}
		set_transient(
			self::CHECK_TRANSIENT,
			array(
				'scope' => self::scope(),
				'state' => $result,
			),
			12 * HOUR_IN_SECONDS
		);
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
	 * Test für «Website-Zustand»: prüft immer neu.
	 *
	 * @return array<string,mixed>
	 */
	public static function site_status(): array {
		return self::report( self::exposure( true ) );
	}

	/**
	 * Ergebnis für «Website-Zustand» zu einem Zustand der Ablage.
	 *
	 * @param string $state self::PROTECTED, self::OUTSIDE, self::EXPOSED oder self::UNKNOWN.
	 * @return array<string,mixed>
	 */
	public static function report( string $state ): array {
		$status = match ( $state ) {
			self::PROTECTED, self::OUTSIDE => 'good',
			self::EXPOSED                  => 'critical',
			default                        => 'recommended',
		};
		$label = match ( $state ) {
			self::PROTECTED => __( 'Die Fotos der Spielberichte sind vor direktem Zugriff geschützt', 'ttleague' ),
			self::OUTSIDE   => __( 'Die Fotos der Spielberichte liegen ausserhalb von WordPress und Web-Root', 'ttleague' ),
			self::EXPOSED   => __( 'Die Fotos der Spielberichte wären direkt erreichbar; Uploads sind gesperrt', 'ttleague' ),
			default         => __( 'Der Schutz der Fotos der Spielberichte liess sich nicht prüfen; Uploads sind gesperrt', 'ttleague' ),
		};
		$text = self::OUTSIDE === $state
			? __( 'Die Fotos liegen im Verzeichnis aus der Konstante TTLEAGUE_PRIVATE_UPLOADS. Es liegt ausserhalb von WordPress und des Web-Roots und hat deshalb keine Adresse, die das Plugin über HTTP prüfen könnte. Prüfe einmal von Hand, dass der Webserver es nicht ausliefert, auch nicht über einen Alias. Ausgeliefert werden die Fotos nur über die REST-API mit Rechteprüfung.', 'ttleague' )
			: __( 'Fotos liegen im Verzeichnis uploads/ttleague-private und werden nur über die REST-API mit Rechteprüfung ausgeliefert. Uploads gibt es nur, wenn Testdateien in jedem erlaubten Bildformat in diesem Verzeichnis über HTTP nicht abrufbar sind. Unter nginx braucht es dafür eine Regel im Server, z. B. «location ^~ /wp-content/uploads/ttleague-private/ { deny all; }». Ohne Prüfung über HTTP geht es mit einem Verzeichnis ausserhalb des Web-Roots in der Konstante TTLEAGUE_PRIVATE_UPLOADS.', 'ttleague' );
		return array(
			'label'       => $label,
			'status'      => $status,
			'badge'       => array(
				'label' => __( 'Sicherheit', 'ttleague' ),
				'color' => 'critical' === $status ? 'red' : 'blue',
			),
			'description' => '<p>' . esc_html( $text ) . '</p>',
			'actions'     => '',
			'test'        => 'ttleague_photo_storage',
		);
	}

	/**
	 * Gemerktes Ergebnis der Prüfung, nur für denselben Bereich: dasselbe Verzeichnis (Konstante
	 * gesetzt oder entfernt), dasselbe Upload-Unterverzeichnis (neuer Monat) und derselbe Web-Root
	 * (WP-CLI kennt keinen).
	 */
	private static function cached(): ?string {
		$cached = get_transient( self::CHECK_TRANSIENT );
		if ( ! is_array( $cached ) || ( $cached['scope'] ?? null ) !== self::scope() || ! in_array( $cached['state'] ?? null, self::STATES, true ) ) {
			return null;
		}
		return $cached['state'];
	}

	/**
	 * Bereich eines Ergebnisses: Verzeichnis der Fotos, Upload-Unterverzeichnis und Web-Root.
	 */
	private static function scope(): string {
		return implode( "\n", array( self::base_dir(), (string) wp_upload_dir( null, false )['subdir'], self::document_root() ) );
	}

	/**
	 * Legt Testdateien mit zufälligem Inhalt ab und ruft sie über HTTP ab: eine pro erlaubter
	 * Dateiendung, im Unterverzeichnis, in das der nächste Upload käme (z. B. 2026/11). So greift
	 * eine Regel, die nur bestimmte Endungen oder Pfade sperrt, nicht nur bei der Testdatei.
	 * Geschützt ist die Ablage nur, wenn jede Testdatei mit 401, 403 oder 404 abgewiesen wird.
	 *
	 * @param string $dir Verzeichnis der Fotos.
	 * @param string $url Adresse, unter der der Webserver das Verzeichnis ausliefern würde.
	 */
	private static function probe( string $dir, string $url ): string {
		$subdir = (string) wp_upload_dir( null, false )['subdir'];
		if ( ! wp_mkdir_p( $dir . $subdir ) ) {
			return self::UNKNOWN;
		}
		$secret = wp_generate_password( 32, false );
		$result = self::PROTECTED;
		foreach ( self::extensions() as $extension ) {
			$name = $subdir . '/pruefung-' . wp_generate_password( 16, false ) . '.' . $extension;
			if ( false === file_put_contents( $dir . $name, $secret ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Testdatei, wird sofort gelöscht.
				return self::UNKNOWN;
			}
			$response = wp_remote_get(
				$url . $name,
				array(
					'timeout'     => 5,
					'redirection' => 0,
					'sslverify'   => apply_filters( 'https_local_ssl_verify', false ), // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Filter von WordPress für Anfragen an die eigene Website.
				)
			);
			wp_delete_file( $dir . $name );
			if ( is_wp_error( $response ) ) {
				// Keine Verbindung: Weitere Abrufe würden nur warten.
				return self::UNKNOWN;
			}
			$code = (int) wp_remote_retrieve_response_code( $response );
			if ( 200 === $code && trim( (string) wp_remote_retrieve_body( $response ) ) === $secret ) {
				return self::EXPOSED;
			}
			if ( ! in_array( $code, array( 401, 403, 404 ), true ) ) {
				$result = self::UNKNOWN;
			}
		}
		return $result;
	}

	/**
	 * Erlaubte Dateiendungen der Fotos (jpg, jpeg, jpe, png, webp, heic, heif).
	 *
	 * @return list<string>
	 */
	public static function extensions(): array {
		return array_merge( ...array_map( static fn( string $key ): array => explode( '|', $key ), array_keys( self::MIMES ) ) );
	}

	/**
	 * Adresse, unter der der Webserver ein Verzeichnis ausliefern würde: innerhalb von WordPress
	 * (uploads, wp-content, ABSPATH) oder des Web-Roots der laufenden Anfrage (DOCUMENT_ROOT, z. B.
	 * wenn WordPress in einem Unterverzeichnis liegt), sonst null. Symbolische Links werden
	 * aufgelöst.
	 *
	 * @param string $dir Verzeichnis.
	 */
	public static function url_of( string $dir ): ?string {
		$uploads = wp_upload_dir( null, false );
		$roots   = array(
			array( $uploads['basedir'], $uploads['baseurl'] ),
			array( WP_CONTENT_DIR, content_url() ),
			array( ABSPATH, site_url() ),
		);
		$web     = self::document_root();
		$home    = wp_parse_url( home_url() );
		if ( '' !== $web && is_array( $home ) && isset( $home['scheme'], $home['host'] ) ) {
			$roots[] = array( $web, $home['scheme'] . '://' . $home['host'] . ( isset( $home['port'] ) ? ':' . $home['port'] : '' ) );
		}
		$path = self::resolved( $dir );
		foreach ( $roots as list( $root, $url ) ) {
			$root = self::resolved( $root );
			if ( $path === $root || str_starts_with( $path, $root . '/' ) ) {
				return untrailingslashit( $url ) . substr( $path, strlen( $root ) );
			}
		}
		return null;
	}

	/**
	 * Web-Root der laufenden Anfrage, leer ohne Webserver (WP-CLI, Cron über die Kommandozeile).
	 */
	private static function document_root(): string {
		$root = $_SERVER['DOCUMENT_ROOT'] ?? ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- Pfad vom Webserver, nur verglichen.
		return is_string( $root ) && '' !== $root ? self::resolved( $root ) : '';
	}

	/**
	 * Pfad ohne symbolische Links und abschliessenden Schrägstrich; unverändert, wenn es ihn nicht gibt.
	 */
	private static function resolved( string $path ): string {
		$real = realpath( $path );
		return untrailingslashit( wp_normalize_path( false === $real ? $path : $real ) );
	}

	/**
	 * Eigenes Verzeichnis per Konstante.
	 */
	private static function configured(): bool {
		return defined( 'TTLEAGUE_PRIVATE_UPLOADS' ) && is_string( TTLEAGUE_PRIVATE_UPLOADS ) && '' !== TTLEAGUE_PRIVATE_UPLOADS;
	}
}
