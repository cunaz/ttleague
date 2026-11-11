<?php
/**
 * Spielplan der geplanten Saison: Spielwochen, Entwurf, Prüfung, Verlegen, Heimrecht tauschen.
 *
 * @package TTLeague
 */

declare(strict_types=1);

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Meldungen der Ausnahmen sind Klartext; escaped wird bei der Ausgabe (Flash).

namespace TTLeague\Season;

use TTLeague\Domain\Match\FixtureWorkflow as Flow;
use TTLeague\Domain\Rules\SeasonRules;
use TTLeague\Domain\Schedule\PlannedFixture;
use TTLeague\Domain\Schedule\ScheduleGroup;
use TTLeague\Domain\Schedule\ScheduleInput;
use TTLeague\Domain\Schedule\SchedulePlanner;
use TTLeague\Domain\Schedule\ScheduleTeam;
use TTLeague\Domain\Schedule\VenueClosure;
use TTLeague\Domain\Season\ScheduleWeeks;
use TTLeague\Domain\Season\SeasonDates;
use TTLeague\Infrastructure\AuditLog;
use TTLeague\Infrastructure\Database\Db;
use TTLeague\Results\Actor;

/**
 * Schritt 5 des Assistenten (docs/saison.md, docs/spielplan.md). Die Begegnungen der geplanten
 * Saison sind ein Entwurf: nicht öffentlich, ohne Erfassung, ohne Erinnerungen. Der Planer
 * (Domain\Schedule) erzeugt und prüft; verlegt wird nie automatisch. Nach jeder Änderung von
 * Hand prüft overview() die gespeicherten Begegnungen neu.
 *
 * Erzeugen, Löschen des Entwurfs, Verlegen und Tauschen gelten nur in der geplanten Saison.
 * In der aktiven Saison setzt die TK einzelne Begegnungen an (Entscheidungsspiel, Art. 15)
 * oder sagt sie ab; verschoben wird dort über das Modul Verschiebung. Alles verlangt die TK, läuft in einer Transaktion unter der Sperre der
 * Saisons, steht im audit_log (season, fixture) und meldet danach SeasonEvents::changed().
 *
 * Alle Termine sind Ortszeit der Website, ohne Umrechnung (wie fixture.scheduled_at).
 */
final class Scheduling {

	private const MM = 'MM';

	private SeasonGuard $guard;

	public function __construct(
		private readonly Db $db,
		private readonly AuditLog $audit,
		private readonly SeasonEvents $events,
	) {
		$this->guard = new SeasonGuard( $db );
	}

	/**
	 * Alle Wochen der Saison mit der Auswahl der TK oder, solange nichts gespeichert ist, dem
	 * Vorschlag: alle Wochen ohne die Runden des Ranglistenturniers der Saison.
	 *
	 * @return array{saved:bool,weeks:list<array{monday:string,leg:int,blocked:bool}>,first_leg_weeks:list<string>,second_leg_weeks:list<string>}
	 *         «leg» ist 1 (Vorrunde), 2 (Rückrunde) oder 0 (keine Spielwoche); «blocked» markiert die Wochen der RL-Runden.
	 * @throws SeasonException Wenn es die Saison nicht gibt.
	 */
	public function weeks( int $season_id ): array {
		$season   = $this->guard->season( $season_id );
		$stored   = ScheduleWeeks::stored( $season['schedule'] );
		$saved    = array() !== $stored[ ScheduleWeeks::FIRST ] || array() !== $stored[ ScheduleWeeks::SECOND ];
		$blocked  = array_map(
			static fn( array $row ): array => array(
				'from' => $row['date_from'],
				'to'   => $row['date_to'],
			),
			$this->db->get_results(
				"SELECT r.date_from, r.date_to FROM {$this->db->table( 'rl_round' )} r
				JOIN {$this->db->table( 'competition' )} c ON c.id = r.competition_id
				WHERE c.season_id = %d AND c.type = 'RL'",
				array( $season_id )
			)
		);
		$proposal = ScheduleWeeks::proposal( (string) $season['start_date'], (string) $season['end_date'], $blocked );
		$chosen   = $saved ? $stored : $proposal;
		$weeks    = array();
		foreach ( ScheduleWeeks::mondays( (string) $season['start_date'], (string) $season['end_date'] ) as $monday ) {
			$leg = 0;
			if ( in_array( $monday, $chosen[ ScheduleWeeks::FIRST ], true ) ) {
				$leg = PlannedFixture::LEG_FIRST;
			} elseif ( in_array( $monday, $chosen[ ScheduleWeeks::SECOND ], true ) ) {
				$leg = PlannedFixture::LEG_SECOND;
			}
			$weeks[] = array(
				'monday'  => $monday,
				'leg'     => $leg,
				'blocked' => in_array( $monday, $proposal['blocked'], true ),
			);
		}
		return array(
			'saved'               => $saved,
			'weeks'               => $weeks,
			ScheduleWeeks::FIRST  => $stored[ ScheduleWeeks::FIRST ],
			ScheduleWeeks::SECOND => $stored[ ScheduleWeeks::SECOND ],
		);
	}

	/**
	 * Spielwochen der Vor- und der Rückrunde speichern (season.schedule_json).
	 *
	 * @param array<mixed> $first  Montage der Vorrunde (JJJJ-MM-TT).
	 * @param array<mixed> $second Montage der Rückrunde.
	 * @throws SeasonException Ohne TK-Recht, ausserhalb der geplanten Saison oder bei ungültigen Wochen.
	 */
	public function save_weeks( Actor $actor, int $season_id, array $first, array $second ): void {
		SeasonGuard::require_tk( $actor );
		$this->db->transaction(
			function () use ( $actor, $season_id, $first, $second ): void {
				$this->guard->lock();
				$season  = $this->guard->planned( $season_id );
				$checked = ScheduleWeeks::validate( $first, $second, (string) $season['start_date'], (string) $season['end_date'] );
				SeasonGuard::reject( $checked['errors'] );
				$weeks    = array_diff_key( $checked, array( 'errors' => true ) );
				$schedule = $weeks + $season['schedule'];
				$this->db->update(
					'season',
					array(
						'schedule_json' => (string) json_encode( $schedule, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ), // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- läuft auch ohne WordPress (Tests, Werkzeuge).
						'updated_at'    => $this->db->now(),
					),
					array( 'id' => $season_id )
				);
				$this->audit->record( 'season', $season_id, 'weeks_saved', $actor->user_id, ScheduleWeeks::stored( $season['schedule'] ), $weeks );
			}
		);
		$this->events->changed( $season_id );
	}

