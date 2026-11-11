<?php
/**
 * HTML der Meldung eines Clubs: Spieler, Neumeldungen, Mannschaften, Lokale, mit Formularen.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Registration;

use TTLeague\Domain\Rating\ClassificationScale;
use TTLeague\Domain\Registration\RegistrationRules as Rules;
use TTLeague\Frontend\Support\Format;

/**
 * Dieselbe Ansicht für «Mein Bereich» (SITE) und die Seite «Meldungen» der TK (ADMIN); sie
 * unterscheiden sich in den CSS-Klassen, im Ziel nach dem Speichern (Feld «back») und in den
 * Entscheiden, die nur die TK sieht. Formulare ohne JavaScript an admin-post.php, Nonce pro
 * Aktion und Club (Actions). Angezeigt wird nur, was die Person ändern darf
 * (RegistrationContext::can()); die Dienste prüfen es beim Speichern erneut. Alle Ausgaben
 * werden hier escaped.
 */
final class RegistrationView {

	public const SITE  = 'site';
	public const ADMIN = 'admin';

	private const CLASSES = array(
		self::SITE  => array(
			'table'     => 'ttl-table ttl-table--cards ttl-reg__table',
			'primary'   => 'ttl-button ttl-button--primary',
			'secondary' => 'ttl-button ttl-button--secondary',
			'form'      => 'ttl-form ttl-reg__form',
			'inline'    => 'ttl-reg__inline',
			'muted'     => 'ttl-muted',
			'details'   => 'ttl-details ttl-reg__details',
			'heading'   => 'h5',
			'team'      => 'h6',
			'summary'   => '',
		),
		self::ADMIN => array(
			'table'     => 'widefat striped ttl-reg__table',
			'primary'   => 'button button-primary',
			'secondary' => 'button',
			'form'      => 'ttl-tk-form ttl-reg__form',
			'inline'    => 'ttl-tk-inline',
			'muted'     => 'ttl-tk-muted',
			'details'   => 'ttl-reg__details',
			'heading'   => 'h3',
			'team'      => 'h4',
			'summary'   => 'button button-small',
		),
	);

	/** @var array<string,string> */
	private array $css;

	private int $club;

	private int $season;

	private string $prefix;

	/**
	 * @param array{context:RegistrationContext,players:list<array<string,mixed>>,candidates:list<array<string,mixed>>,requests:list<array<string,mixed>>,teams:list<array<string,mixed>>,copyable_teams:int,venues:list<array<string,mixed>>,captain_candidates:list<array<string,mixed>>,violations:list<array{code:string,field:string,message:string}>} $state       Aus ClubRegistration::state().
	 * @param string                                                                                                                                                                                                                                                                                                 $skin        SITE oder ADMIN.
	 * @param int                                                                                                                                                                                                                                                                                                    $stamm_size  Stammspieler pro Mannschaft.
	 * @param array<int,array{license_no:int,persons:list<array<string,mixed>>}>                                                                                                                                                                                                                                 $suggestions Nur ADMIN: Vorschläge je offener Neumeldung (Review::suggestions()).
	 */
	public function __construct(
		private readonly array $state,
		private readonly string $skin,
		private readonly int $stamm_size = Rules::STAMM_SIZE,
		private readonly array $suggestions = array(),
	) {
		$this->css    = self::CLASSES[ self::ADMIN === $skin ? self::ADMIN : self::SITE ];
		$this->club   = $state['context']->club_id();
		$this->season = $state['context']->season_id();
		$this->prefix = 'ttl-reg-' . $this->season . '-' . $this->club . '-';
	}

	/**
	 * Meldung einer Saison: Stand, offene Punkte, Spieler, Neumeldungen, Mannschaften, Einreichen.
	 */
	public function registration(): string {
		return '<div class="ttl-reg">'
			. $this->status()
			. $this->violations()
			. $this->players()
			. $this->requests()
			. $this->teams()
			. $this->submit()
			. '</div>';
	}

	/**
	 * Lokale und Hallensperren des Clubs (ohne Saison; gelten sofort).
	 */
	public function venues(): string {
		$h   = $this->css['heading'];
		$out = '<div class="ttl-reg ttl-reg--venues"><' . $h . '>' . esc_html__( 'Lokale und Hallensperren', 'ttleague' ) . '</' . $h . '>'
			. '<p class="' . esc_attr( $this->css['muted'] ) . '">' . esc_html__( 'Änderungen an Lokalen und Hallensperren gelten sofort; die TK sieht sie in ihrer Übersicht.', 'ttleague' ) . '</p>';
		foreach ( $this->state['venues'] as $venue ) {
			$summary = array_filter(
				array(
					$venue['name'],
					Format::weekday( $venue['weekday'] ),
					/* translators: %d: Anzahl Tische */
					null === $venue['tables_count'] ? '' : sprintf( _n( '%d Tisch', '%d Tische', (int) $venue['tables_count'], 'ttleague' ), (int) $venue['tables_count'] ),
					$venue['active'] ? '' : __( 'nicht mehr in Gebrauch', 'ttleague' ),
				)
			);
			$out    .= '<details class="' . esc_attr( $this->css['details'] ) . '">' . $this->summary( esc_html( implode( ' · ', $summary ) ) )
				. $this->venue_form( $venue )
				. $this->closures( $venue )
				. '</details>';
		}
		return $out . '<details class="' . esc_attr( $this->css['details'] ) . '">' . $this->summary( esc_html__( 'Neues Lokal erfassen', 'ttleague' ) ) . $this->venue_form( null ) . '</details></div>';
	}

	public static function status_label( ?string $status ): string {
		return match ( $status ) {
			Rules::OPEN      => __( 'offen', 'ttleague' ),
			Rules::SUBMITTED => __( 'eingereicht', 'ttleague' ),
			Rules::REVIEWED  => __( 'geprüft', 'ttleague' ),
			default          => __( 'keine Meldung', 'ttleague' ),
		};
	}

