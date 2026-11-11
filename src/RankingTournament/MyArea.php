<?php
/**
 * Abschnitt «Ranglistenturnier» in «Mein Bereich».
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\RankingTournament;

use TTLeague\Access\Capabilities;
use TTLeague\Accounts\LoginFlow;
use TTLeague\Accounts\MyAreaContext;
use TTLeague\Accounts\Outcome;
use TTLeague\Domain\Ranking\RlPlayerStatus;
use TTLeague\Frontend\Support\Format;
use TTLeague\Infrastructure\Flash;
use TTLeague\Results\Actor;
use TTLeague\Results\ResultException;
use TTLeague\Results\Results;

/**
 * Zeigt der angemeldeten Person: ihre Anmeldung (anmelden, abmelden), die Sperrdaten der
 * kommenden Runden, ihr Aufgebot in freigegebenen Runden (mit Abmeldung für die Runde) und die
 * Gruppenzettel, die sie nachtragen darf. TK-Chefs melden darunter Personen ihres Clubs an.
 *
 * Die Formulare gehen an admin-post.php (Nonce pro Vorgang und Person). Für sich selbst handelt
 * jede Person; für andere braucht es ttl_manage_club für den Club der Person. Die fachlichen
 * Regeln (Anmeldeschluss, Status der Runde) prüft der Dienst Registrations.
 */
final class MyArea {

	public const ACTION = 'ttleague_rl_my';

	/** Nach «Meine Spiele» (10), vor «Mein Club» (20). */
	public const PRIORITY = 15;

	private const FLASH = 'rl';

	public static function register(): void {
		add_action( 'ttleague_my_area_sections', array( self::class, 'section' ), self::PRIORITY );
		add_action( 'admin_post_' . self::ACTION, array( self::class, 'handle' ) );
	}

	/**
	 * Aktion ttleague_my_area_sections.
	 */
	public static function section( MyAreaContext $context ): void {
		$html = self::html( $context, Flash::take( self::FLASH, $context->user_id ) );
		if ( '' !== $html ) {
			wp_enqueue_style( \TTLeague\Results\EntryBlock::STYLE );
		}
		echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- html() escaped jede Ausgabe.
	}

	/**
	 * HTML des Abschnitts; leer, wenn die Person nichts mit dem Turnier zu tun hat.
	 *
	 * @param array{type:string,message:string,details:list<string>}|null $flash Rückmeldung.
	 */
	public static function html( MyAreaContext $context, ?array $flash ): string {
		$service     = RankingTournament::registrations();
		$repo        = $service->repository();
		$competition = $service->active_competition();
		if ( null === $competition || null === $context->season_id ) {
			return '';
		}
		$players = $repo->players_of_person( $context->person_id, $competition['season_id'] );
		$clubs   = $context->roles['tk_chef_clubs'];
		$rounds  = $repo->rounds( $competition['id'] );
		$to_do   = self::sheets( $competition, $rounds, $context );
		if ( array() === $players && array() === $clubs && array() === $to_do ) {
			return '';
		}
		$open = $service->registration_open( $competition );
		$out  = '<section class="ttl-section ttl-rl-my" id="ttl-rl" aria-labelledby="ttl-rl-title">'
			. '<h3 id="ttl-rl-title">' . esc_html( $competition['name'] ) . '</h3>' . self::notice( $flash );

		foreach ( $players as $player_id ) {
			$out .= self::own( $competition, $rounds, $player_id, $open, $context->is_tk );
		}

		if ( array() !== $to_do ) {
			$out .= '<h4>' . esc_html__( 'Gruppenzettel nachtragen', 'ttleague' ) . '</h4><ul class="ttl-my-games__list">';
			foreach ( $to_do as $group ) {
				$label = null === $group['entered_at'] ? __( 'Gruppenzettel nachtragen', 'ttleague' ) : __( 'Gruppenzettel ändern', 'ttleague' );
				$title = Notices::round_label( $group ) . ', ' . Rounds::group_label( $group );
				$out  .= '<li class="ttl-my-games__item"><span class="ttl-my-games__meta">' . esc_html( Format::datetime( $group['start_at'] ) ) . '</span>'
					. '<span class="ttl-my-games__pairing">' . esc_html( $title ) . ( null === $group['entered_at'] ? '' : ' <span class="ttl-badge ttl-badge--muted">' . esc_html__( 'erfasst', 'ttleague' ) . '</span>' ) . '</span>'
					. '<a class="ttl-button ttl-my-games__action' . ( null === $group['entered_at'] ? '' : ' ttl-button--secondary' ) . '" href="' . esc_url( RankingTournament::entry_url( $group['id'] ) ) . '">' . esc_html( $label )
					. '<span class="screen-reader-text">: ' . esc_html( $title ) . '</span></a></li>';
			}
			$out .= '</ul>';
		}

		foreach ( $clubs as $club_id ) {
			$out .= self::club( $competition, $club_id, $open, $context->person_id );
		}
		return $out . '</section>';
	}

