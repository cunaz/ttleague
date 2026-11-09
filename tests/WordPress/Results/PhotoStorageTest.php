<?php
/**
 * Ablage der Fotos: Prüfung über HTTP, Sperre der Uploads, Website-Zustand.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Tests\WordPress\Results;

use PHPUnit\Framework\Attributes\DataProvider;
use TTLeague\Results\PhotoStorage;
use TTLeague\Results\ResultException;
use TTLeague\Tests\WordPress\TestCase;

/**
 * Den Webserver spielt pre_http_request: Er liefert die Testdatei aus (offen), sperrt sie,
 * leitet weiter, liefert eine andere Seite oder ist nicht erreichbar. Die Testsuite setzt
 * TTLEAGUE_PRIVATE_UPLOADS nicht; geprüft wird das Standardverzeichnis uploads/ttleague-private.
 */
final class PhotoStorageTest extends TestCase {

	/** Verhalten des Webservers, siehe respond(). */
	private string $server = 'offline';

	/** @var list<string> Abgerufene Adressen. */
	private array $requests = array();

	public function set_up(): void {
		parent::set_up();
		$this->server   = 'offline';
		$this->requests = array();
		delete_transient( PhotoStorage::CHECK_TRANSIENT );
		add_filter( 'pre_http_request', array( $this, 'respond' ), 10, 3 );
	}

	/**
	 * Antwort des Webservers auf den Abruf der Testdatei.
	 *
	 * @param false|array<string,mixed>|\WP_Error $pre  Vorbelegung.
	 * @param array<string,mixed>                 $args Anfrage.
	 * @param string                              $url  Adresse.
	 * @return array<string,mixed>|\WP_Error
	 */
	public function respond( $pre, array $args, string $url ) {
		$this->requests[] = $url;
		self::assertSame( 0, $args['redirection'], 'Weiterleitungen werden nicht verfolgt' );
		$file = PhotoStorage::base_dir() . '/' . basename( (string) wp_parse_url( $url, PHP_URL_PATH ) );
		return match ( $this->server ) {
			'exposed'  => self::response( 200, (string) file_get_contents( $file ) ), // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Testdatei der Prüfung.
			'redirect' => self::response( 301, '' ),
			'other'    => self::response( 200, '<html>Seite nicht gefunden</html>' ),
			'error'    => self::response( 500, '' ),
			'offline'  => new \WP_Error( 'http_request_failed', 'cURL error 7: Failed to connect' ),
			default    => self::response( (int) $this->server, '' ),
		};
	}

	public function test_a_directory_served_by_the_web_server_blocks_uploads(): void {
		$this->server = 'exposed';

		self::assertSame( PhotoStorage::EXPOSED, PhotoStorage::exposure() );
		self::assertCount( 1, $this->requests );
		self::assertMatchesRegularExpression( '#^' . preg_quote( wp_upload_dir()['baseurl'], '#' ) . '/ttleague-private/pruefung-[A-Za-z0-9]{16}\.txt$#', $this->requests[0] );
		self::assertSame( array(), glob( PhotoStorage::base_dir() . '/pruefung-*' ), 'Testdatei gelöscht' );

		$error = $this->refusal();
		self::assertSame( ResultException::UNAVAILABLE, $error->error_code );
		self::assertSame( 503, $error->status );
		self::assertStringContainsString( 'von aussen erreichbar', $error->getMessage() );
	}

	/**
	 * @return array<string,array{0:string}>
	 */
	public static function refusing_servers(): array {
		return array(
			'401' => array( '401' ),
			'403' => array( '403' ),
			'404' => array( '404' ),
		);
	}

	#[DataProvider( 'refusing_servers' )]
	public function test_a_refused_test_file_allows_uploads( string $server ): void {
		$this->server = $server;

		self::assertSame( 'hochgeladen', PhotoStorage::in_private_dir( static fn(): string => 'hochgeladen' ) );
		self::assertSame( PhotoStorage::PROTECTED, PhotoStorage::exposure() );
		self::assertCount( 1, $this->requests, 'das bestätigte Ergebnis gilt weiter' );
	}

