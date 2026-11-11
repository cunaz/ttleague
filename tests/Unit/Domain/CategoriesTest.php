<?php
declare(strict_types=1);

namespace TTLeague\Tests\Unit\Domain;

use PHPUnit\Framework\TestCase;
use TTLeague\Domain\Ranking\Categories;

final class CategoriesTest extends TestCase {

	private const THREE = array(
		array(
			'no'  => 3,
			'min' => 1,
		),
		array(
			'no'  => 1,
			'min' => 16,
		),
		array(
			'no'  => 2,
			'min' => 9,
		),
	);

	public function test_highest_matching_limit_wins(): void {
		// Genau an den Grenzen: 16 ist Kategorie 1, 15 noch Kategorie 2; 9 ist Kategorie 2, 8 Kategorie 3.
		self::assertSame( 1, Categories::for_classification( 20, self::THREE ) );
		self::assertSame( 1, Categories::for_classification( 16, self::THREE ) );
		self::assertSame( 2, Categories::for_classification( 15, self::THREE ) );
		self::assertSame( 2, Categories::for_classification( 9, self::THREE ) );
		self::assertSame( 3, Categories::for_classification( 8, self::THREE ) );
		self::assertSame( 3, Categories::for_classification( 1, self::THREE ) );
		self::assertSame( Categories::NONE, Categories::for_classification( 0, self::THREE ) );
	}

	public function test_without_categories_there_is_none(): void {
		self::assertSame( Categories::NONE, Categories::for_classification( 12, array() ) );
		self::assertSame( array(), Categories::normalize( null ) );
		self::assertSame( array(), Categories::normalize( 'kaputt' ) );
	}

	public function test_invalid_entries_are_dropped(): void {
		$categories = Categories::normalize(
			array(
				array(
					'no'  => '2',
					'min' => '5',
				),
				array( 'no' => 1 ),
				array(
					'no'  => 0,
					'min' => 3,
				),
				array(
					'no'  => 2,
					'min' => 1,
				),
				'x',
				array(
					'no'  => 4,
					'min' => 8,
				),
			)
		);

		self::assertSame(
			array(
				array(
					'no'  => 4,
					'min' => 8,
				),
				array(
					'no'  => 2,
					'min' => 5,
				),
			),
			$categories
		);
		self::assertSame( array( 2, 4 ), Categories::numbers( $categories ) );
	}
}
