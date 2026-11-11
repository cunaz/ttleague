<?php
/**
 * Voraussetzungen für die Freigabe und den Abschluss einer Saison.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Domain\Season;

use TTLeague\Domain\Match\FixtureWorkflow as Flow;

/**
 * Reine Regeln ohne Datenbank (docs/saison.md). Der Dienst zählt, diese Klasse entscheidet. Ein
 * offener Punkt ist ein Array mit «code», «message» und «confirmable»: Was bestätigbar ist,
 * darf die TK ausdrücklich in Kauf nehmen; alles andere muss behoben sein.
 */
final class LifecycleRules {

	public const REGISTRATIONS  = 'registrations';
	public const NO_TEAMS       = 'no_teams';
	public const UNASSIGNED     = 'unassigned';
	public const NO_SCHEDULE    = 'no_schedule';
	public const UNCHECKED      = 'schedule_unchecked';
	public const CONFLICTS      = 'conflicts';
	public const RULES          = 'rules';
	public const PREDECESSOR    = 'predecessor';
	public const OPEN_FIXTURES  = 'open_fixtures';
	public const CLASSIFICATION = 'classification';

	/** Bestätigungen, die die TK bei der Freigabe geben kann. */
	public const CONFIRMABLE = array( self::CONFLICTS, self::PREDECESSOR );

	/**
	 * Status einer Begegnung, die den Abschluss der Saison verhindern: Es fehlt ein Resultat
	 * oder ein Entscheid.
	 */
	public const OPEN_STATUSES = array( Flow::SCHEDULED, Flow::POSTPONED, Flow::PROVISIONAL, Flow::DISPUTED );

	/**
	 * Was der Freigabe der geplanten Saison noch im Weg steht.
	 *
	 * @param array{
	 *   rules_error:string|null,
	 *   unreviewed:list<string>,
	 *   teams:int,
	 *   unassigned:list<string>,
	 *   groups_without_schedule:list<string>,
	 *   schedule_error:string|null,
	 *   hard_conflicts:int,
	 *   predecessor:string|null,
	 *   predecessor_open_fixtures:int,
	 *   predecessor_classification_closed:bool
	 * } $facts Stand der geplanten Saison und ihrer Vorgängerin (der aktiven Saison, falls es eine gibt).
	 * @param list<string> $confirmed Was die TK ausdrücklich bestätigt (CONFIRMABLE).
	 * @return list<array{code:string,message:string,confirmable:bool}>
	 */
	public static function publish_blockers( array $facts, array $confirmed = array() ): array {
		$open = array();
		if ( null !== $facts['rules_error'] ) {
			$open[] = self::item( self::RULES, sprintf( 'Die Regeln der Saison sind ungültig: %s', $facts['rules_error'] ) );
		}
		if ( array() !== $facts['unreviewed'] ) {
			$open[] = self::item( self::REGISTRATIONS, sprintf( 'Noch nicht geprüfte Meldungen: %s.', implode( ', ', $facts['unreviewed'] ) ) );
		}
		if ( $facts['teams'] < 1 ) {
			$open[] = self::item( self::NO_TEAMS, 'Es ist noch keine Mannschaft gemeldet.' );
		}
		if ( array() !== $facts['unassigned'] ) {
			$open[] = self::item( self::UNASSIGNED, sprintf( 'Mannschaften ohne Gruppe: %s.', implode( ', ', $facts['unassigned'] ) ) );
		}
		if ( array() !== $facts['groups_without_schedule'] ) {
			$open[] = self::item( self::NO_SCHEDULE, sprintf( 'Der Spielplan fehlt oder passt nicht mehr zu den Mannschaften: %s.', implode( ', ', $facts['groups_without_schedule'] ) ) );
		}
		if ( null !== $facts['schedule_error'] ) {
			$open[] = self::item( self::UNCHECKED, sprintf( 'Der Spielplan lässt sich nicht prüfen: %s', $facts['schedule_error'] ) );
		} elseif ( $facts['hard_conflicts'] > 0 && ! in_array( self::CONFLICTS, $confirmed, true ) ) {
			$open[] = self::item(
				self::CONFLICTS,
				1 === $facts['hard_conflicts']
					? 'Der Spielplan hat eine harte Kollision. Behebe sie oder bestätige sie ausdrücklich.'
					: sprintf( 'Der Spielplan hat %d harte Kollisionen. Behebe sie oder bestätige sie ausdrücklich.', $facts['hard_conflicts'] ),
				true
			);
		}
		if ( null !== $facts['predecessor'] && ! in_array( self::PREDECESSOR, $confirmed, true ) ) {
			$reasons = array();
			if ( $facts['predecessor_open_fixtures'] > 0 ) {
				$reasons[] = 1 === $facts['predecessor_open_fixtures'] ? 'eine Begegnung ist noch offen' : sprintf( '%d Begegnungen sind noch offen', $facts['predecessor_open_fixtures'] );
			}
			if ( ! $facts['predecessor_classification_closed'] ) {
				$reasons[] = 'die Klassierung ist nicht abgeschlossen';
			}
			if ( array() !== $reasons ) {
				$open[] = self::item(
					self::PREDECESSOR,
					sprintf( 'Die Saison %s wird mit der Freigabe archiviert, aber %s. Schliesse sie zuerst ab oder bestätige das ausdrücklich.', $facts['predecessor'], implode( ' und ', $reasons ) ),
					true
				);
			}
		}
		return $open;
	}

	/**
	 * Was dem Abschluss der aktiven Saison noch im Weg steht: Begegnungen ohne Resultat oder
	 * Entscheid und eine nicht abgeschlossene Klassierung.
	 *
	 * @param array<string,int> $open_fixtures         Status => Anzahl Begegnungen (nur OPEN_STATUSES zählen).
	 * @param bool              $classification_closed season.classification_closed_at ist gesetzt.
	 * @return list<array{code:string,message:string,confirmable:bool}>
	 */
	public static function close_blockers( array $open_fixtures, bool $classification_closed ): array {
		$open  = array();
		$count = 0;
		foreach ( self::OPEN_STATUSES as $status ) {
			$count += max( 0, (int) ( $open_fixtures[ $status ] ?? 0 ) );
		}
		if ( $count > 0 ) {
			$open[] = self::item(
				self::OPEN_FIXTURES,
				1 === $count
					? 'Eine Begegnung ist noch angesetzt, verschoben, unbestätigt oder strittig.'
					: sprintf( '%d Begegnungen sind noch angesetzt, verschoben, unbestätigt oder strittig.', $count )
			);
		}
		if ( ! $classification_closed ) {
			$open[] = self::item( self::CLASSIFICATION, 'Die Klassierung ist noch nicht abgeschlossen.' );
		}
		return $open;
	}

	/**
	 * @return array{code:string,message:string,confirmable:bool}
	 */
	private static function item( string $code, string $message, bool $confirmable = false ): array {
		return array(
			'code'        => $code,
			'message'     => $message,
			'confirmable' => $confirmable,
		);
	}
}
