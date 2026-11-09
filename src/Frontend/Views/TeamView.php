<?php
/**
 * Mannschaftsseite: Spielplan, Stammspieler, Einsatzmatrix, Captain, Gruppe, Heimlokal.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Frontend\Views;

use TTLeague\Calendar\FeedUrls;
use TTLeague\Calendar\SubscribeBox;
use TTLeague\Frontend\Support\Format;
use TTLeague\Frontend\Support\Html;
use TTLeague\ReadModel\Teams;

final class TeamView extends View {

	protected const VIEW = 'team';

	public function render( array $attrs ): string {
		$not_found = $this->not_found();
		if ( null !== $not_found ) {
			return $not_found;
		}
		$season = $this->season( $attrs );
		if ( null === $season ) {
			return $this->no_season();
		}
		$slug    = trim( (string) ( $attrs['team'] ?? '' ) );
		$context = $this->own_context();
		if ( '' === $slug && null !== $context ) {
			$slug = (string) $context->param( 'team' );
		}
		$team = '' === $slug ? null : $this->model->championship->team_by_slug( $season['id'], $slug );
		if ( null === $team ) {
			return Html::notice( __( 'Dieser Block zeigt die Mannschaft aus der Adresse (z. B. /mannschaft/2026-27/seefeld-1/). Für eine feste Mannschaft das Kürzel in den Block-Einstellungen angeben.', 'ttleague' ) );
		}

		$data    = $this->model->championship->season( $season['id'] );
		$details = $this->model->teams->details( $season['id'], $team['id'] );
		$group   = null === $team['competition_id'] ? null : $this->model->championship->group_by_id( $season['id'], $team['competition_id'] );

		$captains = array();
		foreach ( $details['captains'] as $captain ) {
			$captains[] = null === $captain['player'] ? esc_html( $captain['name'] ) : $this->player_link( $captain['player'] );
		}
		$venue = '';
		if ( null !== $details['venue'] ) {
			$address = Format::address( $details['venue']['street'], $details['venue']['zip'], $details['venue']['city'] );
			$venue   = esc_html( $details['venue']['name'] );
			if ( '' !== $address ) {
				$venue .= '<br><span class="ttl-muted">' . esc_html( $address ) . '</span> · ' . Html::link( Format::map_url( $address ), __( 'Karte', 'ttleague' ), array( 'rel' => 'nofollow' ) );
			}
		}
		$home_day = '';
		if ( null !== $details['venue'] && null !== $details['venue']['weekday'] ) {
			$home_day = Format::weekday( $details['venue']['weekday'] );
		}
		$start = Format::time( (string) $team['default_start_time'] );
		if ( '' !== $start ) {
			$home_day = trim( $home_day . ', ' . $start, ', ' );
		}
		$rank = '';
		if ( null !== $group ) {
			foreach ( $group['standings'] as $row ) {
				if ( $row['team_id'] === $team['id'] && $group['played'] > 0 ) {
					/* translators: 1: Rang, 2: Punkte. */
					$rank = esc_html( sprintf( __( '%1$d. Rang, %2$d Punkte', 'ttleague' ), $row['rank'], $row['points'] ) );
				}
			}
		}

		/* translators: %s: Saison. */
		$out  = $this->kicker( $this->urls->championship( $season['slug'] ), sprintf( __( 'Meisterschaft %s', 'ttleague' ), $season['name'] ) );
		$out .= Html::facts(
			array(
				__( 'Club', 'ttleague' )       => Html::link( $this->urls->club( $team['club_slug'], $season['slug'] ), $team['club_name'] ),
				__( 'Gruppe', 'ttleague' )     => null === $group ? '' : Html::link( $this->urls->group( $season['slug'], $group['slug'] ), $group['name'] ),
				__( 'Tabelle', 'ttleague' )    => $rank,
				__( 'Captain', 'ttleague' )    => implode( ', ', $captains ),
				__( 'Heimlokal', 'ttleague' )  => $venue,
				__( 'Heimspiele', 'ttleague' ) => esc_html( $home_day ),
			)
		);

		$fixtures = $this->model->championship->team_fixtures( $season['id'], $team['id'] );
		/* translators: %s: Mannschaft. */
		$caption = sprintf( __( 'Spielplan und Resultate %s', 'ttleague' ), $team['name'] );
		$out    .= Html::section( __( 'Spielplan und Resultate', 'ttleague' ), ( new Schedule( $this->urls ) )->render( $fixtures, $data['teams'], $season['slug'], $team['id'], $caption ), 'ttl-section--schedule', 'ttl-spielplan' );
		$out    .= $this->subscribe( $season, $team );
		$out    .= Html::section( __( 'Stammspieler', 'ttleague' ), $this->roster( $details['roster'] ), 'ttl-section--roster', 'ttl-stammspieler' );
		$out    .= Html::section( __( 'Einsätze', 'ttleague' ), $this->matrix( $details['matrix'], $data['teams'], $team ), 'ttl-section--matrix', 'ttl-einsaetze' );
		return $out;
	}

	/**
	 * Kalender-Abo der Mannschaft; nicht für archivierte Saisons.
	 *
	 * @param array<string,mixed> $season Saison.
	 * @param array<string,mixed> $team   Mannschaft.
	 */
	private function subscribe( array $season, array $team ): string {
		if ( 'archived' === $season['status'] ) {
			return '';
		}
		/* translators: %s: Mannschaft. */
		$text = sprintf( __( 'Mit einem Abo erscheinen alle Spiele von %s im eigenen Kalender und werden bei Verschiebungen und neuen Resultaten automatisch nachgeführt.', 'ttleague' ), $team['name'] );
		return SubscribeBox::render( ( new FeedUrls( $this->urls->home() ) )->team( $season['slug'], $team['slug'] ), $text );
	}

	/**
	 * @param list<array<string,mixed>> $roster Stammspieler und gebundene Spieler.
	 */
	private function roster( array $roster ): string {
		$regular = array();
		$bound   = array();
		foreach ( $roster as $entry ) {
			$item = '<li>' . $this->player_link( $entry['player'] ) . ' ' . Html::classification( $entry['classification'] ) . '</li>';
			if ( 'bound' === $entry['kind'] ) {
				$bound[] = $item;
			} else {
				$regular[] = $item;
			}
		}
		if ( array() === $regular && array() === $bound ) {
			return Html::notice( __( 'Keine Stammspieler gemeldet.', 'ttleague' ) );
		}
		$out = '<ul class="ttl-roster">' . implode( '', $regular ) . '</ul>';
		if ( array() !== $bound ) {
			$out .= '<h3 class="ttl-subtitle">' . esc_html__( 'Durch Ersatzeinsätze gebunden', 'ttleague' ) . '</h3><ul class="ttl-roster">' . implode( '', $bound ) . '</ul>';
		}
		return $out;
	}

	/**
	 * Einsatzmatrix: pro gespielte Begegnung S, E oder –.
	 *
	 * @param array<string,mixed>            $matrix Matrix aus dem Lesemodell.
	 * @param array<int,array<string,mixed>> $teams  Teams der Saison.
	 * @param array<string,mixed>            $team   Dieses Team.
	 */
	private function matrix( array $matrix, array $teams, array $team ): string {
		if ( array() === $matrix['fixtures'] ) {
			return Html::notice( __( 'Noch keine Einsätze.', 'ttleague' ) );
		}
		$head = '<th scope="col" class="ttl-matrix__name">' . esc_html__( 'Spieler', 'ttleague' ) . '</th><th scope="col" class="ttl-num"><abbr title="' . esc_attr__( 'Klassierung', 'ttleague' ) . '">' . esc_html__( 'Kl.', 'ttleague' ) . '</abbr></th>';
		foreach ( $matrix['fixtures'] as $fixture ) {
			$other_id = $fixture['home_team_id'] === $team['id'] ? $fixture['away_team_id'] : $fixture['home_team_id'];
			$other    = $teams[ $other_id ]['name'] ?? '';
			$label    = Format::date_short( $fixture['scheduled_at'] );
			/* translators: 1: Datum, 2: Gegner. */
			$title = sprintf( __( '%1$s gegen %2$s', 'ttleague' ), Format::date( $fixture['scheduled_at'] ), $other );
			$head .= '<th scope="col" class="ttl-matrix__col">' . Html::link(
				$this->urls->fixture( (int) $fixture['id'] ),
				$label,
				array(
					'title'      => $title,
					'aria-label' => $title,
				)
			) . '</th>';
		}
		$head .= '<th scope="col" class="ttl-num"><abbr title="' . esc_attr__( 'Einsätze als Stammspieler', 'ttleague' ) . '">S</abbr></th><th scope="col" class="ttl-num"><abbr title="' . esc_attr__( 'Einsätze als Ersatz', 'ttleague' ) . '">E</abbr></th>';

		$body = '';
		foreach ( $matrix['rows'] as $row ) {
			$body .= '<tr' . ( $row['is_roster'] ? '' : ' class="is-substitute"' ) . '><th scope="row">' . $this->player_link( $row['player'] ) . '</th><td class="ttl-num">' . Html::classification( $row['classification'] ) . '</td>';
			foreach ( $row['marks'] as $mark ) {
				$text  = Teams::MARK_NONE === $mark ? '–' : $mark;
				$title = Teams::MARK_REGULAR === $mark ? __( 'Stammspieler', 'ttleague' ) : ( Teams::MARK_SUBSTITUTE === $mark ? __( 'Ersatz', 'ttleague' ) : __( 'nicht eingesetzt', 'ttleague' ) );
				$body .= '<td class="ttl-mark ttl-mark--' . esc_attr( strtolower( Teams::MARK_NONE === $mark ? 'none' : $mark ) ) . '"><span aria-hidden="true">' . esc_html( $text ) . '</span><span class="screen-reader-text">' . esc_html( $title ) . '</span></td>';
			}
			$body .= '<td class="ttl-num">' . (int) $row['regular'] . '</td><td class="ttl-num">' . (int) $row['substitute'] . '</td></tr>';
		}
		/* translators: %s: Mannschaft. */
		$caption = sprintf( __( 'Einsätze %s pro Begegnung', 'ttleague' ), $team['name'] );
		$table   = '<table class="ttl-table ttl-matrix"><caption class="screen-reader-text">' . esc_html( $caption ) . '</caption><thead><tr>' . $head . '</tr></thead><tbody>' . $body . '</tbody></table>';
		$legend  = '<p class="ttl-footnote">' . esc_html__( 'S = Stammspieler, E = Ersatz, – = nicht eingesetzt. Spalten: gespielte Begegnungen mit Aufstellung, Link zum Matchreport.', 'ttleague' ) . '</p>';
		return Html::scroll( $caption, $table ) . $legend;
	}
}
