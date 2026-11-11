<?php
/**
 * Auslosung des Mannschaftscups: Setzung, Vorschlag, Änderungen der TK, Freigabe.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Cup;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Meldungen der Ausnahmen sind Klartext; escaped wird bei der Ausgabe (Flash).

use TTLeague\Domain\Cup\Draw;
use TTLeague\Infrastructure\AuditLog;
use TTLeague\Infrastructure\Database\Db;
use TTLeague\Infrastructure\Time\Clock;
use TTLeague\Results\Actor;
use TTLeague\Results\ResultException;
use TTLeague\Results\ResultInput;

/**
 * Das System schlägt vor, die TK gibt frei (docs/cup.md, Art. 21.1). Alles hier darf nur die TK.
 *
 * - propose() lost die erste Runde aus (Domain\Cup\Draw) und legt den Vorschlag in format_json
 *   der Konkurrenz ab (Schlüssel «draw»: Startwert, Plätze). Der Startwert steht im audit_log.
 * - change() übernimmt geänderte Paarungen und Heimrechte der TK in den Vorschlag.
 * - release() schreibt das Tableau: cup_tie für alle Runden, die Setzung in cup_entry.seed, die
 *   Begegnungen der ersten Runde ohne Termin und die Frist der Runde. Freilose rücken sofort
 *   nach (Bracket::reconcile()). Danach ist die Auslosung fest.
 *
 * Ein Vorschlag gilt nur für die Meldungen, aus denen er entstand: Ändern sie sich, muss neu
 * gelost werden. Zurückgezogene Meldungen nehmen an der Auslosung nicht teil.
 */
final class DrawService {

	private CupRepository $repo;

	/** @var callable():int */
	private $new_seed;

	/**
	 * @param (callable():int)|null $new_seed Startwert einer Auslosung ohne Vorgabe (Standard: Zufall).
	 */
	public function __construct(
		private readonly Db $db,
		private readonly Clock $clock,
		private readonly \DateTimeZone $timezone,
		private readonly AuditLog $audit,
		private readonly CupEvents $events,
		private readonly Bracket $bracket,
		?callable $new_seed = null,
	) {
		$this->repo     = new CupRepository( $db );
		$this->new_seed = $new_seed ?? static fn(): int => random_int( 1, 2147483647 );
	}

	/**
	 * Stand der Auslosung für das Backend.
	 *
	 * @return array{competition:array<string,mixed>,drawn:bool,entries:list<array<string,mixed>>,proposal:array{seed:int,size:int,changed:bool,stale:bool,pairings:list<array{slot:int,home:int|null,away:int|null}>}|null}
	 *         «entries» sind die gültigen Meldungen in der Reihenfolge der Setzung, mit «rank».
	 */
	public function overview( int $competition_id ): array {
		$competition = $this->competition( $competition_id );
		$entries     = $this->seeded_entries( $competition_id );
		$proposal    = $this->proposal( $competition );
		return array(
			'competition' => $competition,
			'drawn'       => $this->repo->has_draw( $competition_id ),
			'entries'     => $entries,
			'proposal'    => null === $proposal ? null : array(
				'seed'     => $proposal['seed'],
				'size'     => count( $proposal['positions'] ),
				'changed'  => $proposal['changed'],
				'stale'    => array() !== Draw::problems( $proposal['positions'], array_column( $entries, 'id' ) ),
				'pairings' => Draw::pairings( $proposal['positions'] ),
			),
		);
	}

