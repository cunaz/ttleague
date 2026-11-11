<?php
/**
 * Tests der fachlichen Rechte aus Captain-, TK-Chef- und Cup-Daten.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Tests\Integration\Access;

use TTLeague\Access\AccessPolicy;
use TTLeague\Tests\Integration\Frontend\Seeder;
use TTLeague\Tests\Integration\IntegrationTestCase;

/**
 * Erfundene Clubs Seefeld, Wollishofen und Oerlikon. Seefeld 1 empfängt Wollishofen 1,
 * Seefeld 1 empfängt Seefeld 2 (Clubduell), im Cup trifft Seefeld 1 auf Wollishofen 1.
 */
final class AccessPolicyTest extends IntegrationTestCase {

	private AccessPolicy $policy;

	/** @var array<string,int> */
	private array $id = array();

	protected function setUp(): void {
		parent::setUp();
		$this->seed();
		$this->policy = new AccessPolicy( $this->db );
	}

	private function seed(): void {
		$s    = new Seeder( $this->db );
		$old  = $s->season( '2025-26', 'archived' );
		$now  = $s->season( '2026-27', 'active' );
		$home = $s->club( 'Seefeld', 1 );
		$away = $s->club( 'Wollishofen', 2 );
		$othr = $s->club( 'Oerlikon', 3 );

		$group = $s->competition( $now, 'MM', 'Gruppe A', 'A', 1 );
		$cup   = $s->competition( $now, 'CUP', 'Cup A', 'A' );
		$oldg  = $s->competition( $old, 'MM', 'Gruppe A', 'A', 1 );

		$t_home  = $s->team( $now, $home, 1, 'Seefeld 1', $group );
		$t_home2 = $s->team( $now, $home, 2, 'Seefeld 2', $group );
		$t_away  = $s->team( $now, $away, 1, 'Wollishofen 1', $group );
		$t_old   = $s->team( $old, $home, 1, 'Seefeld 1', $oldg );
		$t_oldaw = $s->team( $old, $away, 1, 'Wollishofen 1', $oldg );

		$captain_home  = $s->person( 'Heim', 'Hanna' );
		$captain_home2 = $s->person( 'Zweit', 'Zora' );
		$captain_away  = $s->person( 'Gast', 'Gustav' );
		$chef_home     = $s->person( 'Chef', 'Charlotte' );
		$chef_away     = $s->person( 'Leiter', 'Lukas' );
		$chef_other    = $s->person( 'Fremd', 'Fritz' );
		$player        = $s->player( 'Spieler', 'Paul' );
		$former        = $s->player( 'Früher', 'Frida' );
		$cup_a1        = $s->player( 'Cupper', 'Anna' );
		$cup_a2        = $s->player( 'Cupper', 'Ben' );
		$cup_b1        = $s->player( 'Pokal', 'Clara' );
		$cup_b2        = $s->player( 'Pokal', 'Dario' );

		$s->register( $player, $now, $home, 10 );
		$s->register( $former, $old, $home, 10 );
		foreach ( array( $cup_a1, $cup_a2 ) as $p ) {
			$s->register( $p, $now, $home, 12 );
		}
		foreach ( array( $cup_b1, $cup_b2 ) as $p ) {
			$s->register( $p, $now, $away, 12 );
		}

		$s->role( $now, $home, $captain_home, 'captain', $t_home );
		$s->role( $now, $home, $captain_home2, 'captain', $t_home2 );
		$s->role( $now, $away, $captain_away, 'captain', $t_away );
		$s->role( $now, $home, $chef_home, 'tk_chef' );
		$s->role( $now, $away, $chef_away, 'tk_chef' );
		$s->role( $now, $othr, $chef_other, 'tk_chef' );
		$s->role( $old, $home, $captain_home, 'captain', $t_old );

		$entry_home = $this->db->insert(
			'cup_entry',
			array(
				'competition_id' => $cup,
				'club_id'        => $home,
				'number'         => 1,
				'name'           => 'Seefeld 1',
				'player1_id'     => $cup_a1,
				'player2_id'     => $cup_a2,
			)
		);
		$entry_away = $this->db->insert(
			'cup_entry',
			array(
				'competition_id' => $cup,
				'club_id'        => $away,
				'number'         => 1,
				'name'           => 'Wollishofen 1',
				'player1_id'     => $cup_b1,
				'player2_id'     => $cup_b2,
			)
		);

		$this->id = array(
			'captain_home'  => $captain_home,
			'captain_home2' => $captain_home2,
			'captain_away'  => $captain_away,
			'chef_home'     => $chef_home,
			'chef_away'     => $chef_away,
			'chef_other'    => $chef_other,
			'player'        => $s->person_of( $player ),
			'former'        => $s->person_of( $former ),
			'cup_a1'        => $s->person_of( $cup_a1 ),
			'cup_a2'        => $s->person_of( $cup_a2 ),
			'cup_b1'        => $s->person_of( $cup_b1 ),
			'club_home'     => $home,
			'club_away'     => $away,
			'match'         => $s->fixture(
				$group,
				array(
					'home_team_id' => $t_home,
					'away_team_id' => $t_away,
					'scheduled_at' => '2026-11-12 20:00:00',
				)
			),
			'derby'         => $s->fixture(
				$group,
				array(
					'home_team_id' => $t_home,
					'away_team_id' => $t_home2,
					'scheduled_at' => '2026-11-19 20:00:00',
				)
			),
			'archived'      => $s->fixture(
				$oldg,
				array(
					'home_team_id' => $t_old,
					'away_team_id' => $t_oldaw,
					'scheduled_at' => '2025-11-12 20:00:00',
				)
			),
			'cup'           => $s->fixture(
				$cup,
				array(
					'home_entry_id' => $entry_home,
					'away_entry_id' => $entry_away,
					'scheduled_at'  => '2026-12-01 20:00:00',
				)
			),
			'entry_home'    => $entry_home,
		);
	}

