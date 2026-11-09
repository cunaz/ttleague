<?php
/**
 * Die Testumgebung selbst: Isolation der Tests, Anpassung an PHPUnit 11.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Tests\WordPress;

use TTLeague\Infrastructure\Database\Db;
use TTLeague\Tests\Integration\Frontend\Seeder;

final class EnvironmentTest extends TestCase {

	public function test_plugin_transactions_do_not_commit_the_test_transaction(): void {
		$this->db->transaction(
			static function ( Db $db ): void {
				( new Seeder( $db ) )->club( 'Seefeld', 1 );
			}
		);

		$sql = "SELECT COUNT(*) FROM {$this->db->table( 'club' )}";
		self::assertSame( '1', $this->db->get_var( $sql ), 'in dieser Verbindung sichtbar' );

		// Eine zweite Verbindung sieht nur festgeschriebene Daten.
		$other = new \wpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST );
		self::assertSame( '0', $other->get_var( $sql ), 'nicht festgeschrieben' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- fester Tabellenname.
		$other->close();
	}

	public function test_rollback_of_the_plugin_reverts_only_its_own_work(): void {
		$seeder = new Seeder( $this->db );
		$seeder->club( 'Seefeld', 1 );

		try {
			$this->db->transaction(
				static function ( Db $db ): void {
					( new Seeder( $db ) )->club( 'Wollishofen', 2 );
					throw new \RuntimeException( 'Abbruch' );
				}
			);
		} catch ( \RuntimeException $e ) {
			self::assertSame( 'Abbruch', $e->getMessage() );
		}

		self::assertSame( array( 'Seefeld' ), $this->db->get_col( "SELECT short_name FROM {$this->db->table( 'club' )}" ) );
	}

	public function test_incorrect_usage_is_still_detected_under_phpunit_11(): void {
		// Die Testsuite meldet unerwartete _doing_it_wrong()-Aufrufe als Fehler; erwartete werden angemeldet.
		$this->setExpectedIncorrectUsage( 'ttleague_probe' );

		_doing_it_wrong( 'ttleague_probe', 'Probe', '0.1.0' );

		self::assertArrayHasKey( 'ttleague_probe', $this->caught_doing_it_wrong );
	}

	public function test_redirect_stops_the_flow(): void {
		$location = $this->redirect_of(
			static function (): void {
				wp_safe_redirect( 'https://example.com/fremd/' );
				exit; // phpcs:ignore Squiz.PHP.NonExecutableCode.Unreachable -- wird im Test nie erreicht.
			}
		);

		self::assertSame( 'http://example.org/wp-admin/', $location, 'fremdes Ziel durch die Startseite der Verwaltung ersetzt' );
	}
}
