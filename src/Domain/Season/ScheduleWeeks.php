<?php
/**
 * Spielwochen der Meisterschaft: Vorschlag und Prüfung der Auswahl der TK.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Domain\Season;

/**
 * Eine Spielwoche ist ihr Montag als JJJJ-MM-TT (docs/spielplan.md). Vor- und Rückrunde haben
 * getrennte Listen; gespeichert werden sie in season.schedule_json unter «first_leg_weeks» und
 * «second_leg_weeks». Reine Regeln ohne Datenbank.
 */
final class ScheduleWeeks {

	public const FIRST  = 'first_leg_weeks';
	public const SECOND = 'second_leg_weeks';

	/**
	 * Alle Wochen der Saison: jeder Montag vom Saisonbeginn bis zum Saisonende.
	 *
	 * @return list<string>
	 */
	public static function mondays( string $start_date, string $end_date ): array {
		if ( ! SeasonDates::is_date( $start_date ) || ! SeasonDates::is_date( $end_date ) ) {
			return array();
		}
		$day     = new \DateTimeImmutable( $start_date );
		$day     = $day->modify( '+' . ( ( 8 - (int) $day->format( 'N' ) ) % 7 ) . ' days' );
		$mondays = array();
		while ( $day->format( 'Y-m-d' ) <= $end_date ) {
			$mondays[] = $day->format( 'Y-m-d' );
			$day       = $day->modify( '+7 days' );
		}
		return $mondays;
	}

	/**
	 * Vorschlag: alle Wochen der Saison ohne die Wochen der Runden des Ranglistenturniers. Die
	 * Wochen bis zum Jahreswechsel gehören zur Vorrunde, die Wochen danach zur Rückrunde; eine
	 * Woche über den Jahreswechsel zählt zur Vorrunde. Ferienwochen streicht die TK.
	 *
	 * @param string                                        $start_date Saisonbeginn.
	 * @param string                                        $end_date   Saisonende.
	 * @param list<array{from:string|null,to:string|null}> $blocked    Zeiträume ohne Meisterschaft (RL-Runden), erster und letzter Tag eingeschlossen.
	 * @return array{first_leg_weeks:list<string>,second_leg_weeks:list<string>,blocked:list<string>}
	 */
	public static function proposal( string $start_date, string $end_date, array $blocked = array() ): array {
		$result = array(
			self::FIRST  => array(),
			self::SECOND => array(),
			'blocked'    => array(),
		);
		$turn   = ( (int) substr( $start_date, 0, 4 ) + 1 ) . '-01-01';
		foreach ( self::mondays( $start_date, $end_date ) as $monday ) {
			if ( self::is_blocked( $monday, $blocked ) ) {
				$result['blocked'][] = $monday;
				continue;
			}
			$result[ $monday < $turn ? self::FIRST : self::SECOND ][] = $monday;
		}
		return $result;
	}

	/**
	 * Fällt die Woche (Montag bis Sonntag) in einen der Zeiträume?
	 *
	 * @param list<array{from:string|null,to:string|null}> $blocked Zeiträume.
	 */
	public static function is_blocked( string $monday, array $blocked ): bool {
		$sunday = ( new \DateTimeImmutable( $monday ) )->modify( '+6 days' )->format( 'Y-m-d' );
		foreach ( $blocked as $range ) {
			$from = $range['from'] ?? $range['to'] ?? null;
			$to   = $range['to'] ?? $range['from'] ?? null;
			if ( null === $from || null === $to ) {
				continue;
			}
			if ( $from <= $sunday && $to >= $monday ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Prüft die Auswahl der TK und liefert sie sortiert und ohne Doppel. Jede Woche ist ein
	 * Montag in der Saison, keine Woche steht in beiden Halbserien, und die Rückrunde beginnt
	 * nach der letzten Woche der Vorrunde.
	 *
	 * @param array<mixed> $first      Montage der Vorrunde.
	 * @param array<mixed> $second     Montage der Rückrunde.
	 * @param string       $start_date Saisonbeginn.
	 * @param string       $end_date   Saisonende.
	 * @return array{first_leg_weeks:list<string>,second_leg_weeks:list<string>,errors:list<array{field:string,message:string}>}
	 */
	public static function validate( array $first, array $second, string $start_date, string $end_date ): array {
		$errors = array();
		$clean  = array();
		foreach ( array(
			self::FIRST  => $first,
			self::SECOND => $second,
		) as $field => $weeks ) {
			$clean[ $field ] = array();
			foreach ( $weeks as $week ) {
				$week = is_string( $week ) ? trim( $week ) : '';
				if ( ! SeasonDates::is_date( $week ) || '1' !== ( new \DateTimeImmutable( $week ) )->format( 'N' ) ) {
					$errors[] = self::error( $field, sprintf( 'Eine Spielwoche ist ihr Montag: «%s» ist keiner.', $week ) );
					continue;
				}
				if ( $week < $start_date || $week > $end_date ) {
					$errors[] = self::error( $field, sprintf( 'Die Woche vom %s liegt nicht in der Saison.', $week ) );
					continue;
				}
				$clean[ $field ][ $week ] = $week;
			}
			ksort( $clean[ $field ] );
			$clean[ $field ] = array_values( $clean[ $field ] );
		}
		$both = array_intersect( $clean[ self::FIRST ], $clean[ self::SECOND ] );
		if ( array() !== $both ) {
			$errors[] = self::error( self::SECOND, sprintf( 'Eine Woche gehört zur Vorrunde oder zur Rückrunde, nicht zu beiden: %s.', implode( ', ', $both ) ) );
		} elseif ( array() !== $clean[ self::FIRST ] && array() !== $clean[ self::SECOND ] && $clean[ self::SECOND ][0] <= end( $clean[ self::FIRST ] ) ) {
			$errors[] = self::error( self::SECOND, 'Die Rückrunde muss nach der letzten Spielwoche der Vorrunde beginnen.' );
		}
		return array(
			self::FIRST  => $clean[ self::FIRST ],
			self::SECOND => $clean[ self::SECOND ],
			'errors'     => $errors,
		);
	}

	/**
	 * Gespeicherte Spielwochen aus season.schedule_json; Ungültiges fällt weg.
	 *
	 * @param array<string,mixed> $schedule Inhalt von schedule_json.
	 * @return array{first_leg_weeks:list<string>,second_leg_weeks:list<string>}
	 */
	public static function stored( array $schedule ): array {
		$weeks = array();
		foreach ( array( self::FIRST, self::SECOND ) as $field ) {
			$weeks[ $field ] = array();
			foreach ( is_array( $schedule[ $field ] ?? null ) ? $schedule[ $field ] : array() as $week ) {
				if ( is_string( $week ) && SeasonDates::is_date( $week ) && '1' === ( new \DateTimeImmutable( $week ) )->format( 'N' ) ) {
					$weeks[ $field ][ $week ] = $week;
				}
			}
			ksort( $weeks[ $field ] );
			$weeks[ $field ] = array_values( $weeks[ $field ] );
		}
		return $weeks;
	}

	/**
	 * @return array{field:string,message:string}
	 */
	private static function error( string $field, string $message ): array {
		return array(
			'field'   => $field,
			'message' => $message,
		);
	}
}
