<?php
/**
 * HTML-Bausteine mit Escaping: Links, Badges, Tabellen mit Kartenansicht.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Frontend\Support;

/**
 * Konvention: Parameter mit «$html» im Namen sind bereits sicheres HTML, alle anderen
 * Texte werden hier escaped.
 */
final class Html {

	public static function e( string|int|float|null $text ): string {
		return esc_html( (string) $text );
	}

	/**
	 * Link mit escaptem Text.
	 *
	 * @param array<string,string> $attrs Weitere Attribute.
	 */
	public static function link( string $url, string $text, array $attrs = array() ): string {
		return self::link_html( $url, esc_html( $text ), $attrs );
	}

	/**
	 * Link mit bereits sicherem HTML als Inhalt.
	 *
	 * @param array<string,string> $attrs Weitere Attribute.
	 */
	public static function link_html( string $url, string $inner_html, array $attrs = array() ): string {
		return '<a href="' . esc_url( $url ) . '"' . self::attrs( $attrs ) . '>' . $inner_html . '</a>';
	}

	/**
	 * Kleine Markierung, z. B. für «provisorisch».
	 */
	public static function badge( string $text, string $variant = 'neutral' ): string {
		return '<span class="ttl-badge ttl-badge--' . esc_attr( $variant ) . '">' . esc_html( $text ) . '</span>';
	}

	/**
	 * Klassierung als Badge mit ausgeschriebenem Titel.
	 */
	public static function classification( ?int $classification ): string {
		$label = Format::classification( $classification );
		if ( '' === $label ) {
			return '';
		}
		/* translators: %s: Klassierung, z. B. D5. */
		$title = sprintf( __( 'Klassierung %s', 'ttleague' ), $label );
		return '<span class="ttl-class" title="' . esc_attr( $title ) . '">' . esc_html( $label ) . '</span>';
	}

	/**
	 * Hinweis bei leeren Listen.
	 */
	public static function notice( string $text, string $variant = 'info' ): string {
		return '<p class="ttl-notice ttl-notice--' . esc_attr( $variant ) . '">' . esc_html( $text ) . '</p>';
	}

	/**
	 * Hinweis mit sicherem HTML.
	 */
	public static function notice_html( string $inner_html, string $variant = 'info' ): string {
		return '<p class="ttl-notice ttl-notice--' . esc_attr( $variant ) . '">' . $inner_html . '</p>';
	}

	/**
	 * Überschrift mit optionalem Anker.
	 */
	public static function heading( int $level, string $text, string $id = '', string $css_class = '' ): string {
		$level = max( 2, min( 6, $level ) );
		$attrs = array();
		if ( '' !== $id ) {
			$attrs['id'] = $id;
		}
		if ( '' !== $css_class ) {
			$attrs['class'] = $css_class;
		}
		return '<h' . $level . self::attrs( $attrs ) . '>' . esc_html( $text ) . '</h' . $level . '>';
	}

	/**
	 * Abschnitt mit Überschrift; $body_html ist sicheres HTML.
	 */
	public static function section( string $title, string $body_html, string $css_class = '', string $id = '', int $level = 2 ): string {
		$classes = trim( 'ttl-section ' . $css_class );
		$attrs   = array( 'class' => $classes );
		$head_id = '' === $id ? '' : $id;
		if ( '' !== $id ) {
			$attrs['aria-labelledby'] = $id . '-title';
			$head_id                  = $id . '-title';
		}
		return '<section' . self::attrs( $attrs ) . '>' . self::heading( $level, $title, $head_id, 'ttl-section__title' ) . $body_html . '</section>';
	}

