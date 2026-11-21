<?php
/**
 * Recommendations for follow-up work the plugin should not do on its own:
 * plugin replacements, caching configuration, Perfmatters settings, image delivery, etc.
 */

defined( 'ABSPATH' ) || exit;

class LXJ_Advisor {

	const PROBE_TRANSIENT = 'lxjanitor_probe';

	/** Log/queue tables (woocommerce_log, Action Scheduler) are flagged at 50 MB, or 100,000 rows when the host hides table sizes. */
	const BIG_TABLE_BYTES = 52428800;
	const BIG_TABLE_ROWS  = 100000;

	/**
	 * Plugin replacements from the guide.
	 * Matched by plugin folder slug or by a case-insensitive substring of the plugin name.
	 *
	 * @return array[]
	 */
	public static function replacements() {
		return apply_filters(
			'lxjanitor_replacements',
			array(
				array(
					'id'       => 'pixelyoursite',
					'slugs'    => array( 'pixelyoursite', 'pixelyoursite-pro' ),
					'names'    => array( 'PixelYourSite' ),
					'severity' => 'high',
					'replace'  => 'MetaTrac',
					'why'      => 'PixelYourSite has been confirmed to bypass page caching: its PHP session option sends Set-Cookie with no-store on every page, and its advanced matching/CAPI feature sets a unique _fbp cookie per request (100% cache bypass measured on a client site). MetaTrac is the cache-friendly replacement.',
				),
				array(
					'id'       => 'meta-pixel',
					'slugs'    => array( 'official-facebook-pixel', 'facebook-for-woocommerce' ),
					'names'    => array( 'Meta pixel', 'Facebook Pixel', 'Facebook for WooCommerce', 'Meta for WooCommerce' ),
					'severity' => 'medium',
					'replace'  => 'MetaTrac',
					'why'      => 'The guide lists the Meta plugin for replacement with MetaTrac. If this is Facebook/Meta for WooCommerce, confirm catalog sync is not in use before removing it.',
				),
				array(
					'id'       => 'permalink-manager',
					'slugs'    => array( 'permalink-manager' ),
					'names'    => array( 'Permalink Manager' ),
					'severity' => 'medium',
					'replace'  => 'WP Blog Permafix',
					'why'      => 'Lighter replacement for the custom permalink use case. Export any custom permalinks first so redirects are not lost.',
				),
				array(
					'id'       => 'bb-sticky',
					'slugs'    => array(),
					'names'    => array( 'Sticky Section', 'Sticky Row', 'Sticky Header for Beaver' ),
					'severity' => 'low',
					'replace'  => 'a CSS snippet plus a class on each sticky row',
					'why'      => 'A whole plugin for sticky positioning adds a request and PHP overhead for something CSS (position: sticky) handles natively.',
				),
				array(
					'id'       => 'handl-utm',
					'slugs'    => array( 'handl-utm-grabber', 'handl-utm-grabber-v3' ),
					'names'    => array( 'HandL UTM Grabber' ),
					'severity' => 'medium',
					'replace'  => 'Trace IDs',
					'why'      => 'UTM capture plugins commonly set cookies server-side, which can bypass page cache. Trace IDs is the cache-friendly replacement.',
				),
			)
		);
	}

