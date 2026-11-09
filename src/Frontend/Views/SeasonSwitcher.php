<?php
/**
 * Saisonwahl: dieselbe Ansicht in einer anderen Saison.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Frontend\Views;

use TTLeague\Frontend\Support\Html;

/**
 * Ohne JavaScript: ein <details>-Menü mit Links. Auf Seiten ohne Saisonbezug führen die
 * Links zur Meisterschaft der gewählten Saison.
 */
final class SeasonSwitcher extends View {

	public function render( array $attrs ): string {
		$seasons = $this->model->seasons->all();
		if ( array() === $seasons ) {
			return '';
		}
		$current = null === $this->context ? null : $this->context->season;
		$current = $current ?? $this->model->seasons->active();
		$items   = '';
		foreach ( $seasons as $season ) {
			$is_current = null !== $current && $current['id'] === $season['id'];
			$label      = $season['name'] . ( 'active' === $season['status'] ? ' · ' . __( 'laufend', 'ttleague' ) : '' );
			$items     .= '<li>' . Html::link( $this->target( $season ), $label, $is_current ? array( 'aria-current' => 'true' ) : array() ) . '</li>';
		}
		$items .= '<li class="ttl-season-switch__archive">' . Html::link( $this->urls->archive(), __( 'Alle Saisons im Archiv', 'ttleague' ) ) . '</li>';
		$label  = empty( $attrs['label'] ) ? __( 'Saison', 'ttleague' ) : (string) $attrs['label'];
		return '<nav class="ttl-season-switch" aria-label="' . esc_attr__( 'Saisonwahl', 'ttleague' ) . '"><details>'
			. '<summary><span class="ttl-season-switch__label">' . esc_html( $label ) . '</span> <span class="ttl-season-switch__current">' . esc_html( null === $current ? '' : $current['name'] ) . '</span></summary>'
			. '<ul class="ttl-season-switch__list">' . $items . '</ul></details></nav>';
	}

	/**
	 * Adresse derselben Ansicht in einer anderen Saison.
	 *
	 * @param array<string,mixed> $season Zielsaison.
	 */
	public function target( array $season ): string {
		$slug    = $season['slug'];
		$context = $this->context;
		if ( null === $context ) {
			return $this->urls->championship( $slug );
		}
		switch ( $context->view ) {
			case 'group':
				$group = $context->object( 'group' );
				if ( null !== $group && null !== $this->model->championship->group_by_slug( $season['id'], $group['slug'] ) ) {
					return $this->urls->group( $slug, $group['slug'] );
				}
				return $this->urls->championship( $slug );
			case 'team':
				$team = $context->object( 'team' );
				if ( null !== $team && null !== $this->model->championship->team_by_slug( $season['id'], $team['slug'] ) ) {
					return $this->urls->team( $slug, $team['slug'] );
				}
				return $this->urls->championship( $slug );
			case 'clubs':
				return $this->urls->clubs( $slug );
			case 'club':
				$club = $context->object( 'club' );
				return null === $club ? $this->urls->clubs( $slug ) : $this->urls->club( $club['slug'], $slug );
			case 'players':
				return $this->urls->players( $slug, $context->param( 'club_filter' ) );
			case 'player':
				$player = $context->object( 'player' );
				return null === $player ? $this->urls->players( $slug ) : $this->urls->player( (int) $player['license'], $player['slug'], $slug ) . '#ttl-alle-spiele';
			case 'ranking':
				return $this->urls->ranking( $slug );
			case 'cup':
				return $this->urls->cup( $slug, $context->param( 'category' ) );
		}
		return $this->urls->championship( $slug );
	}
}
