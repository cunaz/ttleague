<?php
/**
 * Seite «Spielbetrieb › Anlässe»: Liste, Anlass anlegen und ändern, Teilnehmerliste.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Events;

use TTLeague\Access\Capabilities;
use TTLeague\Accounts\Services;
use TTLeague\Domain\Events\RegistrationRules as Rules;
use TTLeague\Export\Exports;
use TTLeague\Frontend\Support\Format;
use TTLeague\Infrastructure\Flash;
use TTLeague\Season\SeasonRepository;
use TTLeague\Tk\DashboardPage;
use TTLeague\Tk\Tk;

/**
 * Ohne Parameter die Liste aller Anlässe, mit «ansicht=neu» das Formular für einen neuen Anlass,
 * mit «anlass={id}» ein Anlass mit Teilnehmerliste (Name, Club, Zeitpunkt, Bemerkung), Anmeldung
 * von Hand, Formular zum Ändern, Schliessen, Öffnen und Absagen und dem CSV der Teilnehmerliste.
 * Alle Formulare gehen an admin-post.php (AdminActions).
 */
final class AdminPage {

	public const SLUG = 'ttleague-tk-anlaesse';

	/** Platz im Menü «Spielbetrieb» (Konten und Einladungen steht auf 90). */
	public const POSITION = 70;

	/**
	 * Filter ttleague_tk_pages.
	 *
	 * @param list<array<string,mixed>> $pages Seiten der Module.
	 * @return list<array<string,mixed>>
	 */
	public static function pages( $pages ): array {
		$pages   = is_array( $pages ) ? $pages : array();
		$pages[] = array(
			'slug'     => self::SLUG,
			'title'    => __( 'Anlässe', 'ttleague' ),
			'render'   => array( self::class, 'render' ),
			'position' => self::POSITION,
		);
		return $pages;
	}

	/**
	 * Adresse der Liste oder eines Anlasses.
	 */
	public static function url( int $event_id = 0, string $anchor = '' ): string {
		return Tk::page_url( self::SLUG, $event_id > 0 ? array( 'anlass' => (string) $event_id ) : array() ) . ( '' === $anchor ? '' : '#' . $anchor );
	}

	public static function new_url(): string {
		return Tk::page_url( self::SLUG, array( 'ansicht' => 'neu' ) );
	}

