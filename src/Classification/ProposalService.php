<?php
/**
 * Klassierung zum Saisonende: Vorschlag berechnen, Entscheide der TK, abschliessen.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Classification;

use TTLeague\Domain\Rating\ClassificationProposal;
use TTLeague\Domain\Rating\ClassificationScale;
use TTLeague\Domain\Rules\SeasonRules;
use TTLeague\Infrastructure\AuditLog;
use TTLeague\Infrastructure\Database\Db;
use TTLeague\Infrastructure\Time\Clock;
use TTLeague\Results\Actor;
use TTLeague\Season\SeasonRepository;

/**
 * Arbeitet auf der aktiven Saison (docs/klassierung.md). Jeder Schreibvorgang verlangt die TK,
 * sperrt in seiner Transaktion zuerst die Saison, schreibt ins audit_log und meldet danach die
 * Änderung (Cache-Version). Nach dem Abschluss (season.classification_closed_at) sind
 * Vorschläge und Entscheide gesperrt, bis die TK wieder öffnet.
 *
 * Die Wertungszahl wird nach Resultaten verzögert neu berechnet. Berechnen und Abschliessen
 * machen sie deshalb zuerst aktuell; der Abschluss prüft zudem, dass die gespeicherten Zeilen
 * dem entsprechen, was eine Berechnung jetzt ergäbe.
 *
 * Ablehnungen sind ClassificationException mit Klartext für die Rückmeldung.
 */
final class ProposalService {

	/** Höchstlänge einer Bemerkung. */
	public const NOTE_MAX = 1000;

	private ProposalRepository $repo;

	private SeasonRepository $seasons;

	private AuditLog $audit;

	/** @var callable():void */
	private $changed;

	/** @var callable():bool */
	private $refresh_ratings;

	/**
	 * @param callable():void|null $changed         Nach jedem gespeicherten Schreibvorgang (in WordPress: DataVersion::bump()).
	 * @param callable():bool|null $refresh_ratings Wertungszahlen aktuell machen: führt eine ausstehende Neuberechnung
	 *                                              aus und meldet, ob neu berechnet wurde. Ohne Angabe gelten die
	 *                                              gespeicherten Wertungszahlen als aktuell.
	 */
	public function __construct( private readonly Db $db, private readonly Clock $clock, ?callable $changed = null, ?callable $refresh_ratings = null ) {
		$this->repo            = new ProposalRepository( $db );
		$this->seasons         = new SeasonRepository( $db );
		$this->audit           = new AuditLog( $db );
		$this->changed         = $changed ?? static function (): void {};
		$this->refresh_ratings = $refresh_ratings ?? static fn(): bool => false;
	}

	/**
	 * Stand der Klassierung für die Seite der TK.
	 *
	 * @return array{season:array<string,mixed>|null,closed_at:string|null,min_games:int|null,counts:array{total:int,open:int,deviating:int,missing:int},clubs:list<array{id:int,name:string}>,rows:list<array<string,mixed>>}
	 */
	public function overview( ProposalFilter $filter ): array {
		$season = $this->seasons->active();
		if ( null === $season ) {
			return array(
				'season'    => null,
				'closed_at' => null,
				'min_games' => null,
				'counts'    => array(
					'total'     => 0,
					'open'      => 0,
					'deviating' => 0,
					'missing'   => 0,
				),
				'clubs'     => array(),
				'rows'      => array(),
			);
		}
		try {
			$min_games = $this->seasons->rules( $season )->classification_min_games;
		} catch ( \InvalidArgumentException ) {
			$min_games = null;
		}
		return array(
			'season'    => $season,
			'closed_at' => $season['classification_closed_at'],
			'min_games' => $min_games,
			'counts'    => $this->repo->counts( $season['id'] ),
			'clubs'     => $this->repo->clubs( $season['id'] ),
			'rows'      => $this->repo->rows( $season['id'], $filter ),
		);
	}

