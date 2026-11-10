<?php
/**
 * Datenschutz-Werkzeuge von WordPress (Export, Löschung) und Anonymisierung mit Cache.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Tests\WordPress\Accounts;

use TTLeague\Access\Capabilities;
use TTLeague\Accounts\MagicLinks;
use TTLeague\Accounts\Privacy;
use TTLeague\Accounts\Services;
use TTLeague\Infrastructure\Cache\DataVersion;
use TTLeague\ReadModel\ReadModel;
use TTLeague\Tests\WordPress\League;
use TTLeague\Tests\WordPress\TestCase;

/**
 * Liga siehe League. Spieler1 Seefelder (h1) ist für Seefeld gemeldet, spielt in der
 * Cup-Mannschaft und ist hier zusätzlich Captain von Seefeld 1; er hat E-Mail, Telefon,
 * Einstellungen, ein Push-Abo, einen Anmeldelink und ein Konto mit anderer Adresse.
 */
final class PrivacyTest extends TestCase {

	private const EMAIL   = 'spieler1.seefelder@example.invalid';
	private const ACCOUNT = 'konto.spieler1@example.invalid';
	private const PHONE   = '079 555 01 01';

	private League $league;

	private int $person;

	private int $user;

	public function set_up(): void {
		parent::set_up();
		ReadModel::set_instance( null );
		$this->league = new League( $this->db, '2026-11-10 20:00:00' );
		$this->person = $this->league->id['person_h1'];
		$this->db->update(
			'person',
			array(
				'email'         => self::EMAIL,
				'phone'         => self::PHONE,
				'phone_visible' => 1,
				'consent_at'    => '2026-09-01 10:00:00',
			),
			array( 'id' => $this->person )
		);
		$this->db->insert(
			'club_role',
			array(
				'season_id' => $this->league->id['season'],
				'club_id'   => $this->league->id['club_home'],
				'person_id' => $this->person,
				'role'      => 'captain',
				'team_id'   => $this->league->id['team_h1'],
			)
		);
		$this->user = $this->account( 'subscriber', self::ACCOUNT, $this->person );
		Services::preferences()->set( $this->person, 'confirm_request', false, true );
		$this->db->insert(
			'push_subscription',
			array(
				'person_id'     => $this->person,
				'endpoint_hash' => hash( 'sha256', 'https://push.example.invalid/abc' ),
				'endpoint'      => 'https://push.example.invalid/abc',
				'keys_json'     => '{}',
				'created_at'    => '2026-09-01 10:00:00',
			)
		);
		Services::links()->create( $this->person, MagicLinks::LOGIN_TTL, '192.0.2.10' );
	}

	public function tear_down(): void {
		ReadModel::set_instance( null );
		parent::tear_down();
	}

	private function rows( string $table ): int {
		return (int) $this->db->get_var( "SELECT COUNT(*) FROM {$this->db->table( $table )} WHERE person_id = %d", array( $this->person ) );
	}

	/**
	 * Export als Gruppe => Liste der Einträge (Bezeichnung => Wert).
	 *
	 * @return array<string,list<array<string,string>>>
	 */
	private function export( string $email ): array {
		$result = Privacy::export( $email );
		self::assertTrue( $result['done'] );
		$groups = array();
		foreach ( $result['data'] as $item ) {
			self::assertNotSame( '', $item['group_label'] );
			self::assertStringStartsWith( 'ttleague-', $item['item_id'] );
			$groups[ $item['group_id'] ][] = array_column( $item['data'], 'value', 'name' );
		}
		return $groups;
	}

