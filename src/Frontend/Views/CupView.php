<?php
/**
 * Mannschaftscup: Meldelisten (Setzung, Team, Spieler, Punkte) und Resultate.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Frontend\Views;

use TTLeague\Frontend\Support\Format;
use TTLeague\Frontend\Support\Html;

final class CupView extends View {

	protected const VIEW = 'cup';

	public function render( array $attrs ): string {
		$not_found = $this->not_found();
		if ( null !== $not_found ) {
			return $not_found;
		}
		$season = $this->season( $attrs );
		if ( null === $season ) {
			return $this->no_season();
		}
		$category = strtolower( trim( (string) ( $attrs['category'] ?? '' ) ) );
		$context  = $this->own_context();
		if ( '' === $category && null !== $context ) {
			$category = (string) $context->param( 'category' );
		}
		$categories = $this->model->cup->season( $season['id'] );
		if ( array() === $categories ) {
			return Html::notice( __( 'Für diese Saison ist kein Cup erfasst.', 'ttleague' ) );
		}

		$out = '';
		if ( count( $categories ) > 1 && null !== $context ) {
			$items = array( '<li>' . Html::link( $this->urls->cup( $season['slug'] ), __( 'Alle Kategorien', 'ttleague' ), '' === $category ? array( 'aria-current' => 'page' ) : array() ) . '</li>' );
			foreach ( $categories as $cup ) {
				$items[] = '<li>' . Html::link( $this->urls->cup( $season['slug'], $cup['category'] ), $cup['name'], $category === $cup['category'] ? array( 'aria-current' => 'page' ) : array() ) . '</li>';
			}
			$out .= '<nav class="ttl-tabs" aria-label="' . esc_attr__( 'Kategorien', 'ttleague' ) . '"><ul>' . implode( '', $items ) . '</ul></nav>';
		}
		foreach ( $categories as $cup ) {
			if ( '' !== $category && $cup['category'] !== $category ) {
				continue;
			}
			$body = '<h3 class="ttl-subtitle">' . esc_html__( 'Meldeliste', 'ttleague' ) . '</h3>' . $this->entries( $cup, $season['slug'] )
				. '<h3 class="ttl-subtitle">' . esc_html__( 'Resultate', 'ttleague' ) . '</h3>' . $this->results( $cup );
			$out .= Html::section( $cup['name'], $body, 'ttl-section--cup', 'ttl-cup-' . $cup['category'] );
		}
		return $out;
	}

	/**
	 * @param array<string,mixed> $cup Kategorie.
	 */
	private function entries( array $cup, string $season ): string {
		if ( array() === $cup['entries'] ) {
			return Html::notice( __( 'Noch keine Meldungen.', 'ttleague' ) );
		}
		$rows = array();
		foreach ( $cup['entries'] as $entry ) {
			$players = array_map( fn( array $p ): string => $this->player_link( $p, true ), $entry['players'] );
			$rows[]  = array(
				'cells' => array(
					null === $entry['seed'] ? '<span class="ttl-muted">–</span>' : esc_html( (string) $entry['seed'] ),
					Html::link( $this->urls->club( $entry['club_slug'], $season ), $entry['name'] ),
					implode( ', ', $players ),
					null === $entry['points_total'] ? '' : esc_html( (string) $entry['points_total'] ),
				),
			);
		}
		return Html::table(
			array(
				array(
					'label' => __( 'Setzung', 'ttleague' ),
					'class' => 'ttl-col-rank',
				),
				array(
					'label'      => __( 'Team', 'ttleague' ),
					'primary'    => true,
					'hide_label' => true,
				),
				array( 'label' => __( 'Spieler', 'ttleague' ) ),
				array(
					'label' => __( 'Punktesumme', 'ttleague' ),
					'abbr'  => __( 'Pkt.', 'ttleague' ),
					'class' => 'ttl-num',
				),
			),
			$rows,
			/* translators: %s: Cup-Kategorie. */
			array( 'caption' => sprintf( __( 'Meldeliste %s', 'ttleague' ), $cup['name'] ) )
		);
	}

	/**
	 * @param array<string,mixed> $cup Kategorie.
	 */
	private function results( array $cup ): string {
		if ( array() === $cup['fixtures'] ) {
			return Html::notice( __( 'Noch keine Resultate.', 'ttleague' ) );
		}
		$rows = array();
		foreach ( $cup['fixtures'] as $fixture ) {
			$home   = null === $fixture['home'] ? '?' : esc_html( $fixture['home']['name'] );
			$away   = null === $fixture['away'] ? '?' : esc_html( $fixture['away']['name'] );
			$score  = Format::score( $fixture['home_score'], $fixture['away_score'] );
			$date   = Format::date( $fixture['scheduled_at'] );
			$played = null !== $fixture['home_score'];
			$rows[] = array(
				'cells' => array(
					'' === $date ? esc_html__( 'Datum offen', 'ttleague' ) : $this->time_tag( $fixture['scheduled_at'], $date ),
					$home . ' <span aria-hidden="true">–</span><span class="screen-reader-text">' . esc_html__( 'gegen', 'ttleague' ) . '</span> ' . $away,
					( $played ? Html::link_html( $this->urls->fixture( (int) $fixture['id'] ), '<strong>' . esc_html( $score ) . '</strong>', array( 'aria-label' => __( 'Matchreport', 'ttleague' ) . ' ' . $score ) ) : '<span class="ttl-muted">' . esc_html( Format::time( $fixture['scheduled_at'] ) ) . '</span>' ) . $this->status_badge( $fixture['status'] ),
				),
			);
		}
		return Html::table(
			array(
				array(
					'label' => __( 'Datum', 'ttleague' ),
					'class' => 'ttl-col-date',
				),
				array(
					'label'      => __( 'Begegnung', 'ttleague' ),
					'primary'    => true,
					'hide_label' => true,
				),
				array(
					'label' => __( 'Resultat', 'ttleague' ),
					'class' => 'ttl-col-result',
				),
			),
			$rows,
			array(
				/* translators: %s: Cup-Kategorie. */
				'caption' => sprintf( __( 'Resultate %s', 'ttleague' ), $cup['name'] ),
				'class'   => 'ttl-schedule',
			)
		);
	}
}
