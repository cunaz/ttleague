<?php
/**
 * Seite «Spielbetrieb › Übersicht»: offene Punkte der aktiven Saison mit Schnellaktionen.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Tk;

use TTLeague\Access\Capabilities;
use TTLeague\Domain\Match\FixtureWorkflow as Flow;
use TTLeague\Domain\Rules\SeasonRules;
use TTLeague\Frontend\Support\Format;
use TTLeague\Infrastructure\Flash;
use TTLeague\Results\ResultService;
use TTLeague\Results\Results;

/**
 * Jede Liste ist eine Tabelle mit Begegnung (Link zur Erfassungsseite), Datum, Gruppe, Status,
 * Alter und Details. Schnellaktionen sind Formulare an admin-post.php (ohne JavaScript):
 * «Bestätigen» bei provisorischen, «Abschliessen» bei strittigen und bestätigten Resultaten,
 * «Forfait» mit Siegerauswahl und Pflichtbemerkung bei Begegnungen ohne Resultat.
 */
final class DashboardPage {

	public static function render(): void {
		if ( ! current_user_can( Capabilities::TK ) ) {
			wp_die( esc_html__( 'Dazu fehlt dir die Berechtigung.', 'ttleague' ), '', array( 'response' => 403 ) );
		}
		echo self::html( Tk::dashboard()->overview(), Flash::take( Tk::FLASH, get_current_user_id() ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Alle Teile werden beim Aufbau escaped.
	}

	/**
	 * @param array<string,mixed>                                         $overview Dashboard::overview().
	 * @param array{type:string,message:string,details:list<string>}|null $flash    Rückmeldung der letzten Aktion.
	 */
	public static function html( array $overview, ?array $flash ): string {
		$out = '<div class="wrap ttl-tk"><h1>' . esc_html__( 'Spielbetrieb: Übersicht', 'ttleague' ) . '</h1>' . self::notice( $flash );

		$season = $overview['season'];
		if ( null === $season ) {
			return $out . '<p>' . esc_html__( 'Es gibt keine aktive Saison.', 'ttleague' ) . '</p></div>';
		}
		/** @var SeasonRules $rules */
		$rules = $season['rules'];

		$sections = array(
			'disputed'         => array(
				'id'    => 'ttl-einsprachen',
				'title' => __( 'Einsprachen', 'ttleague' ),
				'intro' => __( 'Die Gastseite hat Einsprache erhoben. «Abschliessen» übernimmt das erfasste Resultat; Korrekturen auf der Erfassungsseite. Alter seit der Einsprache.', 'ttleague' ),
				'empty' => __( 'Keine offenen Einsprachen.', 'ttleague' ),
			),
			'confirmation_due' => array(
				'id'    => 'ttl-bestaetigung',
				'title' => __( 'Bestätigung durch die TK fällig', 'ttleague' ),
				/* translators: %d: Tage */
				'intro' => sprintf( _n( 'Provisorische Resultate, die seit mehr als %d Tag nicht bestätigt sind. Alter seit der Erfassung.', 'Provisorische Resultate, die seit mehr als %d Tagen nicht bestätigt sind. Alter seit der Erfassung.', $rules->objection_days, 'ttleague' ), $rules->objection_days ),
				'empty' => __( 'Keine Bestätigungen fällig.', 'ttleague' ),
			),
			'missing'          => array(
				'id'    => 'ttl-fehlend',
				'title' => __( 'Fehlende Resultate', 'ttleague' ),
				/* translators: 1: Stunden, 2: Tage */
				'intro' => sprintf( __( 'Mehr als %1$d Stunden nach der Anspielzeit noch kein Resultat (ab %2$d Tagen unter Forfait-Vorschläge). Alter seit der Anspielzeit.', 'ttleague' ), $rules->entry_deadline_hours, $rules->forfeit_after_days ),
				'empty' => __( 'Keine fehlenden Resultate.', 'ttleague' ),
			),
			'forfeit'          => array(
				'id'    => 'ttl-forfait',
				'title' => __( 'Forfait-Vorschläge', 'ttleague' ),
				/* translators: %d: Tage */
				'intro' => sprintf( __( '%d Tage nach der Anspielzeit noch kein Resultat. Alter seit der Anspielzeit.', 'ttleague' ), $rules->forfeit_after_days ),
				'empty' => __( 'Keine Forfait-Vorschläge.', 'ttleague' ),
			),
			'warnings'         => array(
				'id'    => 'ttl-hinweise',
				'title' => __( 'Hinweise', 'ttleague' ),
				/* translators: %d: Tage */
				'intro' => sprintf( __( 'Hinweise aus Erfassungen und Entscheiden der letzten %d Tage, z. B. Ersatzeinsätze, Bindungen und Korrekturen der TK.', 'ttleague' ), Dashboard::WARNING_DAYS ),
				'empty' => __( 'Keine Hinweise.', 'ttleague' ),
			),
			'decisions'        => array(
				'id'    => 'ttl-entscheide',
				'title' => __( 'Letzte Entscheide', 'ttleague' ),
				'intro' => __( 'Bestätigungen, Abschlüsse, Korrekturen, Forfaits und Rücksetzungen der TK in dieser Saison.', 'ttleague' ),
				'empty' => __( 'Noch keine Entscheide.', 'ttleague' ),
			),
		);

		/* translators: %s: Saison, z. B. 2026/27 */
		$out  .= '<p>' . esc_html( sprintf( __( 'Offene Punkte der Saison %s.', 'ttleague' ), $season['name'] ) ) . '</p>';
		$links = array();
		foreach ( $sections as $key => $section ) {
			$links[] = '<li><a href="#' . esc_attr( $section['id'] ) . '">' . esc_html( $section['title'] ) . ' <span class="count">(' . count( $overview[ $key ] ) . ')</span></a></li>';
		}
		$out .= '<ul class="subsubsub ttl-tk-nav">' . implode( ' | ', $links ) . '</ul><div class="clear"></div>';

		foreach ( $sections as $key => $section ) {
			$rows = $overview[ $key ];
			$out .= '<h2 id="' . esc_attr( $section['id'] ) . '">' . esc_html( $section['title'] ) . ' <span class="count">(' . count( $rows ) . ')</span></h2>'
				. '<p class="description">' . esc_html( $section['intro'] ) . '</p>';
			$out .= array() === $rows ? '<p><em>' . esc_html( $section['empty'] ) . '</em></p>' : self::table( $rows, $key, $rules );
		}
		return $out . '</div>';
	}

	/**
	 * Alter als Text: «5 Stunden», «3 Tage».
	 */
	public static function age( ?int $hours ): string {
		if ( null === $hours ) {
			return '–';
		}
		if ( $hours < 1 ) {
			return __( 'unter 1 Stunde', 'ttleague' );
		}
		if ( $hours < 48 ) {
			/* translators: %d: Stunden */
			return sprintf( _n( '%d Stunde', '%d Stunden', $hours, 'ttleague' ), $hours );
		}
		$days = intdiv( $hours, 24 );
		/* translators: %d: Tage */
		return sprintf( _n( '%d Tag', '%d Tage', $days, 'ttleague' ), $days );
	}

	/**
	 * Entscheid als Text, z. B. «abgeschlossen» oder «Forfait für Seefeld 1».
	 *
	 * @param array<string,mixed> $row Zeile aus Dashboard::decisions() oder ::warnings().
	 */
	public static function decision_label( array $row ): string {
		$action = (string) ( $row['action'] ?? '' );
		return match ( $action ) {
			'confirm'  => __( 'bestätigt', 'ttleague' ),
			'finalize' => __( 'abgeschlossen', 'ttleague' ),
			'correct'  => __( 'korrigiert', 'ttleague' ),
			/* translators: %s: Team */
			'forfeit'  => null === ( $row['winner'] ?? null ) ? __( 'Forfait', 'ttleague' ) : sprintf( __( 'Forfait für %s', 'ttleague' ), 'home' === $row['winner'] ? $row['home'] : $row['away'] ),
			'reset'    => __( 'zurückgesetzt', 'ttleague' ),
			default    => $action,
		};
	}

	/**
	 * Art eines Ereignisses mit Hinweisen.
	 *
	 * @param array<string,mixed> $row Zeile aus Dashboard::warnings().
	 */
	public static function event_label( array $row ): string {
		return match ( (string) ( $row['event'] ?? '' ) ) {
			'entered' => __( 'Erfassung', 'ttleague' ),
			'edited'  => __( 'Änderung', 'ttleague' ),
			'decided' => 'correct' === ( $row['action'] ?? null ) ? __( 'Korrektur der TK', 'ttleague' ) : __( 'Entscheid der TK', 'ttleague' ),
			default   => (string) ( $row['event'] ?? '' ),
		};
	}

	/**
	 * Hinweis im Backend (WordPress-Klassen notice-*).
	 *
	 * @param array{type:string,message:string,details:list<string>}|null $flash Rückmeldung.
	 */
	public static function notice( ?array $flash ): string {
		if ( null === $flash ) {
			return '';
		}
		$type    = in_array( $flash['type'], array( 'success', 'info', 'warning', 'error' ), true ) ? $flash['type'] : 'info';
		$details = '';
		if ( array() !== $flash['details'] ) {
			$details = '<ul class="ttl-tk-warnings">' . implode( '', array_map( static fn( string $d ): string => '<li>' . esc_html( $d ) . '</li>', $flash['details'] ) ) . '</ul>';
		}
		return '<div class="notice notice-' . esc_attr( $type ) . ' is-dismissible"><p>' . esc_html( $flash['message'] ) . '</p>' . $details . '</div>';
	}

	/**
	 * @param list<array<string,mixed>> $rows Zeilen.
	 */
	private static function table( array $rows, string $kind, SeasonRules $rules ): string {
		$actions = 'decisions' !== $kind;
		$head    = array(
			'fixture' => __( 'Begegnung', 'ttleague' ),
			'date'    => __( 'Datum', 'ttleague' ),
			'group'   => __( 'Gruppe', 'ttleague' ),
			'status'  => __( 'Status', 'ttleague' ),
			'age'     => __( 'Alter', 'ttleague' ),
			'details' => __( 'Details', 'ttleague' ),
		);
		if ( $actions ) {
			$head['action'] = __( 'Aktion', 'ttleague' );
		}
		$out = '<table class="widefat striped ttl-tk-table"><thead><tr>';
		foreach ( $head as $key => $label ) {
			$out .= '<th scope="col" class="' . esc_attr( 'ttl-col-' . $key . ( 'fixture' === $key ? ' column-primary' : '' ) ) . '">' . esc_html( $label ) . '</th>';
		}
		$out .= '</tr></thead><tbody>';
		foreach ( $rows as $row ) {
			$out .= '<tr>'
				. '<td class="column-primary">' . self::fixture( $row ) . '</td>'
				. '<td>' . esc_html( Format::datetime( $row['scheduled_at'] ) ) . '</td>'
				. '<td>' . esc_html( $row['competition'] ) . '</td>'
				. '<td>' . esc_html( ResultService::status_label( $row['status'] ) ) . '</td>'
				. '<td' . self::since_title( $row['since'] ) . '>' . esc_html( self::age( $row['age_hours'] ) ) . '</td>'
				. '<td>' . self::details( $row, $kind, $rules ) . '</td>'
				. ( $actions ? '<td>' . self::actions( $row, $kind ) . '</td>' : '' )
				. '</tr>';
		}
		return $out . '</tbody></table>';
	}

	/**
	 * @param array<string,mixed> $row Zeile.
	 */
	private static function fixture( array $row ): string {
		$out = '<a href="' . esc_url( Results::entry_url( $row['id'] ) ) . '"><strong>' . esc_html( $row['home'] . ' – ' . $row['away'] ) . '</strong></a>';
		if ( null !== $row['home_score'] && null !== $row['away_score'] ) {
			$out .= ' <span class="ttl-tk-muted">' . esc_html( $row['home_score'] . ':' . $row['away_score'] ) . '</span>';
		}
		return $out;
	}

	/**
	 * @param array<string,mixed> $row Zeile.
	 */
	private static function details( array $row, string $kind, SeasonRules $rules ): string {
		switch ( $kind ) {
			case 'disputed':
				$out = null === $row['reason'] || '' === $row['reason'] ? '' : '<p class="ttl-tk-reason">«' . esc_html( $row['reason'] ) . '»</p>';
				/* translators: %s: Name */
				return $out . ( null === $row['by'] ? '' : '<span class="ttl-tk-muted">' . esc_html( sprintf( __( 'Einsprache von %s', 'ttleague' ), $row['by'] ) ) . '</span>' );

			case 'confirmation_due':
				/* translators: %s: Datum und Zeit */
				return esc_html( sprintf( __( 'erfasst am %s', 'ttleague' ), self::local_from_utc( $row['since'] ) ) );

			case 'missing':
				$deadline = ( new \DateTimeImmutable( (string) $row['scheduled_at'], wp_timezone() ) )->modify( '+' . $rules->entry_deadline_hours . ' hours' );
				/* translators: %s: Datum und Zeit */
				return esc_html( sprintf( __( 'Frist abgelaufen am %s', 'ttleague' ), Format::datetime( $deadline->format( 'Y-m-d H:i:s' ) ) ) );

			case 'forfeit':
				return esc_html__( 'Kein Resultat erfasst.', 'ttleague' );

			case 'warnings':
				$by = null === $row['by'] ? '' : ' ' . sprintf( /* translators: %s: Name */ __( 'von %s', 'ttleague' ), $row['by'] );
				return '<span class="ttl-tk-muted">' . esc_html( self::event_label( $row ) . $by ) . '</span>'
					. '<ul class="ttl-tk-warnings">' . implode( '', array_map( static fn( string $w ): string => '<li>' . esc_html( $w ) . '</li>', $row['warnings'] ) ) . '</ul>';

			case 'decisions':
				$by  = null === $row['by'] ? '' : ' ' . sprintf( /* translators: %s: Name */ __( 'von %s', 'ttleague' ), $row['by'] );
				$out = '<strong>' . esc_html( self::decision_label( $row ) ) . '</strong>' . esc_html( $by );
				return $out . ( null === $row['comment'] || '' === $row['comment'] ? '' : '<br><span class="ttl-tk-muted">«' . esc_html( $row['comment'] ) . '»</span>' );
		}
		return '';
	}

	/**
	 * Schnellaktionen nach Status der Begegnung; Forfait nur in den Listen ohne Resultat.
	 *
	 * @param array<string,mixed> $row  Zeile.
	 * @param string              $kind Liste.
	 */
	private static function actions( array $row, string $kind ): string {
		$id     = (int) $row['id'];
		$status = (string) $row['status'];
		if ( Flow::PROVISIONAL === $status ) {
			return self::form( $row, 'confirm', '<button type="submit" class="button button-primary button-small">' . esc_html__( 'Bestätigen', 'ttleague' ) . '</button>' );
		}
		if ( in_array( $status, array( Flow::DISPUTED, Flow::CONFIRMED ), true ) ) {
			$field = 'ttl-finalize-' . $id;
			return '<details><summary class="button button-small">' . esc_html__( 'Abschliessen …', 'ttleague' ) . '</summary>'
				. self::form(
					$row,
					'finalize',
					'<label for="' . esc_attr( $field ) . '">' . esc_html__( 'Bemerkung (freiwillig)', 'ttleague' ) . '</label>'
					. '<textarea id="' . esc_attr( $field ) . '" name="comment" rows="2" maxlength="2000"></textarea>'
					. '<button type="submit" class="button button-primary button-small">' . esc_html__( 'Resultat abschliessen', 'ttleague' ) . '</button>'
				)
				. '</details>';
		}
		if ( in_array( $kind, array( 'missing', 'forfeit' ), true ) && in_array( $status, array( Flow::SCHEDULED, Flow::POSTPONED ), true ) ) {
			$winner  = 'ttl-winner-' . $id;
			$comment = 'ttl-forfeit-' . $id;
			return '<details><summary class="button button-small">' . esc_html__( 'Forfait …', 'ttleague' ) . '</summary>'
				. self::form(
					$row,
					'forfeit',
					'<label for="' . esc_attr( $winner ) . '">' . esc_html__( 'Sieger', 'ttleague' ) . '</label>'
					. '<select id="' . esc_attr( $winner ) . '" name="winner" required><option value="">' . esc_html__( 'Bitte wählen', 'ttleague' ) . '</option>'
					. '<option value="home">' . esc_html( $row['home'] ) . '</option><option value="away">' . esc_html( $row['away'] ) . '</option></select>'
					. '<label for="' . esc_attr( $comment ) . '">' . esc_html__( 'Bemerkung (Pflicht)', 'ttleague' ) . '</label>'
					. '<textarea id="' . esc_attr( $comment ) . '" name="comment" rows="2" maxlength="2000" required></textarea>'
					. '<button type="submit" class="button button-primary button-small">' . esc_html__( 'Forfait werten', 'ttleague' ) . '</button>'
				)
				. '</details>';
		}
		return '';
	}

	/**
	 * Formular an admin-post.php mit Nonce pro Begegnung und Stand (version).
	 *
	 * @param array<string,mixed> $row Zeile.
	 */
	private static function form( array $row, string $decision, string $fields_html ): string {
		$id = (int) $row['id'];
		return '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="ttl-tk-form">'
			. '<input type="hidden" name="action" value="' . esc_attr( Tk::DECIDE ) . '">'
			. '<input type="hidden" name="fixture" value="' . esc_attr( (string) $id ) . '">'
			. '<input type="hidden" name="decision" value="' . esc_attr( $decision ) . '">'
			. '<input type="hidden" name="version" value="' . esc_attr( (string) $row['version'] ) . '">'
			. wp_nonce_field( Tk::DECIDE . '_' . $id, '_wpnonce', true, false )
			. $fields_html
			. '</form>';
	}

	private static function since_title( ?string $since ): string {
		return null === $since ? '' : ' title="' . esc_attr( self::local_from_utc( $since ) ) . '"';
	}

	/**
	 * UTC-Zeitpunkt in Ortszeit, «12.11.2026, 20:15».
	 */
	private static function local_from_utc( ?string $utc ): string {
		if ( null === $utc || '' === $utc ) {
			return '';
		}
		return (string) wp_date( 'j.n.Y, H:i', ( new \DateTimeImmutable( $utc, new \DateTimeZone( 'UTC' ) ) )->getTimestamp() );
	}
}