	/**
	 * «Meldefenster: 1.–31.5.2027.»
	 *
	 * @param array<string,mixed> $season Geplante Saison.
	 */
	public static function window_label( array $season ): string {
		if ( null === $season['registration_opens'] ) {
			return __( 'Das Meldefenster ist noch nicht festgelegt.', 'ttleague' );
		}
		if ( null === $season['registration_closes'] ) {
			/* translators: %s: Datum */
			return sprintf( __( 'Meldefenster: ab %s, noch ohne letzten Tag.', 'ttleague' ), Format::date_plain( $season['registration_opens'] ) );
		}
		/* translators: %s: Zeitraum */
		return sprintf( __( 'Meldefenster: %s.', 'ttleague' ), Format::date_range( $season['registration_opens'], $season['registration_closes'] ) );
	}

	private function ctx(): RegistrationContext {
		return $this->state['context'];
	}

	private function status(): string {
		$ctx   = $this->ctx();
		$muted = esc_attr( $this->css['muted'] );
		$out   = '';
		if ( $ctx->is_planned() ) {
			$variant = match ( $ctx->status() ) {
				Rules::REVIEWED  => 'win',
				Rules::SUBMITTED => 'draw',
				default          => 'warning',
			};
			$window = self::window_label( $ctx->season );
			$out   .= '<p class="ttl-reg__status">' . esc_html__( 'Stand:', 'ttleague' ) . ' <span class="ttl-badge ttl-badge--' . esc_attr( $variant ) . ' ttl-reg__badge">' . esc_html( self::status_label( $ctx->status() ) ) . '</span> '
				. '<span class="' . $muted . '">' . esc_html( $window ) . '</span></p>';
			$note   = trim( (string) ( $ctx->club_season['note'] ?? '' ) );
			if ( '' !== $note && Rules::OPEN === $ctx->status() ) {
				/* translators: %s: Bemerkung */
				$out .= $this->notice( 'warning', sprintf( __( 'Die TK hat die Meldung zurückgegeben: %s', 'ttleague' ), $note ) );
			}
		} elseif ( $ctx->is_active() ) {
			/* translators: %s: Datum */
			$out .= '<p class="ttl-reg__status ' . $muted . '">' . esc_html( sprintf( __( 'Nachmeldungen sind bis %s möglich; spielberechtigt ist ein Spieler nach der Freigabe durch die TK.', 'ttleague' ), Format::date( $ctx->late_until ) ) ) . '</p>';
		}
		if ( '' !== $ctx->reason ) {
			$out .= $this->notice( 'info', $ctx->reason );
		}
		return $out;
	}

	/**
	 * Offene Punkte zeigt die Ansicht dort, wo jemand sie beheben kann: dem Club in der geplanten
	 * Saison (sie verhindern das Einreichen), der TK immer.
	 */
	private function shows_violations(): bool {
		return self::ADMIN === $this->skin || $this->ctx()->is_planned();
	}

	private function violations(): string {
		$violations = $this->shows_violations() ? $this->state['violations'] : array();
		if ( array() === $violations ) {
			return '';
		}
		$items = implode( '', array_map( static fn( array $v ): string => '<li>' . esc_html( $v['message'] ) . '</li>', $violations ) );
		/* translators: %d: Anzahl */
		return '<div class="' . esc_attr( self::ADMIN === $this->skin ? 'notice notice-warning inline' : 'ttl-notice ttl-notice--warning' ) . '" role="status"><p>' . esc_html( sprintf( _n( '%d offener Punkt:', '%d offene Punkte:', count( $violations ), 'ttleague' ), count( $violations ) ) ) . '</p><ul>' . $items . '</ul></div>';
	}

	private function players(): string {
		$ctx     = $this->ctx();
		$h       = $this->css['heading'];
		$players = $this->state['players'];
		$can     = $ctx->can( RegistrationAccess::PLAYERS );
		/* translators: %d: Anzahl */
		$out = '<' . $h . '>' . esc_html( sprintf( __( 'Gemeldete Spielerinnen und Spieler (%d)', 'ttleague' ), count( $players ) ) ) . '</' . $h . '>';
		if ( array() === $players ) {
			$out .= '<p class="' . esc_attr( $this->css['muted'] ) . '">' . esc_html__( 'Noch niemand gemeldet.', 'ttleague' ) . '</p>';
		} else {
			$out .= '<table class="' . esc_attr( $this->css['table'] ) . '"><thead><tr>'
				. '<th scope="col">' . esc_html__( 'Name', 'ttleague' ) . '</th>'
				. '<th scope="col">' . esc_html__( 'Lizenz', 'ttleague' ) . '</th>'
				. '<th scope="col">' . esc_html__( 'Klassierung', 'ttleague' ) . '</th>'
				. '<th scope="col">' . esc_html__( 'Stand', 'ttleague' ) . '</th>'
				. '<th scope="col"><span class="screen-reader-text">' . esc_html__( 'Aktionen', 'ttleague' ) . '</span></th>'
				. '</tr></thead><tbody>';
			foreach ( $players as $player ) {
				$provisional = ClubRegistration::PROVISIONAL === $player['status'];
				$actions     = '';
				if ( $can && ( $ctx->is_planned() || $provisional ) ) {
					$actions .= $this->button_form( Actions::WITHDRAW, __( 'Abmelden', 'ttleague' ), array( 'player' => $player['player_id'] ), $player['name'] );
				}
				if ( self::ADMIN === $this->skin && $provisional && $ctx->is_active() ) {
					$actions .= $this->decision_forms( Actions::PLAYER_APPROVE, Actions::PLAYER_REJECT, $player['player_id'], array( 'player' => $player['player_id'] ), $player['name'] );
				}
				$out .= '<tr><th scope="row" class="ttl-cell--primary">' . esc_html( $player['name'] ) . '</th>'
					. '<td data-label="' . esc_attr__( 'Lizenz', 'ttleague' ) . '">' . esc_html( (string) $player['license_no'] ) . '</td>'
					. '<td data-label="' . esc_attr__( 'Klassierung', 'ttleague' ) . '">' . esc_html( ClassificationScale::label( $player['classification'] ) ) . '</td>'
					. '<td data-label="' . esc_attr__( 'Stand', 'ttleague' ) . '">' . esc_html( $provisional ? __( 'provisorisch', 'ttleague' ) : __( 'freigegeben', 'ttleague' ) ) . '</td>'
					. '<td class="ttl-reg__actions">' . $actions . '</td></tr>';
			}
			$out .= '</tbody></table>';
		}
		if ( ! $can ) {
			return $out;
		}
		return $out . $this->candidates() . $this->license_form() . $this->request_form();
	}

