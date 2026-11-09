<?php
/**
 * Zerlegt SQL mit %d/%s/%f-Platzhaltern (Syntax von $wpdb->prepare()).
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Infrastructure\Database;

final class Placeholders {

	/**
	 * Zerlegt das SQL in Literale und Platzhalter. %% bleibt als %% im Literal stehen.
	 *
	 * @return array{literals:list<string>,tokens:list<string>} literals hat ein Element mehr als tokens.
	 */
	public static function split( string $sql, int $expected ): array {
		$literals = array();
		$tokens   = array();
		$current  = '';
		$length   = strlen( $sql );
		for ( $i = 0; $i < $length; $i++ ) {
			$char = $sql[ $i ];
			if ( '%' !== $char ) {
				$current .= $char;
				continue;
			}
			$next = $sql[ $i + 1 ] ?? '';
			if ( '%' === $next ) {
				$current .= '%%';
				++$i;
				continue;
			}
			if ( in_array( $next, array( 'd', 's', 'f' ), true ) ) {
				$literals[] = $current;
				$tokens[]   = '%' . $next;
				$current    = '';
				++$i;
				continue;
			}
			throw new DbException( 'Unbekannter Platzhalter in SQL: %' . $next );
		}
		$literals[] = $current;

		if ( count( $tokens ) !== $expected ) {
			throw new DbException( sprintf( 'SQL erwartet %d Werte, übergeben wurden %d.', count( $tokens ), $expected ) );
		}
		return array(
			'literals' => $literals,
			'tokens'   => $tokens,
		);
	}
}
