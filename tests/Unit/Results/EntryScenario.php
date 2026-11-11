<?php
/**
 * Erfundene Begegnung im Format von ResultService::entry() für die Tests des Formulars.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Tests\Unit\Results;

use TTLeague\Domain\Match\GameOrder;
use TTLeague\Results\EntryForm;

/**
 * Seefeld 1 (Heim, mit einem Ersatz und einer gesperrten Spielerin) gegen Wollishofen 1.
 */
final class EntryScenario {

	/**
	 * @param array<string,mixed>             $current    Gespeichertes Resultat.
	 * @param array<string,mixed>             $rules      Abweichende Regeln.
	 * @param array<string,array<string,int>> $suggestion Vorgeschlagene Aufstellung pro Seite (Slot => Spieler-ID).
	 * @return array<string,mixed>
	 */
	public static function entry( string $type = 'MM', array $current = array(), array $rules = array(), array $suggestion = array() ): array {
		$cup        = 'CUP' === $type;
		$candidate  = static fn( int $id, string $name, int $rank, string $reason = 'stamm', bool $ok = true, string $message = 'Stammspieler.', int $subs = 0 ): array => array(
			'player_id'              => $id,
			'name'                   => $name,
			'license_no'             => 90000 + $id,
			'classification'         => $rank,
			'label'                  => 'C' . $rank,
			'eligible'               => $ok,
			'reason'                 => $reason,
			'message'                => $message,
			'substitute_appearances' => $subs,
			'remaining'              => null,
		);
		$candidates = array(
			'home' => array(
				$candidate( 11, 'Hanna Heim', 8 ),
				$candidate( 12, 'Hugo Heim', 7 ),
				$candidate( 13, 'Hilde Heim', 7 ),
				$candidate( 14, 'Hans Ersatz', 6, 'substitute', true, '2. Einsatz als Ersatz von maximal 3, danach gebunden.', 1 ),
				$candidate( 15, 'Herta Gesperrt', 9, 'blocked_lower_team', false, 'Stammspieler sind für tiefer klassierte Mannschaften nicht spielberechtigt (Art. 12.2).' ),
			),
			'away' => array(
				$candidate( 21, 'Gabi Gast', 8 ),
				$candidate( 22, 'Gerd Gast', 7 ),
				$candidate( 23, 'Greta Gast', 6 ),
			),
		);
		return array(
			'fixture'    => array(
				'id'           => 1,
				'type'         => $type,
				'status'       => 'scheduled',
				'status_label' => 'angesetzt',
				'scheduled_at' => '2026-11-10 20:00:00',
				'competition'  => $cup ? 'Cup A' : 'Gruppe B1',
				'season'       => '2026-27',
				'home'         => array( 'name' => 'Seefeld 1' ),
				'away'         => array( 'name' => 'Wollishofen 1' ),
				'home_score'   => null,
				'away_score'   => null,
			),
			'can'        => array(
				'enter'   => true,
				'confirm' => false,
				'object'  => false,
				'decide'  => false,
				'photo'   => true,
			),
			'roles'      => array( 'home' ),
			'rules'      => array(
				'sets_to_win'        => 3,
				'set_points_enabled' => $rules['set_points_enabled'] ?? false,
				'cup_mode'           => $rules['cup_mode'] ?? 'first_to_three',
				'substitute_max'     => 3,
				'slots'              => array(
					'home' => $cup ? array( 'A', 'B' ) : array( 'A', 'B', 'C' ),
					'away' => $cup ? array( 'Y', 'Z' ) : array( 'X', 'Y', 'Z' ),
				),
			),
			'order'      => $cup ? GameOrder::cup() : GameOrder::team_match(),
			'candidates' => $candidates,
			'suggestion' => array(
				'home' => (object) ( $suggestion['home'] ?? array() ),
				'away' => (object) ( $suggestion['away'] ?? array() ),
			),
			'current'    => $current + array(
				'lineup'      => array(
					'home' => (object) array(),
					'away' => (object) array(),
				),
				'substitutes' => array(),
				'games'       => array(),
				'warnings'    => array(),
				'photo_id'    => null,
			),
			'history'    => array(),
			'version'    => 'abc',
		);
	}

	/**
	 * Formular mit voller Aufstellung, optional mit fehlenden Slots.
	 *
	 * @param array<string,array<string,string>> $lineup Abweichungen pro Seite.
	 * @return array{0:array<string,mixed>,1:array<string,mixed>} Daten und Zustand.
	 */
	public static function form( array $lineup = array(), string $type = 'MM', array $rules = array() ): array {
		$entry          = self::entry( $type, array(), $rules );
		$data           = EntryForm::data( $entry );
		$form           = EntryForm::initial( $entry, $data );
		$full           = 'CUP' === $type
			? array(
				'home' => array(
					'A' => '11',
					'B' => '12',
				),
				'away' => array(
					'Y' => '21',
					'Z' => '22',
				),
			)
			: array(
				'home' => array(
					'A' => '11',
					'B' => '12',
					'C' => '13',
				),
				'away' => array(
					'X' => '21',
					'Y' => '22',
					'Z' => '23',
				),
			);
		$form['lineup'] = array_replace_recursive( $full, $lineup );
		return array( $data, $form );
	}

	/**
	 * @param array<string,mixed> $form Zustand.
	 */
	public static function play( array &$form, int $seq, int $home, int $away ): void {
		$form['games'][ (string) $seq ]['st'] = 'played';
		$form['games'][ (string) $seq ]['sh'] = $home;
		$form['games'][ (string) $seq ]['sa'] = $away;
	}
}
