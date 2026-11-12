<?php
/**
 * Tests der strukturellen Prüfung einer Resultaterfassung.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Tests\Unit\Results;

use PHPUnit\Framework\TestCase;
use TTLeague\Results\ResultException;
use TTLeague\Results\ResultInput;

final class ResultInputTest extends TestCase {

	private const HOME = array( 'A', 'B', 'C' );
	private const AWAY = array( 'X', 'Y', 'Z' );

	public static function setUpBeforeClass(): void {
		require_once dirname( __DIR__ ) . '/Frontend/Stubs/WordPress.php';
	}

	private static function parse( array $data ): ResultInput {
		return ResultInput::from_array( $data, self::HOME, self::AWAY, range( 1, 10 ) );
	}

	/**
	 * @return list<array{field:string,message:string}>
	 */
	private static function errors( array $data ): array {
		try {
			self::parse( $data );
		} catch ( ResultException $e ) {
			self::assertSame( 422, $e->status );
			return $e->errors;
		}
		self::fail( 'Fehler erwartet' );
	}

	public function test_parses_lineup_and_games(): void {
		$input = self::parse(
			array(
				'lineup'  => array(
					'home' => array(
						'A' => 7,
						'b' => '8',
						'C' => null,
					),
					'away' => array(
						'X' => 11,
						'Y' => 12,
						'Z' => 13,
					),
				),
				'games'   => array(
					array(
						'sequence'   => 1,
						'status'     => 'played',
						'sets_home'  => 3,
						'sets_away'  => 1,
						'set_points' => array( array( 11, 8 ), array( 9, 11 ), array( 11, 5 ), array( 11, 7 ) ),
					),
					array(
						'sequence' => 3,
						'status'   => 'walkover',
						'winner'   => 'away',
					),
					array(
						'sequence'     => 7,
						'sets_home'    => '2',
						'sets_away'    => 3,
						'home_players' => array( 7, 8 ),
						'away_players' => array( '11', 12 ),
					),
				),
				'comment' => '  Gutes Spiel  ',
				'version' => 'abc',
			)
		);

		self::assertSame(
			array(
				'A' => 7,
				'B' => 8,
				'C' => null,
			),
			$input->home
		);
		self::assertSame(
			array(
				'A' => 7,
				'B' => 8,
			),
			$input->present( 'home' )
		);
		self::assertSame( array( 1, 3, 7 ), array_keys( $input->games ) );
		self::assertSame( 'walkover', $input->games[3]->status );
		self::assertSame( 'away', $input->games[3]->winner );
		self::assertSame( 'played', $input->games[7]->status, 'Status played ist Standard' );
		self::assertSame( 2, $input->games[7]->sets_home );
		self::assertSame( array( 11, 12 ), $input->games[7]->away_players );
		self::assertCount( 4, $input->games[1]->set_points );
		self::assertSame( 'Gutes Spiel', $input->comment );
		self::assertSame( 'abc', $input->version );
	}

	public function test_reports_structural_errors_with_fields(): void {
		$errors = self::errors(
			array(
				'lineup' => array(
					'home' => array(
						'D' => 1,
						'A' => 'x',
					),
					'away' => 'kaputt',
				),
				'games'  => array(
					array( 'sequence' => 11 ),
					array(
						'sequence' => 2,
						'status'   => 'gewonnen',
					),
					array(
						'sequence' => 4,
						'status'   => 'walkover',
					),
					array(
						'sequence'  => 5,
						'sets_home' => 3,
					),
					array(
						'sequence'  => 6,
						'sets_home' => 3,
						'sets_away' => 0,
					),
					array(
						'sequence'  => 6,
						'sets_home' => 3,
						'sets_away' => 0,
					),
				),
			)
		);
		$fields = array_column( $errors, 'field' );
		self::assertContains( 'lineup.home.D', $fields );
		self::assertContains( 'lineup.home.A', $fields );
		self::assertContains( 'lineup.away', $fields );
		self::assertContains( 'games.0.sequence', $fields );
		self::assertContains( 'games.2.status', $fields );
		self::assertContains( 'games.4.winner', $fields );
		self::assertContains( 'games.5', $fields );
		self::assertContains( 'games.6', $fields, 'doppelt erfasst' );
	}

	public function test_missing_parts(): void {
		$fields = array_column( self::errors( array() ), 'field' );
		self::assertContains( 'lineup', $fields );
		self::assertContains( 'games', $fields );
	}

	public function test_photo_id_is_ignored(): void {
		foreach ( array( 'abc', 0, -3, array( 5 ), 77 ) as $photo ) {
			$input = self::parse(
				array(
					'lineup'   => array(),
					'games'    => array(),
					'photo_id' => $photo,
				)
			);
			self::assertFalse( property_exists( $input, 'photo_id' ), 'das Foto verknüpft nur POST fixtures/{id}/photo' );
		}
	}

	public function test_comment_length(): void {
		$fields = array_column(
			self::errors(
				array(
					'lineup'  => array(),
					'games'   => array(),
					'comment' => str_repeat( 'x', 2001 ),
				)
			),
			'field'
		);
		self::assertSame( array( 'comment' ), $fields );
	}

	public function test_ids_must_fit_in_an_integer_without_coercion(): void {
		self::assertSame( PHP_INT_MAX, ResultInput::int( (string) PHP_INT_MAX ) );
		foreach ( array( '9999999999999999999', (string) PHP_INT_MAX . '0', "12\n", '1.0', '1e2', true, 1.0, array( 1 ), '01', '-1', '0' ) as $invalid ) {
			self::assertNull( ResultInput::int( $invalid ) );
		}
	}

	public function test_overflowing_player_ids_are_reported_at_the_lineup_field(): void {
		$errors = self::errors(
			array(
				'lineup' => array( 'home' => array( 'A' => '9999999999999999999' ) ),
				'games'  => array(),
			)
		);
		self::assertSame( array( 'lineup.home.A' ), array_column( $errors, 'field' ) );
	}
}
