<?php
/**
 * Ende eines WP-CLI-Befehls im Test (anstelle von exit).
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Tests\WordPress\Cli;

/**
 * Geworfen vom WP_CLI-Ersatz der Tests bei WP_CLI::error() und einer abgelehnten Rückfrage.
 */
final class CliStop extends \RuntimeException {
}
