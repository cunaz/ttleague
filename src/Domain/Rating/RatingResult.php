<?php
/**
 * Ergebnis eines Rechenlaufs der Wertungszahl.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Domain\Rating;

final class RatingResult {

	/** @var array<int,float> Spieler => aktuelle Wertungszahl */
	public array $ratings = array();

	/** @var array<int,int> Spieler => Anzahl gewerteter Spiele */
	public array $games = array();

	/** @var array<int,array<int,float>> Saison => Spieler => Wertungszahl nach dem letzten Spiel der Saison */
	public array $season_end = array();

	/** @var list<array{0:float,1:int}> Erwartungswert für Seite A und tatsächlicher Ausgang (1/0) */
	public array $predictions = array();

	/** @var list<RatingEvent> */
	public array $events = array();
}
