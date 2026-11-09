<?php
/**
 * Gruppe der Meisterschaft: volle Tabelle und Spielplan/Resultate.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Frontend\Views;

use TTLeague\Frontend\Support\Html;

final class GroupView extends View {

	protected const VIEW = 'group';

	public function render( array $attrs ): string {
		$not_found = $this->not_found();
		if ( null !== $not_found ) {
			return $not_found;
		}
		$season = $this->season( $attrs );
		if ( null === $season ) {
			return $this->no_season();
		}
		$slug    = trim( strtolower( (string) ( $attrs['group'] ?? '' ) ) );
		$context = $this->own_context();
		if ( '' === $slug && null !== $context ) {
			$slug = (string) $context->param( 'group' );
		}
		$data   = $this->model->championship->season( $season['id'] );
		$group  = null;
		$groups = $data['groups'];
		foreach ( $groups as $candidate ) {
			if ( $candidate['slug'] === $slug || ( '' === $slug && null === $group ) ) {
				$group = $candidate;
			}
		}
		if ( null === $group ) {
			return Html::notice( __( 'Diese Gruppe gibt es in der gewählten Saison nicht.', 'ttleague' ) );
		}

		$show = (string) ( $attrs['show'] ?? 'all' );
		/* translators: %s: Saison. */
		$out = $this->kicker( $this->urls->championship( $season['slug'] ), sprintf( __( 'Meisterschaft %s', 'ttleague' ), $season['name'] ) );
		if ( count( $groups ) > 1 ) {
			$out .= $this->group_nav( $groups, $group, $season['slug'] );
		}
		if ( 'schedule' !== $show ) {
			$out .= Html::section( __( 'Tabelle', 'ttleague' ), ( new Standings( $this->urls ) )->full( $group, $data['teams'], $season['slug'] ), 'ttl-section--standings', 'ttl-tabelle' );
		}
		if ( 'table' !== $show ) {
			$fixtures = $this->model->championship->group_fixtures( $season['id'], $group['id'] );
			/* translators: %s: Gruppe. */
			$caption = sprintf( __( 'Spielplan und Resultate %s', 'ttleague' ), $group['name'] );
			$out    .= Html::section( __( 'Spielplan und Resultate', 'ttleague' ), ( new Schedule( $this->urls ) )->render( $fixtures, $data['teams'], $season['slug'], null, $caption ), 'ttl-section--schedule', 'ttl-spielplan' );
		}
		return $out;
	}

	/**
	 * Navigation zwischen den Gruppen der Saison.
	 *
	 * @param list<array<string,mixed>> $groups  Gruppen.
	 * @param array<string,mixed>       $current Aktuelle Gruppe.
	 */
	private function group_nav( array $groups, array $current, string $season ): string {
		$items = array();
		foreach ( $groups as $group ) {
			$attrs   = $group['id'] === $current['id'] ? array( 'aria-current' => 'page' ) : array();
			$items[] = '<li>' . Html::link( $this->urls->group( $season, $group['slug'] ), $group['name'], $attrs ) . '</li>';
		}
		return '<nav class="ttl-tabs" aria-label="' . esc_attr__( 'Gruppen', 'ttleague' ) . '"><ul>' . implode( '', $items ) . '</ul></nav>';
	}
}
