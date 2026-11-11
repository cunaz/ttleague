<?php
/**
 * Regeln für Verschiebung und Terminvorschlag (Art. 10): Übergänge, Rollen, Bewilligung.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Domain\Match;

/**
 * Reine Regel aus docs/verschiebung.md. Wer eine Seite vertritt, entscheidet die Rechteprüfung;
 * hier steht, welche Rolle daraus für einen Antrag folgt, was sie in welchem Status darf und
 * wann die TK bewilligen muss. Termine sind Ortszeit im Format «Y-m-d H:i:s»; Tage sind
 * Kalendertage.
 */
final class PostponementRules {

	/** Verschiebung einer Begegnung mit Termin. */
	public const POSTPONE = 'postpone';

	/** Terminvorschlag für eine Begegnung ohne Termin (Cup-Runde). */
	public const PROPOSE = 'propose';

	public const REQUESTED = 'requested';
	public const ACCEPTED  = 'accepted';
	public const APPROVED  = 'approved';
	public const DECLINED  = 'declined';
	public const WITHDRAWN = 'withdrawn';
	public const REJECTED  = 'rejected';

	/** Offene Anträge: höchstens einer pro Begegnung. */
	public const OPEN = array( self::REQUESTED, self::ACCEPTED );

	public const WITHDRAW = 'withdraw';
	public const DECLINE  = 'decline';
	public const ACCEPT   = 'accept';
	public const APPROVE  = 'approve';
	public const REJECT   = 'reject';

	public const ROLE_REQUESTER = 'requester';
	public const ROLE_OPPONENT  = 'opponent';
	public const ROLE_TK        = 'tk';

	public const HOME = 'home';
	public const AWAY = 'away';

	/**
	 * Aktion => erlaubte Ausgangsstatus und Rollen. Die TK entscheidet auch über einen Antrag,
	 * den die Gegenseite noch nicht beantwortet hat.
	 */
	private const RULES = array(
		self::WITHDRAW => array(
			'from'  => array( self::REQUESTED ),
			'roles' => array( self::ROLE_REQUESTER ),
		),
		self::DECLINE  => array(
			'from'  => array( self::REQUESTED ),
			'roles' => array( self::ROLE_OPPONENT ),
		),
		self::ACCEPT   => array(
			'from'  => array( self::REQUESTED ),
			'roles' => array( self::ROLE_OPPONENT ),
		),
		self::APPROVE  => array(
			'from'  => array( self::REQUESTED, self::ACCEPTED ),
			'roles' => array( self::ROLE_TK ),
		),
		self::REJECT   => array(
			'from'  => array( self::REQUESTED, self::ACCEPTED ),
			'roles' => array( self::ROLE_TK ),
		),
	);

	/**
	 * @return list<string>
	 */
	public static function actions(): array {
		return array_keys( self::RULES );
	}

	/**
	 * Darf eine der Rollen die Aktion im Status des Antrags ausführen?
	 *
	 * @param list<string> $roles Rollen der handelnden Person für diesen Antrag.
	 */
	public static function allows( string $action, string $status, array $roles ): bool {
		$rule = self::RULES[ $action ] ?? null;
		if ( null === $rule || ! in_array( $status, $rule['from'], true ) ) {
			return false;
		}
		return array() !== array_intersect( $roles, $rule['roles'] );
	}

	/**
	 * Hat eine der Rollen grundsätzlich Zugriff auf die Aktion (unabhängig vom Status)?
	 *
	 * @param list<string> $roles Rollen.
	 */
	public static function role_may( string $action, array $roles ): bool {
		$rule = self::RULES[ $action ] ?? null;
		return null !== $rule && array() !== array_intersect( $roles, $rule['roles'] );
	}

	/**
	 * Status nach der Aktion. Die Zustimmung der Gegenseite genügt, ausser die TK muss
	 * bewilligen. Der Entscheid der TK schliesst die Bewilligung ein: nie «accepted».
	 */
	public static function target( string $action, bool $needs_approval = false ): string {
		return match ( $action ) {
			self::WITHDRAW => self::WITHDRAWN,
			self::DECLINE  => self::DECLINED,
			self::ACCEPT   => $needs_approval ? self::ACCEPTED : self::APPROVED,
			self::APPROVE  => self::APPROVED,
			self::REJECT   => self::REJECTED,
			default        => throw new \InvalidArgumentException( 'Unbekannte Aktion: ' . $action ),
		};
	}

	/**
	 * Status eines neuen Antrags: Stellt ihn die TK, gilt er sofort.
	 */
	public static function initial_status( bool $by_tk ): string {
		return $by_tk ? self::APPROVED : self::REQUESTED;
	}

	/**
	 * Art des Antrags: Ohne bisherigen Termin ist es ein Terminvorschlag.
	 */
	public static function kind( ?string $scheduled_at ): string {
		return null === $scheduled_at || '' === $scheduled_at ? self::PROPOSE : self::POSTPONE;
	}

