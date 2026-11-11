<?php
/**
 * Spielplan der Meisterschaft: Entwurf erzeugen und Pläne prüfen.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Domain\Schedule;

/**
 * Einstieg für die Anwendung. «Erzeugen» (ScheduleGenerator) und «Prüfen» (ScheduleChecker)
 * bleiben getrennt: Nach jeder Verlegung von Hand wird der geänderte Plan mit check() neu
 * geprüft, ohne ihn neu zu erzeugen.
 */
final class SchedulePlanner {

	private readonly ScheduleGenerator $generator;
	private readonly ScheduleChecker $checker;

	/**
	 * @param int $tables_per_fixture Tische, die eine Begegnung braucht.
	 */
	public function __construct( int $tables_per_fixture = 2 ) {
		$this->generator = new ScheduleGenerator();
		$this->checker   = new ScheduleChecker( $tables_per_fixture );
	}

	/**
	 * Erzeugt den Entwurf und prüft ihn. Der Entwurf bleibt, wie ihn der Generator liefert:
	 * Kollisionen stehen im Bericht, die TK entscheidet über Verlegungen.
	 *
	 * @throws \InvalidArgumentException Wenn die Spielwochen für die Runden nicht reichen.
	 */
	public function plan( ScheduleInput $input ): ScheduleDraft {
		$fixtures = $this->generator->generate( $input );
		return new ScheduleDraft( $fixtures, $this->checker->check( $input, $fixtures ) );
	}

	/**
	 * Prüft eine beliebige Liste datierter Begegnungen mit denselben Regeln.
	 *
	 * @param ScheduleInput        $input    Teams, Spielwochen, Hallensperren und Tische.
	 * @param list<PlannedFixture> $fixtures Zu prüfender Plan.
	 * @throws \InvalidArgumentException Wenn eine Begegnung ein Team nennt, das in keiner Gruppe steht.
	 */
	public function check( ScheduleInput $input, array $fixtures ): ScheduleReport {
		return $this->checker->check( $input, $fixtures );
	}
}
