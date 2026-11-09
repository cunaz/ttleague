<?php
/**
 * Resultaterfassung: erfassen, bestätigen, Einsprache, Entscheide der TK, Foto, eigene Spiele.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Results;

use TTLeague\Access\AccessPolicy;
use TTLeague\Domain\Match\FixtureWorkflow as Flow;
use TTLeague\Domain\Match\Side;
use TTLeague\Domain\Match\TeamMatchResult;
use TTLeague\Infrastructure\AuditLog;
use TTLeague\Infrastructure\Database\Db;
use TTLeague\Infrastructure\Time\Clock;

/**
 * Prüft Rechte (Rolle aus den Daten der aktiven Saison) und Status (FixtureWorkflow), schreibt
 * Aufstellung, Spiele, Verlauf (fixture_event) und Änderungsprotokoll (audit_log) in einer
 * Transaktion und meldet danach das Ereignis. Zeitstempel sind UTC, Spieltermine Ortszeit.
 */
final class ResultService {

	public const DECISIONS = array(
		'confirm'  => Flow::CONFIRM,
		'finalize' => Flow::FINALIZE,
		'correct'  => Flow::CORRECT,
		'forfeit'  => Flow::FORFEIT_,
		'reset'    => Flow::RESET,
	);

	private ResultRepository $repo;

	private EligibilityLoader $eligibility;

	/** @var callable(int):?string */
	private $user_names;

	/**
	 * @param callable(int):?string|null $user_names Anzeigename eines Kontos (für Einträge ohne Person).
	 */
	public function __construct(
		private readonly Db $db,
		private readonly AccessPolicy $policy,
		private readonly Clock $clock,
		private readonly \DateTimeZone $timezone,
		private readonly AuditLog $audit,
		private readonly ResultEvents $events,
		?callable $user_names = null,
	) {
		$this->repo        = new ResultRepository( $db );
		$this->eligibility = new EligibilityLoader( $db );
		$this->user_names  = $user_names ?? static fn(): ?string => null;
	}

	public function repository(): ResultRepository {
		return $this->repo;
	}

	/**
	 * Rollen der Person für diese Begegnung.
	 *
	 * @return list<string>
	 */
	public function roles( FixtureContext $ctx, Actor $actor ): array {
		$roles = array();
		if ( null !== $actor->person_id ) {
			if ( $this->policy->can_enter_result( $actor->person_id, $ctx->id ) ) {
				$roles[] = Flow::ROLE_HOME;
			}
			if ( $this->policy->can_confirm_result( $actor->person_id, $ctx->id ) ) {
				$roles[] = Flow::ROLE_AWAY;
			}
		}
		if ( $actor->is_tk ) {
			$roles[] = Flow::ROLE_TK;
		}
		return $roles;
	}

