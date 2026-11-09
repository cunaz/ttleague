<?php
/**
 * Minimale Stubs der WordPress-Funktionen, die Formatierung, Adressbau und Ansichten nutzen.
 * Übersetzungen geben den Quelltext zurück, Escaping verhält sich wie in WordPress (vereinfacht).
 *
 * @package TTLeague
 */

declare(strict_types=1);

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound, Universal.Files.SeparateFunctionsFromOO.Mixed, WordPress.WP.I18n -- Stubs der WordPress-Funktionen für Tests ohne WordPress.

if ( ! function_exists( '__' ) ) {
	function __( string $text, string $domain = 'default' ): string {
		unset( $domain );
		return $text;
	}
}

if ( ! function_exists( '_n' ) ) {
	function _n( string $single, string $plural, int $number, string $domain = 'default' ): string {
		unset( $domain );
		return 1 === $number ? $single : $plural;
	}
}

if ( ! function_exists( 'esc_html' ) ) {
	function esc_html( string $text ): string {
		return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' );
	}
}

if ( ! function_exists( 'esc_attr' ) ) {
	function esc_attr( string $text ): string {
		return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' );
	}
}

if ( ! function_exists( 'esc_url' ) ) {
	function esc_url( string $url ): string {
		return htmlspecialchars( $url, ENT_QUOTES, 'UTF-8' );
	}
}

if ( ! function_exists( 'esc_html__' ) ) {
	function esc_html__( string $text, string $domain = 'default' ): string {
		return esc_html( __( $text, $domain ) );
	}
}

if ( ! function_exists( 'esc_attr__' ) ) {
	function esc_attr__( string $text, string $domain = 'default' ): string {
		return esc_attr( __( $text, $domain ) );
	}
}

if ( ! function_exists( 'wp_kses' ) ) {
	/**
	 * @param array<string,mixed> $allowed Erlaubte Elemente (im Stub ungenutzt).
	 */
	function wp_kses( string $content, array $allowed ): string {
		unset( $allowed );
		return $content;
	}
}

if ( ! function_exists( 'selected' ) ) {
	function selected( string $selected, string $current, bool $display = true ): string {
		unset( $display );
		return $selected === $current ? ' selected=\'selected\'' : '';
	}
}

if ( ! function_exists( 'wp_parse_url' ) ) {
	function wp_parse_url( string $url, int $component = -1 ): mixed {
		return parse_url( $url, $component ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url
	}
}

if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( mixed $value, int $flags = 0, int $depth = 512 ): string|false {
		return json_encode( $value, $flags, $depth ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
	}
}

if ( ! function_exists( 'is_user_logged_in' ) ) {
	/**
	 * In Tests ist niemand angemeldet (öffentliche Ausgabe).
	 */
	function is_user_logged_in(): bool {
		return false;
	}
}
