<?php
/**
 * Seite «Spielbetrieb → Meldungen» der TK und ihre Listen in der Übersicht.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Registration;

use TTLeague\Access\Capabilities;
use TTLeague\Accounts\Services;
use TTLeague\Domain\Rating\ClassificationScale;
use TTLeague\Domain\Registration\RegistrationRules as Rules;
use TTLeague\Export\Exports;
use TTLeague\Frontend\Support\Format;
use TTLeague\Infrastructure\Flash;
use TTLeague\Results\Actor;
use TTLeague\Results\Results;
use TTLeague\Season\SeasonRepository;
use TTLeague\Tk\DashboardPage;
use TTLeague\Tk\Tk;

/**
 * Ohne Club: Stand je Club der gewählten Saison (geplant oder aktiv) mit Anzahl Spieler,
 * Mannschaften und offenen Neumeldungen. Mit Club: die ganze Meldung mit denselben Formularen
 * wie in «Mein Bereich», dazu die Entscheide der TK (Neumeldungen und Nachmeldungen freigeben
 * oder ablehnen, Meldung freigeben oder zurückgeben). Die Seite verlangt ttl_tk.
 */
final class ReviewPage {

	/** Anker der Listen in der Übersicht der TK. */
	public const SECTION_PENDING = 'ttl-meldungen';
	public const SECTION_VENUES  = 'ttl-lokale';

	/** Änderungen an Lokalen erscheinen so viele Tage in der Übersicht. */
	private const VENUE_DAYS = 14;

