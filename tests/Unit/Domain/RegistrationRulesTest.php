<?php
/**
 * Tests der Regeln der Online-Meldung.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Tests\Unit\Domain;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TTLeague\Domain\Registration\RegistrationRules as Rules;

final class RegistrationRulesTest extends TestCase {

	/**
	 * Vollständige Mannschaft; einzelne Tests ändern ein Feld.
	 *
	 * @param list<int>           $players Stammspieler.
	 * @param array<string,mixed> $change  Abweichende Felder.
	 * @return array<string,mixed>
	 */
	private static function team( int $id, array $players, array $change = array() ): array {
		return $change + array(
			'id'            => $id,
			'name'          => 'Seefeld ' . $id,
			'players'       => $players,
			'home_venue_id' => 5,
			'start_time'    => '20:00:00',
			'captain'       => array(
				'name'  => 'Hanna Heim',
				'email' => 'hanna.heim@example.invalid',
			),
		);
	}

	/**
	 * @param list<array{code:string,field:string,message:string}> $violations Verstösse.
	 * @return list<string>
	 */
	private static function codes( array $violations ): array {
		return array_column( $violations, 'code' );
	}

	public function test_complete_teams_have_no_violation(): void {
		$teams = array( self::team( 1, array( 11, 12, 13 ) ), self::team( 2, array( 14, 15, 16 ) ) );

		self::assertSame( array(), Rules::teams( $teams, array( 11, 12, 13, 14, 15, 16, 17 ) ) );
	}

	/**
	 * @return iterable<string,array{list<int>,bool}>
	 */
	public static function stamm_counts(): iterable {
		yield 'zwei sind zu wenig' => array( array( 11, 12 ), false );
		yield 'genau drei' => array( array( 11, 12, 13 ), true );
		yield 'vier sind zu viel' => array( array( 11, 12, 13, 14 ), false );
		yield 'keiner' => array( array(), false );
		yield 'derselbe dreimal zählt einmal' => array( array( 11, 11, 11 ), false );
	}

	/**
	 * @param list<int> $players Stammspieler.
	 */
	#[DataProvider( 'stamm_counts' )]
	public function test_a_team_needs_exactly_three_regular_players( array $players, bool $valid ): void {
		$violations = Rules::teams( array( self::team( 1, $players ) ), array( 11, 12, 13, 14 ) );

		self::assertSame( $valid ? array() : array( 'stamm_count' ), self::codes( $violations ) );
		if ( ! $valid ) {
			self::assertSame( 'team.1.players', $violations[0]['field'] );
		}
	}

	public function test_the_number_of_regular_players_is_a_parameter(): void {
		self::assertSame( array(), Rules::teams( array( self::team( 1, array( 11, 12 ) ) ), array( 11, 12 ), array(), 2 ) );
		self::assertSame( array( 'stamm_count' ), self::codes( Rules::teams( array( self::team( 1, array( 11, 12, 13 ) ) ), array( 11, 12, 13 ), array(), 2 ) ) );
	}

	public function test_nobody_is_a_regular_player_of_two_teams(): void {
		$teams = array( self::team( 1, array( 11, 12, 13 ) ), self::team( 2, array( 13, 14, 15 ) ) );

		$violations = Rules::teams( $teams, array( 11, 12, 13, 14, 15 ), array( 13 => 'Paul Probe' ) );

		self::assertSame( array( 'stamm_duplicate' ), self::codes( $violations ) );
		self::assertSame( 'team.2.players', $violations[0]['field'] );
		self::assertStringContainsString( 'Paul Probe ist Stammspieler in Seefeld 1 und Seefeld 2', $violations[0]['message'] );
	}

	public function test_regular_players_must_be_registered_for_the_club(): void {
		$violations = Rules::teams( array( self::team( 1, array( 11, 12, 13 ) ) ), array( 11, 12 ), array( 13 => 'Paul Probe' ) );

		self::assertSame( array( 'stamm_not_registered' ), self::codes( $violations ) );
		self::assertStringContainsString( 'Paul Probe ist für den Club nicht gemeldet', $violations[0]['message'] );
	}

	public function test_a_team_needs_venue_start_time_and_a_captain_with_email(): void {
		$players = array( 11, 12, 13 );

		self::assertSame( array( 'venue_missing' ), self::codes( Rules::teams( array( self::team( 1, $players, array( 'home_venue_id' => null ) ) ), $players ) ) );
		self::assertSame( array( 'start_missing' ), self::codes( Rules::teams( array( self::team( 1, $players, array( 'start_time' => null ) ) ), $players ) ) );
		self::assertSame( array( 'captain_missing' ), self::codes( Rules::teams( array( self::team( 1, $players, array( 'captain' => null ) ) ), $players ) ) );
		$without_email = array(
			'name'  => 'Hanna Heim',
			'email' => null,
		);
		$violations    = Rules::teams( array( self::team( 1, $players, array( 'captain' => $without_email ) ) ), $players );
		self::assertSame( array( 'captain_email' ), self::codes( $violations ) );
		self::assertSame( 'team.1.captain', $violations[0]['field'] );
	}

	public function test_a_player_is_registered_for_one_club_per_season(): void {
		self::assertFalse( Rules::other_club( 1, null ), 'noch nicht gemeldet' );
		self::assertFalse( Rules::other_club( 1, 1 ), 'schon für denselben Club gemeldet' );
		self::assertTrue( Rules::other_club( 1, 2 ), 'für einen anderen Club gemeldet' );
	}

	/**
	 * @return iterable<string,array{string,string|null,string|null,bool}>
	 */
	public static function windows(): iterable {
		yield 'Tag vor Beginn' => array( '2027-04-30', '2027-05-01', '2027-05-31', false );
		yield 'erster Tag' => array( '2027-05-01', '2027-05-01', '2027-05-31', true );
		yield 'letzter Tag' => array( '2027-05-31', '2027-05-01', '2027-05-31', true );
		yield 'Tag nach Ablauf' => array( '2027-06-01', '2027-05-01', '2027-05-31', false );
		yield 'ohne Beginn kein Fenster' => array( '2027-05-15', null, '2027-05-31', false );
		yield 'ohne Ende offen' => array( '2027-09-01', '2027-05-01', null, true );
		yield 'ungültiges Ende' => array( '2027-05-15', '2027-05-01', 'bald', false );
	}

	#[DataProvider( 'windows' )]
	public function test_registration_window( string $today, ?string $opens, ?string $closes, bool $open ): void {
		self::assertSame( $open, Rules::window_open( $today, $opens, $closes ) );
	}

	public function test_late_registration_ends_with_the_day_of_the_season_or_on_january_31st(): void {
		self::assertSame( '2027-02-15', Rules::late_registration_deadline( '2027-02-15', '2027-07-31' ) );
		self::assertSame( '2027-01-31', Rules::late_registration_deadline( null, '2027-07-31' ), 'Standard Art. 27.3' );

		self::assertTrue( Rules::late_registration_open( '2027-01-30', '2027-01-31' ) );
		self::assertTrue( Rules::late_registration_open( '2027-01-31', '2027-01-31' ), 'der letzte Tag zählt' );
		self::assertFalse( Rules::late_registration_open( '2027-02-01', '2027-01-31' ), 'am Tag danach nur noch die TK' );
	}

	public function test_venue_needs_name_weekday_and_tables(): void {
		$venue = array(
			'name'         => 'Turnhalle Seefeld',
			'weekday'      => 2,
			'tables_count' => 4,
			'open_time'    => '19:30',
			'close_time'   => '22:00',
		);

		self::assertSame( array(), Rules::venue( $venue ) );
		self::assertSame( array( 'venue_name' ), self::codes( Rules::venue( array( 'name' => '  ' ) + $venue ) ) );
		self::assertSame( array(), Rules::venue( array( 'weekday' => 1 ) + $venue ), 'Montag' );
		self::assertSame( array(), Rules::venue( array( 'weekday' => 5 ) + $venue ), 'Freitag' );
		self::assertSame( array( 'venue_weekday' ), self::codes( Rules::venue( array( 'weekday' => 6 ) + $venue ) ), 'Samstag' );
		self::assertSame( array( 'venue_weekday' ), self::codes( Rules::venue( array( 'weekday' => 0 ) + $venue ) ) );
		self::assertSame( array( 'venue_weekday' ), self::codes( Rules::venue( array( 'weekday' => null ) + $venue ) ) );
		self::assertSame( array(), Rules::venue( array( 'tables_count' => 1 ) + $venue ), 'ein Tisch genügt' );
		self::assertSame( array( 'venue_tables' ), self::codes( Rules::venue( array( 'tables_count' => 0 ) + $venue ) ) );
		self::assertSame( array( 'venue_open' ), self::codes( Rules::venue( array( 'open_time' => '25:00' ) + $venue ) ) );
		self::assertSame( array( 'venue_close' ), self::codes( Rules::venue( array( 'close_time' => '19:30' ) + $venue ) ), 'Schluss nicht nach Öffnung' );
		self::assertSame(
			array(),
			Rules::venue(
				array(
					'open_time'  => null,
					'close_time' => null,
				) + $venue
			),
			'Zeiten sind freiwillig'
		);
	}

	public function test_closure_must_not_end_before_it_starts(): void {
		self::assertSame( array(), Rules::closure( '2027-10-04', '2027-10-15' ) );
		self::assertSame( array(), Rules::closure( '2027-10-04', '2027-10-04' ), 'ein einzelner Tag' );
		self::assertSame( array( 'closure_order' ), self::codes( Rules::closure( '2027-10-04', '2027-10-03' ) ) );
		self::assertSame( array( 'closure_from' ), self::codes( Rules::closure( '2027-02-30', '2027-03-01' ) ) );
		self::assertSame( array( 'closure_to' ), self::codes( Rules::closure( '2027-10-04', '' ) ) );
	}

	public function test_request_for_a_new_player(): void {
		$request = array(
			'last_name'      => 'Neuling',
			'first_name'     => 'Nora',
			'email'          => '',
			'classification' => 3,
			'stt_license'    => '',
		);

		self::assertSame( array(), Rules::request( $request ) );
		self::assertSame( array( 'request_last_name' ), self::codes( Rules::request( array( 'last_name' => '' ) + $request ) ) );
		self::assertSame( array( 'request_first_name' ), self::codes( Rules::request( array( 'first_name' => ' ' ) + $request ) ) );
		self::assertSame( array( 'request_email' ), self::codes( Rules::request( array( 'email' => 'nora@' ) + $request ) ) );
		self::assertSame( array(), Rules::request( array( 'classification' => 1 ) + $request ) );
		self::assertSame( array(), Rules::request( array( 'classification' => 22 ) + $request ) );
		self::assertSame( array( 'request_classification' ), self::codes( Rules::request( array( 'classification' => 0 ) + $request ) ) );
		self::assertSame( array( 'request_classification' ), self::codes( Rules::request( array( 'classification' => 23 ) + $request ) ) );
		self::assertSame( array( 'request_classification' ), self::codes( Rules::request( array( 'classification' => null ) + $request ) ) );
		self::assertSame( array( 'request_stt_license' ), self::codes( Rules::request( array( 'stt_license' => str_repeat( '1', 21 ) ) + $request ) ) );
	}

	public function test_submitting_needs_an_open_registration_without_violation(): void {
		$violation = array(
			array(
				'code'    => 'stamm_count',
				'field'   => 'team.1.players',
				'message' => 'Es braucht genau 3 Stammspieler.',
			),
		);

		self::assertTrue( Rules::may_submit( Rules::OPEN, array() ) );
		self::assertFalse( Rules::may_submit( Rules::OPEN, $violation ), 'mit Verstoss' );
		self::assertFalse( Rules::may_submit( Rules::SUBMITTED, array() ), 'schon eingereicht' );
		self::assertFalse( Rules::may_submit( Rules::REVIEWED, array() ), 'schon geprüft' );
	}

	/**
	 * @return iterable<string,array{string,int|null}>
	 */
	public static function reminder_days(): iterable {
		yield 'acht Tage vorher noch nichts' => array( '2027-05-23', null );
		yield 'sieben Tage vorher' => array( '2027-05-24', 7 );
		yield 'drei Tage vorher gilt noch die erste Stufe' => array( '2027-05-28', 7 );
		yield 'zwei Tage vorher' => array( '2027-05-29', 2 );
		yield 'am letzten Tag' => array( '2027-05-31', 2 );
		yield 'nach Ablauf nichts mehr' => array( '2027-06-01', null );
	}

	#[DataProvider( 'reminder_days' )]
	public function test_reminder_step_before_the_window_closes( string $today, ?int $step ): void {
		self::assertSame( $step, Rules::reminder_step( $today, '2027-05-01', '2027-05-31' ) );
	}

	public function test_no_reminder_without_an_open_window_or_closing_day(): void {
		self::assertNull( Rules::reminder_step( '2027-05-30', '2027-06-01', '2027-06-02' ), 'Fenster noch nicht offen' );
		self::assertNull( Rules::reminder_step( '2027-05-30', '2027-05-01', null ), 'ohne Ende' );
		self::assertSame( 10, Rules::reminder_step( '2027-05-25', '2027-05-01', '2027-05-31', array( 10, 3 ) ), 'Stufen sind Parameter' );
	}
}