	/**
	 * Wer darf den Antrag stellen? Jede Seite und die TK; den ersten Termin einer Begegnung
	 * (Terminvorschlag) schlägt die Heimseite vor.
	 *
	 * @param list<string> $sides Seiten, die die Person vertritt (home, away).
	 */
	public static function may_request( string $kind, array $sides, bool $is_tk ): bool {
		if ( $is_tk ) {
			return true;
		}
		return self::PROPOSE === $kind ? in_array( self::HOME, $sides, true ) : array() !== $sides;
	}

	/**
	 * Status der Begegnung nach einem gutgeheissenen Antrag: Eine Verschiebung macht sie
	 * «postponed»; der erste Termin (Terminvorschlag) lässt sie «scheduled».
	 */
	public static function fixture_status( string $kind ): string {
		return self::PROPOSE === $kind ? FixtureWorkflow::SCHEDULED : FixtureWorkflow::POSTPONED;
	}

	/**
	 * Ist die Begegnung für einen Antrag oder seine Wirkung offen (noch ohne Resultat)?
	 */
	public static function fixture_open( string $fixture_status ): bool {
		return in_array( $fixture_status, array( FixtureWorkflow::SCHEDULED, FixtureWorkflow::POSTPONED ), true );
	}

	/**
	 * Rollen einer Person für einen Antrag.
	 *
	 * Antragsteller ist die Seite, die den Antrag gestellt hat (die Person selbst und wer
	 * dieselbe Seite vertritt), Gegenseite, wer die andere Seite vertritt. Im Clubduell vertritt
	 * der TK-Chef beide Seiten: Er ist dann beides, ausser er hat den Antrag selbst gestellt.
	 * Wer beantragt hat, ist nie Gegenseite.
	 *
	 * @param list<string> $requester_sides Seiten, die die antragstellende Person vertritt.
	 * @param list<string> $actor_sides     Seiten, die die handelnde Person vertritt.
	 * @param bool         $is_requester    Die handelnde Person hat den Antrag gestellt.
	 * @param bool         $is_tk           Die handelnde Person gehört zur TK.
	 * @return list<string>
	 */
	public static function roles( array $requester_sides, array $actor_sides, bool $is_requester, bool $is_tk ): array {
		$roles = array();
		if ( $is_requester || array() !== array_intersect( $actor_sides, $requester_sides ) ) {
			$roles[] = self::ROLE_REQUESTER;
		}
		$opposite = array_map( array( self::class, 'opposite' ), $requester_sides );
		if ( ! $is_requester && array() !== array_intersect( $actor_sides, $opposite ) ) {
			$roles[] = self::ROLE_OPPONENT;
		}
		if ( $is_tk ) {
			$roles[] = self::ROLE_TK;
		}
		return $roles;
	}

	public static function opposite( string $side ): string {
		return self::HOME === $side ? self::AWAY : self::HOME;
	}

	/**
	 * Muss die TK bewilligen, nachdem die Gegenseite zugestimmt hat?
	 *
	 * - Terminvorschlag: nur wenn der Termin nach der Frist der Runde liegt. Bis und mit Fristtag
	 *   und ohne Frist genügt die Zustimmung.
	 * - Verschiebung: wenn der neue Termin mehr als $approval_days Kalendertage nach dem
	 *   bisherigen liegt oder (Cup) nach der Frist der Runde. Vorverschieben nie.
	 *
	 * @param string      $kind          postpone oder propose.
	 * @param string|null $old_date      Bisheriger Termin (Ortszeit), null beim Terminvorschlag.
	 * @param string      $new_date      Neuer Termin (Ortszeit).
	 * @param int         $approval_days SeasonRules::postponement_approval_days.
	 * @param string|null $deadline      Frist der Cup-Runde (Datum), null ohne Frist.
	 */
	public static function needs_approval( string $kind, ?string $old_date, string $new_date, int $approval_days, ?string $deadline = null ): bool {
		$new_day    = substr( $new_date, 0, 10 );
		$after_term = null !== $deadline && '' !== $deadline && $new_day > substr( $deadline, 0, 10 );
		if ( self::PROPOSE === $kind || null === $old_date || '' === $old_date ) {
			return $after_term;
		}
		if ( self::timestamp( $new_date ) <= self::timestamp( $old_date ) ) {
			return false;
		}
		return self::days_between( substr( $old_date, 0, 10 ), $new_day ) > $approval_days || $after_term;
	}

	/**
	 * Kalendertage vom einen zum anderen Datum (Y-m-d), negativ wenn $to früher liegt.
	 */
	public static function days_between( string $from, string $to ): int {
		$utc   = new \DateTimeZone( 'UTC' );
		$start = new \DateTimeImmutable( substr( $from, 0, 10 ) . ' 00:00:00', $utc );
		$end   = new \DateTimeImmutable( substr( $to, 0, 10 ) . ' 00:00:00', $utc );
		return (int) $start->diff( $end )->format( '%r%a' );
	}

	/**
	 * Vergleichswert zweier Termine derselben Zeitzone (als wären sie UTC).
	 */
	private static function timestamp( string $local ): int {
		return ( new \DateTimeImmutable( $local, new \DateTimeZone( 'UTC' ) ) )->getTimestamp();
	}
}
