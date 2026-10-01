<?php
/**
 * Plugin Name: Squeeze Image Optimizer
 * Plugin URI:  https://inkandwater.co.uk
 * Description: Resizes and recompresses Media Library images (optionally converting format) to cut file size, with safe backups, a bulk pass over your existing library, and automatic optimisation of new uploads.
 * Version:     1.2.3
 * Author:      Ink & Water
 * Author URI:  https://inkandwater.co.uk
 * License:     GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: squeeze-image-optimizer
 * Requires PHP: 7.4
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access.
}

// Must match the Version header above: it's the ?ver= cache-buster on
// admin.js/admin.css, so a stale value keeps browsers on the old scripts.
define( 'SIO_VERSION', '1.2.3' );
define( 'SIO_PLUGIN_FILE', __FILE__ );
define( 'SIO_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'SIO_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'SIO_BACKUP_DIRNAME', 'squeeze-originals' );
define( 'SIO_OPTION_KEY', 'sio_settings' );
define( 'SIO_LOG_OPTION_KEY', 'sio_conversions_log' );
define( 'SIO_CRON_HOOK', 'sio_daily_backup_cleanup' );

require_once SIO_PLUGIN_DIR . 'includes/class-sio-settings.php';
require_once SIO_PLUGIN_DIR . 'includes/class-sio-backup.php';
require_once SIO_PLUGIN_DIR . 'includes/class-sio-conversions-log.php';
require_once SIO_PLUGIN_DIR . 'includes/class-sio-content-urls.php';
require_once SIO_PLUGIN_DIR . 'includes/class-sio-optimizer.php';
require_once SIO_PLUGIN_DIR . 'includes/class-sio-upload-hook.php';
require_once SIO_PLUGIN_DIR . 'includes/class-sio-media-library.php';

register_activation_hook(
	__FILE__,
	function () {
		SIO_Backup::ensure_backup_dir();
		if ( ! wp_next_scheduled( SIO_CRON_HOOK ) ) {
			wp_schedule_event( time(), 'daily', SIO_CRON_HOOK );
		}
	}
);

register_deactivation_hook(
	__FILE__,
	function () {
		wp_clear_scheduled_hook( SIO_CRON_HOOK );
	}
);

add_action(
	SIO_CRON_HOOK,
	function () {
		SIO_Backup::purge_expired( SIO_Settings::get( 'backup_days' ) );
	}
);

add_action(
	'plugins_loaded',
	function () {
		SIO_Settings::instance();
		SIO_Upload_Hook::instance();
		SIO_Media_Library::instance();

		// Covers sites where the plugin was already active before this
		// version introduced the cron sweep (activation hook won't re-fire).
		if ( ! wp_next_scheduled( SIO_CRON_HOOK ) ) {
			wp_schedule_event( time(), 'daily', SIO_CRON_HOOK );
		}
	}
);
