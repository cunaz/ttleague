<?php
/**
 * Abschnitt «Meldung» in «Mein Bereich» für TK-Chefs: Meldung zur geplanten Saison,
 * Nachmeldungen und Captains der aktiven Saison, Lokale und Hallensperren.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Registration;

use TTLeague\Accounts\MyAreaContext;
use TTLeague\Accounts\MyClub;
use TTLeague\Accounts\Outcome;
use TTLeague\Accounts\Services;
use TTLeague\Frontend\Assets;
use TTLeague\Infrastructure\Flash;
use TTLeague\Results\Actor;
use TTLeague\Season\SeasonRepository;

/**
 * Ein Abschnitt pro Club, den die Person als TK-Chef der aktiven oder der geplanten Saison
 * verwaltet (AccessPolicy::managed_clubs()). Die geplante Saison ist nicht öffentlich: Der
 * Abschnitt liest sie über den Dienst, der das Recht der Person prüft, nie über eine öffentliche
 * Ansicht. Die Meldung zur geplanten Saison erscheint, sobald das Meldefenster begonnen hat;
 * nach dessen Ablauf bleibt sie zum Lesen sichtbar. Sonst zeigt der Abschnitt nur die aktive Saison.
 */
final class MyClubSection {

	public const STYLE = 'ttleague-registration';

	public static function register_style(): void {
		$file = 'assets/css/registration.css';
		$path = TTLEAGUE_DIR . $file;
		wp_register_style( self::STYLE, TTLEAGUE_URL . $file, array( Assets::STYLE, MyClub::STYLE ), TTLEAGUE_VERSION . ( is_readable( $path ) ? '.' . (string) filemtime( $path ) : '' ) );
	}

	/**
	 * Aktion ttleague_my_area_sections.
	 */
	public static function render( MyAreaContext $context ): void {
		$clubs = Services::policy()->managed_clubs( $context->person_id );
		if ( array() === $clubs ) {
			return;
		}
		$actor = new Actor( $context->user_id, $context->person_id, $context->is_tk );
		$out   = '';
		foreach ( $clubs as $club_id ) {
			$out .= self::section( $club_id, $actor, Flash::take( Registration::flash_scope( $club_id ), $context->user_id ) );
		}
		if ( '' !== $out ) {
			wp_enqueue_style( self::STYLE );
		}
		echo $out; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Alle Teile werden beim Aufbau escaped.
	}

	/**
	 * Abschnitt eines Clubs; leer, wenn die Person in keiner der beiden Saisons etwas melden kann.
	 *
	 * @param array{type:string,message:string,details:list<string>}|null $flash Rückmeldung.
	 */
	public static function section( int $club_id, Actor $actor, ?array $flash ): string {
		$seasons = new SeasonRepository( Services::db() );
		$service = Registration::registration();
		$planned = self::state( $service, $actor, $seasons->planned(), $club_id );
		$active  = self::state( $service, $actor, $seasons->active(), $club_id );
		if ( null !== $planned ) {
			$opens = $planned['context']->season['registration_opens'];
			if ( null === $opens || $planned['context']->today < $opens ) {
				$planned = null;
			}
		}
		$any = $planned ?? $active;
		if ( null === $any ) {
			return '';
		}
		$size = Registration::stamm_size();
		$id   = RegistrationMessages::anchor( $club_id );
		$out  = '<section class="ttl-section ttl-club ttl-reg-section" id="' . esc_attr( $id ) . '" aria-labelledby="' . esc_attr( $id . '-titel' ) . '">'
			/* translators: %s: Clubname */
			. '<h3 class="ttl-section__title" id="' . esc_attr( $id . '-titel' ) . '">' . esc_html( sprintf( __( 'Meldung: %s', 'ttleague' ), $any['context']->club['name'] ) ) . '</h3>'
			. self::notice( $flash );
		if ( null !== $planned ) {
			/* translators: %s: Saison */
			$out .= '<h4 class="ttl-reg__season">' . esc_html( sprintf( __( 'Meldung für die Saison %s', 'ttleague' ), $planned['context']->season['name'] ) ) . '</h4>'
				. ( new RegistrationView( $planned, RegistrationView::SITE, $size ) )->registration();
		}
		if ( null !== $active ) {
			/* translators: %s: Saison */
			$out .= '<h4 class="ttl-reg__season">' . esc_html( sprintf( __( 'Laufende Saison %s: Nachmeldungen und Captains', 'ttleague' ), $active['context']->season['name'] ) ) . '</h4>'
				. ( new RegistrationView( $active, RegistrationView::SITE, $size ) )->registration();
		}
		return $out . ( new RegistrationView( $any, RegistrationView::SITE, $size ) )->venues() . '</section>';
	}

	/**
	 * Meldung des Clubs in der Saison, oder null, wenn es keine Saison gibt, der Club nicht
	 * teilnimmt oder die Person dort nicht TK-Chef ist.
	 *
	 * @param array<string,mixed>|null $season Saison.
	 * @return array<string,mixed>|null
	 */
	private static function state( ClubRegistration $service, Actor $actor, ?array $season, int $club_id ): ?array {
		if ( null === $season ) {
			return null;
		}
		try {
			return $service->state( $actor, (int) $season['id'], $club_id );
		} catch ( RegistrationException $e ) {
			return null;
		}
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
