<?php
/**
 * Fachliche Rechte über map_meta_cap mit echten WordPress-Konten und Rollen.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Tests\WordPress\Access;

use TTLeague\Access\Capabilities;
use TTLeague\Tests\WordPress\League;
use TTLeague\Tests\WordPress\TestCase;

/**
 * Liga siehe League: f1 ist Seefeld 1 (Heim) gegen Wollishofen 1, cup die Cup-Begegnung der
 * beiden Clubs, old_fixture eine Begegnung der abgeschlossenen Vorsaison.
 */
final class CapabilitiesTest extends TestCase {

	private League $league;

	public function set_up(): void {
		parent::set_up();
		$this->league = new League( $this->db, '2026-11-10 20:00:00' );
	}

	public function test_meta_capabilities_are_mapped_through_map_meta_cap(): void {
		self::assertSame( array( 'do_not_allow' ), map_meta_cap( Capabilities::ENTER_RESULT, 0, $this->id( 'f1' ) ), 'abgemeldet' );
		self::assertSame( array( 'edit_posts' ), map_meta_cap( 'edit_posts', 0 ), 'andere Rechte unverändert' );
	}

	public function test_home_captain_enters_and_away_captain_confirms(): void {
		$home = $this->member( 'captain_h1' );
		$away = $this->member( 'captain_a1' );

		self::assertTrue( user_can( $home, Capabilities::ENTER_RESULT, $this->id( 'f1' ) ) );
		self::assertFalse( user_can( $home, Capabilities::CONFIRM, $this->id( 'f1' ) ) );
		self::assertFalse( user_can( $away, Capabilities::ENTER_RESULT, $this->id( 'f1' ) ) );
		self::assertTrue( user_can( $away, Capabilities::CONFIRM, $this->id( 'f1' ) ) );
		self::assertFalse( user_can( $home, Capabilities::MANAGE_CLUB, $this->id( 'club_home' ) ), 'Captain verwaltet keinen Club' );
		self::assertFalse( user_can( $home, Capabilities::TK ) );
	}

	public function test_tk_chef_acts_for_the_home_club(): void {
		$chef = $this->member( 'chef_home' );

		self::assertTrue( user_can( $chef, Capabilities::ENTER_RESULT, $this->id( 'f1' ) ) );
		self::assertFalse( user_can( $chef, Capabilities::CONFIRM, $this->id( 'f1' ) ) );
		self::assertTrue( user_can( $chef, Capabilities::MANAGE_CLUB, $this->id( 'club_home' ) ) );
		self::assertFalse( user_can( $chef, Capabilities::MANAGE_CLUB, $this->id( 'club_away' ) ) );
		self::assertTrue( user_can( $chef, Capabilities::INVITE_PERSON, $this->id( 'person_h3' ) ), 'für Seefeld gemeldet' );
		self::assertTrue( user_can( $chef, Capabilities::INVITE_PERSON, $this->id( 'captain_h1' ) ), 'Funktion bei Seefeld' );
		self::assertFalse( user_can( $chef, Capabilities::INVITE_PERSON, $this->id( 'person_a3' ) ), 'für Wollishofen gemeldet' );
		self::assertFalse( user_can( $chef, Capabilities::INVITE_PERSON, $this->id( 'nobody' ) ), 'ohne Club' );
	}

	public function test_cup_players_act_for_their_entry(): void {
		$home = $this->member( 'person_h1' );
		$away = $this->member( 'person_a1' );

		self::assertTrue( user_can( $home, Capabilities::ENTER_RESULT, $this->id( 'cup' ) ) );
		self::assertFalse( user_can( $home, Capabilities::CONFIRM, $this->id( 'cup' ) ) );
		self::assertTrue( user_can( $away, Capabilities::CONFIRM, $this->id( 'cup' ) ) );
		self::assertFalse( user_can( $home, Capabilities::ENTER_RESULT, $this->id( 'f1' ) ), 'Spieler ist nicht Captain' );
	}

	public function test_rights_end_with_the_season(): void {
		$home = $this->member( 'captain_h1' );

		self::assertFalse( user_can( $home, Capabilities::ENTER_RESULT, $this->id( 'old_fixture' ) ), 'Captain der Vorsaison' );
	}

	public function test_tk_role_and_administrators_may_do_everything(): void {
		$tk    = $this->account( 'ttl_tk', 'tk@example.invalid' );
		$admin = $this->account( 'administrator', 'verwaltung@example.invalid' );

		foreach ( array( $tk, $admin ) as $user_id ) {
			self::assertTrue( user_can( $user_id, Capabilities::TK ) );
			self::assertTrue( user_can( $user_id, Capabilities::ENTER_RESULT, $this->id( 'f1' ) ) );
			self::assertTrue( user_can( $user_id, Capabilities::CONFIRM, $this->id( 'f1' ) ) );
			self::assertTrue( user_can( $user_id, Capabilities::ENTER_RESULT, $this->id( 'old_fixture' ) ), 'auch ausserhalb der aktiven Saison' );
			self::assertTrue( user_can( $user_id, Capabilities::MANAGE_CLUB, $this->id( 'club_away' ) ) );
			self::assertTrue( user_can( $user_id, Capabilities::INVITE_PERSON, $this->id( 'nobody' ) ) );
		}
		self::assertSame( array( Capabilities::TK ), map_meta_cap( Capabilities::CONFIRM, $tk, $this->id( 'f1' ) ) );
	}

