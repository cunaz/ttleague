<?php
/**
 * WordPress-Anbindung der fachlichen Rechte über map_meta_cap.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Access;

use TTLeague\Infrastructure\Database\WpdbDb;

final class Capabilities {

	public const TK            = 'ttl_tk';
	public const ENTER_RESULT  = 'ttl_enter_result';
	public const CONFIRM       = 'ttl_confirm_result';
	public const MANAGE_CLUB   = 'ttl_manage_club';
	public const INVITE_PERSON = 'ttl_invite_person';

	private const META = array( self::ENTER_RESULT, self::CONFIRM, self::MANAGE_CLUB, self::INVITE_PERSON );

	private static ?AccessPolicy $policy = null;

	public static function register(): void {
		add_filter( 'map_meta_cap', array( self::class, 'map' ), 10, 4 );
		add_action( 'init', array( Roles::class, 'maybe_install' ), 5 );
	}

	public static function policy(): AccessPolicy {
		if ( null === self::$policy ) {
			self::$policy = new AccessPolicy( WpdbDb::from_global() );
		}
		return self::$policy;
	}

	/**
	 * Für Tests oder nach Datenänderungen.
	 */
	public static function use_policy( ?AccessPolicy $policy ): void {
		self::$policy = $policy;
	}

	/**
	 * @param list<string>     $caps    Benötigte primitive Capabilities.
	 * @param string           $cap     Angefragte Capability.
	 * @param int              $user_id Benutzer.
	 * @param array<int,mixed> $args    Argumente (ID).
	 * @return list<string>
	 */
	public static function map( array $caps, string $cap, int $user_id, array $args ): array {
		if ( ! in_array( $cap, self::META, true ) ) {
			return $caps;
		}
		if ( $user_id <= 0 ) {
			return array( 'do_not_allow' );
		}
		if ( user_can( $user_id, self::TK ) ) {
			return array( self::TK );
		}
		$id     = (int) ( $args[0] ?? 0 );
		$policy = self::policy();
		$person = $policy->person_for_user( $user_id );
		if ( null === $person || $id <= 0 ) {
			return array( 'do_not_allow' );
		}
		$allowed = match ( $cap ) {
			self::ENTER_RESULT  => $policy->can_enter_result( $person, $id ),
			self::CONFIRM       => $policy->can_confirm_result( $person, $id ),
			self::MANAGE_CLUB   => $policy->can_manage_club( $person, $id ),
			self::INVITE_PERSON => $policy->can_invite( $person, $id ),
			default             => false,
		};
		return $allowed ? array( 'read' ) : array( 'do_not_allow' );
	}
}
