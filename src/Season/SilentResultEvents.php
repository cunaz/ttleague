<?php
/**
 * Ereignisse der Erfassung, die niemanden benachrichtigen.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Season;

use TTLeague\Results\ResultEvents;

/**
 * Der Rückzug einer Mannschaft wertet alle ihre Begegnungen in einer Transaktion forfait. Die
 * Erfassung meldet ihre Ereignisse sonst gleich nach jeder Begegnung, also vor dem Ende dieser
 * Transaktion, und jede Begegnung ergäbe eine eigene Mitteilung. Der Saison-Assistent meldet
 * den Rückzug stattdessen einmal über SeasonEvents::team_withdrawn().
 */
final class SilentResultEvents implements ResultEvents {

	public function entered( int $fixture_id, int $user_id, bool $edited ): void {}

	public function confirmed( int $fixture_id, int $user_id, bool $by_tk ): void {}

	public function disputed( int $fixture_id, int $user_id, string $reason ): void {}

	public function decided( int $fixture_id, int $user_id, string $action, ?string $comment ): void {}

	public function photo_added( int $fixture_id, int $user_id, int $attachment_id ): void {}
}
