<?php
/**
 * Startzustand und Ansichtsmodell des Erfassungsformulars (Gegenstück zu buildView in entry-view.js).
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Tests\Unit\Results;

use PHPUnit\Framework\TestCase;
use TTLeague\Results\EntryForm;
use TTLeague\Results\EntryLinks;

/**
 * Arbeitet mit einer Antwort im Format von ResultService::entry() mit erfundenen Spielern (EntryScenario).
 */
final class EntryFormTest extends TestCase {

	public static function setUpBeforeClass(): void {
		require_once dirname( __DIR__ ) . '/Frontend/Stubs/WordPress.php';
	}

	public function test_scores_and_data(): void {
		self::assertSame( array( array( 3, 0 ), array( 3, 1 ), array( 3, 2 ), array( 2, 3 ), array( 1, 3 ), array( 0, 3 ) ), EntryForm::scores( 3 ) );

		$data = EntryForm::data( EntryScenario::entry(), array( 99 => 'Paul Extra' ) );
		self::assertNull( $data['cupTarget'] );
		self::assertSame( 5, $data['maxSets'] );
		self::assertSame( array( 'Seefeld 1', 'Wollishofen 1' ), array_values( $data['teams'] ) );
		self::assertSame( 'Paul Extra', $data['players']['99']['name'] );
		self::assertSame( '', $data['eligibility']['home']['11']['note'], 'Stammspieler ohne Hinweis' );
		self::assertStringContainsString( '2. Einsatz als Ersatz', $data['eligibility']['home']['14']['note'] );
		self::assertFalse( $data['eligibility']['home']['15']['ok'] );
		self::assertArrayHasKey( 'progress', $data['strings'] );

		self::assertSame( 3, EntryForm::data( EntryScenario::entry( 'CUP' ) )['cupTarget'] );
		self::assertNull( EntryForm::data( EntryScenario::entry( 'CUP', array(), array( 'cup_mode' => 'all_games' ) ) )['cupTarget'] );
	}

	public function test_initial_state_from_saved_result(): void {
		$entry = EntryScenario::entry(
			'MM',
			array(
				'lineup' => array(
					'home' => (object) array(
						'A' => 11,
						'B' => 12,
					),
					'away' => (object) array(
						'X' => 21,
						'Y' => 22,
						'Z' => 23,
					),
				),
				'games'  => array(
					array(
						'sequence'     => 1,
						'type'         => 'single',
						'status'       => 'played',
						'sets_home'    => 3,
						'sets_away'    => 1,
						'home_players' => array( 11 ),
						'away_players' => array( 21 ),
						'counts'       => true,
						'set_points'   => array( array( 11, 8 ), array( 9, 11 ), array( 11, 5 ), array( 12, 10 ) ),
					),
					array(
						'sequence'     => 3,
						'type'         => 'single',
						'status'       => 'walkover',
						'sets_home'    => 0,
						'sets_away'    => 3,
						'home_players' => array(),
						'away_players' => array( 23 ),
						'counts'       => true,
						'set_points'   => array(),
					),
					array(
						'sequence'     => 7,
						'type'         => 'double',
						'status'       => 'played',
						'sets_home'    => 2,
						'sets_away'    => 3,
						'home_players' => array( 11, 12 ),
						'away_players' => array( 21, 23 ),
						'counts'       => true,
						'set_points'   => array(),
					),
				),
			)
		);
		$data  = EntryForm::data( $entry );
		$form  = EntryForm::initial( $entry, $data );

		self::assertSame(
			array(
				'A' => '11',
				'B' => '12',
				'C' => EntryForm::MISSING,
			),
			$form['lineup']['home']
		);
		self::assertSame( array( 'played', 3, 1 ), array( $form['games']['1']['st'], $form['games']['1']['sh'], $form['games']['1']['sa'] ) );
		self::assertSame( array( '12', '10' ), $form['games']['1']['pts'][3] );
		self::assertSame( array( 'walkover', 'away' ), array( $form['games']['3']['st'], $form['games']['3']['win'] ) );
		self::assertSame( '', $form['games']['2']['st'], 'nicht erfasst' );
		self::assertSame( array( 'AB', 'XZ' ), array( $form['games']['7']['hp'], $form['games']['7']['ap'] ), 'Paarung als Slots' );

		$empty = EntryForm::initial( EntryScenario::entry(), $data );
		self::assertSame( '', $empty['lineup']['away']['Z'], 'ohne Resultat noch nicht gewählt' );
		self::assertCount( 10, $empty['games'] );
	}