	/**
	 * Build all recommendations.
	 *
	 * @param bool $fresh_probe Re-request the home page instead of using the cached probe.
	 * @return array[] id, severity (high|medium|low|info), title, detail, action
	 */
	public static function recommendations( $fresh_probe = false ) {
		$recs    = array();
		$plugins = self::plugins();
		$probe   = self::probe( $fresh_probe );

		// 1. Plugin replacements.
		foreach ( self::replacements() as $r ) {
			$found = self::find_plugin( $plugins, $r['slugs'], $r['names'] );
			if ( ! $found ) {
				continue;
			}
			$recs[] = array(
				'id'       => 'replace-' . $r['id'],
				'severity' => $found['active'] ? $r['severity'] : 'low',
				'title'    => sprintf( 'Replace %s with %s', $found['name'], $r['replace'] ),
				'detail'   => $r['why'] . ( $found['active'] ? '' : ' (Installed but inactive: delete it.)' ),
				'action'   => 'Get client approval if tracking or URLs are affected, install the replacement, verify events/URLs still work, then remove the old plugin.',
			);
		}

		// 2. Cache-busting cookies and headers on an anonymous home page request.
		if ( ! empty( $probe['error'] ) ) {
			$recs[] = array(
				'id'       => 'probe-failed',
				'severity' => 'info',
				'title'    => 'Could not request the home page from the server',
				'detail'   => 'Loopback request failed: ' . $probe['error'] . '. Cache and front-end checks were skipped.',
				'action'   => 'Run PageSpeed Insights and the /webdev:cache-tracking-check prompt manually.',
			);
		} else {
			if ( $probe['set_cookie'] ) {
				$hints = array();
				foreach ( $probe['set_cookie'] as $c ) {
					if ( 0 === stripos( $c, 'PHPSESSID' ) ) {
						$hints[] = 'PHPSESSID usually means a plugin started a PHP session (PixelYourSite\'s PHP session option is a known cause).';
					}
					if ( 0 === stripos( $c, '_fbp' ) ) {
						$hints[] = '_fbp set server-side is PixelYourSite\'s advanced matching/CAPI behavior.';
					}
				}
				$recs[] = array(
					'id'       => 'set-cookie',
					'severity' => 'high',
					'title'    => 'Anonymous visitors receive cookies, which bypasses page cache',
					'detail'   => 'Set-Cookie on the home page: ' . implode( ', ', $probe['set_cookie'] ) . '. ' . implode( ' ', array_unique( $hints ) ),
					'action'   => 'Find the plugin setting the cookie and turn that feature off or replace the plugin. Retest with the "Re-check home page" button.',
				);
			}
			if ( preg_match( '/no-store|private|no-cache|max-age=0/i', $probe['cache_control'] ) ) {
				$recs[] = array(
					'id'       => 'cache-control',
					'severity' => 'high',
					'title'    => 'Home page tells caches not to store it',
					'detail'   => 'Cache-Control: ' . $probe['cache_control'],
					'action'   => 'Identify what sends this header (often a session or cookie-setting plugin) and fix it, then confirm a cache HIT on a second request.',
				);
			}
			if ( ! $probe['cache_hit'] ) {
				$recs[] = array(
					'id'       => 'no-cache-hit',
					'severity' => 'medium',
					'title'    => 'No page cache hit detected on a repeat request',
					'detail'   => 'Cache headers seen: ' . ( $probe['cache_headers'] ? self::kv( $probe['cache_headers'] ) : 'none' ) . '.'
						. ( $probe['cloudflare'] ? ' On every Cloudflare plan, including free, Cloudflare caches only static files by default (cf-cache-status: DYNAMIC on pages). Caching full HTML at the edge needs an integration that also purges it, such as FlyingPress\'s Cloudflare integration (works on the free plan), WP Rocket\'s, or Cloudflare APO.' : '' ),
					'action'   => 'Run /webdev:cache-tracking-check and name the host and cache layers (Varnish, SmartCache, Bat Cache, Redis, WP Rocket, FlyingPress, Cloudflare).',
				);
			}
			if ( $probe['google_fonts'] ) {
				$recs[] = array(
					'id'       => 'google-fonts',
					'severity' => 'medium',
					'title'    => 'Google Fonts load from Google\'s servers',
					'detail'   => 'The home page references fonts.googleapis.com or fonts.gstatic.com.',
					'action'   => 'In Perfmatters > Fonts, enable local Google Fonts with display swap and limit the subset to Latin.',
				);
			}
			if ( $probe['legacy_images'] > 0 && ! self::is_active( $plugins, array( 'imagify' ) ) ) {
				$recs[] = array(
					'id'       => 'images',
					'severity' => 'medium',
					'title'    => 'Images are served as JPG/PNG',
					'detail'   => sprintf( 'Found %d JPG/PNG references and %d WebP/AVIF references on the home page, and Imagify is not active.', $probe['legacy_images'], $probe['modern_images'] ),
					'action'   => 'Set up Imagify WebP conversion. Background images and Beaver Builder cache files are usually excluded; see the Improve image delivery section of the guide.',
				);
			}

			// Purge chain (guide: WordPress Caching).
			$varnish    = isset( $probe['cache_headers']['x-varnish'] ) || ( isset( $probe['cache_headers']['via'] ) && false !== stripos( $probe['cache_headers']['via'], 'varnish' ) );
			$cloudflare = $probe['cloudflare'];
			if ( $varnish && self::is_active( $plugins, array( 'bb-plugin', 'beaver-builder-lite-version' ) ) ) {
				$recs[] = array(
					'id'       => 'bb-varnish',
					'severity' => 'medium',
					'title'    => 'Varnish + Beaver Builder: confirm proxy cache clearing is on',
					'detail'   => 'Beaver Builder can purge Varnish directly on save, with no cache plugin needed (confirmed on client sites).',
					'action'   => 'Settings > Beaver Builder > Tools > Cache Clearing Tool: enable proxy cache clearing.',
				);
			}
			if ( $varnish && self::is_active( $plugins, array( 'elementor', 'js_composer' ) ) ) {
				$recs[] = array(
					'id'       => 'builder-varnish',
					'severity' => 'medium',
					'title'    => 'Varnish + Elementor/WPBakery: no built-in purge',
					'detail'   => 'Neither builder ships a Varnish purge option.',
					'action'   => 'Enable Breeze Auto Purge Varnish, or add a purge hook on the builder\'s save action.',
				);
			}
			if ( $cloudflare && $probe['cf_caches_html'] && ! self::has_cloudflare_purge( $plugins ) ) {
				$recs[] = array(
					'id'       => 'cf-purge',
					'severity' => 'high',
					'title'    => 'Cloudflare caches HTML but nothing purges it',
					'detail'   => 'Varnish purge plugins (Proxy Cache Purge, Breeze) cannot reach Cloudflare\'s edge. Breeze\'s Cloudflare purge only works with Cloudways\' Cloudflare Enterprise add-on.',
					'action'   => 'Use a cache plugin\'s Cloudflare integration that purges on content updates (FlyingPress, on any Cloudflare plan including free; or WP Rocket), or install a Cloudflare purge plugin configured with the Zone ID and an API token (confirmed working on a client site).',
				);
			}

			// FlyingPress can cache full HTML at Cloudflare's edge and purge it on updates (docs.flyingpress.com, Cloudflare integration guide).
			$flyingpress = self::is_active( $plugins, array( 'flying-press' ) );
			if ( $flyingpress && $cloudflare && ! empty( $probe['cf_apo'] ) ) {
				$recs[] = array(
					'id'       => 'flyingpress-apo',
					'severity' => 'high',
					'title'    => 'Cloudflare APO is on alongside FlyingPress',
					'detail'   => 'The home page was served through Cloudflare APO. FlyingPress\'s guide says to disable APO before enabling its Cloudflare integration, because the two conflict over who caches and purges HTML.',
					'action'   => 'Turn off APO (Cloudflare dashboard > Speed > Optimization, or the Cloudflare plugin), then enable FlyingPress\'s Cloudflare page caching. Recheck with the "Re-check home page" button: the second request should show cf-cache-status: HIT.',
				);
			} elseif ( $flyingpress && $cloudflare && ! $probe['cf_caches_html'] ) {
				$recs[] = array(
					'id'       => 'flyingpress-cloudflare',
					'severity' => 'medium',
					'title'    => 'FlyingPress could cache full pages at Cloudflare\'s edge, but it isn\'t',
					'detail'   => sprintf( 'The site is behind Cloudflare and runs FlyingPress, but Cloudflare is not caching HTML (cf-cache-status: %s). FlyingPress\'s Cloudflare integration caches full HTML at the edge on every Cloudflare plan, including free, and purges it automatically when content is updated. It also carries FlyingPress\'s exclusions, ignored query strings and bypass cookies over to Cloudflare; logged-in users always bypass.', ! empty( $probe['cf_cache_status'] ) ? $probe['cf_cache_status'] : 'none' ),
					'action'   => 'In FlyingPress, connect Cloudflare with an Account API Token that has Zone (Read & Edit), Cache Purge, Cache Settings (Read & Edit) and Zone Transform Rules (Read & Edit), then enable page caching. Before enabling: confirm the DNS record is proxied (orange cloud) and Cloudflare APO is off. Verify with "Re-check home page": the first request after a purge is a MISS, the next should be a HIT.',
				);
			}
		}

		// 3. Object cache.
		if ( ! wp_using_ext_object_cache() ) {
			$recs[] = array(
				'id'       => 'object-cache',
				'severity' => 'medium',
				'title'    => 'No persistent object cache',
				'detail'   => 'Database query results are rebuilt on every uncached request.',
				'action'   => 'Enable Redis (available on some Wordify plans) or memcache (included on Pressable) at the host.',
			);
		}

		// 4. Autoload.
		$autoload = LXJ_Metrics::autoload_bytes();
		if ( $autoload > LXJ_Metrics::AUTOLOAD_LIMIT ) {
			$top    = LXJ_Metrics::autoload_top( 3 );
			$recs[] = array(
				'id'       => 'autoload',
				'severity' => 'high',
				'title'    => sprintf( 'Autoloaded options total %s (target is under 500 KB)', lxj_bytes( $autoload ) ),
				'detail'   => 'Largest: ' . implode( ', ', array_map( function ( $r ) {
					return $r['option_name'] . ' (' . lxj_bytes( $r['size'] ) . ')';
				}, $top ) ),
				'action'   => 'Review the Autoload tab. Options from removed plugins can stop autoloading; options from active plugins may need the plugin optimized or removed, which can require client approval.',
			);
		}

		// 5. Leftover tables.
		$removable = array();
		$orphaned  = array();
		$unknown   = null;
		foreach ( LXJ_Tables::review() as $g ) {
			if ( 'unknown' === $g['id'] ) {
				$unknown = $g;
			} elseif ( $g['removable'] && ! $g['blocked'] && ( 'not installed' === $g['status'] || 'gravityforms-legacy' === $g['id'] ) ) {
				// Leftovers: the plugin is gone (Gravity Forms legacy tables are leftovers even with Gravity Forms installed).
				$held        = LXJ_Tables::describe_data( $g['data'] );
				$removable[] = $g['name'] . ' (' . lxj_bytes( $g['size'] ) . ( $held ? ', contains ' . $held : '' ) . ')';
				if ( $held ) {
					$orphaned[] = $g['name'];
				}
			}
		}
		if ( $removable ) {
			$recs[] = array(
				'id'       => 'removable-tables',
				'severity' => 'medium',
				'title'    => 'Leftover tables from plugins that are no longer installed',
				'detail'   => implode( '; ', $removable ),
				'action'   => 'Confirm the plugin will not be reinstalled, take a backup, then remove these on the Tables tab.'
					. ( $orphaned ? ' Business data is still stored in: ' . implode( ', ', $orphaned ) . '. Ask the client whether to export it first.' : '' ),
			);
		}
		if ( $unknown ) {
			$big = array_filter( $unknown['tables'], function ( $t ) {
				return $t['total'] >= MB_IN_BYTES;
			} );
			if ( $big ) {
				$recs[] = array(
					'id'       => 'unknown-tables',
					'severity' => 'low',
					'title'    => sprintf( '%d tables over 1 MB with an unknown owner', count( $big ) ),
					'detail'   => implode( ', ', array_map( function ( $t ) {
						return $t['name'] . ' (' . lxj_bytes( $t['total'] ) . ')';
					}, $big ) ),
					'action'   => 'Identify the owning plugin before removing anything.',
				);
			}
		}

		// 5b. Wordfence firewall still hooked into PHP after Wordfence is gone: deleting its file would take the site down.
		$prepend = (string) ini_get( 'auto_prepend_file' );
		if ( false !== stripos( $prepend, 'wordfence-waf.php' ) && 'active' !== LXJ_Tables::plugin_status( array( 'wordfence' ) ) ) {
			$recs[] = array(
				'id'       => 'wordfence-waf',
				'severity' => 'high',
				'title'    => 'Wordfence firewall is still loaded on every request',
				'detail'   => 'PHP auto_prepend_file points to ' . $prepend . ' but Wordfence is not active. If that file is deleted, every page will fatal error.',
				'action'   => 'Remove the auto_prepend_file line from .user.ini, .htaccess or php.ini (wait for .user.ini cache, up to 5 minutes), then delete wordfence-waf.php and wp-content/wflogs.',
			);
		} elseif ( file_exists( ABSPATH . 'wordfence-waf.php' ) && 'not installed' === LXJ_Tables::plugin_status( array( 'wordfence' ) ) ) {
			$recs[] = array(
				'id'       => 'wordfence-files',
				'severity' => 'low',
				'title'    => 'Wordfence firewall files left behind',
				'detail'   => 'wordfence-waf.php is in the site root but Wordfence is not installed, and PHP is not loading it.',
				'action'   => 'Delete wordfence-waf.php and wp-content/wflogs.',
			);
		}

		// 6. Large Action Scheduler backlog that survives cleanup.
		$as = LXJ_Metrics::action_scheduler();
		if ( $as && ( $as['pending'] ?? 0 ) > 5000 ) {
			$recs[] = array(
				'id'       => 'as-pending',
				'severity' => 'medium',
				'title'    => sprintf( '%s pending scheduled actions', number_format_i18n( $as['pending'] ) ),
				'detail'   => 'A large pending queue usually means WP-Cron is not running reliably or a plugin is queuing faster than it processes.',
				'action'   => 'Check that a real server cron hits wp-cron.php, then look at which hook is filling the queue (Tools > Scheduled Actions).',
			);
		}

		// 6a. Oversized Action Scheduler tables, with the hooks responsible.
		$as_rec = self::action_scheduler_size_check( $as );
		if ( $as_rec ) {
			$recs[] = $as_rec;
		}

		// 6b. Oversized WooCommerce database log.
		$wcl = LXJ_Metrics::woocommerce_log();
		if ( $wcl && self::is_big( $wcl['size'], $wcl['rows'] ) ) {
			$days   = $wcl['retention_days'];
			$detail = sprintf( '%s holds %s entries (%s), from %s to %s UTC. ', $wcl['table'], number_format_i18n( $wcl['rows'] ), lxj_bytes( $wcl['size'] ), $wcl['oldest'], $wcl['newest'] );
			if ( 'database' === $wcl['handler'] ) {
				$detail .= sprintf( 'WooCommerce logs to the database and should keep only %d days.', $days );
				if ( $wcl['oldest'] && strtotime( $wcl['oldest'] . ' UTC' ) < time() - ( $days + 2 ) * DAY_IN_SECONDS ) {
					$detail .= ' Entries older than that are still here, so WooCommerce\'s daily log cleanup is not running (check WP-Cron and the woocommerce_cleanup_logs scheduled action).';
				}
				$action = 'Run "WooCommerce database log" on the Database tab after a backup. Unless something needs database logging, switch WooCommerce > Status > Logs > Settings to file system storage, and turn off debug logging in whichever plugin wrote most of the entries.';
			} else {
				$detail .= 'WooCommerce is not logging to the database, so these entries are left over from when it did. WooCommerce never cleans this table after the switch.';
				if ( $wcl['newest'] && strtotime( $wcl['newest'] . ' UTC' ) > time() - 2 * DAY_IN_SECONDS ) {
					$detail .= ' Recent entries are still being added, so a plugin is writing to this table directly.';
				}
				$action = sprintf( 'Run "WooCommerce database log" on the Database tab after a backup. It keeps the last %d days, and empties the table outright when every entry is older. To learn what filled it first: SELECT source, COUNT(*) FROM %s GROUP BY source ORDER BY 2 DESC LIMIT 10;', $days, $wcl['table'] );
			}
			$recs[] = array(
				'id'       => 'woocommerce-log',
				'severity' => 'medium',
				'title'    => sprintf( 'WooCommerce database log holds %s entries (about %s)', number_format_i18n( $wcl['rows'] ), lxj_bytes( $wcl['size'] ) ),
				'detail'   => $detail,
				'action'   => $action,
			);
		}

		// 7. Perfmatters.
		$recs = array_merge( $recs, self::perfmatters_checks( $plugins ) );

		// 8. Tool plugins this one replaces.
		foreach ( array( 'wp-optimize' => 'WP-Optimize', 'wp-crontrol' => 'WP Crontrol' ) as $slug => $label ) {
			if ( self::find_plugin( $plugins, array( $slug ), array() ) ) {
				$recs[] = array(
					'id'       => 'superseded-' . $slug,
					'severity' => 'low',
					'title'    => $label . ' is no longer needed',
					'detail'   => 'LX Janitor covers the cleanup steps the guide used it for.',
					'action'   => 'Deactivate and delete it once the cleanup is done.',
				);
			}
		}

		$order = array( 'high' => 0, 'medium' => 1, 'low' => 2, 'info' => 3 );
		usort( $recs, function ( $a, $b ) use ( $order ) {
			return $order[ $a['severity'] ] - $order[ $b['severity'] ];
		} );
		return $recs;
	}

