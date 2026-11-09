<?php
/**
 * Stylesheet der Darstellung und Editor-Skript der Blöcke.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Frontend;

use TTLeague\ReadModel\ReadModel;

final class Assets {

	public const STYLE  = 'ttleague-frontend';
	public const EDITOR = 'ttleague-editor';

	/**
	 * Registriert Stil und Editor-Skript (init, vor den Blöcken).
	 */
	public static function register(): void {
		wp_register_style( self::STYLE, TTLEAGUE_URL . 'assets/css/frontend.css', array(), self::version( 'assets/css/frontend.css' ) );
		wp_register_script(
			self::EDITOR,
			TTLEAGUE_URL . 'assets/js/editor.js',
			array( 'wp-blocks', 'wp-element', 'wp-server-side-render', 'wp-block-editor', 'wp-components', 'wp-i18n' ),
			self::version( 'assets/js/editor.js' ),
			true
		);
	}

	/**
	 * Stil auf allen öffentlichen Seiten (Kopfzeile mit Saisonwahl, Shortcodes).
	 */
	public static function enqueue_frontend(): void {
		wp_enqueue_style( self::STYLE );
	}

	/**
	 * Daten für die Auswahllisten im Editor.
	 */
	public static function editor_data(): void {
		$seasons = array();
		$clubs   = array();
		try {
			$model = ReadModel::instance();
			foreach ( $model->seasons->all() as $season ) {
				$seasons[] = array(
					'value' => $season['slug'],
					'label' => $season['name'],
				);
			}
			foreach ( $model->clubs->all() as $club ) {
				$clubs[] = array(
					'value' => $club['slug'],
					'label' => $club['short_name'],
				);
			}
		} catch ( \Throwable ) {
			$seasons = array();
		}
		wp_add_inline_script(
			self::EDITOR,
			'window.ttleagueEditor = ' . wp_json_encode(
				array(
					'seasons' => $seasons,
					'clubs'   => $clubs,
				)
			) . ';',
			'before'
		);
	}

	private static function version( string $file ): string {
		$path = TTLEAGUE_DIR . $file;
		return is_readable( $path ) ? TTLEAGUE_VERSION . '.' . (string) filemtime( $path ) : TTLEAGUE_VERSION;
	}
}
