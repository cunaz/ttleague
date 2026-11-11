<?php
/**
 * Verschiebung und Terminvorschlag: Antrag, Antwort der Gegenseite, Entscheid der TK.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Postponement;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Meldungen gehen als JSON (REST) oder über Flash hinaus und werden erst bei der Ausgabe escaped, wie in der Erfassung.

use TTLeague\Access\AccessPolicy;
use TTLeague\Domain\Match\PostponementRules as Rules;
use TTLeague\Frontend\Support\Format;
use TTLeague\Infrastructure\AuditLog;
use TTLeague\Infrastructure\Database\Db;
use TTLeague\Infrastructure\Time\Clock;
use TTLeague\Results\Actor;
use TTLeague\Results\FixtureContext;
use TTLeague\Results\ResultException;
use TTLeague\Results\ResultInput;
use TTLeague\Results\ResultRepository;

/**
 * Jeder Übergang aus docs/verschiebung.md läuft in einer Transaktion, die zuerst Saison und
 * Begegnung sperrt (wie die Erfassung) und dann Antrag, Rollen und Status neu liest. So gilt
 * von zwei gleichzeitigen Aktionen nur die erste, und es gibt höchstens einen offenen Antrag
 * pro Begegnung. Wird ein Antrag gutgeheissen, erhält die Begegnung den neuen Termin, einen
 * Eintrag im Verlauf (fixture_event «postponed») und im Änderungsprotokoll.
 *
 * Fehler kommen als ResultException mit denselben Codes wie in der Erfassung (403, 404, 409,
 * 422). Termine sind Ortszeit der Website, Zeitstempel UTC.
 */
final class PostponementService {

	/** Höchstlänge von Begründung und Bemerkung. */
	public const TEXT_MAX = 2000;

	/** Nach so vielen Tagen ohne Antwort erhält die Gegenseite eine Erinnerung. */
	public const REMINDER_DAYS = 3;

	/** Nach so vielen Tagen ohne Antwort erscheint der Antrag in der Übersicht der TK. */
	public const UNANSWERED_DAYS = 7;

	/** Entscheide der TK (REST und Formulare) => Aktion. */
	public const DECISIONS = array(
		'approve' => Rules::APPROVE,
		'reject'  => Rules::REJECT,
	);

	private PostponementRepository $repo;

	private ResultRepository $fixtures;

	private Parties $parties;

	/** @var callable(int):?string */
	private $user_names;

	/**
	 * @param callable(int):?string|null $user_names Anzeigename eines Kontos ohne Person.
	 */
	public function __construct(
		private readonly Db $db,
		private readonly AccessPolicy $policy,
		private readonly Clock $clock,
		private readonly \DateTimeZone $timezone,
		private readonly AuditLog $audit,
		private readonly PostponementEvents $events,
		?callable $user_names = null,
	) {
		$this->repo       = new PostponementRepository( $db );
		$this->fixtures   = new ResultRepository( $db );
		$this->parties    = new Parties( $policy );
		$this->user_names = $user_names ?? static fn(): ?string => null;
	}

	/**
	 * Offener Antrag, Verlauf und was die Person darf (GET fixtures/{id}/postponement).
	 *
	 * @return array<string,mixed>
	 */
	public function overview( int $fixture_id, Actor $actor ): array {
		$ctx   = $this->context( $fixture_id );
		$sides = $this->parties->sides( $ctx, $actor->person_id );
		if ( ! $actor->is_tk && array() === $sides ) {
			throw ResultException::forbidden( $this->no_role_message() );
		}
		$rows    = $this->repo->for_fixture( $fixture_id );
		$names   = $this->names( $rows );
		$open    = null;
		$history = array();
		foreach ( $rows as $row ) {
			if ( null === $open && in_array( $row['status'], Rules::OPEN, true ) ) {
				$open = $row;
				continue;
			}
			$history[] = $this->view( $row, $ctx, $names );
		}
		$dates = $this->repo->season_dates( $ctx->season_id );
		$kind  = Rules::kind( $ctx->scheduled_at );
		return array(
			'fixture' => $this->fixture_payload( $ctx ),
			'kind'    => $kind,
			'open'    => null === $open ? null : $this->view( $open, $ctx, $names ),
			'history' => $history,
			'can'     => array( 'request' => null === $open && $this->effective( $ctx ) && Rules::may_request( $kind, $sides, $actor->is_tk ) )
				+ $this->can( $open, $ctx, $actor ),
			'rules'   => array(
				'approval_days' => $ctx->rules->postponement_approval_days,
				'season_start'  => $dates['start'] ?? null,
				'season_end'    => $dates['end'] ?? null,
			),
		);
	}

