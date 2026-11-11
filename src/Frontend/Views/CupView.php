<?php
/**
 * Mannschaftscup: Tableau, Meldelisten (Setzung, Team, Spieler, Punkte) und Resultate.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Frontend\Views;

use TTLeague\Cup\Labels;
use TTLeague\Domain\Cup\BracketRules;
use TTLeague\Frontend\Support\Format;
use TTLeague\Frontend\Support\Html;

/**
 * Mit freigegebener Auslosung zeigt die Seite je Kategorie das Tableau (Runden als Spalten, auf
 * dem Handy untereinander) mit Fristen, Terminen und Resultaten und darunter die Meldeliste.
 * Ohne Auslosung (auch in importierten Saisons ohne cup_tie) bleibt es bei Meldeliste und
 * Resultaten.
 */
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
			$body = '<h3 class="ttl-subtitle">' . esc_html__( 'Meldeliste', 'ttleague' ) . '</h3>' . $this->entries( $cup, $season['slug'] );
			if ( array() === ( $cup['ties'] ?? array() ) ) {
				$body .= '<h3 class="ttl-subtitle">' . esc_html__( 'Resultate', 'ttleague' ) . '</h3>' . $this->results( $cup );
			} else {
				$body = '<h3 class="ttl-subtitle">' . esc_html__( 'Tableau', 'ttleague' ) . '</h3>' . $this->bracket( $cup ) . $body;
			}
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
					Html::link( $this->urls->club( $entry['club_slug'], $season ), $entry['name'] ) . self::withdrawn( $entry ),
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
	 * Tableau einer Kategorie: eine Spalte je Runde, darin die Paarungen in der Reihenfolge ihrer
	 * Plätze. Der obere Platz einer Paarung steht zuerst.
	 *
	 * @param array<string,mixed> $cup Kategorie mit «ties» und «rounds».
	 */
	private function bracket( array $cup ): string {
		$rounds = array();
		foreach ( $cup['ties'] as $tie ) {
			$rounds[ (int) $tie['round'] ][] = $tie;
		}
		ksort( $rounds );
		$total = (int) $cup['rounds'];
		$out   = '';
		foreach ( $rounds as $round => $ties ) {
			$id        = 'ttl-cup-' . $cup['category'] . '-runde-' . $round;
			$deadlines = array_unique( array_map( static fn( array $tie ): string => (string) $tie['deadline'], $ties ) );
			$common    = 1 === count( $deadlines ) && '' !== $deadlines[0] ? $deadlines[0] : null;
			$neutral   = BracketRules::is_neutral( $round, $total );
			$items     = '';
			foreach ( $ties as $tie ) {
				$items .= $this->tie( $tie, 1 === $round, $neutral, null === $common );
			}
			$out .= '<section class="ttl-bracket__round" aria-labelledby="' . esc_attr( $id ) . '">'
				. '<h4 class="ttl-bracket__title" id="' . esc_attr( $id ) . '">' . esc_html( Labels::round( $round, $total ) ) . '</h4>'
				/* translators: %s: Datum */
				. ( null === $common ? '' : '<p class="ttl-bracket__deadline">' . esc_html( sprintf( __( 'zu spielen bis %s', 'ttleague' ), Format::date_plain( $common ) ) ) . '</p>' )
				. '<ol class="ttl-bracket__ties">' . $items . '</ol></section>';
		}
		/* translators: %s: Cup-Kategorie. */
		return '<div class="ttl-bracket" role="group" aria-label="' . esc_attr( sprintf( __( 'Tableau %s', 'ttleague' ), $cup['name'] ) ) . '">' . $out . '</div>';
	}

	/**
	 * Eine Paarung: beide Mannschaften mit ihrem Resultat, darunter Termin, Frist und Matchreport.
	 *
	 * @param array<string,mixed> $tie           Paarung.
	 * @param bool                $first_round   Erste Runde: ein leerer Platz ist ein Freilos.
	 * @param bool                $neutral       Halbfinal oder Final (kein Heimrecht).
	 * @param bool                $show_deadline Frist bei der Paarung nennen (nicht schon bei der Runde).
	 */
	private function tie( array $tie, bool $first_round, bool $neutral, bool $show_deadline ): string {
		$fixture = $tie['fixture'];
		$played  = null !== $fixture && null !== $fixture['home_score'] && null !== $fixture['away_score'] && 'cancelled' !== $fixture['status'];
		$winner  = null === $fixture ? null : BracketRules::fixture_winner( (string) $fixture['status'], $fixture['home_score'], $fixture['away_score'] );
		$rows    = '';
		foreach ( array( 'home', 'away' ) as $place ) {
			$entry = $tie[ $place ];
			if ( null === $entry ) {
				$rows .= '<div class="ttl-bracket__team ttl-bracket__team--empty"><span class="ttl-bracket__name">' . esc_html( $first_round ? __( 'Freilos', 'ttleague' ) : __( 'offen', 'ttleague' ) ) . '</span></div>';
				continue;
			}
			$side  = null !== $fixture && $fixture['away_id'] === $entry['id'] ? 'away' : 'home';
			$won   = null !== $winner && $winner === $side;
			$name  = esc_html( (string) $entry['name'] ) . self::withdrawn( $entry );
			$score = '';
			if ( $played ) {
				$score = '<span class="ttl-bracket__score">' . esc_html( (string) $fixture[ $side . '_score' ] ) . '</span>';
			} elseif ( null !== $fixture && ! $neutral && 'home' === $side ) {
				$score = '<abbr class="ttl-bracket__home" title="' . esc_attr__( 'Heimrecht', 'ttleague' ) . '">' . esc_html__( 'H', 'ttleague' ) . '</abbr>';
			}
			$rows .= '<div class="ttl-bracket__team' . ( $won ? ' ttl-bracket__team--winner' : '' ) . '"><span class="ttl-bracket__name">' . ( $won ? '<strong>' . $name . '</strong>' : $name ) . '</span>' . $score . '</div>';
		}

		$meta = array();
		if ( $played ) {
			$score  = Format::score( $fixture['home_score'], $fixture['away_score'] );
			$meta[] = Html::link( $this->urls->fixture( (int) $fixture['id'] ), __( 'Matchreport', 'ttleague' ), array( 'aria-label' => __( 'Matchreport', 'ttleague' ) . ' ' . $score ) ) . $this->status_badge( (string) $fixture['status'] );
		} elseif ( null !== $fixture && 'cancelled' !== $fixture['status'] ) {
			$when   = Format::datetime( $fixture['scheduled_at'] );
			$meta[] = '' === $when
				? '<span class="ttl-muted">' . esc_html__( 'Termin offen', 'ttleague' ) . '</span>'
				: $this->time_tag( $fixture['scheduled_at'], $when ) . ( null === $fixture['venue'] ? '' : ', ' . esc_html( (string) $fixture['venue'] ) );
			if ( $show_deadline && null !== $tie['deadline'] ) {
				/* translators: %s: Datum */
				$meta[] = esc_html( sprintf( __( 'zu spielen bis %s', 'ttleague' ), Format::date_plain( $tie['deadline'] ) ) );
			}
		}
		return '<li class="ttl-bracket__tie">' . $rows . ( array() === $meta ? '' : '<p class="ttl-bracket__meta">' . implode( ' · ', $meta ) . '</p>' ) . '</li>';
	}

	/**
	 * Vermerk bei einer zurückgezogenen Mannschaft.
	 *
	 * @param array<string,mixed> $entry Meldung.
	 */
	private static function withdrawn( array $entry ): string {
		return 'withdrawn' === ( $entry['status'] ?? '' ) ? ' ' . Html::badge( __( 'zurückgezogen', 'ttleague' ), 'muted' ) : '';
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
