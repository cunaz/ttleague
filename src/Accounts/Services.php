<?php
/**
 * Gemeinsame Dienste der Konten (einfacher Service-Locator für die WordPress-Schicht).
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Accounts;

use TTLeague\Access\AccessPolicy;
use TTLeague\Access\Capabilities;
use TTLeague\Infrastructure\AuditLog;
use TTLeague\Infrastructure\Database\Db;
use TTLeague\Infrastructure\Database\WpdbDb;
use TTLeague\Infrastructure\Mail\Mailer;
use TTLeague\Infrastructure\RateLimiter;
use TTLeague\Infrastructure\Time\Clock;
use TTLeague\Infrastructure\Time\SystemClock;

final class Services {

	private static ?Db $db       = null;
	private static ?Clock $clock = null;

	public static function db(): Db {
		return self::$db ??= WpdbDb::from_global();
	}

	public static function clock(): Clock {
		return self::$clock ??= new SystemClock();
	}

	public static function accounts(): AccountService {
		return new AccountService( self::db() );
	}

	public static function links(): MagicLinks {
		return new MagicLinks( self::db(), self::clock() );
	}

	public static function policy(): AccessPolicy {
		return Capabilities::policy();
	}

	public static function audit(): AuditLog {
		return new AuditLog( self::db() );
	}

	public static function mailer(): Mailer {
		return new Mailer();
	}

	public static function limiter(): RateLimiter {
		return RateLimiter::wordpress( self::clock() );
	}

	public static function invitations(): Invitations {
		return new Invitations( self::accounts(), self::links(), self::mailer(), self::audit() );
	}

	public static function preferences(): Preferences {
		return new Preferences( self::db() );
	}
}
