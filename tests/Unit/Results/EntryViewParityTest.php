<?php
/**
 * Gleiches Ansichtsmodell in PHP (EntryForm::view) und im Skript (buildView in entry-view.js).
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Tests\Unit\Results;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TTLeague\Results\EntryForm;

/**
 * Die Seite wird serverseitig mit dem PHP-Modell gerendert und im Browser mit dem Skript
 * weitergeführt; weichen beide ab, ändert sich die Seite beim Hydrieren. Braucht Node.
 */
final class EntryViewParityTest extends TestCase {

	public static function setUpBeforeClass(): void {
		require_once dirname( __DIR__ ) . '/Frontend/Stubs/WordPress.php';
	}

	protected function setUp(): void {
		if ( ! EntryScript::available() ) {
			self::markTestSkipped( 'Node ist nicht verfügbar.' );
		}
	}

	/**
	 * @return array<string,array{0:array<string,mixed>,1:array<string,mixed>,2:array<string,string>,3:string,4:array<string,string>}>
	 */
	public static function scenarios(): array {
		// Datenquellen laufen vor setUpBeforeClass().
		require_once dirname( __DIR__ ) . '/Frontend/Stubs/WordPress.php';
		$scenarios = array();

		[ $data, $form ]    = EntryScenario::form();
		$scenarios['leer']  = array( $data, $form, array(), 'enter', array() );
		$empty_data         = EntryForm::data( EntryScenario::entry() );
		$scenarios['start'] = array( $empty_data, EntryForm::initial( EntryScenario::entry(), $empty_data ), array(), 'enter', array() );

		[ $data, $form ] = EntryScenario::form( array( 'home' => array( 'C' => EntryForm::MISSING ) ) );
		EntryScenario::play( $form, 1, 3, 1 );
		EntryScenario::play( $form, 3, 2, 3 );
		$form['games']['7']['ap']                          = 'XZ';
		$form['games']['2']['st']                          = 'walkover';
		$form['games']['2']['win']                         = '';
		$form['comment']                                   = 'Bemerkung';
		$scenarios['fehlender Spieler, Fehler, Korrektur'] = array(
			$data,
			$form,
			array(
				'lineup.home.A'        => 'Hanna Heim: gesperrt.',
				'games.7.away_players' => 'Spiel 7: Die Doppelpaarung fehlt.',
				'games'                => 'Ein 2er-Team kann höchstens 7 Spiele gewinnen.',
				'reason'               => 'Zu kurz.',
			),
			'correct',
			array(
				'reason' => ' Spiel 7 ging an uns ',
				'action' => 'correct',
			),
		);

		[ $data, $form ]                         = EntryScenario::form(
			array(
				'home' => array( 'C' => EntryForm::MISSING ),
				'away' => array(
					'Z' => EntryForm::MISSING,
					'Y' => '',
				),
			)
		);
		$scenarios['beide fehlen, offener Slot'] = array( $data, $form, array(), '', array( 'action' => 'forfeit' ) );

		[ $data, $form ] = EntryScenario::form( array( 'home' => array( 'B' => EntryForm::MISSING ) ), 'CUP' );
		EntryScenario::play( $form, 1, 3, 0 );
		EntryScenario::play( $form, 2, 0, 3 );
		$scenarios['Cup mit einem Spieler'] = array( $data, $form, array(), 'enter', array() );

		[ $data, $form ] = EntryScenario::form( array(), 'CUP' );
		foreach ( array( 1, 2, 3, 5 ) as $seq ) {
			EntryScenario::play( $form, $seq, 3, 2 );
		}
		$scenarios['Cup entschieden'] = array( $data, $form, array(), 'enter', array() );

		[ $data, $form ] = EntryScenario::form( array(), 'MM', array( 'set_points_enabled' => true ) );
		EntryScenario::play( $form, 1, 3, 2 );
		$form['games']['1']['pts'][0] = array( '11', '8' );
		$form['games']['1']['pts'][4] = array( '12', '' );
		$scenarios['Satzpunkte']      = array( $data, $form, array( 'games.1.set_points' => 'Spiel 1: Satz 5: ungültig.' ), 'enter', array() );

		return $scenarios;
	}

	/**
	 * @param array<string,mixed>  $data   Daten.
	 * @param array<string,mixed>  $form   Zustand.
	 * @param array<string,string> $errors Fehler.
	 * @param array<string,string> $ui     Einsprache und Entscheid.
	 */
	#[DataProvider( 'scenarios' )]
	public function test_php_and_script_build_the_same_view( array $data, array $form, array $errors, string $mode, array $ui ): void {
		$php    = EntryForm::view( $data, $form, $errors, $mode, $ui );
		$script = EntryScript::call( 'buildView', array( $data, $form, (object) $errors, $mode, (object) $ui ) );

		self::assertSame( EntryScript::canonical( $php ), EntryScript::canonical( $script ) );
	}
}
