<?php
/**
 * Tests der Rechte im Saisonbetrieb: geplante Saison, Verschiebung, Gruppenzettel.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Tests\Integration\Access;

use TTLeague\Access\AccessPolicy;
use TTLeague\Tests\Integration\Frontend\Seeder;
use TTLeague\Tests\Integration\IntegrationTestCase;

/**
 * Erfundene Clubs Seefeld, Wollishofen und Oerlikon in der aktiven Saison 2026/27 und der
 * geplanten Saison 2027/28.
 */
final class SeasonAccessTest extends IntegrationTestCase {

	private AccessPolicy $policy;

	private Seeder $s;

	/** @var array<string,int> */
	private array $id = array();

	protected function setUp(): void {
		parent::setUp();
		$this->s      = new Seeder( $this->db );
		$s            = $this->s;
		$old          = $s->season( '2025-26', 'archived' );
		$now          = $s->season( '2026-27', 'active' );
		$home         = $s->club( 'Seefeld', 1 );
		$away         = $s->club( 'Wollishofen', 2 );
		$other        = $s->club( 'Oerlikon', 3 );
		$group        = $s->competition( $now, 'MM', 'Gruppe A', 'A', 1 );
		$t_home       = $s->team( $now, $home, 1, 'Seefeld 1', $group );
		$t_away       = $s->team( $now, $away, 1, 'Wollishofen 1', $group );
		$captain_home = $s->person( 'Heim', 'Hanna' );
		$captain_away = $s->person( 'Gast', 'Gustav' );
		$chef_home    = $s->person( 'Chef', 'Charlotte' );
		$chef_away    = $s->person( 'Leiter', 'Lukas' );
		$chef_other   = $s->person( 'Fremd', 'Fritz' );
		$chef_old     = $s->person( 'Alt', 'Albert' );
		$s->role( $now, $home, $captain_home, 'captain', $t_home );
		$s->role( $now, $away, $captain_away, 'captain', $t_away );
		$s->role( $now, $home, $chef_home, 'tk_chef' );
		$s->role( $now, $away, $chef_away, 'tk_chef' );
		$s->role( $now, $other, $chef_other, 'tk_chef' );
		$s->role( $old, $home, $chef_old, 'tk_chef' );

		$this->id     = compact( 'old', 'now', 'home', 'away', 'other', 'group', 't_home', 't_away', 'captain_home', 'captain_away', 'chef_home', 'chef_away', 'chef_other', 'chef_old' );
		$this->policy = new AccessPolicy( $this->db );
	}

	public function test_without_planned_season_only_the_active_season_counts(): void {
		self::assertNull( $this->policy->planned_season_id() );
		self::assertTrue( $this->policy->can_manage_club( $this->id['chef_home'], $this->id['home'] ) );
		self::assertFalse( $this->policy->can_manage_club( $this->id['chef_home'], $this->id['away'] ) );
		self::assertFalse( $this->policy->can_manage_club( $this->id['chef_old'], $this->id['home'] ), 'Funktion nur in der Vorsaison' );
	}

	public function test_tk_chef_of_the_planned_season_manages_the_club(): void {
		$next     = $this->s->season( '2027-28', 'planned' );
		$new_chef = $this->s->person( 'Neu', 'Nora' );
		$this->s->role( $next, $this->id['home'], $new_chef, 'tk_chef' );

		self::assertSame( $next, $this->policy->planned_season_id() );
		self::assertTrue( $this->policy->can_manage_club( $new_chef, $this->id['home'] ), 'TK-Chef erst ab der nächsten Saison' );
		self::assertFalse( $this->policy->can_manage_club( $new_chef, $this->id['away'] ) );
		self::assertTrue( $this->policy->can_manage_club( $this->id['chef_home'], $this->id['home'] ), 'bisheriger TK-Chef bleibt bis zur Freigabe' );
		self::assertSame( array( $this->id['home'] ), $this->policy->managed_clubs( $new_chef ) );
	}

	public function test_managed_clubs_come_from_both_seasons_without_duplicates(): void {
		$next = $this->s->season( '2027-28', 'planned' );
		$this->s->role( $next, $this->id['home'], $this->id['chef_home'], 'tk_chef' );
		$this->s->role( $next, $this->id['other'], $this->id['chef_home'], 'tk_chef' );

		$clubs = $this->policy->managed_clubs( $this->id['chef_home'] );
		sort( $clubs );
		self::assertSame( array( $this->id['home'], $this->id['other'] ), $clubs );
	}

