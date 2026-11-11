<?php
/**
 * Name, Kürzel und Daten einer Saison sowie die Reihenfolge der Meldedaten.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Domain\Season;

/**
 * Reine Regeln ohne Datenbank (docs/datenmodell.md): Eine Saison heisst «2027/28», ihr Kürzel
 * ist «2027-28». Die Folgesaison beginnt und endet ein Jahr nach der laufenden, üblich am
 * 1. August und am 31. Juli. Daten sind Kalendertage als JJJJ-MM-TT. Ein Fehler ist ein Array
 * mit «field» und «message».
 */
final class SeasonDates {

	/**
	 * Name, Kürzel und Daten der Saison, die auf die angegebene folgt.
	 *
	 * @param string $start_date Beginn der laufenden Saison.
	 * @param string $end_date   Ende der laufenden Saison.
	 * @return array{name:string,slug:string,start_date:string,end_date:string}
	 * @throws \InvalidArgumentException Bei ungültigen Daten.
	 */
	public static function following( string $start_date, string $end_date ): array {
		if ( ! self::is_date( $start_date ) || ! self::is_date( $end_date ) ) {
			throw new \InvalidArgumentException( 'Beginn und Ende der Saison müssen gültige Daten sein.' );
		}
		$start = self::plus_one_year( $start_date );
		return array(
			'name'       => self::name_for( (int) substr( $start, 0, 4 ) ),
			'slug'       => self::slug_for( (int) substr( $start, 0, 4 ) ),
			'start_date' => $start,
			'end_date'   => self::plus_one_year( $end_date ),
		);
	}

	/**
	 * Vorschlag für die erste Saison einer Installation: die Saison, die am nächsten 1. August
	 * beginnt (am 1. August selbst die eben beginnende).
	 *
	 * @param string $today Heutiger Tag.
	 * @return array{name:string,slug:string,start_date:string,end_date:string}
	 */
	public static function upcoming( string $today ): array {
		$year = (int) substr( $today, 0, 4 );
		if ( substr( $today, 5 ) > '08-01' ) {
			++$year;
		}
		return array(
			'name'       => self::name_for( $year ),
			'slug'       => self::slug_for( $year ),
			'start_date' => $year . '-08-01',
			'end_date'   => ( $year + 1 ) . '-07-31',
		);
	}

	/**
	 * Prüft die Angaben einer neuen Saison ohne Vorgängerin. Der Name hat die Form «2027/28»:
	 * das Jahr des Beginns und die zwei letzten Ziffern des Folgejahrs.
	 *
	 * @return list<array{field:string,message:string}>
	 */
	public static function errors( string $name, string $start_date, string $end_date ): array {
		$errors = array();
		$year   = self::year_of_name( $name );
		if ( null === $year ) {
			$errors[] = self::error( 'name', 'Der Name hat die Form «2027/28»: Jahr des Beginns, Schrägstrich, die zwei letzten Ziffern des Folgejahrs.' );
		}
		if ( ! self::is_date( $start_date ) ) {
			$errors[] = self::error( 'start_date', 'Der Beginn ist kein gültiges Datum.' );
		}
		if ( ! self::is_date( $end_date ) ) {
			$errors[] = self::error( 'end_date', 'Das Ende ist kein gültiges Datum.' );
		}
		if ( array() !== $errors ) {
			return $errors;
		}
		if ( $end_date <= $start_date ) {
			$errors[] = self::error( 'end_date', 'Das Ende muss nach dem Beginn liegen.' );
		} elseif ( $end_date > self::plus_one_year( $start_date ) ) {
			$errors[] = self::error( 'end_date', 'Eine Saison dauert höchstens ein Jahr.' );
		}
		if ( (int) substr( $start_date, 0, 4 ) !== $year ) {
			$errors[] = self::error( 'start_date', 'Der Beginn muss im ersten Jahr des Namens liegen.' );
		}
		return $errors;
	}

	/**
	 * Kürzel zum Namen («2027/28» ergibt «2027-28»), null bei einem ungültigen Namen.
	 */
	public static function slug_of_name( string $name ): ?string {
		$year = self::year_of_name( $name );
		return null === $year ? null : self::slug_for( $year );
	}

