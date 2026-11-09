<?php
/**
 * WordPress-Konten für die Konten- und Einladungsregeln.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Accounts;

use TTLeague\Access\Capabilities;

final class WordPressUsers implements UserDirectory {

	/**
	 * Eine Abfrage für alle Konten; die Metadaten (letzte Anmeldung) lädt WordPress mit.
	 *
	 * @param list<int> $user_ids Konten.
	 * @return array<int,array{email:string,last_login:int|null,privileged:bool,admin:bool,link_allowed:bool}>
	 */
	public function lookup( array $user_ids ): array {
		$ids = array_values( array_unique( array_filter( array_map( 'intval', $user_ids ), static fn( int $id ): bool => $id > 0 ) ) );
		if ( array() === $ids ) {
			return array();
		}
		$users = get_users(
			array(
				'include' => $ids,
				'blog_id' => 0,
				'fields'  => 'all_with_meta',
			)
		);
		$info  = array();
		foreach ( $users as $user ) {
			if ( ! $user instanceof \WP_User ) {
				continue;
			}
			$last                    = get_user_meta( $user->ID, Accounts::LAST_LOGIN_META, true );
			$info[ (int) $user->ID ] = array(
				'email'        => (string) $user->user_email,
				'last_login'   => is_numeric( $last ) && (int) $last > 0 ? (int) $last : null,
				'privileged'   => user_can( $user, Capabilities::TK ) || user_can( $user, 'edit_posts' ) || user_can( $user, 'manage_options' ),
				'admin'        => user_can( $user, 'manage_options' ),
				'link_allowed' => LoginFlow::link_allowed( (int) $user->ID ),
			);
		}
		return $info;
	}

	public function find_by_email( string $email ): ?int {
		$email = trim( $email );
		if ( '' === $email ) {
			return null;
		}
		$id = email_exists( $email );
		return false === $id ? null : (int) $id;
	}

	public function update_email( int $user_id, string $email ): ?string {
		// Die bisherige Adresse benachrichtigt ClubAccounts selbst; die Mitteilung von WordPress entfällt.
		add_filter( 'send_email_change_email', '__return_false' );
		try {
			$result = wp_update_user(
				array(
					'ID'         => $user_id,
					'user_email' => $email,
				)
			);
		} finally {
			remove_filter( 'send_email_change_email', '__return_false' );
		}
		return is_wp_error( $result ) ? $result->get_error_message() : null;
	}
}
