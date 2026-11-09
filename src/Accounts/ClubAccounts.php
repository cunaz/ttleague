<?php
/**
 * E-Mail-Adressen ändern und Personen einladen: Regeln für die TK und die TK-Chefs.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Accounts;

use TTLeague\Access\AccessPolicy;
use TTLeague\Infrastructure\AuditLog;
use TTLeague\Infrastructure\Mail\Message;
use TTLeague\Infrastructure\RateLimiter;
use TTLeague\Results\Actor;

/**
 * Sicherheitsregeln aus docs/erfassung.md:
 *
 * - TK-Chefs ändern E-Mail-Adressen nur für Personen ihres Clubs (gemeldet oder mit Funktion
 *   in der aktiven Saison), deren Konto keine erweiterten Rechte hat (ttl_tk, edit_posts,
 *   manage_options). Die TK ändert alle ausser Administratorkonten.
 * - Die neue Adresse ist gültig und eindeutig, auch gegenüber anderen WordPress-Konten. Ein
 *   verknüpftes Konto erhält die neue Adresse ebenfalls. Die bisherige Adresse wird benachrichtigt.
 * - Einladungen nur an gültige Adressen, nie per Link an Administratorkonten, für TK-Chefs
 *   höchstens INVITE_LIMIT pro Stunde.
 * - Jede Änderung und jede Einladung steht im audit_log (Einladungen über Invitations::invite).
 *
 * Als Konto einer Person gilt das verknüpfte Konto, sonst das Konto mit ihrer E-Mail-Adresse
 * (dieses würde bei der ersten Anmeldung verknüpft).
 */
final class ClubAccounts {

	/** Einladungen pro TK-Chef und Stunde. */
	public const INVITE_LIMIT = 50;

	public const INVITE_WINDOW = 3600;

	/** @var callable(int,int):void */
	private $send_invitation;

	/** @var callable(Message):bool */
	private $send_mail;

	/**
	 * @param callable(int,int):void $send_invitation Einladung versenden (Person, einladendes Konto); wirft bei Fehlern.
	 * @param callable(Message):bool $send_mail       E-Mail versenden.
	 * @param string                 $site            Name der Website für Mitteilungen.
	 */
	public function __construct(
		private readonly AccountService $accounts,
		private readonly AccessPolicy $policy,
		private readonly ClubPeople $people,
		private readonly UserDirectory $users,
		private readonly AuditLog $audit,
		private readonly RateLimiter $limiter,
		callable $send_invitation,
		callable $send_mail,
		private readonly string $site = '',
	) {
		$this->send_invitation = $send_invitation;
		$this->send_mail       = $send_mail;
	}

	/**
	 * Darf jemand mit diesen Rechten die Adresse der Person aus einer Liste ändern? Für die
	 * Anzeige der Formulare; die Zugehörigkeit zum Club prüft change_email().
	 *
	 * @param array{privileged:bool,admin:bool} $person Person aus ClubPeople::for_club().
	 */
	public static function may_edit_email( bool $by_tk, array $person ): bool {
		return $by_tk ? ! $person['admin'] : ! $person['privileged'];
	}

