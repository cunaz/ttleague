<?php
/**
 * Adressen der öffentlichen Seiten.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Frontend\Support;

/**
 * Baut Adressen aus der Startadresse und den Pfaden der Seiten (Seitenschlüssel => Pfad
 * ohne Schrägstriche, z. B. 'championship' => 'meisterschaft'). Reine Klasse ohne WordPress.
 *
 * Schema: /meisterschaft/2026-27/gruppe-a/, /begegnung/123/, /mannschaft/2026-27/seefeld-1/,
 * /clubs/seefeld/?saison=2026-27, /spieler/90001-hans-muster/, /ranglistenturnier/2026-27/,
 * /cup/2026-27/a/, /archiv/.
 */
final class Urls {

	public const DEFAULT_PATHS = array(
		'championship' => 'meisterschaft',
		'group'        => 'meisterschaft/gruppe',
		'fixture'      => 'begegnung',
		'team'         => 'mannschaft',
		'clubs'        => 'clubs',
		'club'         => 'clubs/club',
		'players'      => 'spieler',
		'player'       => 'spieler/profil',
		'ranking'      => 'ranglistenturnier',
		'cup'          => 'cup',
		'documents'    => 'dokumente',
		'archive'      => 'archiv',
		'login'        => 'anmelden',
		'my_area'      => 'mein-bereich',
	);

	private readonly string $home;

	/**
	 * @var array<string,string>
	 */
	private readonly array $paths;

	/**
	 * @param string               $home  Startadresse, z. B. https://example.org/.
	 * @param array<string,string> $paths Seitenschlüssel => Pfad.
	 */
	public function __construct( string $home, array $paths = array() ) {
		$this->home  = rtrim( $home, '/' ) . '/';
		$this->paths = array_map( static fn( string $p ): string => trim( $p, '/' ), $paths + self::DEFAULT_PATHS );
	}

	/**
	 * Pfad einer Seite ohne Schrägstriche.
	 */
	public function path( string $key ): string {
		return $this->paths[ $key ] ?? $key;
	}

	public function home(): string {
		return $this->home;
	}

	/**
	 * Adresse einer Seite ohne Parameter.
	 */
	public function page( string $key ): string {
		return $this->build( array( $this->path( $key ) ) );
	}

	public function championship( ?string $season = null ): string {
		return $this->build( array( $this->path( 'championship' ), $season ) );
	}

	/**
	 * @param string $season Saison-Slug.
	 * @param string $group  Gruppenslug («a», «a-2»).
	 */
	public function group( string $season, string $group ): string {
		return $this->build( array( $this->path( 'championship' ), $season, 'gruppe-' . $group ) );
	}

	public function fixture( int $id ): string {
		return $this->build( array( $this->path( 'fixture' ), (string) $id ) );
	}

	/**
	 * @param string $season Saison-Slug.
	 * @param string $team   Teamslug («seefeld-1»).
	 */
	public function team( string $season, string $team ): string {
		return $this->build( array( $this->path( 'team' ), $season, $team ) );
	}

	public function clubs( ?string $season = null ): string {
		return $this->build( array( $this->path( 'clubs' ) ), array( 'saison' => $season ) );
	}

	public function club( string $slug, ?string $season = null ): string {
		return $this->build( array( $this->path( 'clubs' ), $slug ), array( 'saison' => $season ) );
	}

	public function players( ?string $season = null, ?string $club = null, ?string $search = null ): string {
		return $this->build(
			array( $this->path( 'players' ) ),
			array(
				'saison' => $season,
				'club'   => $club,
				'q'      => $search,
			)
		);
	}

	/**
	 * @param int         $license Lizenznummer.
	 * @param string      $slug    Namensslug («hans-muster»).
	 * @param string|null $season  Saisonfilter.
	 */
	public function player( int $license, string $slug, ?string $season = null ): string {
		$segment = '' === $slug ? (string) $license : $license . '-' . $slug;
		return $this->build( array( $this->path( 'players' ), $segment ), array( 'saison' => $season ) );
	}

	public function ranking( ?string $season = null ): string {
		return $this->build( array( $this->path( 'ranking' ), $season ) );
	}

	public function cup( ?string $season = null, ?string $category = null ): string {
		$category = null === $category || '' === $category ? null : strtolower( $category );
		return $this->build( array( $this->path( 'cup' ), $season, null === $season ? null : $category ) );
	}

	public function archive(): string {
		return $this->page( 'archive' );
	}

	/**
	 * Gruppenslug aus der Adresse («gruppe-a-2» → «a-2»), null wenn ungültig.
	 */
	public static function group_from_segment( string $segment ): ?string {
		return preg_match( '/^(?:gruppe-)?([a-z0-9]+(?:-\d+)?)$/', strtolower( $segment ), $m ) ? $m[1] : null;
	}

	/**
	 * Gültiger Saison-Slug wie «2026-27»?
	 */
	public static function is_season_slug( string $slug ): bool {
		return 1 === preg_match( '/^\d{4}-\d{2}$/', $slug );
	}

	/**
	 * @param list<string|null>         $segments Pfadteile, null wird ausgelassen.
	 * @param array<string,string|null> $query    Parameter, null und leer werden ausgelassen.
	 */
	private function build( array $segments, array $query = array() ): string {
		$parts = array();
		foreach ( $segments as $segment ) {
			if ( null === $segment || '' === $segment ) {
				continue;
			}
			foreach ( explode( '/', $segment ) as $piece ) {
				if ( '' !== $piece ) {
					$parts[] = rawurlencode( $piece );
				}
			}
		}
		$url   = $this->home . ( array() === $parts ? '' : implode( '/', $parts ) . '/' );
		$query = array_filter( $query, static fn( ?string $v ): bool => null !== $v && '' !== $v );
		if ( array() !== $query ) {
			$url .= '?' . http_build_query( $query, '', '&', PHP_QUERY_RFC3986 );
		}
		return $url;
	}
}
