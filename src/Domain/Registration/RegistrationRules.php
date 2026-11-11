<?php
/**
 * Regeln der Online-Meldung der Clubs (Art. 12, 26 bis 29 des Wettspielreglements).
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Domain\Registration;

use TTLeague\Domain\Rating\ClassificationScale;

/**
 * Reine Prüfungen ohne Datenbank. Ein Verstoss ist ein Array mit «code», «field» (wo er in der
 * Meldung steht, z. B. team.12.players) und «message». Verstösse verhindern das Speichern
 * nicht, aber das Einreichen (may_submit). Daten (Datum) sind Kalendertage in Ortszeit als
 * JJJJ-MM-TT.
 *
 * Die Zahl der Stammspieler und die Tage der Erinnerung sind Parameter mit den Werten des
 * Reglements der Region Zürich als Standard.
 */
final class RegistrationRules {

	/** Stammspieler pro Mannschaft (Art. 12.2). */
	public const STAMM_SIZE = 3;

	/** Erinnerung so viele Tage vor Ablauf des Meldefensters. */
	public const REMINDER_DAYS = array( 7, 2 );

	/** Frist der Nachmeldung ohne eigenen Wert der Saison: 31. Januar (Art. 27.3). */
	public const LATE_REGISTRATION_DEFAULT = '01-31';

	public const OPEN      = 'open';
	public const SUBMITTED = 'submitted';
	public const REVIEWED  = 'reviewed';

	/** Spieltage eines Lokals: Montag (1) bis Freitag (5). */
	private const WEEKDAY_MIN = 1;
	private const WEEKDAY_MAX = 5;

	/**
	 * Mannschaften einer Meldung: genau $stamm_size Stammspieler, niemand Stammspieler in zwei
	 * Mannschaften, Stammspieler für den Club gemeldet, dazu Heimlokal, Anspielzeit und ein
	 * Captain mit E-Mail-Adresse.
	 *
	 * @param list<array{id:int,name:string,players:list<int>,home_venue_id:int|null,start_time:string|null,captain:array{name:string,email:string|null}|null}> $teams      Mannschaften.
	 * @param list<int>                                                                                                                                           $registered Für den Club in dieser Saison gemeldete Spieler.
	 * @param array<int,string>                                                                                                                                   $names      Spieler-ID => Name für die Meldungen.
	 * @param int                                                                                                                                                 $stamm_size Stammspieler pro Mannschaft.
	 * @return list<array{code:string,field:string,message:string}>
	 */
	public static function teams( array $teams, array $registered, array $names = array(), int $stamm_size = self::STAMM_SIZE ): array {
		$violations = array();
		$name       = static fn( int $id ): string => $names[ $id ] ?? 'Spieler ' . $id;
		$seen       = array();
		foreach ( $teams as $team ) {
			$field   = 'team.' . $team['id'] . '.players';
			$players = array_values( array_unique( $team['players'] ) );
			if ( count( $players ) !== $stamm_size ) {
				$violations[] = self::violation( 'stamm_count', $field, sprintf( '%s: Es braucht genau %d Stammspieler, gemeldet sind %d.', $team['name'], $stamm_size, count( $players ) ) );
			}
			foreach ( $players as $player_id ) {
				if ( isset( $seen[ $player_id ] ) ) {
					$violations[] = self::violation( 'stamm_duplicate', $field, sprintf( '%s ist Stammspieler in %s und %s. Erlaubt ist nur eine Mannschaft (Art. 12.2).', $name( $player_id ), $seen[ $player_id ], $team['name'] ) );
				} else {
					$seen[ $player_id ] = $team['name'];
				}
				if ( ! in_array( $player_id, $registered, true ) ) {
					$violations[] = self::violation( 'stamm_not_registered', $field, sprintf( '%s: %s ist für den Club nicht gemeldet.', $team['name'], $name( $player_id ) ) );
				}
			}
			if ( null === $team['home_venue_id'] ) {
				$violations[] = self::violation( 'venue_missing', 'team.' . $team['id'] . '.venue', sprintf( '%s: Das Heimlokal fehlt.', $team['name'] ) );
			}
			if ( null === $team['start_time'] || '' === $team['start_time'] ) {
				$violations[] = self::violation( 'start_missing', 'team.' . $team['id'] . '.start', sprintf( '%s: Die Anspielzeit fehlt.', $team['name'] ) );
			}
			if ( null === $team['captain'] ) {
				$violations[] = self::violation( 'captain_missing', 'team.' . $team['id'] . '.captain', sprintf( '%s: Der Captain fehlt.', $team['name'] ) );
			} elseif ( ! self::is_email( $team['captain']['email'] ) ) {
				$violations[] = self::violation( 'captain_email', 'team.' . $team['id'] . '.captain', sprintf( '%s: Captain %s braucht eine E-Mail-Adresse.', $team['name'], $team['captain']['name'] ) );
			}
		}
		return $violations;
	}

