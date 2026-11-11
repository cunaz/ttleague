<?php
/**
 * Bezeichnungen für Anlässe und Anmeldungen.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Events;

use TTLeague\Domain\Events\RegistrationRules as Rules;

final class Labels {

	/**
	 * Arten eines Anlasses: Schlüssel => Bezeichnung.
	 *
	 * @return array<string,string>
	 */
	public static function types(): array {
		return array(
			Rules::TOURNAMENT => __( 'Turnier', 'ttleague' ),
			Rules::TRAINING   => __( 'Training', 'ttleague' ),
			Rules::MEETING    => __( 'Sitzung', 'ttleague' ),
			Rules::OTHER      => __( 'Anderes', 'ttleague' ),
		);
	}

	public static function type( string $type ): string {
		return self::types()[ $type ] ?? $type;
	}

	public static function status( string $status ): string {
		return match ( $status ) {
			Rules::OPEN      => __( 'offen', 'ttleague' ),
			Rules::CLOSED    => __( 'geschlossen', 'ttleague' ),
			Rules::CANCELLED => __( 'abgesagt', 'ttleague' ),
			default          => $status,
		};
	}

	public static function registration( string $status ): string {
		return match ( $status ) {
			Rules::REGISTERED => __( 'angemeldet', 'ttleague' ),
			Rules::WAITLIST   => __( 'Warteliste', 'ttleague' ),
			default           => $status,
		};
	}

	/**
	 * Warum An- und Abmeldung nicht möglich sind (Grund aus RegistrationRules::blocked()).
	 */
	public static function blocked( string $reason ): string {
		return match ( $reason ) {
			Rules::BLOCK_CANCELLED => __( 'Der Anlass ist abgesagt.', 'ttleague' ),
			Rules::BLOCK_DEADLINE  => __( 'Der Anmeldeschluss ist vorbei. Änderungen nimmt die TK entgegen.', 'ttleague' ),
			default                => __( 'Die Anmeldung ist geschlossen. Änderungen nimmt die TK entgegen.', 'ttleague' ),
		};
	}
}
