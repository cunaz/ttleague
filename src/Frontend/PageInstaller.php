<?php
/**
 * Legt die WordPress-Seiten der öffentlichen Darstellung an (idempotent).
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Frontend;

use TTLeague\Frontend\Support\Urls;

/**
 * Jede Ansicht ist eine echte Seite mit dem passenden Block als Inhalt; das Block-Theme
 * rendert Kopf- und Fusszeile, die TK kann Text ergänzen. Die Seiten-IDs stehen in der
 * Option ttleague_pages. Bestehende Seiten (auch umbenannte oder bearbeitete) bleiben
 * unverändert; fehlende werden angelegt oder über ihren Pfad wiedergefunden.
 */
final class PageInstaller {

	public const OPTION         = 'ttleague_pages';
	public const VERSION_OPTION = 'ttleague_pages_version';
	public const VERSION        = '3';

	/**
	 * Seitendefinitionen: Schlüssel => Titel, Slug, Elternseite, Block.
	 *
	 * @return array<string,array{title:string,slug:string,parent:?string,block:string,menu_order:int}>
	 */
	public static function definitions(): array {
		return array(
			'championship' => array(
				'title'      => __( 'Meisterschaft', 'ttleague' ),
				'slug'       => 'meisterschaft',
				'parent'     => null,
				'block'      => 'ttleague/season-overview',
				'menu_order' => 10,
			),
			'group'        => array(
				'title'      => __( 'Gruppe', 'ttleague' ),
				'slug'       => 'gruppe',
				'parent'     => 'championship',
				'block'      => 'ttleague/group',
				'menu_order' => 11,
			),
			'fixture'      => array(
				'title'      => __( 'Begegnung', 'ttleague' ),
				'slug'       => 'begegnung',
				'parent'     => null,
				'block'      => 'ttleague/match-report',
				'menu_order' => 12,
			),
			'team'         => array(
				'title'      => __( 'Mannschaft', 'ttleague' ),
				'slug'       => 'mannschaft',
				'parent'     => null,
				'block'      => 'ttleague/team',
				'menu_order' => 13,
			),
			'cup'          => array(
				'title'      => __( 'Mannschaftscup', 'ttleague' ),
				'slug'       => 'cup',
				'parent'     => null,
				'block'      => 'ttleague/cup',
				'menu_order' => 20,
			),
			'ranking'      => array(
				'title'      => __( 'Ranglistenturnier', 'ttleague' ),
				'slug'       => 'ranglistenturnier',
				'parent'     => null,
				'block'      => 'ttleague/ranking-tournament',
				'menu_order' => 30,
			),
			'clubs'        => array(
				'title'      => __( 'Clubs', 'ttleague' ),
				'slug'       => 'clubs',
				'parent'     => null,
				'block'      => 'ttleague/clubs',
				'menu_order' => 40,
			),
			'club'         => array(
				'title'      => __( 'Club', 'ttleague' ),
				'slug'       => 'club',
				'parent'     => 'clubs',
				'block'      => 'ttleague/club',
				'menu_order' => 41,
			),
			'players'      => array(
				'title'      => __( 'Spieler', 'ttleague' ),
				'slug'       => 'spieler',
				'parent'     => null,
				'block'      => 'ttleague/players',
				'menu_order' => 50,
			),
			'player'       => array(
				'title'      => __( 'Spielerprofil', 'ttleague' ),
				'slug'       => 'profil',
				'parent'     => 'players',
				'block'      => 'ttleague/player',
				'menu_order' => 51,
			),
			'documents'    => array(
				'title'      => __( 'Dokumente', 'ttleague' ),
				'slug'       => 'dokumente',
				'parent'     => null,
				'block'      => 'ttleague/documents',
				'menu_order' => 55,
			),
			'archive'      => array(
				'title'      => __( 'Archiv', 'ttleague' ),
				'slug'       => 'archiv',
				'parent'     => null,
				'block'      => 'ttleague/archive',
				'menu_order' => 60,
			),
			'login'        => array(
				'title'      => __( 'Anmelden', 'ttleague' ),
				'slug'       => 'anmelden',
				'parent'     => null,
				'block'      => 'ttleague/login',
				'menu_order' => 90,
			),
			'my_area'      => array(
				'title'      => __( 'Mein Bereich', 'ttleague' ),
				'slug'       => 'mein-bereich',
				'parent'     => null,
				'block'      => 'ttleague/my-area',
				'menu_order' => 91,
			),
			'entry'        => array(
				'title'      => __( 'Resultat erfassen', 'ttleague' ),
				'slug'       => 'erfassen',
				'parent'     => null,
				'block'      => 'ttleague/result-entry',
				'menu_order' => 92,
			),
		);
	}