	public static function render(): void {
		if ( ! current_user_can( Capabilities::TK ) ) {
			wp_die( esc_html__( 'Dazu fehlt dir die Berechtigung.', 'ttleague' ), '', array( 'response' => 403 ) );
		}
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Auswahl zum Anzeigen, ändert nichts.
		$event_id = isset( $_GET['anlass'] ) ? absint( $_GET['anlass'] ) : 0;
		$new      = isset( $_GET['ansicht'] ) && is_string( $_GET['ansicht'] ) && 'neu' === sanitize_key( wp_unslash( $_GET['ansicht'] ) );
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		$user  = get_current_user_id();
		$flash = Flash::take( Tk::FLASH, $user );
		$repo  = Events::repository();
		$event = $event_id > 0 ? $repo->find( $event_id ) : null;

		if ( null === $event && ! $new ) {
			if ( $event_id > 0 && null === $flash ) {
				$flash = array(
					'type'    => 'error',
					'message' => __( 'Diesen Anlass gibt es nicht.', 'ttleague' ),
					'details' => array(),
				);
			}
			echo self::list_html( $repo->all(), $flash ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Alle Teile werden beim Aufbau escaped.
			return;
		}

		$draft = AdminActions::take_draft( $user, null === $event ? 0 : $event['id'] );
		if ( null === $event ) {
			$active = Services::policy()->active_season_id();
			$values = $draft ?? array( 'season_id' => null === $active ? '' : (string) $active );
			echo self::new_html( $values, self::seasons( null ), $flash ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Alle Teile werden beim Aufbau escaped.
			return;
		}
		$service = Events::registrations();
		$season  = $service->season_for( $event );
		$html    = self::detail_html(
			$event,
			$repo->registrations( $event['id'], $season ),
			Rules::CANCELLED === $event['status'] ? array() : $repo->candidates( $event['id'], $season ),
			$draft ?? self::values( $event ),
			self::seasons( $event['season_id'] ),
			$flash,
			wp_timezone()
		);
		echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Alle Teile werden beim Aufbau escaped.
	}

	/**
	 * Liste aller Anlässe.
	 *
	 * @param list<array<string,mixed>>                                   $events Anlässe mit registered und waitlist.
	 * @param array{type:string,message:string,details:list<string>}|null $flash  Rückmeldung.
	 */
	public static function list_html( array $events, ?array $flash ): string {
		$out = '<div class="wrap ttl-tk"><h1 class="wp-heading-inline">' . esc_html__( 'Spielbetrieb: Anlässe', 'ttleague' ) . '</h1> '
			. '<a class="page-title-action" href="' . esc_url( self::new_url() ) . '">' . esc_html__( 'Anlass anlegen', 'ttleague' ) . '</a><hr class="wp-header-end">'
			. DashboardPage::notice( $flash );
		if ( array() === $events ) {
			return $out . '<p>' . esc_html__( 'Es sind noch keine Anlässe erfasst.', 'ttleague' ) . '</p></div>';
		}
		$out .= '<table class="widefat striped"><thead><tr>'
			. '<th scope="col" class="column-primary">' . esc_html__( 'Anlass', 'ttleague' ) . '</th>'
			. '<th scope="col">' . esc_html__( 'Art', 'ttleague' ) . '</th>'
			. '<th scope="col">' . esc_html__( 'Beginn', 'ttleague' ) . '</th>'
			. '<th scope="col">' . esc_html__( 'Anmeldeschluss', 'ttleague' ) . '</th>'
			. '<th scope="col">' . esc_html__( 'Status', 'ttleague' ) . '</th>'
			. '<th scope="col">' . esc_html__( 'Teilnehmende', 'ttleague' ) . '</th>'
			. '<th scope="col">' . esc_html__( 'Warteliste', 'ttleague' ) . '</th>'
			. '</tr></thead><tbody>';
		foreach ( $events as $event ) {
			$limit = null === $event['max_participants']
				? (string) $event['registered']
				/* translators: 1: Anzahl Teilnehmende, 2: Höchstzahl */
				: sprintf( __( '%1$d von %2$d', 'ttleague' ), $event['registered'], $event['max_participants'] );
			$out .= '<tr>'
				. '<td class="column-primary"><strong><a href="' . esc_url( self::url( $event['id'] ) ) . '">' . esc_html( $event['title'] ) . '</a></strong></td>'
				. '<td>' . esc_html( Labels::type( $event['type'] ) ) . '</td>'
				. '<td>' . esc_html( Format::datetime( $event['starts_at'] ) ) . '</td>'
				. '<td>' . ( null === $event['deadline'] ? '<span class="ttl-tk-muted">–</span>' : esc_html( Format::datetime( $event['deadline'] ) ) ) . '</td>'
				. '<td>' . self::status_badge( $event['status'] ) . '</td>'
				. '<td>' . esc_html( $limit ) . '</td>'
				. '<td>' . esc_html( (string) $event['waitlist'] ) . '</td>'
				. '</tr>';
		}
		return $out . '</tbody></table></div>';
	}

	/**
	 * Formular für einen neuen Anlass.
	 *
	 * @param array<string,string>                                        $values  Vorbelegung.
	 * @param array<int,string>                                           $seasons Saison-ID => Bezeichnung.
	 * @param array{type:string,message:string,details:list<string>}|null $flash   Rückmeldung.
	 */
	public static function new_html( array $values, array $seasons, ?array $flash ): string {
		return '<div class="wrap ttl-tk"><h1 class="wp-heading-inline">' . esc_html__( 'Spielbetrieb: Anlass anlegen', 'ttleague' ) . '</h1> '
			. '<a class="page-title-action" href="' . esc_url( self::url() ) . '">' . esc_html__( 'Alle Anlässe', 'ttleague' ) . '</a><hr class="wp-header-end">'
			. DashboardPage::notice( $flash )
			. self::form( 0, $values, $seasons, __( 'Anlass anlegen', 'ttleague' ) )
			. '</div>';
	}

	/**
	 * Ein Anlass mit Teilnehmerliste und allen Aktionen.
	 *
	 * @param array<string,mixed>                                         $event         Anlass.
	 * @param list<array<string,mixed>>                                   $registrations Aus EventRepository::registrations().
	 * @param list<array{person_id:int,name:string,club:string|null}>     $candidates    Personen für die Anmeldung von Hand.
	 * @param array<string,string>                                        $values        Werte des Formulars.
	 * @param array<int,string>                                           $seasons       Saison-ID => Bezeichnung.
	 * @param array{type:string,message:string,details:list<string>}|null $flash         Rückmeldung.
	 * @param \DateTimeZone                                               $timezone      Zeitzone der Website.
	 */
	public static function detail_html( array $event, array $registrations, array $candidates, array $values, array $seasons, ?array $flash, \DateTimeZone $timezone ): string {
		$id         = (int) $event['id'];
		$cancelled  = Rules::CANCELLED === $event['status'];
		$registered = array_values( array_filter( $registrations, static fn( array $row ): bool => Rules::REGISTERED === $row['status'] ) );
		$waiting    = array_values( array_filter( $registrations, static fn( array $row ): bool => Rules::WAITLIST === $row['status'] ) );

		/* translators: %s: Titel des Anlasses */
		$out = '<div class="wrap ttl-tk"><h1 class="wp-heading-inline">' . esc_html( sprintf( __( 'Spielbetrieb: Anlass «%s»', 'ttleague' ), $event['title'] ) ) . '</h1> '
			. '<a class="page-title-action" href="' . esc_url( self::url() ) . '">' . esc_html__( 'Alle Anlässe', 'ttleague' ) . '</a><hr class="wp-header-end">'
			. DashboardPage::notice( $flash );

		$facts = array(
			Labels::type( $event['type'] ),
			Format::datetime( $event['starts_at'] ),
		);
		if ( null !== $event['location'] ) {
			$facts[] = $event['location'];
		}
		$facts[] = null === $event['max_participants']
			? __( 'ohne Höchstzahl', 'ttleague' )
			/* translators: %d: Höchstzahl */
			: sprintf( __( 'Höchstzahl %d', 'ttleague' ), $event['max_participants'] );
		$out .= '<p>' . self::status_badge( $event['status'] ) . ' ' . esc_html( implode( ' · ', $facts ) ) . '</p>'
			. '<p class="ttl-tk-muted">'
			/* translators: %s: Shortcode */
			. sprintf( esc_html__( 'Anmeldung in der Ausschreibung: Block «Anlass: Anmeldung» oder Shortcode %s.', 'ttleague' ), '<code>[ttleague view="event-registration" event="' . $id . '"]</code>' )
			. '</p>';

		$out .= '<h2 id="ttl-teilnehmende">' . esc_html__( 'Teilnehmende', 'ttleague' ) . ' <span class="count">(' . count( $registered ) . ')</span></h2>'
			. self::participants( $id, $registered, $cancelled, $timezone, __( 'Noch niemand angemeldet.', 'ttleague' ) );
		if ( array() !== $waiting ) {
			$out .= '<h2 id="ttl-warteliste">' . esc_html__( 'Warteliste', 'ttleague' ) . ' <span class="count">(' . count( $waiting ) . ')</span></h2>'
				. self::participants( $id, $waiting, $cancelled, $timezone, '' );
		}
		if ( array() !== $registrations ) {
			$out .= '<p><a class="button" href="' . esc_url( Exports::url( Export::NAME, array( 'event' => (string) $id ) ) ) . '">' . esc_html__( 'Teilnehmerliste als CSV', 'ttleague' ) . '</a></p>';
		}

		if ( $cancelled ) {
			return $out . '<p>' . esc_html__( 'Der Anlass ist abgesagt. Er lässt sich nicht mehr ändern; die Anmeldungen bleiben zur Nachvollziehbarkeit stehen.', 'ttleague' ) . '</p></div>';
		}

		$out .= '<h2 id="ttl-anmelden">' . esc_html__( 'Von Hand anmelden', 'ttleague' ) . '</h2>' . self::register_form( $id, $candidates )
			. '<h2 id="ttl-aendern">' . esc_html__( 'Anlass ändern', 'ttleague' ) . '</h2>' . self::form( $id, $values, $seasons, __( 'Änderungen speichern', 'ttleague' ) )
			. '<h2 id="ttl-status">' . esc_html__( 'Anmeldung schliessen, Anlass absagen', 'ttleague' ) . '</h2>' . self::status_forms( $event );
		return $out . '</div>';
	}

	/**
	 * Werte eines Anlasses für das Formular.
	 *
	 * @param array<string,mixed> $event Anlass.
	 * @return array<string,string>
	 */
	public static function values( array $event ): array {
		$local = static fn( ?string $value ): string => null === $value ? '' : str_replace( ' ', 'T', substr( $value, 0, 16 ) );
		$post  = '';
		if ( null !== $event['post_id'] ) {
			$url  = get_permalink( $event['post_id'] );
			$post = is_string( $url ) && '' !== $url ? $url : (string) $event['post_id'];
		}
		return array(
			'season_id'        => null === $event['season_id'] ? '' : (string) $event['season_id'],
			'type'             => (string) $event['type'],
			'title'            => (string) $event['title'],
			'starts_at'        => $local( $event['starts_at'] ),
			'ends_at'          => $local( $event['ends_at'] ),
			'location'         => (string) $event['location'],
			'deadline'         => $local( $event['deadline'] ),
			'max_participants' => null === $event['max_participants'] ? '' : (string) $event['max_participants'],
			'post'             => $post,
		);
	}

	/**
	 * Saisons für die Auswahl: die aktive, die geplante und die des Anlasses.
	 *
	 * @return array<int,string>
	 */
	private static function seasons( ?int $current ): array {
		$repo    = new SeasonRepository( Services::db() );
		$seasons = array();
		foreach ( array( $repo->active(), $repo->planned(), null === $current ? null : $repo->by_id( $current ) ) as $season ) {
			if ( null !== $season ) {
				$seasons[ (int) $season['id'] ] = SeasonRepository::PLANNED === $season['status']
					/* translators: %s: Saison */
					? sprintf( __( '%s (geplant)', 'ttleague' ), $season['name'] )
					: (string) $season['name'];
			}
		}
		return $seasons;
	}

	/**
	 * @param list<array<string,mixed>> $rows Anmeldungen eines Status.
	 */
	private static function participants( int $event_id, array $rows, bool $cancelled, \DateTimeZone $timezone, string $none ): string {
		if ( array() === $rows ) {
			return '' === $none ? '' : '<p>' . esc_html( $none ) . '</p>';
		}
		$out = '<table class="widefat striped"><thead><tr>'
			. '<th scope="col" style="width:3em">' . esc_html__( 'Nr.', 'ttleague' ) . '</th>'
			. '<th scope="col" class="column-primary">' . esc_html__( 'Name', 'ttleague' ) . '</th>'
			. '<th scope="col">' . esc_html__( 'Club', 'ttleague' ) . '</th>'
			. '<th scope="col">' . esc_html__( 'Angemeldet am', 'ttleague' ) . '</th>'
			. '<th scope="col">' . esc_html__( 'Bemerkung', 'ttleague' ) . '</th>'
			. '<th scope="col"><span class="screen-reader-text">' . esc_html__( 'Aktionen', 'ttleague' ) . '</span></th>'
			. '</tr></thead><tbody>';
		foreach ( $rows as $i => $row ) {
			$person = (int) $row['person_id'];
			$action = '';
			if ( ! $cancelled ) {
				$action = '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">'
					. '<input type="hidden" name="action" value="' . esc_attr( AdminActions::UNREGISTER ) . '">'
					. '<input type="hidden" name="event" value="' . esc_attr( (string) $event_id ) . '">'
					. '<input type="hidden" name="person" value="' . esc_attr( (string) $person ) . '">'
					. wp_nonce_field( AdminActions::UNREGISTER . '_' . $event_id . '_' . $person, '_wpnonce', true, false )
					/* translators: %s: Name */
					. '<button type="submit" class="button button-small" aria-label="' . esc_attr( sprintf( __( '%s abmelden', 'ttleague' ), $row['name'] ) ) . '">' . esc_html__( 'Abmelden', 'ttleague' ) . '</button>'
					. '</form>';
			}
			$out .= '<tr id="' . esc_attr( 'ttl-person-' . $person ) . '">'
				. '<td>' . ( $i + 1 ) . '</td>'
				. '<td class="column-primary"><strong>' . esc_html( $row['name'] ) . '</strong></td>'
				. '<td>' . esc_html( (string) $row['club'] ) . '</td>'
				. '<td>' . esc_html( Format::datetime( self::local( $row['created_at'], $timezone ) ) ) . '</td>'
				. '<td>' . esc_html( (string) $row['note'] ) . '</td>'
				. '<td>' . $action . '</td>'
				. '</tr>';
		}
		return $out . '</tbody></table>';
	}

	/**
	 * @param list<array{person_id:int,name:string,club:string|null}> $candidates Personen.
	 */
	private static function register_form( int $event_id, array $candidates ): string {
		if ( array() === $candidates ) {
			return '<p>' . esc_html__( 'Alle Personen der Saison sind bereits angemeldet.', 'ttleague' ) . '</p>';
		}
		$options = '<option value="">' . esc_html__( 'Person wählen …', 'ttleague' ) . '</option>';
		foreach ( $candidates as $candidate ) {
			$label    = null === $candidate['club'] ? $candidate['name'] : $candidate['name'] . ' (' . $candidate['club'] . ')';
			$options .= '<option value="' . esc_attr( (string) $candidate['person_id'] ) . '">' . esc_html( $label ) . '</option>';
		}
		return '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="ttl-tk-form">'
			. '<input type="hidden" name="action" value="' . esc_attr( AdminActions::REGISTER ) . '">'
			. '<input type="hidden" name="event" value="' . esc_attr( (string) $event_id ) . '">'
			. wp_nonce_field( AdminActions::REGISTER . '_' . $event_id, '_wpnonce', true, false )
			. '<label for="ttl-event-person">' . esc_html__( 'Person', 'ttleague' ) . '</label>'
			. '<select id="ttl-event-person" name="person" required>' . $options . '</select>'
			. '<label for="ttl-event-note">' . esc_html__( 'Bemerkung (freiwillig)', 'ttleague' ) . '</label>'
			. '<input type="text" id="ttl-event-note" name="note" maxlength="200" class="regular-text">'
			. '<p class="ttl-tk-muted">' . esc_html__( 'Die TK meldet auch nach dem Anmeldeschluss an. Ist die Höchstzahl erreicht, kommt die Person auf die Warteliste.', 'ttleague' ) . '</p>'
			. '<button type="submit" class="button button-primary">' . esc_html__( 'Anmelden', 'ttleague' ) . '</button>'
			. '</form>';
	}

	/**
	 * Formular zum Anlegen (event 0) oder Ändern.
	 *
	 * @param array<string,string> $values  Werte.
	 * @param array<int,string>    $seasons Saison-ID => Bezeichnung.
	 */
	private static function form( int $event_id, array $values, array $seasons, string $submit ): string {
		$value = static fn( string $key ): string => (string) ( $values[ $key ] ?? '' );
		$row   = static fn( string $id, string $label, string $field_html, string $help = '' ): string => '<tr><th scope="row"><label for="' . esc_attr( $id ) . '">' . esc_html( $label ) . '</label></th><td>'
			. $field_html . ( '' === $help ? '' : '<p class="description">' . esc_html( $help ) . '</p>' ) . '</td></tr>';

		$types = '';
		foreach ( Labels::types() as $type => $label ) {
			$types .= '<option value="' . esc_attr( $type ) . '"' . selected( $type, $value( 'type' ), false ) . '>' . esc_html( $label ) . '</option>';
		}
		$season_options = '<option value="">' . esc_html__( 'Keine Saison', 'ttleague' ) . '</option>';
		foreach ( $seasons as $season_id => $name ) {
			$season_options .= '<option value="' . esc_attr( (string) $season_id ) . '"' . selected( (string) $season_id, $value( 'season_id' ), false ) . '>' . esc_html( $name ) . '</option>';
		}
		$datetime = static fn( string $name, bool $required ): string => '<input type="datetime-local" id="ttl-event-' . esc_attr( $name ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( $value( $name ) ) . '"' . ( $required ? ' required' : '' ) . '>';

		return '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">'
			. '<input type="hidden" name="action" value="' . esc_attr( AdminActions::SAVE ) . '">'
			. '<input type="hidden" name="event" value="' . esc_attr( (string) $event_id ) . '">'
			. wp_nonce_field( AdminActions::SAVE . '_' . $event_id, '_wpnonce', true, false )
			. '<table class="form-table" role="presentation"><tbody>'
			. $row( 'ttl-event-title', __( 'Titel', 'ttleague' ), '<input type="text" id="ttl-event-title" name="title" value="' . esc_attr( $value( 'title' ) ) . '" maxlength="' . EventInput::TITLE_LENGTH . '" class="regular-text" required>' )
			. $row( 'ttl-event-type', __( 'Art', 'ttleague' ), '<select id="ttl-event-type" name="type">' . $types . '</select>' )
			. $row( 'ttl-event-season_id', __( 'Saison', 'ttleague' ), '<select id="ttl-event-season_id" name="season_id">' . $season_options . '</select>', __( 'TK-Chefs melden die Spieler an, die in dieser Saison für ihren Club gemeldet sind; ohne Saison gilt die aktive.', 'ttleague' ) )
			. $row( 'ttl-event-starts_at', __( 'Beginn', 'ttleague' ), $datetime( 'starts_at', true ) )
			. $row( 'ttl-event-ends_at', __( 'Ende', 'ttleague' ), $datetime( 'ends_at', false ) )
			. $row( 'ttl-event-location', __( 'Ort', 'ttleague' ), '<input type="text" id="ttl-event-location" name="location" value="' . esc_attr( $value( 'location' ) ) . '" maxlength="' . EventInput::LOCATION_LENGTH . '" class="regular-text">' )
			. $row( 'ttl-event-deadline', __( 'Anmeldeschluss', 'ttleague' ), $datetime( 'deadline', false ), __( 'Bis zu diesem Zeitpunkt melden sich Personen selbst an und ab, danach nur noch die TK. Leer: bis zum Beginn.', 'ttleague' ) )
			. $row( 'ttl-event-max_participants', __( 'Höchstzahl', 'ttleague' ), '<input type="number" id="ttl-event-max_participants" name="max_participants" value="' . esc_attr( $value( 'max_participants' ) ) . '" min="1" max="' . EventInput::MAX_LIMIT . '" step="1" class="small-text">', __( 'Leer: unbegrenzt. Ist die Höchstzahl erreicht, kommen weitere Anmeldungen auf die Warteliste.', 'ttleague' ) )
			. $row( 'ttl-event-post', __( 'Ausschreibung', 'ttleague' ), '<input type="text" id="ttl-event-post" name="post" value="' . esc_attr( $value( 'post' ) ) . '" class="regular-text">', __( 'Adresse oder ID des Beitrags oder der Seite mit der Ausschreibung (freiwillig). Dorthin führen die Links in den Benachrichtigungen.', 'ttleague' ) )
			. '</tbody></table>'
			. '<p class="submit"><button type="submit" class="button button-primary">' . esc_html( $submit ) . '</button></p>'
			. '</form>';
	}

	/**
	 * @param array<string,mixed> $event Anlass.
	 */
	private static function status_forms( array $event ): string {
		$id     = (int) $event['id'];
		$open   = Rules::OPEN === $event['status'];
		$hidden = '<input type="hidden" name="action" value="' . esc_attr( AdminActions::STATUS ) . '">'
			. '<input type="hidden" name="event" value="' . esc_attr( (string) $id ) . '">'
			. wp_nonce_field( AdminActions::STATUS . '_' . $id, '_wpnonce', true, false );
		$out    = '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="ttl-tk-form">' . $hidden
			. '<input type="hidden" name="decision" value="' . esc_attr( $open ? 'close' : 'reopen' ) . '">'
			. '<p>' . esc_html( $open ? __( 'Nach dem Schliessen ändert nur noch die TK die Anmeldungen.', 'ttleague' ) : __( 'Die Anmeldung ist geschlossen. Nach dem Öffnen melden sich Personen bis zum Anmeldeschluss wieder selbst an und ab.', 'ttleague' ) ) . '</p>'
			. '<button type="submit" class="button">' . esc_html( $open ? __( 'Anmeldung schliessen', 'ttleague' ) : __( 'Anmeldung wieder öffnen', 'ttleague' ) ) . '</button>'
			. '</form>';
		return $out . '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="ttl-tk-form" style="margin-top:16px">' . $hidden
			. '<input type="hidden" name="decision" value="cancel">'
			. '<p><label><input type="checkbox" name="confirm" value="1" required> ' . esc_html__( 'Ja, den Anlass absagen. Alle Teilnehmenden und die Warteliste erhalten eine Mitteilung; das lässt sich nicht zurücknehmen.', 'ttleague' ) . '</label></p>'
			. '<button type="submit" class="button button-link-delete">' . esc_html__( 'Anlass absagen', 'ttleague' ) . '</button>'
			. '</form>';
	}

	/**
	 * Status als Marke; die Farben sind die der Kontostatus im Backend (Tk::styles()).
	 */
	private static function status_badge( string $status ): string {
		$variant = match ( $status ) {
			Rules::OPEN      => 'active',
			Rules::CANCELLED => 'no_email',
			default          => 'invited',
		};
		return '<span class="' . esc_attr( 'ttl-tk-status ttl-tk-status--' . $variant ) . '">' . esc_html( Labels::status( $status ) ) . '</span>';
	}

	/**
	 * UTC-Zeitpunkt als Ortszeit «Y-m-d H:i:s».
	 */
	public static function local( string $utc, \DateTimeZone $timezone ): string {
		$time = \DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s', $utc, new \DateTimeZone( 'UTC' ) );
		return false === $time ? $utc : $time->setTimezone( $timezone )->format( 'Y-m-d H:i:s' );
	}
}
