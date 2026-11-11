<?php
/**
 * Ereignisse des Cups für die Tests der Benachrichtigungen: versendet wie die WordPress-Schicht.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Tests\Integration\Cup;

use TTLeague\Cup\CupEvents;
use TTLeague\Cup\CupNotifications;

/**
 * In WordPress hängt Cup::on_changed() an der Aktion ttleague_cup_changed: Mitteilungen zu
 * einzelnen Paarungen gehen direkt hinaus, die Auslosung wird für den Versand in Schritten
 * vorgemerkt. Hier werden die Paarungen direkt gemeldet und die Auslosung nur gezählt.
 */
final class AnnouncingCupEvents implements CupEvents {

	public int $released = 0;

	public function __construct( private readonly CupNotifications $notifications ) {}

	public function changed( int $competition_id, string $action, int $user_id, array $tie_ids = array() ): void {
		if ( self::DRAW_RELEASED === $action ) {
			++$this->released;
			return;
		}
		if ( array() !== $tie_ids && in_array( $action, array( self::ADVANCED, self::SCHEDULED, self::HOME_SWAPPED ), true ) ) {
			$this->notifications->announce( $competition_id, $tie_ids );
		}
	}
}
