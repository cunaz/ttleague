<?php
/**
 * Matchreport einer Begegnung (Meisterschaft und Cup).
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Frontend\Views;

use TTLeague\Frontend\Support\Format;
use TTLeague\Frontend\Support\Html;
use TTLeague\Results\EntryLinks;

final class MatchReportView extends View {

	protected const VIEW = 'fixture';

	public function render( array $attrs ): string {
		$not_found = $this->not_found();
		if ( null !== $not_found ) {
			return $not_found;
		}
		$id      = (int) ( $attrs['fixture'] ?? 0 );
		$context = $this->own_context();
		$report  = null;
		if ( $id > 0 ) {
			$report = $this->model->reports->get( $id );
		} elseif ( null !== $context ) {
			$report = $context->object( 'report' );
		}
		if ( null === $report ) {
			return Html::notice( __( 'Dieser Block zeigt die Begegnung aus der Adresse (z. B. /begegnung/123/). Für eine feste Begegnung die Nummer in den Block-Einstellungen angeben.', 'ttleague' ) );
		}

		// Kontakt der beiden Seiten nur für angemeldete Funktionärinnen und Funktionäre (sonst leer).
		return $this->header( $report ) . $this->lineups( $report ) . $this->games( $report ) . ContactBox::fixture_section( (int) $report['id'] );
	}

	/**
	 * Kopf: Konkurrenz, Datum, Lokal, Resultat.
	 *
	 * @param array<string,mixed> $report Matchreport.
	 */
	private function header( array $report ): string {
		$season = $report['season'];
		if ( 'CUP' === $report['kind'] ) {
			$competition = Html::link( $this->urls->cup( $season['slug'], (string) $report['competition']['category'] ), $report['competition']['name'] . ' ' . $season['name'] );
		} else {
			$competition = Html::link( $this->urls->championship( $season['slug'] ), __( 'Meisterschaft', 'ttleague' ) . ' ' . $season['name'] )
				. ' · ' . Html::link( $this->urls->group( $season['slug'], (string) $report['competition']['slug'] ), $report['competition']['name'] );
		}
		$round = '';
		if ( null !== $report['round'] ) {
			/* translators: %d: Runde. */
			$round = esc_html( sprintf( __( '%d. Runde', 'ttleague' ), $report['round'] ) );
			if ( null !== $report['leg'] ) {
				$round .= ' · ' . esc_html( 1 === $report['leg'] ? __( 'Vorrunde', 'ttleague' ) : __( 'Rückrunde', 'ttleague' ) );
			}
		}
		$venue = '';
		if ( null !== $report['venue'] ) {
			$address = Format::address( $report['venue']['street'], $report['venue']['zip'], $report['venue']['city'] );
			$venue   = esc_html( $report['venue']['name'] );
			if ( '' !== $address ) {
				$venue .= '<br><span class="ttl-muted">' . esc_html( $address ) . '</span> · ' . Html::link( Format::map_url( $address ), __( 'Karte', 'ttleague' ), array( 'rel' => 'nofollow' ) );
			}
		}
		$date = Format::datetime( $report['scheduled_at'] );

		$home   = $this->side_name( $report, 'home' );
		$away   = $this->side_name( $report, 'away' );
		$score  = Format::score( $report['home_score'], $report['away_score'] );
		$out    = '<div class="ttl-match-head">';
		$out   .= '<p class="ttl-kicker">' . $competition . '</p>';
		$out   .= '<div class="ttl-scoreline">'
			. '<span class="ttl-scoreline__team ttl-scoreline__team--home">' . $home . '</span>'
			. '<span class="ttl-scoreline__score" aria-label="' . esc_attr__( 'Resultat', 'ttleague' ) . ' ' . esc_attr( $score ) . '">' . esc_html( $score ) . '</span>'
			. '<span class="ttl-scoreline__team ttl-scoreline__team--away">' . $away . '</span>'
			. '</div>';
		$status = Format::fixture_marker( $report['status'] );
		$out   .= Html::facts(
			array(
				__( 'Datum', 'ttleague' )  => '' === $date ? esc_html__( 'noch offen', 'ttleague' ) : $this->time_tag( $report['scheduled_at'], $date ),
				__( 'Runde', 'ttleague' )  => $round,
				__( 'Lokal', 'ttleague' )  => $venue,
				__( 'Status', 'ttleague' ) => null === $status ? '' : Html::badge( $status, 'forfeit' === $report['status'] ? 'warning' : 'muted' ),
				__( 'Sätze', 'ttleague' )  => array() === $report['games'] ? '' : esc_html( Format::ratio( $report['totals']['sets_home'], $report['totals']['sets_away'] ) ),
			),
			'ttl-facts--match'
		);
		if ( null !== $report['note'] && '' !== trim( (string) $report['note'] ) ) {
			$out .= '<p class="ttl-note">' . esc_html( (string) $report['note'] ) . '</p>';
		}
		// Nur für angemeldete Personen mit Recht; die Ausgabe für Gäste bleibt gleich (Cache).
		$out .= EntryLinks::report_button( (int) $report['id'], (string) $report['status'] );
		return $out . '</div>';
	}

	/**
	 * @param array<string,mixed> $report Matchreport.
	 */
	private function side_name( array $report, string $side ): string {
		$data = $report[ $side ];
		if ( 'MM' === $report['kind'] && null !== $data['slug'] ) {
			return Html::link( $this->urls->team( $report['season']['slug'], (string) $data['slug'] ), $data['name'] );
		}
		if ( null !== $data['club_slug'] ) {
			return Html::link( $this->urls->club( (string) $data['club_slug'], $report['season']['slug'] ), $data['name'] );
		}
		return esc_html( $data['name'] );
	}

	/**
	 * Aufstellungen beider Seiten mit Klassierung und Ersatz-Markierung.
	 *
	 * @param array<string,mixed> $report Matchreport.
	 */
	private function lineups( array $report ): string {
		if ( array() === $report['lineup']['home'] && array() === $report['lineup']['away'] ) {
			return '';
		}
		$out = '<div class="ttl-lineups">';
		foreach ( array( 'home', 'away' ) as $side ) {
			$items = '';
			foreach ( $report['lineup'][ $side ] as $entry ) {
				$items .= '<li><span class="ttl-slot" aria-hidden="true">' . esc_html( $entry['slot'] ) . '</span> '
					. $this->player_link( $entry['player'] ) . ' ' . Html::classification( $entry['classification'] )
					. ( $entry['is_substitute'] ? ' ' . Html::badge( __( 'Ersatz', 'ttleague' ), 'muted' ) : '' ) . '</li>';
			}
			if ( '' === $items ) {
				$items = '<li class="ttl-muted">' . esc_html__( 'keine Aufstellung erfasst', 'ttleague' ) . '</li>';
			}
			$out .= '<section class="ttl-lineup ttl-lineup--' . $side . '"><h2 class="ttl-lineup__title">' . esc_html( $report[ $side ]['name'] ) . '</h2><ul class="ttl-lineup__list">' . $items . '</ul></section>';
		}
		return $out . '</div>';
	}

	/**
	 * Alle Spiele in Spielfolge.
	 *
	 * @param array<string,mixed> $report Matchreport.
	 */
	private function games( array $report ): string {
		if ( array() === $report['games'] ) {
			if ( 'forfeit' === $report['status'] ) {
				/* translators: %s: Resultat, z. B. 0:10. */
				return Html::notice( sprintf( __( 'Forfait: Die Begegnung wurde %s gewertet. Es liegen keine Einzelergebnisse vor.', 'ttleague' ), Format::score( $report['home_score'], $report['away_score'] ) ), 'warning' );
			}
			if ( null === $report['home_score'] ) {
				return Html::notice( __( 'Diese Begegnung ist noch nicht gespielt.', 'ttleague' ) );
			}
			return Html::notice( __( 'Für diese Begegnung sind keine Einzelergebnisse erfasst.', 'ttleague' ) );
		}

		$rows    = array();
		$skipped = false;
		foreach ( $report['games'] as $game ) {
			$pair = 'double' === $game['type'] ? __( 'Doppel', 'ttleague' ) : trim( ( $game['slot_home'] ?? '' ) . '–' . ( $game['slot_away'] ?? '' ), '–' );
			$sets = 'not_played' === $game['status'] ? '–' : Format::ratio( $game['sets_a'], $game['sets_b'] );
			$note = Format::game_status( $game['status'] );
			if ( ! $game['counts_for_result'] ) {
				$skipped = true;
				$note    = trim( $note . ' ' . __( 'nach Entscheidung', 'ttleague' ) );
			}
			$set_points = '';
			if ( array() !== $game['set_scores'] ) {
				$set_points = '<span class="ttl-sets">' . esc_html(
					implode(
						', ',
						array_map( static fn( array $s ): string => $s['a'] . ':' . $s['b'], $game['set_scores'] )
					)
				) . '</span>';
			}
			$rows[] = array(
				'class' => trim( ( 'played' === $game['status'] ? '' : 'is-' . $game['status'] ) . ( $game['counts_for_result'] ? '' : ' is-uncounted' ) ),
				'cells' => array(
					esc_html( (string) $game['sequence'] ) . ' <span class="ttl-muted">' . esc_html( $pair ) . '</span>',
					$this->names( $game['a'], 'a' === $game['winner'] ),
					$this->names( $game['b'], 'b' === $game['winner'] ),
					'<strong>' . esc_html( $sets ) . '</strong>' . ( '' === $note ? '' : ' ' . Html::badge( $note, 'muted' ) ) . $set_points,
				),
			);
		}
		$columns = array(
			array(
				'label' => __( 'Spiel', 'ttleague' ),
				'class' => 'ttl-col-seq',
			),
			array(
				'label' => $report['home']['name'],
				'class' => 'ttl-col-side',
			),
			array(
				'label' => $report['away']['name'],
				'class' => 'ttl-col-side',
			),
			array(
				'label' => __( 'Sätze', 'ttleague' ),
				'class' => 'ttl-col-result',
			),
		);
		$table   = Html::table(
			$columns,
			$rows,
			array(
				'caption' => __( 'Spiele in Spielfolge', 'ttleague' ),
				'class'   => 'ttl-games',
			)
		);
		$totals  = $report['totals'];
		/* translators: 1: Spiele, 2: Sätze. */
		$summary = sprintf( __( 'Total: Spiele %1$s · Sätze %2$s', 'ttleague' ), Format::ratio( $totals['wins_home'], $totals['wins_away'] ), Format::ratio( $totals['sets_home'], $totals['sets_away'] ) );
		if ( null !== $totals['points_home'] ) {
			/* translators: %s: Ballpunkte. */
			$summary .= ' · ' . sprintf( __( 'Punkte %s', 'ttleague' ), Format::ratio( (int) $totals['points_home'], (int) $totals['points_away'] ) );
		}
		$foot = '<p class="ttl-total">' . esc_html( $summary ) . '</p>';
		if ( $skipped ) {
			$foot .= '<p class="ttl-footnote">' . esc_html__( 'Spiele nach der Entscheidung zählen für Statistik und Wertungszahl, nicht für das Resultat.', 'ttleague' ) . '</p>';
		}
		$foot .= '<p class="ttl-footnote">' . esc_html__( 'Satzverhältnisse zählen nur gespielte Spiele (ohne w.o.).', 'ttleague' ) . '</p>';
		return Html::section( __( 'Spiele', 'ttleague' ), $table . $foot, 'ttl-section--games', 'ttl-spiele' );
	}

	/**
	 * Spielernamen einer Seite; beide Namen im Doppel.
	 *
	 * @param list<array<string,mixed>> $players Spieler.
	 */
	private function names( array $players, bool $winner ): string {
		if ( array() === $players ) {
			return '<span class="ttl-muted">' . esc_html__( '(kein Spieler)', 'ttleague' ) . '</span>';
		}
		$html = implode( ' / ', array_map( fn( array $p ): string => $this->player_link( $p ), $players ) );
		return $winner ? '<span class="ttl-winner">' . $html . '<span class="screen-reader-text"> ' . esc_html__( '(Sieg)', 'ttleague' ) . '</span></span>' : $html;
	}
}
