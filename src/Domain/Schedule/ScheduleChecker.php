<?php
/**
 * Prüft einen Spielplan auf Kollisionen und schlägt Ausweichtermine vor.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Domain\Schedule;

/**
 * Gilt für jede Liste datierter Begegnungen: den Entwurf des Generators ebenso wie einen Plan,
 * den die TK von Hand geändert hat. Der Prüfer ändert den Plan nie, er meldet und schlägt vor.
 * Ein «Abend» ist ein Kalendertag.
 */
final class ScheduleChecker {

	/**
	 * @param int $tables_per_fixture Tische, die eine Begegnung braucht.
	 */
	public function __construct( private readonly int $tables_per_fixture = 2 ) {
		if ( $tables_per_fixture < 1 ) {
			throw new \InvalidArgumentException( 'Tische pro Begegnung: mindestens 1.' );
		}
	}

	/**
	 * @param ScheduleInput        $input    Teams, Spielwochen, Hallensperren und Tische.
	 * @param list<PlannedFixture> $fixtures Zu prüfender Plan.
	 * @throws \InvalidArgumentException Wenn eine Begegnung ein Team nennt, das in keiner Gruppe steht.
	 */
	public function check( ScheduleInput $input, array $fixtures ): ScheduleReport {
		$index     = new FixtureIndex( $input, $fixtures );
		$conflicts = array();
		foreach ( $index->days() as $day ) {
			$conflicts = array_merge(
				$conflicts,
				$this->closed_venues( $input, $index, $day ),
				$this->capacity( $input, $index, $day ),
				$this->double_booked_teams( $input, $index, $day ),
				$this->club_evenings( $input, $index, $day )
			);
		}
		return new ScheduleReport( array_merge( $conflicts, $this->unknown_tables( $input, $index ) ) );
	}

	/**
	 * Hart: Das Lokal ist am Tag der Begegnung gesperrt.
	 *
	 * @return list<ScheduleConflict>
	 */
	private function closed_venues( ScheduleInput $input, FixtureIndex $index, string $day ): array {
		$conflicts = array();
		foreach ( $index->on_day( $day ) as $id => $fixture ) {
			$closure = $input->closure_on( $fixture->venue_id, $fixture->starts_at );
			if ( null === $closure ) {
				continue;
			}
			$reason      = '' === $closure->reason ? '' : ' (' . $closure->reason . ')';
			$conflicts[] = new ScheduleConflict(
				ConflictKind::VenueClosed,
				sprintf( 'Lokal am %s gesperrt%s.', $fixture->starts_at->format( 'd.m.Y' ), $reason ),
				array( $fixture ),
				$this->suggestions( $input, $index, array( $id ), false ),
				day: $day,
				venue_id: $fixture->venue_id
			);
		}
		return $conflicts;
	}

	/**
	 * Hart: Die Begegnungen eines Abends brauchen mehr Tische, als das Lokal hat. Lokale mit
	 * unbekannter Tischzahl werden hier übersprungen (siehe unknown_tables).
	 *
	 * @return list<ScheduleConflict>
	 */
	private function capacity( ScheduleInput $input, FixtureIndex $index, string $day ): array {
		$by_venue = array();
		foreach ( $index->on_day( $day ) as $id => $fixture ) {
			$by_venue[ (string) $fixture->venue_id ][ $id ] = $fixture;
		}
		$conflicts = array();
		foreach ( $by_venue as $group ) {
			$venue_id = reset( $group )->venue_id;
			$tables   = $input->tables( $venue_id );
			if ( null === $tables || count( $group ) * $this->tables_per_fixture <= $tables ) {
				continue;
			}
			$conflicts[] = new ScheduleConflict(
				ConflictKind::VenueCapacity,
				sprintf(
					'Kapazität des Lokals am %s überschritten: %d × %d Tische nötig, %d vorhanden.',
					reset( $group )->starts_at->format( 'd.m.Y' ),
					count( $group ),
					$this->tables_per_fixture,
					$tables
				),
				array_values( $group ),
				$this->suggestions( $input, $index, array_keys( $group ), false ),
				day: $day,
				venue_id: $venue_id
			);
		}
		return $conflicts;
	}