	/**
	 * Tabelle, die unter ca. 600px als Karten erscheint (data-label je Zelle).
	 *
	 * Spalten: list<array{label:string,abbr?:string,card?:string,class?:string,primary?:bool,hide_label?:bool}>;
	 * card ist eine kürzere Beschriftung für die Kartenansicht.
	 * Zeilen: list<array{cells:list<string>,class?:string}>; Zellen sind sicheres HTML.
	 * Optionen: class (zusätzliche Klassen), caption (Beschriftung, für Screenreader sichtbar),
	 * caption_visible (bool), cards (bool, Standard true), first_header (erste Spalte als th scope=row).
	 *
	 * @param list<array<string,mixed>> $columns Spalten.
	 * @param list<array<string,mixed>> $rows    Zeilen.
	 * @param array<string,mixed>       $options Optionen.
	 */
	public static function table( array $columns, array $rows, array $options = array() ): string {
		$cards   = (bool) ( $options['cards'] ?? true );
		$classes = trim( 'ttl-table ' . ( $cards ? 'ttl-table--cards ' : '' ) . (string) ( $options['class'] ?? '' ) );
		$out     = '<table class="' . esc_attr( $classes ) . '">';
		if ( isset( $options['caption'] ) && '' !== $options['caption'] ) {
			$caption_class = empty( $options['caption_visible'] ) ? 'screen-reader-text' : 'ttl-table__caption';
			$out          .= '<caption class="' . esc_attr( $caption_class ) . '">' . esc_html( (string) $options['caption'] ) . '</caption>';
		}
		$out .= '<thead><tr>';
		foreach ( $columns as $column ) {
			$label = (string) $column['label'];
			$inner = isset( $column['abbr'] ) && '' !== $column['abbr']
				? '<abbr title="' . esc_attr( $label ) . '">' . esc_html( (string) $column['abbr'] ) . '</abbr>'
				: esc_html( $label );
			$out  .= '<th scope="col"' . self::attrs( array( 'class' => (string) ( $column['class'] ?? '' ) ) ) . '>' . $inner . '</th>';
		}
		$out .= '</tr></thead><tbody>';
		foreach ( $rows as $row ) {
			$out .= '<tr' . self::attrs( array( 'class' => (string) ( $row['class'] ?? '' ) ) ) . '>';
			foreach ( $columns as $i => $column ) {
				$cell    = (string) ( $row['cells'][ $i ] ?? '' );
				$class   = trim( (string) ( $column['class'] ?? '' ) . ( empty( $column['primary'] ) ? '' : ' ttl-cell--primary' ) . ( '' === $cell ? ' ttl-cell--empty' : '' ) );
				$attrs   = array( 'class' => $class );
				$is_head = ! empty( $options['first_header'] ) && 0 === $i;
				if ( empty( $column['hide_label'] ) ) {
					$attrs['data-label'] = (string) ( $column['card'] ?? $column['label'] );
				}
				if ( $is_head ) {
					$attrs['scope'] = 'row';
				}
				$tag  = $is_head ? 'th' : 'td';
				$out .= '<' . $tag . self::attrs( $attrs ) . '>' . $cell . '</' . $tag . '>';
			}
			$out .= '</tr>';
		}
		$out .= '</tbody></table>';
		return $out;
	}

	/**
	 * Horizontal scrollbarer, per Tastatur erreichbarer Bereich (z. B. für Matrizen).
	 */
	public static function scroll( string $label, string $inner_html ): string {
		return '<div class="ttl-scroll" role="region" tabindex="0" aria-label="' . esc_attr( $label ) . '">' . $inner_html . '</div>';
	}

	/**
	 * Begriffsliste (dl) für Kopfdaten; Werte sind sicheres HTML, leere werden ausgelassen.
	 *
	 * @param array<string,string> $items Begriff => Wert (HTML).
	 */
	public static function facts( array $items, string $css_class = '' ): string {
		$out = '';
		foreach ( $items as $term => $value_html ) {
			if ( '' === trim( $value_html ) ) {
				continue;
			}
			$out .= '<div class="ttl-facts__item"><dt>' . esc_html( $term ) . '</dt><dd>' . $value_html . '</dd></div>';
		}
		return '' === $out ? '' : '<dl class="' . esc_attr( trim( 'ttl-facts ' . $css_class ) ) . '">' . $out . '</dl>';
	}

	/**
	 * @param array<string,string> $attrs Attribute; leere Werte werden ausgelassen.
	 */
	public static function attrs( array $attrs ): string {
		$out = '';
		foreach ( $attrs as $name => $value ) {
			if ( '' === $value ) {
				continue;
			}
			$out .= ' ' . $name . '="' . esc_attr( $value ) . '"';
		}
		return $out;
	}
}
