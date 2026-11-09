<?php
declare(strict_types=1);

namespace TTLeague\Tests\Unit\Domain;

use PHPUnit\Framework\TestCase;
use TTLeague\Domain\Rating\Calibration;
use TTLeague\Domain\Rating\RatingEngine;
use TTLeague\Domain\Rating\RatingGame;
use TTLeague\Domain\Rules\SeasonRules;

final class RatingEngineTest extends TestCase {

	private function rules(): SeasonRules {
		return new SeasonRules( rating_k: 16.0, rating_scale: 200.0, rating_doubles_weight: 0.5 );
	}

	public function test_starts_at_classification_and_is_zero_sum(): void {
		$classes = array(
			1 => array(
				10 => 6,
				11 => 6,
			),
		);
		$result  = ( new RatingEngine( $this->rules() ) )->run(
			array( new RatingGame( 1, '2026-09-01 20:00:00', 1, array( 10 ), array( 11 ), true ) ),
			static fn( int $p, int $s ) => $classes[ $s ][ $p ] ?? null
		);
		self::assertEqualsWithDelta( 608.0, $result->ratings[10], 1e-9 );
		self::assertEqualsWithDelta( 592.0, $result->ratings[11], 1e-9 );
		self::assertCount( 2, $result->events );
		self::assertEqualsWithDelta( 8.0, $result->events[0]->delta(), 1e-9 );
		self::assertSame(
			array(
				10 => 1,
				11 => 1,
			),
			$result->games
		);
	}

	public function test_reanchors_each_season_unless_disabled(): void {
		$classes = array(
			1 => array(
				10 => 6,
				11 => 6,
			),
			2 => array(
				10 => 7,
				11 => 5,
			),
		);
		$games   = array(
			new RatingGame( 1, '2025-09-01 20:00:00', 1, array( 10 ), array( 11 ), true ),
			new RatingGame( 2, '2026-09-01 20:00:00', 2, array( 10 ), array( 11 ), false ),
		);
		$lookup  = static fn( int $p, int $s ) => $classes[ $s ][ $p ] ?? null;

		$anchored = ( new RatingEngine( $this->rules(), true ) )->run( $games, $lookup );
		self::assertSame( 700.0, $anchored->events[2]->before, 'Neue Saison beginnt bei der neuen Klassierung.' );
		self::assertLessThan( 700.0, $anchored->ratings[10] );
		self::assertArrayHasKey( 1, $anchored->season_end );
		self::assertEqualsWithDelta( 608.0, $anchored->season_end[1][10], 1e-9 );

		$running = ( new RatingEngine( $this->rules(), false ) )->run( $games, $lookup );
		self::assertEqualsWithDelta( 608.0, $running->events[2]->before, 1e-9, 'Ohne Neuverankerung läuft der Wert weiter.' );
	}

	public function test_doubles_use_pair_average_and_weight(): void {
		$classes = array(
			1 => array(
				1 => 6,
				2 => 8,
				3 => 7,
				4 => 7,
			),
		);
		$result  = ( new RatingEngine( $this->rules() ) )->run(
			array( new RatingGame( 5, '2026-09-01 20:00:00', 1, array( 1, 2 ), array( 3, 4 ), true ) ),
			static fn( int $p, int $s ) => $classes[ $s ][ $p ] ?? null
		);
		self::assertEqualsWithDelta( 604.0, $result->ratings[1], 1e-9 );
		self::assertEqualsWithDelta( 804.0, $result->ratings[2], 1e-9 );
		self::assertEqualsWithDelta( 696.0, $result->ratings[3], 1e-9 );
		self::assertCount( 4, $result->events );
	}

	public function test_doubles_can_be_ignored(): void {
		$rules  = new SeasonRules( rating_k: 16.0, rating_scale: 200.0, rating_doubles_weight: 0.0 );
		$result = ( new RatingEngine( $rules ) )->run(
			array( new RatingGame( 5, '2026-09-01 20:00:00', 1, array( 1, 2 ), array( 3, 4 ), true ) ),
			static fn() => 6
		);
		self::assertSame( 600.0, $result->ratings[1] );
		self::assertSame( array(), $result->events );
		self::assertCount( 1, $result->predictions );
	}

	public function test_invalid_game_is_rejected(): void {
		$this->expectException( \InvalidArgumentException::class );
		new RatingGame( 1, '2026-09-01 20:00:00', 1, array( 1, 2 ), array( 3 ), true );
	}

	public function test_calibration_prefers_parameters_that_reproduce_promotions(): void {
		// Spieler 1 (Klasse 5) gewinnt zwölfmal gegen Spieler 2 (Klasse 6); in der Folgesaison tauschen sie die Klassierung.
		$games = array();
		for ( $i = 1; $i <= 12; $i++ ) {
			$games[] = new RatingGame( $i, sprintf( '2025-10-%02d 20:00:00', $i ), 1, array( 1 ), array( 2 ), true );
		}
		$classes     = array(
			1 => array(
				1 => 5,
				2 => 6,
			),
			2 => array(
				1 => 6,
				2 => 5,
			),
		);
		$calibration = new Calibration( $games, $classes, array( 1, 2 ) );
		$base        = $calibration->baseline();
		self::assertSame( 2, $base['n'] );
		self::assertSame( 0.0, $base['exact'] );

		$rows = $calibration->grid( array( 4.0, 16.0 ), array( 200.0 ), array( 0.5 ), array( true ) );
		self::assertSame( 16.0, $rows[0]['k'], 'Mit K=16 reicht die Siegesserie für den Wechsel.' );
		self::assertSame( 1.0, $rows[0]['exact'] );
		self::assertSame( 1.0, $rows[0]['changes_hit'] );
	}
}
