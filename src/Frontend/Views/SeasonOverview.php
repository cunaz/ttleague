<?php
/**
 * Saisonübersicht der Meisterschaft: alle Gruppen mit kompakter Tabelle.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Frontend\Views;

use TTLeague\Frontend\Support\Html;

final class SeasonOverview extends View {

	protected const VIEW = 'championship';

	public function render( array $attrs ): string {
		$not_found = $this->not_found();
		if ( null !== $not_found ) {
			return $not_found;
		}
		$season = $this->season( $attrs );
		if ( null === $season ) {
			return $this->no_season();
		}
		$data = $this->model->championship->season( $season['id'] );
		if ( array() === $data['groups'] ) {
			return Html::notice( __( 'Für diese Saison sind noch keine Gruppen erfasst.', 'ttleague' ) );
		}

		$played = 0;
		$total  = 0;
		foreach ( $data['groups'] as $group ) {
			$played += $group['played'];
			$total  += $group['total'];
		}
		/* translators: 1: gespielte Begegnungen, 2: alle Begegnungen. */
		$out       = '<p class="ttl-lead">' . esc_html( sprintf( __( '%1$d von %2$d Begegnungen gespielt.', 'ttleague' ), $played, $total ) ) . '</p>';
		$standings = new Standings( $this->urls );
		$out      .= '<div class="ttl-grid ttl-grid--groups">';
		foreach ( $data['groups'] as $group ) {
			$url  = $this->urls->group( $season['slug'], $group['slug'] );
			$out .= '<section class="ttl-card ttl-group-card" aria-labelledby="ttl-group-' . esc_attr( $group['slug'] ) . '">'
				. '<h2 class="ttl-card__title" id="ttl-group-' . esc_attr( $group['slug'] ) . '">' . Html::link( $url, $group['name'] ) . '</h2>'
				. $standings->compact( $group, $data['teams'], $season['slug'] )
				/* translators: %s: Gruppe. */
				. '<p class="ttl-card__more">' . Html::link( $url, sprintf( __( 'Spielplan und Tabelle %s', 'ttleague' ), $group['name'] ) ) . '</p>'
				. '</section>';
		}
		return $out . '</div>';
	}
}