	/**
	 * Zeilen einer Saison für den Export. Ohne Saison-ID die aktive Saison; eine geplante oder
	 * unbekannte Saison hat keine Zeilen.
	 *
	 * @return list<array<string,mixed>>
	 */
	public function rows( ProposalFilter $filter, ?int $season_id = null ): array {
		$season = null === $season_id ? $this->seasons->active() : $this->seasons->by_id( $season_id );
		return null === $season ? array() : $this->repo->rows( $season['id'], $filter );
	}

	/**
	 * Vorschlag für alle gemeldeten Spieler der aktiven Saison berechnen oder neu berechnen.
	 * Zuerst wird eine ausstehende Neuberechnung der Wertungszahl ausgeführt. Bisherige
	 * Klassierung, Wertungszahl, Spiele und Vorschlag bestehender Zeilen werden überschrieben.
	 * Entscheid und Bemerkung bleiben, mit einer Ausnahme: Ändert sich der Vorschlag einer Zeile,
	 * deren Entscheid eine reine Übernahme war (Entscheid gleich dem bisherigen Vorschlag, ohne
	 * Bemerkung), wird der Entscheid zurückgesetzt, und die Zeile ist wieder offen. Zeilen ohne
	 * Entscheid von Spielern, die nicht mehr gemeldet sind, fallen weg.
	 *
	 * @return array{players:int,created:int,updated:int,removed:int,reset:int,refreshed:bool} «reset»: zurückgesetzte
	 *         Entscheide; «refreshed»: ob die Wertungszahl zuvor neu berechnet wurde.
	 * @throws ClassificationException Ohne Recht, ohne aktive Saison, nach dem Abschluss, bei ungültigen Regeln.
	 */
	public function compute( Actor $actor ): array {
		$season = $this->active_season( $actor );
		if ( null !== $season['classification_closed_at'] ) {
			self::reject( ClassificationException::CONFLICT, self::closed_message() );
		}
		$rules     = $this->rules( $season );
		$refreshed = (bool) ( $this->refresh_ratings )();

		$result = $this->db->transaction(
			function () use ( $season, $rules, $actor, $refreshed ): array {
				$this->lock_open( $season['id'] );
				$removed = $this->repo->delete_orphans( $season['id'] );
				$players = $this->calculate( $season['id'], $rules );
				$new     = array();
				$updated = 0;
				$reset   = 0;
				foreach ( $players as [ $row, $old ] ) {
					if ( null === $old ) {
						$new[] = $row;
						continue;
					}
					if ( ! self::differs( $old, $row ) ) {
						continue;
					}
					// Reine Übernahme eines Vorschlags, den es so nicht mehr gibt: wieder offen.
					$accepted = null !== $old['decided'] && $old['decided'] === $old['proposed'] && null === $old['note'];
					$undo     = $accepted && $old['proposed'] !== $row['proposed'];
					$this->repo->refresh( $old['id'], $row, $undo );
					++$updated;
					if ( $undo ) {
						++$reset;
						$this->audit->record(
							'classification_proposal',
							$old['id'],
							'decision_reset',
							$actor->user_id,
							array(
								'decided'  => $old['decided'],
								'note'     => null,
								'proposed' => $old['proposed'],
							),
							array(
								'decided'  => null,
								'note'     => null,
								'proposed' => $row['proposed'],
							)
						);
					}
				}
				$this->repo->insert( $season['id'], $new );
				$result = array(
					'players'   => count( $players ),
					'created'   => count( $new ),
					'updated'   => $updated,
					'removed'   => $removed,
					'reset'     => $reset,
					'refreshed' => $refreshed,
				);
				$this->audit->record( 'season', $season['id'], 'classification_computed', $actor->user_id, null, $result );
				return $result;
			}
		);
		( $this->changed )();
		return $result;
	}

