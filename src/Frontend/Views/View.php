<?php
/**
 * Basis der serverseitig gerenderten Ansichten (Blöcke und Shortcodes).
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Frontend\Views;

use TTLeague\Frontend\Context;
use TTLeague\Frontend\Support\Format;
use TTLeague\Frontend\Support\Html;
use TTLeague\Frontend\Support\Urls;
use TTLeague\ReadModel\ReadModel;

/**
 * Eine Ansicht liest ihre Parameter aus den Block-Attributen; fehlen sie, aus dem Kontext
 * der aktuellen Seite (Adresse), sonst gilt die aktive Saison. Ausgaben sind escaped.
 */
abstract class View {

	/**
	 * Seitenschlüssel, auf dem die Ansicht ihren Kontext aus der Adresse liest.
	 */
	protected const VIEW = '';

	public function __construct(
		protected readonly ReadModel $model,
		protected readonly Urls $urls,
		protected readonly ?Context $context = null,
	) {}

	/**
	 * @param array<string,mixed> $attrs Block-Attribute.
	 */
	abstract public function render( array $attrs ): string;

	/**
	 * Kontext, wenn die Ansicht auf ihrer eigenen Seite steht.
	 */
	protected function own_context(): ?Context {
		return null !== $this->context && static::VIEW === $this->context->view ? $this->context : null;
	}

	/**
	 * Meldung für nicht gefundene Objekte (404) mit Wegweisern, oder null.
	 */
	protected function not_found(): ?string {
		$context = $this->own_context();
		if ( null === $context || ! $context->is_not_found() ) {
			return null;
		}
		$links = array(
			Html::link( $this->urls->championship(), __( 'Meisterschaft', 'ttleague' ) ),
			Html::link( $this->urls->players(), __( 'Spieler', 'ttleague' ) ),
			Html::link( $this->urls->clubs(), __( 'Clubs', 'ttleague' ) ),
			Html::link( $this->urls->archive(), __( 'Archiv', 'ttleague' ) ),
		);
		return '<div class="ttl-not-found" role="status"><p class="ttl-notice ttl-notice--warning">' . esc_html( (string) $context->not_found ) . '</p>'
			. '<p>' . esc_html__( 'Vielleicht hilft einer dieser Einstiege weiter:', 'ttleague' ) . '</p><ul class="ttl-inline-list"><li>' . implode( '</li><li>', $links ) . '</li></ul></div>';
	}

	/**
	 * Saison aus Attribut, Kontext oder aktiver Saison.
	 *
	 * @param array<string,mixed> $attrs Attribute.
	 * @return array<string,mixed>|null
	 */
	protected function season( array $attrs ): ?array {
		$slug = trim( (string) ( $attrs['season'] ?? '' ) );
		if ( '' !== $slug ) {
			return $this->model->seasons->by_slug( $slug );
		}
		if ( null !== $this->context && null !== $this->context->season ) {
			return $this->context->season;
		}
		return $this->model->seasons->active();
	}

	/**
	 * Link auf eine Mannschaftsseite.
	 *
	 * @param array<string,mixed> $team Team mit name und slug.
	 */
	protected function team_link( array $team, string $season_slug ): string {
		return Html::link( $this->urls->team( $season_slug, (string) $team['slug'] ), (string) $team['name'] );
	}

	/**
	 * Link auf ein Spielerprofil, optional mit Klassierung.
	 *
	 * @param array<string,mixed> $player Spielerreferenz.
	 */
	protected function player_link( array $player, bool $with_classification = false ): string {
		$html = Html::link( $this->urls->player( (int) $player['license'], (string) $player['slug'] ), (string) $player['name'] );
		if ( $with_classification && isset( $player['classification'] ) && null !== $player['classification'] ) {
			$html .= ' ' . Html::classification( (int) $player['classification'] );
		}
		return $html;
	}

	/**
	 * Markierung für auffällige Begegnungsstatus.
	 */
	protected function status_badge( string $status ): string {
		$marker = Format::fixture_marker( $status );
		if ( null === $marker ) {
			return '';
		}
		$variant = in_array( $status, array( 'forfeit', 'cancelled', 'disputed' ), true ) ? 'warning' : 'muted';
		return ' ' . Html::badge( $marker, $variant );
	}

	/**
	 * <time> mit maschinenlesbarem Datum.
	 */
	protected function time_tag( ?string $datetime, string $text ): string {
		if ( null === $datetime || '' === $text ) {
			return esc_html( $text );
		}
		$iso = str_replace( ' ', 'T', substr( $datetime, 0, 16 ) );
		return '<time datetime="' . esc_attr( $iso ) . '">' . esc_html( $text ) . '</time>';
	}

	/**
	 * Hinweis, wenn noch keine Saison erfasst ist.
	 */
	protected function no_season(): string {
		return Html::notice( __( 'Es ist noch keine Saison erfasst.', 'ttleague' ) );
	}

	/**
	 * Kopfzeile mit Rückverweis, z. B. «Meisterschaft 2026/27».
	 */
	protected function kicker( string $url, string $text ): string {
		return '<p class="ttl-kicker">' . Html::link( $url, $text ) . '</p>';
	}
}
