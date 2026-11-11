<?php
/**
 * Backend «Spielbetrieb → Cup»: Meldungen, Auslosung, Tableau; Listen der Übersicht der TK.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Cup;

use TTLeague\Access\Capabilities;
use TTLeague\Domain\Rating\ClassificationScale;
use TTLeague\Export\Exports;
use TTLeague\Frontend\Support\Format;
use TTLeague\Infrastructure\Flash;
use TTLeague\Results\Actor;
use TTLeague\Results\ResultException;
use TTLeague\Results\Results;
use TTLeague\Results\ResultService;
use TTLeague\Tk\DashboardPage;
use TTLeague\Tk\Tk;

/**
 * Eine Seite je Cup-Kategorie der aktiven oder der geplanten Saison (Reiter): Punktelimite und
 * Meldeschluss, Meldungen mit Setzung, Auslosung (vorschlagen, Paarungen ändern, freigeben) und
 * nach der Freigabe das Tableau mit Fristen, Heimrecht und dem Ansetzen von Halbfinal und Final.
 * Alle Formulare gehen an admin-post.php (Forms) und verlangen ttl_tk; die Regeln prüfen die
 * Dienste. Jede Ausgabe wird beim Aufbau escaped.
 */
final class AdminPage {

	/** Anker der Listen in der Übersicht der TK. */
	public const SECTION_OVERDUE   = 'ttl-cup-fristen';
	public const SECTION_CONFLICTS = 'ttl-cup-tableau';

	/**
	 * Filter ttleague_tk_pages.
	 *
	 * @param mixed $pages Seiten der anderen Module.
	 * @return list<array<string,mixed>>
	 */
	public static function pages( mixed $pages ): array {
		$pages   = is_array( $pages ) ? array_values( $pages ) : array();
		$pages[] = array(
			'slug'     => Cup::PAGE,
			'title'    => __( 'Cup', 'ttleague' ),
			'render'   => array( self::class, 'render' ),
			'position' => Cup::POSITION,
		);
		return $pages;
	}

	/**
	 * Adresse der Seite, auf Wunsch mit gewählter Konkurrenz und gewähltem Club.
	 */
	public static function url( int $competition_id = 0, int $club_id = 0 ): string {
		$args = array();
		if ( $competition_id > 0 ) {
			$args['competition'] = (string) $competition_id;
		}
		if ( $club_id > 0 ) {
			$args['club'] = (string) $club_id;
		}
		return Tk::page_url( Cup::PAGE, $args );
	}

