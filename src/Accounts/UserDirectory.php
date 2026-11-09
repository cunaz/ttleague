<?php
/**
 * Zugriff auf WordPress-Konten, soweit Konten- und Einladungsregeln ihn brauchen.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Accounts;

/**
 * Trennt die Regeln (ClubPeople, ClubAccounts) von WordPress, damit sie ohne WordPress
 * testbar sind. Produktion: WordPressUsers.
 */
interface UserDirectory {

	/**
	 * Angaben zu Konten; Konten, die es nicht gibt, fehlen im Ergebnis.
	 *
	 * privileged: erweiterte Rechte (ttl_tk, edit_posts oder manage_options); admin: manage_options;
	 * link_allowed: Anmeldung per Link erlaubt (LoginFlow::link_allowed); last_login: Unix-Zeit.
	 *
	 * @param list<int> $user_ids Konten.
	 * @return array<int,array{email:string,last_login:int|null,privileged:bool,admin:bool,link_allowed:bool}>
	 */
	public function lookup( array $user_ids ): array;

	/**
	 * Konto mit dieser E-Mail-Adresse oder null.
	 */
	public function find_by_email( string $email ): ?int;

	/**
	 * Ändert die E-Mail-Adresse eines Kontos ohne die Standardmitteilung von WordPress.
	 *
	 * @return string|null Fehlermeldung oder null bei Erfolg.
	 */
	public function update_email( int $user_id, string $email ): ?string;
}
