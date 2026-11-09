<?php
/**
 * WP-CLI: Personen von Hand mit WordPress-Konten verknüpfen.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Cli;

use TTLeague\Accounts\Services;

/**
 * Verknüpft Personen mit Konten, die nicht automatisch verknüpft werden (z. B. ein
 * Administratorkonto einer Person, die auch spielt).
 */
final class AccountCommand {

	/**
	 * Verknüpft eine Person mit einem bestehenden Konto.
	 *
	 * ## OPTIONS
	 *
	 * <person>
	 * : ID der Person (wp_ttl_person.id).
	 *
	 * <user>
	 * : Konto: ID, Anmeldename oder E-Mail-Adresse.
	 *
	 * ## EXAMPLES
	 *
	 *     wp ttleague account link 42 admin
	 *
	 * @param list<string>         $args       Argumente.
	 * @param array<string,string> $assoc_args Optionen.
	 */
	public function link( array $args, array $assoc_args ): void {
		unset( $assoc_args );
		$person_id = (int) ( $args[0] ?? 0 );
		$accounts  = Services::accounts();
		$person    = $accounts->person( $person_id );
		if ( null === $person ) {
			\WP_CLI::error( 'Person nicht gefunden.' );
		}
		$user = self::user( (string) ( $args[1] ?? '' ) );
		if ( null === $user ) {
			\WP_CLI::error( 'Konto nicht gefunden.' );
		}
		$other = $accounts->linked_person( (int) $user->ID, $person_id );
		if ( null !== $other ) {
			\WP_CLI::error( sprintf( 'Das Konto ist bereits mit Person %d verknüpft.', $other ) );
		}
		$before  = empty( $person['user_id'] ) ? null : array( 'user_id' => (int) $person['user_id'] );
		$user_id = (int) $user->ID;
		try {
			// Verknüpfung und Protokolleintrag gemeinsam oder gar nicht.
			Services::db()->transaction(
				static function () use ( $accounts, $person_id, $user_id, $before ): void {
					$accounts->link_user( $person_id, $user_id );
					Services::audit()->record( 'person', $person_id, 'linked', self::actor(), $before, array( 'user_id' => $user_id ), 'wp-cli' );
				}
			);
		} catch ( \RuntimeException $e ) {
			\WP_CLI::error( $e->getMessage() );
		}
		\WP_CLI::success( sprintf( 'Person %d ist mit Konto %s verknüpft.', $person_id, $user->user_login ) );
	}

	/**
	 * Hebt die Verknüpfung einer Person mit ihrem Konto auf (das Konto bleibt bestehen).
	 *
	 * ## OPTIONS
	 *
	 * <person>
	 * : ID der Person.
	 *
	 * @param list<string>         $args       Argumente.
	 * @param array<string,string> $assoc_args Optionen.
	 */
	public function unlink( array $args, array $assoc_args ): void {
		unset( $assoc_args );
		$person_id = (int) ( $args[0] ?? 0 );
		$person    = Services::accounts()->person( $person_id );
		if ( null === $person ) {
			\WP_CLI::error( 'Person nicht gefunden.' );
		}
		if ( empty( $person['user_id'] ) ) {
			\WP_CLI::error( 'Die Person ist mit keinem Konto verknüpft.' );
		}
		$before = array( 'user_id' => (int) $person['user_id'] );
		try {
			Services::db()->transaction(
				static function () use ( $person_id, $before ): void {
					Services::accounts()->unlink_user( $person_id );
					Services::audit()->record( 'person', $person_id, 'unlinked', self::actor(), $before, null, 'wp-cli' );
				}
			);
		} catch ( \RuntimeException $e ) {
			\WP_CLI::error( $e->getMessage() );
		}
		\WP_CLI::success( sprintf( 'Verknüpfung von Person %d aufgehoben.', $person_id ) );
		if ( ! \TTLeague\Infrastructure\Database\Installer::ensure_unique_person_user() ) {
			\WP_CLI::warning( 'Es gibt weiterhin Konten mit mehreren Personen; Verknüpfen bleibt gesperrt.' );
		}
	}

	/**
	 * Angemeldetes Konto (bei WP-CLI mit --user) oder null.
	 */
	private static function actor(): ?int {
		$id = get_current_user_id();
		return $id > 0 ? $id : null;
	}

	private static function user( string $ref ): ?\WP_User {
		$user = ctype_digit( $ref ) ? get_user_by( 'id', (int) $ref ) : ( is_email( $ref ) ? get_user_by( 'email', $ref ) : get_user_by( 'login', $ref ) );
		return $user instanceof \WP_User ? $user : null;
	}
}
