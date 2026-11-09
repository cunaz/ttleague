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

/**
 * Benachrichtigungen der Fristen (wie der stündliche Lauf ttleague_notifications_hourly).
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
	 * : Zeitpunkt in Ortszeit der Website statt jetzt, z. B. «2026-11-12 08:00». Nur zum Testen:
	 * Die Nachrichten werden wirklich versendet und protokolliert.
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
		foreach ( Notifications::deadlines( $clock )->run() as $entry ) {
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
}
