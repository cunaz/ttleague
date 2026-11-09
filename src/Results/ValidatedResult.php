<?php
/**
 * Geprüftes Resultat, bereit zum Speichern.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Results;

use TTLeague\Domain\Match\TeamMatchResult;

final class ValidatedResult {

	/**
	 * @param list<array{side:string,slot:string,player_id:int,is_substitute:bool}>                                                                         $lineup      Aufstellung.
	 * @param list<array{sequence:int,type:string,a1:int|null,a2:int|null,b1:int|null,b2:int|null,sets_a:int,sets_b:int,status:string,counts:bool,set_points:list<array{0:int,1:int}>}> $games       Spiele (a = Heim, b = Gast).
	 * @param TeamMatchResult                                                                                                                              $result      Gesamtresultat.
	 * @param list<string>                                                                                                                                 $warnings    Hinweise.
	 */
	public function __construct(
		public readonly array $lineup,
		public readonly array $games,
		public readonly TeamMatchResult $result,
		public readonly array $warnings = array(),
	) {}
}