	private function candidates(): string {
		$candidates = $this->state['candidates'];
		if ( array() === $candidates ) {
			return '';
		}
		$free = count( array_filter( $candidates, static fn( array $c ): bool => null === $c['other_club'] ) );
		/* translators: %d: Anzahl */
		$out = '<details class="' . esc_attr( $this->css['details'] ) . '" open>' . $this->summary( esc_html( sprintf( __( 'Spielerinnen und Spieler der letzten Saison, noch nicht gemeldet (%d)', 'ttleague' ), count( $candidates ) ) ) );
		if ( $free > 1 ) {
			/* translators: %d: Anzahl */
			$out .= $this->button_form( Actions::CONFIRM_ALL, sprintf( __( 'Alle %d bestätigen', 'ttleague' ), $free ), array(), '', true );
		}
		$out .= '<table class="' . esc_attr( $this->css['table'] ) . '"><thead><tr>'
			. '<th scope="col">' . esc_html__( 'Name', 'ttleague' ) . '</th>'
			. '<th scope="col">' . esc_html__( 'Lizenz', 'ttleague' ) . '</th>'
			. '<th scope="col">' . esc_html__( 'Klassierung', 'ttleague' ) . '</th>'
			. '<th scope="col"><span class="screen-reader-text">' . esc_html__( 'Aktionen', 'ttleague' ) . '</span></th>'
			. '</tr></thead><tbody>';
		foreach ( $candidates as $candidate ) {
			$action = null === $candidate['other_club']
				? $this->button_form( Actions::CONFIRM, __( 'Bestätigen', 'ttleague' ), array( 'player' => $candidate['player_id'] ), $candidate['name'] )
				/* translators: %s: Club */
				: '<span class="' . esc_attr( $this->css['muted'] ) . '">' . esc_html( sprintf( __( 'gemeldet für %s', 'ttleague' ), $candidate['other_club'] ) ) . '</span>';
			$out .= '<tr><th scope="row" class="ttl-cell--primary">' . esc_html( $candidate['name'] ) . '</th>'
				. '<td data-label="' . esc_attr__( 'Lizenz', 'ttleague' ) . '">' . esc_html( (string) $candidate['license_no'] ) . '</td>'
				. '<td data-label="' . esc_attr__( 'Klassierung', 'ttleague' ) . '">' . esc_html( ClassificationScale::label( $candidate['classification'] ) ) . '</td>'
				. '<td class="ttl-reg__actions">' . $action . '</td></tr>';
		}
		return $out . '</tbody></table><p class="' . esc_attr( $this->css['muted'] ) . '">' . esc_html__( 'Wer nicht bestätigt wird, ist für die Saison nicht gemeldet.', 'ttleague' ) . '</p></details>';
	}

	private function license_form(): string {
		$id  = $this->prefix . 'license';
		$out = '<details class="' . esc_attr( $this->css['details'] ) . '">' . $this->summary( esc_html__( 'Spieler mit Lizenznummer melden', 'ttleague' ) )
			. $this->open( Actions::LICENSE )
			. '<p class="ttl-field"><label for="' . esc_attr( $id ) . '">' . esc_html__( 'Lizenznummer', 'ttleague' ) . '</label>'
			. '<input id="' . esc_attr( $id ) . '" type="number" name="license_no" min="1" step="1" required inputmode="numeric"></p>';
		if ( self::ADMIN === $this->skin ) {
			$out .= '<p class="ttl-field"><label for="' . esc_attr( $id . '-class' ) . '">' . esc_html__( 'Klassierung (leer: vom System übernommen)', 'ttleague' ) . '</label>'
				. '<input id="' . esc_attr( $id . '-class' ) . '" type="number" name="classification" min="' . esc_attr( (string) ClassificationScale::MIN ) . '" max="' . esc_attr( (string) ClassificationScale::MAX ) . '" step="1"></p>';
		}
		return $out . '<p class="' . esc_attr( $this->css['muted'] ) . '">' . esc_html__( 'Für Spielerinnen und Spieler, die schon eine Lizenz der Region haben, z. B. nach einer Pause oder einem Clubwechsel.', 'ttleague' ) . '</p>'
			. '<button type="submit" class="' . esc_attr( $this->css['primary'] ) . '">' . esc_html__( 'Melden', 'ttleague' ) . '</button></form></details>';
	}

