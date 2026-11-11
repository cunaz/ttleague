<?php
declare(strict_types=1);

namespace TTLeague\Tests\Unit\Classification;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TTLeague\Classification\ProposalFilter;

final class ProposalFilterTest extends TestCase {

	public function test_reads_the_parameters_of_a_request(): void {
		$filter = ProposalFilter::from_array(
			array(
				'club'      => '12',
				'deviating' => '1',
				'open'      => 'on',
				'page'      => 'ttleague-tk-klassierung',
			)
		);

		self::assertSame( 12, $filter->club_id );
		self::assertTrue( $filter->deviating );
		self::assertTrue( $filter->open );
		self::assertSame(
			array(
				'club'      => '12',
				'deviating' => '1',
				'open'      => '1',
			),
			$filter->to_args()
		);
	}

	/**
	 * @return array<string,array{0:array<string,mixed>}>
	 */
	public static function invalid(): array {
		return array(
			'nichts gesetzt'       => array( array() ),
			'leere Werte'          => array(
				array(
					'club'      => '',
					'deviating' => '',
					'open'      => '0',
				),
			),
			'Club keine Zahl'      => array( array( 'club' => '12 OR 1=1' ) ),
			'Club negativ'         => array( array( 'club' => '-3' ) ),
			'Felder als Liste'     => array(
				array(
					'club' => array( '12' ),
					'open' => array( '1' ),
				),
			),
			'unbekannter Schalter' => array( array( 'deviating' => 'vielleicht' ) ),
		);
	}

	/**
	 * @param array<string,mixed> $args Parameter.
	 */
	#[DataProvider( 'invalid' )]
	public function test_invalid_values_mean_no_filter( array $args ): void {
		$filter = ProposalFilter::from_array( $args );

		self::assertSame( 0, $filter->club_id );
		self::assertFalse( $filter->deviating );
		self::assertFalse( $filter->open );
		self::assertSame( array(), $filter->to_args() );
	}

	public function test_with_open_keeps_the_other_filters(): void {
		$filter = ( new ProposalFilter( 7, true ) )->with_open();

		self::assertSame(
			array(
				'club'      => '7',
				'deviating' => '1',
				'open'      => '1',
			),
			$filter->to_args()
		);
	}
}
