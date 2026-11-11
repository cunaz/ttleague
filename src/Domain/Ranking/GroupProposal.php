<?php
/**
 * Vorschlag für die Gruppen einer Runde des Ranglistenturniers.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Domain\Ranking;

use TTLeague\Domain\Rules\SeasonRules;

/**
 * Reine, deterministische Funktion: gleiche Eingabe, gleiches Ergebnis. Die TK ändert den
 * Vorschlag und gibt frei.
 *
 * Regeln:
 * - Eine Gruppe hat höchstens rl_group_size Personen. Die Ausnahmegrösse rl_group_size_max
 *   gilt nur, wenn sonst wegen weniger Personen, als in eine einzige Gruppe zusätzlich passen
 *   (üblich: eine Person), eine weitere Gruppe nötig wäre: 13 Angemeldete ergeben 7 und 6,
 *   14 ergeben 5, 5 und 4. Die Gruppen sind so gleich gross wie möglich.
 * - Jede Gruppe erhält einen Abend in einem Lokal (Slot). Ein Slot dient einer Gruppe. Bevorzugt
 *   werden Abende, an denen jemand vom Platzclub in der Kategorie spielen kann, dann Abende,
 *   an denen möglichst viele noch nicht berücksichtigte Personen frei sind, dann Clubs, die
 *   seltener Platzclub waren.
 * - Niemand steht in einer Gruppe an einem gesperrten Abend. Wer an keinem gewählten Abend
 *   kann oder keinen Platz mehr findet, bleibt «nicht zugeteilt».
 * - Jede Gruppe erhält, wenn möglich, eine Person des Platzclubs. Danach werden die Personen
 *   so verteilt und getauscht, dass möglichst wenige Paarungen aus früheren Runden wieder
 *   vorkommen.
 * - Gruppenchef ist die erstgenannte Person des Platzclubs, sonst die erste der Gruppe.
 *
 * Gibt es zu wenige Slots, bleiben Gruppen ohne Abend; die TK setzt ihn von Hand.
 */
final class GroupProposal {

	/** An allen möglichen Abenden gesperrt. */
	public const BLOCKED = 'blocked';

	/** Kein Platz in einer Gruppe an einem freien Abend. */
	public const FULL = 'full';

	public function __construct( private readonly SeasonRules $rules ) {}

	/**
	 * Gruppengrössen für eine Anzahl Personen, grösste zuerst.
	 *
	 * @return list<int>
	 */
	public function group_sizes( int $count ): array {
		if ( $count <= 0 ) {
			return array();
		}
		$size   = $this->rules->rl_group_size;
		$groups = intdiv( $count + $size - 1, $size );
		if ( $groups > 1 && $count - ( $groups - 1 ) * $size <= $this->rules->rl_group_size_max - $size ) {
			--$groups;
		}
		$base  = intdiv( $count, $groups );
		$rest  = $count % $groups;
		$sizes = array();
		for ( $i = 0; $i < $groups; $i++ ) {
			$sizes[] = $base + ( $i < $rest ? 1 : 0 );
		}
		return $sizes;
	}

	/**
	 * Mögliche Abende: jedes Lokal an seinem Spieltag innerhalb der Rundenwoche, ohne Hallensperren.
	 *
	 * @param string                                             $date_from Erster Tag der Rundenwoche (J-M-T).
	 * @param string                                             $date_to   Letzter Tag der Rundenwoche.
	 * @param list<array{id:int,club:int,weekday:int|null}>      $venues    Lokale mit Spieltag (1 = Montag … 7 = Sonntag).
	 * @param list<array{venue:int,from:string,to:string}>       $closures  Hallensperren (Tage einschliesslich).
	 * @return list<array{club:int,venue:int,date:string}> Sortiert nach Datum, Club, Lokal.
	 */
	public static function slots( string $date_from, string $date_to, array $venues, array $closures ): array {
		$from = \DateTimeImmutable::createFromFormat( '!Y-m-d', $date_from );
		$to   = \DateTimeImmutable::createFromFormat( '!Y-m-d', $date_to );
		if ( false === $from || false === $to || $to < $from ) {
			return array();
		}
		$slots = array();
		// Eine Rundenwoche ist kurz; die Obergrenze schützt nur vor falschen Daten.
		for ( $day = $from, $i = 0; $day <= $to && $i < 31; $day = $day->modify( '+1 day' ), $i++ ) {
			$date    = $day->format( 'Y-m-d' );
			$weekday = (int) $day->format( 'N' );
			foreach ( $venues as $venue ) {
				if ( (int) ( $venue['weekday'] ?? 0 ) !== $weekday ) {
					continue;
				}
				$venue_id = (int) $venue['id'];
				foreach ( $closures as $closure ) {
					$closed = (int) $closure['venue'];
					if ( $closed === $venue_id && $closure['from'] <= $date && $date <= $closure['to'] ) {
						continue 2;
					}
				}
				$slots[] = array(
					'club'  => (int) $venue['club'],
					'venue' => (int) $venue['id'],
					'date'  => $date,
				);
			}
		}
		return self::sorted_slots( $slots );
	}

