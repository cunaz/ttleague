<?php
/**
 * Einteilung der Mannschaften in Kategorien und Gruppen (Art. 11 des Wettspielreglements).
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Domain\Season;

/**
 * Reine Regeln ohne Datenbank. Das System schlägt vor, die TK entscheidet (Art. 11.5): Die
 * Hinweise sind deshalb weich und verhindern nichts.
 *
 * Kategorien sind Buchstaben; «A» ist die oberste, der im Alphabet letzte Buchstabe unter den
 * Gruppen der Saison die unterste.
 */
final class GroupingRules {

	public const CLUB_LIMIT  = 'club_limit';
	public const SMALL_GROUP = 'small_group';
	public const UNASSIGNED  = 'unassigned';

	/** Höchstzahl der Mannschaften eines Vereins pro Kategorie, ausser in der untersten (Art. 11.4). */
	public const MAX_PER_CLUB = 2;

	/** Unter so vielen Mannschaften gibt es einen Hinweis zur Gruppe. */
	public const MIN_GROUP_SIZE = 3;

	/**
	 * Vorschlag aus dem Vorjahr (Art. 11.3): Jede Mannschaft, die es im Vorjahr gab (gleicher
	 * Club, gleiche Nummer), bleibt in ihrer Kategorie und Gruppe. Neue Mannschaften und solche,
	 * die im Vorjahr keiner Gruppe angehörten, erhalten keinen Vorschlag.
	 *
	 * @param list<array{id:int,club_id:int,number:int}>                                  $teams    Mannschaften der neuen Saison.
	 * @param list<array{club_id:int,number:int,category:string|null,group_no:int|null}> $previous Mannschaften des Vorjahrs mit ihrer Gruppe.
	 * @return array<int,array{category:string,group_no:int}|null> Mannschaft => Kategorie und Gruppennummer oder null.
	 */
	public static function proposal( array $teams, array $previous ): array {
		$by_key = array();
		foreach ( $previous as $team ) {
			if ( null === $team['category'] || '' === $team['category'] ) {
				continue;
			}
			$by_key[ $team['club_id'] . ':' . $team['number'] ] = array(
				'category' => (string) $team['category'],
				'group_no' => max( 1, (int) $team['group_no'] ),
			);
		}
		$proposal = array();
		foreach ( $teams as $team ) {
			$proposal[ $team['id'] ] = $by_key[ $team['club_id'] . ':' . $team['number'] ] ?? null;
		}
		return $proposal;
	}

	/**
	 * Weiche Hinweise zur Einteilung:
	 * mehr als zwei Mannschaften eines Vereins in einer Kategorie, ausser in der untersten
	 * (Art. 11.4; alle Gruppen der Kategorie zählen zusammen), Gruppen mit weniger als drei
	 * Mannschaften und Mannschaften ohne Gruppe.
	 *
	 * @param list<array{id:int,name:string,category:string|null}>               $groups Gruppen der Saison.
	 * @param list<array{id:int,name:string,club_id:int,competition_id:int|null}> $teams  Mannschaften der Saison.
	 * @param array<int,string>                                                   $clubs  Club-ID => Name für die Meldungen.
	 * @return list<array{code:string,message:string,group_id:int|null,team_id:int|null,club_id:int|null}>
	 */
	public static function hints( array $groups, array $teams, array $clubs = array() ): array {
		$hints    = array();
		$by_id    = array();
		$sizes    = array();
		$per_club = array();
		foreach ( $groups as $group ) {
			$by_id[ $group['id'] ] = $group;
			$sizes[ $group['id'] ] = 0;
		}
		$lowest = self::lowest_category( $groups );

		foreach ( $teams as $team ) {
			$group = null === $team['competition_id'] ? null : ( $by_id[ $team['competition_id'] ] ?? null );
			if ( null === $group ) {
				$hints[] = self::hint( self::UNASSIGNED, sprintf( '%s ist noch keiner Gruppe zugeteilt.', $team['name'] ), null, $team['id'], $team['club_id'] );
				continue;
			}
			++$sizes[ $group['id'] ];
			$category = (string) ( $group['category'] ?? '' );
			if ( '' !== $category ) {
				$per_club[ $category ][ $team['club_id'] ][] = $team['name'];
			}
		}

		ksort( $per_club );
		foreach ( $per_club as $category => $by_club ) {
			if ( (string) $category === $lowest ) {
				continue;
			}
			foreach ( $by_club as $club_id => $names ) {
				if ( count( $names ) > self::MAX_PER_CLUB ) {
					$hints[] = self::hint(
						self::CLUB_LIMIT,
						sprintf(
							'%s hat %d Mannschaften in der Kategorie %s (%s). Erlaubt sind höchstens %d, ausser in der untersten Kategorie (Art. 11.4).',
							$clubs[ $club_id ] ?? 'Ein Verein',
							count( $names ),
							$category,
							implode( ', ', $names ),
							self::MAX_PER_CLUB
						),
						null,
						null,
						(int) $club_id
					);
				}
			}
		}

		foreach ( $groups as $group ) {
			if ( $sizes[ $group['id'] ] < self::MIN_GROUP_SIZE ) {
				$hints[] = self::hint(
					self::SMALL_GROUP,
					0 === $sizes[ $group['id'] ]
						? sprintf( '%s hat noch keine Mannschaft.', $group['name'] )
						: sprintf( '%s hat nur %d %s; üblich sind mindestens %d.', $group['name'], $sizes[ $group['id'] ], 1 === $sizes[ $group['id'] ] ? 'Mannschaft' : 'Mannschaften', self::MIN_GROUP_SIZE ),
					$group['id'],
					null,
					null
				);
			}
		}
		return $hints;
	}

