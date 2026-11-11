<?php
/**
 * CSV-Listen des Ranglistenturniers: Anmeldungen und Gesamtrangliste.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\RankingTournament;

use TTLeague\Frontend\Support\Format;

/**
 * Angemeldet über den Filter ttleague_exports. Beide Listen gelten für die aktive Saison.
 */
final class Exports {

	public const PARTICIPANTS = 'rl-anmeldungen';
	public const STANDINGS    = 'rl-rangliste';

	/**
	 * Filter ttleague_exports.
	 *
	 * @param mixed $exports Name => Liste.
	 * @return array<string,array<string,mixed>>
	 */
	public static function lists( mixed $exports ): array {
		$exports                       = is_array( $exports ) ? $exports : array();
		$exports[ self::PARTICIPANTS ] = array(
			'title'  => __( 'Ranglistenturnier: Anmeldungen', 'ttleague' ),
			'header' => array(
				__( 'Kategorie', 'ttleague' ),
				__( 'Name', 'ttleague' ),
				__( 'Vorname', 'ttleague' ),
				__( 'Lizenz', 'ttleague' ),
				__( 'Club', 'ttleague' ),
				__( 'Klassierung', 'ttleague' ),
				__( 'Status', 'ttleague' ),
				__( 'Angemeldet am', 'ttleague' ),
			),
			'rows'   => array( self::class, 'participants' ),
		);
		$exports[ self::STANDINGS ]    = array(
			'title'  => __( 'Ranglistenturnier: Gesamtrangliste', 'ttleague' ),
			'header' => array(
				__( 'Kategorie', 'ttleague' ),
				__( 'Rang', 'ttleague' ),
				__( 'Name', 'ttleague' ),
				__( 'Club', 'ttleague' ),
				__( 'Klassierung', 'ttleague' ),
				__( 'Punkte je Runde', 'ttleague' ),
				__( 'Punkte total', 'ttleague' ),
				__( 'Sätze gewonnen', 'ttleague' ),
				__( 'Sätze verloren', 'ttleague' ),
				__( 'Final', 'ttleague' ),
			),
			'rows'   => array( self::class, 'standings' ),
		);
		return $exports;
	}

	/**
	 * Anmeldungen der aktiven Saison, auch abgemeldete.
	 *
	 * @return list<list<string|int|null>>
	 */
	public static function participants(): array {
		$repo        = RankingTournament::repository();
		$season      = $repo->active_season_id();
		$competition = null === $season ? null : $repo->competition_of_season( $season );
		if ( null === $competition ) {
			return array();
		}
		$rows = array();
		foreach ( $repo->participants( $competition['id'], $competition['season_id'] ) as $participant ) {
			$registered = '';
			if ( null !== $participant['registered_at'] ) {
				$registered = ( new \DateTimeImmutable( $participant['registered_at'], new \DateTimeZone( 'UTC' ) ) )->setTimezone( wp_timezone() )->format( 'd.m.Y H:i' );
			}
			$rows[] = array(
				0 === $participant['category'] ? '' : $participant['category'],
				$participant['last_name'],
				$participant['first_name'],
				$participant['license_no'],
				(string) $participant['club_short'],
				$participant['classification'],
				Registrations::REGISTERED === $participant['status'] ? __( 'angemeldet', 'ttleague' ) : __( 'abgemeldet', 'ttleague' ),
				$registered,
			);
		}
		return $rows;
	}

	/**
	 * Gesamtrangliste der abgeschlossenen Runden der aktiven Saison.
	 *
	 * @return list<list<string|int|float|null>>
	 */
	public static function standings(): array {
		$repo        = RankingTournament::repository();
		$season      = $repo->active_season_id();
		$competition = null === $season ? null : $repo->competition_of_season( $season );
		if ( null === $competition ) {
			return array();
		}
		$rows = array();
		foreach ( ( new Standings( $repo ) )->overall( $competition ) as $category => $entries ) {
			foreach ( $entries as $entry ) {
				ksort( $entry['rounds'] );
				$rounds = array();
				foreach ( $entry['rounds'] as $round_no => $points ) {
					$rounds[] = 'R' . $round_no . ' ' . Format::points( $points );
				}
				$rows[] = array(
					$category,
					$entry['rank'],
					$entry['name'],
					(string) $entry['club_short'],
					$entry['classification'],
					implode( ' / ', $rounds ),
					Format::points( $entry['points'] ),
					$entry['sets_won'],
					$entry['sets_lost'],
					$entry['needs_draw'] ? __( 'Los', 'ttleague' ) : ( $entry['qualified'] ? __( 'ja', 'ttleague' ) : '' ),
				);
			}
		}
		return $rows;
	}
}
