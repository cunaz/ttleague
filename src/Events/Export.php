<?php
/**
 * CSV der Teilnehmerliste eines Anlasses (Filter ttleague_exports, Liste «event-participants»).
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Events;

/**
 * Abruf im Backend mit Nonce und ttl_tk (Export\Exports) oder mit
 * «wp ttleague export event-participants --event=12». Ohne gültigen Anlass ist die Liste leer.
 */
final class Export {

	public const NAME = 'event-participants';

	/**
	 * Filter ttleague_exports.
	 *
	 * @param array<string,mixed> $exports Name => Liste.
	 * @return array<string,mixed>
	 */
	public static function register( $exports ): array {
		$exports               = is_array( $exports ) ? $exports : array();
		$exports[ self::NAME ] = array(
			'title'  => __( 'Anlässe: Teilnehmerliste eines Anlasses (Filter event)', 'ttleague' ),
			'header' => array(
				__( 'Anlass', 'ttleague' ),
				__( 'Beginn', 'ttleague' ),
				__( 'Nr.', 'ttleague' ),
				__( 'Status', 'ttleague' ),
				__( 'Nachname', 'ttleague' ),
				__( 'Vorname', 'ttleague' ),
				__( 'Club', 'ttleague' ),
				__( 'Angemeldet am', 'ttleague' ),
				__( 'Bemerkung', 'ttleague' ),
			),
			'rows'   => static fn( array $args ): array => self::rows( $args, Events::repository(), Events::registrations(), wp_timezone() ),
		);
		return $exports;
	}

	/**
	 * Zeilen der Liste: zuerst die Teilnehmenden, dann die Warteliste, je in der Reihenfolge der
	 * Anmeldung und je neu nummeriert. Der Zeitpunkt ist Ortszeit der Website.
	 *
	 * @param array<string,string> $args Filter der Anfrage; «event» ist die ID des Anlasses.
	 * @return list<list<string|int>>
	 */
	public static function rows( array $args, EventRepository $repo, RegistrationService $service, \DateTimeZone $timezone ): array {
		$raw   = (string) ( $args['event'] ?? '' );
		$event = 1 === preg_match( '/^[1-9][0-9]{0,17}$/D', $raw ) ? $repo->find( (int) $raw ) : null;
		if ( null === $event ) {
			return array();
		}
		$rows    = array();
		$numbers = array();
		foreach ( $repo->registrations( $event['id'], $service->season_for( $event ) ) as $row ) {
			$numbers[ $row['status'] ] = ( $numbers[ $row['status'] ] ?? 0 ) + 1;
			$rows[]                    = array(
				$event['title'],
				substr( $event['starts_at'], 0, 16 ),
				$numbers[ $row['status'] ],
				Labels::registration( $row['status'] ),
				$row['last_name'],
				$row['first_name'],
				(string) $row['club'],
				substr( AdminPage::local( $row['created_at'], $timezone ), 0, 16 ),
				(string) $row['note'],
			);
		}
		return $rows;
	}
}