	/**
	 * Schlüssel einer Paarung, unabhängig von der Reihenfolge.
	 */
	public static function pair_key( int $a, int $b ): string {
		return min( $a, $b ) . '|' . max( $a, $b );
	}

	/**
	 * Gruppen aller Kategorien einer Runde.
	 *
	 * @param array<int,list<array{id:int,club:int|null,blocked:list<string>}>> $categories   Kategorie => Angemeldete in ihrer Reihenfolge (erstgenannte zuerst) mit Club und gesperrten Abenden.
	 * @param list<array{club:int,venue:int,date:string}>                        $slots        Mögliche Abende, siehe slots().
	 * @param array<string,int>                                                  $history      Paarung (pair_key) => Anzahl früherer Begegnungen.
	 * @param bool                                                               $single_group Eine einzige Gruppe pro Kategorie (Final).
	 * @param array<int,int>                                                     $hosted       Club => Anzahl Gruppen, für die er in früheren Runden Platzclub war; bei gleich guten Abenden kommt zuerst zum Zug, wer seltener Platzclub war.
	 * @return array{groups:list<array{category:int,group_no:int,members:list<int>,slot:array{club:int,venue:int,date:string}|null,chief:int|null}>,unassigned:list<array{id:int,category:int,reason:string}>,repeats:int} «repeats» zählt die Paarungen des Vorschlags, die es schon einmal gab.
	 */
	public function propose( array $categories, array $slots, array $history = array(), bool $single_group = false, array $hosted = array() ): array {
		ksort( $categories );
		$free       = self::sorted_slots( $slots );
		$hosting    = array_map( 'intval', $hosted );
		$groups     = array();
		$unassigned = array();
		$repeats    = 0;
		foreach ( $categories as $category => $participants ) {
			$result = $this->category( array_values( $participants ), $free, $hosting, $history, $single_group );
			foreach ( $result['groups'] as $number => $group ) {
				$groups[] = array(
					'category' => (int) $category,
					'group_no' => $number + 1,
					'members'  => $group['members'],
					'slot'     => $group['slot'],
					'chief'    => $group['chief'],
				);
				$repeats += $group['repeats'];
			}
			foreach ( $result['unassigned'] as $id => $reason ) {
				$unassigned[] = array(
					'id'       => $id,
					'category' => (int) $category,
					'reason'   => $reason,
				);
			}
		}
		return array(
			'groups'     => $groups,
			'unassigned' => $unassigned,
			'repeats'    => $repeats,
		);
	}

