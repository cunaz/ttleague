<?php
/**
 * Datenbankschema: alle Tabellen des Spielbetriebs.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Infrastructure\Database;

/**
 * Liefert CREATE-TABLE-Anweisungen im Format, das dbDelta() erwartet
 * (eine Spalte pro Zeile, zwei Leerzeichen nach PRIMARY KEY, KEY-Namen).
 * Fremdschlüssel werden als KEY abgebildet; dbDelta unterstützt keine Constraints,
 * die referenzielle Integrität sichert die Anwendungsschicht.
 */
final class Schema {

	public const VERSION = '2026111007';

	/**
	 * Indizes, die eine neuere Schemaversion ersetzt hat. dbDelta() entfernt keine Indizes;
	 * der Installer löscht sie, falls vorhanden, vor dem Abgleich.
	 *
	 * @var array<string,list<string>>
	 */
	public const OBSOLETE_INDEXES = array(
		// Seit 2026111005: Dedupe über Person oder Konto (TK-Konten ohne Person) und Zusatzschlüssel.
		'notification_log' => array( 'once' ),
	);

	public const TABLES = array(
		'season',
		'club',
		'club_season',
		'venue',
		'venue_closure',
		'person',
		'player',
		'player_season',
		'player_request',
		'player_rating',
		'rating_event',
		'classification_proposal',
		'club_role',
		'competition',
		'team',
		'team_roster',
		'fixture',
		'lineup',
		'game',
		'game_set',
		'cup_entry',
		'cup_tie',
		'rl_participant',
		'rl_round',
		'rl_group',
		'rl_group_player',
		'availability',
		'postponement',
		'event',
		'registration',
		'login_token',
		'push_subscription',
		'notification_pref',
		'notification_log',
		'fixture_event',
		'audit_log',
	);

	/**
	 * Vollständiger Tabellenname mit WordPress-Präfix.
	 */
	public static function table( string $name, string $prefix ): string {
		return $prefix . 'ttl_' . $name;
	}

	/**
	 * @param string $prefix          WordPress-Tabellenpräfix ($wpdb->prefix).
	 * @param string $charset_collate Zeichensatz-Klausel ($wpdb->get_charset_collate()).
	 * @return array<string,string> Tabellenname => CREATE TABLE.
	 */
	public static function statements( string $prefix, string $charset_collate ): array {
		$t = static fn( string $name ): string => self::table( $name, $prefix );

		// Transaktionen (Erfassung, Verknüpfungen mit Änderungsprotokoll) brauchen InnoDB;
		// ausdrücklich, damit ein anderer Server-Standard nichts ändert.
		$charset_collate = 'ENGINE=InnoDB ' . $charset_collate;

		$source_ref = "source_site varchar(64) DEFAULT NULL,\n  source_type varchar(32) DEFAULT NULL,\n  source_id varchar(128) DEFAULT NULL,";

		return array(
			$t( 'season' )                  => "CREATE TABLE {$t('season')} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  name varchar(20) NOT NULL,
  slug varchar(20) NOT NULL,
  start_date date NOT NULL,
  end_date date NOT NULL,
  status varchar(16) NOT NULL DEFAULT 'planned',
  rules_json longtext,
  registration_opens date DEFAULT NULL,
  registration_closes date DEFAULT NULL,
  late_registration_until date DEFAULT NULL,
  schedule_json longtext,
  classification_closed_at datetime DEFAULT NULL,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY slug (slug),
  KEY status (status)
) $charset_collate;",

			$t( 'club' )                    => "CREATE TABLE {$t('club')} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  number int(11) NOT NULL,
  name varchar(120) NOT NULL,
  short_name varchar(60) NOT NULL,
  slug varchar(60) NOT NULL,
  active tinyint(1) NOT NULL DEFAULT 1,
  $source_ref
  PRIMARY KEY  (id),
  UNIQUE KEY number (number),
  UNIQUE KEY slug (slug)
) $charset_collate;",

