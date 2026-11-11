<?php
/**
 * Eingabe des Regelformulars einer Saison: Felder, Grenzen und Fehler am Feld.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Domain\Season;

use TTLeague\Domain\Rules\SeasonRules;

/**
 * Wandelt die Zeichenketten eines Formulars in die Werte von SeasonRules um und prüft jedes
 * Feld für sich (Typ, Grenzen). Danach entscheidet SeasonRules::from_array() über die
 * Abhängigkeiten zwischen den Feldern. Ein Fehler ist ein Array mit «field» und «message».
 * Gespeichert wird nur ein Ergebnis ohne Fehler.
 *
 * Listen (Punkte pro Rang, Tage der Erinnerung, Kategorien) stehen im Formular als Text mit
 * Kommas, z. B. «6, 5, 4, 3, 2, 1» oder «D».
 */
final class RulesInput {

	public const INT        = 'int';
	public const FLOAT      = 'float';
	public const BOOL       = 'bool';
	public const CHOICE     = 'choice';
	public const INT_LIST   = 'int_list';
	public const CATEGORIES = 'categories';

	/**
	 * Feld => Typ und Grenzen. Die Reihenfolge ist die des Formulars.
	 *
	 * @var array<string,array{type:string,min?:int|float,max?:int|float,choices?:list<string>}>
	 */
	public const FIELDS = array(
		'substitute_max'             => array(
			'type' => self::INT,
			'min'  => 0,
			'max'  => 99,
		),
		'category_any_team'          => array( 'type' => self::CATEGORIES ),
		'entry_deadline_hours'       => array(
			'type' => self::INT,
			'min'  => 1,
			'max'  => 720,
		),
		'objection_days'             => array(
			'type' => self::INT,
			'min'  => 1,
			'max'  => 60,
		),
		'forfeit_after_days'         => array(
			'type' => self::INT,
			'min'  => 1,
			'max'  => 365,
		),
		'postponement_approval_days' => array(
			'type' => self::INT,
			'min'  => 0,
			'max'  => 365,
		),
		'match_reminder_days'        => array(
			'type' => self::INT,
			'min'  => 0,
			'max'  => 30,
		),
		'result_reminder_hours'      => array(
			'type' => self::INT,
			'min'  => 1,
			'max'  => 720,
		),
		'confirm_reminder_days'      => array(
			'type' => self::INT_LIST,
			'min'  => 1,
			'max'  => 60,
		),
		'sets_to_win'                => array(
			'type' => self::INT,
			'min'  => 1,
			'max'  => 5,
		),
		'team_match_games'           => array(
			'type' => self::INT,
			'min'  => 1,
			'max'  => 20,
		),
		'forfeit_score'              => array(
			'type' => self::INT,
			'min'  => 1,
			'max'  => 20,
		),
		'two_player_max_wins'        => array(
			'type' => self::INT,
			'min'  => 0,
			'max'  => 20,
		),
		'set_points_enabled'         => array( 'type' => self::BOOL ),
		'cup_mode'                   => array(
			'type'    => self::CHOICE,
			'choices' => array( SeasonRules::CUP_FIRST_TO_THREE, SeasonRules::CUP_ALL_GAMES ),
		),
		'cup_games'                  => array(
			'type' => self::INT,
			'min'  => 1,
			'max'  => 9,
		),
		'cup_players'                => array(
			'type' => self::INT,
			'min'  => 1,
			'max'  => 4,
		),
		'rl_round_points'            => array(
			'type' => self::INT_LIST,
			'min'  => 0,
			'max'  => 99,
		),
		'rl_seven_player_bonus'      => array(
			'type' => self::INT,
			'min'  => 0,
			'max'  => 10,
		),
		'rl_finalists'               => array(
			'type' => self::INT,
			'min'  => 1,
			'max'  => 64,
		),
		'rl_group_size'              => array(
			'type' => self::INT,
			'min'  => 3,
			'max'  => 20,
		),
		'rl_group_size_max'          => array(
			'type' => self::INT,
			'min'  => 3,
			'max'  => 20,
		),
		'rating_k'                   => array(
			'type' => self::FLOAT,
			'min'  => 0.1,
			'max'  => 100,
		),
		'rating_scale'               => array(
			'type' => self::FLOAT,
			'min'  => 1,
			'max'  => 2000,
		),
		'rating_doubles_weight'      => array(
			'type' => self::FLOAT,
			'min'  => 0,
			'max'  => 1,
		),
		'rating_points_per_class'    => array(
			'type' => self::FLOAT,
			'min'  => 1,
			'max'  => 1000,
		),
		'schedule_tables_per_match'  => array(
			'type' => self::INT,
			'min'  => 1,
			'max'  => 10,
		),
		'classification_min_games'   => array(
			'type' => self::INT,
			'min'  => 0,
			'max'  => 500,
		),
	);