	/**
	 * @return array<string,array{0:string}>
	 */
	public static function inconclusive_servers(): array {
		return array(
			'nicht erreichbar'     => array( 'offline' ),
			'Weiterleitung'        => array( 'redirect' ),
			'andere Seite mit 200' => array( 'other' ),
			'Serverfehler'         => array( 'error' ),
		);
	}

	#[DataProvider( 'inconclusive_servers' )]
	public function test_without_a_confirmed_protection_uploads_stay_blocked( string $server ): void {
		$this->server = $server;

		$error = $this->refusal();

		self::assertSame( 503, $error->status );
		self::assertStringContainsString( 'liess sich nicht prüfen', $error->getMessage() );
		self::assertSame( PhotoStorage::UNKNOWN, PhotoStorage::exposure() );
	}

	/**
	 * Nach einem «offen» hebt erst ein bestätigter Schutz die Sperre auf; ein «unbekannt» bei der
	 * erneuten Prüfung genügt nicht. Ein bestätigter Schutz gilt danach ohne neuen Abruf.
	 */
	public function test_after_exposed_only_a_confirmed_protection_lifts_the_block(): void {
		$this->server = 'exposed';
		self::assertSame( PhotoStorage::EXPOSED, PhotoStorage::exposure() );

		$this->server = 'offline';
		self::assertStringContainsString( 'liess sich nicht prüfen', $this->refusal()->getMessage() );
		self::assertCount( 2, $this->requests, 'das gemerkte «offen» wird beim Upload neu geprüft' );

		$this->server = '403';
		self::assertSame( 'hochgeladen', PhotoStorage::in_private_dir( static fn(): string => 'hochgeladen' ) );
		self::assertCount( 3, $this->requests );

		$this->server = 'exposed';
		self::assertSame( 'hochgeladen', PhotoStorage::in_private_dir( static fn(): string => 'hochgeladen' ) );
		self::assertCount( 3, $this->requests, 'bestätigter Schutz gilt 12 Stunden ohne neuen Abruf' );
		self::assertSame( PhotoStorage::EXPOSED, PhotoStorage::exposure( true ), 'neue Prüfung, z. B. im Website-Zustand' );
		self::assertStringContainsString( 'von aussen erreichbar', $this->refusal()->getMessage() );
	}

	public function test_a_remembered_result_counts_only_for_the_same_directory(): void {
		$this->server = 'exposed';
		set_transient(
			PhotoStorage::CHECK_TRANSIENT,
			array(
				'dir'   => '/anderes/verzeichnis',
				'state' => PhotoStorage::PROTECTED,
			)
		);
		self::assertStringContainsString( 'von aussen erreichbar', $this->refusal()->getMessage() );

		// Ergebnis einer früheren Fassung ohne Verzeichnis.
		set_transient( PhotoStorage::CHECK_TRANSIENT, PhotoStorage::PROTECTED );
		self::assertSame( PhotoStorage::EXPOSED, PhotoStorage::exposure() );
		self::assertCount( 2, $this->requests );
	}

	public function test_uploads_land_in_the_private_directory_without_image_sizes(): void {
		$this->server = '403';
		$base         = PhotoStorage::base_dir();
		$public       = wp_upload_dir()['path'];

		$seen = PhotoStorage::in_private_dir(
			static fn(): array => array(
				'path'      => wp_upload_dir()['path'],
				'sizes'     => apply_filters( 'intermediate_image_sizes_advanced', array( 'thumbnail' => array() ) ),
				'threshold' => apply_filters( 'big_image_size_threshold', 2560 ),
			)
		);

		self::assertStringStartsWith( $base . '/', $seen['path'] );
		self::assertSame( array(), $seen['sizes'], 'keine Vorschaubilder' );
		self::assertFalse( $seen['threshold'], 'keine verkleinerte Fassung' );
		self::assertSame( $public, wp_upload_dir()['path'], 'Filter danach entfernt' );

		try {
			PhotoStorage::in_private_dir(
				static function (): void {
					throw new \RuntimeException( 'Upload gescheitert' );
				}
			);
		} catch ( \RuntimeException $e ) {
			self::assertSame( 'Upload gescheitert', $e->getMessage() );
		}
		self::assertSame( $public, wp_upload_dir()['path'], 'Filter auch nach einem Fehler entfernt' );
		self::assertFalse( has_filter( 'intermediate_image_sizes_advanced', '__return_empty_array' ) );
		self::assertFalse( has_filter( 'big_image_size_threshold', '__return_false' ) );
	}

