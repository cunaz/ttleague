<?php
/**
 * Ansicht des Blocks ttleague/event-registration: Anlass mit An- und Abmeldung.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Events;

use TTLeague\Access\Capabilities;
use TTLeague\Accounts\LoginFlow;
use TTLeague\Domain\Events\RegistrationRules as Rules;
use TTLeague\Frontend\Assets;
use TTLeague\Frontend\Support\Format;
use TTLeague\Frontend\Support\Html;
use TTLeague\Frontend\Views\View;
use TTLeague\Infrastructure\Flash;
use TTLeague\Results\Actor;
use TTLeague\Results\Results;

/**
 * Was wer sieht (docs/anlaesse.md):
 *
 * - Alle: Titel, Termin, Ort, Anmeldeschluss, Anzahl Angemeldete und freie Plätze.
 * - Nicht angemeldete Besucher: dazu den Link zum Login.
 * - Angemeldete Personen mit verknüpfter Person: ihren Stand und das Formular zum An- oder Abmelden.
 * - TK-Chefs: dazu die Spieler ihres Clubs zum An- und Abmelden.
 * - Personen mit Funktion in der aktiven Saison (Captain, TK-Chef) und die TK: die Namen der
 *   Teilnehmenden und der Warteliste.
 *
 * Der öffentliche Teil enthält keine Namen. Der personenbezogene Teil wird bei jeder Anfrage aus
 * der Datenbank gebaut und nie im Cache der Lesemodelle abgelegt; für angemeldete Personen
 * sendet WordPress Kopfzeilen gegen das Zwischenspeichern der Seite. In der Vorschau des Editors
 * (REST) erscheint nur der öffentliche Teil.
 */
final class RegistrationView extends View {

	public const STYLE = 'ttleague-events';

	/** Bereich der Rückmeldungen (Flash) pro Anlass. */
	public const FLASH = 'event';

	public function render( array $attrs ): string {
		$event_id = (int) ( $attrs['event'] ?? 0 );
		$event    = $event_id > 0 ? Events::repository()->find( $event_id ) : null;
		if ( null === $event ) {
			// Den Hinweis sehen nur Personen, die den Block korrigieren können.
			return current_user_can( 'edit_posts' ) || current_user_can( Capabilities::TK )
				? Html::notice( __( 'Anlass nicht gefunden. Wähle im Block einen Anlass aus «Spielbetrieb → Anlässe».', 'ttleague' ), 'warning' )
				: '';
		}
		self::enqueue_style();
		$preview = defined( 'REST_REQUEST' ) && REST_REQUEST;
		$actor   = is_user_logged_in() && ! $preview ? Results::current_actor() : null;
		return self::html( array( 'preview' => $preview ) + self::model( $event, $actor, self::current_url( $event ) ) );
	}

	/**
	 * Daten der Ansicht für das angemeldete Konto oder, ohne Konto, für nicht angemeldete Besucher.
	 *
	 * @param array<string,mixed> $event    Anlass.
	 * @param Actor|null          $actor    Angemeldetes Konto oder null.
	 * @param string              $redirect Adresse der Seite mit dem Block.
	 * @return array<string,mixed>
	 */
	public static function model( array $event, ?Actor $actor, string $redirect ): array {
		$repo    = Events::repository();
		$service = Events::registrations();
		$counts  = $repo->counts( $event['id'] );
		$model   = array(
			'event'    => $event,
			'counts'   => $counts,
			'free'     => Rules::free_places( $event['max_participants'], $counts[ Rules::REGISTERED ] ),
			'blocked'  => $service->blocked( $event, null !== $actor && $actor->is_tk ),
			'viewer'   => 'guest',
			'own'      => null,
			'club'     => array(),
			'names'    => null,
			'flash'    => null,
			'redirect' => $redirect,
			'login'    => LoginFlow::page_url( array( 'redirect_to' => $redirect ) ),
			'admin'    => null,
			'preview'  => false,
		);
		if ( null === $actor ) {
			return $model;
		}
		$model['flash'] = Flash::take( self::FLASH . $event['id'], $actor->user_id );
		$model['admin'] = $actor->is_tk ? AdminPage::url( $event['id'] ) : null;
		$list           = $repo->registrations( $event['id'], $service->season_for( $event ) );
		if ( $actor->is_tk || self::has_function( $actor ) ) {
			$model['names'] = $list;
		}
		if ( null === $actor->person_id ) {
			$model['viewer'] = 'unlinked';
			return $model;
		}
		$model['viewer'] = 'person';
		$model['person'] = $actor->person_id;
		$position        = 0;
		foreach ( $list as $row ) {
			$position += Rules::WAITLIST === $row['status'] ? 1 : 0;
			if ( $row['person_id'] === $actor->person_id ) {
				$model['own'] = array(
					'status'   => $row['status'],
					'note'     => $row['note'],
					'position' => $position,
				);
				break;
			}
		}
		$model['club'] = array_values( array_filter( $service->club_players( $actor, $event ), static fn( array $player ): bool => $player['person_id'] !== $actor->person_id ) );
		return $model;
	}