	/**
	 * Manual steps from the guide that cannot be detected or automated.
	 *
	 * @return string[]
	 */
	public static function manual_steps() {
		return array(
			'Confirm the site is in Client Site Monitor and has a baseline scan before changing anything.',
			'Record Query Monitor generation time on the home page and one admin page, before and after.',
			'Take a full backup before running cleanup.',
			'Perfmatters Script Manager: test unloading scripts on the home page first, then roll out per post type.',
			'Fix "image file is larger than it needs to be" by choosing a smaller display size in the page builder (medium or medium-large for backgrounds).',
			'After site-wide changes: clear caches, test in a private window, rerun PageSpeed Insights, and ask Client Site Monitor to rescan.',
		);
	}

	/* ---------------------------------------------------------------------
	 * Home page probe
	 * ------------------------------------------------------------------- */

	/**
	 * Request the home page twice as an anonymous visitor and record what the cache layers report.
	 *
	 * @param bool $fresh Ignore the 10-minute cached result.
	 * @return array
	 */
	public static function probe( $fresh = false ) {
		if ( ! $fresh ) {
			$cached = get_transient( self::PROBE_TRANSIENT );
			if ( is_array( $cached ) ) {
				return $cached;
			}
		}
		$args  = array(
			'timeout'     => 20,
			'redirection' => 3,
			'sslverify'   => false,
			'cookies'     => array(),
			'user-agent'  => 'Mozilla/5.0 (LX Janitor cache probe)',
		);
		$url   = home_url( '/' );
		$first = wp_remote_get( $url, $args );
		if ( is_wp_error( $first ) ) {
			$result = array( 'error' => $first->get_error_message() );
			set_transient( self::PROBE_TRANSIENT, $result, 10 * MINUTE_IN_SECONDS );
			return $result;
		}
		$second = wp_remote_get( $url, $args );
		$final  = is_wp_error( $second ) ? $first : $second;

		$headers       = self::lower_headers( wp_remote_retrieve_headers( $final ) );
		$cache_headers = array_intersect_key(
			$headers,
			array_flip( array( 'x-cache', 'x-cache-status', 'x-varnish', 'via', 'age', 'cf-cache-status', 'x-proxy-cache', 'x-nginx-cache', 'x-ac', 'x-litespeed-cache', 'x-rocket-nginx-serving-static', 'x-wp-cf-super-cache', 'cf-apo-via', 'cf-edge-cache' ) )
		);

		$hit = false;
		foreach ( $cache_headers as $name => $value ) {
			if ( preg_match( '/\bhit\b/i', $value ) ) {
				$hit = true;
			}
		}
		if ( isset( $cache_headers['x-varnish'] ) && preg_match( '/^\d+\s+\d+$/', trim( $cache_headers['x-varnish'] ) ) ) {
			$hit = true; // Two IDs = served from Varnish.
		}
		if ( isset( $cache_headers['age'] ) && (int) $cache_headers['age'] > 0 ) {
			$hit = true;
		}

		$cookies = array();
		foreach ( array( $first, $final ) as $res ) {
			$raw = wp_remote_retrieve_header( $res, 'set-cookie' );
			foreach ( (array) $raw as $line ) {
				if ( $line ) {
					$cookies[] = trim( strtok( $line, '=' ) );
				}
			}
		}

		$html = (string) wp_remote_retrieve_body( $final );
		preg_match_all( '/\.(?:jpe?g|png)(?:\?[^"\'\s)]*)?["\'\s)]/i', $html, $legacy );
		preg_match_all( '/\.(?:webp|avif)(?:\?[^"\'\s)]*)?["\'\s)]/i', $html, $modern );

		$cf_status = isset( $headers['cf-cache-status'] ) ? strtoupper( $headers['cf-cache-status'] ) : '';
		$result    = array(
			'error'          => null,
			'status'         => (int) wp_remote_retrieve_response_code( $final ),
			'cache_control'  => isset( $headers['cache-control'] ) ? $headers['cache-control'] : '',
			'cache_headers'  => $cache_headers,
			'cache_hit'      => $hit,
			'set_cookie'     => array_values( array_unique( array_filter( $cookies ) ) ),
			'cloudflare'     => isset( $headers['cf-ray'] ),
			'cf_caches_html' => in_array( $cf_status, array( 'HIT', 'MISS', 'EXPIRED', 'REVALIDATED', 'UPDATING', 'STALE' ), true ),
			'cf_cache_status' => $cf_status,
			// Cloudflare APO marks the responses it handles.
			'cf_apo'         => isset( $headers['cf-apo-via'] ) || ( isset( $headers['cf-edge-cache'] ) && false !== stripos( $headers['cf-edge-cache'], 'platform=wordpress' ) ),
			'google_fonts'   => (bool) preg_match( '#fonts\.(googleapis|gstatic)\.com#i', $html ),
			'legacy_images'  => count( $legacy[0] ),
			'modern_images'  => count( $modern[0] ),
			'checked_at'     => time(),
		);
		set_transient( self::PROBE_TRANSIENT, $result, 10 * MINUTE_IN_SECONDS );
		return $result;
	}

