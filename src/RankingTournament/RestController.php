<?php
/**
 * REST-API des Gruppenzettels unter /wp-json/ttleague/v1/rl.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\RankingTournament;

use TTLeague\Rest\ResultController;
use TTLeague\Results\ResultException;
use TTLeague\Results\Results;

/**
 * Wie die REST-API der Erfassung: Anmeldung über das WordPress-Cookie mit X-WP-Nonce, hier wird
 * nur verlangt, dass jemand angemeldet ist (401). Rechte, Status und Eingabe prüft GroupEntry;
 * Fehler kommen als WP_Error mit HTTP-Status und Feldfehlern (data.errors): 403 ttl_forbidden,
 * 404 ttl_not_found, 409 ttl_conflict, 422 ttl_invalid.
 *
 * GET  rl/groups/{id}/entry   Formulardaten: Personen, Spielfolge, erfasster Stand, Rechte, version
 * POST rl/groups/{id}/result  Gruppenzettel speichern: players, games, next_blocked, version
 */
final class RestController {

	public static function register_routes(): void {
		$id = array(
			'id' => array(
				'type'     => 'integer',
				'required' => true,
				'minimum'  => 1,
			),
		);
		register_rest_route(
			ResultController::NAMESPACE,
			'/rl/groups/(?P<id>\d+)/entry',
			array(
				'methods'             => 'GET',
				'callback'            => array( self::class, 'entry' ),
				'permission_callback' => array( ResultController::class, 'logged_in' ),
				'args'                => $id,
			)
		);
		register_rest_route(
			ResultController::NAMESPACE,
			'/rl/groups/(?P<id>\d+)/result',
			array(
				'methods'             => 'POST',
				'callback'            => array( self::class, 'result' ),
				'permission_callback' => array( ResultController::class, 'logged_in' ),
				'args'                => $id,
			)
		);
	}

	/**
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function entry( \WP_REST_Request $request ) {
		return self::run( static fn() => RankingTournament::entry()->entry( (int) $request['id'], Results::current_actor() ) );
	}

	/**
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function result( \WP_REST_Request $request ) {
		$body = $request->get_json_params();
		if ( ! is_array( $body ) ) {
			$body = $request->get_body_params();
		}
		$body = is_array( $body ) ? $body : array();
		return self::run( static fn() => RankingTournament::entry()->save( (int) $request['id'], $body, Results::current_actor() ) );
	}

	/**
	 * Führt eine Aktion aus und übersetzt fachliche Fehler in WP_Error.
	 *
	 * @param callable():mixed $work Aktion.
	 * @return \WP_REST_Response|\WP_Error
	 */
	private static function run( callable $work ) {
		try {
			$response = rest_ensure_response( $work() );
			$response->header( 'Cache-Control', 'no-store' );
			return $response;
		} catch ( ResultException $e ) {
			return new \WP_Error(
				$e->error_code,
				$e->getMessage(),
				array(
					'status' => $e->status,
					'errors' => $e->errors,
				)
			);
		} catch ( \InvalidArgumentException $e ) {
			// Ungültige Regeln der Saison: Die Schreibseite ersetzt sie nicht durch Standardwerte.
			return new \WP_Error( ResultException::CONFLICT, __( 'Die Regeln der Saison sind ungültig. Bitte wende dich an die TK.', 'ttleague' ), array( 'status' => 409 ) );
		}
	}
}
