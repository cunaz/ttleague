<?php
/**
 * Seite «Spielbetrieb → Saison»: der Assistent der TK in sieben Schritten.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Season;

use TTLeague\Access\Capabilities;
use TTLeague\Accounts\Services;
use TTLeague\Domain\Registration\RegistrationRules;
use TTLeague\Frontend\Support\Format;
use TTLeague\Infrastructure\Flash;
use TTLeague\Registration\RegistrationRepository;
use TTLeague\Tk\DashboardPage;
use TTLeague\Tk\Tk;

/**
 * Eine Seite mit einem Reiter je Schritt (docs/saison.md): Saison anlegen, Regeln,
 * Meldefenster, Kategorien und Gruppen, Spielplan, Freigabe, Abschluss. Jeder Reiter zeigt
 * seinen Stand; der Schritt selbst zeigt, was noch fehlt. Die Schritte 1 bis 6 gelten der
 * geplanten Saison, der Schritt 7 und die Aktionen «während der Saison» der aktiven.
 *
 * Die Seite verlangt ttl_tk. Sie liest über die Dienste und schreibt nie selbst: Jedes Formular
 * geht an admin-post.php (SeasonActions). Den Inhalt der Schritte baut SeasonSteps.
 */
final class SeasonPage {

	public const CREATE   = 1;
	public const RULES    = 2;
	public const WINDOW   = 3;
	public const GROUPS   = 4;
	public const SCHEDULE = 5;
	public const PUBLISH  = 6;
	public const CLOSE    = 7;

	public const DONE = 'done';
	public const OPEN = 'open';
	public const IDLE = 'idle';

	/** Anker der Liste in der Übersicht der TK. */
	public const SECTION = 'ttl-saison';