	/**
	 * Ein Spieler ist pro Saison nur für einen Club gemeldet (Art. 27.2).
	 *
	 * @param int      $club_id         Club, der melden will.
	 * @param int|null $registered_club Club, für den der Spieler in der Saison schon gemeldet ist.
	 */
	public static function other_club( int $club_id, ?int $registered_club ): bool {
		return null !== $registered_club && $registered_club !== $club_id;
	}

	/**
	 * Ist das Meldefenster der geplanten Saison offen? Beide Tage zählen dazu. Ohne Beginn gibt es
	 * kein Fenster; ohne Ende bleibt es offen, bis die TK eines setzt.
	 */
	public static function window_open( string $today, ?string $opens, ?string $closes ): bool {
		if ( ! self::is_date( $today ) || null === $opens || ! self::is_date( $opens ) ) {
			return false;
		}
		if ( $today < $opens ) {
			return false;
		}
		return null === $closes || ( self::is_date( $closes ) && $today <= $closes );
	}

	/**
	 * Letzter Tag der Nachmeldung: der Wert der Saison, sonst der 31. Januar im Jahr des Saisonendes.
	 *
	 * @param string|null $until      season.late_registration_until.
	 * @param string      $season_end season.end_date.
	 */
	public static function late_registration_deadline( ?string $until, string $season_end ): string {
		if ( null !== $until && self::is_date( $until ) ) {
			return $until;
		}
		return substr( $season_end, 0, 4 ) . '-' . self::LATE_REGISTRATION_DEFAULT;
	}

	/**
	 * Nachmeldungen sind bis und mit dem letzten Tag möglich (Art. 27.3), danach nur durch die TK.
	 */
	public static function late_registration_open( string $today, string $deadline ): bool {
		return self::is_date( $today ) && self::is_date( $deadline ) && $today <= $deadline;
	}

	/**
	 * Lokaldaten: Name, Spieltag Montag bis Freitag, mindestens ein Tisch, gültige Zeiten.
	 *
	 * @param array{name?:string|null,weekday?:int|null,tables_count?:int|null,open_time?:string|null,close_time?:string|null} $venue Lokal.
	 * @return list<array{code:string,field:string,message:string}>
	 */
	public static function venue( array $venue ): array {
		$violations = array();
		if ( '' === trim( (string) ( $venue['name'] ?? '' ) ) ) {
			$violations[] = self::violation( 'venue_name', 'name', 'Der Name des Lokals fehlt.' );
		}
		$weekday = $venue['weekday'] ?? null;
		if ( null === $weekday || $weekday < self::WEEKDAY_MIN || $weekday > self::WEEKDAY_MAX ) {
			$violations[] = self::violation( 'venue_weekday', 'weekday', 'Der Spieltag liegt zwischen Montag und Freitag.' );
		}
		$tables = $venue['tables_count'] ?? null;
		if ( null === $tables || $tables < 1 ) {
			$violations[] = self::violation( 'venue_tables', 'tables_count', 'Das Lokal braucht mindestens einen Tisch.' );
		}
		$open  = $venue['open_time'] ?? null;
		$close = $venue['close_time'] ?? null;
		if ( null !== $open && '' !== $open && ! self::is_time( $open ) ) {
			$violations[] = self::violation( 'venue_open', 'open_time', 'Die Öffnungszeit ist ungültig (HH:MM).' );
		} elseif ( null !== $close && '' !== $close && ! self::is_time( $close ) ) {
			$violations[] = self::violation( 'venue_close', 'close_time', 'Die Schliesszeit ist ungültig (HH:MM).' );
		} elseif ( null !== $open && '' !== $open && null !== $close && '' !== $close && substr( $close, 0, 5 ) <= substr( $open, 0, 5 ) ) {
			$violations[] = self::violation( 'venue_close', 'close_time', 'Die Schliesszeit liegt nach der Öffnungszeit.' );
		}
		return $violations;
	}

	/**
	 * Hallensperre: gültige Tage, «bis» nicht vor «von». Ein einzelner Tag ist erlaubt.
	 *
	 * @return list<array{code:string,field:string,message:string}>
	 */
	public static function closure( string $from, string $to ): array {
		if ( ! self::is_date( $from ) ) {
			return array( self::violation( 'closure_from', 'date_from', 'Das Datum «von» ist ungültig.' ) );
		}
		if ( ! self::is_date( $to ) ) {
			return array( self::violation( 'closure_to', 'date_to', 'Das Datum «bis» ist ungültig.' ) );
		}
		if ( $to < $from ) {
			return array( self::violation( 'closure_order', 'date_to', '«bis» darf nicht vor «von» liegen.' ) );
		}
		return array();
	}

