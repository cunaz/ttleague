<?php
/**
 * Datenschutz-Werkzeuge gegen die Datenbank: Auskunft, Löschung, Anonymisierung.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Tests\Integration\Accounts;

use TTLeague\Accounts\PersonalData;
use TTLeague\Infrastructure\AuditLog;
use TTLeague\ReadModel\Cache\NullCache;
use TTLeague\ReadModel\ReadModel;
use TTLeague\Tests\Integration\Frontend\Seeder;
use TTLeague\Tests\Integration\IntegrationTestCase;

/**
 * Erfundene Spielerin Hanna Heim (Seefeld): in zwei Saisons gemeldet, Captain von Seefeld 1
 * in der aktiven Saison, mit Konto 77, Telefon, Einstellungen, Push-Abo und Anmeldelink. Sie
 * hat eine Begegnung gespielt. Gustav Gast (Wollishofen) ist ihr Gegner.
 */
final class PersonalDataTest extends IntegrationTestCase {

	private const EMAIL = 'hanna.heim@example.org';
	private const PHONE = '079 555 01 01';

	private PersonalData $data;

	/** @var array<string,int> */
	private array $id = array();

	public static function setUpBeforeClass(): void {
		require_once dirname( __DIR__, 2 ) . '/Unit/Frontend/Stubs/WordPress.php';
	}

	protected function setUp(): void {
		parent::setUp();
		$s      = new Seeder( $this->db );
		$old    = $s->season( '2025-26', 'archived' );
		$season = $s->season( '2026-27' );
		$home   = $s->club( 'Seefeld', 1 );
		$away   = $s->club( 'Wollishofen', 2 );
		$group  = $s->competition( $season, 'MM', 'Gruppe B1', 'B', 1 );
		$h1     = $s->team( $season, $home, 1, 'Seefeld 1', $group );
		$a1     = $s->team( $season, $away, 1, 'Wollishofen 1', $group );

		$hanna  = $s->player( 'Heim', 'Hanna' );
		$gustav = $s->player( 'Gast', 'Gustav' );
		$s->register( $hanna, $old, $home, 8 );
		$s->register( $hanna, $season, $home, 9 );
		$s->register( $gustav, $season, $away, 9 );
		$s->roster( $h1, array( $hanna ) );
		$s->roster( $a1, array( $gustav ) );
		$person = $s->person_of( $hanna );
		$s->role( $season, $home, $person, 'captain', $h1 );

		$fixture = $s->fixture(
			$group,
			array(
				'home_team_id' => $h1,
				'away_team_id' => $a1,
				'scheduled_at' => '2026-11-10 20:00:00',
				'status'       => 'final',
			)
		);
		$s->team_match( $fixture, '2026-11-10 20:00:00', array( 'A' => $hanna ), array( 'X' => $gustav ), array( '3:1', 'np', 'np', 'np', 'np', 'np', 'np', 'np', 'np', 'np' ) );

		$this->db->update(
			'person',
			array(
				'email'         => self::EMAIL,
				'phone'         => self::PHONE,
				'phone_visible' => 1,
				'consent_at'    => '2026-09-01 10:00:00',
				'user_id'       => 77,
			),
			array( 'id' => $person )
		);
		$this->db->insert(
			'notification_pref',
			array(
				'person_id'  => $person,
				'event_type' => 'match_reminder',
				'email'      => 0,
				'push'       => 1,
			)
		);
		$this->db->insert(
			'push_subscription',
			array(
				'person_id'     => $person,
				'endpoint_hash' => hash( 'sha256', 'https://push.example.org/abc' ),
				'endpoint'      => 'https://push.example.org/abc',
				'keys_json'     => '{}',
				'created_at'    => '2026-09-01 10:00:00',
			)
		);
		foreach ( array( 'a', 'b' ) as $token ) {
			$this->db->insert(
				'login_token',
				array(
					'person_id'    => $person,
					'token_hash'   => hash( 'sha256', $token ),
					'expires_at'   => '2026-12-01 10:00:00',
					'requested_ip' => '192.0.2.10',
					'created_at'   => '2026-11-01 10:00:00',
				)
			);
		}

		$this->id   = array(
			'hanna'         => $person,
			'hanna_player'  => $hanna,
			'gustav'        => $s->person_of( $gustav ),
			'gustav_player' => $gustav,
			'fixture'       => $fixture,
			'season'        => $season,
		);
		$this->data = new PersonalData( $this->db, new AuditLog( $this->db ) );
	}

