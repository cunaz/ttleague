<?php
/**
 * Seiten der Darstellung mit echten Seiten: Übernahme mit und ohne Block, «Website-Zustand».
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Tests\WordPress\Frontend;

use TTLeague\Frontend\PageInstaller;
use TTLeague\Tests\WordPress\TestCase;

/**
 * Jeder Test beginnt ohne Seiten; die Testtransaktion rollt Seiten und Optionen zurück.
 */
final class PageInstallerTest extends TestCase {

	public function set_up(): void {
		parent::set_up();
		delete_option( PageInstaller::OPTION );
		$pages = get_posts(
			array(
				'post_type'   => 'page',
				'post_status' => 'any',
				'numberposts' => -1,
				'fields'      => 'ids',
			)
		);
		foreach ( $pages as $id ) {
			wp_delete_post( (int) $id, true );
		}
	}

	public function test_a_found_page_without_its_block_is_adopted_unchanged_and_reported(): void {
		$own    = $this->own_entry_page( '<!-- wp:paragraph --><p>Eigener Text</p><!-- /wp:paragraph -->' );
		$before = get_post( $own );

		$adopted = $this->row( PageInstaller::install(), 'entry' );
		$again   = $this->row( PageInstaller::install(), 'entry' );

		self::assertSame( $own, $adopted['id'] );
		self::assertSame( 'übernommen (Block fehlt)', $adopted['action'] );
		self::assertSame( 'vorhanden (Block fehlt)', $again['action'] );
		$after = get_post( $own );
		self::assertInstanceOf( \WP_Post::class, $before );
		self::assertInstanceOf( \WP_Post::class, $after );
		self::assertSame( $before->post_content, $after->post_content );
		self::assertSame( $before->post_status, $after->post_status );
		self::assertSame( $before->post_modified_gmt, $after->post_modified_gmt );
		self::assertSame( array( 'entry' => PageInstaller::NO_BLOCK ), $this->problems() );
	}

	public function test_a_found_page_with_its_block_is_adopted_without_report(): void {
		$own = $this->own_entry_page( '<!-- wp:paragraph --><p>Hinweis der TK</p><!-- /wp:paragraph --><!-- wp:ttleague/result-entry /-->' );

		$adopted = $this->row( PageInstaller::install(), 'entry' );

		self::assertSame( $own, $adopted['id'] );
		self::assertSame( 'übernommen', $adopted['action'] );
		self::assertSame( array(), $this->problems() );
	}

	public function test_site_health_is_good_when_every_page_is_published_with_its_block(): void {
		$report = PageInstaller::install();

		self::assertSame( array( 'angelegt' ), array_values( array_unique( array_column( $report, 'action' ) ) ) );
		$result = PageInstaller::site_status();
		self::assertSame( 'good', $result['status'] );
		self::assertSame( 'ttleague_pages', $result['test'] );
		self::assertSame( 10, has_filter( 'site_status_tests', array( PageInstaller::class, 'site_status_tests' ) ) );
		$tests = PageInstaller::site_status_tests(
			array(
				'direct' => array(),
				'async'  => array(),
			)
		);
		self::assertSame( array( PageInstaller::class, 'site_status' ), $tests['direct']['ttleague_pages']['test'] );
	}

	public function test_site_health_lists_each_page_to_fix_with_a_link_to_edit_it(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$own = $this->own_entry_page( '<!-- wp:paragraph --><p>Eigener Text</p><!-- /wp:paragraph -->' );
		PageInstaller::install();
		$ids = PageInstaller::ids();
		wp_update_post(
			array(
				'ID'          => $ids['archive'],
				'post_status' => 'draft',
			)
		);
		wp_delete_post( $ids['documents'], true );

		self::assertSame(
			array(
				'documents' => PageInstaller::MISSING,
				'archive'   => PageInstaller::UNPUBLISHED,
				'entry'     => PageInstaller::NO_BLOCK,
			),
			$this->problems()
		);
		$result = PageInstaller::site_status();
		self::assertSame( 'recommended', $result['status'] );
		self::assertSame( 'ttleague_pages', $result['test'] );
		$description = $result['description'];
		self::assertStringContainsString( '«Dokumente» fehlt.', $description );
		self::assertStringContainsString( '«Archiv» ist nicht veröffentlicht.', $description );
		self::assertStringContainsString( '«Resultat erfassen» enthält den Block ttleague/result-entry nicht.', $description );
		self::assertStringContainsString( esc_url( (string) get_edit_post_link( $own, 'raw' ) ), $description );
		self::assertStringContainsString( esc_url( (string) get_edit_post_link( $ids['archive'], 'raw' ) ), $description );
		self::assertSame( 2, substr_count( $description, 'post.php?post=' ), 'Link nur zu vorhandenen Seiten' );
	}

	/**
	 * Eigene Seite unter /erfassen/, bevor das Plugin seine Seiten anlegt.
	 */
	private function own_entry_page( string $content ): int {
		return self::factory()->post->create(
			array(
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_name'    => 'erfassen',
				'post_title'   => 'Erfassen',
				'post_content' => $content,
			)
		);
	}

	/**
	 * Zeile einer Seite im Bericht von install().
	 *
	 * @param list<array{key:string,title:string,id:int,url:string,action:string}> $report Bericht.
	 * @return array{key:string,title:string,id:int,url:string,action:string}
	 */
	private function row( array $report, string $key ): array {
		foreach ( $report as $row ) {
			if ( $key === $row['key'] ) {
				return $row;
			}
		}
		self::fail( 'Keine Zeile für ' . $key );
	}

	/**
	 * @return array<string,string> Seitenschlüssel => Problem.
	 */
	private function problems(): array {
		return array_column( PageInstaller::problems(), 'problem', 'key' );
	}
}
