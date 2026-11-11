<?php
/**
 * Regeln der Verschiebung: Übergänge, Rollen, Bewilligung an den Grenzen.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Tests\Unit\Domain;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TTLeague\Domain\Match\FixtureWorkflow;
use TTLeague\Domain\Match\PostponementRules as Rules;

/**
 * Bisheriger Termin ist Dienstag, 10.11.2026, 20:00; die Grenze liegt bei 14 Kalendertagen.
 */
final class PostponementRulesTest extends TestCase {

	private const OLD = '2026-11-10 20:00:00';

	/**
	 * @return array<string,array{0:string,1:bool}>
	 */
	public static function postponed_dates(): array {
		return array(
			'genau 14 Tage, gleiche Zeit'     => array( '2026-11-24 20:00:00', false ),
			'genau 14 Tage, später am Abend'  => array( '2026-11-24 21:30:00', false ),
			'genau 14 Tage, früher am Tag'    => array( '2026-11-24 18:00:00', false ),
			'15 Tage, früher am Tag'          => array( '2026-11-25 18:00:00', true ),
			'15 Tage, kurz nach Mitternacht'  => array( '2026-11-25 00:00:00', true ),
			'13 Tage'                         => array( '2026-11-23 20:00:00', false ),
			'am selben Tag später'            => array( '2026-11-10 21:00:00', false ),
			'vorverschoben um einen Tag'      => array( '2026-11-09 20:00:00', false ),
			'vorverschoben um mehrere Wochen' => array( '2026-10-01 20:00:00', false ),
			'15 Tage, gleiche Zeit'           => array( '2026-11-25 20:00:00', true ),
			'Monate später'                   => array( '2027-03-29 20:00:00', true ),
		);
	}

	#[DataProvider( 'postponed_dates' )]
	public function test_approval_is_needed_more_than_fourteen_days_after_the_old_date( string $new_date, bool $expected ): void {
		self::assertSame( $expected, Rules::needs_approval( Rules::POSTPONE, self::OLD, $new_date, 14 ) );
	}

	public function test_the_limit_is_a_parameter_of_the_season(): void {
		self::assertFalse( Rules::needs_approval( Rules::POSTPONE, self::OLD, '2026-11-17 20:00:00', 7 ), 'genau 7 Tage' );
		self::assertTrue( Rules::needs_approval( Rules::POSTPONE, self::OLD, '2026-11-18 20:00:00', 7 ), '8 Tage' );
		self::assertTrue( Rules::needs_approval( Rules::POSTPONE, self::OLD, '2026-11-11 20:00:00', 0 ), 'bei 0 braucht jedes Nachverschieben die Bewilligung' );
		self::assertFalse( Rules::needs_approval( Rules::POSTPONE, self::OLD, '2026-11-09 20:00:00', 0 ), 'Vorverschieben nie' );
	}

	public function test_days_between_counts_calendar_days_across_the_clock_change(): void {
		self::assertSame( 14, Rules::days_between( '2026-11-10', '2026-11-24' ) );
		self::assertSame( -1, Rules::days_between( '2026-11-10', '2026-11-09' ) );
		// Zeitumstellung am 28.3.2027: Der Tag hat 23 Stunden und zählt trotzdem als einer.
		self::assertSame( 14, Rules::days_between( '2027-03-20', '2027-04-03' ) );
		self::assertSame( 15, Rules::days_between( '2027-03-20 20:00:00', '2027-04-04 08:00:00' ) );
	}

	public function test_a_proposal_needs_approval_only_after_the_deadline_of_the_round(): void {
		self::assertFalse( Rules::needs_approval( Rules::PROPOSE, null, '2026-11-29 20:00:00', 14, '2026-11-30' ), 'vor der Frist' );
		self::assertFalse( Rules::needs_approval( Rules::PROPOSE, null, '2026-11-30 23:30:00', 14, '2026-11-30' ), 'am Tag der Frist, spät am Abend' );
		self::assertTrue( Rules::needs_approval( Rules::PROPOSE, null, '2026-12-01 00:00:00', 14, '2026-11-30' ), 'am Tag nach der Frist' );
		self::assertTrue( Rules::needs_approval( Rules::PROPOSE, null, '2026-12-01 20:00:00', 14, '2026-11-30' ) );
		self::assertFalse( Rules::needs_approval( Rules::PROPOSE, null, '2027-05-01 20:00:00', 14, null ), 'ohne Frist genügt die Zustimmung' );
		self::assertFalse( Rules::needs_approval( Rules::PROPOSE, null, '2027-05-01 20:00:00', 14 ) );
	}