	/**
	 * HTML der Ansicht.
	 *
	 * @param array<string,mixed> $model Daten aus model().
	 */
	public static function html( array $model ): string {
		$event = $model['event'];
		$id    = 'ttl-anlass-' . (int) $event['id'];
		$out   = '<section class="ttl-section ttl-event" id="' . esc_attr( $id ) . '" aria-labelledby="' . esc_attr( $id . '-titel' ) . '">'
			. '<h3 class="ttl-section__title" id="' . esc_attr( $id . '-titel' ) . '">' . esc_html( $event['title'] ) . ' ' . Html::badge( Labels::type( $event['type'] ), 'muted' ) . '</h3>'
			. self::notice( $model['flash'] )
			. self::facts( $event, $model['counts'], $model['free'] );

		if ( Rules::BLOCK_CANCELLED === $model['blocked'] ) {
			$out .= Html::notice( __( 'Der Anlass ist abgesagt.', 'ttleague' ), 'warning' );
		}
		if ( ! empty( $model['preview'] ) ) {
			return $out . '<p class="ttl-muted">' . esc_html__( 'Angemeldete Personen sehen hier ihre An- und Abmeldung.', 'ttleague' ) . '</p></section>';
		}

		switch ( $model['viewer'] ) {
			case 'person':
				$out .= self::own( $model ) . self::club( $model );
				break;
			case 'unlinked':
				$out .= Html::notice( __( 'Dein Konto ist mit keiner Person verknüpft. Bitte wende dich an die TK.', 'ttleague' ), 'warning' );
				break;
			default:
				$out .= self::guest( $model );
		}
		if ( null !== $model['names'] ) {
			$out .= self::names( $model['names'] );
		}
		if ( null !== $model['admin'] ) {
			$out .= '<p class="ttl-muted">' . Html::link( $model['admin'], __( 'Im Backend verwalten', 'ttleague' ) ) . '</p>';
		}
		return $out . '</section>';
	}

	/**
	 * Anlässe für die Auswahl im Editor (enqueue_block_editor_assets).
	 */
	public static function editor_data(): void {
		$events = array();
		try {
			foreach ( Events::repository()->all() as $event ) {
				$events[] = array(
					'value' => (string) $event['id'],
					'label' => Format::date_plain( $event['starts_at'] ) . ' ' . $event['title'],
				);
			}
		} catch ( \Throwable ) {
			$events = array();
		}
		wp_add_inline_script( Assets::EDITOR, 'window.ttleagueEvents = ' . wp_json_encode( $events ) . ';', 'before' );
	}

	/**
	 * Adresse der Seite mit dem Block: die angezeigte Seite, sonst die Ausschreibung, sonst die Startseite.
	 *
	 * @param array<string,mixed> $event Anlass.
	 */
	public static function current_url( array $event ): string {
		$queried = is_singular() ? get_queried_object_id() : 0;
		foreach ( array( $queried, (int) $event['post_id'] ) as $post_id ) {
			$url = $post_id > 0 ? get_permalink( $post_id ) : false;
			if ( is_string( $url ) && '' !== $url ) {
				return $url;
			}
		}
		return home_url( '/' );
	}

	/**
	 * Hat die Person eine Funktion in der aktiven Saison (Captain oder TK-Chef)?
	 */
	private static function has_function( Actor $actor ): bool {
		$policy = Capabilities::policy();
		$season = $policy->active_season_id();
		if ( null === $actor->person_id || null === $season ) {
			return false;
		}
		$roles = $policy->roles( $actor->person_id, $season );
		return array() !== $roles['captain_teams'] || array() !== $roles['tk_chef_clubs'];
	}

