<?php
/**
 * Tableau des Mannschaftscups: nachrücken, Rückzug, Fristen, Halbfinal und Final ansetzen.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Cup;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Meldungen der Ausnahmen sind Klartext; escaped wird bei der Ausgabe (Flash).

use TTLeague\Domain\Cup\BracketRules as Rules;
use TTLeague\Frontend\Support\Format;
use TTLeague\Infrastructure\AuditLog;
use TTLeague\Infrastructure\Database\Db;
use TTLeague\Infrastructure\Time\Clock;
use TTLeague\Results\Actor;
use TTLeague\Results\ResultException;
use TTLeague\Results\ResultInput;
use TTLeague\Results\ResultRepository;

/**
 * Das Tableau (cup_tie) folgt aus den Resultaten: sync() gleicht es nach jeder Änderung ab und
 * lässt sich beliebig oft wiederholen.
 *
 * - Sobald die Begegnung einer Paarung bestätigt, abgeschlossen oder forfait gewertet ist, steht
 *   der Sieger in der Paarung der nächsten Runde; ein Freilos und der Gegner einer
 *   zurückgezogenen Mannschaft kommen ohne Begegnung weiter (BracketRules::outcome()).
 * - Sind beide Plätze einer Paarung besetzt, entsteht ihre Begegnung, ohne Termin. Der obere
 *   Platz hat Heimrecht; die TK kann es tauschen (swap_home()).
 * - Ändert eine Korrektur den Sieger oder wird ein Resultat zurückgesetzt, rückt das Tableau neu
 *   nach, solange die Begegnung der nächsten Runde kein Resultat hat: Sie wird annulliert und
 *   mit der richtigen Mannschaft neu angelegt. Hat sie schon ein Resultat, bleibt das Tableau
 *   stehen, und conflicts() meldet es der TK.
 *
 * Hängt nur an Db, Clock und der Domäne. Das Forfait beim Rückzug übernimmt die Erfassung über
 * die injizierte Funktion, damit Verlauf und Mitteilungen der Begegnung stimmen.
 */
final class Bracket {

	private CupRepository $repo;

	private ResultRepository $fixtures;

	/** @var (callable(int,string,Actor,string):void)|null */
	private $forfeit;

	/**
	 * @param (callable(int,string,Actor,string):void)|null $forfeit Wertet eine Begegnung forfait:
	 *                                                               Begegnung, Siegerseite (home, away),
	 *                                                               handelnde Person, Bemerkung.
	 */
	public function __construct(
		private readonly Db $db,
		private readonly Clock $clock,
		private readonly \DateTimeZone $timezone,
		private readonly AuditLog $audit,
		private readonly CupEvents $events,
		?callable $forfeit = null,
	) {
		$this->repo     = new CupRepository( $db );
		$this->fixtures = new ResultRepository( $db );
		$this->forfeit  = $forfeit;
	}

	/**
	 * Gleicht das Tableau einer Konkurrenz mit Resultaten und Rückzügen ab.
	 *
	 * @param int $user_id Handelndes Konto, 0 für das System.
	 * @return array{created:list<int>,changed:bool,blocked:list<array<string,mixed>>} Paarungen mit
	 *         neuer Begegnung, ob sich Plätze geändert haben, und was nicht nachrücken konnte.
	 */
	public function sync( int $competition_id, int $user_id = 0 ): array {
		$competition = $this->repo->competition( $competition_id );
		if ( null === $competition ) {
			return self::empty_report();
		}
		$report = $this->db->transaction(
			function () use ( $competition, $user_id ): array {
				$this->repo->lock_season( $competition['season_id'] );
				return $this->reconcile( $competition['id'], $user_id );
			}
		);
		if ( $report['changed'] || array() !== $report['created'] ) {
			$this->events->changed( $competition['id'], CupEvents::ADVANCED, $user_id, $report['created'] );
		}
		return $report;
	}

	/**
	 * Abgleich innerhalb einer laufenden Transaktion mit gesperrter Saison (sync() und die
	 * Freigabe der Auslosung). Löst kein Ereignis aus.
	 *
	 * @return array{created:list<int>,changed:bool,blocked:list<array<string,mixed>>}
	 */
	public function reconcile( int $competition_id, int $user_id = 0 ): array {
		return $this->walk( $competition_id, true, $user_id );
	}