	private function request_form(): string {
		$id     = $this->prefix . 'new-';
		$fields = array(
			'last_name'   => array( __( 'Nachname', 'ttleague' ), 'text', true ),
			'first_name'  => array( __( 'Vorname', 'ttleague' ), 'text', true ),
			'email'       => array( __( 'E-Mail-Adresse (freiwillig)', 'ttleague' ), 'email', false ),
			'stt_license' => array( __( 'STT-Lizenz (falls vorhanden)', 'ttleague' ), 'text', false ),
		);
		$out    = '<details class="' . esc_attr( $this->css['details'] ) . '">' . $this->summary( esc_html__( 'Neue Spielerin oder neuen Spieler melden', 'ttleague' ) ) . $this->open( Actions::REQUEST );
		foreach ( $fields as $name => [ $label, $type, $required ] ) {
			$out .= '<p class="ttl-field"><label for="' . esc_attr( $id . $name ) . '">' . esc_html( $label ) . '</label>'
				. '<input id="' . esc_attr( $id . $name ) . '" type="' . esc_attr( $type ) . '" name="' . esc_attr( $name ) . '"' . ( $required ? ' required' : '' ) . ' autocomplete="off"></p>';
		}
		return $out . '<p class="ttl-field"><label for="' . esc_attr( $id . 'classification' ) . '">' . esc_html__( 'Klassierung nach Einschätzung des Clubs', 'ttleague' ) . '</label>'
			. $this->classification_select( $id . 'classification', null, true ) . '</p>'
			. '<p class="' . esc_attr( $this->css['muted'] ) . '">' . esc_html__( 'Die TK prüft die Meldung, vergibt die Lizenznummer und legt die Klassierung fest. Spielberechtigt ist die Person erst danach.', 'ttleague' ) . '</p>'
			. '<button type="submit" class="' . esc_attr( $this->css['primary'] ) . '">' . esc_html__( 'Melden', 'ttleague' ) . '</button></form></details>';
	}

	private function requests(): string {
		$requests = $this->state['requests'];
		if ( array() === $requests ) {
			return '';
		}
		$ctx = $this->ctx();
		$h   = $this->css['heading'];
		$out = '<' . $h . '>' . esc_html__( 'Neumeldungen', 'ttleague' ) . '</' . $h . '>'
			. '<table class="' . esc_attr( $this->css['table'] ) . '"><thead><tr>'
			. '<th scope="col">' . esc_html__( 'Name', 'ttleague' ) . '</th>'
			. '<th scope="col">' . esc_html__( 'Klassierung', 'ttleague' ) . '</th>'
			. '<th scope="col">' . esc_html__( 'Stand', 'ttleague' ) . '</th>'
			. '<th scope="col"><span class="screen-reader-text">' . esc_html__( 'Aktionen', 'ttleague' ) . '</span></th>'
			. '</tr></thead><tbody>';
		foreach ( $requests as $request ) {
			$open   = 'requested' === $request['status'];
			$status = match ( $request['status'] ) {
				'approved' => __( 'freigegeben', 'ttleague' ),
				'rejected' => __( 'abgelehnt', 'ttleague' ),
				default    => __( 'wartet auf die TK', 'ttleague' ),
			};
			$note   = trim( (string) $request['note'] );
			$action = '';
			if ( $open && self::ADMIN === $this->skin ) {
				$action = $this->request_decision( $request );
			} elseif ( $open && $ctx->can( RegistrationAccess::PLAYERS ) ) {
				$action = $this->button_form( Actions::REQUEST_CANCEL, __( 'Zurückziehen', 'ttleague' ), array( 'request' => $request['id'] ), $request['name'] );
			}
			$detail = array_filter( array( $request['email'], null === $request['stt_license'] ? null : 'STT ' . $request['stt_license'] ) );
			$out   .= '<tr><th scope="row" class="ttl-cell--primary">' . esc_html( $request['name'] )
				. ( array() === $detail ? '' : '<br><span class="' . esc_attr( $this->css['muted'] ) . '">' . esc_html( implode( ' · ', $detail ) ) . '</span>' ) . '</th>'
				. '<td data-label="' . esc_attr__( 'Klassierung', 'ttleague' ) . '">' . esc_html( null === $request['classification'] ? '–' : ClassificationScale::label( $request['classification'] ) ) . '</td>'
				. '<td data-label="' . esc_attr__( 'Stand', 'ttleague' ) . '">' . esc_html( $status ) . ( '' === $note ? '' : '<br><span class="' . esc_attr( $this->css['muted'] ) . '">' . esc_html( $note ) . '</span>' ) . '</td>'
				. '<td class="ttl-reg__actions">' . $action . '</td></tr>';
		}
		return $out . '</tbody></table>';
	}

	/**
	 * Nur TK: Neumeldung freigeben (Lizenznummer, Klassierung, bestehende Person) oder ablehnen.
	 *
	 * @param array<string,mixed> $request Neumeldung.
	 */
	private function request_decision( array $request ): string {
		$id          = $this->prefix . 'request-' . $request['id'] . '-';
		$suggestions = $this->suggestions[ $request['id'] ] ?? array(
			'license_no' => 0,
			'persons'    => array(),
		);
		$out         = $this->open( Actions::REQUEST_APPROVE, array( 'request' => $request['id'] ), $request['id'] )
			. '<p class="ttl-field"><label for="' . esc_attr( $id . 'license' ) . '">' . esc_html__( 'Lizenznummer (Vorschlag: nächste freie)', 'ttleague' ) . '</label>'
			. '<input id="' . esc_attr( $id . 'license' ) . '" type="number" name="license_no" min="1" step="1" value="' . esc_attr( $suggestions['license_no'] > 0 ? (string) $suggestions['license_no'] : '' ) . '"></p>'
			. '<p class="ttl-field"><label for="' . esc_attr( $id . 'class' ) . '">' . esc_html__( 'Klassierung', 'ttleague' ) . '</label>'
			. $this->classification_select( $id . 'class', $request['classification'], true ) . '</p>'
			. '<p class="ttl-field"><label for="' . esc_attr( $id . 'email' ) . '">' . esc_html__( 'E-Mail-Adresse der neuen Person', 'ttleague' ) . '</label>'
			. '<input id="' . esc_attr( $id . 'email' ) . '" type="email" name="email" value="' . esc_attr( (string) $request['email'] ) . '" autocomplete="off"></p>';
		if ( array() !== $suggestions['persons'] ) {
			$options = '<option value="0">' . esc_html__( 'Neue Person anlegen', 'ttleague' ) . '</option>';
			foreach ( $suggestions['persons'] as $person ) {
				$label = $person['name']
					/* translators: %d: Lizenznummer */
					. ( null === $person['license_no'] ? ' · ' . __( 'ohne Lizenz', 'ttleague' ) : ' · ' . sprintf( __( 'Lizenz %d', 'ttleague' ), $person['license_no'] ) )
					. ( null === $person['email'] ? '' : ' · ' . $person['email'] );
				$options .= '<option value="' . esc_attr( (string) $person['person_id'] ) . '">' . esc_html( $label ) . '</option>';
			}
			$out .= '<p class="ttl-field"><label for="' . esc_attr( $id . 'person' ) . '"><strong>' . esc_html__( 'Diese Person gibt es vielleicht schon', 'ttleague' ) . '</strong></label>'
				. '<select id="' . esc_attr( $id . 'person' ) . '" name="person_id">' . $options . '</select></p>'
				. '<p class="' . esc_attr( $this->css['muted'] ) . '">' . esc_html__( 'Mit einer bestehenden Person entsteht kein Doppel; hat sie schon eine Lizenz, bleibt diese, und Lizenznummer und E-Mail-Adresse oben werden nicht verwendet.', 'ttleague' ) . '</p>';
		}
		$out .= '<button type="submit" class="' . esc_attr( $this->css['primary'] ) . '">' . esc_html__( 'Freigeben', 'ttleague' ) . '</button></form>';
		return $out . $this->reject_form( Actions::REQUEST_REJECT, $request['id'], array( 'request' => $request['id'] ), $request['name'] );
	}

