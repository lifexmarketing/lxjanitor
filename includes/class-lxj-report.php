<?php
/**
 * Printable report: site, baseline vs. now, and a plain-language history.
 *
 * Opened from the Overview and History tabs as a standalone page. The browser's print dialog opens
 * automatically so the report can be saved with "Save as PDF". No PDF library is needed, so the
 * plugin stays small and any characters in site names print correctly.
 */

defined( 'ABSPATH' ) || exit;

class LXJ_Report {

	const ACTION = 'lxj_report';

	/** Shown wherever the page speed figure appears. */
	const TTFB_CAVEAT = 'The home page response time is measured from the web server to itself, with caching bypassed. It shows how quickly the server builds a page and is useful for comparing before and after, but it is not what visitors experience. For visitor-facing speed, use PageSpeed Insights and Client Site Monitor.';

	/**
	 * Report link, valid for the current user.
	 *
	 * @return string
	 */
	public static function url() {
		return wp_nonce_url( admin_url( 'admin-post.php?action=' . self::ACTION ), self::ACTION );
	}

	/**
	 * Baseline vs. now, shared by the Overview tab and the report so the numbers always match.
	 * For every metric, lower is better.
	 *
	 * @return array[] label, before (int|null), now (int|null), bytes (bool), ttfb (bool)
	 */
	public static function comparison_rows() {
		$baseline = LXJ_Metrics::baseline_snapshot();
		$latest   = LXJ_Metrics::last_snapshot();
		$current  = LXJ_Metrics::snapshot( false );
		$base     = $baseline ? $baseline['data'] : array();
		$as_sum   = function ( $as ) {
			return $as ? array_sum( array_diff_key( $as, array( '_logs' => 1 ) ) ) : null;
		};
		return array(
			array( 'label' => 'Database size', 'before' => $base['db_size'] ?? null, 'now' => $current['db_size'], 'bytes' => true ),
			array( 'label' => 'Autoloaded options (target under 500 KB)', 'before' => $base['autoload_bytes'] ?? null, 'now' => $current['autoload_bytes'], 'bytes' => true ),
			array( 'label' => 'Post revisions', 'before' => $base['counts']['revisions'] ?? null, 'now' => $current['counts']['revisions'] ),
			array( 'label' => 'Action Scheduler actions', 'before' => $as_sum( $base['action_scheduler'] ?? null ), 'now' => $as_sum( $current['action_scheduler'] ) ),
			array( 'label' => 'Cron events', 'before' => $base['cron_events'] ?? null, 'now' => $current['cron_events'] ),
			array( 'label' => 'Uncached home page response, ms (from latest snapshot)', 'before' => $base['ttfb']['median_ms'] ?? null, 'now' => $latest['data']['ttfb']['median_ms'] ?? null, 'ttfb' => true ),
		);
	}

	/**
	 * @param array $row From comparison_rows().
	 * @param string $which 'before' or 'now'.
	 * @return string Plain text (not escaped).
	 */
	public static function format_value( array $row, $which ) {
		$v = $row[ $which ];
		if ( null === $v ) {
			return '—';
		}
		return ! empty( $row['bytes'] ) ? lxj_bytes( $v ) : number_format_i18n( $v );
	}

	/**
	 * @param array $row From comparison_rows().
	 * @return float|null Percent change from baseline, or null when it cannot be computed.
	 */
	public static function change( array $row ) {
		if ( null === $row['before'] || null === $row['now'] || $row['before'] <= 0 ) {
			return null;
		}
		return ( $row['now'] - $row['before'] ) / $row['before'] * 100;
	}

	/**
	 * Display name for a history entry's user.
	 *
	 * @param int $user_id User id (0 for WP-CLI and scheduled runs).
	 * @return string
	 */
	public static function who( $user_id ) {
		$user = $user_id ? get_userdata( $user_id ) : false;
		return $user ? $user->display_name : 'WP-CLI or system';
	}

