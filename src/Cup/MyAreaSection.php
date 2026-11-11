<?php
/**
 * Abschnitt «Cup» in «Mein Bereich» für TK-Chefs: Cupmannschaften melden und zurückziehen.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Cup;

use TTLeague\Accounts\MyAreaContext;
use TTLeague\Accounts\MyClub;
use TTLeague\Accounts\Outcome;
use TTLeague\Accounts\Services;
use TTLeague\Domain\Rating\ClassificationScale;
use TTLeague\Frontend\Support\Format;
use TTLeague\Infrastructure\Flash;
use TTLeague\Results\Actor;

/**
 * Ein Abschnitt pro Club, den die Person als TK-Chef der aktiven oder der geplanten Saison
 * verwaltet, sofern es dort eine Cup-Konkurrenz gibt. Die Daten kommen aus Entries::for_club(),
 * das das Recht der Person in der Saison der Konkurrenz prüft; eine geplante Saison geht so
 * nie über eine öffentliche Ansicht. Die Formulare gehen an admin-post.php (Forms).
 */
final class MyAreaSection {

	/**
	 * Aktion ttleague_my_area_sections.
	 */
	public static function render( MyAreaContext $context ): void {
		$clubs = Services::policy()->managed_clubs( $context->person_id );
		if ( array() === $clubs ) {
			return;
		}
		$actor   = new Actor( $context->user_id, $context->person_id, $context->is_tk );
		$entries = Cup::entries();
		$out     = '';
		foreach ( $clubs as $club_id ) {
			$out .= self::section( $club_id, $entries->for_club( $club_id, $actor ), Flash::take( Forms::flash_scope( $club_id ), $context->user_id ) );
		}
		if ( '' === $out ) {
			return;
		}
		wp_enqueue_style( MyClub::STYLE );
		echo $out; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Alle Teile werden beim Aufbau escaped.
	}

	public static function anchor( int $club_id ): string {
		return 'ttl-cup-' . $club_id;
	}

	/**
	 * Abschnitt eines Clubs; leer ohne Cup-Konkurrenz.
	 *
	 * @param list<array<string,mixed>>                                   $blocks Konkurrenzen aus Entries::for_club().
	 * @param array{type:string,message:string,details:list<string>}|null $flash  Rückmeldung.
	 */
	public static function section( int $club_id, array $blocks, ?array $flash ): string {
		if ( array() === $blocks ) {
			return '';
		}
		$club = Cup::repository()->club( $club_id );
		$id   = self::anchor( $club_id );
		$out  = '<section class="ttl-section ttl-club ttl-cup-entries" id="' . esc_attr( $id ) . '" aria-labelledby="' . esc_attr( $id . '-titel' ) . '">'
			/* translators: %s: Clubname */
			. '<h3 class="ttl-section__title" id="' . esc_attr( $id . '-titel' ) . '">' . esc_html( sprintf( __( 'Cup: %s', 'ttleague' ), null === $club ? '' : $club['name'] ) ) . '</h3>'
			. self::notice( $flash )
			. '<p class="ttl-muted">' . esc_html__( 'Eine Cupmannschaft besteht aus zwei Spielern deines Clubs. Jeder Spieler steht in der Saison in höchstens einer Cupmannschaft.', 'ttleague' ) . '</p>';
		foreach ( $blocks as $block ) {
			$out .= self::competition( $club_id, $block );
		}
		return $out . '</section>';
	}