	/**
	 * Hart: Dieselbe Mannschaft hat am selben Abend mehrere Begegnungen. Der Generator erzeugt
	 * das nie, von Hand verlegte Spiele können es auslösen.
	 *
	 * @return list<ScheduleConflict>
	 */
	private function double_booked_teams( ScheduleInput $input, FixtureIndex $index, string $day ): array {
		$by_team = array();
		foreach ( $index->on_day( $day ) as $id => $fixture ) {
			foreach ( array( $fixture->home_team, $fixture->away_team ) as $team_id ) {
				$by_team[ (string) $team_id ]['team_id']         = $team_id;
				$by_team[ (string) $team_id ]['fixtures'][ $id ] = $fixture;
			}
		}
		$conflicts = array();
		foreach ( $by_team as $team ) {
			if ( count( $team['fixtures'] ) < 2 ) {
				continue;
			}
			$conflicts[] = new ScheduleConflict(
				ConflictKind::TeamDoubleBooked,
				sprintf(
					'Eine Mannschaft hat am %s %d Begegnungen.',
					reset( $team['fixtures'] )->starts_at->format( 'd.m.Y' ),
					count( $team['fixtures'] )
				),
				array_values( $team['fixtures'] ),
				$this->suggestions( $input, $index, array_keys( $team['fixtures'] ), false ),
				day: $day,
				team_id: $team['team_id']
			);
		}
		return $conflicts;
	}

	/**
	 * Weich: Mehrere Mannschaften desselben Clubs spielen am selben Abend in verschiedenen
	 * Begegnungen. Ein Clubduell allein ist kein Hinweis: Es lässt sich nicht verlegen, ohne
	 * dass wieder beide Mannschaften am selben Abend spielen.
	 *
	 * @return list<ScheduleConflict>
	 */
	private function club_evenings( ScheduleInput $input, FixtureIndex $index, string $day ): array {
		$by_club = array();
		foreach ( $index->on_day( $day ) as $id => $fixture ) {
			foreach ( array( $fixture->home_team, $fixture->away_team ) as $team_id ) {
				$club_id = $input->team( $team_id )->club_id;

				$by_club[ (string) $club_id ]['club_id']                    = $club_id;
				$by_club[ (string) $club_id ]['fixtures'][ $id ]            = $fixture;
				$by_club[ (string) $club_id ]['teams'][ (string) $team_id ] = true;
			}
		}
		$conflicts = array();
		foreach ( $by_club as $club ) {
			if ( count( $club['fixtures'] ) < 2 || count( $club['teams'] ) < 2 ) {
				continue;
			}
			$conflicts[] = new ScheduleConflict(
				ConflictKind::ClubSameEvening,
				sprintf(
					'%d Mannschaften desselben Clubs spielen am %s; Ersatzspieler fehlen dann.',
					count( $club['teams'] ),
					reset( $club['fixtures'] )->starts_at->format( 'd.m.Y' )
				),
				array_values( $club['fixtures'] ),
				$this->suggestions( $input, $index, array_keys( $club['fixtures'] ), true ),
				day: $day,
				club_id: $club['club_id']
			);
		}
		return $conflicts;
	}

	/**
	 * Hinweis pro Lokal mit unbekannter Tischzahl: Die Kapazität wurde dort nicht geprüft.
	 *
	 * @return list<ScheduleConflict>
	 */
	private function unknown_tables( ScheduleInput $input, FixtureIndex $index ): array {
		$by_venue = array();
		foreach ( $index->fixtures as $fixture ) {
			if ( null === $input->tables( $fixture->venue_id ) ) {
				$by_venue[ (string) $fixture->venue_id ][] = $fixture;
			}
		}
		$conflicts = array();
		foreach ( $by_venue as $group ) {
			$per_day     = array_count_values( array_map( static fn( PlannedFixture $fixture ) => $fixture->day(), $group ) );
			$conflicts[] = new ScheduleConflict(
				ConflictKind::TablesUnknown,
				sprintf(
					'Tischzahl des Lokals unbekannt, Kapazität nicht geprüft (höchstens %d × %d Tische an einem Abend nötig).',
					max( $per_day ),
					$this->tables_per_fixture
				),
				$group,
				venue_id: $group[0]->venue_id
			);
		}
		return $conflicts;
	}

