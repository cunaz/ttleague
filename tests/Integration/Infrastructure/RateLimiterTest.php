<?php
/**
 * Tests für das Rate-Limit mit festen Zeitfenstern.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Tests\Integration\Infrastructure;

use TTLeague\Infrastructure\RateLimiter;
use TTLeague\Infrastructure\Time\FixedClock;
use TTLeague\Tests\Integration\IntegrationTestCase;

final class RateLimiterTest extends IntegrationTestCase {

	private FixedClock $clock;

	private RateLimiter $limiter;

	protected function setUp(): void {
		parent::setUp();
		$this->clock   = new FixedClock( '2026-11-10 12:00:00' );
		$this->limiter = new RateLimiter( $this->db, $this->clock );
	}

	public function test_allows_up_to_limit_then_blocks(): void {
		for ( $i = 0; $i < 5; $i++ ) {
			self::assertTrue( $this->limiter->hit( 'magic_mail', 'a@example.org', 5, 3600 ), 'Versuch ' . ( $i + 1 ) );
		}
		self::assertFalse( $this->limiter->hit( 'magic_mail', 'a@example.org', 5, 3600 ) );
		self::assertFalse( $this->limiter->hit( 'magic_mail', 'a@example.org', 5, 3600 ) );
	}

	public function test_subjects_and_buckets_are_independent(): void {
		self::assertTrue( $this->limiter->hit( 'magic_mail', 'a@example.org', 1, 3600 ) );
		self::assertFalse( $this->limiter->hit( 'magic_mail', 'a@example.org', 1, 3600 ) );
		self::assertTrue( $this->limiter->hit( 'magic_mail', 'b@example.org', 1, 3600 ) );
		self::assertTrue( $this->limiter->hit( 'magic_ip', 'a@example.org', 1, 3600 ) );
	}

	public function test_new_window_resets_the_count(): void {
		self::assertTrue( $this->limiter->hit( 'magic_ip', '192.0.2.1', 2, 3600 ) );
		self::assertTrue( $this->limiter->hit( 'magic_ip', '192.0.2.1', 2, 3600 ) );
		self::assertFalse( $this->limiter->hit( 'magic_ip', '192.0.2.1', 2, 3600 ) );

		$this->clock->advance( '+1 hour' );
		self::assertTrue( $this->limiter->hit( 'magic_ip', '192.0.2.1', 2, 3600 ) );
	}

	public function test_blocked_attempts_are_not_counted(): void {
		self::assertTrue( $this->limiter->hit( 'x', 's', 1, 60 ) );
		self::assertFalse( $this->limiter->hit( 'x', 's', 1, 60 ) );
		$row = $this->db->get_row( "SELECT attempts, expires_at FROM {$this->db->table( 'rate_limit' )}" );
		self::assertSame(
			array(
				'attempts'   => '1',
				'expires_at' => '2026-11-10 12:01:00',
			),
			$row
		);
	}

	public function test_bucket_delimiters_and_window_sizes_do_not_alias(): void {
		self::assertTrue( $this->limiter->hit( 'a|b', 'c', 1, 3600 ) );
		self::assertTrue( $this->limiter->hit( 'a', 'b|c', 1, 3600 ) );
		self::assertTrue( $this->limiter->hit( 'a', 'b|c', 1, 60 ) );
		self::assertFalse( $this->limiter->hit( 'a|b', 'c', 1, 3600 ) );
	}

	public function test_expired_windows_are_purged_without_resetting_current_limits(): void {
		self::assertTrue( $this->limiter->hit( 'mail', 'a@example.invalid', 1, 3600 ) );
		$this->clock->advance( '+1 hour' );
		self::assertSame( 0, $this->limiter->purge(), 'abgelaufene Fenster bleiben noch einen Tag' );
		$this->clock->advance( '+1 day' );
		self::assertTrue( $this->limiter->hit( 'mail', 'a@example.invalid', 1, 3600 ) );
		self::assertSame( 1, $this->limiter->purge() );
		self::assertFalse( $this->limiter->hit( 'mail', 'a@example.invalid', 1, 3600 ) );
	}

	/** Zwölf unabhängige PHP-Prozesse konkurrieren um je 5 bzw. 20 Plätze. */
	public function test_simultaneous_requests_cannot_exceed_either_limit(): void {
		$autoload = var_export( dirname( __DIR__, 3 ) . '/vendor/autoload.php', true ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_export -- PHP-Literal für den unabhängigen Testprozess.
		$script   = 'require ' . $autoload . ';' . <<<'PHP'
$db = \TTLeague\Infrastructure\Database\PdoDb::connect(
	getenv('TTLEAGUE_TEST_DB_DSN') ?: 'mysql:host=127.0.0.1;dbname=ttleague_test;charset=utf8mb4',
	getenv('TTLEAGUE_TEST_DB_USER') ?: 'ttleague',
	false === getenv('TTLEAGUE_TEST_DB_PASS') ? 'ttleague' : getenv('TTLEAGUE_TEST_DB_PASS'),
	'wptest_'
);
$limiter = new \TTLeague\Infrastructure\RateLimiter($db, new \TTLeague\Infrastructure\Time\FixedClock('2026-11-10 12:00:00'));
echo "ready\n";
fgets(STDIN);
$counts = [0, 0];
for ($i = 0; $i < 4; ++$i) {
	$counts[0] += (int) $limiter->hit('mail', 'parallel@example.invalid', 5, 3600);
	$counts[1] += (int) $limiter->hit('ip', '192.0.2.1', 20, 3600);
}
echo json_encode($counts);
PHP;
		$workers  = array();
		try {
			for ( $i = 0; $i < 12; ++$i ) {
				$process = proc_open( array( PHP_BINARY, '-r', $script ), array( array( 'pipe', 'r' ), array( 'pipe', 'w' ), array( 'pipe', 'w' ) ), $pipes ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_proc_open -- Echte konkurrierende Datenbankzugriffe im Test.
				self::assertIsResource( $process );
				$workers[] = array(
					'process' => $process,
					'pipes'   => $pipes,
				);
			}
			foreach ( $workers as $worker ) {
				stream_set_timeout( $worker['pipes'][1], 15 );
				self::assertSame( "ready\n", fgets( $worker['pipes'][1] ) );
			}
			foreach ( $workers as $worker ) {
				fwrite( $worker['pipes'][0], "go\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Startsignal an Testprozesse.
				fclose( $worker['pipes'][0] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Pipe.
			}
			$counts = array( 0, 0 );
			foreach ( $workers as $worker ) {
				$result = json_decode( stream_get_contents( $worker['pipes'][1] ), true );
				$error  = stream_get_contents( $worker['pipes'][2] );
				self::assertSame( 0, proc_close( $worker['process'] ), $error );
				self::assertIsArray( $result );
				$counts[0] += $result[0];
				$counts[1] += $result[1];
			}
			self::assertSame( array( 5, 20 ), $counts, '48 Versuche je Schlüssel überschreiten die Grenzen nicht' );
		} finally {
			foreach ( $workers as $worker ) {
				foreach ( $worker['pipes'] as $pipe ) {
					if ( is_resource( $pipe ) ) {
						fclose( $pipe ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Test-Pipe aufräumen.
					}
				}
				if ( is_resource( $worker['process'] ) ) {
					proc_terminate( $worker['process'] );
					proc_close( $worker['process'] );
				}
			}
		}
	}
}
