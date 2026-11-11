<?php
/**
 * Name, Kürzel und Daten einer Saison, Reihenfolge der Meldedaten.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Tests\Unit\Domain;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TTLeague\Domain\Season\SeasonDates;

final class SeasonDatesTest extends TestCase {

	public function test_the_following_season_starts_one_year_later(): void {
		self::assertSame(
			array(
				'name'       => '2027/28',
				'slug'       => '2027-28',
				'start_date' => '2027-08-01',
				'end_date'   => '2028-07-31',
			),
			SeasonDates::following( '2026-08-01', '2027-07-31' )
		);
	}

	public function test_the_name_wraps_at_the_turn_of_the_century(): void {
		$next = SeasonDates::following( '2098-08-01', '2099-07-31' );
		self::assertSame( '2099/00', $next['name'] );
		self::assertSame( '2099-00', $next['slug'] );
	}

	public function test_a_leap_day_becomes_the_last_day_of_february(): void {
		self::assertSame( '2029-02-28', SeasonDates::following( '2027-08-01', '2028-02-29' )['end_date'] );
	}

	public function test_the_following_season_needs_valid_dates(): void {
		$this->expectException( \InvalidArgumentException::class );
		SeasonDates::following( '2026-08-01', '2027-02-30' );
	}

	public function test_the_first_season_is_proposed_from_the_next_first_of_august(): void {
		self::assertSame( '2027/28', SeasonDates::upcoming( '2027-05-10' )['name'] );
		self::assertSame( '2027/28', SeasonDates::upcoming( '2027-08-01' )['name'] );
		self::assertSame( '2028/29', SeasonDates::upcoming( '2027-08-02' )['name'] );
		self::assertSame( '2028-07-31', SeasonDates::upcoming( '2027-08-01' )['end_date'] );
	}

	public function test_a_valid_new_season_has_no_errors(): void {
		self::assertSame( array(), SeasonDates::errors( '2027/28', '2027-08-01', '2028-07-31' ) );
		self::assertSame( '2027-28', SeasonDates::slug_of_name( '2027/28' ) );
	}

	/**
	 * @return array<string,array{0:string,1:string,2:string,3:string}>
	 */
	public static function invalid_seasons(): array {
		return array(
			'Name ohne Schrägstrich'   => array( '2027-28', '2027-08-01', '2028-07-31', 'name' ),
			'zweites Jahr passt nicht' => array( '2027/29', '2027-08-01', '2028-07-31', 'name' ),
			'Beginn kein Datum'        => array( '2027/28', '2027-13-01', '2028-07-31', 'start_date' ),
			'Ende kein Datum'          => array( '2027/28', '2027-08-01', '31.07.2028', 'end_date' ),
			'Ende am Tag des Beginns'  => array( '2027/28', '2027-08-01', '2027-08-01', 'end_date' ),
			'länger als ein Jahr'      => array( '2027/28', '2027-08-01', '2028-08-02', 'end_date' ),
			'Beginn im falschen Jahr'  => array( '2027/28', '2028-01-01', '2028-07-31', 'start_date' ),
		);
	}

	#[DataProvider( 'invalid_seasons' )]
	public function test_invalid_new_seasons_name_the_field( string $name, string $start, string $end, string $field ): void {
		$errors = SeasonDates::errors( $name, $start, $end );
		self::assertNotSame( array(), $errors );
		self::assertContains( $field, array_column( $errors, 'field' ) );
	}

	public function test_a_season_may_last_exactly_one_year(): void {
		self::assertSame( array(), SeasonDates::errors( '2027/28', '2027-08-01', '2028-08-01' ) );
	}

	public function test_an_invalid_name_has_no_slug(): void {
		self::assertNull( SeasonDates::slug_of_name( 'Saison 27' ) );
	}

	public function test_the_window_may_be_empty_or_a_single_day(): void {
		self::assertSame( array(), SeasonDates::window_errors( null, null, null, '2027-08-01', '2028-07-31' ) );
		self::assertSame( array(), SeasonDates::window_errors( '2027-05-01', '2027-05-01', null, '2027-08-01', '2028-07-31' ) );
		self::assertSame( array(), SeasonDates::window_errors( '2027-05-01', null, '2028-01-31', '2027-08-01', '2028-07-31' ) );
	}

	public function test_the_window_must_not_close_before_it_opens(): void {
		$errors = SeasonDates::window_errors( '2027-05-02', '2027-05-01', null, '2027-08-01', '2028-07-31' );
		self::assertSame( array( 'registration_closes' ), array_column( $errors, 'field' ) );
	}

	public function test_a_closing_day_needs_an_opening_day(): void {
		$errors = SeasonDates::window_errors( null, '2027-05-31', null, '2027-08-01', '2028-07-31' );
		self::assertSame( array( 'registration_opens' ), array_column( $errors, 'field' ) );
	}

	public function test_the_window_closes_before_the_season_ends(): void {
		self::assertSame( array(), SeasonDates::window_errors( '2027-05-01', '2028-07-31', null, '2027-08-01', '2028-07-31' ) );
		$errors = SeasonDates::window_errors( '2027-05-01', '2028-08-01', null, '2027-08-01', '2028-07-31' );
		self::assertSame( array( 'registration_closes' ), array_column( $errors, 'field' ) );
	}

	public function test_late_registration_ends_within_the_season(): void {
		self::assertSame( array(), SeasonDates::window_errors( null, null, '2027-08-01', '2027-08-01', '2028-07-31' ) );
		self::assertSame( array(), SeasonDates::window_errors( null, null, '2028-07-31', '2027-08-01', '2028-07-31' ) );
		foreach ( array( '2027-07-31', '2028-08-01' ) as $outside ) {
			$errors = SeasonDates::window_errors( null, null, $outside, '2027-08-01', '2028-07-31' );
			self::assertSame( array( 'late_registration_until' ), array_column( $errors, 'field' ), $outside );
		}
	}

	public function test_late_registration_does_not_end_before_the_window(): void {
		self::assertSame( array(), SeasonDates::window_errors( '2027-08-01', '2027-09-30', '2027-09-30', '2027-08-01', '2028-07-31' ) );
		$errors = SeasonDates::window_errors( '2027-08-01', '2027-09-30', '2027-09-29', '2027-08-01', '2028-07-31' );
		self::assertSame( array( 'late_registration_until' ), array_column( $errors, 'field' ) );
	}

	public function test_invalid_dates_are_reported_at_their_field(): void {
		$errors = SeasonDates::window_errors( '2027-02-30', 'morgen', '2028-01-31', '2027-08-01', '2028-07-31' );
		self::assertSame( array( 'registration_opens', 'registration_closes' ), array_column( $errors, 'field' ) );
	}
}
