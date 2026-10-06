<?php
/**
 * Plugin Name: ShiftDB
 * Description: A safe WordPress database toolkit for search and replace, recovery snapshots, cleanup, optimization, exports, and database diagnostics.
 * Version:     1.0.1
 * Author:      Quberiq Technologies
 * Author URI:  https://quberiqtechnologies.com
 * License:     GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: shiftdb
 * Requires at least: 6.6
 * Requires PHP: 8.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'SHIFTDB_VERSION', '1.0.1' );
define( 'SHIFTDB_FILE', __FILE__ );
define( 'SHIFTDB_DIR', plugin_dir_path( __FILE__ ) );
define( 'SHIFTDB_URL', plugin_dir_url( __FILE__ ) );

require_once SHIFTDB_DIR . 'includes/class-shiftdb-logger.php';
require_once SHIFTDB_DIR . 'includes/class-shiftdb-backup.php';
require_once SHIFTDB_DIR . 'includes/class-shiftdb-database.php';
require_once SHIFTDB_DIR . 'includes/class-shiftdb-cleanup.php';
require_once SHIFTDB_DIR . 'includes/class-shiftdb-admin.php';

register_activation_hook(
	__FILE__,
	static function () {
		ShiftDB_Logger::install();
		ShiftDB_Backup::ensure_storage();
	}
);

add_action(
	'plugins_loaded',
	static function () {
		if ( is_admin() ) {
			new ShiftDB_Admin();
		}
	}
);
