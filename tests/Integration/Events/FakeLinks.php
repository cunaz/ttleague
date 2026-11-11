<?php
/**
 * Adressen für Tests der Benachrichtigungen zu Anlässen.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Tests\Integration\Events;

use TTLeague\Events\Links;

final class FakeLinks implements Links {

	public function event( array $event ): ?string {
		return null === ( $event['post_id'] ?? null ) ? null : 'https://liga.example.org/?p=' . (int) $event['post_id'];
	}

	public function my_area(): string {
		return 'https://liga.example.org/mein-bereich/';
	}
}
