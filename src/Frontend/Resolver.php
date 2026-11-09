<?php
/**
 * Löst eine Anfrage (Seite plus Parameter) in einen Kontext auf: Objekte, Titel, 404, Weiterleitung.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Frontend;

use TTLeague\Frontend\Support\Urls;
use TTLeague\ReadModel\ReadModel;

/**
 * Ohne WordPress-Abhängigkeit ausser den Übersetzungsfunktionen, damit die Regeln
 * (unbekannte IDs → 404, falscher Namensslug → 301) testbar bleiben.
 */
final class Resolver {

	public function __construct( private readonly ReadModel $model, private readonly Urls $urls ) {}

	/**
	 * @param string                    $view  Seitenschlüssel.
	 * @param array<string,string|null> $route Parameter aus der Adresse (season, group, fixture, team, club, license, name, category).
	 * @param array<string,string|null> $query Abfrageparameter (saison, club, q, kategorie).
	 */
	public function resolve( string $view, array $route, array $query = array() ): Context {
		$route = array_map( static fn( $v ): string => null === $v ? '' : trim( (string) $v ), $route );
		$query = array_map( static fn( $v ): string => null === $v ? '' : trim( (string) $v ), $query );

		switch ( $view ) {
			case 'championship':
				return $this->listing( $view, $route['season'] ?? '', __( 'Meisterschaft', 'ttleague' ), fn( string $slug ): string => $this->urls->championship( $slug ) );
			case 'ranking':
				return $this->listing( $view, $route['season'] ?? '', __( 'Ranglistenturnier', 'ttleague' ), fn( string $slug ): string => $this->urls->ranking( $slug ) );
			case 'clubs':
				return $this->listing( $view, $query['saison'] ?? '', __( 'Clubs', 'ttleague' ), fn( string $slug ): string => $this->urls->clubs( $slug ) );
			case 'players':
				return $this->players( $route, $query );
			case 'group':
				return $this->group( $route );
			case 'fixture':
				return $this->fixture( $route );
			case 'team':
				return $this->team( $route );
			case 'club':
				return $this->club( $route, $query );
			case 'player':
				return $this->player( $route, $query );
			case 'cup':
				return $this->cup( $route );
		}
		return new Context( $view );
	}

	/**
	 * Saison aus einem Slug; leer heisst aktive Saison.
	 *
	 * @return array{0:?array<string,mixed>,1:?string} Saison und Fehlermeldung.
	 */
	private function season( string $slug ): array {
		if ( '' === $slug ) {
			return array( $this->model->seasons->active(), null );
		}
		$season = Urls::is_season_slug( $slug ) ? $this->model->seasons->by_slug( $slug ) : null;
		if ( null === $season ) {
			/* translators: %s: Saison, z. B. 2019-20. */
			return array( null, sprintf( __( 'Die Saison «%s» gibt es in dieser Datenbank nicht.', 'ttleague' ), $slug ) );
		}
		return array( $season, null );
	}

	/**
	 * @param callable(string):string $url Adresse der Ansicht für einen Saison-Slug.
	 */
	private function listing( string $view, string $slug, string $label, callable $url ): Context {
		[ $season, $error ] = $this->season( $slug );
		if ( null !== $error ) {
			return new Context( $view, null, array( 'season_slug' => $slug ), array(), $error );
		}
		$title = null === $season ? $label : $label . ' ' . $season['name'];
		return new Context(
			$view,
			$season,
			array( 'season_slug' => $slug ),
			array(),
			null,
			null,
			302,
			$title,
			$title,
			null === $season ? null : $url( $season['slug'] )
		);
	}

	/**
	 * @param array<string,string> $route Adresse.
	 * @param array<string,string> $query Abfrage.
	 */
	private function players( array $route, array $query ): Context {
		$base   = $this->listing( 'players', $query['saison'] ?? '', __( 'Spieler', 'ttleague' ), fn( string $slug ): string => $this->urls->players( $slug ) );
		$club   = '' === ( $query['club'] ?? '' ) ? null : $this->model->clubs->by_slug( $query['club'] );
		$params = $base->params + array(
			'club_filter' => null === $club ? '' : $club['slug'],
			'search'      => mb_substr( $query['q'] ?? '', 0, 60 ),
		);
		unset( $route );
		return new Context( 'players', $base->season, $params, array( 'club' => $club ), $base->not_found, null, 302, $base->title, $base->doc_title, $base->canonical );
	}

