<?php
declare(strict_types=1);

namespace TTLeague\Tests\Unit\Domain;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TTLeague\Domain\Ranking\GroupGameOrder;

final class GroupGameOrderTest extends TestCase {

	/**
	 * @return iterable<string,array{0:int}>
	 */
	public static function sizes(): iterable {
		for ( $size = 2; $size <= 8; $size++ ) {
			yield $size . ' Personen' => array( $size );
		}
	}

	#[DataProvider( 'sizes' )]
	public function test_everyone_plays_everyone_once( int $size ): void {
		$games = GroupGameOrder::for_size( $size );

		self::assertCount( intdiv( $size * ( $size - 1 ), 2 ), $games );
		self::assertSame( range( 1, count( $games ) ), array_column( $games, 'sequence' ) );
		$pairs = array();
		foreach ( $games as $game ) {
			self::assertLessThan( $game['b'], $game['a'], 'Die kleinere Position steht zuerst.' );
			self::assertGreaterThanOrEqual( 1, $game['a'] );
			self::assertLessThanOrEqual( $size, $game['b'] );
			$pairs[] = $game['a'] . '-' . $game['b'];
		}
		self::assertCount( count( $games ), array_unique( $pairs ), 'Keine Paarung kommt doppelt vor.' );
	}

	#[DataProvider( 'sizes' )]
	public function test_nobody_plays_twice_in_one_pass( int $size ): void {
		// Ein Durchgang hat so viele Partien, wie Tische gleichzeitig belegt sind.
		$per_pass = intdiv( $size, 2 );
		foreach ( array_chunk( GroupGameOrder::for_size( $size ), $per_pass ) as $pass ) {
			$players = array_merge( array_column( $pass, 'a' ), array_column( $pass, 'b' ) );
			self::assertCount( count( $players ), array_unique( $players ) );
		}
	}

	public function test_groups_below_two_have_no_games(): void {
		self::assertSame( array(), GroupGameOrder::for_size( 1 ) );
		self::assertSame( array(), GroupGameOrder::for_size( 0 ) );
	}

	public function test_order_for_players_uses_their_position_on_the_sheet(): void {
		$games = GroupGameOrder::for_players( array( 41, 17, 23 ) );

		self::assertCount( 3, $games );
		$positions = GroupGameOrder::for_size( 3 );
		$players   = array(
			1 => 41,
			2 => 17,
			3 => 23,
		);
		foreach ( $games as $i => $game ) {
			self::assertSame( $positions[ $i ]['sequence'], $game['sequence'] );
			self::assertSame( $players[ $positions[ $i ]['a'] ], $game['a'] );
			self::assertSame( $players[ $positions[ $i ]['b'] ], $game['b'] );
		}
	}

	public function test_order_is_stable(): void {
		self::assertSame( GroupGameOrder::for_size( 6 ), GroupGameOrder::for_size( 6 ) );
		// Sechsergruppe: 15 Partien in fünf Durchgängen an drei Tischen.
		self::assertCount( 15, GroupGameOrder::for_size( 6 ) );
		self::assertCount( 21, GroupGameOrder::for_size( 7 ) );
	}
}
