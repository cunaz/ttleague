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
use TTLeague\Rest\ResultController;

final class Results {

	public const RECALC_HOOK = 'ttleague_rating_recalc';

	/** Verzögerung der Neuberechnung, damit mehrere Änderungen zusammen gerechnet werden. */
	public const RECALC_DELAY = 120;

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
		if ( false === wp_next_scheduled( self::RECALC_HOOK ) ) {
			wp_schedule_single_event( time() + self::RECALC_DELAY, self::RECALC_HOOK );
		}
	}

	public static function recalc_ratings(): void {
		( new RatingService( WpdbDb::from_global() ) )->recalc();
		DataVersion::bump();
	}

	public static function deactivate(): void {
		wp_clear_scheduled_hook( self::RECALC_HOOK );
	}
}
