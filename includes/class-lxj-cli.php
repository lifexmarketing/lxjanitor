<?php
/**
 * WP-CLI: wp lxjanitor <report|snapshot|clean|crons|recommend>
 */

defined( 'ABSPATH' ) || exit;

class LXJ_CLI {

	/**
	 * Show database metrics.
	 *
	 * ## EXAMPLES
	 *     wp lxjanitor report
	 */
	public function report() {
		$s = LXJ_Metrics::snapshot( false );
		WP_CLI::log( str_pad( 'Database size:', 22 ) . lxj_bytes( $s['db_size'] ) );
		WP_CLI::log( str_pad( 'Autoload:', 22 ) . lxj_bytes( $s['autoload_bytes'] ) . ' (' . $s['autoload_bytes'] . ' bytes, target < ' . LXJ_Metrics::AUTOLOAD_LIMIT . ')' );
		WP_CLI::log( str_pad( 'Cron events:', 22 ) . $s['cron_events'] );
		foreach ( $s['counts'] as $k => $v ) {
			WP_CLI::log( str_pad( $k . ':', 22 ) . $v );
		}
		if ( $s['action_scheduler'] ) {
			foreach ( $s['action_scheduler'] as $status => $n ) {
				WP_CLI::log( str_pad( 'AS ' . $status . ':', 22 ) . $n );
			}
		}
	}

	/**
	 * Record a before/after snapshot (includes loopback TTFB).
	 */
	public function snapshot() {
		$s = LXJ_Metrics::snapshot( true );
		lxj_log( 'snapshot', $s );
		WP_CLI::success( sprintf( 'Snapshot recorded. DB %s, autoload %s, TTFB %s ms.', lxj_bytes( $s['db_size'] ), lxj_bytes( $s['autoload_bytes'] ), $s['ttfb']['median_ms'] ?? 'n/a' ) );
	}

	/**
	 * Run cleanup tasks until done.
	 *
	 * ## OPTIONS
	 *
	 * [--tasks=<tasks>]
	 * : Comma-separated task keys. Default: all except woocommerce_log (name it to include it).
	 *
	 * [--yes]
	 * : Skip the backup confirmation.
	 *
	 * ## EXAMPLES
	 *     wp lxjanitor clean --tasks=action_scheduler,revisions
	 *
	 * @param array $args       Positional.
	 * @param array $assoc_args Flags.
	 */
	public function clean( $args, $assoc_args ) {
		WP_CLI::confirm( 'Do you have a current backup?', $assoc_args );
		$all   = array_keys( LXJ_Cleaner::tasks() );
		$tasks = isset( $assoc_args['tasks'] ) ? array_intersect( $all, explode( ',', $assoc_args['tasks'] ) ) : array_diff( $all, LXJ_Cleaner::off_by_default() );

		if ( ! LXJ_Metrics::baseline_snapshot() ) {
			lxj_log( 'snapshot', LXJ_Metrics::snapshot( false ) + array( 'auto' => true ) );
		}
		$results = array();
		foreach ( $tasks as $task ) {
			$total = 0;
			do {
				$r      = ( new LXJ_Cleaner( 60 ) )->run( $task );
				$total += $r['removed'];
			} while ( $r['more'] );
			$results[] = array( 'task' => $task, 'removed' => $total, 'note' => $r['note'] );
			WP_CLI::log( sprintf( '%-20s %s %s', $task, number_format( $total ), $r['note'] ) );
		}
		lxj_log( 'action', array( 'cleanup' => $results, 'via' => 'cli' ) );
		WP_CLI::success( 'Cleanup complete.' );
	}

	/**
	 * List flagged cron hooks, or remove them with --remove.
	 *
	 * ## OPTIONS
	 *
	 * [--remove]
	 * : Unschedule all flagged hooks.
	 *
	 * @param array $args       Positional.
	 * @param array $assoc_args Flags.
	 */
	public function crons( $args, $assoc_args ) {
		$flagged = LXJ_Crons::flagged_hooks();
		if ( ! $flagged ) {
			WP_CLI::success( 'No flagged cron hooks.' );
			return;
		}
		foreach ( $flagged as $h ) {
			WP_CLI::log( $h );
		}
		if ( ! empty( $assoc_args['remove'] ) ) {
			$r = LXJ_Crons::remove( $flagged );
			lxj_log( 'action', array( 'crons_removed' => $flagged, 'events' => $r['removed'], 'via' => 'cli' ) );
			WP_CLI::success( sprintf( 'Unscheduled %d events.', $r['removed'] ) );
		}
	}

	/**
	 * Print follow-up recommendations.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : table, json, csv. Default: table.
	 *
	 * @param array $args       Positional.
	 * @param array $assoc_args Flags.
	 */
	public function recommend( $args, $assoc_args ) {
		$recs = LXJ_Advisor::recommendations( true );
		WP_CLI\Utils\format_items( $assoc_args['format'] ?? 'table', $recs, array( 'severity', 'title', 'action' ) );
	}

	/**
	 * Show non-core tables by owner, or remove recognized groups whose plugin is no longer active.
	 *
	 * ## OPTIONS
	 *
	 * [--remove=<groups>]
	 * : Comma-separated group ids from the listing (e.g. wpforms, gravityforms-legacy). Blocked while the plugin is active.
	 *
	 * [--yes]
	 * : Skip the backup confirmation.
	 *
	 * ## EXAMPLES
	 *     wp lxjanitor tables
	 *     wp lxjanitor tables --remove=gravityforms-legacy,wordfence
	 *
	 * @param array $args       Positional.
	 * @param array $assoc_args Flags.
	 */
	public function tables( $args, $assoc_args ) {
		if ( empty( $assoc_args['remove'] ) ) {
			$rows = array();
			foreach ( LXJ_Tables::review() as $g ) {
				$rows[] = array(
					'id'        => $g['id'],
					'owner'     => $g['name'],
					'plugin'    => $g['status'],
					'tables'    => count( $g['tables'] ),
					'options'   => $g['options'],
					'size'      => lxj_bytes( $g['size'] ),
					'data'      => LXJ_Tables::describe_data( $g['data'] ),
					'removable' => $g['removable'] ? ( $g['blocked'] ? 'blocked: ' . $g['blocked'] : 'yes' ) : 'no',
				);
			}
			WP_CLI\Utils\format_items( 'table', $rows, array( 'id', 'owner', 'plugin', 'tables', 'options', 'size', 'data', 'removable' ) );
			return;
		}
		WP_CLI::confirm( 'Dropped tables cannot be recovered without a backup. Do you have a current database backup?', $assoc_args );
		foreach ( explode( ',', $assoc_args['remove'] ) as $id ) {
			$r = LXJ_Tables::remove_group( sanitize_key( $id ) );
			lxj_log( 'action', array( 'tables_removed' => $id, 'tables' => $r['tables'], 'options' => $r['options'], 'data' => $r['data'] ?? array(), 'ok' => $r['ok'], 'via' => 'cli' ) );
			if ( $r['ok'] ) {
				WP_CLI::success( $r['message'] );
			} else {
				WP_CLI::warning( $r['message'] );
			}
		}
	}
}
