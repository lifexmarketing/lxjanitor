<?php
/**
 * Self-hosted updates from GitHub Releases.
 *
 * WordPress checks for plugin updates twice a day (the wp_update_plugins cron event). On each check
 * this class reads the latest release of the GitHub repo, cached for 12 hours, so a site makes at most
 * two GitHub requests a day. Updates then install through the normal Plugins screen, WP-CLI
 * (wp plugin update lxjanitor), or WordPress auto-updates if enabled for this plugin.
 *
 * Release convention: tag vX.Y.Z matching the plugin's Version header, with an lxjanitor.zip asset
 * whose top-level folder is lxjanitor/ (built by .github/workflows/release.yml).
 */

defined( 'ABSPATH' ) || exit;

class LXJ_Updater {

	const REPO      = 'lifexmarketing/lxjanitor';
	const SLUG      = 'lxjanitor';
	const ASSET     = 'lxjanitor.zip';
	const CACHE_KEY = 'lxjanitor_release';
	const CACHE_TTL = 12 * HOUR_IN_SECONDS;

	public static function init() {
		if ( ! self::enabled() ) {
			return;
		}
		add_filter( 'pre_set_site_transient_update_plugins', array( __CLASS__, 'inject_update' ) );
		add_filter( 'plugins_api', array( __CLASS__, 'plugin_info' ), 20, 3 );
		add_filter( 'upgrader_source_selection', array( __CLASS__, 'fix_folder_name' ), 10, 4 );
		add_action( 'upgrader_process_complete', array( __CLASS__, 'clear_cache' ), 10, 0 );
	}

	/**
	 * Never update a development checkout (a git working copy, often symlinked into a local site):
	 * WordPress replaces the plugin folder during an update, which would delete the working tree.
	 *
	 * @return bool
	 */
	public static function enabled() {
		$dev = file_exists( LXJ_DIR . '.git' ) || is_link( untrailingslashit( LXJ_DIR ) );
		return (bool) apply_filters( 'lxjanitor_updater_enabled', ! $dev );
	}

	public static function basename() {
		return plugin_basename( LXJ_FILE );
	}

	/**
	 * Latest release, from cache or GitHub. Failures are cached for an hour so an outage
	 * does not turn every admin page load into a GitHub request.
	 *
	 * @param bool $force Skip the cache (the "Check again" button on Dashboard > Updates).
	 * @return array|null version, package, url, changelog, published
	 */
	public static function latest_release( $force = false ) {
		$cached = get_site_transient( self::CACHE_KEY );
		if ( ! $force && is_array( $cached ) ) {
			return $cached['release'];
		}

		$response = wp_remote_get(
			'https://api.github.com/repos/' . self::REPO . '/releases/latest',
			array(
				'timeout' => 10,
				'headers' => array(
					'Accept'     => 'application/vnd.github+json',
					'User-Agent' => 'LX-Janitor-Updater',
				),
			)
		);

		$release = null;
		if ( ! is_wp_error( $response ) && 200 === (int) wp_remote_retrieve_response_code( $response ) ) {
			$body    = json_decode( (string) wp_remote_retrieve_body( $response ), true );
			$version = isset( $body['tag_name'] ) ? ltrim( (string) $body['tag_name'], 'vV' ) : '';
			$package = '';
			foreach ( (array) ( $body['assets'] ?? array() ) as $asset ) {
				if ( isset( $asset['name'], $asset['browser_download_url'] ) && self::ASSET === $asset['name'] ) {
					$package = $asset['browser_download_url'];
				}
			}
			if ( '' === $package && ! empty( $body['zipball_url'] ) ) {
				$package = $body['zipball_url']; // Folder name is fixed in fix_folder_name().
			}
			if ( preg_match( '/^\d+(\.\d+)*$/', $version ) && '' !== $package && empty( $body['draft'] ) && empty( $body['prerelease'] ) ) {
				$release = array(
					'version'   => $version,
					'package'   => $package,
					'url'       => (string) ( $body['html_url'] ?? 'https://github.com/' . self::REPO ),
					'changelog' => (string) ( $body['body'] ?? '' ),
					'published' => (string) ( $body['published_at'] ?? '' ),
				);
			}
		}

		set_site_transient( self::CACHE_KEY, array( 'release' => $release ), $release ? self::CACHE_TTL : HOUR_IN_SECONDS );
		return $release;
	}

