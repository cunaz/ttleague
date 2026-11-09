<?php
/**
 * Konten mit dem Recht der TK (ttl_tk); in WordPress über die Benutzerrollen.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Notifications;

interface TkDirectory {

	/**
	 * Konten mit dem Recht ttl_tk. tk_role: Das Konto hat die Rolle ttl_tk (nicht nur als
	 * Administrator das Recht).
	 *
	 * @return list<array{user_id:int,email:string,name:string,tk_role:bool}>
	 */
	public function accounts(): array;
}
