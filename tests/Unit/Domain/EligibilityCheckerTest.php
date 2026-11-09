<?php
declare(strict_types=1);

namespace TTLeague\Tests\Unit\Domain;

use PHPUnit\Framework\TestCase;
use TTLeague\Domain\Eligibility\EligibilityChecker;
use TTLeague\Domain\Eligibility\EligibilityReason;
use TTLeague\Domain\Eligibility\TeamInfo;
use TTLeague\Domain\Rules\SeasonRules;

final class EligibilityCheckerTest extends TestCase {

	private function checker( ?SeasonRules $rules = null ): EligibilityChecker {
		$c = new EligibilityChecker( $rules ?? new SeasonRules() );
		$c->add_team( new TeamInfo( 'T1', 'club', 1, 'A' ) )
			->add_team( new TeamInfo( 'T2', 'club', 2, 'B' ) )
			->add_team( new TeamInfo( 'T3', 'club', 3, 'B' ) )
			->add_team( new TeamInfo( 'T4', 'club', 4, 'D' ) )
			->add_team( new TeamInfo( 'T5', 'club', 5, 'D' ) )
			->add_team( new TeamInfo( 'O1', 'other', 1, 'A' ) );
		$c->add_player( 'p1', 'club', 'T1' )
			->add_player( 'p2', 'club', 'T2' )
			->add_player( 'p3', 'club', 'T3' )
			->add_player( 'p4', 'club', 'T4' )
			->add_player( 'p5', 'club', 'T5' )
			->add_player( 'free', 'club' )
			->add_player( 'ox', 'other', 'O1' );
		return $c;
	}

	public function test_stamm_player_in_own_team(): void {
		$e = $this->checker()->check( 'p1', 'T1' );
		self::assertTrue( $e->allowed );
		self::assertSame( EligibilityReason::Stamm, $e->reason );
	}

	public function test_stamm_player_cannot_play_lower_category(): void {
		$e = $this->checker()->check( 'p1', 'T2' );
		self::assertFalse( $e->allowed );
		self::assertSame( EligibilityReason::BlockedLowerTeam, $e->reason );
	}

	public function test_playing_up_is_allowed_as_substitute(): void {
		$e = $this->checker()->check( 'p2', 'T1' );
		self::assertTrue( $e->allowed );
		self::assertSame( EligibilityReason::Substitute, $e->reason );
		self::assertSame( 0, $e->substitute_appearances );
		self::assertSame( 3, $e->remaining );
	}

	public function test_same_category_only_lower_team_number(): void {
		self::assertTrue( $this->checker()->check( 'p3', 'T2' )->allowed );
		$e = $this->checker()->check( 'p2', 'T3' );
		self::assertFalse( $e->allowed );
		self::assertSame( EligibilityReason::BlockedTeamNumber, $e->reason );
	}

	public function test_category_d_ignores_team_number(): void {
		self::assertTrue( $this->checker()->check( 'p4', 'T5' )->allowed );
		self::assertTrue( $this->checker()->check( 'p5', 'T4' )->allowed );
	}

	public function test_substitute_only_in_one_team(): void {
		$c = $this->checker();
		$c->add_appearance( 'free', 'T3' );
		$e = $c->check( 'free', 'T2' );
		self::assertFalse( $e->allowed );
		self::assertSame( EligibilityReason::BlockedOtherTeamSubstitute, $e->reason );
		$again = $c->check( 'free', 'T3' );
		self::assertTrue( $again->allowed );
		self::assertSame( 1, $again->substitute_appearances );
		self::assertSame( 2, $again->remaining );
	}

	public function test_binding_after_max_appearances(): void {
		$c = $this->checker();
		$c->add_appearance( 'free', 'T3' )->add_appearance( 'free', 'T3' )->add_appearance( 'free', 'T3' );
		self::assertSame( 'T3', $c->bound_team( 'free' ) );
		self::assertSame( EligibilityReason::Bound, $c->check( 'free', 'T3' )->reason );
		self::assertSame( EligibilityReason::BlockedBoundElsewhere, $c->check( 'free', 'T1' )->reason );
	}

	public function test_bound_stamm_player_may_no_longer_play_own_team(): void {
		$c = $this->checker();
		$c->add_appearance( 'p2', 'T1' )->add_appearance( 'p2', 'T1' )->add_appearance( 'p2', 'T1' );
		self::assertSame( EligibilityReason::BlockedBoundElsewhere, $c->check( 'p2', 'T2' )->reason );
		self::assertTrue( $c->check( 'p2', 'T1' )->allowed );
	}

	public function test_appearances_in_own_team_do_not_count(): void {
		$c = $this->checker();
		$c->add_appearance( 'p2', 'T2' )->add_appearance( 'p2', 'T2' )->add_appearance( 'p2', 'T2' )->add_appearance( 'p2', 'T2' );
		self::assertNull( $c->bound_team( 'p2' ) );
		self::assertSame( EligibilityReason::Stamm, $c->check( 'p2', 'T2' )->reason );
	}

	public function test_old_rule_with_two_appearances(): void {
		$c = $this->checker( new SeasonRules( substitute_max: 2 ) );
		$c->add_appearance( 'free', 'T3' )->add_appearance( 'free', 'T3' );
		self::assertSame( 'T3', $c->bound_team( 'free' ) );
	}

	public function test_other_club_is_blocked(): void {
		self::assertSame( EligibilityReason::BlockedOtherClub, $this->checker()->check( 'ox', 'T1' )->reason );
		self::assertSame( EligibilityReason::BlockedOtherClub, $this->checker()->check( 'unknown', 'T1' )->reason );
	}

	public function test_explicit_binding_from_roster(): void {
		$c = $this->checker();
		$c->bind( 'p4', 'T2' );

		self::assertSame( 'T2', $c->bound_team( 'p4' ) );
		self::assertSame( EligibilityReason::Bound, $c->check( 'p4', 'T2' )->reason );
		self::assertFalse( $c->check( 'p4', 'T4' )->allowed, 'Stammteam ist nach der Bindung gesperrt' );
	}
}