	/**
	 * Eine Begegnung hat ein Resultat erhalten, verloren oder geändert (Ereignisse der Erfassung).
	 */
	public function fixture_changed( int $fixture_id, int $user_id = 0 ): void {
		$competition_id = $this->repo->competition_of_fixture( $fixture_id );
		if ( null !== $competition_id ) {
			$this->sync( $competition_id, $user_id );
		}
	}

	/**
	 * Eine Mannschaft hat nach der Auslosung zurückgezogen: Ihre offene Begegnung wird forfait
	 * für den Gegner gewertet, danach rückt das Tableau nach. Lässt sich die Begegnung nicht
	 * werten (etwa weil die Saison abgeschlossen ist), bleibt sie für den Entscheid der TK stehen.
	 */
	public function entry_withdrawn( int $entry_id, Actor $actor ): void {
		$entry = $this->repo->entry( $entry_id );
		if ( null === $entry ) {
			return;
		}
		foreach ( $this->repo->ties( $entry['competition_id'] ) as $tie ) {
			$fixture = $tie['fixture'];
			if ( null === $fixture || null === $this->forfeit || ! in_array( $entry_id, array( $fixture['home_entry'], $fixture['away_entry'] ), true ) ) {
				continue;
			}
			if ( in_array( $fixture['status'], Rules::DECIDED_STATUSES, true ) || 'cancelled' === $fixture['status'] ) {
				continue;
			}
			$winner = $fixture['home_entry'] === $entry_id ? Rules::AWAY : Rules::HOME;
			try {
				/* translators: %s: Cupmannschaft */
				( $this->forfeit )( $fixture['id'], $winner, $actor, sprintf( __( 'Rückzug von %s.', 'ttleague' ), $entry['name'] ) );
			} catch ( ResultException $e ) {
				unset( $e );
			}
		}
		$this->sync( $entry['competition_id'], $actor->user_id );
	}

	/**
	 * Frist einer ganzen Runde setzen (TK).
	 *
	 * @return array{round:int,deadline:string,ties:int}
	 */
	public function set_round_deadline( int $competition_id, int $round, mixed $date, Actor $actor ): array {
		$this->require_tk( $actor );
		$competition = $this->competition( $competition_id );
		$deadline    = $this->deadline( $date );
		$count       = $this->db->transaction(
			function () use ( $competition, $round, $deadline, $actor ): int {
				$this->repo->lock_season( $competition['season_id'] );
				$ties = array_values( array_filter( $this->repo->ties( $competition['id'] ), static fn( array $tie ): bool => $tie['round'] === $round ) );
				if ( array() === $ties ) {
					throw ResultException::not_found( __( 'Diese Runde gibt es im Tableau nicht.', 'ttleague' ) );
				}
				$this->db->update(
					'cup_tie',
					array( 'deadline' => $deadline ),
					array(
						'competition_id' => $competition['id'],
						'round'          => $round,
					)
				);
				$this->audit->record(
					'competition',
					$competition['id'],
					'cup_round_deadline',
					$actor->user_id,
					array(
						'round'     => $round,
						'deadlines' => array_values( array_unique( array_column( $ties, 'deadline' ) ) ),
					),
					array(
						'round'    => $round,
						'deadline' => $deadline,
					)
				);
				return count( $ties );
			}
		);
		$this->events->changed( $competition['id'], CupEvents::DEADLINE, $actor->user_id );
		return array(
			'round'    => $round,
			'deadline' => $deadline,
			'ties'     => $count,
		);
	}

	/**
	 * Frist einer einzelnen Paarung setzen oder verlängern (TK).
	 *
	 * @return array{tie:int,deadline:string}
	 */
	public function set_tie_deadline( int $tie_id, mixed $date, Actor $actor ): array {
		$this->require_tk( $actor );
		$tie         = $this->tie( $tie_id );
		$competition = $this->competition( $tie['competition_id'] );
		$deadline    = $this->deadline( $date );
		$this->db->transaction(
			function () use ( $competition, $tie_id, $deadline, $actor ): void {
				$this->repo->lock_season( $competition['season_id'] );
				$current = $this->tie( $tie_id );
				$this->db->update( 'cup_tie', array( 'deadline' => $deadline ), array( 'id' => $tie_id ) );
				$this->audit->record( 'cup_tie', $tie_id, 'deadline', $actor->user_id, array( 'deadline' => $current['deadline'] ), array( 'deadline' => $deadline ) );
			}
		);
		$this->events->changed( $competition['id'], CupEvents::DEADLINE, $actor->user_id );
		return array(
			'tie'      => $tie_id,
			'deadline' => $deadline,
		);
	}