	/* ---------------------------------------------------------------------
	 * Helpers
	 * ------------------------------------------------------------------- */

	private static function is_big( $bytes, $rows ) {
		return $bytes >= self::BIG_TABLE_BYTES || $rows >= self::BIG_TABLE_ROWS;
	}

	/**
	 * Flag large actionscheduler_actions / actionscheduler_logs tables and name the hooks filling them.
	 *
	 * @param array|null $as Counts from LXJ_Metrics::action_scheduler().
	 * @return array|null Recommendation.
	 */
	private static function action_scheduler_size_check( $as ) {
		global $wpdb;
		if ( ! $as ) {
			return null;
		}
		$actions_table = $wpdb->prefix . 'actionscheduler_actions';
		$logs_table    = $wpdb->prefix . 'actionscheduler_logs';
		$action_rows   = array_sum( array_diff_key( $as, array( '_logs' => 1 ) ) );
		$log_rows      = (int) ( $as['_logs'] ?? 0 );
		$action_size   = LXJ_Metrics::table_size( $actions_table );
		$log_size      = LXJ_Metrics::table_size( $logs_table );
		$big_actions   = self::is_big( $action_size, $action_rows );
		$big_logs      = isset( $as['_logs'] ) && self::is_big( $log_size, $log_rows );
		if ( ! $big_actions && ! $big_logs ) {
			return null;
		}

		$sizes = array();
		if ( $big_actions ) {
			$sizes[] = sprintf( '%s has %s rows (about %s)', $actions_table, number_format_i18n( $action_rows ), lxj_bytes( $action_size ) );
		}
		if ( $big_logs ) {
			$sizes[] = sprintf( '%s has %s rows (about %s)', $logs_table, number_format_i18n( $log_rows ), lxj_bytes( $log_size ) );
		}
		$detail = implode( '; ', $sizes ) . '.';
		$days   = LXJ_Cleaner::AS_RETENTION_DAYS;
		$advice = array();

		if ( $big_actions ) {
			$parts         = array();
			$recent        = 0;
			$busiest       = null;
			$mostly_failed = array();
			foreach ( LXJ_Metrics::action_scheduler_top_hooks( 3 ) as $h ) {
				$bits = array();
				foreach ( array( 'complete', 'failed', 'canceled', 'pending' ) as $status ) {
					if ( (int) $h[ $status ] ) {
						$bits[] = number_format_i18n( (int) $h[ $status ] ) . ' ' . $status;
					}
				}
				$parts[] = sprintf( '%s (%s: %s)', $h['hook'], number_format_i18n( (int) $h['total'] ), implode( ', ', $bits ) );
				$recent += (int) $h['recent_complete'];
				if ( ! $busiest || (int) $h['recent_complete'] > (int) $busiest['recent_complete'] ) {
					$busiest = $h;
				}
				if ( (int) $h['failed'] * 2 > (int) $h['total'] ) {
					$mostly_failed[] = $h['hook'];
				}
			}
			$detail .= ' Most actions come from: ' . implode( '; ', $parts ) . '.';
			if ( $recent * 2 >= $action_rows ) {
				$detail  .= sprintf(
					' %s of these actions completed within the last %d days (%s from %s), so cleanup cannot remove them yet. %s is running far more often than most sites need.',
					number_format_i18n( $recent ),
					$days,
					number_format_i18n( (int) $busiest['recent_complete'] ),
					$busiest['hook'],
					$busiest['hook']
				);
				$advice[] = sprintf( 'Find the plugin behind %s (Tools > Scheduled Actions, search for the hook) and reduce how often it runs or turn the feature off.', $busiest['hook'] );
			}
			if ( $mostly_failed ) {
				$detail  .= ' Mostly failing: ' . implode( ', ', $mostly_failed ) . '.';
				$advice[] = 'Open a failed action for ' . $mostly_failed[0] . ' in Tools > Scheduled Actions and read its log to see why it fails; fix that rather than only deleting the failures.';
			}
		}

		if ( $big_logs ) {
			$parts = array();
			foreach ( LXJ_Metrics::action_scheduler_top_log_hooks( 3 ) as $h ) {
				$per     = (int) $h['actions'] ? (int) $h['logs'] / (int) $h['actions'] : 0;
				$parts[] = sprintf( '%s (%s entries, %.1f per action)', $h['hook'], number_format_i18n( (int) $h['logs'] ), $per );
			}
			$detail .= ' Most log entries belong to: ' . implode( '; ', $parts ) . '.';
		}

		$advice[] = sprintf( 'Run the Action Scheduler task on the Database tab to remove finished actions older than %d days and orphaned log entries.', $days );
		return array(
			'id'       => 'as-size',
			'severity' => 'medium',
			'title'    => 'Action Scheduler tables are large',
			'detail'   => $detail,
			'action'   => implode( ' ', $advice ),
		);
	}