	public function test_empty_form_starts_with_the_suggested_lineup(): void {
		$entry = EntryScenario::entry(
			'MM',
			array(),
			array(),
			array(
				'home' => array(
					'A' => 11,
					'B' => 12,
					'C' => 14,
				),
				'away' => array( 'X' => 21 ),
			)
		);
		$data  = EntryForm::data( $entry );
		$form  = EntryForm::initial( $entry, $data );

		self::assertSame(
			array(
				'A' => '11',
				'B' => '12',
				'C' => '14',
			),
			$data['suggestion']['home']
		);
		self::assertSame( $data['suggestion']['home'], $form['lineup']['home'] );
		self::assertSame(
			array(
				'X' => '21',
				'Y' => '',
				'Z' => '',
			),
			$form['lineup']['away'],
			'Slots ohne Vorschlag bleiben leer, nicht «fehlt»'
		);
		self::assertSame( '', $form['games']['1']['st'], 'Spiele werden nicht vorgeschlagen' );

		$view = EntryForm::view( $data, $form );
		self::assertTrue( $view['lineup']['home']['sug'] );
		self::assertTrue( $view['lineup']['away']['sug'] );
		self::assertStringContainsString( '2. Einsatz als Ersatz', $view['lineup']['home']['C']['note'], 'Hinweis zum vorgeschlagenen Ersatz' );
		self::assertSame( 'Hanna Heim', $view['g1']['home'] );

		// Die Kennzeichnung gilt pro Seite und nur, solange die Seite dem Vorschlag entspricht.
		$form['lineup']['home']['C'] = '13';
		$view                        = EntryForm::view( $data, $form );
		self::assertFalse( $view['lineup']['home']['sug'] );
		self::assertTrue( $view['lineup']['away']['sug'] );

		$form['lineup']['home']['C'] = '14';
		$form['lineup']['away']['Y'] = '22';
		$view                        = EntryForm::view( $data, $form );
		self::assertTrue( $view['lineup']['home']['sug'], 'wieder wie vorgeschlagen' );
		self::assertFalse( $view['lineup']['away']['sug'] );
	}

	public function test_suggestion_without_effect(): void {
		$entry = EntryScenario::entry();
		$data  = EntryForm::data( $entry );
		self::assertSame(
			array(
				'home' => array(),
				'away' => array(),
			),
			$data['suggestion']
		);
		$view = EntryForm::view( $data, EntryForm::initial( $entry, $data ) );
		self::assertFalse( $view['lineup']['home']['sug'], 'ohne Vorschlag keine Kennzeichnung' );
		self::assertFalse( $view['lineup']['away']['sug'] );

		// Unbekannte Spieler und Slots werden nicht übernommen.
		$entry = EntryScenario::entry(
			'MM',
			array(),
			array(),
			array(
				'home' => array(
					'A' => 99,
					'D' => 11,
				),
			)
		);
		self::assertSame( array(), EntryForm::data( $entry )['suggestion']['home'] );

		// Ein erfasstes Resultat hat Vorrang vor einem Vorschlag.
		$saved = array(
			'lineup' => array(
				'home' => (object) array(
					'A' => 13,
					'B' => 12,
				),
				'away' => (object) array(
					'X' => 21,
					'Y' => 22,
					'Z' => 23,
				),
			),
			'games'  => array(
				array(
					'sequence'     => 1,
					'type'         => 'single',
					'status'       => 'played',
					'sets_home'    => 3,
					'sets_away'    => 1,
					'home_players' => array( 13 ),
					'away_players' => array( 21 ),
					'counts'       => true,
					'set_points'   => array(),
				),
			),
		);
		$entry = EntryScenario::entry( 'MM', $saved, array(), array( 'home' => array( 'A' => 11 ) ) );
		$data  = EntryForm::data( $entry );
		$form  = EntryForm::initial( $entry, $data );
		self::assertSame(
			array(
				'A' => '13',
				'B' => '12',
				'C' => EntryForm::MISSING,
			),
			$form['lineup']['home']
		);
		self::assertFalse( EntryForm::view( $data, $form )['lineup']['home']['sug'] );
	}