	/**
	 * Antrag stellen: neuer Termin (Ortszeit) und Begründung. Stellt ihn die TK, gilt er sofort.
	 *
	 * @param array<string,mixed> $payload new_date, reason.
	 * @return array<string,mixed> Antrag (wie in overview) mit «fixture».
	 */
	public function request( int $fixture_id, Actor $actor, array $payload ): array {
		$ctx = $this->context( $fixture_id );
		$row = $this->db->transaction(
			function () use ( $ctx, $actor, $payload ): array {
				$this->fixtures->lock( $ctx->season_id, $ctx->id );
				$current = $this->context( $ctx->id );
				$sides   = $this->parties->sides( $current, $actor->person_id );
				$kind    = Rules::kind( $current->scheduled_at );
				if ( ! $actor->is_tk && array() === $sides ) {
					throw ResultException::forbidden( $this->no_role_message() );
				}
				$this->require_effective( $current );
				if ( ! Rules::may_request( $kind, $sides, $actor->is_tk ) ) {
					throw ResultException::forbidden( __( 'Den Termin schlägt die Heimmannschaft vor.', 'ttleague' ) );
				}
				if ( null !== $this->repo->open_for_fixture( $current->id ) ) {
					throw ResultException::conflict( __( 'Für diese Begegnung gibt es schon einen offenen Antrag.', 'ttleague' ) );
				}
				[ $new_date, $reason ] = $this->input( $current, $kind, $payload );

				$now    = $this->now();
				$status = Rules::initial_status( $actor->is_tk );
				$data   = array(
					'fixture_id'   => $current->id,
					'requested_by' => $actor->user_id,
					'kind'         => $kind,
					'old_date'     => $current->scheduled_at,
					'new_date'     => $new_date,
					'status'       => $status,
					'reason'       => $reason,
					'created_at'   => $now,
					'updated_at'   => $now,
				);
				if ( Rules::APPROVED === $status ) {
					$data['approved_by'] = $actor->user_id;
					$data['approved_at'] = $now;
				}
				$row = $this->find( $this->repo->insert( $data ) );
				$this->audit->record( 'postponement', $row['id'], $status, $actor->user_id, null, $row );
				if ( Rules::APPROVED === $status ) {
					$this->apply( $row, $current, $actor, $now );
				}
				return $row;
			}
		);
		$this->events->changed( $row['id'], 'request', $actor->user_id, $actor->person_id );
		return $this->result( $row );
	}

	/**
	 * Zustimmung der Gegenseite. Der Antrag gilt damit, ausser die TK muss bewilligen.
	 *
	 * @return array<string,mixed>
	 */
	public function accept( int $postponement_id, Actor $actor ): array {
		return $this->transition( $postponement_id, $actor, Rules::ACCEPT, null );
	}

	/**
	 * Ablehnung der Gegenseite, Bemerkung freiwillig.
	 *
	 * @return array<string,mixed>
	 */
	public function decline( int $postponement_id, Actor $actor, ?string $note = null ): array {
		return $this->transition( $postponement_id, $actor, Rules::DECLINE, $this->note( $note, 'note', false ) );
	}

	/**
	 * Rückzug durch den Antragsteller, solange niemand geantwortet hat.
	 *
	 * @return array<string,mixed>
	 */
	public function withdraw( int $postponement_id, Actor $actor ): array {
		return $this->transition( $postponement_id, $actor, Rules::WITHDRAW, null );
	}