	/**
	 * Eigene Anmeldung, Sperrdaten und Aufgebot.
	 *
	 * @param array<string,mixed>       $competition Konkurrenz.
	 * @param list<array<string,mixed>> $rounds      Runden.
	 */
	private static function own( array $competition, array $rounds, int $player_id, bool $open, bool $is_tk ): string {
		$repo        = RankingTournament::repository();
		$player      = $repo->player( $player_id, $competition['season_id'] );
		$participant = $repo->participant( $competition['id'], $player_id );
		if ( null === $player ) {
			return '';
		}
		$registered = null !== $participant && Registrations::REGISTERED === $participant['status'];
		$deadline   = null === $competition['deadline'] ? '' : Format::date_plain( $competition['deadline'] );
		if ( ! $registered ) {
			if ( ! $open && ! $is_tk ) {
				return '<p>' . esc_html__( 'Du bist nicht angemeldet. Die Anmeldefrist ist abgelaufen; Nachmeldungen nimmt die TK entgegen.', 'ttleague' ) . '</p>';
			}
			return '<p>' . esc_html(
				'' === $deadline
					? __( 'Du bist nicht angemeldet.', 'ttleague' )
					/* translators: %s: Datum */
					: sprintf( __( 'Du bist nicht angemeldet. Anmeldeschluss ist am %s.', 'ttleague' ), $deadline )
			) . '</p>' . self::button( 'register', $player_id, 0, __( 'Zum Ranglistenturnier anmelden', 'ttleague' ), true );
		}

		/* translators: %d: Kategorie */
		$status = 0 === $participant['category'] ? __( 'Du bist angemeldet. Die TK teilt dich einer Kategorie zu.', 'ttleague' ) : sprintf( __( 'Du bist angemeldet in Kategorie %d.', 'ttleague' ), $participant['category'] );
		$out    = '<p>' . esc_html( $status ) . '</p>';

		// Aufgebot in freigegebenen Runden.
		foreach ( $rounds as $round ) {
			if ( Rounds::PUBLISHED !== $round['status'] ) {
				continue;
			}
			foreach ( $repo->groups( $round['id'] ) as $group ) {
				$members = array_column( $group['members'], null, 'player_id' );
				if ( isset( $members[ $player_id ] ) ) {
					$out .= self::callup( $round, $group, $members[ $player_id ] );
				}
			}
		}

		// Sperrdaten der kommenden Runden in Planung.
		foreach ( $rounds as $round ) {
			if ( Rounds::PLANNED !== $round['status'] || null === $round['date_from'] || null === $round['date_to'] ) {
				continue;
			}
			$out .= self::blocked_form( $round, $player_id, $player['person_id'] );
		}
		return $out . '<details class="ttl-details"><summary>' . esc_html__( 'Vom Turnier abmelden', 'ttleague' ) . '</summary>'
			. '<p class="ttl-muted">' . esc_html__( 'Du nimmst dann an keiner weiteren Runde teil. Kannst du nur an einer Runde nicht spielen, melde dich bei deinem Aufgebot für diese Runde ab.', 'ttleague' ) . '</p>'
			. self::button( 'withdraw', $player_id, 0, __( 'Vom Ranglistenturnier abmelden', 'ttleague' ), false ) . '</details>';
	}

