<?php
/**
 * WP-CLI: wp ttleague account anonymize.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Tests\WordPress\Cli;

use TTLeague\Cli\AccountCommand;
use TTLeague\Infrastructure\Cache\DataVersion;
use TTLeague\Tests\WordPress\League;
use TTLeague\Tests\WordPress\TestCase;

/**
 * Liga siehe League; Hanna Heim ist Captain von Seefeld 1 und hat ein Konto. WP-CLI selbst
 * ist in den Tests nicht geladen: Ein Ersatz für die Klasse WP_CLI zeichnet die Ausgaben auf.
 */
final class AccountCommandTest extends TestCase {

	private League $league;

	private int $hanna;

	private int $user;

	public static function set_up_before_class(): void {
		parent::set_up_before_class();
		require_once __DIR__ . '/wp-cli-stub.php';
	}

	public function set_up(): void {
		parent::set_up();
		\WP_CLI::$output = array();
		$this->league    = new League( $this->db, '2026-11-10 20:00:00' );
		$this->hanna     = $this->league->id['captain_h1'];
		$this->db->update( 'person', array( 'email' => 'hanna.heim@example.invalid' ), array( 'id' => $this->hanna ) );
		$this->user = $this->account( 'subscriber', 'hanna.heim@example.invalid', $this->hanna );
	}

	/**
	 * @return array<string,string|null>
	 */
	private function person( int $id ): array {
		return (array) $this->db->get_row( "SELECT * FROM {$this->db->table( 'person' )} WHERE id = %d", array( $id ) );
	}

	/**
	 * @param list<string>         $args       Argumente.
	 * @param array<string,string> $assoc_args Optionen.
	 */
	private function run_anonymize( array $args, array $assoc_args ): ?string {
		try {
			( new AccountCommand() )->anonymize( $args, $assoc_args );
		} catch ( CliStop $stop ) {
			return $stop->getMessage();
		}
		return null;
	}

	public function test_anonymize_asks_before_it_changes_anything(): void {
		$version = DataVersion::get();

		self::assertSame( 'Rückfrage nicht bestätigt.', $this->run_anonymize( array( (string) $this->hanna ), array() ) );

		self::assertSame( array( 'confirm' ), array_column( \WP_CLI::$output, 0 ) );
		self::assertStringContainsString( 'Person ' . $this->hanna . ' (Hanna Heim) unwiderruflich anonymisieren?', \WP_CLI::$output[0][1] );
		self::assertSame( 'Heim', $this->person( $this->hanna )['last_name'] );
		self::assertSame( $version, DataVersion::get() );
	}

	public function test_anonymize_with_yes_reports_name_account_and_open_function(): void {
		$version = DataVersion::get();

		self::assertNull( $this->run_anonymize( array( (string) $this->hanna ), array( 'yes' => true ) ) );

		$person = $this->person( $this->hanna );
		self::assertSame( array( '', 'Ehemaliges Mitglied 1', 'inactive' ), array( $person['first_name'], $person['last_name'], $person['status'] ) );
		self::assertNull( $person['email'] );
		self::assertNull( $person['user_id'] );
		self::assertSame( $version + 1, DataVersion::get(), 'Lesemodelle werden neu berechnet' );

		self::assertSame( array( 'success', 'log', 'warning' ), array_column( \WP_CLI::$output, 0 ) );
		self::assertSame( 'Person ' . $this->hanna . ' heisst jetzt «Ehemaliges Mitglied 1» und ist inaktiv.', \WP_CLI::$output[0][1] );
		$user = get_userdata( $this->user );
		self::assertInstanceOf( \WP_User::class, $user, 'das Konto bleibt bestehen' );
		self::assertStringContainsString( 'Das WordPress-Konto ' . $user->user_login . ' ist nicht mehr verknüpft', \WP_CLI::$output[1][1] );
		self::assertStringContainsString( 'wp user delete ' . $this->user, \WP_CLI::$output[1][1] );
		self::assertStringContainsString( 'Funktion in der aktiven Saison', \WP_CLI::$output[2][1] );

		$log = $this->db->get_row( "SELECT * FROM {$this->db->table( 'audit_log' )} WHERE entity = 'person' AND entity_id = %d AND action = 'anonymized'", array( $this->hanna ) );
		self::assertNotNull( $log );
		self::assertSame( 'wp-cli', $log['note'] );
		self::assertStringNotContainsString( 'Hanna', $log['before_json'] . $log['after_json'] );
		self::assertStringNotContainsString( 'example.invalid', $log['before_json'] . $log['after_json'] );
	}

	public function test_anonymize_reports_unknown_and_already_anonymized_people(): void {
		self::assertSame( 'Person nicht gefunden.', $this->run_anonymize( array( '999999' ), array( 'yes' => true ) ) );
		self::assertSame( 'Person nicht gefunden.', $this->run_anonymize( array(), array( 'yes' => true ) ) );

		// Person ohne Konto und ohne Funktion: nur die Erfolgsmeldung.
		\WP_CLI::$output = array();
		$nobody          = $this->league->id['nobody'];
		self::assertNull( $this->run_anonymize( array( (string) $nobody ), array( 'yes' => true ) ) );
		self::assertSame( array( 'success' ), array_column( \WP_CLI::$output, 0 ) );

		self::assertSame( 'Die Person ist bereits anonymisiert.', $this->run_anonymize( array( (string) $nobody ), array( 'yes' => true ) ) );
		\WP_CLI::$output = array();
		self::assertSame( 'Die Person ist bereits anonymisiert.', $this->run_anonymize( array( (string) $nobody ), array() ), 'schon vor der Rückfrage' );
		self::assertSame( array( 'error' ), array_column( \WP_CLI::$output, 0 ) );
		self::assertSame( 'Ehemaliges Mitglied 1', $this->person( $nobody )['last_name'] );
	}
}
