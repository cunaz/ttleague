<?php
/**
 * Regelformular einer Saison: Typen, Grenzen und Fehler am Feld.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Tests\Unit\Domain;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TTLeague\Domain\Rules\SeasonRules;
use TTLeague\Domain\Season\RulesInput;

final class SeasonRulesInputTest extends TestCase {

	/**
	 * Eingabe wie aus dem Formular: jede Regel als Text.
	 *
	 * @param array<string,mixed> $change Abweichende Felder.
	 * @return array<string,mixed>
	 */
	private static function form( array $change = array() ): array {
		$form = array();
		foreach ( ( new SeasonRules() )->to_array() as $field => $value ) {
			$form[ $field ] = RulesInput::text( $field, $value );
		}
		unset( $form['set_points_enabled'] ); // Nicht angekreuzt: Das Feld fehlt.
		return $change + $form;
	}

	public function test_the_form_knows_every_rule(): void {
		$fields = array_keys( RulesInput::FIELDS );
		$rules  = array_keys( ( new SeasonRules() )->to_array() );
		sort( $fields );
		sort( $rules );
		self::assertSame( $rules, $fields );
	}

	public function test_the_default_rules_survive_the_round_trip(): void {
		$parsed = RulesInput::parse( self::form() );
		self::assertSame( array(), $parsed['errors'] );
		self::assertEquals( ( new SeasonRules() )->to_array(), $parsed['rules'] );
	}

	public function test_values_are_converted_to_their_types(): void {
		$parsed = RulesInput::parse(
			self::form(
				array(
					'substitute_max'        => ' 4 ',
					'category_any_team'     => 'c, d',
					'rl_round_points'       => '7,5, 3',
					'confirm_reminder_days' => '',
					'rating_k'              => '12,5',
					'set_points_enabled'    => '1',
					'cup_mode'              => 'all_games',
				)
			)
		);
		self::assertSame( array(), $parsed['errors'] );
		self::assertSame( 4, $parsed['rules']['substitute_max'] );
		self::assertSame(
			array(
				'C' => true,
				'D' => true,
			),
			$parsed['rules']['category_any_team']
		);
		self::assertSame( array( 7, 5, 3 ), $parsed['rules']['rl_round_points'] );
		self::assertSame( array(), $parsed['rules']['confirm_reminder_days'] );
		self::assertSame( 12.5, $parsed['rules']['rating_k'] );
		self::assertTrue( $parsed['rules']['set_points_enabled'] );
		self::assertSame( 'all_games', $parsed['rules']['cup_mode'] );
	}

	public function test_a_missing_checkbox_means_no(): void {
		$parsed = RulesInput::parse( self::form(), array( 'set_points_enabled' => true ) );
		self::assertFalse( $parsed['rules']['set_points_enabled'] );
	}

	public function test_outside_a_form_missing_fields_keep_their_value(): void {
		$parsed = RulesInput::parse( array( 'substitute_max' => '5' ), array( 'set_points_enabled' => true ) + ( new SeasonRules() )->to_array(), false );
		self::assertSame( array(), $parsed['errors'] );
		self::assertTrue( $parsed['rules']['set_points_enabled'] );
		self::assertSame( 5, $parsed['rules']['substitute_max'] );
	}

	/**
	 * @return array<string,array{0:string,1:string}>
	 */
	public static function invalid_values(): array {
		return array(
			'keine Zahl'                        => array( 'substitute_max', 'drei' ),
			'negativ'                           => array( 'substitute_max', '-1' ),
			'Kommazahl statt ganzer Zahl'       => array( 'objection_days', '7.5' ),
			'unter der Grenze'                  => array( 'entry_deadline_hours', '0' ),
			'über der Grenze'                   => array( 'sets_to_win', '6' ),
			'unbekannter Cup-Modus'             => array( 'cup_mode', 'best_of_five' ),
			'Liste mit Text'                    => array( 'rl_round_points', '6, fünf' ),
			'Kategorie mit Sonderzeichen'       => array( 'category_any_team', 'D; C' ),
			'Tische pro Begegnung null'         => array( 'schedule_tables_per_match', '0' ),
			'Erinnerung nach der Einsprache'    => array( 'confirm_reminder_days', '3, 7' ),
			'Ausnahmegrösse unter der üblichen' => array( 'rl_group_size_max', '5' ),
			'Forfait höher als Spiele'          => array( 'forfeit_score', '11' ),
			'Gewicht des Doppels über eins'     => array( 'rating_doubles_weight', '1.5' ),
			'K-Faktor null'                     => array( 'rating_k', '0' ),
		);
	}

	#[DataProvider( 'invalid_values' )]
	public function test_an_invalid_value_is_reported_at_its_field_and_nothing_is_returned( string $field, string $value ): void {
		$parsed = RulesInput::parse( self::form( array( $field => $value ) ) );
		self::assertNull( $parsed['rules'] );
		self::assertSame( array( $field ), array_column( $parsed['errors'], 'field' ) );
		self::assertNotSame( '', $parsed['errors'][0]['message'] );
	}

	public function test_the_limits_themselves_are_allowed(): void {
		$parsed = RulesInput::parse(
			self::form(
				array(
					'substitute_max'            => '0',
					'sets_to_win'               => '5',
					'schedule_tables_per_match' => '1',
					'confirm_reminder_days'     => '6',
					'rl_group_size'             => '7',
					'rl_group_size_max'         => '7',
					'forfeit_score'             => '10',
					'rating_doubles_weight'     => '1',
				)
			)
		);
		self::assertSame( array(), $parsed['errors'] );
		self::assertSame( array( 6 ), $parsed['rules']['confirm_reminder_days'] );
	}

	public function test_several_errors_are_reported_together(): void {
		$parsed = RulesInput::parse(
			self::form(
				array(
					'substitute_max' => 'x',
					'objection_days' => '0',
				)
			)
		);
		self::assertSame( array( 'substitute_max', 'objection_days' ), array_column( $parsed['errors'], 'field' ) );
	}

	public function test_arrays_from_a_forged_form_are_rejected(): void {
		$parsed = RulesInput::parse( self::form( array( 'substitute_max' => array( '3' ) ) ) );
		self::assertSame( array( 'substitute_max' ), array_column( $parsed['errors'], 'field' ) );
	}

	public function test_lists_and_categories_are_shown_as_text(): void {
		self::assertSame( '6, 5, 4', RulesInput::text( 'rl_round_points', array( 6, 5, 4 ) ) );
		self::assertSame(
			'D',
			RulesInput::text(
				'category_any_team',
				array(
					'C' => false,
					'D' => true,
				)
			)
		);
		self::assertSame( '0.5', RulesInput::text( 'rating_doubles_weight', 0.5 ) );
		self::assertSame( '16', RulesInput::text( 'rating_k', 16.0 ) );
		self::assertSame( '', RulesInput::text( 'set_points_enabled', false ) );
	}
}