	public function test_postponing_a_cup_fixture_applies_both_rules(): void {
		// Bisher 10.11., Frist der Runde 20.11.
		self::assertFalse( Rules::needs_approval( Rules::POSTPONE, self::OLD, '2026-11-20 20:00:00', 14, '2026-11-20' ), 'am Tag der Frist, 10 Tage später' );
		self::assertTrue( Rules::needs_approval( Rules::POSTPONE, self::OLD, '2026-11-21 20:00:00', 14, '2026-11-20' ), 'nach der Frist, obwohl nur 11 Tage später' );
		self::assertTrue( Rules::needs_approval( Rules::POSTPONE, self::OLD, '2026-11-25 20:00:00', 14, '2026-12-31' ), 'vor der Frist, aber 15 Tage später' );
		self::assertFalse( Rules::needs_approval( Rules::POSTPONE, self::OLD, '2026-11-24 20:00:00', 14, '2026-12-31' ), 'vor der Frist und genau 14 Tage' );
		self::assertFalse( Rules::needs_approval( Rules::POSTPONE, '2026-12-10 20:00:00', '2026-12-05 20:00:00', 14, '2026-11-20' ), 'Vorverschieben nie, auch nach der Frist' );
	}

	public function test_a_request_of_the_tk_is_approved_at_once(): void {
		self::assertSame( Rules::APPROVED, Rules::initial_status( true ) );
		self::assertSame( Rules::REQUESTED, Rules::initial_status( false ) );
	}

	public function test_kind_follows_from_the_current_date(): void {
		self::assertSame( Rules::PROPOSE, Rules::kind( null ) );
		self::assertSame( Rules::PROPOSE, Rules::kind( '' ) );
		self::assertSame( Rules::POSTPONE, Rules::kind( self::OLD ) );
	}

	public function test_the_fixture_is_postponed_after_a_postponement_and_stays_scheduled_after_a_proposal(): void {
		self::assertSame( FixtureWorkflow::POSTPONED, Rules::fixture_status( Rules::POSTPONE ) );
		self::assertSame( FixtureWorkflow::SCHEDULED, Rules::fixture_status( Rules::PROPOSE ) );
	}

	public function test_only_open_fixtures_can_be_rescheduled(): void {
		self::assertTrue( Rules::fixture_open( FixtureWorkflow::SCHEDULED ) );
		self::assertTrue( Rules::fixture_open( FixtureWorkflow::POSTPONED ) );
		foreach ( array( FixtureWorkflow::PROVISIONAL, FixtureWorkflow::CONFIRMED, FixtureWorkflow::DISPUTED, FixtureWorkflow::FINAL, FixtureWorkflow::FORFEIT, FixtureWorkflow::CANCELLED ) as $status ) {
			self::assertFalse( Rules::fixture_open( $status ), $status );
		}
	}

	public function test_who_may_request(): void {
		self::assertTrue( Rules::may_request( Rules::POSTPONE, array( Rules::HOME ), false ) );
		self::assertTrue( Rules::may_request( Rules::POSTPONE, array( Rules::AWAY ), false ) );
		self::assertFalse( Rules::may_request( Rules::POSTPONE, array(), false ) );
		self::assertTrue( Rules::may_request( Rules::POSTPONE, array(), true ), 'TK' );

		self::assertTrue( Rules::may_request( Rules::PROPOSE, array( Rules::HOME ), false ), 'die Heimseite schlägt vor' );
		self::assertFalse( Rules::may_request( Rules::PROPOSE, array( Rules::AWAY ), false ) );
		self::assertTrue( Rules::may_request( Rules::PROPOSE, array( Rules::HOME, Rules::AWAY ), false ) );
		self::assertTrue( Rules::may_request( Rules::PROPOSE, array(), true ), 'TK' );
	}

