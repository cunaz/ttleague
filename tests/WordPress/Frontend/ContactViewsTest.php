<?php
/**
 * Kontaktdaten der Captains und TK-Chefs auf Club-, Mannschafts- und Erfassungsseite und im
 * Matchreport: nur mit Recht sichtbar, nie im Cache, für Gäste unveränderte Ausgabe.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Tests\WordPress\Frontend;

use TTLeague\Infrastructure\Cache\DataVersion;
use TTLeague\ReadModel\ReadModel;
use TTLeague\Results\EntryBlock;
use TTLeague\Tests\WordPress\League;
use TTLeague\Tests\WordPress\TestCase;

/**
 * Liga siehe League. Hanna Heim (Captain Seefeld 1) hat E-Mail und freigegebenes Telefon,
 * Gustav Gast (Captain Wollishofen 1) E-Mail und ein nicht freigegebenes Telefon, Charlotte
 * Chef (TK-Chefin Seefeld) nur ein freigegebenes Telefon.
 */
final class ContactViewsTest extends TestCase {

	private const HANNA_MAIL   = 'hanna.heim@example.invalid';
	private const HANNA_PHONE  = '079 555 01 01';
	private const GUSTAV_MAIL  = 'gustav.gast@example.invalid';
	private const GUSTAV_PHONE = '079 555 01 02';
	private const CHEF_PHONE   = '+41 (0)44 555 01 03';

	private League $league;

	public function set_up(): void {
		parent::set_up();
		ReadModel::set_instance( null );
		$this->league = new League( $this->db, '2026-11-10 20:00:00' );
		$this->contact( 'captain_h1', self::HANNA_MAIL, self::HANNA_PHONE, true );
		$this->contact( 'captain_a1', self::GUSTAV_MAIL, self::GUSTAV_PHONE, false );
		$this->contact( 'chef_home', null, self::CHEF_PHONE, true );
	}

	public function tear_down(): void {
		ReadModel::set_instance( null );
		$_GET = array();
		parent::tear_down();
	}

	public function test_club_page_shows_contacts_only_to_officials(): void {
		$block = '<!-- wp:ttleague/club {"club":"seefeld"} /-->';
		$guest = $this->render_as( 0, $block );

		self::assertStringContainsString( 'Hanna Heim', $guest );
		self::assertStringContainsString( 'Kontaktdaten sehen angemeldete Funktionärinnen und Funktionäre.', $guest );
		$this->assert_no_contacts( $guest );
		self::assertSame( $guest, $this->render_as( $this->member( 'nobody' ), $block ), 'angemeldet ohne Funktion: wie Gäste' );
		self::assertSame( $guest, $this->render_as( $this->member( 'person_h1' ), $block ), 'Cup-Spieler ohne Funktion: wie Gäste' );

		$official = $this->render_as( $this->member( 'captain_a1' ), $block );
		self::assertStringContainsString( '<a class="ttl-contact__link" href="mailto:' . self::HANNA_MAIL . '">' . self::HANNA_MAIL . '</a>', $official );
		self::assertStringContainsString( '<a class="ttl-contact__link" href="tel:0795550101">' . self::HANNA_PHONE . '</a>', $official );
		self::assertStringContainsString( '<a class="ttl-contact__link" href="tel:+41445550103">' . self::CHEF_PHONE . '</a>', $official, 'TK-Chefin ohne E-Mail: nur Telefon' );
		self::assertSame( 1, substr_count( $official, 'mailto:' ), 'keine leeren Links für Personen ohne E-Mail' );
		self::assertStringContainsString( 'Telefonnummern erscheinen, wenn die Person sie freigegeben hat.', $official );
		self::assertStringNotContainsString( 'Kontaktdaten sehen angemeldete', $official );

		self::assertSame( $guest, $this->render_as( 0, $block ), 'Gast nach dem Aufruf eines Funktionärs' );
		$this->assert_cache_has_no_contacts();
	}

