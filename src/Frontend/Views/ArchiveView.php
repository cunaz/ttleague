<?php
/**
 * Archiv: alle Saisons mit Einstiegen und Gruppensiegern.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Frontend\Views;

use TTLeague\Frontend\Support\Format;
use TTLeague\Frontend\Support\Html;

final class ArchiveView extends View {

	public function render( array $attrs ): string {
		$seasons = $this->model->seasons->all();
		if ( array() === $seasons ) {
			return $this->no_season();
		}
		if ( ! empty( $attrs['compact'] ) ) {
			$items = array();
			foreach ( $seasons as $season ) {
				$items[] = '<li>' . Html::link( $this->urls->championship( $season['slug'] ), __( 'Saison', 'ttleague' ) . ' ' . $season['name'] ) . '</li>';
			}
			return '<ul class="ttl-inline-list ttl-archive-list">' . implode( '', $items ) . '<li>' . Html::link( $this->urls->archive(), __( 'Übersicht aller Saisons', 'ttleague' ) ) . '</li></ul>';
		}
		$out = '<div class="ttl-grid ttl-grid--cards ttl-archive">';
		foreach ( $seasons as $season ) {
			$data    = $this->model->championship->season( $season['id'] );
			$leaders = array();
			foreach ( $data['groups'] as $group ) {
				$first = $group['standings'][0] ?? null;
				if ( null === $first || 0 === $group['played'] ) {
					continue;
				}
				$team      = $data['teams'][ $first['team_id'] ] ?? null;
				$leaders[] = '<div class="ttl-facts__item"><dt>' . Html::link( $this->urls->group( $season['slug'], $group['slug'] ), $group['name'] ) . '</dt><dd>' . ( null === $team ? '' : Html::link( $this->urls->team( $season['slug'], $team['slug'] ), $team['name'] ) ) . '</dd></div>';
			}
			$status       = 'active' === $season['status'] ? Html::badge( __( 'laufend', 'ttleague' ), 'win' ) : '';
			$links        = array(
				Html::link( $this->urls->championship( $season['slug'] ), __( 'Meisterschaft', 'ttleague' ) ),
				Html::link( $this->urls->cup( $season['slug'] ), __( 'Cup', 'ttleague' ) ),
				Html::link( $this->urls->ranking( $season['slug'] ), __( 'Ranglistenturnier', 'ttleague' ) ),
				Html::link( $this->urls->clubs( $season['slug'] ), __( 'Clubs', 'ttleague' ) ),
				Html::link( $this->urls->players( $season['slug'] ), __( 'Spieler', 'ttleague' ) ),
			);
			$leader_title = 'active' === $season['status'] ? __( 'Tabellenführer', 'ttleague' ) : __( 'Gruppensieger', 'ttleague' );
			$out         .= '<article class="ttl-card"><h2 class="ttl-card__title">' . Html::link( $this->urls->championship( $season['slug'] ), __( 'Saison', 'ttleague' ) . ' ' . $season['name'] ) . ' ' . $status . '</h2>'
				. '<p class="ttl-card__meta">' . esc_html( Format::date_plain( $season['start_date'] ) . ' – ' . Format::date_plain( $season['end_date'] ) ) . '</p>'
				. '<ul class="ttl-inline-list"><li>' . implode( '</li><li>', $links ) . '</li></ul>'
				. ( array() === $leaders ? '' : '<p class="ttl-card__label">' . esc_html( $leader_title ) . '</p><dl class="ttl-facts ttl-facts--compact">' . implode( '', $leaders ) . '</dl>' )
				. '</article>';
		}
		return $out . '</div>';
	}
}