	/**
	 * Was für den Entwurf noch fehlt: Gruppen, Spielwochen, Heimlokal, Spieltag des Lokals,
	 * Anspielzeit, Gruppen mit weniger als zwei Mannschaften.
	 *
	 * @return list<array{field:string,message:string}>
	 * @throws SeasonException Wenn es die Saison nicht gibt.
	 */
	public function missing( int $season_id ): array {
		return $this->load( $this->guard->season( $season_id ) )['missing'];
	}

	/**
	 * Entwurf erzeugen und als Begegnungen speichern: Status «scheduled», Runde, Halbserie,
	 * laufende Nummer je Gruppe, Termin und Lokal des Heimteams. Ein neuer Entwurf ersetzt alle
	 * Begegnungen der Gruppe ($competition_id) oder aller Gruppen, solange keine ein Resultat hat.
	 *
	 * @param int|null $competition_id Nur diese Gruppe neu erzeugen; null: alle.
	 * @return array{fixtures:int,hard:int,hints:int} Geschriebene Begegnungen und Kollisionen des ganzen Plans.
	 * @throws SeasonException Ohne TK-Recht, ausserhalb der geplanten Saison, mit fehlenden
	 *                         Angaben (Feldfehler), zu wenigen Spielwochen oder nach dem ersten Resultat.
	 */
	public function generate( Actor $actor, int $season_id, ?int $competition_id = null ): array {
		SeasonGuard::require_tk( $actor );
		$written = $this->db->transaction(
			function () use ( $actor, $season_id, $competition_id ): int {
				$this->guard->lock();
				$season = $this->guard->planned( $season_id );
				$data   = $this->load( $season );
				if ( array() !== $data['missing'] ) {
					throw SeasonException::invalid( $data['missing'], __( 'Der Entwurf lässt sich noch nicht erzeugen: Es fehlen Angaben.', 'ttleague' ) );
				}
				$targets = $this->targets( $data, $competition_id );
				$this->require_no_results( $targets );
				try {
					$draft = $this->planner( $season )->plan( $data['input'] );
				} catch ( \InvalidArgumentException $e ) {
					throw SeasonException::conflict( $e->getMessage() );
				}
				$this->delete_fixtures( $targets );
				$rows    = array();
				$numbers = array();
				foreach ( $draft->fixtures as $fixture ) {
					$group_id = $data['keys'][ (string) $fixture->group_id ];
					if ( ! in_array( $group_id, $targets, true ) ) {
						continue;
					}
					$numbers[ $group_id ] = ( $numbers[ $group_id ] ?? 0 ) + 1;
					$rows[]               = array(
						'competition_id' => $group_id,
						'round'          => $fixture->round,
						'leg'            => $fixture->leg,
						'match_no'       => $numbers[ $group_id ],
						'scheduled_at'   => $fixture->starts_at->format( 'Y-m-d H:i:s' ),
						'home_team_id'   => (int) $fixture->home_team,
						'away_team_id'   => (int) $fixture->away_team,
						'venue_id'       => (int) $fixture->venue_id,
						'status'         => Flow::SCHEDULED,
					);
				}
				$this->db->insert_many( 'fixture', $rows );
				$this->guard->touch( $season_id );
				$this->audit->record(
					'season',
					$season_id,
					'schedule_generated',
					$actor->user_id,
					null,
					array(
						'competition_id' => $competition_id,
						'fixtures'       => count( $rows ),
					)
				);
				return count( $rows );
			}
		);
		$this->events->changed( $season_id );
		$overview = $this->overview( $season_id );
		return array(
			'fixtures' => $written,
			'hard'     => $overview['hard'],
			'hints'    => $overview['hints'],
		);
	}

	/**
	 * Entwurf löschen: alle Begegnungen der Gruppe oder aller Gruppen, solange keine ein Resultat hat.
	 *
	 * @param int|null $competition_id Nur diese Gruppe; null: alle.
	 * @return int Anzahl gelöschter Begegnungen.
	 * @throws SeasonException Ohne TK-Recht, ausserhalb der geplanten Saison oder nach dem ersten Resultat.
	 */
	public function clear( Actor $actor, int $season_id, ?int $competition_id = null ): int {
		SeasonGuard::require_tk( $actor );
		$deleted = $this->db->transaction(
			function () use ( $actor, $season_id, $competition_id ): int {
				$this->guard->lock();
				$season  = $this->guard->planned( $season_id );
				$targets = $this->targets( $this->load( $season ), $competition_id );
				$this->require_no_results( $targets );
				$deleted = $this->delete_fixtures( $targets );
				$this->guard->touch( $season_id );
				$this->audit->record(
					'season',
					$season_id,
					'schedule_cleared',
					$actor->user_id,
					null,
					array(
						'competition_id' => $competition_id,
						'fixtures'       => $deleted,
					)
				);
				return $deleted;
			}
		);
		$this->events->changed( $season_id );
		return $deleted;
	}

