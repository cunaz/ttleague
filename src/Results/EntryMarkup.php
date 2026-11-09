<?php
/**
 * HTML der Erfassungsseite: Kopf, Formular, Gastseite, Entscheid der TK, Verlauf.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Results;

use TTLeague\Domain\Match\FixtureWorkflow as Flow;
use TTLeague\Frontend\Support\Format;

/**
 * Rendert die Seite für eine Person mit Rolle. Dynamische Teile tragen Direktiven der
 * Interactivity API (Namensraum ttleague/entry) und zugleich ihren Startwert aus dem
 * Ansichtsmodell, damit die Seite auch ohne Skript den Zustand richtig zeigt. Alle Texte
 * werden escaped; JSON in Attributen über esc_attr().
 */
final class EntryMarkup {

	public const NS = 'ttleague/entry';

	/**
	 * @param array<string,mixed> $entry  Antwort von ResultService::entry().
	 * @param array<string,mixed> $data   Daten aus EntryForm::data().
	 * @param array<string,mixed> $state  Startzustand (form, view, message, photo …).
	 * @param array<string,mixed> $page   report (Adresse des Matchreports), login, decisions (erlaubte Entscheide der TK).
	 * @param \DateTimeZone       $timezone Zeitzone der Website (für Zeitstempel in UTC).
	 */
	public function __construct(
		private readonly array $entry,
		private readonly array $data,
		private readonly array $state,
		private readonly array $page,
		private readonly \DateTimeZone $timezone,
	) {}

	/**
	 * Ganze Seite (innerhalb des Block-Wrappers).
	 */
	public function render(): string {
		$can = $this->entry['can'];
		$out = '<div class="ttl-entry" data-wp-interactive="' . esc_attr( self::NS ) . '" data-wp-init="callbacks.init" data-wp-watch="callbacks.persist">'
			. $this->head()
			. '<noscript><p class="ttl-notice ttl-notice--warning">' . esc_html__( 'Bitte JavaScript aktivieren: Erfassen, Bestätigen und Entscheide laufen über die Schnittstelle der Website.', 'ttleague' ) . '</p></noscript>'
			. $this->live()
			. $this->status()
			. $this->warnings();
		if ( $this->has_result() ) {
			$out .= $this->result();
		}
		if ( in_array( Flow::ROLE_AWAY, $this->entry['roles'], true ) && ( $can['confirm'] || $can['object'] ) ) {
			$out .= $this->guest();
		}
		if ( $can['decide'] ) {
			$out .= $this->decision();
		}
		if ( $can['enter'] || in_array( 'correct', (array) $this->page['decisions'], true ) ) {
			$out .= $this->form();
		}
		return $out . $this->history() . '</div>';
	}

	/**
	 * Hinweis für abgemeldete Besucher mit Link zur Anmeldung.
	 */
	public static function logged_out( string $login_url ): string {
		return '<div class="ttl-entry ttl-entry--gate">'
			. '<p class="ttl-notice">' . esc_html__( 'Resultate erfassen und bestätigen Captains und TK-Chefs der beiden Mannschaften sowie die TK. Bitte melde dich an.', 'ttleague' ) . '</p>'
			. '<p class="ttl-actions"><a class="ttl-button ttl-button--primary" href="' . esc_url( $login_url ) . '">' . esc_html__( 'Anmelden', 'ttleague' ) . '</a></p>'
			. '</div>';
	}

	/**
	 * Hinweis mit Wegweisern (keine Begegnung, keine Rolle, nicht gefunden).
	 *
	 * @param array<string,string> $links Beschriftung => Adresse.
	 */
	public static function notice( string $message, array $links, string $variant = 'warning' ): string {
		$items = '';
		foreach ( $links as $label => $url ) {
			$items .= '<li><a href="' . esc_url( $url ) . '">' . esc_html( $label ) . '</a></li>';
		}
		return '<div class="ttl-entry ttl-entry--gate"><p class="ttl-notice ttl-notice--' . esc_attr( $variant ) . '" role="status">' . esc_html( $message ) . '</p>'
			. ( '' === $items ? '' : '<ul class="ttl-inline-list">' . $items . '</ul>' ) . '</div>';
	}

	private function head(): string {
		$fixture = $this->entry['fixture'];
		$kicker  = Format::kind( (string) $fixture['type'] ) . ' · ' . $fixture['competition'] . ' · ' . str_replace( '-', '/', (string) $fixture['season'] );
		$date    = Format::datetime( $fixture['scheduled_at'] );
		$meta    = '' === $date ? '' : '<time datetime="' . esc_attr( str_replace( ' ', 'T', substr( (string) $fixture['scheduled_at'], 0, 16 ) ) ) . '">' . esc_html( $date ) . '</time> ';
		$meta   .= '<span class="ttl-badge ttl-entry__status ttl-entry__status--' . esc_attr( (string) $fixture['status'] ) . '">' . esc_html( (string) $fixture['status_label'] ) . '</span>';
		if ( null !== $fixture['home_score'] && null !== $fixture['away_score'] ) {
			/* translators: %s: Resultat, z. B. 6:4 */
			$meta .= ' <span class="ttl-entry__score">' . esc_html( sprintf( __( 'Resultat %s', 'ttleague' ), $fixture['home_score'] . ':' . $fixture['away_score'] ) ) . '</span>';
		}
		return '<header class="ttl-entry__head">'
			. '<p class="ttl-kicker">' . esc_html( $kicker ) . '</p>'
			. '<h2 class="ttl-entry__title">' . esc_html( $fixture['home']['name'] . ' – ' . $fixture['away']['name'] ) . '</h2>'
			. '<p class="ttl-entry__meta">' . $meta . '</p>'
			. '<p class="ttl-entry__links"><a href="' . esc_url( (string) $this->page['report'] ) . '">' . esc_html__( 'Zum Matchreport', 'ttleague' ) . '</a></p>'
			. '</header>';
	}

	/**
	 * Meldungen nach einer Aktion (Live-Region, bleibt immer im Dokument).
	 */
	private function live(): string {
		$message = $this->state['message'];
		$classes = 'ttl-notice ttl-entry__message'
			. ( $message['success'] ? ' ttl-notice--success' : '' )
			. ( $message['warning'] ? ' ttl-notice--warning' : '' );
		return '<div class="ttl-entry__live" aria-live="polite">'
			. '<div id="ttl-e-message" class="' . esc_attr( $classes ) . '" tabindex="-1"'
			. ' data-wp-class--ttl-notice--success="state.message.success" data-wp-class--ttl-notice--warning="state.message.warning"'
			. $this->hide_unless( 'message.text' ) . '>'
			. '<p class="ttl-entry__message-text"' . $this->text_attr( 'message.text' ) . '>' . esc_html( (string) $message['text'] ) . '</p>'
			. '<p class="ttl-actions"' . $this->hide_unless( 'message.reload' ) . '><button type="button" class="ttl-button" data-wp-on--click="actions.reload">' . esc_html__( 'Seite neu laden', 'ttleague' ) . '</button></p>'
			. '<p class="ttl-actions"' . $this->hide_unless( 'message.login' ) . '><a class="ttl-button" href="' . esc_url( (string) $this->page['login'] ) . '">' . esc_html__( 'Neu anmelden', 'ttleague' ) . '</a></p>'
			. '</div></div>';
	}