	/**
	 * Entscheid der TK: bewilligen oder ablehnen (Bemerkung Pflicht), auch an Stelle der Gegenseite.
	 *
	 * @param array<string,mixed> $payload action (approve, reject), comment.
	 * @return array<string,mixed>
	 */
	public function decide( int $postponement_id, Actor $actor, array $payload ): array {
		if ( ! $actor->is_tk ) {
			throw ResultException::forbidden( __( 'Über eine Verschiebung entscheidet die TK.', 'ttleague' ) );
		}
		// Erst prüfen, ob es den Antrag gibt: 404 vor einem Fehler in der Eingabe.
		$this->find( $postponement_id );
		$action = is_string( $payload['action'] ?? null ) ? $payload['action'] : '';
		if ( ! isset( self::DECISIONS[ $action ] ) ) {
			throw ResultException::invalid( array( ResultInput::error( 'action', __( 'Unbekannte Entscheidung.', 'ttleague' ) ) ) );
		}
		$comment = $this->note( $payload['comment'] ?? null, 'comment', 'reject' === $action );
		return $this->transition( $postponement_id, $actor, self::DECISIONS[ $action ], $comment );
	}

	/**
	 * Begegnung eines Antrags (für die Rechteprüfung der WordPress-Schicht).
	 *
	 * @throws ResultException Wenn es den Antrag nicht gibt.
	 */
	public function fixture_of( int $postponement_id ): int {
		return $this->find( $postponement_id )['fixture_id'];
	}

	/**
	 * Für «Mein Bereich»: offene Anträge zu Begegnungen der Person (zuerst die, die sie
	 * beantworten kann) und Begegnungen ohne Termin.
	 *
	 * @return array{requests:list<array<string,mixed>>,unscheduled:list<array<string,mixed>>}
	 */
	public function mine( Actor $actor ): array {
		$result = array(
			'requests'    => array(),
			'unscheduled' => array(),
		);
		$season = $this->policy->active_season_id();
		if ( null === $actor->person_id || null === $season ) {
			return $result;
		}
		foreach ( $this->repo->open_in_season( $season ) as $row ) {
			$ctx = $this->fixtures->context( $row['fixture_id'] );
			if ( null === $ctx || array() === $this->parties->sides( $ctx, $actor->person_id ) ) {
				continue;
			}
			$result['requests'][] = $this->item( $row, $ctx, $actor );
		}
		// Zuerst, was die Person beantworten kann.
		usort(
			$result['requests'],
			static fn( array $a, array $b ): int => array( $b['can']['accept'], $a['postponement']['id'] ) <=> array( $a['can']['accept'], $b['postponement']['id'] )
		);

		foreach ( $this->repo->unscheduled( $season, $this->clubs( $actor->person_id, $season ) ) as $fixture_id ) {
			$ctx = $this->fixtures->context( $fixture_id );
			if ( null === $ctx || null !== $this->repo->open_for_fixture( $fixture_id ) ) {
				continue;
			}
			$sides = $this->parties->sides( $ctx, $actor->person_id );
			if ( array() === $sides ) {
				continue;
			}
			$result['unscheduled'][] = array(
				'fixture' => $this->fixture_payload( $ctx ),
				'side'    => $sides[0],
				'can'     => array( 'request' => Rules::may_request( Rules::PROPOSE, $sides, $actor->is_tk ) ),
			);
		}
		return $result;
	}

	/**
	 * Für die Übersicht der TK: Anträge, die sie bewilligen muss, und Anträge, die die Gegenseite
	 * seit UNANSWERED_DAYS Tagen nicht beantwortet hat. Nur aktive Saison.
	 *
	 * @return array{to_approve:list<array<string,mixed>>,unanswered:list<array<string,mixed>>}
	 */
	public function for_tk( Actor $actor ): array {
		$result = array(
			'to_approve' => array(),
			'unanswered' => array(),
		);
		if ( ! $actor->is_tk ) {
			return $result;
		}
		$limit = $this->clock->now()->modify( '-' . self::UNANSWERED_DAYS . ' days' )->format( 'Y-m-d H:i:s' );
		foreach ( $this->repo->active_seasons() as $season ) {
			foreach ( $this->repo->open_in_season( $season ) as $row ) {
				$accepted = Rules::ACCEPTED === $row['status'];
				if ( ! $accepted && $row['created_at'] > $limit ) {
					continue;
				}
				$ctx = $this->fixtures->context( $row['fixture_id'] );
				if ( null === $ctx ) {
					continue;
				}
				$result[ $accepted ? 'to_approve' : 'unanswered' ][] = $this->item( $row, $ctx, $actor );
			}
		}
		return $result;
	}

