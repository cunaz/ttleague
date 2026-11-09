<?php
/**
 * REST-API der Resultaterfassung unter /wp-json/ttleague/v1.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Rest;

use TTLeague\Results\ResultException;
use TTLeague\Results\ResultInput;
use TTLeague\Results\Results;

/**
 * Authentifizierung über das WordPress-Cookie mit X-WP-Nonce. Die Rechte prüft der
 * ResultService; hier wird nur verlangt, dass jemand angemeldet ist. Fehler kommen als
 * WP_Error mit HTTP-Status und Feldfehlern (data.errors).
 */
final class ResultController {

	public const NAMESPACE = 'ttleague/v1';

	/** Höchstgrösse eines Fotos in Bytes. */
	public const PHOTO_MAX_BYTES = 10485760;

	/**
	 * Erlaubte Bildformate für Fotos des Spielberichts.
	 */
	public const PHOTO_MIMES = array(
		'jpg|jpeg|jpe' => 'image/jpeg',
		'png'          => 'image/png',
		'webp'         => 'image/webp',
		'heic'         => 'image/heic',
		'heif'         => 'image/heif',
	);

	public static function register_routes(): void {
		$id     = array(
			'id' => array(
				'type'     => 'integer',
				'required' => true,
				'minimum'  => 1,
			),
		);
		$routes = array(
			'/fixtures/(?P<id>\d+)/entry'     => array( 'GET', 'entry' ),
			'/fixtures/(?P<id>\d+)/result'    => array( 'POST', 'result' ),
			'/fixtures/(?P<id>\d+)/confirm'   => array( 'POST', 'confirm' ),
			'/fixtures/(?P<id>\d+)/objection' => array( 'POST', 'objection' ),
			'/fixtures/(?P<id>\d+)/decision'  => array( 'POST', 'decision' ),
			'/fixtures/(?P<id>\d+)/photo'     => array( 'POST', 'photo' ),
		);
		foreach ( $routes as $route => [ $method, $callback ] ) {
			register_rest_route(
				self::NAMESPACE,
				$route,
				array(
					'methods'             => $method,
					'callback'            => array( self::class, $callback ),
					'permission_callback' => array( self::class, 'logged_in' ),
					'args'                => $id,
				)
			);
		}
		register_rest_route(
			self::NAMESPACE,
			'/me/fixtures',
			array(
				'methods'             => 'GET',
				'callback'            => array( self::class, 'my_fixtures' ),
				'permission_callback' => array( self::class, 'logged_in' ),
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
	public static function entry( \WP_REST_Request $request ) {
		return self::run( static fn() => Results::service()->entry( (int) $request['id'], Results::current_actor() ) );
	}

	/**
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function result( \WP_REST_Request $request ) {
		return self::run( static fn() => Results::service()->submit( (int) $request['id'], self::body( $request ), Results::current_actor() ) );
	}

	/**
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function confirm( \WP_REST_Request $request ) {
		$body = self::body( $request );
		return self::run( static fn() => Results::service()->confirm( (int) $request['id'], Results::current_actor(), self::version( $body ) ) );
	}

	/**
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function objection( \WP_REST_Request $request ) {
		$body   = self::body( $request );
		$reason = is_string( $body['reason'] ?? null ) ? $body['reason'] : '';
		return self::run( static fn() => Results::service()->object( (int) $request['id'], Results::current_actor(), $reason, self::version( $body ) ) );
	}

	/**
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function decision( \WP_REST_Request $request ) {
		return self::run( static fn() => Results::service()->decide( (int) $request['id'], Results::current_actor(), self::body( $request ) ) );
	}

	/**
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function my_fixtures() {
		return self::run( static fn() => Results::service()->my_fixtures( Results::current_actor() ) );
	}

	/**
	 * Foto des Spielberichts hochladen (Multipart, Feld «file»).
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function photo( \WP_REST_Request $request ) {
		return self::run(
			static function () use ( $request ): array {
				$fixture_id = (int) $request['id'];
				$service    = Results::service();
				$actor      = Results::current_actor();
				$ctx        = $service->context( $fixture_id );
				if ( ! $service->may_attach_photo( $ctx, $service->roles( $ctx, $actor ) ) ) {
					throw ResultException::forbidden( __( 'Du kannst zu dieser Begegnung kein Foto hochladen.', 'ttleague' ) );
				}
				$attachment_id = self::store_photo( $fixture_id, $request->get_file_params()['file'] ?? null );
				$service->attach_photo( $fixture_id, $actor, $attachment_id );
				return array(
					'attachment_id' => $attachment_id,
					'url'           => (string) wp_get_attachment_url( $attachment_id ),
				);
			}
		);
	}

	/**
	 * Prüft und speichert das Foto als privaten Anhang mit zufälligem Dateinamen.
	 *
	 * @param mixed $file Eintrag aus $_FILES.
	 * @throws ResultException Bei ungültiger Datei.
	 */
	private static function store_photo( int $fixture_id, mixed $file ): int {
		if ( ! is_array( $file ) || UPLOAD_ERR_OK !== ( $file['error'] ?? UPLOAD_ERR_NO_FILE ) || ! is_uploaded_file( (string) ( $file['tmp_name'] ?? '' ) ) ) {
			throw ResultException::invalid( array( ResultInput::error( 'file', __( 'Es ist kein Foto angekommen.', 'ttleague' ) ) ) );
		}
		if ( (int) ( $file['size'] ?? 0 ) > self::PHOTO_MAX_BYTES ) {
			throw ResultException::invalid( array( ResultInput::error( 'file', __( 'Das Foto ist zu gross (höchstens 10 MB).', 'ttleague' ) ) ) );
		}
		$check = wp_check_filetype_and_ext( (string) $file['tmp_name'], (string) ( $file['name'] ?? '' ), self::PHOTO_MIMES );
		if ( empty( $check['type'] ) || empty( $check['ext'] ) ) {
			throw ResultException::invalid( array( ResultInput::error( 'file', __( 'Bitte ein Foto als JPEG, PNG, WebP oder HEIC hochladen.', 'ttleague' ) ) ) );
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$file['name']        = sprintf( 'spielbericht-%d-%s.%s', $fixture_id, bin2hex( random_bytes( 8 ) ), $check['ext'] );
		$_FILES['ttl_photo'] = $file; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- REST-Anfrage mit Nonce; media_handle_upload liest aus $_FILES.

		$attachment_id = media_handle_upload(
			'ttl_photo',
			0,
			array(
				/* translators: %d: Nummer der Begegnung */
				'post_title'  => sprintf( __( 'Spielbericht Begegnung %d', 'ttleague' ), $fixture_id ),
				'post_status' => 'private',
			),
			array(
				'test_form' => false,
				'mimes'     => self::PHOTO_MIMES,
			)
		);
		unset( $_FILES['ttl_photo'] );
		if ( is_wp_error( $attachment_id ) ) {
			throw ResultException::invalid( array( ResultInput::error( 'file', $attachment_id->get_error_message() ) ) );
		}
		update_post_meta( $attachment_id, '_ttleague_fixture', $fixture_id );
		return (int) $attachment_id;
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
	 * @param array<string,mixed> $body Anfrage.
	 */
	private static function version( array $body ): ?string {
		return is_string( $body['version'] ?? null ) && '' !== $body['version'] ? $body['version'] : null;
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
