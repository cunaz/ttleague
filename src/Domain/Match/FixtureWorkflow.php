<?php
/**
 * Statusübergänge einer Begegnung (Erfassen, Bestätigen, Einsprache, Entscheide der TK).
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Domain\Match;

/**
 * Wer darf in welchem Status was? Rollen: Heimseite, Gastseite, TK. Ob eine Person eine
 * Seite vertritt, entscheidet die Rechteprüfung; hier steht nur die fachliche Tabelle
 * aus docs/erfassung.md.
 */
final class FixtureWorkflow {

	public const SCHEDULED   = 'scheduled';
	public const POSTPONED   = 'postponed';
	public const PROVISIONAL = 'provisional';
	public const CONFIRMED   = 'confirmed';
	public const DISPUTED    = 'disputed';
	public const FINAL       = 'final';
	public const FORFEIT     = 'forfeit';
	public const CANCELLED   = 'cancelled';

	public const ENTER    = 'enter';
	public const CONFIRM  = 'confirm';
	public const OBJECT   = 'object';
	public const FINALIZE = 'finalize';
	public const CORRECT  = 'correct';
	public const FORFEIT_ = 'forfeit';
	public const RESET    = 'reset';

	public const ROLE_HOME = 'home';
	public const ROLE_AWAY = 'away';
	public const ROLE_TK   = 'tk';

	/**
	 * Aktion => erlaubte Ausgangsstatus, Rollen und Zielstatus.
	 */
	private const RULES = array(
		self::ENTER    => array(
			'from'  => array( self::SCHEDULED, self::POSTPONED, self::PROVISIONAL ),
			'roles' => array( self::ROLE_HOME, self::ROLE_TK ),
			'to'    => self::PROVISIONAL,
		),
		self::CONFIRM  => array(
			'from'  => array( self::PROVISIONAL ),
			'roles' => array( self::ROLE_AWAY, self::ROLE_TK ),
			'to'    => self::CONFIRMED,
		),
		self::OBJECT   => array(
			'from'  => array( self::PROVISIONAL ),
			'roles' => array( self::ROLE_AWAY ),
			'to'    => self::DISPUTED,
		),
		self::FINALIZE => array(
			'from'  => array( self::PROVISIONAL, self::CONFIRMED, self::DISPUTED ),
			'roles' => array( self::ROLE_TK ),
			'to'    => self::FINAL,
		),
		self::CORRECT  => array(
			'from'  => array( self::SCHEDULED, self::POSTPONED, self::PROVISIONAL, self::CONFIRMED, self::DISPUTED, self::FINAL, self::FORFEIT ),
			'roles' => array( self::ROLE_TK ),
			'to'    => self::FINAL,
		),
		self::FORFEIT_ => array(
			'from'  => array( self::SCHEDULED, self::POSTPONED, self::PROVISIONAL, self::CONFIRMED, self::DISPUTED, self::FINAL, self::FORFEIT ),
			'roles' => array( self::ROLE_TK ),
			'to'    => self::FORFEIT,
		),
		self::RESET    => array(
			'from'  => array( self::POSTPONED, self::PROVISIONAL, self::CONFIRMED, self::DISPUTED, self::FINAL, self::FORFEIT, self::CANCELLED ),
			'roles' => array( self::ROLE_TK ),
			'to'    => self::SCHEDULED,
		),
	);

	/**
	 * Status, deren Resultat in Tabellen zählt.
	 */
	public const COUNTED = array( self::PROVISIONAL, self::CONFIRMED, self::DISPUTED, self::FINAL, self::FORFEIT );

	/**
	 * @return list<string>
	 */
	public static function actions(): array {
		return array_keys( self::RULES );
	}

	/**
	 * Darf eine der Rollen die Aktion im Status ausführen?
	 *
	 * @param list<string> $roles Rollen der handelnden Person für diese Begegnung.
	 */
	public static function allows( string $action, string $status, array $roles ): bool {
		$rule = self::RULES[ $action ] ?? null;
		if ( null === $rule || ! in_array( $status, $rule['from'], true ) ) {
			return false;
		}
		return array() !== array_intersect( $roles, $rule['roles'] );
	}

	/**
	 * Hat eine der Rollen grundsätzlich Zugriff auf die Aktion (unabhängig vom Status)?
	 *
	 * @param list<string> $roles Rollen.
	 */
	public static function role_may( string $action, array $roles ): bool {
		$rule = self::RULES[ $action ] ?? null;
		return null !== $rule && array() !== array_intersect( $roles, $rule['roles'] );
	}

	public static function target( string $action ): string {
		if ( ! isset( self::RULES[ $action ] ) ) {
			throw new \InvalidArgumentException( 'Unbekannte Aktion: ' . $action );
		}
		return self::RULES[ $action ]['to'];
	}

	public static function counts( string $status ): bool {
		return in_array( $status, self::COUNTED, true );
	}

	/**
	 * Resultat zählt, ist aber noch nicht bestätigt (in Tabellen markiert).
	 */
	public static function is_provisional( string $status ): bool {
		return in_array( $status, array( self::PROVISIONAL, self::DISPUTED ), true );
	}
}
