<?php
/**
 * Ranglistenturnier: Runden, Kategorien, Gruppen, Gesamtrangliste mit Finalqualifikation, Finalrunde.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Frontend\Views;

use TTLeague\Frontend\Support\Format;
use TTLeague\Frontend\Support\Html;

final class RankingView extends View {

	protected const VIEW = 'ranking';

	public function render( array $attrs ): string {
		$not_found = $this->not_found();
		if ( null !== $not_found ) {
			return $not_found;
		}
		$season = $this->season( $attrs );
		if ( null === $season ) {
			return $this->no_season();
		}
		$rl = $this->model->ranking->get( $season['id'] );
		if ( null === $rl || array() === $rl['rounds'] ) {
			return Html::notice( __( 'Für diese Saison ist kein Ranglistenturnier erfasst.', 'ttleague' ) );
		}
		$only = (int) ( $attrs['category'] ?? 0 );

		$nav = array();
		foreach ( $rl['rounds'] as $round ) {
			$nav[] = '<li>' . Html::link( '#ttl-runde-' . $round['round_no'], $this->round_label( $round ) ) . '</li>';
		}
		// Html::section() setzt die ID auf den Titel des Abschnitts.
		$nav[] = '<li>' . Html::link( '#ttl-gesamt-title', __( 'Gesamtrangliste', 'ttleague' ) ) . '</li>';
		$out   = '<nav class="ttl-tabs" aria-label="' . esc_attr__( 'Runden', 'ttleague' ) . '"><ul>' . implode( '', $nav ) . '</ul></nav>';

		$regular       = $rl['regular_rounds'];
		$overall_html  = $this->overall( $rl['overall'], $rl['finalists'], $only, $regular );
		$overall_title = $this->overall_title( count( $regular ) );
		$open          = $this->current_round( $rl['rounds'] );
		$shown         = false;
		foreach ( $rl['rounds'] as $round ) {
			// Die Gesamtrangliste steht vor der Finalrunde, sonst am Schluss: genau einmal.
			if ( $round['is_final'] && ! $shown ) {
				$out  .= Html::section( $overall_title, $overall_html, 'ttl-section--overall', 'ttl-gesamt' );
				$shown = true;
			}
			$out .= $this->round( $round, $only, $round['round_no'] === $open );
		}
		if ( ! $shown ) {
			$out .= Html::section( $overall_title, $overall_html, 'ttl-section--overall', 'ttl-gesamt' );
		}
		return $out;
	}

	/**
	 * Titel der Gesamtrangliste: «nach drei Runden» beim üblichen Modus, sonst mit der Zahl
	 * der regulären Runden.
	 */
	private function overall_title( int $rounds ): string {
		if ( 3 === $rounds ) {
			return __( 'Gesamtrangliste nach drei Runden', 'ttleague' );
		}
		/* translators: %d: Anzahl reguläre Runden. */
		return sprintf( _n( 'Gesamtrangliste nach %d Runde', 'Gesamtrangliste nach %d Runden', $rounds, 'ttleague' ), $rounds );
	}

	/**
	 * @param array<string,mixed> $round Runde.
	 */
	private function round_label( array $round ): string {
		/* translators: %d: Runde. */
		return $round['is_final'] ? __( 'Finalrunde', 'ttleague' ) : sprintf( __( '%d. Runde', 'ttleague' ), $round['round_no'] );
	}

	/**
	 * Aufgeklappte Runde: die letzte mit eingeteilten Gruppen.
	 *
	 * @param list<array<string,mixed>> $rounds Runden.
	 */
	private function current_round( array $rounds ): int {
		$open = 0;
		foreach ( $rounds as $round ) {
			if ( array() !== $round['categories'] ) {
				$open = $round['round_no'];
			}
		}
		return $open;
	}

	/**
	 * Runde als aufklappbarer Abschnitt.
	 *
	 * @param array<string,mixed> $round Runde mit Kategorien.
	 */
	private function round( array $round, int $only, bool $open ): string {
		$title  = $this->round_label( $round );
		$dates  = Format::date_range( $round['date_from'], $round['date_to'] );
		$groups = 0;
		foreach ( $round['categories'] as $category_groups ) {
			$groups += count( $category_groups );
		}
		/* translators: %d: Anzahl Gruppen. */
		$meta = trim( $dates . ( 0 === $groups ? '' : ' · ' . sprintf( _n( '%d Gruppe', '%d Gruppen', $groups, 'ttleague' ), $groups ) ), ' ·' );
		$body = '';
		if ( array() === $round['categories'] ) {
			$body .= Html::notice( __( 'Die Gruppen sind noch nicht eingeteilt.', 'ttleague' ) );
		}
		foreach ( $round['categories'] as $category => $groups ) {
			if ( $only > 0 && $only !== (int) $category ) {
				continue;
			}
			/* translators: %d: Kategorie. */
			$body .= '<h3 class="ttl-subtitle">' . esc_html( sprintf( __( 'Kategorie %d', 'ttleague' ), $category ) ) . '</h3><div class="ttl-grid ttl-grid--cards">';
			foreach ( $groups as $group ) {
				$body .= $this->group( $group, $round );
			}
			$body .= '</div>';
		}
		return '<details class="ttl-section ttl-round" id="ttl-runde-' . (int) $round['round_no'] . '"' . ( $open ? ' open' : '' ) . '>'
			. '<summary class="ttl-round__summary"><h2 class="ttl-section__title">' . esc_html( $title ) . '</h2>'
			. ( '' === $meta ? '' : '<span class="ttl-round__meta">' . esc_html( $meta ) . '</span>' ) . '</summary>'
			. $body . '</details>';
	}

	/**
	 * Gruppe: Kopf (Aufgebot, Platzclub), Rangliste, Einzelpartien.
	 *
	 * @param array<string,mixed> $group Gruppe.
	 * @param array<string,mixed> $round Runde.
	 */
	private function group( array $group, array $round ): string {
		/* translators: %d: Gruppennummer. */
		$title = sprintf( __( 'Gruppe %d', 'ttleague' ), $group['group_no'] );
		$meta  = array();
		$when  = Format::datetime( $group['start_at'] );
		if ( '' !== $when ) {
			$meta[] = $this->time_tag( $group['start_at'], $when );
		}
		if ( null !== $group['host'] ) {
			/* translators: %s: Platzclub. */
			$meta[] = sprintf( esc_html__( 'bei %s', 'ttleague' ), Html::link( $this->urls->club( $group['host']['slug'] ), $group['host']['short'] ) );
		}
		if ( null !== $group['venue'] ) {
			$address = Format::address( $group['venue']['street'], $group['venue']['zip'], $group['venue']['city'] );
			$meta[]  = esc_html( $group['venue']['name'] ) . ( '' === $address ? '' : ' · ' . Html::link( Format::map_url( $address ), __( 'Karte', 'ttleague' ), array( 'rel' => 'nofollow' ) ) );
		}
		$out = '<article class="ttl-card ttl-rl-group"><h4 class="ttl-card__title">' . esc_html( $title ) . '</h4>';
		if ( array() !== $meta ) {
			$out .= '<p class="ttl-card__meta">' . implode( ' · ', $meta ) . '</p>';
		}
		$note = trim( (string) $group['note'] );
		if ( '' !== $note && ! $group['has_games'] ) {
			$out .= '<p class="ttl-note">' . esc_html( $note ) . '</p>';
		}

		if ( ! $group['has_games'] ) {
			$items = array();
			foreach ( $group['players'] as $member ) {
				// Im Aufgebot einer freigegebenen Runde ist sichtbar, wer sich abgemeldet hat.
				$absent  = 'published' === ( $round['status'] ?? '' ) && 'absent' === $member['status'] ? ' ' . Html::badge( __( 'abgemeldet', 'ttleague' ), 'muted' ) : '';
				$items[] = '<li>' . $this->player_link( $member['player'] ) . ' ' . Html::classification( $member['classification'] ) . ( null === $member['club_short'] ? '' : ' <span class="ttl-muted">' . esc_html( (string) $member['club_short'] ) . '</span>' ) . $absent . '</li>';
			}
			$label = $round['is_final'] ? __( 'Finalisten', 'ttleague' ) : __( 'Aufgebot', 'ttleague' );
			return $out . '<p class="ttl-card__label">' . esc_html( $label ) . '</p>' . ( array() === $items ? Html::notice( __( 'Noch niemand eingeteilt.', 'ttleague' ) ) : '<ul class="ttl-roster">' . implode( '', $items ) . '</ul>' ) . '</article>';
		}

		$rows = array();
		foreach ( $group['players'] as $member ) {
			$ranked    = 'played' === $member['status'];
			$scheduled = 'scheduled' === $member['status'];
			$rows[]    = array(
				'class' => $ranked || $scheduled ? '' : 'is-absent',
				'cells' => array(
					null === $member['rank'] ? '–' : esc_html( $member['rank'] . '.' ),
					$this->player_link( $member['player'] ) . ' ' . Html::classification( $member['classification'] ) . ( $ranked || $scheduled ? '' : ' ' . Html::badge( 'absent' === $member['status'] ? __( 'abwesend', 'ttleague' ) : Format::fixture_status( (string) $member['status'] ), 'muted' ) ),
					$ranked ? esc_html( (string) $member['wins'] ) : '–',
					$ranked ? esc_html( Format::ratio( $member['sets_won'], $member['sets_lost'] ) ) : '–',
					'<strong>' . esc_html( Format::points( $member['points'] ) ) . '</strong>',
				),
			);
		}
		$out .= Html::table(
			array(
				array(
					'label' => __( 'Rang', 'ttleague' ),
					'class' => 'ttl-col-rank',
				),
				array( 'label' => __( 'Spieler', 'ttleague' ) ),
				array(
					'label' => __( 'Siege', 'ttleague' ),
					'class' => 'ttl-num',
				),
				array(
					'label' => __( 'Sätze', 'ttleague' ),
					'class' => 'ttl-num',
				),
				array(
					'label' => __( 'Punkte', 'ttleague' ),
					'abbr'  => __( 'Pkt.', 'ttleague' ),
					'class' => 'ttl-num',
				),
			),
			$rows,
			array(
				/* translators: %s: Gruppe. */
				'caption' => sprintf( __( 'Rangliste %s', 'ttleague' ), $title ),
				'cards'   => false,
				'class'   => 'ttl-table--compact',
			)
		);

		$games = array();
		foreach ( $group['games'] as $game ) {
			$a       = $this->player_link( $game['a'] );
			$b       = $this->player_link( $game['b'] );
			$games[] = '<li>' . ( 'a' === $game['winner'] ? '<strong>' . $a . '</strong>' : $a ) . ' – ' . ( 'b' === $game['winner'] ? '<strong>' . $b . '</strong>' : $b )
				. ' <span class="ttl-games-list__score">' . esc_html( Format::ratio( $game['sets_a'], $game['sets_b'] ) ) . '</span>'
				. ( 'played' === $game['status'] ? '' : ' ' . Html::badge( Format::game_status( $game['status'] ), 'muted' ) ) . '</li>';
		}
		/* translators: %d: Anzahl Partien. */
		$out .= '<details class="ttl-details"><summary>' . esc_html( sprintf( _n( '%d Einzelpartie', '%d Einzelpartien', count( $games ), 'ttleague' ), count( $games ) ) ) . '</summary><ul class="ttl-games-list">' . implode( '', $games ) . '</ul></details>';
		if ( '' !== $note ) {
			$out .= '<p class="ttl-footnote">' . esc_html( $note ) . '</p>';
		}
		return $out . '</article>';
	}

	/**
	 * Gesamtrangliste pro Kategorie, Finalisten markiert.
	 *
	 * @param array<int,list<array<string,mixed>>> $overall   Kategorie => Zeilen.
	 * @param int                                  $finalists Anzahl Finalplätze.
	 * @param int                                  $only      Nur diese Kategorie (0 = alle).
	 * @param list<int>                            $regular   Nummern der regulären Runden (Spalten).
	 */
	private function overall( array $overall, int $finalists, int $only, array $regular ): string {
		if ( array() === $overall ) {
			return Html::notice( __( 'Noch keine gespielte Runde.', 'ttleague' ) );
		}
		$out = '';
		foreach ( $overall as $category => $rows_data ) {
			if ( $only > 0 && $only !== (int) $category ) {
				continue;
			}
			$rows = array();
			foreach ( $rows_data as $row ) {
				$marks = '';
				if ( $row['qualified'] ) {
					$marks .= ' ' . Html::badge( __( 'Final', 'ttleague' ), 'win' );
				}
				if ( $row['needs_draw'] ) {
					$marks .= ' ' . Html::badge( __( 'Los', 'ttleague' ), 'warning' );
				}
				$cells = array(
					esc_html( $row['rank'] . '.' ),
					$this->player_link( $row['player'] ) . ' ' . Html::classification( $row['classification'] ) . $marks,
					esc_html( (string) ( $row['club_short'] ?? '' ) ),
				);
				foreach ( $regular as $r ) {
					$cells[] = array_key_exists( $r, $row['rounds'] ) ? esc_html( Format::points( $row['rounds'][ $r ] ) ) : '<span class="ttl-muted">–</span>';
				}
				$cells[] = '<strong>' . esc_html( Format::points( $row['points'] ) ) . '</strong>';
				$cells[] = esc_html( Format::ratio( $row['sets_won'], $row['sets_lost'] ) );
				$rows[]  = array(
					'class' => $row['qualified'] ? 'is-qualified' : '',
					'cells' => $cells,
				);
			}
			/* translators: %d: Kategorie. */
			$label   = sprintf( __( 'Kategorie %d', 'ttleague' ), $category );
			$columns = array(
				array(
					'label' => __( 'Rang', 'ttleague' ),
					'class' => 'ttl-col-rank',
				),
				array(
					'label'      => __( 'Spieler', 'ttleague' ),
					'primary'    => true,
					'hide_label' => true,
				),
				array( 'label' => __( 'Club', 'ttleague' ) ),
			);
			foreach ( $regular as $r ) {
				$columns[] = array(
					/* translators: %d: Runde. */
					'label' => sprintf( __( '%d. Runde', 'ttleague' ), $r ),
					/* translators: %d: Runde, Kurzform für enge Spalten. */
					'abbr'  => sprintf( __( 'R%d', 'ttleague' ), $r ),
					'class' => 'ttl-num',
				);
			}
			$columns[] = array(
				'label' => __( 'Punkte total', 'ttleague' ),
				'abbr'  => __( 'Total', 'ttleague' ),
				'class' => 'ttl-num ttl-col-points',
			);
			$columns[] = array(
				'label' => __( 'Sätze', 'ttleague' ),
				'class' => 'ttl-num',
			);
			$out      .= '<h3 class="ttl-subtitle">' . esc_html( $label ) . '</h3>' . Html::table(
				$columns,
				$rows,
				/* translators: %s: Kategorie. */
				array( 'caption' => sprintf( __( 'Gesamtrangliste %s', 'ttleague' ), $label ) )
			);
		}
		/* translators: 1: Anzahl reguläre Runden, 2: Anzahl Finalplätze. */
		$out .= '<p class="ttl-footnote">' . esc_html( sprintf( __( 'Punkte der Runden 1 bis %1$d addiert; bei Gleichstand entscheiden Satz- und Ballquotient, sonst das Los. Die besten %2$d jeder Kategorie sind für die Finalrunde qualifiziert.', 'ttleague' ), count( $regular ), $finalists ) ) . '</p>';
		return $out;
	}
}
