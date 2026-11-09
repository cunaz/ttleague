<?php
/**
 * Empfänger je Fall: Meisterschaft, Cup, Clubduell, TK.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Tests\Integration\Notifications;

use TTLeague\Notifications\Recipient;

final class RecipientResolverTest extends NotificationTestCase {

	/**
	 * @param list<Recipient> $recipients Empfänger.
	 * @return list<string>
	 */
	private static function keys( array $recipients ): array {
		return array_map( static fn( Recipient $r ): string => $r->key(), $recipients );
	}

	public function test_championship_sides_are_captains_of_the_team_and_tk_chefs_of_the_club(): void {
		$ctx = $this->repo->context( $this->id['f1'] );

		$home = $this->recipients->side( $ctx, 'home' );
		self::assertSame( array( $this->p( 'captain_h1' ), $this->p( 'no_email' ), $this->p( 'chef_home' ) ), self::keys( $home ), 'aktive Captains von Seefeld 1 und der TK-Chef, nicht Seefeld 2' );
		self::assertSame( 'captain.h1@example.org', $home[0]->email );
		self::assertSame( 101, $home[0]->user_id );
		self::assertSame( 'Hanna', $home[0]->salutation );
		self::assertNull( $home[1]->email, 'ungültige Adresse wird nicht verwendet' );

		self::assertSame( array( $this->p( 'captain_a1' ), $this->p( 'chef_away' ) ), self::keys( $this->recipients->side( $ctx, 'away' ) ) );
	}

	public function test_roles_of_another_season_do_not_count(): void {
		$other = $this->db->insert(
			'season',
			array(
				'name'       => '2025/26',
				'slug'       => '2025-26',
				'start_date' => '2025-08-01',
				'end_date'   => '2026-07-31',
				'status'     => 'archived',
				'created_at' => '2026-01-01 00:00:00',
				'updated_at' => '2026-01-01 00:00:00',
			)
		);
		$club  = (int) $this->db->get_var( "SELECT club_id FROM {$this->db->table( 'club_role' )} WHERE person_id = %d", array( $this->person['chef_home'] ) );
		$this->db->insert(
			'club_role',
			array(
				'season_id' => $other,
				'club_id'   => $club,
				'person_id' => $this->person['unconnected'],
				'role'      => 'tk_chef',
				'team_id'   => null,
			)
		);
		$keys = self::keys( $this->recipients->side( $this->repo->context( $this->id['f1'] ), 'home' ) );
		self::assertNotContains( $this->p( 'unconnected' ), $keys );
	}

	public function test_cup_sides_are_the_players_of_the_entry_and_tk_chefs(): void {
		$ctx = $this->repo->context( $this->id['cup'] );

		self::assertSame( array( $this->p( 'cup_h1' ), $this->p( 'cup_h2' ), $this->p( 'chef_home' ) ), self::keys( $this->recipients->side( $ctx, 'home' ) ) );
		self::assertSame( array( $this->p( 'cup_a1' ), $this->p( 'cup_a2' ), $this->p( 'chef_away' ) ), self::keys( $this->recipients->side( $ctx, 'away' ) ) );
		self::assertNotContains( $this->p( 'captain_h1' ), self::keys( $this->recipients->sides( $ctx ) ), 'Captains der Meisterschaft sind im Cup keine Empfänger' );
	}

	public function test_club_derby_lists_the_tk_chef_once(): void {
		$ctx = $this->repo->context( $this->id['derby'] );

		self::assertSame( array( $this->p( 'captain_h2' ), $this->p( 'chef_home' ) ), self::keys( $this->recipients->side( $ctx, 'away' ) ) );
		$both = self::keys( $this->recipients->sides( $ctx ) );
		self::assertSame( array( $this->p( 'captain_h1' ), $this->p( 'no_email' ), $this->p( 'chef_home' ), $this->p( 'captain_h2' ) ), $both );
	}

	public function test_tk_are_tk_role_accounts_and_administrators_with_a_person(): void {
		$tk = $this->recipients->tk();

		self::assertSame( array( $this->p( 'tk' ), 'u108', $this->p( 'admin' ) ), self::keys( $tk ), 'Administrator 109 ohne Person erhält nichts' );
		self::assertSame( 'tk@example.org', $tk[0]->email, 'Adresse der Person vor der des Kontos' );
		self::assertSame( 103, $tk[0]->user_id );
		self::assertNull( $tk[1]->person_id );
		self::assertSame( 'tk.konto@example.org', $tk[1]->email );
		self::assertSame( 'TK-Konto', $tk[1]->salutation );
	}

	public function test_tk_account_of_a_person_without_address_uses_the_account_address(): void {
		$this->db->update( 'person', array( 'email' => null ), array( 'id' => $this->person['tk'] ) );
		$tk = $this->recipients->tk();
		self::assertSame( $this->p( 'tk' ), $tk[0]->key() );
		self::assertSame( 'konto.103@example.org', $tk[0]->email );
	}

	public function test_valid_email(): void {
		self::assertSame( 'a.b@example.org', Recipient::valid_email( ' a.b@example.org ' ) );
		self::assertNull( Recipient::valid_email( 'keine-adresse' ) );
		self::assertNull( Recipient::valid_email( '' ) );
		self::assertNull( Recipient::valid_email( null ) );
		self::assertNull( Recipient::valid_email( 'a@b@example.org' ) );
	}
}