	/**
	 * Alles für das Erfassungsformular.
	 *
	 * @return array<string,mixed>
	 */
	public function entry( int $fixture_id, Actor $actor ): array {
		$ctx   = $this->context( $fixture_id );
		$roles = $this->roles( $ctx, $actor );
		if ( array() === $roles ) {
			throw ResultException::forbidden( __( 'Du kannst diese Begegnung nicht bearbeiten.', 'ttleague' ) );
		}
		$sides  = $this->rosters( $ctx );
		$lineup = $this->repo->lineup( $fixture_id );
		$events = $this->repo->events( $fixture_id );

		// Hinweise der letzten Erfassung; Forfait und Zurücksetzen verwerfen sie mit dem Resultat.
		$warnings = array();
		foreach ( $events as $event ) {
			if ( in_array( $event['type'], array( 'forfeit', 'reset' ), true ) ) {
				$warnings = array();
			} elseif ( in_array( $event['type'], array( 'entered', 'edited', 'decided' ), true ) && isset( $event['data']['warnings'] ) ) {
				$warnings = (array) $event['data']['warnings'];
			}
		}

		return array(
			'fixture'    => $this->fixture_payload( $ctx ),
			'can'        => array(
				'enter'   => Flow::allows( Flow::ENTER, $ctx->status, $roles ),
				'confirm' => Flow::allows( Flow::CONFIRM, $ctx->status, $roles ) && ( $actor->is_tk || $ctx->entered_by !== $actor->user_id ),
				'object'  => Flow::allows( Flow::OBJECT, $ctx->status, $roles ),
				'decide'  => in_array( Flow::ROLE_TK, $roles, true ),
				'photo'   => $this->may_attach_photo( $ctx, $roles ),
			),
			'roles'      => $roles,
			'rules'      => array(
				'sets_to_win'          => $ctx->rules->sets_to_win,
				'set_points_enabled'   => $ctx->rules->set_points_enabled,
				'cup_mode'             => $ctx->rules->cup_mode,
				'substitute_max'       => $ctx->rules->substitute_max,
				'entry_deadline_hours' => $ctx->rules->entry_deadline_hours,
				'slots'                => array(
					'home' => $ctx->slots( 'home' ),
					'away' => $ctx->slots( 'away' ),
				),
			),
			'order'      => $ctx->order(),
			'candidates' => array(
				'home' => EligibilityLoader::candidates( $sides['home']->players, static fn( int $id ) => $sides['home']->check( $id ) ),
				'away' => EligibilityLoader::candidates( $sides['away']->players, static fn( int $id ) => $sides['away']->check( $id ) ),
			),
			'current'    => array(
				'lineup'       => array(
					'home' => (object) $lineup['home'],
					'away' => (object) $lineup['away'],
				),
				'substitutes'  => $lineup['substitutes'],
				'games'        => $this->repo->games( $fixture_id ),
				'entered_by'   => $this->name_for_user( $ctx->entered_by, $events, array( 'entered', 'edited' ) ),
				'entered_at'   => self::iso( $ctx->entered_at ),
				'confirmed_by' => $this->name_for_user( $ctx->confirmed_by, $events, array( 'confirmed', 'decided', 'forfeit' ) ),
				'confirmed_at' => self::iso( $ctx->confirmed_at ),
				'warnings'     => $warnings,
				'photo_id'     => $ctx->photo_id,
			),
			'history'    => $this->history( $events ),
			'version'    => $ctx->version(),
		);
	}

	/**
	 * Resultat erfassen oder ändern (Heimseite oder TK).
	 *
	 * @param array<string,mixed> $payload Eingabe wie in docs/erfassung.md.
	 * @return array{status:string,home_score:int,away_score:int,warnings:list<string>,version:string}
	 */
	public function submit( int $fixture_id, array $payload, Actor $actor ): array {
		$ctx = $this->context( $fixture_id );
		$this->authorize( $ctx, $actor, Flow::ENTER );
		$input = $this->input( $ctx, $payload );
		$this->check_version( $ctx, $input->version );

		$validated = $this->validator( $ctx, $input )->validate( $input );
		$warnings  = array_merge( $validated->warnings, $this->timing_warnings( $ctx ) );
		$edited    = Flow::PROVISIONAL === $ctx->status;
		$now       = $this->now();

		$this->write(
			$ctx,
			function () use ( $ctx, $validated, $warnings, $edited, $now, $actor, $input ): void {
				$before  = $this->repo->snapshot( $ctx->id );
				$players = $this->repo->lineup_players( $ctx->id );
				$this->repo->save_result( $ctx->id, $validated, $ctx->scheduled_at );
				$this->repo->update_fixture(
					$ctx->id,
					array(
						'status'         => Flow::PROVISIONAL,
						'home_score'     => $validated->result->home_score,
						'away_score'     => $validated->result->away_score,
						'entered_by'     => $actor->user_id,
						'entered_at'     => $now,
						'confirmed_by'   => null,
						'confirmed_at'   => null,
						'confirmed_auto' => 0,
					)
				);
				$this->sync_bindings( $ctx, array_merge( $players, $this->repo->lineup_players( $ctx->id ) ) );
				$this->repo->add_event(
					$ctx->id,
					$edited ? 'edited' : 'entered',
					$actor->user_id,
					$actor->person_id,
					$input->comment,
					array(
						'score'    => $validated->result->home_score . ':' . $validated->result->away_score,
						'warnings' => $warnings,
					),
					$now
				);
				$this->audit->record( 'fixture', $ctx->id, $edited ? 'result_edited' : 'result_entered', $actor->user_id, $before, $this->repo->snapshot( $ctx->id ) );
			}
		);
		$this->events->entered( $ctx->id, $actor->user_id, $edited );

		return array(
			'status'     => Flow::PROVISIONAL,
			'home_score' => $validated->result->home_score,
			'away_score' => $validated->result->away_score,
			'warnings'   => $warnings,
			'version'    => $this->context( $ctx->id )->version(),
		);
	}