	/**
	 * Was gilt jetzt für diese Person? (Status und nächster Schritt.)
	 */
	private function status(): string {
		$entry   = $this->entry;
		$status  = (string) $entry['fixture']['status'];
		$current = $entry['current'];
		$can     = $entry['can'];
		$roles   = $entry['roles'];
		$by      = null === $current['entered_by'] ? '' : (string) $current['entered_by'];
		$at      = $this->local( $current['entered_at'] );
		$text    = '';
		switch ( $status ) {
			case Flow::SCHEDULED:
			case Flow::POSTPONED:
				if ( $can['enter'] ) {
					$text = __( 'Trage Aufstellung und Spiele vom Spielbericht ein. Danach bestätigt die Gastmannschaft das Resultat.', 'ttleague' );
				} else {
					$text = __( 'Noch kein Resultat erfasst. Die Heimmannschaft erfasst es nach dem Spiel; danach kannst du es hier bestätigen.', 'ttleague' );
				}
				break;
			case Flow::PROVISIONAL:
				/* translators: 1: Zeitpunkt, 2: Name */
				$text   = '' === $by ? '' : sprintf( __( 'Provisorisch erfasst am %1$s von %2$s.', 'ttleague' ), $at, $by ) . ' ';
				$checks = ( $can['confirm'] || $can['object'] ) && in_array( Flow::ROLE_AWAY, $roles, true );
				if ( ! $checks && $can['enter'] && ! $can['decide'] ) {
					$text .= __( 'Bis zur Bestätigung durch die Gastmannschaft kannst du es noch ändern.', 'ttleague' );
				} elseif ( ! $checks && ! $can['decide'] ) {
					$text .= __( 'Bestätigen muss es die andere Seite oder die TK.', 'ttleague' );
				}
				break;
			case Flow::CONFIRMED:
				$text = __( 'Das Resultat ist bestätigt. Ändern kann es nur noch die TK.', 'ttleague' );
				break;
			case Flow::DISPUTED:
				$text = __( 'Gegen das Resultat ist Einsprache erhoben. Die TK entscheidet.', 'ttleague' );
				break;
			case Flow::FINAL:
				$text = __( 'Das Resultat ist abgeschlossen.', 'ttleague' );
				break;
			case Flow::FORFEIT:
				$text = __( 'Die Begegnung ist forfait gewertet.', 'ttleague' );
				break;
			case Flow::CANCELLED:
				$text = __( 'Die Begegnung ist abgesagt.', 'ttleague' );
				break;
		}
		$text = trim( $text );
		return '' === $text ? '' : '<p class="ttl-notice ttl-entry__state">' . esc_html( $text ) . '</p>';
	}

	/**
	 * Hinweise zum gespeicherten Resultat (z. B. Ersatzeinsatz, Erfassung vor dem Termin).
	 */
	private function warnings(): string {
		$warnings = $this->entry['current']['warnings'];
		// Nach einem Zurücksetzen oder Forfait gibt es kein Resultat, zu dem die Hinweise gehören.
		if ( array() === $warnings || array() === $this->entry['current']['games'] ) {
			return '';
		}
		$items = '';
		foreach ( $warnings as $warning ) {
			$items .= '<li>' . esc_html( (string) $warning ) . '</li>';
		}
		return '<div class="ttl-notice ttl-notice--warning ttl-entry__warnings"><p class="ttl-entry__warnings-title">' . esc_html__( 'Hinweise zum Resultat', 'ttleague' ) . '</p><ul>' . $items . '</ul></div>';
	}

	private function has_result(): bool {
		return array() !== $this->entry['current']['games'] || Flow::FORFEIT === $this->entry['fixture']['status'];
	}

	/**
	 * Erfasstes Resultat zum Lesen (Gastseite, nach der Bestätigung, TK).
	 */
	private function result(): string {
		$entry   = $this->entry;
		$fixture = $entry['fixture'];
		$current = $entry['current'];
		$body    = '';
		if ( array() === $current['games'] ) {
			/* translators: %s: Resultat, z. B. 0:10 */
			$body = '<p>' . esc_html( sprintf( __( 'Forfait: Die Begegnung wurde %s gewertet.', 'ttleague' ), Format::score( $fixture['home_score'], $fixture['away_score'] ) ) ) . '</p>';
		} else {
			$body .= '<div class="ttl-lineups">';
			foreach ( array( 'home', 'away' ) as $side ) {
				$lineup = (array) $current['lineup'][ $side ];
				$items  = '';
				foreach ( $this->data['slots'][ $side ] as $slot ) {
					$id     = $lineup[ $slot ] ?? null;
					$items .= '<li><span class="ttl-slot" aria-hidden="true">' . esc_html( $slot ) . '</span> ';
					if ( null === $id ) {
						$items .= '<span class="ttl-muted">' . esc_html__( 'fehlt', 'ttleague' ) . '</span>';
					} else {
						$player = $this->data['players'][ (string) $id ] ?? null;
						$items .= esc_html( null === $player ? $this->data['strings']['unknown'] : $player['name'] );
						if ( null !== $player && '' !== $player['label'] ) {
							$items .= ' <span class="ttl-class">' . esc_html( $player['label'] ) . '</span>';
						}
						if ( in_array( (int) $id, $current['substitutes'], true ) ) {
							$items .= ' <span class="ttl-badge ttl-badge--muted">' . esc_html__( 'Ersatz', 'ttleague' ) . '</span>';
						}
					}
					$items .= '</li>';
				}
				$body .= '<section class="ttl-lineup"><h4 class="ttl-lineup__title">' . esc_html( $fixture[ $side ]['name'] ) . '</h4><ul class="ttl-lineup__list">' . $items . '</ul></section>';
			}
			$body  .= '</div><ol class="ttl-entry__played">';
			$by_seq = array();
			foreach ( $current['games'] as $game ) {
				$by_seq[ (int) $game['sequence'] ] = $game;
			}
			foreach ( $this->data['order'] as $slot_game ) {
				$game  = $by_seq[ (int) $slot_game['seq'] ] ?? null;
				$body .= $this->played_row( $slot_game, $game );
			}
			/* translators: %s: Resultat, z. B. 6:4 */
			$body .= '</ol><p class="ttl-total">' . esc_html( sprintf( __( 'Total %s', 'ttleague' ), Format::score( $fixture['home_score'], $fixture['away_score'] ) ) ) . '</p>';
		}
		$photo = $this->state['photo']['url'];
		if ( null !== $photo && '' !== $photo ) {
			$body .= '<p><a href="' . esc_url( (string) $photo ) . '">' . esc_html__( 'Foto des Spielberichts ansehen', 'ttleague' ) . '</a></p>';
		}
		return '<section class="ttl-section ttl-entry__result" aria-labelledby="ttl-e-result-title"' . $this->hide_unless( 'view.formHidden' ) . '>'
			. '<h3 id="ttl-e-result-title" class="ttl-section__title">' . esc_html__( 'Erfasstes Resultat', 'ttleague' ) . '</h3>' . $body . '</section>';
	}

