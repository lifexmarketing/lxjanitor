<?php
/**
 * Database cleanup tasks. Every task:
 *  - works in small batches inside a time budget so it is safe on shared hosting,
 *  - returns how many rows it removed and whether more remain (run it again if so),
 *  - never touches pending/in-progress Action Scheduler actions or published content.
 */

defined( 'ABSPATH' ) || exit;

class LXJ_Cleaner {

	/** Guide default: keep the last 2 weeks of revisions/trash. */
	const KEEP_DAYS = 14;

	/** Guide default: always keep 2 revisions per post. */
	const KEEP_REVISIONS = 2;

	/** Matches Action Scheduler's own default retention period. */
	const AS_RETENTION_DAYS = 31;

	/** A claim lives only while its batch runs (minutes), so anything a day old is from a batch that died. */
	const AS_CLAIM_DAYS = 1;

	const BATCH = 500;

	/** Log rows are small and deleted by primary key range, so they can go in larger batches. */
	const LOG_BATCH = 10000;

	/** @var float */
	private $deadline;

	/**
	 * @param int $budget_seconds How long one run may take.
	 */
	public function __construct( $budget_seconds = 20 ) {
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( $budget_seconds + 30 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}
		$this->deadline = microtime( true ) + $budget_seconds;
	}

	/**
	 * Task registry: key => label. Order matters; it is the order "Run all" uses.
	 *
	 * @return array
	 */
	public static function tasks() {
		return array(
			'action_scheduler'   => 'Action Scheduler: delete completed, canceled and failed actions older than ' . self::AS_RETENTION_DAYS . ' days, plus orphaned logs and stale claims left by batches that crashed (older than ' . self::AS_CLAIM_DAYS . ' day)',
			'woocommerce_log'    => 'WooCommerce database log: delete entries older than WooCommerce\'s log retention (' . LXJ_Metrics::wc_log_retention_days() . ' days); empties the table when every entry is older',
			'revisions'          => 'Post revisions: keep the newest ' . self::KEEP_REVISIONS . ' per post and anything from the last ' . self::KEEP_DAYS . ' days',
			'trash'              => 'Trashed posts and trashed/spam comments older than ' . self::KEEP_DAYS . ' days',
			'auto_drafts'        => 'Auto-drafts older than 7 days',
			'expired_transients' => 'Expired transients',
			'orphan_postmeta'    => 'Post meta whose post no longer exists',
			'optimize_tables'    => 'Optimize tables with reclaimable space (same as wp db optimize, limited to tables that benefit)',
		);
	}

	/**
	 * Tasks left unticked on the Database tab and skipped by `wp lxjanitor clean` unless named with --tasks.
	 * Most sites never logged WooCommerce to the database, so that task is opt-in.
	 *
	 * @return string[] Task keys.
	 */
	public static function off_by_default() {
		return array( 'woocommerce_log' );
	}

	/**
	 * Run one task by key.
	 *
	 * @param string $task Key from tasks().
	 * @return array{task:string, removed:int, more:bool, note:string}
	 */
	public function run( $task ) {
		$method = 'task_' . $task;
		if ( ! array_key_exists( $task, self::tasks() ) || ! method_exists( $this, $method ) ) {
			return array( 'task' => $task, 'removed' => 0, 'more' => false, 'note' => 'Unknown task.' );
		}
		$result         = $this->$method();
		$result['task'] = $task;
		$result        += array( 'note' => '' );
		return $result;
	}

	private function out_of_time() {
		return microtime( true ) >= $this->deadline;
	}