	/**
	 * Aufgebot einer freigegebenen Runde.
	 *
	 * @param array<string,mixed> $round  Runde.
	 * @param array<string,mixed> $group  Gruppe.
	 * @param array<string,mixed> $member Eigene Zeile in der Gruppe.
	 */
	private static function callup( array $round, array $group, array $member ): string {
		$where = array();
		if ( null !== $group['venue'] ) {
			$where[] = $group['venue']['name'];
			$address = Format::address( $group['venue']['street'], $group['venue']['zip'], $group['venue']['city'] );
			if ( '' !== $address ) {
				$where[] = $address;
			}
		}
		$names = array();
		$chief = '';
		foreach ( $group['members'] as $other ) {
			if ( $other['player_id'] === $group['chief_player_id'] ) {
				$chief = $other['name'];
			}
			if ( RlPlayerStatus::Absent->value !== $other['status'] ) {
				$names[] = $other['name'];
			}
		}
		$when = Format::datetime( $group['start_at'] );
		$out  = '<div class="ttl-card ttl-rl-callup"><h4 class="ttl-card__title">' . esc_html( __( 'Aufgebot', 'ttleague' ) . ': ' . Notices::round_label( $round ) . ', ' . Rounds::group_label( $group ) ) . '</h4>'
			. '<p class="ttl-card__meta">' . esc_html( implode( ' · ', array_filter( array( $when, implode( ', ', $where ), null === $group['host'] ? '' : $group['host']['short'] ) ) ) ) . '</p>'
			. '<p>' . esc_html( __( 'Mitspielende', 'ttleague' ) . ': ' . implode( ', ', $names ) ) . ( '' === $chief ? '' : '<br>' . esc_html( __( 'Gruppenchef', 'ttleague' ) . ': ' . $chief ) ) . '</p>';
		if ( RlPlayerStatus::Scheduled->value === $member['status'] ) {
			$out .= self::button( 'round_withdraw', $member['player_id'], $round['id'], __( 'Für diese Runde abmelden', 'ttleague' ), false );
		} elseif ( RlPlayerStatus::Absent->value === $member['status'] ) {
			$out .= '<p class="ttl-notice ttl-notice--warning" role="status">' . esc_html__( 'Du bist für diese Runde abgemeldet.', 'ttleague' ) . '</p>';
		}
		return $out . '</div>';
	}

	/**
	 * Formular der Sperrdaten für eine Runde in Planung.
	 *
	 * @param array<string,mixed> $round Runde.
	 */
	private static function blocked_form( array $round, int $player_id, int $person_id ): string {
		$repo     = RankingTournament::repository();
		$blocked  = $repo->blocked_dates( array( $person_id ), $round['date_from'], $round['date_to'] )[ $person_id ] ?? array();
		$answered = array() !== $repo->answered( array( $person_id ), $round['date_from'], $round['date_to'] );
		$boxes    = '';
		foreach ( Registrations::evenings( $round ) as $date ) {
			$field  = 'ttl-rl-block-' . $round['id'] . '-' . $player_id . '-' . $date;
			$boxes .= '<p class="ttl-field ttl-field--check"><input type="checkbox" id="' . esc_attr( $field ) . '" name="dates[]" value="' . esc_attr( $date ) . '"' . checked( true, in_array( $date, $blocked, true ), false ) . '> '
				. '<label for="' . esc_attr( $field ) . '">' . esc_html( Format::date( $date ) ) . '</label></p>';
		}
		/* translators: 1: Runde, 2: Woche */
		$title = sprintf( __( 'Sperrdaten für die %1$s (%2$s)', 'ttleague' ), Notices::round_label( $round ), Format::date_range( $round['date_from'], $round['date_to'] ) );
		return '<form class="ttl-form ttl-rl-blocked" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">'
			. self::hidden( 'blocked', $player_id, $round['id'] )
			. '<fieldset><legend>' . esc_html( $title ) . '</legend>'
			. '<p class="ttl-muted">' . esc_html( $answered ? __( 'Gemeldet. Du kannst die Angaben ändern, bis die TK die Runde freigibt.', 'ttleague' ) : __( 'Noch nicht gemeldet. Kreuze die Abende an, an denen du nicht spielen kannst. Kannst du an jedem Abend, speichere ohne Häkchen.', 'ttleague' ) ) . '</p>'
			. $boxes . '</fieldset>'
			. '<button type="submit" class="ttl-button">' . esc_html__( 'Sperrdaten speichern', 'ttleague' ) . '</button></form>';
	}

