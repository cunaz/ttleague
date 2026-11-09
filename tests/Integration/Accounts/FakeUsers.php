<?php
/**
 * Testdouble für die WordPress-Konten.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Tests\Integration\Accounts;

use TTLeague\Accounts\UserDirectory;

/**
 * Konten als Array; zeichnet Änderungen der E-Mail-Adresse auf.
 */
final class FakeUsers implements UserDirectory {

	/** @var array<int,array{email:string,last_login:int|null,privileged:bool,admin:bool,link_allowed:bool}> */
	public array $users = array();

	/** @var list<array{0:int,1:string}> */
	public array $updates = array();

	/** @var string|null Fehlermeldung, die update_email() liefern soll. */
	public ?string $fail_with = null;

	/** @var (callable(int,string):void)|null Nebenwirkung in update_email() (z. B. gleichzeitige Änderung). */
	public $on_update = null;

	/** Anzahl Aufrufe von lookup(). */
	public int $lookups = 0;

	public function add( int $id, string $email, bool $privileged = false, bool $admin = false, ?int $last_login = null ): void {
		$this->users[ $id ] = array(
			'email'        => $email,
			'last_login'   => $last_login,
			'privileged'   => $privileged || $admin,
			'admin'        => $admin,
			// Wie LoginFlow::link_allowed(): kein Anmeldelink für Konten mit erweiterten Rechten.
			'link_allowed' => ! ( $privileged || $admin ),
		);
	}

	public function lookup( array $user_ids ): array {
		++$this->lookups;
		return array_intersect_key( $this->users, array_flip( $user_ids ) );
	}

	public function find_by_email( string $email ): ?int {
		foreach ( $this->users as $id => $user ) {
			if ( '' !== $email && strtolower( $user['email'] ) === strtolower( trim( $email ) ) ) {
				return $id;
			}
		}
		return null;
	}

	public function update_email( int $user_id, string $email ): ?string {
		if ( null !== $this->fail_with ) {
			return $this->fail_with;
		}
		if ( null !== $this->on_update ) {
			( $this->on_update )( $user_id, $email );
		}
		$this->updates[]                  = array( $user_id, $email );
		$this->users[ $user_id ]['email'] = $email;
		return null;
	}
}