	/**
	 * Muss die TK diesen Antrag nach der Zustimmung bewilligen?
	 *
	 * @param array<string,mixed> $row Antrag.
	 */
	public function needs_approval( array $row, FixtureContext $ctx ): bool {
		return Rules::needs_approval( $row['kind'], $row['old_date'], $row['new_date'], $ctx->rules->postponement_approval_days, $this->repo->deadline( $ctx->id ) );
	}

	/**
	 * @throws ResultException Wenn die Begegnung fehlt.
	 */
	public function context( int $fixture_id ): FixtureContext {
		$ctx = $this->fixtures->context( $fixture_id );
		if ( null === $ctx ) {
			throw ResultException::not_found( __( 'Diese Begegnung gibt es nicht.', 'ttleague' ) );
		}
		return $ctx;
	}

	/**
	 * @return array<string,mixed>
	 * @throws ResultException Wenn es den Antrag nicht gibt.
	 */
	public function find( int $postponement_id ): array {
		$row = $this->repo->find( $postponement_id );
		if ( null === $row ) {
			throw ResultException::not_found( __( 'Diesen Antrag gibt es nicht.', 'ttleague' ) );
		}
		return $row;
	}

	/**
	 * Antwort der Gegenseite, Rückzug oder Entscheid der TK.
	 *
	 * @return array<string,mixed>
	 */
	private function transition( int $postponement_id, Actor $actor, string $action, ?string $note ): array {
		$fixture_id = $this->find( $postponement_id )['fixture_id'];
		$ctx        = $this->context( $fixture_id );
		$after      = $this->db->transaction(
			function () use ( $postponement_id, $ctx, $actor, $action, $note ): array {
				$this->fixtures->lock( $ctx->season_id, $ctx->id );
				$row     = $this->find( $postponement_id );
				$current = $this->context( $ctx->id );
				$roles   = $this->parties->roles( $row, $current, $actor );
				if ( ! Rules::role_may( $action, $roles ) ) {
					throw ResultException::forbidden( $this->role_message( $action, $row, $actor ) );
				}
				if ( ! Rules::allows( $action, $row['status'], $roles ) ) {
					throw ResultException::conflict(
						/* translators: %s: Status des Antrags */
						sprintf( __( 'Der Antrag ist «%s». Das ist nicht mehr möglich.', 'ttleague' ), self::status_label( $row['status'] ) )
					);
				}
				$needs = false;
				if ( in_array( $action, array( Rules::ACCEPT, Rules::APPROVE ), true ) ) {
					$this->require_effective( $current );
					if ( $row['old_date'] !== $current->scheduled_at ) {
						throw ResultException::conflict( __( 'Der Termin der Begegnung hat sich seit dem Antrag geändert. Der Antrag kann nur noch abgelehnt oder zurückgezogen werden.', 'ttleague' ) );
					}
					if ( Rules::ACCEPT === $action ) {
						if ( $row['new_date'] <= $this->local_now() ) {
							throw ResultException::conflict( __( 'Der vorgeschlagene Termin ist schon vorbei.', 'ttleague' ) );
						}
						$needs = $this->needs_approval( $row, $current );
					}
				}

				$now    = $this->now();
				$status = Rules::target( $action, $needs );
				$data   = array(
					'status'     => $status,
					'updated_at' => $now,
				);
				switch ( $action ) {
					case Rules::DECLINE:
						$data['response_note'] = $note;
						$data['responded_at']  = $now;
						break;
					case Rules::ACCEPT:
						$data['accepted_by']  = $actor->user_id;
						$data['responded_at'] = $now;
						if ( Rules::APPROVED === $status ) {
							$data['approved_at'] = $now;
						}
						break;
					case Rules::APPROVE:
					case Rules::REJECT:
						$data['approved_by']   = $actor->user_id;
						$data['approved_at']   = $now;
						$data['response_note'] = $note;
						break;
				}
				$this->repo->update( $postponement_id, $data );
				$after = $this->find( $postponement_id );
				$this->audit->record( 'postponement', $postponement_id, $status, $actor->user_id, $row, $after, $note );
				if ( Rules::APPROVED === $status ) {
					$this->apply( $after, $current, $actor, $now );
				}
				return $after;
			}
		);
		$this->events->changed( $postponement_id, $action, $actor->user_id, $actor->person_id );
		return $this->result( $after );
	}