	public function test_home_captain_enters_and_away_captain_confirms(): void {
		$match = $this->id['match'];

		self::assertTrue( $this->policy->can_enter_result( $this->id['captain_home'], $match ) );
		self::assertFalse( $this->policy->can_confirm_result( $this->id['captain_home'], $match ) );
		self::assertTrue( $this->policy->can_confirm_result( $this->id['captain_away'], $match ) );
		self::assertFalse( $this->policy->can_enter_result( $this->id['captain_away'], $match ) );

		self::assertSame( AccessPolicy::HOME, $this->policy->side_for( $this->id['captain_home'], $match ) );
		self::assertSame( AccessPolicy::AWAY, $this->policy->side_for( $this->id['captain_away'], $match ) );
	}

	public function test_tk_chef_acts_for_all_teams_of_the_club(): void {
		$match = $this->id['match'];

		self::assertTrue( $this->policy->can_enter_result( $this->id['chef_home'], $match ) );
		self::assertFalse( $this->policy->can_confirm_result( $this->id['chef_home'], $match ) );
		self::assertTrue( $this->policy->can_confirm_result( $this->id['chef_away'], $match ) );
		self::assertNull( $this->policy->side_for( $this->id['chef_other'], $match ) );
	}

	public function test_players_without_function_have_no_rights(): void {
		self::assertNull( $this->policy->side_for( $this->id['player'], $this->id['match'] ) );
		self::assertFalse( $this->policy->can_enter_result( $this->id['player'], $this->id['match'] ) );
	}

	public function test_club_derby_home_side_takes_precedence(): void {
		$derby = $this->id['derby'];

		self::assertSame( AccessPolicy::HOME, $this->policy->side_for( $this->id['chef_home'], $derby ) );
		self::assertTrue( $this->policy->can_confirm_result( $this->id['chef_home'], $derby ) );
		self::assertSame( AccessPolicy::AWAY, $this->policy->side_for( $this->id['captain_home2'], $derby ) );
	}

	public function test_archived_season_grants_nothing(): void {
		self::assertFalse( $this->policy->can_enter_result( $this->id['captain_home'], $this->id['archived'] ) );
		self::assertFalse( $this->policy->can_enter_result( $this->id['chef_home'], $this->id['archived'] ) );
	}

	public function test_cup_players_act_for_their_entry(): void {
		$cup = $this->id['cup'];

		self::assertTrue( $this->policy->can_enter_result( $this->id['cup_a1'], $cup ) );
		self::assertTrue( $this->policy->can_enter_result( $this->id['cup_a2'], $cup ) );
		self::assertTrue( $this->policy->can_confirm_result( $this->id['cup_b1'], $cup ) );
		self::assertFalse( $this->policy->can_confirm_result( $this->id['cup_a1'], $cup ) );
		self::assertTrue( $this->policy->can_enter_result( $this->id['chef_home'], $cup ), 'TK-Chef des Clubs der Cup-Mannschaft' );
		self::assertFalse( $this->policy->can_enter_result( $this->id['captain_home'], $cup ), 'Captain der Meisterschaft ist nicht Teil der Cup-Mannschaft' );
	}