	/**
	 * Für TK-Chefs: Personen des Clubs an- und abmelden.
	 *
	 * @param array<string,mixed> $competition Konkurrenz.
	 */
	private static function club( array $competition, int $club_id, bool $open, int $own_person ): string {
		$players = RankingTournament::repository()->season_players( $competition['season_id'], $competition['id'], $club_id );
		if ( array() === $players ) {
			return '';
		}
		$rows = '';
		foreach ( $players as $player ) {
			if ( $player['person_id'] === $own_person ) {
				continue;
			}
			$registered = null !== $player['participant'] && Registrations::REGISTERED === $player['participant']['status'];
			$action     = '';
			if ( $registered ) {
				$action = self::button( 'withdraw', $player['player_id'], 0, __( 'Abmelden', 'ttleague' ), false, $player['name'] );
			} elseif ( $open ) {
				$action = self::button( 'register', $player['player_id'], 0, __( 'Anmelden', 'ttleague' ), false, $player['name'] );
			}
			$rows .= '<tr><th scope="row" class="ttl-cell--primary">' . esc_html( $player['name'] ) . '</th>'
				. '<td data-label="' . esc_attr__( 'Klassierung', 'ttleague' ) . '">' . esc_html( Format::classification( $player['classification'] ) ) . '</td>'
				. '<td data-label="' . esc_attr__( 'Ranglistenturnier', 'ttleague' ) . '">' . esc_html(
					$registered
						/* translators: %d: Kategorie */
						? ( 0 === $player['participant']['category'] ? __( 'angemeldet', 'ttleague' ) : sprintf( __( 'angemeldet, Kategorie %d', 'ttleague' ), $player['participant']['category'] ) )
						: __( 'nicht angemeldet', 'ttleague' )
				) . '</td><td>' . $action . '</td></tr>';
		}
		if ( '' === $rows ) {
			return '';
		}
		return '<details class="ttl-details ttl-rl-club"' . ( $open ? ' open' : '' ) . '><summary>' . esc_html( sprintf( /* translators: %s: Club */ __( 'Personen von %s anmelden', 'ttleague' ), $players[0]['club_short'] ) ) . '</summary>'
			. ( $open ? '' : '<p class="ttl-muted">' . esc_html__( 'Die Anmeldefrist ist abgelaufen. Nachmeldungen nimmt die TK entgegen.', 'ttleague' ) . '</p>' )
			. '<table class="ttl-table ttl-table--cards"><thead><tr><th scope="col">' . esc_html__( 'Name', 'ttleague' ) . '</th><th scope="col">' . esc_html__( 'Klassierung', 'ttleague' ) . '</th>'
			. '<th scope="col">' . esc_html__( 'Ranglistenturnier', 'ttleague' ) . '</th><th scope="col"><span class="screen-reader-text">' . esc_html__( 'Aktionen', 'ttleague' ) . '</span></th></tr></thead><tbody>' . $rows . '</tbody></table></details>';
	}

	/**
	 * Gruppen freigegebener Runden, deren Gruppenzettel die Person nachtragen darf
	 * (ttl_enter_rl_group: Gruppenchef, TK-Chef seines Clubs oder des Platzclubs).
	 *
	 * @param array<string,mixed>       $competition Konkurrenz.
	 * @param list<array<string,mixed>> $rounds      Runden.
	 * @return list<array<string,mixed>>
	 */
	private static function sheets( array $competition, array $rounds, MyAreaContext $context ): array {
		if ( 'active' !== $competition['season_status'] ) {
			return array();
		}
		$policy = Capabilities::policy();
		$groups = array();
		foreach ( $rounds as $round ) {
			if ( Rounds::PUBLISHED !== $round['status'] ) {
				continue;
			}
			foreach ( RankingTournament::repository()->groups( $round['id'] ) as $group ) {
				if ( $policy->can_enter_rl_group( $context->person_id, $group['id'] ) ) {
					$groups[] = $group;
				}
			}
		}
		return $groups;
	}