	/**
	 * Meldung einer neuen Person: Name und Klassierung sind Pflicht, die E-Mail-Adresse ist
	 * freiwillig, aber gültig.
	 *
	 * @param array{last_name?:string|null,first_name?:string|null,email?:string|null,classification?:int|null,stt_license?:string|null} $request Angaben.
	 * @return list<array{code:string,field:string,message:string}>
	 */
	public static function request( array $request ): array {
		$violations = array();
		foreach ( array(
			'last_name'  => 'Der Nachname fehlt.',
			'first_name' => 'Der Vorname fehlt.',
		) as $field => $message ) {
			$value = trim( (string) ( $request[ $field ] ?? '' ) );
			if ( '' === $value ) {
				$violations[] = self::violation( 'request_' . $field, $field, $message );
			} elseif ( mb_strlen( $value ) > 80 ) {
				$violations[] = self::violation( 'request_' . $field, $field, 'Der Name ist zu lang (höchstens 80 Zeichen).' );
			}
		}
		$email = trim( (string) ( $request['email'] ?? '' ) );
		if ( '' !== $email && ! self::is_email( $email ) ) {
			$violations[] = self::violation( 'request_email', 'email', 'Die E-Mail-Adresse ist ungültig.' );
		}
		if ( ! self::is_classification( $request['classification'] ?? null ) ) {
			$violations[] = self::violation( 'request_classification', 'classification', sprintf( 'Die Klassierung liegt zwischen %d und %d.', ClassificationScale::MIN, ClassificationScale::MAX ) );
		}
		if ( mb_strlen( trim( (string) ( $request['stt_license'] ?? '' ) ) ) > 20 ) {
			$violations[] = self::violation( 'request_stt_license', 'stt_license', 'Die STT-Lizenz ist zu lang (höchstens 20 Zeichen).' );
		}
		return $violations;
	}

	public static function is_classification( ?int $classification ): bool {
		return null !== $classification && $classification >= ClassificationScale::MIN && $classification <= ClassificationScale::MAX;
	}

	/**
	 * Einreichen geht nur aus dem Stand «offen» und ohne Verstoss.
	 *
	 * @param list<array{code:string,field:string,message:string}> $violations Offene Verstösse.
	 */
	public static function may_submit( string $status, array $violations ): bool {
		return self::OPEN === $status && array() === $violations;
	}

	/**
	 * Fällige Stufe der Erinnerung vor Ablauf des Meldefensters: die kleinste Zahl Tage, die
	 * schon erreicht ist (7 Tage vorher bis 3 Tage vorher die Stufe 7, ab 2 Tagen vorher bis zum
	 * letzten Tag die Stufe 2). Vor der ersten Stufe, nach dem Ablauf und bei geschlossenem
	 * Fenster gibt es keine.
	 *
	 * @param list<int> $days Stufen in Tagen vor dem letzten Tag.
	 */
	public static function reminder_step( string $today, ?string $opens, ?string $closes, array $days = self::REMINDER_DAYS ): ?int {
		if ( null === $closes || ! self::window_open( $today, $opens, $closes ) ) {
			return null;
		}
		$left = (int) ( new \DateTimeImmutable( $today ) )->diff( new \DateTimeImmutable( $closes ) )->days;
		$step = null;
		foreach ( $days as $day ) {
			if ( $day >= 0 && $left <= $day && ( null === $step || $day < $step ) ) {
				$step = $day;
			}
		}
		return $step;
	}

	/**
	 * Gültiger Kalendertag JJJJ-MM-TT.
	 */
	public static function is_date( string $value ): bool {
		$date = \DateTimeImmutable::createFromFormat( '!Y-m-d', $value );
		return false !== $date && $date->format( 'Y-m-d' ) === $value;
	}

	/**
	 * Gültige Uhrzeit HH:MM oder HH:MM:SS.
	 */
	public static function is_time( string $value ): bool {
		return 1 === preg_match( '/^(?:[01]\d|2[0-3]):[0-5]\d(?::[0-5]\d)?$/D', $value );
	}

	public static function is_email( ?string $email ): bool {
		$email = trim( (string) $email );
		return '' !== $email && strlen( $email ) <= 190 && false !== filter_var( $email, FILTER_VALIDATE_EMAIL );
	}

	/**
	 * @return array{code:string,field:string,message:string}
	 */
	private static function violation( string $code, string $field, string $message ): array {
		return array(
			'code'    => $code,
			'field'   => $field,
			'message' => $message,
		);
	}
}