	public function test_exporter_and_eraser_are_registered_with_wordpress(): void {
		// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Filter von WordPress, hier wie von den Werkzeugen aufgerufen.
		$exporters = apply_filters( 'wp_privacy_personal_data_exporters', array() );
		$erasers   = apply_filters( 'wp_privacy_personal_data_erasers', array() );
		// phpcs:enable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound

		self::assertSame( 'Spielbetrieb (ttleague)', $exporters[ Privacy::KEY ]['exporter_friendly_name'] );
		self::assertSame( 'Spielbetrieb (ttleague)', $erasers[ Privacy::KEY ]['eraser_friendly_name'] );
		self::assertIsCallable( $exporters[ Privacy::KEY ]['callback'] );
		self::assertIsCallable( $erasers[ Privacy::KEY ]['callback'] );
		self::assertSame( Privacy::export( self::EMAIL ), call_user_func( $exporters[ Privacy::KEY ]['callback'], self::EMAIL, 1 ) );
	}

	public function test_export_contains_profile_functions_registrations_and_notifications(): void {
		$groups = $this->export( self::EMAIL );

		self::assertSame( array( 'ttleague-profile', 'ttleague-functions', 'ttleague-registrations', 'ttleague-notifications' ), array_keys( $groups ) );
		self::assertSame(
			array(
				'Vorname'  => 'Spieler1',
				'Nachname' => 'Seefelder',
				'E-Mail'   => self::EMAIL,
				'Telefon'  => self::PHONE,
				'Telefon für Captains, TK-Chefs und TK sichtbar' => 'ja',
				'Einwilligung zum Datenschutzhinweis (UTC)' => '2026-09-01 10:00:00',
				'Status'   => 'aktiv',
			),
			$groups['ttleague-profile'][0]
		);
		self::assertSame(
			array(
				array(
					'Saison'     => '2026/27',
					'Funktion'   => 'Captain',
					'Club'       => 'TTC Seefeld',
					'Mannschaft' => 'Seefeld 1',
				),
			),
			$groups['ttleague-functions']
		);
		self::assertCount( 1, $groups['ttleague-registrations'] );
		self::assertSame( array( '2026/27', 'TTC Seefeld' ), array( $groups['ttleague-registrations'][0]['Saison'], $groups['ttleague-registrations'][0]['Club'] ) );
		self::assertMatchesRegularExpression( '/^[A-D][0-9]+$/', $groups['ttleague-registrations'][0]['Klassierung'] );
		self::assertMatchesRegularExpression( '/^9[0-9]{4}$/', $groups['ttleague-registrations'][0]['Lizenznummer'] );

		$notifications = $groups['ttleague-notifications'][0];
		self::assertSame( 'E-Mail: nein, Push: ja', $notifications['Resultat zum Bestätigen'], 'gespeicherte Einstellung' );
		self::assertSame( 'E-Mail: ja, Push: nein', $notifications['Entscheid der TK'], 'Standard ohne Eintrag' );
		self::assertSame( '1', $notifications['Push-Abos (Geräte)'] );
	}

	public function test_export_finds_the_person_through_the_linked_account_or_not_at_all(): void {
		self::assertSame( $this->export( self::EMAIL ), $this->export( self::ACCOUNT ), 'Adresse des verknüpften Kontos' );
		self::assertSame( $this->export( self::EMAIL ), $this->export( strtoupper( self::EMAIL ) ) );
		self::assertSame( array(), $this->export( 'niemand@example.invalid' ) );

		// Ein Konto ohne Person hat im Spielbetrieb keine Daten.
		$this->account( 'subscriber', 'ohne.person@example.invalid' );
		self::assertSame( array(), $this->export( 'ohne.person@example.invalid' ) );

		// Person ohne Funktion und Meldung: Profil und Benachrichtigungen (Standard).
		$this->db->update( 'person', array( 'email' => 'nina.niemand@example.invalid' ), array( 'id' => $this->league->id['nobody'] ) );
		$nobody = $this->export( 'nina.niemand@example.invalid' );
		self::assertSame( array( 'ttleague-profile', 'ttleague-notifications' ), array_keys( $nobody ) );
		self::assertArrayNotHasKey( 'Telefon', $nobody['ttleague-profile'][0], 'leere Angaben fehlen' );
		self::assertSame( 'nein', $nobody['ttleague-profile'][0]['Telefon für Captains, TK-Chefs und TK sichtbar'] );
	}

