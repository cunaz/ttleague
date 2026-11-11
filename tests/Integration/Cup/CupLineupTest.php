<?php
/**
 * Aufstellungsregel des Cups bei der Erfassung (Art. 20.1 und 20.2) über den ResultService.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Tests\Integration\Cup;

/**
 * Cup B ohne Punktelimite und ohne Tableau: Seefeld 1 (Spieler 1 und 2, Klassierungen 14 und 6,
 * Punktesumme 20) gegen Oerlikon 1, Seefeld 2 (Spieler 3 und 4) gegen Oerlikon 2. Dazu zwei
 * weitere für Seefeld gemeldete Spieler ohne Cupmannschaft: «ersatz» (Klassierung 3) und
 * «zweiter» (Klassierung 2).
 */
final class CupLineupTest extends CupTestCase {

	private int $first;

	private int $second;

	private int $substitute;

	private int $another;

	protected function setUp(): void {
		parent::setUp();
		$this->format( $this->cup_b, array() );
		$seefeld_1 = $this->team( 'seefeld', 1, 2, $this->cup_b )['id'];
		$seefeld_2 = $this->team( 'seefeld', 3, 4, $this->cup_b )['id'];
		$oerlikon1 = $this->team( 'oerlikon', 1, 2, $this->cup_b )['id'];
		$oerlikon2 = $this->team( 'oerlikon', 3, 4, $this->cup_b )['id'];

		$this->substitute = $this->seeder->player( 'Cupspieler', 'Seefeld5' );
		$this->another    = $this->seeder->player( 'Cupspieler', 'Seefeld6' );
		$this->seeder->register( $this->substitute, $this->season, $this->club['seefeld'], 3 );
		$this->seeder->register( $this->another, $this->season, $this->club['seefeld'], 2 );

		$this->first  = $this->seeder->fixture(
			$this->cup_b,
			array(
				'home_entry_id' => $seefeld_1,
				'away_entry_id' => $oerlikon1,
				'scheduled_at'  => '2026-11-02 08:00:00',
			)
		);
		$this->second = $this->seeder->fixture(
			$this->cup_b,
			array(
				'home_entry_id' => $seefeld_2,
				'away_entry_id' => $oerlikon2,
				'scheduled_at'  => '2026-11-02 08:00:00',
			)
		);
	}

	/**
	 * Erfassung mit der angegebenen Heimaufstellung (Spieler-IDs für A und B).
	 *
	 * @return array<string,mixed>
	 */
	private function with_home( int $fixture, int $a, int $b ): array {
		return $this->payload(
			$fixture,
			'home',
			array(
				'home' => array(
					'A' => $a,
					'B' => $b,
				),
			)
		);
	}

	private function classify( int $player_id, int $classification ): void {
		$this->db->update(
			'player_season',
			array( 'classification' => $classification ),
			array(
				'player_id' => $player_id,
				'season_id' => $this->season,
			)
		);
	}

	public function test_the_registered_pair_and_a_free_substitute_are_accepted(): void {
		$result = $this->results->submit( $this->first, $this->payload( $this->first, 'home' ), $this->tk );
		self::assertSame( array( 'provisional', array() ), array( $result['status'], $result['warnings'] ) );

		$edited = $this->results->submit( $this->first, $this->with_home( $this->first, $this->player['seefeld'][1], $this->substitute ), $this->tk );
		self::assertSame( array( 'Seefeld5 Cupspieler ist nicht für diese Cup-Mannschaft gemeldet und spielt als Ersatz.' ), $edited['warnings'], '14 + 3 liegt unter der Summe der Meldung' );
	}

	public function test_at_least_one_original_player_must_play(): void {
		$error = self::expect_error( fn() => $this->results->submit( $this->first, $this->with_home( $this->first, $this->substitute, $this->another ), $this->tk ), 422 );

		self::assertSame( array( 'lineup.home' ), array_column( $error->errors, 'field' ) );
		self::assertSame( 'Seefeld 1: Mindestens ein Spieler der ursprünglichen Meldung muss spielen (Art. 20.1).', $error->getMessage() );
		self::assertSame( 'scheduled', $this->fixture_row( $this->first )['status'] );
	}

	public function test_the_sum_of_the_fielded_players_is_limited_by_the_entry(): void {
		// Spieler 2 (Klassierung 6) mit einem Ersatz: 6 + 14 = 20 ist genau die Summe der Meldung.
		$this->classify( $this->substitute, 14 );
		$lineup = $this->with_home( $this->first, $this->player['seefeld'][2], $this->substitute );
		self::assertSame( 'provisional', $this->results->submit( $this->first, $lineup, $this->tk )['status'] );

		// Ein Punkt mehr ist zu viel.
		$this->classify( $this->substitute, 15 );
		$error = self::expect_error( fn() => $this->results->submit( $this->first, $lineup, $this->tk ), 422 );
		self::assertSame( array( 'lineup.home' ), array_column( $error->errors, 'field' ) );
		self::assertSame( 'Seefeld 1: Die Klassierungen der eingesetzten Spieler ergeben 21, die Meldung erlaubt höchstens 20 (Art. 20.1).', $error->getMessage() );

		// Die gemeldete Mannschaft selbst darf immer spielen, auch nach einer höheren Klassierung.
		$this->classify( $this->player['seefeld'][1], 16 );
		self::assertSame( array(), $this->results->submit( $this->first, $this->payload( $this->first, 'home' ), $this->tk )['warnings'] );
	}