	/**
	 * @return array<string,string|null>
	 */
	private function person( string $key ): array {
		return (array) $this->db->get_row( "SELECT * FROM {$this->db->table( 'person' )} WHERE id = %d", array( $this->id[ $key ] ) );
	}

	private function rows( string $table, string $key = 'hanna' ): int {
		return (int) $this->db->get_var( "SELECT COUNT(*) FROM {$this->db->table( $table )} WHERE person_id = %d", array( $this->id[ $key ] ) );
	}

	public function test_person_is_found_by_email_or_linked_account(): void {
		self::assertSame( array( $this->id['hanna'] ), $this->data->person_ids( strtoupper( self::EMAIL ) ), 'Gross-/Kleinschreibung egal' );
		self::assertSame( array( $this->id['hanna'] ), $this->data->person_ids( 'konto@example.org', 77 ), 'über das verknüpfte Konto' );
		self::assertSame( array( $this->id['hanna'] ), $this->data->person_ids( self::EMAIL, 77 ), 'einmal, auch wenn beides passt' );
		self::assertSame( array(), $this->data->person_ids( 'niemand@example.org', 78 ) );
		self::assertSame( array(), $this->data->person_ids( '' ) );
		self::assertFalse( $this->data->is_ambiguous( self::EMAIL, 77 ) );
		self::assertFalse( $this->data->is_ambiguous( 'niemand@example.org', 78 ) );
	}

	public function test_request_is_processed_for_the_one_matching_person_only(): void {
		$seen  = array();
		$found = $this->data->for_request(
			'konto@example.org',
			77,
			static function ( int $person_id ) use ( &$seen ): string {
				$seen[] = $person_id;
				return 'verarbeitet';
			}
		);
		self::assertSame(
			array(
				'ambiguous' => false,
				'person_id' => $this->id['hanna'],
				'result'    => 'verarbeitet',
			),
			$found
		);
		self::assertSame( array( $this->id['hanna'] ), $seen );

		// Mehrdeutig oder niemand: Die Verarbeitung läuft nicht.
		$this->db->update( 'person', array( 'email' => 'gustav.gast@example.org' ), array( 'id' => $this->id['gustav'] ) );
		$never = static function (): never {
			self::fail( 'darf nicht laufen' );
		};
		self::assertSame(
			array(
				'ambiguous' => true,
				'person_id' => null,
				'result'    => null,
			),
			$this->data->for_request( 'gustav.gast@example.org', 77, $never )
		);
		self::assertFalse( $this->data->for_request( 'niemand@example.org', 78, $never )['ambiguous'] );
	}

	public function test_the_match_cannot_change_while_the_request_is_processed(): void {
		$other = self::another_connection();
		$other->pdo()->exec( 'SET SESSION innodb_lock_wait_timeout = 1' );
		$table   = $this->db->table( 'person' );
		$blocked = null;

		$this->data->for_request(
			'konto@example.org',
			77,
			function ( int $person_id ) use ( $other, $table, &$blocked ): void {
				// Während der Verarbeitung versucht eine zweite Verbindung, die Verknüpfung zu lösen.
				try {
					$other->pdo()->exec( "UPDATE `{$table}` SET user_id = NULL WHERE id = " . $person_id );
					$blocked = false;
				} catch ( \PDOException $e ) {
					$blocked = str_contains( $e->getMessage(), 'Lock wait timeout' );
				}
			}
		);

		self::assertTrue( $blocked, 'die Zeile der Person ist bis zum Ende der Verarbeitung gesperrt' );
		self::assertSame( '77', (string) $this->person( 'hanna' )['user_id'] );
		// Danach ist die Zeile wieder frei.
		self::assertSame( 1, $other->pdo()->exec( "UPDATE `{$table}` SET user_id = NULL WHERE id = " . $this->id['hanna'] ) );
	}