	/**
	 * Wirkung eines gutgeheissenen Antrags: neuer Termin, Status, Verlauf und Protokoll.
	 *
	 * @param array<string,mixed> $row Antrag (approved).
	 */
	private function apply( array $row, FixtureContext $ctx, Actor $actor, string $now ): void {
		$status = Rules::fixture_status( $row['kind'] );
		$before = array(
			'status'       => $ctx->status,
			'scheduled_at' => $ctx->scheduled_at,
		);
		$after  = array(
			'status'       => $status,
			'scheduled_at' => $row['new_date'],
		);
		$this->fixtures->update_fixture( $ctx->id, $after );
		$this->fixtures->add_event(
			$ctx->id,
			'postponed',
			$actor->user_id,
			$actor->person_id,
			self::event_comment( $row ),
			array(
				'action'          => $row['kind'],
				'postponement_id' => $row['id'],
				'old_date'        => $row['old_date'],
				'new_date'        => $row['new_date'],
			),
			$now
		);
		$this->audit->record( 'fixture', $ctx->id, Rules::PROPOSE === $row['kind'] ? 'date_set' : 'postponed', $actor->user_id, $before, $after + array( 'postponement_id' => $row['id'] ), $row['reason'] );
	}

	/**
	 * Text im Verlauf der Begegnung: neuer und bisheriger Termin, dazu die Begründung.
	 *
	 * @param array<string,mixed> $row Antrag.
	 */
	private static function event_comment( array $row ): string {
		$text = null === $row['old_date']
			/* translators: %s: Datum und Zeit */
			? sprintf( __( 'Termin: %s.', 'ttleague' ), Format::datetime( $row['new_date'] ) )
			/* translators: 1: neuer Termin, 2: bisheriger Termin */
			: sprintf( __( 'Neuer Termin: %1$s (bisher %2$s).', 'ttleague' ), Format::datetime( $row['new_date'] ), Format::datetime( $row['old_date'] ) );
		return null === $row['reason'] || '' === $row['reason'] ? $text : $text . ' ' . $row['reason'];
	}

	/**
	 * Was die Person mit dem offenen Antrag tun kann.
	 *
	 * @param array<string,mixed>|null $row Offener Antrag oder null.
	 * @return array{accept:bool,decline:bool,withdraw:bool,approve:bool,reject:bool}
	 */
	private function can( ?array $row, FixtureContext $ctx, Actor $actor ): array {
		$can = array(
			'accept'   => false,
			'decline'  => false,
			'withdraw' => false,
			'approve'  => false,
			'reject'   => false,
		);
		if ( null === $row ) {
			return $can;
		}
		$roles     = $this->parties->roles( $row, $ctx, $actor );
		$effective = $this->effective( $ctx ) && $row['old_date'] === $ctx->scheduled_at;
		foreach ( array_keys( $can ) as $action ) {
			$can[ $action ] = Rules::allows( $action, $row['status'], $roles );
		}
		$can['accept']  = $can['accept'] && $effective && $row['new_date'] > $this->local_now();
		$can['approve'] = $can['approve'] && $effective;
		return $can;
	}