	/**
	 * Halbfinal oder Final mit Termin und Ort ansetzen (TK, Art. 23.1).
	 *
	 * @param array<string,mixed> $payload scheduled_at (Ortszeit), venue_id (leer: ohne Lokal).
	 * @return array{tie:int,fixture:int,scheduled_at:string,venue_id:int|null}
	 */
	public function schedule( int $tie_id, Actor $actor, array $payload ): array {
		$this->require_tk( $actor );
		$tie         = $this->tie( $tie_id );
		$competition = $this->competition( $tie['competition_id'] );
		if ( ! Rules::is_neutral( $tie['round'], $this->rounds( $competition['id'] ) ) ) {
			throw ResultException::conflict( __( 'Nur Halbfinal und Final setzt die TK an. In den früheren Runden schlägt das Heimteam den Termin vor.', 'ttleague' ) );
		}

		$errors = array();
		$local  = $this->clock->now()->setTimezone( $this->timezone );
		$when   = CupInput::datetime( $payload['scheduled_at'] ?? null, $this->timezone );
		if ( null === $when ) {
			$errors[] = ResultInput::error( 'scheduled_at', __( 'Bitte gib Datum und Zeit an.', 'ttleague' ) );
		} elseif ( $when <= $local->format( 'Y-m-d H:i:s' ) ) {
			$errors[] = ResultInput::error( 'scheduled_at', __( 'Der Termin liegt in der Vergangenheit.', 'ttleague' ) );
		} elseif ( substr( $when, 0, 10 ) < $competition['season_start'] || substr( $when, 0, 10 ) > $competition['season_end'] ) {
			$errors[] = ResultInput::error( 'scheduled_at', __( 'Der Termin liegt ausserhalb der Saison.', 'ttleague' ) );
		}
		$venue = null;
		if ( ! CupInput::blank( $payload['venue_id'] ?? null ) ) {
			$venue = (int) $payload['venue_id'];
			if ( ! isset( $this->repo->venues()[ $venue ] ) ) {
				$errors[] = ResultInput::error( 'venue_id', __( 'Dieses Lokal gibt es nicht.', 'ttleague' ) );
			}
		}
		if ( array() !== $errors ) {
			throw ResultException::invalid( $errors );
		}
		$when = (string) $when;

		$fixture_id = $this->db->transaction(
			function () use ( $competition, $tie_id, $actor, $when, $venue ): int {
				$this->repo->lock_season( $competition['season_id'] );
				$current = $this->tie( $tie_id );
				$fixture = $current['fixture'];
				if ( null === $fixture ) {
					throw ResultException::conflict( __( 'Die Begegnung steht noch nicht fest.', 'ttleague' ) );
				}
				if ( ! in_array( $fixture['status'], Rules::OPEN_STATUSES, true ) ) {
					throw ResultException::conflict( __( 'Die Begegnung hat schon ein Resultat.', 'ttleague' ) );
				}
				$before = array(
					'scheduled_at' => $fixture['scheduled_at'],
					'venue_id'     => $fixture['venue_id'],
				);
				$after  = array(
					'scheduled_at' => $when,
					'venue_id'     => $venue,
				);
				$this->fixtures->update_fixture( $fixture['id'], $after );
				$place = null === $venue ? '' : ' ' . $this->repo->venues()[ $venue ] . '.';
				$this->fixtures->add_event(
					$fixture['id'],
					'postponed',
					$actor->user_id,
					$actor->person_id,
					/* translators: %s: Datum und Zeit */
					sprintf( __( 'Termin: %s.', 'ttleague' ), Format::datetime( $when ) ) . $place,
					array(
						'action'   => null === $fixture['scheduled_at'] ? 'propose' : 'postpone',
						'old_date' => $fixture['scheduled_at'],
						'new_date' => $when,
					),
					$this->now()
				);
				$this->audit->record( 'fixture', $fixture['id'], 'date_set', $actor->user_id, $before, $after );
				return $fixture['id'];
			}
		);
		$this->events->changed( $competition['id'], CupEvents::SCHEDULED, $actor->user_id, array( $tie_id ) );
		return array(
			'tie'          => $tie_id,
			'fixture'      => $fixture_id,
			'scheduled_at' => $when,
			'venue_id'     => $venue,
		);
	}