	/**
	 * Prüft die Eingabe. Felder, die fehlen, behalten den bisherigen Wert; ein fehlendes
	 * Ankreuzfeld gilt als «nein», wenn $input vom Formular kommt ($form).
	 *
	 * @param array<string,mixed> $input   Feld => Zeichenkette des Formulars.
	 * @param array<string,mixed> $current Bisherige Regeln (season.rules_json).
	 * @param bool                $form    Eingabe eines vollständigen Formulars.
	 * @return array{rules:array<string,mixed>|null,errors:list<array{field:string,message:string}>}
	 *         «rules» ist null, wenn es Fehler gibt.
	 */
	public static function parse( array $input, array $current = array(), bool $form = true ): array {
		$values = array_intersect_key( $current, self::FIELDS );
		$errors = array();
		foreach ( self::FIELDS as $field => $spec ) {
			if ( ! array_key_exists( $field, $input ) ) {
				if ( $form && self::BOOL === $spec['type'] ) {
					$values[ $field ] = false;
				}
				continue;
			}
			$raw = $input[ $field ];
			if ( ! is_scalar( $raw ) ) {
				$errors[] = self::error( $field, 'Ungültige Eingabe.' );
				continue;
			}
			$text = trim( (string) $raw );
			try {
				$values[ $field ] = self::value( $spec, $text, is_bool( $raw ) ? $raw : null );
			} catch ( \InvalidArgumentException $e ) {
				$errors[] = self::error( $field, $e->getMessage() );
			}
		}
		if ( array() !== $errors ) {
			return array(
				'rules'  => null,
				'errors' => $errors,
			);
		}
		if ( isset( $values['forfeit_score'], $values['team_match_games'] ) && $values['forfeit_score'] > $values['team_match_games'] ) {
			$errors[] = self::error( 'forfeit_score', 'Die Forfait-Wertung kann nicht höher sein als die Zahl der Spiele pro Begegnung.' );
		}
		if ( isset( $values['two_player_max_wins'], $values['team_match_games'] ) && $values['two_player_max_wins'] > $values['team_match_games'] ) {
			$errors[] = self::error( 'two_player_max_wins', 'Ein Team zu zweit kann nicht mehr Spiele gewinnen, als die Begegnung hat.' );
		}
		if ( isset( $values['rl_group_size'], $values['rl_group_size_max'] ) && $values['rl_group_size_max'] < $values['rl_group_size'] ) {
			$errors[] = self::error( 'rl_group_size_max', 'Die Ausnahmegrösse darf nicht kleiner sein als die übliche Gruppengrösse.' );
		}
		if ( isset( $values['confirm_reminder_days'], $values['objection_days'] ) ) {
			foreach ( (array) $values['confirm_reminder_days'] as $days ) {
				if ( $days >= $values['objection_days'] ) {
					$errors[] = self::error( 'confirm_reminder_days', 'Erinnerungen an die Gastseite müssen vor Ablauf der Einsprachefrist liegen.' );
					break;
				}
			}
		}
		if ( array() === $errors ) {
			try {
				// Letzte Instanz: Was SeasonRules nicht annimmt, wird nicht gespeichert.
				$values = SeasonRules::from_array( $values )->to_array();
			} catch ( \InvalidArgumentException $e ) {
				$errors[] = self::error( self::field_of( $e->getMessage() ), $e->getMessage() );
			}
		}
		return array(
			'rules'  => array() === $errors ? $values : null,
			'errors' => $errors,
		);
	}