	/**
	 * One plain-language line for a history entry.
	 *
	 * @param array $entry       History entry (time, user, type, data).
	 * @param bool  $is_baseline Whether this is the first snapshot.
	 * @return string
	 */
	public static function summarize( array $entry, $is_baseline = false ) {
		$d = (array) ( $entry['data'] ?? array() );

		if ( 'snapshot' === ( $entry['type'] ?? '' ) ) {
			$parts = array();
			if ( isset( $d['db_size'] ) ) {
				$parts[] = 'database ' . lxj_bytes( $d['db_size'] );
			}
			if ( isset( $d['autoload_bytes'] ) ) {
				$parts[] = 'autoload ' . lxj_bytes( $d['autoload_bytes'] );
			}
			if ( isset( $d['ttfb']['median_ms'] ) ) {
				$parts[] = 'home page response ' . number_format_i18n( $d['ttfb']['median_ms'] ) . ' ms';
			}
			$label = $is_baseline ? 'Baseline snapshot recorded' : 'Snapshot recorded';
			if ( ! empty( $d['auto'] ) ) {
				$label .= ' automatically before cleanup';
			}
			return $label . ( $parts ? ': ' . implode( ', ', $parts ) : '' ) . '.';
		}

		if ( isset( $d['cleanup'] ) ) {
			$nouns = array(
				'action_scheduler'   => 'old scheduled actions',
				'woocommerce_log'    => 'WooCommerce log entries',
				'revisions'          => 'post revisions',
				'trash'              => 'trashed posts and comments',
				'auto_drafts'        => 'auto-drafts',
				'expired_transients' => 'expired transients',
				'orphan_postmeta'    => 'orphaned post meta rows',
				'optimize_tables'    => 'tables optimized',
			);
			$parts     = array();
			$optimized = 0;
			foreach ( (array) $d['cleanup'] as $r ) {
				$n = (int) ( $r['removed'] ?? 0 );
				if ( ! $n ) {
					continue;
				}
				if ( 'optimize_tables' === ( $r['task'] ?? '' ) ) {
					$optimized = $n;
					continue;
				}
				$parts[] = number_format_i18n( $n ) . ' ' . ( $nouns[ $r['task'] ?? '' ] ?? (string) ( $r['task'] ?? 'items' ) );
			}
			if ( ! $parts && ! $optimized ) {
				return 'Database cleanup ran; nothing needed removing.';
			}
			$line = 'Database cleanup' . ( $parts ? ' removed ' . self::join_list( $parts ) : '' );
			if ( $optimized ) {
				$line .= ( $parts ? '; ' : ': ' ) . sprintf( _n( '%s table optimized', '%s tables optimized', $optimized ), number_format_i18n( $optimized ) );
			}
			return $line . '.';
		}

		if ( isset( $d['crons_removed'] ) ) {
			$hooks = array_values( array_filter( array_map( function ( $h ) {
				return is_scalar( $h ) ? (string) $h : '';
			}, (array) $d['crons_removed'] ) ) );
			$shown = array_slice( $hooks, 0, 5 );
			$more  = count( $hooks ) - count( $shown );
			return sprintf(
				'Removed %s scheduled cron events (%s%s).',
				number_format_i18n( (int) ( $d['events'] ?? 0 ) ),
				implode( ', ', $shown ),
				$more > 0 ? ', and ' . $more . ' more' : ''
			);
		}

		if ( isset( $d['autoload_disabled'] ) ) {
			return sprintf(
				'Stopped autoloading %s options; autoloaded data went from %s to %s.',
				number_format_i18n( count( (array) $d['autoload_disabled'] ) ),
				lxj_bytes( $d['before'] ?? 0 ),
				lxj_bytes( $d['after'] ?? 0 )
			);
		}

		if ( isset( $d['autoload_restored'] ) ) {
			return sprintf( 'Restored autoload on %s options.', number_format_i18n( (int) $d['autoload_restored'] ) );
		}

		if ( isset( $d['tables_removed'] ) ) {
			$name = (string) $d['tables_removed'];
			foreach ( LXJ_Tables::owners() as $o ) {
				if ( $o['id'] === $d['tables_removed'] ) {
					$name = $o['name'];
				}
			}
			if ( empty( $d['ok'] ) ) {
				return 'Tried to remove leftover tables from ' . $name . '; nothing was removed.';
			}
			$tables = count( (array) ( $d['tables'] ?? array() ) );
			$held   = LXJ_Tables::describe_data( (array) ( $d['data'] ?? array() ) );
			$line   = sprintf( 'Removed leftover data from %s: %s', $name, sprintf( _n( '%s table', '%s tables', $tables ), number_format_i18n( $tables ) ) );
			if ( ! empty( $d['options'] ) ) {
				$line .= sprintf( _n( ' and %s setting', ' and %s settings', (int) $d['options'] ), number_format_i18n( (int) $d['options'] ) );
			}
			return $line . ( $held ? ', including ' . $held : '' ) . '.';
		}

		if ( ! empty( $d['baseline_reset'] ) ) {
			$p = (array) ( $d['previous'] ?? array() );
			if ( empty( $p['time'] ) ) {
				return 'Baseline reset to a new snapshot.';
			}
			$parts = array();
			if ( isset( $p['db_size'] ) ) {
				$parts[] = 'database ' . lxj_bytes( $p['db_size'] );
			}
			if ( isset( $p['autoload_bytes'] ) ) {
				$parts[] = 'autoload ' . lxj_bytes( $p['autoload_bytes'] );
			}
			if ( isset( $p['ttfb_ms'] ) ) {
				$parts[] = 'home page response ' . number_format_i18n( $p['ttfb_ms'] ) . ' ms';
			}
			return 'Baseline reset to a new snapshot. The previous baseline was recorded ' . wp_date( 'M j, Y', (int) $p['time'] )
				. ( $parts ? ' (' . implode( ', ', $parts ) . ')' : '' ) . '.';
		}

		if ( isset( $d['unknown_tables_removed'] ) ) {
			$n = count( (array) $d['unknown_tables_removed'] );
			return sprintf( 'Removed %s with no identified owner (%s).', sprintf( _n( '%s table', '%s tables', $n ), number_format_i18n( $n ) ), lxj_bytes( $d['size'] ?? 0 ) );
		}

		return 'Maintenance action recorded.';
	}