	/**
	 * Heimrecht einer Begegnung tauschen (TK), solange sie kein Resultat hat. Im Halbfinal und im
	 * Final gibt es kein Heimrecht.
	 *
	 * @return array{tie:int,fixture:int,home:int,away:int}
	 */
	public function swap_home( int $tie_id, Actor $actor ): array {
		$this->require_tk( $actor );
		$tie         = $this->tie( $tie_id );
		$competition = $this->competition( $tie['competition_id'] );
		if ( Rules::is_neutral( $tie['round'], $this->rounds( $competition['id'] ) ) ) {
			throw ResultException::conflict( __( 'Im Halbfinal und im Final gibt es kein Heimrecht.', 'ttleague' ) );
		}
		$result = $this->db->transaction(
			function () use ( $competition, $tie_id, $actor ): array {
				$this->repo->lock_season( $competition['season_id'] );
				$fixture = $this->tie( $tie_id )['fixture'];
				if ( null === $fixture || null === $fixture['home_entry'] || null === $fixture['away_entry'] ) {
					throw ResultException::conflict( __( 'Die Begegnung steht noch nicht fest.', 'ttleague' ) );
				}
				if ( ! Rules::fixture_untouched( $fixture['status'], $fixture['home_score'], $fixture['away_score'] ) ) {
					throw ResultException::conflict( __( 'Die Begegnung hat schon ein Resultat.', 'ttleague' ) );
				}
				$after = array(
					'home_entry_id' => $fixture['away_entry'],
					'away_entry_id' => $fixture['home_entry'],
				);
				$this->fixtures->update_fixture( $fixture['id'], $after );
				$this->audit->record(
					'fixture',
					$fixture['id'],
					'home_swapped',
					$actor->user_id,
					array(
						'home_entry_id' => $fixture['home_entry'],
						'away_entry_id' => $fixture['away_entry'],
					),
					$after
				);
				return array(
					'tie'     => $tie_id,
					'fixture' => $fixture['id'],
					'home'    => $fixture['away_entry'],
					'away'    => $fixture['home_entry'],
				);
			}
		);
		$this->events->changed( $competition['id'], CupEvents::HOME_SWAPPED, $actor->user_id, array( $tie_id ) );
		return $result;
	}

	/**
	 * Tableau für das Backend: Runden mit ihren Paarungen, Mannschaften, Begegnung und Stand.
	 *
	 * @return array{rounds:int,entries:array<int,array<string,mixed>>,ties:array<int,list<array<string,mixed>>>}
	 */
	public function tableau( int $competition_id ): array {
		$entries = array_column( $this->repo->entries( $competition_id ), null, 'id' );
		$ties    = $this->repo->ties( $competition_id );
		$rounds  = array() === $ties ? 0 : max( array_column( $ties, 'round' ) );
		$today   = $this->today();
		$out     = array();
		foreach ( $ties as $tie ) {
			$fixture = $tie['fixture'];
			$open    = null !== $fixture && in_array( $fixture['status'], Rules::OPEN_STATUSES, true );
			$neutral = Rules::is_neutral( $tie['round'], $rounds );

			$out[ $tie['round'] ][] = $tie + array(
				'neutral'      => $neutral,
				'overdue'      => $open && null !== $tie['deadline'] && $tie['deadline'] < $today,
				'can_swap'     => ! $neutral && null !== $fixture && Rules::fixture_untouched( $fixture['status'], $fixture['home_score'], $fixture['away_score'] ),
				'can_schedule' => $neutral && $open,
			);
		}
		return array(
			'rounds'  => $rounds,
			'entries' => $entries,
			'ties'    => $out,
		);
	}

