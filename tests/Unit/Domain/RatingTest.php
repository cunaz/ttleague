<?php
declare(strict_types=1);

namespace TTLeague\Tests\Unit\Domain;

use PHPUnit\Framework\TestCase;
use TTLeague\Domain\Rating\ClassificationScale;
use TTLeague\Domain\Rating\EloRating;
use TTLeague\Domain\Rules\SeasonRules;

final class RatingTest extends TestCase {

	public function test_labels_follow_stt_scale(): void {
		self::assertSame( 'D1', ClassificationScale::label( 1 ) );
		self::assertSame( 'D5', ClassificationScale::label( 5 ) );
		self::assertSame( 'C6', ClassificationScale::label( 6 ) );
		self::assertSame( 'C10', ClassificationScale::label( 10 ) );
		self::assertSame( 'B11', ClassificationScale::label( 11 ) );
		self::assertSame( 'B15', ClassificationScale::label( 15 ) );
		self::assertSame( 'A16', ClassificationScale::label( 16 ) );
		self::assertSame( 'A22', ClassificationScale::label( 22 ) );
		self::assertSame( 'A22', ClassificationScale::label( 30 ), 'Werte ausserhalb werden begrenzt.' );
	}

	public function test_parse_accepts_letters_and_numbers(): void {
		self::assertSame( 5, ClassificationScale::parse( 'd5' ) );
		self::assertSame( 22, ClassificationScale::parse( 'A22' ) );
		self::assertSame( 7, ClassificationScale::parse( ' 7 ' ) );
	}

	public function test_parse_rejects_garbage(): void {
		$this->expectException( \InvalidArgumentException::class );
		ClassificationScale::parse( 'E3' );
	}

	public function test_parse_rejects_out_of_range(): void {
		$this->expectException( \InvalidArgumentException::class );
		ClassificationScale::parse( '23' );
	}

	public function test_expected_score_and_symmetry(): void {
		$elo = new EloRating( new SeasonRules( rating_scale: 400.0 ) );
		self::assertEqualsWithDelta( 0.5, $elo->expected( 600, 600 ), 1e-9 );
		self::assertEqualsWithDelta( 0.64, $elo->expected( 700, 600 ), 0.001 );
		self::assertEqualsWithDelta( 1.0, $elo->expected( 700, 600 ) + $elo->expected( 600, 700 ), 1e-9 );
	}

	public function test_single_update_is_zero_sum(): void {
		$elo       = new EloRating( new SeasonRules( rating_k: 24.0 ) );
		[ $a, $b ] = $elo->update_single( 600, 600, true );
		self::assertEqualsWithDelta( 612.0, $a, 1e-9 );
		self::assertEqualsWithDelta( 588.0, $b, 1e-9 );
		[ $a2, $b2 ] = $elo->update_single( 500, 700, true );
		self::assertGreaterThan( 12.0, $a2 - 500, 'Überraschungssieg bringt mehr als zwölf Punkte.' );
		self::assertEqualsWithDelta( 0.0, ( $a2 - 500 ) + ( $b2 - 700 ), 1e-9 );
	}

	public function test_double_uses_half_weight_and_pair_average(): void {
		$elo         = new EloRating( new SeasonRules( rating_k: 24.0, rating_doubles_weight: 0.5 ) );
		[ $pa, $pb ] = $elo->update_double( array( 600, 600 ), array( 600, 600 ), true );
		self::assertEqualsWithDelta( 606.0, $pa[0], 1e-9 );
		self::assertEqualsWithDelta( 606.0, $pa[1], 1e-9 );
		self::assertEqualsWithDelta( 594.0, $pb[0], 1e-9 );
	}

	public function test_default_scale_makes_one_class_difference_clear(): void {
		$elo = new EloRating( new SeasonRules() );
		self::assertEqualsWithDelta( 0.76, $elo->expected( 700, 600 ), 0.01 );
	}

	public function test_initial_rating_and_classification_round_trip(): void {
		$elo = new EloRating( new SeasonRules() );
		self::assertSame( 600.0, $elo->initial_rating( 6 ) );
		self::assertSame( 6, $elo->classification_for( 649.0 ) );
		self::assertSame( 7, $elo->classification_for( 650.0 ) );
		self::assertSame( 1, $elo->classification_for( 20.0 ) );
		self::assertSame( 22, $elo->classification_for( 5000.0 ) );
	}
}
