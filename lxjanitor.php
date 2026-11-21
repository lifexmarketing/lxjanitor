<?php
/**
 * Plugin Name:       LX Janitor
 * Description:       Database cleanup and speed audit for LifeX-managed WordPress sites. Runs the database steps from the LifeX Speed Optimization Guide and recommends follow-up actions it cannot safely do on its own.
 * Version:           0.2.0
 * Requires at least: 6.4
 * Requires PHP:      7.4
 * Author:            LifeX Marketing
 * Plugin URI:        https://github.com/lifexmarketing/lxjanitor
 * Update URI:        https://github.com/lifexmarketing/lxjanitor
 * License:           GPL-2.0-or-later
 * Text Domain:       lxjanitor
 */

defined( 'ABSPATH' ) || exit;

define( 'LXJ_VERSION', '0.2.0' );
define( 'LXJ_FILE', __FILE__ );
define( 'LXJ_DIR', plugin_dir_path( __FILE__ ) );
define( 'LXJ_URL', plugin_dir_url( __FILE__ ) );

// Never autoloaded: this plugin must not add to the problem it measures.
define( 'LXJ_HISTORY_OPTION', 'lxjanitor_history' );

require_once LXJ_DIR . 'includes/class-lxj-metrics.php';
require_once LXJ_DIR . 'includes/class-lxj-cleaner.php';
require_once LXJ_DIR . 'includes/class-lxj-crons.php';
require_once LXJ_DIR . 'includes/class-lxj-autoload.php';
require_once LXJ_DIR . 'includes/class-lxj-tables.php';
require_once LXJ_DIR . 'includes/class-lxj-advisor.php';
require_once LXJ_DIR . 'includes/class-lxj-updater.php';

// Loaded on every request, not just admin: WordPress's twice-daily update check runs from cron.
LXJ_Updater::init();

if ( is_admin() ) {
	require_once LXJ_DIR . 'includes/class-lxj-admin.php';
	add_action( 'plugins_loaded', array( 'LXJ_Admin', 'init' ) );
}

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	require_once LXJ_DIR . 'includes/class-lxj-cli.php';
	WP_CLI::add_command( 'lxjanitor', 'LXJ_CLI' );
}

/**
 * Append an entry to the run history (snapshots and actions).
 *
 * @param string $type  'snapshot' or 'action'.
 * @param array  $data  Entry payload.
 */
function lxj_log( $type, array $data ) {
	$history   = get_option( LXJ_HISTORY_OPTION, array() );
	$history   = is_array( $history ) ? $history : array();
	$history[] = array(
		'time' => time(),
		'user' => get_current_user_id(),
		'type' => $type,
		'data' => $data,
	);
	// Keep the last 50 entries.
	$history = array_slice( $history, -50 );
	update_option( LXJ_HISTORY_OPTION, $history, false );
}

/**
 * Human-readable bytes.
 *
 * @param int|float $bytes Bytes.
 * @return string
 */
function lxj_bytes( $bytes ) {
	return size_format( (float) $bytes, 1 ) ?: '0 B';
}