	private static function enqueue_style(): void {
		if ( ! wp_style_is( self::STYLE, 'registered' ) ) {
			$file = 'assets/css/events.css';
			$path = TTLEAGUE_DIR . $file;
			wp_register_style( self::STYLE, TTLEAGUE_URL . $file, array( Assets::STYLE ), TTLEAGUE_VERSION . ( is_readable( $path ) ? '.' . (string) filemtime( $path ) : '' ) );
		}
		wp_enqueue_style( self::STYLE );
	}

	/**
	 * Öffentliche Eckdaten: Termin, Ort, wirksamer Anmeldeschluss (ohne gesetzte Frist der Beginn),
	 * Anzahl und freie Plätze.
	 *
	 * @param array<string,mixed>                $event  Anlass.
	 * @param array{registered:int,waitlist:int} $counts Anzahl Teilnehmende und Warteliste.
	 */
	private static function facts( array $event, array $counts, ?int $free ): string {
		$when = Format::datetime( $event['starts_at'] );
		if ( null !== $event['ends_at'] ) {
			$same  = substr( $event['ends_at'], 0, 10 ) === substr( $event['starts_at'], 0, 10 );
			$when .= $same ? '–' . Format::time( $event['ends_at'] ) : ' – ' . Format::datetime( $event['ends_at'] );
		}
		/* translators: %d: Anzahl Personen */
		$registered = sprintf( _n( '%d Person', '%d Personen', $counts[ Rules::REGISTERED ], 'ttleague' ), $counts[ Rules::REGISTERED ] );
		if ( $counts[ Rules::WAITLIST ] > 0 ) {
			/* translators: 1: Angemeldete, z. B. «12 Personen», 2: Anzahl auf der Warteliste */
			$registered = sprintf( __( '%1$s, %2$d auf der Warteliste', 'ttleague' ), $registered, $counts[ Rules::WAITLIST ] );
		}
		$items = array(
			__( 'Termin', 'ttleague' )         => '<time datetime="' . esc_attr( str_replace( ' ', 'T', substr( $event['starts_at'], 0, 16 ) ) ) . '">' . esc_html( $when ) . '</time>',
			__( 'Ort', 'ttleague' )            => esc_html( (string) $event['location'] ),
			__( 'Anmeldeschluss', 'ttleague' ) => esc_html( Format::datetime( Rules::effective_deadline( $event['deadline'], $event['starts_at'] ) ) ),
			__( 'Angemeldet', 'ttleague' )     => esc_html( $registered ),
		);
		if ( null !== $free ) {
			/* translators: 1: freie Plätze, 2: Höchstzahl */
			$items[ __( 'Freie Plätze', 'ttleague' ) ] = esc_html( sprintf( __( '%1$d von %2$d', 'ttleague' ), $free, $event['max_participants'] ) );
		}
		return Html::facts( $items, 'ttl-event__facts' );
	}

	/**
	 * @param array<string,mixed> $model Daten.
	 */
	private static function guest( array $model ): string {
		if ( null !== $model['blocked'] ) {
			return Rules::BLOCK_CANCELLED === $model['blocked'] ? '' : '<p>' . esc_html( Labels::blocked( $model['blocked'] ) ) . '</p>';
		}
		return '<p>' . esc_html__( 'Für die Anmeldung meldest du dich zuerst auf der Website an.', 'ttleague' ) . '</p>'
			. '<p class="ttl-actions"><a class="ttl-button ttl-button--primary" href="' . esc_url( $model['login'] ) . '">' . esc_html__( 'Anmelden', 'ttleague' ) . '</a></p>';
	}