	/**
	 * Setznummern vergeben oder entfernen (TK). Ein bestehender Vorschlag verfällt.
	 *
	 * @param array<int|string,mixed> $seeds Meldung => Setznummer; leer entfernt sie.
	 * @return list<array<string,mixed>> Meldungen in der neuen Reihenfolge der Setzung.
	 */
	public function set_seeds( int $competition_id, Actor $actor, array $seeds ): array {
		$this->require_tk( $actor );
		$competition = $this->competition( $competition_id );
		$this->db->transaction(
			function () use ( $competition, $actor, $seeds ): void {
				$this->repo->lock_season( $competition['season_id'] );
				$this->require_undrawn( $competition['id'] );
				$entries = array_column( $this->active_entries( $competition['id'] ), null, 'id' );
				$count   = count( $entries );
				$wanted  = array();
				$errors  = array();
				foreach ( $seeds as $entry_id => $value ) {
					$entry_id = (int) $entry_id;
					if ( ! isset( $entries[ $entry_id ] ) ) {
						continue;
					}
					if ( CupInput::blank( $value ) ) {
						$wanted[ $entry_id ] = null;
						continue;
					}
					$number = filter_var( $value, FILTER_VALIDATE_INT );
					if ( false === $number || $number < 1 || $number > $count ) {
						/* translators: 1: Cupmannschaft, 2: Anzahl Mannschaften */
						$errors[] = ResultInput::error( 'seed.' . $entry_id, sprintf( __( '%1$s: Die Setznummer ist eine ganze Zahl von 1 bis %2$d oder leer.', 'ttleague' ), $entries[ $entry_id ]['name'], $count ) );
						continue;
					}
					$wanted[ $entry_id ] = $number;
				}
				$final = array();
				foreach ( $entries as $entry_id => $entry ) {
					$final[ $entry_id ] = array_key_exists( $entry_id, $wanted ) ? $wanted[ $entry_id ] : $entry['seed'];
				}
				$given = array_filter( $final, static fn( ?int $seed ): bool => null !== $seed );
				if ( count( $given ) !== count( array_unique( $given ) ) ) {
					$errors[] = ResultInput::error( 'seed', __( 'Jede Setznummer darf nur einmal vorkommen.', 'ttleague' ) );
				}
				if ( array() !== $errors ) {
					throw ResultException::invalid( $errors );
				}
				$before = array_map( static fn( array $entry ): ?int => $entry['seed'], $entries );
				if ( $before === $final ) {
					return;
				}
				foreach ( $final as $entry_id => $seed ) {
					if ( $entries[ $entry_id ]['seed'] !== $seed ) {
						$this->db->update( 'cup_entry', array( 'seed' => $seed ), array( 'id' => $entry_id ) );
					}
				}
				$format = $this->competition( $competition['id'] )['format'];
				unset( $format['draw'] );
				$this->repo->save_format( $competition['id'], $format );
				$this->audit->record( 'competition', $competition['id'], 'cup_seeded', $actor->user_id, $before, $final );
			}
		);
		$this->events->changed( $competition['id'], CupEvents::SEEDED, $actor->user_id );
		return $this->seeded_entries( $competition['id'] );
	}

	/**
	 * Erste Runde auslosen und als Vorschlag ablegen (TK).
	 *
	 * @param int|null $seed Startwert; ohne Angabe ein zufälliger.
	 * @return array<string,mixed> Stand wie overview().
	 */
	public function propose( int $competition_id, Actor $actor, ?int $seed = null ): array {
		$this->require_tk( $actor );
		$competition = $this->competition( $competition_id );
		$seed      ??= ( $this->new_seed )();
		$this->db->transaction(
			function () use ( $competition, $actor, $seed ): void {
				$this->repo->lock_season( $competition['season_id'] );
				$this->require_undrawn( $competition['id'] );
				$entries = $this->active_entries( $competition['id'] );
				if ( count( $entries ) < 2 ) {
					throw ResultException::conflict( __( 'Eine Auslosung braucht mindestens zwei gemeldete Mannschaften.', 'ttleague' ) );
				}
				$draw = Draw::draw(
					array_map(
						static fn( array $entry ): array => array(
							'id'            => $entry['id'],
							'club_id'       => $entry['club_id'],
							'points_total'  => $entry['points_total'],
							'registered_at' => $entry['registered_at'],
							'seed'          => $entry['seed'],
						),
						$entries
					),
					$seed
				);
				$this->store(
					$competition['id'],
					array(
						'seed'        => $draw['seed'],
						'positions'   => $draw['positions'],
						'changed'     => false,
						'proposed_by' => $actor->user_id,
						'proposed_at' => $this->now(),
					)
				);
				$this->audit->record(
					'competition',
					$competition['id'],
					'cup_draw_proposed',
					$actor->user_id,
					null,
					array(
						'seed'      => $draw['seed'],
						'seeding'   => $draw['seeding'],
						'seeded'    => $draw['seeded'],
						'positions' => $draw['positions'],
					)
				);
			}
		);
		$this->events->changed( $competition['id'], CupEvents::DRAW_PROPOSED, $actor->user_id );
		return $this->overview( $competition['id'] );
	}