	/**
	 * Eine Konkurrenz: Eckdaten, Mannschaften des Clubs, Formular für eine neue Meldung.
	 *
	 * @param array<string,mixed> $block Konkurrenz aus Entries::for_club().
	 */
	private static function competition( int $club_id, array $block ): string {
		$competition = $block['competition'];
		$settings    = $block['settings'];
		$facts       = array();
		if ( null !== $settings['points_limit'] ) {
			/* translators: %d: Punktelimite */
			$facts[] = sprintf( __( 'Summe der Klassierungen höchstens %d', 'ttleague' ), $settings['points_limit'] );
		}
		if ( null !== $settings['registration_deadline'] ) {
			/* translators: %s: Datum */
			$facts[] = sprintf( __( 'Meldeschluss %s', 'ttleague' ), Format::date_plain( $settings['registration_deadline'] ) );
		}
		$out = '<h4 class="ttl-subtitle">' . esc_html( $competition['name'] . ' ' . $competition['season_name'] ) . '</h4>'
			. ( array() === $facts ? '' : '<p>' . esc_html( implode( ' · ', $facts ) ) . '</p>' );

		if ( array() === $block['entries'] ) {
			$out .= '<p>' . esc_html__( 'Dein Club hat noch keine Cupmannschaft gemeldet.', 'ttleague' ) . '</p>';
		} else {
			$rows = '';
			foreach ( $block['entries'] as $entry ) {
				$players = array();
				foreach ( $entry['players'] as $player ) {
					$players[] = esc_html( $player['name'] ) . ( null === $player['classification'] ? '' : ' <span class="ttl-class">' . esc_html( ClassificationScale::label( $player['classification'] ) ) . '</span>' );
				}
				$rows .= '<tr><td class="ttl-cell--primary">' . esc_html( $entry['name'] ) . '</td>'
					. '<td data-label="' . esc_attr__( 'Spieler', 'ttleague' ) . '">' . implode( ', ', $players ) . '</td>'
					. '<td data-label="' . esc_attr__( 'Punktesumme', 'ttleague' ) . '">' . esc_html( null === $entry['points_total'] ? '' : (string) $entry['points_total'] ) . '</td>'
					. '<td data-label="' . esc_attr__( 'Status', 'ttleague' ) . '">' . esc_html( Labels::entry_status( $entry['status'] ) ) . '</td>'
					. '<td>' . ( empty( $entry['can_withdraw'] ) ? '' : self::withdraw_form( $entry ) ) . '</td></tr>';
			}
			$out .= '<table class="ttl-table ttl-table--cards ttl-club__table"><thead><tr>'
				. '<th scope="col">' . esc_html__( 'Mannschaft', 'ttleague' ) . '</th>'
				. '<th scope="col">' . esc_html__( 'Spieler', 'ttleague' ) . '</th>'
				. '<th scope="col">' . esc_html__( 'Punktesumme', 'ttleague' ) . '</th>'
				. '<th scope="col">' . esc_html__( 'Status', 'ttleague' ) . '</th>'
				. '<th scope="col"><span class="screen-reader-text">' . esc_html__( 'Aktionen', 'ttleague' ) . '</span></th>'
				. '</tr></thead><tbody>' . $rows . '</tbody></table>';
		}

		if ( ! $block['open'] ) {
			return $out . '<p class="ttl-muted">' . esc_html( (string) $block['closed_reason'] ) . '</p>';
		}
		return $out . self::register_form( $club_id, $competition['id'], $block['players'], Forms::BACK_AREA );
	}

