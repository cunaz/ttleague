<?php
/**
 * Saison-Parameter: alles, was im Reglement steht und sich per OV-Beschluss ändern kann.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Domain\Rules;

/**
 * Unveränderliches Regelwerk einer Saison. Wird aus season.rules_json geladen;
 * fehlende Schlüssel erhalten die Standardwerte der Region Zürich (Stand 2026/27).
 */
final class SeasonRules {

	public const CUP_FIRST_TO_THREE = 'first_to_three';
	public const CUP_ALL_GAMES      = 'all_games';

	/**
	 * @param int                 $substitute_max           Einsätze als Ersatz in einem Team, danach gebunden (Art. 12.5, OV 2025: 3).
	 * @param array<string,bool>  $category_any_team        Kategorien, in denen Ersatz unabhängig von der Mannschaftsnummer erlaubt ist (Art. 12.8).
	 * @param int                 $entry_deadline_hours     Frist für das Nachtragen durch den Heimclub (Art. 7.3).
	 * @param int                 $objection_days           Einsprachefrist des Gastclubs (Art. 7.3).
	 * @param int                 $forfeit_after_days       Ohne Resultat nach so vielen Tagen: Forfait-Vorschlag (Art. 10.2).
	 * @param int                 $postponement_approval_days Verschiebung um mehr Tage braucht Bewilligung (Art. 10.2).
	 * @param int                 $sets_to_win              Gewinnsätze pro Spiel (3 Gewinnsätze, ITTF).
	 * @param int                 $team_match_games         Spiele pro Meisterschaftsbegegnung (Art. 14.1).
	 * @param int                 $forfeit_score            Forfait-Wertung, z. B. 0:10 (Art. 33).
	 * @param int                 $two_player_max_wins      Maximaler Sieg eines 2er-Teams (7:3, Art. 14.4).
	 * @param string              $cup_mode                 Cup endet beim dritten Sieg oder alle fünf Spiele werden gewertet.
	 * @param int                 $cup_games                Spiele pro Cup-Begegnung (Art. 22).
	 * @param int                 $cup_players              Spieler pro Cup-Team (Art. 19.1).
	 * @param bool                $set_points_enabled       Satzpunkte (11:8) erfassbar.
	 * @param list<int>           $rl_round_points          Punkte pro Rang in einer RL-Gruppe.
	 * @param int                 $rl_seven_player_bonus    Zusatzpunkt für alle in einer 7er-Gruppe.
	 * @param int                 $rl_finalists             Finalteilnehmer pro Kategorie.
	 * @param float               $rating_k                 K-Faktor der Wertungszahl (kalibriert an fünf Saisons: 16).
	 * @param float               $rating_scale             Skalierung der Erwartungswertformel (kalibriert: 200, d. h. eine Klassierungsstufe Unterschied ergibt 76 % Siegerwartung).
	 * @param float               $rating_doubles_weight    Gewicht eines Doppels für die Wertungszahl.
	 * @param float               $rating_points_per_class  Wertungspunkte pro Klassierungsstufe.
	 * @param int                 $match_reminder_days      Erinnerung an beide Seiten so viele Tage vor dem Spiel.
	 * @param int                 $result_reminder_hours    Erinnerung an die Heimseite, wenn so viele Stunden nach Anspielzeit kein Resultat vorliegt.
	 * @param list<int>           $confirm_reminder_days    Erinnerungen an die Gastseite so viele Tage nach der Erfassung (danach die TK nach objection_days).
	 */
	public function __construct(
		public readonly int $substitute_max = 3,
		public readonly array $category_any_team = array( 'D' => true ),
		public readonly int $entry_deadline_hours = 48,
		public readonly int $objection_days = 7,
		public readonly int $forfeit_after_days = 16,
		public readonly int $postponement_approval_days = 14,
		public readonly int $sets_to_win = 3,
		public readonly int $team_match_games = 10,
		public readonly int $forfeit_score = 10,
		public readonly int $two_player_max_wins = 7,
		public readonly string $cup_mode = self::CUP_FIRST_TO_THREE,
		public readonly int $cup_games = 5,
		public readonly int $cup_players = 2,
		public readonly bool $set_points_enabled = false,
		public readonly array $rl_round_points = array( 6, 5, 4, 3, 2, 1 ),
		public readonly int $rl_seven_player_bonus = 1,
		public readonly int $rl_finalists = 6,
		public readonly float $rating_k = 16.0,
		public readonly float $rating_scale = 200.0,
		public readonly float $rating_doubles_weight = 0.5,
		public readonly float $rating_points_per_class = 100.0,
		public readonly int $match_reminder_days = 2,
		public readonly int $result_reminder_hours = 24,
		public readonly array $confirm_reminder_days = array( 3, 6 ),
	) {
		if ( $this->substitute_max < 0 ) {
			throw new \InvalidArgumentException( 'substitute_max darf nicht negativ sein.' );
		}
		if ( ! in_array( $this->cup_mode, array( self::CUP_FIRST_TO_THREE, self::CUP_ALL_GAMES ), true ) ) {
			throw new \InvalidArgumentException( 'Unbekannter cup_mode: ' . $this->cup_mode );
		}
		if ( $this->sets_to_win < 1 ) {
			throw new \InvalidArgumentException( 'sets_to_win muss mindestens 1 sein.' );
		}
		// Fristen der Benachrichtigungen: 0 schaltet nur die Erinnerung vor dem Spiel aus. Eine
		// Erinnerung «Resultat fehlt» zur Anspielzeit oder eine Erinnerung an die Gastseite, wenn die
		// TK schon bestätigen soll, wäre sinnlos.
		if ( $this->match_reminder_days < 0 ) {
			throw new \InvalidArgumentException( 'match_reminder_days darf nicht negativ sein (0 schaltet die Erinnerung aus).' );
		}
		if ( $this->result_reminder_hours < 1 ) {
			throw new \InvalidArgumentException( 'result_reminder_hours muss mindestens 1 sein.' );
		}
		foreach ( $this->confirm_reminder_days as $days ) {
			if ( ! is_int( $days ) || $days < 1 || $days >= $this->objection_days ) {
				throw new \InvalidArgumentException( 'confirm_reminder_days: nur ganze Tage ab 1 und vor objection_days.' );
			}
		}
	}

