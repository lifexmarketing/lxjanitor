<?php
/**
 * Cron review: replaces the WP Crontrol step in the guide.
 * Flags events with no callback attached ("empty") and the hooks the guide lists as unnecessary.
 */

defined( 'ABSPATH' ) || exit;

class LXJ_Crons {

	/**
	 * Hook patterns the guide says to remove. `*` is a wildcard.
	 *
	 * @return string[]
	 */
	public static function unwanted_patterns() {
		return apply_filters(
			'lxjanitor_unwanted_cron_patterns',
			array(
				'mainwp_child_*',
				'rank_math/content-ai/update_prompts',
				'monsterinsights_*',
				'siteground_optimizer_*',
			)
		);
	}

	/**
	 * Core hooks that are never offered for removal, even if something odd is going on.
	 *
	 * @return string[]
	 */
	public static function protected_hooks() {
		return array(
			'wp_version_check', 'wp_update_plugins', 'wp_update_themes', 'wp_scheduled_delete',
			'delete_expired_transients', 'wp_scheduled_auto_draft_delete', 'wp_privacy_delete_old_export_files',
			'wp_site_health_scheduled_check', 'recovery_mode_clean_expired_keys', 'wp_https_detection',
			'wp_update_user_counts', 'wp_delete_temp_updater_backups', 'action_scheduler_run_queue',
		);
	}

	/**
	 * All cron hooks, grouped, with flags.
	 *
	 * @return array[] hook, count, next, schedule, empty, unwanted, protected
	 */
	public static function review() {
		$hooks = array();
		foreach ( (array) _get_cron_array() as $timestamp => $by_hook ) {
			foreach ( (array) $by_hook as $hook => $events ) {
				foreach ( (array) $events as $event ) {
					if ( ! isset( $hooks[ $hook ] ) ) {
						$hooks[ $hook ] = array(
							'hook'     => $hook,
							'count'    => 0,
							'next'     => (int) $timestamp,
							'schedule' => isset( $event['schedule'] ) && $event['schedule'] ? $event['schedule'] : 'once',
						);
					}
					$hooks[ $hook ]['count']++;
					$hooks[ $hook ]['next'] = min( $hooks[ $hook ]['next'], (int) $timestamp );
				}
			}
		}

		$protected = self::protected_hooks();
		foreach ( $hooks as $hook => &$row ) {
			$row['protected'] = in_array( $hook, $protected, true );
			$row['empty']     = ! has_action( $hook );
			$row['unwanted']  = self::matches_unwanted( $hook );
		}
		unset( $row );

		// Flagged rows first.
		uasort( $hooks, function ( $a, $b ) {
			$fa = ( $a['empty'] || $a['unwanted'] ) && ! $a['protected'];
			$fb = ( $b['empty'] || $b['unwanted'] ) && ! $b['protected'];
			return $fa === $fb ? strcmp( $a['hook'], $b['hook'] ) : ( $fa ? -1 : 1 );
		} );
		return array_values( $hooks );
	}

	/**
	 * Hooks recommended for removal.
	 *
	 * @return string[]
	 */
	public static function flagged_hooks() {
		$out = array();
		foreach ( self::review() as $row ) {
			if ( ( $row['empty'] || $row['unwanted'] ) && ! $row['protected'] ) {
				$out[] = $row['hook'];
			}
		}
		return $out;
	}

	/**
	 * Unschedule every event for the given hooks.
	 *
	 * @param string[] $hooks Hook names.
	 * @return array{removed:int, skipped:string[]}
	 */
	public static function remove( array $hooks ) {
		$removed = 0;
		$skipped = array();
		$protected = self::protected_hooks();
		foreach ( $hooks as $hook ) {
			$hook = (string) $hook;
			if ( in_array( $hook, $protected, true ) ) {
				$skipped[] = $hook;
				continue;
			}
			$n = wp_unschedule_hook( $hook );
			if ( is_int( $n ) ) {
				$removed += $n;
			}
		}
		return array( 'removed' => $removed, 'skipped' => $skipped );
	}

	/**
	 * @param string $hook Hook name.
	 * @return bool
	 */
	public static function matches_unwanted( $hook ) {
		foreach ( self::unwanted_patterns() as $pattern ) {
			$regex = '/^' . str_replace( '\*', '.*', preg_quote( $pattern, '/' ) ) . '$/';
			if ( preg_match( $regex, $hook ) ) {
				return true;
			}
		}
		return false;
	}
}