	/**
	 * @param array<string,string> $route Adresse.
	 */
	private function group( array $route ): Context {
		$slug  = $route['season'] ?? '';
		$group = Urls::group_from_segment( $route['group'] ?? '' );
		if ( '' === $slug || null === $group ) {
			return new Context( 'group', null, array(), array(), null, $this->urls->championship() );
		}
		[ $season, $error ] = $this->season( $slug );
		if ( null !== $error ) {
			return new Context( 'group', null, array( 'season_slug' => $slug ), array(), $error );
		}
		$found = $this->model->championship->group_by_slug( $season['id'], $group );
		if ( null === $found ) {
			return new Context(
				'group',
				$season,
				array( 'season_slug' => $slug ),
				array(),
				/* translators: 1: Gruppe, z. B. gruppe-e, 2: Saison, z. B. 2026/27. */
				sprintf( __( 'Die Gruppe «%1$s» gibt es in der Saison %2$s nicht.', 'ttleague' ), 'gruppe-' . $group, $season['name'] )
			);
		}
		return new Context(
			'group',
			$season,
			array(
				'season_slug' => $slug,
				'group'       => $group,
			),
			array( 'group' => $found ),
			null,
			null,
			302,
			$found['name'],
			/* translators: 1: Gruppe, 2: Saison. */
			sprintf( __( '%1$s – Meisterschaft %2$s', 'ttleague' ), $found['name'], $season['name'] ),
			$this->urls->group( $season['slug'], $found['slug'] )
		);
	}

	/**
	 * @param array<string,string> $route Adresse.
	 */
	private function fixture( array $route ): Context {
		$id = (int) ( $route['fixture'] ?? 0 );
		if ( $id <= 0 ) {
			return new Context( 'fixture', null, array(), array(), null, $this->urls->championship() );
		}
		$report = $this->model->reports->get( $id );
		if ( null === $report ) {
			/* translators: %d: Nummer der Begegnung. */
			return new Context( 'fixture', null, array( 'fixture' => (string) $id ), array(), sprintf( __( 'Die Begegnung Nr. %d gibt es nicht.', 'ttleague' ), $id ) );
		}
		$season = $this->model->seasons->by_id( $report['season']['id'] );
		$title  = $report['home']['name'] . ' – ' . $report['away']['name'];
		$kind   = 'CUP' === $report['kind'] ? __( 'Cupbegegnung', 'ttleague' ) : __( 'Begegnung', 'ttleague' );
		return new Context(
			'fixture',
			$season,
			array( 'fixture' => (string) $id ),
			array( 'report' => $report ),
			null,
			null,
			302,
			$title,
			$title . ' – ' . $kind . ' ' . $report['season']['name'],
			$this->urls->fixture( $id )
		);
	}

	/**
	 * @param array<string,string> $route Adresse.
	 */
	private function team( array $route ): Context {
		$slug = $route['season'] ?? '';
		$team = $route['team'] ?? '';
		if ( '' === $slug || '' === $team ) {
			return new Context( 'team', null, array(), array(), null, $this->urls->championship() );
		}
		[ $season, $error ] = $this->season( $slug );
		if ( null !== $error ) {
			return new Context( 'team', null, array( 'season_slug' => $slug ), array(), $error );
		}
		$found = $this->model->championship->team_by_slug( $season['id'], $team );
		if ( null === $found ) {
			/* translators: 1: Team-Kürzel aus der Adresse, 2: Saison. */
			return new Context( 'team', $season, array( 'season_slug' => $slug ), array(), sprintf( __( 'Die Mannschaft «%1$s» gibt es in der Saison %2$s nicht.', 'ttleague' ), $team, $season['name'] ) );
		}
		return new Context(
			'team',
			$season,
			array(
				'season_slug' => $slug,
				'team'        => $found['slug'],
			),
			array( 'team' => $found ),
			null,
			null,
			302,
			$found['name'],
			/* translators: 1: Mannschaft, 2: Saison. */
			sprintf( __( '%1$s – Mannschaft %2$s', 'ttleague' ), $found['name'], $season['name'] ),
			$this->urls->team( $season['slug'], $found['slug'] )
		);
	}

