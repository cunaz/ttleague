<?php
/**
 * Eingabe einer Resultaterfassung (aus dem JSON der REST-API), strukturell geprüft.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Results;

/**
 * Prüft nur Form und Typen (Slots, Zahlen, Status). Ob Spieler berechtigt sind und die
 * Spiele zusammenpassen, prüft der ResultValidator.
 */
final class ResultInput {

	public const STATUSES = array( 'played', 'walkover', 'not_played' );

	/**
	 * @param array<string,int|null> $home    Slot => Spieler-ID.
	 * @param array<string,int|null> $away    Slot => Spieler-ID.
	 * @param array<int,GameInput>   $games   Sequenz => Spiel.
	 * @param int|null               $photo_id Anhang mit dem Foto des Spielberichts.
	 * @param string|null            $comment Bemerkung.
	 * @param string|null            $version Erwarteter Stand (gegen gleichzeitige Änderungen).
	 */
	public function __construct(
		public readonly array $home,
		public readonly array $away,
		public readonly array $games,
		public readonly ?int $photo_id = null,
		public readonly ?string $comment = null,
		public readonly ?string $version = null,
	) {}

	/**
	 * @param array<string,mixed> $data       Rohdaten.
	 * @param list<string>        $home_slots Erlaubte Slots Heim.
	 * @param list<string>        $away_slots Erlaubte Slots Gast.
	 * @param list<int>           $sequences  Erlaubte Spielnummern.
	 * @throws ResultException Bei Formfehlern (422).
	 */
	public static function from_array( array $data, array $home_slots, array $away_slots, array $sequences ): self {
		$errors = array();
		$lineup = $data['lineup'] ?? null;
		if ( ! is_array( $lineup ) ) {
			$errors[] = self::error( 'lineup', __( 'Die Aufstellung fehlt.', 'ttleague' ) );
			$lineup   = array();
		}
		$home = self::slots( $lineup['home'] ?? array(), $home_slots, 'lineup.home', $errors );
		$away = self::slots( $lineup['away'] ?? array(), $away_slots, 'lineup.away', $errors );

		$games = array();
		$raw   = $data['games'] ?? null;
		if ( ! is_array( $raw ) || ! array_is_list( $raw ) ) {
			$errors[] = self::error( 'games', __( 'Die Spiele fehlen.', 'ttleague' ) );
			$raw      = array();
		}
		foreach ( $raw as $i => $game ) {
			$field = 'games.' . $i;
			if ( ! is_array( $game ) ) {
				$errors[] = self::error( $field, __( 'Ungültiges Spiel.', 'ttleague' ) );
				continue;
			}
			$sequence = self::int( $game['sequence'] ?? null );
			if ( null === $sequence || ! in_array( $sequence, $sequences, true ) ) {
				$errors[] = self::error( $field . '.sequence', __( 'Unbekannte Spielnummer.', 'ttleague' ) );
				continue;
			}
			$field = 'games.' . $sequence;
			if ( isset( $games[ $sequence ] ) ) {
				/* translators: %d: Spielnummer */
				$errors[] = self::error( $field, sprintf( __( 'Spiel %d ist doppelt erfasst.', 'ttleague' ), $sequence ) );
				continue;
			}
			$parsed = GameInput::from_array( $sequence, $game, $field, $errors );
			if ( null !== $parsed ) {
				$games[ $sequence ] = $parsed;
			}
		}
		ksort( $games );

		$photo = $data['photo_id'] ?? null;
		if ( null !== $photo && null === self::int( $photo ) ) {
			$errors[] = self::error( 'photo_id', __( 'Ungültiges Foto.', 'ttleague' ) );
		}
		$comment = self::text( $data['comment'] ?? null );
		if ( null !== $comment && mb_strlen( $comment ) > 2000 ) {
			$errors[] = self::error( 'comment', __( 'Die Bemerkung ist zu lang (höchstens 2000 Zeichen).', 'ttleague' ) );
		}
		$version = isset( $data['version'] ) && is_string( $data['version'] ) && '' !== $data['version'] ? $data['version'] : null;

		if ( array() !== $errors ) {
			throw ResultException::invalid( $errors );
		}
		return new self( $home, $away, $games, null === $photo ? null : self::int( $photo ), $comment, $version );
	}

	/**
	 * Spieler einer Seite ohne leere Slots.
	 *
	 * @return array<string,int>
	 */
	public function present( string $side ): array {
		return array_filter( 'home' === $side ? $this->home : $this->away, static fn( ?int $id ): bool => null !== $id );
	}

	/**
	 * @param array{field:string,message:string}[] $errors Fehlerliste (wird ergänzt).
	 * @return array<string,int|null>
	 */
	private static function slots( mixed $raw, array $allowed, string $field, array &$errors ): array {
		$result = array_fill_keys( $allowed, null );
		if ( ! is_array( $raw ) ) {
			$errors[] = self::error( $field, __( 'Ungültige Aufstellung.', 'ttleague' ) );
			return $result;
		}
		foreach ( $raw as $slot => $player ) {
			$slot = strtoupper( (string) $slot );
			if ( ! in_array( $slot, $allowed, true ) ) {
				/* translators: %s: Slot */
				$errors[] = self::error( $field . '.' . $slot, sprintf( __( 'Unbekannter Platz %s.', 'ttleague' ), $slot ) );
				continue;
			}
			if ( null === $player || '' === $player || 0 === $player ) {
				continue;
			}
			$id = self::int( $player );
			if ( null === $id ) {
				$errors[] = self::error( $field . '.' . $slot, __( 'Ungültiger Spieler.', 'ttleague' ) );
				continue;
			}
			$result[ $slot ] = $id;
		}
		return $result;
	}

	/**
	 * Positive Ganzzahl oder null.
	 */
	public static function int( mixed $value ): ?int {
		if ( is_int( $value ) ) {
			return $value > 0 ? $value : null;
		}
		if ( is_string( $value ) && 1 === preg_match( '/^[1-9][0-9]{0,18}$/', $value ) ) {
			return (int) $value;
		}
		return null;
	}

	/**
	 * Getrimmter Text oder null.
	 */
	public static function text( mixed $value ): ?string {
		if ( ! is_string( $value ) ) {
			return null;
		}
		$value = trim( $value );
		return '' === $value ? null : $value;
	}

	/**
	 * @return array{field:string,message:string}
	 */
	public static function error( string $field, string $message ): array {
		return array(
			'field'   => $field,
			'message' => $message,
		);
	}
}