	public function test_view_of_empty_form(): void {
		$entry = EntryScenario::entry();
		$data  = EntryForm::data( $entry );
		$view  = EntryForm::view( $data, EntryForm::initial( $entry, $data ) );

		self::assertFalse( $view['formHidden'] );
		self::assertTrue( $view['lockBlocked'] );
		self::assertSame( '0:0', $view['total'] );
		self::assertSame( '0 von 10 erfasst', $view['progress'] );
		self::assertSame( 'offen', $view['g1']['out'] );
		self::assertSame( array( 'A', 'X' ), array( $view['g1']['home'], $view['g1']['away'] ) );
		self::assertSame( array( 'Seefeld 1', 'Wollishofen 1' ), array( $view['g7']['home'], $view['g7']['away'] ), 'Doppel ohne Paarung' );
		self::assertFalse( $view['g1']['p31'] );
		self::assertFalse( $view['hasErrors'] );
		self::assertSame( 'A + B', $view['pairs']['home']['AB'] );
		self::assertFalse( $view['dec']['reset'] );
	}

	public function test_missing_player_suggests_walkover(): void {
		[ $data, $form ] = EntryScenario::form( array( 'home' => array( 'C' => EntryForm::MISSING ) ) );
		EntryScenario::play( $form, 1, 3, 1 );
		$view = EntryForm::view( $data, $form );

		foreach ( array( 3, 6, 9 ) as $seq ) {
			$game = $view[ 'g' . $seq ];
			self::assertTrue( $game['sug'] && $game['wo'] && $game['woAway'], "Spiel $seq: Vorschlag kampflos für Gast" );
			self::assertSame( 'Vorschlag: C fehlt, kampflos für Wollishofen 1.', $game['hint'] );
			self::assertSame( '0:3 w.o.', $game['out'] );
		}
		self::assertSame( 'C fehlt', $view['g3']['home'] );
		self::assertSame( '1:3', $view['total'] );
		self::assertSame( '4 von 10 erfasst', $view['progress'] );
		self::assertTrue( $view['g1']['p31'] );
		self::assertSame( 'Hanna Heim', $view['g1']['home'] );
		self::assertSame( 'Doppel Seefeld 1: Hanna Heim / Hugo Heim (automatisch)', $view['g7']['autoHome'] );
		self::assertFalse( $view['g7']['pairHome'] );
		self::assertTrue( $view['g7']['pairAway'], 'Gast mit drei Spielern wählt die Paarung' );
	}

	public function test_explicit_result_against_missing_player_can_be_fixed(): void {
		[ $data, $form ] = EntryScenario::form( array( 'away' => array( 'Z' => EntryForm::MISSING ) ) );
		EntryScenario::play( $form, 3, 1, 3 );
		$view = EntryForm::view( $data, $form );

		self::assertFalse( $view['g3']['sug'] );
		self::assertTrue( $view['g3']['fix'] );
		self::assertSame( array( 'walkover', 'home' ), array( $view['g3']['fixSt'], $view['g3']['fixWin'] ) );
		self::assertSame( 'Z fehlt in der Aufstellung: Das Spiel ist kampflos für Seefeld 1.', $view['g3']['hint'] );

		[ $data, $form ] = EntryScenario::form(
			array(
				'home' => array( 'C' => EntryForm::MISSING ),
				'away' => array( 'Z' => EntryForm::MISSING ),
			)
		);
		$view            = EntryForm::view( $data, $form );
		self::assertTrue( $view['g3']['np'] && $view['g3']['sug'], 'beide fehlen: nicht gespielt' );
		self::assertSame( 'nicht gespielt', $view['g3']['out'] );
	}

