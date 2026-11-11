<?php
/**
 * Regeln der Anmeldung zu einem Anlass: Anmeldefenster, Höchstzahl, Warteliste, Nachrücken.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Domain\Events;

/**
 * Reine Regeln aus docs/anlaesse.md. Termine eines Anlasses sind Ortszeit der Website
 * («Y-m-d H:i:s»), «jetzt» ist ein Zeitpunkt; verglichen wird in der übergebenen Zeitzone.
 */
final class RegistrationRules {

	public const OPEN      = 'open';
	public const CLOSED    = 'closed';
	public const CANCELLED = 'cancelled';

	public const STATUSES = array( self::OPEN, self::CLOSED, self::CANCELLED );

	public const TOURNAMENT = 'tournament';
	public const TRAINING   = 'training';
	public const MEETING    = 'meeting';
	public const OTHER      = 'other';

	public const TYPES = array( self::TOURNAMENT, self::TRAINING, self::MEETING, self::OTHER );

	public const REGISTERED = 'registered';
	public const WAITLIST   = 'waitlist';

	/** Gründe, aus denen An- und Abmeldung nicht möglich sind. */
	public const BLOCK_CANCELLED = 'cancelled';
	public const BLOCK_CLOSED    = 'closed';
	public const BLOCK_DEADLINE  = 'deadline';

	/**
	 * Bis wann man sich an- und abmelden kann: der Anmeldeschluss, ohne Anmeldeschluss der Beginn.
	 */
	public static function effective_deadline( ?string $deadline, string $starts_at ): string {
		return null === $deadline || '' === $deadline ? $starts_at : $deadline;
	}

	/**
	 * Ist die Frist verstrichen? Genau zum Zeitpunkt der Frist ist sie es noch nicht.
	 *
	 * @param string $deadline Ortszeit «Y-m-d H:i:s».
	 */
	public static function deadline_passed( string $deadline, \DateTimeImmutable $now, \DateTimeZone $timezone ): bool {
		$limit = \DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s', $deadline, $timezone );
		return false !== $limit && $now > $limit;
	}

	/**
	 * Warum An- und Abmeldung nicht möglich sind, oder null. Ein abgesagter Anlass ist für alle
	 * gesperrt; nach dem Schliessen und nach der Frist ändert nur noch die TK.
	 *
	 * @param string      $status    Status des Anlasses.
	 * @param string|null $deadline  Anmeldeschluss (Ortszeit) oder null.
	 * @param string      $starts_at Beginn (Ortszeit).
	 * @param bool        $by_tk     Die TK handelt.
	 */
	public static function blocked( string $status, ?string $deadline, string $starts_at, \DateTimeImmutable $now, \DateTimeZone $timezone, bool $by_tk = false ): ?string {
		if ( self::CANCELLED === $status ) {
			return self::BLOCK_CANCELLED;
		}
		if ( $by_tk ) {
			return null;
		}
		if ( self::OPEN !== $status ) {
			return self::BLOCK_CLOSED;
		}
		return self::deadline_passed( self::effective_deadline( $deadline, $starts_at ), $now, $timezone ) ? self::BLOCK_DEADLINE : null;
	}

	/**
	 * Status einer neuen Anmeldung: Warteliste, sobald die Höchstzahl erreicht ist.
	 *
	 * @param int|null $max        Höchstzahl oder null (unbegrenzt).
	 * @param int      $registered Anzahl Teilnehmende (ohne Warteliste).
	 */
	public static function placement( ?int $max, int $registered ): string {
		return null !== $max && $registered >= $max ? self::WAITLIST : self::REGISTERED;
	}

	/**
	 * Freie Plätze; null ohne Höchstzahl.
	 */
	public static function free_places( ?int $max, int $registered ): ?int {
		return null === $max ? null : max( 0, $max - $registered );
	}

	/**
	 * Wie viele Anmeldungen der Warteliste rücken nach?
	 *
	 * @param int|null $max        Höchstzahl oder null (unbegrenzt: alle rücken nach).
	 * @param int      $registered Anzahl Teilnehmende.
	 * @param int      $waiting    Länge der Warteliste.
	 */
	public static function promotions( ?int $max, int $registered, int $waiting ): int {
		$free = self::free_places( $max, $registered );
		return null === $free ? max( 0, $waiting ) : max( 0, min( $free, $waiting ) );
	}
}