	private function teams(): string {
		$ctx   = $this->ctx();
		$h     = $this->css['heading'];
		$teams = $this->state['teams'];
		/* translators: %d: Anzahl */
		$out = '<' . $h . '>' . esc_html( sprintf( __( 'Mannschaften (%d)', 'ttleague' ), count( $teams ) ) ) . '</' . $h . '>';
		if ( array() === $teams ) {
			$out .= '<p class="' . esc_attr( $this->css['muted'] ) . '">' . esc_html__( 'Noch keine Mannschaft gemeldet.', 'ttleague' ) . '</p>';
		}
		$last = array() === $teams ? 0 : $teams[ count( $teams ) - 1 ]['id'];
		foreach ( $teams as $team ) {
			$out .= '<div class="ttl-reg__team" id="' . esc_attr( $this->prefix . 'team-' . $team['id'] ) . '"><' . $this->css['team'] . ' class="ttl-reg__team-title">' . esc_html( $team['name'] ) . '</' . $this->css['team'] . '>'
				. $this->team_violations( $team['id'] )
				. ( $ctx->can( RegistrationAccess::TEAMS ) ? $this->team_form( $team ) : $this->team_summary( $team ) );
			if ( $ctx->can( RegistrationAccess::STRUCTURE ) && $team['id'] === $last ) {
				$out .= $this->button_form( Actions::TEAM_REMOVE, __( 'Mannschaft entfernen', 'ttleague' ), array( 'team' => $team['id'] ), $team['name'] );
			}
			$out .= '</div>';
		}
		$copyable = (int) ( $this->state['copyable_teams'] ?? 0 );
		if ( $ctx->can( RegistrationAccess::STRUCTURE ) && array() === $teams && $copyable > 0 ) {
			/* translators: %d: Anzahl */
			$out .= $this->button_form( Actions::TEAM_COPY, sprintf( __( 'Mannschaften des Vorjahrs übernehmen (%d)', 'ttleague' ), $copyable ), array(), '', true )
				. '<p class="' . esc_attr( $this->css['muted'] ) . '">' . esc_html__( 'Bestätige zuerst die Spielerinnen und Spieler: Nur gemeldete Stammspieler kommen mit. Lokal, Anspielzeit und Captain werden übernommen und lassen sich danach ändern.', 'ttleague' ) . '</p>';
		}
		if ( $ctx->can( RegistrationAccess::STRUCTURE ) ) {
			$out .= $this->button_form( Actions::TEAM_ADD, __( 'Mannschaft hinzufügen', 'ttleague' ), array(), '', array() !== $teams || 0 === $copyable )
				. '<p class="' . esc_attr( $this->css['muted'] ) . '">' . esc_html__( 'Kategorie und Gruppe legt die TK fest.', 'ttleague' ) . '</p>';
		}
		return $out;
	}

	private function team_violations( int $team_id ): string {
		$items = '';
		foreach ( $this->shows_violations() ? $this->state['violations'] : array() as $violation ) {
			if ( str_starts_with( $violation['field'], 'team.' . $team_id . '.' ) ) {
				$items .= '<li>' . esc_html( $violation['message'] ) . '</li>';
			}
		}
		return '' === $items ? '' : '<ul class="ttl-reg__errors">' . $items . '</ul>';
	}

