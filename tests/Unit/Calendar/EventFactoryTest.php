<?php
/**
 * Tests der Kalenderereignisse: Titel, Dauer, Ganztag, Status, Ort und Links.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Tests\Unit\Calendar;

use PHPUnit\Framework\TestCase;
use TTLeague\Calendar\EventFactory;
use TTLeague\Calendar\IcsEvent;
use TTLeague\Frontend\Support\Urls;

final class EventFactoryTest extends TestCase {

	private EventFactory $factory;

	public static function setUpBeforeClass(): void {
		require_once dirname( __DIR__ ) . '/Frontend/Stubs/WordPress.php';
	}

	protected function setUp(): void {
		parent::setUp();
		$this->factory = new EventFactory( new Urls( 'https://Example.org/' ) );
	}

	/**
	 * @param array<string,mixed> $data Abweichende Felder.
	 * @return array<string,mixed>
	 */
	private static function fixture( array $data = array() ): array {
		return $data + array(
			'type'        => 'fixture',
			'id'          => 12,
			'kind'        => 'MM',
			'competition' => 'Gruppe A',
			'season_slug' => '2026-27',
			'season_name' => '2026/27',
			'start'       => '2026-09-08 20:00:00',
			'status'      => 'scheduled',
			'home'        => 'Seefeld 1',
			'away'        => 'Altstetten 1',
			'home_score'  => null,
			'away_score'  => null,
			'venue'       => array(
				'name'   => 'Turnhalle Seefeld',
				'street' => 'Musterstrasse 1',
				'zip'    => '8000',
				'city'   => 'Musterstadt',
				'lat'    => null,
				'lng'    => null,
			),
		);
	}

	public function test_scheduled_championship_fixture(): void {
		$event = $this->factory->fixture( self::fixture() );
		self::assertNotNull( $event );
		self::assertSame( 'fixture-12@example.org', $event->uid );
		self::assertSame( 'Seefeld 1 – Altstetten 1 (Gruppe A)', $event->summary );
		self::assertSame( '2026-09-08 20:00:00 Europe/Zurich', $event->start->format( 'Y-m-d H:i:s e' ) );
		self::assertFalse( $event->all_day );
		self::assertSame( 150, $event->minutes );
		self::assertSame( 'Turnhalle Seefeld, Musterstrasse 1, 8000 Musterstadt', $event->location );
		self::assertSame( 'https://Example.org/begegnung/12/', $event->url );
		self::assertSame( IcsEvent::STATUS_CONFIRMED, $event->status );
		self::assertSame( array( 'Meisterschaft' ), $event->categories );
		self::assertNull( $event->geo );
		self::assertSame(
			"Meisterschaft 2026/27, Gruppe A\nSpiellokal: Turnhalle Seefeld, Musterstrasse 1, 8000 Musterstadt\nMatchreport: https://Example.org/begegnung/12/",
			$event->description
		);
	}

	public function test_results_after_the_match(): void {
		$final = $this->factory->fixture(
			self::fixture(
				array(
					'status'     => 'final',
					'home_score' => 6,
					'away_score' => 4,
				)
			)
		);
		self::assertSame( 'Seefeld 1 – Altstetten 1 (Gruppe A) 6:4', $final->summary );
		self::assertStringContainsString( "\nResultat: 6:4\n", $final->description );

		$provisional = $this->factory->fixture(
			self::fixture(
				array(
					'status'     => 'provisional',
					'home_score' => 4,
					'away_score' => 6,
				)
			)
		);
		self::assertSame( 'Seefeld 1 – Altstetten 1 (Gruppe A) 4:6', $provisional->summary );
		self::assertStringContainsString( 'Resultat: 4:6 (provisorisch)', $provisional->description );

		$forfeit = $this->factory->fixture(
			self::fixture(
				array(
					'status'     => 'forfeit',
					'home_score' => 0,
					'away_score' => 10,
				)
			)
		);
		self::assertSame( 'Seefeld 1 – Altstetten 1 (Gruppe A) 0:10 Forfait', $forfeit->summary );
		self::assertStringContainsString( 'Resultat: 0:10 (Forfait)', $forfeit->description );

		$no_score = $this->factory->fixture( self::fixture( array( 'status' => 'final' ) ) );
		self::assertSame( 'Seefeld 1 – Altstetten 1 (Gruppe A)', $no_score->summary, 'ohne erfasstes Resultat kein Resultat im Titel' );
	}

	public function test_cancelled_and_postponed_fixtures(): void {
		$cancelled = $this->factory->fixture( self::fixture( array( 'status' => 'cancelled' ) ) );
		self::assertSame( IcsEvent::STATUS_CANCELLED, $cancelled->status );
		self::assertSame( 'Annulliert: Seefeld 1 – Altstetten 1 (Gruppe A)', $cancelled->summary );
		self::assertStringContainsString( 'Status: annulliert', $cancelled->description );

		$postponed = $this->factory->fixture( self::fixture( array( 'status' => 'postponed' ) ) );
		self::assertSame( IcsEvent::STATUS_TENTATIVE, $postponed->status );
		self::assertSame( 'Verschoben: Seefeld 1 – Altstetten 1 (Gruppe A)', $postponed->summary );
	}

	public function test_unknown_time_becomes_an_all_day_event(): void {
		$event = $this->factory->fixture( self::fixture( array( 'start' => '2026-12-02 00:00:00' ) ) );
		self::assertTrue( $event->all_day );
		self::assertSame( '2026-12-02', $event->start->format( 'Y-m-d' ) );
		self::assertStringContainsString( 'Anspielzeit noch offen.', $event->description );

		self::assertTrue( EventFactory::start( '2026-12-02' )[1] );
		self::assertFalse( EventFactory::start( '2026-12-02 00:01:00' )[1] );
		self::assertNull( EventFactory::start( null ) );
		self::assertNull( EventFactory::start( '' ) );
		self::assertNull( EventFactory::start( '2026-02-30 20:00:00' ) );
		self::assertNull( EventFactory::start( '2026-12-02 25:00:00' ) );
		self::assertNull( $this->factory->fixture( self::fixture( array( 'start' => null ) ) ) );
	}

	public function test_cup_fixture_without_venue(): void {
		$event = $this->factory->fixture(
			self::fixture(
				array(
					'id'          => 301,
					'kind'        => 'CUP',
					'competition' => 'Mannschaftscup A',
					'status'      => 'final',
					'home_score'  => 3,
					'away_score'  => 1,
					'venue'       => null,
				)
			)
		);
		self::assertSame( 'Seefeld 1 – Altstetten 1 (Mannschaftscup A) 3:1', $event->summary );
		self::assertSame( 120, $event->minutes );
		self::assertSame( '', $event->location );
		self::assertSame( array( 'Cup' ), $event->categories );
		self::assertStringStartsWith( "Mannschaftscup A 2026/27\nResultat: 3:1\nMatchreport:", $event->description );
	}

	public function test_ranking_tournament_group(): void {
		$row   = array(
			'type'        => 'rl_group',
			'id'          => 7,
			'season_slug' => '2026-27',
			'season_name' => '2026/27',
			'start'       => '2026-11-18 19:15:00',
			'round_no'    => 2,
			'is_final'    => false,
			'category'    => 1,
			'group_no'    => 3,
			'host'        => 'Seefeld',
			'venue'       => array(
				'name'   => 'Turnhalle Seefeld',
				'street' => 'Musterstrasse 1',
				'zip'    => '8000',
				'city'   => 'Musterstadt',
				'lat'    => 47.3,
				'lng'    => 8.5,
			),
		);
		$event = $this->factory->rl_group( $row );
		self::assertSame( 'rlgroup-7@example.org', $event->uid );
		self::assertSame( 'Ranglistenturnier: 2. Runde, Kategorie 1, Gruppe 3', $event->summary );
		self::assertSame( 180, $event->minutes );
		self::assertSame( 'https://Example.org/ranglistenturnier/2026-27/', $event->url );
		self::assertSame( array( 47.3, 8.5 ), $event->geo );
		self::assertStringContainsString( 'Platzclub: Seefeld', $event->description );

		$final = $this->factory->rl_group(
			array(
				'is_final' => true,
				'venue'    => null,
			) + $row
		);
		self::assertSame( 'Ranglistenturnier: Finalrunde, Kategorie 1, Gruppe 3', $final->summary );
		self::assertSame( 'Seefeld', $final->location, 'ohne Lokal der Platzclub' );
	}

	public function test_events_keep_order_and_skip_rows_without_date(): void {
		$events = $this->factory->events(
			array(
				self::fixture( array( 'id' => 1 ) ),
				self::fixture(
					array(
						'id'    => 2,
						'start' => 'unbekannt',
					)
				),
				self::fixture( array( 'id' => 3 ) ),
			)
		);
		self::assertSame( array( 'fixture-1@example.org', 'fixture-3@example.org' ), array_map( static fn( IcsEvent $e ): string => $e->uid, $events ) );
	}

	public function test_calendar_names(): void {
		$season = array(
			'id'   => 2,
			'name' => '2026/27',
		);
		$team   = array(
			'type'   => 'team',
			'name'   => 'Seefeld 1',
			'season' => $season,
		);
		self::assertSame( 'Seefeld 1 – Spiele 2026/27', EventFactory::name( $team ) );
		self::assertSame( 'Spielplan und Resultate von Seefeld 1 in der Meisterschaft. Saison 2026/27.', EventFactory::description( $team ) );
		self::assertSame(
			'TTC Seefeld – Spiele',
			EventFactory::name(
				array(
					'type'   => 'club',
					'name'   => 'TTC Seefeld',
					'season' => null,
				)
			)
		);
		self::assertStringStartsWith(
			'Begegnungen der Teams von Hans Muster',
			EventFactory::description(
				array(
					'type'   => 'player',
					'name'   => 'Hans Muster',
					'season' => $season,
				)
			)
		);
	}
}
