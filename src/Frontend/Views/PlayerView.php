<?php
/**
 * Spielerprofil: Klassierungen, Wertungszahl mit Verlauf, Bilanzen, Klassierungsauswertung, alle Spiele.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Frontend\Views;

use TTLeague\Calendar\FeedUrls;
use TTLeague\Calendar\SubscribeBox;
use TTLeague\Domain\Rating\ClassificationScale;
use TTLeague\Frontend\Support\Format;
use TTLeague\Frontend\Support\Html;
use TTLeague\ReadModel\PlayerProfile;

final class PlayerView extends View {

	protected const VIEW = 'player';

	public function render( array $attrs ): string {
		$not_found = $this->not_found();
		if ( null !== $not_found ) {
			return $not_found;
		}
		$license = (int) ( $attrs['player'] ?? 0 );
		$context = $this->own_context();
		$player  = null;
		if ( $license > 0 ) {
			$player = $this->model->players->by_license( $license );
		} elseif ( null !== $context ) {
			$player = $context->object( 'player' );
		}
		if ( null === $player ) {
			return Html::notice( __( 'Dieser Block zeigt den Spieler aus der Adresse (z. B. /spieler/90001-hans-muster/). Für einen festen Spieler die Lizenznummer in den Block-Einstellungen angeben.', 'ttleague' ) );
		}

		$profile = $this->model->profiles->get( $player['id'] );
		$filter  = null === $context ? '' : (string) $context->param( 'season_slug' );
		$games   = '' === $filter ? $profile['games'] : array_values( array_filter( $profile['games'], static fn( array $g ): bool => $g['season_slug'] === $filter ) );

		$out  = $this->facts( $player, $profile );
		$out .= Html::section( __( 'Wertungszahl', 'ttleague' ), $this->rating( $player, $profile ), 'ttl-section--rating', 'ttl-wertungszahl' );
		$out .= Html::section( __( 'Klassierung pro Saison', 'ttleague' ), $this->seasons( $profile['seasons'] ), 'ttl-section--seasons', 'ttl-klassierung' );
		$out .= Html::section( __( 'Bilanz', 'ttleague' ), $this->balance( $profile['games'] ), 'ttl-section--balance', 'ttl-bilanz' );

		$filter_nav = $this->season_filter( $player, $profile['seasons'], $filter );
		$out       .= Html::section( __( 'Klassierungsauswertung', 'ttleague' ), $filter_nav . $this->analysis( $games ), 'ttl-section--analysis', 'ttl-auswertung' );
		$out       .= Html::section( __( 'Alle Spiele', 'ttleague' ), $filter_nav . $this->games_by_season( $games, '' !== $filter ), 'ttl-section--games', 'ttl-alle-spiele' );
		$out       .= $this->subscribe( $player, $profile['seasons'] );
		return $out;
	}

	/**
	 * Kalender-Abo des Spielers, wenn er in der aktiven Saison gemeldet ist.
	 *
	 * @param array<string,mixed>       $player  Spieler.
	 * @param list<array<string,mixed>> $seasons Saisons des Spielers, neueste zuerst.
	 */
	private function subscribe( array $player, array $seasons ): string {
		$active = $this->model->seasons->active();
		if ( null === $active || ( $seasons[0]['season_id'] ?? null ) !== $active['id'] ) {
			return '';
		}
		/* translators: 1: Name des Spielers, 2: Saison. */
		$text = sprintf( __( 'Mit einem Abo erscheinen die Begegnungen aller Teams von %1$s und die Gruppen im Ranglistenturnier der Saison %2$s im eigenen Kalender und werden automatisch nachgeführt.', 'ttleague' ), $player['name'], $active['name'] );
		return SubscribeBox::render( ( new FeedUrls( $this->urls->home() ) )->player( (int) $player['license'] ), $text );
	}

	/**
	 * @param array<string,mixed> $player  Spieler.
	 * @param array<string,mixed> $profile Profil.
	 */
	private function facts( array $player, array $profile ): string {
		$current = $profile['seasons'][0] ?? null;
		$items   = array(
			__( 'Lizenz', 'ttleague' ) => esc_html( (string) $player['license'] ),
		);
		if ( null !== $current ) {
			$items[ __( 'Club', 'ttleague' ) ] = Html::link( $this->urls->club( $current['club_slug'], $current['slug'] ), $current['club_short'] ) . ' <span class="ttl-muted">' . esc_html( $current['name'] ) . '</span>';
			/* translators: %s: Saison. */
			$items[ __( 'Klassierung', 'ttleague' ) ] = Html::classification( $current['classification'] ) . ' <span class="ttl-muted">' . esc_html( sprintf( __( 'offiziell %s', 'ttleague' ), $current['name'] ) ) . '</span>';
			$teams                                    = array();
			foreach ( $current['teams'] as $team ) {
				$label   = $team['name'] . ( 'bound' === $team['kind'] ? ' (' . __( 'gebunden', 'ttleague' ) . ')' : '' );
				$teams[] = Html::link( $this->urls->team( $current['slug'], \TTLeague\ReadModel\Support\Slug::make( $team['name'] ) ), $label );
			}
			$items[ __( 'Mannschaft', 'ttleague' ) ] = implode( ', ', $teams );
		}
		if ( null !== $profile['rating'] ) {
			$items[ __( 'Wertungszahl', 'ttleague' ) ] = '<strong>' . esc_html( Format::rating( $profile['rating']['rating'] ) ) . '</strong>';
		}
		return Html::facts( $items, 'ttl-facts--player' );
	}

	/**
	 * Aktuelle Wertungszahl, Verlaufsgrafik und Tabelle als Alternative.
	 *
	 * @param array<string,mixed> $player  Spieler.
	 * @param array<string,mixed> $profile Profil.
	 */
	private function rating( array $player, array $profile ): string {
		if ( null === $profile['rating'] && array() === $profile['history'] ) {
			return Html::notice( __( 'Für diesen Spieler ist noch keine Wertungszahl berechnet. Sie entsteht aus gewerteten Spielen in Meisterschaft, Cup und Ranglistenturnier.', 'ttleague' ) );
		}
		$out = '';
		if ( null !== $profile['rating'] ) {
			$value    = (float) $profile['rating']['rating'];
			$proposal = ClassificationScale::from_rating( $value, 100.0 );
			/* translators: 1: Wertungszahl, 2: Anzahl Spiele, 3: Klassierung. */
			$text = sprintf( __( 'Aktuell %1$s nach %2$d gewerteten Spielen; das entspricht der Klassierung %3$s.', 'ttleague' ), Format::rating( $value ), (int) $profile['rating']['games'], ClassificationScale::label( $proposal ) );
			$out .= '<p class="ttl-rating"><span class="ttl-rating__value">' . esc_html( Format::rating( $value ) ) . '</span> <span class="ttl-rating__text">' . esc_html( $text ) . '</span></p>';
		}
		if ( array() === $profile['history'] ) {
			return $out . Html::notice( __( 'Noch kein Verlauf vorhanden.', 'ttleague' ) );
		}

		$seasons = $this->model->seasons->all();
		$days    = RatingChart::days( $profile['history'], $seasons );
		$values  = array_column( $days, 'value' );
		$peak    = max( $values );
		$peak_at = $days[ (int) array_search( $peak, $values, true ) ]['date'];
		$first   = $days[0];
		$last    = $days[ count( $days ) - 1 ];
		/* translators: %s: Name des Spielers. */
		$title = sprintf( __( 'Verlauf der Wertungszahl von %s', 'ttleague' ), $player['name'] );
		/* translators: 1: Startdatum, 2: Startwert, 3: Enddatum, 4: Endwert, 5: Höchstwert, 6: Datum des Höchstwerts. */
		$desc  = sprintf( __( 'Von %1$s (%2$s) bis %3$s (%4$s), Höchstwert %5$s am %6$s. Zu jedem Saisonbeginn wird die Wertungszahl auf die neue Klassierung gesetzt; die Saisons sind durch senkrechte Linien getrennt.', 'ttleague' ), Format::date_plain( $first['date'] ), Format::rating( $first['start'] ), Format::date_plain( $last['date'] ), Format::rating( $last['value'] ), Format::rating( $peak ), Format::date_plain( $peak_at ) );
		$parts = RatingChart::parts( $days, 'ttl-rating-' . (int) $player['license'], $title, $desc );
		$chart = str_replace( '{svg}', wp_kses( $parts['svg'], RatingChart::allowed_html() ), $parts['html'] );

		$out .= '<figure class="ttl-chart">' . $chart
			. '<figcaption class="ttl-footnote">' . esc_html__( 'Linie pro Saison; zu Saisonbeginn startet die Wertungszahl bei der neuen Klassierung × 100. Mit der Maus über die Grafik fahren zeigt die Werte pro Spieltag.', 'ttleague' ) . '</figcaption></figure>';

		$rows = array();
		foreach ( array_reverse( $days ) as $day ) {
			$rows[] = array(
				'cells' => array(
					$this->time_tag( $day['date'], Format::date( $day['date'] ) ),
					esc_html( $day['season_name'] ),
					esc_html( (string) $day['games'] ),
					esc_html( Format::delta( $day['delta'] ) ),
					'<strong>' . esc_html( Format::rating( $day['value'] ) ) . '</strong>',
				),
			);
		}
		$table = Html::table(
			array(
				array( 'label' => __( 'Datum', 'ttleague' ) ),
				array( 'label' => __( 'Saison', 'ttleague' ) ),
				array(
					'label' => __( 'Spiele', 'ttleague' ),
					'class' => 'ttl-num',
				),
				array(
					'label' => __( 'Veränderung', 'ttleague' ),
					'class' => 'ttl-num',
				),
				array(
					'label' => __( 'Wertungszahl', 'ttleague' ),
					'class' => 'ttl-num',
				),
			),
			$rows,
			array(
				'caption' => __( 'Wertungszahl pro Spieltag', 'ttleague' ),
				'cards'   => false,
				'class'   => 'ttl-table--compact',
			)
		);
		return $out . '<details class="ttl-details"><summary>' . esc_html__( 'Verlauf als Tabelle', 'ttleague' ) . '</summary>' . Html::scroll( __( 'Wertungszahl pro Spieltag', 'ttleague' ), $table ) . '</details>';
	}

	/**
	 * @param list<array<string,mixed>> $seasons Saisons des Spielers.
	 */
	private function seasons( array $seasons ): string {
		if ( array() === $seasons ) {
			return Html::notice( __( 'Keine Saison gemeldet.', 'ttleague' ) );
		}
		$rows = array();
		foreach ( $seasons as $season ) {
			$teams = array();
			foreach ( $season['teams'] as $team ) {
				$teams[] = Html::link( $this->urls->team( $season['slug'], \TTLeague\ReadModel\Support\Slug::make( $team['name'] ) ), $team['name'] ) . ( 'bound' === $team['kind'] ? ' <span class="ttl-muted">(' . esc_html__( 'gebunden', 'ttleague' ) . ')</span>' : '' );
			}
			$rows[] = array(
				'cells' => array(
					esc_html( $season['name'] ),
					Html::link( $this->urls->club( $season['club_slug'], $season['slug'] ), $season['club_short'] ),
					Html::classification( $season['classification'] ),
					implode( ', ', $teams ),
				),
			);
		}
		return Html::table(
			array(
				array(
					'label'      => __( 'Saison', 'ttleague' ),
					'primary'    => true,
					'hide_label' => true,
				),
				array( 'label' => __( 'Club', 'ttleague' ) ),
				array(
					'label' => __( 'Klassierung', 'ttleague' ),
					'class' => 'ttl-num',
				),
				array( 'label' => __( 'Mannschaft', 'ttleague' ) ),
			),
			$rows,
			array( 'caption' => __( 'Klassierung pro Saison', 'ttleague' ) )
		);
	}

	/**
	 * Bilanz pro Saison und Konkurrenz (nur gespielte Spiele).
	 *
	 * @param list<array<string,mixed>> $games Alle Spiele.
	 */
	private function balance( array $games ): string {
		$rows = array();
		foreach ( PlayerProfile::balance( $games ) as $row ) {
			$rows[] = array(
				'cells' => array(
					esc_html( $row['season_name'] ),
					'<abbr title="' . esc_attr( Format::kind( $row['kind'] ) ) . '">' . esc_html( Format::kind_short( $row['kind'] ) ) . '</abbr>',
					esc_html( Format::ratio( $row['singles_won'], $row['singles_lost'] ) ),
					'RL' === $row['kind'] ? '<span class="ttl-muted">–</span>' : esc_html( Format::ratio( $row['doubles_won'], $row['doubles_lost'] ) ),
					esc_html( Format::ratio( $row['sets_won'], $row['sets_lost'] ) ),
				),
			);
		}
		if ( array() === $rows ) {
			return Html::notice( __( 'Noch keine gespielten Spiele.', 'ttleague' ) );
		}
		return Html::table(
			array(
				array( 'label' => __( 'Saison', 'ttleague' ) ),
				array( 'label' => __( 'Konkurrenz', 'ttleague' ) ),
				array(
					'label' => __( 'Einzel (Siege:Niederlagen)', 'ttleague' ),
					'abbr'  => __( 'Einzel', 'ttleague' ),
					'class' => 'ttl-num',
				),
				array(
					'label' => __( 'Doppel (Siege:Niederlagen)', 'ttleague' ),
					'abbr'  => __( 'Doppel', 'ttleague' ),
					'class' => 'ttl-num',
				),
				array(
					'label' => __( 'Sätze', 'ttleague' ),
					'class' => 'ttl-num',
				),
			),
			$rows,
			array(
				'caption' => __( 'Bilanz pro Saison und Konkurrenz', 'ttleague' ),
				'cards'   => false,
				'class'   => 'ttl-table--compact ttl-balance',
			)
		) . '<p class="ttl-footnote">' . esc_html__( 'Einzel und Doppel als Siege:Niederlagen. Gezählt werden gespielte Spiele; kampflose (w.o.) und nicht gespielte erscheinen nur in der Spielliste. MM = Meisterschaft, RL = Ranglistenturnier.', 'ttleague' ) . '</p>';
	}

	/**
	 * Bilanz im Einzel gegen jede Gegner-Klassierung.
	 *
	 * @param list<array<string,mixed>> $games Spiele (gefiltert).
	 */
	private function analysis( array $games ): string {
		$rows = array();
		foreach ( PlayerProfile::classification_analysis( $games ) as $row ) {
			$total  = $row['won'] + $row['lost'];
			$share  = 0 === $total ? 0 : (int) round( 100 * $row['won'] / $total );
			$rows[] = array(
				'cells' => array(
					Html::classification( $row['classification'] ),
					esc_html( (string) $row['won'] ),
					esc_html( (string) $row['lost'] ),
					'<span class="ttl-meter" aria-hidden="true"><span class="ttl-meter__fill" style="width:' . esc_attr( (string) $share ) . '%"></span></span> ' . esc_html( $share . ' %' ),
				),
			);
		}
		if ( array() === $rows ) {
			return Html::notice( __( 'Keine gespielten Einzel mit bekannter Gegner-Klassierung.', 'ttleague' ) );
		}
		return Html::table(
			array(
				array(
					'label' => __( 'Gegner-Klassierung', 'ttleague' ),
					'class' => 'ttl-col-class',
				),
				array(
					'label' => __( 'Siege', 'ttleague' ),
					'class' => 'ttl-num',
				),
				array(
					'label' => __( 'Niederlagen', 'ttleague' ),
					'class' => 'ttl-num',
				),
				array(
					'label' => __( 'Siegquote', 'ttleague' ),
					'class' => 'ttl-col-share',
				),
			),
			$rows,
			array(
				'caption' => __( 'Bilanz im Einzel nach Klassierung der Gegner', 'ttleague' ),
				'cards'   => false,
				'class'   => 'ttl-table--compact ttl-analysis',
			)
		);
	}

	/**
	 * Saisonfilter als Linkliste.
	 *
	 * @param array<string,mixed>       $player  Spieler.
	 * @param list<array<string,mixed>> $seasons Saisons des Spielers.
	 */
	private function season_filter( array $player, array $seasons, string $current ): string {
		if ( count( $seasons ) < 2 || null === $this->own_context() ) {
			return '';
		}
		$items = array( '<li>' . Html::link( $this->urls->player( (int) $player['license'], $player['slug'] ) . '#ttl-alle-spiele', __( 'Alle Saisons', 'ttleague' ), '' === $current ? array( 'aria-current' => 'true' ) : array() ) . '</li>' );
		foreach ( $seasons as $season ) {
			$items[] = '<li>' . Html::link( $this->urls->player( (int) $player['license'], $player['slug'], $season['slug'] ) . '#ttl-alle-spiele', $season['name'], $current === $season['slug'] ? array( 'aria-current' => 'true' ) : array() ) . '</li>';
		}
		return '<nav class="ttl-tabs ttl-tabs--small" aria-label="' . esc_attr__( 'Saisonfilter', 'ttleague' ) . '"><ul>' . implode( '', $items ) . '</ul></nav>';
	}

	/**
	 * Spiele nach Saison gruppiert; die neueste Saison ist aufgeklappt.
	 *
	 * @param list<array<string,mixed>> $games    Spiele (neueste zuerst).
	 * @param bool                      $filtered Nur eine Saison gewählt: ohne Gruppierung.
	 */
	private function games_by_season( array $games, bool $filtered ): string {
		if ( $filtered || array() === $games ) {
			return $this->games( $games ) . ( array() === $games ? '' : '<p class="ttl-footnote">' . esc_html__( 'S = Sieg, N = Niederlage; Resultat in Sätzen aus Sicht des Spielers.', 'ttleague' ) . '</p>' );
		}
		$by_season = array();
		foreach ( $games as $game ) {
			$by_season[ $game['season_name'] ][] = $game;
		}
		$out   = '';
		$first = true;
		foreach ( $by_season as $name => $season_games ) {
			/* translators: %d: Anzahl Spiele. */
			$count = sprintf( _n( '%d Spiel', '%d Spiele', count( $season_games ), 'ttleague' ), count( $season_games ) );
			$out  .= '<details class="ttl-details ttl-details--season"' . ( $first ? ' open' : '' ) . '><summary>' . esc_html__( 'Saison', 'ttleague' ) . ' ' . esc_html( (string) $name ) . ' <span class="ttl-details__count">' . esc_html( $count ) . '</span></summary>' . $this->games( $season_games ) . '</details>';
			$first = false;
		}
		return $out . '<p class="ttl-footnote">' . esc_html__( 'S = Sieg, N = Niederlage; Resultat in Sätzen aus Sicht des Spielers.', 'ttleague' ) . '</p>';
	}

	/**
	 * Alle Spiele, neueste zuerst.
	 *
	 * @param list<array<string,mixed>> $games Spiele (gefiltert).
	 */
	private function games( array $games ): string {
		if ( array() === $games ) {
			return Html::notice( __( 'Keine Spiele in dieser Auswahl.', 'ttleague' ) );
		}
		$rows = array();
		foreach ( $games as $game ) {
			$opponents = array();
			$classes   = array();
			foreach ( $game['opponents'] as $opponent ) {
				$opponents[] = $this->player_link( $opponent );
				$classes[]   = Html::classification( $opponent['classification'] );
			}
			$opponent_html = array() === $opponents ? '<span class="ttl-muted">' . esc_html__( '(kein Gegner)', 'ttleague' ) . '</span>' : implode( ' / ', $opponents );
			if ( null !== $game['partner'] ) {
				/* translators: %s: Doppelpartner. */
				$opponent_html .= '<br><span class="ttl-muted">' . sprintf( esc_html__( 'mit %s', 'ttleague' ), $this->player_link( $game['partner'] ) ) . '</span>';
			}
			$result = 'not_played' === $game['status'] ? '–' : Format::ratio( $game['sets_for'], $game['sets_against'] );
			$badge  = '';
			if ( null !== $game['won'] ) {
				$badge = ' ' . Html::badge( $game['won'] ? __( 'S', 'ttleague' ) : __( 'N', 'ttleague' ), $game['won'] ? 'win' : 'loss' );
			}
			$note = Format::game_status( $game['status'] );
			if ( '' !== $note ) {
				$badge .= ' ' . Html::badge( $note, 'muted' );
			}
			$result_html = '<strong>' . esc_html( $result ) . '</strong>' . $badge;
			if ( null !== $game['fixture_id'] ) {
				$result_html = Html::link_html( $this->urls->fixture( (int) $game['fixture_id'] ), '<strong>' . esc_html( $result ) . '</strong>', array( 'aria-label' => __( 'Matchreport', 'ttleague' ) . ' ' . $result ) ) . $badge;
			}
			$rows[] = array(
				'class' => null === $game['won'] ? '' : ( $game['won'] ? 'is-win' : 'is-loss' ),
				'cells' => array(
					$this->time_tag( $game['date'], Format::date( $game['date'] ) ),
					$this->competition( $game ),
					$opponent_html,
					implode( ' ', array_filter( $classes ) ),
					$result_html,
				),
			);
		}
		return Html::table(
			array(
				array(
					'label' => __( 'Datum', 'ttleague' ),
					'class' => 'ttl-col-date',
				),
				array( 'label' => __( 'Konkurrenz', 'ttleague' ) ),
				array(
					'label'   => __( 'Gegner', 'ttleague' ),
					'primary' => true,
				),
				array(
					'label' => __( 'Klassierung Gegner', 'ttleague' ),
					'abbr'  => __( 'Kl.', 'ttleague' ),
					'class' => 'ttl-num',
				),
				array(
					'label' => __( 'Resultat', 'ttleague' ),
					'class' => 'ttl-col-result',
				),
			),
			$rows,
			array(
				'caption' => __( 'Spiele, neueste zuerst', 'ttleague' ),
				'class'   => 'ttl-player-games',
			)
		);
	}

	/**
	 * Konkurrenz eines Spiels mit Link.
	 *
	 * @param array<string,mixed> $game Spiel.
	 */
	private function competition( array $game ): string {
		$season = $game['season_slug'];
		if ( 'RL' === $game['kind'] ) {
			if ( $game['rl_is_final'] ) {
				$label = __( 'RL Final', 'ttleague' );
			} else {
				/* translators: 1: Runde, 2: Kategorie. */
				$label = sprintf( __( 'RL %1$d. Runde, Kat. %2$d', 'ttleague' ), (int) $game['rl_round_no'], (int) $game['rl_category'] );
			}
			return Html::link( $this->urls->ranking( $season ), $label );
		}
		if ( 'CUP' === $game['kind'] ) {
			return Html::link( $this->urls->cup( $season, (string) $game['category'] ), $game['competition'] );
		}
		$slug = \TTLeague\ReadModel\Championship::group_slug( $game['category'], (int) $game['group_no'], 0 );
		return Html::link( $this->urls->group( $season, $slug ), $game['competition'] ) . ( 'double' === $game['type'] ? ' <span class="ttl-muted">' . esc_html__( 'Doppel', 'ttleague' ) . '</span>' : '' );
	}
}