	/**
	 * Eine Zeile der gespielten Spiele.
	 *
	 * @param array<string,mixed>      $slot_game Spiel der Spielfolge.
	 * @param array<string,mixed>|null $game      Erfasstes Spiel.
	 */
	private function played_row( array $slot_game, ?array $game ): string {
		$double = 'double' === $slot_game['type'];
		$pair   = $double ? __( 'Doppel', 'ttleague' ) : $slot_game['home'] . '–' . $slot_game['away'];
		$names  = function ( array $ids ): string {
			if ( array() === $ids ) {
				return '<span class="ttl-muted">' . esc_html__( '(kein Spieler)', 'ttleague' ) . '</span>';
			}
			return esc_html(
				implode(
					' / ',
					array_map( fn( int $id ): string => (string) ( $this->data['players'][ (string) $id ]['name'] ?? $this->data['strings']['unknown'] ), $ids )
				)
			);
		};
		$row    = '<li class="ttl-entry__played-item"><span class="ttl-entry__played-no">' . esc_html( (string) $slot_game['seq'] ) . '</span>'
			. '<span class="ttl-entry__played-pair ttl-muted">' . esc_html( $pair ) . '</span>';
		if ( null === $game ) {
			return $row . '<span class="ttl-entry__played-names ttl-muted">' . esc_html__( 'nicht erfasst (nach der Entscheidung)', 'ttleague' ) . '</span><span class="ttl-entry__played-score">–</span></li>';
		}
		$home_won = $game['sets_home'] > $game['sets_away'];
		$home     = $names( $game['home_players'] );
		$away     = $names( $game['away_players'] );
		$score    = 'not_played' === $game['status'] ? '–' : $game['sets_home'] . ':' . $game['sets_away'];
		$note     = Format::game_status( (string) $game['status'] );
		if ( ! $game['counts'] ) {
			$note = trim( $note . ' ' . __( 'nach Entscheidung', 'ttleague' ) );
		}
		$points = array();
		foreach ( $game['set_points'] as $set ) {
			$points[] = $set[0] . ':' . $set[1];
		}
		return $row . '<span class="ttl-entry__played-names">'
			. ( 'not_played' !== $game['status'] && $home_won ? '<strong>' . $home . '</strong>' : $home )
			. ' <span aria-hidden="true">–</span><span class="screen-reader-text">' . esc_html__( 'gegen', 'ttleague' ) . '</span> '
			. ( 'not_played' !== $game['status'] && ! $home_won ? '<strong>' . $away . '</strong>' : $away )
			. ( array() === $points ? '' : '<span class="ttl-sets">' . esc_html( implode( ', ', $points ) ) . '</span>' )
			. '</span><span class="ttl-entry__played-score"><strong>' . esc_html( $score ) . '</strong>'
			. ( '' === $note ? '' : ' <span class="ttl-badge ttl-badge--muted">' . esc_html( $note ) . '</span>' ) . '</span></li>';
	}

	/**
	 * Gastseite: bestätigen oder Einsprache erheben.
	 */
	private function guest(): string {
		$can = $this->entry['can'];
		$out = '<section class="ttl-section ttl-entry__guest" aria-labelledby="ttl-e-guest-title">'
			. '<h3 id="ttl-e-guest-title" class="ttl-section__title">' . esc_html__( 'Resultat prüfen', 'ttleague' ) . '</h3>'
			. '<p>' . esc_html__( 'Stimmt das Resultat mit dem Spielbericht überein, bestätige es. Sonst erhebe Einsprache mit einer Begründung; die TK entscheidet.', 'ttleague' ) . '</p>';
		if ( $can['confirm'] ) {
			$out .= '<p class="ttl-actions"><button type="button" class="ttl-button ttl-button--primary ttl-entry__confirm" data-wp-on--click="actions.confirm"' . $this->disabled( 'busy' ) . '>' . esc_html__( 'Resultat bestätigen', 'ttleague' ) . '</button></p>';
		}
		if ( $can['object'] ) {
			$out .= '<details class="ttl-entry__objection"><summary>' . esc_html__( 'Einsprache erheben', 'ttleague' ) . '</summary>'
				. '<form class="ttl-form" novalidate data-wp-on--submit="actions.object">'
				. '<p class="ttl-field"><label for="ttl-e-reason">' . esc_html__( 'Begründung', 'ttleague' ) . '</label>'
				. '<textarea id="ttl-e-reason" rows="4" maxlength="2000" required aria-describedby="ttl-e-reason-info ttl-e-reason-err"'
				. $this->bind_bool( 'aria-invalid', 'view.reasonBad' ) . ' data-wp-on--input="actions.setReason"></textarea>'
				. '<small id="ttl-e-reason-info" class="ttl-muted"' . $this->text_attr( 'view.reasonInfo' ) . '>' . esc_html( (string) $this->state['view']['reasonInfo'] ) . '</small>'
				. $this->error( 'ttl-e-reason-err', 'view.reasonErr' ) . '</p>'
				. '<button type="submit" class="ttl-button"' . $this->disabled( 'busy' ) . '>' . esc_html__( 'Einsprache absenden', 'ttleague' ) . '</button>'
				. '</form></details>';
		}
		return $out . '</section>';
	}

