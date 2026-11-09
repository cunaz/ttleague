<?php
/**
 * Spielplan/Resultate als Tabelle (Gruppe, Mannschaft).
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Frontend\Views;

use TTLeague\Frontend\Support\Format;
use TTLeague\Frontend\Support\Html;
use TTLeague\Frontend\Support\Urls;

final class Schedule {

	public function __construct( private readonly Urls $urls ) {}

	/**
	 * Chronologischer Spielplan: Datum mit Wochentag, Begegnung, Resultat oder Zeit, Status.
	 *
	 * @param list<array<string,mixed>>      $fixtures Begegnungen.
	 * @param array<int,array<string,mixed>> $teams    Teams der Saison.
	 * @param string                         $season   Saison-Slug.
	 * @param int|null                       $focus    Team aus dessen Sicht (Sieg/Niederlage), sonst null.
	 * @param string                         $caption  Tabellenbeschriftung.
	 */
	public function render( array $fixtures, array $teams, string $season, ?int $focus, string $caption ): string {
		if ( array() === $fixtures ) {
			return Html::notice( __( 'Noch keine Begegnungen angesetzt.', 'ttleague' ) );
		}
		$rows = array();
		foreach ( $fixtures as $fixture ) {
			$home    = $teams[ $fixture['home_team_id'] ] ?? null;
			$away    = $teams[ $fixture['away_team_id'] ] ?? null;
			$date    = Format::date( $fixture['scheduled_at'] );
			$date    = '' === $date ? __( 'Datum offen', 'ttleague' ) : $date;
			$time    = Format::time( $fixture['scheduled_at'] );
			$played  = null !== $fixture['home_score'] && null !== $fixture['away_score'];
			$pairing = ( null === $home ? '?' : $this->team( $home, $season, $focus ) ) . ' <span class="ttl-dash" aria-hidden="true">–</span><span class="screen-reader-text">' . esc_html__( 'gegen', 'ttleague' ) . '</span> ' . ( null === $away ? '?' : $this->team( $away, $season, $focus ) );
			$result  = '';
			$outcome = '';
			$label   = ( null === $home ? '' : $home['name'] ) . ' – ' . ( null === $away ? '' : $away['name'] );
			if ( $played ) {
				$score = Format::score( $fixture['home_score'], $fixture['away_score'] );
				/* translators: 1: Begegnung, 2: Resultat. */
				$aria   = sprintf( __( 'Matchreport %1$s, %2$s', 'ttleague' ), $label, $score );
				$result = Html::link_html(
					$this->urls->fixture( (int) $fixture['id'] ),
					'<strong>' . esc_html( $score ) . '</strong>',
					array(
						'aria-label' => $aria,
						'class'      => 'ttl-score',
					)
				);
				if ( null !== $focus ) {
					$outcome = $this->outcome( $fixture, $focus );
				}
			} else {
				$result = '<span class="ttl-muted">' . esc_html( '' === $time ? '–' : $time ) . '</span>';
			}
			$rows[] = array(
				'class' => trim( ( $played ? 'is-played' : 'is-open' ) . ' is-' . $fixture['status'] ),
				'cells' => array(
					$this->time_tag( $fixture['scheduled_at'], $date ) . ( $played && '' !== $time ? ' <span class="ttl-muted ttl-hide-mobile">' . esc_html( $time ) . '</span>' : '' ),
					$pairing,
					$result . $outcome . $this->badge( (string) $fixture['status'] ),
				),
			);
		}
		$columns = array(
			array(
				'label' => __( 'Datum', 'ttleague' ),
				'class' => 'ttl-col-date',
			),
			array(
				'label'      => __( 'Begegnung', 'ttleague' ),
				'class'      => 'ttl-col-pairing',
				'primary'    => true,
				'hide_label' => true,
			),
			array(
				'label' => __( 'Resultat', 'ttleague' ),
				'class' => 'ttl-col-result',
			),
		);
		return Html::table(
			$columns,
			$rows,
			array(
				'caption' => $caption,
				'class'   => 'ttl-schedule',
			)
		);
	}

	/**
	 * @param array<string,mixed> $team Team.
	 */
	private function team( array $team, string $season, ?int $focus ): string {
		$link = Html::link( $this->urls->team( $season, $team['slug'] ), $team['name'] );
		return $focus === $team['id'] ? '<strong>' . $link . '</strong>' : $link;
	}

	/**
	 * @param array<string,mixed> $fixture Begegnung.
	 */
	private function outcome( array $fixture, int $team_id ): string {
		$for     = $fixture['home_team_id'] === $team_id ? $fixture['home_score'] : $fixture['away_score'];
		$against = $fixture['home_team_id'] === $team_id ? $fixture['away_score'] : $fixture['home_score'];
		if ( $for > $against ) {
			return ' ' . Html::badge( __( 'Sieg', 'ttleague' ), 'win' );
		}
		if ( $for < $against ) {
			return ' ' . Html::badge( __( 'Niederlage', 'ttleague' ), 'loss' );
		}
		return ' ' . Html::badge( __( 'Unentschieden', 'ttleague' ), 'draw' );
	}

	private function badge( string $status ): string {
		$marker = Format::fixture_marker( $status );
		if ( null === $marker ) {
			return '';
		}
		return ' ' . Html::badge( $marker, in_array( $status, array( 'forfeit', 'cancelled', 'disputed' ), true ) ? 'warning' : 'muted' );
	}

	private function time_tag( ?string $datetime, string $text ): string {
		if ( null === $datetime ) {
			return esc_html( $text );
		}
		return '<time datetime="' . esc_attr( str_replace( ' ', 'T', substr( $datetime, 0, 16 ) ) ) . '">' . esc_html( $text ) . '</time>';
	}
}