	public function test_accounts_without_person_or_function_have_no_rights(): void {
		$users = array(
			'Abonnent ohne Person'  => $this->account( 'subscriber', 'ohne.person@example.invalid' ),
			'Redaktion ohne Person' => $this->account( 'editor', 'redaktion@example.invalid' ),
			'Person ohne Funktion'  => $this->member( 'nobody' ),
		);

		foreach ( $users as $label => $user_id ) {
			self::assertFalse( user_can( $user_id, Capabilities::ENTER_RESULT, $this->id( 'f1' ) ), $label );
			self::assertFalse( user_can( $user_id, Capabilities::CONFIRM, $this->id( 'f1' ) ), $label );
			self::assertFalse( user_can( $user_id, Capabilities::MANAGE_CLUB, $this->id( 'club_home' ) ), $label );
			self::assertFalse( user_can( $user_id, Capabilities::INVITE_PERSON, $this->id( 'person_h3' ) ), $label );
			self::assertFalse( user_can( $user_id, Capabilities::TK ), $label );
		}
	}

	public function test_officials_and_the_tk_view_contacts(): void {
		self::assertTrue( user_can( $this->member( 'captain_h1' ), Capabilities::VIEW_CONTACTS ), 'Captain' );
		self::assertTrue( user_can( $this->member( 'chef_home' ), Capabilities::VIEW_CONTACTS ), 'TK-Chef' );
		self::assertFalse( user_can( $this->member( 'nobody' ), Capabilities::VIEW_CONTACTS ), 'Person ohne Funktion' );
		self::assertFalse( user_can( $this->member( 'person_h1' ), Capabilities::VIEW_CONTACTS ), 'Cup-Spieler ohne Funktion' );
		self::assertFalse( user_can( $this->account( 'subscriber', 'ohne.person@example.invalid' ), Capabilities::VIEW_CONTACTS ), 'Konto ohne Person' );
		self::assertFalse( user_can( $this->account( 'editor', 'redaktion@example.invalid' ), Capabilities::VIEW_CONTACTS ), 'Redaktion ohne Person' );

		$tk = $this->account( 'ttl_tk', 'tk@example.invalid' );
		self::assertTrue( user_can( $tk, Capabilities::VIEW_CONTACTS ) );
		self::assertTrue( user_can( $this->account( 'administrator', 'verwaltung@example.invalid' ), Capabilities::VIEW_CONTACTS ) );
		self::assertSame( array( Capabilities::TK ), map_meta_cap( Capabilities::VIEW_CONTACTS, $tk ) );
		self::assertSame( array( 'do_not_allow' ), map_meta_cap( Capabilities::VIEW_CONTACTS, 0 ), 'abgemeldet' );

		wp_set_current_user( 0 );
		self::assertFalse( current_user_can( Capabilities::VIEW_CONTACTS ) );
	}

	public function test_contacts_need_a_function_in_the_active_season(): void {
		$home = $this->member( 'captain_h1' );
		self::assertTrue( user_can( $home, Capabilities::VIEW_CONTACTS ) );

		// Hanna Heim bleibt Captain der Vorsaison; in der aktiven Saison verliert sie die Funktion.
		$this->db->delete(
			'club_role',
			array(
				'season_id' => $this->id( 'season' ),
				'person_id' => $this->id( 'captain_h1' ),
			)
		);
		Capabilities::policy()->reset();

		self::assertFalse( user_can( $home, Capabilities::VIEW_CONTACTS ) );
	}

	public function test_logged_out_visitors_and_missing_ids_get_nothing(): void {
		wp_set_current_user( 0 );
		self::assertFalse( current_user_can( Capabilities::ENTER_RESULT, $this->id( 'f1' ) ) );

		$home = $this->member( 'captain_h1' );
		wp_set_current_user( $home );
		self::assertTrue( current_user_can( Capabilities::ENTER_RESULT, $this->id( 'f1' ) ) );
		self::assertFalse( current_user_can( Capabilities::ENTER_RESULT ), 'ohne Begegnung' );
		self::assertFalse( current_user_can( Capabilities::ENTER_RESULT, 999999 ), 'unbekannte Begegnung' );
	}

	public function test_rights_follow_changes_after_a_reset_of_the_policy(): void {
		$nobody = $this->member( 'nobody' );
		self::assertFalse( user_can( $nobody, Capabilities::ENTER_RESULT, $this->id( 'f1' ) ) );

		$this->db->insert(
			'club_role',
			array(
				'season_id' => $this->id( 'season' ),
				'club_id'   => $this->id( 'club_home' ),
				'person_id' => $this->id( 'nobody' ),
				'role'      => 'captain',
				'team_id'   => $this->id( 'team_h1' ),
			)
		);
		Capabilities::policy()->reset();

		self::assertTrue( user_can( $nobody, Capabilities::ENTER_RESULT, $this->id( 'f1' ) ) );
	}

	/**
	 * Abonnenten-Konto für eine Person der Liga.
	 */
	private function member( string $person ): int {
		return $this->account( 'subscriber', str_replace( '_', '.', $person ) . '@example.invalid', $this->id( $person ) );
	}

	private function id( string $key ): int {
		return $this->league->id[ $key ];
	}
}
