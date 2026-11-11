<?php
/**
 * Adressen der Website für Benachrichtigungen zu Anlässen.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Events;

use TTLeague\Accounts\LoginFlow;

final class WordPressLinks implements Links {

	public function event( array $event ): ?string {
		$post_id = (int) ( $event['post_id'] ?? 0 );
		if ( $post_id <= 0 || 'publish' !== get_post_status( $post_id ) ) {
			return null;
		}
		$url = get_permalink( $post_id );
		return is_string( $url ) && '' !== $url ? $url : null;
	}

	public function my_area(): string {
		return LoginFlow::my_area_url();
	}
}
