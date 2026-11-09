<?php
/**
 * Basis der WordPress-Tests.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Tests\WordPress;

use TTLeague\Access\Capabilities;
use TTLeague\Accounts\AccountService;
use TTLeague\Infrastructure\Database\WpdbDb;
use TTLeague\Infrastructure\Mail\Message;

/**
 * Jeder Test läuft in einer Transaktion der WordPress-Testsuite, die am Ende zurückgerollt wird;
 * die Transaktionen des Plugins werden darin zu Savepoints. Weiterleitungen werfen Redirect
 * statt exit, Mails des Plugins werden mitgeschnitten (und gehen an den PHPMailer-Ersatz der
 * Testsuite), Anmelde-Cookies werden nicht gesendet.
 */
abstract class TestCase extends \WP_UnitTestCase {

	protected WpdbDb $db;

	/** @var list<Message> */
	protected array $mails = array();

	/** Offene Savepoints der Plugin-Transaktionen. */
	private int $savepoints = 0;

	/** @var array<string,mixed> */
	private array $server = array();

	public function set_up(): void {
		parent::set_up();
		$this->db         = WpdbDb::from_global();
		$this->mails      = array();
		$this->savepoints = 0;
		$this->server     = $_SERVER;
		add_filter( 'query', array( $this, 'savepoint' ) );
		add_filter( 'ttleague_mail', array( $this, 'record_mail' ) );
		add_filter( 'wp_redirect', array( $this, 'stop_redirect' ), 10, 2 );
		add_filter( 'send_auth_cookies', '__return_false' );
		reset_phpmailer_instance();
		// Die Rechteprüfung merkt sich Rollen und Begegnungen; jeder Test beginnt ohne.
		Capabilities::use_policy( null );
	}

	public function tear_down(): void {
		// Vor dem ROLLBACK der Testsuite entfernen, damit er die Testtransaktion beendet.
		remove_filter( 'query', array( $this, 'savepoint' ) );
		Capabilities::use_policy( null );
		$_SERVER = $this->server;
		parent::tear_down();
	}

	/**
	 * MySQL kennt keine verschachtelten Transaktionen: START TRANSACTION und COMMIT aus
	 * WpdbDb::transaction() würden die Testtransaktion festschreiben und Testdaten in den
	 * nächsten Test tragen. Innerhalb eines Tests werden sie deshalb zu Savepoints; ein
	 * ROLLBACK des Plugins rollt nur bis zu seinem Savepoint zurück.
	 *
	 * @param string $query SQL.
	 */
	public function savepoint( string $query ): string {
		switch ( strtoupper( trim( $query ) ) ) {
			case 'START TRANSACTION':
				++$this->savepoints;
				return 'SAVEPOINT ttl_' . $this->savepoints;
			case 'COMMIT':
				if ( $this->savepoints > 0 ) {
					return 'RELEASE SAVEPOINT ttl_' . $this->savepoints--;
				}
				break;
			case 'ROLLBACK':
				if ( $this->savepoints > 0 ) {
					return 'ROLLBACK TO SAVEPOINT ttl_' . $this->savepoints--;
				}
				break;
		}
		return $query;
	}

	/**
	 * @param Message|false $message Nachricht des Plugins.
	 * @return Message|false
	 */
	public function record_mail( $message ) {
		if ( $message instanceof Message ) {
			$this->mails[] = $message;
		}
		return $message;
	}

	/**
	 * @throws Redirect Immer, statt der Kopfzeile und des exit danach.
	 */
	public function stop_redirect( string $location, int $status ): string {
		throw new Redirect( $location, $status ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Ausnahme im Test, keine Ausgabe.
	}

	/**
	 * Führt einen Ablauf aus, der mit einer Weiterleitung endet, und liefert deren Ziel.
	 *
	 * @param callable():void $flow Ablauf.
	 */
	protected function redirect_of( callable $flow ): string {
		try {
			$flow();
		} catch ( Redirect $redirect ) {
			return $redirect->location;
		}
		self::fail( 'Weiterleitung erwartet.' );
	}

	/**
	 * Legt ein WordPress-Konto an und verknüpft es auf Wunsch mit einer Person.
	 */
	protected function account( string $role, string $email, ?int $person_id = null ): int {
		$user_id = self::factory()->user->create(
			array(
				'role'       => $role,
				'user_email' => $email,
			)
		);
		if ( null !== $person_id ) {
			( new AccountService( $this->db ) )->link_user( $person_id, $user_id );
		}
		return $user_id;
	}

	/**
	 * Konto, mit dem die Person verknüpft ist, oder null.
	 */
	protected function linked_user( int $person_id ): ?int {
		$user_id = $this->db->get_var( "SELECT user_id FROM {$this->db->table( 'person' )} WHERE id = %d", array( $person_id ) );
		return null === $user_id ? null : (int) $user_id;
	}

	/**
	 * Anpassung an PHPUnit 11: WP_UnitTestCase_Base::expectDeprecated() liest die Annotationen
	 * expectedDeprecated und expectedIncorrectUsage über eine PHPUnit-Funktion, die es seit
	 * PHPUnit 10 nicht mehr gibt, ausser die Testklasse liefert sie selbst. Diese Tests
	 * verwenden keine solchen Annotationen; Erwartungen setzen sie mit setExpectedDeprecated()
	 * und setExpectedIncorrectUsage().
	 *
	 * @return array{class:array<string,list<string>>,method:array<string,list<string>>}
	 */
	public function getAnnotations(): array { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- Name, den die WordPress-Testsuite aufruft.
		return array(
			'class'  => array(),
			'method' => array(),
		);
	}

	/**
	 * Anpassung an PHPUnit 11: ersetzt die wirkungslose Methode der WordPress-Testsuite, deren
	 * Kommentar PHPUnit 11 als veraltete Metadaten meldet (einmal pro Testklasse).
	 */
	protected function checkRequirements(): void { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- überschreibt die Methode der WordPress-Testsuite.
	}
}