	/**
	 * E-Mail-Adresse einer Person setzen oder ändern.
	 */
	public function change_email( Actor $by, int $person_id, string $email ): Outcome {
		$person = $this->accounts->person( $person_id );
		if ( null === $person ) {
			return Outcome::error( __( 'Diese Person gibt es nicht.', 'ttleague' ) );
		}
		$name    = self::name( $person );
		$account = $this->account( $person );
		$denied  = $this->email_denied( $by, $person_id, $name, $account );
		if ( null !== $denied ) {
			return Outcome::error( $denied );
		}

		$email = strtolower( trim( $email ) );
		if ( '' === $email || false === is_email( $email ) ) {
			/* translators: %s: Name der Person */
			return Outcome::error( sprintf( __( 'Die E-Mail-Adresse für %s ist ungültig.', 'ttleague' ), $name ) );
		}
		$old = trim( (string) ( $person['email'] ?? '' ) );
		if ( strtolower( $old ) === $email ) {
			/* translators: %s: Name der Person */
			return Outcome::info( sprintf( __( 'Die E-Mail-Adresse von %s ist unverändert.', 'ttleague' ), $name ) );
		}
		$owner = $this->accounts->email_owner( $email );
		if ( null !== $owner && $owner !== $person_id ) {
			return self::taken( $name );
		}
		$linked = null !== $account && $account['linked'] ? $account['id'] : null;
		$other  = $this->users->find_by_email( $email );
		if ( null !== $other && $other !== $linked ) {
			/* translators: %s: Name der Person */
			return Outcome::error( sprintf( __( 'Die E-Mail-Adresse für %s gehört bereits zu einem anderen Konto.', 'ttleague' ), $name ) );
		}

		if ( null !== $linked ) {
			$error = $this->users->update_email( $linked, $email );
			if ( null !== $error ) {
				/* translators: 1: Name der Person, 2: Fehlermeldung von WordPress */
				return Outcome::error( sprintf( __( 'Das Konto von %1$s liess sich nicht ändern: %2$s', 'ttleague' ), $name, $error ) );
			}
		}
		try {
			$this->accounts->set_email( $person_id, $email );
		} catch ( \RuntimeException $e ) {
			// Gleichzeitig vergeben (person.email ist eindeutig): das Konto auf seine bisherige
			// Adresse zurücksetzen (nicht auf die der Person, die abweichen oder fehlen kann) und
			// verständlich melden.
			$previous = null === $account ? '' : $account['email'];
			if ( null !== $linked && '' !== $previous ) {
				$error = $this->users->update_email( $linked, $previous );
				if ( null !== $error ) {
					/* translators: 1: Name der Person, 2: bisherige Adresse des Kontos, 3: Fehlermeldung von WordPress */
					return Outcome::error( sprintf( __( 'Die Adresse von %1$s liess sich nicht speichern, und das Konto liess sich nicht auf %2$s zurücksetzen: %3$s. Bitte in der Benutzerverwaltung prüfen.', 'ttleague' ), $name, $previous, $error ) );
				}
			}
			$owner = $this->accounts->email_owner( $email );
			if ( null !== $owner && $owner !== $person_id ) {
				return self::taken( $name );
			}
			throw $e;
		}

		$this->audit->record( 'person', $person_id, 'email_changed', $by->user_id, array( 'email' => '' === $old ? null : $old ), array( 'email' => $email ) );
		/* translators: %s: Name der Person */
		$message = sprintf( __( 'E-Mail-Adresse von %s gespeichert.', 'ttleague' ), $name );
		if ( '' !== $old && false !== is_email( $old ) && $this->notify_previous( $person, $old, $email, $by ) ) {
			$message .= ' ' . __( 'Die bisherige Adresse wurde benachrichtigt.', 'ttleague' );
		}
		return Outcome::success( $message );
	}

