<?php
/**
 * Adressen der Kalender-Abos.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Calendar;

/**
 * Schema: /kalender/team/2026-27/seefeld-1.ics, /kalender/club/seefeld.ics,
 * /kalender/spieler/90001.ics. Reine Klasse ohne WordPress.
 */
final class FeedUrls {

	public const BASE = 'kalender';

	public const TYPES = array(
		'team'   => 'team',
		'club'   => 'club',
		'player' => 'spieler',
	);

	private readonly string $home;

	/**
	 * @param string $home Startadresse, z. B. https://example.org/.
	 */
	public function __construct( string $home ) {
		$this->home = rtrim( $home, '/' ) . '/';
	}

	public function team( string $season, string $team ): string {
		return $this->build( 'team', $season . '/' . $team );
	}

	public function club( string $club ): string {
		return $this->build( 'club', $club );
	}

	public function player( int $license ): string {
		return $this->build( 'player', (string) $license );
	}

	/**
	 * Gleiche Adresse mit webcal:// statt http(s):// für das Abonnieren per Klick.
	 */
	public static function webcal( string $url ): string {
		return (string) preg_replace( '#^https?://#i', 'webcal://', $url );
	}

	/**
	 * Rewrite-Regeln als Regex => Query (relativ zur Startadresse); reine Funktion.
	 * Die letzte Regel fängt alle übrigen .ics-Adressen unter /kalender/ ab, damit sie
	 * eine klare 404-Antwort erhalten statt einer Weiterleitung von WordPress.
	 *
	 * @param string $query_var Query-Variable für den Typ.
	 * @param string $season    Query-Variable für die Saison.
	 * @param string $key       Query-Variable für Team, Club oder Lizenz.
	 * @return array<string,string>
	 */
	public static function rules( string $query_var, string $season, string $key ): array {
		$base = preg_quote( self::BASE, '#' );
		return array(
			'^' . $base . '/' . self::TYPES['team'] . '/(\d{4}-\d{2})/([A-Za-z0-9-]+)\.ics$' => 'index.php?' . $query_var . '=team&' . $season . '=$matches[1]&' . $key . '=$matches[2]',
			'^' . $base . '/' . self::TYPES['club'] . '/([A-Za-z0-9-]+)\.ics$' => 'index.php?' . $query_var . '=club&' . $key . '=$matches[1]',
			'^' . $base . '/' . self::TYPES['player'] . '/([0-9]+)\.ics$' => 'index.php?' . $query_var . '=player&' . $key . '=$matches[1]',
			'^' . $base . '/.+\.ics$' => 'index.php?' . $query_var . '=unknown',
		);
	}

	private function build( string $type, string $path ): string {
		$parts = array_map( 'rawurlencode', explode( '/', $path ) );
		return $this->home . self::BASE . '/' . self::TYPES[ $type ] . '/' . implode( '/', $parts ) . '.ics';
	}
}