	/**
	 * Clubs, für die die Person in der Saison eine Funktion hat oder im Cup spielt.
	 *
	 * @return list<int>
	 */
	private function clubs( int $person_id, int $season_id ): array {
		$roles = $this->policy->roles( $person_id, $season_id );
		$clubs = $roles['tk_chef_clubs'];
		foreach ( $roles['captain_teams'] as $team_id ) {
			$clubs[] = (int) $this->db->get_var( "SELECT club_id FROM {$this->db->table( 'team' )} WHERE id = %d", array( $team_id ) );
		}
		foreach ( $roles['cup_entries'] as $entry_id ) {
			$clubs[] = (int) $this->db->get_var( "SELECT club_id FROM {$this->db->table( 'cup_entry' )} WHERE id = %d", array( $entry_id ) );
		}
		return array_values( array_unique( array_filter( array_map( 'intval', $clubs ) ) ) );
	}

	/**
	 * Ist ein Antrag für die Begegnung möglich bzw. wirksam? Nur in der aktiven Saison und nur
	 * solange kein Resultat erfasst ist.
	 */
	private function effective( FixtureContext $ctx ): bool {
		return 'active' === $ctx->season_status && Rules::fixture_open( $ctx->status );
	}

	/**
	 * @throws ResultException 409, wenn die Saison nicht aktiv oder die Begegnung nicht mehr offen ist.
	 */
	private function require_effective( FixtureContext $ctx ): void {
		if ( 'planned' === $ctx->season_status ) {
			throw ResultException::conflict( __( 'Die Saison dieser Begegnung ist noch nicht freigegeben. Der Spielplanentwurf wird im Saison-Assistenten bearbeitet.', 'ttleague' ) );
		}
		if ( 'active' !== $ctx->season_status ) {
			throw ResultException::conflict( __( 'Die Saison dieser Begegnung ist abgeschlossen.', 'ttleague' ) );
		}
		if ( ! Rules::fixture_open( $ctx->status ) ) {
			throw ResultException::conflict( __( 'Für diese Begegnung ist schon ein Resultat erfasst oder sie ist abgesagt. Der Termin lässt sich nicht mehr ändern.', 'ttleague' ) );
		}
	}

	/**
	 * Neuer Termin und Begründung aus der Eingabe.
	 *
	 * @param array<string,mixed> $payload Eingabe.
	 * @return array{0:string,1:string|null} Termin «Y-m-d H:i:s» (Ortszeit) und Begründung.
	 * @throws ResultException 422 mit Feldfehlern.
	 */
	private function input( FixtureContext $ctx, string $kind, array $payload ): array {
		$errors = array();
		$date   = self::parse_date( $payload['new_date'] ?? null, $this->timezone );
		if ( null === $date ) {
			$errors[] = ResultInput::error( 'new_date', __( 'Bitte gib den neuen Termin mit Datum und Zeit an.', 'ttleague' ) );
		} else {
			$dates = $this->repo->season_dates( $ctx->season_id );
			$day   = substr( $date, 0, 10 );
			if ( $date === $ctx->scheduled_at ) {
				$errors[] = ResultInput::error( 'new_date', __( 'Das ist der bisherige Termin.', 'ttleague' ) );
			} elseif ( $date <= $this->local_now() ) {
				$errors[] = ResultInput::error( 'new_date', __( 'Der neue Termin muss in der Zukunft liegen.', 'ttleague' ) );
			} elseif ( null !== $dates && ( $day < $dates['start'] || $day > $dates['end'] ) ) {
				$errors[] = ResultInput::error(
					'new_date',
					/* translators: 1: Beginn der Saison, 2: Ende der Saison */
					sprintf( __( 'Der neue Termin muss in der Saison liegen (%1$s bis %2$s).', 'ttleague' ), Format::date_plain( $dates['start'] ), Format::date_plain( $dates['end'] ) )
				);
			}
		}
		$reason = ResultInput::text( $payload['reason'] ?? null );
		if ( null === $reason && Rules::POSTPONE === $kind ) {
			$errors[] = ResultInput::error( 'reason', __( 'Bitte begründe die Verschiebung.', 'ttleague' ) );
		} elseif ( null !== $reason && mb_strlen( $reason ) > self::TEXT_MAX ) {
			/* translators: %d: Zeichen */
			$errors[] = ResultInput::error( 'reason', sprintf( __( 'Die Begründung ist zu lang (höchstens %d Zeichen).', 'ttleague' ), self::TEXT_MAX ) );
		}
		if ( array() !== $errors ) {
			throw ResultException::invalid( $errors );
		}
		return array( (string) $date, $reason );
	}