	/**
	 * Entscheid der TK.
	 */
	private function decision(): string {
		$labels  = array(
			'confirm'  => array( __( 'Bestätigen', 'ttleague' ), __( 'Das Resultat gilt als bestätigt (anstelle der Gastmannschaft).', 'ttleague' ) ),
			'finalize' => array( __( 'Abschliessen', 'ttleague' ), __( 'Das Resultat wird unverändert definitiv.', 'ttleague' ) ),
			'correct'  => array( __( 'Korrigieren', 'ttleague' ), __( 'Resultat im Formular anpassen; danach ist es definitiv.', 'ttleague' ) ),
			'forfeit'  => array( __( 'Forfait', 'ttleague' ), __( 'Die Begegnung wird forfait gewertet; erfasste Spiele werden gelöscht.', 'ttleague' ) ),
			'reset'    => array( __( 'Zurücksetzen', 'ttleague' ), __( 'Resultat, Aufstellung und Spiele werden gelöscht; die Begegnung ist wieder angesetzt.', 'ttleague' ) ),
		);
		$choices = '';
		foreach ( (array) $this->page['decisions'] as $action ) {
			if ( ! isset( $labels[ $action ] ) ) {
				continue;
			}
			$id       = 'ttl-e-action-' . $action;
			$choices .= '<div class="ttl-entry__choice">'
				. '<input type="radio" name="ttl-e-action" id="' . esc_attr( $id ) . '" value="' . esc_attr( $action ) . '" data-wp-on--change="actions.setDecision"'
				. $this->checked( 'view.dec.' . $action ) . '>'
				. '<label for="' . esc_attr( $id ) . '"><strong>' . esc_html( $labels[ $action ][0] ) . '</strong> <span class="ttl-muted">' . esc_html( $labels[ $action ][1] ) . '</span></label></div>';
		}
		if ( '' === $choices ) {
			return '';
		}
		$teams = $this->data['teams'];
		return '<section class="ttl-section ttl-entry__decision" aria-labelledby="ttl-e-decision-title">'
			. '<h3 id="ttl-e-decision-title" class="ttl-section__title">' . esc_html__( 'Entscheid der TK', 'ttleague' ) . '</h3>'
			. '<form class="ttl-form ttl-entry__decision-form" novalidate data-wp-on--submit="actions.decide">'
			. '<fieldset class="ttl-entry__choices" id="ttl-e-action" tabindex="-1"><legend class="ttl-label">' . esc_html__( 'Entscheid', 'ttleague' ) . '</legend>'
			. $choices . $this->error( 'ttl-e-action-err', 'view.decErr' ) . '</fieldset>'
			. '<fieldset class="ttl-entry__choices" id="ttl-e-winner" tabindex="-1"' . $this->hide_unless( 'view.decForfeit' ) . '><legend class="ttl-label">' . esc_html__( 'Forfait zugunsten von', 'ttleague' ) . '</legend>'
			. '<div class="ttl-entry__choice"><input type="radio" name="ttl-e-forfeit" id="ttl-e-forfeit-home" value="home" data-wp-on--change="actions.setDecisionWinner"><label for="ttl-e-forfeit-home">' . esc_html( $teams['home'] ) . '</label></div>'
			. '<div class="ttl-entry__choice"><input type="radio" name="ttl-e-forfeit" id="ttl-e-forfeit-away" value="away" data-wp-on--change="actions.setDecisionWinner"><label for="ttl-e-forfeit-away">' . esc_html( $teams['away'] ) . '</label></div>'
			. $this->error( 'ttl-e-winner-err', 'view.decWinErr' ) . '</fieldset>'
			. '<p class="ttl-notice"' . $this->hide_unless( 'view.decCorrect' ) . '>' . esc_html__( 'Passe das Resultat im Formular unten an und speichere die Korrektur.', 'ttleague' ) . ' <a href="#ttl-e-form">' . esc_html__( 'Zum Formular', 'ttleague' ) . '</a></p>'
			. '<div class="ttl-entry__decision-submit"' . $this->hide_if( 'view.decCorrect' ) . '>'
			. '<p class="ttl-field"><label for="ttl-e-dec-comment">' . esc_html__( 'Bemerkung (für den Verlauf)', 'ttleague' ) . '</label>'
			. '<textarea id="ttl-e-dec-comment" rows="3" maxlength="2000" aria-describedby="ttl-e-dec-comment-err" data-wp-on--input="actions.setDecisionComment"></textarea>'
			. $this->error( 'ttl-e-dec-comment-err', 'view.decCommentErr' ) . '</p>'
			. '<button type="submit" class="ttl-button ttl-button--primary"' . $this->disabled( 'busy' ) . '>' . esc_html__( 'Entscheid speichern', 'ttleague' ) . '</button></div>'
			. '</form></section>';
	}

	/**
	 * Erfassungsformular (Heimseite, TK; im Korrekturmodus für den Entscheid der TK).
	 */
	private function form(): string {
		$out = '<form id="ttl-e-form" class="ttl-entry__form" novalidate tabindex="-1" data-wp-on--submit="actions.submit"'
			. $this->bind_bool( 'aria-busy', 'busy' ) . $this->hide_if( 'view.formHidden' ) . '>'
			. '<h3 class="ttl-section__title ttl-entry__form-title"' . $this->text_attr( 'view.formTitle' ) . '>' . esc_html( (string) $this->state['view']['formTitle'] ) . '</h3>'
			. $this->draft()
			. $this->summary()
			. $this->lineup()
			. $this->games()
			. '<p class="ttl-field ttl-entry__comment"><label for="ttl-e-comment"' . $this->text_attr( 'view.comment' ) . '>' . esc_html( (string) $this->state['view']['comment'] ) . '</label>'
			. '<textarea id="ttl-e-comment" rows="3" maxlength="2000" aria-describedby="ttl-e-comment-err" data-wp-bind--value="state.sel.comment" data-wp-on--input="actions.setComment"></textarea>'
			. $this->error( 'ttl-e-comment-err', 'view.commentErr' ) . '</p>';
		if ( $this->entry['can']['photo'] ) {
			$out .= $this->photo();
		}
		$out .= $this->error( 'ttl-e-general-err', 'view.generalErr' )
			. '<div class="ttl-entry__bar">'
			. '<p class="ttl-entry__total" aria-live="polite"><span class="ttl-entry__total-label">' . esc_html__( 'Zwischenstand', 'ttleague' ) . '</span> '
			. '<strong class="ttl-entry__total-score"' . $this->text_attr( 'view.total' ) . '>' . esc_html( (string) $this->state['view']['total'] ) . '</strong> '
			. '<span class="ttl-entry__progress"' . $this->text_attr( 'view.progress' ) . '>' . esc_html( (string) $this->state['view']['progress'] ) . '</span></p>'
			. '<button type="submit" class="ttl-button ttl-button--primary ttl-entry__submit"' . $this->disabled( 'busy' ) . $this->text_attr( 'view.submit' ) . '>' . esc_html( (string) $this->state['view']['submit'] ) . '</button>'
			. '</div></form>';
		return $out;
	}

	/**
	 * Hinweis auf einen Entwurf im Browser.
	 */
	private function draft(): string {
		return '<div class="ttl-notice ttl-entry__draft"' . $this->hide_unless( 'draft.text' ) . '>'
			. '<p' . $this->text_attr( 'draft.text' ) . '>' . esc_html( (string) $this->state['draft']['text'] ) . '</p>'
			. '<p class="ttl-actions">'
			. '<button type="button" class="ttl-button"' . $this->hide_unless( 'draft.offer' ) . ' data-wp-on--click="actions.restoreDraft">' . esc_html__( 'Wiederherstellen', 'ttleague' ) . '</button> '
			. '<button type="button" class="ttl-button ttl-button--secondary" data-wp-on--click="actions.discardDraft">' . esc_html__( 'Entwurf verwerfen', 'ttleague' ) . '</button>'
			. '</p></div>';
	}

