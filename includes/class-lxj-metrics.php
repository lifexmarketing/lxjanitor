<?php
/**
 * Read-only measurements. Nothing in this class changes the database.
 */

defined( 'ABSPATH' ) || exit;

class LXJ_Metrics {

	/** Autoload target from the guide: under 500 KB. */
	const AUTOLOAD_LIMIT = 500000;

	/**
	 * Autoload values WordPress treats as "load on every request".
	 *
	 * @return string[]
	 */
	public static function autoload_values() {
		if ( function_exists( 'wp_autoload_values_to_autoload' ) ) {
			return wp_autoload_values_to_autoload(); // WP 6.6+.
		}
		return array( 'yes', 'on', 'auto-on', 'auto' );
	}

	/**
	 * Every table in this site's database with its size.
	 *
	 * @return array[] name, engine, rows, data, index, free, total
	 */
	public static function tables() {
		global $wpdb;
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT TABLE_NAME AS name, ENGINE AS engine, TABLE_ROWS AS row_count,
					DATA_LENGTH AS data, INDEX_LENGTH AS idx, DATA_FREE AS free
				FROM information_schema.TABLES
				WHERE TABLE_SCHEMA = %s AND TABLE_NAME LIKE %s
				ORDER BY (DATA_LENGTH + INDEX_LENGTH) DESC',
				DB_NAME,
				$wpdb->esc_like( $wpdb->prefix ) . '%'
			),
			ARRAY_A
		);
		$out = array();
		foreach ( (array) $rows as $r ) {
			$out[] = array(
				'name'   => $r['name'],
				'engine' => $r['engine'],
				'rows'   => (int) $r['row_count'],
				'data'   => (int) $r['data'],
				'index'  => (int) $r['idx'],
				'free'   => (int) $r['free'],
				'total'  => (int) $r['data'] + (int) $r['idx'],
			);
		}
		// Some hosts restrict information_schema (and SQLite has none): fall back to names only.
		if ( ! $out ) {
			$names = $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $wpdb->prefix ) . '%' ) );
			foreach ( (array) $names as $name ) {
				$out[] = array( 'name' => $name, 'engine' => '', 'rows' => 0, 'data' => 0, 'index' => 0, 'free' => 0, 'total' => 0 );
			}
		}
		return $out;
	}

	/**
	 * Total size of this site's tables (same figure Site Health reports for a single site).
	 *
	 * @return int bytes
	 */
	public static function db_size() {
		return array_sum( wp_list_pluck( self::tables(), 'total' ) );
	}

	/**
	 * Total bytes of autoloaded options.
	 *
	 * @return int
	 */
	public static function autoload_bytes() {
		global $wpdb;
		$in = self::in_list( self::autoload_values() );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $in is built from escaped literals.
		return (int) $wpdb->get_var( "SELECT SUM(LENGTH(option_value)) FROM {$wpdb->options} WHERE autoload IN ($in)" );
	}

	/**
	 * Largest autoloaded options.
	 *
	 * @param int $limit Rows.
	 * @return array[] option_name, size
	 */
	public static function autoload_top( $limit = 20 ) {
		global $wpdb;
		$in = self::in_list( self::autoload_values() );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT option_name, LENGTH(option_value) AS size FROM {$wpdb->options}
				WHERE autoload IN ($in) ORDER BY size DESC LIMIT %d",
				$limit
			),
			ARRAY_A
		);
	}

	/**
	 * Action Scheduler action counts by status, or null when the table does not exist.
	 *
	 * @return array|null
	 */
	public static function action_scheduler() {
		global $wpdb;
		$table = $wpdb->prefix . 'actionscheduler_actions';
		if ( ! self::table_exists( $table ) ) {
			return null;
		}
		$rows = $wpdb->get_results( "SELECT status, COUNT(*) AS n FROM {$table} GROUP BY status", ARRAY_A ); // phpcs:ignore
		$out  = array();
		foreach ( (array) $rows as $r ) {
			$out[ $r['status'] ] = (int) $r['n'];
		}
		$logs = $wpdb->prefix . 'actionscheduler_logs';
		if ( self::table_exists( $logs ) ) {
			$out['_logs'] = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$logs}" ); // phpcs:ignore
		}
		return $out;
	}

	/**
	 * Size of one table from information_schema (0 when the host hides sizes or the table is missing).
	 *
	 * @param string $table Full table name.
	 * @return int bytes
	 */
	public static function table_size( $table ) {
		foreach ( self::tables() as $t ) {
			if ( $t['name'] === $table ) {
				return $t['total'];
			}
		}
		return 0;
	}

	/**
	 * Hooks responsible for the most Action Scheduler actions, with a status breakdown.
	 * Groups on the indexed hook column, so it stays usable on large tables.
	 *
	 * @param int $limit Hooks to return.
	 * @return array[] hook, total, complete, failed, canceled, pending, recent_complete (completed within the cleanup retention)
	 */
	public static function action_scheduler_top_hooks( $limit = 3 ) {
		global $wpdb;
		$table = $wpdb->prefix . 'actionscheduler_actions';
		if ( ! self::table_exists( $table ) ) {
			return array();
		}
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - LXJ_Cleaner::AS_RETENTION_DAYS * DAY_IN_SECONDS );
		return (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT hook, COUNT(*) AS total,
					SUM(status = 'complete') AS complete, SUM(status = 'failed') AS failed,
					SUM(status = 'canceled') AS canceled, SUM(status = 'pending') AS pending,
					SUM(status = 'complete' AND scheduled_date_gmt >= %s) AS recent_complete
				FROM {$table} GROUP BY hook ORDER BY total DESC LIMIT %d", // phpcs:ignore
				$cutoff,
				$limit
			),
			ARRAY_A
		);
	}

	/**
	 * Hooks whose actions have the most Action Scheduler log entries.
	 *
	 * @param int $limit Hooks to return.
	 * @return array[] hook, logs, actions
	 */
	public static function action_scheduler_top_log_hooks( $limit = 3 ) {
		global $wpdb;
		$actions = $wpdb->prefix . 'actionscheduler_actions';
		$logs    = $wpdb->prefix . 'actionscheduler_logs';
		if ( ! self::table_exists( $actions ) || ! self::table_exists( $logs ) ) {
			return array();
		}
		return (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT COALESCE(a.hook, '(deleted actions)') AS hook, COUNT(*) AS logs, COUNT(DISTINCT l.action_id) AS actions
				FROM {$logs} l LEFT JOIN {$actions} a ON a.action_id = l.action_id
				GROUP BY a.hook ORDER BY logs DESC LIMIT %d", // phpcs:ignore
				$limit
			),
			ARRAY_A
		);
	}

	/**
	 * WooCommerce's database log table, or null when it does not exist.
	 *
	 * Uses log_id (the primary key) to find the oldest and newest entries, so this stays fast on large tables.
	 *
	 * @return array|null table, rows, size, oldest, newest (UTC 'Y-m-d H:i:s' or null), handler, retention_days
	 */
	public static function woocommerce_log() {
		global $wpdb;
		$table = $wpdb->prefix . 'woocommerce_log';
		if ( ! self::table_exists( $table ) ) {
			return null;
		}
		$size = self::table_size( $table );
		$rows = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ); // phpcs:ignore
		return array(
			'table'          => $table,
			'rows'           => $rows,
			'size'           => $size,
			'oldest'         => $rows ? $wpdb->get_var( "SELECT timestamp FROM {$table} ORDER BY log_id ASC LIMIT 1" ) : null, // phpcs:ignore
			'newest'         => $rows ? $wpdb->get_var( "SELECT timestamp FROM {$table} ORDER BY log_id DESC LIMIT 1" ) : null, // phpcs:ignore
			'handler'        => self::wc_log_handler(),
			'retention_days' => self::wc_log_retention_days(),
		);
	}

	/**
	 * Where WooCommerce currently writes its logs. Mirrors WooCommerce's own resolution:
	 * the WC_LOG_HANDLER constant wins, then the woocommerce_logs_default_handler option, then file logging.
	 *
	 * @return string database|file|other|inactive
	 */
	public static function wc_log_handler() {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return 'inactive';
		}
		$handler = defined( 'WC_LOG_HANDLER' ) ? WC_LOG_HANDLER : get_option( 'woocommerce_logs_default_handler', '' );
		$handler = is_string( $handler ) ? ltrim( $handler, '\\' ) : '';
		if ( 0 === strcasecmp( $handler, 'WC_Log_Handler_DB' ) ) {
			return 'database';
		}
		if ( '' === $handler || false !== stripos( $handler, 'File' ) ) {
			return 'file';
		}
		return 'other';
	}

	/**
	 * WooCommerce's log retention setting (WooCommerce 8.6+), default 30 days.
	 *
	 * @return int
	 */
	public static function wc_log_retention_days() {
		return max( 1, (int) get_option( 'woocommerce_logs_retention_period_days', 30 ) );
	}

	/**
	 * Counts of the things the cleaner removes.
	 *
	 * @return array
	 */
	public static function cleanup_counts() {
		global $wpdb;
		$now = time();
		return array(
			'revisions'          => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'revision'" ),
			'trashed_posts'      => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_status = 'trash'" ),
			'auto_drafts'        => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_status = 'auto-draft'" ),
			'expired_transients' => (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$wpdb->options}
					WHERE option_name LIKE %s AND option_value < %d",
					$wpdb->esc_like( '_transient_timeout_' ) . '%',
					$now
				)
			),
			'orphan_postmeta'    => (int) $wpdb->get_var(
				"SELECT COUNT(*) FROM {$wpdb->postmeta} pm
				LEFT JOIN {$wpdb->posts} p ON p.ID = pm.post_id WHERE p.ID IS NULL"
			),
			'spam_comments'      => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->comments} WHERE comment_approved = 'spam'" ),
			'trashed_comments'   => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->comments} WHERE comment_approved = 'trash'" ),
		);
	}

	/**
	 * Number of scheduled cron events.
	 *
	 * @return int
	 */
	public static function cron_count() {
		$count = 0;
		foreach ( (array) _get_cron_array() as $hooks ) {
			foreach ( (array) $hooks as $events ) {
				$count += count( (array) $events );
			}
		}
		return $count;
	}

	/**
	 * Median uncached time-to-first-byte of the home page, measured from the server itself.
	 * A cache-busting query string forces a fresh render, so this approximates generation time.
	 *
	 * @param int $samples Requests to make.
	 * @return array{median_ms: ?int, samples: int[], error: ?string}
	 */
	public static function ttfb( $samples = 3 ) {
		$times = array();
		$error = null;
		for ( $i = 0; $i < $samples; $i++ ) {
			$url   = add_query_arg( 'lxj_nocache', wp_generate_password( 8, false ), home_url( '/' ) );
			$start = microtime( true );
			$res   = wp_remote_get(
				$url,
				array(
					'timeout'     => 20,
					'redirection' => 3,
					'sslverify'   => false,
					'headers'     => array( 'Cache-Control' => 'no-cache' ),
				)
			);
			if ( is_wp_error( $res ) ) {
				$error = $res->get_error_message();
				break;
			}
			$times[] = (int) round( ( microtime( true ) - $start ) * 1000 );
		}
		sort( $times );
		return array(
			'median_ms' => $times ? $times[ (int) floor( count( $times ) / 2 ) ] : null,
			'samples'   => $times,
			'error'     => $error,
		);
	}

	/**
	 * Full snapshot for before/after comparison.
	 *
	 * @param bool $with_ttfb Whether to make loopback requests.
	 * @return array
	 */
	public static function snapshot( $with_ttfb = true ) {
		$snap = array(
			'db_size'          => self::db_size(),
			'autoload_bytes'   => self::autoload_bytes(),
			'action_scheduler' => self::action_scheduler(),
			'counts'           => self::cleanup_counts(),
			'cron_events'      => self::cron_count(),
		);
		if ( $with_ttfb ) {
			$snap['ttfb'] = self::ttfb();
		}
		return $snap;
	}

	/**
	 * Most recent snapshot from history, or null.
	 *
	 * @return array|null
	 */
	public static function last_snapshot() {
		$history = (array) get_option( LXJ_HISTORY_OPTION, array() );
		for ( $i = count( $history ) - 1; $i >= 0; $i-- ) {
			if ( isset( $history[ $i ]['type'] ) && 'snapshot' === $history[ $i ]['type'] ) {
				return $history[ $i ];
			}
		}
		return null;
	}

	/**
	 * The baseline: the first snapshot ever recorded on this site, or null.
	 *
	 * Stored in its own option so it survives history trimming (history keeps the last 50 entries).
	 * Sites that recorded snapshots before the baseline was stored separately are migrated here, once,
	 * from the oldest snapshot still in their history.
	 *
	 * @return array|null History entry (time, user, type, data).
	 */
	public static function baseline_snapshot() {
		$stored = get_option( LXJ_BASELINE_OPTION, null );
		if ( is_array( $stored ) && isset( $stored['data'] ) ) {
			return $stored;
		}
		foreach ( (array) get_option( LXJ_HISTORY_OPTION, array() ) as $entry ) {
			if ( isset( $entry['type'] ) && 'snapshot' === $entry['type'] ) {
				update_option( LXJ_BASELINE_OPTION, $entry, false );
				return $entry;
			}
		}
		return null;
	}

	/**
	 * Whether a history entry is the stored baseline.
	 *
	 * @param array $entry History entry.
	 * @return bool
	 */
	public static function is_baseline( array $entry ) {
		$base = self::baseline_snapshot();
		return $base && 'snapshot' === ( $entry['type'] ?? '' ) && (int) $base['time'] === (int) ( $entry['time'] ?? 0 );
	}

	/**
	 * @param string $table Full table name.
	 * @return bool
	 */
	public static function table_exists( $table ) {
		global $wpdb;
		return $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
	}

	/**
	 * @param string[] $values Literal values.
	 * @return string Quoted, comma-separated list safe for SQL IN ().
	 */
	private static function in_list( array $values ) {
		global $wpdb;
		return implode( ',', array_map( function ( $v ) {
			return "'" . esc_sql( $v ) . "'";
		}, $values ) );
	}
}
