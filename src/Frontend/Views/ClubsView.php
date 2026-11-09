<?php
/**
 * Clubliste einer Saison: Name, Teams, Spielerzahl.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Frontend\Views;

use TTLeague\Frontend\Support\Html;

final class ClubsView extends View {

	protected const VIEW = 'clubs';

	public function render( array $attrs ): string {
		$not_found = $this->not_found();
		if ( null !== $not_found ) {
			return $not_found;
		}
		$season = $this->season( $attrs );
		if ( null === $season ) {
			return $this->no_season();
		}
		$list = $this->model->clubs->season_list( $season['id'] );
		if ( array() === $list ) {
			return Html::notice( __( 'Für diese Saison sind keine Clubs erfasst.', 'ttleague' ) );
		}
		$rows = array();
		foreach ( $list as $entry ) {
			$teams = array();
			foreach ( $entry['teams'] as $team ) {
				$teams[] = Html::link( $this->urls->team( $season['slug'], $team['slug'] ), $team['name'] );
			}
			$rows[] = array(
				'cells' => array(
					Html::link( $this->urls->club( $entry['club']['slug'], $season['slug'] ), $entry['club']['name'] ),
					array() === $teams ? '<span class="ttl-muted">–</span>' : implode( ', ', $teams ),
					(string) $entry['players'],
				),
			);
		}
		$columns = array(
			array(
				'label'      => __( 'Club', 'ttleague' ),
				'primary'    => true,
				'hide_label' => true,
			),
			array( 'label' => __( 'Teams', 'ttleague' ) ),
			array(
				'label' => __( 'Spieler', 'ttleague' ),
				'class' => 'ttl-num',
			),
		);
		/* translators: %s: Saison. */
		$caption = sprintf( __( 'Clubs der Saison %s', 'ttleague' ), $season['name'] );
		return Html::table(
			$columns,
			$rows,
			array(
				'caption' => $caption,
				'class'   => 'ttl-clubs',
			)
		);
	}
}