	/**
	 * Fehlerübersicht mit Sprungmarken zu den Feldern.
	 */
	private function summary(): string {
		return '<div id="ttl-e-summary" class="ttl-notice ttl-notice--error ttl-entry__summary" tabindex="-1"' . $this->hide_unless( 'view.hasErrors' ) . '>'
			. '<p class="ttl-entry__summary-title"' . $this->text_attr( 'view.errorTitle' ) . '>' . esc_html( (string) $this->state['view']['errorTitle'] ) . '</p>'
			. '<ul><template data-wp-each--error="state.errorList" data-wp-each-key="context.error.key">'
			. '<li><a data-wp-bind--href="context.error.href" data-wp-text="context.error.text" data-wp-on--click="actions.jump"></a></li>'
			. '</template></ul></div>';
	}

	private function lineup(): string {
		$out = '<fieldset id="ttl-e-lineup" class="ttl-entry__lineup" tabindex="-1"><legend class="ttl-entry__legend">' . esc_html__( 'Aufstellung', 'ttleague' ) . '</legend>'
			. $this->error( 'ttl-e-lineup-err', 'view.lineup.err' )
			. '<div class="ttl-entry__sides">';
		foreach ( array( 'home', 'away' ) as $side ) {
			$team = $this->data['teams'][ $side ];
			$out .= '<fieldset id="ttl-e-lineup-' . $side . '" class="ttl-entry__side" tabindex="-1">'
				. '<legend class="ttl-entry__side-title">' . esc_html( $team ) . ' <span class="ttl-muted">' . esc_html( 'home' === $side ? __( '(Heim)', 'ttleague' ) : __( '(Gast)', 'ttleague' ) ) . '</span></legend>';
			foreach ( $this->data['slots'][ $side ] as $slot ) {
				$out .= $this->slot( $side, (string) $slot );
			}
			$out .= $this->error( 'ttl-e-lineup-' . $side . '-err', 'view.lineup.' . $side . '.err' )
				. $this->blocked( $side ) . '</fieldset>';
		}
		return $out . '</div></fieldset>';
	}

	/**
	 * Auswahl eines Slots: Kandidaten nach Spielberechtigung gruppiert, gesperrte mit Grund.
	 */
	private function slot( string $side, string $slot ): string {
		$id         = 'ttl-e-slot-' . $side . '-' . $slot;
		$path       = 'view.lineup.' . $side . '.' . $slot;
		$value      = (string) $this->state['form']['lineup'][ $side ][ $slot ];
		$cup        = 'CUP' === $this->data['type'];
		$groups     = array(
			'stamm'      => array( $cup ? __( 'Für diese Cup-Mannschaft gemeldet', 'ttleague' ) : __( 'Stammspieler', 'ttleague' ), '' ),
			'bound'      => array( __( 'An dieses Team gebunden', 'ttleague' ), '' ),
			'substitute' => array( $cup ? __( 'Weitere Spieler des Clubs (Ersatz)', 'ttleague' ) : __( 'Ersatz', 'ttleague' ), '' ),
			'blocked'    => array( __( 'Nicht spielberechtigt', 'ttleague' ), '' ),
		);
		$candidates = $this->entry['candidates'][ $side ];
		foreach ( $candidates as $candidate ) {
			$group  = $candidate['eligible'] ? (string) $candidate['reason'] : 'blocked';
			$group  = isset( $groups[ $group ] ) ? $group : 'blocked';
			$option = '<option value="' . esc_attr( (string) $candidate['player_id'] ) . '"'
				. ( (string) $candidate['player_id'] === $value ? ' selected' : '' );
			if ( 'blocked' === $group ) {
				$option .= $this->disabled( 'view.lockBlocked' );
			}
			$groups[ $group ][1] .= $option . '>' . esc_html( self::option_label( $candidate, $cup ) ) . '</option>';
		}
		$options = '<option value=""' . ( '' === $value ? ' selected' : '' ) . '>' . esc_html__( 'Spieler wählen …', 'ttleague' ) . '</option>'
			. '<option value="' . esc_attr( EntryForm::MISSING ) . '"' . ( EntryForm::MISSING === $value ? ' selected' : '' ) . '>' . esc_html__( 'fehlt (nicht angetreten)', 'ttleague' ) . '</option>';
		foreach ( $groups as $group ) {
			if ( '' !== $group[1] ) {
				$options .= '<optgroup label="' . esc_attr( $group[0] ) . '">' . $group[1] . '</optgroup>';
			}
		}
		$invalid = (bool) $this->state['view']['lineup'][ $side ][ $slot ]['bad'];
		return '<div class="ttl-entry__slot' . ( $invalid ? ' is-invalid' : '' ) . '" data-wp-context="' . esc_attr(
			self::json(
				array(
					'side' => $side,
					'slot' => $slot,
				)
			)
		) . '" data-wp-class--is-invalid="state.' . esc_attr( $path ) . '.bad">'
			. '<label for="' . esc_attr( $id ) . '" class="ttl-entry__slot-label"><span class="ttl-slot" aria-hidden="true">' . esc_html( $slot ) . '</span><span class="screen-reader-text">'
			/* translators: 1: Platz in der Aufstellung, 2: Mannschaft */
			. esc_html( sprintf( __( 'Platz %1$s, %2$s', 'ttleague' ), $slot, $this->data['teams'][ $side ] ) ) . '</span></label>'
			. '<select id="' . esc_attr( $id ) . '" class="ttl-entry__select" aria-describedby="' . esc_attr( $id . '-note ' . $id . '-err' ) . '"'
			. ' data-wp-bind--value="state.sel.' . esc_attr( $side . $slot ) . '" data-wp-on--change="actions.setSlot"' . $this->bind_bool( 'aria-invalid', $path . '.bad' ) . $this->disabled( 'busy' ) . '>'
			. $options . '</select>'
			. '<small id="' . esc_attr( $id . '-note' ) . '" class="ttl-entry__note"' . $this->hide_unless( $path . '.note' ) . $this->text_attr( $path . '.note' ) . '>'
			. esc_html( (string) $this->state['view']['lineup'][ $side ][ $slot ]['note'] ) . '</small>'
			. $this->error( $id . '-err', $path . '.err' )
			. '</div>';
	}

	/**
	 * Text einer Option: Name, Klassierung und Kurzbegründung.
	 *
	 * @param array<string,mixed> $candidate Kandidat aus ResultService::entry().
	 */
	public static function option_label( array $candidate, bool $cup ): string {
		$text = (string) $candidate['name'];
		if ( '' !== (string) $candidate['label'] ) {
			$text .= ' (' . $candidate['label'] . ')';
		}
		if ( ! $candidate['eligible'] ) {
			$short = array(
				'blocked_other_club'            => __( 'nicht für den Club gemeldet', 'ttleague' ),
				'blocked_lower_team'            => __( 'Stammspieler einer höheren Kategorie', 'ttleague' ),
				'blocked_team_number'           => __( 'Stammspieler eines anderen Teams (Art. 12.7)', 'ttleague' ),
				'blocked_other_team_substitute' => __( 'Ersatz in einem anderen Team', 'ttleague' ),
				'blocked_bound_elsewhere'       => __( 'an ein anderes Team gebunden', 'ttleague' ),
			);
			/* translators: %s: Kurzbegründung */
			return $text . ' · ' . sprintf( __( 'gesperrt: %s', 'ttleague' ), $short[ $candidate['reason'] ] ?? __( 'nicht spielberechtigt', 'ttleague' ) );
		}
		if ( 'substitute' === $candidate['reason'] ) {
			/* translators: %d: Nummer des Ersatzeinsatzes */
			return $text . ' · ' . ( $cup ? __( 'Ersatz', 'ttleague' ) : sprintf( __( '%d. Ersatzeinsatz', 'ttleague' ), (int) $candidate['substitute_appearances'] + 1 ) );
		}
		return $text;
	}