	public function test_cup_is_decided_after_third_win(): void {
		[ $data, $form ] = EntryScenario::form( array(), 'CUP' );
		EntryScenario::play( $form, 1, 3, 0 );
		EntryScenario::play( $form, 2, 3, 1 );
		EntryScenario::play( $form, 3, 3, 2 );
		$view = EntryForm::view( $data, $form );

		self::assertTrue( $view['decided'] );
		self::assertSame( '3:0', $view['total'] );
		self::assertSame( 'Entschieden, weitere Spiele sind freiwillig', $view['progress'] );
		self::assertFalse( $view['g3']['opt'] );
		self::assertTrue( $view['g4']['opt'] && $view['g5']['opt'] );

		EntryScenario::play( $form, 4, 0, 3 );
		self::assertSame( '3:0', EntryForm::view( $data, $form )['total'], 'Spiele nach der Entscheidung zählen nicht' );

		[ $data, $form ] = EntryScenario::form( array( 'home' => array( 'B' => EntryForm::MISSING ) ), 'CUP' );
		$view            = EntryForm::view( $data, $form );
		self::assertTrue( $view['g3']['woAway'], 'Doppel mit nur einem Spieler: kampflos' );
		self::assertSame( 'Vorschlag: Für das Doppel fehlt ein Spieler, kampflos für Wollishofen 1.', $view['g3']['hint'] );

		[ $data, $form ] = EntryScenario::form( array(), 'CUP', array( 'cup_mode' => 'all_games' ) );
		EntryScenario::play( $form, 1, 3, 0 );
		EntryScenario::play( $form, 2, 3, 1 );
		EntryScenario::play( $form, 3, 3, 2 );
		self::assertFalse( EntryForm::view( $data, $form )['g4']['opt'], 'alle Spiele werden gewertet' );
	}

	public function test_errors_and_modes(): void {
		[ $data, $form ] = EntryScenario::form();
		$view            = EntryForm::view(
			$data,
			$form,
			array(
				'lineup.home.A'        => 'Hanna Heim: gesperrt.',
				'games.7.home_players' => 'Spiel 7: Die Doppelpaarung fehlt.',
				'games'                => 'Ein 2er-Team kann höchstens 7 Spiele gewinnen.',
			),
			'correct',
			array(
				'reason' => '  Zu kurz  ',
				'action' => 'correct',
			)
		);
		self::assertTrue( $view['lineup']['home']['A']['bad'] );
		self::assertFalse( $view['lineup']['home']['B']['bad'] );
		self::assertTrue( $view['g7']['bad'] && $view['g7']['badHome'] );
		self::assertSame( 'Ein 2er-Team kann höchstens 7 Spiele gewinnen.', $view['gamesErr'] );
		self::assertTrue( $view['hasErrors'] );
		self::assertSame( 'Bitte prüfe die 3 markierten Angaben.', $view['errorTitle'] );
		self::assertSame( '7 Zeichen (mindestens 10, höchstens 2000)', $view['reasonInfo'] );
		self::assertTrue( $view['correct'] && $view['decCorrect'] && $view['dec']['correct'] );
		self::assertFalse( $view['lockBlocked'], 'Korrektur der TK: gesperrte Spieler wählbar' );
		self::assertSame( 'Korrektur speichern', $view['submit'] );

		self::assertTrue( EntryForm::view( $data, $form, array(), '' )['formHidden'] );
	}

	public function test_set_points_rows_follow_the_result(): void {
		[ $data, $form ] = EntryScenario::form( array(), 'MM', array( 'set_points_enabled' => true ) );
		EntryScenario::play( $form, 1, 3, 1 );
		$form['games']['1']['pts'][0] = array( '11', '8' );
		$view                         = EntryForm::view( $data, $form );

		self::assertTrue( $view['g1']['pts'] );
		self::assertSame( array( true, true, true, true, false ), array( $view['g1']['s1'], $view['g1']['s2'], $view['g1']['s3'], $view['g1']['s4'], $view['g1']['s5'] ) );
		self::assertSame( array( '11', '8' ), array( $view['g1']['v1h'], $view['g1']['v1a'] ) );
		self::assertFalse( $view['g2']['pts'], 'ohne Resultat keine Satzpunkte' );
	}

	public function test_report_button_label(): void {
		self::assertSame( 'Resultat erfassen', EntryLinks::label( 'scheduled', true, false, false ) );
		self::assertNull( EntryLinks::label( 'scheduled', false, true, false ), 'Gastseite vor der Erfassung' );
		self::assertSame( 'Resultat bestätigen', EntryLinks::label( 'provisional', false, true, false ) );
		self::assertSame( 'Resultat ändern', EntryLinks::label( 'provisional', true, false, false ) );
		self::assertSame( 'Resultat bestätigen', EntryLinks::label( 'provisional', true, true, true ), 'TK' );
		self::assertNull( EntryLinks::label( 'confirmed', true, false, false ) );
		self::assertSame( 'Entscheid der TK', EntryLinks::label( 'disputed', true, true, true ) );
	}
}