	public function test_eraser_removes_private_data_and_reports_what_is_retained(): void {
		$result = Privacy::erase( self::EMAIL );

		self::assertTrue( $result['items_removed'] );
		self::assertTrue( $result['items_retained'] );
		self::assertTrue( $result['done'] );
		self::assertCount( 1, $result['messages'] );
		self::assertStringContainsString( 'Name, E-Mail-Adresse, Lizenznummer, Meldungen (Club, Klassierung), Funktionen und Resultate bleiben gespeichert', $result['messages'][0] );
		self::assertStringContainsString( 'gehören zum Spielbetrieb', $result['messages'][0] );

		$person = $this->db->get_row( "SELECT * FROM {$this->db->table( 'person' )} WHERE id = %d", array( $this->person ) );
		self::assertNull( $person['phone'] );
		self::assertSame( '0', $person['phone_visible'] );
		self::assertSame( 0, $this->rows( 'notification_pref' ) + $this->rows( 'push_subscription' ) + $this->rows( 'login_token' ) );
		self::assertSame( array( 'Spieler1', 'Seefelder', self::EMAIL, (string) $this->user ), array( $person['first_name'], $person['last_name'], $person['email'], $person['user_id'] ) );
		self::assertSame( 1, $this->rows( 'club_role' ) );
		self::assertInstanceOf( \WP_User::class, get_userdata( $this->user ) );

		// Zweiter Lauf: nichts mehr zu entfernen, aber weiterhin Aufbewahrtes.
		$again = Privacy::erase( self::ACCOUNT );
		self::assertFalse( $again['items_removed'] );
		self::assertTrue( $again['items_retained'] );

		self::assertSame(
			array(
				'items_removed'  => false,
				'items_retained' => false,
				'messages'       => array(),
				'done'           => true,
			),
			Privacy::erase( 'niemand@example.invalid' )
		);
	}

	public function test_anonymize_shows_the_placeholder_in_cached_views_and_ends_the_rights(): void {
		$players = '<!-- wp:ttleague/players {"club":"seefeld"} /-->';
		$club    = '<!-- wp:ttleague/club {"club":"seefeld"} /-->';
		wp_set_current_user( 0 );
		self::assertStringContainsString( 'Spieler1 Seefelder', do_blocks( $players ) );
		self::assertStringContainsString( 'Spieler1 Seefelder', do_blocks( $club ) );
		self::assertTrue( user_can( $this->user, Capabilities::ENTER_RESULT, $this->league->id['f1'] ) );
		$version = DataVersion::get();

		$result = Privacy::anonymize( $this->person, 1, 'test' );

		self::assertSame( 'Ehemaliges Mitglied 1', $result['name'] );
		self::assertSame( $this->user, $result['user_id'] );
		self::assertSame( $version + 1, DataVersion::get() );
		foreach ( array( $players, $club ) as $block ) {
			$html = do_blocks( $block );
			self::assertStringContainsString( 'Ehemaliges Mitglied 1', $html );
			self::assertStringNotContainsString( 'Spieler1 Seefelder', $html );
		}
		self::assertStringContainsString( '-ehemaliges-mitglied-1/', do_blocks( $players ), 'Adresse des Profils ohne den alten Namen' );

		self::assertInstanceOf( \WP_User::class, get_userdata( $this->user ), 'das Konto wird nicht gelöscht' );
		self::assertNull( $this->linked_user( $this->person ) );
		self::assertFalse( user_can( $this->user, Capabilities::ENTER_RESULT, $this->league->id['f1'] ), 'ohne Verknüpfung keine Rechte' );
		self::assertFalse( user_can( $this->user, Capabilities::VIEW_CONTACTS ) );
		self::assertSame( array(), $this->export( self::EMAIL ), 'unter der alten Adresse nicht mehr auffindbar' );
		self::assertSame( array(), $this->export( self::ACCOUNT ) );
	}
}