	/**
	 * Resultat bestätigen (Gastseite oder TK; nie die Person, die es erfasst hat, ausser TK).
	 *
	 * @return array{status:string,version:string}
	 */
	public function confirm( int $fixture_id, Actor $actor, ?string $version = null ): array {
		return $this->confirm_result( $fixture_id, $actor, $version, null, null );
	}

	/**
	 * Bestätigung durch die Gastseite oder als Entscheid der TK.
	 *
	 * @param bool|null $by_tk true für den Entscheid der TK; null leitet es aus den Rollen ab
	 *                         (die Gastseite bestätigt als Gastseite, auch wenn sie zur TK gehört).
	 * @return array{status:string,version:string}
	 */
	private function confirm_result( int $fixture_id, Actor $actor, ?string $version, ?string $comment, ?bool $by_tk ): array {
		$ctx   = $this->context( $fixture_id );
		$roles = $this->authorize( $ctx, $actor, Flow::CONFIRM );
		$this->check_version( $ctx, $version );
		if ( ! $actor->is_tk && $ctx->entered_by === $actor->user_id ) {
			throw ResultException::forbidden( __( 'Wer das Resultat erfasst hat, kann es nicht selbst bestätigen.', 'ttleague' ) );
		}
		$by_tk ??= ! in_array( Flow::ROLE_AWAY, $roles, true );
		$this->change_status( $ctx, $actor, Flow::CONFIRMED, 'confirmed', $comment, array( 'by_tk' => $by_tk ), true );
		$this->events->confirmed( $ctx->id, $actor->user_id, $by_tk );
		return array(
			'status'  => Flow::CONFIRMED,
			'version' => $this->context( $ctx->id )->version(),
		);
	}

	/**
	 * Einsprache der Gastseite mit Begründung.
	 *
	 * @return array{status:string,version:string}
	 */
	public function object( int $fixture_id, Actor $actor, string $reason, ?string $version = null ): array {
		$ctx = $this->context( $fixture_id );
		$this->authorize( $ctx, $actor, Flow::OBJECT );
		$this->check_version( $ctx, $version );
		$reason = trim( $reason );
		$length = mb_strlen( $reason );
		if ( $length < 10 || $length > 2000 ) {
			throw ResultException::invalid( array( ResultInput::error( 'reason', __( 'Bitte begründe die Einsprache (10 bis 2000 Zeichen).', 'ttleague' ) ) ) );
		}
		$this->change_status( $ctx, $actor, Flow::DISPUTED, 'disputed', $reason, array(), false );
		$this->events->disputed( $ctx->id, $actor->user_id, $reason );
		return array(
			'status'  => Flow::DISPUTED,
			'version' => $this->context( $ctx->id )->version(),
		);
	}

