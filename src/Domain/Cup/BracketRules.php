<?php
/**
 * Regeln des Cup-Tableaus: Nachrücken, Heimrecht, kampfloses Weiterkommen.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Domain\Cup;

/**
 * Reine Regeln aus docs/cup.md. Eine Paarung steht in Runde und Platz (beide ab 1). Die Sieger
 * der Paarungen 2k - 1 und 2k einer Runde treffen sich in Paarung k der nächsten Runde; der
 * Sieger der oberen Paarung (ungerader Platz) steht dort oben und hat Heimrecht.
 */
final class BracketRules {

	public const HOME = 'home';
	public const AWAY = 'away';

	/** Die Paarung wartet: Es fehlt eine Mannschaft oder das Resultat. */
	public const PENDING = 'pending';

	/** Die Paarung hat eine Siegerin. */
	public const DECIDED = 'decided';

	/** Aus der Paarung kommt niemand weiter (beide Plätze leer oder zurückgezogen). */
	public const DEAD = 'dead';

	/** Durch ein Resultat entschieden. */
	public const BY_RESULT = 'result';

	/** Ohne Begegnung weiter: Freilos oder Rückzug des Gegners. */
	public const BY_WALKOVER = 'walkover';

	/** Status einer Begegnung, mit denen der Sieger feststeht. */
	public const DECIDED_STATUSES = array( 'confirmed', 'final', 'forfeit' );

	/** Status einer Begegnung ohne Resultat. */
	public const OPEN_STATUSES = array( 'scheduled', 'postponed' );

	/**
	 * Platz des Siegers in der nächsten Runde; null nach dem Final.
	 *
	 * @return array{round:int,slot:int,side:string}|null
	 */
	public static function next( int $round, int $slot, int $rounds ): ?array {
		if ( $round >= $rounds ) {
			return null;
		}
		return array(
			'round' => $round + 1,
			'slot'  => intdiv( $slot + 1, 2 ),
			'side'  => 1 === $slot % 2 ? self::HOME : self::AWAY,
		);
	}

	/**
	 * Paarung der Vorrunde, deren Sieger auf diesen Platz kommt; null in der ersten Runde.
	 *
	 * @return array{round:int,slot:int}|null
	 */
	public static function feeder( int $round, int $slot, string $side ): ?array {
		if ( $round <= 1 ) {
			return null;
		}
		return array(
			'round' => $round - 1,
			'slot'  => self::HOME === $side ? 2 * $slot - 1 : 2 * $slot,
		);
	}

	/**
	 * Halbfinal und Final setzt die TK mit Ort und Termin an, das Heimrecht entfällt (Art. 23.1).
	 */
	public static function is_neutral( int $round, int $rounds ): bool {
		return $rounds - $round <= 1;
	}

	/**
	 * Siegerseite einer Begegnung, sobald sie bestätigt, abgeschlossen oder forfait gewertet ist.
	 */
	public static function fixture_winner( string $status, ?int $home_score, ?int $away_score ): ?string {
		if ( ! in_array( $status, self::DECIDED_STATUSES, true ) || null === $home_score || null === $away_score || $home_score === $away_score ) {
			return null;
		}
		return $home_score > $away_score ? self::HOME : self::AWAY;
	}

	/**
	 * Hat die Begegnung noch kein Resultat (auch kein provisorisches)? Nur dann darf das Tableau
	 * eine Mannschaft darin ersetzen.
	 */
	public static function fixture_untouched( string $status, ?int $home_score, ?int $away_score ): bool {
		return in_array( $status, self::OPEN_STATUSES, true ) && null === $home_score && null === $away_score;
	}

	/**
	 * Stand einer Paarung.
	 *
	 * Ein Resultat der Begegnung geht vor: Wer gewonnen hat, bleibt Sieger, auch wenn danach eine
	 * der Mannschaften zurückzieht. Sonst wartet die Paarung, solange ein Platz noch besetzt werden
	 * kann oder beide Mannschaften spielen müssen. Steht fest, dass nur eine Mannschaft antritt
	 * (Freilos, Rückzug, niemand aus der Vorrunde), kommt sie kampflos weiter.
	 *
	 * @param int|null                                                                                              $home       Meldung auf dem oberen Platz.
	 * @param int|null                                                                                              $away       Meldung auf dem unteren Platz.
	 * @param bool                                                                                                  $home_final Der obere Platz ändert sich nicht mehr (erste Runde oder Vorrunde entschieden).
	 * @param bool                                                                                                  $away_final Der untere Platz ändert sich nicht mehr.
	 * @param array<int,bool>                                                                                       $withdrawn  Zurückgezogene Meldungen (ID => true).
	 * @param array{status:string,home_entry:int|null,away_entry:int|null,home_score:int|null,away_score:int|null}|null $fixture    Begegnung der Paarung.
	 * @return array{state:string,winner:int|null,by:string|null}
	 */
	public static function outcome( ?int $home, ?int $away, bool $home_final, bool $away_final, array $withdrawn, ?array $fixture ): array {
		if ( null !== $fixture ) {
			$side   = self::fixture_winner( $fixture['status'], $fixture['home_score'], $fixture['away_score'] );
			$winner = null === $side ? null : $fixture[ $side . '_entry' ];
			if ( null !== $winner && in_array( $winner, array( $home, $away ), true ) ) {
				return self::result( self::DECIDED, $winner, self::BY_RESULT );
			}
		}
		if ( ! $home_final || ! $away_final ) {
			return self::result( self::PENDING );
		}
		$playing = array();
		foreach ( array( $home, $away ) as $entry ) {
			if ( null !== $entry && empty( $withdrawn[ $entry ] ) ) {
				$playing[] = $entry;
			}
		}
		return match ( count( $playing ) ) {
			2       => self::result( self::PENDING ),
			1       => self::result( self::DECIDED, $playing[0], self::BY_WALKOVER ),
			default => self::result( self::DEAD ),
		};
	}

	/**
	 * @return array{state:string,winner:int|null,by:string|null}
	 */
	private static function result( string $state, ?int $winner = null, ?string $by = null ): array {
		return array(
			'state'  => $state,
			'winner' => $winner,
			'by'     => $by,
		);
	}
}
