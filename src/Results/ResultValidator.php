<?php
/**
 * Fachliche Prüfung einer Resultaterfassung: Aufstellung, Spielberechtigung, Spiele.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Results;

use TTLeague\Domain\Eligibility\EligibilityReason;
use TTLeague\Domain\Match\GameOrder;
use TTLeague\Domain\Match\GameResult;
use TTLeague\Domain\Match\GameStatus;
use TTLeague\Domain\Match\LineupValidator;
use TTLeague\Domain\Match\SetPoints;
use TTLeague\Domain\Match\Side;
use TTLeague\Domain\Match\TeamMatchResult;
use TTLeague\Domain\Rules\SeasonRules;

/**
 * Harte Fehler führen zu 422 mit Feldangaben; Hinweise (Ersatzeinsätze, Bindung, nicht
 * gemeldete Cup-Spieler) werden gespeichert und angezeigt. Im nachsichtigen Modus (Korrektur
 * durch die TK) werden fehlende Spielberechtigungen zu Hinweisen.
 */
final class ResultValidator {

	/** @var list<array{field:string,message:string}> */
	private array $errors = array();

	/** @var list<string> */
	private array $warnings = array();

	/** @var callable(int):string */
	private $names;

	/**
	 * @param FixtureContext            $ctx   Begegnung.
	 * @param array<string,SideRoster>  $sides home/away => Spielberechtigung.
	 * @param callable(int):string|null $names Spielername zu einer Spieler-ID.
	 */
	public function __construct(
		private readonly FixtureContext $ctx,
		private readonly array $sides,
		?callable $names = null,
	) {
		$this->names = $names ?? fn( int $id ): string => $this->sides['home']->player_name( $id );
	}

	/**
	 * @throws ResultException Bei Fehlern (422).
	 */
	public function validate( ResultInput $input, bool $lenient = false ): ValidatedResult {
		$this->errors   = array();
		$this->warnings = array();
		$rules          = $this->ctx->rules;

		[ $lineup, $bound ] = $this->lineup( $input, $rules, $lenient );
		$present            = array(
			'home' => $input->present( 'home' ),
			'away' => $input->present( 'away' ),
		);

		$cup     = $this->ctx->is_cup();
		$target  = $cup && SeasonRules::CUP_FIRST_TO_THREE === $rules->cup_mode ? intdiv( $rules->cup_games, 2 ) + 1 : null;
		$wins    = array( 0, 0 );
		$decided = false;
		$rows    = array();
		$results = array();

		foreach ( $this->ctx->order() as $slot_game ) {
			$sequence  = $slot_game['sequence'];
			$field     = 'games.' . $sequence;
			$is_double = GameOrder::DOUBLE === $slot_game['type'];
			$game      = $input->games[ $sequence ] ?? null;

			if ( null === $game ) {
				if ( ! $decided ) {
					/* translators: %d: Spielnummer */
					$this->error( $field, sprintf( __( 'Spiel %d fehlt.', 'ttleague' ), $sequence ) );
				}
				continue;
			}

			if ( $is_double ) {
				$home_missing = count( $present['home'] ) < 2;
				$away_missing = count( $present['away'] ) < 2;
				$home_players = $this->pairing( $game->home_players, $present['home'], 'played' === $game->status && ! $home_missing, $field . '.home_players', $sequence );
				$away_players = $this->pairing( $game->away_players, $present['away'], 'played' === $game->status && ! $away_missing, $field . '.away_players', $sequence );
			} else {
				$home_player  = $present['home'][ (string) $slot_game['home'] ] ?? null;
				$away_player  = $present['away'][ (string) $slot_game['away'] ] ?? null;
				$home_missing = null === $home_player;
				$away_missing = null === $away_player;
				$home_players = null === $home_player ? array() : array( $home_player );
				$away_players = null === $away_player ? array() : array( $away_player );
			}

			$points = array();
			if ( 'played' === $game->status && $rules->set_points_enabled && array() !== $game->set_points ) {
				$set_errors = SetPoints::validate( $game->set_points, (int) $game->sets_home, (int) $game->sets_away, $rules->sets_to_win );
				foreach ( $set_errors as $message ) {
					/* translators: 1: Spielnummer, 2: Fehler */
					$this->error( $field . '.set_points', sprintf( __( 'Spiel %1$d: %2$s', 'ttleague' ), $sequence, $message ) );
				}
				if ( array() === $set_errors ) {
					$points = $game->set_points;
				}
			}

			try {
				$result = $this->game_result( $game, $is_double, $points, $rules->sets_to_win );
			} catch ( \InvalidArgumentException $e ) {
				/* translators: 1: Spielnummer, 2: Fehler */
				$this->error( $field, sprintf( __( 'Spiel %1$d: %2$s', 'ttleague' ), $sequence, $e->getMessage() ) );
				continue;
			}

			$this->check_missing( $result, $home_missing, $away_missing, $decided, $field, $sequence );

			$counts = ! $decided;
			if ( $counts ) {
				$winner = $result->winner();
				if ( null !== $winner ) {
					++$wins[ Side::Home === $winner ? 0 : 1 ];
				}
				if ( null !== $target && max( $wins ) >= $target ) {
					$decided = true;
				}
			}
			$results[] = $result;
			$rows[]    = array(
				'sequence'   => $sequence,
				'type'       => $slot_game['type'],
				'a1'         => $home_players[0] ?? null,
				'a2'         => $home_players[1] ?? null,
				'b1'         => $away_players[0] ?? null,
				'b2'         => $away_players[1] ?? null,
				'sets_a'     => $result->sets_home,
				'sets_b'     => $result->sets_away,
				'status'     => $result->status->value,
				'counts'     => $counts,
				'set_points' => $points,
			);
		}

		if ( array() !== $this->errors ) {
			throw ResultException::invalid( $this->errors );
		}

		$team_result = $cup ? TeamMatchResult::from_cup( $results, $rules ) : TeamMatchResult::from_team_match( $results, $rules );
		if ( $cup && null === $team_result->winner() ) {
			$this->error( 'games', __( 'Im Cup braucht es eine Siegerin: Eine Seite muss mehr Spiele gewinnen.', 'ttleague' ) );
		}
		if ( ! $cup ) {
			foreach ( LineupValidator::validate_team_match( $input->home, $input->away, $results, $rules ) as $message ) {
				$this->error( 'games', $message );
			}
		}
		if ( array() !== $this->errors ) {
			throw ResultException::invalid( $this->errors );
		}

		return new ValidatedResult( $lineup, $rows, $team_result, $this->warnings, $bound );
	}

