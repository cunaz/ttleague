<?php
/**
 * Einstieg in die Lesemodelle der öffentlichen Darstellung.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\ReadModel;

use TTLeague\Infrastructure\Database\Db;
use TTLeague\Infrastructure\Database\WpdbDb;
use TTLeague\ReadModel\Cache\Cache;
use TTLeague\ReadModel\Cache\WpCache;

/**
 * Bündelt alle Abfragen mit gemeinsamer Datenbank und gemeinsamem Cache. Die Produktion
 * nutzt instance() (wpdb, WpCache), Tests erzeugen eine Instanz mit PdoDb und NullCache.
 */
final class ReadModel {

	private static ?self $instance = null;

	public readonly Seasons $seasons;
	public readonly Championship $championship;
	public readonly Players $players;
	public readonly MatchReports $reports;
	public readonly Teams $teams;
	public readonly Clubs $clubs;
	public readonly PlayerProfile $profiles;
	public readonly RankingTournament $ranking;
	public readonly Cup $cup;
	public readonly Fixtures $fixtures;

	public function __construct( Db $db, Cache $cache ) {
		$this->seasons      = new Seasons( $db, $cache );
		$this->championship = new Championship( $db, $cache );
		$this->players      = new Players( $db, $cache );
		$this->reports      = new MatchReports( $db, $cache, $this->players );
		$this->teams        = new Teams( $db, $cache, $this->championship, $this->players );
		$this->clubs        = new Clubs( $db, $cache, $this->championship, $this->players );
		$this->profiles     = new PlayerProfile( $db, $cache, $this->players );
		$this->ranking      = new RankingTournament( $db, $cache, $this->seasons );
		$this->cup          = new Cup( $db, $cache, $this->players );
		$this->fixtures     = new Fixtures( $db, $cache );
	}

	/**
	 * Gemeinsame Instanz für WordPress-Anfragen.
	 */
	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self( WpdbDb::from_global(), new WpCache() );
		}
		return self::$instance;
	}

	/**
	 * Ersetzt die gemeinsame Instanz (Tests) oder setzt sie zurück (null).
	 */
	public static function set_instance( ?self $instance ): void {
		self::$instance = $instance;
	}
}