	/**
	 * Eigener Stand mit Formular zum An- oder Abmelden.
	 *
	 * @param array<string,mixed> $model Daten.
	 */
	private static function own( array $model ): string {
		$event   = $model['event'];
		$own     = $model['own'];
		$blocked = $model['blocked'];
		$hint    = null === $blocked || Rules::BLOCK_CANCELLED === $blocked ? '' : '<p class="ttl-muted">' . esc_html( Labels::blocked( $blocked ) ) . '</p>';

		if ( null !== $own ) {
			$text = Rules::WAITLIST === $own['status']
				/* translators: %d: Platz auf der Warteliste */
				? sprintf( __( 'Du stehst auf der Warteliste (Platz %d). Wird ein Platz frei, rückst du nach und erhältst eine Mitteilung.', 'ttleague' ), $own['position'] )
				: __( 'Du bist angemeldet.', 'ttleague' );
			$out = '<p class="ttl-event__state"><strong>' . esc_html( $text ) . '</strong>'
				/* translators: %s: Bemerkung */
				. ( null === $own['note'] ? '' : ' <span class="ttl-muted">' . esc_html( sprintf( __( 'Deine Bemerkung: %s', 'ttleague' ), $own['note'] ) ) . '</span>' ) . '</p>';
			if ( null !== $blocked ) {
				return $out . $hint;
			}
			return $out . self::form( BlockActions::UNREGISTER, $event['id'], $model['person'], $model['redirect'], '', __( 'Abmelden', 'ttleague' ), 'ttl-button--secondary' );
		}
		if ( null !== $blocked ) {
			return $hint;
		}
		$full  = 0 === $model['free'];
		$field = 'ttl-anlass-bemerkung-' . (int) $event['id'];
		return ( $full ? '<p>' . esc_html__( 'Die Höchstzahl ist erreicht. Deine Anmeldung kommt auf die Warteliste; wird ein Platz frei, rückst du nach.', 'ttleague' ) . '</p>' : '' )
			. self::form(
				BlockActions::REGISTER,
				$event['id'],
				$model['person'],
				$model['redirect'],
				'<p class="ttl-field"><label for="' . esc_attr( $field ) . '">' . esc_html__( 'Bemerkung (freiwillig)', 'ttleague' ) . '</label>'
				. '<input type="text" id="' . esc_attr( $field ) . '" name="note" maxlength="200"></p>',
				$full ? __( 'Auf die Warteliste setzen', 'ttleague' ) : __( 'Anmelden', 'ttleague' ),
				'ttl-button--primary'
			);
	}

	/**
	 * Für TK-Chefs: Spieler des Clubs an- und abmelden.
	 *
	 * @param array<string,mixed> $model Daten.
	 */
	private static function club( array $model ): string {
		if ( array() === $model['club'] || null !== $model['blocked'] ) {
			return '';
		}
		$event   = $model['event'];
		$id      = (int) $event['id'];
		$options = '';
		$rows    = '';
		foreach ( $model['club'] as $player ) {
			if ( null === $player['registration'] ) {
				$options .= '<option value="' . esc_attr( (string) $player['person_id'] ) . '">' . esc_html( $player['name'] ) . '</option>';
				continue;
			}
			$rows .= '<li><span class="ttl-event__name">' . esc_html( $player['name'] ) . '</span> ' . Html::badge( Labels::registration( $player['registration'] ), Rules::WAITLIST === $player['registration'] ? 'warning' : 'win' ) . ' '
				/* translators: %s: Name */
				. self::form( BlockActions::UNREGISTER, $id, $player['person_id'], $model['redirect'], '', __( 'Abmelden', 'ttleague' ), 'ttl-button--secondary', sprintf( __( '%s abmelden', 'ttleague' ), $player['name'] ) )
				. '</li>';
		}
		$out = '<div class="ttl-event__club"><h4>' . esc_html__( 'Spieler deines Clubs', 'ttleague' ) . '</h4>';
		if ( '' !== $rows ) {
			$out .= '<ul class="ttl-event__players">' . $rows . '</ul>';
		}
		if ( '' !== $options ) {
			$select = 'ttl-anlass-spieler-' . $id;
			$note   = 'ttl-anlass-spieler-bemerkung-' . $id;
			$out   .= self::form(
				BlockActions::REGISTER,
				$id,
				null,
				$model['redirect'],
				'<p class="ttl-field"><label for="' . esc_attr( $select ) . '">' . esc_html__( 'Spielerin oder Spieler', 'ttleague' ) . '</label>'
				. '<select id="' . esc_attr( $select ) . '" name="person" required><option value="">' . esc_html__( 'Bitte wählen …', 'ttleague' ) . '</option>' . $options . '</select></p>'
				. '<p class="ttl-field"><label for="' . esc_attr( $note ) . '">' . esc_html__( 'Bemerkung (freiwillig)', 'ttleague' ) . '</label>'
				. '<input type="text" id="' . esc_attr( $note ) . '" name="note" maxlength="200"></p>',
				__( 'Für den Club anmelden', 'ttleague' ),
				'ttl-button--secondary'
			);
		}
		return $out . '</div>';
	}

