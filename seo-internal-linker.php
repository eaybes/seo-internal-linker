<?php
/**
 * Plugin Name: SEO Internal Linker
 * Description: Automatically links predefined phrases to target pages the first time they appear in a post (or in selected pages), and generates SEO/AEO-optimised TL;DR summaries via the Claude API.
 * Version: 1.1.0
 * Author: Elad Aybes
 * Text Domain: sil
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'SIL_PLUGIN_FILE', __FILE__ );
define( 'SIL_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'SIL_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'SIL_VERSION', '1.1.0' );

require_once SIL_PLUGIN_DIR . 'includes/class-sil-db.php';
require_once SIL_PLUGIN_DIR . 'includes/class-sil-settings.php';
require_once SIL_PLUGIN_DIR . 'includes/class-sil-linker.php';
require_once SIL_PLUGIN_DIR . 'includes/class-sil-tldr.php';
require_once SIL_PLUGIN_DIR . 'includes/class-sil-rescan.php';
require_once SIL_PLUGIN_DIR . 'includes/class-sil-admin.php';
require_once SIL_PLUGIN_DIR . 'includes/class-sil-post-meta.php';

register_activation_hook( __FILE__, array( 'SIL_DB', 'create_table' ) );

register_deactivation_hook(
	__FILE__,
	function () {
		wp_clear_scheduled_hook( SIL_Rescan::CRON_HOOK );
		delete_option( SIL_Rescan::QUEUE_OPTION );
	}
);

add_action( 'plugins_loaded', function () {
	// Keeps the DB schema current on upgrades without requiring a manual
	// deactivate/reactivate cycle.
	SIL_DB::maybe_upgrade();

	SIL_Linker::instance();
	SIL_TLDR::instance();
	SIL_Rescan::instance();
	SIL_Admin::instance();
	SIL_Post_Meta::instance();
} );
