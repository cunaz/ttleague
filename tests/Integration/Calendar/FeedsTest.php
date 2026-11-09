<?php
/**
 * Integrationstests der Kalender-Abos gegen das Szenario aus dem Seeder.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Tests\Integration\Calendar;

use TTLeague\Calendar\EventFactory;
use TTLeague\Calendar\Feeds;
use TTLeague\Calendar\IcsWriter;
use TTLeague\Frontend\Support\Urls;
use TTLeague\ReadModel\Cache\NullCache;
use TTLeague\ReadModel\ReadModel;
use TTLeague\Tests\Integration\Frontend\Seeder;
use TTLeague\Tests\Integration\IntegrationTestCase;

final class FeedsTest extends IntegrationTestCase {

	private ReadModel $model;

	private Feeds $feeds;

	private Urls $urls;

	private Seeder $seeder;

	/**
	 * @var array<string,int>
	 */
	private array $ids;

	public static function setUpBeforeClass(): void {
		require_once dirname( __DIR__, 2 ) . '/Unit/Frontend/Stubs/WordPress.php';
	}

	protected function setUp(): void {
		parent::setUp();
		$this->seeder = new Seeder( $this->db );
		$this->ids    = $this->seeder->scenario();
		$this->model  = new ReadModel( $this->db, new NullCache() );
		$this->feeds  = new Feeds( $this->db, new NullCache(), $this->model );
		$this->urls   = new Urls( 'https://example.org/' );
	}

	/**
	 * Zeilen eines Feeds als «Typ-ID».
	 *
	 * @param array<string,mixed> $feed Feed.
	 * @return list<string>
	 */
	private function keys( array $feed ): array {
		return array_map( static fn( array $row ): string => $row['type'] . '-' . $row['id'], $this->feeds->rows( $feed )['rows'] );
	}

	/**
	 * Feed als iCalendar-Text.
	 *
	 * @param array<string,mixed> $feed Feed.
	 */
	private function ics( array $feed ): string {
		$data = $this->feeds->rows( $feed );
		return ( new IcsWriter() )->calendar(
			EventFactory::name( $feed ),
			( new EventFactory( $this->urls ) )->events( $data['rows'] ),
			new \DateTimeImmutable( $data['generated'], new \DateTimeZone( 'UTC' ) ),
			EventFactory::description( $feed )
		);
	}

	public function test_unknown_feeds_resolve_to_null(): void {
		self::assertNull( $this->feeds->team( '2026-27', 'gibt-es-nicht' ) );
		self::assertNull( $this->feeds->team( '1999-00', 'seefeld-1' ) );
		self::assertNull( $this->feeds->team( '2026/27', 'seefeld-1' ) );
		self::assertNull( $this->feeds->club( 'gibt-es-nicht' ) );
		self::assertNull( $this->feeds->player( 99999 ) );
		self::assertNull( $this->feeds->player( 0 ) );
	}

	public function test_team_feed_contains_the_whole_season(): void {
		$feed = $this->feeds->team( '2026-27', 'seefeld-1' );
		self::assertNotNull( $feed );
		self::assertSame( 'Seefeld 1', $feed['name'] );
		self::assertSame( 'Gruppe A', $feed['group'] );
		self::assertSame( 'seefeld-1-2026-27.ics', Feeds::filename( $feed ) );
		self::assertSame( 'Seefeld 1 – Spiele 2026/27', EventFactory::name( $feed ) );
		self::assertSame(
			array( 'fixture-' . $this->ids['fixture_1'], 'fixture-' . $this->ids['fixture_4'], 'fixture-' . $this->ids['fixture_5'], 'fixture-' . $this->ids['fixture_7'] ),
			$this->keys( $feed ),
			'vergangene und kommende Begegnungen, chronologisch'
		);
		self::assertMatchesRegularExpression( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $this->feeds->rows( $feed )['generated'] );

		$ics = str_replace( "\r\n ", '', $this->ics( $feed ) );
		self::assertSame( 4, substr_count( $ics, 'BEGIN:VEVENT' ) );
		self::assertStringContainsString( 'UID:fixture-' . $this->ids['fixture_1'] . "@example.org\r\n", $ics );
		self::assertStringContainsString( "DTSTART;TZID=Europe/Zurich:20260908T200000\r\nDTEND;TZID=Europe/Zurich:20260908T223000\r\n", $ics );
		self::assertStringContainsString( "SUMMARY:Seefeld 1 – Altstetten 1 (Gruppe A) 7:3\r\n", $ics );
		self::assertStringContainsString( "SUMMARY:Lindenhof 1 – Seefeld 1 (Gruppe A) 0:10 Forfait\r\n", $ics );
		self::assertStringContainsString( "SUMMARY:Seefeld 1 – Bergwiese 1 (Gruppe A) 4:6\r\n", $ics );
		self::assertStringContainsString( "SUMMARY:Bergwiese 1 – Seefeld 1 (Gruppe A)\r\n", $ics );
		self::assertStringContainsString( 'LOCATION:Sporthalle Bergwiese\, Musterstrasse 1\, 8000 Musterstadt', $ics );
		self::assertStringContainsString( 'URL:https://example.org/begegnung/' . $this->ids['fixture_7'] . '/', $ics );
		self::assertStringContainsString( 'Matchreport: https://example.org/begegnung/' . $this->ids['fixture_7'] . '/', $ics );
		self::assertStringContainsString( 'X-WR-CALNAME:Seefeld 1 – Spiele 2026/27', $ics );

		$old = $this->feeds->team( '2025-26', 'seefeld-1' );
		self::assertSame( array( 'fixture-' . $this->ids['fixture_old'] ), $this->keys( $old ), 'Feed einer archivierten Saison' );
	}

	public function test_cancelled_undated_and_open_time_fixtures(): void {
		$base      = array(
			'home_team_id' => $this->ids['team_see'],
			'away_team_id' => $this->ids['team_lind'],
			'leg'          => 2,
		);
		$open      = $this->seeder->fixture(
			$this->ids['group_a'],
			$base + array(
				'scheduled_at' => '2027-01-12 00:00:00',
				'status'       => 'scheduled',
			)
		);
		$cancelled = $this->seeder->fixture(
			$this->ids['group_a'],
			$base + array(
				'scheduled_at' => '2027-01-19 20:00:00',
				'status'       => 'cancelled',
			)
		);
		$this->seeder->fixture(
			$this->ids['group_a'],
			$base + array(
				'scheduled_at' => null,
				'status'       => 'scheduled',
			)
		);

		$feed = $this->feeds->team( '2026-27', 'seefeld-1' );
		self::assertCount( 6, $this->keys( $feed ), 'ohne Datum kein Ereignis' );
		$ics = str_replace( "\r\n ", '', $this->ics( $feed ) );
		self::assertStringContainsString( "UID:fixture-{$open}@example.org\r\nDTSTAMP:", $ics );
		self::assertStringContainsString( "DTSTART;VALUE=DATE:20270112\r\nDTEND;VALUE=DATE:20270113\r\n", $ics );
		self::assertStringContainsString( 'Anspielzeit noch offen.', $ics );
		self::assertMatchesRegularExpression( "/UID:fixture-{$cancelled}@example\\.org\r\n(?:[^\r]*\r\n)*?STATUS:CANCELLED\r\n/", $ics );
		self::assertStringContainsString( 'SUMMARY:Annulliert: Seefeld 1 – Lindenhof 1 (Gruppe A)', $ics );
	}

	public function test_club_feed_contains_all_teams_and_cup_of_the_active_season(): void {
		$feed = $this->feeds->club( 'seefeld' );
		self::assertNotNull( $feed );
		self::assertSame( '2026-27', $feed['season']['slug'] );
		self::assertSame( 'TTC Seefeld – Spiele 2026/27', EventFactory::name( $feed ) );
		self::assertSame( 'seefeld-2026-27.ics', Feeds::filename( $feed ) );
		$keys = $this->keys( $feed );
		self::assertCount( 7, $keys, 'Seefeld 1 (4), Seefeld 2 (2), Cup (1)' );
		self::assertContains( 'fixture-' . $this->ids['fixture_b2'], $keys );
		self::assertContains( 'fixture-' . $this->ids['cup_fixture'], $keys );
		self::assertNotContains( 'fixture-' . $this->ids['fixture_old'], $keys, 'nur die aktive Saison' );

		$ics = str_replace( "\r\n ", '', $this->ics( $feed ) );
		self::assertStringContainsString( "SUMMARY:Seefeld 1 – Bergwiese 1 (Mannschaftscup A) 3:1\r\n", $ics );
		self::assertStringContainsString( "DTSTART;TZID=Europe/Zurich:20261020T200000\r\nDTEND;TZID=Europe/Zurich:20261020T220000\r\n", $ics, 'Cup 2 Stunden' );
		self::assertStringContainsString( 'CATEGORIES:Cup', $ics );
	}

	public function test_player_feed_with_teams_cup_and_ranking_groups(): void {
		$feed = $this->feeds->player( 90001 );
		self::assertNotNull( $feed );
		self::assertSame( 'Hans Muster – Spiele 2026/27', EventFactory::name( $feed ) );
		self::assertSame( '90001-hans-muster-2026-27.ics', Feeds::filename( $feed ) );
		self::assertSame(
			array(
				'fixture-' . $this->ids['fixture_1'],
				'fixture-' . $this->ids['fixture_4'],
				'rl_group-' . $this->ids['rl_group_1'],
				'fixture-' . $this->ids['fixture_5'],
				'fixture-' . $this->ids['cup_fixture'],
				'rl_group-' . ( $this->ids['rl_group_1'] + 1 ),
				'fixture-' . $this->ids['fixture_7'],
			),
			$this->keys( $feed )
		);

		$ics = str_replace( "\r\n ", '', $this->ics( $feed ) );
		self::assertStringContainsString( 'UID:rlgroup-' . $this->ids['rl_group_1'] . "@example.org\r\n", $ics );
		self::assertStringContainsString( "DTSTART;TZID=Europe/Zurich:20260923T191500\r\nDTEND;TZID=Europe/Zurich:20260923T221500\r\n", $ics, 'RL 3 Stunden' );
		self::assertStringContainsString( 'SUMMARY:Ranglistenturnier: 1. Runde\, Kategorie 1\, Gruppe 1', $ics );
		self::assertStringContainsString( 'LOCATION:Sporthalle Bergwiese\, Musterstrasse 1\, 8000 Musterstadt', $ics );
		self::assertStringContainsString( 'URL:https://example.org/ranglistenturnier/2026-27/', $ics );
	}

	public function test_player_feed_follows_substitute_appearances(): void {
		// Sara Vorlage ist nicht gemeldet, spielte aber als Ersatz für Seefeld 1.
		$feed = $this->feeds->player( 90004 );
		self::assertSame(
			array( 'fixture-' . $this->ids['fixture_1'], 'fixture-' . $this->ids['fixture_4'], 'fixture-' . $this->ids['fixture_5'], 'fixture-' . $this->ids['fixture_7'] ),
			$this->keys( $feed )
		);

		// Jürg Müsterli ist gemeldet, aber ohne Team: leerer, gültiger Kalender.
		$empty = $this->feeds->player( 90016 );
		self::assertNotNull( $empty );
		self::assertSame( array(), $this->keys( $empty ) );
		self::assertStringContainsString( "END:VTIMEZONE\r\nEND:VCALENDAR\r\n", $this->ics( $empty ) );
	}
}