	public function test_captain_of_the_planned_season_has_no_rights_on_its_fixtures(): void {
		$next    = $this->s->season( '2027-28', 'planned' );
		$group   = $this->s->competition( $next, 'MM', 'Gruppe A', 'A', 1 );
		$t_home  = $this->s->team( $next, $this->id['home'], 1, 'Seefeld 1', $group );
		$t_away  = $this->s->team( $next, $this->id['away'], 1, 'Wollishofen 1', $group );
		$captain = $this->s->person( 'Bald', 'Bea' );
		$this->s->role( $next, $this->id['home'], $captain, 'captain', $t_home );
		$fixture = $this->s->fixture(
			$group,
			array(
				'home_team_id' => $t_home,
				'away_team_id' => $t_away,
				'scheduled_at' => '2027-09-14 20:00:00',
			)
		);

		self::assertFalse( $this->policy->can_enter_result( $captain, $fixture ) );
		self::assertFalse( $this->policy->can_reschedule( $captain, $fixture ) );
		self::assertFalse( $this->policy->can_manage_club( $captain, $this->id['home'] ) );
	}

	public function test_both_sides_may_reschedule_but_nobody_else(): void {
		$fixture = $this->s->fixture(
			$this->id['group'],
			array(
				'home_team_id' => $this->id['t_home'],
				'away_team_id' => $this->id['t_away'],
				'scheduled_at' => '2026-11-17 20:00:00',
			)
		);

		self::assertTrue( $this->policy->can_reschedule( $this->id['captain_home'], $fixture ) );
		self::assertTrue( $this->policy->can_reschedule( $this->id['captain_away'], $fixture ), 'die Gastseite darf erfassen nicht, verschieben schon' );
		self::assertTrue( $this->policy->can_reschedule( $this->id['chef_away'], $fixture ) );
		self::assertFalse( $this->policy->can_reschedule( $this->id['chef_other'], $fixture ) );
		self::assertFalse( $this->policy->can_reschedule( $this->id['captain_home'], 999 ), 'unbekannte Begegnung' );
	}

	public function test_group_sheet_is_entered_by_chief_his_tk_chef_or_the_host_tk_chef(): void {
		$chief  = $this->s->player( 'Gruppenchef', 'Gian' );
		$member = $this->s->player( 'Mitspieler', 'Mia' );
		$this->s->register( $chief, $this->id['now'], $this->id['away'], 8 );
		$this->s->register( $member, $this->id['now'], $this->id['other'], 8 );
		$group = $this->rl_group( $this->id['now'], $this->id['home'], $chief );

		self::assertTrue( $this->policy->can_enter_rl_group( $this->s->person_of( $chief ), $group ), 'Gruppenchef' );
		self::assertTrue( $this->policy->can_enter_rl_group( $this->id['chef_away'], $group ), 'TK-Chef des Clubs des Gruppenchefs' );
		self::assertTrue( $this->policy->can_enter_rl_group( $this->id['chef_home'], $group ), 'TK-Chef des Platzclubs' );
		self::assertFalse( $this->policy->can_enter_rl_group( $this->id['chef_other'], $group ), 'TK-Chef eines Mitspielers' );
		self::assertFalse( $this->policy->can_enter_rl_group( $this->s->person_of( $member ), $group ), 'Mitspieler' );
		self::assertFalse( $this->policy->can_enter_rl_group( $this->id['captain_home'], $group ), 'Captain des Platzclubs' );
		self::assertFalse( $this->policy->can_enter_rl_group( $this->id['chef_home'], 999 ), 'unbekannte Gruppe' );
	}

	public function test_group_without_chief_or_host_is_entered_by_nobody_but_the_tk(): void {
		$group = $this->rl_group( $this->id['now'], null, null );

		self::assertFalse( $this->policy->can_enter_rl_group( $this->id['chef_home'], $group ) );
		self::assertFalse( $this->policy->can_enter_rl_group( $this->id['captain_home'], $group ) );
	}

	public function test_group_sheets_of_other_seasons_are_closed(): void {
		$chief = $this->s->player( 'Gruppenchef', 'Gian' );
		$this->s->register( $chief, $this->id['old'], $this->id['home'], 8 );
		$group = $this->rl_group( $this->id['old'], $this->id['home'], $chief );

		self::assertFalse( $this->policy->can_enter_rl_group( $this->s->person_of( $chief ), $group ) );
		self::assertFalse( $this->policy->can_enter_rl_group( $this->id['chef_home'], $group ) );
	}

	private function rl_group( int $season, ?int $host_club, ?int $chief ): int {
		$competition = $this->s->competition( $season, 'RL', 'Ranglistenturnier' );
		$round       = $this->db->insert(
			'rl_round',
			array(
				'competition_id' => $competition,
				'round_no'       => 2,
				'status'         => 'published',
			)
		);
		return $this->db->insert(
			'rl_group',
			array(
				'rl_round_id'     => $round,
				'category'        => 1,
				'group_no'        => 1,
				'host_club_id'    => $host_club,
				'chief_player_id' => $chief,
			)
		);
	}
}
