<?php
/**
 * Tabellen der Meisterschaft (voll und kompakt).
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Frontend\Views;

use TTLeague\Frontend\Support\Format;
use TTLeague\Frontend\Support\Html;
use TTLeague\Frontend\Support\Urls;

/**
 * Gemeinsame Darstellung für Saisonübersicht, Gruppe, Startseite und Archiv.
 */
final class Standings {

	public function __construct( private readonly Urls $urls ) {}

	/**
	 * Volle Tabelle: Rang, Team, Begegnungen, S/U/N, Spiele, Sätze, Punkte.
	 *
	 * @param array<string,mixed>            $group  Gruppe mit standings.
	 * @param array<int,array<string,mixed>> $teams  Teams der Saison.
	 * @param string                         $season Saison-Slug.
	 */
	public function full( array $group, array $teams, string $season ): string {
		$rows = array();
		foreach ( $group['standings'] as $row ) {
			$team   = $teams[ $row['team_id'] ] ?? null;
			$rows[] = array(
				'class' => $row['shared'] ? 'is-shared' : '',
				'cells' => array(
					$this->rank( $row ),
					null === $team ? '' : Html::link( $this->urls->team( $season, $team['slug'] ), $team['name'] ),
					(string) $row['played'],
					$row['wins'] . '/' . $row['draws'] . '/' . $row['losses'],
					Format::ratio( $row['games_won'], $row['games_lost'] ),
					Format::ratio( $row['sets_won'], $row['sets_lost'] ),
					'<strong>' . (int) $row['points'] . '</strong>',
				),
			);
		}
		$columns = array(
			array(
				'label' => __( 'Rang', 'ttleague' ),
				'class' => 'ttl-col-rank',
			),
			array(
				'label'      => __( 'Team', 'ttleague' ),
				'class'      => 'ttl-col-team',
				'primary'    => true,
				'hide_label' => true,
			),
			array(
				'label' => __( 'Begegnungen', 'ttleague' ),
				'abbr'  => __( 'Beg.', 'ttleague' ),
				'class' => 'ttl-num',
			),
			array(
				'label' => __( 'Siege/Unentschieden/Niederlagen', 'ttleague' ),
				'abbr'  => __( 'S/U/N', 'ttleague' ),
				'card'  => __( 'S/U/N', 'ttleague' ),
				'class' => 'ttl-num',
			),
			array(
				'label' => __( 'Spiele', 'ttleague' ),
				'class' => 'ttl-num',
			),
			array(
				'label' => __( 'Sätze', 'ttleague' ),
				'class' => 'ttl-num',
			),
			array(
				'label' => __( 'Punkte', 'ttleague' ),
				'abbr'  => __( 'Pkt.', 'ttleague' ),
				'class' => 'ttl-num ttl-col-points',
			),
		);
		/* translators: %s: Gruppe. */
		$caption = sprintf( __( 'Tabelle %s', 'ttleague' ), $group['name'] );
		return Html::table(
			$columns,
			$rows,
			array(
				'caption' => $caption,
				'class'   => 'ttl-standings',
			)
		) . $this->notes( $group );
	}

	/**
	 * Kompakte Tabelle: Rang, Team, Begegnungen, Spiele, Punkte (ohne Kartenansicht).
	 *
	 * @param array<string,mixed>            $group   Gruppe mit standings.
	 * @param array<int,array<string,mixed>> $teams   Teams der Saison.
	 * @param string                         $season  Saison-Slug.
	 * @param bool                           $minimal Nur Rang, Team, Punkte.
	 */
	public function compact( array $group, array $teams, string $season, bool $minimal = false ): string {
		$rows = array();
		foreach ( $group['standings'] as $row ) {
			$team  = $teams[ $row['team_id'] ] ?? null;
			$cells = array(
				$this->rank( $row ),
				null === $team ? '' : Html::link( $this->urls->team( $season, $team['slug'] ), $team['name'] ),
			);
			if ( ! $minimal ) {
				$cells[] = (string) $row['played'];
				$cells[] = Format::ratio( $row['games_won'], $row['games_lost'] );
			}
			$cells[] = '<strong>' . (int) $row['points'] . '</strong>';
			$rows[]  = array( 'cells' => $cells );
		}
		$columns = array(
			array(
				'label' => __( 'Rang', 'ttleague' ),
				'class' => 'ttl-col-rank',
			),
			array(
				'label' => __( 'Team', 'ttleague' ),
				'class' => 'ttl-col-team',
			),
		);
		if ( ! $minimal ) {
			$columns[] = array(
				'label' => __( 'Begegnungen', 'ttleague' ),
				'abbr'  => __( 'Beg.', 'ttleague' ),
				'class' => 'ttl-num',
			);
			$columns[] = array(
				'label' => __( 'Spiele', 'ttleague' ),
				'class' => 'ttl-num',
			);
		}
		$columns[] = array(
			'label' => __( 'Punkte', 'ttleague' ),
			'abbr'  => __( 'Pkt.', 'ttleague' ),
			'class' => 'ttl-num ttl-col-points',
		);
		/* translators: %s: Gruppe. */
		$caption = sprintf( __( 'Tabelle %s', 'ttleague' ), $group['name'] );
		return Html::table(
			$columns,
			$rows,
			array(
				'caption' => $caption,
				'cards'   => false,
				'class'   => 'ttl-table--compact ttl-standings',
			)
		);
	}

	/**
	 * Rang mit Kennzeichnung geteilter Ränge.
	 *
	 * @param array<string,mixed> $row Tabellenzeile.
	 */
	private function rank( array $row ): string {
		$html = esc_html( $row['rank'] . '.' );
		if ( $row['shared'] ) {
			$html .= '<span class="ttl-shared" title="' . esc_attr__( 'geteilter Rang', 'ttleague' ) . '">*</span>';
		}
		return $html;
	}

	/**
	 * Fussnoten: geteilte Ränge (Hinweis aus dem Tabellenrechner) und provisorische Resultate.
	 *
	 * @param array<string,mixed> $group Gruppe.
	 */
	private function notes( array $group ): string {
		$notes = array();
		if ( $group['played'] > 0 ) {
			foreach ( $group['standings'] as $row ) {
				if ( $row['shared'] && null !== $row['tiebreak_note'] ) {
					$notes[] = '* ' . esc_html__( 'Geteilter Rang:', 'ttleague' ) . ' ' . esc_html( (string) $row['tiebreak_note'] );
					break;
				}
			}
		}
		if ( $group['has_provisional'] ) {
			$notes[] = esc_html__( 'Provisorische Resultate sind eingerechnet und im Spielplan markiert.', 'ttleague' );
		}
		if ( 0 === $group['played'] ) {
			$notes[] = esc_html__( 'Noch keine Begegnung gespielt.', 'ttleague' );
		}
		return array() === $notes ? '' : '<p class="ttl-footnote">' . implode( '<br>', $notes ) . '</p>';
	}
}
