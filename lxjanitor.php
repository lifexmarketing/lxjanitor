<?php
/**
 * Plugin Name:       LX Janitor
 * Description:       Database cleanup and speed audit for LifeX-managed WordPress sites. Runs the database steps from the LifeX Speed Optimization Guide and recommends follow-up actions it cannot safely do on its own.
 * Version:           0.2.1
 * Requires at least: 6.4
 * Requires PHP:      7.4
 * Author:            LifeX Marketing
 * Plugin URI:        https://github.com/lifexmarketing/lxjanitor
 * Update URI:        https://github.com/lifexmarketing/lxjanitor
 * License:           GPL-2.0-or-later
 * Text Domain:       lxjanitor
 */

defined( 'ABSPATH' ) || exit;

define( 'LXJ_VERSION', '0.2.1' );
define( 'LXJ_FILE', __FILE__ );
define( 'LXJ_DIR', plugin_dir_path( __FILE__ ) );
define( 'LXJ_URL', plugin_dir_url( __FILE__ ) );

// Never autoloaded: this plugin must not add to the problem it measures.
define( 'LXJ_HISTORY_OPTION', 'lxjanitor_history' );
define( 'LXJ_BASELINE_OPTION', 'lxjanitor_baseline' );

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
	require_once LXJ_DIR . 'includes/class-lxj-report.php';
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
	$entry = array(
		'time' => time(),
		'user' => get_current_user_id(),
		'type' => $type,
		'data' => $data,
	);
	// The first snapshot ever becomes the baseline, stored apart from history so trimming never drops it.
	// (baseline_snapshot() first migrates an older snapshot from history, if there is one.)
	if ( 'snapshot' === $type && ! LXJ_Metrics::baseline_snapshot() ) {
		update_option( LXJ_BASELINE_OPTION, $entry, false );
	}

	$history   = get_option( LXJ_HISTORY_OPTION, array() );
	$history   = is_array( $history ) ? $history : array();
	$history[] = $entry;
	// Keep the last 50 entries.
	$history = array_slice( $history, -50 );
	update_option( LXJ_HISTORY_OPTION, $history, false );
}

/**
 * Replace the baseline with a fresh snapshot (for example after a site rebuild or migration).
 * The previous baseline's key figures are kept in History so the audit trail is not lost.
 *
 * @return array{previous: array|null, current: array} The old and new baseline entries.
 */
function lxj_reset_baseline() {
	$previous = LXJ_Metrics::baseline_snapshot();
	lxj_log( 'snapshot', LXJ_Metrics::snapshot( true ) );
	$history = (array) get_option( LXJ_HISTORY_OPTION, array() );
	$current = end( $history );
	update_option( LXJ_BASELINE_OPTION, $current, false );

	$prev_data = $previous ? (array) $previous['data'] : array();
	lxj_log(
		'action',
		array(
			'baseline_reset' => true,
			'previous'       => $previous ? array(
				'time'           => (int) $previous['time'],
				'db_size'        => $prev_data['db_size'] ?? null,
				'autoload_bytes' => $prev_data['autoload_bytes'] ?? null,
				'ttfb_ms'        => $prev_data['ttfb']['median_ms'] ?? null,
			) : null,
		)
	);
	return array( 'previous' => $previous, 'current' => $current );
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
