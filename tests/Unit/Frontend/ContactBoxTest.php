<?php
/**
 * Links der Kontaktdaten (mailto:, tel:) und ihre Maskierung.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Tests\Unit\Frontend;

use PHPUnit\Framework\TestCase;
use TTLeague\Frontend\Views\ContactBox;

final class ContactBoxTest extends TestCase {

	public static function setUpBeforeClass(): void {
		require_once __DIR__ . '/Stubs/WordPress.php';
	}

	public function test_email_and_phone_become_links(): void {
		$html = ContactBox::links(
			array(
				'email' => 'hanna.heim@example.org',
				'phone' => '079 555 01 01',
			)
		);

		self::assertStringContainsString( '<a class="ttl-contact__link" href="mailto:hanna.heim@example.org">hanna.heim@example.org</a>', $html );
		self::assertStringContainsString( '<a class="ttl-contact__link" href="tel:0795550101">079 555 01 01</a>', $html );
		self::assertStringStartsWith( '<span class="ttl-contact">', $html );
	}

	public function test_missing_data_leaves_no_empty_links(): void {
		self::assertSame( '', ContactBox::links( null ) );
		self::assertSame(
			'',
			ContactBox::links(
				array(
					'email' => null,
					'phone' => null,
				)
			)
		);
		self::assertSame(
			'',
			ContactBox::links(
				array(
					'email' => ' ',
					'phone' => 'siehe Clubliste',
				)
			),
			'keine wählbare Nummer'
		);

		$only_phone = ContactBox::links(
			array(
				'email' => null,
				'phone' => '+41 (0)79 555 01 03',
			)
		);
		self::assertStringNotContainsString( 'mailto:', $only_phone );
		self::assertStringContainsString( 'href="tel:+41795550103"', $only_phone );
		self::assertStringNotContainsString( '·', $only_phone, 'kein Trenner ohne zweiten Link' );

		$only_email = ContactBox::links(
			array(
				'email' => 'zora.zweit@example.org',
				'phone' => null,
			)
		);
		self::assertStringNotContainsString( 'tel:', $only_email );
	}

	public function test_values_are_escaped_and_invalid_addresses_dropped(): void {
		$html = ContactBox::links(
			array(
				'email' => 'kein"><script>',
				'phone' => '079 <b>555</b> 01 01',
			)
		);

		self::assertStringNotContainsString( 'mailto:', $html, 'ungültige Adresse' );
		self::assertStringNotContainsString( '<b>', $html );
		self::assertStringContainsString( 'href="tel:0795550101"', $html );
		self::assertStringContainsString( '079 &lt;b&gt;555&lt;/b&gt; 01 01', $html );
	}

	public function test_dial_keeps_digits_and_a_leading_plus(): void {
		self::assertSame( '+41795550103', ContactBox::dial( '+41 (0)79 555 01 03' ) );
		self::assertSame( '0445550199', ContactBox::dial( '044 / 555 01 99' ) );
		self::assertSame( '', ContactBox::dial( '' ) );
		self::assertSame( '', ContactBox::dial( '12' ) );
	}
}