	/**
	 * Formular «Cupmannschaft melden»: zwei Auswahllisten mit den gemeldeten Spielern des Clubs.
	 * Spieler, die schon in einer Cupmannschaft stehen, sind sichtbar, aber gesperrt.
	 *
	 * @param list<array{player_id:int,name:string,classification:int,entry:string|null}> $players Spieler des Clubs.
	 * @param string                                                                      $back    Ziel nach der Aktion (Forms::BACK_*).
	 */
	public static function register_form( int $club_id, int $competition_id, array $players, string $back ): string {
		$free = array_filter( $players, static fn( array $player ): bool => null === $player['entry'] );
		if ( count( $free ) < 2 ) {
			return '<p class="ttl-muted">' . esc_html__( 'Für eine weitere Cupmannschaft braucht es zwei gemeldete Spieler, die noch in keiner Cupmannschaft stehen.', 'ttleague' ) . '</p>';
		}
		$options = '<option value="">' . esc_html__( 'Bitte wählen', 'ttleague' ) . '</option>';
		foreach ( $players as $player ) {
			$label = $player['name'] . ' (' . ClassificationScale::label( $player['classification'] ) . ')';
			if ( null !== $player['entry'] ) {
				/* translators: 1: Spieler mit Klassierung, 2: Cupmannschaft */
				$label = sprintf( __( '%1$s, schon in %2$s', 'ttleague' ), $label, $player['entry'] );
			}
			$options .= '<option value="' . esc_attr( (string) $player['player_id'] ) . '"' . ( null === $player['entry'] ? '' : ' disabled' ) . '>' . esc_html( $label ) . '</option>';
		}
		$prefix = 'ttl-cup-' . $competition_id . '-' . $club_id;
		return '<form class="ttl-form ttl-cup-form" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">'
			. '<input type="hidden" name="action" value="' . esc_attr( Forms::REGISTER ) . '">'
			. '<input type="hidden" name="competition" value="' . esc_attr( (string) $competition_id ) . '">'
			. '<input type="hidden" name="club" value="' . esc_attr( (string) $club_id ) . '">'
			. '<input type="hidden" name="back" value="' . esc_attr( $back ) . '">'
			. wp_nonce_field( Forms::REGISTER . '_' . $competition_id . '_' . $club_id, '_wpnonce', true, false )
			. '<p class="ttl-field"><label for="' . esc_attr( $prefix . '-1' ) . '">' . esc_html__( 'Spieler 1', 'ttleague' ) . '</label> '
			. '<select id="' . esc_attr( $prefix . '-1' ) . '" name="player1" required>' . $options . '</select></p>'
			. '<p class="ttl-field"><label for="' . esc_attr( $prefix . '-2' ) . '">' . esc_html__( 'Spieler 2', 'ttleague' ) . '</label> '
			. '<select id="' . esc_attr( $prefix . '-2' ) . '" name="player2" required>' . $options . '</select></p>'
			. '<p><button type="submit" class="ttl-button button button-primary">' . esc_html__( 'Cupmannschaft melden', 'ttleague' ) . '</button></p>'
			. '</form>';
	}

	/**
	 * Knopf «Zurückziehen» für eine Meldung.
	 *
	 * @param array<string,mixed> $entry Meldung.
	 * @param string              $back  Ziel nach der Aktion (Forms::BACK_*).
	 */
	public static function withdraw_form( array $entry, string $back = Forms::BACK_AREA ): string {
		return '<form class="ttl-inline-form" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">'
			. '<input type="hidden" name="action" value="' . esc_attr( Forms::WITHDRAW ) . '">'
			. '<input type="hidden" name="entry" value="' . esc_attr( (string) $entry['id'] ) . '">'
			. '<input type="hidden" name="back" value="' . esc_attr( $back ) . '">'
			. wp_nonce_field( Forms::WITHDRAW . '_' . $entry['id'], '_wpnonce', true, false )
			/* translators: %s: Cupmannschaft */
			. '<button type="submit" class="ttl-button ttl-button--secondary button" aria-label="' . esc_attr( sprintf( __( '%s zurückziehen', 'ttleague' ), $entry['name'] ) ) . '">' . esc_html__( 'Zurückziehen', 'ttleague' ) . '</button>'
			. '</form>';
	}

	/**
	 * @param array{type:string,message:string,details:list<string>}|null $flash Rückmeldung.
	 */
	private static function notice( ?array $flash ): string {
		if ( null === $flash ) {
			return '';
		}
		$variant = match ( $flash['type'] ) {
			Outcome::SUCCESS => 'success',
			Outcome::ERROR   => 'error',
			Outcome::WARNING => 'warning',
			default          => 'info',
		};
		$details = array() === $flash['details'] ? '' : '<ul>' . implode( '', array_map( static fn( string $d ): string => '<li>' . esc_html( $d ) . '</li>', $flash['details'] ) ) . '</ul>';
		return '<div class="ttl-notice ttl-notice--' . esc_attr( $variant ) . '" role="' . ( 'error' === $variant ? 'alert' : 'status' ) . '"><p>' . esc_html( $flash['message'] ) . '</p>' . $details . '</div>';
	}
}
