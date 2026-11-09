<?php
/**
 * TK-Konten für Tests.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Tests\Integration\Notifications;

use TTLeague\Notifications\TkDirectory;

final class FakeTkDirectory implements TkDirectory {

	/**
	 * @param list<array{user_id:int,email:string,name:string,tk_role:bool}> $accounts Konten.
	 */
	public function __construct( public array $accounts = array() ) {}

	public function accounts(): array {
		return $this->accounts;
	}
}
