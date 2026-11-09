<?php
/**
 * VAPID-Schlüssel der Website: einmal erzeugt, auch bei gleichzeitigen Anfragen.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Tests\WordPress\Notifications;

use TTLeague\Notifications\Push\P256;
use TTLeague\Notifications\Push\Vapid;
use TTLeague\Notifications\Push\VapidKeys;
use TTLeague\Tests\WordPress\TestCase;

/**
 * Ohne Konstanten erzeugt das Plugin das Schlüsselpaar beim ersten Bedarf und speichert es als
 * Option. Ein Browser abonniert mit dem öffentlichen Schlüssel; ein später überschriebenes Paar
 * würde jede Nachricht an dieses Abo scheitern lassen.
 */
final class VapidKeysTest extends TestCase {

	public function set_up(): void {
		parent::set_up();
		if ( ! P256::supported() ) {
			self::markTestSkipped( 'OpenSSL ohne P-256.' );
		}
		VapidKeys::reset();
		delete_option( VapidKeys::OPTION );
	}

	public function tear_down(): void {
		VapidKeys::reset();
		parent::tear_down();
	}

	public function test_key_pair_is_created_once_and_kept(): void {
		$vapid = VapidKeys::get();

		self::assertInstanceOf( Vapid::class, $vapid );
		self::assertSame( 'option', VapidKeys::source() );
		$stored = get_option( VapidKeys::OPTION );
		self::assertSame( $stored['public'], $vapid->public_key() );

		VapidKeys::reset();
		self::assertSame( $vapid->public_key(), VapidKeys::get()->public_key(), 'beim nächsten Laden dasselbe Paar' );
	}

	/**
	 * Eine gleichzeitige Anfrage hat ihr Paar eben gespeichert, der Cache dieser Anfrage meldet die
	 * Option noch als fehlend. Das gespeicherte Paar gilt; das eigene wird verworfen statt es zu
	 * überschreiben (add_option() würde überschreiben).
	 */
	public function test_a_key_pair_stored_concurrently_is_kept(): void {
		global $wpdb;
		self::assertFalse( get_option( VapidKeys::OPTION ), 'Cache: Option fehlt' );
		$other = Vapid::generate() + array( 'created_at' => '2026-11-09 20:00:00' );
		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- gleichzeitige Anfrage, am Cache vorbei.
			$wpdb->options,
			array(
				'option_name'  => VapidKeys::OPTION,
				'option_value' => maybe_serialize( $other ),
				'autoload'     => 'off',
			)
		);

		$vapid = VapidKeys::get();

		self::assertInstanceOf( Vapid::class, $vapid );
		self::assertSame( $other['public'], $vapid->public_key() );
		self::assertSame( $other, get_option( VapidKeys::OPTION ) );
	}
}