	/**
	 * Person einladen (oder erneut einladen): Konto anlegen und Anmeldelink senden.
	 */
	public function invite( Actor $by, int $person_id ): Outcome {
		$person = $this->accounts->person( $person_id );
		if ( null === $person ) {
			return Outcome::error( __( 'Diese Person gibt es nicht.', 'ttleague' ) );
		}
		$name = self::name( $person );
		if ( ! $by->is_tk && ( null === $by->person_id || ! $this->policy->can_invite( $by->person_id, $person_id ) ) ) {
			return Outcome::error( __( 'Du kannst nur Personen deines Clubs einladen.', 'ttleague' ) );
		}
		$email = trim( (string) ( $person['email'] ?? '' ) );
		if ( '' === $email || false === is_email( $email ) ) {
			/* translators: %s: Name der Person */
			return Outcome::error( sprintf( __( 'Für %s ist keine gültige E-Mail-Adresse hinterlegt.', 'ttleague' ), $name ) );
		}
		$account = $this->account( $person );
		if ( null !== $account && ! $account['link_allowed'] ) {
			/* translators: %s: Name der Person */
			return Outcome::info( sprintf( __( '%s hat ein Konto mit erweiterten Rechten und meldet sich mit Passwort an. Eine Einladung per Link gibt es dafür nicht.', 'ttleague' ), $name ) );
		}
		if ( ! $by->is_tk && ! $this->limiter->hit( 'invite', (string) $by->user_id, self::INVITE_LIMIT, self::INVITE_WINDOW ) ) {
			/* translators: %d: Anzahl Einladungen */
			return Outcome::error( sprintf( __( 'Du hast in der letzten Stunde bereits %d Einladungen verschickt. Bitte versuche es später wieder.', 'ttleague' ), self::INVITE_LIMIT ) );
		}
		try {
			( $this->send_invitation )( $person_id, $by->user_id );
		} catch ( \RuntimeException $e ) {
			/* translators: 1: Name der Person, 2: Fehlermeldung */
			return Outcome::error( sprintf( __( 'Die Einladung an %1$s ist fehlgeschlagen: %2$s', 'ttleague' ), $name, $e->getMessage() ) );
		}
		/* translators: 1: Name der Person, 2: E-Mail-Adresse */
		return Outcome::success( sprintf( __( 'Einladung an %1$s (%2$s) verschickt.', 'ttleague' ), $name, $email ) );
	}

	/**
	 * Alle Captains und TK-Chefs eines Clubs ohne Konto einladen (nur TK).
	 */
	public function invite_officials( Actor $by, int $club_id ): Outcome {
		if ( ! $by->is_tk ) {
			return Outcome::error( __( 'Das kann nur die TK.', 'ttleague' ) );
		}
		$season = $this->policy->active_season_id();
		if ( null === $season ) {
			return Outcome::error( __( 'Es gibt keine aktive Saison.', 'ttleague' ) );
		}
		$invited = array();
		$details = array();
		foreach ( $this->people->for_club( $season, $club_id ) as $person ) {
			if ( ! ClubPeople::is_official( $person ) ) {
				continue;
			}
			if ( ClubPeople::NO_EMAIL === $person['status'] ) {
				/* translators: %s: Name der Person */
				$details[] = sprintf( __( '%s: keine E-Mail-Adresse', 'ttleague' ), $person['name'] );
				continue;
			}
			if ( ClubPeople::NO_ACCOUNT !== $person['status'] ) {
				continue;
			}
			$outcome = $this->invite( $by, $person['person_id'] );
			if ( $outcome->ok() ) {
				$invited[] = $person['name'];
			} else {
				$details[] = $outcome->message;
			}
		}
		if ( array() === $invited ) {
			return array() === $details
				? Outcome::info( __( 'Alle Captains und TK-Chefs dieses Clubs haben bereits ein Konto.', 'ttleague' ) )
				: Outcome::warning( __( 'Niemand eingeladen.', 'ttleague' ), $details );
		}
		$message = sprintf(
			/* translators: 1: Anzahl, 2: Namen */
			_n( '%1$d Einladung verschickt: %2$s.', '%1$d Einladungen verschickt: %2$s.', count( $invited ), 'ttleague' ),
			count( $invited ),
			implode( ', ', $invited )
		);
		return array() === $details ? Outcome::success( $message ) : Outcome::warning( $message, $details );
	}

	/**
	 * Grund, warum das handelnde Konto die Adresse nicht ändern darf, oder null.
	 *
	 * @param array{id:int,linked:bool,email:string,last_login:int|null,privileged:bool,admin:bool,link_allowed:bool}|null $account Konto der Person.
	 */
	private function email_denied( Actor $by, int $person_id, string $name, ?array $account ): ?string {
		if ( $by->is_tk ) {
			return null !== $account && $account['admin']
				/* translators: %s: Name der Person */
				? sprintf( __( '%s hat ein Administratorkonto. Dessen E-Mail-Adresse lässt sich nur in der Benutzerverwaltung ändern.', 'ttleague' ), $name )
				: null;
		}
		if ( null === $by->person_id || ! $this->policy->can_invite( $by->person_id, $person_id ) ) {
			return __( 'Du kannst nur E-Mail-Adressen von Personen deines Clubs ändern.', 'ttleague' );
		}
		if ( null !== $account && $account['privileged'] ) {
			/* translators: %s: Name der Person */
			return sprintf( __( '%s hat ein Konto mit erweiterten Rechten. Die E-Mail-Adresse ändert die TK.', 'ttleague' ), $name );
		}
		return null;
	}

