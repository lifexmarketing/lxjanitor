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
	 * @return array[] option_name, size, owner, owner_status (core|transient|active|inactive|not installed|active theme|inactive theme|theme not installed|unknown), changeable
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
	 * Installed plugins and themes that an option name can be matched against.
	 * A list rather than a map, because a plugin and a theme can share a slug.
	 *
	 * @return array[] slug (normalized), name, status (active|inactive|active theme|inactive theme)
	 */
	private static function plugin_slugs() {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$out = array();
		foreach ( get_plugins() as $file => $data ) {
			$dir   = dirname( $file );
			$slug  = '.' === $dir ? basename( $file, '.php' ) : $dir;
			$out[] = array(
				'slug'   => self::norm( $slug ),
				'name'   => $data['Name'],
				'status' => is_plugin_active( $file ) ? 'active' : 'inactive',
			);
		}
		foreach ( self::themes() as $stylesheet => $t ) {
			$out[] = array(
				'slug'   => self::norm( $stylesheet ),
				'name'   => $t['name'],
				'status' => $t['status'],
			);
		}
		return $out;
	}

	/**
	 * Installed themes keyed by folder name. The parent of an active child theme counts as active,
	 * because the child depends on it.
	 *
	 * @return array stylesheet => [name, status]
	 */
	private static function themes() {
		$active = array( get_stylesheet(), get_template() );
		$out    = array();
		foreach ( wp_get_themes() as $stylesheet => $theme ) {
			$out[ $stylesheet ] = array(
				'name'   => $theme->get( 'Name' ) . ' (theme)',
				'status' => in_array( $stylesheet, $active, true ) ? 'active theme' : 'inactive theme',
			);
		}
		return $out;
	}

	/**
	 * Customizer settings that WordPress stores per theme as theme_mods_{folder}. The active theme's
	 * copy is core (protected in core_options()); the rest belong to inactive or deleted themes.
	 *
	 * @param string $option Option name.
	 * @return array{name:string, status:string}|null
	 */
	private static function theme_mods_owner( $option ) {
		if ( 0 !== strpos( $option, 'theme_mods_' ) ) {
			return null;
		}
		$folder = substr( $option, strlen( 'theme_mods_' ) );
		if ( '' === $folder ) {
			return null;
		}
		$themes = self::themes();
		if ( isset( $themes[ $folder ] ) ) {
			// WordPress only reads the active (child) theme's copy; even an active parent's copy goes unused.
			if ( get_stylesheet() === $folder ) {
				return array( 'name' => 'Customizer settings for ' . $themes[ $folder ]['name'], 'status' => 'active theme' );
			}
			$note = get_template() === $folder ? ', not used while its child theme is active' : '';
			return array( 'name' => 'Customizer settings for ' . $themes[ $folder ]['name'] . $note, 'status' => 'inactive theme' );
		}
		return array( 'name' => 'Customizer settings for a deleted theme (' . $folder . ')', 'status' => 'theme not installed' );
	}

	/**
	 * Match an option name to a plugin or theme by slug prefix (longest match wins).
	 *
	 * @param string $option  Option name.
	 * @param array  $plugins From plugin_slugs() (plugins and themes).
	 * @return array{name:string, status:string}
	 */
	private static function guess_owner( $option, array $plugins ) {
		$known = self::theme_mods_owner( $option ) ?? self::known_owner( $option );
		if ( $known ) {
			return $known;
		}
		$opt  = self::norm( $option );
		$best = null;
		$len  = 0;
		foreach ( $plugins as $p ) {
			// Compare on the first segment too, e.g. "fusion_options" vs the "fusion-builder" slug.
			$candidates = array( $p['slug'], strtok( $p['slug'], '_' ) );
			foreach ( $candidates as $c ) {
				if ( strlen( $c ) >= 4 && 0 === strpos( $opt, $c ) && strlen( $c ) > $len ) {
					$best = $p;
					$len  = strlen( $c );
				}
			}
		}
		if ( ! $best ) {
			return array( 'name' => 'Unknown (possibly a removed plugin or theme)', 'status' => 'unknown' );
		}
		return array( 'name' => $best['name'], 'status' => $best['status'] );
	}

	/**
	 * Option prefixes whose names don't resemble the plugin's folder slug, so slug matching misses them.
	 * Filterable with `lxjanitor_option_owners`.
	 *
	 * @return array[] prefix (string, case-insensitive), name, slugs (plugin folder slugs), themes (theme folder slugs),
	 *                 status (optional fixed status, e.g. 'legacy', instead of checking plugins/themes)
	 */
	public static function option_owners() {
		return apply_filters(
			'lxjanitor_option_owners',
			array(
				// Common themes and page builders.
				array(
					'prefix' => 'avada',
					'name'   => 'Avada (ThemeFusion)',
					'slugs'  => array( 'fusion-builder', 'fusion-core' ),
					'themes' => array( 'Avada' ),
				),
				array(
					'prefix' => 'fusion_',
					'name'   => 'Avada / Avada Builder (ThemeFusion)',
					'slugs'  => array( 'fusion-builder', 'fusion-core' ),
					'themes' => array( 'Avada' ),
				),
				array(
					'prefix' => 'et_',
					'name'   => 'Divi / Extra / Divi Builder (Elegant Themes)',
					'slugs'  => array( 'divi-builder' ),
					'themes' => array( 'Divi', 'Extra' ),
				),
				array(
					'prefix' => 'genesis',
					'name'   => 'Genesis Framework (StudioPress)',
					'themes' => array( 'genesis' ),
				),
				array(
					'prefix' => 'flatsome',
					'name'   => 'Flatsome (UX Themes)',
					'themes' => array( 'flatsome' ),
				),
				array(
					'prefix' => 'betheme',
					'name'   => 'BeTheme (Muffin Group)',
					'themes' => array( 'betheme' ),
				),
				array(
					'prefix' => 'mfn',
					'name'   => 'BeTheme / Muffin Builder (Muffin Group)',
					'themes' => array( 'betheme' ),
				),
				array(
					'prefix' => 'elementor',
					'name'   => 'Elementor',
					'slugs'  => array( 'elementor', 'elementor-pro' ),
					'themes' => array( 'hello-elementor' ),
				),
				array(
					'prefix' => '_elementor',
					'name'   => 'Elementor',
					'slugs'  => array( 'elementor', 'elementor-pro' ),
					'themes' => array( 'hello-elementor' ),
				),
				array(
					'prefix' => 'wpb_js',
					'name'   => 'WPBakery Page Builder',
					'slugs'  => array( 'js_composer' ),
				),
				array(
					// MonsterInsights reuses this plugin's folder name, so a plugin check would wrongly say "active".
					'prefix' => 'yst_ga',
					'name'   => 'Google Analytics by Yoast (pre-2016, replaced by MonsterInsights). Nothing reads these anymore; safe to delete. May hold old Google sign-in tokens.',
					'status' => 'legacy',
				),
				array(
					'prefix' => 'monsterinsights',
					'name'   => 'MonsterInsights',
					'slugs'  => array( 'google-analytics-for-wordpress', 'google-analytics-premium' ),
				),
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
					'prefix' => 'wpseo',
					'name'   => 'Yoast SEO',
					'slugs'  => array( 'wordpress-seo', 'wordpress-seo-premium', 'wpseo-local', 'wordpress-seo-local' ),
				),
				array(
					'prefix' => 'yoast',
					'name'   => 'Yoast SEO',
					'slugs'  => array( 'wordpress-seo', 'wordpress-seo-premium' ),
				),
				array(
					'prefix' => 'wpgmza',
					'name'   => 'WP Go Maps (formerly WP Google Maps)',
					'slugs'  => array( 'wp-google-maps', 'wp-google-maps-pro' ),
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
				$status = $o['status'] ?? self::owner_status( $o['slugs'] ?? array(), $o['themes'] ?? array() );
				return array( 'name' => $o['name'], 'status' => $status );
			}
		}
		return null;
	}

	/**
	 * Status of an owner that may be a plugin, a theme, or both (e.g. Divi ships as both).
	 * Active beats inactive beats not installed.
	 *
	 * @param string[] $plugin_slugs Plugin folder names.
	 * @param string[] $theme_slugs  Theme folder names (case-insensitive).
	 * @return string
	 */
	private static function owner_status( array $plugin_slugs, array $theme_slugs ) {
		$plugin = $plugin_slugs ? LXJ_Tables::plugin_status( $plugin_slugs ) : 'not installed';
		$theme  = 'theme not installed';
		foreach ( self::themes() as $folder => $t ) {
			foreach ( $theme_slugs as $slug ) {
				if ( 0 !== strcasecmp( $folder, $slug ) ) {
					continue;
				}
				if ( 'active theme' === $t['status'] || 'theme not installed' === $theme ) {
					$theme = $t['status']; // An active match wins; otherwise the first installed one.
				}
			}
		}
		foreach ( array( 'active' => $plugin, 'active theme' => $theme, 'inactive' => $plugin, 'inactive theme' => $theme ) as $want => $have ) {
			if ( $want === $have ) {
				return $have;
			}
		}
		return $theme_slugs && ! $plugin_slugs ? 'theme not installed' : 'not installed';
	}

	private static function norm( $s ) {
		return strtolower( str_replace( array( '-', ' ' ), '_', $s ) );
	}
}
