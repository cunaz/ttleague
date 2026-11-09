<?php
/**
 * REST-API der Push-Abos unter /wp-json/ttleague/v1/push/subscriptions.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Notifications\Push;

use TTLeague\Accounts\Services;
use TTLeague\Notifications\Notifications;

/**
 * POST speichert das Abo des Browsers für die angemeldete Person, DELETE entfernt es. Anmeldung
 * über das WordPress-Cookie mit X-WP-Nonce (ohne Nonce gilt die Anfrage als abgemeldet).
 *
 * Fehler: 401 rest_forbidden (nicht angemeldet), 403 ttl_forbidden (Konto ohne Person),
 * 503 ttl_unavailable (Push nicht eingerichtet), 422 ttl_invalid mit Feldfehlern.
 */
final class SubscriptionController {

	public const NAMESPACE = 'ttleague/v1';

	public const ROUTE = '/push/subscriptions';

	public static function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			self::ROUTE,
			array(
				array(
					'methods'             => 'POST',
					'callback'            => array( self::class, 'create' ),
					'permission_callback' => array( self::class, 'logged_in' ),
				),
				array(
					'methods'             => 'DELETE',
					'callback'            => array( self::class, 'delete' ),
					'permission_callback' => array( self::class, 'logged_in' ),
				),
			)
		);
	}

	/**
	 * @return true|\WP_Error
	 */
	public static function logged_in() {
		if ( is_user_logged_in() ) {
			return true;
		}
		return new \WP_Error( 'rest_forbidden', __( 'Bitte melde dich an.', 'ttleague' ), array( 'status' => 401 ) );
	}

	/**
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function create( \WP_REST_Request $request ) {
		$person = self::person();
		if ( $person instanceof \WP_Error ) {
			return $person;
		}
		if ( null === VapidKeys::get() ) {
			return new \WP_Error( 'ttl_unavailable', __( 'Push-Benachrichtigungen sind auf dieser Website nicht eingerichtet.', 'ttleague' ), array( 'status' => 503 ) );
		}
		$result = Notifications::validator()->validate( self::body( $request ) );
		if ( null === $result['subscription'] ) {
			return new \WP_Error(
				'ttl_invalid',
				__( 'Das Push-Abo ist ungültig.', 'ttleague' ),
				array(
					'status' => 422,
					'errors' => $result['errors'],
				)
			);
		}
		Notifications::subscriptions()->save( $person, $result['subscription'] );
		return new \WP_REST_Response( array( 'subscribed' => true ), 201 );
	}

	/**
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function delete( \WP_REST_Request $request ) {
		$person = self::person();
		if ( $person instanceof \WP_Error ) {
			return $person;
		}
		$body     = self::body( $request );
		$endpoint = is_string( $body['endpoint'] ?? null ) ? trim( $body['endpoint'] ) : '';
		if ( '' === $endpoint ) {
			return new \WP_Error(
				'ttl_invalid',
				__( 'Die Adresse des Push-Dienstes fehlt.', 'ttleague' ),
				array(
					'status' => 422,
					'errors' => array(
						array(
							'field'   => 'endpoint',
							'message' => __( 'Die Adresse des Push-Dienstes fehlt.', 'ttleague' ),
						),
					),
				)
			);
		}
		return new \WP_REST_Response( array( 'deleted' => Notifications::subscriptions()->remove( $person, $endpoint ) ), 200 );
	}

	/**
	 * Person des angemeldeten Kontos.
	 *
	 * @return int|\WP_Error
	 */
	private static function person() {
		$person = Services::policy()->person_for_user( get_current_user_id() );
		if ( null === $person ) {
			return new \WP_Error( 'ttl_forbidden', __( 'Dein Konto ist mit keiner Person verknüpft.', 'ttleague' ), array( 'status' => 403 ) );
		}
		return $person;
	}

	/**
	 * JSON-Inhalt der Anfrage, sonst die Parameter.
	 *
	 * @return array<string,mixed>
	 */
	private static function body( \WP_REST_Request $request ): array {
		$json = $request->get_json_params();
		return is_array( $json ) && array() !== $json ? $json : $request->get_params();
	}
}
