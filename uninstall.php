<?php
/**
 * Deinstallation: Tabellen werden nur entfernt, wenn die Option
 * ttleague_remove_data_on_uninstall gesetzt ist. Standard ist Behalten,
 * damit ein versehentliches Löschen des Plugins keine Saisondaten vernichtet.
 *
 * @package TTLeague
 */

declare(strict_types=1);

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

if ( ! get_option( 'ttleague_remove_data_on_uninstall', false ) ) {
	return;
}

require_once __DIR__ . '/src/Infrastructure/Database/Schema.php';
require_once __DIR__ . '/src/Infrastructure/Database/Installer.php';

\TTLeague\Infrastructure\Database\Installer::drop_all();
delete_metadata( 'user', 0, 'ttleague_last_login', '', true );
delete_option( 'ttleague_db_version' );
delete_option( 'ttleague_remove_data_on_uninstall' );