	public function test_guest_output_stays_the_same_when_an_official_fills_the_cache(): void {
		$blocks = array(
			'<!-- wp:ttleague/club {"club":"seefeld"} /-->',
			'<!-- wp:ttleague/team {"season":"2026-27","team":"seefeld-1"} /-->',
			'<!-- wp:ttleague/match-report {"fixture":' . $this->league->id['f1'] . '} /-->',
		);
		$guest  = array();
		foreach ( $blocks as $block ) {
			$guest[ $block ] = $this->render_as( 0, $block );
			$this->assert_no_contacts( $guest[ $block ] );
		}

		// Neue Datenversion: Der Cache ist leer, und ein Funktionär füllt ihn mit seinem Aufruf.
		DataVersion::bump();
		$tk = $this->account( 'ttl_tk', 'tk@example.invalid' );
		foreach ( $blocks as $block ) {
			self::assertStringContainsString( 'mailto:' . self::HANNA_MAIL, $this->render_as( $tk, $block ), $block );
		}
		$this->assert_cache_has_no_contacts();

		foreach ( $blocks as $block ) {
			self::assertSame( $guest[ $block ], $this->render_as( 0, $block ), $block );
		}
	}

	public function test_team_page_shows_the_contact_of_the_captain(): void {
		$block = '<!-- wp:ttleague/team {"season":"2026-27","team":"wollishofen-1"} /-->';
		$guest = $this->render_as( 0, $block );
		$this->assert_no_contacts( $guest );

		$official = $this->render_as( $this->member( 'chef_home' ), $block );
		self::assertStringContainsString( 'Gustav Gast <span class="ttl-contact"><a class="ttl-contact__link" href="mailto:' . self::GUSTAV_MAIL . '">', $official );
		self::assertStringNotContainsString( 'tel:', $official, 'Telefon nicht freigegeben' );
		self::assertStringNotContainsString( self::GUSTAV_PHONE, $official );
		self::assertSame( $guest, $this->render_as( 0, $block ) );
	}

	public function test_match_report_lists_the_contacts_of_both_sides(): void {
		$block = '<!-- wp:ttleague/match-report {"fixture":' . $this->league->id['f1'] . '} /-->';
		$guest = $this->render_as( 0, $block );
		$this->assert_no_contacts( $guest );
		self::assertStringNotContainsString( 'ttl-kontakt', $guest );

		$official = $this->render_as( $this->member( 'captain_h1' ), $block );
		self::assertStringContainsString( '<h2 id="ttl-kontakt-title" class="ttl-section__title">Kontakt</h2>', $official );
		self::assertMatchesRegularExpression( '#<h3 class="ttl-subtitle">Seefeld 1</h3>.*<dt>TK-Chef</dt><dd>Charlotte Chef<br>.*<dt>Captain</dt><dd>Hanna Heim<br>.*<h3 class="ttl-subtitle">Wollishofen 1</h3>.*<dt>Captain</dt><dd>Gustav Gast<br>#s', $official );
		self::assertStringContainsString( 'mailto:' . self::GUSTAV_MAIL, $official );
		self::assertStringNotContainsString( self::GUSTAV_PHONE, $official );
		self::assertSame( $guest, $this->render_as( 0, $block ) );
	}

	public function test_names_in_the_contact_section_are_escaped(): void {
		$this->db->update( 'person', array( 'first_name' => '<b>Gustav</b>' ), array( 'id' => $this->league->id['captain_a1'] ) );

		$html = $this->render_as( $this->member( 'captain_h1' ), '<!-- wp:ttleague/match-report {"fixture":' . $this->league->id['f1'] . '} /-->' );

		self::assertStringContainsString( '&lt;b&gt;Gustav&lt;/b&gt; Gast<br>', $html );
		self::assertStringNotContainsString( '<b>Gustav</b>', $html );
	}

	public function test_archived_season_shows_no_contacts(): void {
		// Hanna Heim war auch in der Vorsaison Captain von Seefeld 1.
		$block = '<!-- wp:ttleague/team {"season":"2025-26","team":"seefeld-1"} /-->';
		$guest = $this->render_as( 0, $block );

		self::assertStringContainsString( 'Hanna Heim', $guest );
		self::assertSame( $guest, $this->render_as( $this->account( 'ttl_tk', 'tk@example.invalid' ), $block ) );
		self::assertSame(
			$this->render_as( 0, '<!-- wp:ttleague/club {"club":"seefeld","season":"2025-26"} /-->' ),
			$this->render_as( $this->member( 'captain_a1' ), '<!-- wp:ttleague/club {"club":"seefeld","season":"2025-26"} /-->' )
		);
	}

