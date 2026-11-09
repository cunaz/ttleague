<?php
/**
 * Rolle der Technischen Kommission.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Access;

final class Roles {

	public const ROLE    = 'ttl_tk';
	public const OPTION  = 'ttleague_roles_version';
	public const VERSION = '1';

	public static function install(): void {
		$caps = array(
			'read'           => true,
			'upload_files'   => true,
			Capabilities::TK => true,
		);
		$role = get_role( self::ROLE );
		if ( null === $role ) {
			add_role( self::ROLE, __( 'Technische Kommission', 'ttleague' ), $caps );
		} else {
			foreach ( $caps as $cap => $grant ) {
				$role->add_cap( $cap, $grant );
			}
		}
		$admin = get_role( 'administrator' );
		if ( null !== $admin ) {
			$admin->add_cap( Capabilities::TK );
		}
		update_option( self::OPTION, self::VERSION, false );
	}

	public static function maybe_install(): void {
		if ( get_option( self::OPTION ) !== self::VERSION ) {
			self::install();
		}
	}
}
