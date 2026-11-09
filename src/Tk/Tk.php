<?php
/**
 * Backend der Technischen Kommission: Menü «Spielbetrieb» mit Übersicht und Konten.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Tk;

use TTLeague\Access\Capabilities;
use TTLeague\Accounts\Services;

final class Tk {

	/** Seite «Übersicht» (zugleich Hauptmenü). */
	public const OVERVIEW_PAGE = 'ttleague-tk';

	/** Seite «Konten und Einladungen». */
	public const ACCOUNTS_PAGE = 'ttleague-tk-konten';

	public const DECIDE           = 'ttleague_tk_decide';
	public const EMAIL            = 'ttleague_tk_email';
	public const INVITE           = 'ttleague_tk_invite';
	public const INVITE_OFFICIALS = 'ttleague_tk_invite_officials';

	/** Bereich der Rückmeldungen (Flash). */
	public const FLASH = 'tk';

	/** @var list<string> Hook-Suffixe der eigenen Seiten. */
	private static array $hooks = array();

	public static function register(): void {
		add_action( 'admin_menu', array( self::class, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( self::class, 'styles' ) );
		add_action( 'admin_post_' . self::DECIDE, array( Actions::class, 'decide' ) );
		add_action( 'admin_post_' . self::EMAIL, array( Actions::class, 'email' ) );
		add_action( 'admin_post_' . self::INVITE, array( Actions::class, 'invite' ) );
		add_action( 'admin_post_' . self::INVITE_OFFICIALS, array( Actions::class, 'invite_officials' ) );
	}

	public static function dashboard(): Dashboard {
		return new Dashboard(
			Services::db(),
			Services::clock(),
			wp_timezone(),
			static function ( array $user_ids ): array {
				$names = array();
				foreach ( get_users(
					array(
						'include' => $user_ids,
						'blog_id' => 0,
						'fields'  => array( 'ID', 'display_name' ),
					)
				) as $user ) {
					$names[ (int) $user->ID ] = (string) $user->display_name;
				}
				return $names;
			}
		);
	}

	public static function menu(): void {
		$title         = __( 'Spielbetrieb', 'ttleague' );
		self::$hooks   = array();
		self::$hooks[] = (string) add_menu_page( $title, $title, Capabilities::TK, self::OVERVIEW_PAGE, array( DashboardPage::class, 'render' ), 'dashicons-clipboard', 26 );
		self::$hooks[] = (string) add_submenu_page( self::OVERVIEW_PAGE, __( 'Übersicht', 'ttleague' ), __( 'Übersicht', 'ttleague' ), Capabilities::TK, self::OVERVIEW_PAGE, array( DashboardPage::class, 'render' ) );
		self::$hooks[] = (string) add_submenu_page( self::OVERVIEW_PAGE, __( 'Konten und Einladungen', 'ttleague' ), __( 'Konten und Einladungen', 'ttleague' ), Capabilities::TK, self::ACCOUNTS_PAGE, array( AccountsPage::class, 'render' ) );
	}

	public static function overview_url( string $anchor = '' ): string {
		return admin_url( 'admin.php?page=' . self::OVERVIEW_PAGE ) . ( '' === $anchor ? '' : '#' . $anchor );
	}

	public static function accounts_url( int $club_id = 0, string $anchor = '' ): string {
		$url = admin_url( 'admin.php?page=' . self::ACCOUNTS_PAGE );
		if ( $club_id > 0 ) {
			$url = add_query_arg( 'club', (string) $club_id, $url );
		}
		return $url . ( '' === $anchor ? '' : '#' . $anchor );
	}

	/**
	 * Wenige Regeln für Formulare in Tabellenzellen; sonst gelten die Standardklassen des Backends.
	 */
	public static function styles( string $hook ): void {
		if ( ! in_array( $hook, self::$hooks, true ) ) {
			return;
		}
		wp_add_inline_style(
			'common',
			'.ttl-tk .ttl-tk-nav{margin:0 0 8px;float:none}'
			. '.ttl-tk h2{margin-top:28px}'
			. '.ttl-tk h2 .count{color:#646970;font-weight:400}'
			. '.ttl-tk-table{table-layout:fixed}'
			. '.ttl-tk-table td{overflow-wrap:break-word}'
			. '.ttl-tk-table .ttl-col-fixture{width:19%}'
			. '.ttl-tk-table .ttl-col-date{width:13%}'
			. '.ttl-tk-table .ttl-col-group{width:8%}'
			. '.ttl-tk-table .ttl-col-status{width:11%}'
			. '.ttl-tk-table .ttl-col-age{width:7%}'
			. '.ttl-tk-table .ttl-col-details{width:27%}'
			. '.ttl-tk-table .ttl-col-action{width:15%}'
			. '.ttl-tk-table .ttl-col-name,.ttl-tk-table .ttl-col-functions{width:17%}'
			. '.ttl-tk-table .ttl-col-email{width:31%}'
			. '.ttl-tk-table .ttl-col-account{width:19%}'
			. '.ttl-tk-table .ttl-col-invite{width:16%}'
			. '.ttl-tk details>summary{cursor:pointer;list-style:none}'
			. '.ttl-tk details>summary::-webkit-details-marker{display:none}'
			. '.ttl-tk details[open]>summary{margin-bottom:6px}'
			. '.ttl-tk .ttl-tk-form{display:grid;gap:6px;justify-items:start;margin:0;max-width:320px}'
			. '.ttl-tk .ttl-tk-form textarea,.ttl-tk .ttl-tk-form select{width:100%;justify-self:stretch}'
			. '.ttl-tk .ttl-tk-inline{display:flex;gap:6px;align-items:center;margin:0}'
			. '.ttl-tk .ttl-tk-inline input[type=email]{flex:1 1 auto;min-width:0}'
			. '.ttl-tk .ttl-tk-inline .button{flex:none}'
			. '.ttl-tk .ttl-tk-warnings{margin:4px 0 0 18px;list-style:disc}'
			. '.ttl-tk .ttl-tk-muted{color:#646970}'
			. '.ttl-tk .ttl-tk-reason{margin:0 0 2px}'
			. '.ttl-tk .ttl-tk-filter{display:flex;flex-wrap:wrap;gap:8px;align-items:center;margin:12px 0}'
			. '.ttl-tk .ttl-tk-status{display:inline-block;padding:1px 8px;border-radius:10px;background:#f0f0f1;font-size:12px}'
			. '.ttl-tk .ttl-tk-status--active{background:#edfaef;color:#00450c}'
			. '.ttl-tk .ttl-tk-status--no_email{background:#fcf9e8;color:#614a00}'
			. '.ttl-tk .ttl-tk-status--invited{background:#f0f6fc;color:#043959}'
		);
	}
}