	/**
	 * Add this plugin to WordPress's update list when GitHub has a newer version.
	 *
	 * @param object $transient update_plugins transient.
	 * @return object
	 */
	public static function inject_update( $transient ) {
		if ( ! is_object( $transient ) ) {
			return $transient;
		}
		$force   = is_admin() && isset( $_GET['force-check'] ); // phpcs:ignore WordPress.Security.NonceVerification
		$release = self::latest_release( $force );
		if ( ! $release ) {
			return $transient;
		}

		$item = (object) array(
			'id'           => 'github.com/' . self::REPO,
			'slug'         => self::SLUG,
			'plugin'       => self::basename(),
			'new_version'  => $release['version'],
			'url'          => 'https://github.com/' . self::REPO,
			'package'      => $release['package'],
			'requires'     => '6.4',
			'requires_php' => '7.4',
			'icons'        => array(),
			'banners'      => array(),
		);

		if ( version_compare( $release['version'], LXJ_VERSION, '>' ) ) {
			$transient->response[ self::basename() ] = $item;
		} else {
			// Listing it as up to date is what enables the "Enable auto-updates" link.
			unset( $transient->response[ self::basename() ] );
			$transient->no_update[ self::basename() ] = $item;
		}
		return $transient;
	}

	/**
	 * "View details" popup on the Plugins screen.
	 *
	 * @param false|object|array $result Default result.
	 * @param string             $action plugins_api action.
	 * @param object             $args   Request args.
	 * @return false|object|array
	 */
	public static function plugin_info( $result, $action, $args ) {
		if ( 'plugin_information' !== $action || empty( $args->slug ) || self::SLUG !== $args->slug ) {
			return $result;
		}
		$release = self::latest_release();
		$version = $release ? $release['version'] : LXJ_VERSION;
		return (object) array(
			'name'          => 'LX Janitor',
			'slug'          => self::SLUG,
			'version'       => $version,
			'author'        => 'LifeX Marketing',
			'homepage'      => 'https://github.com/' . self::REPO,
			'requires'      => '6.4',
			'requires_php'  => '7.4',
			'last_updated'  => $release ? $release['published'] : '',
			'download_link' => $release ? $release['package'] : '',
			'sections'      => array(
				'description' => 'Database cleanup and speed audit for managed WordPress sites. Runs the database steps from the speed optimization guide and recommends follow-up actions it cannot safely do on its own.',
				'changelog'   => $release && '' !== trim( $release['changelog'] )
					? wpautop( esc_html( $release['changelog'] ) )
					: '<p>See <a href="https://github.com/' . self::REPO . '/releases">GitHub releases</a>.</p>',
			),
		);
	}

	/**
	 * GitHub's source zipball extracts to "owner-repo-sha/". Rename it to "lxjanitor/" so the update
	 * replaces this plugin instead of installing a second copy. The release asset is already correct.
	 *
	 * @param string      $source        Extracted folder.
	 * @param string      $remote_source Parent working folder.
	 * @param WP_Upgrader $upgrader      Upgrader.
	 * @param array       $hook_extra    Context; includes 'plugin' for plugin updates.
	 * @return string|WP_Error
	 */
	public static function fix_folder_name( $source, $remote_source, $upgrader, $hook_extra = array() ) {
		global $wp_filesystem;
		if ( empty( $hook_extra['plugin'] ) || self::basename() !== $hook_extra['plugin'] ) {
			return $source;
		}
		$wanted = trailingslashit( $remote_source ) . self::SLUG . '/';
		if ( trailingslashit( $source ) === $wanted ) {
			return $source;
		}
		if ( $wp_filesystem && $wp_filesystem->move( $source, $wanted, true ) ) {
			return $wanted;
		}
		return new WP_Error( 'lxj_rename_failed', 'LX Janitor update: could not rename the downloaded folder.' );
	}

	public static function clear_cache() {
		delete_site_transient( self::CACHE_KEY );
	}
}
