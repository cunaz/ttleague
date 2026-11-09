<?php
/**
 * Texte des TK-Dashboards und der Kontenlisten.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Tests\Unit\Tk;

use PHPUnit\Framework\TestCase;
use TTLeague\Accounts\AccountLabels;
use TTLeague\Accounts\ClubPeople;
use TTLeague\Tk\DashboardPage;

final class LabelsTest extends TestCase {

	public static function setUpBeforeClass(): void {
		require_once dirname( __DIR__ ) . '/Frontend/Stubs/WordPress.php';
	}

	public function test_age(): void {
		self::assertSame( '–', DashboardPage::age( null ) );
		self::assertSame( 'unter 1 Stunde', DashboardPage::age( 0 ) );
		self::assertSame( '1 Stunde', DashboardPage::age( 1 ) );
		self::assertSame( '47 Stunden', DashboardPage::age( 47 ) );
		self::assertSame( '2 Tage', DashboardPage::age( 48 ) );
		self::assertSame( '16 Tage', DashboardPage::age( 16 * 24 + 23 ) );
	}

	public function test_decisions_and_events(): void {
		$row = array(
			'home'   => 'Seefeld 1',
			'away'   => 'Wollishofen 1',
			'action' => 'forfeit',
			'winner' => 'away',
		);
		self::assertSame( 'Forfait für Wollishofen 1', DashboardPage::decision_label( $row ) );
		self::assertSame( 'bestätigt', DashboardPage::decision_label( array( 'action' => 'confirm' ) ) );
		self::assertSame( 'abgeschlossen', DashboardPage::decision_label( array( 'action' => 'finalize' ) ) );
		self::assertSame( 'zurückgesetzt', DashboardPage::decision_label( array( 'action' => 'reset' ) ) );
		self::assertSame(
			'Korrektur der TK',
			DashboardPage::event_label(
				array(
					'event'  => 'decided',
					'action' => 'correct',
				)
			)
		);
		self::assertSame( 'Änderung', DashboardPage::event_label( array( 'event' => 'edited' ) ) );
	}

	public function test_functions_and_summary(): void {
		$person = array(
			'functions' => array(
				array(
					'role' => 'tk_chef',
					'team' => null,
				),
				array(
					'role' => 'captain',
					'team' => 'Seefeld 1',
				),
			),
			'player'    => true,
		);
		self::assertSame( 'TK-Chef · Captain Seefeld 1 · Spieler', AccountLabels::functions( $person ) );
		self::assertSame(
			'4 Personen · 1 ohne E-Mail · 2 ohne Konto · 1 aktiv',
			AccountLabels::summary(
				array(
					array( 'status' => ClubPeople::NO_ACCOUNT ),
					array( 'status' => ClubPeople::ACTIVE ),
					array( 'status' => ClubPeople::NO_EMAIL ),
					array( 'status' => ClubPeople::NO_ACCOUNT ),
				)
			)
		);
		self::assertSame( 'Einladen', AccountLabels::invite_button( array( 'status' => ClubPeople::NO_ACCOUNT ) ) );
		self::assertSame( 'Erneut einladen', AccountLabels::invite_button( array( 'status' => ClubPeople::INVITED ) ) );
	}
}