	private static function perfmatters_checks( array $plugins ) {
		if ( ! self::is_active( $plugins, array( 'perfmatters' ) ) ) {
			return array(
				array(
					'id'       => 'perfmatters',
					'severity' => 'medium',
					'title'    => 'Perfmatters is not active',
					'detail'   => 'The guide uses Perfmatters to disable emojis, dashicons and jQuery Migrate, move the login URL, self-host fonts and manage scripts.',
					'action'   => 'Install Perfmatters, activate it with the agency license, and apply the guide\'s settings.',
				),
			);
		}
		$opts = get_option( 'perfmatters_options' );
		if ( ! is_array( $opts ) ) {
			return array();
		}
		$missing = array();
		$checks  = array(
			'disable_emojis'        => 'Disable Emojis',
			'disable_dashicons'     => 'Disable Dashicons',
			'remove_jquery_migrate' => 'Remove jQuery Migrate',
		);
		foreach ( $checks as $key => $label ) {
			if ( empty( $opts[ $key ] ) ) {
				$missing[] = $label;
			}
		}
		// The agency's login slug is kept out of this public code: define LXJ_LOGIN_SLUG in wp-config.php to check it.
		$slug = defined( 'LXJ_LOGIN_SLUG' ) ? (string) LXJ_LOGIN_SLUG : '';
		if ( empty( $opts['login_url'] ) ) {
			$missing[] = 'Custom login URL';
		} elseif ( '' !== $slug && trim( $opts['login_url'], '/' ) !== trim( $slug, '/' ) ) {
			$missing[] = 'Custom login URL (set, but not the agency standard)';
		}
		if ( ! $missing ) {
			return array();
		}
		return array(
			array(
				'id'       => 'perfmatters-settings',
				'severity' => 'low',
				'title'    => 'Perfmatters settings differ from the guide',
				'detail'   => 'Appears off: ' . implode( ', ', $missing ) . '. (Read from saved settings; confirm in Perfmatters if this looks wrong.)',
				'action'   => 'Perfmatters > General: enable the items above. Login URL: the agency standard slug from the speed guide, disabled behavior message "Login at this address has been disabled."',
			),
		);
	}

