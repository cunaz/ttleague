<?php
/**
 * REST-API der Verschiebung unter /wp-json/ttleague/v1.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Postponement;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Meldungen gehen als JSON hinaus, nie als HTML (wie src/Rest).

use TTLeague\Access\Capabilities;
use TTLeague\Rest\ResultController;
use TTLeague\Results\ResultException;
use TTLeague\Results\Results;

/**
 * Authentifizierung über das WordPress-Cookie mit X-WP-Nonce, Fehler wie in der Erfassung
 * (WP_Error mit HTTP-Status und Feldfehlern in data.errors). Jede Route verlangt eine
 * Anmeldung (401) und das Recht ttl_reschedule_fixture für die Begegnung (403); ob die Aktion
 * im aktuellen Stand erlaubt ist, entscheidet der PostponementService.
 */
final class RestController {

	public const FIXTURE_ROUTE = '/fixtures/(?P<id>\d+)/postponement';

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
			self::FIXTURE_ROUTE,
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( self::class, 'overview' ),
					'permission_callback' => array( ResultController::class, 'logged_in' ),
					'args'                => $id,
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( self::class, 'request' ),
					'permission_callback' => array( ResultController::class, 'logged_in' ),
					'args'                => $id,
				),
			)
		);
		foreach ( array( 'accept', 'decline', 'withdraw', 'decision' ) as $action ) {
			register_rest_route(
				ResultController::NAMESPACE,
				'/postponements/(?P<id>\d+)/' . $action,
				array(
					'methods'             => 'POST',
					'callback'            => array( self::class, $action ),
					'permission_callback' => array( ResultController::class, 'logged_in' ),
					'args'                => $id,
				)
			);
		}
	}

	/**
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function overview( \WP_REST_Request $request ) {
		return self::run(
			static function () use ( $request ): array {
				$fixture_id = self::fixture( (int) $request['id'] );
				return Postponements::service()->overview( $fixture_id, Results::current_actor() );
			}
		);
	}

	/**
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function request( \WP_REST_Request $request ) {
		return self::run(
			static function () use ( $request ): array {
				$fixture_id = self::fixture( (int) $request['id'] );
				return Postponements::service()->request( $fixture_id, Results::current_actor(), self::body( $request ) );
			}
		);
	}

	/**
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function accept( \WP_REST_Request $request ) {
		return self::run( static fn(): array => Postponements::service()->accept( self::postponement( (int) $request['id'] ), Results::current_actor() ) );
	}

	/**
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function decline( \WP_REST_Request $request ) {
		return self::run(
			static function () use ( $request ): array {
				$note = self::body( $request )['note'] ?? null;
				return Postponements::service()->decline( self::postponement( (int) $request['id'] ), Results::current_actor(), is_string( $note ) ? $note : null );
			}
		);
	}

	/**
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function withdraw( \WP_REST_Request $request ) {
		return self::run( static fn(): array => Postponements::service()->withdraw( self::postponement( (int) $request['id'] ), Results::current_actor() ) );
	}

	/**
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function decision( \WP_REST_Request $request ) {
		return self::run( static fn(): array => Postponements::service()->decide( self::postponement( (int) $request['id'] ), Results::current_actor(), self::body( $request ) ) );
	}

	/**
	 * Begegnung prüfen: Es gibt sie (404), und das Konto hat ttl_reschedule_fixture (403).
	 *
	 * @throws ResultException Bei fehlender Begegnung oder fehlendem Recht.
	 */
	private static function fixture( int $fixture_id ): int {
		Postponements::service()->context( $fixture_id );
		if ( ! current_user_can( Capabilities::RESCHEDULE, $fixture_id ) ) {
			throw ResultException::forbidden( __( 'Termine verschieben die beiden Mannschaften (Captain oder TK-Chef) und die TK.', 'ttleague' ) );
		}
		return $fixture_id;
	}

	/**
	 * Antrag prüfen: Es gibt ihn (404), und das Konto hat das Recht für seine Begegnung (403).
	 *
	 * @throws ResultException Bei fehlendem Antrag oder fehlendem Recht.
	 */
	private static function postponement( int $postponement_id ): int {
		self::fixture( Postponements::service()->fixture_of( $postponement_id ) );
		return $postponement_id;
	}

	/**
	 * @return array<string,mixed>
	 */
	private static function body( \WP_REST_Request $request ): array {
		$json = $request->get_json_params();
		if ( is_array( $json ) ) {
			return $json;
		}
		$params = $request->get_body_params();
		return is_array( $params ) ? $params : array();
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
		}
	}
}
