<?php
/**
 * Club-Detail: Teams der Saison, Lokale, Funktionen und Spieler.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Frontend\Views;

use TTLeague\Frontend\Support\Format;
use TTLeague\Frontend\Support\Html;

final class ClubView extends View {

	protected const VIEW = 'club';

	public function render( array $attrs ): string {
		$not_found = $this->not_found();
		if ( null !== $not_found ) {
			return $not_found;
		}
		$season = $this->season( $attrs );
		if ( null === $season ) {
			return $this->no_season();
		}
		$slug    = trim( (string) ( $attrs['club'] ?? '' ) );
		$context = $this->own_context();
		$club    = '' === $slug ? ( null === $context ? null : $context->object( 'club' ) ) : $this->model->clubs->by_slug( $slug );
		if ( null === $club ) {
			return Html::notice( __( 'Dieser Block zeigt den Club aus der Adresse (z. B. /clubs/seefeld/). Für einen festen Club das Kürzel in den Block-Einstellungen angeben.', 'ttleague' ) );
		}
		$details = $this->model->clubs->details( $club['id'], $season['id'] );
		$data    = $this->model->championship->season( $season['id'] );

		/* translators: %s: Saison. */
		$out = '<p class="ttl-kicker">' . Html::link( $this->urls->clubs( $season['slug'] ), sprintf( __( 'Clubs %s', 'ttleague' ), $season['name'] ) ) . '</p>';

		$teams = array();
		foreach ( $details['teams'] as $team ) {
			$group   = null === $team['competition_id'] ? null : $this->model->championship->group_by_id( $season['id'], $team['competition_id'] );
			$teams[] = '<li>' . Html::link( $this->urls->team( $season['slug'], $team['slug'] ), $team['name'] )
				. ( null === $group ? '' : ' <span class="ttl-muted">·</span> ' . Html::link( $this->urls->group( $season['slug'], $group['slug'] ), $group['name'] ) ) . '</li>';
		}
		/* translators: %s: Saison. */
		$out .= Html::section( sprintf( __( 'Teams %s', 'ttleague' ), $season['name'] ), array() === $teams ? Html::notice( __( 'Keine Teams in dieser Saison.', 'ttleague' ) ) : '<ul class="ttl-list">' . implode( '', $teams ) . '</ul>', 'ttl-section--teams', 'ttl-teams' );

		$out .= Html::section( __( 'Spiellokale', 'ttleague' ), $this->venues( $details['venues'], $season['slug'] ), 'ttl-section--venues', 'ttl-lokale' );
		$out .= Html::section( __( 'Funktionen', 'ttleague' ), $this->roles( $details['roles'], $data['teams'] ), 'ttl-section--roles', 'ttl-funktionen' );
		/* translators: %s: Saison. */
		$out .= Html::section( sprintf( __( 'Spieler %s', 'ttleague' ), $season['name'] ), $this->players( $details['players'], $club['name'] ), 'ttl-section--players', 'ttl-spieler' );
		return $out;
	}

	/**
	 * @param list<array<string,mixed>> $venues Lokale.
	 */
	private function venues( array $venues, string $season ): string {
		if ( array() === $venues ) {
			return Html::notice( __( 'Keine Spiellokale erfasst.', 'ttleague' ) );
		}
		$out = '<div class="ttl-grid ttl-grid--cards">';
		foreach ( $venues as $venue ) {
			$address = Format::address( $venue['street'], $venue['zip'], $venue['city'] );
			$teams   = array_map( fn( array $t ): string => Html::link( $this->urls->team( $season, $t['slug'] ), $t['name'] ), $venue['teams'] );
			$hall    = '';
			if ( null !== $venue['open_time'] ) {
				/* translators: 1: Öffnungszeit, 2: Schliesszeit. */
				$hall = esc_html( null === $venue['close_time'] ? $venue['open_time'] : sprintf( __( '%1$s bis %2$s', 'ttleague' ), $venue['open_time'], $venue['close_time'] ) );
			}
			$out .= '<article class="ttl-card ttl-venue"><h3 class="ttl-card__title">' . esc_html( $venue['name'] ) . '</h3>'
				. Html::facts(
					array(
						__( 'Adresse', 'ttleague' )     => '' === $address ? '' : esc_html( $address ) . '<br>' . Html::link( Format::map_url( $address ), __( 'Auf der Karte zeigen', 'ttleague' ), array( 'rel' => 'nofollow' ) ),
						__( 'Wochentag', 'ttleague' )   => esc_html( Format::weekday( $venue['weekday'] ) ),
						__( 'Anspielzeit', 'ttleague' ) => esc_html( implode( ', ', $venue['start_times'] ) ),
						__( 'Halle offen', 'ttleague' ) => $hall,
						__( 'Tische', 'ttleague' )      => null === $venue['tables'] ? '' : esc_html( (string) $venue['tables'] ),
						__( 'Heimteams', 'ttleague' )   => implode( ', ', $teams ),
					)
				)
				. ( null === $venue['note'] || '' === trim( (string) $venue['note'] ) ? '' : '<p class="ttl-note">' . esc_html( (string) $venue['note'] ) . '</p>' )
				. '</article>';
		}
		return $out . '</div>';
	}

	/**
	 * TK-Chef und Captains, nur Namen.
	 *
	 * @param list<array<string,mixed>>      $roles Funktionen.
	 * @param array<int,array<string,mixed>> $teams Teams der Saison.
	 */
	private function roles( array $roles, array $teams ): string {
		if ( array() === $roles ) {
			return Html::notice( __( 'Keine Funktionen erfasst.', 'ttleague' ) );
		}
		$items = array();
		foreach ( $roles as $role ) {
			$name = null === $role['player'] ? esc_html( $role['name'] ) : $this->player_link( $role['player'] );
			if ( 'tk_chef' === $role['role'] ) {
				$label = __( 'TK-Chef', 'ttleague' );
			} else {
				$team = null === $role['team_id'] ? null : ( $teams[ $role['team_id'] ] ?? null );
				/* translators: %s: Mannschaft. */
				$label = null === $team ? __( 'Captain', 'ttleague' ) : sprintf( __( 'Captain %s', 'ttleague' ), $team['name'] );
			}
			$items[] = '<div class="ttl-facts__item"><dt>' . esc_html( $label ) . '</dt><dd>' . $name . '</dd></div>';
		}
		return '<dl class="ttl-facts ttl-facts--roles">' . implode( '', $items ) . '</dl><p class="ttl-footnote">' . esc_html__( 'Kontaktdaten sehen angemeldete Funktionärinnen und Funktionäre.', 'ttleague' ) . '</p>';
	}

	/**
	 * @param list<array<string,mixed>> $players Spieler der Saison.
	 */
	private function players( array $players, string $club ): string {
		if ( array() === $players ) {
			return Html::notice( __( 'Keine Spieler gemeldet.', 'ttleague' ) );
		}
		$rows = array();
		foreach ( $players as $row ) {
			$rows[] = array(
				'cells' => array(
					$this->player_link( $row['player'] ),
					Html::classification( $row['classification'] ),
					esc_html( Format::rating( $row['rating'] ) ),
				),
			);
		}
		$columns = array(
			array(
				'label'      => __( 'Name', 'ttleague' ),
				'primary'    => true,
				'hide_label' => true,
			),
			array(
				'label' => __( 'Klassierung', 'ttleague' ),
				'abbr'  => __( 'Kl.', 'ttleague' ),
				'class' => 'ttl-num',
			),
			array(
				'label' => __( 'Wertungszahl', 'ttleague' ),
				'class' => 'ttl-num',
			),
		);
		return Html::table(
			$columns,
			$rows,
			array(
				/* translators: %s: Club. */
				'caption' => sprintf( __( 'Spieler %s', 'ttleague' ), $club ),
				'class'   => 'ttl-players',
			)
		);
	}
}