	public function test_unknown_fixture_grants_nothing(): void {
		self::assertNull( $this->policy->fixture_context( 999999 ) );
		self::assertFalse( $this->policy->can_enter_result( $this->id['chef_home'], 999999 ) );
	}

	public function test_roles_of_a_person(): void {
		$season = (int) $this->policy->active_season_id();
		$roles  = $this->policy->roles( $this->id['cup_a1'], $season );

		self::assertSame( array(), $roles['captain_teams'] );
		self::assertSame( array(), $roles['tk_chef_clubs'] );
		self::assertSame( array( $this->id['entry_home'] ), $roles['cup_entries'] );
		self::assertSame( array( $this->id['club_home'] ), $this->policy->roles( $this->id['chef_home'], $season )['tk_chef_clubs'] );
	}

	public function test_tk_chef_manages_only_own_club(): void {
		self::assertTrue( $this->policy->can_manage_club( $this->id['chef_home'], $this->id['club_home'] ) );
		self::assertFalse( $this->policy->can_manage_club( $this->id['chef_home'], $this->id['club_away'] ) );
		self::assertFalse( $this->policy->can_manage_club( $this->id['captain_home'], $this->id['club_home'] ) );
	}

	public function test_tk_chef_invites_people_of_own_club_in_active_season(): void {
		self::assertTrue( $this->policy->can_invite( $this->id['chef_home'], $this->id['player'] ), 'gemeldeter Spieler' );
		self::assertTrue( $this->policy->can_invite( $this->id['chef_home'], $this->id['captain_home'] ), 'Captain ohne Lizenz' );
		self::assertFalse( $this->policy->can_invite( $this->id['chef_home'], $this->id['captain_away'] ), 'anderer Club' );
		self::assertFalse( $this->policy->can_invite( $this->id['chef_home'], $this->id['former'] ), 'nur in früherer Saison gemeldet' );
		self::assertFalse( $this->policy->can_invite( $this->id['captain_home'], $this->id['player'] ), 'Captains laden nicht ein' );
	}

	public function test_officials_of_the_active_season_view_contacts(): void {
		self::assertTrue( $this->policy->can_view_contacts( $this->id['captain_home'] ), 'Captain' );
		self::assertTrue( $this->policy->can_view_contacts( $this->id['chef_other'] ), 'TK-Chef eines Clubs ohne Team' );
		self::assertFalse( $this->policy->can_view_contacts( $this->id['player'] ), 'gemeldeter Spieler ohne Funktion' );
		self::assertFalse( $this->policy->can_view_contacts( $this->id['cup_a1'] ), 'Cup-Spieler ohne Funktion' );
		self::assertFalse( $this->policy->can_view_contacts( 0 ) );
	}

	public function test_contacts_follow_the_active_season_and_a_reset(): void {
		$s      = new Seeder( $this->db );
		$season = (int) $this->policy->active_season_id();
		$old    = (int) $this->db->get_var( "SELECT id FROM {$this->db->table( 'season' )} WHERE status = 'archived'" );
		$past   = $s->person( 'Vorbei', 'Vera' );
		$s->role( $old, $this->id['club_home'], $past, 'captain' );
		self::assertFalse( $this->policy->can_view_contacts( $past ), 'nur in der Vorsaison Captain' );

		// Captain ohne zugeordnetes Team: hat eine Funktion, aber kein Recht an einer Begegnung.
		$s->role( $season, $this->id['club_home'], $past, 'captain' );
		self::assertFalse( $this->policy->can_view_contacts( $past ), 'gemerktes Ergebnis bis zum Zurücksetzen' );
		$this->policy->reset();
		self::assertTrue( $this->policy->can_view_contacts( $past ) );

		$this->db->update( 'season', array( 'status' => 'archived' ), array( 'id' => $season ) );
		$this->policy->reset();
		self::assertFalse( $this->policy->can_view_contacts( $this->id['captain_home'] ), 'ohne aktive Saison' );
	}

	public function test_person_for_user(): void {
		$this->db->update( 'person', array( 'user_id' => 42 ), array( 'id' => $this->id['captain_home'] ) );

		self::assertSame( $this->id['captain_home'], $this->policy->person_for_user( 42 ) );
		self::assertNull( $this->policy->person_for_user( 43 ) );
		self::assertNull( $this->policy->person_for_user( 0 ) );
	}
}