	/**
	 * Vorschlag einer Zeile als Entscheid übernehmen.
	 *
	 * @param int|null $expected Vorschlag, den die TK gesehen hat; weicht er inzwischen ab, wird abgelehnt.
	 * @return array<string,mixed> Zeile nach dem Entscheid.
	 * @throws ClassificationException Ohne Recht, nach dem Abschluss, bei unbekannter Zeile oder geändertem Vorschlag.
	 */
	public function accept( Actor $actor, int $proposal_id, ?int $expected = null ): array {
		$season  = $this->active_season( $actor );
		$written = $this->db->transaction(
			function () use ( $season, $actor, $proposal_id, $expected ): bool {
				$this->lock_open( $season['id'] );
				$row = $this->row_for_update( $season['id'], $proposal_id );
				if ( null !== $expected && $expected !== $row['proposed'] ) {
					self::reject( ClassificationException::CONFLICT, __( 'Der Vorschlag wurde inzwischen neu berechnet. Bitte lade die Seite neu.', 'ttleague' ) );
				}
				return $this->write_decision( $row, $row['proposed'], null, 'accepted', $actor );
			}
		);
		if ( $written ) {
			( $this->changed )();
		}
		return $this->row( $season['id'], $proposal_id );
	}

	/**
	 * Vorschlag für alle Zeilen ohne Entscheid übernehmen, die zum Filter passen, in einer Transaktion.
	 *
	 * @return int Anzahl entschiedener Zeilen.
	 * @throws ClassificationException Ohne Recht, ohne aktive Saison, nach dem Abschluss.
	 */
	public function accept_open( Actor $actor, ProposalFilter $filter ): int {
		$season = $this->active_season( $actor );
		$count  = $this->db->transaction(
			function () use ( $season, $actor, $filter ): int {
				$this->lock_open( $season['id'] );
				$rows = $this->repo->open_for_update( $season['id'], $filter );
				$this->repo->accept_many( array_column( $rows, 'id' ), $actor->user_id, $this->now() );
				foreach ( $rows as $row ) {
					$this->audit->record(
						'classification_proposal',
						$row['id'],
						'accepted',
						$actor->user_id,
						array(
							'decided' => null,
							'note'    => $row['note'],
						),
						array(
							'decided'  => $row['proposed'],
							'note'     => null,
							'proposed' => $row['proposed'],
						)
					);
				}
				return count( $rows );
			}
		);
		if ( $count > 0 ) {
			( $this->changed )();
		}
		return $count;
	}

	/**
	 * Eigenen Wert entscheiden. Die Bemerkung ist Pflicht.
	 *
	 * @return array<string,mixed> Zeile nach dem Entscheid.
	 * @throws ClassificationException Ohne Recht, nach dem Abschluss, ohne Bemerkung, bei einem Wert ausserhalb der Skala.
	 */
	public function override( Actor $actor, int $proposal_id, int $value, string $note ): array {
		$season = $this->active_season( $actor );
		$note   = self::note( $note, __( 'Bitte begründe den abweichenden Entscheid in der Bemerkung.', 'ttleague' ) );
		if ( $value < ClassificationScale::MIN || $value > ClassificationScale::MAX ) {
			self::reject( ClassificationException::INVALID, __( 'Die Klassierung liegt ausserhalb der Skala D1 bis A22.', 'ttleague' ) );
		}
		$written = $this->db->transaction(
			function () use ( $season, $actor, $proposal_id, $value, $note ): bool {
				$this->lock_open( $season['id'] );
				return $this->write_decision( $this->row_for_update( $season['id'], $proposal_id ), $value, $note, 'overridden', $actor );
			}
		);
		if ( $written ) {
			( $this->changed )();
		}
		return $this->row( $season['id'], $proposal_id );
	}