	public static function render(): void {
		if ( ! current_user_can( Capabilities::TK ) ) {
			wp_die( esc_html__( 'Dazu fehlt dir die Berechtigung.', 'ttleague' ), '', array( 'response' => 403 ) );
		}
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Auswahl zum Anzeigen, ändert nichts.
		$season_id = isset( $_GET['season'] ) ? absint( $_GET['season'] ) : 0;
		$club_id   = isset( $_GET['club'] ) ? absint( $_GET['club'] ) : 0;
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		echo self::html( $season_id, $club_id, Results::current_actor(), Flash::take( Tk::FLASH, get_current_user_id() ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Alle Teile werden beim Aufbau escaped.
	}

	/**
	 * Adresse der Seite, mit Club die Meldung dieses Clubs.
	 */
	public static function url( int $season_id = 0, int $club_id = 0 ): string {
		$args = array();
		if ( $season_id > 0 ) {
			$args['season'] = (string) $season_id;
		}
		if ( $club_id > 0 ) {
			$args['club'] = (string) $club_id;
		}
		return Tk::page_url( Registration::PAGE, $args );
	}

	/**
	 * @param array{type:string,message:string,details:list<string>}|null $flash Rückmeldung.
	 */
	public static function html( int $season_id, int $club_id, Actor $actor, ?array $flash ): string {
		$repo    = new SeasonRepository( Services::db() );
		$seasons = array_values( array_filter( array( $repo->planned(), $repo->active() ) ) );
		$out     = '<div class="wrap ttl-tk ttl-reg-admin"><h1>' . esc_html__( 'Spielbetrieb: Meldungen', 'ttleague' ) . '</h1>' . DashboardPage::notice( $flash );
		if ( array() === $seasons ) {
			return $out . '<p>' . esc_html__( 'Es gibt weder eine geplante noch eine aktive Saison.', 'ttleague' ) . '</p></div>';
		}
		$season = $seasons[0];
		foreach ( $seasons as $candidate ) {
			if ( (int) $candidate['id'] === $season_id ) {
				$season = $candidate;
			}
		}
		$tabs = '';
		foreach ( $seasons as $candidate ) {
			$label = $candidate['name'] . ' (' . ( SeasonRepository::PLANNED === $candidate['status'] ? __( 'geplant', 'ttleague' ) : __( 'aktiv', 'ttleague' ) ) . ')';
			$tabs .= '<a href="' . esc_url( self::url( (int) $candidate['id'] ) ) . '" class="nav-tab' . ( $candidate['id'] === $season['id'] ? ' nav-tab-active' : '' ) . '">' . esc_html( $label ) . '</a>';
		}
		$out .= '<nav class="nav-tab-wrapper">' . $tabs . '</nav>';
		try {
			$out .= $club_id > 0 ? self::detail( $season, $club_id, $actor ) : self::overview( $season, $actor );
		} catch ( RegistrationException $e ) {
			$out .= '<div class="notice notice-error inline"><p>' . esc_html( $e->getMessage() ) . '</p></div>'
				. '<p><a href="' . esc_url( self::url( (int) $season['id'] ) ) . '">' . esc_html__( 'Zurück zur Liste', 'ttleague' ) . '</a></p>';
		}
		return $out . '</div>';
	}

	/**
	 * Filter ttleague_tk_overview_sections: «Meldungen zu prüfen» und «Geänderte Lokale».
	 *
	 * @param mixed $sections Listen.
	 * @return list<array<string,mixed>>
	 */
	public static function overview_sections( mixed $sections ): array {
		$sections = is_array( $sections ) ? array_values( $sections ) : array();
		if ( ! current_user_can( Capabilities::TK ) ) {
			return $sections;
		}
		try {
			$pending = Registration::review()->pending( Results::current_actor() );
		} catch ( RegistrationException $e ) {
			return $sections;
		}
		$sections[] = array(
			'id'    => self::SECTION_PENDING,
			'title' => __( 'Meldungen zu prüfen', 'ttleague' ),
			'count' => count( $pending['submitted'] ) + count( $pending['requests'] ) + count( $pending['players'] ),
			'html'  => self::pending_html( $pending ),
		);
		$since      = Services::clock()->now()->modify( '-' . self::VENUE_DAYS . ' days' )->format( 'Y-m-d H:i:s' );
		$changes    = ( new RegistrationRepository( Services::db() ) )->venue_changes( $since );
		$sections[] = array(
			'id'    => self::SECTION_VENUES,
			'title' => __( 'Geänderte Lokale und Hallensperren', 'ttleague' ),
			'count' => count( $changes ),
			'html'  => self::venue_changes_html( $changes ),
		);
		return $sections;
	}

	/**
	 * @param array{submitted:list<array<string,mixed>>,requests:list<array<string,mixed>>,players:list<array<string,mixed>>} $pending Aus Review::pending().
	 */
	public static function pending_html( array $pending ): string {
		if ( array() === $pending['submitted'] && array() === $pending['requests'] && array() === $pending['players'] ) {
			return '<p>' . esc_html__( 'Nichts zu prüfen.', 'ttleague' ) . '</p>';
		}
		$out = '<table class="widefat striped ttl-tk-table"><thead><tr>'
			. '<th scope="col">' . esc_html__( 'Club', 'ttleague' ) . '</th>'
			. '<th scope="col">' . esc_html__( 'Was', 'ttleague' ) . '</th>'
			. '<th scope="col">' . esc_html__( 'Seit', 'ttleague' ) . '</th>'
			. '<th scope="col">' . esc_html__( 'Aktion', 'ttleague' ) . '</th>'
			. '</tr></thead><tbody>';
		foreach ( $pending['submitted'] as $row ) {
			/* translators: 1: Anzahl Spieler, 2: Anzahl Mannschaften */
			$what = sprintf( __( 'Meldung eingereicht: %1$d Spieler, %2$d Mannschaften', 'ttleague' ), $row['players'], $row['teams'] );
			$out .= self::pending_row( (string) $row['name'], $what, $row['submitted_at'], (int) $row['season_id'], (int) $row['club_id'] );
		}
		foreach ( $pending['requests'] as $row ) {
			/* translators: 1: Name, 2: Saison */
			$what = sprintf( __( 'Neumeldung: %1$s (Saison %2$s)', 'ttleague' ), $row['name'], $row['season'] );
			$out .= self::pending_row( (string) $row['club'], $what, (string) $row['requested_at'], (int) $row['season_id'], (int) $row['club_id'] );
		}
		foreach ( $pending['players'] as $row ) {
			/* translators: 1: Name, 2: Klassierung */
			$what = sprintf( __( 'Nachmeldung: %1$s (%2$s)', 'ttleague' ), $row['name'], ClassificationScale::label( $row['classification'] ) );
			$out .= self::pending_row( (string) $row['club'], $what, $row['registered_at'], (int) $row['season_id'], (int) $row['club_id'] );
		}
		return $out . '</tbody></table>';
	}

	/**
	 * @param list<array<string,mixed>> $changes Aus RegistrationRepository::venue_changes().
	 */
	public static function venue_changes_html( array $changes ): string {
		if ( array() === $changes ) {
			/* translators: %d: Tage */
			return '<p>' . esc_html( sprintf( __( 'Keine Änderungen in den letzten %d Tagen.', 'ttleague' ), self::VENUE_DAYS ) ) . '</p>';
		}
		$out = '<table class="widefat striped ttl-tk-table"><thead><tr>'
			. '<th scope="col">' . esc_html__( 'Club', 'ttleague' ) . '</th>'
			. '<th scope="col">' . esc_html__( 'Lokal', 'ttleague' ) . '</th>'
			. '<th scope="col">' . esc_html__( 'Änderung', 'ttleague' ) . '</th>'
			. '<th scope="col">' . esc_html__( 'Wann', 'ttleague' ) . '</th>'
			. '</tr></thead><tbody>';
		foreach ( $changes as $change ) {
			$range = isset( $change['data']['date_from'], $change['data']['date_to'] ) ? Format::date_range( (string) $change['data']['date_from'], (string) $change['data']['date_to'] ) : '';
			$what  = match ( $change['entity'] . ':' . $change['action'] ) {
				'venue:created'         => __( 'Lokal erfasst', 'ttleague' ),
				'venue:updated'         => __( 'Lokaldaten geändert', 'ttleague' ),
				/* translators: %s: Zeitraum */
				'venue_closure:created' => sprintf( __( 'Hallensperre %s eingetragen', 'ttleague' ), $range ),
				/* translators: %s: Zeitraum */
				'venue_closure:deleted' => sprintf( __( 'Hallensperre %s entfernt', 'ttleague' ), $range ),
				default                 => __( 'geändert', 'ttleague' ),
			};
			$out .= '<tr><td>' . esc_html( (string) ( $change['club'] ?? '–' ) ) . '</td>'
				. '<td>' . esc_html( (string) ( $change['venue'] ?? '–' ) ) . '</td>'
				. '<td>' . esc_html( $what ) . '</td>'
				. '<td>' . esc_html( self::local( $change['created_at'] ) ) . '</td></tr>';
		}
		return $out . '</tbody></table>';
	}

	/**
	 * @param array<string,mixed> $season Saison.
	 */
	private static function overview( array $season, Actor $actor ): string {
		$season_id = (int) $season['id'];
		$rows      = Registration::review()->overview( $actor, $season_id );
		$planned   = SeasonRepository::PLANNED === $season['status'];
		$out       = '';
		if ( $planned ) {
			$out .= '<p>' . esc_html( RegistrationView::window_label( $season ) ) . ' ' . esc_html__( 'Nach Ablauf des Fensters ändert nur noch die TK.', 'ttleague' ) . '</p>';
		} else {
			/* translators: %s: Datum */
			$out .= '<p>' . esc_html( sprintf( __( 'Clubs melden bis %s nach; danach meldet nur die TK.', 'ttleague' ), Format::date( Rules::late_registration_deadline( $season['late_registration_until'], (string) $season['end_date'] ) ) ) ) . '</p>';
		}
		$out .= '<p><a class="button" href="' . esc_url( Exports::url( RegistrationExport::CLUBS, array( 'season' => (string) $season_id ) ) ) . '">' . esc_html__( 'CSV: Stand je Club', 'ttleague' ) . '</a> '
			. '<a class="button" href="' . esc_url( Exports::url( RegistrationExport::PLAYERS, array( 'season' => (string) $season_id ) ) ) . '">' . esc_html__( 'CSV: gemeldete Spieler', 'ttleague' ) . '</a></p>';
		if ( array() === $rows ) {
			return $out . '<p>' . esc_html__( 'Für diese Saison gibt es noch keine Meldungen.', 'ttleague' ) . '</p>';
		}
		$out .= '<table class="widefat striped ttl-tk-table"><thead><tr>'
			. '<th scope="col">' . esc_html__( 'Club', 'ttleague' ) . '</th>'
			. ( $planned ? '<th scope="col">' . esc_html__( 'Stand', 'ttleague' ) . '</th>' : '' )
			. '<th scope="col">' . esc_html__( 'Spieler', 'ttleague' ) . '</th>'
			. '<th scope="col">' . esc_html__( 'Mannschaften', 'ttleague' ) . '</th>'
			. '<th scope="col">' . esc_html__( 'Offene Neumeldungen', 'ttleague' ) . '</th>'
			. '<th scope="col">' . esc_html__( 'Aktion', 'ttleague' ) . '</th>'
			. '</tr></thead><tbody>';
		foreach ( $rows as $row ) {
			$players = (string) $row['players'];
			if ( $row['provisional'] > 0 ) {
				/* translators: %d: Anzahl */
				$players .= ' ' . sprintf( __( '(%d provisorisch)', 'ttleague' ), $row['provisional'] );
			}
			$status = '';
			if ( $planned ) {
				$status = '<td><span class="' . esc_attr( 'ttl-tk-status ttl-reg-status--' . ( $row['status'] ?? 'none' ) ) . '">' . esc_html( RegistrationView::status_label( $row['status'] ) ) . '</span>'
					. ( Rules::SUBMITTED === $row['status'] && null !== $row['submitted_at'] ? '<br><span class="ttl-tk-muted">' . esc_html( self::local( $row['submitted_at'] ) ) . '</span>' : '' ) . '</td>';
			}
			$out .= '<tr><td><strong>' . esc_html( $row['name'] ) . '</strong></td>' . $status
				. '<td>' . esc_html( $players ) . '</td>'
				. '<td>' . esc_html( (string) $row['teams'] ) . '</td>'
				. '<td>' . esc_html( (string) $row['requests'] ) . '</td>'
				. '<td><a class="button button-small" href="' . esc_url( self::url( $season_id, $row['club_id'] ) ) . '">'
				. esc_html( $row['requests'] > 0 || Rules::SUBMITTED === $row['status'] || ( ! $planned && $row['provisional'] > 0 ) ? __( 'Prüfen', 'ttleague' ) : __( 'Ansehen und bearbeiten', 'ttleague' ) )
				. '<span class="screen-reader-text">: ' . esc_html( $row['name'] ) . '</span></a></td></tr>';
		}
		return $out . '</tbody></table>';
	}

	/**
	 * Meldung eines Clubs mit den Entscheiden der TK.
	 *
	 * @param array<string,mixed> $season Saison.
	 * @throws RegistrationException Wenn es die Meldung nicht gibt.
	 */
	private static function detail( array $season, int $club_id, Actor $actor ): string {
		$season_id   = (int) $season['id'];
		$state       = Registration::registration()->state( $actor, $season_id, $club_id );
		$ctx         = $state['context'];
		$review      = Registration::review();
		$suggestions = array();
		foreach ( $state['requests'] as $request ) {
			if ( 'requested' === $request['status'] ) {
				$suggestions[ $request['id'] ] = $review->suggestions( $actor, $request['id'] );
			}
		}
		$view = new RegistrationView( $state, RegistrationView::ADMIN, Registration::stamm_size(), $suggestions );
		$out  = '<p><a href="' . esc_url( self::url( $season_id ) ) . '">' . esc_html__( '← Alle Meldungen', 'ttleague' ) . '</a></p>'
			/* translators: 1: Club, 2: Saison */
			. '<h2>' . esc_html( sprintf( __( '%1$s, Saison %2$s', 'ttleague' ), $ctx->club['name'], $season['name'] ) ) . '</h2>';
		if ( $ctx->is_planned() ) {
			$out .= self::decision( $ctx, $state['violations'], count( array_filter( $state['requests'], static fn( array $r ): bool => 'requested' === $r['status'] ) ) );
		}
		return $out . $view->registration() . $view->venues();
	}

	/**
	 * Meldung freigeben oder zurückgeben.
	 *
	 * @param list<array{code:string,field:string,message:string}> $violations Offene Verstösse.
	 */
	private static function decision( RegistrationContext $ctx, array $violations, int $open_requests ): string {
		$club   = $ctx->club_id();
		$season = $ctx->season_id();
		$hidden = static fn( string $action ): string => '<input type="hidden" name="action" value="' . esc_attr( $action ) . '">'
			. '<input type="hidden" name="club" value="' . esc_attr( (string) $club ) . '">'
			. '<input type="hidden" name="season" value="' . esc_attr( (string) $season ) . '">'
			. '<input type="hidden" name="back" value="tk">'
			. '<input type="hidden" name="_wpnonce" value="' . esc_attr( wp_create_nonce( Actions::nonce( $action, $club ) ) ) . '">';
		$out    = '<div class="ttl-reg__decision"><h3>' . esc_html__( 'Entscheid der TK', 'ttleague' ) . '</h3>';
		if ( Rules::REVIEWED !== $ctx->status() ) {
			if ( array() !== $violations || $open_requests > 0 ) {
				$out .= '<p class="ttl-tk-muted">' . esc_html__( 'Freigeben lässt sich die Meldung, sobald keine Punkte und keine Neumeldungen mehr offen sind.', 'ttleague' ) . '</p>';
			} else {
				$out .= '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="ttl-tk-inline">' . $hidden( Actions::APPROVE )
					. '<button type="submit" class="button button-primary">' . esc_html__( 'Meldung freigeben', 'ttleague' ) . '</button>'
					. '<span class="ttl-tk-muted">' . esc_html__( 'Setzt alle Spieler des Clubs in dieser Saison auf «freigegeben» und benachrichtigt den TK-Chef.', 'ttleague' ) . '</span></form>';
			}
		} else {
			$out .= '<p>' . esc_html__( 'Die Meldung ist geprüft.', 'ttleague' ) . '</p>';
		}
		if ( Rules::OPEN !== $ctx->status() ) {
			$id   = 'ttl-reg-return-' . $club;
			$out .= '<details><summary class="button">' . esc_html__( 'An den Club zurückgeben', 'ttleague' ) . '</summary>'
				. '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="ttl-tk-form">' . $hidden( Actions::RETURN )
				. '<label for="' . esc_attr( $id ) . '">' . esc_html__( 'Bemerkung an den Club (Pflicht)', 'ttleague' ) . '</label>'
				. '<textarea id="' . esc_attr( $id ) . '" name="note" rows="3" required></textarea>'
				. '<button type="submit" class="button">' . esc_html__( 'Zurückgeben', 'ttleague' ) . '</button></form></details>';
		}
		return $out . '</div>';
	}

	private static function pending_row( string $club, string $what, ?string $since, int $season_id, int $club_id ): string {
		return '<tr><td><strong>' . esc_html( $club ) . '</strong></td><td>' . esc_html( $what ) . '</td>'
			. '<td>' . esc_html( null === $since ? '–' : self::local( $since ) ) . '</td>'
			. '<td><a class="button button-small" href="' . esc_url( self::url( $season_id, $club_id ) ) . '">' . esc_html__( 'Prüfen', 'ttleague' )
			. '<span class="screen-reader-text">: ' . esc_html( $club ) . '</span></a></td></tr>';
	}

	/**
	 * Systemzeitpunkt (UTC) in Ortszeit der Website.
	 */
	private static function local( string $utc ): string {
		return Format::datetime( get_date_from_gmt( $utc, 'Y-m-d H:i:s' ) );
	}
}
