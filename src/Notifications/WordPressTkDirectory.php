<?php
/**
 * TK-Konten aus den WordPress-Benutzern.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Notifications;

use TTLeague\Access\Capabilities;
use TTLeague\Access\Roles;

final class WordPressTkDirectory implements TkDirectory {

	public function accounts(): array {
		$accounts = array();
		$users    = get_users(
			array(
				'capability' => Capabilities::TK,
				'orderby'    => 'ID',
			)
		);
		foreach ( $users as $user ) {
			$accounts[] = array(
				'user_id' => (int) $user->ID,
				'email'   => (string) $user->user_email,
				'name'    => (string) $user->display_name,
				'tk_role' => in_array( Roles::ROLE, (array) $user->roles, true ),
			);
		}
		return $accounts;
	}
}
