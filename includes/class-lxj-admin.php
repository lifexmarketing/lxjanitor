<?php
/**
 * Tools > LX Janitor admin screen.
 */

defined( 'ABSPATH' ) || exit;

class LXJ_Admin {

	const SLUG = 'lxjanitor';
	const CAP  = 'manage_options';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_post_lxj_snapshot', array( __CLASS__, 'handle_snapshot' ) );
		add_action( 'admin_post_lxj_clean', array( __CLASS__, 'handle_clean' ) );
		add_action( 'admin_post_lxj_crons', array( __CLASS__, 'handle_crons' ) );
		add_action( 'admin_post_lxj_autoload', array( __CLASS__, 'handle_autoload' ) );
		add_action( 'admin_post_lxj_probe', array( __CLASS__, 'handle_probe' ) );
		add_action( 'admin_post_lxj_tables', array( __CLASS__, 'handle_tables' ) );
		add_action( 'admin_post_lxj_tables_unknown', array( __CLASS__, 'handle_tables_unknown' ) );
		add_action( 'admin_post_' . LXJ_Report::ACTION, array( 'LXJ_Report', 'handle' ) );
		add_action( 'admin_post_lxj_reset_baseline', array( __CLASS__, 'handle_reset_baseline' ) );
	}

	public static function handle_reset_baseline() {
		self::guard( 'lxj_reset_baseline' );
		if ( empty( $_POST['confirm_reset'] ) ) {
			self::done( 'overview', 'The baseline was not reset. Tick the confirmation box to reset it.', 'error' );
		}
		$r    = lxj_reset_baseline();
		$data = (array) $r['current']['data'];
		$msg  = sprintf( 'Baseline reset. New baseline: database %s, autoload %s.', lxj_bytes( $data['db_size'] ?? 0 ), lxj_bytes( $data['autoload_bytes'] ?? 0 ) );
		if ( $r['previous'] ) {
			$msg .= ' The previous baseline (' . wp_date( 'M j, Y', $r['previous']['time'] ) . ') is recorded in History.';
		}
		self::done( 'overview', $msg );
	}

	private static function report_button() {
		printf(
			'<p><a class="button" href="%s" target="_blank" rel="noopener">Download PDF report</a> <span class="description">Opens a printable report; choose "Save as PDF" in the print dialog.</span></p>',
			esc_url( LXJ_Report::url() )
		);
	}

	public static function menu() {
		$hook = add_management_page( 'LX Janitor', 'LX Janitor', self::CAP, self::SLUG, array( __CLASS__, 'render' ) );
		add_action( 'admin_print_styles-' . $hook, function () {
			wp_enqueue_style( 'lxjanitor', LXJ_URL . 'assets/admin.css', array(), LXJ_VERSION );
		} );
	}

	/* ---------------------------------------------------------------------
	 * Handlers
	 * ------------------------------------------------------------------- */

	private static function guard( $action ) {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( 'Not allowed.' );
		}
		check_admin_referer( $action );
	}

	private static function done( $tab, $message, $type = 'success' ) {
		set_transient( 'lxj_notice_' . get_current_user_id(), array( 'message' => $message, 'type' => $type ), 60 );
		wp_safe_redirect( admin_url( 'tools.php?page=' . self::SLUG . '&tab=' . $tab ) );
		exit;
	}

	public static function handle_snapshot() {
		self::guard( 'lxj_snapshot' );
		$snap = LXJ_Metrics::snapshot( true );
		lxj_log( 'snapshot', $snap );
		self::done( 'overview', 'Snapshot recorded. Database ' . lxj_bytes( $snap['db_size'] ) . ', autoload ' . lxj_bytes( $snap['autoload_bytes'] ) . '.' );
	}

	public static function handle_probe() {
		self::guard( 'lxj_probe' );
		LXJ_Advisor::probe( true );
		self::done( 'overview', 'Home page re-checked.' );
	}

	public static function handle_clean() {
		self::guard( 'lxj_clean' );
		if ( empty( $_POST['backup_confirmed'] ) ) {
			self::done( 'cleanup', 'Confirm you have a current backup before running cleanup.', 'error' );
		}
		$tasks = array_map( 'sanitize_key', (array) wp_unslash( $_POST['tasks'] ?? array() ) );
		if ( ! $tasks ) {
			self::done( 'cleanup', 'Select at least one task.', 'error' );
		}

		// Record a "before" snapshot automatically if none exists yet, so there is always a baseline.
		if ( ! LXJ_Metrics::baseline_snapshot() ) {
			lxj_log( 'snapshot', LXJ_Metrics::snapshot( false ) + array( 'auto' => true ) );
		}

		$cleaner = new LXJ_Cleaner( 20 );
		$results = array();
		$more    = array();
		foreach ( array_keys( LXJ_Cleaner::tasks() ) as $task ) {
			if ( ! in_array( $task, $tasks, true ) ) {
				continue;
			}
			$r         = $cleaner->run( $task );
			$results[] = $r;
			if ( $r['more'] ) {
				$more[] = $task;
			}
		}
		lxj_log( 'action', array( 'cleanup' => $results ) );

		$parts = array();
		foreach ( $results as $r ) {
			$parts[] = $r['task'] . ': ' . number_format_i18n( $r['removed'] ) . ( $r['note'] ? ' (' . $r['note'] . ')' : '' );
		}
		$msg = 'Cleanup ran. ' . implode( '; ', $parts ) . '.';
		if ( $more ) {
			$msg .= ' Time limit reached, run again to continue: ' . implode( ', ', $more ) . '.';
		}
		self::done( 'cleanup', $msg, $more ? 'warning' : 'success' );
	}

	public static function handle_tables() {
		self::guard( 'lxj_tables' );
		if ( empty( $_POST['backup_confirmed'] ) ) {
			self::done( 'tables', 'Confirm you have a current database backup before removing tables.', 'error' );
		}
		$groups = array_map( 'sanitize_key', (array) wp_unslash( $_POST['groups'] ?? array() ) );
		if ( ! $groups ) {
			self::done( 'tables', 'Select at least one group.', 'error' );
		}
		$messages = array();
		$failed   = false;
		foreach ( $groups as $id ) {
			$r          = LXJ_Tables::remove_group( $id );
			$messages[] = $r['message'];
			$failed     = $failed || ! $r['ok'];
			lxj_log( 'action', array( 'tables_removed' => $id, 'tables' => $r['tables'], 'options' => $r['options'], 'data' => $r['data'] ?? array(), 'ok' => $r['ok'] ) );
		}
		self::done( 'tables', implode( ' ', $messages ), $failed ? 'warning' : 'success' );
	}

	public static function handle_tables_unknown() {
		self::guard( 'lxj_tables_unknown' );
		$names = array_map( 'sanitize_text_field', (array) wp_unslash( $_POST['unknown_tables'] ?? array() ) );
		if ( ! $names ) {
			self::done( 'tables', 'Select at least one table.', 'error' );
		}
		if ( empty( $_POST['backup_confirmed'] ) ) {
			self::done( 'tables', 'Nothing was dropped. Confirm you have a current database backup first.', 'error' );
		}
		if ( 'DELETE' !== trim( (string) wp_unslash( $_POST['confirm_text'] ?? '' ) ) ) {
			self::done( 'tables', 'Nothing was dropped. Type DELETE (in capitals) to confirm permanent removal.', 'error' );
		}
		$r = LXJ_Tables::remove_unknown( $names );
		lxj_log( 'action', array( 'unknown_tables_removed' => $r['tables'], 'skipped' => $r['skipped'], 'size' => $r['size'], 'ok' => $r['ok'] ) );
		self::done( 'tables', $r['message'], $r['ok'] ? 'success' : 'warning' );
	}

	public static function handle_crons() {
		self::guard( 'lxj_crons' );
		$hooks = array_map( 'sanitize_text_field', (array) wp_unslash( $_POST['hooks'] ?? array() ) );
		if ( ! $hooks ) {
			self::done( 'crons', 'No cron hooks selected.', 'error' );
		}
		$r = LXJ_Crons::remove( $hooks );
		lxj_log( 'action', array( 'crons_removed' => $hooks, 'events' => $r['removed'] ) );
		$msg = sprintf( 'Unscheduled %d events across %d hooks.', $r['removed'], count( $hooks ) - count( $r['skipped'] ) );
		if ( $r['skipped'] ) {
			$msg .= ' Skipped protected core hooks: ' . implode( ', ', $r['skipped'] ) . '.';
		}
		$msg .= ' If a hook reappears, the plugin that owns it reschedules it; remove or reconfigure that plugin instead.';
		self::done( 'crons', $msg );
	}

	public static function handle_autoload() {
		self::guard( 'lxj_autoload' );
		if ( isset( $_POST['restore_all'] ) ) {
			$n = LXJ_Autoload::restore_all();
			lxj_log( 'action', array( 'autoload_restored' => $n ) );
			self::done( 'autoload', sprintf( 'Restored autoload on %d options.', $n ) );
		}
		$names = array_map( 'sanitize_text_field', (array) wp_unslash( $_POST['options'] ?? array() ) );
		if ( ! $names ) {
			self::done( 'autoload', 'No options selected.', 'error' );
		}
		$before = LXJ_Metrics::autoload_bytes();
		$n      = LXJ_Autoload::disable( $names );
		$after  = LXJ_Metrics::autoload_bytes();
		lxj_log( 'action', array( 'autoload_disabled' => $names, 'before' => $before, 'after' => $after ) );
		self::done( 'autoload', sprintf( 'Stopped autoloading %d options. Autoload went from %s to %s. Test the front end and admin; use "Restore all" if anything breaks.', $n, lxj_bytes( $before ), lxj_bytes( $after ) ) );
	}

	/* ---------------------------------------------------------------------
	 * Rendering
	 * ------------------------------------------------------------------- */

	public static function render() {
		$tabs = array(
			'overview' => 'Overview',
			'crons'    => 'Cron',
			'autoload' => 'Autoload',
			'tables'   => 'Tables',
			'cleanup'  => 'Database',
			'history'  => 'History',
		);
		$tab  = isset( $_GET['tab'] ) && isset( $tabs[ $_GET['tab'] ] ) ? sanitize_key( $_GET['tab'] ) : 'overview'; // phpcs:ignore WordPress.Security.NonceVerification

		echo '<div class="wrap lxj"><h1>LX Janitor</h1>';
		self::notice();
		echo '<nav class="nav-tab-wrapper">';
		foreach ( $tabs as $key => $label ) {
			printf(
				'<a href="%s" class="nav-tab %s">%s</a>',
				esc_url( admin_url( 'tools.php?page=' . self::SLUG . '&tab=' . $key ) ),
				$key === $tab ? 'nav-tab-active' : '',
				esc_html( $label )
			);
		}
		echo '</nav>';
		call_user_func( array( __CLASS__, 'tab_' . $tab ) );
		echo '</div>';
	}

	private static function notice() {
		$key    = 'lxj_notice_' . get_current_user_id();
		$notice = get_transient( $key );
		if ( $notice ) {
			delete_transient( $key );
			printf( '<div class="notice notice-%s is-dismissible"><p>%s</p></div>', esc_attr( $notice['type'] ), esc_html( $notice['message'] ) );
		}
	}

	private static function form_open( $action ) {
		printf( '<form method="post" action="%s">', esc_url( admin_url( 'admin-post.php' ) ) );
		printf( '<input type="hidden" name="action" value="%s">', esc_attr( $action ) );
		wp_nonce_field( $action );
	}

	private static function tab_overview() {
		$baseline = LXJ_Metrics::baseline_snapshot();

		echo '<h2>Where things stand</h2>';
		echo '<table class="widefat striped lxj-compare"><thead><tr><th>Metric</th><th>Baseline</th><th>Now</th><th>Change</th></tr></thead><tbody>';
		// Same rows as the printable report, so the two never disagree.
		foreach ( LXJ_Report::comparison_rows() as $row ) {
			$pct = LXJ_Report::change( $row );
			printf(
				'<tr><td>%s</td><td>%s</td><td>%s</td><td>%s</td></tr>',
				esc_html( $row['label'] ),
				esc_html( LXJ_Report::format_value( $row, 'before' ) ),
				esc_html( LXJ_Report::format_value( $row, 'now' ) ),
				esc_html( null === $pct ? '—' : sprintf( '%+.0f%%', $pct ) )
			);
		}
		echo '</tbody></table>';

		echo '<p>';
		echo $baseline
			? esc_html( 'Baseline recorded ' . wp_date( 'M j, Y g:i a', $baseline['time'] ) . '. ' )
			: '<strong>No baseline yet.</strong> Record one before cleanup so you can show the improvement. ';
		echo esc_html( LXJ_Report::TTFB_CAVEAT ) . '</p>';

		self::form_open( 'lxj_snapshot' );
		submit_button( $baseline ? 'Record snapshot now' : 'Record baseline snapshot', 'primary', 'submit', false );
		echo '</form>';
		self::report_button();

		if ( $baseline ) {
			echo '<details class="lxj-reset"><summary>Reset baseline</summary>';
			echo '<p>Only reset the baseline when the old starting point no longer means anything, for example after a site rebuild, a migration to new hosting, or a restore from an old backup. '
				. 'Resetting takes a new snapshot now (including the home page speed check, which takes a few seconds) and uses it as the baseline. '
				. 'Every "Baseline" and "Change" figure on this page and in reports will then compare against the new snapshot, so improvements made before today will no longer show. '
				. 'The current baseline\'s figures (' . esc_html( wp_date( 'M j, Y', $baseline['time'] ) ) . ') are kept in History.</p>';
			self::form_open( 'lxj_reset_baseline' );
			echo '<p><label><input type="checkbox" name="confirm_reset" value="1" required> I understand that reports will no longer show improvements made before today.</label></p>';
			submit_button( 'Reset baseline to a new snapshot', 'delete', 'submit', false );
			echo '</form></details>';
		}

		echo '<h2>Recommendations</h2>';
		$recs = LXJ_Advisor::recommendations();
		if ( ! $recs ) {
			echo '<p>No follow-up recommendations. Nice work.</p>';
		} else {
			echo '<table class="widefat lxj-recs"><thead><tr><th>Priority</th><th>Finding</th><th>What to do</th></tr></thead><tbody>';
			foreach ( $recs as $r ) {
				printf(
					'<tr><td><span class="lxj-sev lxj-sev-%1$s">%1$s</span></td><td><strong>%2$s</strong><br>%3$s</td><td>%4$s</td></tr>',
					esc_html( $r['severity'] ),
					esc_html( $r['title'] ),
					esc_html( $r['detail'] ),
					esc_html( $r['action'] )
				);
			}
			echo '</tbody></table>';
		}
		$probe = LXJ_Advisor::probe();
		if ( ! empty( $probe['checked_at'] ) ) {
			echo '<p class="description">Home page checked ' . esc_html( human_time_diff( $probe['checked_at'] ) ) . ' ago.</p>';
		}
		self::form_open( 'lxj_probe' );
		submit_button( 'Re-check home page', 'secondary', 'submit', false );
		echo '</form>';

		echo '<h2>Manual steps from the guide</h2><ul class="ul-disc">';
		foreach ( LXJ_Advisor::manual_steps() as $step ) {
			echo '<li>' . esc_html( $step ) . '</li>';
		}
		echo '</ul>';
	}

	private static function tab_cleanup() {
		$counts = LXJ_Metrics::cleanup_counts();
		$as     = LXJ_Metrics::action_scheduler();
		$wcl    = LXJ_Metrics::woocommerce_log();
		$tables = LXJ_Metrics::tables();
		$free   = array_sum( wp_list_pluck( $tables, 'free' ) );
		$wcl_to = array(
			'database' => 'WooCommerce logs to the database',
			'file'     => 'WooCommerce logs to files',
			'other'    => 'WooCommerce uses a custom log handler',
			'inactive' => 'WooCommerce is not active',
		);

		$current = array(
			'action_scheduler'   => $as ? sprintf( '%s complete, %s failed, %s canceled, %s pending, %s logs', number_format_i18n( $as['complete'] ?? 0 ), number_format_i18n( $as['failed'] ?? 0 ), number_format_i18n( $as['canceled'] ?? 0 ), number_format_i18n( $as['pending'] ?? 0 ), number_format_i18n( $as['_logs'] ?? 0 ) ) : 'Not installed',
			'woocommerce_log'    => $wcl ? sprintf( '%s entries (%s). %s.', number_format_i18n( $wcl['rows'] ), lxj_bytes( $wcl['size'] ), $wcl_to[ $wcl['handler'] ] ) : 'Not present',
			'revisions'          => number_format_i18n( $counts['revisions'] ) . ' revisions total',
			'trash'              => sprintf( '%s posts, %s comments', number_format_i18n( $counts['trashed_posts'] ), number_format_i18n( $counts['trashed_comments'] + $counts['spam_comments'] ) ),
			'auto_drafts'        => number_format_i18n( $counts['auto_drafts'] ) . ' total',
			'expired_transients' => number_format_i18n( $counts['expired_transients'] ),
			'orphan_postmeta'    => number_format_i18n( $counts['orphan_postmeta'] ),
			'optimize_tables'    => lxj_bytes( $free ) . ' reclaimable',
		);

		echo '<p>Database size: <strong>' . esc_html( lxj_bytes( LXJ_Metrics::db_size() ) ) . '</strong>. Each run stops after about 20 seconds; if it says more remains, run it again.</p>';

		self::form_open( 'lxj_clean' );
		echo '<table class="widefat striped"><thead><tr><td class="check-column"></td><th>Task</th><th>Currently</th></tr></thead><tbody>';
		$off = LXJ_Cleaner::off_by_default();
		foreach ( LXJ_Cleaner::tasks() as $key => $label ) {
			printf(
				'<tr><th class="check-column"><input type="checkbox" name="tasks[]" value="%1$s" id="lxj-%1$s"%4$s></th><td><label for="lxj-%1$s">%2$s</label></td><td>%3$s</td></tr>',
				esc_attr( $key ),
				esc_html( $label ),
				esc_html( $current[ $key ] ),
				in_array( $key, $off, true ) ? '' : ' checked'
			);
		}
		echo '</tbody></table>';
		echo '<p><label><input type="checkbox" name="backup_confirmed" value="1"> I have a current backup of this site (full or database).</label></p>';
		submit_button( 'Run selected cleanup' );
		echo '</form>';

		echo '<h2>Largest tables</h2><table class="widefat striped"><thead><tr><th>Table</th><th>Engine</th><th>Rows (approx.)</th><th>Size</th><th>Reclaimable</th></tr></thead><tbody>';
		foreach ( array_slice( $tables, 0, 15 ) as $t ) {
			printf(
				'<tr><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td></tr>',
				esc_html( $t['name'] ),
				esc_html( $t['engine'] ),
				esc_html( number_format_i18n( $t['rows'] ) ),
				esc_html( lxj_bytes( $t['total'] ) ),
				esc_html( lxj_bytes( $t['free'] ) )
			);
		}
		echo '</tbody></table>';
	}

	private static function tab_crons() {
		$rows = LXJ_Crons::review();
		echo '<p>Flagged hooks either have no callback attached (the plugin that scheduled them is gone or inactive) or match the guide\'s list of unnecessary jobs. Flagged rows are pre-selected.</p>';
		self::form_open( 'lxj_crons' );
		echo '<table class="widefat striped"><thead><tr><td class="check-column"></td><th>Hook</th><th>Events</th><th>Schedule</th><th>Next run</th><th>Flag</th></tr></thead><tbody>';
		foreach ( $rows as $r ) {
			$flags = array();
			if ( $r['empty'] ) {
				$flags[] = 'No callback';
			}
			if ( $r['unwanted'] ) {
				$flags[] = 'Guide: remove';
			}
			if ( $r['protected'] ) {
				$flags = array( 'Core (protected)' );
			}
			$flagged = ( $r['empty'] || $r['unwanted'] ) && ! $r['protected'];
			printf(
				'<tr><th class="check-column">%s</th><td><code>%s</code></td><td>%d</td><td>%s</td><td>%s</td><td>%s</td></tr>',
				$r['protected'] ? '' : sprintf( '<input type="checkbox" name="hooks[]" value="%s" %s>', esc_attr( $r['hook'] ), checked( $flagged, true, false ) ), // phpcs:ignore
				esc_html( $r['hook'] ),
				(int) $r['count'],
				esc_html( $r['schedule'] ),
				esc_html( $r['next'] < time() ? 'Overdue' : human_time_diff( $r['next'] ) ),
				esc_html( implode( ', ', $flags ) )
			);
		}
		echo '</tbody></table>';
		submit_button( 'Unschedule selected hooks' );
		echo '</form>';
	}

	private static function tab_autoload() {
		$total   = LXJ_Metrics::autoload_bytes();
		$rows    = LXJ_Autoload::review( 20 );
		$changes = LXJ_Autoload::changes();
		$class   = $total > LXJ_Metrics::AUTOLOAD_LIMIT ? 'lxj-bad' : 'lxj-good';

		printf( '<p>Autoloaded options: <strong class="%s">%s</strong> (target under 500 KB).</p>', esc_attr( $class ), esc_html( lxj_bytes( $total ) ) );
		echo '<p>Options from inactive or removed plugins are usually safe to stop autoloading. For options from active plugins, find out why they are large first; the fix may be a plugin setting or replacing the plugin, which can need client approval. Changes here are reversible.</p>';

		self::form_open( 'lxj_autoload' );
		echo '<table class="widefat striped"><thead><tr><td class="check-column"></td><th>Option</th><th>Size</th><th>Likely owner</th></tr></thead><tbody>';
		foreach ( $rows as $r ) {
			$status_label = array(
				'core'     => 'core',
				'active'   => 'active plugin',
				'inactive' => 'inactive plugin',
				'not installed' => 'plugin not installed',
				'active theme'        => 'active theme',
				'inactive theme'      => 'inactive theme',
				'theme not installed' => 'theme deleted',
				'legacy'              => 'legacy, unused',
				'unknown'   => 'not matched',
				'transient' => 'transient',
			)[ $r['owner_status'] ] ?? $r['owner_status'];
			printf(
				'<tr><th class="check-column">%s</th><td><code>%s</code></td><td>%s</td><td>%s <span class="lxj-owner lxj-owner-%s">%s</span></td></tr>',
				$r['changeable'] ? sprintf( '<input type="checkbox" name="options[]" value="%s">', esc_attr( $r['option_name'] ) ) : '', // phpcs:ignore
				esc_html( $r['option_name'] ),
				esc_html( lxj_bytes( $r['size'] ) ),
				esc_html( $r['owner'] ),
				esc_attr( str_replace( ' ', '-', $r['owner_status'] ) ),
				esc_html( $status_label )
			);
		}
		echo '</tbody></table>';
		submit_button( 'Stop autoloading selected options', 'primary', 'submit', false );
		if ( $changes ) {
			echo ' ';
			submit_button( sprintf( 'Restore all (%d changed)', count( $changes ) ), 'secondary', 'restore_all', false );
		}
		echo '</form>';
	}

	private static function tab_tables() {
		$groups = LXJ_Tables::review();
		echo '<p>Tables that are not part of WordPress core, matched to the plugin that created them. A group can be removed once its plugin is no longer active. Groups that hold business data (form entries, orders, payments, redirects, subscribers) show exact counts: export anything the client may still need before removing them.</p>';
		if ( ! $groups ) {
			echo '<p>No non-core tables found.</p>';
			return;
		}
		$labels = array(
			'active'        => 'active',
			'inactive'      => 'installed, inactive',
			'not installed' => 'not installed',
			'shared'        => 'shared library',
			'unknown'       => 'unknown',
		);

		self::form_open( 'lxj_tables' );
		echo '<table class="widefat striped lxj-tables"><thead><tr><td class="check-column"></td><th>Owner</th><th>Plugin</th><th>Tables</th><th>Size</th><th>Notes</th></tr></thead><tbody>';
		$any_removable = false;
		foreach ( $groups as $g ) {
			$can   = $g['removable'] && ! $g['blocked'];
			$any_removable = $any_removable || $can;
			$names = array_map( function ( $t ) {
				return '<code>' . esc_html( $t['name'] ) . '</code>';
			}, $g['tables'] );
			$extra = $g['options'] ? sprintf( '<br>%d leftover options (%s)', $g['options'], esc_html( lxj_bytes( $g['options_size'] ) ) ) : '';
			$data_note = '';
			if ( $g['data'] ) {
				$held      = LXJ_Tables::describe_data( $g['data'] );
				$data_note = $held
					? '<strong class="lxj-bad">Contains ' . esc_html( $held ) . '.</strong> Export these first if the client may need them.'
					: 'No ' . esc_html( implode( ', ', array_keys( $g['data'] ) ) ) . ' stored.';
			}
			$notes = array_filter( array( $g['blocked'] ? '<strong>Blocked:</strong> ' . esc_html( $g['blocked'] ) : '', $data_note, esc_html( $g['note'] ) ) );
			printf(
				'<tr><th class="check-column">%s</th><td><strong>%s</strong>%s</td><td><span class="lxj-owner lxj-owner-%s">%s</span></td><td>%s%s</td><td>%s</td><td>%s</td></tr>',
				$can ? sprintf( '<input type="checkbox" name="groups[]" value="%s">', esc_attr( $g['id'] ) ) : '', // phpcs:ignore
				esc_html( $g['name'] ),
				$can ? ' <span class="lxj-sev lxj-sev-low">removable</span>' : '',
				esc_attr( str_replace( ' ', '-', $g['status'] ) ),
				esc_html( $labels[ $g['status'] ] ?? $g['status'] ),
				$names ? implode( ' ', $names ) : '&mdash;', // phpcs:ignore -- escaped above.
				$extra, // phpcs:ignore -- escaped above.
				esc_html( lxj_bytes( $g['size'] ) ),
				implode( '<br>', $notes ) // phpcs:ignore -- escaped above.
			);
		}
		echo '</tbody></table>';
		if ( $any_removable ) {
			echo '<p><label><input type="checkbox" name="backup_confirmed" value="1"> I have a current backup of this site\'s database. Dropped tables cannot be recovered without one.</label></p>';
			submit_button( 'Remove selected groups', 'delete' );
		}
		echo '</form>';

		foreach ( $groups as $g ) {
			if ( 'unknown' === $g['id'] && $g['tables'] ) {
				self::unknown_tables_form( $g['tables'] );
			}
		}
	}

	/**
	 * Manual override for tables LX Janitor cannot attribute to a plugin.
	 *
	 * @param array[] $tables Unknown-owner tables from LXJ_Tables::review().
	 */
	private static function unknown_tables_form( array $tables ) {
		echo '<div class="lxj-danger-zone">';
		echo '<h2>Remove unknown tables manually</h2>';
		echo '<div class="notice notice-error inline"><p><strong>Warning: this permanently deletes data.</strong> '
			. 'LX Janitor cannot tell which plugin created these tables, so it cannot check whether anything still uses them. '
			. 'Dropped tables and every row in them are gone immediately. There is no undo, no trash and no restore button in LX Janitor. '
			. '<strong>The only way to get them back is to restore a database backup</strong>, which also rolls back every other change made to the site since that backup was taken.</p>'
			. '<p>Only continue if you are certain which plugin created each table you select, and that the plugin is gone from this site for good.</p></div>';

		self::form_open( 'lxj_tables_unknown' );
		echo '<table class="widefat striped lxj-tables"><thead><tr><td class="check-column"></td><th>Table</th><th>Rows (approx.)</th><th>Size</th></tr></thead><tbody>';
		foreach ( $tables as $t ) {
			printf(
				'<tr><th class="check-column"><input type="checkbox" name="unknown_tables[]" value="%1$s" id="lxj-unk-%2$s"></th><td><label for="lxj-unk-%2$s"><code>%3$s</code></label></td><td>%4$s</td><td>%5$s</td></tr>',
				esc_attr( $t['name'] ),
				esc_attr( md5( $t['name'] ) ),
				esc_html( $t['name'] ),
				esc_html( number_format_i18n( (int) ( $t['rows'] ?? 0 ) ) ),
				esc_html( lxj_bytes( $t['total'] ) )
			);
		}
		echo '</tbody></table>';
		echo '<p><label><input type="checkbox" name="backup_confirmed" value="1"> I have a current, tested backup of this site\'s database, and I understand restoring it is the only way to recover these tables.</label></p>';
		echo '<p><label for="lxj-confirm-text">Type <code>DELETE</code> to confirm permanent removal of the selected tables:</label><br>'
			. '<input type="text" id="lxj-confirm-text" name="confirm_text" autocomplete="off" spellcheck="false" class="regular-text"></p>';
		submit_button( 'Permanently drop selected tables', 'delete' );
		echo '</form></div>';
	}

	private static function tab_history() {
		$history = array_reverse( (array) get_option( LXJ_HISTORY_OPTION, array() ) );
		if ( ! $history ) {
			echo '<p>Nothing recorded yet.</p>';
			return;
		}
		self::report_button();
		echo '<table class="widefat striped"><thead><tr><th>When</th><th>Who</th><th>What happened</th></tr></thead><tbody>';
		foreach ( $history as $h ) {
			printf(
				'<tr><td>%s</td><td>%s</td><td>%s<details><summary>Raw details</summary><code class="lxj-json">%s</code></details></td></tr>',
				esc_html( wp_date( 'M j, Y g:i a', $h['time'] ) ),
				esc_html( LXJ_Report::who( (int) $h['user'] ) ),
				esc_html( LXJ_Report::summarize( $h, LXJ_Metrics::is_baseline( $h ) ) ),
				esc_html( wp_json_encode( $h['data'] ) )
			);
		}
		echo '</tbody></table>';
	}
}
