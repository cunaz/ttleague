<?php
/**
 * Rechte des Saisonbetriebs über map_meta_cap: Verschiebung, Gruppenzettel, geplante Saison.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Tests\WordPress\Access;

use TTLeague\Access\Capabilities;
use TTLeague\Tests\Integration\Frontend\Seeder;
use TTLeague\Tests\WordPress\League;
use TTLeague\Tests\WordPress\TestCase;

/**
 * Liga siehe League: f1 ist Seefeld 1 (Heim) gegen Wollishofen 1.
 */
final class SeasonCapabilitiesTest extends TestCase {

	private League $league;

	/** @var array<string,int> Konten nach Schlüssel der Person. */
	private array $members = array();

	public function set_up(): void {
		parent::set_up();
		$this->league  = new League( $this->db, '2026-11-17 20:00:00' );
		$this->members = array();
	}

	public function test_both_sides_and_the_tk_may_reschedule_a_fixture(): void {
		$f1 = $this->league->id['f1'];

		self::assertSame( array( 'do_not_allow' ), map_meta_cap( Capabilities::RESCHEDULE, 0, $f1 ), 'abgemeldet' );
		self::assertTrue( user_can( $this->member( 'captain_h1' ), Capabilities::RESCHEDULE, $f1 ) );
		self::assertTrue( user_can( $this->member( 'captain_a1' ), Capabilities::RESCHEDULE, $f1 ) );
		self::assertFalse( user_can( $this->member( 'nobody' ), Capabilities::RESCHEDULE, $f1 ) );
		self::assertFalse( user_can( $this->member( 'captain_h1' ), Capabilities::RESCHEDULE, $this->league->id['old_fixture'] ), 'Vorsaison' );
		self::assertTrue( user_can( $this->account( 'ttl_tk', 'tk@example.invalid' ), Capabilities::RESCHEDULE, $f1 ) );
	}

	public function test_group_chief_and_host_tk_chef_enter_the_group_sheet(): void {
		$s     = new Seeder( $this->db );
		$rl    = $s->competition( $this->league->id['season'], 'RL', 'Ranglistenturnier' );
		$round = $this->db->insert(
			'rl_round',
			array(
				'competition_id' => $rl,
				'round_no'       => 2,
				'status'         => 'published',
			)
		);
		$group = $this->db->insert(
			'rl_group',
			array(
				'rl_round_id'     => $round,
				'category'        => 1,
				'group_no'        => 1,
				'host_club_id'    => $this->league->id['club_home'],
				'chief_player_id' => $this->league->id['a1'],
			)
		);

		self::assertTrue( user_can( $this->member( 'person_a1' ), Capabilities::ENTER_RL, $group ), 'Gruppenchef' );
		self::assertTrue( user_can( $this->member( 'chef_home' ), Capabilities::ENTER_RL, $group ), 'TK-Chef des Platzclubs' );
		self::assertFalse( user_can( $this->member( 'person_a3' ), Capabilities::ENTER_RL, $group ) );
		self::assertFalse( user_can( $this->member( 'captain_h1' ), Capabilities::ENTER_RL, $group ) );
		self::assertTrue( user_can( $this->account( 'ttl_tk', 'tk@example.invalid' ), Capabilities::ENTER_RL, $group ) );
		self::assertSame( array( 'do_not_allow' ), map_meta_cap( Capabilities::ENTER_RL, 0, $group ), 'abgemeldet' );
	}

	public function test_tk_chef_of_the_planned_season_manages_the_club_but_invites_nobody_yet(): void {
		$s    = new Seeder( $this->db );
		$next = $s->season( '2027-28', 'planned' );
		$new  = $s->person( 'Neu', 'Nora' );
		$s->role( $next, $this->league->id['club_away'], $new, 'tk_chef' );
		$user = $this->account( 'subscriber', 'nora.neu@example.invalid', $new );

		self::assertTrue( user_can( $user, Capabilities::MANAGE_CLUB, $this->league->id['club_away'] ) );
		self::assertFalse( user_can( $user, Capabilities::MANAGE_CLUB, $this->league->id['club_home'] ) );
		self::assertFalse( user_can( $user, Capabilities::INVITE_PERSON, $this->league->id['person_a3'] ), 'Einladungen bleiben beim TK-Chef der aktiven Saison' );
		self::assertFalse( user_can( $user, Capabilities::ENTER_RESULT, $this->league->id['f1'] ) );
	}

	private function member( string $key ): int {
		$this->members[ $key ] ??= $this->account( 'subscriber', $key . '@example.invalid', $this->league->id[ $key ] );
		return $this->members[ $key ];
	}
}
