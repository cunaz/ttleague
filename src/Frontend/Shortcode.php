<?php
/**
 * Shortcode [ttleague view="…"] als Rückfall für klassische Inhalte.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Frontend;

/**
 * Beispiele: [ttleague view="group" season="2026-27" group="a"],
 * [ttleague view="player" player="90001"], [ttleague view="latest-results" count="5"].
 * Die Attribute entsprechen den Block-Attributen; view ist der Blockname ohne Namensraum.
 */
final class Shortcode {

	public const TAG = 'ttleague';

	/**
	 * Kurzformen für view.
	 */
	private const ALIASES = array(
		'meisterschaft'     => 'season-overview',
		'season'            => 'season-overview',
		'gruppe'            => 'group',
		'begegnung'         => 'match-report',
		'matchreport'       => 'match-report',
		'mannschaft'        => 'team',
		'spieler'           => 'players',
		'spielerprofil'     => 'player',
		'ranglistenturnier' => 'ranking-tournament',
		'ranking'           => 'ranking-tournament',
		'resultate'         => 'latest-results',
		'termine'           => 'upcoming-fixtures',
		'tabellen'          => 'standings-compact',
		'saisonwahl'        => 'season-switcher',
		'archiv'            => 'archive',
	);

	public static function register(): void {
		add_shortcode( self::TAG, array( self::class, 'render' ) );
	}

	/**
	 * @param array<string,string>|string $atts Attribute.
	 */
	public static function render( $atts ): string {
		$atts = is_array( $atts ) ? array_change_key_case( $atts, CASE_LOWER ) : array();
		$view = self::view_name( (string) ( $atts['view'] ?? '' ) );
		if ( null === $view ) {
			return '';
		}
		unset( $atts['view'] );
		$attributes = array();
		foreach ( $atts as $key => $value ) {
			$attributes[ sanitize_key( (string) $key ) ] = in_array( $key, array( 'fixture', 'player', 'count', 'category' ), true ) && is_numeric( $value ) ? (int) $value : sanitize_text_field( (string) $value );
		}
		wp_enqueue_style( Assets::STYLE );
		return '<div class="ttl ttl-' . esc_attr( $view ) . '">' . Blocks::render_view( $view, $attributes ) . '</div>';
	}

	/**
	 * Blockname zu view oder null.
	 */
	public static function view_name( string $view ): ?string {
		$view = strtolower( trim( $view ) );
		$view = self::ALIASES[ $view ] ?? $view;
		return isset( Blocks::VIEWS[ $view ] ) ? $view : null;
	}
}