	public function test_address_and_account_pointing_to_different_people_match_nobody(): void {
		// Gustav hat die Adresse der Anfrage, das Konto mit dieser Adresse ist mit Hanna verknüpft.
		$this->db->update( 'person', array( 'email' => 'gustav.gast@example.org' ), array( 'id' => $this->id['gustav'] ) );

		self::assertSame( array(), $this->data->person_ids( 'gustav.gast@example.org', 77 ) );
		self::assertTrue( $this->data->is_ambiguous( 'gustav.gast@example.org', 77 ) );
		self::assertSame( array( $this->id['gustav'] ), $this->data->person_ids( 'gustav.gast@example.org' ), 'ohne Konto eindeutig' );
		self::assertSame( array( $this->id['hanna'] ), $this->data->person_ids( 'andere@example.org', 77 ), 'abweichende Konto-Adresse bleibt eindeutig' );
	}

	public function test_export_lists_profile_functions_registrations_and_settings(): void {
		$export = $this->data->export( $this->id['hanna'] );

		self::assertNotNull( $export );
		self::assertSame(
			array(
				'first_name'    => 'Hanna',
				'last_name'     => 'Heim',
				'email'         => self::EMAIL,
				'phone'         => self::PHONE,
				'phone_visible' => true,
				'consent_at'    => '2026-09-01 10:00:00',
				'status'        => 'active',
			),
			$export['profile']
		);
		self::assertSame(
			array(
				array(
					'season' => '2026/27',
					'role'   => 'captain',
					'club'   => 'TTC Seefeld',
					'team'   => 'Seefeld 1',
				),
			),
			$export['functions']
		);
		self::assertSame( array( '2026/27', '2025/26' ), array_column( $export['registrations'], 'season' ), 'neueste Saison zuerst' );
		self::assertSame( array( 9, 8 ), array_column( $export['registrations'], 'classification' ) );
		self::assertSame( array( 'TTC Seefeld', 'TTC Seefeld' ), array_column( $export['registrations'], 'club' ) );
		self::assertSame( array( 90001, 90001 ), array_column( $export['registrations'], 'license_no' ) );
		self::assertSame(
			array(
				'match_reminder' => array(
					'email' => false,
					'push'  => true,
				),
			),
			$export['preferences']
		);
		self::assertSame( 1, $export['push_subscriptions'] );

		$other = $this->data->export( $this->id['gustav'] );
		self::assertNotNull( $other );
		self::assertNull( $other['profile']['email'] );
		self::assertSame( array(), $other['functions'] );
		self::assertSame( 0, $other['push_subscriptions'] );
		self::assertNull( $this->data->export( 999999 ) );
	}