	/**
	 * Klassierung abschliessen. Zuerst wird eine ausstehende Neuberechnung der Wertungszahl
	 * ausgeführt. Dann müssen die gespeicherten Zeilen dem entsprechen, was eine Berechnung jetzt
	 * ergäbe (gemeldete Spieler, bisherige Klassierung, Wertungszahl, Spiele, Vorschlag), und jede
	 * Zeile muss entschieden sein. Die Prüfung ändert keine Zeile.
	 *
	 * @return string Zeitpunkt des Abschlusses (UTC).
	 * @throws ClassificationException Ohne Recht, ohne Zeilen, mit veralteten oder offenen Zeilen, wenn schon
	 *                                 abgeschlossen, bei ungültigen Regeln.
	 */
	public function close( Actor $actor ): string {
		$season = $this->active_season( $actor );
		if ( null !== $season['classification_closed_at'] ) {
			self::reject( ClassificationException::CONFLICT, __( 'Die Klassierung ist bereits abgeschlossen.', 'ttleague' ) );
		}
		$rules = $this->rules( $season );
		( $this->refresh_ratings )();
		$now = $this->now();
		$this->db->transaction(
			function () use ( $season, $rules, $actor, $now ): void {
				$this->lock_open( $season['id'], __( 'Die Klassierung ist bereits abgeschlossen.', 'ttleague' ) );
				$counts = $this->repo->counts( $season['id'] );
				if ( 0 === $counts['total'] ) {
					self::reject( ClassificationException::CONFLICT, __( 'Es gibt noch keinen Vorschlag. Berechne ihn zuerst.', 'ttleague' ) );
				}
				$outdated = $this->outdated( $season['id'], $rules );
				if ( $outdated > 0 ) {
					/* translators: %d: Anzahl Zeilen */
					self::reject( ClassificationException::CONFLICT, sprintf( _n( 'Abschliessen ist noch nicht möglich: %d Zeile entspricht nicht mehr dem aktuellen Stand (Meldung, Klassierung, Wertungszahl oder Spiele). Wähle «Vorschlag neu berechnen».', 'Abschliessen ist noch nicht möglich: %d Zeilen entsprechen nicht mehr dem aktuellen Stand (Meldung, Klassierung, Wertungszahl oder Spiele). Wähle «Vorschlag neu berechnen».', $outdated, 'ttleague' ), $outdated ) );
				}
				if ( $counts['open'] > 0 ) {
					/* translators: %d: Anzahl Zeilen */
					self::reject( ClassificationException::CONFLICT, sprintf( _n( 'Abschliessen ist noch nicht möglich: %d Zeile ist ohne Entscheid.', 'Abschliessen ist noch nicht möglich: %d Zeilen sind ohne Entscheid.', $counts['open'], 'ttleague' ), $counts['open'] ) );
				}
				$this->repo->close( $season['id'], $now, $now );
				$this->audit->record(
					'season',
					$season['id'],
					'classification_closed',
					$actor->user_id,
					array( 'classification_closed_at' => null ),
					array(
						'classification_closed_at' => $now,
						'rows'                     => $counts['total'],
					)
				);
			}
		);
		( $this->changed )();
		return $now;
	}

	/**
	 * Abgeschlossene Klassierung wieder öffnen. Die Bemerkung ist Pflicht.
	 *
	 * @throws ClassificationException Ohne Recht, ohne Bemerkung, wenn nicht abgeschlossen.
	 */
	public function reopen( Actor $actor, string $note ): void {
		$season = $this->active_season( $actor );
		$note   = self::note( $note, __( 'Bitte begründe in der Bemerkung, warum du die Klassierung wieder öffnest.', 'ttleague' ) );
		$this->db->transaction(
			function () use ( $season, $actor, $note ): void {
				$state = $this->lock( $season['id'] );
				if ( null === $state['classification_closed_at'] ) {
					self::reject( ClassificationException::CONFLICT, __( 'Die Klassierung ist nicht abgeschlossen.', 'ttleague' ) );
				}
				$this->repo->close( $season['id'], null, $this->now() );
				$this->audit->record(
					'season',
					$season['id'],
					'classification_reopened',
					$actor->user_id,
					array( 'classification_closed_at' => $state['classification_closed_at'] ),
					array(
						'classification_closed_at' => null,
						'note'                     => $note,
					),
					$note
				);
			}
		);
		( $this->changed )();
	}