	/**
	 * Liste der gesperrten Spieler einer Seite mit vollständiger Begründung.
	 */
	private function blocked( string $side ): string {
		$items = '';
		$count = 0;
		foreach ( $this->entry['candidates'][ $side ] as $candidate ) {
			if ( $candidate['eligible'] ) {
				continue;
			}
			++$count;
			$items .= '<li><strong>' . esc_html( (string) $candidate['name'] ) . '</strong>'
				. ( '' === (string) $candidate['label'] ? '' : ' <span class="ttl-class">' . esc_html( (string) $candidate['label'] ) . '</span>' )
				. ': ' . esc_html( (string) $candidate['message'] ) . '</li>';
		}
		if ( 0 === $count ) {
			return '';
		}
		/* translators: %d: Anzahl Spieler */
		return '<details class="ttl-entry__blocked"><summary>' . esc_html( sprintf( _n( '%d Spieler nicht spielberechtigt', '%d Spieler nicht spielberechtigt', $count, 'ttleague' ), $count ) ) . '</summary><ul>' . $items . '</ul></details>';
	}

	private function games(): string {
		$out = '<section id="ttl-e-games" class="ttl-entry__games" tabindex="-1" aria-labelledby="ttl-e-games-title">'
			. '<h4 id="ttl-e-games-title" class="ttl-entry__legend">' . esc_html__( 'Spiele', 'ttleague' ) . '</h4>'
			. '<p class="ttl-muted ttl-entry__games-help">' . esc_html__( 'Sätze aus Sicht der Heimmannschaft (Heim : Gast). Fehlt ein Spieler, schlägt das Formular «kampflos» vor.', 'ttleague' ) . '</p>'
			. $this->error( 'ttl-e-games-err', 'view.gamesErr' )
			. '<ol class="ttl-entry__game-list">';
		foreach ( $this->data['order'] as $slot_game ) {
			$out .= '<li>' . $this->game( $slot_game ) . '</li>';
		}
		return $out . '</ol></section>';
	}

	/**
	 * Ein Spiel: Schnellknöpfe, kampflos, Doppelpaarung, Satzpunkte, Hinweise.
	 *
	 * @param array<string,mixed> $slot_game Spiel der Spielfolge.
	 */
	private function game( array $slot_game ): string {
		$seq    = (int) $slot_game['seq'];
		$g      = 'view.g' . $seq;
		$view   = $this->state['view'][ 'g' . $seq ];
		$id     = 'ttl-e-game-' . $seq;
		$double = 'double' === $slot_game['type'];
		$teams  = $this->data['teams'];
		$label  = $double ? __( 'Doppel', 'ttleague' ) : $slot_game['home'] . ' – ' . $slot_game['away'];
		/* translators: %d: Spielnummer */
		$title   = sprintf( __( 'Spiel %d', 'ttleague' ), $seq );
		$classes = 'ttl-game'
			. ( $view['done'] ? ' is-done' : '' )
			. ( $view['bad'] ? ' is-invalid' : '' )
			. ( $view['opt'] ? ' is-optional' : '' )
			. ( $view['sug'] ? ' is-suggested' : '' );

		$out = '<fieldset id="' . esc_attr( $id ) . '" class="' . esc_attr( $classes ) . '" tabindex="-1" aria-describedby="' . esc_attr( $id . '-err' ) . '"'
			. ' data-wp-context="' . esc_attr( self::json( array( 'seq' => $seq ) ) ) . '"'
			. ' data-wp-class--is-done="state.' . esc_attr( $g ) . '.done" data-wp-class--is-invalid="state.' . esc_attr( $g ) . '.bad"'
			. ' data-wp-class--is-optional="state.' . esc_attr( $g ) . '.opt" data-wp-class--is-suggested="state.' . esc_attr( $g ) . '.sug">'
			. '<legend class="ttl-game__legend"><span class="ttl-game__no">' . esc_html( $title ) . '</span> <span class="ttl-game__slots">' . esc_html( $label ) . '</span></legend>'
			. '<p class="ttl-game__head">'
			. '<span class="ttl-game__names"><span' . $this->text_attr( $g . '.home' ) . '>' . esc_html( (string) $view['home'] ) . '</span>'
			. ' <span aria-hidden="true">–</span><span class="screen-reader-text">' . esc_html__( 'gegen', 'ttleague' ) . '</span> '
			. '<span' . $this->text_attr( $g . '.away' ) . '>' . esc_html( (string) $view['away'] ) . '</span></span>'
			. '<span class="ttl-game__out"' . $this->text_attr( $g . '.out' ) . '>' . esc_html( (string) $view['out'] ) . '</span>'
			. '<span class="ttl-badge ttl-badge--muted ttl-game__optional"' . $this->hide_unless( $g . '.opt' ) . '>' . esc_html__( 'freiwillig', 'ttleague' ) . '</span>'
			. '</p>';

		/* translators: %d: Spielnummer */
		// Satzresultat als Radiogruppe: ein Tabulatorhalt pro Spiel, Pfeiltasten wählen; nochmals
		// antippen hebt die Wahl auf (actions.setScore).
		$out .= '<div class="ttl-game__controls"><fieldset class="ttl-game__scores">'
			/* translators: %d: Spielnummer */
			. '<legend class="screen-reader-text">' . esc_html( sprintf( __( 'Sätze Spiel %d (Heim : Gast)', 'ttleague' ), $seq ) ) . '</legend>';
		foreach ( $this->data['scores'] as [ $h, $a ] ) {
			$key   = 'p' . $h . $a;
			$radio = $id . '-s' . $h . $a;
			$out  .= '<span class="ttl-score ttl-score--' . ( $h > $a ? 'home' : 'away' ) . '">'
				. '<input type="radio" name="' . esc_attr( $id . '-score' ) . '" id="' . esc_attr( $radio ) . '" value="' . esc_attr( $h . ':' . $a ) . '"'
				. ' data-wp-context="' . esc_attr(
					self::json(
						array(
							'h' => $h,
							'a' => $a,
						)
					)
				) . '" data-wp-on--click="actions.setScore"'
				. $this->checked( $g . '.' . $key ) . $this->disabled( 'busy' ) . '>'
				. '<label for="' . esc_attr( $radio ) . '">' . esc_html( $h . ':' . $a ) . '</label></span>';
		}
		$out .= '</fieldset>';

		$out .= '<div class="ttl-game__wo">'
			. '<span class="ttl-check"><input type="checkbox" id="' . esc_attr( $id . '-wo' ) . '" data-wp-on--change="actions.toggleWalkover"' . $this->checked( $g . '.wo' ) . $this->disabled( 'busy' ) . '>'
			. '<label for="' . esc_attr( $id . '-wo' ) . '">' . esc_html__( 'kampflos', 'ttleague' ) . '</label></span>'
			. '<fieldset id="' . esc_attr( $id . '-win' ) . '" class="ttl-game__winner"' . $this->hide_unless( $g . '.wo' ) . '>'
			/* translators: %d: Spielnummer */
			. '<legend class="screen-reader-text">' . esc_html( sprintf( __( 'Sieger von Spiel %d', 'ttleague' ), $seq ) ) . '</legend>';
		foreach ( array( 'home', 'away' ) as $side ) {
			$radio = $id . '-win-' . $side;
			$out  .= '<span class="ttl-check"><input type="radio" name="' . esc_attr( $id . '-win' ) . '" id="' . esc_attr( $radio ) . '" value="' . esc_attr( $side ) . '"'
				. ' data-wp-on--change="actions.setWinner"' . $this->checked( $g . '.wo' . ucfirst( $side ) ) . $this->disabled( 'busy' ) . '>'
				. '<label for="' . esc_attr( $radio ) . '">' . esc_html( $teams[ $side ] ) . '</label></span>';
		}
		$out .= '</fieldset>'
			. '<span class="ttl-game__np"' . $this->hide_unless( $g . '.np' ) . '>' . esc_html__( 'nicht gespielt', 'ttleague' ) . '</span>'
			. '</div></div>';

		if ( $double ) {
			$out .= $this->pairing( $seq, 'home' ) . $this->pairing( $seq, 'away' );
		}
		if ( $this->data['setPoints'] ) {
			$out .= $this->points( $seq );
		}

		$out .= '<p class="ttl-game__hint"' . $this->hide_unless( $g . '.hint' ) . $this->text_attr( $g . '.hint' ) . '>' . esc_html( (string) $view['hint'] ) . '</p>'
			. '<p class="ttl-game__fix"' . $this->hide_unless( $g . '.fix' ) . '><button type="button" class="ttl-button ttl-button--secondary" data-wp-on--click="actions.applyFix"' . $this->disabled( 'busy' ) . '>' . esc_html__( 'Übernehmen', 'ttleague' ) . '</button></p>'
			. $this->error( $id . '-err', $g . '.err' )
			. $this->error( $id . '-win-err', $g . '.errWin' )
			. '</fieldset>';
		return $out;
	}