	/**
	 * Paarungen und Heimrecht des Vorschlags ändern (TK). Die erstgenannte Mannschaft einer
	 * Paarung hat Heimrecht; ein Freilos steht immer hinten.
	 *
	 * @param list<mixed> $positions Plätze des Tableaus: Meldung oder leer (Freilos), paarweise.
	 * @return array<string,mixed> Stand wie overview().
	 */
	public function change( int $competition_id, Actor $actor, array $positions ): array {
		$this->require_tk( $actor );
		$competition = $this->competition( $competition_id );
		$positions   = Draw::normalize( array_map( static fn( mixed $id ): ?int => CupInput::blank( $id ) || (int) $id <= 0 ? null : (int) $id, array_values( $positions ) ) );
		$this->db->transaction(
			function () use ( $competition, $actor, $positions ): void {
				$this->repo->lock_season( $competition['season_id'] );
				$this->require_undrawn( $competition['id'] );
				$current  = $this->competition( $competition['id'] );
				$proposal = $this->proposal( $current );
				if ( null === $proposal ) {
					throw ResultException::conflict( __( 'Es gibt noch keinen Vorschlag. Bitte zuerst auslosen.', 'ttleague' ) );
				}
				$this->require_valid( $positions, array_column( $this->active_entries( $current['id'] ), 'id' ) );
				if ( $positions === $proposal['positions'] ) {
					return;
				}
				$this->store(
					$current['id'],
					array(
						'positions' => $positions,
						'changed'   => true,
					) + $proposal
				);
				$this->audit->record( 'competition', $current['id'], 'cup_draw_changed', $actor->user_id, array( 'positions' => $proposal['positions'] ), array( 'positions' => $positions ) );
			}
		);
		$this->events->changed( $competition['id'], CupEvents::DRAW_CHANGED, $actor->user_id );
		return $this->overview( $competition['id'] );
	}