	/**
	 * @param array<string,string> $route Adresse.
	 * @param array<string,string> $query Abfrage.
	 */
	private function club( array $route, array $query ): Context {
		$slug = $route['club'] ?? '';
		if ( '' === $slug || basename( $this->urls->path( 'club' ) ) === $slug ) {
			return new Context( 'club', null, array(), array(), null, $this->urls->clubs() );
		}
		$club = $this->model->clubs->by_slug( $slug );
		if ( null === $club ) {
			/* translators: %s: Club-Kürzel aus der Adresse. */
			return new Context( 'club', null, array( 'club' => $slug ), array(), sprintf( __( 'Den Club «%s» gibt es nicht.', 'ttleague' ), $slug ) );
		}
		[ $season, $error ] = $this->season( $query['saison'] ?? '' );
		if ( null !== $error ) {
			return new Context( 'club', null, array( 'club' => $slug ), array( 'club' => $club ), $error );
		}
		$explicit = '' !== ( $query['saison'] ?? '' ) && null !== $season;
		return new Context(
			'club',
			$season,
			array(
				'club'        => $slug,
				'season_slug' => $query['saison'] ?? '',
			),
			array( 'club' => $club ),
			null,
			null,
			302,
			$club['name'],
			/* translators: %s: Clubname. */
			sprintf( __( '%s – Club', 'ttleague' ), $club['name'] ),
			$this->urls->club( $club['slug'], $explicit ? $season['slug'] : null )
		);
	}

	/**
	 * @param array<string,string> $route Adresse.
	 * @param array<string,string> $query Abfrage.
	 */
	private function player( array $route, array $query ): Context {
		$license = (int) ( $route['license'] ?? 0 );
		if ( $license <= 0 ) {
			return new Context( 'player', null, array(), array(), null, $this->urls->players() );
		}
		$player = $this->model->players->by_license( $license );
		if ( null === $player ) {
			/* translators: %d: Lizenznummer. */
			return new Context( 'player', null, array( 'license' => (string) $license ), array(), sprintf( __( 'Einen Spieler mit der Lizenznummer %d gibt es nicht.', 'ttleague' ), $license ) );
		}
		$filter = $query['saison'] ?? '';
		$season = Urls::is_season_slug( $filter ) ? $this->model->seasons->by_slug( $filter ) : null;
		if ( ( $route['name'] ?? '' ) !== $player['slug'] ) {
			return new Context( 'player', null, array(), array(), null, $this->urls->player( $license, $player['slug'], null === $season ? null : $season['slug'] ), 301 );
		}
		return new Context(
			'player',
			$season,
			array(
				'license'     => (string) $license,
				'season_slug' => null === $season ? '' : $season['slug'],
			),
			array( 'player' => $player ),
			null,
			null,
			302,
			$player['name'],
			/* translators: %s: Name des Spielers. */
			sprintf( __( '%s – Spieler', 'ttleague' ), $player['name'] ),
			$this->urls->player( $license, $player['slug'] )
		);
	}

	/**
	 * @param array<string,string> $route Adresse.
	 */
	private function cup( array $route ): Context {
		$base = $this->listing( 'cup', $route['season'] ?? '', __( 'Mannschaftscup', 'ttleague' ), fn( string $slug ): string => $this->urls->cup( $slug ) );
		if ( null !== $base->not_found || null === $base->season ) {
			return $base;
		}
		$category = strtolower( $route['category'] ?? '' );
		if ( '' === $category ) {
			return $base;
		}
		foreach ( $this->model->cup->season( $base->season['id'] ) as $competition ) {
			if ( $competition['category'] === $category ) {
				$title = $competition['name'] . ' ' . $base->season['name'];
				return new Context(
					'cup',
					$base->season,
					$base->params + array( 'category' => $category ),
					array( 'cup' => $competition ),
					null,
					null,
					302,
					$title,
					$title,
					$this->urls->cup( $base->season['slug'], $category )
				);
			}
		}
		/* translators: 1: Kategorie, 2: Saison. */
		return new Context( 'cup', $base->season, $base->params, array(), sprintf( __( 'Eine Cup-Kategorie «%1$s» gibt es in der Saison %2$s nicht.', 'ttleague' ), strtoupper( $category ), $base->season['name'] ) );
	}
}