	/**
	 * Was eine Berechnung jetzt ergäbe: je gemeldetem Spieler die neuen Werte und die bisherige Zeile.
	 *
	 * @return list<array{0:array{player_id:int,current:int,rating:float,games:int,proposed:int},1:array{id:int,current:int,rating:float,games:int,proposed:int,decided:int|null,note:string|null}|null}>
	 */
	private function calculate( int $season_id, SeasonRules $rules ): array {
		$out = array();
		foreach ( $this->repo->candidates( $season_id ) as $player ) {
			$out[] = array(
				array(
					'player_id' => $player['player_id'],
					'current'   => $player['classification'],
					'rating'    => round( $player['rating'] ?? ProposalRepository::NO_RATING, 2 ),
					'games'     => $player['games'],
					'proposed'  => ClassificationProposal::propose( $player['classification'], $player['rating'], $player['games'], $rules ),
				),
				$player['proposal'],
			);
		}
		return $out;
	}

	/**
	 * Anzahl der Zeilen, die eine Berechnung jetzt anlegen, ändern oder entfernen würde.
	 */
	private function outdated( int $season_id, SeasonRules $rules ): int {
		$count = $this->repo->count_orphans( $season_id );
		foreach ( $this->calculate( $season_id, $rules ) as [ $row, $old ] ) {
			if ( null === $old || self::differs( $old, $row ) ) {
				++$count;
			}
		}
		return $count;
	}

	/**
	 * Weicht der berechnete Teil der gespeicherten Zeile von den neuen Werten ab?
	 *
	 * @param array{current:int,rating:float,games:int,proposed:int} $old Gespeicherte Zeile.
	 * @param array{current:int,rating:float,games:int,proposed:int} $row Neu berechnete Werte.
	 */
	private static function differs( array $old, array $row ): bool {
		return $old['current'] !== $row['current'] || $old['rating'] !== $row['rating'] || $old['games'] !== $row['games'] || $old['proposed'] !== $row['proposed'];
	}

	/**
	 * Regeln der Saison; ungültige Regeln sind eine Ablehnung, kein Rückfall auf Standardwerte.
	 *
	 * @param array<string,mixed> $season Saison.
	 */
	private function rules( array $season ): SeasonRules {
		try {
			return $this->seasons->rules( $season );
		} catch ( \InvalidArgumentException ) {
			self::reject( ClassificationException::INVALID, __( 'Die Regeln der Saison sind ungültig. Bitte zuerst die Saisonregeln korrigieren.', 'ttleague' ) );
		}
	}

	private static function closed_message(): string {
		return __( 'Die Klassierung ist abgeschlossen. Öffne sie wieder, um etwas zu ändern.', 'ttleague' );
	}

	/**
	 * Die aktive Saison, nach Prüfung des Rechts.
	 *
	 * @return array<string,mixed>
	 */
	private function active_season( Actor $actor ): array {
		if ( ! $actor->is_tk ) {
			self::reject( ClassificationException::FORBIDDEN, __( 'Die Klassierung bearbeitet nur die TK.', 'ttleague' ) );
		}
		$season = $this->seasons->active();
		if ( null === $season ) {
			self::reject( ClassificationException::NOT_FOUND, __( 'Es gibt keine aktive Saison.', 'ttleague' ) );
		}
		return $season;
	}