	public function test_erase_removes_phone_settings_subscriptions_and_tokens_only(): void {
		$removed = $this->data->erase( $this->id['hanna'], 5, 'privacy-tools' );

		self::assertSame(
			array(
				'phone'              => true,
				'preferences'        => 1,
				'push_subscriptions' => 1,
				'login_tokens'       => 2,
			),
			$removed
		);
		$person = $this->person( 'hanna' );
		self::assertNull( $person['phone'] );
		self::assertSame( '0', $person['phone_visible'] );
		self::assertSame( 0, $this->rows( 'notification_pref' ) + $this->rows( 'push_subscription' ) + $this->rows( 'login_token' ) );

		// Was zum Spielbetrieb gehört, bleibt.
		self::assertSame( array( 'Hanna', 'Heim', self::EMAIL, '77', 'active' ), array( $person['first_name'], $person['last_name'], $person['email'], $person['user_id'], $person['status'] ) );
		self::assertSame( '2026-09-01 10:00:00', $person['consent_at'] );
		self::assertSame( '2', $this->db->get_var( "SELECT COUNT(*) FROM {$this->db->table( 'player_season' )} WHERE player_id = %d", array( $this->id['hanna_player'] ) ) );
		self::assertSame( '1', $this->db->get_var( "SELECT COUNT(*) FROM {$this->db->table( 'lineup' )} WHERE player_id = %d", array( $this->id['hanna_player'] ) ) );
		self::assertSame( 1, $this->rows( 'club_role' ) );

		$log = ( new AuditLog( $this->db ) )->for_entity( 'person', $this->id['hanna'] );
		self::assertCount( 1, $log );
		self::assertSame( array( 'privacy_erased', '5', 'privacy-tools' ), array( $log[0]['action'], $log[0]['user_id'], $log[0]['note'] ) );
		self::assertStringNotContainsString( '555', (string) $log[0]['after_json'], 'keine gelöschten Werte im Protokoll' );

		// Zweiter Lauf: nichts mehr zu entfernen, kein weiterer Eintrag.
		self::assertSame(
			array(
				'phone'              => false,
				'preferences'        => 0,
				'push_subscriptions' => 0,
				'login_tokens'       => 0,
			),
			$this->data->erase( $this->id['hanna'] )
		);
		self::assertCount( 1, ( new AuditLog( $this->db ) )->for_entity( 'person', $this->id['hanna'] ) );
		self::assertNull( $this->data->erase( 999999 ) );
	}

	public function test_anonymize_replaces_the_name_and_removes_everything_personal(): void {
		$audit = new AuditLog( $this->db );
		$audit->record( 'person', $this->id['hanna'], 'email_changed', 5, array( 'email' => 'alt@example.org' ), array( 'email' => self::EMAIL ) );
		self::assertSame( 1, $this->data->active_functions( $this->id['hanna'] ) );

		$result = $this->data->anonymize( $this->id['hanna'], 5, 'wp-cli' );

		self::assertSame(
			array(
				'name'               => 'Ehemaliges Mitglied 1',
				'user_id'            => 77,
				'email'              => true,
				'phone'              => true,
				'preferences'        => 1,
				'push_subscriptions' => 1,
				'login_tokens'       => 2,
			),
			$result
		);
		$person = $this->person( 'hanna' );
		self::assertSame( '', $person['first_name'] );
		self::assertSame( 'Ehemaliges Mitglied 1', $person['last_name'] );
		self::assertSame( 'inactive', $person['status'] );
		foreach ( array( 'email', 'phone', 'consent_at', 'user_id' ) as $column ) {
			self::assertNull( $person[ $column ], $column );
		}
		self::assertSame( '0', $person['phone_visible'] );
		self::assertSame( 0, $this->rows( 'notification_pref' ) + $this->rows( 'push_subscription' ) + $this->rows( 'login_token' ) );
		self::assertTrue( PersonalData::is_anonymized( $person ) );
		self::assertFalse( PersonalData::is_anonymized( $this->person( 'gustav' ) ) );

		// Lizenznummer, Meldungen und Resultate bleiben.
		self::assertSame( '90001', $this->db->get_var( "SELECT license_no FROM {$this->db->table( 'player' )} WHERE id = %d", array( $this->id['hanna_player'] ) ) );
		self::assertSame( '2', $this->db->get_var( "SELECT COUNT(*) FROM {$this->db->table( 'player_season' )} WHERE player_id = %d", array( $this->id['hanna_player'] ) ) );
		self::assertGreaterThan( 0, (int) $this->db->get_var( "SELECT COUNT(*) FROM {$this->db->table( 'game' )} WHERE a1_player_id = %d", array( $this->id['hanna_player'] ) ) );

		// Protokoll: ein Eintrag ohne den alten Namen und ohne Adressen; frühere Adressen sind entfernt.
		$log = $audit->for_entity( 'person', $this->id['hanna'] );
		self::assertSame( array( 'anonymized', 'email_changed' ), array_column( $log, 'action' ) );
		self::assertSame( array( '5', 'wp-cli' ), array( $log[0]['user_id'], $log[0]['note'] ) );
		self::assertSame(
			array(
				'status'    => 'inactive',
				'last_name' => 'Ehemaliges Mitglied 1',
			),
			json_decode( (string) $log[0]['after_json'], true )
		);
		self::assertSame( 77, json_decode( (string) $log[0]['before_json'], true )['user_id'] );
		$stored = implode( ' ', array_map( static fn( array $row ): string => implode( ' ', array_map( 'strval', $row ) ), $log ) );
		foreach ( array( 'Hanna', 'Heim', 'example.org', '555' ) as $trace ) {
			self::assertStringNotContainsString( $trace, $stored, $trace );
		}
		self::assertNull( $log[1]['before_json'] );
		self::assertNull( $log[1]['after_json'] );
	}

