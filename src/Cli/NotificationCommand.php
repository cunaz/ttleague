<?php
/**
 * WP-CLI: Fristen und Erinnerungen von Hand auslösen.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Cli;

use TTLeague\Infrastructure\Time\FixedClock;
use TTLeague\Notifications\Notifications;
use TTLeague\Notifications\Push\Vapid;
use TTLeague\Notifications\Push\VapidKeys;

/**
 * Benachrichtigungen der Fristen (wie der stündliche Lauf ttleague_notifications_hourly) und
 * VAPID-Schlüssel für Web Push.
 */
final class NotificationCommand {

	/**
	 * Prüft die Fristen und versendet fällige Erinnerungen.
	 *
	 * Bereits versendete Nachrichten gehen nicht noch einmal hinaus (notification_log).
	 *
	 * ## OPTIONS
	 *
	 * [--at=<zeit>]
	 * : Zeitpunkt in Ortszeit der Website statt jetzt, z. B. «2026-11-12 08:00». Nur zum Testen auf
	 * einer Kopie der Website: Die Nachrichten werden wirklich versendet und protokolliert. Alte
	 * Einträge des Versandprotokolls bleiben dabei stehen.
	 *
	 * ## EXAMPLES
	 *
	 *     wp ttleague notifications run
	 *     wp ttleague notifications run --at="2026-11-12 08:00"
	 *
	 * @param list<string>         $args       Argumente.
	 * @param array<string,string> $assoc_args Optionen.
	 */
	public function run( array $args, array $assoc_args ): void {
		unset( $args );
		$clock = null;
		if ( isset( $assoc_args['at'] ) ) {
			try {
				$local = new \DateTimeImmutable( (string) $assoc_args['at'], wp_timezone() );
			} catch ( \Exception $e ) {
				\WP_CLI::error( 'Ungültiger Zeitpunkt: ' . $e->getMessage() );
			}
			$clock = new FixedClock( $local->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' ) );
			\WP_CLI::log( 'Zeitpunkt: ' . $local->format( 'Y-m-d H:i' ) . ' Ortszeit' );
		}
		$rows = array();
		foreach ( Notifications::deadlines( $clock )->run( null === $clock ) as $entry ) {
			foreach ( $entry['deliveries'] as $delivery ) {
				$rows[] = array(
					'art'        => $entry['event'],
					'bezug'      => $entry['ref'],
					'empfaenger' => $delivery['recipient'],
					'kanal'      => $delivery['channel'],
					'status'     => $delivery['status'],
				);
			}
		}
		if ( array() === $rows ) {
			\WP_CLI::success( 'Nichts fällig.' );
			return;
		}
		\WP_CLI\Utils\format_items( 'table', $rows, array( 'art', 'bezug', 'empfaenger', 'kanal', 'status' ) );
		$sent = count( array_filter( $rows, static fn( array $row ): bool => 'sent' === $row['status'] ) );
		\WP_CLI::success( 1 === $sent ? '1 Nachricht versendet.' : sprintf( '%d Nachrichten versendet.', $sent ) );
	}

	/**
	 * Zeigt Herkunft und öffentlichen Teil der VAPID-Schlüssel oder erzeugt ein neues Paar.
	 *
	 * Ohne Konstanten in wp-config.php erzeugt das Plugin beim ersten Bedarf ein Schlüsselpaar
	 * in der Option ttleague_vapid. Mit --generate wird ein neues Paar nur ausgegeben, nicht
	 * gespeichert, z. B. für die Konstanten. Ein Wechsel macht alle Push-Abos ungültig.
	 *
	 * ## OPTIONS
	 *
	 * [--generate]
	 * : Neues Schlüsselpaar als Zeilen für wp-config.php ausgeben.
	 *
	 * ## EXAMPLES
	 *
	 *     wp ttleague notifications vapid
	 *     wp ttleague notifications vapid --generate
	 *
	 * @param list<string>         $args       Argumente.
	 * @param array<string,string> $assoc_args Optionen.
	 */
	public function vapid( array $args, array $assoc_args ): void {
		unset( $args );
		if ( isset( $assoc_args['generate'] ) ) {
			$keys = Vapid::generate();
			if ( null === $keys ) {
				\WP_CLI::error( 'OpenSSL mit P-256 fehlt.' );
			}
			\WP_CLI::log( "define( 'TTLEAGUE_VAPID_PUBLIC_KEY', '" . $keys['public'] . "' );" );
			\WP_CLI::log( "define( 'TTLEAGUE_VAPID_PRIVATE_KEY', '" . $keys['private'] . "' );" );
			return;
		}
		$vapid = VapidKeys::get();
		if ( null === $vapid ) {
			\WP_CLI::error( 'Push ist nicht verfügbar: OpenSSL mit P-256 fehlt, oder die Konstanten sind ungültig.' );
		}
		\WP_CLI::log( 'Herkunft: ' . ( 'constants' === VapidKeys::source() ? 'Konstanten in wp-config.php' : 'Option ttleague_vapid' ) );
		\WP_CLI::log( 'Öffentlicher Schlüssel: ' . $vapid->public_key() );
		\WP_CLI::log( 'Kontakt (sub): ' . VapidKeys::subject() );
	}
}
