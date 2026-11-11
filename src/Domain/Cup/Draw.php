<?php
/**
 * Auslosung der ersten Runde des Mannschaftscups (Art. 21): Setzung, Freilose, Lose.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Domain\Cup;

/**
 * Reine Funktion aus docs/cup.md, mit ihrem Startwert reproduzierbar.
 *
 * - Das Tableau hat so viele Plätze wie die nächste Zweierpotenz ab der Zahl der Mannschaften.
 * - Setzung nach Punktesumme absteigend, bei Gleichstand nach Meldezeitpunkt (dann nach ID).
 *   Eine von der TK vergebene Setznummer (seed) geht vor.
 * - Die Gesetzten stehen auf den üblichen Setzplätzen: Die Nummern 1 und 2 treffen sich erst im
 *   Final, die Nummern 1 bis 4 erst im Halbfinal. Die Freilose stehen den höchsten Gesetzten
 *   gegenüber; darum sind immer mindestens so viele Mannschaften gesetzt, wie es Freilose gibt.
 * - Die übrigen Mannschaften werden auf die freien Plätze gelost. Treffen dabei zwei
 *   Mannschaften desselben Clubs aufeinander, werden geloste Mannschaften getauscht, solange das
 *   die Zahl solcher Paarungen senkt.
 * - Die erstgenannte Mannschaft einer Paarung hat Heimrecht; wer erstgenannt ist, wird gelost.
 *
 * Ein Tableau ist eine Liste von Plätzen: Platz 0 und 1 bilden die Paarung 1, Platz 2 und 3 die
 * Paarung 2 und so weiter. Ein Platz enthält die ID der Meldung oder null (Freilos).
 */
final class Draw {

	/**
	 * Mannschaften in der Reihenfolge der Setzung.
	 *
	 * @param list<array{id:int,club_id:int,points_total:int|null,registered_at:string|null,seed?:int|null}> $entries Meldungen.
	 * @return list<array{id:int,club_id:int,points_total:int|null,registered_at:string|null,seed?:int|null}>
	 */
	public static function seeding( array $entries ): array {
		usort(
			$entries,
			static function ( array $a, array $b ): int {
				$seed_a = $a['seed'] ?? null;
				$seed_b = $b['seed'] ?? null;
				return array( null === $seed_a ? 1 : 0, $seed_a ?? 0, - (int) ( $a['points_total'] ?? 0 ), null === $a['registered_at'] ? 1 : 0, (string) $a['registered_at'], $a['id'] )
					<=> array( null === $seed_b ? 1 : 0, $seed_b ?? 0, - (int) ( $b['points_total'] ?? 0 ), null === $b['registered_at'] ? 1 : 0, (string) $b['registered_at'], $b['id'] );
			}
		);
		return $entries;
	}

	/**
	 * Grösse des Tableaus: die nächste Zweierpotenz, mindestens 2.
	 */
	public static function size( int $count ): int {
		$size = 2;
		while ( $size < $count ) {
			$size *= 2;
		}
		return $size;
	}

	/**
	 * Anzahl Runden eines Tableaus dieser Grösse (2 Plätze: 1 Runde, 16 Plätze: 4 Runden).
	 */
	public static function rounds( int $size ): int {
		$rounds = 0;
		while ( $size > 1 ) {
			$size = intdiv( $size, 2 );
			++$rounds;
		}
		return $rounds;
	}

	/**
	 * Setznummer je Platz des Tableaus, für 8 Plätze 1, 8, 4, 5, 2, 7, 3, 6: Nummer n spielt
	 * zuerst gegen Nummer (Grösse + 1 - n), und die Nummern 1 und 2 stehen in verschiedenen Hälften.
	 *
	 * @return list<int>
	 */
	public static function seed_positions( int $size ): array {
		$positions = array( 1, 2 );
		for ( $length = 2; $length < $size; $length *= 2 ) {
			$next = array();
			$sum  = 2 * $length + 1;
			foreach ( $positions as $number ) {
				$next[] = $number;
				$next[] = $sum - $number;
			}
			$positions = $next;
		}
		return $positions;
	}