	/**
	 * Saison sperren (erste Abfrage der Transaktion); sie muss weiterhin aktiv sein.
	 *
	 * @return array{status:string,classification_closed_at:string|null}
	 */
	private function lock( int $season_id ): array {
		$state = $this->repo->lock_season( $season_id );
		if ( null === $state || SeasonRepository::ACTIVE !== $state['status'] ) {
			self::reject( ClassificationException::CONFLICT, __( 'Die Saison ist nicht mehr aktiv. Bitte lade die Seite neu.', 'ttleague' ) );
		}
		return $state;
	}

	/**
	 * Saison sperren und ablehnen, wenn die Klassierung abgeschlossen ist.
	 */
	private function lock_open( int $season_id, ?string $message = null ): void {
		if ( null !== $this->lock( $season_id )['classification_closed_at'] ) {
			self::reject( ClassificationException::CONFLICT, $message ?? self::closed_message() );
		}
	}

	/**
	 * @return array{id:int,player_id:int,current:int,proposed:int,decided:int|null,note:string|null}
	 */
	private function row_for_update( int $season_id, int $proposal_id ): array {
		$row = $this->repo->find_for_update( $season_id, $proposal_id );
		if ( null === $row ) {
			self::reject( ClassificationException::NOT_FOUND, __( 'Diese Zeile gibt es nicht (mehr). Bitte lade die Seite neu.', 'ttleague' ) );
		}
		return $row;
	}

	/**
	 * Entscheid speichern und protokollieren. Ein unveränderter Entscheid (gleicher Wert, gleiche
	 * Bemerkung) wird nicht erneut geschrieben; dann bleiben auch Konto und Zeitpunkt des Entscheids.
	 *
	 * @param array{id:int,player_id:int,current:int,proposed:int,decided:int|null,note:string|null} $row Zeile, gesperrt gelesen.
	 * @return bool Ob geschrieben wurde.
	 */
	private function write_decision( array $row, int $value, ?string $note, string $action, Actor $actor ): bool {
		if ( $row['decided'] === $value && ( $row['note'] ?? '' ) === ( $note ?? '' ) ) {
			return false;
		}
		$this->repo->decide( $row['id'], $value, $note, $actor->user_id, $this->now() );
		$this->audit->record(
			'classification_proposal',
			$row['id'],
			$action,
			$actor->user_id,
			array(
				'decided' => $row['decided'],
				'note'    => $row['note'],
			),
			array(
				'decided'  => $value,
				'note'     => $note,
				'proposed' => $row['proposed'],
			),
			$note
		);
		return true;
	}

	/**
	 * @return array<string,mixed>
	 */
	private function row( int $season_id, int $proposal_id ): array {
		$row = $this->repo->one( $season_id, $proposal_id );
		if ( null === $row ) {
			self::reject( ClassificationException::NOT_FOUND, __( 'Diese Zeile gibt es nicht (mehr). Bitte lade die Seite neu.', 'ttleague' ) );
		}
		return $row;
	}

	/**
	 * Bemerkung bereinigen (Zeilenenden vereinheitlicht, ohne Rand); leer oder zu lang wird abgelehnt.
	 */
	private static function note( string $note, string $missing ): string {
		$note = trim( str_replace( array( "\r\n", "\r" ), "\n", $note ) );
		if ( '' === $note ) {
			self::reject( ClassificationException::INVALID, $missing );
		}
		if ( mb_strlen( $note ) > self::NOTE_MAX ) {
			/* translators: %d: Anzahl Zeichen */
			self::reject( ClassificationException::INVALID, sprintf( __( 'Die Bemerkung ist zu lang (höchstens %d Zeichen).', 'ttleague' ), self::NOTE_MAX ) );
		}
		return $note;
	}

	private function now(): string {
		return $this->clock->now()->format( 'Y-m-d H:i:s' );
	}

	/**
	 * @return never
	 * @throws ClassificationException Immer.
	 */
	private static function reject( string $reason, string $message ): never {
		throw new ClassificationException( $reason, $message ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Klartext; escaped wird bei der Ausgabe der Rückmeldung.
	}
}