	/**
	 * Stand des Spielplans: Gruppen mit Vollständigkeit, fehlende Angaben, die gespeicherten
	 * Begegnungen und der Bericht ihrer Prüfung (Kollisionen mit Ausweichvorschlag).
	 *
	 * @return array{
	 *   groups:list<array{id:int,name:string,teams:int,fixtures:int,expected:int,complete:bool}>,
	 *   missing:list<array{field:string,message:string}>,
	 *   fixtures:array<int,array<string,mixed>>,
	 *   conflicts:list<array{kind:string,hard:bool,message:string,day:string|null,venue:string|null,club:string|null,team:string|null,fixtures:list<int>,suggestions:list<array{fixture_id:int,starts_at:string}>}>,
	 *   hard:int,
	 *   hints:int,
	 *   error:string|null,
	 *   unscheduled:int,
	 *   venues:array<int,array{id:int,name:string,club_id:int,active:bool,weekday:int|null,tables:int|null}>
	 * }
	 * @throws SeasonException Wenn es die Saison nicht gibt.
	 */
	public function overview( int $season_id ): array {
		$season    = $this->guard->season( $season_id );
		$data      = $this->load( $season );
		$fixtures  = $this->fixtures( $season_id );
		$planned   = array();
		$ids       = array();
		$conflicts = array();
		$error     = null;
		$skipped   = 0;

		foreach ( $fixtures as $id => $row ) {
			if ( Flow::CANCELLED === $row['status'] ) {
				continue;
			}
			if ( null === $row['scheduled_at'] || null === $row['venue_id'] || null === $row['home_team_id'] || null === $row['away_team_id'] ) {
				++$skipped;
				continue;
			}
			$fixture                          = new PlannedFixture(
				$data['labels'][ $row['competition_id'] ] ?? (string) $row['competition_id'],
				$row['round'],
				PlannedFixture::LEG_SECOND === $row['leg'] ? PlannedFixture::LEG_SECOND : PlannedFixture::LEG_FIRST,
				$row['home_team_id'],
				$row['away_team_id'],
				new \DateTimeImmutable( (string) $row['scheduled_at'] ),
				$row['venue_id']
			);
			$planned[]                        = $fixture;
			$ids[ spl_object_id( $fixture ) ] = $id;
		}

		$hard  = 0;
		$hints = 0;
		if ( array() !== $planned ) {
			try {
				$input  = $data['input'] ?? $this->input( $season, $data, true );
				$report = $this->planner( $season )->check( $input, $planned );
				foreach ( $report->conflicts as $conflict ) {
					$suggestions = array();
					foreach ( $conflict->suggestions as $suggestion ) {
						$suggestions[] = array(
							'fixture_id' => $ids[ spl_object_id( $suggestion->fixture ) ],
							'starts_at'  => $suggestion->starts_at->format( 'Y-m-d H:i:s' ),
						);
					}
					$conflict->is_hard() ? ++$hard : ++$hints;
					$conflicts[] = array(
						'kind'        => $conflict->kind->value,
						'hard'        => $conflict->is_hard(),
						'message'     => $conflict->message,
						'day'         => $conflict->day,
						'venue'       => null === $conflict->venue_id ? null : ( $data['venues'][ (int) $conflict->venue_id ]['name'] ?? null ),
						'club'        => null === $conflict->club_id ? null : ( $data['clubs'][ (int) $conflict->club_id ] ?? null ),
						'team'        => null === $conflict->team_id ? null : ( $data['team_names'][ (int) $conflict->team_id ] ?? null ),
						'fixtures'    => array_map( static fn( PlannedFixture $f ): int => $ids[ spl_object_id( $f ) ], $conflict->fixtures ),
						'suggestions' => $suggestions,
					);
				}
			} catch ( \InvalidArgumentException | SeasonException $e ) {
				$error = $e->getMessage();
			}
		}

		$groups = array();
		foreach ( $data['groups'] as $group ) {
			$pairs = array();
			$count = 0;
			foreach ( $fixtures as $row ) {
				if ( $row['competition_id'] === $group['id'] && Flow::CANCELLED !== $row['status'] ) {
					++$count;
					$pairs[ $row['home_team_id'] . ':' . $row['away_team_id'] ] = true;
				}
			}
			$complete = count( $group['team_ids'] ) >= 2;
			foreach ( $group['team_ids'] as $home ) {
				foreach ( $group['team_ids'] as $away ) {
					if ( $home !== $away && ! isset( $pairs[ $home . ':' . $away ] ) ) {
						$complete = false;
					}
				}
			}
			$expected = count( $group['team_ids'] ) * ( count( $group['team_ids'] ) - 1 );
			$groups[] = array(
				'id'       => $group['id'],
				'name'     => $group['name'],
				'teams'    => count( $group['team_ids'] ),
				'fixtures' => $count,
				'expected' => $expected,
				'complete' => $complete && $count === $expected,
			);
		}

		return array(
			'groups'      => $groups,
			'missing'     => $data['missing'],
			'fixtures'    => $fixtures,
			'conflicts'   => $conflicts,
			'hard'        => $hard,
			'hints'       => $hints,
			'error'       => $error,
			'unscheduled' => $skipped,
			'venues'      => $data['venues'],
		);
	}