	/**
	 * Tabelle aus docs/verschiebung.md: Aktion, Status, Rolle => erlaubt.
	 */
	public function test_transitions_per_role(): void {
		$allowed  = array(
			Rules::WITHDRAW => array( Rules::REQUESTED => array( Rules::ROLE_REQUESTER ) ),
			Rules::DECLINE  => array( Rules::REQUESTED => array( Rules::ROLE_OPPONENT ) ),
			Rules::ACCEPT   => array( Rules::REQUESTED => array( Rules::ROLE_OPPONENT ) ),
			Rules::APPROVE  => array(
				Rules::REQUESTED => array( Rules::ROLE_TK ),
				Rules::ACCEPTED  => array( Rules::ROLE_TK ),
			),
			Rules::REJECT   => array(
				Rules::REQUESTED => array( Rules::ROLE_TK ),
				Rules::ACCEPTED  => array( Rules::ROLE_TK ),
			),
		);
		$statuses = array( Rules::REQUESTED, Rules::ACCEPTED, Rules::APPROVED, Rules::DECLINED, Rules::WITHDRAWN, Rules::REJECTED );
		$roles    = array( Rules::ROLE_REQUESTER, Rules::ROLE_OPPONENT, Rules::ROLE_TK );
		self::assertSame( array_keys( $allowed ), Rules::actions() );
		foreach ( $allowed as $action => $by_status ) {
			foreach ( $statuses as $status ) {
				foreach ( $roles as $role ) {
					$expected = in_array( $role, $by_status[ $status ] ?? array(), true );
					self::assertSame( $expected, Rules::allows( $action, $status, array( $role ) ), $action . ' / ' . $status . ' / ' . $role );
				}
			}
		}
		self::assertFalse( Rules::allows( 'unbekannt', Rules::REQUESTED, $roles ) );
		self::assertFalse( Rules::allows( Rules::ACCEPT, Rules::REQUESTED, array() ) );
		self::assertTrue( Rules::role_may( Rules::ACCEPT, array( Rules::ROLE_REQUESTER, Rules::ROLE_OPPONENT ) ) );
		self::assertFalse( Rules::role_may( Rules::ACCEPT, array( Rules::ROLE_REQUESTER, Rules::ROLE_TK ) ) );
	}

	public function test_targets(): void {
		self::assertSame( Rules::WITHDRAWN, Rules::target( Rules::WITHDRAW ) );
		self::assertSame( Rules::DECLINED, Rules::target( Rules::DECLINE ) );
		self::assertSame( Rules::APPROVED, Rules::target( Rules::ACCEPT, false ), 'die Zustimmung genügt' );
		self::assertSame( Rules::ACCEPTED, Rules::target( Rules::ACCEPT, true ), 'die TK muss bewilligen' );
		// Der Entscheid der TK schliesst die Bewilligung ein: nie «accepted».
		self::assertSame( Rules::APPROVED, Rules::target( Rules::APPROVE, true ) );
		self::assertSame( Rules::REJECTED, Rules::target( Rules::REJECT, true ) );

		$this->expectException( \InvalidArgumentException::class );
		Rules::target( 'unbekannt' );
	}

	public function test_roles_of_both_sides(): void {
		$home = array( Rules::HOME );
		$away = array( Rules::AWAY );

		self::assertSame( array( Rules::ROLE_REQUESTER ), Rules::roles( $home, $home, true, false ), 'wer beantragt hat' );
		self::assertSame( array( Rules::ROLE_REQUESTER ), Rules::roles( $home, $home, false, false ), 'zweiter Captain derselben Seite' );
		self::assertSame( array( Rules::ROLE_OPPONENT ), Rules::roles( $home, $away, false, false ) );
		self::assertSame( array( Rules::ROLE_OPPONENT ), Rules::roles( $away, $home, false, false ) );
		self::assertSame( array(), Rules::roles( $home, array(), false, false ), 'ohne Seite keine Rolle' );
		self::assertSame( array( Rules::ROLE_TK ), Rules::roles( $home, array(), false, true ) );
		self::assertSame( array( Rules::ROLE_OPPONENT, Rules::ROLE_TK ), Rules::roles( $home, $away, false, true ) );
		self::assertSame( array( Rules::ROLE_REQUESTER ), Rules::roles( array(), $away, true, false ), 'Antragsteller ohne Funktion zieht noch zurück' );
		self::assertSame( array(), Rules::roles( array(), $away, false, false ), 'ohne Seite des Antrags antwortet niemand' );
	}

	public function test_in_a_club_derby_the_requester_never_answers_the_own_request(): void {
		$both = array( Rules::HOME, Rules::AWAY );

		// Der TK-Chef vertritt beide Seiten und hat selbst beantragt.
		self::assertSame( array( Rules::ROLE_REQUESTER ), Rules::roles( $both, $both, true, false ) );
		// Ein Captain hat beantragt; der TK-Chef (beide Seiten) kann zurückziehen und antworten.
		self::assertSame( array( Rules::ROLE_REQUESTER, Rules::ROLE_OPPONENT ), Rules::roles( array( Rules::HOME ), $both, false, false ) );
		// Der TK-Chef hat beantragt; jeder Captain kann antworten.
		self::assertContains( Rules::ROLE_OPPONENT, Rules::roles( $both, array( Rules::HOME ), false, false ) );
		self::assertContains( Rules::ROLE_OPPONENT, Rules::roles( $both, array( Rules::AWAY ), false, false ) );
		// Der andere Captain antwortet, der eigene nicht.
		self::assertSame( array( Rules::ROLE_OPPONENT ), Rules::roles( array( Rules::HOME ), array( Rules::AWAY ), false, false ) );
		self::assertSame( array( Rules::ROLE_REQUESTER ), Rules::roles( array( Rules::HOME ), array( Rules::HOME ), false, false ) );
	}
}