	/**
	 * Auslosung freigeben (TK): Tableau, Setzung und Begegnungen der ersten Runde schreiben.
	 *
	 * @param mixed $deadline Frist der ersten Runde (Datum) oder leer.
	 * @return array{competition_id:int,seed:int,rounds:int,ties:int,fixtures:list<int>,deadline:string|null}
	 */
	public function release( int $competition_id, Actor $actor, mixed $deadline = null ): array {
		$this->require_tk( $actor );
		$competition = $this->competition( $competition_id );
		if ( 'active' !== $competition['season_status'] ) {
			throw ResultException::conflict( __( 'Die Auslosung lässt sich erst in der aktiven Saison freigeben.', 'ttleague' ) );
		}
		$date = null;
		if ( ! CupInput::blank( $deadline ) ) {
			$date = CupInput::date( $deadline );
			if ( null === $date ) {
				throw ResultException::invalid( array( ResultInput::error( 'deadline', __( 'Bitte gib die Frist als Datum an.', 'ttleague' ) ) ) );
			}
			if ( $date < $this->clock->now()->setTimezone( $this->timezone )->format( 'Y-m-d' ) ) {
				throw ResultException::invalid( array( ResultInput::error( 'deadline', __( 'Die Frist liegt in der Vergangenheit.', 'ttleague' ) ) ) );
			}
		}

		$result = $this->db->transaction(
			function () use ( $competition, $actor, $date ): array {
				$this->repo->lock_season( $competition['season_id'] );
				$this->require_undrawn( $competition['id'] );
				$current  = $this->competition( $competition['id'] );
				$proposal = $this->proposal( $current );
				if ( null === $proposal ) {
					throw ResultException::conflict( __( 'Es gibt noch keinen Vorschlag. Bitte zuerst auslosen.', 'ttleague' ) );
				}
				$entries = $this->seeded_entries( $current['id'] );
				if ( array() !== Draw::problems( $proposal['positions'], array_column( $entries, 'id' ) ) ) {
					throw ResultException::conflict( __( 'Die Meldungen haben sich seit dem Vorschlag geändert. Bitte neu auslosen.', 'ttleague' ) );
				}

				// Setzung festhalten, wie sie für die Auslosung galt.
				foreach ( $entries as $entry ) {
					if ( $entry['seed'] !== $entry['rank'] ) {
						$this->db->update( 'cup_entry', array( 'seed' => $entry['rank'] ), array( 'id' => $entry['id'] ) );
					}
				}

				$size   = count( $proposal['positions'] );
				$rounds = Draw::rounds( $size );
				$ties   = 0;
				foreach ( Draw::pairings( $proposal['positions'] ) as $pairing ) {
					$tie_id = $this->db->insert(
						'cup_tie',
						array(
							'competition_id' => $current['id'],
							'round'          => 1,
							'slot'           => $pairing['slot'],
							'fixture_id'     => null,
							'home_entry_id'  => $pairing['home'],
							'away_entry_id'  => $pairing['away'],
							'deadline'       => $date,
						)
					);
					++$ties;
					if ( null === $pairing['away'] ) {
						// Merkt das Freilos für die Mitteilung an die Mannschaft vor.
						$this->audit->record( 'cup_tie', $tie_id, 'bye', $actor->user_id, null, array( 'entry' => $pairing['home'] ) );
					}
				}
				for ( $round = 2; $round <= $rounds; $round++ ) {
					$slots = intdiv( $size, 2 ** $round );
					for ( $slot = 1; $slot <= $slots; $slot++ ) {
						$this->db->insert(
							'cup_tie',
							array(
								'competition_id' => $current['id'],
								'round'          => $round,
								'slot'           => $slot,
								'fixture_id'     => null,
								'home_entry_id'  => null,
								'away_entry_id'  => null,
								'deadline'       => null,
							)
						);
						++$ties;
					}
				}

				$format = $current['format'];
				unset( $format['draw'] );
				$this->repo->save_format( $current['id'], $format );
				$this->audit->record(
					'competition',
					$current['id'],
					'cup_draw_released',
					$actor->user_id,
					null,
					array(
						'seed'      => $proposal['seed'],
						'changed'   => $proposal['changed'],
						'seeding'   => array_column( $entries, 'id' ),
						'positions' => $proposal['positions'],
						'deadline'  => $date,
					)
				);

				// Begegnungen der ersten Runde anlegen, Freilose rücken nach.
				$report = $this->bracket->reconcile( $current['id'], $actor->user_id );
				$tied   = array_column( $this->repo->ties( $current['id'] ), null, 'id' );
				return array(
					'competition_id' => $current['id'],
					'seed'           => $proposal['seed'],
					'rounds'         => $rounds,
					'ties'           => $ties,
					'fixtures'       => array_values( array_map( static fn( int $tie_id ): int => (int) $tied[ $tie_id ]['fixture']['id'], $report['created'] ) ),
					'deadline'       => $date,
				);
			}
		);
		$this->events->changed( $competition['id'], CupEvents::DRAW_RELEASED, $actor->user_id );
		return $result;
	}

	/**
	 * Gültige Meldungen in der Reihenfolge der Setzung, mit ihrem Rang («rank», ab 1).
	 *
	 * @return list<array<string,mixed>>
	 */
	private function seeded_entries( int $competition_id ): array {
		$entries = array_column( $this->active_entries( $competition_id ), null, 'id' );
		$ordered = array();
		foreach ( Draw::seeding(
			array_map(
				static fn( array $entry ): array => array(
					'id'            => $entry['id'],
					'club_id'       => $entry['club_id'],
					'points_total'  => $entry['points_total'],
					'registered_at' => $entry['registered_at'],
					'seed'          => $entry['seed'],
				),
				array_values( $entries )
			)
		) as $i => $row ) {
			$ordered[] = $entries[ $row['id'] ] + array( 'rank' => $i + 1 );
		}
		return $ordered;
	}

	/**
	 * @return list<array<string,mixed>>
	 */
	private function active_entries( int $competition_id ): array {
		return array_values( array_filter( $this->repo->entries( $competition_id ), static fn( array $entry ): bool => CupRepository::ACTIVE === $entry['status'] ) );
	}

