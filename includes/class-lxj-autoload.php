<?php
/**
 * Autoload review: largest autoloaded options, a best guess at which plugin owns each,
 * and a reversible "stop autoloading" action.
 */

defined( 'ABSPATH' ) || exit;

class LXJ_Autoload {

	const CHANGES_OPTION = 'lxjanitor_autoload_changes';

	/**
	 * Core options that must keep autoloading.
	 *
	 * @return string[]
	 */
	public static function core_options() {
		global $wpdb;
		return array(
			'cron', 'rewrite_rules', 'active_plugins', 'siteurl', 'home', 'template', 'stylesheet',
			'sidebars_widgets', 'uninstall_plugins', 'recently_activated', 'can_compress_scripts',
			'theme_mods_' . get_option( 'stylesheet' ), $wpdb->prefix . 'user_roles', 'blogname',
			'permalink_structure', 'db_version', 'initial_db_version', 'auto_core_update_notified',
		);
	}

	/**
	 * Largest autoloaded options with owner hints.
	 *
	 * @param int $limit Rows.
	 * @return array[] option_name, size, owner, owner_status (core|transient|active|inactive|not installed|unknown), changeable
	 */
	public static function review( $limit = 20 ) {
		$plugins = self::plugin_slugs();
		$core    = self::core_options();
		$rows    = array();
		foreach ( LXJ_Metrics::autoload_top( $limit ) as $r ) {
			$name = $r['option_name'];
			if ( in_array( $name, $core, true ) || 0 === strpos( $name, 'widget_' ) || 0 === strpos( $name, '_site_transient_' ) ) {
				$owner = array( 'name' => 'WordPress core', 'status' => 'core' );
			} elseif ( 0 === strpos( $name, '_transient_' ) ) {
				$owner = array( 'name' => 'Transient (cached data; regenerates if needed)', 'status' => 'transient' );
			} else {
				$owner = self::guess_owner( $name, $plugins );
			}
			$rows[] = array(
				'option_name'  => $name,
				'size'         => (int) $r['size'],
				'owner'        => $owner['name'],
				'owner_status' => $owner['status'],
				'changeable'   => 'core' !== $owner['status'],
			);
		}
		return $rows;
	}

	/**
	 * Stop autoloading the given options and remember their previous value for restore.
	 *
	 * @param string[] $names Option names.
	 * @return int Number changed.
	 */
	public static function disable( array $names ) {
		global $wpdb;
		$core    = self::core_options();
		$changes = (array) get_option( self::CHANGES_OPTION, array() );
		$changed = 0;
		$off     = function_exists( 'wp_autoload_values_to_autoload' ) ? 'off' : 'no'; // 'off' is WP 6.6+.
		foreach ( $names as $name ) {
			$name = (string) $name;
			if ( in_array( $name, $core, true ) ) {
				continue;
			}
			$current = $wpdb->get_var( $wpdb->prepare( "SELECT autoload FROM {$wpdb->options} WHERE option_name = %s", $name ) );
			if ( null === $current || ! in_array( $current, LXJ_Metrics::autoload_values(), true ) ) {
				continue;
			}
			if ( $wpdb->update( $wpdb->options, array( 'autoload' => $off ), array( 'option_name' => $name ) ) ) {
				if ( ! isset( $changes[ $name ] ) ) {
					$changes[ $name ] = $current;
				}
				$changed++;
			}
		}
		update_option( self::CHANGES_OPTION, $changes, false );
		wp_cache_delete( 'alloptions', 'options' );
		wp_cache_delete( 'notoptions', 'options' );
		return $changed;
	}

	/**
	 * Undo every autoload change this plugin made.
	 *
	 * @return int Number restored.
	 */
	public static function restore_all() {
		global $wpdb;
		$changes  = (array) get_option( self::CHANGES_OPTION, array() );
		$restored = 0;
		foreach ( $changes as $name => $original ) {
			if ( false !== $wpdb->update( $wpdb->options, array( 'autoload' => $original ), array( 'option_name' => $name ) ) ) {
				$restored++;
			}
		}
		delete_option( self::CHANGES_OPTION );
		wp_cache_delete( 'alloptions', 'options' );
		return $restored;
	}

	/**
	 * @return array Changed option name => original autoload value.
	 */
	public static function changes() {
		return (array) get_option( self::CHANGES_OPTION, array() );
	}