	private static function taken( string $name ): Outcome {
		/* translators: %s: Name der Person */
		return Outcome::error( sprintf( __( 'Die E-Mail-Adresse für %s ist bereits bei einer anderen Person hinterlegt.', 'ttleague' ), $name ) );
	}

	/**
	 * Konto der Person: verknüpft, sonst über die hinterlegte E-Mail-Adresse.
	 *
	 * @param array<string,string|null> $person Person.
	 * @return array{id:int,linked:bool,email:string,last_login:int|null,privileged:bool,admin:bool,link_allowed:bool}|null
	 */
	private function account( array $person ): ?array {
		$linked = (int) ( $person['user_id'] ?? 0 );
		if ( $linked > 0 ) {
			$info = $this->users->lookup( array( $linked ) )[ $linked ] ?? null;
			if ( null !== $info ) {
				return array( 'id' => $linked ) + array( 'linked' => true ) + $info;
			}
		}
		$by_email = $this->users->find_by_email( (string) ( $person['email'] ?? '' ) );
		$info     = null === $by_email ? null : ( $this->users->lookup( array( $by_email ) )[ $by_email ] ?? null );
		return null === $info ? null : array( 'id' => (int) $by_email ) + array( 'linked' => false ) + $info;
	}

	/**
	 * Mitteilung an die bisherige Adresse.
	 *
	 * @param array<string,string|null> $person Person.
	 */
	private function notify_previous( array $person, string $old, string $address, Actor $by ): bool {
		$first  = AccountService::salutation( $person );
		$who    = '' !== $by->name ? $by->name : __( 'Die Technische Kommission', 'ttleague' );
		$masked = self::mask( $address );
		/* translators: 1: Person, die geändert hat, 2: Name der Seite, 3: neue Adresse (gekürzt) */
		$changed = sprintf( __( '%1$s hat deine E-Mail-Adresse bei %2$s geändert. Neue Adresse: %3$s', 'ttleague' ), $who, $this->site, $masked );
		$next    = __( 'Benachrichtigungen und Anmeldelinks gehen ab jetzt an die neue Adresse. Falls diese Änderung nicht stimmt, melde dich bitte bei deinem TK-Chef oder bei der TK.', 'ttleague' );
		/* translators: %s: Vorname */
		$hello = sprintf( __( 'Hallo %s', 'ttleague' ), $first );
		return (bool) ( $this->send_mail )(
			new Message(
				$old,
				/* translators: %s: Name der Seite */
				sprintf( __( 'Deine E-Mail-Adresse bei %s wurde geändert', 'ttleague' ), $this->site ),
				$hello . "\n\n" . $changed . "\n\n" . $next,
				'<p>' . esc_html( $hello ) . '</p><p>' . esc_html( $changed ) . '</p><p>' . esc_html( $next ) . '</p>',
				'email_changed'
			)
		);
	}

	/**
	 * Adresse gekürzt für Mitteilungen: «ha…@example.org».
	 */
	public static function mask( string $email ): string {
		$at = strrpos( $email, '@' );
		if ( false === $at ) {
			return '…';
		}
		$local = substr( $email, 0, $at );
		$keep  = mb_strlen( $local ) > 2 ? 2 : 1;
		return mb_substr( $local, 0, $keep ) . '…' . substr( $email, $at );
	}

	/**
	 * @param array<string,string|null> $person Person.
	 */
	private static function name( array $person ): string {
		return trim( (string) ( $person['first_name'] ?? '' ) . ' ' . (string) ( $person['last_name'] ?? '' ) );
	}
}
