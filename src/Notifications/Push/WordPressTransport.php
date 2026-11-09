<?php
/**
 * Versand über die HTTP-API von WordPress.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Notifications\Push;

/**
 * wp_remote_post mit reject_unsafe_urls (wie wp_safe_remote_post): keine Weiterleitungen, keine
 * Adressen im lokalen Netz. Zeitlimit 10 Sekunden.
 */
final class WordPressTransport implements Transport {

	public function post( string $url, array $headers, string $body ): int {
		$response = wp_remote_post(
			$url,
			array(
				'headers'            => $headers,
				'body'               => $body,
				'timeout'            => 10,
				'redirection'        => 0,
				'reject_unsafe_urls' => true,
				'data_format'        => 'body',
			)
		);
		if ( is_wp_error( $response ) ) {
			return 0;
		}
		return (int) wp_remote_retrieve_response_code( $response );
	}
}