	/**
	 * Entscheid der TK: bestätigen, abschliessen, korrigieren, Forfait, zurücksetzen.
	 *
	 * @param array<string,mixed> $payload action, winner, result, comment, version.
	 * @return array<string,mixed>
	 */
	public function decide( int $fixture_id, Actor $actor, array $payload ): array {
		$ctx = $this->context( $fixture_id );
		if ( ! $actor->is_tk ) {
			throw ResultException::forbidden( __( 'Nur die TK kann über ein Resultat entscheiden.', 'ttleague' ) );
		}
		$action = is_string( $payload['action'] ?? null ) ? $payload['action'] : '';
		if ( ! isset( self::DECISIONS[ $action ] ) ) {
			throw ResultException::invalid( array( ResultInput::error( 'action', __( 'Unbekannte Entscheidung.', 'ttleague' ) ) ) );
		}
		$comment = ResultInput::text( $payload['comment'] ?? null );
		if ( null !== $comment && mb_strlen( $comment ) > 2000 ) {
			throw ResultException::invalid( array( ResultInput::error( 'comment', __( 'Die Bemerkung ist zu lang (höchstens 2000 Zeichen).', 'ttleague' ) ) ) );
		}
		$version = is_string( $payload['version'] ?? null ) && '' !== $payload['version'] ? $payload['version'] : null;
		$this->check_version( $ctx, $version );
		if ( ! Flow::allows( self::DECISIONS[ $action ], $ctx->status, array( Flow::ROLE_TK ) ) ) {
			throw ResultException::conflict( $this->status_message( $ctx->status ) );
		}

		switch ( $action ) {
			case 'confirm':
				// Entscheid der TK: immer by_tk, mit Bemerkung; die Meldung an beide Seiten löst
				// das Ereignis «confirmed» aus, ein zusätzliches «decided» braucht es nicht.
				return $this->confirm_result( $fixture_id, $actor, $version, $comment, true );

			case 'finalize':
				if ( ! $ctx->has_result() ) {
					throw ResultException::conflict( __( 'Es ist noch kein Resultat erfasst.', 'ttleague' ) );
				}
				$this->change_status( $ctx, $actor, Flow::FINAL, 'decided', $comment, array( 'action' => 'finalize' ), null === $ctx->confirmed_by );
				break;

			case 'correct':
				$result = $payload['result'] ?? null;
				if ( ! is_array( $result ) ) {
					throw ResultException::invalid( array( ResultInput::error( 'result', __( 'Das korrigierte Resultat fehlt.', 'ttleague' ) ) ) );
				}
				$input     = $this->input( $ctx, $result );
				$validated = $this->validator( $ctx, $input )->validate( $input, true );
				$this->write_decision(
					$ctx,
					$actor,
					Flow::FINAL,
					'decided',
					$comment,
					array(
						'action'   => 'correct',
						'score'    => $validated->result->home_score . ':' . $validated->result->away_score,
						'warnings' => $validated->warnings,
					),
					$validated->result,
					$validated
				);
				break;

			case 'forfeit':
				$winner = $payload['winner'] ?? null;
				if ( ! in_array( $winner, array( 'home', 'away' ), true ) ) {
					throw ResultException::invalid( array( ResultInput::error( 'winner', __( 'Wer gewinnt forfait?', 'ttleague' ) ) ) );
				}
				$this->write_decision(
					$ctx,
					$actor,
					Flow::FORFEIT,
					'forfeit',
					$comment,
					array(
						'action' => 'forfeit',
						'winner' => $winner,
					),
					TeamMatchResult::forfeit( Side::from( $winner ), $ctx->rules ),
					null
				);
				break;

			case 'reset':
				$this->write_decision( $ctx, $actor, Flow::SCHEDULED, 'reset', $comment, array( 'action' => 'reset' ), null, null );
				break;
		}

		$this->events->decided( $ctx->id, $actor->user_id, $action, $comment );
		$after = $this->context( $ctx->id );
		return array(
			'status'     => $after->status,
			'home_score' => $after->home_score,
			'away_score' => $after->away_score,
			'version'    => $after->version(),
		);
	}

	/**
	 * Foto des Spielberichts verknüpfen (Heimseite oder TK).
	 */
	public function attach_photo( int $fixture_id, Actor $actor, int $attachment_id ): void {
		$ctx   = $this->context( $fixture_id );
		$roles = $this->roles( $ctx, $actor );
		if ( ! $this->may_attach_photo( $ctx, $roles ) ) {
			throw ResultException::forbidden( __( 'Du kannst zu dieser Begegnung kein Foto hochladen.', 'ttleague' ) );
		}
		$now = $this->now();
		$this->write(
			$ctx,
			function () use ( $ctx, $actor, $attachment_id, $now ): void {
				$this->repo->update_fixture( $ctx->id, array( 'photo_attachment_id' => $attachment_id ) );
				$this->repo->add_event( $ctx->id, 'photo', $actor->user_id, $actor->person_id, null, array( 'attachment_id' => $attachment_id ), $now );
				$this->audit->record( 'fixture', $ctx->id, 'photo', $actor->user_id, array( 'photo_attachment_id' => $ctx->photo_id ), array( 'photo_attachment_id' => $attachment_id ) );
			}
		);
		$this->events->photo_added( $ctx->id, $actor->user_id, $attachment_id );
	}

	/**
	 * Darf die Person ein Foto hochladen (Heimseite vor der Bestätigung, TK immer)?
	 *
	 * @param list<string> $roles Rollen.
	 */
	public function may_attach_photo( FixtureContext $ctx, array $roles ): bool {
		return in_array( Flow::ROLE_TK, $roles, true ) || Flow::allows( Flow::ENTER, $ctx->status, $roles );
	}