	/**
	 * Begegnung des Entwurfs verlegen: Datum, Anspielzeit und auf Wunsch ein anderes Lokal.
	 *
	 * @param string   $date     Neuer Tag (JJJJ-MM-TT), in der Saison.
	 * @param string   $time     Anspielzeit (HH:MM).
	 * @param int|null $venue_id Neues Lokal; null: unverändert.
	 * @throws SeasonException Ohne TK-Recht, ausserhalb der geplanten Saison oder bei ungültigen Angaben.
	 */
	public function move( Actor $actor, int $fixture_id, string $date, string $time, ?int $venue_id = null ): void {
		SeasonGuard::require_tk( $actor );
		$season_id = $this->db->transaction(
			function () use ( $actor, $fixture_id, $date, $time, $venue_id ): int {
				$this->guard->lock();
				[ $fixture, $season ] = $this->draft_fixture( $fixture_id );
				$errors               = array();
				$date                 = trim( $date );
				$time                 = trim( $time );
				if ( ! SeasonDates::is_date( $date ) ) {
					$errors[] = self::error( 'date', __( 'Das Datum ist ungültig.', 'ttleague' ) );
				} elseif ( $date < $season['start_date'] || $date > $season['end_date'] ) {
					$errors[] = self::error( 'date', __( 'Das Datum liegt nicht in der Saison.', 'ttleague' ) );
				}
				if ( 1 !== preg_match( '/^(?:[01]\d|2[0-3]):[0-5]\d$/D', $time ) ) {
					$errors[] = self::error( 'time', __( 'Die Anspielzeit hat die Form «HH:MM».', 'ttleague' ) );
				}
				$venue_id = null === $venue_id || $venue_id <= 0 ? $fixture['venue_id'] : $venue_id;
				if ( null === $venue_id || null === $this->db->get_var( "SELECT id FROM {$this->db->table( 'venue' )} WHERE id = %d AND ( active = 1 OR id = %d )", array( $venue_id, (int) $fixture['venue_id'] ) ) ) {
					$errors[] = self::error( 'venue', __( 'Dieses Lokal gibt es nicht, oder es ist nicht mehr in Gebrauch.', 'ttleague' ) );
				}
				SeasonGuard::reject( $errors );
				$this->write_move(
					$actor,
					$fixture,
					array(
						'scheduled_at' => $date . ' ' . $time . ':00',
						'venue_id'     => $venue_id,
					),
					'moved'
				);
				return (int) $season['id'];
			}
		);
		$this->events->changed( $season_id );
	}

	/**
	 * Ausweichvorschlag der Prüfung übernehmen. Der Vorschlag muss im aktuellen Bericht stehen;
	 * hat sich der Plan inzwischen geändert, gilt er nicht mehr.
	 *
	 * @param string $starts_at Vorgeschlagener Termin (JJJJ-MM-TT HH:MM:SS).
	 * @throws SeasonException Ohne TK-Recht, ausserhalb der geplanten Saison oder wenn der Vorschlag nicht mehr gilt.
	 */
	public function apply_suggestion( Actor $actor, int $fixture_id, string $starts_at ): void {
		SeasonGuard::require_tk( $actor );
		$season_id = $this->db->transaction(
			function () use ( $actor, $fixture_id, $starts_at ): int {
				$this->guard->lock();
				[ $fixture, $season ] = $this->draft_fixture( $fixture_id );
				$found                = false;
				foreach ( $this->overview( (int) $season['id'] )['conflicts'] as $conflict ) {
					foreach ( $conflict['suggestions'] as $suggestion ) {
						$found = $found || ( $suggestion['fixture_id'] === $fixture_id && $suggestion['starts_at'] === $starts_at );
					}
				}
				if ( ! $found ) {
					throw SeasonException::conflict( __( 'Dieser Vorschlag gilt nicht mehr: Der Spielplan hat sich inzwischen geändert.', 'ttleague' ) );
				}
				$this->write_move( $actor, $fixture, array( 'scheduled_at' => $starts_at ), 'suggestion_applied' );
				return (int) $season['id'];
			}
		);
		$this->events->changed( $season_id );
	}

	/**
	 * Heimrecht tauschen: Die bisherige Gastmannschaft wird Heimmannschaft. Die Begegnung bleibt
	 * in ihrer Woche und fällt auf den Heimspieltag, die Anspielzeit und das Heimlokal der neuen
	 * Heimmannschaft. Mit $with_return wird auch die Begegnung der anderen Halbserie getauscht.
	 *
	 * @throws SeasonException Ohne TK-Recht, ausserhalb der geplanten Saison oder wenn der neuen
	 *                         Heimmannschaft Heimlokal, Spieltag oder Anspielzeit fehlen.
	 */
	public function swap_home( Actor $actor, int $fixture_id, bool $with_return = false ): void {
		SeasonGuard::require_tk( $actor );
		$season_id = $this->db->transaction(
			function () use ( $actor, $fixture_id, $with_return ): int {
				$this->guard->lock();
				[ $fixture, $season ] = $this->draft_fixture( $fixture_id );
				$data                 = $this->load( $season );
				$swap                 = array( $fixture );
				if ( $with_return ) {
					$other = $this->db->get_var(
						"SELECT id FROM {$this->db->table( 'fixture' )} WHERE competition_id = %d AND home_team_id = %d AND away_team_id = %d AND id <> %d AND status = %s ORDER BY id LIMIT 1",
						array( $fixture['competition_id'], (int) $fixture['away_team_id'], (int) $fixture['home_team_id'], $fixture_id, Flow::SCHEDULED )
					);
					if ( null !== $other ) {
						$swap[] = $this->draft_fixture( (int) $other )[0];
					}
				}
				foreach ( $swap as $row ) {
					$home = $data['schedule_teams'][ (int) $row['away_team_id'] ] ?? null;
					if ( null === $home ) {
						/* translators: %s: Mannschaft */
						throw SeasonException::conflict( sprintf( __( '%s kann das Heimrecht nicht übernehmen: Heimlokal, Spieltag des Lokals oder Anspielzeit fehlen.', 'ttleague' ), $data['team_names'][ (int) $row['away_team_id'] ] ?? __( 'Die Gastmannschaft', 'ttleague' ) ) );
					}
					$change = array(
						'home_team_id' => (int) $row['away_team_id'],
						'away_team_id' => (int) $row['home_team_id'],
						'venue_id'     => (int) $home->venue_id,
					);
					if ( null !== $row['scheduled_at'] ) {
						$day                    = new \DateTimeImmutable( (string) $row['scheduled_at'] );
						$monday                 = $day->modify( '-' . ( (int) $day->format( 'N' ) - 1 ) . ' days' );
						$change['scheduled_at'] = $home->home_date( $monday )->format( 'Y-m-d H:i:s' );
					}
					$this->write_move( $actor, $row, $change, 'home_swapped' );
				}
				return (int) $season['id'];
			}
		);
		$this->events->changed( $season_id );
	}

