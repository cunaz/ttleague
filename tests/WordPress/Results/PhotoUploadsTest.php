<?php
/**
 * Die Fotogrenze zählt Belege und serialisiert Uploads über Datenbankverbindungen hinweg.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Tests\WordPress\Results;

use TTLeague\Infrastructure\Database\Db;
use TTLeague\Infrastructure\Database\DbException;
use TTLeague\Infrastructure\Database\WpdbDb;
use TTLeague\Results\PhotoUploads;
use TTLeague\Results\ResultException;
use TTLeague\Tests\WordPress\TestCase;

final class PhotoUploadsTest extends TestCase {

	public function test_five_photos_are_preserved_and_the_sixth_is_never_stored(): void {
		$uploads = new PhotoUploads( $this->db );
		$photos  = array();
		for ( $i = 0; $i < 5; ++$i ) {
			$photos[] = $uploads->run( 123, fn(): int => $this->attachment( 123 ) );
			if ( 0 === $i ) {
				add_post_meta( $photos[0], '_ttleague_fixture', 123 );
			}
		}
		// Doppelte Metadaten zählen denselben Anhang nicht zweimal; andere Begegnungen sind frei.
		self::assertGreaterThan( 0, $uploads->run( 456, fn(): int => $this->attachment( 456 ) ) );
		$this->refused( fn() => $uploads->run( 123, static fn(): int => self::fail( 'Kein weiterer Speicherzugriff erlaubt' ) ), 409 );
		foreach ( $photos as $photo ) {
			self::assertNotNull( get_post( $photo ), 'Historie nicht gelöscht' );
		}
		wp_delete_attachment( $photos[0], true );
		self::assertGreaterThan( 0, $uploads->run( 123, fn(): int => $this->attachment( 123 ) ) );
		$this->refused( fn() => $uploads->run( 123, static fn(): int => self::fail( 'Grenze erneut erreicht' ) ), 409 );
	}

	public function test_legacy_histories_above_the_limit_cannot_grow(): void {
		for ( $i = 0; $i < 7; ++$i ) {
			$this->attachment( 123 );
		}
		$this->refused( fn() => ( new PhotoUploads( $this->db ) )->run( 123, static fn(): int => self::fail( 'Bestand über der Grenze' ) ), 409 );
	}

	public function test_another_connection_cannot_upload_until_the_lock_is_released(): void {
		$other = new \wpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST );
		$other->set_prefix( $this->db->prefix() );
		$db   = new WpdbDb( $other );
		$name = PhotoUploads::lock_name( DB_NAME, $this->db->prefix(), 123 );
		try {
			self::assertSame( '1', $db->get_var( 'SELECT GET_LOCK(%s, 0)', array( $name ) ) );
			$this->refused( fn() => ( new PhotoUploads( $this->db ) )->run( 123, static fn(): int => self::fail( 'Anderer Upload hält die Sperre' ) ), 503 );
			self::assertGreaterThan( 0, ( new PhotoUploads( $this->db ) )->run( 456, fn(): int => $this->attachment( 456 ) ), 'andere Begegnung nicht gesperrt' );
		} finally {
			$db->get_var( 'SELECT RELEASE_LOCK(%s)', array( $name ) );
			$other->close();
		}
		self::assertGreaterThan( 0, ( new PhotoUploads( $this->db ) )->run( 123, fn(): int => $this->attachment( 123 ) ) );
	}

	public function test_the_lock_spans_the_callback_and_is_released_even_after_failure(): void {
		$uploads = new PhotoUploads( $this->db );
		$name    = PhotoUploads::lock_name( DB_NAME, $this->db->prefix(), 123 );
		try {
			$uploads->run(
				123,
				function () use ( $uploads, $name ): int {
					self::assertSame( $this->db->get_var( 'SELECT CONNECTION_ID()' ), $this->db->get_var( 'SELECT IS_USED_LOCK(%s)', array( $name ) ) );
					$this->refused( fn() => $uploads->run( 123, static fn(): int => self::fail( 'Rekursiver Upload' ) ), 503 );
					throw new \RuntimeException( 'Speichern fehlgeschlagen' );
				}
			);
		} catch ( \RuntimeException $e ) {
			self::assertSame( 'Speichern fehlgeschlagen', $e->getMessage() );
		}
		self::assertSame( '1', $this->db->get_var( 'SELECT IS_FREE_LOCK(%s)', array( $name ) ) );
		self::assertGreaterThan( 0, $uploads->run( 123, fn(): int => $this->attachment( 123 ) ) );
	}

	public function test_database_failure_does_not_allow_storage(): void {
		$db = $this->createMock( Db::class );
		$db->method( 'get_var' )->willReturnOnConsecutiveCalls( DB_NAME, '1', '1' );
		$db->method( 'get_col' )->willThrowException( new DbException( 'Lesen fehlgeschlagen' ) );
		$this->refused( static fn() => ( new PhotoUploads( $db ) )->run( 123, static fn(): int => self::fail( 'Ohne Prüfung kein Upload' ) ), 503 );
	}

	private function attachment( int $fixture ): int {
		return self::factory()->attachment->create(
			array(
				'post_status' => 'private',
				'meta_input'  => array( '_ttleague_fixture' => $fixture ),
			)
		);
	}

	/** @param callable():mixed $work Abzuweisender Upload. */
	private function refused( callable $work, int $status ): void {
		try {
			$work();
			self::fail( 'Upload muss abgewiesen werden' );
		} catch ( ResultException $e ) {
			self::assertSame( $status, $e->status );
		}
	}
}