	/**
	 * Erzeugt Regeln aus einem (teilweisen) Array, z. B. aus rules_json. Fehlen die Stufen
	 * confirm_reminder_days, gelten die Standardstufen, soweit sie vor objection_days liegen:
	 * Gespeicherte Regeln mit kürzerer Einsprachefrist bleiben so gültig. Gesetzte Stufen prüft
	 * der Konstruktor streng.
	 *
	 * @param array<string,mixed> $data Teilweise Regeln.
	 */
	public static function from_array( array $data ): self {
		$defaults = ( new self() )->to_array();
		$merged   = array_intersect_key( $data, $defaults ) + $defaults;
		$confirm  = array_values( array_map( 'intval', (array) $merged['confirm_reminder_days'] ) );
		if ( ! array_key_exists( 'confirm_reminder_days', $data ) ) {
			$objection = (int) $merged['objection_days'];
			$confirm   = array_values( array_filter( $confirm, static fn( int $days ): bool => $days < $objection ) );
		}

		return new self(
			substitute_max: (int) $merged['substitute_max'],
			category_any_team: array_map( 'boolval', (array) $merged['category_any_team'] ),
			entry_deadline_hours: (int) $merged['entry_deadline_hours'],
			objection_days: (int) $merged['objection_days'],
			forfeit_after_days: (int) $merged['forfeit_after_days'],
			postponement_approval_days: (int) $merged['postponement_approval_days'],
			sets_to_win: (int) $merged['sets_to_win'],
			team_match_games: (int) $merged['team_match_games'],
			forfeit_score: (int) $merged['forfeit_score'],
			two_player_max_wins: (int) $merged['two_player_max_wins'],
			cup_mode: (string) $merged['cup_mode'],
			cup_games: (int) $merged['cup_games'],
			cup_players: (int) $merged['cup_players'],
			set_points_enabled: (bool) $merged['set_points_enabled'],
			rl_round_points: array_values( array_map( 'intval', (array) $merged['rl_round_points'] ) ),
			rl_seven_player_bonus: (int) $merged['rl_seven_player_bonus'],
			rl_finalists: (int) $merged['rl_finalists'],
			rating_k: (float) $merged['rating_k'],
			rating_scale: (float) $merged['rating_scale'],
			rating_doubles_weight: (float) $merged['rating_doubles_weight'],
			rating_points_per_class: (float) $merged['rating_points_per_class'],
			match_reminder_days: (int) $merged['match_reminder_days'],
			result_reminder_hours: (int) $merged['result_reminder_hours'],
			confirm_reminder_days: $confirm,
		);
	}

	/**
	 * @return array<string,mixed>
	 */
	public function to_array(): array {
		return get_object_vars( $this );
	}

	/**
	 * Darf in dieser Kategorie unabhängig von der Mannschaftsnummer ausgeholfen werden?
	 */
	public function allows_any_team_in_category( string $category ): bool {
		return ! empty( $this->category_any_team[ strtoupper( $category ) ] );
	}

	/**
	 * Punkte für einen Rang in einer RL-Gruppe der angegebenen Grösse.
	 */
	public function rl_points_for_rank( int $rank, int $group_size ): int {
		$points = $this->rl_round_points[ $rank - 1 ] ?? 0;
		if ( $group_size >= 7 ) {
			$points += $this->rl_seven_player_bonus;
		}
		return $points;
	}
}
