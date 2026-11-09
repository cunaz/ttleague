<?php
/**
 * Weiterleitung in einem Test: ersetzt Kopfzeile und exit.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Tests\WordPress;

/**
 * Wird vom Filter wp_redirect geworfen (siehe TestCase), damit Abläufe, die mit
 * wp_safe_redirect() und exit enden, im Test weiterlaufen und ihr Ziel prüfbar ist.
 * Bewusst keine RuntimeException: Die Abläufe fangen RuntimeException selbst ab.
 */
final class Redirect extends \Exception {

	public function __construct( public readonly string $location, public readonly int $status ) {
		parent::__construct( 'Weiterleitung nach ' . $location );
	}
}
