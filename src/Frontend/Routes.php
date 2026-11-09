<?php
/**
 * Rewrite-Regeln und Query-Variablen der öffentlichen Darstellung.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Frontend;

use TTLeague\Frontend\Support\Urls;

/**
 * Die Regeln zeigen per page_id auf die Seiten aus PageInstaller und übergeben die
 * Parameter als Query-Variablen. Die Pfade folgen den aktuellen Seitenpfaden.
 */
final class Routes {

	public const QUERY_VARS = array( 'ttl_season', 'ttl_group', 'ttl_fixture', 'ttl_team', 'ttl_club', 'ttl_license', 'ttl_name', 'ttl_category' );

	/**
	 * @param list<string> $vars Öffentliche Query-Variablen.
	 * @return list<string>
	 */
	public static function query_vars( array $vars ): array {
		return array_values( array_unique( array_merge( $vars, self::QUERY_VARS ) ) );
	}

	/**
	 * Regeln für alle angelegten Seiten (init).
	 */
	public static function add_rewrite_rules(): void {
		foreach ( self::rules( PageInstaller::ids(), PageInstaller::paths() ) as $regex => $query ) {
			add_rewrite_rule( $regex, $query, 'top' );
		}
	}

	/**
	 * Regeln als Regex => Query; reine Funktion.
	 *
	 * @param array<string,int>    $ids   Seitenschlüssel => Seiten-ID.
	 * @param array<string,string> $paths Seitenschlüssel => Pfad.
	 * @return array<string,string>
	 */
	public static function rules( array $ids, array $paths ): array {
		$paths  = $paths + Urls::DEFAULT_PATHS;
		$season = '(\d{4}-\d{2})';
		$rules  = array();
		$q      = static fn( string $path ): string => preg_quote( trim( $path, '/' ), '#' );

		if ( isset( $ids['championship'] ) ) {
			$rules[ '^' . $q( $paths['championship'] ) . '/' . $season . '/?$' ] = 'index.php?page_id=' . $ids['championship'] . '&ttl_season=$matches[1]';
		}
		if ( isset( $ids['group'] ) ) {
			$rules[ '^' . $q( $paths['championship'] ) . '/' . $season . '/gruppe-([a-z0-9]+(?:-[0-9]+)?)/?$' ] = 'index.php?page_id=' . $ids['group'] . '&ttl_season=$matches[1]&ttl_group=$matches[2]';
		}
		if ( isset( $ids['fixture'] ) ) {
			$rules[ '^' . $q( $paths['fixture'] ) . '/([0-9]+)/?$' ] = 'index.php?page_id=' . $ids['fixture'] . '&ttl_fixture=$matches[1]';
		}
		if ( isset( $ids['team'] ) ) {
			$rules[ '^' . $q( $paths['team'] ) . '/' . $season . '/([a-z0-9-]+)/?$' ] = 'index.php?page_id=' . $ids['team'] . '&ttl_season=$matches[1]&ttl_team=$matches[2]';
		}
		if ( isset( $ids['club'] ) ) {
			$rules[ '^' . $q( $paths['clubs'] ) . '/([a-z0-9-]+)/?$' ] = 'index.php?page_id=' . $ids['club'] . '&ttl_club=$matches[1]';
		}
		if ( isset( $ids['player'] ) ) {
			$rules[ '^' . $q( $paths['players'] ) . '/([0-9]+)(?:-([a-z0-9-]*))?/?$' ] = 'index.php?page_id=' . $ids['player'] . '&ttl_license=$matches[1]&ttl_name=$matches[2]';
		}
		if ( isset( $ids['ranking'] ) ) {
			$rules[ '^' . $q( $paths['ranking'] ) . '/' . $season . '/?$' ] = 'index.php?page_id=' . $ids['ranking'] . '&ttl_season=$matches[1]';
		}
		if ( isset( $ids['cup'] ) ) {
			$rules[ '^' . $q( $paths['cup'] ) . '/' . $season . '/?$' ]         = 'index.php?page_id=' . $ids['cup'] . '&ttl_season=$matches[1]';
			$rules[ '^' . $q( $paths['cup'] ) . '/' . $season . '/([a-z])/?$' ] = 'index.php?page_id=' . $ids['cup'] . '&ttl_season=$matches[1]&ttl_category=$matches[2]';
		}
		return $rules;
	}
}
