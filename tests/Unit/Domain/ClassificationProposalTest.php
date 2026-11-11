<?php
declare(strict_types=1);

namespace TTLeague\Tests\Unit\Domain;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TTLeague\Domain\Rating\ClassificationProposal;
use TTLeague\Domain\Rules\SeasonRules;

final class ClassificationProposalTest extends TestCase {

	/**
	 * @return array<string,array{0:float,1:int}>
	 */
	public static function ratings(): array {
		return array(
			'Mitte der Stufe'               => array( 700.0, 7 ),
			'knapp unter der Stufengrenze'  => array( 649.99, 6 ),
			'genau auf der Stufengrenze'    => array( 650.0, 7 ),
			'unteres Ende der Skala'        => array( 149.99, 1 ),
			'Wertungszahl 0 bleibt auf D1'  => array( 0.0, 1 ),
			'oberes Ende der Skala'         => array( 2150.0, 22 ),
			'knapp unter A22'               => array( 2149.99, 21 ),
			'über der Skala bleibt auf A22' => array( 2680.0, 22 ),
		);
	}

	#[DataProvider( 'ratings' )]
	public function test_proposal_follows_the_rating_within_the_scale( float $rating, int $expected ): void {
		self::assertSame( $expected, ClassificationProposal::propose( 10, $rating, 40, new SeasonRules() ) );
	}

	public function test_exactly_the_minimum_of_games_is_enough_and_one_less_is_not(): void {
		$rules = new SeasonRules( classification_min_games: 10 );

		self::assertSame( 8, ClassificationProposal::propose( 6, 800.0, 10, $rules ), 'genau zehn Spiele: Vorschlag aus der Wertungszahl' );
		self::assertSame( 6, ClassificationProposal::propose( 6, 800.0, 9, $rules ), 'neun Spiele: bisherige Klassierung' );
	}

	public function test_minimum_of_games_is_a_parameter_of_the_season(): void {
		self::assertSame( 8, ClassificationProposal::propose( 6, 800.0, 3, new SeasonRules( classification_min_games: 3 ) ) );
		self::assertSame( 6, ClassificationProposal::propose( 6, 800.0, 3, new SeasonRules( classification_min_games: 4 ) ) );
		self::assertSame( 8, ClassificationProposal::propose( 6, 800.0, 0, new SeasonRules( classification_min_games: 0 ) ), 'ohne Mindestzahl gilt immer die Wertungszahl' );
	}

	public function test_player_without_rating_keeps_the_classification(): void {
		self::assertSame( 6, ClassificationProposal::propose( 6, null, 0, new SeasonRules() ) );
		self::assertSame( 6, ClassificationProposal::propose( 6, null, 25, new SeasonRules( classification_min_games: 0 ) ), 'auch ohne Mindestzahl an Spielen' );
	}

	public function test_points_per_class_come_from_the_rules(): void {
		$rules = new SeasonRules( rating_points_per_class: 50.0 );

		self::assertSame( 14, ClassificationProposal::propose( 6, 700.0, 10, $rules ) );
	}

	public function test_deviation_is_signed(): void {
		self::assertSame( 2, ClassificationProposal::deviation( 6, 8 ) );
		self::assertSame( -1, ClassificationProposal::deviation( 6, 5 ) );
		self::assertSame( 0, ClassificationProposal::deviation( 6, 6 ) );
	}
}
