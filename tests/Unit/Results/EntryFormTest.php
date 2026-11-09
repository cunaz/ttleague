<?php
/**
 * Startzustand und Ansichtsmodell des Erfassungsformulars (Gegenstück zu buildView in entry.js).
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Tests\Unit\Results;

use PHPUnit\Framework\TestCase;
use TTLeague\Domain\Match\GameOrder;
use TTLeague\Results\EntryForm;
use TTLeague\Results\EntryLinks;

/**
 * Arbeitet mit einer Antwort im Format von ResultService::entry() mit erfundenen Spielern.
 */
final class EntryFormTest extends TestCase {

	public static function setUpBeforeClass(): void {
		require_once dirname( __DIR__ ) . '/Frontend/Stubs/WordPress.php';
	}

	/**
	 * @param array<string,mixed> $current Gespeichertes Resultat.
	 * @param array<string,mixed> $rules   Abweichende Regeln.
	 * @return array<string,mixed>
	 */
	private static function entry( string $type = 'MM', array $current = array(), array $rules = array() ): array {
		$cup        = 'CUP' === $type;
		$candidate  = static fn( int $id, string $name, int $rank, string $reason = 'stamm', bool $ok = true, string $message = 'Stammspieler.', int $subs = 0 ): array => array(
			'player_id'              => $id,
			'name'                   => $name,
			'license_no'             => 90000 + $id,
			'classification'         => $rank,
			'label'                  => 'C' . $rank,
			'eligible'               => $ok,
			'reason'                 => $reason,
			'message'                => $message,
			'substitute_appearances' => $subs,
			'remaining'              => null,
		);
		$candidates = array(
			'home' => array(
				$candidate( 11, 'Hanna Heim', 8 ),
				$candidate( 12, 'Hugo Heim', 7 ),
				$candidate( 13, 'Hilde Heim', 7 ),
				$candidate( 14, 'Hans Ersatz', 6, 'substitute', true, '2. Einsatz als Ersatz von maximal 3, danach gebunden.', 1 ),
				$candidate( 15, 'Herta Gesperrt', 9, 'blocked_lower_team', false, 'Stammspieler sind für tiefer klassierte Mannschaften nicht spielberechtigt (Art. 12.2).' ),
			),
			'away' => array(
				$candidate( 21, 'Gabi Gast', 8 ),
				$candidate( 22, 'Gerd Gast', 7 ),
				$candidate( 23, 'Greta Gast', 6 ),
			),
		);
		return array(
			'fixture'    => array(
				'id'           => 1,
				'type'         => $type,
				'status'       => 'scheduled',
				'status_label' => 'angesetzt',
				'scheduled_at' => '2026-11-10 20:00:00',
				'competition'  => $cup ? 'Cup A' : 'Gruppe B1',
				'season'       => '2026-27',
				'home'         => array( 'name' => 'Seefeld 1' ),
				'away'         => array( 'name' => 'Wollishofen 1' ),
				'home_score'   => null,
				'away_score'   => null,
			),
			'can'        => array(
				'enter'   => true,
				'confirm' => false,
				'object'  => false,
				'decide'  => false,
				'photo'   => true,
			),
			'roles'      => array( 'home' ),
			'rules'      => array(
				'sets_to_win'        => 3,
				'set_points_enabled' => $rules['set_points_enabled'] ?? false,
				'cup_mode'           => $rules['cup_mode'] ?? 'first_to_three',
				'substitute_max'     => 3,
				'slots'              => array(
					'home' => $cup ? array( 'A', 'B' ) : array( 'A', 'B', 'C' ),
					'away' => $cup ? array( 'Y', 'Z' ) : array( 'X', 'Y', 'Z' ),
				),
			),
			'order'      => $cup ? GameOrder::cup() : GameOrder::team_match(),
			'candidates' => $candidates,
			'current'    => $current + array(
				'lineup'      => array(
					'home' => (object) array(),
					'away' => (object) array(),
				),
				'substitutes' => array(),
				'games'       => array(),
				'warnings'    => array(),
				'photo_id'    => null,
			),
			'history'    => array(),
			'version'    => 'abc',
		);
	}

	/**
	 * Formular mit voller Aufstellung, optional mit fehlenden Slots.
	 *
	 * @param array<string,array<string,string>> $lineup Abweichungen pro Seite.
	 * @return array{0:array<string,mixed>,1:array<string,mixed>} Daten und Zustand.
	 */
	private static function form( array $lineup = array(), string $type = 'MM', array $rules = array() ): array {
		$entry          = self::entry( $type, array(), $rules );
		$data           = EntryForm::data( $entry );
		$form           = EntryForm::initial( $entry, $data );
		$full           = 'CUP' === $type
			? array(
				'home' => array(
					'A' => '11',
					'B' => '12',
				),
				'away' => array(
					'Y' => '21',
					'Z' => '22',
				),
			)
			: array(
				'home' => array(
					'A' => '11',
					'B' => '12',
					'C' => '13',
				),
				'away' => array(
					'X' => '21',
					'Y' => '22',
					'Z' => '23',
				),
			);
		$form['lineup'] = array_replace_recursive( $full, $lineup );
		return array( $data, $form );
	}