	/**
	 * Namen der Teilnehmenden und der Warteliste (nur für Personen mit Funktion und die TK).
	 *
	 * @param list<array<string,mixed>> $rows Anmeldungen aus EventRepository::registrations().
	 */
	private static function names( array $rows ): string {
		$groups = array(
			Rules::REGISTERED => array(),
			Rules::WAITLIST   => array(),
		);
		foreach ( $rows as $row ) {
			if ( isset( $groups[ $row['status'] ] ) ) {
				$groups[ $row['status'] ][] = '<li>' . esc_html( $row['name'] ) . ( null === $row['club'] ? '' : ' <span class="ttl-muted">(' . esc_html( $row['club'] ) . ')</span>' ) . '</li>';
			}
		}
		$out = '<div class="ttl-event__names">'
			/* translators: %d: Anzahl */
			. '<h4>' . esc_html( sprintf( __( 'Teilnehmende (%d)', 'ttleague' ), count( $groups[ Rules::REGISTERED ] ) ) ) . '</h4>'
			. ( array() === $groups[ Rules::REGISTERED ] ? '<p class="ttl-muted">' . esc_html__( 'Noch niemand angemeldet.', 'ttleague' ) . '</p>' : '<ol>' . implode( '', $groups[ Rules::REGISTERED ] ) . '</ol>' );
		if ( array() !== $groups[ Rules::WAITLIST ] ) {
			/* translators: %d: Anzahl */
			$out .= '<h4>' . esc_html( sprintf( __( 'Warteliste (%d)', 'ttleague' ), count( $groups[ Rules::WAITLIST ] ) ) ) . '</h4><ol>' . implode( '', $groups[ Rules::WAITLIST ] ) . '</ol>';
		}
		return $out . '<p class="ttl-muted">' . esc_html__( 'Die Namen sehen nur Captains, TK-Chefs und die TK.', 'ttleague' ) . '</p></div>';
	}

	/**
	 * Formular an admin-post.php. Ohne Person kommt sie aus den Feldern (Auswahlliste).
	 *
	 * @param string   $fields_html Weitere Felder (sicheres HTML).
	 * @param int|null $person      Person oder null.
	 */
	private static function form( string $action, int $event_id, ?int $person, string $redirect, string $fields_html, string $submit, string $button_class, string $aria_label = '' ): string {
		return '<form class="ttl-form ttl-event__form" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">'
			. '<input type="hidden" name="action" value="' . esc_attr( $action ) . '">'
			. '<input type="hidden" name="event" value="' . esc_attr( (string) $event_id ) . '">'
			. ( null === $person ? '' : '<input type="hidden" name="person" value="' . esc_attr( (string) $person ) . '">' )
			. '<input type="hidden" name="redirect_to" value="' . esc_attr( $redirect ) . '">'
			. '<input type="hidden" name="_wpnonce" value="' . esc_attr( wp_create_nonce( $action . '_' . $event_id ) ) . '">'
			. $fields_html
			. '<button type="submit" class="ttl-button ' . esc_attr( $button_class ) . '"' . ( '' === $aria_label ? '' : ' aria-label="' . esc_attr( $aria_label ) . '"' ) . '>' . esc_html( $submit ) . '</button>'
			. '</form>';
	}

	/**
	 * @param array{type:string,message:string,details:list<string>}|null $flash Rückmeldung.
	 */
	private static function notice( ?array $flash ): string {
		if ( null === $flash ) {
			return '';
		}
		$variant = in_array( $flash['type'], array( 'success', 'error', 'warning' ), true ) ? $flash['type'] : 'info';
		$details = array() === $flash['details'] ? '' : '<ul>' . implode( '', array_map( static fn( string $d ): string => '<li>' . esc_html( $d ) . '</li>', $flash['details'] ) ) . '</ul>';
		return '<div class="ttl-notice ttl-notice--' . esc_attr( $variant ) . '" role="' . ( 'error' === $variant ? 'alert' : 'status' ) . '"><p>' . esc_html( $flash['message'] ) . '</p>' . $details . '</div>';
	}
}