	public function test_placeholders_are_numbered_and_stay_unique(): void {
		self::assertSame( 'Ehemaliges Mitglied 1', $this->data->anonymize( $this->id['hanna'] )['name'] );
		self::assertSame( 'Ehemaliges Mitglied 2', $this->data->anonymize( $this->id['gustav'] )['name'] );

		// Lücken werden nicht neu vergeben: Es zählt die höchste Nummer.
		$s = new Seeder( $this->db );
		$this->db->update( 'person', array( 'last_name' => 'Ehemaliges Mitglied 7' ), array( 'id' => $this->id['hanna'] ) );
		$third = $s->person( 'Ehemaliges Mitglied und Gönner', 'Erika' );
		self::assertSame( 'Ehemaliges Mitglied 8', $this->data->anonymize( $third )['name'], 'ähnliche Namen zählen nicht' );
	}

	public function test_anonymize_refuses_unknown_and_already_anonymized_people(): void {
		$this->data->anonymize( $this->id['hanna'] );

		try {
			$this->data->anonymize( $this->id['hanna'] );
			self::fail( 'Zweite Anonymisierung hätte abgelehnt werden müssen.' );
		} catch ( \RuntimeException $e ) {
			self::assertSame( 'Die Person ist bereits anonymisiert.', $e->getMessage() );
		}
		self::assertSame( 'Ehemaliges Mitglied 1', $this->person( 'hanna' )['last_name'] );
		self::assertCount( 1, ( new AuditLog( $this->db ) )->for_entity( 'person', $this->id['hanna'] ) );

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'Person nicht gefunden.' );
		$this->data->anonymize( 999999 );
	}

	public function test_read_models_show_the_placeholder_and_keep_license_and_results(): void {
		$this->data->anonymize( $this->id['hanna'] );
		$model = new ReadModel( $this->db, new NullCache() );

		$player = $model->players->by_license( 90001 );
		self::assertNotNull( $player );
		self::assertSame( 'Ehemaliges Mitglied 1', $player['name'] );
		self::assertSame( 'ehemaliges-mitglied-1', $player['slug'], 'neue Adresse des Profils ohne den alten Namen' );

		$report = $model->reports->get( $this->id['fixture'] );
		self::assertNotNull( $report );
		self::assertSame( 'Ehemaliges Mitglied 1', $report['lineup']['home'][0]['player']['name'] );
		self::assertSame( 'Ehemaliges Mitglied 1', $report['games'][0]['a'][0]['name'] );
		self::assertSame( 'Gustav Gast', $report['games'][0]['b'][0]['name'] );
		self::assertSame( 1, $report['home_score'] );

		$club = $model->clubs->by_slug( 'seefeld' );
		self::assertNotNull( $club );
		$roles = $model->clubs->details( $club['id'], $this->id['season'] )['roles'];
		self::assertSame( array( 'Ehemaliges Mitglied 1' ), array_column( $roles, 'name' ) );
	}
}