	/**
	 * @param array<string,mixed> $form Zustand.
	 */
	private static function play( array &$form, int $seq, int $home, int $away ): void {
		$form['games'][ (string) $seq ]['st'] = 'played';
		$form['games'][ (string) $seq ]['sh'] = $home;
		$form['games'][ (string) $seq ]['sa'] = $away;
	}

	public function test_scores_and_data(): void {
		self::assertSame( array( array( 3, 0 ), array( 3, 1 ), array( 3, 2 ), array( 2, 3 ), array( 1, 3 ), array( 0, 3 ) ), EntryForm::scores( 3 ) );

		$data = EntryForm::data( self::entry(), array( 99 => 'Paul Extra' ) );
		self::assertNull( $data['cupTarget'] );
		self::assertSame( 5, $data['maxSets'] );
		self::assertSame( array( 'Seefeld 1', 'Wollishofen 1' ), array_values( $data['teams'] ) );
		self::assertSame( 'Paul Extra', $data['players']['99']['name'] );
		self::assertSame( '', $data['eligibility']['home']['11']['note'], 'Stammspieler ohne Hinweis' );
		self::assertStringContainsString( '2. Einsatz als Ersatz', $data['eligibility']['home']['14']['note'] );
		self::assertFalse( $data['eligibility']['home']['15']['ok'] );
		self::assertArrayHasKey( 'progress', $data['strings'] );

		self::assertSame( 3, EntryForm::data( self::entry( 'CUP' ) )['cupTarget'] );
		self::assertNull( EntryForm::data( self::entry( 'CUP', array(), array( 'cup_mode' => 'all_games' ) ) )['cupTarget'] );
	}

	public function test_initial_state_from_saved_result(): void {
		$entry = self::entry(
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

		$empty = EntryForm::initial( self::entry(), $data );
		self::assertSame( '', $empty['lineup']['away']['Z'], 'ohne Resultat noch nicht gewählt' );
		self::assertCount( 10, $empty['games'] );
	}

	public function test_view_of_empty_form(): void {
		$entry = self::entry();
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
		[ $data, $form ] = self::form( array( 'home' => array( 'C' => EntryForm::MISSING ) ) );
		self::play( $form, 1, 3, 1 );
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
		self::assertSame( 'Paarung: Hanna Heim / Hugo Heim (automatisch)', $view['g7']['autoHome'] );
		self::assertFalse( $view['g7']['pairHome'] );
		self::assertTrue( $view['g7']['pairAway'], 'Gast mit drei Spielern wählt die Paarung' );
	}

	public function test_explicit_result_against_missing_player_can_be_fixed(): void {
		[ $data, $form ] = self::form( array( 'away' => array( 'Z' => EntryForm::MISSING ) ) );
		self::play( $form, 3, 1, 3 );
		$view = EntryForm::view( $data, $form );

		self::assertFalse( $view['g3']['sug'] );
		self::assertTrue( $view['g3']['fix'] );
		self::assertSame( array( 'walkover', 'home' ), array( $view['g3']['fixSt'], $view['g3']['fixWin'] ) );
		self::assertSame( 'Z fehlt in der Aufstellung: Das Spiel ist kampflos für Seefeld 1.', $view['g3']['hint'] );

		[ $data, $form ] = self::form(
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
		[ $data, $form ] = self::form( array(), 'CUP' );
		self::play( $form, 1, 3, 0 );
		self::play( $form, 2, 3, 1 );
		self::play( $form, 3, 3, 2 );
		$view = EntryForm::view( $data, $form );

		self::assertTrue( $view['decided'] );
		self::assertSame( '3:0', $view['total'] );
		self::assertSame( 'Entschieden, weitere Spiele sind freiwillig', $view['progress'] );
		self::assertFalse( $view['g3']['opt'] );
		self::assertTrue( $view['g4']['opt'] && $view['g5']['opt'] );

		self::play( $form, 4, 0, 3 );
		self::assertSame( '3:0', EntryForm::view( $data, $form )['total'], 'Spiele nach der Entscheidung zählen nicht' );

		[ $data, $form ] = self::form( array( 'home' => array( 'B' => EntryForm::MISSING ) ), 'CUP' );
		$view            = EntryForm::view( $data, $form );
		self::assertTrue( $view['g3']['woAway'], 'Doppel mit nur einem Spieler: kampflos' );
		self::assertSame( 'Vorschlag: Für das Doppel fehlt ein Spieler, kampflos für Wollishofen 1.', $view['g3']['hint'] );

		[ $data, $form ] = self::form( array(), 'CUP', array( 'cup_mode' => 'all_games' ) );
		self::play( $form, 1, 3, 0 );
		self::play( $form, 2, 3, 1 );
		self::play( $form, 3, 3, 2 );
		self::assertFalse( EntryForm::view( $data, $form )['g4']['opt'], 'alle Spiele werden gewertet' );
	}

	public function test_errors_and_modes(): void {
		[ $data, $form ] = self::form();
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
		[ $data, $form ] = self::form( array(), 'MM', array( 'set_points_enabled' => true ) );
		self::play( $form, 1, 3, 1 );
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
