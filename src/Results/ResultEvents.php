<?php
/**
 * Empfänger der fachlichen Ereignisse der Erfassung (WordPress-Aktionen, in Tests aufgezeichnet).
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Results;

interface ResultEvents {

	public function entered( int $fixture_id, int $user_id, bool $edited ): void;

	public function confirmed( int $fixture_id, int $user_id, bool $by_tk ): void;

	public function disputed( int $fixture_id, int $user_id, string $reason ): void;

	public function decided( int $fixture_id, int $user_id, string $action, ?string $comment ): void;

	public function photo_added( int $fixture_id, int $user_id, int $attachment_id ): void;
}
