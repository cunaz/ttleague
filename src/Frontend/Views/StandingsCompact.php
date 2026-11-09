<?php
/**
 * Kurztabellen aller Gruppen (oder einer Gruppe) für die Startseite.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Frontend\Views;

use TTLeague\Frontend\Support\Html;

final class StandingsCompact extends View {

	public function render( array $attrs ): string {
		$season = $this->season( $attrs );
		if ( null === $season ) {
			return $this->no_season();
		}
		$only   = strtolower( trim( (string) ( $attrs['group'] ?? '' ) ) );
		$data   = $this->model->championship->season( $season['id'] );
		$tables = new Standings( $this->urls );
		$out    = '';
		foreach ( $data['groups'] as $group ) {
			if ( '' !== $only && $only !== $group['slug'] ) {
				continue;
			}
			$url  = $this->urls->group( $season['slug'], $group['slug'] );
			$out .= '<section class="ttl-card ttl-group-card ttl-group-card--mini"><h3 class="ttl-card__title">' . Html::link( $url, $group['name'] ) . '</h3>'
				. $tables->compact( $group, $data['teams'], $season['slug'], true ) . '</section>';
		}
		if ( '' === $out ) {
			return Html::notice( __( 'Für diese Saison sind noch keine Gruppen erfasst.', 'ttleague' ) );
		}
		/* translators: %s: Saison. */
		return '<div class="ttl-grid ttl-grid--mini">' . $out . '</div><p class="ttl-card__more">' . Html::link( $this->urls->championship( $season['slug'] ), sprintf( __( 'Alle Tabellen der Saison %s', 'ttleague' ), $season['name'] ) ) . '</p>';
	}
}
