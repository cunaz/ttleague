<?php
/**
 * Tests der Statusübergänge einer Begegnung.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Tests\Unit\Domain;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TTLeague\Domain\Match\FixtureWorkflow as Flow;

final class FixtureWorkflowTest extends TestCase {

	/**
	 * @return iterable<string,array{string,string,list<string>,bool}>
	 */
	public static function cases(): iterable {
		yield 'Heim erfasst angesetzte Begegnung' => array( Flow::ENTER, Flow::SCHEDULED, array( Flow::ROLE_HOME ), true );
		yield 'Heim erfasst verschobene Begegnung' => array( Flow::ENTER, Flow::POSTPONED, array( Flow::ROLE_HOME ), true );
		yield 'Heim ändert provisorisches Resultat' => array( Flow::ENTER, Flow::PROVISIONAL, array( Flow::ROLE_HOME ), true );
		yield 'Heim ändert nicht nach Bestätigung' => array( Flow::ENTER, Flow::CONFIRMED, array( Flow::ROLE_HOME ), false );
		yield 'Gast erfasst nicht' => array( Flow::ENTER, Flow::SCHEDULED, array( Flow::ROLE_AWAY ), false );
		yield 'TK erfasst' => array( Flow::ENTER, Flow::SCHEDULED, array( Flow::ROLE_TK ), true );
		yield 'Gast bestätigt' => array( Flow::CONFIRM, Flow::PROVISIONAL, array( Flow::ROLE_AWAY ), true );
		yield 'Heim bestätigt nicht' => array( Flow::CONFIRM, Flow::PROVISIONAL, array( Flow::ROLE_HOME ), false );
		yield 'TK bestätigt' => array( Flow::CONFIRM, Flow::PROVISIONAL, array( Flow::ROLE_TK ), true );
		yield 'Bestätigen nur provisorisch' => array( Flow::CONFIRM, Flow::DISPUTED, array( Flow::ROLE_AWAY, Flow::ROLE_TK ), false );
		yield 'Gast erhebt Einsprache' => array( Flow::OBJECT, Flow::PROVISIONAL, array( Flow::ROLE_AWAY ), true );
		yield 'TK erhebt keine Einsprache' => array( Flow::OBJECT, Flow::PROVISIONAL, array( Flow::ROLE_TK ), false );
		yield 'Einsprache nicht nach Bestätigung' => array( Flow::OBJECT, Flow::CONFIRMED, array( Flow::ROLE_AWAY ), false );
		yield 'TK schliesst strittige ab' => array( Flow::FINALIZE, Flow::DISPUTED, array( Flow::ROLE_TK ), true );
		yield 'Abschliessen nicht ohne Resultat' => array( Flow::FINALIZE, Flow::SCHEDULED, array( Flow::ROLE_TK ), false );
		yield 'Gast schliesst nicht ab' => array( Flow::FINALIZE, Flow::CONFIRMED, array( Flow::ROLE_AWAY ), false );
		yield 'TK korrigiert abgeschlossene' => array( Flow::CORRECT, Flow::FINAL, array( Flow::ROLE_TK ), true );
		yield 'TK wertet forfait' => array( Flow::FORFEIT_, Flow::SCHEDULED, array( Flow::ROLE_TK ), true );
		yield 'TK setzt zurück' => array( Flow::RESET, Flow::FINAL, array( Flow::ROLE_TK ), true );
		yield 'Zurücksetzen nicht aus angesetzt' => array( Flow::RESET, Flow::SCHEDULED, array( Flow::ROLE_TK ), false );
		yield 'Ohne Rolle nichts' => array( Flow::ENTER, Flow::SCHEDULED, array(), false );
		yield 'Unbekannte Aktion' => array( 'delete', Flow::SCHEDULED, array( Flow::ROLE_TK ), false );
	}

	/**
	 * @param list<string> $roles Rollen.
	 */
	#[DataProvider( 'cases' )]
	public function test_allows( string $action, string $status, array $roles, bool $expected ): void {
		self::assertSame( $expected, Flow::allows( $action, $status, $roles ) );
	}

	public function test_targets(): void {
		self::assertSame( Flow::PROVISIONAL, Flow::target( Flow::ENTER ) );
		self::assertSame( Flow::CONFIRMED, Flow::target( Flow::CONFIRM ) );
		self::assertSame( Flow::DISPUTED, Flow::target( Flow::OBJECT ) );
		self::assertSame( Flow::FINAL, Flow::target( Flow::CORRECT ) );
		self::assertSame( Flow::FORFEIT, Flow::target( Flow::FORFEIT_ ) );
		self::assertSame( Flow::SCHEDULED, Flow::target( Flow::RESET ) );
		$this->expectException( \InvalidArgumentException::class );
		Flow::target( 'delete' );
	}

	public function test_counted_and_provisional_statuses(): void {
		self::assertTrue( Flow::counts( Flow::PROVISIONAL ) );
		self::assertTrue( Flow::counts( Flow::FORFEIT ) );
		self::assertFalse( Flow::counts( Flow::SCHEDULED ) );
		self::assertTrue( Flow::is_provisional( Flow::DISPUTED ) );
		self::assertFalse( Flow::is_provisional( Flow::CONFIRMED ) );
	}

	public function test_role_may_ignores_status(): void {
		self::assertTrue( Flow::role_may( Flow::CONFIRM, array( Flow::ROLE_AWAY ) ) );
		self::assertFalse( Flow::role_may( Flow::CONFIRM, array( Flow::ROLE_HOME ) ) );
	}
}