	/**
	 * Prüft Meldefenster und Frist der Nachmeldung. Jedes Datum darf fehlen. Das Fenster endet
	 * nicht vor seinem Beginn (ein einzelner Tag ist erlaubt) und nicht ohne Beginn; es schliesst
	 * vor dem Saisonende. Die Nachmeldung endet in der Saison, also vom ersten bis zum letzten
	 * Tag, und nicht vor dem Ende des Meldefensters.
	 *
	 * @param string|null $opens        Erster Tag des Meldefensters.
	 * @param string|null $closes       Letzter Tag des Meldefensters.
	 * @param string|null $late_until   Letzter Tag der Nachmeldung.
	 * @param string      $season_start Beginn der Saison.
	 * @param string      $season_end   Ende der Saison.
	 * @return list<array{field:string,message:string}>
	 */
	public static function window_errors( ?string $opens, ?string $closes, ?string $late_until, string $season_start, string $season_end ): array {
		$errors = array();
		foreach ( array(
			'registration_opens'      => $opens,
			'registration_closes'     => $closes,
			'late_registration_until' => $late_until,
		) as $field => $value ) {
			if ( null !== $value && ! self::is_date( $value ) ) {
				$errors[] = self::error( $field, 'Das ist kein gültiges Datum.' );
			}
		}
		if ( array() !== $errors ) {
			return $errors;
		}
		if ( null !== $closes && null === $opens ) {
			$errors[] = self::error( 'registration_opens', 'Ohne ersten Tag gibt es kein Meldefenster.' );
		}
		if ( null !== $opens && null !== $closes && $closes < $opens ) {
			$errors[] = self::error( 'registration_closes', 'Das Meldefenster darf nicht vor seinem Beginn enden.' );
		}
		foreach ( array(
			'registration_opens'  => $opens,
			'registration_closes' => $closes,
		) as $field => $value ) {
			if ( null !== $value && $value > $season_end ) {
				$errors[] = self::error( $field, 'Das Meldefenster muss vor dem Ende der Saison liegen.' );
			}
		}
		if ( null !== $late_until ) {
			if ( $late_until < $season_start || $late_until > $season_end ) {
				$errors[] = self::error( 'late_registration_until', 'Die Frist der Nachmeldung muss in der Saison liegen.' );
			} elseif ( null !== $closes && $late_until < $closes ) {
				$errors[] = self::error( 'late_registration_until', 'Die Frist der Nachmeldung darf nicht vor dem Ende des Meldefensters liegen.' );
			}
		}
		return $errors;
	}

	/**
	 * Gültiges Datum JJJJ-MM-TT?
	 */
	public static function is_date( string $value ): bool {
		if ( 1 !== preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/D', $value, $m ) ) {
			return false;
		}
		return checkdate( (int) $m[2], (int) $m[3], (int) $m[1] );
	}

	/**
	 * Jahr des Beginns aus einem Namen der Form «2027/28», sonst null.
	 */
	private static function year_of_name( string $name ): ?int {
		if ( 1 !== preg_match( '/^(\d{4})\/(\d{2})$/D', $name, $m ) ) {
			return null;
		}
		$year = (int) $m[1];
		return ( $year + 1 ) % 100 === (int) $m[2] ? $year : null;
	}

	private static function name_for( int $year ): string {
		return sprintf( '%04d/%02d', $year, ( $year + 1 ) % 100 );
	}

	private static function slug_for( int $year ): string {
		return sprintf( '%04d-%02d', $year, ( $year + 1 ) % 100 );
	}

	/**
	 * Derselbe Tag ein Jahr später; der 29. Februar wird zum 28. Februar.
	 */
	private static function plus_one_year( string $date ): string {
		$year  = (int) substr( $date, 0, 4 ) + 1;
		$month = (int) substr( $date, 5, 2 );
		$day   = (int) substr( $date, 8, 2 );
		if ( ! checkdate( $month, $day, $year ) ) {
			--$day;
		}
		return sprintf( '%04d-%02d-%02d', $year, $month, $day );
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