	private static function join_list( array $items ) {
		if ( count( $items ) < 2 ) {
			return implode( '', $items );
		}
		$last = array_pop( $items );
		return implode( ', ', $items ) . ' and ' . $last;
	}

	/**
	 * admin-post handler: print the standalone report page.
	 */
	public static function handle() {
		if ( ! current_user_can( LXJ_Admin::CAP ) ) {
			wp_die( 'Not allowed.' );
		}
		check_admin_referer( self::ACTION );
		nocache_headers();
		header( 'Content-Type: text/html; charset=' . get_option( 'blog_charset' ) );
		self::render();
		exit;
	}

	public static function render() {
		$site     = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		$home     = home_url( '/' );
		$host     = wp_parse_url( $home, PHP_URL_HOST );
		$baseline = LXJ_Metrics::baseline_snapshot();
		$rows     = self::comparison_rows();
		$history  = (array) get_option( LXJ_HISTORY_OPTION, array() ); // Oldest first: reads as a timeline.
		$me       = wp_get_current_user();
		$title    = sprintf( 'LX Janitor report - %s - %s', $host, wp_date( 'Y-m-d' ) ); // Browsers use this as the PDF file name.
		?>
<!doctype html>
<html lang="<?php echo esc_attr( get_bloginfo( 'language' ) ); ?>">
<head>
<meta charset="<?php echo esc_attr( get_option( 'blog_charset' ) ); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?php echo esc_html( $title ); ?></title>
<style>
	:root { --ink: #1d2327; --muted: #50575e; --line: #dcdcde; --good: #00733b; --bad: #b32d2e; }
	* { box-sizing: border-box; }
	body { margin: 0; background: #f0f0f1; color: var(--ink); font: 14px/1.5 -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif; }
	.page { max-width: 820px; margin: 24px auto; padding: 40px 48px; background: #fff; box-shadow: 0 1px 3px rgba(0,0,0,.08); }
	.toolbar { max-width: 820px; margin: 16px auto 0; display: flex; gap: 12px; align-items: center; justify-content: flex-end; padding: 0 16px; }
	.toolbar button { font: inherit; padding: 6px 14px; border: 1px solid #2271b1; background: #2271b1; color: #fff; border-radius: 3px; cursor: pointer; }
	.toolbar span { color: var(--muted); font-size: 13px; }
	header { border-bottom: 2px solid var(--ink); padding-bottom: 12px; margin-bottom: 24px; }
	.eyebrow { text-transform: uppercase; letter-spacing: .08em; font-size: 11px; color: var(--muted); margin: 0 0 4px; }
	h1 { font-size: 24px; margin: 0; line-height: 1.25; }
	.domain { margin: 2px 0 0; font-size: 15px; }
	.meta { margin: 10px 0 0; color: var(--muted); font-size: 12px; }
	h2 { font-size: 16px; margin: 28px 0 8px; }
	table { width: 100%; border-collapse: collapse; }
	th, td { text-align: left; padding: 7px 8px; border-bottom: 1px solid var(--line); vertical-align: top; }
	th { font-size: 12px; text-transform: uppercase; letter-spacing: .04em; color: var(--muted); border-bottom-color: var(--ink); }
	td.num, th.num { text-align: right; white-space: nowrap; font-variant-numeric: tabular-nums; }
	.good { color: var(--good); font-weight: 600; }
	.bad { color: var(--bad); font-weight: 600; }
	.note { margin: 10px 0 0; padding: 10px 12px; background: #f6f7f7; border-left: 3px solid var(--muted); font-size: 12px; color: var(--muted); }
	.history td:first-child { white-space: nowrap; width: 1%; }
	.history td:nth-child(2) { white-space: nowrap; width: 1%; }
	.empty { color: var(--muted); }
	@media (max-width: 640px) { .page { margin: 0; padding: 24px 16px; } .history td:first-child, .history td:nth-child(2) { white-space: normal; } }
	@media print {
		@page { margin: 16mm 14mm; }
		body { background: #fff; font-size: 11.5px; }
		.toolbar { display: none; }
		.page { margin: 0; padding: 0; max-width: none; box-shadow: none; }
		tr { break-inside: avoid; }
		thead { display: table-header-group; }
		h2 { break-after: avoid; }
	}
</style>
</head>
<body>
<div class="toolbar">
	<span>Choose "Save as PDF" as the destination to download.</span>
	<button type="button" onclick="window.print()">Print or save as PDF</button>
</div>
<main class="page">
	<header>
		<p class="eyebrow">Database and speed maintenance report</p>
		<h1><?php echo esc_html( $site ? $site : $host ); ?></h1>
		<p class="domain"><?php echo esc_html( untrailingslashit( $home ) ); ?></p>
		<p class="meta">
			<?php
			echo esc_html(
				'Report generated ' . wp_date( 'F j, Y g:i a T' )
				. ( $me && $me->exists() ? ' by ' . $me->display_name : '' )
				. ( $baseline ? '. Baseline recorded ' . wp_date( 'F j, Y g:i a', $baseline['time'] ) . '.' : '.' )
				. ' Generated with LX Janitor ' . LXJ_VERSION . '.'
			);
			?>
		</p>
	</header>

	<h2>Baseline compared with now</h2>
	<?php if ( ! $baseline ) : ?>
		<p class="empty">No baseline snapshot has been recorded yet, so only current values are shown.</p>
	<?php endif; ?>
	<table>
		<thead><tr><th>Metric</th><th class="num">Baseline</th><th class="num">Now</th><th class="num">Change</th></tr></thead>
		<tbody>
		<?php foreach ( $rows as $row ) : ?>
			<?php
			$pct   = self::change( $row );
			$class = null === $pct || 0.0 === round( $pct ) ? '' : ( $pct < 0 ? 'good' : 'bad' ); // Lower is better for every metric.
			?>
			<tr>
				<td><?php echo esc_html( $row['label'] ); ?><?php echo ! empty( $row['ttfb'] ) ? ' *' : ''; ?></td>
				<td class="num"><?php echo esc_html( self::format_value( $row, 'before' ) ); ?></td>
				<td class="num"><?php echo esc_html( self::format_value( $row, 'now' ) ); ?></td>
				<td class="num <?php echo esc_attr( $class ); ?>"><?php echo esc_html( null === $pct ? '—' : sprintf( '%+.0f%%', $pct ) ); ?></td>
			</tr>
		<?php endforeach; ?>
		</tbody>
	</table>
	<p class="note">Lower is better for every metric: a negative change is an improvement. * <?php echo esc_html( self::TTFB_CAVEAT ); ?></p>

	<h2>History</h2>
	<?php if ( ! $history ) : ?>
		<p class="empty">No maintenance has been recorded yet.</p>
	<?php else : ?>
		<table class="history">
			<thead><tr><th>Date</th><th>By</th><th>What happened</th></tr></thead>
			<tbody>
			<?php foreach ( $history as $h ) : ?>
				<tr>
					<td><?php echo esc_html( wp_date( 'M j, Y g:i a', (int) ( $h['time'] ?? 0 ) ) ); ?></td>
					<td><?php echo esc_html( self::who( (int) ( $h['user'] ?? 0 ) ) ); ?></td>
					<td><?php echo esc_html( self::summarize( (array) $h, LXJ_Metrics::is_baseline( (array) $h ) ) ); ?></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php if ( count( $history ) >= 50 ) : ?>
			<p class="note">History keeps the 50 most recent entries, so earlier activity may not appear here. The baseline above is stored separately and is always the first snapshot recorded on this site.</p>
		<?php endif; ?>
	<?php endif; ?>
</main>
<script>window.addEventListener('load', function () { setTimeout(function () { window.print(); }, 300); });</script>
</body>
</html>
		<?php
	}
}