	/**
	 * Formulare speichern.
	 */
	public static function handle(): void {
		$task   = isset( $_POST['do'] ) ? sanitize_key( wp_unslash( (string) $_POST['do'] ) ) : '';
		$player = isset( $_POST['player'] ) ? absint( $_POST['player'] ) : 0;
		$round  = isset( $_POST['round'] ) ? absint( $_POST['round'] ) : 0;
		check_admin_referer( self::nonce( $task, $player, $round ) );
		$actor = Results::current_actor();
		if ( ! self::may_act( $actor, $player ) ) {
			wp_die( esc_html__( 'Dazu fehlt dir die Berechtigung.', 'ttleague' ), '', array( 'response' => 403 ) );
		}
		$service = RankingTournament::registrations();
		try {
			switch ( $task ) {
				case 'register':
					$result = $service->register( $actor, $player );
					/* translators: %d: Kategorie */
					$outcome = Outcome::success( 0 === $result['category'] ? __( 'Angemeldet. Die TK teilt die Kategorie zu.', 'ttleague' ) : sprintf( __( 'Angemeldet in Kategorie %d.', 'ttleague' ), $result['category'] ) );
					break;
				case 'withdraw':
					$service->withdraw( $actor, $player );
					$outcome = Outcome::success( __( 'Vom Ranglistenturnier abgemeldet.', 'ttleague' ) );
					break;
				case 'blocked':
					$dates   = isset( $_POST['dates'] ) && is_array( $_POST['dates'] ) ? array_map( 'sanitize_text_field', wp_unslash( $_POST['dates'] ) ) : array();
					$saved   = $service->set_blocked_dates( $actor, $player, $round, array_values( $dates ) );
					$outcome = Outcome::success(
						array() === $saved
							? __( 'Gespeichert: Du kannst an jedem Abend der Runde spielen.', 'ttleague' )
							/* translators: %s: Liste der Tage */
							: sprintf( __( 'Sperrdaten gespeichert: %s.', 'ttleague' ), implode( ', ', array_map( array( Format::class, 'date_plain' ), $saved ) ) )
					);
					break;
				case 'round_withdraw':
					$service->withdraw_from_round( $actor, $player, $round );
					$outcome = Outcome::success( __( 'Für diese Runde abgemeldet.', 'ttleague' ) );
					break;
				default:
					$outcome = Outcome::error( __( 'Unbekannte Aktion.', 'ttleague' ) );
			}
		} catch ( ResultException $e ) {
			$outcome = Outcome::error( $e->getMessage() );
		}
		Flash::set( self::FLASH, get_current_user_id(), $outcome->type, $outcome->message, $outcome->details );
		wp_safe_redirect( LoginFlow::my_area_url() . '#ttl-rl' );
		exit;
	}

	/**
	 * Rechte der WordPress-Schicht: für sich selbst jede Person mit Konto und verknüpfter Person,
	 * für andere ttl_manage_club für den Club, für den die Person gemeldet ist (die TK darf alles).
	 */
	private static function may_act( Actor $actor, int $player_id ): bool {
		if ( $actor->user_id <= 0 || $player_id <= 0 ) {
			return false;
		}
		if ( $actor->is_tk ) {
			return true;
		}
		$repo   = RankingTournament::repository();
		$season = $repo->active_season_id();
		$player = null === $season ? null : $repo->player( $player_id, $season );
		if ( null === $player || null === $actor->person_id ) {
			return false;
		}
		return $player['person_id'] === $actor->person_id || current_user_can( Capabilities::MANAGE_CLUB, $player['club_id'] );
	}

	private static function nonce( string $task, int $player_id, int $round_id ): string {
		return self::ACTION . '_' . $task . '_' . $player_id . '_' . $round_id;
	}

	private static function hidden( string $task, int $player_id, int $round_id ): string {
		return '<input type="hidden" name="action" value="' . esc_attr( self::ACTION ) . '">'
			. '<input type="hidden" name="do" value="' . esc_attr( $task ) . '">'
			. '<input type="hidden" name="player" value="' . esc_attr( (string) $player_id ) . '">'
			. '<input type="hidden" name="round" value="' . esc_attr( (string) $round_id ) . '">'
			. wp_nonce_field( self::nonce( $task, $player_id, $round_id ), '_wpnonce', true, false );
	}

	/**
	 * Formular mit einem einzigen Knopf.
	 *
	 * @param string $about Name der betroffenen Person für Screenreader, falls nicht man selbst.
	 */
	private static function button( string $task, int $player_id, int $round_id, string $label, bool $primary, string $about = '' ): string {
		return '<form class="ttl-rl-my__action" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">' . self::hidden( $task, $player_id, $round_id )
			. '<button type="submit" class="ttl-button' . ( $primary ? ' ttl-button--primary' : ' ttl-button--secondary' ) . '">' . esc_html( $label )
			. ( '' === $about ? '' : '<span class="screen-reader-text">: ' . esc_html( $about ) . '</span>' ) . '</button></form>';
	}

	/**
	 * @param array{type:string,message:string,details:list<string>}|null $flash Rückmeldung.
	 */
	private static function notice( ?array $flash ): string {
		if ( null === $flash ) {
			return '';
		}
		$variant = in_array( $flash['type'], array( 'success', 'error', 'warning' ), true ) ? $flash['type'] : 'info';
		return '<p class="ttl-notice ttl-notice--' . esc_attr( $variant ) . '" role="' . ( 'error' === $variant ? 'alert' : 'status' ) . '">' . esc_html( $flash['message'] ) . '</p>';
	}
}