	/**
	 * Termin aus der Eingabe: «2026-11-24 20:00» oder «2026-11-24T20:00» (Feld datetime-local),
	 * auch mit Sekunden. Liefert «Y-m-d H:i:00» oder null, wenn es den Zeitpunkt in der
	 * Zeitzone nicht gibt (ungültiges Datum, Stunde der Zeitumstellung).
	 */
	public static function parse_date( mixed $value, \DateTimeZone $timezone ): ?string {
		if ( ! is_string( $value ) || 1 !== preg_match( '/^(\d{4}-\d{2}-\d{2})[ T](\d{2}:\d{2})(?::\d{2})?$/', trim( $value ), $m ) ) {
			return null;
		}
		$local = $m[1] . ' ' . $m[2];
		$time  = \DateTimeImmutable::createFromFormat( '!Y-m-d H:i', $local, $timezone );
		if ( false === $time || $time->format( 'Y-m-d H:i' ) !== $local ) {
			return null;
		}
		return $local . ':00';
	}

	/**
	 * Bemerkung zu Ablehnung oder Entscheid.
	 *
	 * @throws ResultException 422, wenn sie fehlt (Pflicht) oder zu lang ist.
	 */
	private function note( mixed $value, string $field, bool $required ): ?string {
		$note = ResultInput::text( $value );
		if ( null === $note && $required ) {
			throw ResultException::invalid( array( ResultInput::error( $field, __( 'Bitte begründe die Ablehnung in der Bemerkung.', 'ttleague' ) ) ) );
		}
		if ( null !== $note && mb_strlen( $note ) > self::TEXT_MAX ) {
			/* translators: %d: Zeichen */
			throw ResultException::invalid( array( ResultInput::error( $field, sprintf( __( 'Die Bemerkung ist zu lang (höchstens %d Zeichen).', 'ttleague' ), self::TEXT_MAX ) ) ) );
		}
		return $note;
	}

	/**
	 * Antrag mit Begegnung und Rechten der Person, für Listen.
	 *
	 * @param array<string,mixed> $row Antrag.
	 * @return array{postponement:array<string,mixed>,fixture:array<string,mixed>,can:array<string,bool>}
	 */
	private function item( array $row, FixtureContext $ctx, Actor $actor ): array {
		return array(
			'postponement' => $this->view( $row, $ctx, $this->names( array( $row ) ) ),
			'fixture'      => $this->fixture_payload( $ctx ),
			'can'          => $this->can( $row, $ctx, $actor ),
		);
	}

	/**
	 * Antwort der schreibenden Aktionen: der Antrag und der Stand der Begegnung.
	 *
	 * @param array<string,mixed> $row Antrag.
	 * @return array<string,mixed>
	 */
	private function result( array $row ): array {
		$ctx = $this->context( $row['fixture_id'] );
		return $this->view( $row, $ctx, $this->names( array( $row ) ) ) + array( 'fixture' => $this->fixture_payload( $ctx ) );
	}

	/**
	 * Antrag für die Ausgabe (REST, Formulare).
	 *
	 * @param array<string,mixed> $row   Antrag.
	 * @param array<int,string>   $names Konto => Name.
	 * @return array<string,mixed>
	 */
	private function view( array $row, FixtureContext $ctx, array $names ): array {
		$open    = in_array( $row['status'], Rules::OPEN, true );
		$parties = $this->parties->of( $row, $ctx );
		$name    = fn( ?int $user_id ): ?string => null === $user_id ? null : ( $names[ $user_id ] ?? ( $this->user_names )( $user_id ) );
		return array(
			'id'             => $row['id'],
			'fixture_id'     => $row['fixture_id'],
			'kind'           => $row['kind'],
			'status'         => $row['status'],
			'status_label'   => self::status_label( $row['status'] ),
			'old_date'       => $row['old_date'],
			'new_date'       => $row['new_date'],
			'reason'         => $row['reason'],
			'requested_by'   => $name( $row['requested_by'] ),
			'requested_at'   => self::iso( $row['created_at'] ),
			'requester_side' => 1 === count( $parties['requester'] ) ? $parties['requester'][0] : null,
			'accepted_by'    => $name( $row['accepted_by'] ),
			'responded_at'   => self::iso( $row['responded_at'] ),
			'response_note'  => $row['response_note'],
			'decided_by'     => $name( $row['approved_by'] ),
			'decided_at'     => self::iso( $row['approved_at'] ),
			// Bei einem offenen Antrag: Braucht er nach der Zustimmung die Bewilligung der TK?
			'needs_approval' => $open ? $this->needs_approval( $row, $ctx ) : false,
		);
	}

