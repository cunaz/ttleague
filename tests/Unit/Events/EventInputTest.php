<?php
/**
 * Tests der Eingabeprüfung eines Anlasses.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Tests\Unit\Events;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TTLeague\Events\EventException;
use TTLeague\Events\EventInput;

final class EventInputTest extends TestCase {

	public static function setUpBeforeClass(): void {
		require_once dirname( __DIR__ ) . '/Frontend/Stubs/WordPress.php';
	}

	/**
	 * @param array<string,mixed> $overrides Abweichende Felder.
	 * @return array<string,mixed>
	 */
	private static function input( array $overrides = array() ): array {
		return $overrides + array(
			'season_id'        => '3',
			'type'             => 'training',
			'title'            => 'Training für Einsteiger',
			'starts_at'        => '2026-11-20T19:30',
			'ends_at'          => '',
			'location'         => '',
			'deadline'         => '',
			'max_participants' => '',
			'post_id'          => '',
		);
	}

	/**
	 * Felder mit Fehlern.
	 *
	 * @param array<string,mixed> $input Eingabe.
	 * @return list<string>
	 */
	private static function fields( array $input ): array {
		try {
			EventInput::validate( $input );
		} catch ( EventException $e ) {
			self::assertSame( 422, $e->status );
			return array_column( $e->errors, 'field' );
		}
		return array();
	}

	public function test_minimal_input_becomes_column_values(): void {
		self::assertSame(
			array(
				'season_id'        => 3,
				'type'             => 'training',
				'title'            => 'Training für Einsteiger',
				'starts_at'        => '2026-11-20 19:30:00',
				'ends_at'          => null,
				'location'         => null,
				'deadline'         => null,
				'max_participants' => null,
				'post_id'          => null,
			),
			EventInput::validate( self::input() )
		);
	}

	public function test_full_input_is_normalised(): void {
		$data = EventInput::validate(
			self::input(
				array(
					'season_id'        => '',
					'title'            => "  Plausch-\n turnier ",
					'ends_at'          => '2026-11-20 22:00:00',
					'location'         => ' Turnhalle  Seefeld ',
					'deadline'         => '2026-11-20T19:30',
					'max_participants' => ' 16 ',
					'post_id'          => '42',
				)
			)
		);

		self::assertNull( $data['season_id'] );
		self::assertSame( 'Plausch- turnier', $data['title'] );
		self::assertSame( '2026-11-20 22:00:00', $data['ends_at'] );
		self::assertSame( 'Turnhalle Seefeld', $data['location'] );
		self::assertSame( '2026-11-20 19:30:00', $data['deadline'], 'Anmeldeschluss genau zum Beginn ist erlaubt' );
		self::assertSame( 16, $data['max_participants'] );
		self::assertSame( 42, $data['post_id'] );
	}

	/**
	 * @return iterable<string,array{array<string,mixed>,list<string>}>
	 */
	public static function invalid(): iterable {
		yield 'ohne Titel' => array( array( 'title' => '   ' ), array( 'title' ) );
		yield 'Titel mit 161 Zeichen' => array( array( 'title' => str_repeat( 'ä', 161 ) ), array( 'title' ) );
		yield 'unbekannte Art' => array( array( 'type' => 'party' ), array( 'type' ) );
		yield 'ohne Beginn' => array( array( 'starts_at' => '' ), array( 'starts_at' ) );
		yield 'Beginn ohne Zeit' => array( array( 'starts_at' => '2026-11-20' ), array( 'starts_at' ) );
		yield 'Beginn am 30. Februar' => array( array( 'starts_at' => '2026-02-30T19:30' ), array( 'starts_at' ) );
		yield 'Beginn um 24 Uhr' => array( array( 'starts_at' => '2026-11-20T24:00' ), array( 'starts_at' ) );
		yield 'Ende vor dem Beginn' => array( array( 'ends_at' => '2026-11-20T19:29' ), array( 'ends_at' ) );
		yield 'Anmeldeschluss eine Minute nach dem Beginn' => array( array( 'deadline' => '2026-11-20T19:31' ), array( 'deadline' ) );
		yield 'Anmeldeschluss unlesbar' => array( array( 'deadline' => 'morgen' ), array( 'deadline' ) );
		yield 'Ort mit 161 Zeichen' => array( array( 'location' => str_repeat( 'x', 161 ) ), array( 'location' ) );
		yield 'Höchstzahl 0' => array( array( 'max_participants' => '0' ), array( 'max_participants' ) );
		yield 'Höchstzahl 65536' => array( array( 'max_participants' => '65536' ), array( 'max_participants' ) );
		yield 'Höchstzahl negativ' => array( array( 'max_participants' => '-3' ), array( 'max_participants' ) );
		yield 'Höchstzahl mit Komma' => array( array( 'max_participants' => '12,5' ), array( 'max_participants' ) );
		yield 'Beitrag unlesbar' => array( array( 'post_id' => 'abc' ), array( 'post_id' ) );
		yield 'Saison unlesbar' => array( array( 'season_id' => 'x' ), array( 'season_id' ) );
		yield 'mehrere Fehler' => array(
			array(
				'title'     => '',
				'type'      => '',
				'starts_at' => '',
			),
			array( 'title', 'type', 'starts_at' ),
		);
	}

	/**
	 * @param array<string,mixed> $overrides Abweichende Felder.
	 * @param list<string>        $fields    Erwartete Fehlerfelder.
	 */
	#[DataProvider( 'invalid' )]
	public function test_invalid_input_names_the_fields( array $overrides, array $fields ): void {
		self::assertSame( $fields, self::fields( self::input( $overrides ) ) );
	}

	public function test_limits_at_their_borders_are_valid(): void {
		self::assertSame( array(), self::fields( self::input( array( 'title' => str_repeat( 'ä', 160 ) ) ) ) );
		self::assertSame( array(), self::fields( self::input( array( 'location' => str_repeat( 'x', 160 ) ) ) ) );
		self::assertSame( 1, EventInput::validate( self::input( array( 'max_participants' => '1' ) ) )['max_participants'] );
		self::assertSame( 65535, EventInput::validate( self::input( array( 'max_participants' => '65535' ) ) )['max_participants'] );
		self::assertSame( array(), self::fields( self::input( array( 'ends_at' => '2026-11-20T19:30' ) ) ), 'Ende gleich Beginn' );
	}

	public function test_a_single_error_is_the_message(): void {
		try {
			EventInput::validate( self::input( array( 'title' => '' ) ) );
			self::fail( 'Fehler erwartet.' );
		} catch ( EventException $e ) {
			self::assertSame( 'Bitte gib einen Titel an.', $e->getMessage() );
		}
		try {
			EventInput::validate(
				self::input(
					array(
						'title' => '',
						'type'  => '',
					)
				)
			);
			self::fail( 'Fehler erwartet.' );
		} catch ( EventException $e ) {
			self::assertSame( 'Die Eingabe enthält Fehler.', $e->getMessage() );
		}
	}

	public function test_note_is_one_line_and_at_most_200_characters(): void {
		self::assertNull( EventInput::note( null ) );
		self::assertNull( EventInput::note( " \n " ) );
		self::assertSame( 'Komme später', EventInput::note( " Komme\n\tspäter " ) );
		self::assertSame( 200, mb_strlen( (string) EventInput::note( str_repeat( 'ö', 250 ) ) ) );
	}
}
