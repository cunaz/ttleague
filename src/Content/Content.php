<?php
/**
 * Redaktionelle Inhalte des Plugins (Dokumente).
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Content;

final class Content {

	public static function register(): void {
		add_action( 'init', array( Documents::class, 'register' ) );
	}
}