	private static function has_cloudflare_purge( array $plugins ) {
		// FlyingPress purges Cloudflare's HTML cache on content updates when its Cloudflare integration is on.
		if ( self::is_active( $plugins, array( 'cloudflare', 'wp-rocket', 'wp-cloudflare-page-cache', 'flying-press' ) ) ) {
			return true;
		}
		foreach ( $plugins as $p ) {
			if ( $p['active'] && false !== stripos( $p['name'], 'cloudflare' ) ) {
				return true;
			}
		}
		return false;
	}

	private static function plugins() {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$out = array();
		foreach ( get_plugins() as $file => $data ) {
			$dir   = dirname( $file );
			$out[] = array(
				'file'   => $file,
				'slug'   => '.' === $dir ? basename( $file, '.php' ) : $dir,
				'name'   => $data['Name'],
				'active' => is_plugin_active( $file ),
			);
		}
		return $out;
	}

	private static function find_plugin( array $plugins, array $slugs, array $names ) {
		$hit = null;
		foreach ( $plugins as $p ) {
			$match = in_array( $p['slug'], $slugs, true );
			foreach ( $names as $n ) {
				if ( false !== stripos( $p['name'], $n ) ) {
					$match = true;
				}
			}
			if ( $match && ( ! $hit || $p['active'] ) ) {
				$hit = $p; // Prefer an active match.
			}
		}
		return $hit;
	}

	private static function is_active( array $plugins, array $slugs ) {
		foreach ( $plugins as $p ) {
			if ( $p['active'] && in_array( $p['slug'], $slugs, true ) ) {
				return true;
			}
		}
		return false;
	}

	private static function lower_headers( $headers ) {
		$out = array();
		foreach ( $headers as $k => $v ) {
			$out[ strtolower( $k ) ] = is_array( $v ) ? implode( ', ', $v ) : (string) $v;
		}
		return $out;
	}

	private static function kv( array $arr ) {
		$parts = array();
		foreach ( $arr as $k => $v ) {
			$parts[] = $k . ': ' . $v;
		}
		return implode( '; ', $parts );
	}
}
