<?php
/**
 * Plugin Name:       Hostelworld Content Health
 * Description:       Finds blog articles worth refreshing and articles with no search traffic, using Google Search Console data (and Ahrefs backlinks, if connected), with reviewed, reversible actions.
 * Version:           1.13.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Hostelworld
 * Text Domain:       hw-content-health
 *
 * @package HW_Content_Health
 */

defined( 'ABSPATH' ) || exit;

define( 'HWCH_VERSION', '1.13.0' );
define( 'HWCH_FILE', __FILE__ );
define( 'HWCH_DIR', plugin_dir_path( __FILE__ ) );
define( 'HWCH_URL', plugin_dir_url( __FILE__ ) );

require_once HWCH_DIR . 'includes/class-hwch-settings.php';
require_once HWCH_DIR . 'includes/class-hwch-lang.php';
require_once HWCH_DIR . 'includes/class-hwch-gsc.php';
require_once HWCH_DIR . 'includes/class-hwch-oauth.php';
require_once HWCH_DIR . 'includes/class-hwch-store.php';
require_once HWCH_DIR . 'includes/class-hwch-redirects.php';
require_once HWCH_DIR . 'includes/class-hwch-reports.php';
require_once HWCH_DIR . 'includes/class-hwch-overlap.php';
require_once HWCH_DIR . 'includes/class-hwch-chart.php';
require_once HWCH_DIR . 'includes/class-hwch-dashboard.php';
require_once HWCH_DIR . 'includes/class-hwch-watchlists.php';
require_once HWCH_DIR . 'includes/class-hwch-quickwins.php';
require_once HWCH_DIR . 'includes/class-hwch-impact.php';
require_once HWCH_DIR . 'includes/class-hwch-tags.php';
require_once HWCH_DIR . 'includes/class-hwch-dated.php';
require_once HWCH_DIR . 'includes/class-hwch-links.php';
require_once HWCH_DIR . 'includes/class-hwch-onpage.php';
require_once HWCH_DIR . 'includes/class-hwch-seasonal.php';
require_once HWCH_DIR . 'includes/class-hwch-questions.php';
require_once HWCH_DIR . 'includes/class-hwch-broken.php';
require_once HWCH_DIR . 'includes/class-hwch-annotations.php';
require_once HWCH_DIR . 'includes/class-hwch-editor.php';
require_once HWCH_DIR . 'includes/class-hwch-planner.php';
require_once HWCH_DIR . 'includes/class-hwch-aio.php';
require_once HWCH_DIR . 'includes/class-hwch-brief.php';
require_once HWCH_DIR . 'includes/class-hwch-export.php';
require_once HWCH_DIR . 'includes/class-hwch-ahrefs.php';
require_once HWCH_DIR . 'includes/class-hwch-todo.php';
require_once HWCH_DIR . 'includes/class-hwch-actions.php';
require_once HWCH_DIR . 'includes/class-hwch-admin.php';

register_activation_hook( __FILE__, array( 'HWCH_Store', 'install' ) );
register_activation_hook( __FILE__, array( 'HWCH_Store', 'schedule' ) );
register_deactivation_hook( __FILE__, array( 'HWCH_Store', 'unschedule' ) );

add_action( 'plugins_loaded', array( 'HWCH_Store', 'maybe_upgrade' ) );
add_action( 'hwch_weekly_sync', array( 'HWCH_Store', 'sync' ) );
add_action( 'hwch_hourly', array( 'HWCH_Seasonal', 'hourly' ) );
add_action( 'hwch_sync_step', array( 'HWCH_Store', 'run_step' ) );
// Steps left over (if the background request was missed) run hourly.
add_action( 'hwch_hourly', array( 'HWCH_Store', 'run_step' ), 5 );
// The Content health screen runs the steps while it is open.
add_action(
	'wp_ajax_hwch_step',
	function () {
		check_ajax_referer( 'hwch_step' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( null, 403 );
		}
		wp_send_json_success( HWCH_Store::run_step() );
	}
);

HWCH_OAuth::init();
HWCH_Redirects::init();
HWCH_Actions::init();
HWCH_Watchlists::init();
HWCH_Broken::init();
HWCH_Editor::init();
HWCH_Export::init();
HWCH_Ahrefs::init();
HWCH_Admin::init();