	/**
	 * Gruppen einer Kategorie. Verbraucht Slots aus $free und zählt in $hosting, wie oft ein Club
	 * in dieser Runde schon Platzclub ist.
	 *
	 * @param list<array{id:int,club:int|null,blocked:list<string>}> $participants Angemeldete.
	 * @param list<array{club:int,venue:int,date:string}>            $free         Freie Slots, sortiert.
	 * @param array<int,int>                                         $hosting      Club => Anzahl Gruppen (frühere Runden und diese).
	 * @param array<string,int>                                      $history      Frühere Paarungen.
	 * @return array{groups:list<array{members:list<int>,slot:array{club:int,venue:int,date:string}|null,chief:int|null,repeats:int}>,unassigned:array<int,string>}
	 */
	private function category( array $participants, array &$free, array &$hosting, array $history, bool $single_group ): array {
		$people = array();
		foreach ( $participants as $participant ) {
			$id = (int) $participant['id'];
			if ( isset( $people[ $id ] ) ) {
				continue;
			}
			$people[ $id ] = array(
				'index'   => count( $people ),
				'club'    => null === ( $participant['club'] ?? null ) ? null : (int) $participant['club'],
				'blocked' => array_fill_keys( array_map( 'strval', $participant['blocked'] ?? array() ), true ),
			);
		}

		// Wer an jedem möglichen Abend gesperrt ist, lässt sich nicht zuteilen.
		$dates      = array_values( array_unique( array_column( $free, 'date' ) ) );
		$unassigned = array();
		$placeable  = array();
		foreach ( $people as $id => $person ) {
			if ( array() !== $dates && array() === array_diff( $dates, array_keys( $person['blocked'] ) ) ) {
				$unassigned[ $id ] = self::BLOCKED;
			} else {
				$placeable[] = $id;
			}
		}
		if ( array() === $placeable ) {
			return array(
				'groups'     => array(),
				'unassigned' => $unassigned,
			);
		}
		$sizes = $single_group ? array( count( $placeable ) ) : $this->group_sizes( count( $placeable ) );
		$open  = array();
		foreach ( $placeable as $id ) {
			$open[ $id ] = count( array_diff( $dates, array_keys( $people[ $id ]['blocked'] ) ) );
		}

		// Abende wählen.
		$groups    = array();
		$uncovered = $placeable;
		foreach ( $sizes as $size ) {
			$best       = null;
			$best_score = null;
			$best_free  = array();
			foreach ( $free as $key => $slot ) {
				$available = array_values( array_filter( $placeable, static fn( int $id ): bool => ! isset( $people[ $id ]['blocked'][ $slot['date'] ] ) ) );
				$hosts     = count( array_filter( $available, static fn( int $id ): bool => $people[ $id ]['club'] === $slot['club'] ) );
				$score     = array( $hosts > 0 ? 1 : 0, count( array_intersect( $uncovered, $available ) ), -( $hosting[ $slot['club'] ] ?? 0 ), $hosts );
				if ( null === $best_score || $score > $best_score ) {
					$best       = $key;
					$best_score = $score;
					$best_free  = $available;
				}
			}
			if ( null === $best ) {
				$groups[] = array(
					'slot'    => null,
					'target'  => $size,
					'members' => array(),
				);
				continue;
			}
			$slot     = $free[ $best ];
			$groups[] = array(
				'slot'    => $slot,
				'target'  => $size,
				'members' => array(),
			);
			unset( $free[ $best ] );
			$hosting[ $slot['club'] ] = ( $hosting[ $slot['club'] ] ?? 0 ) + 1;

			// Der Abend deckt so viele Personen ab, wie die Gruppe fasst: zuerst jene mit den wenigsten freien Abenden.
			$covered = array_values( array_intersect( $uncovered, $best_free ) );
			usort(
				$covered,
				static fn( int $a, int $b ): int => array( $open[ $a ], $people[ $a ]['index'] ) <=> array( $open[ $b ], $people[ $b ]['index'] )
			);
			$uncovered = array_values( array_diff( $uncovered, array_slice( $covered, 0, $size ) ) );
			if ( array() === $uncovered ) {
				$uncovered = $placeable;
			}
		}
		$free = array_values( $free );

		$feasible = static fn( int $id, array $group ): bool => null === $group['slot'] || ! isset( $people[ $id ]['blocked'][ $group['slot']['date'] ] );
		// Kosten einer Person in einer Gruppe: frühere Begegnungen im Quadrat, damit eine dritte
		// Begegnung derselben zwei schwerer wiegt als zwei zweite Begegnungen.
		$cost = static function ( int $id, array $members ) use ( $history ): int {
			$sum = 0;
			foreach ( $members as $other ) {
				if ( $other !== $id ) {
					$sum += ( $history[ self::pair_key( $id, $other ) ] ?? 0 ) ** 2;
				}
			}
			return $sum;
		};

		// Jede Gruppe erhält zuerst eine Person des Platzclubs.
		$assigned = array();
		foreach ( $groups as $g => $group ) {
			if ( null === $group['slot'] ) {
				continue;
			}
			foreach ( $placeable as $id ) {
				if ( ! isset( $assigned[ $id ] ) && $people[ $id ]['club'] === $group['slot']['club'] && $feasible( $id, $group ) ) {
					$groups[ $g ]['members'][] = $id;
					$assigned[ $id ]           = true;
					break;
				}
			}
		}

		// Die übrigen: am stärksten eingeschränkte zuerst, in die Gruppe mit den wenigsten bekannten Gegnern.
		$rest    = array_values( array_filter( $placeable, static fn( int $id ): bool => ! isset( $assigned[ $id ] ) ) );
		$options = array();
		foreach ( $rest as $id ) {
			$options[ $id ] = count( array_filter( $groups, static fn( array $group ): bool => $feasible( $id, $group ) ) );
		}
		usort( $rest, static fn( int $a, int $b ): int => array( $options[ $a ], $people[ $a ]['index'] ) <=> array( $options[ $b ], $people[ $b ]['index'] ) );
		$limits = $single_group ? array() : array( $this->rules->rl_group_size, $this->rules->rl_group_size_max );
		foreach ( $rest as $id ) {
			$choice = $this->choose( $id, $groups, $feasible, $cost, null );
			foreach ( $limits as $limit ) {
				// Reicht der vorgesehene Platz an den freien Abenden nicht, darf eine Gruppe bis zur Höchstgrösse wachsen.
				$choice ??= $this->choose( $id, $groups, $feasible, $cost, $limit );
			}
			if ( null === $choice ) {
				$unassigned[ $id ] = 0 === $options[ $id ] ? self::BLOCKED : self::FULL;
				continue;
			}
			$groups[ $choice ]['members'][] = $id;
		}

		$groups = $this->improve( $groups, $people, $feasible, $cost );

		// Leere Gruppen entfallen, ihr Abend wird wieder frei.
		$result = array();
		foreach ( $groups as $group ) {
			if ( array() === $group['members'] ) {
				if ( null !== $group['slot'] ) {
					$free[] = $group['slot'];
					--$hosting[ $group['slot']['club'] ];
				}
				continue;
			}
			usort( $group['members'], static fn( int $a, int $b ): int => $people[ $a ]['index'] <=> $people[ $b ]['index'] );
			$chief = $group['members'][0];
			if ( null !== $group['slot'] ) {
				foreach ( $group['members'] as $id ) {
					if ( $people[ $id ]['club'] === $group['slot']['club'] ) {
						$chief = $id;
						break;
					}
				}
			}
			$repeats = 0;
			foreach ( $group['members'] as $a ) {
				foreach ( $group['members'] as $b ) {
					if ( $a < $b && ( $history[ self::pair_key( $a, $b ) ] ?? 0 ) > 0 ) {
						++$repeats;
					}
				}
			}
			$result[] = array(
				'members' => $group['members'],
				'slot'    => $group['slot'],
				'chief'   => $chief,
				'repeats' => $repeats,
			);
		}
		$free = self::sorted_slots( $free );
		usort(
			$result,
			static fn( array $a, array $b ): int => array( null === $a['slot'] ? 1 : 0, $a['slot']['date'] ?? '', $a['slot']['club'] ?? 0, $a['slot']['venue'] ?? 0, $people[ $a['members'][0] ]['index'] )
				<=> array( null === $b['slot'] ? 1 : 0, $b['slot']['date'] ?? '', $b['slot']['club'] ?? 0, $b['slot']['venue'] ?? 0, $people[ $b['members'][0] ]['index'] )
		);
		ksort( $unassigned );
		return array(
			'groups'     => $result,
			'unassigned' => $unassigned,
		);
	}