	/**
	 * Einzelne Begegnung in der aktiven Saison ansetzen (Entscheidungsspiel, Art. 15). Beide
	 * Mannschaften gehören zur Gruppe. Der Entwurf der geplanten Saison entsteht nur über
	 * generate(), damit er vollständig bleibt.
	 *
	 * @param int|null $venue_id Lokal; null: Heimlokal der Heimmannschaft.
	 * @return int ID der Begegnung.
	 * @throws SeasonException Ohne TK-Recht, ausserhalb der aktiven Saison oder bei ungültigen Angaben.
	 */
	public function add_fixture( Actor $actor, int $competition_id, int $home_team_id, int $away_team_id, string $date, string $time, ?int $venue_id = null ): int {
		SeasonGuard::require_tk( $actor );
		[ $fixture_id, $season_id ] = $this->db->transaction(
			function () use ( $actor, $competition_id, $home_team_id, $away_team_id, $date, $time, $venue_id ): array {
				$this->guard->lock();
				$group = $this->db->get_row( "SELECT id, season_id FROM {$this->db->table( 'competition' )} WHERE id = %d AND type = %s", array( $competition_id, self::MM ) );
				if ( null === $group ) {
					throw SeasonException::not_found( __( 'Diese Gruppe gibt es nicht.', 'ttleague' ) );
				}
				$season = $this->guard->active( (int) $group['season_id'] );
				$errors = array();
				$teams  = array();
				foreach ( array(
					'home' => $home_team_id,
					'away' => $away_team_id,
				) as $side => $team_id ) {
					$teams[ $side ] = $this->db->get_row( "SELECT id, home_venue_id, status FROM {$this->db->table( 'team' )} WHERE id = %d AND competition_id = %d", array( $team_id, $competition_id ) );
					if ( null === $teams[ $side ] ) {
						$errors[] = self::error( $side, __( 'Die Mannschaft gehört nicht zu dieser Gruppe.', 'ttleague' ) );
					}
				}
				if ( $home_team_id === $away_team_id ) {
					$errors[] = self::error( 'away', __( 'Heim und Gast dürfen nicht dieselbe Mannschaft sein.', 'ttleague' ) );
				}
				$date = trim( $date );
				$time = trim( $time );
				if ( ! SeasonDates::is_date( $date ) || $date < $season['start_date'] || $date > $season['end_date'] ) {
					$errors[] = self::error( 'date', __( 'Das Datum muss in der Saison liegen.', 'ttleague' ) );
				}
				if ( 1 !== preg_match( '/^(?:[01]\d|2[0-3]):[0-5]\d$/D', $time ) ) {
					$errors[] = self::error( 'time', __( 'Die Anspielzeit hat die Form «HH:MM».', 'ttleague' ) );
				}
				if ( null === $venue_id || $venue_id <= 0 ) {
					$venue_id = isset( $teams['home']['home_venue_id'] ) ? (int) $teams['home']['home_venue_id'] : null;
				}
				if ( null === $venue_id || null === $this->db->get_var( "SELECT id FROM {$this->db->table( 'venue' )} WHERE id = %d", array( $venue_id ) ) ) {
					$errors[] = self::error( 'venue', __( 'Das Lokal fehlt.', 'ttleague' ) );
				}
				SeasonGuard::reject( $errors );
				$row = array(
					'competition_id' => $competition_id,
					'match_no'       => 1 + (int) $this->db->get_var( "SELECT COALESCE( MAX( match_no ), 0 ) FROM {$this->db->table( 'fixture' )} WHERE competition_id = %d", array( $competition_id ) ),
					'scheduled_at'   => $date . ' ' . $time . ':00',
					'home_team_id'   => $home_team_id,
					'away_team_id'   => $away_team_id,
					'venue_id'       => $venue_id,
					'status'         => Flow::SCHEDULED,
				);
				$id  = $this->db->insert( 'fixture', $row );
				$this->audit->record( 'fixture', $id, 'scheduled', $actor->user_id, null, $row );
				return array( $id, (int) $season['id'] );
			}
		);
		$this->events->changed( $season_id );
		return $fixture_id;
	}

	/**
	 * Angesetzte Begegnung der aktiven Saison absagen (Status «cancelled»): Sie zählt nicht mehr
	 * und steht dem Abschluss der Saison nicht im Weg. Zurücksetzen kann sie die TK über die
	 * Erfassung.
	 *
	 * @throws SeasonException Ohne TK-Recht, ohne Begründung, ausserhalb der aktiven Saison oder
	 *                         wenn die Begegnung schon ein Resultat hat.
	 */
	public function cancel_fixture( Actor $actor, int $fixture_id, string $reason ): void {
		SeasonGuard::require_tk( $actor );
		$reason = trim( $reason );
		if ( '' === $reason ) {
			throw SeasonException::field( 'reason', __( 'Bitte begründe die Absage.', 'ttleague' ) );
		}
		$season_id = $this->db->transaction(
			function () use ( $actor, $fixture_id, $reason ): int {
				$this->guard->lock();
				[ $fixture, $season ] = $this->fixture( $fixture_id );
				$this->guard->active( (int) $season['id'] );
				if ( ! in_array( $fixture['status'], array( Flow::SCHEDULED, Flow::POSTPONED ), true ) || null !== $fixture['home_score'] ) {
					throw SeasonException::conflict( __( 'Absagen lässt sich nur eine Begegnung ohne Resultat.', 'ttleague' ) );
				}
				$this->db->update( 'fixture', array( 'status' => Flow::CANCELLED ), array( 'id' => $fixture_id ) );
				$this->audit->record( 'fixture', $fixture_id, 'cancelled', $actor->user_id, array( 'status' => $fixture['status'] ), array( 'status' => Flow::CANCELLED ), $reason );
				return (int) $season['id'];
			}
		);
		$this->events->changed( $season_id );
	}

