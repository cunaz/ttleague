<?php
/**
 * WordPress-Anbindung des Routings: Kontext der aktuellen Anfrage, 404, Weiterleitungen, Titel.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Frontend;

use TTLeague\ReadModel\ReadModel;

final class Router {

	private static ?Context $current = null;

	private static bool $resolved = false;

	/**
	 * Kontext der aktuellen Seite, null ausserhalb der Plugin-Seiten.
	 */
	public static function current(): ?Context {
		if ( self::$resolved ) {
			return self::$current;
		}
		if ( ! did_action( 'wp' ) || is_admin() || ! is_page() ) {
			return null;
		}
		self::$resolved = true;
		$view           = PageInstaller::key_for( (int) get_queried_object_id() );
		if ( null === $view ) {
			return null;
		}
		$route = array(
			'season'   => (string) get_query_var( 'ttl_season' ),
			'group'    => (string) get_query_var( 'ttl_group' ),
			'fixture'  => (string) get_query_var( 'ttl_fixture' ),
			'team'     => (string) get_query_var( 'ttl_team' ),
			'club'     => (string) get_query_var( 'ttl_club' ),
			'license'  => (string) get_query_var( 'ttl_license' ),
			'name'     => (string) get_query_var( 'ttl_name' ),
			'category' => (string) get_query_var( 'ttl_category' ),
		);
		$query = array(
			'saison' => self::get_param( 'saison' ),
			'club'   => self::get_param( 'club' ),
			'q'      => self::get_param( 'q' ),
		);

		self::$current = ( new Resolver( ReadModel::instance(), Frontend::urls() ) )->resolve( $view, $route, $query );
		return self::$current;
	}

	/**
	 * Für Tests und Vorschauen: Kontext setzen oder zurücksetzen.
	 */
	public static function set_current( ?Context $context ): void {
		self::$current  = $context;
		self::$resolved = null !== $context;
	}

	/**
	 * Abfrageparameter (nur lesend, für öffentliche Filter).
	 */
	public static function get_param( string $name ): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- öffentliche, nur lesende Filter.
		$value = isset( $_GET[ $name ] ) && is_string( $_GET[ $name ] ) ? sanitize_text_field( wp_unslash( $_GET[ $name ] ) ) : '';
		return mb_substr( $value, 0, 80 );
	}

	/**
	 * template_redirect: Weiterleitungen und 404-Status.
	 */
	public static function template_redirect(): void {
		$context = self::current();
		if ( null === $context ) {
			return;
		}
		if ( null !== $context->redirect && ! is_preview() ) {
			wp_safe_redirect( $context->redirect, $context->redirect_status );
			exit;
		}
		if ( $context->is_not_found() ) {
			status_header( 404 );
			nocache_headers();
		}
	}

	/**
	 * Dokumenttitel: konkretes Objekt statt Seitentitel.
	 *
	 * @param array<string,string> $parts Titelteile.
	 * @return array<string,string>
	 */
	public static function document_title( array $parts ): array {
		$context = self::current();
		if ( null === $context ) {
			return $parts;
		}
		if ( $context->is_not_found() ) {
			$parts['title'] = __( 'Nicht gefunden', 'ttleague' );
		} elseif ( null !== $context->doc_title ) {
			$parts['title'] = $context->doc_title;
		}
		return $parts;
	}

	/**
	 * Seitenüberschrift (Post-Title-Block) der aktuellen Plugin-Seite.
	 */
	public static function the_title( string $title, int|string $post_id = 0 ): string {
		if ( (int) $post_id <= 0 || ! did_action( 'wp' ) || (int) get_queried_object_id() !== (int) $post_id ) {
			return $title;
		}
		$context = self::current();
		if ( null === $context ) {
			return $title;
		}
		if ( $context->is_not_found() ) {
			return __( 'Nicht gefunden', 'ttleague' );
		}
		return $context->title ?? $title;
	}

	/**
	 * Kanonische Adresse des konkreten Objekts.
	 */
	public static function canonical( string $url ): string {
		$context = self::current();
		return null === $context || null === $context->canonical ? $url : $context->canonical;
	}

	/**
	 * Nicht gefundene Objekte nicht indexieren.
	 *
	 * @param array<string,bool|string> $robots Direktiven.
	 * @return array<string,bool|string>
	 */
	public static function robots( array $robots ): array {
		$context = self::current();
		if ( null !== $context && $context->is_not_found() ) {
			$robots['noindex'] = true;
		}
		return $robots;
	}
}