	public function test_entry_page_shows_the_contact_of_the_other_side(): void {
		$_GET[ EntryBlock::PARAM ] = (string) $this->league->id['f1'];

		wp_set_current_user( $this->member( 'captain_h1' ) );
		$html = EntryBlock::render();
		self::assertStringContainsString( '<h3 id="ttl-kontakt-title" class="ttl-section__title">Kontakt</h3>', $html );
		self::assertStringContainsString( '<h4 class="ttl-subtitle">Wollishofen 1</h4>', $html );
		self::assertStringContainsString( 'mailto:' . self::GUSTAV_MAIL, $html );
		self::assertStringNotContainsString( self::GUSTAV_PHONE, $html );

		// Hat ein Recht für die Seite, aber keine Rolle für diese Begegnung: nur der Hinweis.
		$other = $this->league->id['nobody'];
		$this->db->insert(
			'club_role',
			array(
				'season_id' => $this->league->id['season'],
				'club_id'   => $this->league->id['club_away'],
				'person_id' => $other,
				'role'      => 'captain',
				'team_id'   => null,
			)
		);
		wp_set_current_user( $this->member( 'nobody' ) );
		$html = EntryBlock::render();
		self::assertStringContainsString( 'ttl-entry--gate', $html );
		$this->assert_no_contacts( $html );
	}

	public function test_cup_players_without_function_see_no_contacts_on_the_entry_page(): void {
		$_GET[ EntryBlock::PARAM ] = (string) $this->league->id['cup'];

		wp_set_current_user( $this->member( 'person_h1' ) );
		$html = EntryBlock::render();
		self::assertStringContainsString( 'ttl-e-form', $html, 'Formular für die Cup-Mannschaft' );
		self::assertStringNotContainsString( 'ttl-kontakt', $html );
		$this->assert_no_contacts( $html );

		wp_set_current_user( $this->member( 'chef_home' ) );
		$html = EntryBlock::render();
		self::assertStringContainsString( '<dt>TK-Chef</dt><dd>Charlotte Chef<br>', $html, 'Cup: TK-Chefs der Clubs' );
		self::assertStringNotContainsString( '<dt>Captain</dt>', $html );
	}

	private function contact( string $person, ?string $email, ?string $phone, bool $visible ): void {
		$this->db->update(
			'person',
			array(
				'email'         => $email,
				'phone'         => $phone,
				'phone_visible' => $visible ? 1 : 0,
			),
			array( 'id' => $this->league->id[ $person ] )
		);
	}

	/**
	 * Abonnenten-Konto für eine Person der Liga (eigene Adresse, unabhängig von person.email).
	 */
	private function member( string $person ): int {
		return $this->account( 'subscriber', 'konto.' . str_replace( '_', '.', $person ) . '@example.invalid', $this->league->id[ $person ] );
	}

	private function render_as( int $user_id, string $block ): string {
		wp_set_current_user( $user_id );
		return do_blocks( $block );
	}

	private function assert_no_contacts( string $html ): void {
		self::assertStringNotContainsString( 'mailto:', $html );
		self::assertStringNotContainsString( 'tel:', $html );
		self::assertStringNotContainsString( 'ttl-contact', $html );
		foreach ( array( self::HANNA_MAIL, self::HANNA_PHONE, self::GUSTAV_MAIL, self::GUSTAV_PHONE, self::CHEF_PHONE ) as $value ) {
			self::assertStringNotContainsString( $value, $html );
		}
	}

	/**
	 * Weder der Object-Cache des Plugins noch die Transients (Rückfall ohne persistenten
	 * Object-Cache) enthalten Kontaktdaten.
	 */
	private function assert_cache_has_no_contacts(): void {
		global $wpdb, $wp_object_cache;
		$group      = (array) ( $wp_object_cache->cache['ttleague'] ?? array() );
		$transients = (array) $wpdb->get_col( "SELECT option_value FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_ttl\\_%'" );
		self::assertNotSame( array(), $group, 'Lesemodelle sind im Object-Cache' );
		self::assertNotSame( array(), $transients, 'Lesemodelle sind als Transient gespeichert' );
		$stored = serialize( $group ) . implode( '', $transients ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- nur zum Durchsuchen des Cache-Inhalts im Test.
		self::assertStringContainsString( 'Hanna', $stored, 'Namen stehen im Cache' );
		foreach ( array( self::HANNA_MAIL, self::HANNA_PHONE, '0795550101', self::GUSTAV_MAIL, self::GUSTAV_PHONE, self::CHEF_PHONE, '+41445550103' ) as $value ) {
			self::assertStringNotContainsString( $value, $stored );
		}
	}
}