	/**
	 * Installed plugins keyed by a normalized slug.
	 *
	 * @return array slug => [name, active]
	 */
	private static function plugin_slugs() {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$out = array();
		foreach ( get_plugins() as $file => $data ) {
			$dir  = dirname( $file );
			$slug = '.' === $dir ? basename( $file, '.php' ) : $dir;
			$out[ self::norm( $slug ) ] = array(
				'name'   => $data['Name'],
				'active' => is_plugin_active( $file ),
			);
		}
		return $out;
	}

	/**
	 * Match an option name to a plugin by slug prefix (longest match wins).
	 *
	 * @param string $option  Option name.
	 * @param array  $plugins From plugin_slugs().
	 * @return array{name:string, status:string}
	 */
	private static function guess_owner( $option, array $plugins ) {
		$known = self::known_owner( $option );
		if ( $known ) {
			return $known;
		}
		$opt  = self::norm( $option );
		$best = null;
		$len  = 0;
		foreach ( $plugins as $slug => $p ) {
			// Compare on the first segment too, e.g. "wpseo" option vs "wordpress-seo" slug won't match; that is fine, it reads "unknown".
			$candidates = array( $slug, strtok( $slug, '_' ) );
			foreach ( $candidates as $c ) {
				if ( strlen( $c ) >= 4 && 0 === strpos( $opt, $c ) && strlen( $c ) > $len ) {
					$best = $p;
					$len  = strlen( $c );
				}
			}
		}
		if ( ! $best ) {
			return array( 'name' => 'Unknown (possibly a removed plugin)', 'status' => 'unknown' );
		}
		return array( 'name' => $best['name'], 'status' => $best['active'] ? 'active' : 'inactive' );
	}

	/**
	 * Option prefixes whose names don't resemble the plugin's folder slug, so slug matching misses them.
	 * Filterable with `lxjanitor_option_owners`.
	 *
	 * @return array[] prefix (string, case-insensitive), name, slugs (plugin folder slugs)
	 */
	public static function option_owners() {
		return apply_filters(
			'lxjanitor_option_owners',
			array(
				array(
					'prefix' => 'gtm4wp',
					'name'   => 'GTM4WP (Google Tag Manager for WordPress)',
					'slugs'  => array( 'duracelltomi-google-tag-manager' ),
				),
				array(
					'prefix' => 'wpmtst_',
					'name'   => 'Strong Testimonials',
					'slugs'  => array( 'strong-testimonials' ),
				),
				array(
					'prefix' => 'cleantalk',
					'name'   => 'CleanTalk Anti-Spam',
					'slugs'  => array( 'cleantalk-spam-protect' ),
				),
				array(
					'prefix' => 'rank-math',
					'name'   => 'Rank Math SEO',
					'slugs'  => array( 'seo-by-rank-math', 'seo-by-rank-math-pro' ),
				),
				array(
					'prefix' => 'rank_math',
					'name'   => 'Rank Math SEO',
					'slugs'  => array( 'seo-by-rank-math', 'seo-by-rank-math-pro' ),
				),
				array(
					'prefix' => 'ez-toc',
					'name'   => 'Easy Table of Contents',
					'slugs'  => array( 'easy-table-of-contents' ),
				),
				array(
					'prefix' => 'html-regression-track',
					'name'   => 'MainWP',
					'slugs'  => array( 'mainwp-child', 'mainwp-child-reports', 'mainwp' ),
				),
				array(
					'prefix' => 'mainwp',
					'name'   => 'MainWP',
					'slugs'  => array( 'mainwp-child', 'mainwp-child-reports', 'mainwp' ),
				),
				array(
					'prefix' => 'pys_',
					'name'   => 'PixelYourSite',
					'slugs'  => array( 'pixelyoursite', 'pixelyoursite-pro' ),
				),
				array(
					'prefix' => 'flying_press',
					'name'   => 'FlyingPress',
					'slugs'  => array( 'flying-press' ),
				),
			)
		);
	}

	/**
	 * @param string $option Option name.
	 * @return array{name:string, status:string}|null
	 */
	private static function known_owner( $option ) {
		foreach ( self::option_owners() as $o ) {
			if ( 0 === stripos( $option, $o['prefix'] ) ) {
				return array( 'name' => $o['name'], 'status' => LXJ_Tables::plugin_status( $o['slugs'] ) );
			}
		}
		return null;
	}

	private static function norm( $s ) {
		return strtolower( str_replace( array( '-', ' ' ), '_', $s ) );
	}
}