	/**
	 * Für die Übersicht der TK: Begegnungen aktiver Saisons ohne Resultat, deren Frist abgelaufen
	 * ist (die Frist gilt bis und mit ihrem Tag).
	 *
	 * @return list<array{tie:int,competition_id:int,competition:string,round:int,rounds:int,home:string,away:string,deadline:string,fixture:int,scheduled_at:string|null}>
	 */
	public function overdue(): array {
		$today = $this->today();
		$list  = array();
		foreach ( $this->repo->active_competitions_with_draw() as $competition_id ) {
			$competition = $this->repo->competition( $competition_id );
			$ties        = $this->repo->ties( $competition_id );
			$names       = array_column( $this->repo->entries( $competition_id ), 'name', 'id' );
			$rounds      = array() === $ties ? 0 : max( array_column( $ties, 'round' ) );
			foreach ( $ties as $tie ) {
				$fixture = $tie['fixture'];
				if ( null === $competition || null === $fixture || null === $tie['deadline'] || $tie['deadline'] >= $today || ! in_array( $fixture['status'], Rules::OPEN_STATUSES, true ) ) {
					continue;
				}
				$list[] = array(
					'tie'            => $tie['id'],
					'competition_id' => $competition_id,
					'competition'    => $competition['name'],
					'round'          => $tie['round'],
					'rounds'         => $rounds,
					'home'           => (string) ( $names[ (int) $fixture['home_entry'] ] ?? '' ),
					'away'           => (string) ( $names[ (int) $fixture['away_entry'] ] ?? '' ),
					'deadline'       => $tie['deadline'],
					'fixture'        => $fixture['id'],
					'scheduled_at'   => $fixture['scheduled_at'],
				);
			}
		}
		usort( $list, static fn( array $a, array $b ): int => array( $a['deadline'], $a['tie'] ) <=> array( $b['deadline'], $b['tie'] ) );
		return $list;
	}

	/**
	 * Hinweise für die TK: Paarungen, in denen nach einer Korrektur eine andere Mannschaft stehen
	 * müsste, die aber schon ein Resultat haben.
	 *
	 * @return list<array{tie:int,round:int,slot:int,fixture:int,current:string,expected:string}>
	 */
	public function conflicts( int $competition_id ): array {
		$names = array_column( $this->repo->entries( $competition_id ), 'name', 'id' );
		$list  = array();
		foreach ( $this->walk( $competition_id, false, 0 )['blocked'] as $blocked ) {
			$list[] = array(
				'tie'      => $blocked['tie'],
				'round'    => $blocked['round'],
				'slot'     => $blocked['slot'],
				'fixture'  => $blocked['fixture'],
				'current'  => null === $blocked['current'] ? '' : (string) ( $names[ $blocked['current'] ] ?? '' ),
				'expected' => null === $blocked['expected'] ? '' : (string) ( $names[ $blocked['expected'] ] ?? '' ),
			);
		}
		return $list;
	}

	/**
	 * Anzahl Runden des Tableaus (0 ohne Auslosung).
	 */
	public function rounds( int $competition_id ): int {
		$ties = $this->repo->ties( $competition_id );
		return array() === $ties ? 0 : max( array_column( $ties, 'round' ) );
	}

