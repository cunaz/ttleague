<?php
/**
 * Cache ohne Speicherung (Tests, Werkzeuge).
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\ReadModel\Cache;

final class NullCache implements Cache {

	public function remember( string $name, array $parts, callable $compute ): mixed {
		return $compute();
	}
}