	/**
	 * Gruppe mit freiem Platz an einem freien Abend: wenigste frühere Gegner, dann die kleinste.
	 *
	 * @param list<array{slot:array{club:int,venue:int,date:string}|null,target:int,members:list<int>}> $groups   Gruppen.
	 * @param callable(int,array<string,mixed>):bool                                                    $feasible Ist die Person an diesem Abend nicht gesperrt?
	 * @param callable(int,list<int>):int                                                               $cost     Kosten der früheren Begegnungen mit den Personen.
	 * @param int|null                                                                                  $limit    Höchstgrösse statt der vorgesehenen Grösse.
	 */
	private function choose( int $id, array $groups, callable $feasible, callable $cost, ?int $limit ): ?int {
		$best       = null;
		$best_score = null;
		foreach ( $groups as $g => $group ) {
			if ( ! $feasible( $id, $group ) || count( $group['members'] ) >= max( $group['target'], $limit ?? 0 ) ) {
				continue;
			}
			$score = array( $cost( $id, $group['members'] ), count( $group['members'] ), $g );
			if ( null === $best_score || $score < $best_score ) {
				$best       = $g;
				$best_score = $score;
			}
		}
		return $best;
	}

	/**
	 * Tauscht je zwei Personen zwischen Gruppen, solange das frühere Paarungen spart. Ein Tausch
	 * hält die gesperrten Abende ein und nimmt einer Gruppe nie ihre einzige Person des Platzclubs.
	 * Die Summe der Wiederholungen sinkt mit jedem Tausch, das Verfahren endet also.
	 *
	 * @param list<array{slot:array{club:int,venue:int,date:string}|null,target:int,members:list<int>}> $groups   Gruppen.
	 * @param array<int,array{index:int,club:int|null,blocked:array<string,bool>}>                      $people   Personen.
	 * @param callable(int,array<string,mixed>):bool                                                    $feasible Ist die Person an diesem Abend nicht gesperrt?
	 * @param callable(int,list<int>):int                                                               $cost     Frühere Begegnungen mit den Personen.
	 * @return list<array{slot:array{club:int,venue:int,date:string}|null,target:int,members:list<int>}>
	 */
	private function improve( array $groups, array $people, callable $feasible, callable $cost ): array {
		$keeps_host = static function ( array $group, int $leaving, int $joining ) use ( $people ): bool {
			if ( null === $group['slot'] || $people[ $leaving ]['club'] !== $group['slot']['club'] || $people[ $joining ]['club'] === $group['slot']['club'] ) {
				return true;
			}
			$hosts = count( array_filter( $group['members'], static fn( int $id ): bool => $people[ $id ]['club'] === $group['slot']['club'] ) );
			return $hosts > 1;
		};
		$count      = count( $groups );
		do {
			$improved = false;
			for ( $i = 0; $i < $count && ! $improved; $i++ ) {
				for ( $j = $i + 1; $j < $count && ! $improved; $j++ ) {
					foreach ( $groups[ $i ]['members'] as $x => $a ) {
						foreach ( $groups[ $j ]['members'] as $y => $b ) {
							if ( ! $feasible( $a, $groups[ $j ] ) || ! $feasible( $b, $groups[ $i ] ) || ! $keeps_host( $groups[ $i ], $a, $b ) || ! $keeps_host( $groups[ $j ], $b, $a ) ) {
								continue;
							}
							$others_i = array_values( array_diff( $groups[ $i ]['members'], array( $a ) ) );
							$others_j = array_values( array_diff( $groups[ $j ]['members'], array( $b ) ) );
							if ( $cost( $a, $others_j ) + $cost( $b, $others_i ) < $cost( $a, $others_i ) + $cost( $b, $others_j ) ) {
								$groups[ $i ]['members'][ $x ] = $b;
								$groups[ $j ]['members'][ $y ] = $a;
								$improved                      = true;
								break 2;
							}
						}
					}
				}
			}
		} while ( $improved );
		return $groups;
	}

	/**
	 * Slots ohne Doppel, sortiert nach Datum, Club, Lokal.
	 *
	 * @param list<array{club:int,venue:int,date:string}> $slots Slots.
	 * @return list<array{club:int,venue:int,date:string}>
	 */
	private static function sorted_slots( array $slots ): array {
		$unique = array();
		foreach ( $slots as $slot ) {
			$unique[ $slot['date'] . '|' . (int) $slot['venue'] ] = array(
				'club'  => (int) $slot['club'],
				'venue' => (int) $slot['venue'],
				'date'  => (string) $slot['date'],
			);
		}
		$unique = array_values( $unique );
		usort( $unique, static fn( array $a, array $b ): int => array( $a['date'], $a['club'], $a['venue'] ) <=> array( $b['date'], $b['club'], $b['venue'] ) );
		return $unique;
	}
}
