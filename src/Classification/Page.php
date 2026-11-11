<?php
/**
 * Seite «Spielbetrieb › Klassierung»: Vorschlag aus der Wertungszahl und Entscheide der TK.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Classification;

use TTLeague\Access\Capabilities;
use TTLeague\Domain\Rating\ClassificationProposal;
use TTLeague\Domain\Rating\ClassificationScale;
use TTLeague\Export\Exports;
use TTLeague\Frontend\Support\Format;
use TTLeague\Infrastructure\Flash;
use TTLeague\Tk\DashboardPage;
use TTLeague\Tk\Tk;

/**
 * Zeigt alle Spieler der aktiven Saison auf einer Seite (rund 300 Zeilen) mit Club, bisheriger
 * Klassierung, Wertungszahl, Spielen, Vorschlag, Abweichung, Entscheid und Bemerkung. Filter
 * nach Club, «Vorschlag weicht ab» und «noch offen». Formulare ohne JavaScript an
 * admin-post.php (Actions). Nach dem Abschluss ist die Tabelle gesperrt.
 */
final class Page {

	public static function render(): void {
		if ( ! current_user_can( Capabilities::TK ) ) {
			wp_die( esc_html__( 'Dazu fehlt dir die Berechtigung.', 'ttleague' ), '', array( 'response' => 403 ) );
		}
		$args = array();
		foreach ( array( 'club', 'deviating', 'open' ) as $key ) {
			$args[ $key ] = isset( $_GET[ $key ] ) ? sanitize_key( wp_unslash( (string) $_GET[ $key ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Filter zum Anzeigen, ändert nichts.
		}
		$filter = ProposalFilter::from_array( $args );
		echo self::html( Classification::service()->overview( $filter ), $filter, Flash::take( Tk::FLASH, get_current_user_id() ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Alle Teile werden beim Aufbau escaped.
	}

	/**
	 * Sprungmarke einer Zeile.
	 */
	public static function row_id( int $proposal_id ): string {
		return 'ttl-proposal-' . $proposal_id;
	}

	/**
	 * @param array{season:array<string,mixed>|null,closed_at:string|null,min_games:int|null,counts:array{total:int,open:int,deviating:int,missing:int},clubs:list<array{id:int,name:string}>,rows:list<array<string,mixed>>} $state Stand aus ProposalService::overview().
	 * @param array{type:string,message:string,details:list<string>}|null $flash Rückmeldung.
	 */
	public static function html( array $state, ProposalFilter $filter, ?array $flash ): string {
		$out = '<div class="wrap ttl-tk"><h1>' . esc_html__( 'Spielbetrieb: Klassierung', 'ttleague' ) . '</h1>' . DashboardPage::notice( $flash );
		if ( null === $state['season'] ) {
			return $out . '<p>' . esc_html__( 'Es gibt keine aktive Saison.', 'ttleague' ) . '</p></div>';
		}
		$counts = $state['counts'];
		$closed = null !== $state['closed_at'];

		/* translators: %s: Name der Saison */
		$intro = sprintf( __( 'Saison %s: Das System schlägt aus der Wertungszahl die Klassierung für die nächste Saison vor, die TK entscheidet.', 'ttleague' ), $state['season']['name'] );
		if ( null !== $state['min_games'] && $state['min_games'] > 0 ) {
			/* translators: %d: Anzahl Spiele */
			$intro .= ' ' . sprintf( _n( 'Wer in dieser Saison weniger als %d gewertetes Spiel hat, behält im Vorschlag seine Klassierung.', 'Wer in dieser Saison weniger als %d gewertete Spiele hat, behält im Vorschlag seine Klassierung.', $state['min_games'], 'ttleague' ), $state['min_games'] );
		}
		$out .= '<p>' . esc_html( $intro ) . '</p>';

		if ( $closed ) {
			/* translators: %s: Datum und Uhrzeit */
			$out .= '<div class="ttl-cl-closed"><p><strong>' . esc_html( sprintf( __( 'Abgeschlossen am %s.', 'ttleague' ), self::local_time( (string) $state['closed_at'] ) ) ) . '</strong> '
				. esc_html__( 'Die Werte sind gesperrt und gelten für die nächste Meldung.', 'ttleague' ) . '</p>'
				. '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="ttl-tk-form">'
				. self::hidden( Classification::REOPEN, $filter )
				. '<label for="ttl-cl-reopen-note">' . esc_html__( 'Bemerkung (Pflicht): Warum wird wieder geöffnet?', 'ttleague' ) . '</label>'
				. '<textarea id="ttl-cl-reopen-note" name="note" rows="2" required maxlength="' . esc_attr( (string) ProposalService::NOTE_MAX ) . '"></textarea>'
				. '<button type="submit" class="button">' . esc_html__( 'Wieder öffnen', 'ttleague' ) . '</button>'
				. '</form></div>';
		}

		if ( 0 === $counts['total'] ) {
			return $out . '<p>' . esc_html__( 'Es gibt noch keinen Vorschlag.', 'ttleague' ) . '</p>' . self::toolbar( $counts, $filter, $closed ) . '</div>';
		}

		/* translators: 1: Anzahl Spieler, 2: Anzahl ohne Entscheid, 3: Anzahl abweichender Vorschläge */
		$out .= '<p>' . esc_html( sprintf( __( '%1$d Spieler, %2$d ohne Entscheid, %3$d Vorschläge weichen von der bisherigen Klassierung ab.', 'ttleague' ), $counts['total'], $counts['open'], $counts['deviating'] ) ) . '</p>';
		if ( $counts['missing'] > 0 && ! $closed ) {
			/* translators: %d: Anzahl Spieler */
			$out .= '<div class="notice notice-warning inline"><p>' . esc_html( sprintf( _n( '%d gemeldeter Spieler hat noch keinen Vorschlag. Berechne den Vorschlag neu.', '%d gemeldete Spieler haben noch keinen Vorschlag. Berechne den Vorschlag neu.', $counts['missing'], 'ttleague' ), $counts['missing'] ) ) . '</p></div>';
		}
		$out .= self::toolbar( $counts, $filter, $closed ) . self::filter_form( $state['clubs'], $filter );

		$rows = $state['rows'];
		if ( array() === $rows ) {
			return $out . '<p>' . esc_html__( 'Zu diesem Filter gibt es keine Zeilen.', 'ttleague' ) . '</p></div>';
		}

		if ( ! $closed ) {
			$open = count( array_filter( $rows, static fn( array $row ): bool => null === $row['decided'] ) );
			$out .= '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="ttl-tk-filter">'
				. self::hidden( Classification::ACCEPT_OPEN, $filter )
				/* translators: %d: Anzahl Zeilen */
				. '<button type="submit" class="button button-primary"' . disabled( 0, $open, false ) . '>' . esc_html( sprintf( __( 'Alle offenen übernehmen (%d)', 'ttleague' ), $open ) ) . '</button>'
				. '<span class="ttl-tk-muted">' . esc_html__( 'Übernimmt den Vorschlag für alle angezeigten Zeilen ohne Entscheid.', 'ttleague' ) . '</span>'
				. '</form>';
		}

		$head = array(
			'player'   => __( 'Name', 'ttleague' ),
			'club'     => __( 'Club', 'ttleague' ),
			'current'  => __( 'Bisher', 'ttleague' ),
			'rating'   => __( 'Wertungszahl', 'ttleague' ),
			'games'    => __( 'Spiele', 'ttleague' ),
			'proposed' => __( 'Vorschlag', 'ttleague' ),
			'diff'     => __( 'Abweichung', 'ttleague' ),
			'decided'  => __( 'Entscheid', 'ttleague' ),
			'note'     => __( 'Bemerkung', 'ttleague' ),
		);
		if ( ! $closed ) {
			$head['action'] = __( 'Aktion', 'ttleague' );
		}
		$out .= '<table class="widefat striped ttl-cl-table"><thead><tr>';
		foreach ( $head as $key => $label ) {
			$out .= '<th scope="col" class="' . esc_attr( 'ttl-col-' . $key . ( 'player' === $key ? ' column-primary' : '' ) ) . '">' . esc_html( $label ) . '</th>';
		}
		$out .= '</tr></thead><tbody>';

		$forms = $closed ? null : array(
			'accept'   => self::hidden( Classification::ACCEPT, $filter ),
			'override' => self::hidden( Classification::OVERRIDE, $filter ),
		);
		foreach ( $rows as $row ) {
			$out .= self::row( $row, $forms );
		}
		return $out . '</tbody></table></div>';
	}

	/**
	 * Berechnen, exportieren, abschliessen.
	 *
	 * @param array{total:int,open:int,deviating:int,missing:int} $counts Zahlen der Saison.
	 */
	private static function toolbar( array $counts, ProposalFilter $filter, bool $closed ): string {
		$post = esc_url( admin_url( 'admin-post.php' ) );
		$out  = '<div class="ttl-tk-filter">';
		if ( ! $closed ) {
			$out .= '<form method="post" action="' . $post . '" class="ttl-tk-inline">'
				. self::hidden( Classification::COMPUTE, $filter )
				. '<button type="submit" class="' . esc_attr( 0 === $counts['total'] ? 'button button-primary' : 'button' ) . '">' . esc_html( 0 === $counts['total'] ? __( 'Vorschlag berechnen', 'ttleague' ) : __( 'Vorschlag neu berechnen', 'ttleague' ) ) . '</button>'
				. '</form>';
		}
		if ( $counts['total'] > 0 ) {
			$out .= '<a class="button" href="' . esc_url( Exports::url( Classification::EXPORT, $filter->to_args() ) ) . '">' . esc_html__( 'CSV exportieren', 'ttleague' ) . '</a>';
		}
		if ( ! $closed && $counts['total'] > 0 ) {
			$ready = 0 === $counts['open'] && 0 === $counts['missing'];
			$out  .= '<form method="post" action="' . $post . '" class="ttl-tk-inline">'
				. self::hidden( Classification::CLOSE, $filter )
				. '<button type="submit" class="button"' . disabled( $ready, false, false ) . '>' . esc_html__( 'Klassierung abschliessen', 'ttleague' ) . '</button>'
				. ( $ready ? '' : '<span class="ttl-tk-muted">' . esc_html__( 'Möglich, sobald jede Zeile entschieden ist.', 'ttleague' ) . '</span>' )
				. '</form>';
		}
		return $out . '</div>';
	}

	/**
	 * @param list<array{id:int,name:string}> $clubs Clubs der Saison.
	 */
	private static function filter_form( array $clubs, ProposalFilter $filter ): string {
		$options = '<option value="0">' . esc_html__( 'Alle Clubs', 'ttleague' ) . '</option>';
		foreach ( $clubs as $club ) {
			$options .= '<option value="' . esc_attr( (string) $club['id'] ) . '"' . selected( $club['id'], $filter->club_id, false ) . '>' . esc_html( $club['name'] ) . '</option>';
		}
		return '<form method="get" action="' . esc_url( admin_url( 'admin.php' ) ) . '" class="ttl-tk-filter">'
			. '<input type="hidden" name="page" value="' . esc_attr( Classification::PAGE ) . '">'
			. '<label for="ttl-cl-club"><strong>' . esc_html__( 'Club', 'ttleague' ) . '</strong></label>'
			. '<select id="ttl-cl-club" name="club">' . $options . '</select>'
			. '<label for="ttl-cl-deviating"><input type="checkbox" id="ttl-cl-deviating" name="deviating" value="1"' . checked( $filter->deviating, true, false ) . '> ' . esc_html__( 'Vorschlag weicht ab', 'ttleague' ) . '</label>'
			. '<label for="ttl-cl-open"><input type="checkbox" id="ttl-cl-open" name="open" value="1"' . checked( $filter->open, true, false ) . '> ' . esc_html__( 'noch offen', 'ttleague' ) . '</label>'
			. '<button type="submit" class="button">' . esc_html__( 'Anzeigen', 'ttleague' ) . '</button>'
			. '</form>';
	}

	/**
	 * @param array<string,mixed>                       $row   Zeile aus ProposalRepository::rows().
	 * @param array{accept:string,override:string}|null $forms Versteckte Felder der Formulare; null, wenn abgeschlossen.
	 */
	private static function row( array $row, ?array $forms ): string {
		$id        = (int) $row['id'];
		$deviation = ClassificationProposal::deviation( $row['current'], $row['proposed'] );
		$decided   = $row['decided'];
		if ( null === $decided ) {
			$decision = '<span class="ttl-tk-status ttl-cl-open">' . esc_html__( 'offen', 'ttleague' ) . '</span>';
		} else {
			$decision = '<strong>' . esc_html( ClassificationScale::label( $decided ) ) . '</strong>'
				. ( $decided === $row['proposed'] ? '' : '<br><span class="ttl-tk-muted">' . esc_html__( 'übersteuert', 'ttleague' ) . '</span>' );
		}
		/* translators: %d: Lizenznummer */
		$license = sprintf( __( 'Lizenz %d', 'ttleague' ), $row['license_no'] );
		$out     = '<tr id="' . esc_attr( self::row_id( $id ) ) . '">'
			. '<td class="column-primary ttl-cl-player"><strong>' . esc_html( $row['name'] ) . '</strong><br><span class="ttl-tk-muted">' . esc_html( $license ) . '</span></td>'
			. '<td>' . ( null === $row['club_id'] ? '<span class="ttl-tk-muted">' . esc_html__( 'nicht mehr gemeldet', 'ttleague' ) . '</span>' : esc_html( $row['club'] ) ) . '</td>'
			. '<td>' . esc_html( ClassificationScale::label( $row['current'] ) ) . '</td>'
			. '<td>' . esc_html( Format::rating( $row['rating'] ) ) . '</td>'
			. '<td>' . esc_html( (string) $row['games'] ) . '</td>'
			. '<td>' . esc_html( ClassificationScale::label( $row['proposed'] ) ) . '</td>'
			. '<td' . ( 0 === $deviation ? '' : ' class="ttl-cl-deviating"' ) . '>' . esc_html( self::deviation( $deviation ) ) . '</td>'
			. '<td>' . $decision . '</td>'
			. '<td class="ttl-cl-note">' . esc_html( (string) $row['note'] ) . '</td>';
		if ( null !== $forms ) {
			$out .= '<td>' . self::actions( $row, $forms ) . '</td>';
		}
		return $out . '</tr>';
	}

	/**
	 * Formulare einer Zeile: Vorschlag übernehmen, eigenen Wert mit Bemerkung entscheiden.
	 *
	 * @param array<string,mixed>                  $row   Zeile.
	 * @param array{accept:string,override:string} $forms Versteckte Felder.
	 */
	private static function actions( array $row, array $forms ): string {
		$id    = (int) $row['id'];
		$post  = esc_url( admin_url( 'admin-post.php' ) );
		$ident = '<input type="hidden" name="proposal" value="' . esc_attr( (string) $id ) . '">';
		$out   = '';
		if ( $row['decided'] !== $row['proposed'] || null !== $row['note'] ) {
			$out .= '<form method="post" action="' . $post . '" class="ttl-tk-inline">' . $forms['accept'] . $ident
				. '<input type="hidden" name="proposed" value="' . esc_attr( (string) $row['proposed'] ) . '">'
				/* translators: %s: Klassierung */
				. '<button type="submit" class="button button-small">' . esc_html( sprintf( __( '%s übernehmen', 'ttleague' ), ClassificationScale::label( $row['proposed'] ) ) ) . '</button>'
				. '</form>';
		}
		$value   = 'ttl-cl-value-' . $id;
		$note    = 'ttl-cl-note-' . $id;
		$current = $row['decided'] ?? $row['proposed'];
		$options = '';
		for ( $class = ClassificationScale::MAX; $class >= ClassificationScale::MIN; $class-- ) {
			$options .= '<option value="' . esc_attr( (string) $class ) . '"' . selected( $class, $current, false ) . '>' . esc_html( ClassificationScale::label( $class ) ) . '</option>';
		}
		return $out . '<details><summary class="button button-small">' . esc_html( null === $row['decided'] ? __( 'Übersteuern', 'ttleague' ) : __( 'Entscheid ändern', 'ttleague' ) ) . '</summary>'
			. '<form method="post" action="' . $post . '" class="ttl-tk-form">' . $forms['override'] . $ident
			/* translators: %s: Name */
			. '<label for="' . esc_attr( $value ) . '">' . esc_html( sprintf( __( 'Klassierung für %s', 'ttleague' ), $row['name'] ) ) . '</label>'
			. '<select id="' . esc_attr( $value ) . '" name="value">' . $options . '</select>'
			. '<label for="' . esc_attr( $note ) . '">' . esc_html__( 'Bemerkung (Pflicht)', 'ttleague' ) . '</label>'
			. '<textarea id="' . esc_attr( $note ) . '" name="note" rows="2" required maxlength="' . esc_attr( (string) ProposalService::NOTE_MAX ) . '">' . esc_textarea( (string) $row['note'] ) . '</textarea>'
			. '<button type="submit" class="button button-small">' . esc_html__( 'Entscheiden', 'ttleague' ) . '</button>'
			. '</form></details>';
	}

	/**
	 * Versteckte Felder eines Formulars: Aktion, Nonce und der Filter der Seite für die Rückkehr.
	 */
	private static function hidden( string $action, ProposalFilter $filter ): string {
		// Nonce als eigenes Feld ohne id: wp_nonce_field() gäbe jedem der vielen Formulare dieselbe id.
		$out = '<input type="hidden" name="action" value="' . esc_attr( $action ) . '"><input type="hidden" name="_wpnonce" value="' . esc_attr( wp_create_nonce( $action ) ) . '">';
		foreach ( $filter->to_args() as $key => $value ) {
			$out .= '<input type="hidden" name="' . esc_attr( $key ) . '" value="' . esc_attr( $value ) . '">';
		}
		return $out;
	}

	/**
	 * Abweichung mit Vorzeichen: «+2», «−1», «–» ohne Abweichung.
	 */
	public static function deviation( int $deviation ): string {
		if ( 0 === $deviation ) {
			return '–';
		}
		return ( $deviation > 0 ? '+' : '−' ) . abs( $deviation );
	}

	/**
	 * Zeitpunkt (UTC) in Ortszeit der Website.
	 */
	private static function local_time( string $utc ): string {
		return (string) wp_date( 'j.n.Y, H:i', ( new \DateTimeImmutable( $utc, new \DateTimeZone( 'UTC' ) ) )->getTimestamp() );
	}
}