	/**
	 * Meisterschaftsbegegnungen einer Saison mit Namen, nach Gruppe und Termin.
	 *
	 * @return array<int,array{id:int,competition_id:int,group:string,round:int|null,leg:int|null,match_no:int|null,scheduled_at:string|null,home_team_id:int|null,away_team_id:int|null,home:string,away:string,venue_id:int|null,venue:string|null,status:string,home_score:int|null}>
	 */
	public function fixtures( int $season_id ): array {
		$team     = $this->db->table( 'team' );
		$fixtures = array();
		foreach ( $this->db->get_results(
			"SELECT f.id, f.competition_id, c.name AS group_name, f.round, f.leg, f.match_no, f.scheduled_at, f.home_team_id, f.away_team_id,
				f.venue_id, f.status, f.home_score, h.name AS home, a.name AS away, v.name AS venue
			FROM {$this->db->table( 'fixture' )} f
			JOIN {$this->db->table( 'competition' )} c ON c.id = f.competition_id
			LEFT JOIN {$team} h ON h.id = f.home_team_id
			LEFT JOIN {$team} a ON a.id = f.away_team_id
			LEFT JOIN {$this->db->table( 'venue' )} v ON v.id = f.venue_id
			WHERE c.season_id = %d AND c.type = %s
			ORDER BY c.sort_order, c.category, c.group_no, c.id, f.scheduled_at IS NULL, f.scheduled_at, f.match_no, f.id",
			array( $season_id, self::MM )
		) as $row ) {
			$int                          = static fn( ?string $value ): ?int => null === $value ? null : (int) $value;
			$fixtures[ (int) $row['id'] ] = array(
				'id'             => (int) $row['id'],
				'competition_id' => (int) $row['competition_id'],
				'group'          => (string) $row['group_name'],
				'round'          => $int( $row['round'] ),
				'leg'            => $int( $row['leg'] ),
				'match_no'       => $int( $row['match_no'] ),
				'scheduled_at'   => $row['scheduled_at'],
				'home_team_id'   => $int( $row['home_team_id'] ),
				'away_team_id'   => $int( $row['away_team_id'] ),
				'home'           => (string) $row['home'],
				'away'           => (string) $row['away'],
				'venue_id'       => $int( $row['venue_id'] ),
				'venue'          => $row['venue'],
				'status'         => (string) $row['status'],
				'home_score'     => $int( $row['home_score'] ),
			);
		}
		return $fixtures;
	}

	/**
	 * Gruppen, Mannschaften, Lokale und Hallensperren einer Saison, die fehlenden Angaben und,
	 * wenn nichts fehlt, die Eingabe für den Planer.
	 *
	 * @param array<string,mixed> $season Saison.
	 * @return array{
	 *   groups:list<array{id:int,name:string,label:string,team_ids:list<int>}>,
	 *   labels:array<int,string>,
	 *   keys:array<string,int>,
	 *   team_names:array<int,string>,
	 *   clubs:array<int,string>,
	 *   venues:array<int,array{id:int,name:string,club_id:int,active:bool,weekday:int|null,tables:int|null}>,
	 *   schedule_teams:array<int,ScheduleTeam>,
	 *   missing:list<array{field:string,message:string}>,
	 *   input:ScheduleInput|null
	 * }
	 */
	private function load( array $season ): array {
		$season_id = (int) $season['id'];
		$venues    = array();
		foreach ( $this->db->get_results( "SELECT id, name, club_id, active, weekday, tables_count FROM {$this->db->table( 'venue' )} ORDER BY name, id" ) as $row ) {
			$venues[ (int) $row['id'] ] = array(
				'id'      => (int) $row['id'],
				'name'    => (string) $row['name'],
				'club_id' => (int) $row['club_id'],
				'active'  => '1' === (string) $row['active'],
				'weekday' => null === $row['weekday'] ? null : (int) $row['weekday'],
				'tables'  => null === $row['tables_count'] || (int) $row['tables_count'] < 1 ? null : (int) $row['tables_count'],
			);
		}

		$groups  = array();
		$labels  = array();
		$keys    = array();
		$missing = array();
		$rows    = $this->db->get_results(
			"SELECT id, name, category, group_no FROM {$this->db->table( 'competition' )} WHERE season_id = %d AND type = %s ORDER BY sort_order, category, group_no, id",
			array( $season_id, self::MM )
		);
		$per_cat = array_count_values( array_map( static fn( array $row ): string => (string) $row['category'], $rows ) );
		foreach ( $rows as $row ) {
			$id       = (int) $row['id'];
			$category = (string) $row['category'];
			$label    = '' === $category ? (string) $row['name'] : $category . ( $per_cat[ $category ] > 1 ? max( 1, (int) $row['group_no'] ) : '' );
			if ( isset( $keys[ $label ] ) ) {
				$label .= ' #' . $id;
			}
			$keys[ $label ] = $id;
			$labels[ $id ]  = $label;
			$groups[ $id ]  = array(
				'id'       => $id,
				'name'     => (string) $row['name'],
				'label'    => $label,
				'team_ids' => array(),
			);
		}
		if ( array() === $groups ) {
			$missing[] = self::error( 'groups', __( 'Es gibt noch keine Gruppen.', 'ttleague' ) );
		}
		$weeks = ScheduleWeeks::stored( $season['schedule'] );
		if ( array() === $weeks[ ScheduleWeeks::FIRST ] ) {
			$missing[] = self::error( ScheduleWeeks::FIRST, __( 'Die Spielwochen der Vorrunde sind noch nicht gewählt.', 'ttleague' ) );
		}
		if ( array() === $weeks[ ScheduleWeeks::SECOND ] ) {
			$missing[] = self::error( ScheduleWeeks::SECOND, __( 'Die Spielwochen der Rückrunde sind noch nicht gewählt.', 'ttleague' ) );
		}

		$team_names     = array();
		$clubs          = array();
		$schedule_teams = array();
		foreach ( $this->db->get_results(
			"SELECT t.id, t.name, t.club_id, t.competition_id, t.home_venue_id, t.default_start_time, t.status, c.name AS club
			FROM {$this->db->table( 'team' )} t
			JOIN {$this->db->table( 'club' )} c ON c.id = t.club_id
			WHERE t.season_id = %d
			ORDER BY t.name, t.id",
			array( $season_id )
		) as $row ) {
			$id                             = (int) $row['id'];
			$team_names[ $id ]              = (string) $row['name'];
			$clubs[ (int) $row['club_id'] ] = (string) $row['club'];
			$group_id                       = null === $row['competition_id'] ? null : (int) $row['competition_id'];
			if ( null === $group_id || ! isset( $groups[ $group_id ] ) || 'active' !== $row['status'] ) {
				continue;
			}
			$groups[ $group_id ]['team_ids'][] = $id;
			$venue                             = null === $row['home_venue_id'] ? null : ( $venues[ (int) $row['home_venue_id'] ] ?? null );
			$start                             = null === $row['default_start_time'] ? '' : substr( (string) $row['default_start_time'], 0, 5 );
			$complete                          = true;
			if ( null === $venue ) {
				/* translators: %s: Mannschaft */
				$missing[] = self::error( 'team.' . $id . '.venue', sprintf( __( '%s: Das Heimlokal fehlt.', 'ttleague' ), $row['name'] ) );
				$complete  = false;
			} elseif ( null === $venue['weekday'] || $venue['weekday'] < 1 || $venue['weekday'] > 7 ) {
				/* translators: 1: Mannschaft, 2: Lokal */
				$missing[] = self::error( 'team.' . $id . '.weekday', sprintf( __( '%1$s: Dem Lokal «%2$s» fehlt der Spieltag.', 'ttleague' ), $row['name'], $venue['name'] ) );
				$complete  = false;
			}
			if ( 1 !== preg_match( '/^(?:[01]\d|2[0-3]):[0-5]\d$/D', $start ) ) {
				/* translators: %s: Mannschaft */
				$missing[] = self::error( 'team.' . $id . '.start', sprintf( __( '%s: Die Anspielzeit fehlt.', 'ttleague' ), $row['name'] ) );
				$complete  = false;
			}
			if ( $complete ) {
				$schedule_teams[ $id ] = new ScheduleTeam( $id, (int) $row['club_id'], $venue['id'], (int) $venue['weekday'], $start );
			}
		}
		foreach ( $groups as $group ) {
			if ( count( $group['team_ids'] ) < 2 ) {
				/* translators: %s: Gruppe */
				$missing[] = self::error( 'group.' . $group['id'], sprintf( __( '%s: Eine Gruppe braucht mindestens zwei Mannschaften.', 'ttleague' ), $group['name'] ) );
			}
		}

		$data = array(
			'groups'         => array_values( $groups ),
			'labels'         => $labels,
			'keys'           => $keys,
			'team_names'     => $team_names,
			'clubs'          => $clubs,
			'venues'         => $venues,
			'schedule_teams' => $schedule_teams,
			'missing'        => $missing,
			'input'          => null,
		);
		if ( array() === $missing ) {
			try {
				$data['input'] = $this->input( $season, $data, false );
			} catch ( SeasonException $e ) {
				$data['missing'][] = self::error( ScheduleWeeks::SECOND, $e->getMessage() );
			}
		}
		return $data;
	}