	/**
	 * @param array<string,mixed> $team Mannschaft.
	 */
	private function team_form( array $team ): string {
		$id      = $this->prefix . 'team-' . $team['id'] . '-';
		$out     = $this->open( Actions::TEAM_SAVE, array( 'team' => $team['id'] ) );
		$selects = max( $this->stamm_size, count( $team['players'] ) );
		for ( $i = 0; $i < $selects; $i++ ) {
			$options = '<option value="0">' . esc_html__( '– wählen –', 'ttleague' ) . '</option>';
			$current = $team['players'][ $i ] ?? 0;
			$known   = false;
			foreach ( $this->state['players'] as $player ) {
				$known    = $known || $player['player_id'] === $current;
				$options .= '<option value="' . esc_attr( (string) $player['player_id'] ) . '"' . selected( $player['player_id'], $current, false ) . '>' . esc_html( $player['name'] . ' (' . ClassificationScale::label( $player['classification'] ) . ')' ) . '</option>';
			}
			if ( $current > 0 && ! $known ) {
				$options .= '<option value="' . esc_attr( (string) $current ) . '" selected>' . esc_html__( 'nicht mehr gemeldet', 'ttleague' ) . '</option>';
			}
			/* translators: %d: Nummer */
			$out .= '<p class="ttl-field"><label for="' . esc_attr( $id . 'p' . $i ) . '">' . esc_html( sprintf( __( 'Stammspieler %d', 'ttleague' ), $i + 1 ) ) . '</label>'
				. '<select id="' . esc_attr( $id . 'p' . $i ) . '" name="players[]">' . $options . '</select></p>';
		}

		$venues = '<option value="0">' . esc_html__( '– wählen –', 'ttleague' ) . '</option>';
		foreach ( $this->state['venues'] as $venue ) {
			if ( $venue['active'] || $venue['id'] === $team['home_venue_id'] ) {
				$venues .= '<option value="' . esc_attr( (string) $venue['id'] ) . '"' . selected( $venue['id'], $team['home_venue_id'], false ) . '>' . esc_html( $venue['name'] . ' (' . Format::weekday( $venue['weekday'] ) . ')' ) . '</option>';
			}
		}
		$out .= '<p class="ttl-field"><label for="' . esc_attr( $id . 'venue' ) . '">' . esc_html__( 'Heimlokal', 'ttleague' ) . '</label><select id="' . esc_attr( $id . 'venue' ) . '" name="home_venue_id">' . $venues . '</select></p>'
			. '<p class="ttl-field"><label for="' . esc_attr( $id . 'start' ) . '">' . esc_html__( 'Anspielzeit', 'ttleague' ) . '</label>'
			. '<input id="' . esc_attr( $id . 'start' ) . '" type="time" name="start_time" value="' . esc_attr( substr( (string) $team['start_time'], 0, 5 ) ) . '"></p>'
			. '<p class="ttl-field"><label for="' . esc_attr( $id . 'captain' ) . '">' . esc_html__( 'Captain', 'ttleague' ) . '</label>' . $this->captain_select( $id . 'captain', $team ) . '</p>'
			. '<button type="submit" class="' . esc_attr( $this->css['primary'] ) . '">' . esc_html__( 'Mannschaft speichern', 'ttleague' ) . '</button></form>';
		return $out;
	}

	/**
	 * Mannschaft zum Lesen, mit Formular für den Captain, falls erlaubt.
	 *
	 * @param array<string,mixed> $team Mannschaft.
	 */
	private function team_summary( array $team ): string {
		$names = array_column( $this->state['players'], 'name', 'player_id' );
		$stamm = array_map( static fn( int $id ): string => $names[ $id ] ?? __( 'nicht mehr gemeldet', 'ttleague' ), $team['players'] );
		$venue = '';
		foreach ( $this->state['venues'] as $candidate ) {
			if ( $candidate['id'] === $team['home_venue_id'] ) {
				$venue = $candidate['name'];
			}
		}
		$rows = array(
			__( 'Stammspieler', 'ttleague' ) => array() === $stamm ? '–' : implode( ', ', $stamm ),
			__( 'Heimlokal', 'ttleague' )    => '' === $venue ? '–' : $venue,
			__( 'Anspielzeit', 'ttleague' )  => null === $team['start_time'] ? '–' : substr( (string) $team['start_time'], 0, 5 ),
			__( 'Captain', 'ttleague' )      => null === $team['captain'] ? '–' : $team['captain']['name'],
		);
		$out  = '<dl class="ttl-reg__summary">';
		foreach ( $rows as $label => $value ) {
			$out .= '<dt>' . esc_html( $label ) . '</dt><dd>' . esc_html( $value ) . '</dd>';
		}
		$out .= '</dl>';
		if ( $this->ctx()->can( RegistrationAccess::CAPTAIN ) ) {
			$id   = $this->prefix . 'team-' . $team['id'] . '-captain';
			$out .= '<details class="' . esc_attr( $this->css['details'] ) . '">' . $this->summary( esc_html__( 'Captain ändern', 'ttleague' ) )
				. $this->open( Actions::CAPTAIN, array( 'team' => $team['id'] ) )
				/* translators: %s: Mannschaft */
				. '<p class="ttl-field"><label for="' . esc_attr( $id ) . '">' . esc_html( sprintf( __( 'Captain von %s', 'ttleague' ), $team['name'] ) ) . '</label>' . $this->captain_select( $id, $team ) . '</p>'
				. '<button type="submit" class="' . esc_attr( $this->css['primary'] ) . '">' . esc_html__( 'Speichern', 'ttleague' ) . '</button></form></details>';
		}
		return $out;
	}

	/**
	 * @param array<string,mixed> $team Mannschaft.
	 */
	private function captain_select( string $id, array $team ): string {
		$current = $team['captain']['person_id'] ?? 0;
		$options = '<option value="0">' . esc_html__( '– wählen –', 'ttleague' ) . '</option>';
		$known   = false;
		foreach ( $this->state['captain_candidates'] as $person ) {
			$known    = $known || $person['person_id'] === $current;
			$email    = Rules::is_email( $person['email'] );
			$options .= '<option value="' . esc_attr( (string) $person['person_id'] ) . '"' . selected( $person['person_id'], $current, false ) . ( $email || $person['person_id'] === $current ? '' : ' disabled' ) . '>'
				. esc_html( $person['name'] . ( $email ? '' : ' – ' . __( 'ohne E-Mail-Adresse', 'ttleague' ) ) ) . '</option>';
		}
		if ( $current > 0 && ! $known ) {
			$options .= '<option value="' . esc_attr( (string) $current ) . '" selected>' . esc_html( (string) $team['captain']['name'] ) . '</option>';
		}
		return '<select id="' . esc_attr( $id ) . '" name="captain">' . $options . '</select>';
	}

