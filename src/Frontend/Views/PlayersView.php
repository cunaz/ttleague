<?php
/**
 * Spielerliste einer Saison mit Filter nach Club und Namenssuche.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Frontend\Views;

use TTLeague\Frontend\Support\Format;
use TTLeague\Frontend\Support\Html;
use TTLeague\ReadModel\Players;

final class PlayersView extends View {

	protected const VIEW = 'players';

	public function render( array $attrs ): string {
		$not_found = $this->not_found();
		if ( null !== $not_found ) {
			return $not_found;
		}
		$season = $this->season( $attrs );
		if ( null === $season ) {
			return $this->no_season();
		}
		$context   = $this->own_context();
		$club_slug = trim( (string) ( $attrs['club'] ?? '' ) );
		$search    = '';
		$filtering = null !== $context;
		if ( null !== $context ) {
			$club_slug = '' === $club_slug ? (string) $context->param( 'club_filter' ) : $club_slug;
			$search    = (string) $context->param( 'search' );
		}
		$club    = '' === $club_slug ? null : $this->model->clubs->by_slug( $club_slug );
		$all     = $this->model->players->season_list( $season['id'] );
		$players = Players::filter( $all, null === $club ? null : $club['id'], $search );

		$out = $filtering ? $this->form( $season, $club_slug, $search, $all ) : '';
		/* translators: 1: Anzahl Spieler, 2: Saison. */
		$out .= '<p class="ttl-lead" role="status">' . esc_html( sprintf( _n( '%1$d Spieler in der Saison %2$s', '%1$d Spieler in der Saison %2$s', count( $players ), 'ttleague' ), count( $players ), $season['name'] ) ) . '</p>';
		if ( array() === $players ) {
			return $out . Html::notice( __( 'Keine Spieler gefunden.', 'ttleague' ) );
		}
		$rows = array();
		foreach ( $players as $row ) {
			$rows[] = array(
				'cells' => array(
					$this->player_link( $row['player'] ),
					Html::link( $this->urls->club( $row['club_slug'], $season['slug'] ), $row['club_short'] ),
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
			array( 'label' => __( 'Club', 'ttleague' ) ),
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
		return $out . Html::table(
			$columns,
			$rows,
			array(
				/* translators: %s: Saison. */
				'caption' => sprintf( __( 'Spieler der Saison %s', 'ttleague' ), $season['name'] ),
				'class'   => 'ttl-players',
			)
		);
	}

	/**
	 * Filterformular (GET, ohne JavaScript).
	 *
	 * @param array<string,mixed>       $season Saison.
	 * @param list<array<string,mixed>> $all    Alle Spieler der Saison.
	 */
	private function form( array $season, string $club_slug, string $search, array $all ): string {
		$clubs = array();
		foreach ( $all as $row ) {
			$clubs[ $row['club_slug'] ] = $row['club_short'];
		}
		asort( $clubs );
		$options = '<option value="">' . esc_html__( 'Alle Clubs', 'ttleague' ) . '</option>';
		foreach ( $clubs as $slug => $name ) {
			$options .= '<option value="' . esc_attr( (string) $slug ) . '"' . selected( $club_slug, (string) $slug, false ) . '>' . esc_html( $name ) . '</option>';
		}
		$active = $this->model->seasons->active();
		$hidden = null !== $active && $active['slug'] !== $season['slug'] ? '<input type="hidden" name="saison" value="' . esc_attr( $season['slug'] ) . '">' : '';
		return '<form class="ttl-filter" method="get" action="' . esc_url( $this->urls->players() ) . '" role="search">'
			. $hidden
			. '<div class="ttl-filter__field"><label for="ttl-filter-q">' . esc_html__( 'Name oder Lizenznummer', 'ttleague' ) . '</label>'
			. '<input type="search" id="ttl-filter-q" name="q" value="' . esc_attr( $search ) . '" autocomplete="off"></div>'
			. '<div class="ttl-filter__field"><label for="ttl-filter-club">' . esc_html__( 'Club', 'ttleague' ) . '</label>'
			. '<select id="ttl-filter-club" name="club">' . $options . '</select></div>'
			. '<div class="ttl-filter__actions"><button type="submit" class="ttl-button">' . esc_html__( 'Filtern', 'ttleague' ) . '</button>'
			. ( '' === $search && '' === $club_slug ? '' : ' ' . Html::link( $this->urls->players( null === $active || $active['slug'] === $season['slug'] ? null : $season['slug'] ), __( 'Zurücksetzen', 'ttleague' ), array( 'class' => 'ttl-button ttl-button--secondary' ) ) )
			. '</div></form>';
	}
}