	/**
	 * Geht das Tableau Runde für Runde durch. Mit $apply werden Begegnungen angelegt und Sieger
	 * nachgerückt; ohne wird nur gemeldet, was sich nicht nachrücken lässt.
	 *
	 * @return array{created:list<int>,changed:bool,blocked:list<array<string,mixed>>}
	 */
	private function walk( int $competition_id, bool $apply, int $user_id ): array {
		$report = self::empty_report();
		$ties   = array();
		foreach ( $this->repo->ties( $competition_id ) as $tie ) {
			$ties[ $tie['round'] ][ $tie['slot'] ] = $tie;
		}
		if ( array() === $ties ) {
			return $report;
		}
		$rounds    = max( array_keys( $ties ) );
		$withdrawn = array();
		foreach ( $this->repo->entries( $competition_id ) as $entry ) {
			if ( CupRepository::WITHDRAWN === $entry['status'] ) {
				$withdrawn[ $entry['id'] ] = true;
			}
		}

		$outcomes = array();
		for ( $round = 1; $round <= $rounds; $round++ ) {
			foreach ( array_keys( $ties[ $round ] ?? array() ) as $slot ) {
				$tie   = $ties[ $round ][ $slot ];
				$final = array();
				foreach ( array( Rules::HOME, Rules::AWAY ) as $side ) {
					$feeder         = Rules::feeder( $round, $slot, $side );
					$final[ $side ] = null === $feeder || Rules::PENDING !== ( $outcomes[ $feeder['round'] ][ $feeder['slot'] ]['state'] ?? Rules::PENDING );
				}

				if ( $apply && null === $tie['fixture'] && null !== $tie['home'] && null !== $tie['away'] && empty( $withdrawn[ $tie['home'] ] ) && empty( $withdrawn[ $tie['away'] ] ) ) {
					$tie                 = $this->create_fixture( $tie, $user_id );
					$report['created'][] = $tie['id'];
				}
				$fixture = $tie['fixture'];
				$outcome = Rules::outcome( $tie['home'], $tie['away'], $final[ Rules::HOME ], $final[ Rules::AWAY ], $withdrawn, $fixture );
				if ( $apply && Rules::BY_WALKOVER === $outcome['by'] && null !== $fixture && Rules::fixture_untouched( $fixture['status'], $fixture['home_score'], $fixture['away_score'] ) ) {
					// Eine Mannschaft ist zurückgezogen und die Begegnung liess sich nicht forfait werten.
					$tie               = $this->cancel_fixture( $tie, $user_id );
					$report['changed'] = true;
				}
				$ties[ $round ][ $slot ]     = $tie;
				$outcomes[ $round ][ $slot ] = $outcome;

				$next = Rules::next( $round, $slot, $rounds );
				if ( null === $next || ! isset( $ties[ $next['round'] ][ $next['slot'] ] ) ) {
					continue;
				}
				$target   = $ties[ $next['round'] ][ $next['slot'] ];
				$expected = Rules::DECIDED === $outcome['state'] ? $outcome['winner'] : null;
				$current  = $target[ $next['side'] ];
				if ( $current === $expected ) {
					continue;
				}
				$played = $target['fixture'];
				if ( null !== $played && ! Rules::fixture_untouched( $played['status'], $played['home_score'], $played['away_score'] ) ) {
					$report['blocked'][] = array(
						'tie'      => $target['id'],
						'round'    => $target['round'],
						'slot'     => $target['slot'],
						'side'     => $next['side'],
						'fixture'  => $played['id'],
						'current'  => $current,
						'expected' => $expected,
					);
					continue;
				}
				if ( ! $apply ) {
					continue;
				}
				if ( null !== $played ) {
					$target = $this->cancel_fixture( $target, $user_id );
				}
				$this->db->update( 'cup_tie', array( $next['side'] . '_entry_id' => $expected ), array( 'id' => $target['id'] ) );
				$this->audit->record(
					'cup_tie',
					$target['id'],
					'advanced',
					0 === $user_id ? null : $user_id,
					array( $next['side'] => $current ),
					array(
						$next['side'] => $expected,
						'from'        => array( $round, $slot ),
						'by'          => $outcome['by'],
					)
				);
				$target[ $next['side'] ]                 = $expected;
				$ties[ $next['round'] ][ $next['slot'] ] = $target;
				$report['changed']                       = true;
			}
		}
		return $report;
	}

	/**
	 * Legt die Begegnung einer Paarung an: ohne Termin, der obere Platz hat Heimrecht.
	 *
	 * @param array<string,mixed> $tie Paarung.
	 * @return array<string,mixed> Paarung mit Begegnung.
	 */
	private function create_fixture( array $tie, int $user_id ): array {
		$fixture_id = $this->db->insert(
			'fixture',
			array(
				'competition_id' => $tie['competition_id'],
				'round'          => $tie['round'],
				'match_no'       => 100 * $tie['round'] + $tie['slot'],
				'scheduled_at'   => null,
				'home_entry_id'  => $tie['home'],
				'away_entry_id'  => $tie['away'],
				'status'         => 'scheduled',
			)
		);
		$this->db->update( 'cup_tie', array( 'fixture_id' => $fixture_id ), array( 'id' => $tie['id'] ) );
		$this->audit->record(
			'cup_tie',
			$tie['id'],
			'fixture_created',
			0 === $user_id ? null : $user_id,
			null,
			array(
				'fixture_id' => $fixture_id,
				'home'       => $tie['home'],
				'away'       => $tie['away'],
			)
		);
		$tie['fixture'] = array(
			'id'           => $fixture_id,
			'status'       => 'scheduled',
			'scheduled_at' => null,
			'venue_id'     => null,
			'home_entry'   => $tie['home'],
			'away_entry'   => $tie['away'],
			'home_score'   => null,
			'away_score'   => null,
		);
		return $tie;
	}

