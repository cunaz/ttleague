<?php
/**
 * Abschnitt «Meine Spiele» in «Mein Bereich».
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Results;

use TTLeague\Accounts\MyAreaContext;
use TTLeague\Frontend\Support\Format;

/**
 * Zeigt aus ResultService::my_fixtures() die Begegnungen der Person: zu erfassen (Heimseite,
 * Anspielzeit vorbei), zu bestätigen (Gastseite, provisorisch) und demnächst (21 Tage), jeweils
 * mit Link auf die Erfassungsseite. Nur für Personen mit einer Funktion in der aktiven Saison.
 */
final class MyGames {

	public static function register(): void {
		add_action( 'ttleague_my_area_sections', array( self::class, 'section' ) );
	}

	/**
	 * Aktion ttleague_my_area_sections: gibt den Abschnitt aus (Mein Bereich puffert die Ausgabe).
	 */
	public static function section( MyAreaContext $context ): void {
		$roles = $context->roles;
		if ( array() === $roles['captain_teams'] && array() === $roles['tk_chef_clubs'] && array() === $roles['cup_entries'] ) {
			return;
		}
		$actor    = new Actor( $context->user_id, $context->person_id, $context->is_tk );
		$fixtures = Results::service()->my_fixtures( $actor );
		wp_enqueue_style( EntryBlock::STYLE );
		/**
		 * Weitere Links zu einer Begegnung in «Meine Spiele», z. B. «Termin verschieben».
		 * Fertiges, escaptes HTML.
		 *
		 * @param string              $html    Links.
		 * @param array<string,mixed> $fixture Begegnung aus ResultService::my_fixtures().
		 */
		$actions = static fn( array $fixture ): string => (string) apply_filters( 'ttleague_my_games_actions', '', $fixture );
		echo self::render( $fixtures, array( Results::class, 'entry_url' ), $actions ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- render() escaped jede Ausgabe.
	}

	/**
	 * HTML des Abschnitts.
	 *
	 * @param array{to_enter:list<array<string,mixed>>,to_confirm:list<array<string,mixed>>,upcoming:list<array<string,mixed>>} $fixtures Aus ResultService::my_fixtures().
	 * @param callable(int):string                                                                                            $entry_url Adresse der Erfassungsseite.
	 * @param (callable(array<string,mixed>):string)|null                                                                     $actions   Fertiges HTML weiterer Links zu einer Begegnung (Module).
	 */
	public static function render( array $fixtures, callable $entry_url, ?callable $actions = null ): string {
		$lists = array(
			'to_enter'   => array(
				__( 'Zu erfassen', 'ttleague' ),
				__( 'Keine offenen Resultate.', 'ttleague' ),
			),
			'to_confirm' => array(
				__( 'Zu bestätigen', 'ttleague' ),
				__( 'Keine Resultate zu bestätigen.', 'ttleague' ),
			),
			'upcoming'   => array(
				__( 'Demnächst', 'ttleague' ),
				__( 'In den nächsten drei Wochen stehen keine Spiele an.', 'ttleague' ),
			),
		);
		$out   = '<section class="ttl-section ttl-my-games" aria-labelledby="ttl-my-games-title">'
			. '<h3 id="ttl-my-games-title">' . esc_html__( 'Meine Spiele', 'ttleague' ) . '</h3>';
		foreach ( $lists as $key => [ $title, $empty ] ) {
			$items = $fixtures[ $key ];
			$out  .= '<h4 class="ttl-my-games__title" id="ttl-my-games-' . esc_attr( str_replace( '_', '-', $key ) ) . '">' . esc_html( $title )
				. ' <span class="ttl-badge' . ( array() !== $items && 'upcoming' !== $key ? ' ttl-badge--warning' : ' ttl-badge--muted' ) . '">' . esc_html( (string) count( $items ) ) . '</span></h4>';
			if ( array() === $items ) {
				$out .= '<p class="ttl-muted ttl-my-games__empty">' . esc_html( $empty ) . '</p>';
				continue;
			}
			$out .= '<ul class="ttl-my-games__list" aria-labelledby="ttl-my-games-' . esc_attr( str_replace( '_', '-', $key ) ) . '">';
			foreach ( $items as $fixture ) {
				$out .= self::item( $fixture, $key, $entry_url, $actions );
			}
			$out .= '</ul>';
		}
		return $out . '</section>';
	}

	/**
	 * Eine Begegnung mit Link auf die Erfassungsseite.
	 *
	 * @param array<string,mixed>  $fixture   Begegnung.
	 * @param callable(int):string                        $entry_url Adresse der Erfassungsseite.
	 * @param (callable(array<string,mixed>):string)|null $actions   Fertiges HTML weiterer Links (Module).
	 */
	private static function item( array $fixture, string $group, callable $entry_url, ?callable $actions = null ): string {
		$home  = 'home' === $fixture['side'];
		$label = match ( $group ) {
			'to_enter'   => __( 'Resultat erfassen', 'ttleague' ),
			'to_confirm' => __( 'Resultat bestätigen', 'ttleague' ),
			default      => $home ? __( 'Zur Erfassung', 'ttleague' ) : __( 'Ansehen', 'ttleague' ),
		};
		$pairing = $fixture['home'] . ' – ' . $fixture['away'];
		$date    = Format::datetime( $fixture['scheduled_at'] );
		$meta    = array(
			Format::kind_short( (string) $fixture['type'] ) . ' ' . $fixture['competition'],
			$home ? __( 'Heim', 'ttleague' ) : __( 'Gast', 'ttleague' ),
		);
		$score   = '';
		if ( null !== $fixture['home_score'] && null !== $fixture['away_score'] ) {
			/* translators: %s: Resultat, z. B. 6:4 */
			$score = ' <span class="ttl-badge ttl-badge--muted">' . esc_html( sprintf( __( 'provisorisch %s', 'ttleague' ), $fixture['home_score'] . ':' . $fixture['away_score'] ) ) . '</span>';
		}
		return '<li class="ttl-my-games__item">'
			. '<span class="ttl-my-games__meta">'
			. ( '' === $date ? '' : '<time datetime="' . esc_attr( str_replace( ' ', 'T', substr( (string) $fixture['scheduled_at'], 0, 16 ) ) ) . '">' . esc_html( $date ) . '</time> · ' )
			. esc_html( implode( ' · ', $meta ) ) . '</span>'
			. '<span class="ttl-my-games__pairing">' . esc_html( $pairing ) . $score . '</span>'
			. '<a class="ttl-button ttl-my-games__action' . ( 'upcoming' === $group ? ' ttl-button--secondary' : '' ) . '" href="' . esc_url( $entry_url( (int) $fixture['id'] ) ) . '">'
			. esc_html( $label ) . '<span class="screen-reader-text">: ' . esc_html( $pairing ) . '</span></a>'
			. ( null === $actions ? '' : $actions( $fixture ) )
			. '</li>';
	}
}