	/**
	 * Aufstellung beider Seiten prüfen.
	 *
	 * @return array{0:list<array{side:string,slot:string,player_id:int,is_substitute:bool}>,1:list<array{player_id:int,team_id:int}>}
	 */
	private function lineup( ResultInput $input, SeasonRules $rules, bool $lenient ): array {
		$rows  = array();
		$bound = array();
		$sides = array();
		foreach ( array( 'home', 'away' ) as $side ) {
			$roster = $this->sides[ $side ];
			$seen   = array();
			foreach ( $input->present( $side ) as $slot => $player_id ) {
				$field = 'lineup.' . $side . '.' . $slot;
				$name  = ( $this->names )( $player_id );
				if ( isset( $seen[ $player_id ] ) ) {
					/* translators: %s: Spielername */
					$this->error( $field, sprintf( __( '%s steht doppelt in der Aufstellung.', 'ttleague' ), $name ) );
					continue;
				}
				$seen[ $player_id ] = true;

				$eligibility = $roster->check( $player_id );
				if ( ! $eligibility->allowed ) {
					/* translators: 1: Spielername, 2: Grund */
					$message = sprintf( __( '%1$s: %2$s', 'ttleague' ), $name, $eligibility->message );
					if ( ! $lenient ) {
						$this->error( $field, $message );
						continue;
					}
					$this->warnings[] = $message;
				}

				$is_substitute = EligibilityReason::Substitute === $eligibility->reason;
				if ( $is_substitute && $roster->is_cup() ) {
					/* translators: %s: Spielername */
					$this->warnings[] = sprintf( __( '%s ist nicht für diese Cup-Mannschaft gemeldet und spielt als Ersatz.', 'ttleague' ), $name );
				} elseif ( $is_substitute && null !== $roster->team_id ) {
					$number = $eligibility->substitute_appearances + 1;
					if ( $number >= $rules->substitute_max ) {
						$bound[] = array(
							'player_id' => $player_id,
							'team_id'   => $roster->team_id,
						);
						/* translators: 1: Nummer des Einsatzes, 2: Spielername, 3: Mannschaft */
						$this->warnings[] = sprintf( __( '%1$d. Ersatzeinsatz von %2$s: ab jetzt an %3$s gebunden (Art. 12.5).', 'ttleague' ), $number, $name, $roster->name );
					} else {
						/* translators: 1: Spielername, 2: Nummer des Einsatzes, 3: Höchstzahl */
						$this->warnings[] = sprintf( __( '%1$s spielt als Ersatz (%2$d. von höchstens %3$d Einsätzen, danach gebunden).', 'ttleague' ), $name, $number, $rules->substitute_max );
					}
				}
				$rows[]           = array(
					'side'          => $side,
					'slot'          => $slot,
					'player_id'     => $player_id,
					'is_substitute' => $is_substitute,
				);
				$sides[ $side ][] = $player_id;
			}

			$minimum = $this->ctx->is_cup() ? 1 : 2;
			if ( count( $input->present( $side ) ) < $minimum ) {
				$this->error(
					'lineup.' . $side,
					$this->ctx->is_cup()
						? __( 'Die Cup-Mannschaft braucht mindestens einen Spieler.', 'ttleague' )
						: __( 'Eine Mannschaft braucht mindestens zwei Spieler (Art. 14.2).', 'ttleague' )
				);
			} elseif ( $this->ctx->is_cup() && count( $input->present( $side ) ) < 2 ) {
				$this->warnings[] = __( 'Eine Cup-Mannschaft ist nur mit einem Spieler angetreten.', 'ttleague' );
			}
		}

		foreach ( array_intersect( $sides['home'] ?? array(), $sides['away'] ?? array() ) as $player_id ) {
			/* translators: %s: Spielername */
			$this->error( 'lineup', sprintf( __( '%s kann nicht für beide Seiten spielen.', 'ttleague' ), ( $this->names )( $player_id ) ) );
		}
		return array( $rows, $bound );
	}