	/**
	 * Wie viele Mannschaften gesetzt werden: ein Viertel des Tableaus, mindestens zwei und
	 * mindestens so viele, wie es Freilose gibt.
	 */
	public static function seeded_count( int $count ): int {
		$size = self::size( $count );
		return min( $count, max( $size - $count, 2, intdiv( $size, 4 ) ) );
	}

	/**
	 * Lost die erste Runde aus.
	 *
	 * @param list<array{id:int,club_id:int,points_total:int|null,registered_at:string|null,seed?:int|null}> $entries Meldungen.
	 * @param int                                                                                             $seed    Startwert.
	 * @return array{seed:int,size:int,rounds:int,seeded:int,seeding:list<int>,positions:list<int|null>}
	 * @throws \InvalidArgumentException Bei weniger als zwei Mannschaften oder doppelten IDs.
	 */
	public static function draw( array $entries, int $seed ): array {
		$count = count( $entries );
		if ( $count < 2 ) {
			throw new \InvalidArgumentException( 'Eine Auslosung braucht mindestens zwei Mannschaften.' );
		}
		$order = self::seeding( $entries );
		$clubs = array();
		foreach ( $order as $entry ) {
			if ( isset( $clubs[ $entry['id'] ] ) ) {
				throw new \InvalidArgumentException( 'Eine Mannschaft ist doppelt in der Auslosung.' );
			}
			$clubs[ $entry['id'] ] = $entry['club_id'];
		}
		$size   = self::size( $count );
		$seeded = self::seeded_count( $count );
		$random = new DrawRandom( $seed );

		// Gesetzte auf ihre Plätze; Plätze der fehlenden Nummern sind Freilose.
		$positions = array_fill( 0, $size, null );
		$free      = array();
		foreach ( self::seed_positions( $size ) as $index => $number ) {
			if ( $number <= $seeded ) {
				$positions[ $index ] = $order[ $number - 1 ]['id'];
			} elseif ( $number <= $count ) {
				$free[] = $index;
			}
		}

		// Die übrigen Mannschaften auf die freien Plätze losen.
		$drawn = $random->shuffle( array_column( array_slice( $order, $seeded ), 'id' ) );
		foreach ( $free as $i => $index ) {
			$positions[ $index ] = $drawn[ $i ];
		}
		$positions = self::separate_clubs( $positions, $free, $clubs, $random );

		// Heimrecht hat die erstgenannte Mannschaft: losen, wer das ist. Ein Freilos steht hinten.
		for ( $index = 0; $index < $size; $index += 2 ) {
			$first  = $positions[ $index ];
			$second = $positions[ $index + 1 ];
			if ( null === $first || ( null !== $second && 1 === $random->below( 2 ) ) ) {
				$positions[ $index ]     = $second;
				$positions[ $index + 1 ] = $first;
			}
		}

		return array(
			'seed'      => $seed,
			'size'      => $size,
			'rounds'    => self::rounds( $size ),
			'seeded'    => $seeded,
			'seeding'   => array_column( $order, 'id' ),
			'positions' => $positions,
		);
	}

	/**
	 * Paarungen der ersten Runde aus den Plätzen.
	 *
	 * @param list<int|null> $positions Plätze.
	 * @return list<array{slot:int,home:int|null,away:int|null}>
	 */
	public static function pairings( array $positions ): array {
		$pairings = array();
		$count    = count( $positions );
		for ( $index = 0; $index + 1 < $count; $index += 2 ) {
			$pairings[] = array(
				'slot' => intdiv( $index, 2 ) + 1,
				'home' => $positions[ $index ],
				'away' => $positions[ $index + 1 ],
			);
		}
		return $pairings;
	}

