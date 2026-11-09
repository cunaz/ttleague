<?php
/**
 * Prüfung von Satzresultaten (Punkte pro Satz).
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Domain\Match;

/**
 * Ein Satz geht auf 11 Punkte, ab 10:10 mit zwei Punkten Vorsprung. Die Sätze müssen zum
 * Spielresultat passen, und nach dem entscheidenden Satz folgt keiner mehr.
 */
final class SetPoints {

	public const POINTS_TO_WIN = 11;

	/**
	 * @param list<array{0:int,1:int}> $sets        Punkte pro Satz (Heim, Gast).
	 * @param int                      $sets_home   Gewonnene Sätze Heim laut Spielresultat.
	 * @param int                      $sets_away   Gewonnene Sätze Gast.
	 * @param int                      $sets_to_win Gewinnsätze.
	 * @return list<string> Fehlermeldungen (leer, wenn gültig).
	 */
	public static function validate( array $sets, int $sets_home, int $sets_away, int $sets_to_win = 3 ): array {
		$errors = array();
		if ( count( $sets ) !== $sets_home + $sets_away ) {
			return array( sprintf( '%d Sätze erfasst, das Resultat %d:%d verlangt %d.', count( $sets ), $sets_home, $sets_away, $sets_home + $sets_away ) );
		}
		$won = array( 0, 0 );
		foreach ( array_values( $sets ) as $i => $set ) {
			$error = self::set_error( (int) $set[0], (int) $set[1] );
			if ( null !== $error ) {
				$errors[] = sprintf( 'Satz %d: %s', $i + 1, $error );
				continue;
			}
			if ( max( $won ) >= $sets_to_win ) {
				$errors[] = sprintf( 'Satz %d: Das Spiel war bereits entschieden.', $i + 1 );
				continue;
			}
			++$won[ $set[0] > $set[1] ? 0 : 1 ];
		}
		if ( array() === $errors && ( $won[0] !== $sets_home || $won[1] !== $sets_away ) ) {
			$errors[] = sprintf( 'Die Sätze ergeben %d:%d, das Resultat lautet %d:%d.', $won[0], $won[1], $sets_home, $sets_away );
		}
		return $errors;
	}

	/**
	 * Fehler eines einzelnen Satzes oder null.
	 */
	public static function set_error( int $home, int $away ): ?string {
		if ( $home < 0 || $away < 0 ) {
			return 'Punkte dürfen nicht negativ sein.';
		}
		$high = max( $home, $away );
		$low  = min( $home, $away );
		if ( $high < self::POINTS_TO_WIN ) {
			return sprintf( '%d:%d ist kein beendeter Satz.', $home, $away );
		}
		if ( $low >= self::POINTS_TO_WIN - 1 ) {
			return 2 === $high - $low ? null : sprintf( '%d:%d: Ab 10:10 braucht es zwei Punkte Vorsprung.', $home, $away );
		}
		return self::POINTS_TO_WIN === $high ? null : sprintf( '%d:%d: Ein Satz endet bei 11 Punkten.', $home, $away );
	}

	/**
	 * Punktetotal (Heim, Gast).
	 *
	 * @param list<array{0:int,1:int}> $sets Sätze.
	 * @return array{0:int,1:int}
	 */
	public static function totals( array $sets ): array {
		$home = 0;
		$away = 0;
		foreach ( $sets as $set ) {
			$home += (int) $set[0];
			$away += (int) $set[1];
		}
		return array( $home, $away );
	}
}
