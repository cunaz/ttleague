<?php
/**
 * Zufallsfolge der Cup-Auslosung, aus dem Startwert reproduzierbar.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Domain\Cup;

/**
 * Eigener kleiner Generator (Xorshift, 32 Bit) statt der Zufallsfunktionen von PHP: Dieselbe
 * Auslosung muss sich mit ihrem Startwert auf jeder PHP-Version gleich wiederholen lassen. Für
 * Lose genügt die Güte; für Geheimnisse ist der Generator nicht gedacht.
 */
final class DrawRandom {

	private const MASK = 0xFFFFFFFF;

	private int $state;

	public function __construct( int $seed ) {
		// Zustand 1 bis 2^32 - 1, nie 0 (Xorshift bliebe dort stehen).
		$this->state = ( ( $seed % self::MASK ) + self::MASK ) % self::MASK + 1;
		// Benachbarte Startwerte liefern erst nach einigen Schritten unabhängige Folgen.
		for ( $i = 0; $i < 16; $i++ ) {
			$this->next();
		}
	}

	/**
	 * Ganze Zahl von 0 bis $bound - 1, gleich verteilt.
	 */
	public function below( int $bound ): int {
		if ( $bound < 1 ) {
			throw new \InvalidArgumentException( 'Die Obergrenze muss mindestens 1 sein.' );
		}
		$limit = intdiv( self::MASK + 1, $bound ) * $bound;
		do {
			$value = $this->next();
		} while ( $value >= $limit );
		return $value % $bound;
	}

	/**
	 * Mischt eine Liste (Fisher-Yates).
	 *
	 * @template T
	 * @param list<T> $items Liste.
	 * @return list<T>
	 */
	public function shuffle( array $items ): array {
		$items = array_values( $items );
		for ( $i = count( $items ) - 1; $i > 0; $i-- ) {
			$j           = $this->below( $i + 1 );
			$swap        = $items[ $i ];
			$items[ $i ] = $items[ $j ];
			$items[ $j ] = $swap;
		}
		return $items;
	}

	private function next(): int {
		$x  = $this->state;
		$x ^= ( $x << 13 ) & self::MASK;
		$x ^= $x >> 17;
		$x ^= ( $x << 5 ) & self::MASK;

		$this->state = $x & self::MASK;
		return $this->state;
	}
}