	/**
	 * Annulliert die Begegnung einer Paarung ohne Resultat und löst sie von der Paarung. Die
	 * Begegnung bleibt mit ihrem Verlauf (z. B. Terminvorschlägen) erhalten.
	 *
	 * @param array<string,mixed> $tie Paarung mit Begegnung.
	 * @return array<string,mixed> Paarung ohne Begegnung.
	 */
	private function cancel_fixture( array $tie, int $user_id ): array {
		$fixture = $tie['fixture'];
		$this->fixtures->update_fixture( $fixture['id'], array( 'status' => 'cancelled' ) );
		$this->db->update( 'cup_tie', array( 'fixture_id' => null ), array( 'id' => $tie['id'] ) );
		$this->audit->record( 'fixture', $fixture['id'], 'cup_cancelled', 0 === $user_id ? null : $user_id, array( 'status' => $fixture['status'] ), array( 'status' => 'cancelled' ) );
		$tie['fixture'] = null;
		return $tie;
	}

	/**
	 * Frist aus der Eingabe: gültiges Datum, heute oder später.
	 *
	 * @throws ResultException 422.
	 */
	private function deadline( mixed $value ): string {
		$date = CupInput::date( $value );
		if ( null === $date ) {
			throw ResultException::invalid( array( ResultInput::error( 'deadline', __( 'Bitte gib die Frist als Datum an.', 'ttleague' ) ) ) );
		}
		if ( $date < $this->today() ) {
			throw ResultException::invalid( array( ResultInput::error( 'deadline', __( 'Die Frist liegt in der Vergangenheit.', 'ttleague' ) ) ) );
		}
		return $date;
	}

	/**
	 * @return array{id:int,name:string,category:string,season_id:int,season_name:string,season_slug:string,season_status:string,season_start:string,season_end:string,format:array<string,mixed>}
	 * @throws ResultException 404 ohne Konkurrenz, 409 ausserhalb der aktiven Saison.
	 */
	private function competition( int $competition_id ): array {
		$competition = $this->repo->competition( $competition_id );
		if ( null === $competition ) {
			throw ResultException::not_found( __( 'Diese Cup-Konkurrenz gibt es nicht.', 'ttleague' ) );
		}
		if ( 'active' !== $competition['season_status'] ) {
			throw ResultException::conflict( __( 'Das Tableau lässt sich nur in der aktiven Saison ändern.', 'ttleague' ) );
		}
		return $competition;
	}

	/**
	 * @return array{id:int,competition_id:int,round:int,slot:int,home:int|null,away:int|null,deadline:string|null,fixture:array<string,mixed>|null}
	 * @throws ResultException 404, wenn es die Paarung nicht gibt.
	 */
	private function tie( int $tie_id ): array {
		$tie = $this->repo->tie( $tie_id );
		if ( null === $tie ) {
			throw ResultException::not_found( __( 'Diese Paarung gibt es nicht.', 'ttleague' ) );
		}
		return $tie;
	}

	private function require_tk( Actor $actor ): void {
		if ( ! $actor->is_tk ) {
			throw ResultException::forbidden( __( 'Das kann nur die TK.', 'ttleague' ) );
		}
	}

	/**
	 * @return array{created:list<int>,changed:bool,blocked:list<array<string,mixed>>}
	 */
	private static function empty_report(): array {
		return array(
			'created' => array(),
			'changed' => false,
			'blocked' => array(),
		);
	}

	private function today(): string {
		return $this->clock->now()->setTimezone( $this->timezone )->format( 'Y-m-d' );
	}

	private function now(): string {
		return $this->clock->now()->format( 'Y-m-d H:i:s' );
	}
}
