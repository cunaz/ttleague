<?php
/**
 * Eingabe eines Anlasses: prüfen und in Spaltenwerte umsetzen.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Events;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Meldungen sind Klartext für die Rückmeldung (Flash) und werden bei der Ausgabe escaped.

use TTLeague\Domain\Events\RegistrationRules;

/**
 * Termine kommen als Ortszeit der Website, wie sie ein Feld «datetime-local» liefert
 * («2026-11-20T19:30»), und werden als «Y-m-d H:i:s» gespeichert.
 */
final class EventInput {

	public const TITLE_LENGTH    = 160;
	public const LOCATION_LENGTH = 160;

	/** Grösster Wert der Spalte max_participants (smallint unsigned). */
	public const MAX_LIMIT = 65535;

	/**
	 * @param array<string,mixed> $input Felder season_id, type, title, starts_at, ends_at, location, deadline, max_participants, post_id.
	 * @return array{season_id:int|null,type:string,title:string,starts_at:string,ends_at:string|null,location:string|null,deadline:string|null,max_participants:int|null,post_id:int|null}
	 * @throws EventException Mit Feldfehlern (422).
	 */
	public static function validate( array $input ): array {
		$errors = array();
		$error  = static function ( string $field, string $message ) use ( &$errors ): void {
			$errors[] = array(
				'field'   => $field,
				'message' => $message,
			);
		};

		$title = self::text( $input['title'] ?? '' );
		if ( '' === $title ) {
			$error( 'title', __( 'Bitte gib einen Titel an.', 'ttleague' ) );
		} elseif ( mb_strlen( $title ) > self::TITLE_LENGTH ) {
			/* translators: %d: Zeichen */
			$error( 'title', sprintf( __( 'Der Titel ist länger als %d Zeichen.', 'ttleague' ), self::TITLE_LENGTH ) );
		}

		$type = is_string( $input['type'] ?? null ) ? $input['type'] : '';
		if ( ! in_array( $type, RegistrationRules::TYPES, true ) ) {
			$error( 'type', __( 'Bitte wähle eine Art.', 'ttleague' ) );
		}

		$starts_at = self::datetime( $input['starts_at'] ?? '' );
		if ( null === $starts_at ) {
			$error( 'starts_at', __( 'Bitte gib den Beginn mit Datum und Zeit an.', 'ttleague' ) );
		} elseif ( false === $starts_at ) {
			$error( 'starts_at', __( 'Der Beginn ist kein gültiger Zeitpunkt.', 'ttleague' ) );
		}

		$ends_at = self::datetime( $input['ends_at'] ?? '' );
		if ( false === $ends_at ) {
			$error( 'ends_at', __( 'Das Ende ist kein gültiger Zeitpunkt.', 'ttleague' ) );
		} elseif ( is_string( $ends_at ) && is_string( $starts_at ) && $ends_at < $starts_at ) {
			$error( 'ends_at', __( 'Das Ende liegt vor dem Beginn.', 'ttleague' ) );
		}

		$deadline = self::datetime( $input['deadline'] ?? '' );
		if ( false === $deadline ) {
			$error( 'deadline', __( 'Der Anmeldeschluss ist kein gültiger Zeitpunkt.', 'ttleague' ) );
		} elseif ( is_string( $deadline ) && is_string( $starts_at ) && $deadline > $starts_at ) {
			$error( 'deadline', __( 'Der Anmeldeschluss liegt nach dem Beginn.', 'ttleague' ) );
		}

		$location = self::text( $input['location'] ?? '' );
		if ( mb_strlen( $location ) > self::LOCATION_LENGTH ) {
			/* translators: %d: Zeichen */
			$error( 'location', sprintf( __( 'Der Ort ist länger als %d Zeichen.', 'ttleague' ), self::LOCATION_LENGTH ) );
		}

		$max = self::whole_number( $input['max_participants'] ?? '' );
		if ( false === $max || ( null !== $max && ( $max < 1 || $max > self::MAX_LIMIT ) ) ) {
			/* translators: %d: grösste Höchstzahl */
			$error( 'max_participants', sprintf( __( 'Die Höchstzahl ist eine ganze Zahl von 1 bis %d; leer heisst unbegrenzt.', 'ttleague' ), self::MAX_LIMIT ) );
		}

		$post_id = self::whole_number( $input['post_id'] ?? '' );
		if ( false === $post_id ) {
			$error( 'post_id', __( 'Die Ausschreibung ist kein gültiger Beitrag.', 'ttleague' ) );
		}

		$season_id = self::whole_number( $input['season_id'] ?? '' );
		if ( false === $season_id ) {
			$error( 'season_id', __( 'Diese Saison gibt es nicht.', 'ttleague' ) );
		}

		if ( array() !== $errors ) {
			$message = 1 === count( $errors ) ? $errors[0]['message'] : __( 'Die Eingabe enthält Fehler.', 'ttleague' );
			throw EventException::invalid( $errors, $message );
		}

		return array(
			'season_id'        => 0 === $season_id ? null : $season_id,
			'type'             => $type,
			'title'            => $title,
			'starts_at'        => (string) $starts_at,
			'ends_at'          => is_string( $ends_at ) ? $ends_at : null,
			'location'         => '' === $location ? null : $location,
			'deadline'         => is_string( $deadline ) ? $deadline : null,
			'max_participants' => $max,
			'post_id'          => 0 === $post_id ? null : $post_id,
		);
	}

	/**
	 * Bemerkung zu einer Anmeldung: eine Zeile, höchstens $length Zeichen, leer wird null.
	 */
	public static function note( ?string $note, int $length = 200 ): ?string {
		$note = self::text( $note ?? '' );
		return '' === $note ? null : mb_substr( $note, 0, $length );
	}

	/**
	 * Zeitpunkt als «Y-m-d H:i:s»; null bei leerer Eingabe, false bei ungültiger.
	 */
	public static function datetime( mixed $value ): string|false|null {
		if ( null === $value || ( is_string( $value ) && '' === trim( $value ) ) ) {
			return null;
		}
		if ( ! is_string( $value ) || 1 !== preg_match( '/^(\d{4})-(\d{2})-(\d{2})[T ](\d{2}):(\d{2})(?::(\d{2}))?$/D', trim( $value ), $m ) ) {
			return false;
		}
		$second = (int) ( $m[6] ?? 0 );
		if ( (int) $m[1] < 1970 || ! checkdate( (int) $m[2], (int) $m[3], (int) $m[1] ) || (int) $m[4] > 23 || (int) $m[5] > 59 || $second > 59 ) {
			return false;
		}
		return sprintf( '%s-%s-%s %s:%s:%02d', $m[1], $m[2], $m[3], $m[4], $m[5], $second );
	}

	/**
	 * Ganze Zahl ab 0; null bei leerer Eingabe, false bei ungültiger.
	 */
	private static function whole_number( mixed $value ): int|false|null {
		if ( null === $value || ( is_string( $value ) && '' === trim( $value ) ) ) {
			return null;
		}
		if ( is_int( $value ) ) {
			return $value >= 0 ? $value : false;
		}
		if ( ! is_string( $value ) || 1 !== preg_match( '/^\d{1,9}$/D', trim( $value ) ) ) {
			return false;
		}
		return (int) trim( $value );
	}

	/**
	 * Einzeiliger Text ohne Steuerzeichen und mehrfache Leerzeichen.
	 */
	private static function text( mixed $value ): string {
		if ( ! is_string( $value ) ) {
			return '';
		}
		return trim( (string) preg_replace( '/[\s\x00-\x1F\x7F]+/u', ' ', $value ) );
	}
}
