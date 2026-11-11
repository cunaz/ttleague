<?php
/**
 * CSV-Listen der Meldungen: Stand je Club und gemeldete Spieler.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Registration;

use TTLeague\Accounts\Services;
use TTLeague\Domain\Rating\ClassificationScale;
use TTLeague\Season\SeasonRepository;

/**
 * Angemeldet über den Filter ttleague_exports; Abruf im Backend (Nonce und ttl_tk prüft
 * Export\Exports) und mit «wp ttleague export registrations» bzw. «registration-players».
 * Filter: season (ID oder Kürzel wie 2027-28; ohne Angabe die geplante, sonst die aktive
 * Saison), bei den Spielern zusätzlich club (ID).
 */
final class RegistrationExport {

	public const CLUBS   = 'registrations';
	public const PLAYERS = 'registration-players';

	/**
	 * Filter ttleague_exports.
	 *
	 * @param mixed $exports Name => Liste.
	 * @return array<string,mixed>
	 */
	public static function exports( mixed $exports ): array {
		$exports                  = is_array( $exports ) ? $exports : array();
		$exports[ self::CLUBS ]   = array(
			'title'  => __( 'Meldungen: Stand je Club', 'ttleague' ),
			'header' => array(
				__( 'Saison', 'ttleague' ),
				__( 'Club', 'ttleague' ),
				__( 'Stand', 'ttleague' ),
				__( 'Eingereicht am', 'ttleague' ),
				__( 'Geprüft am', 'ttleague' ),
				__( 'Spieler', 'ttleague' ),
				__( 'davon provisorisch', 'ttleague' ),
				__( 'Mannschaften', 'ttleague' ),
				__( 'Offene Neumeldungen', 'ttleague' ),
				__( 'Bemerkung der TK', 'ttleague' ),
			),
			'rows'   => array( self::class, 'club_rows' ),
		);
		$exports[ self::PLAYERS ] = array(
			'title'  => __( 'Meldungen: gemeldete Spieler', 'ttleague' ),
			'header' => array(
				__( 'Saison', 'ttleague' ),
				__( 'Club', 'ttleague' ),
				__( 'Lizenz', 'ttleague' ),
				__( 'Nachname', 'ttleague' ),
				__( 'Vorname', 'ttleague' ),
				__( 'Klassierung', 'ttleague' ),
				__( 'Stand', 'ttleague' ),
				__( 'Stammspieler in', 'ttleague' ),
			),
			'rows'   => array( self::class, 'player_rows' ),
		);
		return $exports;
	}

	/**
	 * @param array<string,string> $args Filter der Anfrage.
	 * @return list<list<string|int|null>>
	 */
	public static function club_rows( array $args ): array {
		$season = self::season( $args );
		if ( null === $season ) {
			return array();
		}
		$rows = array();
		foreach ( ( new RegistrationRepository( Services::db() ) )->overview( (int) $season['id'] ) as $row ) {
			$rows[] = array(
				(string) $season['name'],
				$row['name'],
				SeasonRepository::PLANNED === $season['status'] ? RegistrationView::status_label( $row['status'] ) : '',
				self::local( $row['submitted_at'] ),
				self::local( $row['reviewed_at'] ),
				$row['players'],
				$row['provisional'],
				$row['teams'],
				$row['requests'],
				(string) $row['note'],
			);
		}
		return $rows;
	}

	/**
	 * @param array<string,string> $args Filter der Anfrage.
	 * @return list<list<string|int|null>>
	 */
	public static function player_rows( array $args ): array {
		$season = self::season( $args );
		if ( null === $season ) {
			return array();
		}
		$club = isset( $args['club'] ) && ctype_digit( $args['club'] ) && (int) $args['club'] > 0 ? (int) $args['club'] : null;
		$rows = array();
		foreach ( ( new RegistrationRepository( Services::db() ) )->export_players( (int) $season['id'], $club ) as $row ) {
			$rows[] = array(
				(string) $season['name'],
				$row['club'],
				$row['license_no'],
				$row['last_name'],
				$row['first_name'],
				ClassificationScale::label( $row['classification'] ),
				ClubRegistration::PROVISIONAL === $row['status'] ? __( 'provisorisch', 'ttleague' ) : __( 'freigegeben', 'ttleague' ),
				(string) $row['team'],
			);
		}
		return $rows;
	}

	/**
	 * Saison aus dem Filter: ID oder Kürzel; ohne Angabe die geplante, sonst die aktive.
	 *
	 * @param array<string,string> $args Filter.
	 * @return array<string,mixed>|null
	 */
	private static function season( array $args ): ?array {
		$repo  = new SeasonRepository( Services::db() );
		$value = trim( (string) ( $args['season'] ?? '' ) );
		if ( '' === $value ) {
			return $repo->planned() ?? $repo->active();
		}
		if ( ctype_digit( $value ) ) {
			return $repo->by_id( (int) $value );
		}
		$db = Services::db();
		$id = $db->get_var( "SELECT id FROM {$db->table( 'season' )} WHERE slug = %s", array( $value ) );
		return null === $id ? null : $repo->by_id( (int) $id );
	}

	/**
	 * Systemzeitpunkt (UTC) in Ortszeit der Website, leer ohne Wert.
	 */
	private static function local( ?string $utc ): string {
		return null === $utc || '' === $utc ? '' : (string) get_date_from_gmt( $utc, 'd.m.Y H:i' );
	}
}
