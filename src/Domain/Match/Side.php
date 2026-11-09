<?php
/**
 * Seite einer Begegnung.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Domain\Match;

enum Side: string {
	case Home = 'home';
	case Away = 'away';

	public function opponent(): self {
		return self::Home === $this ? self::Away : self::Home;
	}
}
