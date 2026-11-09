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
 * Den Webserver spielt pre_http_request: Er liefert die Testdateien aus (offen) oder nur eine
 * Endung, sperrt sie, leitet weiter, liefert eine andere Seite oder ist nicht erreichbar. Die
 * Testsuite setzt TTLEAGUE_PRIVATE_UPLOADS nicht; geprüft wird das Standardverzeichnis
 * uploads/ttleague-private. Eine Prüfung ruft je erlaubter Endung eine Testdatei ab, zuerst .jpg.
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
	 * Antwort des Webservers auf den Abruf einer Testdatei.
	 *
	 * @param false|array<string,mixed>|\WP_Error $pre  Vorbelegung.
	 * @param array<string,mixed>                 $args Anfrage.
	 * @param string                              $url  Adresse.
	 * @return array<string,mixed>|\WP_Error
	 */
	public function respond( $pre, array $args, string $url ) {
		$this->requests[] = $url;
		self::assertSame( 0, $args['redirection'], 'Weiterleitungen werden nicht verfolgt' );
		$file  = PhotoStorage::base_dir() . substr( $url, strlen( self::base_url() ) );
		$serve = 'exposed' === $this->server || ( 'webp' === $this->server && str_ends_with( $url, '.webp' ) );
		return match ( true ) {
			$serve                     => self::response( 200, (string) file_get_contents( $file ) ), // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Testdatei der Prüfung.
			'webp' === $this->server   => self::response( 403, '' ),
			'redirect' === $this->server => self::response( 301, '' ),
			'other' === $this->server  => self::response( 200, '<html>Seite nicht gefunden</html>' ),
			'error' === $this->server  => self::response( 500, '' ),
			'offline' === $this->server => new \WP_Error( 'http_request_failed', 'cURL error 7: Failed to connect' ),
			default                    => self::response( (int) $this->server, '' ),
		};
	}

	public function test_a_directory_served_by_the_web_server_blocks_uploads(): void {
		$this->server = 'exposed';

		self::assertSame( PhotoStorage::EXPOSED, PhotoStorage::exposure() );
		self::assertCount( 1, $this->requests, 'die erste ausgelieferte Testdatei genügt' );
		self::assertMatchesRegularExpression( '#^' . preg_quote( self::base_url() . wp_upload_dir()['subdir'], '#' ) . '/pruefung-[A-Za-z0-9]{16}\.jpg$#', $this->requests[0], 'im Unterverzeichnis des nächsten Uploads' );
		self::assertSame( array(), $this->leftovers(), 'Testdateien gelöscht' );

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
	public function test_refused_test_files_in_every_format_allow_uploads( string $server ): void {
		$this->server = $server;

		self::assertSame( 'hochgeladen', PhotoStorage::in_private_dir( static fn(): string => 'hochgeladen' ) );
		self::assertSame( PhotoStorage::extensions(), $this->extensions(), 'je eine Testdatei pro erlaubter Endung' );
		self::assertSame( array( 'jpg', 'jpeg', 'jpe', 'png', 'webp', 'heic', 'heif' ), PhotoStorage::extensions() );
		self::assertSame( array(), $this->leftovers() );
		self::assertSame( PhotoStorage::PROTECTED, PhotoStorage::exposure() );
		self::assertSame( 1, $this->checks(), 'das bestätigte Ergebnis gilt weiter' );
	}

	/**
	 * Eine Serverregel sperrt nur einen Teil der Endungen; unter nginx gewinnt etwa eine Regel für
	 * Bilder mit regulärem Ausdruck gegen eine Sperre ohne ^~. Hier liefert der Server nur .webp.
	 */
	public function test_a_server_that_serves_one_photo_format_counts_as_exposed(): void {
		$this->server = 'webp';

		self::assertStringContainsString( 'von aussen erreichbar', $this->refusal()->getMessage() );
		self::assertSame( array( 'jpg', 'jpeg', 'jpe', 'png', 'webp' ), $this->extensions() );
		self::assertSame( PhotoStorage::EXPOSED, PhotoStorage::exposure() );
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
		self::assertSame( array(), $this->leftovers() );
		self::assertCount( 'offline' === $server ? 1 : 7, $this->requests, 'ohne Verbindung ein Abruf, sonst alle Endungen' );
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
		self::assertSame( 2, $this->checks(), 'das gemerkte «offen» wird beim Upload neu geprüft' );

		$this->server = '403';
		self::assertSame( 'hochgeladen', PhotoStorage::in_private_dir( static fn(): string => 'hochgeladen' ) );
		self::assertSame( 3, $this->checks() );

		$this->server = 'exposed';
		self::assertSame( 'hochgeladen', PhotoStorage::in_private_dir( static fn(): string => 'hochgeladen' ) );
		self::assertSame( 3, $this->checks(), 'bestätigter Schutz gilt 12 Stunden ohne neuen Abruf' );
		self::assertSame( PhotoStorage::EXPOSED, PhotoStorage::exposure( true ), 'neue Prüfung, z. B. im Website-Zustand' );
		self::assertStringContainsString( 'von aussen erreichbar', $this->refusal()->getMessage() );
	}

	/**
	 * Das gemerkte Ergebnis gilt nur für dasselbe Verzeichnis und denselben Web-Root: WP-CLI kennt
	 * keinen Web-Root und hätte ein Verzeichnis der Konstante darin nicht prüfen können.
	 */
	public function test_a_remembered_result_counts_only_for_the_same_scope(): void {
		$this->server = '403';
		self::assertSame( PhotoStorage::PROTECTED, PhotoStorage::exposure() );

		$this->server = 'exposed';
		self::assertSame( PhotoStorage::PROTECTED, PhotoStorage::exposure(), 'gemerkt' );
		self::assertSame( 1, $this->checks() );

		$_SERVER['DOCUMENT_ROOT'] = get_temp_dir();
		self::assertSame( PhotoStorage::EXPOSED, PhotoStorage::exposure(), 'anderer Web-Root, neu geprüft' );
		self::assertSame( 2, $this->checks() );

		// Ergebnisse früherer Fassungen ohne Bereich.
		foreach ( array(
			PhotoStorage::PROTECTED,
			array(
				'dir'   => PhotoStorage::base_dir(),
				'state' => PhotoStorage::PROTECTED,
			),
		) as $old ) {
			set_transient( PhotoStorage::CHECK_TRANSIENT, $old );
			self::assertStringContainsString( 'von aussen erreichbar', $this->refusal()->getMessage() );
		}
		self::assertSame( 4, $this->checks() );
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
	 * Für TTLEAGUE_PRIVATE_UPLOADS: Ein Verzeichnis innerhalb von WordPress oder des Web-Roots hat
	 * eine Adresse und wird über HTTP geprüft, eines ausserhalb nicht. Symbolische Links werden
	 * aufgelöst.
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
			self::assertNull( PhotoStorage::url_of( $outside ), 'ohne Web-Root (WP-CLI)' );

			// WordPress in einem Unterverzeichnis des Web-Roots: Das Verzeichnis liegt ausserhalb
			// von WordPress, aber im Web-Root, und hat dort eine Adresse.
			$_SERVER['DOCUMENT_ROOT'] = get_temp_dir();
			$home                     = (string) wp_parse_url( home_url(), PHP_URL_SCHEME ) . '://' . (string) wp_parse_url( home_url(), PHP_URL_HOST );
			self::assertSame( $home . '/' . basename( $outside ), PhotoStorage::url_of( $outside ) );
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
		$this->server = '403';
		PhotoStorage::exposure();
		$this->server = $server;

		$result = PhotoStorage::site_status();

		self::assertSame( 2, $this->checks(), 'Website-Zustand prüft trotz gemerktem Schutz neu' );
		self::assertSame( $status, $result['status'] );
		self::assertSame( $color, $result['badge']['color'] );
		self::assertSame( 'ttleague_photo_storage', $result['test'] );
	}

	/**
	 * Adresse des Standardverzeichnisses der Fotos.
	 */
	private static function base_url(): string {
		return wp_upload_dir()['baseurl'] . '/' . PhotoStorage::DIR;
	}

	/**
	 * Anzahl Prüfungen: Jede beginnt mit der Testdatei .jpg.
	 */
	private function checks(): int {
		return count( preg_grep( '/\.jpg$/', $this->requests ) );
	}

	/**
	 * Endungen der abgerufenen Testdateien in der Reihenfolge der Abrufe.
	 *
	 * @return list<string>
	 */
	private function extensions(): array {
		return array_map( static fn( string $url ): string => pathinfo( $url, PATHINFO_EXTENSION ), $this->requests );
	}

	/**
	 * Testdateien, die nach einer Prüfung liegen geblieben sind.
	 *
	 * @return list<string>
	 */
	private function leftovers(): array {
		$files = glob( PhotoStorage::base_dir() . wp_upload_dir()['subdir'] . '/pruefung-*' );
		return false === $files ? array() : $files;
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