	/**
	 * Legt fehlende Seiten an und erneuert die Rewrite-Regeln.
	 *
	 * @return list<array{key:string,title:string,id:int,url:string,action:string}>
	 */
	public static function install(): array {
		$ids    = self::ids();
		$report = array();
		foreach ( self::definitions() as $key => $page ) {
			$action = 'vorhanden';
			$id     = $ids[ $key ] ?? 0;
			$post   = $id > 0 ? get_post( $id ) : null;
			if ( ! $post instanceof \WP_Post || 'page' !== $post->post_type || 'trash' === $post->post_status ) {
				$path  = null === $page['parent'] ? $page['slug'] : self::path_of( $ids[ $page['parent'] ] ?? 0, $page['parent'] ) . '/' . $page['slug'];
				$found = get_page_by_path( $path, OBJECT, 'page' );
				if ( $found instanceof \WP_Post && 'trash' !== $found->post_status ) {
					$id     = (int) $found->ID;
					$action = 'übernommen';
				} else {
					$id     = self::create( $page, null === $page['parent'] ? 0 : (int) ( $ids[ $page['parent'] ] ?? 0 ) );
					$action = $id > 0 ? 'angelegt' : 'Fehler';
				}
				$ids[ $key ] = $id;
			} elseif ( ! has_block( $page['block'], $post ) ) {
				$action = 'vorhanden (Block fehlt)';
			}
			$report[] = array(
				'key'    => $key,
				'title'  => $page['title'],
				'id'     => $id,
				'url'    => $id > 0 ? (string) get_permalink( $id ) : '',
				'action' => $action,
			);
		}
		update_option( self::OPTION, $ids, true );
		update_option( self::VERSION_OPTION, self::VERSION, true );

		Routes::add_rewrite_rules();
		flush_rewrite_rules( false );
		return $report;
	}

	/**
	 * Beim ersten Laden nach Aktivierung oder Update: Seiten anlegen, Regeln erneuern.
	 */
	public static function maybe_install(): void {
		if ( self::VERSION !== get_option( self::VERSION_OPTION ) ) {
			self::install();
		}
	}

	/**
	 * Deaktivierung: Regeln beim nächsten Aufruf ohne die Plugin-Regeln neu aufbauen.
	 */
	public static function deactivate(): void {
		delete_option( 'rewrite_rules' );
		delete_option( self::VERSION_OPTION );
	}

	/**
	 * Wird eine der Seiten umbenannt oder verschoben, Regeln neu aufbauen lassen.
	 */
	public static function on_page_saved( int $post_id ): void {
		if ( null !== self::key_for( $post_id ) ) {
			delete_option( 'rewrite_rules' );
		}
	}

	/**
	 * @return array<string,int> Seitenschlüssel => Seiten-ID.
	 */
	public static function ids(): array {
		$ids = get_option( self::OPTION, array() );
		return is_array( $ids ) ? array_map( 'intval', $ids ) : array();
	}

	public static function page_id( string $key ): int {
		return self::ids()[ $key ] ?? 0;
	}

	/**
	 * Seitenschlüssel zu einer Seiten-ID oder null.
	 */
	public static function key_for( int $page_id ): ?string {
		if ( $page_id <= 0 ) {
			return null;
		}
		$key = array_search( $page_id, self::ids(), true );
		return false === $key ? null : (string) $key;
	}

	/**
	 * Aktuelle Pfade aller Seiten (folgen Umbenennungen), sonst die Standardpfade.
	 *
	 * @return array<string,string>
	 */
	public static function paths(): array {
		$paths = array();
		foreach ( self::ids() as $key => $id ) {
			$path = self::path_of( $id, $key );
			if ( '' !== $path ) {
				$paths[ $key ] = $path;
			}
		}
		return $paths + Urls::DEFAULT_PATHS;
	}

	private static function path_of( int $id, string $key ): string {
		if ( $id > 0 ) {
			$post = get_post( $id );
			if ( $post instanceof \WP_Post && 'trash' !== $post->post_status ) {
				return trim( (string) get_page_uri( $post ), '/' );
			}
		}
		return Urls::DEFAULT_PATHS[ $key ] ?? '';
	}

	/**
	 * @param array{title:string,slug:string,parent:?string,block:string,menu_order:int} $page Definition.
	 */
	private static function create( array $page, int $parent_id ): int {
		$id = wp_insert_post(
			array(
				'post_type'      => 'page',
				'post_status'    => 'publish',
				'post_title'     => $page['title'],
				'post_name'      => $page['slug'],
				'post_parent'    => $parent_id,
				'menu_order'     => $page['menu_order'],
				'comment_status' => 'closed',
				'ping_status'    => 'closed',
				'post_content'   => '<!-- wp:' . $page['block'] . ' {"align":"wide"} /-->',
			),
			true
		);
		return is_wp_error( $id ) ? 0 : (int) $id;
	}
}
