<?php
/**
 * Neuberechnung und Kalibrierung der Wertungszahl.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Rating;

use TTLeague\Domain\Rating\Calibration;
use TTLeague\Domain\Rating\RatingEngine;
use TTLeague\Domain\Rating\RatingResult;
use TTLeague\Domain\Rules\SeasonRules;
use TTLeague\Infrastructure\Database\Db;

final class RatingService {

	private RatingRepository $repository;

	public function __construct( private readonly Db $db ) {
		$this->repository = new RatingRepository( $db );
	}

	/**
	 * Regeln für die Wertungszahl: die der aktiven Saison (sonst Standard).
	 */
	public function rules(): SeasonRules {
		$json = $this->db->get_var( "SELECT rules_json FROM {$this->db->table( 'season' )} WHERE status = 'active' ORDER BY start_date DESC LIMIT 1" );
		$data = null === $json ? array() : (array) json_decode( $json, true );
		return SeasonRules::from_array( $data );
	}

	/**
	 * Berechnet alle Wertungszahlen neu und speichert sie.
	 */
	public function recalc( bool $reanchor = true ): RatingResult {
		$games   = $this->repository->games();
		$classes = $this->repository->classifications();
		$engine  = new RatingEngine( $this->rules(), $reanchor );
		$result  = $engine->run( $games, static fn( int $player, int $season ) => $classes[ $season ][ $player ] ?? null );
		$this->repository->save( $result );
		return $result;
	}

	public function calibration(): Calibration {
		return new Calibration( $this->repository->games(), $this->repository->classifications(), $this->repository->season_order() );
	}
}
