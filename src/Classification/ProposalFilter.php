<?php
/**
 * Filter der Klassierungstabelle: Club, «Vorschlag weicht ab», «noch offen».
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Classification;

final class ProposalFilter {

	/**
	 * @param int  $club_id   Nur Spieler dieses Clubs (0: alle).
	 * @param bool $deviating Nur Zeilen, deren Vorschlag von der bisherigen Klassierung abweicht.
	 * @param bool $open      Nur Zeilen ohne Entscheid.
	 */
	public function __construct(
		public readonly int $club_id = 0,
		public readonly bool $deviating = false,
		public readonly bool $open = false,
	) {}

	/**
	 * Aus den Parametern einer Anfrage (club, deviating, open). Unbekanntes und Ungültiges gilt als «kein Filter».
	 *
	 * @param array<int|string,mixed> $args Parameter.
	 */
	public static function from_array( array $args ): self {
		$club = $args['club'] ?? 0;
		$flag = static fn( mixed $value ): bool => is_scalar( $value ) && in_array( (string) $value, array( '1', 'on', 'true', 'yes' ), true );
		return new self(
			is_scalar( $club ) && ctype_digit( (string) $club ) ? (int) $club : 0,
			$flag( $args['deviating'] ?? null ),
			$flag( $args['open'] ?? null ),
		);
	}

	/**
	 * Als Parameter für Adressen und versteckte Felder; nur gesetzte Filter.
	 *
	 * @return array<string,string>
	 */
	public function to_args(): array {
		$args = array();
		if ( $this->club_id > 0 ) {
			$args['club'] = (string) $this->club_id;
		}
		if ( $this->deviating ) {
			$args['deviating'] = '1';
		}
		if ( $this->open ) {
			$args['open'] = '1';
		}
		return $args;
	}

	/**
	 * Derselbe Filter, beschränkt auf Zeilen ohne Entscheid.
	 */
	public function with_open(): self {
		return new self( $this->club_id, $this->deviating, true );
	}
}
