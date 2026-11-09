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
			self::store_once( $generated + array( 'created_at' => gmdate( 'Y-m-d H:i:s' ) ) );
			$stored = get_option( self::OPTION );
		}
		if ( ! is_array( $stored ) || ! isset( $stored['private'], $stored['public'] ) ) {
			return null;
		}
		return Vapid::from_base64url( (string) $stored['private'], (string) $stored['public'] );
	}

	/**
	 * Legt das Schlüsselpaar nur an, wenn es noch keines gibt (INSERT IGNORE), und verwirft danach
	 * die zwischengespeicherte Option samt «gibt es nicht». add_option() schreibt mit ON DUPLICATE
	 * KEY UPDATE: Bei gleichzeitigen Anfragen würde das zweite Paar das erste überschreiben,
	 * obwohl ein Browser das erste vielleicht schon für ein Abo verwendet. So gilt für alle das
	 * zuerst gespeicherte Paar.
	 *
	 * @param array<string,string> $keys Schlüsselpaar mit Zeitpunkt.
	 */
	private static function store_once( array $keys ): void {
		global $wpdb;
		$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- atomares Anlegen, Cache wird danach geleert.
			$wpdb->prepare(
				"INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'off')",
				self::OPTION,
				maybe_serialize( $keys )
			)
		);
		wp_cache_delete( self::OPTION, 'options' );
		$missing = wp_cache_get( 'notoptions', 'options' );
		if ( is_array( $missing ) && isset( $missing[ self::OPTION ] ) ) {
			unset( $missing[ self::OPTION ] );
			wp_cache_set( 'notoptions', $missing, 'options' );
		}
	}

	private static function from_constants(): bool {
		return defined( 'TTLEAGUE_VAPID_PRIVATE_KEY' ) && '' !== trim( (string) constant( 'TTLEAGUE_VAPID_PRIVATE_KEY' ) );
	}
}
