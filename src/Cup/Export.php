<?php
/**
 * CSV-Export der Cup-Meldungen (Filter ttleague_exports, docs/saisonbetrieb.md).
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Cup;

use TTLeague\Domain\Rating\ClassificationScale;

/**
 * Liste «cup-meldungen»: alle Meldungen der aktiven Saison, eine Zeile je Cupmannschaft.
 * Filter der Anfrage: «saison» (Slug einer anderen Saison) und «konkurrenz» (ID einer
 * Kategorie). Abruf im Backend mit Nonce und ttl_tk oder mit «wp ttleague export cup-meldungen».
 */
final class Export {

	public const NAME = 'cup-meldungen';

	/**
	 * Filter ttleague_exports.
	 *
	 * @param mixed $exports Listen der anderen Module.
	 * @return array<string,mixed>
	 */
	public static function register( $exports ): array {
		$exports               = is_array( $exports ) ? $exports : array();
		$exports[ self::NAME ] = array(
			'title'  => __( 'Cup: Meldungen', 'ttleague' ),
			'header' => array(
				__( 'Saison', 'ttleague' ),
				__( 'Konkurrenz', 'ttleague' ),
				__( 'Mannschaft', 'ttleague' ),
				__( 'Club', 'ttleague' ),
				__( 'Spieler 1', 'ttleague' ),
				__( 'Klassierung 1', 'ttleague' ),
				__( 'Spieler 2', 'ttleague' ),
				__( 'Klassierung 2', 'ttleague' ),
				__( 'Punktesumme', 'ttleague' ),
				__( 'Setzung', 'ttleague' ),
				__( 'Status', 'ttleague' ),
				__( 'Gemeldet am (UTC)', 'ttleague' ),
			),
			'rows'   => array( self::class, 'rows' ),
		);
		return $exports;
	}

	/**
	 * Zeilen des Exports.
	 *
	 * @param array<string,string> $args Filter der Anfrage.
	 * @return list<list<string>>
	 */
	public static function rows( array $args ): array {
		return self::build( Cup::repository(), $args );
	}

	/**
	 * @param array<string,string> $args Filter: saison (Slug), konkurrenz (ID).
	 * @return list<list<string>>
	 */
	public static function build( CupRepository $repo, array $args ): array {
		$slug   = (string) ( $args['saison'] ?? '' );
		$season = '' === $slug ? ( $repo->seasons( 'active' )[0] ?? null ) : $repo->season_by_slug( $slug );
		if ( null === $season ) {
			return array();
		}
		$only = (int) ( $args['konkurrenz'] ?? 0 );
		$rows = array();
		foreach ( $repo->competitions( $season ) as $competition ) {
			if ( $only > 0 && $competition['id'] !== $only ) {
				continue;
			}
			foreach ( $repo->entries( $competition['id'] ) as $entry ) {
				$players = array_pad( $entry['players'], 2, null );
				$cells   = array( $competition['season_name'], $competition['name'], $entry['name'], $entry['club_name'] );
				foreach ( array_slice( $players, 0, 2 ) as $player ) {
					$cells[] = null === $player ? '' : $player['name'];
					$cells[] = null === $player || null === $player['classification'] ? '' : ClassificationScale::label( $player['classification'] );
				}
				$cells[] = null === $entry['points_total'] ? '' : (string) $entry['points_total'];
				$cells[] = null === $entry['seed'] ? '' : (string) $entry['seed'];
				$cells[] = Labels::entry_status( $entry['status'] );
				$cells[] = (string) $entry['registered_at'];
				$rows[]  = $cells;
			}
		}
		return $rows;
	}
}