	public static function render(): void {
		if ( ! current_user_can( Capabilities::TK ) ) {
			wp_die( esc_html__( 'Dazu fehlt dir die Berechtigung.', 'ttleague' ), '', array( 'response' => 403 ) );
		}
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Auswahl zum Anzeigen, ändert nichts.
		$step    = isset( $_GET['step'] ) ? absint( $_GET['step'] ) : 0;
		$fixture = isset( $_GET['fixture'] ) ? absint( $_GET['fixture'] ) : 0;
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		echo self::html( $step, $fixture, Flash::take( Tk::FLASH, get_current_user_id() ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Alle Teile werden beim Aufbau escaped.
	}

	/**
	 * Adresse der Seite, mit Schritt der Reiter dieses Schritts.
	 *
	 * @param array<string,string> $args Weitere Parameter.
	 */
	public static function url( int $step = 0, array $args = array() ): string {
		if ( $step >= self::CREATE && $step <= self::CLOSE ) {
			$args = array( 'step' => (string) $step ) + $args;
		}
		return Tk::page_url( SeasonAdmin::PAGE, $args );
	}

	/**
	 * @param int                                                          $step       Gewählter Schritt; 0: der nächste offene.
	 * @param int                                                          $fixture_id Begegnung, die im Schritt Spielplan zum Verlegen offen ist.
	 * @param array{type:string,message:string,details:list<string>}|null $flash      Rückmeldung.
	 */
	public static function html( int $step, int $fixture_id, ?array $flash ): string {
		$status = self::status();
		if ( $step < self::CREATE || $step > self::CLOSE ) {
			$step = $status['next'];
		}
		$out  = '<div class="wrap ttl-tk ttl-season"><h1>' . esc_html__( 'Spielbetrieb: Saison', 'ttleague' ) . '</h1>' . DashboardPage::notice( $flash );
		$out .= '<p>' . esc_html( self::summary( $status['planned'], $status['active'] ) ) . '</p>';

		$tabs = '';
		foreach ( $status['steps'] as $number => $info ) {
			$mark = match ( $info['state'] ) {
				self::DONE => '✓ ',
				self::OPEN => '● ',
				default    => '',
			};
			$tabs .= '<a href="' . esc_url( self::url( $number ) ) . '" class="nav-tab' . ( $number === $step ? ' nav-tab-active' : '' ) . '"' . ( $number === $step ? ' aria-current="page"' : '' )
				. ' title="' . esc_attr( $info['text'] ) . '">'
				. '<span aria-hidden="true">' . esc_html( $mark ) . '</span>' . esc_html( $number . '. ' . $info['title'] )
				. '<span class="screen-reader-text"> (' . esc_html( $info['text'] ) . ')</span></a>';
		}
		$out .= '<nav class="nav-tab-wrapper" aria-label="' . esc_attr__( 'Schritte', 'ttleague' ) . '">' . $tabs . '</nav>';

		$info = $status['steps'][ $step ];
		$out .= '<h2>' . esc_html( $step . '. ' . $info['title'] ) . '</h2>'
			. '<p class="ttl-season-state"><span class="' . esc_attr( 'ttl-tk-status ttl-season-state--' . $info['state'] ) . '">' . esc_html( self::state_label( $info['state'] ) ) . '</span> ' . esc_html( $info['text'] ) . '</p>';
		try {
			$out .= SeasonSteps::render( $step, $status['planned'], $status['active'], $fixture_id );
		} catch ( SeasonException $e ) {
			$out .= '<div class="notice notice-error inline"><p>' . esc_html( $e->getMessage() ) . '</p></div>';
		}
		return $out . '</div>';
	}

	/**
	 * Stand aller Schritte.
	 *
	 * @return array{planned:array<string,mixed>|null,active:array<string,mixed>|null,next:int,steps:array<int,array{title:string,state:string,text:string}>}
	 */
	public static function status(): array {
		$db      = Services::db();
		$seasons = new SeasonRepository( $db );
		$planned = $seasons->planned();
		$active  = $seasons->active();
		$titles  = array(
			self::CREATE   => __( 'Saison anlegen', 'ttleague' ),
			self::RULES    => __( 'Regeln', 'ttleague' ),
			self::WINDOW   => __( 'Meldefenster', 'ttleague' ),
			self::GROUPS   => __( 'Kategorien und Gruppen', 'ttleague' ),
			self::SCHEDULE => __( 'Spielplan', 'ttleague' ),
			self::PUBLISH  => __( 'Freigabe', 'ttleague' ),
			self::CLOSE    => __( 'Abschluss', 'ttleague' ),
		);
		$steps   = array();
		foreach ( $titles as $number => $title ) {
			$steps[ $number ] = array(
				'title' => $title,
				'state' => self::IDLE,
				'text'  => __( 'Zuerst eine Saison anlegen.', 'ttleague' ),
			);
		}
		$set = static function ( int $number, bool $done, string $text ) use ( &$steps ): void {
			$steps[ $number ]['state'] = $done ? self::DONE : self::OPEN;
			$steps[ $number ]['text']  = $text;
		};

		if ( null === $planned ) {
			$set( self::CREATE, false, __( 'Es gibt noch keine geplante Saison.', 'ttleague' ) );
		} else {
			$season_id = (int) $planned['id'];
			$state     = SeasonAdmin::lifecycle()->readiness( $season_id );
			$facts     = $state['facts'];
			/* translators: %s: Saison */
			$set( self::CREATE, true, sprintf( __( 'Die Saison %s ist angelegt.', 'ttleague' ), $planned['name'] ) );
			$set( self::RULES, null === $facts['rules_error'], null === $facts['rules_error'] ? __( 'Die Regeln sind gültig.', 'ttleague' ) : __( 'Die Regeln sind ungültig.', 'ttleague' ) );

			$clubs    = ( new RegistrationRepository( $db ) )->overview( $season_id );
			$reviewed = count( array_filter( $clubs, static fn( array $row ): bool => RegistrationRules::REVIEWED === $row['status'] ) );
			if ( null === $planned['registration_opens'] ) {
				$set( self::WINDOW, false, __( 'Das Meldefenster ist noch nicht gesetzt.', 'ttleague' ) );
			} else {
				/* translators: 1: geprüfte Meldungen, 2: Clubs */
				$set( self::WINDOW, array() !== $clubs && count( $clubs ) === $reviewed, sprintf( __( '%1$d von %2$d Meldungen geprüft.', 'ttleague' ), $reviewed, count( $clubs ) ) );
			}

			if ( $facts['teams'] < 1 ) {
				$set( self::GROUPS, false, __( 'Noch keine Mannschaft gemeldet.', 'ttleague' ) );
			} elseif ( array() !== $facts['unassigned'] ) {
				/* translators: %d: Anzahl Mannschaften */
				$set( self::GROUPS, false, sprintf( _n( '%d Mannschaft ohne Gruppe.', '%d Mannschaften ohne Gruppe.', count( $facts['unassigned'] ), 'ttleague' ), count( $facts['unassigned'] ) ) );
			} else {
				$set( self::GROUPS, true, __( 'Jede Mannschaft ist zugeteilt.', 'ttleague' ) );
			}

			if ( $facts['teams'] < 1 || array() !== $facts['unassigned'] ) {
				$set( self::SCHEDULE, false, __( 'Zuerst jede Mannschaft einer Gruppe zuteilen.', 'ttleague' ) );
			} elseif ( array() !== $facts['groups_without_schedule'] ) {
				/* translators: %s: Gruppen */
				$set( self::SCHEDULE, false, sprintf( __( 'Spielplan fehlt oder passt nicht: %s.', 'ttleague' ), implode( ', ', $facts['groups_without_schedule'] ) ) );
			} elseif ( null !== $facts['schedule_error'] ) {
				$set( self::SCHEDULE, false, __( 'Der Spielplan lässt sich nicht prüfen.', 'ttleague' ) );
			} elseif ( $facts['hard_conflicts'] > 0 ) {
				/* translators: %d: Anzahl */
				$set( self::SCHEDULE, false, sprintf( _n( '%d harte Kollision.', '%d harte Kollisionen.', $facts['hard_conflicts'], 'ttleague' ), $facts['hard_conflicts'] ) );
			} else {
				$set( self::SCHEDULE, true, __( 'Der Spielplan ist vollständig und ohne harte Kollision.', 'ttleague' ) );
			}

			$blocking = count( array_filter( $state['open'], static fn( array $item ): bool => ! $item['confirmable'] ) );
			if ( $blocking > 0 ) {
				/* translators: %d: Anzahl */
				$set( self::PUBLISH, false, sprintf( _n( '%d Punkt fehlt noch.', '%d Punkte fehlen noch.', $blocking, 'ttleague' ), $blocking ) );
			} else {
				$set( self::PUBLISH, false, array() === $state['open'] ? __( 'Bereit zur Freigabe.', 'ttleague' ) : __( 'Bereit zur Freigabe, mit ausdrücklicher Bestätigung.', 'ttleague' ) );
			}
		}

		if ( null === $active ) {
			$steps[ self::CLOSE ]['text'] = __( 'Es gibt keine aktive Saison.', 'ttleague' );
		} else {
			$open = SeasonAdmin::lifecycle()->closing( (int) $active['id'] );
			$set(
				self::CLOSE,
				false,
				array() === $open
					/* translators: %s: Saison */
					? sprintf( __( 'Die Saison %s lässt sich abschliessen.', 'ttleague' ), $active['name'] )
					/* translators: %s: Saison */
					: sprintf( __( 'Die Saison %s läuft.', 'ttleague' ), $active['name'] )
			);
		}

		$next = self::CREATE;
		if ( null !== $planned ) {
			$next = self::PUBLISH;
			foreach ( array( self::RULES, self::WINDOW, self::GROUPS, self::SCHEDULE ) as $number ) {
				if ( self::OPEN === $steps[ $number ]['state'] ) {
					$next = $number;
					break;
				}
			}
		}
		return array(
			'planned' => $planned,
			'active'  => $active,
			'next'    => $next,
			'steps'   => $steps,
		);
	}

	/**
	 * Filter ttleague_tk_overview_sections: «Saison: nächste Schritte».
	 *
	 * @param mixed $sections Listen.
	 * @return list<array<string,mixed>>
	 */
	public static function overview_sections( mixed $sections ): array {
		$sections = is_array( $sections ) ? array_values( $sections ) : array();
		if ( ! current_user_can( Capabilities::TK ) ) {
			return $sections;
		}
		$status = self::status();
		if ( null === $status['planned'] ) {
			return $sections;
		}
		$rows = '';
		$open = 0;
		foreach ( $status['steps'] as $number => $info ) {
			if ( self::OPEN !== $info['state'] || self::CLOSE === $number ) {
				continue;
			}
			++$open;
			$rows .= '<tr><td><a href="' . esc_url( self::url( $number ) ) . '">' . esc_html( $number . '. ' . $info['title'] ) . '</a></td><td>' . esc_html( $info['text'] ) . '</td></tr>';
		}
		$sections[] = array(
			'id'    => self::SECTION,
			/* translators: %s: Saison */
			'title' => sprintf( __( 'Saison %s: nächste Schritte', 'ttleague' ), $status['planned']['name'] ),
			'count' => $open,
			'html'  => '<table class="widefat striped ttl-tk-table"><thead><tr><th scope="col">' . esc_html__( 'Schritt', 'ttleague' ) . '</th><th scope="col">' . esc_html__( 'Stand', 'ttleague' ) . '</th></tr></thead><tbody>' . $rows . '</tbody></table>',
		);
		return $sections;
	}

	/**
	 * Wenige eigene Regeln; sonst gelten die Standardklassen des Backends und die der TK-Seiten.
	 */
	public static function styles( string $hook ): void {
		if ( ! str_ends_with( $hook, SeasonAdmin::PAGE ) ) {
			return;
		}
		wp_add_inline_style(
			'common',
			'.ttl-season .ttl-season-error{color:#b32d2e;display:block}'
			. '.ttl-season .ttl-season-state--done{background:#edfaef;color:#00450c}'
			. '.ttl-season .ttl-season-state--open{background:#fcf9e8;color:#614a00}'
			. '.ttl-season .ttl-season-hard{border-left:4px solid #d63638;padding-left:8px}'
			. '.ttl-season .ttl-season-soft{border-left:4px solid #dba617;padding-left:8px}'
			. '.ttl-season .ttl-season-list{margin:4px 0 12px 18px;list-style:disc}'
			. '.ttl-season .ttl-season-actions{display:flex;flex-wrap:wrap;gap:8px;align-items:center;margin:8px 0}'
			. '.ttl-season .ttl-season-actions form{margin:0}'
			. '.ttl-season table.ttl-season-narrow{width:auto;min-width:40%}'
			. '.ttl-season h3{margin-top:24px}'
		);
	}

	/**
	 * Kopf eines Formulars an admin-post.php mit Aktion, Nonce und versteckten Feldern.
	 *
	 * @param array<string,string> $hidden Versteckte Felder.
	 */
	public static function form( string $action, int $nonce_id, array $hidden = array(), string $css = 'ttl-tk-form' ): string {
		$out = '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="' . esc_attr( $css ) . '">'
			. '<input type="hidden" name="action" value="' . esc_attr( $action ) . '">'
			. '<input type="hidden" name="_wpnonce" value="' . esc_attr( wp_create_nonce( SeasonActions::nonce( $action, $nonce_id ) ) ) . '">';
		foreach ( $hidden as $name => $value ) {
			$out .= '<input type="hidden" name="' . esc_attr( $name ) . '" value="' . esc_attr( $value ) . '">';
		}
		return $out;
	}

	/**
	 * Zeitraum einer Saison als Text.
	 *
	 * @param array<string,mixed> $season Saison.
	 */
	public static function period( array $season ): string {
		return Format::date_plain( (string) $season['start_date'] ) . ' – ' . Format::date_plain( (string) $season['end_date'] );
	}

	/**
	 * @param array<string,mixed>|null $planned Geplante Saison.
	 * @param array<string,mixed>|null $active  Aktive Saison.
	 */
	private static function summary( ?array $planned, ?array $active ): string {
		$parts = array(
			null === $active
				? __( 'Keine aktive Saison.', 'ttleague' )
				/* translators: 1: Saison, 2: Zeitraum */
				: sprintf( __( 'Aktive Saison: %1$s (%2$s).', 'ttleague' ), $active['name'], self::period( $active ) ),
			null === $planned
				? __( 'Keine geplante Saison.', 'ttleague' )
				/* translators: 1: Saison, 2: Zeitraum */
				: sprintf( __( 'Geplante Saison: %1$s (%2$s), nicht öffentlich bis zur Freigabe.', 'ttleague' ), $planned['name'], self::period( $planned ) ),
		);
		return implode( ' ', $parts );
	}

	private static function state_label( string $state ): string {
		return match ( $state ) {
			self::DONE => __( 'erledigt', 'ttleague' ),
			self::OPEN => __( 'offen', 'ttleague' ),
			default    => __( 'noch nicht möglich', 'ttleague' ),
		};
	}
}
