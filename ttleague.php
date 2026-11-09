<?php
/**
 * Plugin Name:       ttleague
 * Plugin URI:        https://github.com/cunaz/ttleague
 * Description:       Spielbetrieb einer Tischtennis-Liga: Meisterschaft, Cup, Ranglistenturnier, Spieler und Wertungszahl. Entwickelt für den SFFS Firmensport Region Zürich.
 * Version:           0.1.0
 * Requires at least: 6.7
 * Requires PHP:      8.2
 * Author:            Rupert Wildner
 * Author URI:        https://github.com/cunaz
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       ttleague
 * Domain Path:       /languages
 *
 * @package TTLeague
 * @copyright 2026 Rupert Wildner
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'TTLEAGUE_VERSION', '0.1.0' );
define( 'TTLEAGUE_FILE', __FILE__ );
define( 'TTLEAGUE_DIR', plugin_dir_path( __FILE__ ) );
define( 'TTLEAGUE_URL', plugin_dir_url( __FILE__ ) );

// Composer-Autoloader, falls vorhanden; sonst minimaler PSR-4-Loader für src/.
if ( is_readable( __DIR__ . '/vendor/autoload.php' ) ) {
	require __DIR__ . '/vendor/autoload.php';
} else {
	spl_autoload_register(
		static function ( string $class_name ): void {
			$prefix = 'TTLeague\\';
			if ( 0 !== strncmp( $class_name, $prefix, strlen( $prefix ) ) ) {
				return;
			}
			$relative = str_replace( '\\', '/', substr( $class_name, strlen( $prefix ) ) );
			$file     = __DIR__ . '/src/' . $relative . '.php';
			if ( is_readable( $file ) ) {
				require $file;
			}
		}
	);
}

register_activation_hook( __FILE__, array( \TTLeague\Infrastructure\Database\Installer::class, 'activate' ) );

add_action( 'plugins_loaded', array( \TTLeague\Plugin::class, 'boot' ) );
