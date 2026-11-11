<?php
/**
 * Seite «Spielbetrieb › Ranglistenturnier»: Anmeldungen, Kategorien, Runden und Gruppen.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\RankingTournament;

use TTLeague\Access\Capabilities;
use TTLeague\Domain\Ranking\Categories;
use TTLeague\Domain\Ranking\RlPlayerStatus;
use TTLeague\Export\Exports as ExportLinks;
use TTLeague\Frontend\Support\Format;
use TTLeague\Infrastructure\Flash;
use TTLeague\Tk\DashboardPage;
use TTLeague\Tk\Tk;

/**
 * Zwei Ansichten: «Anmeldungen» (Einstellungen, Personen anmelden und abmelden, Kategorien, CSV)
 * und «Runden» (anlegen, einteilen, Gruppen ändern, freigeben, abschliessen). Alle Formulare
 * gehen ohne JavaScript an admin-post.php (AdminActions). Die Ausgabe wird beim Aufbau escaped.
 */
final class AdminPage {

	public static function render(): void {
		if ( ! current_user_can( Capabilities::TK ) ) {
			wp_die( esc_html__( 'Dazu fehlt dir die Berechtigung.', 'ttleague' ), '', array( 'response' => 403 ) );
		}
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Auswahl zum Anzeigen, ändert nichts.
		$view  = isset( $_GET['view'] ) ? sanitize_key( wp_unslash( (string) $_GET['view'] ) ) : 'anmeldungen';
		$round = isset( $_GET['round'] ) ? absint( $_GET['round'] ) : 0;
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		echo self::html( $view, $round, Flash::take( Tk::FLASH, get_current_user_id() ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Alle Teile werden beim Aufbau escaped.
	}

	/**
	 * Adresse einer Ansicht der Seite.
	 */
	public static function url( string $view = 'anmeldungen', int $round = 0 ): string {
		$args = array( 'view' => $view );
		if ( $round > 0 ) {
			$args['round'] = (string) $round;
		}
		return Tk::page_url( RankingTournament::PAGE, $args );
	}

	/**
	 * @param array{type:string,message:string,details:list<string>}|null $flash Rückmeldung.
	 */
	public static function html( string $view, int $round_id, ?array $flash ): string {
		$repo   = RankingTournament::repository();
		$out    = '<div class="wrap ttl-tk"><h1>' . esc_html__( 'Spielbetrieb: Ranglistenturnier', 'ttleague' ) . '</h1>' . DashboardPage::notice( $flash );
		$season = $repo->active_season_id();
		if ( null === $season ) {
			return $out . '<p>' . esc_html__( 'Es gibt keine aktive Saison.', 'ttleague' ) . '</p></div>';
		}
		$competition = $repo->competition_of_season( $season );
		if ( null === $competition ) {
			return $out . '<p>' . esc_html__( 'Für die laufende Saison ist noch kein Ranglistenturnier eingerichtet.', 'ttleague' ) . '</p>'
				. self::settings_form( $season, null ) . '</div>';
		}

		$view = 'runden' === $view ? 'runden' : 'anmeldungen';
		$out .= '<nav class="nav-tab-wrapper ttl-tk-nav">';
		foreach ( array(
			'anmeldungen' => __( 'Anmeldungen', 'ttleague' ),
			'runden'      => __( 'Runden', 'ttleague' ),
		) as $key => $label ) {
			$out .= '<a class="nav-tab' . ( $key === $view ? ' nav-tab-active' : '' ) . '" href="' . esc_url( self::url( $key ) ) . '">' . esc_html( $label ) . '</a>';
		}
		$out .= '</nav>';

		if ( 'anmeldungen' === $view ) {
			return $out . self::registrations( $competition ) . '</div>';
		}
		$round = $round_id > 0 ? $repo->round( $round_id ) : null;
		if ( null !== $round && $round['competition_id'] === $competition['id'] ) {
			return $out . self::round( $competition, $round ) . '</div>';
		}
		return $out . self::rounds( $competition ) . '</div>';
	}

	/**
	 * Ansicht «Anmeldungen».
	 *
	 * @param array<string,mixed> $competition Konkurrenz.
	 */
	private static function registrations( array $competition ): string {
		$repo         = RankingTournament::repository();
		$participants = $repo->participants( $competition['id'], $competition['season_id'] );
		$numbers      = Categories::numbers( $competition['categories'] );
		$counts       = array();
		foreach ( $participants as $participant ) {
			if ( Registrations::REGISTERED === $participant['status'] ) {
				$counts[ $participant['category'] ] = ( $counts[ $participant['category'] ] ?? 0 ) + 1;
			}
		}
		ksort( $counts );
		$summary = array();
		foreach ( $counts as $category => $count ) {
			/* translators: 1: Kategorie, 2: Anzahl */
			$summary[] = 0 === $category ? sprintf( __( 'ohne Kategorie: %2$d', 'ttleague' ), $category, $count ) : sprintf( __( 'Kategorie %1$d: %2$d', 'ttleague' ), $category, $count );
		}
		$deadline = null === $competition['deadline']
			? __( 'Kein Anmeldeschluss hinterlegt.', 'ttleague' )
			/* translators: %s: Datum */
			: sprintf( __( 'Anmeldeschluss: %s. Danach meldet nur noch die TK an.', 'ttleague' ), Format::date_plain( $competition['deadline'] ) );

		$out = '<h2>' . esc_html__( 'Anmeldungen', 'ttleague' ) . ' <span class="count">(' . esc_html( (string) array_sum( $counts ) ) . ')</span></h2>'
			. '<p>' . esc_html( $deadline . ( array() === $summary ? '' : ' ' . implode( ', ', $summary ) . '.' ) ) . '</p>'
			. '<p><a class="button" href="' . esc_url( ExportLinks::url( Exports::PARTICIPANTS ) ) . '">' . esc_html__( 'Anmeldungen als CSV', 'ttleague' ) . '</a> '
			. '<a class="button" href="' . esc_url( ExportLinks::url( Exports::STANDINGS ) ) . '">' . esc_html__( 'Gesamtrangliste als CSV', 'ttleague' ) . '</a></p>';

		// Person anmelden.
		$options = '';
		$club    = null;
		foreach ( $repo->season_players( $competition['season_id'], $competition['id'] ) as $player ) {
			if ( null !== $player['participant'] && Registrations::REGISTERED === $player['participant']['status'] ) {
				continue;
			}
			if ( $club !== $player['club_short'] ) {
				$options .= ( null === $club ? '' : '</optgroup>' ) . '<optgroup label="' . esc_attr( $player['club_short'] ) . '">';
				$club     = $player['club_short'];
			}
			$options .= '<option value="' . esc_attr( (string) $player['player_id'] ) . '">' . esc_html( $player['name'] . ' (' . Format::classification( $player['classification'] ) . ')' ) . '</option>';
		}
		if ( '' !== $options ) {
			$out .= '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="ttl-tk-filter">'
				. self::hidden( 'register', 0 )
				. '<label for="ttl-rl-player"><strong>' . esc_html__( 'Person anmelden', 'ttleague' ) . '</strong></label>'
				. '<select id="ttl-rl-player" name="player" required><option value="">' . esc_html__( 'Bitte wählen', 'ttleague' ) . '</option>' . $options . '</optgroup></select>'
				. '<label for="ttl-rl-register-category">' . esc_html__( 'Kategorie', 'ttleague' ) . '</label>'
				. self::category_select( 'ttl-rl-register-category', $numbers, 0, __( 'nach Klassierung', 'ttleague' ) )
				. '<button type="submit" class="button button-primary">' . esc_html__( 'Anmelden', 'ttleague' ) . '</button></form>';
		}

		if ( array() === $participants ) {
			return $out . '<p>' . esc_html__( 'Noch niemand ist angemeldet.', 'ttleague' ) . '</p>' . self::settings_form( $competition['season_id'], $competition );
		}
		$out .= '<table class="widefat striped ttl-tk-table"><thead><tr>'
			. '<th scope="col">' . esc_html__( 'Name', 'ttleague' ) . '</th>'
			. '<th scope="col">' . esc_html__( 'Club', 'ttleague' ) . '</th>'
			. '<th scope="col">' . esc_html__( 'Klassierung', 'ttleague' ) . '</th>'
			. '<th scope="col">' . esc_html__( 'Kategorie', 'ttleague' ) . '</th>'
			. '<th scope="col">' . esc_html__( 'Status', 'ttleague' ) . '</th>'
			. '<th scope="col">' . esc_html__( 'Aktion', 'ttleague' ) . '</th>'
			. '</tr></thead><tbody>';
		foreach ( $participants as $participant ) {
			$id         = $participant['player_id'];
			$registered = Registrations::REGISTERED === $participant['status'];
			$field      = 'ttl-rl-category-' . $id;
			$out       .= '<tr id="' . esc_attr( 'ttl-rl-player-' . $id ) . '"><td><strong>' . esc_html( $participant['name'] ) . '</strong></td>'
				. '<td>' . esc_html( (string) $participant['club_short'] ) . '</td>'
				. '<td>' . esc_html( Format::classification( $participant['classification'] ) ) . '</td>'
				. '<td><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="ttl-tk-inline">'
				. self::hidden( 'category', $id )
				/* translators: %s: Name */
				. '<label class="screen-reader-text" for="' . esc_attr( $field ) . '">' . esc_html( sprintf( __( 'Kategorie von %s', 'ttleague' ), $participant['name'] ) ) . '</label>'
				. self::category_select( $field, $numbers, $participant['category'], __( 'keine', 'ttleague' ) )
				. '<button type="submit" class="button button-small">' . esc_html__( 'Setzen', 'ttleague' ) . '</button></form></td>'
				. '<td>' . esc_html( $registered ? __( 'angemeldet', 'ttleague' ) : __( 'abgemeldet', 'ttleague' ) ) . '</td>'
				. '<td><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">'
				. self::hidden( $registered ? 'withdraw' : 'register', $id )
				. '<button type="submit" class="button button-small">' . esc_html( $registered ? __( 'Abmelden', 'ttleague' ) : __( 'Wieder anmelden', 'ttleague' ) ) . '</button></form></td></tr>';
		}
		return $out . '</tbody></table>' . self::settings_form( $competition['season_id'], $competition );
	}

	/**
	 * Formular «Turnier einrichten» bzw. «Einstellungen».
	 *
	 * @param array<string,mixed>|null $competition Konkurrenz oder null.
	 */
	private static function settings_form( int $season_id, ?array $competition ): string {
		$pairs = array();
		foreach ( array_reverse( $competition['categories'] ?? array() ) as $category ) {
			$pairs[ $category['no'] ] = $category['no'] . ':' . $category['min'];
		}
		ksort( $pairs );
		return '<h2>' . esc_html( null === $competition ? __( 'Turnier einrichten', 'ttleague' ) : __( 'Einstellungen', 'ttleague' ) ) . '</h2>'
			. '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="ttl-tk-form">'
			. self::hidden( 'configure', $season_id )
			. '<label for="ttl-rl-name">' . esc_html__( 'Name', 'ttleague' ) . '</label>'
			. '<input type="text" id="ttl-rl-name" name="name" maxlength="80" value="' . esc_attr( (string) ( $competition['name'] ?? __( 'Ranglistenturnier', 'ttleague' ) ) ) . '">'
			. '<label for="ttl-rl-categories">' . esc_html__( 'Kategorien als «Nummer:Klassierung ab», z. B. 1:16, 2:9, 3:1', 'ttleague' ) . '</label>'
			. '<input type="text" id="ttl-rl-categories" name="categories" value="' . esc_attr( implode( ', ', $pairs ) ) . '">'
			. '<label for="ttl-rl-deadline">' . esc_html__( 'Anmeldeschluss', 'ttleague' ) . '</label>'
			. '<input type="date" id="ttl-rl-deadline" name="registration_deadline" value="' . esc_attr( (string) ( $competition['deadline'] ?? '' ) ) . '">'
			. '<label for="ttl-rl-start">' . esc_html__( 'Spielbeginn (leer: Öffnungszeit des Lokals)', 'ttleague' ) . '</label>'
			. '<input type="time" id="ttl-rl-start" name="start_time" value="' . esc_attr( substr( (string) ( $competition['start_time'] ?? '' ), 0, 5 ) ) . '">'
			. '<button type="submit" class="button button-primary">' . esc_html__( 'Speichern', 'ttleague' ) . '</button></form>';
	}

	/**
	 * Ansicht «Runden»: Liste und neue Runde.
	 *
	 * @param array<string,mixed> $competition Konkurrenz.
	 */
	private static function rounds( array $competition ): string {
		$repo   = RankingTournament::repository();
		$rounds = $repo->rounds( $competition['id'] );
		$out    = '<h2>' . esc_html__( 'Runden', 'ttleague' ) . '</h2>';
		if ( array() === $rounds ) {
			$out .= '<p>' . esc_html__( 'Noch keine Runde angelegt.', 'ttleague' ) . '</p>';
		} else {
			$out .= '<table class="widefat striped ttl-tk-table"><thead><tr>'
				. '<th scope="col">' . esc_html__( 'Runde', 'ttleague' ) . '</th>'
				. '<th scope="col">' . esc_html__( 'Woche', 'ttleague' ) . '</th>'
				. '<th scope="col">' . esc_html__( 'Status', 'ttleague' ) . '</th>'
				. '<th scope="col">' . esc_html__( 'Gruppen', 'ttleague' ) . '</th>'
				. '<th scope="col">' . esc_html__( 'Gruppenzettel', 'ttleague' ) . '</th>'
				. '</tr></thead><tbody>';
			foreach ( $rounds as $round ) {
				$groups  = $repo->groups( $round['id'] );
				$entered = count( array_filter( $groups, static fn( array $group ): bool => null !== $group['entered_at'] ) );
				$out    .= '<tr><td><a href="' . esc_url( self::url( 'runden', $round['id'] ) ) . '"><strong>' . esc_html( Notices::round_label( $round ) ) . '</strong></a></td>'
					. '<td>' . esc_html( Format::date_range( $round['date_from'], $round['date_to'] ) ) . '</td>'
					. '<td>' . esc_html( Rounds::status_label( $round['status'] ) ) . '</td>'
					. '<td>' . esc_html( (string) count( $groups ) ) . '</td>'
					/* translators: 1: erfasste Gruppen, 2: alle Gruppen */
					// Runden aus Altdaten sind abgeschlossen, ohne dass je ein Gruppenzettel erfasst wurde.
					. '<td>' . esc_html( Rounds::PLANNED === $round['status'] || ( Rounds::CLOSED === $round['status'] && 0 === $entered ) ? '–' : sprintf( __( '%1$d von %2$d', 'ttleague' ), $entered, count( $groups ) ) ) . '</td></tr>';
			}
			$out .= '</tbody></table>';
		}
		$last = array() === $rounds ? null : $rounds[ count( $rounds ) - 1 ];
		if ( null !== $last && $last['is_final'] ) {
			return $out;
		}
		return $out . '<h2>' . esc_html__( 'Neue Runde', 'ttleague' ) . '</h2>'
			. '<p class="ttl-tk-muted">' . esc_html__( 'Eine neue Runde ist ein Entwurf: Angemeldete melden ihre Sperrdaten, öffentlich wird sie erst mit der Freigabe.', 'ttleague' ) . '</p>'
			. '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="ttl-tk-filter">'
			. self::hidden( 'round_create', $competition['id'] )
			. self::week_fields( null, 'neu' )
			. '<button type="submit" class="button button-primary">' . esc_html__( 'Runde anlegen', 'ttleague' ) . '</button></form>';
	}

	/**
	 * Eine Runde mit ihren Gruppen und den Aktionen ihres Status.
	 *
	 * @param array<string,mixed> $competition Konkurrenz.
	 * @param array<string,mixed> $round       Runde.
	 */
	private static function round( array $competition, array $round ): string {
		$repo    = RankingTournament::repository();
		$service = RankingTournament::rounds();
		$groups  = $repo->groups( $round['id'] );
		$planned = Rounds::PLANNED === $round['status'];
		$open    = Rounds::CLOSED !== $round['status'];
		$out     = '<p><a href="' . esc_url( self::url( 'runden' ) ) . '">' . esc_html__( '← Alle Runden', 'ttleague' ) . '</a></p>'
			. '<h2>' . esc_html( Notices::round_label( $round ) . ', ' . Format::date_range( $round['date_from'], $round['date_to'] ) ) . ' <span class="ttl-tk-status">' . esc_html( Rounds::status_label( $round['status'] ) ) . '</span></h2>';

		if ( $planned ) {
			$out .= '<p>' . esc_html__( 'Diese Runde ist ein Entwurf und nicht öffentlich. «Einteilen» schlägt Gruppen vor und ersetzt einen bestehenden Vorschlag. Mit der Freigabe geht das Aufgebot hinaus.', 'ttleague' ) . '</p>'
				. '<div class="ttl-tk-filter">'
				. self::button( 'propose', $round['id'], $round['id'], array() === $groups ? __( 'Einteilen', 'ttleague' ) : __( 'Neu einteilen', 'ttleague' ), array() === $groups )
				. ( array() === $groups ? '' : self::button( 'publish', $round['id'], $round['id'], __( 'Freigeben und Aufgebot verschicken', 'ttleague' ), true ) )
				. self::button( 'round_delete', $round['id'], $round['id'], __( 'Runde löschen', 'ttleague' ), false )
				. '</div>'
				. '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="ttl-tk-filter">'
				. self::hidden( 'round_update', $round['id'], $round['id'] )
				. self::week_fields( $round, (string) $round['id'] )
				. '<button type="submit" class="button">' . esc_html__( 'Woche speichern', 'ttleague' ) . '</button></form>';
		}

		$participants = $repo->participants( $competition['id'], $competition['season_id'], true );
		$blocked      = null === $round['date_from'] || null === $round['date_to'] ? array() : $repo->blocked_dates( array_column( $participants, 'person_id' ), $round['date_from'], $round['date_to'] );
		$answered     = null === $round['date_from'] || null === $round['date_to'] ? array() : $repo->answered( array_column( $participants, 'person_id' ), $round['date_from'], $round['date_to'] );
		if ( $planned ) {
			$missing = count( $participants ) - count( $answered );
			/* translators: 1: Personen mit gemeldeten Sperrdaten, 2: alle Angemeldeten */
			$out .= '<p>' . esc_html( sprintf( __( 'Sperrdaten gemeldet: %1$d von %2$d Angemeldeten.', 'ttleague' ), count( $answered ), count( $participants ) ) )
				. ( $missing > 0 ? ' ' . esc_html__( 'Wer nichts gemeldet hat, gilt beim Einteilen als an jedem Abend frei.', 'ttleague' ) : '' ) . '</p>';
		}

		// Nicht zugeteilte Angemeldete.
		$unassigned = $open ? $service->unassigned( $round['id'] ) : array();
		if ( array() !== $unassigned ) {
			$names = array();
			foreach ( $unassigned as $row ) {
				/* translators: 1: Name, 2: Kategorie */
				$names[] = 0 === $row['category'] ? sprintf( __( '%1$s (ohne Kategorie)', 'ttleague' ), $row['name'] ) : sprintf( __( '%1$s (Kat. %2$d)', 'ttleague' ), $row['name'], $row['category'] );
			}
			$out .= '<div class="notice notice-warning inline"><p><strong>' . esc_html__( 'Nicht zugeteilt:', 'ttleague' ) . '</strong> ' . esc_html( implode( ', ', $names ) ) . '</p></div>';
		}

		$by_player = array_column( $participants, null, 'player_id' );
		$venues    = $repo->all_venues();
		foreach ( $groups as $group ) {
			$out .= self::group( $round, $group, $by_player, $unassigned, $groups, $venues, $blocked );
		}
		if ( array() === $groups ) {
			$out .= '<p>' . esc_html__( 'Noch keine Gruppen.', 'ttleague' ) . '</p>';
		}

		if ( $open ) {
			$numbers = array_values( array_unique( array_filter( array_column( $participants, 'category' ) ) ) );
			sort( $numbers );
			if ( array() !== $numbers ) {
				$out .= '<h3>' . esc_html__( 'Neue Gruppe', 'ttleague' ) . '</h3>'
					. '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="ttl-tk-filter">'
					. self::hidden( 'group_save', 0, $round['id'] )
					. '<label for="ttl-rl-new-group">' . esc_html__( 'Kategorie', 'ttleague' ) . '</label>'
					. self::category_select( 'ttl-rl-new-group', $numbers, $numbers[0], null )
					. '<button type="submit" class="button">' . esc_html__( 'Leere Gruppe anlegen', 'ttleague' ) . '</button></form>';
			}
		}
		if ( Rounds::PUBLISHED === $round['status'] ) {
			$out .= self::closing_form( $round );
		}
		return $out;
	}

	/**
	 * Eine Gruppe: Kopf, Mitglieder, Formular zum Ändern.
	 *
	 * @param array<string,mixed>            $round      Runde.
	 * @param array<string,mixed>            $group      Gruppe.
	 * @param array<int,array<string,mixed>> $by_player  Angemeldete nach Spieler.
	 * @param list<array<string,mixed>>      $unassigned Nicht Zugeteilte.
	 * @param list<array<string,mixed>>      $groups     Alle Gruppen der Runde.
	 * @param list<array<string,mixed>>      $venues     Lokale.
	 * @param array<int,list<string>>        $blocked    Person => gesperrte Abende.
	 */
	private static function group( array $round, array $group, array $by_player, array $unassigned, array $groups, array $venues, array $blocked ): string {
		$id      = $group['id'];
		$open    = Rounds::CLOSED !== $round['status'];
		$entered = null !== $group['entered_at'];
		$date    = null === $group['start_at'] ? null : substr( $group['start_at'], 0, 10 );
		$meta    = array();
		$when    = Format::datetime( $group['start_at'] );
		$meta[]  = '' === $when ? __( 'kein Termin', 'ttleague' ) : $when;
		$meta[]  = null === $group['venue'] ? __( 'kein Lokal', 'ttleague' ) : $group['venue']['name'] . ( null === $group['host'] ? '' : ' (' . $group['host']['short'] . ')' );
		if ( Rounds::PLANNED !== $round['status'] ) {
			$meta[] = $entered ? __( 'Gruppenzettel erfasst', 'ttleague' ) : __( 'Gruppenzettel fehlt', 'ttleague' );
		}
		$out = '<h3 id="' . esc_attr( 'ttl-rl-group-' . $id ) . '">' . esc_html( Rounds::group_label( $group ) ) . ' <span class="ttl-tk-muted">' . esc_html( implode( ' · ', $meta ) ) . '</span></h3>';
		if ( '' !== trim( (string) $group['note'] ) ) {
			$out .= '<p class="ttl-tk-muted">' . esc_html( (string) $group['note'] ) . '</p>';
		}

		$rows = '';
		foreach ( $group['members'] as $position => $member ) {
			$marks = array();
			if ( $member['player_id'] === $group['chief_player_id'] ) {
				$marks[] = __( 'Gruppenchef', 'ttleague' );
			}
			if ( null !== $date && in_array( $date, $blocked[ $member['person_id'] ] ?? array(), true ) ) {
				$marks[] = __( 'an diesem Abend gesperrt', 'ttleague' );
			}
			if ( RlPlayerStatus::Scheduled->value !== $member['status'] ) {
				$marks[] = GroupEntry::status_label( $member['status'] );
			}
			$rows .= '<tr><td>' . esc_html( (string) ( $position + 1 ) ) . '</td><td>' . esc_html( $member['name'] ) . '</td><td>' . esc_html( (string) $member['club_short'] ) . '</td>'
				. '<td>' . esc_html( Format::classification( $member['classification'] ) ) . '</td><td>' . esc_html( implode( ', ', $marks ) ) . '</td>'
				. '<td>' . esc_html( null === $member['rank_no'] ? '–' : $member['rank_no'] . '.' ) . '</td><td>' . esc_html( Format::points( $member['points'] ) ) . '</td></tr>';
		}
		$out .= '<table class="widefat striped ttl-tk-table"><thead><tr><th scope="col">' . esc_html__( 'Nr.', 'ttleague' ) . '</th><th scope="col">' . esc_html__( 'Name', 'ttleague' ) . '</th><th scope="col">' . esc_html__( 'Club', 'ttleague' ) . '</th>'
			. '<th scope="col">' . esc_html__( 'Klassierung', 'ttleague' ) . '</th><th scope="col">' . esc_html__( 'Hinweis', 'ttleague' ) . '</th><th scope="col">' . esc_html__( 'Rang', 'ttleague' ) . '</th><th scope="col">' . esc_html__( 'Punkte', 'ttleague' ) . '</th></tr></thead><tbody>'
			. ( '' === $rows ? '<tr><td colspan="7">' . esc_html__( 'Noch niemand eingeteilt.', 'ttleague' ) . '</td></tr>' : $rows ) . '</tbody></table>';

		if ( Rounds::PLANNED !== $round['status'] ) {
			$out .= '<p><a class="button button-small" href="' . esc_url( RankingTournament::entry_url( $id ) ) . '">' . esc_html( $open ? ( $entered ? __( 'Gruppenzettel ändern', 'ttleague' ) : __( 'Gruppenzettel nachtragen', 'ttleague' ) ) : __( 'Gruppenzettel ansehen', 'ttleague' ) ) . '</a></p>';
		}
		if ( ! $open ) {
			return $out;
		}

		// Formular: Lokal, Zeit, Gruppenchef, Bemerkung, Mitglieder.
		$venue_options = '<option value="0">' . esc_html__( 'kein Lokal', 'ttleague' ) . '</option>';
		foreach ( $venues as $venue ) {
			$venue_options .= '<option value="' . esc_attr( (string) $venue['id'] ) . '"' . selected( $venue['id'], $group['venue_id'], false ) . '>'
				. esc_html( $venue['club_short'] . ' – ' . $venue['name'] . ( null === $venue['weekday'] ? '' : ' (' . Format::weekday_short( $venue['weekday'] ) . ')' ) ) . '</option>';
		}
		$chief_options = '<option value="0">' . esc_html__( 'automatisch', 'ttleague' ) . '</option>';
		$member_boxes  = '';
		foreach ( $group['members'] as $member ) {
			$chief_options .= '<option value="' . esc_attr( (string) $member['player_id'] ) . '"' . selected( $member['player_id'], $group['chief_player_id'], false ) . '>' . esc_html( $member['name'] ) . '</option>';
			$member_boxes  .= '<label><input type="checkbox" name="members[]" value="' . esc_attr( (string) $member['player_id'] ) . '" checked' . disabled( $entered, true, false ) . '> ' . esc_html( $member['name'] ) . '</label><br>';
			// Ein gesperrtes Kästchen wird nicht gesendet; die Mitglieder bleiben über ein verstecktes Feld.
			$member_boxes .= $entered ? '<input type="hidden" name="members[]" value="' . esc_attr( (string) $member['player_id'] ) . '">' : '';
		}
		$add_options = '';
		foreach ( $unassigned as $row ) {
			if ( $row['category'] === $group['category'] ) {
				$add_options .= '<option value="' . esc_attr( (string) $row['player_id'] ) . '">' . esc_html( $row['name'] . ' – ' . __( 'nicht zugeteilt', 'ttleague' ) ) . '</option>';
			}
		}
		foreach ( $groups as $other ) {
			if ( $other['id'] === $id || $other['category'] !== $group['category'] || null !== $other['entered_at'] ) {
				continue;
			}
			foreach ( $other['members'] as $member ) {
				if ( isset( $by_player[ $member['player_id'] ] ) ) {
					/* translators: %d: Gruppennummer */
					$add_options .= '<option value="' . esc_attr( (string) $member['player_id'] ) . '">' . esc_html( $member['name'] . ' – ' . sprintf( __( 'aus Gruppe %d', 'ttleague' ), $other['group_no'] ) ) . '</option>';
				}
			}
		}
		$prefix = 'ttl-rl-g' . $id;
		$out   .= '<details><summary class="button button-small">' . esc_html__( 'Gruppe ändern', 'ttleague' ) . '</summary>'
			. '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="ttl-tk-form">'
			. self::hidden( 'group_save', $id, $round['id'] )
			. '<label for="' . esc_attr( $prefix . '-venue' ) . '">' . esc_html__( 'Lokal (der Club ist Platzclub)', 'ttleague' ) . '</label>'
			. '<select id="' . esc_attr( $prefix . '-venue' ) . '" name="venue_id">' . $venue_options . '</select>'
			. '<label for="' . esc_attr( $prefix . '-start' ) . '">' . esc_html__( 'Datum und Zeit', 'ttleague' ) . '</label>'
			. '<input type="datetime-local" id="' . esc_attr( $prefix . '-start' ) . '" name="start_at" value="' . esc_attr( null === $group['start_at'] ? '' : str_replace( ' ', 'T', substr( $group['start_at'], 0, 16 ) ) ) . '">'
			. '<label for="' . esc_attr( $prefix . '-chief' ) . '">' . esc_html__( 'Gruppenchef', 'ttleague' ) . '</label>'
			. '<select id="' . esc_attr( $prefix . '-chief' ) . '" name="chief_player_id">' . $chief_options . '</select>'
			. '<label for="' . esc_attr( $prefix . '-note' ) . '">' . esc_html__( 'Bemerkung (öffentlich)', 'ttleague' ) . '</label>'
			. '<input type="text" id="' . esc_attr( $prefix . '-note' ) . '" name="note" maxlength="500" value="' . esc_attr( (string) $group['note'] ) . '">'
			. '<fieldset><legend>' . esc_html__( 'Mitglieder (Häkchen entfernen = aus der Gruppe nehmen)', 'ttleague' ) . '</legend>' . $member_boxes . '</fieldset>'
			. ( $entered || '' === $add_options ? '' : '<label for="' . esc_attr( $prefix . '-add' ) . '">' . esc_html__( 'Hinzufügen (mehrere mit Ctrl oder Cmd)', 'ttleague' ) . '</label><select id="' . esc_attr( $prefix . '-add' ) . '" name="add[]" multiple size="5">' . $add_options . '</select>' )
			. ( $entered ? '<p class="ttl-tk-muted">' . esc_html__( 'Der Gruppenzettel ist erfasst: Mitglieder lassen sich erst nach dem Zurücksetzen ändern.', 'ttleague' ) . '</p>' : '' )
			. '<button type="submit" class="button button-primary">' . esc_html__( 'Gruppe speichern', 'ttleague' ) . '</button></form>'
			. '<div class="ttl-tk-filter">'
			. ( $entered
				? self::button( 'group_reset', $id, $round['id'], __( 'Gruppenzettel zurücksetzen', 'ttleague' ), false )
				: self::button( 'group_delete', $id, $round['id'], __( 'Gruppe löschen', 'ttleague' ), false ) )
			. '</div></details>';
		return $out;
	}

	/**
	 * Formular «Runde abschliessen» mit Rang und Punkten je Person.
	 *
	 * @param array<string,mixed> $round Runde.
	 */
	private static function closing_form( array $round ): string {
		$closing = RankingTournament::rounds()->closing( $round['id'] );
		$missing = 0;
		$tables  = '';
		foreach ( $closing as $entry ) {
			$group = $entry['group'];
			if ( ! $entry['entered'] ) {
				++$missing;
				continue;
			}
			$rows = '';
			foreach ( $entry['rows'] as $row ) {
				if ( null === $row['rank'] ) {
					$rows .= '<tr><td>–</td><td>' . esc_html( $row['name'] ) . ' <span class="ttl-tk-muted">' . esc_html( GroupEntry::status_label( $row['status'] ) ) . '</span></td><td>–</td><td>–</td><td>–</td></tr>';
					continue;
				}
				$name  = 'manual[' . $group['id'] . '][' . $row['player_id'] . ']';
				$rows .= '<tr><td><input type="number" class="small-text" min="1" max="' . esc_attr( (string) count( $entry['rows'] ) ) . '" name="' . esc_attr( $name . '[rank]' ) . '" value="' . esc_attr( (string) $row['rank'] ) . '"'
					/* translators: %s: Name */
					. ' aria-label="' . esc_attr( sprintf( __( 'Rang von %s', 'ttleague' ), $row['name'] ) ) . '"></td>'
					. '<td>' . esc_html( $row['name'] ) . ( $row['shared'] ? ' <strong>' . esc_html__( '(Gleichstand)', 'ttleague' ) . '</strong>' : '' ) . '</td>'
					. '<td>' . esc_html( $row['wins'] . ':' . $row['losses'] ) . '</td><td>' . esc_html( $row['sets_won'] . ':' . $row['sets_lost'] ) . '</td>'
					. '<td><input type="text" class="small-text" inputmode="decimal" name="' . esc_attr( $name . '[points]' ) . '" value="' . esc_attr( Format::points( $row['points'] ) ) . '"'
					/* translators: %s: Name */
					. ' aria-label="' . esc_attr( sprintf( __( 'Punkte von %s', 'ttleague' ), $row['name'] ) ) . '"></td></tr>';
			}
			$tables .= '<h4>' . esc_html( Rounds::group_label( $group ) ) . '</h4><table class="widefat striped ttl-tk-table"><thead><tr><th scope="col">' . esc_html__( 'Rang', 'ttleague' ) . '</th><th scope="col">' . esc_html__( 'Name', 'ttleague' ) . '</th>'
				. '<th scope="col">' . esc_html__( 'Siege', 'ttleague' ) . '</th><th scope="col">' . esc_html__( 'Sätze', 'ttleague' ) . '</th><th scope="col">' . esc_html__( 'Punkte', 'ttleague' ) . '</th></tr></thead><tbody>' . $rows . '</tbody></table>';
		}
		return '<h2>' . esc_html__( 'Runde abschliessen', 'ttleague' ) . '</h2>'
			. '<p>' . esc_html__( 'Der Abschluss schreibt Rang und Punkte fest; danach lässt sich kein Gruppenzettel mehr ändern, und die nächste Runde kann eingeteilt werden. Es gelten die berechneten Werte. Bei einem Gleichstand, den das Los entscheidet, trägst du Rang und Punkte von Hand ein; das wird protokolliert.', 'ttleague' ) . '</p>'
			. '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">'
			. self::hidden( 'close', $round['id'], $round['id'] )
			. $tables
			/* translators: %d: Anzahl Gruppen */
			. ( $missing > 0 ? '<p><label><input type="checkbox" name="allow_missing" value="1"> ' . esc_html( sprintf( _n( '%d Gruppe ohne Gruppenzettel bleibt ohne Resultat.', '%d Gruppen ohne Gruppenzettel bleiben ohne Resultat.', $missing, 'ttleague' ), $missing ) ) . '</label></p>' : '' )
			. '<p><button type="submit" class="button button-primary">' . esc_html__( 'Runde abschliessen', 'ttleague' ) . '</button></p></form>';
	}

	/**
	 * Felder für die Rundenwoche.
	 *
	 * @param array<string,mixed>|null $round  Bestehende Runde.
	 * @param string                   $suffix Zusatz für eindeutige IDs.
	 */
	private static function week_fields( ?array $round, string $suffix ): string {
		return '<label for="' . esc_attr( 'ttl-rl-from-' . $suffix ) . '">' . esc_html__( 'Woche von (Montag)', 'ttleague' ) . '</label>'
			. '<input type="date" id="' . esc_attr( 'ttl-rl-from-' . $suffix ) . '" name="date_from" required value="' . esc_attr( (string) ( $round['date_from'] ?? '' ) ) . '">'
			. '<label for="' . esc_attr( 'ttl-rl-to-' . $suffix ) . '">' . esc_html__( 'bis (letzter Spielabend)', 'ttleague' ) . '</label>'
			. '<input type="date" id="' . esc_attr( 'ttl-rl-to-' . $suffix ) . '" name="date_to" required value="' . esc_attr( (string) ( $round['date_to'] ?? '' ) ) . '">'
			. '<label><input type="checkbox" name="is_final" value="1"' . checked( true, (bool) ( $round['is_final'] ?? false ), false ) . '> ' . esc_html__( 'Finalrunde', 'ttleague' ) . '</label>';
	}

	/**
	 * Auswahl einer Kategorie.
	 *
	 * @param list<int>   $numbers Hinterlegte Kategorien; ohne Kategorien die Nummern 1 bis 9.
	 * @param string|null $placeholder Bezeichnung des leeren Eintrags oder null ohne leeren Eintrag.
	 */
	private static function category_select( string $id, array $numbers, int $current, ?string $placeholder ): string {
		$numbers = array() === $numbers ? range( 1, 9 ) : $numbers;
		if ( $current > 0 && ! in_array( $current, $numbers, true ) ) {
			$numbers[] = $current;
			sort( $numbers );
		}
		$out = '<select id="' . esc_attr( $id ) . '" name="category">' . ( null === $placeholder ? '' : '<option value="0">' . esc_html( $placeholder ) . '</option>' );
		foreach ( $numbers as $number ) {
			$out .= '<option value="' . esc_attr( (string) $number ) . '"' . selected( $number, $current, false ) . '>' . esc_html( (string) $number ) . '</option>';
		}
		return $out . '</select>';
	}

	/**
	 * Versteckte Felder eines Formulars: Aktion, Vorgang, Ziel, Runde und Nonce.
	 */
	private static function hidden( string $task, int $id, int $round = 0 ): string {
		return '<input type="hidden" name="action" value="' . esc_attr( AdminActions::ACTION ) . '">'
			. '<input type="hidden" name="do" value="' . esc_attr( $task ) . '">'
			. '<input type="hidden" name="id" value="' . esc_attr( (string) $id ) . '">'
			. ( $round > 0 ? '<input type="hidden" name="round" value="' . esc_attr( (string) $round ) . '">' : '' )
			. wp_nonce_field( AdminActions::nonce( $task, $id ), '_wpnonce', true, false );
	}

	/**
	 * Formular mit einem einzigen Knopf.
	 */
	private static function button( string $task, int $id, int $round, string $label, bool $primary ): string {
		return '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">' . self::hidden( $task, $id, $round )
			. '<button type="submit" class="button' . ( $primary ? ' button-primary' : '' ) . '">' . esc_html( $label ) . '</button></form>';
	}
}