	/**
	 * Eingabe für den Planer. Für die Prüfung eines bestehenden Plans ($lenient) zählen nur die
	 * Mannschaften mit vollständigen Angaben und Gruppen mit mindestens zwei davon; ungültig
	 * gespeicherte Spielwochen fallen weg (dann gibt es keine Ausweichvorschläge).
	 *
	 * @param array<string,mixed> $season  Saison.
	 * @param array<string,mixed> $data    Aus load().
	 * @param bool                $lenient Für check() statt plan().
	 */
	private function input( array $season, array $data, bool $lenient ): ScheduleInput {
		$groups = array();
		foreach ( $data['groups'] as $group ) {
			$teams = array();
			foreach ( $group['team_ids'] as $team_id ) {
				if ( isset( $data['schedule_teams'][ $team_id ] ) ) {
					$teams[] = $data['schedule_teams'][ $team_id ];
				}
			}
			if ( count( $teams ) >= 2 ) {
				$groups[] = new ScheduleGroup( $group['label'], $teams );
			}
		}
		$closures = array();
		foreach ( $this->db->get_results(
			"SELECT venue_id, date_from, date_to, reason FROM {$this->db->table( 'venue_closure' )} WHERE date_to >= %s AND date_from <= %s ORDER BY date_from, id",
			array( (string) $season['start_date'], (string) $season['end_date'] )
		) as $row ) {
			if ( $row['date_from'] <= $row['date_to'] ) {
				$closures[] = new VenueClosure( (int) $row['venue_id'], new \DateTimeImmutable( (string) $row['date_from'] ), new \DateTimeImmutable( (string) $row['date_to'] ), (string) $row['reason'] );
			}
		}
		$tables = array();
		foreach ( $data['venues'] as $venue ) {
			$tables[ $venue['id'] ] = $venue['tables'];
		}
		$weeks = ScheduleWeeks::stored( $season['schedule'] );
		$dates = static fn( array $days ): array => array_map( static fn( string $day ): \DateTimeImmutable => new \DateTimeImmutable( $day ), $days );
		try {
			return new ScheduleInput( $groups, $dates( $weeks[ ScheduleWeeks::FIRST ] ), $dates( $weeks[ ScheduleWeeks::SECOND ] ), $closures, $tables );
		} catch ( \InvalidArgumentException $e ) {
			if ( ! $lenient ) {
				throw SeasonException::conflict( $e->getMessage() );
			}
			return new ScheduleInput( $groups, array(), array(), $closures, $tables );
		}
	}