	/**
	 * Wert eines Feldes als Text für das Formular.
	 *
	 * @param mixed $value Wert aus den Regeln.
	 */
	public static function text( string $field, mixed $value ): string {
		$type = self::FIELDS[ $field ]['type'] ?? self::INT;
		switch ( $type ) {
			case self::BOOL:
				return $value ? '1' : '';
			case self::INT_LIST:
				return implode( ', ', array_map( 'intval', (array) $value ) );
			case self::CATEGORIES:
				return implode( ', ', array_keys( array_filter( (array) $value ) ) );
			case self::FLOAT:
				return rtrim( rtrim( number_format( (float) $value, 4, '.', '' ), '0' ), '.' );
			default:
				return is_scalar( $value ) ? (string) $value : '';
		}
	}

	/**
	 * @param array{type:string,min?:int|float,max?:int|float,choices?:list<string>} $spec Feld.
	 * @param string                                                                $text Eingabe ohne Leerraum am Rand.
	 * @param bool|null                                                             $flag Wahrheitswert, wenn die Eingabe schon einer ist.
	 * @return int|float|bool|string|list<int>|array<string,bool>
	 * @throws \InvalidArgumentException Mit der Meldung für das Feld.
	 */
	private static function value( array $spec, string $text, ?bool $flag ): int|float|bool|string|array {
		switch ( $spec['type'] ) {
			case self::BOOL:
				return $flag ?? in_array( strtolower( $text ), array( '1', 'on', 'yes', 'true', 'ja' ), true );

			case self::CHOICE:
				if ( ! in_array( $text, $spec['choices'] ?? array(), true ) ) {
					throw new \InvalidArgumentException( 'Bitte wähle einen der angebotenen Werte.' );
				}
				return $text;

			case self::FLOAT:
				$text = str_replace( ',', '.', $text );
				if ( 1 !== preg_match( '/^\d+(?:\.\d+)?$/D', $text ) ) {
					throw new \InvalidArgumentException( 'Bitte gib eine Zahl ein, z. B. 16 oder 0.5.' );
				}
				$number = (float) $text;
				self::in_range( $number, $spec );
				return $number;

			case self::INT_LIST:
				$list = array();
				foreach ( '' === $text ? array() : explode( ',', $text ) as $part ) {
					$list[] = self::integer( trim( $part ), $spec );
				}
				return $list;

			case self::CATEGORIES:
				$categories = array();
				foreach ( '' === $text ? array() : explode( ',', $text ) as $part ) {
					$category = strtoupper( trim( $part ) );
					if ( 1 !== preg_match( '/^[A-Z0-9]{1,8}$/D', $category ) ) {
						throw new \InvalidArgumentException( 'Kategorien sind Buchstaben wie A, B, C, D, getrennt mit Komma.' );
					}
					$categories[ $category ] = true;
				}
				return $categories;

			default:
				return self::integer( $text, $spec );
		}
	}

	/**
	 * @param array{min?:int|float,max?:int|float} $spec Grenzen.
	 * @throws \InvalidArgumentException Wenn die Eingabe keine ganze Zahl in den Grenzen ist.
	 */
	private static function integer( string $text, array $spec ): int {
		if ( 1 !== preg_match( '/^\d{1,6}$/D', $text ) ) {
			throw new \InvalidArgumentException( 'Bitte gib eine ganze Zahl ein.' );
		}
		$number = (int) $text;
		self::in_range( $number, $spec );
		return $number;
	}

	/**
	 * @param array{min?:int|float,max?:int|float} $spec Grenzen.
	 * @throws \InvalidArgumentException Ausserhalb der Grenzen.
	 */
	private static function in_range( int|float $number, array $spec ): void {
		if ( ( isset( $spec['min'] ) && $number < $spec['min'] ) || ( isset( $spec['max'] ) && $number > $spec['max'] ) ) {
			throw new \InvalidArgumentException( sprintf( 'Erlaubt sind Werte von %s bis %s.', (string) ( $spec['min'] ?? 0 ), (string) ( $spec['max'] ?? '' ) ) );
		}
	}

	/**
	 * Feld, das eine Meldung von SeasonRules nennt (sie beginnt mit dem Namen des Parameters).
	 */
	private static function field_of( string $message ): string {
		$found = '';
		foreach ( array_keys( self::FIELDS ) as $field ) {
			if ( str_contains( $message, $field ) && strlen( $field ) > strlen( $found ) ) {
				$found = $field;
			}
		}
		return $found;
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