			// Teilnahme und Meldestand eines Clubs in einer Saison (open, submitted, reviewed).
			$t( 'club_season' )             => "CREATE TABLE {$t('club_season')} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  season_id bigint(20) unsigned NOT NULL,
  club_id bigint(20) unsigned NOT NULL,
  status varchar(16) NOT NULL DEFAULT 'open',
  submitted_at datetime DEFAULT NULL,
  submitted_by bigint(20) unsigned DEFAULT NULL,
  reviewed_at datetime DEFAULT NULL,
  reviewed_by bigint(20) unsigned DEFAULT NULL,
  note text,
  PRIMARY KEY  (id),
  UNIQUE KEY season_club (season_id,club_id)
) $charset_collate;",

			$t( 'venue' )                   => "CREATE TABLE {$t('venue')} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  club_id bigint(20) unsigned NOT NULL,
  name varchar(120) NOT NULL,
  street varchar(120) DEFAULT NULL,
  zip varchar(10) DEFAULT NULL,
  city varchar(80) DEFAULT NULL,
  phone varchar(40) DEFAULT NULL,
  weekday tinyint(1) DEFAULT NULL,
  open_time time DEFAULT NULL,
  close_time time DEFAULT NULL,
  tables_count tinyint(3) unsigned DEFAULT NULL,
  lat decimal(9,6) DEFAULT NULL,
  lng decimal(9,6) DEFAULT NULL,
  note text,
  active tinyint(1) NOT NULL DEFAULT 1,
  $source_ref
  PRIMARY KEY  (id),
  KEY club_id (club_id)
) $charset_collate;",

			$t( 'venue_closure' )           => "CREATE TABLE {$t('venue_closure')} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  venue_id bigint(20) unsigned NOT NULL,
  date_from date NOT NULL,
  date_to date NOT NULL,
  reason varchar(120) DEFAULT NULL,
  PRIMARY KEY  (id),
  KEY venue_id (venue_id)
) $charset_collate;",

			$t( 'person' )                  => "CREATE TABLE {$t('person')} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  user_id bigint(20) unsigned DEFAULT NULL,
  last_name varchar(80) NOT NULL,
  first_name varchar(80) NOT NULL DEFAULT '',
  email varchar(190) DEFAULT NULL,
  phone varchar(40) DEFAULT NULL,
  phone_visible tinyint(1) NOT NULL DEFAULT 0,
  consent_at datetime DEFAULT NULL,
  status varchar(16) NOT NULL DEFAULT 'active',
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  $source_ref
  PRIMARY KEY  (id),
  UNIQUE KEY email (email),
  UNIQUE KEY user_id (user_id),
  KEY last_name (last_name,first_name)
) $charset_collate;",

			$t( 'player' )                  => "CREATE TABLE {$t('player')} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  person_id bigint(20) unsigned NOT NULL,
  license_no int(11) NOT NULL,
  stt_license varchar(20) DEFAULT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY license_no (license_no),
  KEY person_id (person_id)
) $charset_collate;",

			$t( 'player_season' )           => "CREATE TABLE {$t('player_season')} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  player_id bigint(20) unsigned NOT NULL,
  season_id bigint(20) unsigned NOT NULL,
  club_id bigint(20) unsigned NOT NULL,
  classification tinyint(3) unsigned NOT NULL,
  status varchar(16) NOT NULL DEFAULT 'active',
  registered_at datetime DEFAULT NULL,
  registered_by bigint(20) unsigned DEFAULT NULL,
  approved_by bigint(20) unsigned DEFAULT NULL,
  approved_at datetime DEFAULT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY player_season (player_id,season_id),
  KEY season_club (season_id,club_id)
) $charset_collate;",

			// Meldung einer neuen Person ohne Lizenznummer; die TK gibt frei (requested, approved, rejected).
			$t( 'player_request' )          => "CREATE TABLE {$t('player_request')} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  season_id bigint(20) unsigned NOT NULL,
  club_id bigint(20) unsigned NOT NULL,
  last_name varchar(80) NOT NULL,
  first_name varchar(80) NOT NULL DEFAULT '',
  email varchar(190) DEFAULT NULL,
  classification tinyint(3) unsigned DEFAULT NULL,
  stt_license varchar(20) DEFAULT NULL,
  status varchar(16) NOT NULL DEFAULT 'requested',
  player_id bigint(20) unsigned DEFAULT NULL,
  requested_by bigint(20) unsigned NOT NULL,
  requested_at datetime NOT NULL,
  decided_by bigint(20) unsigned DEFAULT NULL,
  decided_at datetime DEFAULT NULL,
  note text,
  PRIMARY KEY  (id),
  KEY season_club (season_id,club_id),
  KEY status (status)
) $charset_collate;",

			$t( 'player_rating' )           => "CREATE TABLE {$t('player_rating')} (
  player_id bigint(20) unsigned NOT NULL,
  rating decimal(8,2) NOT NULL,
  games_count int(11) NOT NULL DEFAULT 0,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (player_id)
) $charset_collate;",

			$t( 'rating_event' )            => "CREATE TABLE {$t('rating_event')} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  player_id bigint(20) unsigned NOT NULL,
  game_id bigint(20) unsigned NOT NULL,
  rating_before decimal(8,2) NOT NULL,
  rating_after decimal(8,2) NOT NULL,
  delta decimal(8,2) NOT NULL,
  played_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY player_game (player_id,game_id),
  KEY player_time (player_id,played_at)
) $charset_collate;",

			$t( 'classification_proposal' ) => "CREATE TABLE {$t('classification_proposal')} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  season_id bigint(20) unsigned NOT NULL,
  player_id bigint(20) unsigned NOT NULL,
  current_value tinyint(3) unsigned NOT NULL,
  rating decimal(8,2) NOT NULL,
  games_count int(11) NOT NULL DEFAULT 0,
  proposed tinyint(3) unsigned NOT NULL,
  decided tinyint(3) unsigned DEFAULT NULL,
  decided_by bigint(20) unsigned DEFAULT NULL,
  decided_at datetime DEFAULT NULL,
  note text,
  PRIMARY KEY  (id),
  UNIQUE KEY season_player (season_id,player_id)
) $charset_collate;",

			$t( 'club_role' )               => "CREATE TABLE {$t('club_role')} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  season_id bigint(20) unsigned NOT NULL,
  club_id bigint(20) unsigned NOT NULL,
  person_id bigint(20) unsigned NOT NULL,
  role varchar(16) NOT NULL,
  team_id bigint(20) unsigned DEFAULT NULL,
  PRIMARY KEY  (id),
  KEY season_person (season_id,person_id),
  KEY season_club (season_id,club_id),
  KEY team_id (team_id)
) $charset_collate;",

			$t( 'competition' )             => "CREATE TABLE {$t('competition')} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  season_id bigint(20) unsigned NOT NULL,
  type varchar(8) NOT NULL,
  name varchar(80) NOT NULL,
  category varchar(8) DEFAULT NULL,
  group_no tinyint(3) unsigned DEFAULT NULL,
  sort_order smallint(5) unsigned NOT NULL DEFAULT 0,
  format_json longtext,
  $source_ref
  PRIMARY KEY  (id),
  KEY season_type (season_id,type)
) $charset_collate;",

			$t( 'team' )                    => "CREATE TABLE {$t('team')} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  season_id bigint(20) unsigned NOT NULL,
  club_id bigint(20) unsigned NOT NULL,
  number tinyint(3) unsigned NOT NULL,
  name varchar(80) NOT NULL,
  competition_id bigint(20) unsigned DEFAULT NULL,
  home_venue_id bigint(20) unsigned DEFAULT NULL,
  default_start_time time DEFAULT NULL,
  status varchar(16) NOT NULL DEFAULT 'active',
  $source_ref
  PRIMARY KEY  (id),
  UNIQUE KEY season_club_number (season_id,club_id,number),
  KEY competition_id (competition_id)
) $charset_collate;",

			$t( 'team_roster' )             => "CREATE TABLE {$t('team_roster')} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  team_id bigint(20) unsigned NOT NULL,
  player_id bigint(20) unsigned NOT NULL,
  kind varchar(16) NOT NULL DEFAULT 'stamm',
  valid_from date DEFAULT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY team_player (team_id,player_id),
  KEY player_id (player_id)
) $charset_collate;",

			$t( 'fixture' )                 => "CREATE TABLE {$t('fixture')} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  competition_id bigint(20) unsigned NOT NULL,
  round smallint(5) unsigned DEFAULT NULL,
  leg tinyint(1) unsigned DEFAULT NULL,
  match_no smallint(5) unsigned DEFAULT NULL,
  scheduled_at datetime DEFAULT NULL,
  home_team_id bigint(20) unsigned DEFAULT NULL,
  away_team_id bigint(20) unsigned DEFAULT NULL,
  home_entry_id bigint(20) unsigned DEFAULT NULL,
  away_entry_id bigint(20) unsigned DEFAULT NULL,
  venue_id bigint(20) unsigned DEFAULT NULL,
  status varchar(16) NOT NULL DEFAULT 'scheduled',
  home_score tinyint(3) unsigned DEFAULT NULL,
  away_score tinyint(3) unsigned DEFAULT NULL,
  entered_by bigint(20) unsigned DEFAULT NULL,
  entered_at datetime DEFAULT NULL,
  confirmed_by bigint(20) unsigned DEFAULT NULL,
  confirmed_at datetime DEFAULT NULL,
  confirmed_auto tinyint(1) NOT NULL DEFAULT 0,
  photo_attachment_id bigint(20) unsigned DEFAULT NULL,
  note text,
  $source_ref
  PRIMARY KEY  (id),
  KEY competition_round (competition_id,round),
  KEY home_team_id (home_team_id),
  KEY away_team_id (away_team_id),
  KEY scheduled_status (scheduled_at,status),
  KEY source (source_site,source_type,source_id)
) $charset_collate;",

			$t( 'lineup' )                  => "CREATE TABLE {$t('lineup')} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  fixture_id bigint(20) unsigned NOT NULL,
  side varchar(4) NOT NULL,
  slot char(1) NOT NULL,
  player_id bigint(20) unsigned NOT NULL,
  is_substitute tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY  (id),
  UNIQUE KEY fixture_side_slot (fixture_id,side,slot),
  KEY player_id (player_id)
) $charset_collate;",

			$t( 'game' )                    => "CREATE TABLE {$t('game')} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  fixture_id bigint(20) unsigned DEFAULT NULL,
  rl_group_id bigint(20) unsigned DEFAULT NULL,
  sequence tinyint(3) unsigned NOT NULL,
  type varchar(8) NOT NULL DEFAULT 'single',
  a1_player_id bigint(20) unsigned DEFAULT NULL,
  a2_player_id bigint(20) unsigned DEFAULT NULL,
  b1_player_id bigint(20) unsigned DEFAULT NULL,
  b2_player_id bigint(20) unsigned DEFAULT NULL,
  sets_a tinyint(3) unsigned NOT NULL DEFAULT 0,
  sets_b tinyint(3) unsigned NOT NULL DEFAULT 0,
  status varchar(16) NOT NULL DEFAULT 'played',
  counts_for_result tinyint(1) NOT NULL DEFAULT 1,
  played_at datetime DEFAULT NULL,
  PRIMARY KEY  (id),
  KEY fixture_id (fixture_id,sequence),
  KEY rl_group_id (rl_group_id),
  KEY a1_player_id (a1_player_id),
  KEY b1_player_id (b1_player_id),
  KEY a2_player_id (a2_player_id),
  KEY b2_player_id (b2_player_id)
) $charset_collate;",

			$t( 'game_set' )                => "CREATE TABLE {$t('game_set')} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  game_id bigint(20) unsigned NOT NULL,
  set_no tinyint(1) unsigned NOT NULL,
  points_a tinyint(3) unsigned NOT NULL,
  points_b tinyint(3) unsigned NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY game_set (game_id,set_no)
) $charset_collate;",

			$t( 'cup_entry' )               => "CREATE TABLE {$t('cup_entry')} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  competition_id bigint(20) unsigned NOT NULL,
  club_id bigint(20) unsigned NOT NULL,
  number tinyint(3) unsigned NOT NULL,
  name varchar(80) NOT NULL,
  player1_id bigint(20) unsigned DEFAULT NULL,
  player2_id bigint(20) unsigned DEFAULT NULL,
  points_total tinyint(3) unsigned DEFAULT NULL,
  seed tinyint(3) unsigned DEFAULT NULL,
  status varchar(16) NOT NULL DEFAULT 'active',
  registered_by bigint(20) unsigned DEFAULT NULL,
  registered_at datetime DEFAULT NULL,
  $source_ref
  PRIMARY KEY  (id),
  KEY competition_id (competition_id)
) $charset_collate;",

			$t( 'cup_tie' )                 => "CREATE TABLE {$t('cup_tie')} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  competition_id bigint(20) unsigned NOT NULL,
  round tinyint(3) unsigned NOT NULL,
  slot tinyint(3) unsigned NOT NULL,
  fixture_id bigint(20) unsigned DEFAULT NULL,
  home_entry_id bigint(20) unsigned DEFAULT NULL,
  away_entry_id bigint(20) unsigned DEFAULT NULL,
  deadline date DEFAULT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY competition_round_slot (competition_id,round,slot)
) $charset_collate;",

			$t( 'rl_participant' )          => "CREATE TABLE {$t('rl_participant')} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  competition_id bigint(20) unsigned NOT NULL,
  player_id bigint(20) unsigned NOT NULL,
  category tinyint(3) unsigned NOT NULL,
  status varchar(16) NOT NULL DEFAULT 'registered',
  registered_by bigint(20) unsigned DEFAULT NULL,
  registered_at datetime DEFAULT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY competition_player (competition_id,player_id)
) $charset_collate;",

			$t( 'rl_round' )                => "CREATE TABLE {$t('rl_round')} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  competition_id bigint(20) unsigned NOT NULL,
  round_no tinyint(3) unsigned NOT NULL,
  date_from date DEFAULT NULL,
  date_to date DEFAULT NULL,
  is_final tinyint(1) NOT NULL DEFAULT 0,
  status varchar(16) NOT NULL DEFAULT 'closed',
  closed_by bigint(20) unsigned DEFAULT NULL,
  closed_at datetime DEFAULT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY competition_round (competition_id,round_no)
) $charset_collate;",

			$t( 'rl_group' )                => "CREATE TABLE {$t('rl_group')} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  rl_round_id bigint(20) unsigned NOT NULL,
  category tinyint(3) unsigned NOT NULL,
  group_no tinyint(3) unsigned NOT NULL,
  host_club_id bigint(20) unsigned DEFAULT NULL,
  venue_id bigint(20) unsigned DEFAULT NULL,
  start_at datetime DEFAULT NULL,
  chief_player_id bigint(20) unsigned DEFAULT NULL,
  entered_by bigint(20) unsigned DEFAULT NULL,
  entered_at datetime DEFAULT NULL,
  note text,
  $source_ref
  PRIMARY KEY  (id),
  UNIQUE KEY round_category_group (rl_round_id,category,group_no)
) $charset_collate;",

			$t( 'rl_group_player' )         => "CREATE TABLE {$t('rl_group_player')} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  rl_group_id bigint(20) unsigned NOT NULL,
  player_id bigint(20) unsigned NOT NULL,
  status varchar(16) NOT NULL DEFAULT 'played',
  rank_no tinyint(3) unsigned DEFAULT NULL,
  points decimal(4,1) DEFAULT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY group_player (rl_group_id,player_id),
  KEY player_id (player_id)
) $charset_collate;",

			$t( 'availability' )            => "CREATE TABLE {$t('availability')} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  person_id bigint(20) unsigned NOT NULL,
  date_from date NOT NULL,
  date_to date NOT NULL,
  context varchar(32) DEFAULT NULL,
  note varchar(200) DEFAULT NULL,
  PRIMARY KEY  (id),
  KEY person_dates (person_id,date_from,date_to)
) $charset_collate;",

			$t( 'postponement' )            => "CREATE TABLE {$t('postponement')} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  fixture_id bigint(20) unsigned NOT NULL,
  requested_by bigint(20) unsigned NOT NULL,
  kind varchar(16) NOT NULL DEFAULT 'postpone',
  old_date datetime DEFAULT NULL,
  new_date datetime NOT NULL,
  status varchar(16) NOT NULL DEFAULT 'requested',
  reason text,
  accepted_by bigint(20) unsigned DEFAULT NULL,
  response_note text,
  responded_at datetime DEFAULT NULL,
  approved_by bigint(20) unsigned DEFAULT NULL,
  approved_at datetime DEFAULT NULL,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY fixture_id (fixture_id),
  KEY status (status)
) $charset_collate;",

			$t( 'event' )                   => "CREATE TABLE {$t('event')} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  season_id bigint(20) unsigned DEFAULT NULL,
  type varchar(16) NOT NULL,
  title varchar(160) NOT NULL,
  starts_at datetime NOT NULL,
  ends_at datetime DEFAULT NULL,
  location varchar(160) DEFAULT NULL,
  deadline datetime DEFAULT NULL,
  max_participants smallint(5) unsigned DEFAULT NULL,
  post_id bigint(20) unsigned DEFAULT NULL,
  status varchar(16) NOT NULL DEFAULT 'open',
  created_by bigint(20) unsigned DEFAULT NULL,
  PRIMARY KEY  (id),
  KEY starts_at (starts_at)
) $charset_collate;",

			$t( 'registration' )            => "CREATE TABLE {$t('registration')} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  event_id bigint(20) unsigned NOT NULL,
  person_id bigint(20) unsigned NOT NULL,
  status varchar(16) NOT NULL DEFAULT 'registered',
  note varchar(200) DEFAULT NULL,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY event_person (event_id,person_id)
) $charset_collate;",

			$t( 'login_token' )             => "CREATE TABLE {$t('login_token')} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  person_id bigint(20) unsigned NOT NULL,
  token_hash char(64) NOT NULL,
  expires_at datetime NOT NULL,
  used_at datetime DEFAULT NULL,
  requested_ip varchar(45) DEFAULT NULL,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY token_hash (token_hash),
  KEY person_id (person_id)
) $charset_collate;",

			$t( 'push_subscription' )       => "CREATE TABLE {$t('push_subscription')} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  person_id bigint(20) unsigned NOT NULL,
  endpoint_hash char(64) NOT NULL,
  endpoint text NOT NULL,
  keys_json text NOT NULL,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY endpoint_hash (endpoint_hash),
  KEY person_id (person_id)
) $charset_collate;",

			$t( 'notification_pref' )       => "CREATE TABLE {$t('notification_pref')} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  person_id bigint(20) unsigned NOT NULL,
  event_type varchar(32) NOT NULL,
  email tinyint(1) NOT NULL DEFAULT 1,
  push tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY  (id),
  UNIQUE KEY person_event (person_id,event_type)
) $charset_collate;",

			$t( 'notification_log' )        => "CREATE TABLE {$t('notification_log')} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  person_id bigint(20) unsigned NOT NULL DEFAULT 0,
  user_id bigint(20) unsigned NOT NULL DEFAULT 0,
  event_type varchar(48) NOT NULL,
  ref_type varchar(16) NOT NULL,
  ref_id bigint(20) unsigned NOT NULL,
  ref_key varchar(32) NOT NULL DEFAULT '',
  channel varchar(8) NOT NULL,
  status varchar(8) NOT NULL DEFAULT 'sent',
  sent_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY recipient_once (person_id,user_id,event_type,ref_type,ref_id,ref_key,channel),
  KEY ref (ref_type,ref_id),
  KEY sent_at (sent_at)
) $charset_collate;",

			$t( 'fixture_event' )           => "CREATE TABLE {$t('fixture_event')} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  fixture_id bigint(20) unsigned NOT NULL,
  type varchar(16) NOT NULL,
  user_id bigint(20) unsigned DEFAULT NULL,
  person_id bigint(20) unsigned DEFAULT NULL,
  comment text,
  data_json longtext,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY fixture_id (fixture_id,created_at),
  KEY type_created (type,created_at)
) $charset_collate;",

			$t( 'audit_log' )               => "CREATE TABLE {$t('audit_log')} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  entity varchar(32) NOT NULL,
  entity_id bigint(20) unsigned NOT NULL,
  action varchar(32) NOT NULL,
  user_id bigint(20) unsigned DEFAULT NULL,
  created_at datetime NOT NULL,
  before_json longtext,
  after_json longtext,
  note varchar(255) DEFAULT NULL,
  PRIMARY KEY  (id),
  KEY entity (entity,entity_id),
  KEY created_at (created_at)
) $charset_collate;",
		);
	}
}