	/**
	 * @param array<string,mixed> $season Saison.
	 * @throws SeasonException Wenn die Regeln der Saison ungültig sind.
	 */
	private function planner( array $season ): SchedulePlanner {
		try {
			return new SchedulePlanner( SeasonRules::from_array( $season['rules'] )->schedule_tables_per_match );
		} catch ( \InvalidArgumentException $e ) {
			/* translators: %s: Fehlermeldung */
			throw SeasonException::conflict( sprintf( __( 'Die Regeln der Saison sind ungültig: %s', 'ttleague' ), $e->getMessage() ) );
		}
	}

	/**
	 * Gruppen, deren Entwurf ersetzt oder gelöscht wird.
	 *
	 * @param array<string,mixed> $data           Aus load().
	 * @param int|null            $competition_id Eine Gruppe oder null für alle.
	 * @return list<int>
	 * @throws SeasonException Wenn die Gruppe nicht zur Saison gehört.
	 */
	private function targets( array $data, ?int $competition_id ): array {
		$ids = array_column( $data['groups'], 'id' );
		if ( null === $competition_id ) {
			return $ids;
		}
		if ( ! in_array( $competition_id, $ids, true ) ) {
			throw SeasonException::not_found( __( 'Diese Gruppe gehört nicht zur geplanten Saison.', 'ttleague' ) );
		}
		return array( $competition_id );
	}

	/**
	 * @param list<int> $competition_ids Gruppen.
	 * @throws SeasonException Wenn eine Begegnung der Gruppen schon ein Resultat hat.
	 */
	private function require_no_results( array $competition_ids ): void {
		if ( array() === $competition_ids ) {
			return;
		}
		$in      = implode( ',', array_map( 'intval', $competition_ids ) ); // Nur Ganzzahlen.
		$fixture = $this->db->table( 'fixture' );
		$played  = (int) $this->db->get_var(
			"SELECT COUNT(*) FROM {$fixture} f WHERE f.competition_id IN ( {$in} ) AND (
				f.status <> %s OR f.home_score IS NOT NULL OR f.entered_at IS NOT NULL
				OR EXISTS ( SELECT 1 FROM {$this->db->table( 'game' )} g WHERE g.fixture_id = f.id )
				OR EXISTS ( SELECT 1 FROM {$this->db->table( 'lineup' )} l WHERE l.fixture_id = f.id )
			)",
			array( Flow::SCHEDULED )
		);
		if ( $played > 0 ) {
			throw SeasonException::conflict( __( 'Es gibt schon Begegnungen mit Resultat oder Entscheid. Der Entwurf lässt sich nicht mehr ersetzen.', 'ttleague' ) );
		}
	}

	/**
	 * @param list<int> $competition_ids Gruppen.
	 */
	private function delete_fixtures( array $competition_ids ): int {
		$deleted = 0;
		foreach ( $competition_ids as $competition_id ) {
			$deleted += $this->db->delete( 'fixture', array( 'competition_id' => $competition_id ) );
		}
		return $deleted;
	}

	/**
	 * Meisterschaftsbegegnung mit ihrer Saison.
	 *
	 * @return array{0:array<string,mixed>,1:array<string,mixed>}
	 * @throws SeasonException Wenn es die Begegnung nicht gibt.
	 */
	private function fixture( int $fixture_id ): array {
		$row = $this->db->get_row(
			"SELECT f.id, f.competition_id, f.scheduled_at, f.home_team_id, f.away_team_id, f.venue_id, f.status, f.home_score, c.season_id
			FROM {$this->db->table( 'fixture' )} f
			JOIN {$this->db->table( 'competition' )} c ON c.id = f.competition_id
			WHERE f.id = %d AND c.type = %s",
			array( $fixture_id, self::MM )
		);
		if ( null === $row ) {
			throw SeasonException::not_found( __( 'Diese Begegnung gibt es nicht.', 'ttleague' ) );
		}
		$fixture = array(
			'id'             => (int) $row['id'],
			'competition_id' => (int) $row['competition_id'],
			'scheduled_at'   => $row['scheduled_at'],
			'home_team_id'   => null === $row['home_team_id'] ? null : (int) $row['home_team_id'],
			'away_team_id'   => null === $row['away_team_id'] ? null : (int) $row['away_team_id'],
			'venue_id'       => null === $row['venue_id'] ? null : (int) $row['venue_id'],
			'status'         => (string) $row['status'],
			'home_score'     => null === $row['home_score'] ? null : (int) $row['home_score'],
		);
		return array( $fixture, $this->guard->season( (int) $row['season_id'] ) );
	}

	/**
	 * Begegnung des Entwurfs: geplante Saison, Status «scheduled».
	 *
	 * @return array{0:array<string,mixed>,1:array<string,mixed>}
	 * @throws SeasonException Wenn es die Begegnung nicht gibt oder sie kein Entwurf mehr ist.
	 */
	private function draft_fixture( int $fixture_id ): array {
		[ $fixture, $season ] = $this->fixture( $fixture_id );
		$this->guard->planned( (int) $season['id'] );
		if ( Flow::SCHEDULED !== $fixture['status'] ) {
			throw SeasonException::conflict( __( 'Diese Begegnung ist nicht mehr angesetzt.', 'ttleague' ) );
		}
		return array( $fixture, $season );
	}

	/**
	 * @param array<string,mixed>            $fixture Begegnung vorher.
	 * @param array<string,int|string|null> $change  Neue Werte.
	 */
	private function write_move( Actor $actor, array $fixture, array $change, string $action ): void {
		$this->db->update( 'fixture', $change, array( 'id' => $fixture['id'] ) );
		$this->audit->record( 'fixture', $fixture['id'], $action, $actor->user_id, array_intersect_key( $fixture, $change ), $change );
	}

	/**
	 * @return array{field:string,message:string}
	 */
	private static function error( string $field, string $message ): array {
		return array(
			'field'   => $field,
			'message' => $message,
		);
	}
}
