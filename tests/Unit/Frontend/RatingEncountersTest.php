<?php
/**
 * Gruppierung des Wertungsverlaufs.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Tests\Unit\Frontend;

use PHPUnit\Framework\TestCase;
use TTLeague\Frontend\Views\RatingEncounters;

final class RatingEncountersTest extends TestCase {

	private function game( int $id, string $kind, ?int $source, string $season = '2026/27' ): array {
		return array(
			'id'          => $id,
			'kind'        => $kind,
			'fixture_id'  => 'RL' === $kind ? null : $source,
			'rl_group_id' => 'RL' === $kind ? $source : null,
			'season_slug' => $season,
			'season_name' => $season,
		);
	}

	private function event( int $id, float $before, float $after ): array {
		return array(
			'game_id' => $id,
			'at'      => '2026-10-01 20:00:00',
			'before'  => $before,
			'after'   => $after,
			'delta'   => $after - $before,
		);
	}

	public function test_groups_chronologically_by_fixture_or_tournament_group_even_on_the_same_date(): void {
		$games   = array(
			$this->game( 1, 'MM', 8 ),
			$this->game( 2, 'MM', 8 ),
			$this->game( 3, 'RL', 8 ),
			$this->game( 4, 'RL', 8 ),
			$this->game( 5, 'RL', 9 ),
			$this->game( 6, 'MM', 8 ),
			$this->game( 7, 'CUP', 10 ),
			$this->game( 8, 'CUP', 10 ),
		);
		$history = array(
			$this->event( 1, 100, 105 ),
			$this->event( 2, 105, 103 ),
			$this->event( 3, 103, 104 ),
			$this->event( 4, 104, 105 ),
			$this->event( 5, 105, 106 ),
			$this->event( 6, 106, 107 ),
			$this->event( 7, 107, 108 ),
			$this->event( 8, 108, 109 ),
		);
		$points  = RatingEncounters::group( $history, array_reverse( $games ) );
		self::assertSame( array( 2, 2, 1, 1, 2 ), array_map( 'count', array_column( $points, 'games' ) ) );
		self::assertSame( array( 1, 2 ), array_column( $points[0]['games'], 'id' ) );
		self::assertSame( 100.0, $points[0]['before'] );
		self::assertSame( 103.0, $points[0]['after'] );
		self::assertSame( 3.0, $points[0]['delta'] );
	}

	public function test_unknown_games_sources_season_changes_and_rating_resets_separate_points(): void {
		$games   = array(
			$this->game( 1, 'MM', 8 ),
			$this->game( 3, 'MM', 8 ),
			$this->game( 4, 'MM', 8 ),
			$this->game( 5, 'MM', 8, '2027/28' ),
			$this->game( 6, 'RL', null ),
			$this->game( 7, 'RL', null ),
		);
		$history = array(
			$this->event( 1, 100, 105 ),
			$this->event( 2, 105, 105 ),
			$this->event( 3, 105, 107 ),
			$this->event( 4, 120, 121 ),
			$this->event( 5, 121, 122 ),
			$this->event( 6, 122, 123 ),
			$this->event( 7, 123, 124 ),
		);
		$points  = RatingEncounters::group( $history, $games );
		self::assertSame( array( 105.0, 107.0, 121.0, 122.0, 123.0, 124.0 ), array_column( $points, 'after' ) );
		self::assertSame( array( 1, 1, 1, 1, 1, 1 ), array_map( 'count', array_column( $points, 'games' ) ) );
	}
}
