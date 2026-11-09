<?php
/**
 * URL-Slugs nach deutscher Konvention (ä → ae, ö → oe, ü → ue, ß → ss).
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\ReadModel\Support;

/**
 * Gleiche Regel wie bei club.slug: Kleinbuchstaben, Umlaute ausgeschrieben, übrige Akzente
 * entfernt, alles andere wird zu Bindestrichen. «TTC Seefeld 2» → «ttc-seefeld-2».
 */
final class Slug {

	public static function make( string $text ): string {
		$text = mb_strtolower( trim( $text ), 'UTF-8' );
		$text = strtr(
			$text,
			array(
				'ä' => 'ae',
				'ö' => 'oe',
				'ü' => 'ue',
				'ß' => 'ss',
				'&' => ' ',
			)
		);
		$text = self::ascii( $text );
		$text = (string) preg_replace( '/[^a-z0-9]+/', '-', $text );
		return trim( $text, '-' );
	}

	/**
	 * Suchform ohne Umlaut-Ausschreibung (ü → u), damit «muster» auch «Müsterli» findet.
	 */
	public static function fold( string $text ): string {
		$text = self::ascii( mb_strtolower( trim( $text ), 'UTF-8' ) );
		$text = (string) preg_replace( '/[^a-z0-9]+/', '-', $text );
		return trim( $text, '-' );
	}

	/**
	 * Entfernt Akzente (é → e, ü → u).
	 */
	private static function ascii( string $text ): string {
		if ( function_exists( 'transliterator_transliterate' ) ) {
			$result = transliterator_transliterate( 'Any-Latin; Latin-ASCII', $text );
			if ( is_string( $result ) ) {
				return $result;
			}
		}
		$result = @iconv( 'UTF-8', 'ASCII//TRANSLIT', $text ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- iconv meldet nicht abbildbare Zeichen.
		return false === $result ? $text : $result;
	}
}