	/**
	 * Unterste Kategorie unter den Gruppen: der im Alphabet letzte Buchstabe, null ohne Kategorie.
	 *
	 * @param list<array{category:string|null}> $groups Gruppen.
	 */
	public static function lowest_category( array $groups ): ?string {
		$categories = array();
		foreach ( $groups as $group ) {
			if ( null !== $group['category'] && '' !== $group['category'] ) {
				$categories[] = (string) $group['category'];
			}
		}
		if ( array() === $categories ) {
			return null;
		}
		sort( $categories, SORT_STRING );
		return end( $categories );
	}

	/**
	 * Prüft Kategorie, Gruppennummer und Name einer neuen Gruppe und liefert die bereinigten
	 * Werte. Ohne Namen heisst die Gruppe «Gruppe A», ab Gruppennummer 2 «Gruppe A2».
	 *
	 * @return array{category:string,group_no:int,name:string,errors:list<array{field:string,message:string}>}
	 */
	public static function group( string $category, int $group_no, string $name ): array {
		$errors   = array();
		$category = strtoupper( trim( $category ) );
		$name     = trim( $name );
		if ( 1 !== preg_match( '/^[A-Z]$/D', $category ) ) {
			$errors[] = array(
				'field'   => 'category',
				'message' => 'Die Kategorie ist ein Buchstabe von A bis Z.',
			);
		}
		if ( $group_no < 1 || $group_no > 99 ) {
			$errors[] = array(
				'field'   => 'group_no',
				'message' => 'Die Gruppennummer liegt zwischen 1 und 99.',
			);
		}
		if ( '' === $name ) {
			$name = 'Gruppe ' . $category . ( $group_no > 1 ? $group_no : '' );
		}
		$name_error = self::name_error( $name );
		if ( null !== $name_error ) {
			$errors[] = array(
				'field'   => 'name',
				'message' => $name_error,
			);
		}
		return array(
			'category' => $category,
			'group_no' => $group_no,
			'name'     => $name,
			'errors'   => $errors,
		);
	}

	/**
	 * Meldung zu einem ungültigen Gruppennamen, sonst null.
	 */
	public static function name_error( string $name ): ?string {
		$name = trim( $name );
		if ( '' === $name ) {
			return 'Der Name fehlt.';
		}
		return mb_strlen( $name ) > 80 ? 'Der Name ist zu lang (höchstens 80 Zeichen).' : null;
	}

	/**
	 * Format einer Konkurrenz (competition.format_json) für die neue Saison: alles ausser den
	 * Fristen, die ein Datum der alten Saison tragen (Schlüssel mit «deadline»).
	 *
	 * @param array<string,mixed> $format Format der bisherigen Konkurrenz.
	 * @return array<string,mixed>
	 */
	public static function carried_format( array $format ): array {
		return array_filter(
			$format,
			static fn( int|string $key ): bool => ! str_contains( strtolower( (string) $key ), 'deadline' ),
			ARRAY_FILTER_USE_KEY
		);
	}

	/**
	 * @return array{code:string,message:string,group_id:int|null,team_id:int|null,club_id:int|null}
	 */
	private static function hint( string $code, string $message, ?int $group_id, ?int $team_id, ?int $club_id ): array {
		return array(
			'code'     => $code,
			'message'  => $message,
			'group_id' => $group_id,
			'team_id'  => $team_id,
			'club_id'  => $club_id,
		);
	}
}
