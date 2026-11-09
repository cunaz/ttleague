<?php
/**
 * Vergleich von Verhältnissen (Siege:Niederlagen, Sätze, Punkte) ohne Fliesskomma.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Domain\Standings;

final class Ratio {

	/**
	 * Vergleicht a_won/a_lost mit b_won/b_lost. Rückgabe wie <=>: 1 wenn a besser.
	 * Ohne Niederlagen (aber mit Siegen) ist ein Verhältnis «unendlich»; ohne jedes Spiel zählt es als 0.
	 */
	public static function compare( int $a_won, int $a_lost, int $b_won, int $b_lost ): int {
		$a_inf = 0 === $a_lost && $a_won > 0;
		$b_inf = 0 === $b_lost && $b_won > 0;

		if ( $a_inf && $b_inf ) {
			return $a_won <=> $b_won;
		}
		if ( $a_inf ) {
			return 1;
		}
		if ( $b_inf ) {
			return -1;
		}

		// Ab hier: a_lost > 0 oder a ohne Spiele (0:0); dasselbe für b.
		$a_empty = 0 === $a_lost;
		$b_empty = 0 === $b_lost;
		if ( $a_empty && $b_empty ) {
			return 0;
		}
		if ( $a_empty ) {
			return $b_won > 0 ? -1 : 0;
		}
		if ( $b_empty ) {
			return $a_won > 0 ? 1 : 0;
		}
		return ( $a_won * $b_lost ) <=> ( $b_won * $a_lost );
	}
}
