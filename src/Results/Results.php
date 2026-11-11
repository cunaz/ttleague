<?php
/**
 * Einstieg der Resultaterfassung in WordPress.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Results;

use TTLeague\Access\Capabilities;
use TTLeague\Accounts\Services;
use TTLeague\Infrastructure\Cache\DataVersion;
use TTLeague\Infrastructure\Database\WpdbDb;
use TTLeague\Rating\RatingService;
use TTLeague\Rating\RecalcLock;
use TTLeague\Rest\ResultController;

final class Results {

	public const RECALC_HOOK = 'ttleague_rating_recalc';

	/**
	 * Option mit der Markierung «Neuberechnung steht aus». Sie entsteht mit jeder Resultatänderung
	 * neu (eigener Wert) und verschwindet erst nach einer erfolgreichen Neuberechnung, die mit
	 * genau diesem Wert begonnen hat. Anders als der Cron-Eintrag, den WP-Cron vor dem Start des
	 * Laufs entfernt, sagt sie zuverlässig, ob die gespeicherten Wertungszahlen aktuell sind.
	 */
	public const RECALC_PENDING = 'ttleague_rating_pending';

	/** Verzögerung der Neuberechnung, damit mehrere Änderungen zusammen gerechnet werden. */
	public const RECALC_DELAY = 120;

	/** Wartezeit auf die Sperre der Neuberechnung in Sekunden (Filter ttleague_rating_recalc_lock_wait). */
	public const RECALC_LOCK_WAIT = 20;

	public static function register(): void {
		add_action( 'rest_api_init', array( ResultController::class, 'register_routes' ) );
		add_filter( 'rest_pre_serve_request', array( ResultController::class, 'serve_photo' ), 10, 3 );
		PhotoStorage::register();
		add_action( self::RECALC_HOOK, array( self::class, 'recalc_ratings' ) );
		EntryBlock::register();
		MyGames::register();
		if ( defined( 'TTLEAGUE_FILE' ) ) {
			register_deactivation_hook( TTLEAGUE_FILE, array( self::class, 'deactivate' ) );
		}
	}

	public static function service(): ResultService {
		return new ResultService(
			Services::db(),
			Capabilities::policy(),
			Services::clock(),
			wp_timezone(),
			Services::audit(),
			new WordPressResultEvents(),
			static function ( int $user_id ): ?string {
				$user = get_userdata( $user_id );
				return false === $user ? null : $user->display_name;
			}
		);
	}

	/**
	 * Adresse der Erfassungsseite einer Begegnung (Seite «entry», Block ttleague/result-entry).
	 */
	public static function entry_url( int $fixture_id ): string {
		return add_query_arg( 'begegnung', (string) $fixture_id, \TTLeague\Frontend\Frontend::urls()->page( 'entry' ) );
	}

	/**
	 * Geschützte Adresse des Fotos einer Begegnung. Der REST-Nonce in der Adresse lässt sie
	 * auch in `<img>` funktionieren; er gilt nur zusammen mit dem Anmelde-Cookie derselben Person.
	 *
	 * @param int|null $attachment_id Früheres Foto (nur für die TK), sonst das aktuelle.
	 */
	public static function photo_url( int $fixture_id, ?int $attachment_id = null ): string {
		$args = array( '_wpnonce' => wp_create_nonce( 'wp_rest' ) );
		if ( null !== $attachment_id ) {
			$args['attachment'] = (string) $attachment_id;
		}
		return add_query_arg( $args, rest_url( ResultController::NAMESPACE . '/fixtures/' . $fixture_id . '/photo' ) );
	}

	/**
	 * Handelnde Person aus dem angemeldeten Konto.
	 */
	public static function current_actor(): Actor {
		$user_id = get_current_user_id();
		$user    = wp_get_current_user();
		return new Actor(
			$user_id,
			Capabilities::policy()->person_for_user( $user_id ),
			user_can( $user_id, Capabilities::TK ),
			$user->display_name
		);
	}

	/**
	 * Neuberechnung der Wertungszahl einmalig einplanen (entprellt).
	 */
	public static function schedule_rating_recalc(): void {
		update_option( self::RECALC_PENDING, uniqid( '', true ), false );
		self::ensure_recalc_scheduled();
	}

	private static function ensure_recalc_scheduled(): void {
		if ( false === wp_next_scheduled( self::RECALC_HOOK ) ) {
			wp_schedule_single_event( time() + self::RECALC_DELAY, self::RECALC_HOOK );
		}
	}

	/**
	 * Wert der Markierung «Neuberechnung steht aus», frisch aus der Datenbank (andere Anfragen
	 * können sie während einer langen Berechnung gesetzt haben); null, wenn nichts aussteht.
	 */
	public static function rating_pending(): ?string {
		global $wpdb;
		$value = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", self::RECALC_PENDING ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- bewusst am Options-Cache vorbei.
		return is_string( $value ) && '' !== $value ? $value : null;
	}

	/**
	 * Markierung entfernen, aber nur, wenn sie noch den Wert hat, mit dem die Berechnung begann.
	 * Wurde inzwischen ein Resultat gespeichert, bleibt sie stehen.
	 *
	 * @param string|null $token Wert vor der Berechnung (rating_pending()).
	 */
	public static function clear_rating_pending( ?string $token ): void {
		global $wpdb;
		if ( null === $token ) {
			return;
		}
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s", self::RECALC_PENDING, $token ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Vergleichen und Löschen in einem Schritt.
		wp_cache_delete( self::RECALC_PENDING, 'options' );
		wp_cache_delete( 'notoptions', 'options' );
	}

	/**
	 * Geplanter Lauf der Neuberechnung (Cron). Rechnet unter der Sperre der Neuberechnung, damit
	 * wartet, wer aktuelle Wertungszahlen braucht (Klassierung). Hält ein anderer Lauf die Sperre
	 * nach der Wartezeit noch, wird neu eingeplant statt doppelt gerechnet.
	 */
	public static function recalc_ratings(): void {
		$lock = self::recalc_lock();
		if ( ! $lock->acquire( self::recalc_lock_wait() ) ) {
			// Die Markierung «steht aus» bleibt, wie sie ist; nur der Lauf wird neu eingeplant.
			self::ensure_recalc_scheduled();
			return;
		}
		try {
			self::recalc_ratings_unlocked();
		} finally {
			$lock->release();
		}
	}

	/**
	 * Neuberechnung ohne eigene Sperre: nur für Aufrufer, die recalc_lock() selbst halten.
	 * Danach verschwindet die Markierung «steht aus», sofern während der Berechnung kein
	 * weiteres Resultat gespeichert wurde; sonst bleibt sie, und ein Lauf ist eingeplant.
	 */
	public static function recalc_ratings_unlocked(): void {
		$token = self::rating_pending();
		( new RatingService( WpdbDb::from_global() ) )->recalc();
		DataVersion::bump();
		self::clear_rating_pending( $token );
		if ( null !== self::rating_pending() ) {
			self::ensure_recalc_scheduled();
		}
	}

	/**
	 * Sperre, unter der die Neuberechnung dieser Installation läuft.
	 */
	public static function recalc_lock(): RecalcLock {
		$db = WpdbDb::from_global();
		return new RecalcLock( $db, RecalcLock::name( defined( 'DB_NAME' ) ? (string) DB_NAME : '', $db->prefix() ) );
	}

	/**
	 * Wartezeit auf die Sperre in Sekunden.
	 */
	public static function recalc_lock_wait(): int {
		/**
		 * Wie lange auf einen laufenden Lauf der Neuberechnung gewartet wird.
		 *
		 * @param int $seconds Sekunden.
		 */
		return max( 0, (int) apply_filters( 'ttleague_rating_recalc_lock_wait', self::RECALC_LOCK_WAIT ) );
	}

	public static function deactivate(): void {
		wp_clear_scheduled_hook( self::RECALC_HOOK );
	}
}
