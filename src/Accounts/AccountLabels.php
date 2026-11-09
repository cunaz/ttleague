<?php
/**
 * Texte zu Kontostatus und Funktionen für die Listen «Konten und Einladungen» und «Mein Club».
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Accounts;

final class AccountLabels {

	public static function status( string $status ): string {
		return match ( $status ) {
			ClubPeople::NO_EMAIL   => __( 'keine E-Mail', 'ttleague' ),
			ClubPeople::NO_ACCOUNT => __( 'kein Konto', 'ttleague' ),
			ClubPeople::INVITED    => __( 'eingeladen', 'ttleague' ),
			ClubPeople::ACCOUNT    => __( 'Konto, nie angemeldet', 'ttleague' ),
			ClubPeople::ACTIVE     => __( 'aktiv', 'ttleague' ),
			default                => $status,
		};
	}

	/**
	 * Zusatz zum Status: letzte Anmeldung oder Gültigkeit der offenen Einladung.
	 *
	 * @param array{status:string,last_login:int|null,consent_at:string|null,invited_until:string|null} $person Person aus ClubPeople::for_club().
	 */
	public static function status_detail( array $person ): string {
		if ( ClubPeople::ACTIVE === $person['status'] && null !== $person['last_login'] ) {
			/* translators: %s: Datum und Zeit */
			return sprintf( __( 'zuletzt angemeldet am %s', 'ttleague' ), (string) wp_date( 'j.n.Y, H:i', $person['last_login'] ) );
		}
		if ( ClubPeople::ACTIVE === $person['status'] && null !== $person['consent_at'] ) {
			/* translators: %s: Datum und Zeit */
			return sprintf( __( 'erstmals angemeldet am %s', 'ttleague' ), (string) wp_date( 'j.n.Y, H:i', self::timestamp( $person['consent_at'] ) ) );
		}
		if ( null !== $person['invited_until'] && ClubPeople::NO_EMAIL !== $person['status'] ) {
			/* translators: %s: Datum und Zeit */
			return sprintf( __( 'Einladung gültig bis %s', 'ttleague' ), (string) wp_date( 'j.n.Y, H:i', self::timestamp( $person['invited_until'] ) ) );
		}
		return '';
	}

	private static function timestamp( string $utc ): int {
		return ( new \DateTimeImmutable( $utc, new \DateTimeZone( 'UTC' ) ) )->getTimestamp();
	}

	/**
	 * Funktionen in einer Zeile: «TK-Chef · Captain Seefeld 1 · Spieler».
	 *
	 * @param array{functions:list<array{role:string,team:string|null}>,player:bool} $person Person.
	 */
	public static function functions( array $person ): string {
		$parts = array();
		foreach ( $person['functions'] as $function ) {
			if ( 'tk_chef' === $function['role'] ) {
				$parts[] = __( 'TK-Chef', 'ttleague' );
			} else {
				/* translators: %s: Team */
				$parts[] = null === $function['team'] ? __( 'Captain', 'ttleague' ) : sprintf( __( 'Captain %s', 'ttleague' ), $function['team'] );
			}
		}
		if ( $person['player'] ) {
			$parts[] = __( 'Spieler', 'ttleague' );
		}
		return implode( ' · ', array_unique( $parts ) );
	}

	/**
	 * Beschriftung der Einladung: «Einladen» oder «Erneut einladen».
	 *
	 * @param array{status:string} $person Person.
	 */
	public static function invite_button( array $person ): string {
		return ClubPeople::NO_ACCOUNT === $person['status'] ? __( 'Einladen', 'ttleague' ) : __( 'Erneut einladen', 'ttleague' );
	}

	/**
	 * Zusammenfassung einer Liste: «12 Personen · 3 ohne E-Mail · 4 ohne Konto · …».
	 *
	 * @param list<array{status:string}> $people Personen.
	 */
	public static function summary( array $people ): string {
		$count = array_count_values( array_column( $people, 'status' ) );
		/* translators: %d: Anzahl */
		$parts = array( sprintf( _n( '%d Person', '%d Personen', count( $people ), 'ttleague' ), count( $people ) ) );
		foreach ( array(
			/* translators: %d: Anzahl */
			ClubPeople::NO_EMAIL   => __( '%d ohne E-Mail', 'ttleague' ),
			/* translators: %d: Anzahl */
			ClubPeople::NO_ACCOUNT => __( '%d ohne Konto', 'ttleague' ),
			/* translators: %d: Anzahl */
			ClubPeople::INVITED    => __( '%d eingeladen', 'ttleague' ),
			/* translators: %d: Anzahl */
			ClubPeople::ACCOUNT    => __( '%d nie angemeldet', 'ttleague' ),
			/* translators: %d: Anzahl */
			ClubPeople::ACTIVE     => __( '%d aktiv', 'ttleague' ),
		) as $status => $label ) {
			if ( ! empty( $count[ $status ] ) ) {
				$parts[] = sprintf( $label, $count[ $status ] );
			}
		}
		return implode( ' · ', $parts );
	}
}