	/**
	 * @return array<string,mixed>
	 */
	private function fixture_payload( FixtureContext $ctx ): array {
		return array(
			'id'           => $ctx->id,
			'type'         => $ctx->type,
			'status'       => $ctx->status,
			'scheduled_at' => $ctx->scheduled_at,
			'competition'  => $ctx->competition,
			'home'         => $ctx->home['name'],
			'away'         => $ctx->away['name'],
			'deadline'     => $ctx->is_cup() ? $this->repo->deadline( $ctx->id ) : null,
		);
	}

	/**
	 * Namen der beteiligten Konten.
	 *
	 * @param list<array<string,mixed>> $rows Anträge.
	 * @return array<int,string>
	 */
	private function names( array $rows ): array {
		$ids = array();
		foreach ( $rows as $row ) {
			array_push( $ids, (int) $row['requested_by'], (int) $row['accepted_by'], (int) $row['approved_by'] );
		}
		return $this->repo->names_for_users( $ids );
	}

	private function no_role_message(): string {
		return __( 'Termine verschieben die beiden Mannschaften (Captain oder TK-Chef) und die TK.', 'ttleague' );
	}

	/**
	 * Meldung, wenn der Person die Rolle für die Aktion fehlt.
	 *
	 * @param array<string,mixed> $row Antrag.
	 */
	private function role_message( string $action, array $row, Actor $actor ): string {
		if ( in_array( $action, array( Rules::APPROVE, Rules::REJECT ), true ) ) {
			return __( 'Über eine Verschiebung entscheidet die TK.', 'ttleague' );
		}
		if ( Rules::WITHDRAW === $action ) {
			return __( 'Zurückziehen kann den Antrag nur, wer ihn gestellt hat.', 'ttleague' );
		}
		if ( $actor->user_id === $row['requested_by'] ) {
			return __( 'Wer den Antrag gestellt hat, kann ihn nicht selbst beantworten.', 'ttleague' );
		}
		return __( 'Den Antrag beantwortet die Gegenseite (Captain oder TK-Chef).', 'ttleague' );
	}

	public static function status_label( string $status ): string {
		return match ( $status ) {
			Rules::REQUESTED => __( 'beantragt', 'ttleague' ),
			Rules::ACCEPTED  => __( 'zugestimmt, Bewilligung der TK offen', 'ttleague' ),
			Rules::APPROVED  => __( 'gutgeheissen', 'ttleague' ),
			Rules::DECLINED  => __( 'von der Gegenseite abgelehnt', 'ttleague' ),
			Rules::WITHDRAWN => __( 'zurückgezogen', 'ttleague' ),
			Rules::REJECTED  => __( 'von der TK abgelehnt', 'ttleague' ),
			default          => $status,
		};
	}

	private function now(): string {
		return $this->clock->now()->format( 'Y-m-d H:i:s' );
	}

	/**
	 * Jetzt in Ortszeit der Website, vergleichbar mit Terminen.
	 */
	private function local_now(): string {
		return $this->clock->now()->setTimezone( $this->timezone )->format( 'Y-m-d H:i:s' );
	}

	private static function iso( ?string $utc ): ?string {
		return null === $utc || '' === $utc ? null : str_replace( ' ', 'T', $utc ) . 'Z';
	}
}