	public function test_protection_files_are_restored(): void {
		$base = PhotoStorage::base_dir();
		self::assertTrue( PhotoStorage::protect( $base ) );
		$htaccess = (string) file_get_contents( $base . '/.htaccess' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Schutzdatei im Test.
		self::assertStringContainsString( 'Require all denied', $htaccess );

		file_put_contents( $base . '/.htaccess', "Options +Indexes\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Schutzdatei im Test verändert.
		wp_delete_file( $base . '/index.php' );
		self::assertTrue( PhotoStorage::protect( $base ) );

		self::assertSame( $htaccess, file_get_contents( $base . '/.htaccess' ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Schutzdatei im Test.
		self::assertFileExists( $base . '/index.php' );
	}

	/**
	 * WordPress löscht Dateien nur innerhalb von uploads; mit TTLEAGUE_PRIVATE_UPLOADS ausserhalb
	 * davon löscht PhotoStorage die Fotos selbst, aber nichts ausserhalb des Verzeichnisses der
	 * Fotos, auch nicht über einen symbolischen Link.
	 */
	public function test_deleting_the_attachment_removes_its_files_only_in_the_private_directory(): void {
		self::assertSame( 10, has_action( 'delete_attachment', array( PhotoStorage::class, 'delete_files' ) ) );
		$dir     = PhotoStorage::base_dir() . '/2026/11';
		$name    = 'spielbericht-1-' . wp_generate_password( 6, false );
		$outside = get_temp_dir() . 'ttl-fremd-' . wp_generate_password( 6, false ) . '.jpg';
		$public  = wp_upload_dir()['basedir'] . '/ttl-oeffentlich-' . wp_generate_password( 6, false ) . '.jpg';
		wp_mkdir_p( $dir );
		foreach ( array( "$dir/$name.jpg", "$dir/$name-original.heic", $outside, $public ) as $file ) {
			file_put_contents( $file, 'Bild' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Datei für den Test.
		}
		symlink( $outside, "$dir/$name-link.jpg" );
		$photo = self::factory()->attachment->create_object(
			array(
				'file'           => "$dir/$name.jpg",
				'post_mime_type' => 'image/jpeg',
				'post_status'    => 'private',
			)
		);
		wp_update_attachment_metadata(
			$photo,
			array(
				'original_image' => "$name-original.heic",
				'sizes'          => array( 'thumbnail' => array( 'file' => "$name-link.jpg" ) ),
			)
		);
		$other = self::factory()->attachment->create_object(
			array(
				'file'           => $public,
				'post_mime_type' => 'image/jpeg',
			)
		);

		try {
			PhotoStorage::delete_files( $photo );
			PhotoStorage::delete_files( $other );

			self::assertFileDoesNotExist( "$dir/$name.jpg" );
			self::assertFileDoesNotExist( "$dir/$name-original.heic", 'Original vor der Umwandlung' );
			self::assertFileExists( $outside, 'Ziel eines Links ausserhalb des Verzeichnisses der Fotos' );
			self::assertFileExists( $public, 'Anhang ausserhalb des Verzeichnisses der Fotos' );
		} finally {
			foreach ( array( "$dir/$name-link.jpg", $outside, $public ) as $file ) {
				if ( is_link( $file ) || is_file( $file ) ) {
					unlink( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Dateien aus dem Test.
				}
			}
		}
	}

	/**
	 * Für TTLEAGUE_PRIVATE_UPLOADS: Ein Verzeichnis innerhalb von WordPress hat eine Adresse und
	 * wird über HTTP geprüft, eines ausserhalb nicht. Symbolische Links werden aufgelöst.
	 */
	public function test_url_of_maps_directories_within_wordpress(): void {
		$uploads = wp_upload_dir();
		$inside  = WP_CONTENT_DIR . '/ttl-privat-' . wp_generate_password( 6, false );
		$outside = get_temp_dir() . 'ttl-privat-' . wp_generate_password( 6, false );
		$link    = get_temp_dir() . 'ttl-link-' . wp_generate_password( 6, false );
		wp_mkdir_p( $inside );
		wp_mkdir_p( $outside );
		symlink( $inside, $link );
		try {
			self::assertSame( $uploads['baseurl'] . '/ttleague-private', PhotoStorage::url_of( $uploads['basedir'] . '/ttleague-private' ) );
			self::assertSame( content_url( basename( $inside ) ), PhotoStorage::url_of( $inside ) );
			self::assertSame( content_url( basename( $inside ) ), PhotoStorage::url_of( $link ), 'Link in den Web-Root' );
			self::assertSame( site_url( '/wp-includes' ), PhotoStorage::url_of( ABSPATH . 'wp-includes' ) );
			self::assertNull( PhotoStorage::url_of( $outside ) );
		} finally {
			unlink( $link ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- symbolischer Link aus dem Test.
			rmdir( $inside ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Verzeichnis aus dem Test.
			rmdir( $outside ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Verzeichnis aus dem Test.
		}
	}

	/**
	 * @return array<string,array{0:string,1:string,2:string}>
	 */
	public static function site_health_states(): array {
		return array(
			'geschützt'     => array( '403', 'good', 'blue' ),
			'offen'         => array( 'exposed', 'critical', 'red' ),
			'nicht prüfbar' => array( 'offline', 'recommended', 'blue' ),
		);
	}

	#[DataProvider( 'site_health_states' )]
	public function test_site_health_checks_again_and_reports_the_state( string $server, string $status, string $color ): void {
		self::assertSame( 10, has_filter( 'site_status_tests', array( PhotoStorage::class, 'site_status_tests' ) ) );
		self::assertArrayHasKey( 'ttleague_photo_storage', PhotoStorage::site_status_tests( array( 'direct' => array() ) )['direct'] );
		set_transient(
			PhotoStorage::CHECK_TRANSIENT,
			array(
				'dir'   => PhotoStorage::base_dir(),
				'state' => PhotoStorage::PROTECTED,
			)
		);
		$this->server = $server;

		$result = PhotoStorage::site_status();

		self::assertCount( 1, $this->requests, 'Website-Zustand prüft immer neu' );
		self::assertSame( $status, $result['status'] );
		self::assertSame( $color, $result['badge']['color'] );
		self::assertSame( 'ttleague_photo_storage', $result['test'] );
	}

	/**
	 * Der Upload muss mit einer ResultException abgelehnt werden, ohne ihn auszuführen.
	 */
	private function refusal(): ResultException {
		$ran = false;
		try {
			PhotoStorage::in_private_dir(
				static function () use ( &$ran ): void {
					$ran = true;
				}
			);
		} catch ( ResultException $e ) {
			self::assertFalse( $ran, 'Upload nicht ausgeführt' );
			return $e;
		}
		self::fail( 'Upload hätte gesperrt sein müssen.' );
	}

	/**
	 * @return array<string,mixed>
	 */
	private static function response( int $code, string $body ): array {
		return array(
			'headers'  => array(),
			'body'     => $body,
			'response' => array(
				'code'    => $code,
				'message' => get_status_header_desc( $code ),
			),
			'cookies'  => array(),
			'filename' => null,
		);
	}
}
