<?php
/**
 * Knopf «Teilen»: Auszeichnung, Maskierung und Titel.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Tests\Unit\Frontend;

use PHPUnit\Framework\TestCase;
use TTLeague\Frontend\Views\ShareButton;

final class ShareButtonTest extends TestCase {

	public static function setUpBeforeClass(): void {
		require_once __DIR__ . '/Stubs/WordPress.php';
	}

	public function test_title_names_both_teams_and_the_result(): void {
		self::assertSame( 'Seefeld 1 – Wollishofen 1 6:4', ShareButton::title( 'Seefeld 1', 'Wollishofen 1', 6, 4 ) );
		self::assertSame( 'Seefeld 1 – Wollishofen 1 0:10', ShareButton::title( 'Seefeld 1', 'Wollishofen 1', 0, 10 ) );
	}

	public function test_button_is_hidden_without_script_and_carries_title_and_address(): void {
		$html = ShareButton::render( 'Seefeld 1 – Wollishofen 1 6:4', 'https://liga.example/begegnung/12/' );

		self::assertStringStartsWith( '<div class="ttl-share" data-ttl-share ', $html );
		self::assertMatchesRegularExpression( '#^<div [^>]* hidden>#', $html, 'ohne JavaScript verborgen' );
		self::assertStringContainsString( 'data-ttl-share-title="Seefeld 1 – Wollishofen 1 6:4"', $html );
		self::assertStringContainsString( 'data-ttl-share-url="https://liga.example/begegnung/12/"', $html );
		self::assertStringContainsString( 'data-ttl-share-copy="Link kopieren"', $html );
		self::assertStringContainsString( 'data-ttl-share-copied="Link kopiert."', $html );
		self::assertMatchesRegularExpression( '#<button type="button" class="ttl-button ttl-button--secondary ttl-share__button"><svg [^>]*aria-hidden="true"[^>]*>.*</svg><span class="ttl-share__label">Teilen</span></button>#', $html );
		self::assertStringContainsString( '<span class="ttl-share__status" role="status" aria-live="polite"></span>', $html, 'leere Live-Region für die Rückmeldung' );
	}

	public function test_title_and_address_are_escaped(): void {
		$html = ShareButton::render( 'Seefeld <2> & "Co" – Wollishofen 1 6:4', 'https://liga.example/begegnung/12/?a=1&b="x"' );

		self::assertStringContainsString( 'data-ttl-share-title="Seefeld &lt;2&gt; &amp; &quot;Co&quot; – Wollishofen 1 6:4"', $html );
		self::assertStringNotContainsString( '<2>', $html );
		self::assertStringNotContainsString( '"x"', $html );
		self::assertSame( '', ShareButton::render( 'Titel', '' ), 'ohne Adresse kein Knopf' );
	}
}