	/**
	 * Doppelpaarung einer Seite: angegeben (aus der Aufstellung) oder bei genau zwei Spielern automatisch.
	 *
	 * @param list<int>          $given    Angegebene Spieler.
	 * @param array<string,int>  $present  Aufstellung der Seite.
	 * @return list<int>
	 */
	private function pairing( array $given, array $present, bool $required, string $field, int $sequence ): array {
		$available = array_values( $present );
		if ( array() === $given ) {
			if ( 2 === count( $available ) ) {
				return $available;
			}
			if ( $required ) {
				/* translators: %d: Spielnummer */
				$this->error( $field, sprintf( __( 'Spiel %d: Die Doppelpaarung fehlt.', 'ttleague' ), $sequence ) );
			}
			return array();
		}
		if ( 2 !== count( $given ) || $given[0] === $given[1] ) {
			/* translators: %d: Spielnummer */
			$this->error( $field, sprintf( __( 'Spiel %d: Das Doppel braucht zwei verschiedene Spieler.', 'ttleague' ), $sequence ) );
			return array();
		}
		foreach ( $given as $player_id ) {
			if ( ! in_array( $player_id, $available, true ) ) {
				/* translators: 1: Spielnummer, 2: Spielername */
				$this->error( $field, sprintf( __( 'Spiel %1$d: %2$s steht nicht in der Aufstellung.', 'ttleague' ), $sequence, ( $this->names )( $player_id ) ) );
				return array();
			}
		}
		return $given;
	}

	/**
	 * @param list<array{0:int,1:int}> $points Satzpunkte (geprüft).
	 */
	private function game_result( GameInput $game, bool $is_double, array $points, int $sets_to_win ): GameResult {
		if ( 'walkover' === $game->status ) {
			return GameResult::walkover( $game->sequence, Side::from( (string) $game->winner ), $is_double, $sets_to_win );
		}
		if ( 'not_played' === $game->status ) {
			return GameResult::not_played( $game->sequence, $is_double, $sets_to_win );
		}
		[ $points_home, $points_away ] = array() === $points ? array( null, null ) : SetPoints::totals( $points );
		return new GameResult( $game->sequence, (int) $game->sets_home, (int) $game->sets_away, GameStatus::Played, $is_double, $points_home, $points_away, $sets_to_win );
	}

	/**
	 * Fehlt ein Spieler, ist das Spiel kampflos für die andere Seite; fehlen beide, gilt es als nicht gespielt.
	 */
	private function check_missing( GameResult $result, bool $home_missing, bool $away_missing, bool $decided, string $field, int $sequence ): void {
		if ( $home_missing && $away_missing ) {
			if ( GameStatus::NotPlayed !== $result->status && ! $decided ) {
				/* translators: %d: Spielnummer */
				$this->error( $field, sprintf( __( 'Spiel %d: Auf beiden Seiten fehlt ein Spieler, das Spiel gilt als nicht gespielt.', 'ttleague' ), $sequence ) );
			}
			return;
		}
		if ( $home_missing ) {
			if ( ! ( GameStatus::Walkover === $result->status && Side::Away === $result->winner() ) ) {
				/* translators: %d: Spielnummer */
				$this->error( $field, sprintf( __( 'Spiel %d: Auf der Heimseite fehlt ein Spieler, das Spiel ist kampflos für die Gäste.', 'ttleague' ), $sequence ) );
			}
			return;
		}
		if ( $away_missing ) {
			if ( ! ( GameStatus::Walkover === $result->status && Side::Home === $result->winner() ) ) {
				/* translators: %d: Spielnummer */
				$this->error( $field, sprintf( __( 'Spiel %d: Auf der Gastseite fehlt ein Spieler, das Spiel ist kampflos für das Heimteam.', 'ttleague' ), $sequence ) );
			}
			return;
		}
		if ( GameStatus::NotPlayed === $result->status && ! $decided ) {
			/* translators: %d: Spielnummer */
			$this->warnings[] = sprintf( __( 'Spiel %d wurde nicht gespielt.', 'ttleague' ), $sequence );
		}
	}

	private function error( string $field, string $message ): void {
		$this->errors[] = ResultInput::error( $field, $message );
	}
}
