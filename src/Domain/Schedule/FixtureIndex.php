<?php
/**
 * Nachschlagehilfe über die Begegnungen eines Plans: nach Tag, nach Team und Woche, nach Club.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Domain\Schedule;

/**
 * Hilfsklasse des ScheduleChecker. Begegnungen werden über ihre Position im Plan angesprochen,
 * damit auch zwei gleiche Einträge unterscheidbar bleiben.
 *
 * @internal
 */
final class FixtureIndex {

	/** @var list<PlannedFixture> */
	public readonly array $fixtures;

	/** @var array<string,list<int>> Tag => Positionen. */
	private array $by_day = array();

	/** @var array<int|string,array<string,list<int>>> Team => Montag der Woche => Positionen. */
	private array $by_team_week = array();

	/** @var array<int|string,array<string,list<int>>> Club => Tag => Positionen. */
	private array $by_club_day = array();

	/**
	 * @param ScheduleInput        $input    Liefert die Clubs der Teams.
	 * @param list<PlannedFixture> $fixtures Plan.
	 * @throws \InvalidArgumentException Wenn eine Begegnung ein Team nennt, das in keiner Gruppe steht.
	 */
	public function __construct( ScheduleInput $input, array $fixtures ) {
		$fixtures = array_values( $fixtures );
		foreach ( $fixtures as $id => $fixture ) {
			if ( ! $fixture instanceof PlannedFixture ) {
				throw new \InvalidArgumentException( 'Begegnungen des Plans müssen PlannedFixture sein.' );
			}
			$day                    = $fixture->day();
			$this->by_day[ $day ][] = $id;
			$clubs                  = array();
			foreach ( array( $fixture->home_team, $fixture->away_team ) as $team_id ) {
				$team = $input->team( $team_id );
				if ( null === $team ) {
					throw new \InvalidArgumentException( 'Begegnung mit unbekanntem Team: ' . $team_id );
				}
				$this->by_team_week[ (string) $team_id ][ $fixture->week() ][] = $id;
				$clubs[ (string) $team->club_id ]                              = true;
			}
			// Ein Clubduell zählt für seinen Club als eine Begegnung.
			foreach ( array_keys( $clubs ) as $club ) {
				$this->by_club_day[ $club ][ $day ][] = $id;
			}
		}
		ksort( $this->by_day, SORT_STRING );
		$this->fixtures = $fixtures;
	}

	/**
	 * @return list<string> Tage mit Begegnungen (Y-m-d), aufsteigend.
	 */
	public function days(): array {
		return array_keys( $this->by_day );
	}

	/**
	 * @return array<int,PlannedFixture> Position => Begegnung, in der Reihenfolge des Plans.
	 */
	public function on_day( string $day ): array {
		$result = array();
		foreach ( $this->by_day[ $day ] ?? array() as $id ) {
			$result[ $id ] = $this->fixtures[ $id ];
		}
		return $result;
	}

	/**
	 * Anzahl Begegnungen in einem Lokal an einem Tag.
	 */
	public function count_in_venue( int|string $venue_id, string $day ): int {
		$count = 0;
		foreach ( $this->on_day( $day ) as $fixture ) {
			if ( (string) $fixture->venue_id === (string) $venue_id ) {
				++$count;
			}
		}
		return $count;
	}

	/**
	 * Hat das Team in der Woche (Montag als Y-m-d) eine andere Begegnung als die an der Position $except?
	 */
	public function team_plays_in_week( int|string $team_id, string $week, int $except ): bool {
		return array() !== array_diff( $this->by_team_week[ (string) $team_id ][ $week ] ?? array(), array( $except ) );
	}

	/**
	 * Hat der Club am Tag eine Begegnung?
	 */
	public function club_plays_on( int|string $club_id, string $day ): bool {
		return isset( $this->by_club_day[ (string) $club_id ][ $day ] );
	}
}