	/**
	 * Eigene Begegnungen: zu erfassen, zu bestätigen, demnächst.
	 *
	 * @return array{to_enter:list<array<string,mixed>>,to_confirm:list<array<string,mixed>>,upcoming:list<array<string,mixed>>}
	 */
	public function my_fixtures( Actor $actor, int $days_ahead = 21 ): array {
		$result = array(
			'to_enter'   => array(),
			'to_confirm' => array(),
			'upcoming'   => array(),
		);
		$season = $this->policy->active_season_id();
		if ( null === $actor->person_id || null === $season ) {
			return $result;
		}
		$roles = $this->policy->roles( $actor->person_id, $season );
		$clubs = $roles['tk_chef_clubs'];
		foreach ( $roles['captain_teams'] as $team_id ) {
			$clubs[] = (int) $this->db->get_var( "SELECT club_id FROM {$this->db->table( 'team' )} WHERE id = %d", array( $team_id ) );
		}
		foreach ( $roles['cup_entries'] as $entry_id ) {
			$clubs[] = (int) $this->db->get_var( "SELECT club_id FROM {$this->db->table( 'cup_entry' )} WHERE id = %d", array( $entry_id ) );
		}
		$clubs = array_values( array_unique( array_filter( array_map( 'intval', $clubs ) ) ) );
		if ( array() === $clubs ) {
			return $result;
		}

		$local = $this->clock->now()->setTimezone( $this->timezone );
		$now   = $local->format( 'Y-m-d H:i:s' );
		$until = $local->modify( '+' . $days_ahead . ' days' )->format( 'Y-m-d H:i:s' );
		$in    = implode( ',', $clubs ); // Nur Ganzzahlen.
		$rows  = $this->db->get_results(
			"SELECT f.id, f.status, f.scheduled_at, f.home_score, f.away_score, f.entered_by, c.type, c.name AS competition,
				COALESCE(ht.name, he.name, '') AS home_name, COALESCE(at.name, ae.name, '') AS away_name
			FROM {$this->db->table( 'fixture' )} f
			JOIN {$this->db->table( 'competition' )} c ON c.id = f.competition_id
			LEFT JOIN {$this->db->table( 'team' )} ht ON ht.id = f.home_team_id
			LEFT JOIN {$this->db->table( 'team' )} at ON at.id = f.away_team_id
			LEFT JOIN {$this->db->table( 'cup_entry' )} he ON he.id = f.home_entry_id
			LEFT JOIN {$this->db->table( 'cup_entry' )} ae ON ae.id = f.away_entry_id
			WHERE c.season_id = %d AND c.type IN ('MM', 'CUP')
				AND ( ht.club_id IN ({$in}) OR at.club_id IN ({$in}) OR he.club_id IN ({$in}) OR ae.club_id IN ({$in}) )
				AND f.status IN ('scheduled', 'postponed', 'provisional')
			ORDER BY f.scheduled_at, f.id",
			array( $season )
		);
		foreach ( $rows as $row ) {
			$side = $this->policy->side_for( $actor->person_id, (int) $row['id'] );
			if ( null === $side ) {
				continue;
			}
			$item = array(
				'id'           => (int) $row['id'],
				'type'         => (string) $row['type'],
				'competition'  => (string) $row['competition'],
				'scheduled_at' => $row['scheduled_at'],
				'home'         => (string) $row['home_name'],
				'away'         => (string) $row['away_name'],
				'status'       => (string) $row['status'],
				'side'         => $side,
				'home_score'   => null === $row['home_score'] ? null : (int) $row['home_score'],
				'away_score'   => null === $row['away_score'] ? null : (int) $row['away_score'],
			);
			$open = in_array( $row['status'], array( Flow::SCHEDULED, Flow::POSTPONED ), true );
			if ( Flow::PROVISIONAL === $row['status'] ) {
				$confirmable = $this->policy->can_confirm_result( $actor->person_id, (int) $row['id'] ) && (int) $row['entered_by'] !== $actor->user_id;
				if ( $confirmable ) {
					$result['to_confirm'][] = $item;
				}
			} elseif ( $open && null !== $row['scheduled_at'] && $row['scheduled_at'] <= $now ) {
				if ( AccessPolicy::HOME === $side ) {
					$result['to_enter'][] = $item;
				}
			} elseif ( $open && null !== $row['scheduled_at'] && $row['scheduled_at'] <= $until ) {
				$result['upcoming'][] = $item;
			}
		}
		return $result;
	}

