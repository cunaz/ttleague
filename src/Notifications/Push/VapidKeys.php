<?php
/**
 * VAPID-Schlüssel der Website: Konstanten in wp-config.php oder einmalig erzeugt in einer Option.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Notifications\Push;

/**
 * Reihenfolge: TTLEAGUE_VAPID_PRIVATE_KEY (und optional TTLEAGUE_VAPID_PUBLIC_KEY, muss dazu
 * passen) aus wp-config.php, sonst die Option ttleague_vapid. Fehlt beides, wird beim ersten
 * Bedarf ein Schlüsselpaar erzeugt und in der Option gespeichert (nicht automatisch geladen).
 * Beide Schlüssel sind Base64url-kodierte Rohschlüssel (öffentlich 65 Byte, privat 32 Byte),
 * dasselbe Format wie bei anderen Web-Push-Werkzeugen.
 *
 * Wer die Schlüssel wechselt, macht alle bestehenden Abos ungültig: Die Browser müssen Push neu
 * aktivieren («Mein Bereich» erneuert das Abo beim nächsten Besuch selbst).
 */
final class VapidKeys {

	public const OPTION = 'ttleague_vapid';

	private static ?Vapid $vapid = null;

	private static bool $loaded = false;

	/**
	 * Schlüssel der Website oder null, wenn Push nicht verfügbar ist.
	 */
	public static function get(): ?Vapid {
		if ( self::$loaded ) {
			return self::$vapid;
		}
		self::$loaded = true;
		self::$vapid  = self::load();
		return self::$vapid;
	}

	/**
	 * Herkunft der Schlüssel: constants, option oder none.
	 */
	public static function source(): string {
		if ( self::from_constants() ) {
			return null === self::get() ? 'none' : 'constants';
		}
		return null === self::get() ? 'none' : 'option';
	}

	/**
	 * Kontakt für den Push-Dienst (sub im JWT): mailto: mit der Adresse der Verwaltung, sonst die Website.
	 */
	public static function subject(): string {
		$email   = (string) get_option( 'admin_email' );
		$default = is_email( $email ) ? 'mailto:' . $email : home_url( '/' );
		/**
		 * Kontakt-Claim (sub) des VAPID-JWT: mailto:-Adresse oder https-Adresse.
		 *
		 * @param string $subject Standard: mailto: mit der Adresse der Verwaltung.
		 */
		$subject = (string) apply_filters( 'ttleague_vapid_subject', $default );
		return 1 === preg_match( '#^(mailto:|https://)#', $subject ) ? $subject : $default;
	}

	/**
	 * Zwischenspeicher leeren (Tests, nach dem Erzeugen).
	 */
	public static function reset(): void {
		self::$vapid  = null;
		self::$loaded = false;
	}

	private static function load(): ?Vapid {
		if ( ! P256::supported() ) {
			return null;
		}
		if ( self::from_constants() ) {
			$public = defined( 'TTLEAGUE_VAPID_PUBLIC_KEY' ) ? (string) constant( 'TTLEAGUE_VAPID_PUBLIC_KEY' ) : null;
			$vapid  = Vapid::from_base64url( (string) constant( 'TTLEAGUE_VAPID_PRIVATE_KEY' ), $public );
			if ( null === $vapid ) {
				error_log( 'ttleague: TTLEAGUE_VAPID_PRIVATE_KEY ist ungültig oder passt nicht zu TTLEAGUE_VAPID_PUBLIC_KEY.' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Hinweis für die Verwaltung.
			}
			return $vapid;
		}
		$stored = get_option( self::OPTION );
		if ( ! is_array( $stored ) || ! isset( $stored['private'], $stored['public'] ) ) {
			$generated = Vapid::generate();
			if ( null === $generated ) {
				return null;
			}
			// add_option schreibt nur, wenn noch nichts da ist; bei gleichzeitigen Anfragen gewinnt die erste.
			add_option( self::OPTION, $generated + array( 'created_at' => gmdate( 'Y-m-d H:i:s' ) ), '', false );
			$stored = get_option( self::OPTION );
		}
		if ( ! is_array( $stored ) || ! isset( $stored['private'], $stored['public'] ) ) {
			return null;
		}
		return Vapid::from_base64url( (string) $stored['private'], (string) $stored['public'] );
	}

	private static function from_constants(): bool {
		return defined( 'TTLEAGUE_VAPID_PRIVATE_KEY' ) && '' !== trim( (string) constant( 'TTLEAGUE_VAPID_PRIVATE_KEY' ) );
	}
}