	/**
	 * Anzahl Paarungen der ersten Runde mit zwei Mannschaften desselben Clubs.
	 *
	 * @param list<int|null> $positions Plätze.
	 * @param array<int,int> $clubs     Meldung => Club.
	 */
	public static function club_clashes( array $positions, array $clubs ): int {
		$clashes = 0;
		foreach ( self::pairings( $positions ) as $pairing ) {
			if ( null !== $pairing['home'] && null !== $pairing['away'] && ( $clubs[ $pairing['home'] ] ?? -1 ) === ( $clubs[ $pairing['away'] ] ?? -2 ) ) {
				++$clashes;
			}
		}
		return $clashes;
	}

	/**
	 * Prüft ein von Hand geändertes Tableau: richtige Grösse, jede Mannschaft genau einmal, keine
	 * Paarung aus zwei Freilosen. Liefert die Verstösse als Kennungen (leer: gültig).
	 *
	 * @param list<int|null> $positions Plätze.
	 * @param list<int>      $entry_ids Mannschaften, die im Tableau stehen müssen.
	 * @return list<string> «size», «unknown», «duplicate», «missing», «empty_pairing».
	 */
	public static function problems( array $positions, array $entry_ids ): array {
		$problems = array();
		if ( count( $entry_ids ) < 2 || count( $positions ) !== self::size( count( $entry_ids ) ) ) {
			return array( 'size' );
		}
		$seen = array();
		foreach ( $positions as $id ) {
			if ( null === $id ) {
				continue;
			}
			if ( ! in_array( $id, $entry_ids, true ) ) {
				$problems['unknown'] = true;
			} elseif ( isset( $seen[ $id ] ) ) {
				$problems['duplicate'] = true;
			}
			$seen[ $id ] = true;
		}
		if ( array() !== array_diff( $entry_ids, array_keys( $seen ) ) ) {
			$problems['missing'] = true;
		}
		foreach ( self::pairings( $positions ) as $pairing ) {
			if ( null === $pairing['home'] && null === $pairing['away'] ) {
				$problems['empty_pairing'] = true;
			}
		}
		return array_keys( $problems );
	}

	/**
	 * Stellt in Paarungen mit einem Freilos die Mannschaft nach vorn.
	 *
	 * @param list<int|null> $positions Plätze.
	 * @return list<int|null>
	 */
	public static function normalize( array $positions ): array {
		$positions = array_values( $positions );
		$count     = count( $positions );
		for ( $index = 0; $index + 1 < $count; $index += 2 ) {
			if ( null === $positions[ $index ] && null !== $positions[ $index + 1 ] ) {
				$positions[ $index ]     = $positions[ $index + 1 ];
				$positions[ $index + 1 ] = null;
			}
		}
		return $positions;
	}

	/**
	 * Tauscht geloste Mannschaften, solange das die Zahl der Paarungen aus demselben Club senkt.
	 * Gesetzte bleiben auf ihren Plätzen. Die Reihenfolge der Versuche ist gelost, damit das
	 * Ergebnis nicht von der Reihenfolge der Plätze abhängt.
	 *
	 * @param list<int|null> $positions Plätze.
	 * @param list<int>      $free      Geloste Plätze.
	 * @param array<int,int> $clubs     Meldung => Club.
	 * @return list<int|null>
	 */
	private static function separate_clubs( array $positions, array $free, array $clubs, DrawRandom $random ): array {
		$clashes = self::club_clashes( $positions, $clubs );
		while ( $clashes > 0 ) {
			$improved = false;
			$order    = $random->shuffle( $free );
			foreach ( $order as $a ) {
				$partner = $positions[ $a ^ 1 ];
				if ( null === $partner || $clubs[ $partner ] !== $clubs[ $positions[ $a ] ] ) {
					continue;
				}
				foreach ( $order as $b ) {
					if ( $b === $a || ( $b ^ 1 ) === $a ) {
						continue;
					}
					$candidate       = $positions;
					$candidate[ $a ] = $positions[ $b ];
					$candidate[ $b ] = $positions[ $a ];
					$count           = self::club_clashes( $candidate, $clubs );
					if ( $count < $clashes ) {
						$positions = $candidate;
						$clashes   = $count;
						$improved  = true;
						break 2;
					}
				}
			}
			if ( ! $improved ) {
				break;
			}
		}
		return $positions;
	}
}