	/**
	 * @throws ResultException Wenn die Begegnung fehlt.
	 */
	public function context( int $fixture_id ): FixtureContext {
		$ctx = $this->repo->context( $fixture_id );
		if ( null === $ctx ) {
			throw ResultException::not_found( __( 'Diese Begegnung gibt es nicht.', 'ttleague' ) );
		}
		return $ctx;
	}

	/**
	 * Rolle und Status prüfen.
	 *
	 * @return list<string> Rollen.
	 */
	private function authorize( FixtureContext $ctx, Actor $actor, string $action ): array {
		$roles = $this->roles( $ctx, $actor );
		if ( ! Flow::role_may( $action, $roles ) ) {
			throw ResultException::forbidden(
				match ( $action ) {
					Flow::ENTER   => __( 'Das Resultat erfasst die Heimmannschaft (Captain oder TK-Chef).', 'ttleague' ),
					Flow::CONFIRM => __( 'Das Resultat bestätigt die Gastmannschaft (Captain oder TK-Chef).', 'ttleague' ),
					Flow::OBJECT  => __( 'Einsprache erhebt die Gastmannschaft (Captain oder TK-Chef).', 'ttleague' ),
					default       => __( 'Dazu fehlt dir die Berechtigung.', 'ttleague' ),
				}
			);
		}
		if ( ! Flow::allows( $action, $ctx->status, $roles ) ) {
			throw ResultException::conflict( $this->status_message( $ctx->status ) );
		}
		return $roles;
	}

	private function check_version( FixtureContext $ctx, ?string $version ): void {
		if ( null !== $version && ! hash_equals( $ctx->version(), $version ) ) {
			throw ResultException::conflict( __( 'Die Begegnung wurde inzwischen geändert. Bitte lade die Seite neu.', 'ttleague' ) );
		}
	}

	/**
	 * @param array<string,mixed> $payload Rohdaten.
	 */
	private function input( FixtureContext $ctx, array $payload ): ResultInput {
		return ResultInput::from_array(
			$payload,
			$ctx->slots( 'home' ),
			$ctx->slots( 'away' ),
			array_map( static fn( array $g ): int => $g['sequence'], $ctx->order() )
		);
	}

	private function validator( FixtureContext $ctx, ResultInput $input ): ResultValidator {
		$ids = array();
		foreach ( array( $input->home, $input->away ) as $slots ) {
			foreach ( $slots as $id ) {
				if ( null !== $id ) {
					$ids[] = $id;
				}
			}
		}
		foreach ( $input->games as $game ) {
			$ids = array_merge( $ids, $game->home_players, $game->away_players );
		}
		$names = $this->repo->player_names( $ids );
		return new ResultValidator(
			$ctx,
			$this->rosters( $ctx ),
			static fn( int $id ): string => $names[ $id ] ?? sprintf( /* translators: %d: Spieler-ID */ __( 'Spieler %d', 'ttleague' ), $id )
		);
	}

	/**
	 * @return array{home:SideRoster,away:SideRoster}
	 */
	private function rosters( FixtureContext $ctx ): array {
		$sides = array();
		foreach ( array( 'home', 'away' ) as $side ) {
			$info = $ctx->side( $side );
			$club = $info['club_id'];
			if ( $ctx->is_cup() ) {
				$sides[ $side ] = new SideRoster(
					null === $club ? array() : $this->eligibility->club_players( $ctx->season_id, $club ),
					null,
					null,
					null === $info['entry_id'] ? array() : $this->eligibility->entry_players( $info['entry_id'] ),
					$info['name']
				);
				continue;
			}
			$loaded         = null === $club ? null : $this->eligibility->for_club( $ctx->season_id, $club, $ctx->rules, $ctx->id, $ctx->scheduled_at ?? $this->clock->now()->setTimezone( $this->timezone )->format( 'Y-m-d H:i:s' ) );
			$sides[ $side ] = new SideRoster( $loaded['players'] ?? array(), $loaded['checker'] ?? null, $info['team_id'], array(), $info['name'] );
		}
		return $sides;
	}

