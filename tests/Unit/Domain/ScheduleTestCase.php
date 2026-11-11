<?php
declare(strict_types=1);

namespace TTLeague\Tests\Unit\Domain;

use PHPUnit\Framework\TestCase;
use TTLeague\Domain\Schedule\PlannedFixture;
use TTLeague\Domain\Schedule\ScheduleGroup;
use TTLeague\Domain\Schedule\ScheduleInput;
use TTLeague\Domain\Schedule\ScheduleTeam;

/**
 * Erfundene Liga für die Tests des Spielplans: Die Team-ID ist der Teamname, der Club heisst
 * wie das Team ohne Nummer, das Lokal «Halle <Club>».
 */
abstract class ScheduleTestCase extends TestCase {

	protected function team( string $name, int $weekday = 2, string $start_time = '19:30' ): ScheduleTeam {
		$club = substr( $name, 0, (int) strrpos( $name, ' ' ) );
		return new ScheduleTeam( $name, $club, 'Halle ' . $club, $weekday, $start_time );
	}

	/**
	 * Gruppe mit Teams «<Club> 1»; die Heimspieltage wechseln von Montag bis Donnerstag.
	 *
	 * @param list<string> $clubs Clubs.
	 */
	protected function group( string $id, array $clubs ): ScheduleGroup {
		$teams = array();
		foreach ( array_values( $clubs ) as $index => $club ) {
			$teams[] = $this->team( $club . ' 1', 1 + $index % 4 );
		}
		return new ScheduleGroup( $id, $teams );
	}

	/**
	 * @return list<\DateTimeImmutable> Aufeinanderfolgende Montage.
	 */
	protected function mondays( string $first, int $count ): array {
		$weeks = array();
		for ( $i = 0; $i < $count; $i++ ) {
			$weeks[] = ( new \DateTimeImmutable( $first ) )->modify( '+' . ( 7 * $i ) . ' days' );
		}
		return $weeks;
	}

	/**
	 * Begegnung im Heimlokal des Heimteams, sofern kein anderes Lokal genannt ist.
	 */
	protected function fixture( ScheduleInput $input, string $home, string $away, string $starts_at, int $leg = 1, ?string $venue = null ): PlannedFixture {
		return new PlannedFixture( 'A', null, $leg, $home, $away, new \DateTimeImmutable( $starts_at ), $venue ?? $input->team( $home )->venue_id );
	}

	/**
	 * @param list<PlannedFixture> $fixtures Begegnungen.
	 * @return list<string> «Heim – Gast».
	 */
	protected function pairings( array $fixtures ): array {
		return array_map( static fn( PlannedFixture $fixture ) => $fixture->home_team . ' – ' . $fixture->away_team, $fixtures );
	}
}
