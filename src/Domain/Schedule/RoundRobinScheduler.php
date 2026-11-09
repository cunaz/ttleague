<?php
/**
 * Spielplan-Generator: Rundenturnier mit Vor- und Rückrunde (Art. 13.1).
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Domain\Schedule;

final class RoundRobinScheduler {

	/**
	 * Einfache Runde «jeder gegen jeden» nach dem Kreisverfahren. Bei ungerader Teamzahl
	 * hat pro Runde ein Team spielfrei (nicht aufgeführt). Heimrecht wechselt pro Runde.
	 *
	 * @param list<int|string> $teams Teams.
	 * @return list<list<array{home:int|string,away:int|string}>> Runden mit Paarungen.
	 */
	public function single_round_robin( array $teams ): array {
		$teams = array_values( $teams );
		if ( count( $teams ) < 2 ) {
			return array();
		}
		if ( count( $teams ) % 2 === 1 ) {
			$teams[] = null;
		}
		$n     = count( $teams );
		$fixed = $teams[0];
		$rest  = array_slice( $teams, 1 );

		$home_count = array();
		$last_home  = array();
		foreach ( $teams as $team ) {
			if ( null !== $team ) {
				$home_count[ $team ] = 0;
				$last_home[ $team ]  = null;
			}
		}

		$rounds = array();
		for ( $r = 0; $r < $n - 1; $r++ ) {
			$pairs    = array();
			$rotation = array_merge( array( $fixed ), $rest );
			for ( $i = 0; $i < $n / 2; $i++ ) {
				$a = $rotation[ $i ];
				$b = $rotation[ $n - 1 - $i ];
				if ( null === $a || null === $b ) {
					continue;
				}
				// Heimrecht an das Team mit weniger Heimspielen; bei Gleichstand an das Team,
				// das zuletzt auswärts war; sonst an das erstgenannte.
				if ( $home_count[ $a ] !== $home_count[ $b ] ) {
					$a_home = $home_count[ $a ] < $home_count[ $b ];
				} elseif ( $last_home[ $a ] !== $last_home[ $b ] ) {
					$a_home = false === $last_home[ $a ];
				} else {
					$a_home = 0 === ( $r + $i ) % 2;
				}
				$home = $a_home ? $a : $b;
				$away = $a_home ? $b : $a;
				++$home_count[ $home ];
				$last_home[ $home ] = true;
				$last_home[ $away ] = false;
				$pairs[]            = array(
					'home' => $home,
					'away' => $away,
				);
			}
			$rounds[] = $pairs;
			array_unshift( $rest, array_pop( $rest ) );
		}
		return $rounds;
	}

	/**
	 * Vor- und Rückrunde: die Rückrunde spiegelt das Heimrecht.
	 *
	 * @param list<int|string> $teams Teams.
	 * @return list<list<array{home:int|string,away:int|string,leg:int}>>
	 */
	public function double_round_robin( array $teams ): array {
		$first  = $this->single_round_robin( $teams );
		$result = array();
		foreach ( $first as $round ) {
			$result[] = array_map( static fn( array $p ) => $p + array( 'leg' => 1 ), $round );
		}
		foreach ( $first as $round ) {
			$result[] = array_map(
				static fn( array $p ) => array(
					'home' => $p['away'],
					'away' => $p['home'],
					'leg'  => 2,
				),
				$round
			);
		}
		return $result;
	}

	/**
	 * Verteilt Runden auf Spielwochen. Der Termin ergibt sich aus Wochenbeginn (Montag)
	 * plus Heimspieltag des Heimteams.
	 *
	 * @param list<list<array{home:int|string,away:int|string,leg?:int}>> $rounds      Runden.
	 * @param list<\DateTimeImmutable>                                     $week_starts Montag jeder Spielwoche, eine pro Runde.
	 * @param callable(int|string):int                                     $home_weekday Liefert den Heimspieltag eines Teams (1 = Montag … 7 = Sonntag).
	 * @return list<list<array{home:int|string,away:int|string,leg?:int,date:\DateTimeImmutable}>>
	 */
	public function assign_dates( array $rounds, array $week_starts, callable $home_weekday ): array {
		if ( count( $week_starts ) < count( $rounds ) ) {
			throw new \InvalidArgumentException( 'Zu wenige Spielwochen für die Anzahl Runden.' );
		}
		$result = array();
		foreach ( $rounds as $index => $round ) {
			$monday = $week_starts[ $index ];
			$dated  = array();
			foreach ( $round as $pair ) {
				$weekday = (int) $home_weekday( $pair['home'] );
				if ( $weekday < 1 || $weekday > 7 ) {
					throw new \InvalidArgumentException( 'Heimspieltag muss zwischen 1 und 7 liegen.' );
				}
				$pair['date'] = $monday->modify( '+' . ( $weekday - 1 ) . ' days' );
				$dated[]      = $pair;
			}
			$result[] = $dated;
		}
		return $result;
	}
}