	/**
	 * Hinweise zur Zeit: nach Ablauf der Frist erfasst oder vor dem Termin.
	 *
	 * @return list<string>
	 */
	private function timing_warnings( FixtureContext $ctx ): array {
		if ( null === $ctx->scheduled_at ) {
			return array();
		}
		$scheduled = new \DateTimeImmutable( $ctx->scheduled_at, $this->timezone );
		$now       = $this->clock->now();
		if ( $now > $scheduled->modify( '+' . $ctx->rules->entry_deadline_hours . ' hours' ) ) {
			/* translators: %d: Stunden */
			return array( sprintf( __( 'Erfasst nach Ablauf der Frist von %d Stunden.', 'ttleague' ), $ctx->rules->entry_deadline_hours ) );
		}
		if ( $now < $scheduled->modify( '-2 hours' ) ) {
			/* translators: %s: Datum */
			return array( sprintf( __( 'Die Begegnung ist erst am %s angesetzt.', 'ttleague' ), $scheduled->format( 'd.m.Y' ) ) );
		}
		return array();
	}

	/**
	 * Nur Status und Bestätigung ändern.
	 *
	 * @param array<string,mixed> $data Zusatzdaten für den Verlauf.
	 */
	private function change_status( FixtureContext $ctx, Actor $actor, string $status, string $event, ?string $comment, array $data, bool $confirm ): void {
		$now = $this->now();
		$this->write(
			$ctx,
			function () use ( $ctx, $actor, $status, $event, $comment, $data, $confirm, $now ): void {
				$before = $this->repo->snapshot( $ctx->id );
				$fields = array( 'status' => $status );
				if ( $confirm ) {
					$fields['confirmed_by']   = $actor->user_id;
					$fields['confirmed_at']   = $now;
					$fields['confirmed_auto'] = 0;
				}
				$this->repo->update_fixture( $ctx->id, $fields );
				$this->repo->add_event( $ctx->id, $event, $actor->user_id, $actor->person_id, $comment, $data, $now );
				$this->audit->record( 'fixture', $ctx->id, 'status_' . $status, $actor->user_id, $before, $this->repo->snapshot( $ctx->id ) );
			}
		);
	}

	/**
	 * Entscheid mit neuem Resultat, Forfait oder Zurücksetzen.
	 *
	 * @param array<string,mixed> $data Zusatzdaten für den Verlauf.
	 */
	private function write_decision( FixtureContext $ctx, Actor $actor, string $status, string $event, ?string $comment, array $data, ?TeamMatchResult $result, ?ValidatedResult $validated ): void {
		$now = $this->now();
		$this->write(
			$ctx,
			function () use ( $ctx, $actor, $status, $event, $comment, $data, $result, $validated, $now ): void {
				$before  = $this->repo->snapshot( $ctx->id );
				$players = $this->repo->lineup_players( $ctx->id );
				if ( null === $validated ) {
					$this->repo->clear_result( $ctx->id );
				} else {
					$this->repo->save_result( $ctx->id, $validated, $ctx->scheduled_at );
				}
				$this->sync_bindings( $ctx, array_merge( $players, $this->repo->lineup_players( $ctx->id ) ) );
				$reset  = Flow::SCHEDULED === $status;
				$fields = array(
					'status'         => $status,
					'home_score'     => $result?->home_score,
					'away_score'     => $result?->away_score,
					'confirmed_by'   => $reset ? null : $actor->user_id,
					'confirmed_at'   => $reset ? null : $now,
					'confirmed_auto' => 0,
				);
				if ( $reset ) {
					// Zurück zum Ausgangszustand: Das Foto gehört zur verworfenen Erfassung. Der Anhang
					// bleibt in der Mediathek, der Verlauf nennt ihn weiterhin.
					$fields['entered_by']          = null;
					$fields['entered_at']          = null;
					$fields['photo_attachment_id'] = null;
				}
				$this->repo->update_fixture( $ctx->id, $fields );
				$this->repo->add_event( $ctx->id, $event, $actor->user_id, $actor->person_id, $comment, $data, $now );
				$this->audit->record( 'fixture', $ctx->id, 'decision_' . (string) ( $data['action'] ?? $event ), $actor->user_id, $before, $this->repo->snapshot( $ctx->id ), $comment );
			}
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
			'status_label' => self::status_label( $ctx->status ),
			'scheduled_at' => $ctx->scheduled_at,
			'competition'  => $ctx->competition,
			'season'       => $ctx->season_slug,
			'home'         => $ctx->home,
			'away'         => $ctx->away,
			'home_score'   => $ctx->home_score,
			'away_score'   => $ctx->away_score,
		);
	}