	private function submit(): string {
		$ctx = $this->ctx();
		if ( ! $ctx->is_planned() || Rules::OPEN !== $ctx->status() || ! $ctx->can( RegistrationAccess::SUBMIT ) ) {
			return '';
		}
		$h   = $this->css['heading'];
		$out = '<' . $h . '>' . esc_html__( 'Meldung einreichen', 'ttleague' ) . '</' . $h . '>';
		if ( array() !== $this->state['violations'] ) {
			return $out . '<p class="' . esc_attr( $this->css['muted'] ) . '">' . esc_html__( 'Einreichen kannst du, sobald die offenen Punkte oben behoben sind.', 'ttleague' ) . '</p>';
		}
		return $out . '<p>' . esc_html__( 'Nach dem Einreichen prüft die TK die Meldung. Ändern kannst du sie erst wieder, wenn die TK sie zurückgibt.', 'ttleague' ) . '</p>'
			. $this->button_form( Actions::SUBMIT, __( 'Meldung einreichen', 'ttleague' ), array(), '', true );
	}

	/**
	 * @param array<string,mixed>|null $venue Lokal oder null für ein neues.
	 */
	private function venue_form( ?array $venue ): string {
		$id     = $this->prefix . 'venue-' . ( $venue['id'] ?? 'neu' ) . '-';
		$fields = array(
			'name'   => array( __( 'Name', 'ttleague' ), true ),
			'street' => array( __( 'Strasse und Nummer', 'ttleague' ), false ),
			'zip'    => array( __( 'PLZ', 'ttleague' ), false ),
			'city'   => array( __( 'Ort', 'ttleague' ), false ),
			'phone'  => array( __( 'Telefon', 'ttleague' ), false ),
		);
		$out    = $this->open( Actions::VENUE, array( 'venue' => $venue['id'] ?? 0 ) );
		foreach ( $fields as $name => [ $label, $required ] ) {
			$out .= '<p class="ttl-field"><label for="' . esc_attr( $id . $name ) . '">' . esc_html( $label ) . '</label>'
				. '<input id="' . esc_attr( $id . $name ) . '" type="text" name="' . esc_attr( $name ) . '" value="' . esc_attr( (string) ( $venue[ $name ] ?? '' ) ) . '"' . ( $required ? ' required' : '' ) . '></p>';
		}
		$days = '<option value="">' . esc_html__( '– wählen –', 'ttleague' ) . '</option>';
		for ( $day = 1; $day <= 5; $day++ ) {
			$days .= '<option value="' . esc_attr( (string) $day ) . '"' . selected( $day, $venue['weekday'] ?? 0, false ) . '>' . esc_html( Format::weekday( $day ) ) . '</option>';
		}
		$out .= '<p class="ttl-field"><label for="' . esc_attr( $id . 'weekday' ) . '">' . esc_html__( 'Spieltag', 'ttleague' ) . '</label><select id="' . esc_attr( $id . 'weekday' ) . '" name="weekday" required>' . $days . '</select></p>'
			. '<p class="ttl-field"><label for="' . esc_attr( $id . 'open' ) . '">' . esc_html__( 'Geöffnet ab', 'ttleague' ) . '</label><input id="' . esc_attr( $id . 'open' ) . '" type="time" name="open_time" value="' . esc_attr( substr( (string) ( $venue['open_time'] ?? '' ), 0, 5 ) ) . '"></p>'
			. '<p class="ttl-field"><label for="' . esc_attr( $id . 'close' ) . '">' . esc_html__( 'Geöffnet bis', 'ttleague' ) . '</label><input id="' . esc_attr( $id . 'close' ) . '" type="time" name="close_time" value="' . esc_attr( substr( (string) ( $venue['close_time'] ?? '' ), 0, 5 ) ) . '"></p>'
			. '<p class="ttl-field"><label for="' . esc_attr( $id . 'tables' ) . '">' . esc_html__( 'Anzahl Tische', 'ttleague' ) . '</label><input id="' . esc_attr( $id . 'tables' ) . '" type="number" name="tables_count" min="1" max="99" step="1" required value="' . esc_attr( (string) ( $venue['tables_count'] ?? '' ) ) . '"></p>';
		if ( null !== $venue ) {
			$out .= '<p class="ttl-field ttl-field--check"><label><input type="checkbox" name="active" value="1"' . checked( $venue['active'], true, false ) . '> ' . esc_html__( 'Lokal ist in Gebrauch', 'ttleague' ) . '</label></p>';
		}
		return $out . '<button type="submit" class="' . esc_attr( $this->css['primary'] ) . '">' . esc_html( null === $venue ? __( 'Lokal erfassen', 'ttleague' ) : __( 'Lokal speichern', 'ttleague' ) ) . '</button></form>';
	}

	/**
	 * @param array<string,mixed> $venue Lokal mit Hallensperren.
	 */
	private function closures( array $venue ): string {
		$id  = $this->prefix . 'closure-' . $venue['id'] . '-';
		$out = '<p class="ttl-reg__subtitle"><strong>' . esc_html__( 'Hallensperren', 'ttleague' ) . '</strong></p>';
		if ( array() === $venue['closures'] ) {
			$out .= '<p class="' . esc_attr( $this->css['muted'] ) . '">' . esc_html__( 'Keine kommenden Sperren eingetragen.', 'ttleague' ) . '</p>';
		} else {
			$out .= '<ul class="ttl-reg__closures">';
			foreach ( $venue['closures'] as $closure ) {
				$label = Format::date_range( $closure['date_from'], $closure['date_to'] ) . ( null === $closure['reason'] ? '' : ' · ' . $closure['reason'] );
				$out  .= '<li><span>' . esc_html( $label ) . '</span> ' . $this->button_form( Actions::CLOSURE_DELETE, __( 'Entfernen', 'ttleague' ), array( 'closure' => $closure['id'] ), $label ) . '</li>';
			}
			$out .= '</ul>';
		}
		return $out . $this->open( Actions::CLOSURE_ADD, array( 'venue' => $venue['id'] ) )
			. '<p class="ttl-field"><label for="' . esc_attr( $id . 'from' ) . '">' . esc_html__( 'Gesperrt von', 'ttleague' ) . '</label><input id="' . esc_attr( $id . 'from' ) . '" type="date" name="date_from" required></p>'
			. '<p class="ttl-field"><label for="' . esc_attr( $id . 'to' ) . '">' . esc_html__( 'bis (leer: nur dieser Tag)', 'ttleague' ) . '</label><input id="' . esc_attr( $id . 'to' ) . '" type="date" name="date_to"></p>'
			. '<p class="ttl-field"><label for="' . esc_attr( $id . 'reason' ) . '">' . esc_html__( 'Grund', 'ttleague' ) . '</label><input id="' . esc_attr( $id . 'reason' ) . '" type="text" name="reason" maxlength="120"></p>'
			. '<button type="submit" class="' . esc_attr( $this->css['secondary'] ) . '">' . esc_html__( 'Sperre eintragen', 'ttleague' ) . '</button></form>';
	}

