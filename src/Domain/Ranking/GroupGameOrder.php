<?php
/**
 * Spielfolge «jeder gegen jeden» in einer Gruppe des Ranglistenturniers.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Domain\Ranking;

use TTLeague\Domain\Schedule\RoundRobinScheduler;

/**
 * Die Reihenfolge kommt aus dem Kreisverfahren des Spielplan-Generators: In jedem Durchgang
 * spielt jede Person höchstens einmal, bei ungerader Gruppengrösse setzt eine aus. So können
 * die Partien eines Durchgangs gleichzeitig an mehreren Tischen laufen. Eine Partie nennt die
 * Person mit der kleineren Position zuerst.
 */
final class GroupGameOrder {

	/**
	 * Partien für eine Gruppengrösse, mit Positionen ab 1.
	 *
	 * @return list<array{sequence:int,a:int,b:int}>
	 */
	public static function for_size( int $size ): array {
		if ( $size < 2 ) {
			return array();
		}
		$games    = array();
		$sequence = 0;
		foreach ( ( new RoundRobinScheduler() )->single_round_robin( range( 1, $size ) ) as $round ) {
			foreach ( $round as $pair ) {
				$games[] = array(
					'sequence' => ++$sequence,
					'a'        => (int) min( $pair['home'], $pair['away'] ),
					'b'        => (int) max( $pair['home'], $pair['away'] ),
				);
			}
		}
		return $games;
	}

	/**
	 * Partien für die Personen einer Gruppe in ihrer Reihenfolge auf dem Gruppenzettel.
	 *
	 * @param list<int|string> $players Personen; die Position ist der Platz in der Liste.
	 * @return list<array{sequence:int,a:int|string,b:int|string}>
	 */
	public static function for_players( array $players ): array {
		$players = array_values( $players );
		$games   = array();
		foreach ( self::for_size( count( $players ) ) as $game ) {
			$games[] = array(
				'sequence' => $game['sequence'],
				'a'        => $players[ $game['a'] - 1 ],
				'b'        => $players[ $game['b'] - 1 ],
			);
		}
		return $games;
	}
}