	/**
	 * @param list<array<string,mixed>> $events Verlauf.
	 * @return list<array<string,mixed>>
	 */
	private function history( array $events ): array {
		return array_map(
			fn( array $event ): array => array(
				'type'     => $event['type'],
				'by'       => $event['name'] ?? ( null === $event['user_id'] ? null : ( $this->user_names )( $event['user_id'] ) ),
				'at'       => self::iso( $event['created_at'] ),
				'comment'  => $event['comment'],
				'action'   => $event['data']['action'] ?? null,
				'score'    => $event['data']['score'] ?? null,
				'warnings' => $event['data']['warnings'] ?? array(),
			),
			$events
		);
	}

	/**
	 * Name zu einem Konto aus dem Verlauf (Person) oder über WordPress.
	 *
	 * @param list<array<string,mixed>> $events Verlauf.
	 * @param list<string>              $types  Passende Ereignistypen.
	 */
	private function name_for_user( ?int $user_id, array $events, array $types ): ?string {
		if ( null === $user_id ) {
			return null;
		}
		foreach ( array_reverse( $events ) as $event ) {
			if ( $event['user_id'] === $user_id && in_array( $event['type'], $types, true ) && null !== $event['name'] ) {
				return $event['name'];
			}
		}
		return ( $this->user_names )( $user_id );
	}

	private function status_message( string $status ): string {
		/* translators: %s: Status der Begegnung */
		return sprintf( __( 'Im Status «%s» ist das nicht möglich.', 'ttleague' ), self::status_label( $status ) );
	}

	public static function status_label( string $status ): string {
		return match ( $status ) {
			Flow::SCHEDULED   => __( 'angesetzt', 'ttleague' ),
			Flow::POSTPONED   => __( 'verschoben', 'ttleague' ),
			Flow::PROVISIONAL => __( 'provisorisch', 'ttleague' ),
			Flow::CONFIRMED   => __( 'bestätigt', 'ttleague' ),
			Flow::DISPUTED    => __( 'strittig', 'ttleague' ),
			Flow::FINAL       => __( 'abgeschlossen', 'ttleague' ),
			Flow::FORFEIT     => __( 'forfait', 'ttleague' ),
			Flow::CANCELLED   => __( 'abgesagt', 'ttleague' ),
			default           => $status,
		};
	}

	/**
	 * Schreibt in einer Transaktion. Zuerst wird die Begegnung gesperrt und neu gelesen: Hat sie
	 * sich seit der Prüfung von Rechten, Status und Version geändert, bricht die Aktion mit 409
	 * ab. So setzt keine von zwei gleichzeitigen Aktionen eine veraltete Version oder einen
	 * inzwischen unzulässigen Statuswechsel durch.
	 *
	 * @param callable():void $work Schreibvorgänge.
	 * @throws ResultException 409, wenn sich die Begegnung inzwischen geändert hat.
	 */
	private function write( FixtureContext $ctx, callable $work ): void {
		$this->db->transaction(
			function () use ( $ctx, $work ): void {
				$this->repo->lock( $ctx->id );
				$current = $this->repo->context( $ctx->id );
				if ( null === $current || ! hash_equals( $ctx->version(), $current->version() ) ) {
					throw ResultException::conflict( __( 'Die Begegnung wurde inzwischen geändert. Bitte lade die Seite neu.', 'ttleague' ) );
				}
				$work();
			}
		);
	}

	/**
	 * Bindungen (Art. 12.5) der Spieler neu bestimmen, deren Einsätze sich geändert haben. Eine
	 * Bindung folgt allein aus den Einsätzen: Sie entsteht mit dem Einsatz, der die Höchstzahl
	 * der Ersatzeinsätze in einem Team erreicht, und entfällt, wenn ein Zurücksetzen, ein
	 * Forfait oder eine Korrektur diesen Einsatz entfernt.
	 *
	 * @param list<int> $player_ids Spieler der bisherigen und der neuen Aufstellung.
	 */
	private function sync_bindings( FixtureContext $ctx, array $player_ids ): void {
		if ( $ctx->is_cup() ) {
			return;
		}
		foreach ( array_values( array_unique( $player_ids ) ) as $player_id ) {
			$this->repo->replace_binding( $ctx->season_id, $player_id, $this->eligibility->binding( $ctx->season_id, $player_id, $ctx->rules ) );
		}
	}

	private function now(): string {
		return $this->clock->now()->format( 'Y-m-d H:i:s' );
	}

	private static function iso( ?string $utc ): ?string {
		return null === $utc || '' === $utc ? null : str_replace( ' ', 'T', $utc ) . 'Z';
	}
}