	/**
	 * Nur TK: «Freigeben» als Knopf, «Ablehnen» mit Pflichtbemerkung.
	 *
	 * @param array<string,int> $hidden Weitere Felder.
	 */
	private function decision_forms( string $approve, string $reject, int $nonce_id, array $hidden, string $subject ): string {
		return $this->button_form( $approve, __( 'Freigeben', 'ttleague' ), $hidden, $subject, true, $nonce_id )
			. $this->reject_form( $reject, $nonce_id, $hidden, $subject );
	}

	/**
	 * @param array<string,int> $hidden Weitere Felder.
	 */
	private function reject_form( string $action, int $nonce_id, array $hidden, string $subject ): string {
		$id = $this->prefix . sanitize_html_class( $action ) . '-' . $nonce_id . '-note';
		return '<details class="' . esc_attr( $this->css['details'] ) . '">' . $this->summary( esc_html__( 'Ablehnen', 'ttleague' ) )
			. $this->open( $action, $hidden, $nonce_id )
			/* translators: %s: Name */
			. '<p class="ttl-field"><label for="' . esc_attr( $id ) . '">' . esc_html( sprintf( __( 'Bemerkung an den Club zu %s (Pflicht)', 'ttleague' ), $subject ) ) . '</label>'
			. '<textarea id="' . esc_attr( $id ) . '" name="note" rows="2" required></textarea></p>'
			. '<button type="submit" class="' . esc_attr( $this->css['secondary'] ) . '">' . esc_html__( 'Ablehnen', 'ttleague' ) . '</button></form></details>';
	}

	/**
	 * Formular mit nur einem Knopf.
	 *
	 * @param array<string,int> $hidden   Weitere Felder.
	 * @param string            $subject  Worauf sich der Knopf bezieht (für Screenreader).
	 * @param int|null          $nonce_id Datensatz des Nonce; ohne Angabe der Club.
	 */
	private function button_form( string $action, string $label, array $hidden = array(), string $subject = '', bool $primary = false, ?int $nonce_id = null ): string {
		return $this->open( $action, $hidden, $nonce_id, $this->css['inline'] )
			. '<button type="submit" class="' . esc_attr( $primary ? $this->css['primary'] : $this->css['secondary'] ) . '">' . esc_html( $label )
			. ( '' === $subject ? '' : '<span class="screen-reader-text">: ' . esc_html( $subject ) . '</span>' ) . '</button></form>';
	}

	/**
	 * Formularkopf mit Aktion, Club, Saison, Rückweg und Nonce.
	 *
	 * @param array<string,int> $hidden   Weitere Felder.
	 * @param int|null          $nonce_id Datensatz des Nonce (Entscheide der TK); ohne Angabe der Club.
	 */
	private function open( string $action, array $hidden = array(), ?int $nonce_id = null, ?string $css = null ): string {
		$fields = array(
			'action'   => $action,
			'club'     => (string) $this->club,
			'season'   => (string) $this->season,
			'_wpnonce' => wp_create_nonce( Actions::nonce( $action, $nonce_id ?? $this->club ) ),
		);
		if ( self::ADMIN === $this->skin ) {
			$fields['back'] = 'tk';
		}
		foreach ( $hidden as $name => $value ) {
			$fields[ $name ] = (string) $value;
		}
		$out = '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="' . esc_attr( $css ?? $this->css['form'] ) . '">';
		foreach ( $fields as $name => $value ) {
			$out .= '<input type="hidden" name="' . esc_attr( $name ) . '" value="' . esc_attr( $value ) . '">';
		}
		return $out;
	}

	private function classification_select( string $id, ?int $current, bool $required ): string {
		$options = '<option value="">' . esc_html__( '– wählen –', 'ttleague' ) . '</option>';
		for ( $value = ClassificationScale::MIN; $value <= ClassificationScale::MAX; $value++ ) {
			$options .= '<option value="' . esc_attr( (string) $value ) . '"' . selected( $value, $current ?? 0, false ) . '>' . esc_html( ClassificationScale::label( $value ) ) . '</option>';
		}
		return '<select id="' . esc_attr( $id ) . '" name="classification"' . ( $required ? ' required' : '' ) . '>' . $options . '</select>';
	}

	/**
	 * Aufklappzeile; im Backend als Knopf, weil dort die Markierung der Zeile ausgeblendet ist.
	 *
	 * @param string $html Escapter Inhalt.
	 */
	private function summary( string $html ): string {
		return '<summary' . ( '' === $this->css['summary'] ? '' : ' class="' . esc_attr( $this->css['summary'] ) . '"' ) . '>' . $html . '</summary>';
	}

	private function notice( string $type, string $message ): string {
		if ( self::ADMIN === $this->skin ) {
			return '<div class="notice notice-' . esc_attr( $type ) . ' inline"><p>' . esc_html( $message ) . '</p></div>';
		}
		return '<div class="ttl-notice ttl-notice--' . esc_attr( $type ) . '" role="status"><p>' . esc_html( $message ) . '</p></div>';
	}
}