	/**
	 * @param list<int> $ids Positionen der betroffenen Begegnungen.
	 * @return list<ScheduleSuggestion> Vorschläge für die Begegnungen, für die es einen gibt.
	 */
	private function suggestions( ScheduleInput $input, FixtureIndex $index, array $ids, bool $avoid_club_evening ): array {
		$suggestions = array();
		foreach ( $ids as $id ) {
			$suggestion = $this->suggest( $input, $index, $id, $avoid_club_evening );
			if ( null !== $suggestion ) {
				$suggestions[] = $suggestion;
			}
		}
		return $suggestions;
	}

	/**
	 * Sucht den Ausweichtermin einer Begegnung: Heimspieltag des Heimteams zur Anspielzeit in
	 * einer Spielwoche derselben Halbserie. Zuerst die späteren Termine aufsteigend, dann die
	 * früheren absteigend, also jeweils der nächstgelegene zuerst. Der erste freie Termin gilt.
	 * Der bisherige Tag ist nie Kandidat.
	 */
	private function suggest( ScheduleInput $input, FixtureIndex $index, int $id, bool $avoid_club_evening ): ?ScheduleSuggestion {
		$fixture = $index->fixtures[ $id ];
		$home    = $input->team( $fixture->home_team );
		$current = $fixture->day();
		$later   = array();
		$earlier = array();
		foreach ( $input->weeks( $fixture->leg ) as $monday ) {
			$date = $home->home_date( $monday );
			$day  = $date->format( 'Y-m-d' );
			if ( $day > $current ) {
				$later[] = array( $monday, $date );
			} elseif ( $day < $current ) {
				$earlier[] = array( $monday, $date );
			}
		}
		foreach ( array_merge( $later, array_reverse( $earlier ) ) as list( $monday, $date ) ) {
			if ( $this->is_free( $input, $index, $id, $monday->format( 'Y-m-d' ), $date, $avoid_club_evening ) ) {
				return new ScheduleSuggestion( $fixture, $date );
			}
		}
		return null;
	}

	/**
	 * Ein Termin ist frei, wenn beide Teams in der Woche keine andere Begegnung haben, das
	 * Lokal nicht gesperrt ist und (bei bekannter Tischzahl) noch Platz für eine weitere
	 * Begegnung hat. Für den Hinweis «Club am selben Abend» darf am Termin zudem keiner der
	 * beiden Clubs eine Begegnung haben, sonst bliebe der Hinweis bestehen. Die Begegnung
	 * selbst liegt nie am geprüften Tag (siehe suggest), sie zählt also nur bei der Woche mit.
	 */
	private function is_free( ScheduleInput $input, FixtureIndex $index, int $id, string $week, \DateTimeImmutable $date, bool $avoid_club_evening ): bool {
		$fixture = $index->fixtures[ $id ];
		$day     = $date->format( 'Y-m-d' );
		if ( $index->team_plays_in_week( $fixture->home_team, $week, $id ) || $index->team_plays_in_week( $fixture->away_team, $week, $id ) ) {
			return false;
		}
		if ( null !== $input->closure_on( $fixture->venue_id, $date ) ) {
			return false;
		}
		$tables = $input->tables( $fixture->venue_id );
		if ( null !== $tables && ( $index->count_in_venue( $fixture->venue_id, $day ) + 1 ) * $this->tables_per_fixture > $tables ) {
			return false;
		}
		if ( $avoid_club_evening ) {
			foreach ( array( $fixture->home_team, $fixture->away_team ) as $team_id ) {
				if ( $index->club_plays_on( $input->team( $team_id )->club_id, $day ) ) {
					return false;
				}
			}
		}
		return true;
	}
}
