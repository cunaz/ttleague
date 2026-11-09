<?php
/**
 * Tests der Verlaufsgrafik.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Tests\Unit\Frontend;

use PHPUnit\Framework\TestCase;
use TTLeague\Frontend\Views\RatingChart;

final class RatingChartTest extends TestCase {

	public static function setUpBeforeClass(): void {
		require_once __DIR__ . '/Stubs/WordPress.php';
	}

	/**
	 * @return list<array{slug:string,name:string,start_date:string,end_date:string}>
	 */
	private static function seasons(): array {
		return array(
			array(
				'slug'       => '2026-27',
				'name'       => '2026/27',
				'start_date' => '2026-08-01',
				'end_date'   => '2027-07-31',
			),
			array(
				'slug'       => '2025-26',
				'name'       => '2025/26',
				'start_date' => '2025-08-01',
				'end_date'   => '2026-07-31',
			),
		);
	}

	public function test_days_aggregate_events_per_date_and_season(): void {
		$history = array(
			array(
				'at'      => '2026-03-10 20:00:00',
				'before'  => 1100.0,
				'after'   => 1091.5,
				'delta'   => -8.5,
				'game_id' => 1,
			),
			array(
				'at'      => '2026-03-10 20:00:00',
				'before'  => 1091.5,
				'after'   => 1097.5,
				'delta'   => 6.0,
				'game_id' => 2,
			),
			array(
				'at'      => '2026-09-08 20:00:00',
				'before'  => 1200.0,
				'after'   => 1209.0,
				'delta'   => 9.0,
				'game_id' => 3,
			),
		);
		$days    = RatingChart::days( $history, self::seasons() );
		self::assertCount( 2, $days );
		self::assertSame( '2025-26', $days[0]['season'] );
		self::assertSame( 1100.0, $days[0]['start'] );
		self::assertSame( 1097.5, $days[0]['value'] );
		self::assertSame( -2.5, $days[0]['delta'] );
		self::assertSame( 2, $days[0]['games'] );
		self::assertSame( '2026/27', $days[1]['season_name'] );
		self::assertSame( 1200.0, $days[1]['start'], 'Neuverankerung zu Saisonbeginn' );

		$parts = RatingChart::parts( $days, 'chart', 'Titel', 'Beschreibung' );
		self::assertSame( 2, substr_count( $parts['svg'], '<polyline' ), 'eine Linie pro Saison' );
		self::assertSame( 1, substr_count( $parts['svg'], 'ttl-chart__boundary' ), 'eine Saisongrenze' );
		self::assertSame( 2, substr_count( $parts['svg'], '<title>' ), 'ein Hover-Band pro Spieltag' );
		self::assertStringContainsString( 'Di, 8.9.2026: 1209 (+9,0, 1 Spiel)', $parts['svg'] );
		self::assertStringContainsString( 'aria-labelledby="chart-title"', $parts['html'] );
		self::assertStringContainsString( '25/26', $parts['html'] );
		self::assertStringContainsString( '{svg}', $parts['html'] );
		self::assertStringContainsString( '>1209<', $parts['html'] );
	}

	public function test_empty_history_renders_nothing(): void {
		self::assertSame( array(), RatingChart::days( array(), self::seasons() ) );
		self::assertSame(
			array(
				'svg'  => '',
				'html' => '',
			),
			RatingChart::parts( array(), 'x', 't', 'd' )
		);
	}
}
