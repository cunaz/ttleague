<?php
/**
 * Ereignisse der Erfassung für die Tests des Cups: gleicht das Tableau ab wie die WordPress-Schicht.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Tests\Integration\Cup;

use TTLeague\Cup\Bracket;
use TTLeague\Results\ResultEvents;

/**
 * In WordPress hängt Cup::on_result() an den Aktionen ttleague_result_confirmed und
 * ttleague_result_decided; hier ruft das Testdouble das Tableau direkt auf.
 */
final class BracketResultEvents implements ResultEvents {

	/** @var list<list<mixed>> */
	public array $calls = array();

	public function __construct( private readonly Bracket $bracket ) {}

	public function entered( int $fixture_id, int $user_id, bool $edited ): void {
		$this->calls[] = array( 'entered', $fixture_id, $user_id, $edited );
	}

	public function confirmed( int $fixture_id, int $user_id, bool $by_tk ): void {
		$this->calls[] = array( 'confirmed', $fixture_id, $user_id, $by_tk );
		$this->bracket->fixture_changed( $fixture_id, $user_id );
	}

	public function disputed( int $fixture_id, int $user_id, string $reason ): void {
		$this->calls[] = array( 'disputed', $fixture_id, $user_id, $reason );
	}

	public function decided( int $fixture_id, int $user_id, string $action, ?string $comment ): void {
		$this->calls[] = array( 'decided', $fixture_id, $user_id, $action, $comment );
		$this->bracket->fixture_changed( $fixture_id, $user_id );
	}

	public function photo_added( int $fixture_id, int $user_id, int $attachment_id ): void {
		$this->calls[] = array( 'photo', $fixture_id, $user_id, $attachment_id );
	}
}