	/**
	 * Doppelpaarung einer Seite (Auswahl bei drei Spielern, sonst automatisch).
	 */
	private function pairing( int $seq, string $side ): string {
		$g     = 'view.g' . $seq;
		$view  = $this->state['view'][ 'g' . $seq ];
		$key   = 'home' === $side ? 'Home' : 'Away';
		$id    = 'ttl-e-game-' . $seq . '-pair-' . $side;
		$value = (string) $this->state['form']['games'][ (string) $seq ][ 'home' === $side ? 'hp' : 'ap' ];
		$opts  = '<option value=""' . ( '' === $value ? ' selected' : '' ) . '>' . esc_html__( 'Paarung wählen …', 'ttleague' ) . '</option>';
		foreach ( $this->state['view']['pairs'][ $side ] as $pair => $label ) {
			$opts .= '<option value="' . esc_attr( (string) $pair ) . '"' . ( (string) $pair === $value ? ' selected' : '' ) . $this->text_attr( 'view.pairs.' . $side . '.' . $pair ) . '>' . esc_html( (string) $label ) . '</option>';
		}
		return '<p class="ttl-game__auto"' . $this->hide_unless( $g . '.auto' . $key ) . $this->text_attr( $g . '.auto' . $key ) . '>' . esc_html( (string) $view[ 'auto' . $key ] ) . '</p>'
			. '<p class="ttl-field ttl-game__pair" data-wp-context="' . esc_attr( self::json( array( 'side' => $side ) ) ) . '"' . $this->hide_unless( $g . '.pair' . $key ) . '>'
			/* translators: %s: Mannschaft */
			. '<label for="' . esc_attr( $id ) . '">' . esc_html( sprintf( __( 'Doppel %s', 'ttleague' ), $this->data['teams'][ $side ] ) ) . '</label>'
			. '<select id="' . esc_attr( $id ) . '" aria-describedby="' . esc_attr( $id . '-err' ) . '" data-wp-bind--value="state.sel.pair' . esc_attr( $seq . $side ) . '" data-wp-on--change="actions.setPair"'
			. $this->bind_bool( 'aria-invalid', $g . '.bad' . $key ) . $this->disabled( 'busy' ) . '>' . $opts . '</select>'
			. $this->error( $id . '-err', $g . '.err' . $key ) . '</p>';
	}

	/**
	 * Satzpunkte (freiwillig, nur wenn die Saison sie vorsieht).
	 */
	private function points( int $seq ): string {
		$g     = 'view.g' . $seq;
		$view  = $this->state['view'][ 'g' . $seq ];
		$teams = $this->data['teams'];
		$out   = '<div class="ttl-game__points"' . $this->hide_unless( $g . '.pts' ) . '><p class="ttl-game__points-title">' . esc_html__( 'Satzpunkte (freiwillig)', 'ttleague' ) . '</p><div class="ttl-game__sets">';
		for ( $k = 1; $k <= (int) $this->data['maxSets']; $k++ ) {
			$out .= '<span class="ttl-game__set"' . $this->hide_unless( $g . '.s' . $k ) . '><span class="ttl-game__set-no" aria-hidden="true">' . esc_html( $k . '.' ) . '</span>';
			foreach ( array(
				0 => 'home',
				1 => 'away',
			) as $index => $side ) {
				$value = (string) $view[ 'v' . $k . ( 0 === $index ? 'h' : 'a' ) ];
				if ( 1 === $index ) {
					$out .= '<span aria-hidden="true">:</span>';
				}
				/* translators: 1: Satznummer, 2: Mannschaft */
				$out .= '<input type="text" inputmode="numeric" pattern="[0-9]*" maxlength="2" autocomplete="off" class="ttl-game__point" aria-label="' . esc_attr( sprintf( __( 'Satz %1$d, Punkte %2$s', 'ttleague' ), $k, $teams[ $side ] ) ) . '"'
					. ' data-wp-context="' . esc_attr(
						self::json(
							array(
								'set' => $k,
								'col' => $index,
							)
						)
					) . '" data-wp-on--input="actions.setPoint"'
					. ' value="' . esc_attr( $value ) . '" data-wp-bind--value="state.' . esc_attr( $g . '.v' . $k . ( 0 === $index ? 'h' : 'a' ) ) . '"' . $this->disabled( 'busy' ) . '>';
			}
			$out .= '</span>';
		}
		return $out . '</div>' . $this->error( 'ttl-e-game-' . $seq . '-pts-err', $g . '.errPts' ) . '</div>';
	}