	private function cutoff_mysql( $days ) {
		return gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS );
	}

	/* ---------------------------------------------------------------------
	 * Tasks
	 * ------------------------------------------------------------------- */

	private function task_action_scheduler() {
		global $wpdb;
		$actions = $wpdb->prefix . 'actionscheduler_actions';
		$logs    = $wpdb->prefix . 'actionscheduler_logs';
		if ( ! LXJ_Metrics::table_exists( $actions ) ) {
			return array( 'removed' => 0, 'more' => false, 'note' => 'Action Scheduler is not installed.' );
		}
		$has_logs = LXJ_Metrics::table_exists( $logs );
		$cutoff   = $this->cutoff_mysql( self::AS_RETENTION_DAYS );
		$removed  = 0;

		while ( ! $this->out_of_time() ) {
			$ids = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT action_id FROM {$actions}
					WHERE status IN ('complete','canceled','failed')
					AND scheduled_date_gmt < %s
					LIMIT %d",
					$cutoff,
					self::BATCH
				)
			);
			if ( ! $ids ) {
				break;
			}
			$in = implode( ',', array_map( 'intval', $ids ) );
			if ( $has_logs ) {
				$wpdb->query( "DELETE FROM {$logs} WHERE action_id IN ($in)" ); // phpcs:ignore
			}
			$removed += (int) $wpdb->query( "DELETE FROM {$actions} WHERE action_id IN ($in)" ); // phpcs:ignore
		}

		// Logs that point at actions that no longer exist.
		while ( $has_logs && ! $this->out_of_time() ) {
			$ids = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT l.log_id FROM {$logs} l
					LEFT JOIN {$actions} a ON a.action_id = l.action_id
					WHERE a.action_id IS NULL LIMIT %d",
					self::BATCH
				)
			);
			if ( ! $ids ) {
				break;
			}
			$in = implode( ',', array_map( 'intval', $ids ) );
			$wpdb->query( "DELETE FROM {$logs} WHERE log_id IN ($in)" ); // phpcs:ignore
		}

		// Claims left by batches that died mid-run. A finished batch deletes its own claim, and Action Scheduler
		// frees a dead batch's actions after about 5 minutes but never deletes the claim row. Claims still held
		// by a pending or in-progress action are kept, however old.
		$claims  = $wpdb->prefix . 'actionscheduler_claims';
		$cleared = 0;
		if ( LXJ_Metrics::table_exists( $claims ) ) {
			$claim_cutoff = $this->cutoff_mysql( self::AS_CLAIM_DAYS );
			while ( ! $this->out_of_time() ) {
				$ids = $wpdb->get_col(
					$wpdb->prepare(
						"SELECT c.claim_id FROM {$claims} c
						WHERE c.date_created_gmt < %s
						AND NOT EXISTS (
							SELECT 1 FROM {$actions} a WHERE a.claim_id = c.claim_id AND a.status IN ('pending','in-progress')
						)
						LIMIT %d",
						$claim_cutoff,
						self::LOG_BATCH
					)
				);
				if ( ! $ids ) {
					break;
				}
				$in       = implode( ',', array_map( 'intval', $ids ) );
				$cleared += (int) $wpdb->query( "DELETE FROM {$claims} WHERE claim_id IN ($in)" ); // phpcs:ignore
			}
		}

		return array(
			'removed' => $removed,
			'more'    => $this->out_of_time(),
			'note'    => $cleared ? sprintf( 'Also removed %s stale claims.', number_format_i18n( $cleared ) ) : '',
		);
	}

	/**
	 * WooCommerce only prunes this table while it is the active log handler. After a switch to
	 * file logging, the old entries stay forever, so this removes anything past the retention period.
	 */
	private function task_woocommerce_log() {
		global $wpdb;
		$table = $wpdb->prefix . 'woocommerce_log';
		if ( ! LXJ_Metrics::table_exists( $table ) ) {
			return array( 'removed' => 0, 'more' => false, 'note' => 'No WooCommerce database log table.' );
		}
		$days   = LXJ_Metrics::wc_log_retention_days();
		$cutoff = $this->cutoff_mysql( $days );
		$total  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ); // phpcs:ignore
		if ( ! $total ) {
			return array( 'removed' => 0, 'more' => false, 'note' => 'Already empty.' );
		}

		// Every entry is past retention: TRUNCATE is instant and releases the disk space right away.
		$newest = (string) $wpdb->get_var( "SELECT timestamp FROM {$table} ORDER BY log_id DESC LIMIT 1" ); // phpcs:ignore
		if ( $newest < $cutoff && false !== $wpdb->query( "TRUNCATE TABLE `{$table}`" ) ) { // phpcs:ignore
			return array(
				'removed' => $total,
				'more'    => false,
				'note'    => sprintf( 'every entry was older than %d days, so the table was emptied', $days ),
			);
		}

		// Otherwise (or if the host does not allow TRUNCATE) delete old rows by primary key range, in batches.
		$boundary = (int) $wpdb->get_var( $wpdb->prepare( "SELECT MAX(log_id) FROM {$table} WHERE timestamp < %s", $cutoff ) ); // phpcs:ignore
		$removed  = 0;
		while ( $boundary && ! $this->out_of_time() ) {
			$n        = (int) $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE log_id <= %d AND timestamp < %s LIMIT %d", $boundary, $cutoff, self::LOG_BATCH ) ); // phpcs:ignore
			$removed += $n;
			if ( $n < self::LOG_BATCH ) {
				$boundary = 0;
			}
		}
		return array(
			'removed' => $removed,
			'more'    => (bool) $boundary,
			'note'    => $removed ? sprintf( 'kept the last %d days; run "Optimize tables" to release the freed space', $days ) : '',
		);
	}

	private function task_revisions() {
		global $wpdb;
		$cutoff  = $this->cutoff_mysql( self::KEEP_DAYS );
		$removed = 0;

		// Parents that have more revisions than we keep, and at least one old enough to remove.
		$parents = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT post_parent FROM {$wpdb->posts}
				WHERE post_type = 'revision'
				GROUP BY post_parent
				HAVING COUNT(*) > %d AND MIN(post_date_gmt) < %s",
				self::KEEP_REVISIONS,
				$cutoff
			)
		);

		foreach ( $parents as $parent_id ) {
			$revisions = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT ID, post_date_gmt FROM {$wpdb->posts}
					WHERE post_type = 'revision' AND post_parent = %d
					ORDER BY post_date_gmt DESC, ID DESC",
					$parent_id
				)
			);
			foreach ( array_slice( $revisions, self::KEEP_REVISIONS ) as $rev ) {
				if ( $this->out_of_time() ) {
					return array( 'removed' => $removed, 'more' => true );
				}
				if ( $rev->post_date_gmt < $cutoff && wp_delete_post_revision( (int) $rev->ID ) ) {
					$removed++;
				}
			}
		}
		return array( 'removed' => $removed, 'more' => false );
	}

	private function task_trash() {
		global $wpdb;
		$cutoff_ts = time() - self::KEEP_DAYS * DAY_IN_SECONDS;
		$removed   = 0;

		$post_ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT p.ID FROM {$wpdb->posts} p
				LEFT JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = '_wp_trash_meta_time'
				WHERE p.post_status = 'trash' AND (m.meta_value IS NULL OR m.meta_value < %d)
				LIMIT 2000",
				$cutoff_ts
			)
		);
		foreach ( $post_ids as $id ) {
			if ( $this->out_of_time() ) {
				return array( 'removed' => $removed, 'more' => true );
			}
			if ( wp_delete_post( (int) $id, true ) ) {
				$removed++;
			}
		}

		$comment_ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT comment_ID FROM {$wpdb->comments}
				WHERE comment_approved IN ('spam','trash') AND comment_date_gmt < %s
				LIMIT 5000",
				$this->cutoff_mysql( self::KEEP_DAYS )
			)
		);
		foreach ( $comment_ids as $id ) {
			if ( $this->out_of_time() ) {
				return array( 'removed' => $removed, 'more' => true );
			}
			if ( wp_delete_comment( (int) $id, true ) ) {
				$removed++;
			}
		}

		$more = count( $post_ids ) >= 2000 || count( $comment_ids ) >= 5000;
		return array( 'removed' => $removed, 'more' => $more );
	}

	private function task_auto_drafts() {
		global $wpdb;
		$before = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_status = 'auto-draft'" );
		wp_delete_auto_drafts(); // Core: removes auto-drafts older than 7 days.
		$after = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_status = 'auto-draft'" );
		return array( 'removed' => max( 0, $before - $after ), 'more' => false );
	}

	private function task_expired_transients() {
		$before = LXJ_Metrics::cleanup_counts()['expired_transients'];
		delete_expired_transients( true );
		return array( 'removed' => $before, 'more' => false );
	}

	private function task_orphan_postmeta() {
		global $wpdb;
		$removed = 0;
		while ( ! $this->out_of_time() ) {
			$ids = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT pm.meta_id FROM {$wpdb->postmeta} pm
					LEFT JOIN {$wpdb->posts} p ON p.ID = pm.post_id
					WHERE p.ID IS NULL LIMIT %d",
					self::BATCH
				)
			);
			if ( ! $ids ) {
				break;
			}
			$in       = implode( ',', array_map( 'intval', $ids ) );
			$removed += (int) $wpdb->query( "DELETE FROM {$wpdb->postmeta} WHERE meta_id IN ($in)" ); // phpcs:ignore
		}
		return array( 'removed' => $removed, 'more' => $this->out_of_time() );
	}

	private function task_optimize_tables() {
		global $wpdb;
		$done    = array();
		$pending = false;
		foreach ( LXJ_Metrics::tables() as $t ) {
			// Only tables where at least 1 MB or 10% is reclaimable. Optimizing everything
			// rebuilds large InnoDB tables for no gain and can briefly lock them.
			$worth_it = $t['free'] >= MB_IN_BYTES || ( $t['total'] > 0 && $t['free'] / $t['total'] >= 0.10 );
			if ( ! $worth_it ) {
				continue;
			}
			if ( $this->out_of_time() ) {
				$pending = true;
				break;
			}
			$wpdb->query( 'OPTIMIZE TABLE `' . esc_sql( $t['name'] ) . '`' ); // phpcs:ignore
			$done[] = $t['name'];
		}
		return array(
			'removed' => count( $done ),
			'more'    => $pending,
			'note'    => $done ? 'Optimized: ' . implode( ', ', $done ) : 'No tables had meaningful reclaimable space.',
		);
	}
}