	/**
	 * Vorschlag aus format_json; null ohne (lesbaren) Vorschlag.
	 *
	 * @param array{format:array<string,mixed>} $competition Konkurrenz.
	 * @return array{seed:int,positions:list<int|null>,changed:bool,proposed_by:int|null,proposed_at:string|null}|null
	 */
	private function proposal( array $competition ): ?array {
		$draw = $competition['format']['draw'] ?? null;
		if ( ! is_array( $draw ) || ! is_array( $draw['positions'] ?? null ) || ! isset( $draw['seed'] ) ) {
			return null;
		}
		return array(
			'seed'        => (int) $draw['seed'],
			'positions'   => array_map( static fn( mixed $id ): ?int => null === $id ? null : (int) $id, array_values( $draw['positions'] ) ),
			'changed'     => ! empty( $draw['changed'] ),
			'proposed_by' => isset( $draw['proposed_by'] ) ? (int) $draw['proposed_by'] : null,
			'proposed_at' => isset( $draw['proposed_at'] ) ? (string) $draw['proposed_at'] : null,
		);
	}

	/**
	 * @param array<string,mixed> $proposal Vorschlag.
	 */
	private function store( int $competition_id, array $proposal ): void {
		$format         = $this->competition( $competition_id )['format'];
		$format['draw'] = $proposal;
		$this->repo->save_format( $competition_id, $format );
	}

	/**
	 * @param list<int|null> $positions Plätze.
	 * @param list<int>      $entry_ids Gültige Meldungen.
	 * @throws ResultException 409, wenn sich die Meldungen geändert haben; 422 bei ungültigen Paarungen.
	 */
	private function require_valid( array $positions, array $entry_ids ): void {
		$problems = Draw::problems( $positions, $entry_ids );
		if ( array() === $problems ) {
			return;
		}
		if ( in_array( 'size', $problems, true ) ) {
			throw ResultException::conflict( __( 'Die Meldungen haben sich seit dem Vorschlag geändert. Bitte neu auslosen.', 'ttleague' ) );
		}
		$messages = array(
			'unknown'       => __( 'Eine gewählte Mannschaft gehört nicht zu den gültigen Meldungen.', 'ttleague' ),
			'duplicate'     => __( 'Eine Mannschaft steht mehrfach im Tableau.', 'ttleague' ),
			'missing'       => __( 'Es fehlen Mannschaften im Tableau.', 'ttleague' ),
			'empty_pairing' => __( 'Eine Paarung braucht mindestens eine Mannschaft.', 'ttleague' ),
		);
		throw ResultException::invalid( array_map( static fn( string $problem ): array => ResultInput::error( 'positions', $messages[ $problem ] ), $problems ) );
	}

	/**
	 * @throws ResultException 409 nach der Freigabe und in einer abgeschlossenen Saison.
	 */
	private function require_undrawn( int $competition_id ): void {
		if ( 'archived' === $this->competition( $competition_id )['season_status'] ) {
			throw ResultException::conflict( __( 'Die Saison ist abgeschlossen.', 'ttleague' ) );
		}
		if ( $this->repo->has_draw( $competition_id ) ) {
			throw ResultException::conflict( __( 'Die Auslosung ist schon freigegeben.', 'ttleague' ) );
		}
	}

	private function require_tk( Actor $actor ): void {
		if ( ! $actor->is_tk ) {
			throw ResultException::forbidden( __( 'Das kann nur die TK.', 'ttleague' ) );
		}
	}

	/**
	 * @return array{id:int,name:string,category:string,season_id:int,season_name:string,season_slug:string,season_status:string,season_start:string,season_end:string,format:array<string,mixed>}
	 * @throws ResultException 404 ohne Konkurrenz.
	 */
	private function competition( int $competition_id ): array {
		$competition = $this->repo->competition( $competition_id );
		if ( null === $competition ) {
			throw ResultException::not_found( __( 'Diese Cup-Konkurrenz gibt es nicht.', 'ttleague' ) );
		}
		return $competition;
	}

	private function now(): string {
		return $this->clock->now()->format( 'Y-m-d H:i:s' );
	}
}