	/**
	 * Foto des Spielberichts (Upload direkt nach der Auswahl).
	 */
	private function photo(): string {
		$photo = $this->state['photo'];
		$url   = (string) ( $photo['url'] ?? '' );
		return '<p class="ttl-field ttl-entry__photo"><label for="ttl-e-photo">' . esc_html__( 'Foto des Spielberichts (freiwillig)', 'ttleague' ) . '</label>'
			. '<input id="ttl-e-photo" type="file" accept="image/*" capture="environment" aria-describedby="ttl-e-photo-text ttl-e-photo-err" data-wp-on--change="actions.uploadPhoto"' . $this->disabled( 'photo.busy' ) . '>'
			. '<small id="ttl-e-photo-text" class="ttl-entry__note" aria-live="polite"' . $this->text_attr( 'photo.text' ) . '>' . esc_html( (string) $photo['text'] ) . '</small>'
			. '<img class="ttl-entry__thumb" alt="' . esc_attr__( 'Foto des Spielberichts', 'ttleague' ) . '"' . ( '' === $url ? '' : ' src="' . esc_url( $url ) . '"' ) . ' data-wp-bind--src="state.photo.url"' . $this->hide_unless( 'photo.url' ) . '>'
			. $this->error( 'ttl-e-photo-err', 'view.photoErr' ) . '</p>';
	}

	/**
	 * Verlauf der Begegnung (älteste Einträge zuerst).
	 */
	private function history(): string {
		$events = $this->entry['history'];
		if ( array() === $events ) {
			return '';
		}
		$labels  = array(
			'entered'   => __( 'Resultat erfasst', 'ttleague' ),
			'edited'    => __( 'Resultat geändert', 'ttleague' ),
			'confirmed' => __( 'Resultat bestätigt', 'ttleague' ),
			'disputed'  => __( 'Einsprache erhoben', 'ttleague' ),
			'decided'   => __( 'Entscheid der TK', 'ttleague' ),
			'forfeit'   => __( 'Forfait gewertet', 'ttleague' ),
			'reset'     => __( 'Zurückgesetzt', 'ttleague' ),
			'photo'     => __( 'Foto hochgeladen', 'ttleague' ),
		);
		$actions = array(
			'finalize' => __( 'abgeschlossen', 'ttleague' ),
			'correct'  => __( 'korrigiert', 'ttleague' ),
		);
		$items   = '';
		foreach ( $events as $event ) {
			$label = $labels[ $event['type'] ] ?? (string) $event['type'];
			if ( 'decided' === $event['type'] && isset( $actions[ (string) $event['action'] ] ) ) {
				$label .= ': ' . $actions[ (string) $event['action'] ];
			}
			if ( null !== $event['score'] && '' !== $event['score'] ) {
				$label .= ' (' . $event['score'] . ')';
			}
			$items .= '<li class="ttl-entry__event"><span class="ttl-entry__event-head">'
				. ( null === $event['at'] ? '' : '<time datetime="' . esc_attr( (string) $event['at'] ) . '">' . esc_html( $this->local( $event['at'] ) ) . '</time> · ' )
				. '<strong>' . esc_html( $label ) . '</strong>'
				. ( null === $event['by'] || '' === $event['by'] ? '' : ' · ' . esc_html( (string) $event['by'] ) ) . '</span>'
				. ( null === $event['comment'] || '' === $event['comment'] ? '' : '<span class="ttl-entry__event-comment">' . esc_html( (string) $event['comment'] ) . '</span>' )
				. '</li>';
		}
		return '<section class="ttl-section ttl-entry__history" aria-labelledby="ttl-e-history-title">'
			. '<h3 id="ttl-e-history-title" class="ttl-section__title">' . esc_html__( 'Verlauf', 'ttleague' ) . '</h3><ol class="ttl-entry__events">' . $items . '</ol></section>';
	}

	/**
	 * Zeitpunkt in UTC (ISO) als Ortszeit «Di, 10.11.2026, 21:30».
	 */
	private function local( ?string $iso ): string {
		if ( null === $iso || '' === $iso ) {
			return '';
		}
		try {
			$time = ( new \DateTimeImmutable( $iso ) )->setTimezone( $this->timezone );
		} catch ( \Exception ) {
			return '';
		}
		return Format::datetime( $time->format( 'Y-m-d H:i:s' ) );
	}

	/**
	 * Fehlermeldung an einem Feld (leer und verborgen ohne Fehler).
	 */
	private function error( string $id, string $path ): string {
		return '<span id="' . esc_attr( $id ) . '" class="ttl-error"' . $this->hide_unless( $path ) . $this->text_attr( $path ) . '>' . esc_html( (string) $this->value( $path ) ) . '</span>';
	}

	/**
	 * Wert eines Pfads im Startzustand (wie die Direktive ihn auswertet).
	 */
	private function value( string $path ): mixed {
		$current = $this->state;
		foreach ( explode( '.', $path ) as $segment ) {
			if ( ! is_array( $current ) || ! array_key_exists( $segment, $current ) ) {
				return null;
			}
			$current = $current[ $segment ];
		}
		return $current;
	}

	private function text_attr( string $path ): string {
		return ' data-wp-text="state.' . esc_attr( $path ) . '"';
	}

	private function hide_unless( string $path ): string {
		return ' data-wp-bind--hidden="!state.' . esc_attr( $path ) . '"' . ( $this->value( $path ) ? '' : ' hidden' );
	}

	private function hide_if( string $path ): string {
		return ' data-wp-bind--hidden="state.' . esc_attr( $path ) . '"' . ( $this->value( $path ) ? ' hidden' : '' );
	}

	private function disabled( string $path ): string {
		return ' data-wp-bind--disabled="state.' . esc_attr( $path ) . '"' . ( $this->value( $path ) ? ' disabled' : '' );
	}

	private function checked( string $path ): string {
		return ' data-wp-bind--checked="state.' . esc_attr( $path ) . '"' . ( $this->value( $path ) ? ' checked' : '' );
	}

	/**
	 * Boolesches ARIA-Attribut («true»/«false») mit Bindung.
	 */
	private function bind_bool( string $attribute, string $path ): string {
		return ' ' . $attribute . '="' . ( $this->value( $path ) ? 'true' : 'false' ) . '" data-wp-bind--' . $attribute . '="state.' . esc_attr( $path ) . '"';
	}

	/**
	 * @param array<string,mixed> $value Kontext.
	 */
	private static function json( array $value ): string {
		return (string) wp_json_encode( $value );
	}
}
