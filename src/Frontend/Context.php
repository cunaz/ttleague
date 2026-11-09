<?php
/**
 * Aufgelöste Anfrage einer Seite der öffentlichen Darstellung.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Frontend;

/**
 * view: Seitenschlüssel (championship, group, fixture, team, clubs, club, players, player,
 * ranking, cup, archive). season: aufgelöste Saison. params: Parameter aus Adresse und
 * Abfrage (season_slug, group, fixture, team, club, license, name, category, club_filter, search).
 * objects: geladene Objekte (group, report, team, club, player). Bei not_found antwortet
 * die Seite mit 404 und zeigt die Meldung; bei redirect wird weitergeleitet.
 */
final class Context {

	/**
	 * @param string                    $view            Seitenschlüssel.
	 * @param array<string,mixed>|null  $season          Saison.
	 * @param array<string,mixed>       $params          Parameter.
	 * @param array<string,mixed>       $objects         Geladene Objekte.
	 * @param string|null               $not_found       Meldung für 404.
	 * @param string|null               $redirect        Ziel einer Weiterleitung.
	 * @param int                       $redirect_status 301 oder 302.
	 * @param string|null               $title           Seitenüberschrift.
	 * @param string|null               $doc_title       Dokumenttitel (ohne Seitenname).
	 * @param string|null               $canonical       Kanonische Adresse.
	 */
	public function __construct(
		public readonly string $view,
		public readonly ?array $season = null,
		public readonly array $params = array(),
		public readonly array $objects = array(),
		public readonly ?string $not_found = null,
		public readonly ?string $redirect = null,
		public readonly int $redirect_status = 302,
		public readonly ?string $title = null,
		public readonly ?string $doc_title = null,
		public readonly ?string $canonical = null,
	) {}

	public function param( string $name ): ?string {
		$value = $this->params[ $name ] ?? null;
		return null === $value || '' === $value ? null : (string) $value;
	}

	/**
	 * @return array<string,mixed>|null
	 */
	public function object( string $name ): ?array {
		$value = $this->objects[ $name ] ?? null;
		return is_array( $value ) ? $value : null;
	}

	public function is_not_found(): bool {
		return null !== $this->not_found;
	}
}
