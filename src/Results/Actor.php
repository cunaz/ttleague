<?php
/**
 * Handelnde Person einer Aktion (angemeldetes Konto).
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Results;

final class Actor {

	public function __construct(
		public readonly int $user_id,
		public readonly ?int $person_id,
		public readonly bool $is_tk,
		public readonly string $name = '',
	) {}
}
