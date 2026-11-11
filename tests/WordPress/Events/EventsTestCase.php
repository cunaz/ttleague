<?php
/**
 * Gemeinsame Daten der WordPress-Tests für Anlässe.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Tests\WordPress\Events;

use TTLeague\Accounts\AccountService;
use TTLeague\Events\Events;
use TTLeague\Results\Actor;
use TTLeague\Tests\WordPress\League;
use TTLeague\Tests\WordPress\TestCase;

/**
 * Liga siehe League. Konten: Hanna Heim (Captain Seefeld 1), Charlotte Chef (TK-Chefin Seefeld),
 * Nina Niemand (ohne Funktion), die Spieler h1 und h3 (Seefeld) und a1 (Wollishofen), dazu ein
 * TK-Konto ohne Person. Alle Personen haben eine E-Mail-Adresse.
 *
 * Die Uhr der Website läuft echt: Der Anlass aus event() beginnt in zehn Tagen, Anmeldeschluss
 * ist in fünf Tagen (Ortszeit der Website).
 */
abstract class EventsTestCase extends TestCase {

	protected League $league;

	/** @var array<string,int> Personen nach Schlüssel. */
	protected array $person = array();

	/** @var array<string,int> Konten nach Schlüssel. */
	protected array $user = array();

	public function set_up(): void {
		parent::set_up();
		$this->league = new League( $this->db, '2026-11-10 20:00:00' );
		$this->person = array(
			'captain' => $this->league->id['captain_h1'],
			'chef'    => $this->league->id['chef_home'],
			'nobody'  => $this->league->id['nobody'],
			'h1'      => $this->league->id['person_h1'],
			'h3'      => $this->league->id['person_h3'],
			'a1'      => $this->league->id['person_a1'],
			'a3'      => $this->league->id['person_a3'],
		);
		$accounts     = new AccountService( $this->db );
		foreach ( $this->person as $key => $person_id ) {
			$email = $key . '@example.invalid';
			$accounts->set_email( $person_id, $email );
			$this->user[ $key ] = $this->account( 'subscriber', $email, $person_id );
		}
		$this->user['tk']       = $this->account( 'ttl_tk', 'tk@example.invalid' );
		$this->user['unlinked'] = $this->account( 'subscriber', 'ohne-person@example.invalid' );
		$this->mails            = array();
	}

	public function tear_down(): void {
		$_POST    = array();
		$_GET     = array();
		$_REQUEST = array();
		parent::tear_down();
	}

	/**
	 * Zeitpunkt in Ortszeit der Website, so viele Tage von jetzt («Y-m-d\TH:i»).
	 */
	protected function local( int $days ): string {
		return wp_date( 'Y-m-d\TH:i', time() + $days * DAY_IN_SECONDS );
	}

	/**
	 * Legt als TK einen Anlass an.
	 *
	 * @param array<string,string> $overrides Abweichende Felder.
	 */
	protected function event( array $overrides = array() ): int {
		return Events::events()->create(
			$overrides + array(
				'season_id'        => (string) $this->league->id['season'],
				'type'             => 'tournament',
				'title'            => 'Plauschturnier',
				'starts_at'        => $this->local( 10 ),
				'ends_at'          => '',
				'location'         => 'Turnhalle Seefeld',
				'deadline'         => $this->local( 5 ),
				'max_participants' => '',
				'post_id'          => '',
			),
			$this->actor( 'tk' )
		);
	}

	protected function actor( string $key ): Actor {
		return new Actor( $this->user[ $key ], $this->person[ $key ] ?? null, 'tk' === $key );
	}

	/**
	 * Meldet Personen selbst an und verwirft die Mails dazu.
	 *
	 * @param list<string> $keys Schlüssel der Personen.
	 */
	protected function register_all( int $event, array $keys ): void {
		foreach ( $keys as $key ) {
			Events::registrations()->register( $event, $this->person[ $key ], $this->actor( $key ) );
		}
		$this->mails = array();
	}

	/**
	 * Anmeldungen eines Anlasses: Schlüssel der Person => Status.
	 *
	 * @return array<string,string>
	 */
	protected function list_of( int $event ): array {
		$keys = array_flip( $this->person );
		$list = array();
		foreach ( Events::repository()->registrations( $event, $this->league->id['season'] ) as $row ) {
			$list[ (string) ( $keys[ $row['person_id'] ] ?? $row['person_id'] ) ] = $row['status'];
		}
		return $list;
	}

	/**
	 * Formulardaten mit Nonce, wie WordPress sie liefert (mit Backslashes maskiert).
	 *
	 * @param array<string,string> $fields Felder.
	 */
	protected function post( string $nonce_action, array $fields ): void {
		$_POST    = wp_slash( $fields + array( '_wpnonce' => wp_create_nonce( $nonce_action ) ) );
		$_REQUEST = $_POST; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Der Test setzt die Formulardaten, der Nonce ist enthalten.
	}

	/**
	 * @param callable():mixed $flow Ablauf, der mit wp_die() enden muss.
	 */
	protected function die_of( callable $flow ): \WPDieException {
		try {
			$flow();
		} catch ( \WPDieException $e ) {
			return $e;
		}
		self::fail( 'wp_die() erwartet.' );
	}

	/**
	 * Betreffzeilen der mitgeschnittenen Mails nach Empfänger.
	 *
	 * @return array<string,list<string>>
	 */
	protected function subjects(): array {
		$subjects = array();
		foreach ( $this->mails as $mail ) {
			$subjects[ $mail->to ][] = $mail->subject;
		}
		ksort( $subjects );
		return $subjects;
	}

	/**
	 * Führt den Ablauf aus und leitet das Fehlerprotokoll dabei in eine Datei um; liefert deren Inhalt.
	 *
	 * @param callable():void $flow Ablauf.
	 */
	protected function error_log_of( callable $flow ): string {
		$file     = (string) tempnam( get_temp_dir(), 'ttl-log' );
		$previous = ini_set( 'error_log', $file ); // phpcs:ignore WordPress.PHP.IniSet.Risky -- Fehlerprotokoll im Test umleiten.
		try {
			$flow();
			return (string) file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- lokale Testdatei.
		} finally {
			ini_set( 'error_log', false === $previous ? '' : $previous ); // phpcs:ignore WordPress.PHP.IniSet.Risky -- siehe oben.
			wp_delete_file( $file );
		}
	}
}
