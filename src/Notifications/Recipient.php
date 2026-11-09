<?php
/**
 * Empfängerin oder Empfänger einer Benachrichtigung: eine Person oder ein TK-Konto ohne Person.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Notifications;

final class Recipient {

	/**
	 * @param int|null    $person_id  Person (null bei einem TK-Konto ohne Person).
	 * @param int|null    $user_id    WordPress-Konto, falls vorhanden.
	 * @param string|null $email      Gültige E-Mail-Adresse oder null.
	 * @param string      $name       Anzeigename.
	 * @param string      $salutation Anrede (Vorname).
	 */
	public function __construct(
		public readonly ?int $person_id,
		public readonly ?int $user_id,
		public readonly ?string $email,
		public readonly string $name,
		public readonly string $salutation,
	) {}

	/**
	 * Eindeutiger Schlüssel: Person, sonst Konto.
	 */
	public function key(): string {
		return null !== $this->person_id ? 'p' . $this->person_id : 'u' . (int) $this->user_id;
	}

	/**
	 * Ist das die handelnde Person (Konto oder Person)?
	 */
	public function is( ?int $user_id, ?int $person_id ): bool {
		return ( null !== $user_id && $user_id > 0 && $user_id === $this->user_id )
			|| ( null !== $person_id && $person_id > 0 && $person_id === $this->person_id );
	}

	/**
	 * Gültige E-Mail-Adresse oder null.
	 */
	public static function valid_email( ?string $email ): ?string {
		$email = trim( (string) $email );
		if ( '' === $email || strlen( $email ) > 190 ) {
			return null;
		}
		return false === filter_var( $email, FILTER_VALIDATE_EMAIL ) ? null : $email;
	}
}
