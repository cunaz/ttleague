<?php
/**
 * Startseite: letzte Resultate und nächste Begegnungen als kompakte Liste.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Frontend\Views;

use TTLeague\Frontend\Support\Format;
use TTLeague\Frontend\Support\Html;

final class FixtureList extends View {

	/**
	 * @param bool $upcoming true: nächste Begegnungen, false: letzte Resultate.
	 */
	public function __construct( \TTLeague\ReadModel\ReadModel $model, \TTLeague\Frontend\Support\Urls $urls, ?\TTLeague\Frontend\Context $context, private readonly bool $upcoming = false, private readonly string $now = '' ) {
		parent::__construct( $model, $urls, $context );
	}

	public function render( array $attrs ): string {
		$count  = max( 1, min( 30, (int) ( $attrs['count'] ?? 6 ) ) );
		$season = null;
		if ( '' !== trim( (string) ( $attrs['season'] ?? '' ) ) ) {
			$season = $this->model->seasons->by_slug( trim( (string) $attrs['season'] ) );
		}
		$season_id = null === $season ? null : $season['id'];
		$fixtures  = $this->upcoming
			? $this->model->fixtures->upcoming( $count, '' === $this->now ? gmdate( 'Y-m-d H:i:s' ) : $this->now, $season_id )
			: $this->model->fixtures->latest( $count, $season_id );
		if ( array() === $fixtures ) {
			return Html::notice( $this->upcoming ? __( 'Keine angesetzten Begegnungen.', 'ttleague' ) : __( 'Noch keine Resultate.', 'ttleague' ) );
		}
		$items = '';
		foreach ( $fixtures as $fixture ) {
			$competition = 'CUP' === $fixture['kind']
				? Html::link( $this->urls->cup( $fixture['season_slug'], (string) $fixture['category'] ), $fixture['competition'] )
				: Html::link( $this->urls->group( $fixture['season_slug'], \TTLeague\ReadModel\Championship::group_slug( $fixture['category'], (int) $fixture['group_no'], 0 ) ), $fixture['competition'] );
			$home        = 'MM' === $fixture['kind'] ? Html::link( $this->urls->team( $fixture['season_slug'], $fixture['home_slug'] ), $fixture['home'] ) : esc_html( $fixture['home'] );
			$away        = 'MM' === $fixture['kind'] ? Html::link( $this->urls->team( $fixture['season_slug'], $fixture['away_slug'] ), $fixture['away'] ) : esc_html( $fixture['away'] );
			$date        = $this->upcoming ? Format::datetime( $fixture['scheduled_at'] ) : Format::date( $fixture['scheduled_at'] );
			if ( $this->upcoming ) {
				$result = '' === (string) $fixture['venue'] ? '' : '<span class="ttl-fixture-list__venue">' . esc_html( (string) $fixture['venue'] ) . '</span>';
			} else {
				$score  = Format::score( $fixture['home_score'], $fixture['away_score'] );
				$result = Html::link_html(
					$this->urls->fixture( $fixture['id'] ),
					esc_html( $score ),
					array(
						'class'      => 'ttl-fixture-list__score',
						'aria-label' => __( 'Matchreport', 'ttleague' ) . ' ' . $fixture['home'] . ' – ' . $fixture['away'] . ', ' . $score,
					)
				);
			}
			$items .= '<li class="ttl-fixture-list__item">'
				. '<span class="ttl-fixture-list__meta">' . $this->time_tag( $fixture['scheduled_at'], $date ) . ' · ' . $competition . $this->status_badge( $fixture['status'] ) . '</span>'
				. '<span class="ttl-fixture-list__pairing">' . $home . ' <span aria-hidden="true">–</span><span class="screen-reader-text">' . esc_html__( 'gegen', 'ttleague' ) . '</span> ' . $away . '</span>'
				. $result . '</li>';
		}
		return '<ul class="ttl-fixture-list' . ( $this->upcoming ? ' ttl-fixture-list--upcoming' : '' ) . '">' . $items . '</ul>';
	}
}