	public static function render(): void {
		if ( ! current_user_can( Capabilities::TK ) ) {
			wp_die( esc_html__( 'Dazu fehlt dir die Berechtigung.', 'ttleague' ), '', array( 'response' => 403 ) );
		}
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Auswahl zum Anzeigen, ändert nichts.
		$competition_id = isset( $_GET['competition'] ) ? absint( $_GET['competition'] ) : 0;
		$club_id        = isset( $_GET['club'] ) ? absint( $_GET['club'] ) : 0;
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		echo self::html( $competition_id, $club_id, Results::current_actor(), Flash::take( Tk::FLASH, get_current_user_id() ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Alle Teile werden beim Aufbau escaped.
	}

	/**
	 * @param array{type:string,message:string,details:list<string>}|null $flash Rückmeldung.
	 */
	public static function html( int $competition_id, int $club_id, Actor $actor, ?array $flash ): string {
		$repo         = Cup::repository();
		$competitions = array();
		foreach ( array( 'active', 'planned' ) as $status ) {
			foreach ( $repo->seasons( $status ) as $season_id ) {
				$competitions = array_merge( $competitions, $repo->competitions( $season_id ) );
			}
		}
		$out = '<div class="wrap ttl-tk ttl-cup-admin"><h1>' . esc_html__( 'Spielbetrieb: Cup', 'ttleague' ) . '</h1>' . DashboardPage::notice( $flash );
		if ( array() === $competitions ) {
			return $out . '<p>' . esc_html__( 'In der aktiven und der geplanten Saison gibt es keine Cup-Konkurrenz.', 'ttleague' ) . '</p></div>';
		}
		$competition = $competitions[0];
		foreach ( $competitions as $candidate ) {
			if ( $candidate['id'] === $competition_id ) {
				$competition = $candidate;
			}
		}
		$tabs = '';
		foreach ( $competitions as $candidate ) {
			$tabs .= '<a href="' . esc_url( self::url( $candidate['id'] ) ) . '" class="nav-tab' . ( $candidate['id'] === $competition['id'] ? ' nav-tab-active' : '' ) . '">' . esc_html( $candidate['name'] . ' ' . $candidate['season_name'] ) . '</a>';
		}
		$out .= '<nav class="nav-tab-wrapper">' . $tabs . '</nav>';
		try {
			$out .= self::competition( $competition, $club_id, $actor );
		} catch ( ResultException $e ) {
			$out .= '<div class="notice notice-error inline"><p>' . esc_html( $e->getMessage() ) . '</p></div>';
		}
		return $out . '</div>';
	}

	/**
	 * Filter ttleague_tk_overview_sections: «Cup: Frist abgelaufen» und, falls nötig, «Cup:
	 * Tableau prüfen».
	 *
	 * @param mixed $sections Listen der anderen Module.
	 * @return list<array<string,mixed>>
	 */
	public static function overview_sections( mixed $sections ): array {
		$sections = is_array( $sections ) ? array_values( $sections ) : array();
		if ( ! current_user_can( Capabilities::TK ) ) {
			return $sections;
		}
		$bracket    = Cup::bracket();
		$overdue    = $bracket->overdue();
		$sections[] = array(
			'id'    => self::SECTION_OVERDUE,
			'title' => __( 'Cup: Frist abgelaufen', 'ttleague' ),
			'count' => count( $overdue ),
			'html'  => self::overdue_html( $overdue ),
		);

		$conflicts = '';
		$count     = 0;
		$repo      = Cup::repository();
		foreach ( $repo->active_competitions_with_draw() as $competition_id ) {
			$list        = $bracket->conflicts( $competition_id );
			$competition = $repo->competition( $competition_id );
			if ( array() === $list || null === $competition ) {
				continue;
			}
			$count     += count( $list );
			$conflicts .= self::conflicts_html( $competition, $list, $bracket->rounds( $competition_id ) );
		}
		if ( $count > 0 ) {
			$sections[] = array(
				'id'    => self::SECTION_CONFLICTS,
				'title' => __( 'Cup: Tableau prüfen', 'ttleague' ),
				'count' => $count,
				'html'  => $conflicts,
			);
		}
		return $sections;
	}

	/**
	 * Liste «Cup: Frist abgelaufen».
	 *
	 * @param list<array<string,mixed>> $overdue Begegnungen aus Bracket::overdue().
	 */
	public static function overdue_html( array $overdue ): string {
		$out = '<p class="description">' . esc_html__( 'Cup-Begegnungen ohne Resultat, deren Rundenfrist abgelaufen ist. Verlängere die Frist oder werte die Begegnung auf ihrer Erfassungsseite forfait.', 'ttleague' ) . '</p>';
		if ( array() === $overdue ) {
			return $out . '<p><em>' . esc_html__( 'Keine Begegnung mit abgelaufener Frist.', 'ttleague' ) . '</em></p>';
		}
		$out .= '<table class="widefat striped ttl-tk-table"><thead><tr>'
			. '<th scope="col" class="ttl-col-fixture column-primary">' . esc_html__( 'Begegnung', 'ttleague' ) . '</th>'
			. '<th scope="col" class="ttl-col-date">' . esc_html__( 'Frist', 'ttleague' ) . '</th>'
			. '<th scope="col" class="ttl-col-date">' . esc_html__( 'Termin', 'ttleague' ) . '</th>'
			. '<th scope="col" class="ttl-col-action">' . esc_html__( 'Aktion', 'ttleague' ) . '</th>'
			. '</tr></thead><tbody>';
		foreach ( $overdue as $row ) {
			$url  = Results::entry_url( (int) $row['fixture'] );
			$out .= '<tr>'
				. '<td class="column-primary"><a href="' . esc_url( $url ) . '"><strong>' . esc_html( $row['home'] . ' – ' . $row['away'] ) . '</strong></a>'
				. '<br><span class="ttl-tk-muted">' . esc_html( $row['competition'] . ', ' . Labels::round( (int) $row['round'], (int) $row['rounds'] ) ) . '</span></td>'
				. '<td>' . esc_html( Format::date_plain( $row['deadline'] ) ) . '</td>'
				. '<td>' . esc_html( null === $row['scheduled_at'] ? __( 'kein Termin', 'ttleague' ) : Format::datetime( $row['scheduled_at'] ) ) . '</td>'
				. '<td>' . self::tie_deadline_form( (int) $row['tie'], Forms::BACK_TK, __( 'Frist verlängern', 'ttleague' ) )
				. '<p><a href="' . esc_url( $url ) . '">' . esc_html__( 'Forfait werten', 'ttleague' ) . '</a></p></td>'
				. '</tr>';
		}
		return $out . '</tbody></table>';
	}

	/**
	 * Hinweise einer Konkurrenz: Paarungen, die nach einer Korrektur nicht nachrücken konnten.
	 *
	 * @param array<string,mixed>       $competition Konkurrenz.
	 * @param list<array<string,mixed>> $conflicts   Hinweise aus Bracket::conflicts().
	 */
	public static function conflicts_html( array $competition, array $conflicts, int $rounds ): string {
		$items = '';
		foreach ( $conflicts as $conflict ) {
			$expected = '' === $conflict['expected'] ? __( 'noch niemand', 'ttleague' ) : $conflict['expected'];
			$current  = '' === $conflict['current'] ? __( 'niemand', 'ttleague' ) : $conflict['current'];
			$items   .= '<li>' . esc_html(
				sprintf(
					/* translators: 1: Konkurrenz, 2: Runde, 3: Nummer der Paarung, 4: Mannschaft im Tableau, 5: Mannschaft nach dem Resultat der Vorrunde */
					__( '%1$s, %2$s, Paarung %3$d: Im Tableau steht %4$s, nach dem Resultat der Vorrunde wäre es %5$s. Die Begegnung hat schon ein Resultat.', 'ttleague' ),
					$competition['name'],
					Labels::round( (int) $conflict['round'], $rounds ),
					(int) $conflict['slot'],
					$current,
					$expected
				)
			) . ' <a href="' . esc_url( Results::entry_url( (int) $conflict['fixture'] ) ) . '">' . esc_html__( 'Begegnung öffnen', 'ttleague' ) . '</a></li>';
		}
		return '<p class="description">' . esc_html__( 'Ein korrigiertes Resultat hat den Sieger geändert, die nächste Begegnung ist aber schon erfasst. Setze sie zurück, wenn die andere Mannschaft spielen soll; das Tableau rückt dann nach.', 'ttleague' ) . '</p>'
			. '<ul class="ttl-tk-warnings">' . $items . '</ul>';
	}

	/**
	 * Inhalt eines Reiters.
	 *
	 * @param array<string,mixed> $competition Konkurrenz.
	 */
	private static function competition( array $competition, int $club_id, Actor $actor ): string {
		$id       = (int) $competition['id'];
		$overview = Cup::draw()->overview( $id );
		$quorum   = Cup::entries()->quorum( $id );
		$all      = Cup::repository()->entries( $id );
		$out      = '';
		if ( ! $quorum['enough'] ) {
			$out .= '<div class="notice notice-warning inline"><p>' . esc_html(
				sprintf(
					/* translators: 1: Mindestzahl, 2: Anzahl Meldungen */
					__( 'Der Cup findet ab %1$d Meldungen statt (Art. 18.2). Bisher sind es %2$d. Ob er trotzdem ausgetragen wird, entscheidet die TK.', 'ttleague' ),
					$quorum['minimum'],
					$quorum['count']
				)
			) . '</p></div>';
		}
		if ( 'archived' === $competition['season_status'] ) {
			return $out;
		}
		$out .= self::settings( $competition )
			. self::entries( $competition, $all, $overview, $club_id, $actor );
		if ( $overview['drawn'] ) {
			return $out . self::tableau( $competition );
		}
		return $out . self::draw( $competition, $overview );
	}

	/**
	 * Punktelimite, Meldeschluss, Mindestzahl.
	 *
	 * @param array<string,mixed> $competition Konkurrenz.
	 */
	private static function settings( array $competition ): string {
		$id       = (int) $competition['id'];
		$settings = Entries::settings( $competition );
		return '<h2>' . esc_html__( 'Einstellungen', 'ttleague' ) . '</h2>'
			. '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="ttl-tk-filter">'
			. self::hidden( Forms::CONFIGURE, 'competition', $id )
			. '<label>' . esc_html__( 'Punktelimite (Summe der Klassierungen)', 'ttleague' ) . ' <input type="number" name="points_limit" min="2" max="44" class="small-text" value="' . esc_attr( (string) ( $settings['points_limit'] ?? '' ) ) . '"></label>'
			. '<label>' . esc_html__( 'Meldeschluss', 'ttleague' ) . ' <input type="date" name="registration_deadline" value="' . esc_attr( (string) ( $settings['registration_deadline'] ?? '' ) ) . '"></label>'
			. '<label>' . esc_html__( 'Mindestzahl Meldungen', 'ttleague' ) . ' <input type="number" name="min_entries" min="2" max="64" class="small-text" value="' . esc_attr( (string) $settings['min_entries'] ) . '"></label>'
			. '<button type="submit" class="button">' . esc_html__( 'Speichern', 'ttleague' ) . '</button>'
			. '</form>';
	}

	/**
	 * Meldungen mit Setzung, Rückzug, Meldung durch die TK und CSV.
	 *
	 * @param array<string,mixed>       $competition Konkurrenz.
	 * @param list<array<string,mixed>> $all         Alle Meldungen, auch zurückgezogene.
	 * @param array<string,mixed>       $overview    Stand der Auslosung.
	 */
	private static function entries( array $competition, array $all, array $overview, int $club_id, Actor $actor ): string {
		$id    = (int) $competition['id'];
		$drawn = (bool) $overview['drawn'];
		$ranks = array_column( $overview['entries'], 'rank', 'id' );
		usort( $all, static fn( array $a, array $b ): int => array( $ranks[ $a['id'] ] ?? PHP_INT_MAX, $a['id'] ) <=> array( $ranks[ $b['id'] ] ?? PHP_INT_MAX, $b['id'] ) );
		$out = '<h2>' . esc_html__( 'Meldungen', 'ttleague' ) . ' <span class="count">(' . esc_html( (string) count( $overview['entries'] ) ) . ')</span></h2>'
			. '<p><a href="' . esc_url(
				Exports::url(
					Export::NAME,
					array(
						'saison'     => (string) $competition['season_slug'],
						'konkurrenz' => (string) $id,
					)
				)
			) . '">' . esc_html__( 'Meldungen als CSV', 'ttleague' ) . '</a></p>';
		if ( array() === $all ) {
			$out .= '<p><em>' . esc_html__( 'Noch keine Meldungen.', 'ttleague' ) . '</em></p>';
		} else {
			$rows = '';
			foreach ( $all as $entry ) {
				$players = array();
				foreach ( $entry['players'] as $player ) {
					$players[] = $player['name'] . ( null === $player['classification'] ? '' : ' (' . ClassificationScale::label( $player['classification'] ) . ')' );
				}
				$active = CupRepository::ACTIVE === $entry['status'];
				$seed   = $drawn || ! $active
					? esc_html( null === $entry['seed'] ? '–' : (string) $entry['seed'] )
					/* translators: %s: Cupmannschaft */
					: '<input type="number" form="ttl-cup-seeds" name="seed[' . esc_attr( (string) $entry['id'] ) . ']" min="1" max="' . esc_attr( (string) count( $overview['entries'] ) ) . '" class="small-text" value="' . esc_attr( (string) ( $entry['seed'] ?? '' ) ) . '" aria-label="' . esc_attr( sprintf( __( 'Setznummer von %s', 'ttleague' ), $entry['name'] ) ) . '">'
						. ' <span class="ttl-tk-muted">' . esc_html( isset( $ranks[ $entry['id'] ] ) ? '#' . $ranks[ $entry['id'] ] : '' ) . '</span>';
				$rows .= '<tr>'
					. '<td>' . $seed . '</td>'
					. '<td class="column-primary"><strong>' . esc_html( $entry['name'] ) . '</strong></td>'
					. '<td>' . esc_html( implode( ', ', $players ) ) . '</td>'
					. '<td>' . esc_html( null === $entry['points_total'] ? '' : (string) $entry['points_total'] ) . '</td>'
					. '<td>' . esc_html( (string) $entry['registered_at'] ) . '</td>'
					. '<td>' . esc_html( Labels::entry_status( $entry['status'] ) ) . '</td>'
					. '<td>' . ( $active ? MyAreaSection::withdraw_form( $entry, Forms::BACK_ADMIN ) : '' ) . '</td>'
					. '</tr>';
			}
			$out .= '<table class="widefat striped ttl-tk-table"><thead><tr>'
				. '<th scope="col">' . esc_html__( 'Setzung', 'ttleague' ) . '</th>'
				. '<th scope="col" class="column-primary">' . esc_html__( 'Mannschaft', 'ttleague' ) . '</th>'
				. '<th scope="col">' . esc_html__( 'Spieler', 'ttleague' ) . '</th>'
				. '<th scope="col">' . esc_html__( 'Punktesumme', 'ttleague' ) . '</th>'
				. '<th scope="col">' . esc_html__( 'Gemeldet am (UTC)', 'ttleague' ) . '</th>'
				. '<th scope="col">' . esc_html__( 'Status', 'ttleague' ) . '</th>'
				. '<th scope="col">' . esc_html__( 'Aktion', 'ttleague' ) . '</th>'
				. '</tr></thead><tbody>' . $rows . '</tbody></table>';
		}
		if ( $drawn ) {
			return $out;
		}
		if ( array() !== $overview['entries'] ) {
			$out .= '<form id="ttl-cup-seeds" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">'
				. self::hidden( Forms::SEEDS, 'competition', $id )
				. '<p><button type="submit" class="button">' . esc_html__( 'Setzung speichern', 'ttleague' ) . '</button> '
				. '<span class="description">' . esc_html__( 'Ohne Setznummer gilt die Reihenfolge nach Punktesumme und Meldezeitpunkt (grau).', 'ttleague' ) . '</span></p></form>';
		}

		// Meldung durch die TK: zuerst den Club wählen, dann die beiden Spieler.
		$repo    = Cup::repository();
		$options = '<option value="0">' . esc_html__( 'Club wählen', 'ttleague' ) . '</option>';
		foreach ( $repo->clubs_of_season( (int) $competition['season_id'] ) as $club ) {
			$options .= '<option value="' . esc_attr( (string) $club['id'] ) . '"' . selected( $club['id'], $club_id, false ) . '>' . esc_html( $club['short_name'] ) . '</option>';
		}
		$out .= '<h3>' . esc_html__( 'Mannschaft melden', 'ttleague' ) . '</h3>'
			. '<form method="get" action="' . esc_url( admin_url( 'admin.php' ) ) . '" class="ttl-tk-filter">'
			. '<input type="hidden" name="page" value="' . esc_attr( Cup::PAGE ) . '">'
			. '<input type="hidden" name="competition" value="' . esc_attr( (string) $id ) . '">'
			. '<label for="ttl-cup-club">' . esc_html__( 'Club', 'ttleague' ) . '</label> <select id="ttl-cup-club" name="club">' . $options . '</select>'
			. '<button type="submit" class="button">' . esc_html__( 'Spieler anzeigen', 'ttleague' ) . '</button></form>';
		if ( $club_id > 0 ) {
			foreach ( Cup::entries()->for_club( $club_id, $actor ) as $block ) {
				if ( $block['competition']['id'] === $id ) {
					$out .= $block['open']
						? MyAreaSection::register_form( $club_id, $id, $block['players'], Forms::BACK_ADMIN )
						: '<p>' . esc_html( (string) $block['closed_reason'] ) . '</p>';
				}
			}
		}
		return $out;
	}

	/**
	 * Auslosung: vorschlagen, Paarungen ändern, freigeben.
	 *
	 * @param array<string,mixed> $competition Konkurrenz.
	 * @param array<string,mixed> $overview    Stand der Auslosung.
	 */
	private static function draw( array $competition, array $overview ): string {
		$id       = (int) $competition['id'];
		$proposal = $overview['proposal'];
		$out      = '<h2>' . esc_html__( 'Auslosung', 'ttleague' ) . '</h2>';
		if ( count( $overview['entries'] ) < 2 ) {
			return $out . '<p><em>' . esc_html__( 'Eine Auslosung braucht mindestens zwei gemeldete Mannschaften.', 'ttleague' ) . '</em></p>';
		}
		$out .= '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="ttl-tk-filter">'
			. self::hidden( Forms::PROPOSE, 'competition', $id )
			. '<label>' . esc_html__( 'Startwert (leer: zufällig)', 'ttleague' ) . ' <input type="number" name="seed" min="1" max="2147483647" class="regular-text" style="width:10em"></label>'
			. '<button type="submit" class="button' . ( null === $proposal ? ' button-primary' : '' ) . '">' . esc_html( null === $proposal ? __( 'Auslosung vorschlagen', 'ttleague' ) : __( 'Neu auslosen', 'ttleague' ) ) . '</button>'
			. '</form>';
		if ( null === $proposal ) {
			return $out . '<p class="description">' . esc_html__( 'Der Vorschlag setzt die stärksten Mannschaften, gibt ihnen die Freilose und lost die übrigen. Du kannst ihn danach ändern.', 'ttleague' ) . '</p>';
		}
		if ( $proposal['stale'] ) {
			return $out . '<div class="notice notice-warning inline"><p>' . esc_html__( 'Die Meldungen haben sich seit dem Vorschlag geändert. Bitte neu auslosen.', 'ttleague' ) . '</p></div>';
		}

		$out .= '<p>' . esc_html(
			sprintf(
				/* translators: %d: Startwert */
				__( 'Vorschlag mit Startwert %d.', 'ttleague' ),
				(int) $proposal['seed']
			) . ( $proposal['changed'] ? ' ' . __( 'Von Hand geändert.', 'ttleague' ) : '' )
		) . ' ' . esc_html__( 'Die erstgenannte Mannschaft hat Heimrecht.', 'ttleague' ) . '</p>';
		$rows = '';
		foreach ( $proposal['pairings'] as $pairing ) {
			$rows .= '<tr><td>' . esc_html( (string) $pairing['slot'] ) . '</td>'
				. '<td>' . self::entry_select( $overview['entries'], $pairing['home'], (int) $pairing['slot'], __( 'Heim', 'ttleague' ) ) . '</td>'
				. '<td>' . self::entry_select( $overview['entries'], $pairing['away'], (int) $pairing['slot'], __( 'Gast', 'ttleague' ) ) . '</td></tr>';
		}
		$out .= '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">'
			. self::hidden( Forms::CHANGE, 'competition', $id )
			. '<table class="widefat striped ttl-tk-table" style="max-width:48em"><thead><tr>'
			. '<th scope="col" style="width:4em">' . esc_html__( 'Paarung', 'ttleague' ) . '</th>'
			. '<th scope="col">' . esc_html__( 'Heim', 'ttleague' ) . '</th>'
			. '<th scope="col">' . esc_html__( 'Gast', 'ttleague' ) . '</th>'
			. '</tr></thead><tbody>' . $rows . '</tbody></table>'
			. '<p><button type="submit" class="button">' . esc_html__( 'Paarungen speichern', 'ttleague' ) . '</button></p></form>';

		return $out . '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="ttl-tk-filter">'
			. self::hidden( Forms::RELEASE, 'competition', $id )
			. '<label>' . esc_html__( 'Frist der ersten Runde', 'ttleague' ) . ' <input type="date" name="deadline"></label>'
			. '<button type="submit" class="button button-primary">' . esc_html__( 'Auslosung freigeben', 'ttleague' ) . '</button>'
			. '<span class="description">' . esc_html__( 'Legt das Tableau und die Begegnungen der ersten Runde an und benachrichtigt die Mannschaften. Danach ist die Auslosung fest.', 'ttleague' ) . '</span>'
			. '</form>';
	}

	/**
	 * Tableau nach der Freigabe: je Runde die Paarungen mit Begegnung, Frist und Aktionen.
	 *
	 * @param array<string,mixed> $competition Konkurrenz.
	 */
	private static function tableau( array $competition ): string {
		$id        = (int) $competition['id'];
		$bracket   = Cup::bracket();
		$tableau   = $bracket->tableau( $id );
		$conflicts = $bracket->conflicts( $id );
		$editable  = 'active' === $competition['season_status'];
		$venues    = Cup::repository()->venues();
		$out       = '<h2>' . esc_html__( 'Tableau', 'ttleague' ) . '</h2>';
		if ( array() !== $conflicts ) {
			$out .= '<div class="notice notice-warning inline">' . self::conflicts_html( $competition, $conflicts, $tableau['rounds'] ) . '</div>';
		}
		foreach ( $tableau['ties'] as $round => $ties ) {
			$out .= '<h3>' . esc_html( Labels::round( (int) $round, $tableau['rounds'] ) ) . '</h3>';
			if ( $editable ) {
				$out .= '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="ttl-tk-filter">'
					. '<input type="hidden" name="action" value="' . esc_attr( Forms::ROUND_DEADLINE ) . '">'
					. '<input type="hidden" name="competition" value="' . esc_attr( (string) $id ) . '">'
					. '<input type="hidden" name="round" value="' . esc_attr( (string) $round ) . '">'
					. wp_nonce_field( Forms::ROUND_DEADLINE . '_' . $id . '_' . $round, '_wpnonce', true, false )
					. '<label>' . esc_html__( 'Frist der Runde', 'ttleague' ) . ' <input type="date" name="deadline" required></label>'
					. '<button type="submit" class="button">' . esc_html__( 'Für alle Paarungen setzen', 'ttleague' ) . '</button></form>';
			}
			$rows = '';
			foreach ( $ties as $tie ) {
				$rows .= self::tie_row( $tie, $tableau['entries'], 1 === (int) $round, $editable, $venues );
			}
			$out .= '<table class="widefat striped ttl-tk-table"><thead><tr>'
				. '<th scope="col" style="width:4em">' . esc_html__( 'Paarung', 'ttleague' ) . '</th>'
				. '<th scope="col" class="column-primary">' . esc_html__( 'Mannschaften', 'ttleague' ) . '</th>'
				. '<th scope="col">' . esc_html__( 'Begegnung', 'ttleague' ) . '</th>'
				. '<th scope="col">' . esc_html__( 'Frist', 'ttleague' ) . '</th>'
				. '<th scope="col">' . esc_html__( 'Aktion', 'ttleague' ) . '</th>'
				. '</tr></thead><tbody>' . $rows . '</tbody></table>';
		}
		return $out;
	}

	/**
	 * Zeile einer Paarung im Tableau des Backends.
	 *
	 * @param array<string,mixed>            $tie         Paarung aus Bracket::tableau().
	 * @param array<int,array<string,mixed>> $entries     Meldungen nach ID.
	 * @param bool                           $first_round Erste Runde: ein leerer Platz ist ein Freilos.
	 * @param bool                           $editable    Aktive Saison: Aktionen anbieten.
	 * @param array<int,string>              $venues      Lokale für das Ansetzen.
	 */
	private static function tie_row( array $tie, array $entries, bool $first_round, bool $editable, array $venues ): string {
		$fixture = $tie['fixture'];
		$names   = array();
		foreach ( array( 'home', 'away' ) as $place ) {
			$entry = null === $tie[ $place ] ? null : ( $entries[ $tie[ $place ] ] ?? null );
			if ( null === $entry ) {
				$names[] = '<span class="ttl-tk-muted">' . esc_html( $first_round ? __( 'Freilos', 'ttleague' ) : __( 'offen', 'ttleague' ) ) . '</span>';
				continue;
			}
			$name = esc_html( $entry['name'] );
			if ( CupRepository::WITHDRAWN === $entry['status'] ) {
				$name .= ' <span class="ttl-tk-muted">(' . esc_html__( 'zurückgezogen', 'ttleague' ) . ')</span>';
			}
			if ( null !== $fixture && ! $tie['neutral'] && $fixture['home_entry'] === $entry['id'] ) {
				$name .= ' <abbr title="' . esc_attr__( 'Heimrecht', 'ttleague' ) . '">' . esc_html__( 'H', 'ttleague' ) . '</abbr>';
			}
			$names[] = $name;
		}

		$match = '<span class="ttl-tk-muted">–</span>';
		if ( null !== $fixture ) {
			$score = null === $fixture['home_score'] || null === $fixture['away_score'] ? '' : ' ' . $fixture['home_score'] . ':' . $fixture['away_score'];
			$when  = null === $fixture['scheduled_at'] ? __( 'ohne Termin', 'ttleague' ) : Format::datetime( $fixture['scheduled_at'] );
			$place = null === $fixture['venue_id'] || ! isset( $venues[ $fixture['venue_id'] ] ) ? '' : ', ' . $venues[ $fixture['venue_id'] ];
			$match = '<a href="' . esc_url( Results::entry_url( (int) $fixture['id'] ) ) . '">' . esc_html( ResultService::status_label( (string) $fixture['status'] ) . $score ) . '</a>'
				. '<br><span class="ttl-tk-muted">' . esc_html( $when . $place ) . '</span>';
		}

		$deadline = null === $tie['deadline'] ? '<span class="ttl-tk-muted">–</span>' : esc_html( Format::date_plain( $tie['deadline'] ) );
		if ( $tie['overdue'] ) {
			$deadline .= ' <strong>' . esc_html__( 'abgelaufen', 'ttleague' ) . '</strong>';
		}

		$actions = '';
		if ( $editable && null !== $fixture && in_array( $fixture['status'], array( 'scheduled', 'postponed' ), true ) ) {
			$actions .= self::tie_deadline_form( (int) $tie['id'], Forms::BACK_ADMIN, __( 'Frist setzen', 'ttleague' ) );
		}
		if ( $editable && $tie['can_swap'] ) {
			$actions .= '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="ttl-tk-inline">'
				. self::hidden( Forms::SWAP_HOME, 'tie', (int) $tie['id'] )
				. '<button type="submit" class="button">' . esc_html__( 'Heimrecht tauschen', 'ttleague' ) . '</button></form>';
		}
		if ( $editable && $tie['can_schedule'] ) {
			$options = '<option value="">' . esc_html__( 'Ort offen', 'ttleague' ) . '</option>';
			foreach ( $venues as $venue_id => $label ) {
				$options .= '<option value="' . esc_attr( (string) $venue_id ) . '"' . selected( $venue_id, $fixture['venue_id'] ?? 0, false ) . '>' . esc_html( $label ) . '</option>';
			}
			$actions .= '<details><summary class="button">' . esc_html__( 'Ansetzen', 'ttleague' ) . '</summary>'
				. '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="ttl-tk-form">'
				. self::hidden( Forms::SCHEDULE, 'tie', (int) $tie['id'] )
				. '<label>' . esc_html__( 'Datum und Zeit', 'ttleague' ) . ' <input type="datetime-local" name="scheduled_at" required value="' . esc_attr( null === $fixture['scheduled_at'] ? '' : str_replace( ' ', 'T', substr( (string) $fixture['scheduled_at'], 0, 16 ) ) ) . '"></label>'
				. '<label>' . esc_html__( 'Ort', 'ttleague' ) . ' <select name="venue_id">' . $options . '</select></label>'
				. '<button type="submit" class="button button-primary">' . esc_html__( 'Begegnung ansetzen', 'ttleague' ) . '</button></form></details>';
		}

		return '<tr><td>' . esc_html( (string) $tie['slot'] ) . '</td>'
			. '<td class="column-primary">' . implode( ' <span aria-hidden="true">–</span> ', $names ) . '</td>'
			. '<td>' . $match . '</td>'
			. '<td>' . $deadline . '</td>'
			. '<td>' . $actions . '</td></tr>';
	}

	/**
	 * Formular «Frist setzen» einer Paarung.
	 *
	 * @param string $back  Ziel nach der Aktion (Forms::BACK_*).
	 * @param string $label Beschriftung des Knopfs.
	 */
	private static function tie_deadline_form( int $tie_id, string $back, string $label ): string {
		return '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="ttl-tk-inline">'
			. self::hidden( Forms::TIE_DEADLINE, 'tie', $tie_id, $back )
			/* translators: %d: Nummer der Paarung in der Datenbank */
			. '<input type="date" name="deadline" required aria-label="' . esc_attr( sprintf( __( 'Neue Frist der Paarung %d', 'ttleague' ), $tie_id ) ) . '">'
			. '<button type="submit" class="button">' . esc_html( $label ) . '</button></form>';
	}

	/**
	 * Auswahl einer Mannschaft für einen Platz des Vorschlags.
	 *
	 * @param list<array<string,mixed>> $entries  Gültige Meldungen in der Reihenfolge der Setzung.
	 * @param int|null                  $selected Gewählte Meldung oder null (Freilos).
	 */
	private static function entry_select( array $entries, ?int $selected, int $slot, string $side ): string {
		$options = '<option value="0"' . selected( null, $selected, false ) . '>' . esc_html__( 'Freilos', 'ttleague' ) . '</option>';
		foreach ( $entries as $entry ) {
			$options .= '<option value="' . esc_attr( (string) $entry['id'] ) . '"' . selected( $entry['id'], $selected, false ) . '>'
				. esc_html( $entry['rank'] . '. ' . $entry['name'] . ( null === $entry['points_total'] ? '' : ' (' . $entry['points_total'] . ')' ) ) . '</option>';
		}
		/* translators: 1: Nummer der Paarung, 2: Heim oder Gast */
		return '<select name="positions[]" aria-label="' . esc_attr( sprintf( __( 'Paarung %1$d, %2$s', 'ttleague' ), $slot, $side ) ) . '">' . $options . '</select>';
	}

	/**
	 * Versteckte Felder eines Formulars der TK: Aktion, Objekt, Ziel und Nonce (pro Aktion und Objekt).
	 */
	private static function hidden( string $action, string $field, int $id, string $back = Forms::BACK_ADMIN ): string {
		return '<input type="hidden" name="action" value="' . esc_attr( $action ) . '">'
			. '<input type="hidden" name="' . esc_attr( $field ) . '" value="' . esc_attr( (string) $id ) . '">'
			. '<input type="hidden" name="back" value="' . esc_attr( $back ) . '">'
			. wp_nonce_field( $action . '_' . $id, '_wpnonce', true, false );
	}
}