	public function test_a_substitute_registered_in_another_cup_team_is_blocked(): void {
		$error = self::expect_error( fn() => $this->results->submit( $this->first, $this->with_home( $this->first, $this->player['seefeld'][1], $this->player['seefeld'][3] ), $this->tk ), 422 );

		self::assertSame( array( 'lineup.home.B' ), array_column( $error->errors, 'field' ) );
		self::assertSame( 'Seefeld3 Cupspieler: In der Cupmannschaft Seefeld 2 (Mannschaftscup B) gemeldet oder eingesetzt; ein Spieler spielt in der Saison nur für eine Cupmannschaft (Art. 20.2).', $error->getMessage() );

		// Auch eine Meldung in der anderen Kategorie bindet.
		$this->db->update( 'cup_entry', array( 'competition_id' => $this->cup_a ), array( 'name' => 'Seefeld 2' ) );
		$other = self::expect_error( fn() => $this->results->submit( $this->first, $this->with_home( $this->first, $this->player['seefeld'][1], $this->player['seefeld'][4] ), $this->tk ), 422 );
		self::assertStringContainsString( 'Seefeld 2 (Mannschaftscup A)', $other->getMessage() );
	}

	public function test_a_substitute_who_played_for_another_cup_team_is_blocked(): void {
		// Der Ersatz spielt zuerst für Seefeld 2.
		$played = $this->results->submit( $this->second, $this->with_home( $this->second, $this->player['seefeld'][3], $this->substitute ), $this->tk );
		self::assertSame( 'provisional', $played['status'] );
		// Die eigene Begegnung zählt nicht gegen ihn: Sie lässt sich weiter ändern.
		self::assertSame( 'provisional', $this->results->submit( $this->second, $this->with_home( $this->second, $this->player['seefeld'][3], $this->substitute ), $this->tk )['status'] );

		$error = self::expect_error( fn() => $this->results->submit( $this->first, $this->with_home( $this->first, $this->player['seefeld'][1], $this->substitute ), $this->tk ), 422 );

		self::assertSame( array( 'lineup.home.B' ), array_column( $error->errors, 'field' ) );
		self::assertStringContainsString( 'Seefeld5 Cupspieler: In der Cupmannschaft Seefeld 2 (Mannschaftscup B) gemeldet oder eingesetzt', $error->getMessage() );

		// Der zweite freie Spieler hat noch für niemanden gespielt.
		self::assertSame( 'provisional', $this->results->submit( $this->first, $this->with_home( $this->first, $this->player['seefeld'][1], $this->another ), $this->tk )['status'] );
	}

	public function test_the_form_shows_players_of_other_cup_teams_as_blocked(): void {
		$entry = $this->results->entry( $this->first, $this->tk );
		$home  = array_column( $entry['candidates']['home'], null, 'player_id' );

		self::assertSame( array( true, 'stamm' ), array( $home[ $this->player['seefeld'][1] ]['eligible'], $home[ $this->player['seefeld'][1] ]['reason'] ) );
		self::assertSame( array( true, 'substitute' ), array( $home[ $this->substitute ]['eligible'], $home[ $this->substitute ]['reason'] ) );
		self::assertSame( array( false, 'blocked_bound_elsewhere' ), array( $home[ $this->player['seefeld'][3] ]['eligible'], $home[ $this->player['seefeld'][3] ]['reason'] ) );
		self::assertStringContainsString( 'Seefeld 2 (Mannschaftscup B)', $home[ $this->player['seefeld'][3] ]['message'] );
	}

	public function test_the_tk_overrides_the_rule_in_a_correction_and_gets_notes(): void {
		$this->classify( $this->another, 22 );
		$result = $this->with_home( $this->first, $this->player['seefeld'][3], $this->another );

		$decided = $this->results->decide(
			$this->first,
			$this->tk,
			array(
				'action' => 'correct',
				'result' => $result,
			)
		);

		self::assertSame( array( 'final', 3, 0 ), array( $decided['status'], $decided['home_score'], $decided['away_score'] ) );
		$event = json_decode( (string) $this->db->get_var( "SELECT data_json FROM {$this->db->table( 'fixture_event' )} WHERE fixture_id = %d AND type = 'decided'", array( $this->first ) ), true );
		self::assertSame(
			array(
				'Seefeld3 Cupspieler: In der Cupmannschaft Seefeld 2 (Mannschaftscup B) gemeldet oder eingesetzt; ein Spieler spielt in der Saison nur für eine Cupmannschaft (Art. 20.2).',
				'Seefeld6 Cupspieler ist nicht für diese Cup-Mannschaft gemeldet und spielt als Ersatz.',
				'Seefeld 1: Mindestens ein Spieler der ursprünglichen Meldung muss spielen (Art. 20.1).',
				'Seefeld 1: Die Klassierungen der eingesetzten Spieler ergeben 27, die Meldung erlaubt höchstens 20 (Art. 20.1).',
			),
			$event['warnings'],
			'Verstösse als Hinweise'
		);
		self::assertSame( array( $this->player['seefeld'][3], $this->another ), array_values( $this->results->repository()->lineup( $this->first )['home'] ) );
	}

	public function test_an_entry_without_players_or_points_is_not_checked(): void {
		// Wie importiert: Meldung ohne Spieler und ohne Punktesumme.
		$this->db->update(
			'cup_entry',
			array(
				'player1_id'   => null,
				'player2_id'   => null,
				'points_total' => null,
			),
			array( 'name' => 'Seefeld 1' )
		);
		$this->classify( $this->another, 22 );

		$result = $this->results->submit( $this->first, $this->with_home( $this->first, $this->substitute, $this->another ), $this->tk );

		self::assertSame( 'provisional', $result['status'] );
		self::assertCount( 2, $result['warnings'], 'beide spielen als Ersatz, sonst kein Verstoss' );
	}
}
