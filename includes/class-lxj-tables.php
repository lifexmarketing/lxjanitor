<?php
/**
 * Leftover table review: matches non-core tables to the plugins that created them,
 * and drops a short list of known-safe groups on request.
 */

defined( 'ABSPATH' ) || exit;

class LXJ_Tables {

	/**
	 * Known table owners. Matching is case-insensitive on the table name after the site prefix.
	 * More specific entries come first; the first match wins.
	 *
	 * Keys:
	 *  id, name, slugs (plugin folder slugs),
	 *  prefix (string[]) and/or exact (string[]) table names without the site prefix,
	 *  removable (bool) whether the Tables tab offers to drop this group. Defaults to true for any group
	 *    tied to plugin slugs; removal is still blocked while that plugin is active. Shared libraries
	 *    (no slugs, e.g. Action Scheduler) are never removable.
	 *  options (string[]) option-name LIKE prefixes removed with the group,
	 *  data (array) table name without prefix => label, for tables holding business data
	 *    (entries, orders, payments, redirects...). Their exact row counts are shown before removal.
	 *  note (string) shown next to the group.
	 *
	 * @return array[]
	 */
	public static function owners() {
		$owners = array(
			// --- Removable groups (requested) ---------------------------------------------
			array(
				'id'        => 'gravityforms-legacy',
				'name'      => 'Gravity Forms (pre-2.3 legacy tables)',
				'slugs'     => array( 'gravityforms' ),
				'exact'     => array( 'rg_form', 'rg_form_meta', 'rg_form_view', 'rg_lead', 'rg_lead_detail', 'rg_lead_detail_long', 'rg_lead_meta', 'rg_lead_notes', 'rg_incomplete_submissions' ),
				'removable' => true,
				'options'   => array(),
				'note'      => 'Gravity Forms 2.3+ moved forms and entries to gf_* tables and left these behind.',
			),
			array(
				'id'        => 'wordfence',
				'name'      => 'Wordfence',
				'slugs'     => array( 'wordfence' ),
				'prefix'    => array( 'wfls_' ),
				'exact'     => array(
					'wfblockediplog', 'wfblocks', 'wfblocks7', 'wfconfig', 'wfcrawlers', 'wffilechanges', 'wffilemods',
					'wfhits', 'wfhoover', 'wfissues', 'wfknownfilelist', 'wflivetraffichuman', 'wflocs', 'wflockedout',
					'wflogins', 'wfnotifications', 'wfpendingissues', 'wfreversecache', 'wfsnipcache', 'wfstatus',
					'wftrafficrates', 'wfsecurityevents', 'wfauditevents', 'wfwaffailures', 'wfthrottlelog', 'wfnet404s',
					'wfvulnscanners', 'wfbadleechers', 'wfleechers', 'wfscanners', 'wfperflog',
				),
				'removable' => true,
				'options'   => array( 'wordfence' ),
				'note'      => 'Check the Wordfence firewall file is unhooked first (see recommendations).',
			),
			array(
				'id'        => 'defender',
				'name'      => 'Defender Security',
				'slugs'     => array( 'wp-defender', 'defender-security' ),
				'prefix'    => array( 'defender_' ),
				'removable' => true,
				'options'   => array(),
				'note'      => '',
			),
			array(
				'id'        => 'caos',
				'name'      => 'CAOS (Complete Analytics Optimization Suite)',
				'slugs'     => array( 'host-analyticsjs-local' ),
				'prefix'    => array( 'caos_' ),
				'removable' => true,
				'options'   => array( 'caos_', 'sgal_' ),
				'note'      => 'CAOS mostly leaves options rather than tables.',
			),
			array(
				'id'        => 'mainwp',
				'name'      => 'MainWP (Child, Child Reports, Dashboard)',
				'slugs'     => array( 'mainwp-child', 'mainwp-child-reports', 'mainwp' ),
				'prefix'    => array( 'mainwp_' ),
				'removable' => true,
				'options'   => array( 'mainwp_', 'html-regression-track' ),
				'note'      => 'Blocked while any MainWP plugin is active. Only remove once the site is disconnected from MainWP for good.',
			),
			array(
				'id'        => 'mediacleaner',
				'name'      => 'Media Cleaner',
				'slugs'     => array( 'media-cleaner', 'media-cleaner-pro' ),
				'prefix'    => array( 'mclean_' ),
				'removable' => true,
				'options'   => array(),
				'note'      => 'Scan results and reference index only (mclean_refs, mclean_scan); no media files are touched.',
			),

			// --- Recognized owners: removable once the plugin is no longer active ---------------
			array( 'id' => 'actionscheduler', 'name' => 'Action Scheduler (shared library: WooCommerce and others)', 'slugs' => array(), 'prefix' => array( 'actionscheduler_' ), 'removable' => false ),
			array( 'id' => 'woocommerce', 'name' => 'WooCommerce', 'slugs' => array( 'woocommerce' ), 'prefix' => array( 'wc_', 'woocommerce_' ), 'data' => array( 'wc_orders' => 'orders (HPOS)', 'woocommerce_order_items' => 'order line items', 'wc_customer_lookup' => 'customer records' ) ),
			array( 'id' => 'gravityforms', 'name' => 'Gravity Forms', 'slugs' => array( 'gravityforms' ), 'prefix' => array( 'gf_' ), 'data' => array( 'gf_entry' => 'form entries' ) ),
			array( 'id' => 'yoast', 'name' => 'Yoast SEO', 'slugs' => array( 'wordpress-seo', 'wordpress-seo-premium' ), 'prefix' => array( 'yoast_' ) ),
			array( 'id' => 'rankmath', 'name' => 'Rank Math', 'slugs' => array( 'seo-by-rank-math', 'seo-by-rank-math-pro' ), 'prefix' => array( 'rank_math_' ), 'data' => array( 'rank_math_redirections' => 'redirects' ) ),
			array( 'id' => 'aioseo', 'name' => 'All in One SEO', 'slugs' => array( 'all-in-one-seo-pack', 'all-in-one-seo-pack-pro' ), 'prefix' => array( 'aioseo_' ) ),
			array( 'id' => 'redirection', 'name' => 'Redirection', 'slugs' => array( 'redirection' ), 'prefix' => array( 'redirection_' ), 'data' => array( 'redirection_items' => 'redirects' ) ),
			array( 'id' => 'wpforms', 'name' => 'WPForms', 'slugs' => array( 'wpforms-lite', 'wpforms' ), 'prefix' => array( 'wpforms_' ), 'data' => array( 'wpforms_entries' => 'form entries', 'wpforms_payments' => 'payments' ) ),
			array( 'id' => 'formidable', 'name' => 'Formidable Forms', 'slugs' => array( 'formidable' ), 'prefix' => array( 'frm_' ), 'data' => array( 'frm_items' => 'form entries' ) ),
			array( 'id' => 'ninjaforms', 'name' => 'Ninja Forms', 'slugs' => array( 'ninja-forms' ), 'prefix' => array( 'nf3_' ) ),
			array( 'id' => 'fluentform', 'name' => 'Fluent Forms', 'slugs' => array( 'fluentform' ), 'prefix' => array( 'fluentform_' ), 'data' => array( 'fluentform_submissions' => 'form submissions' ) ),
			array( 'id' => 'cfdb7', 'name' => 'Contact Form CFDB7', 'slugs' => array( 'contact-form-cfdb7' ), 'exact' => array( 'db7_forms' ), 'data' => array( 'db7_forms' => 'form submissions' ) ),
			array( 'id' => 'elementor', 'name' => 'Elementor', 'slugs' => array( 'elementor', 'elementor-pro' ), 'exact' => array( 'e_events', 'e_submissions', 'e_submissions_values', 'e_submissions_actions_log', 'e_notes', 'e_notes_users_relations' ), 'data' => array( 'e_submissions' => 'form submissions' ) ),
			array( 'id' => 'wpml', 'name' => 'WPML', 'slugs' => array( 'sitepress-multilingual-cms' ), 'prefix' => array( 'icl_' ) ),
			array( 'id' => 'itsec', 'name' => 'Solid Security (iThemes Security)', 'slugs' => array( 'better-wp-security', 'ithemes-security-pro' ), 'prefix' => array( 'itsec_' ) ),
			array( 'id' => 'aiowps', 'name' => 'All In One WP Security', 'slugs' => array( 'all-in-one-wp-security-and-firewall' ), 'prefix' => array( 'aiowps_' ) ),
			array( 'id' => 'wsal', 'name' => 'WP Activity Log', 'slugs' => array( 'wp-security-audit-log' ), 'prefix' => array( 'wsal_' ) ),
			array( 'id' => 'simplehistory', 'name' => 'Simple History', 'slugs' => array( 'simple-history' ), 'prefix' => array( 'simple_history' ) ),
			array( 'id' => 'wpmailsmtp', 'name' => 'WP Mail SMTP', 'slugs' => array( 'wp-mail-smtp', 'wp-mail-smtp-pro' ), 'prefix' => array( 'wpmailsmtp_' ) ),
			array( 'id' => 'fluentsmtp', 'name' => 'FluentSMTP', 'slugs' => array( 'fluent-smtp' ), 'prefix' => array( 'fsmpt_' ) ),
			array( 'id' => 'wprocket', 'name' => 'WP Rocket', 'slugs' => array( 'wp-rocket' ), 'prefix' => array( 'wpr_' ) ),
			array( 'id' => 'flyingpress', 'name' => 'FlyingPress', 'slugs' => array( 'flying-press' ), 'prefix' => array( 'flying_press_' ) ),
			array( 'id' => 'litespeed', 'name' => 'LiteSpeed Cache', 'slugs' => array( 'litespeed-cache' ), 'prefix' => array( 'litespeed_' ) ),
			array( 'id' => 'wpoptimize', 'name' => 'WP-Optimize / UpdraftPlus task manager', 'slugs' => array( 'wp-optimize', 'updraftplus' ), 'exact' => array( 'tm_tasks', 'tm_taskmeta' ) ),
			array( 'id' => 'imagify', 'name' => 'Imagify', 'slugs' => array( 'imagify' ), 'prefix' => array( 'imagify_' ) ),
			array( 'id' => 'ewww', 'name' => 'EWWW Image Optimizer', 'slugs' => array( 'ewww-image-optimizer' ), 'prefix' => array( 'ewwwio_' ) ),
			array( 'id' => 'smush', 'name' => 'Smush', 'slugs' => array( 'wp-smushit', 'wp-smush-pro' ), 'prefix' => array( 'smush_' ) ),
			array( 'id' => 'tec', 'name' => 'The Events Calendar', 'slugs' => array( 'the-events-calendar' ), 'prefix' => array( 'tec_' ) ),
			array( 'id' => 'wpstatistics', 'name' => 'WP Statistics', 'slugs' => array( 'wp-statistics' ), 'prefix' => array( 'statistics_' ) ),
			array( 'id' => 'revslider', 'name' => 'Slider Revolution', 'slugs' => array( 'revslider' ), 'prefix' => array( 'revslider_' ) ),
			array( 'id' => 'layerslider', 'name' => 'LayerSlider', 'slugs' => array( 'LayerSlider', 'layerslider' ), 'prefix' => array( 'layerslider' ) ),
			array( 'id' => 'mailpoet', 'name' => 'MailPoet', 'slugs' => array( 'mailpoet' ), 'prefix' => array( 'mailpoet_' ), 'data' => array( 'mailpoet_subscribers' => 'email subscribers' ) ),
			array( 'id' => 'jetpack', 'name' => 'Jetpack', 'slugs' => array( 'jetpack' ), 'prefix' => array( 'jetpack_' ) ),
			array( 'id' => 'pmxi', 'name' => 'WP All Import', 'slugs' => array( 'wp-all-import', 'wp-all-import-pro' ), 'prefix' => array( 'pmxi_' ) ),
			array( 'id' => 'sbi', 'name' => 'Smash Balloon Instagram Feed', 'slugs' => array( 'instagram-feed', 'instagram-feed-pro' ), 'prefix' => array( 'sbi_' ) ),
			array( 'id' => 'cff', 'name' => 'Smash Balloon Facebook Feed', 'slugs' => array( 'custom-facebook-feed', 'custom-facebook-feed-pro' ), 'prefix' => array( 'cff_' ) ),
			array( 'id' => 'cookieyes', 'name' => 'CookieYes', 'slugs' => array( 'cookie-law-info' ), 'prefix' => array( 'cky_' ) ),
			array( 'id' => 'hustle', 'name' => 'Hustle', 'slugs' => array( 'wordpress-popup' ), 'prefix' => array( 'hustle_' ) ),
			array( 'id' => 'cleantalk', 'name' => 'CleanTalk Anti-Spam', 'slugs' => array( 'cleantalk-spam-protect' ), 'prefix' => array( 'cleantalk_' ) ),
			array( 'id' => 'relevanssi', 'name' => 'Relevanssi', 'slugs' => array( 'relevanssi', 'relevanssi-premium' ), 'prefix' => array( 'relevanssi' ) ),
			array( 'id' => 'gla', 'name' => 'Google for WooCommerce (Google Listings & Ads)', 'slugs' => array( 'google-listings-and-ads' ), 'prefix' => array( 'gla_' ) ),
			array( 'id' => 'cartabandonment', 'name' => 'Cart Abandonment Recovery for WooCommerce (CartFlows)', 'slugs' => array( 'woo-cart-abandonment-recovery' ), 'prefix' => array( 'cartflows_ca_' ), 'data' => array( 'cartflows_ca_cart_abandonment' => 'abandoned carts (with customer contact details)' ) ),
			array( 'id' => 'searchfilter', 'name' => 'Search & Filter (Pro) 3.x', 'slugs' => array( 'search-filter', 'search-filter-pro' ), 'prefix' => array( 'search_filter_' ) ),
			array( 'id' => 'yithwapo', 'name' => 'YITH WooCommerce Product Add-Ons', 'slugs' => array( 'yith-woocommerce-product-add-ons', 'yith-woocommerce-product-add-ons-premium', 'yith-woocommerce-advanced-product-options-premium' ), 'prefix' => array( 'yith_wapo_' ) ),
			array( 'id' => 'asnpbundles', 'name' => 'Product Bundle Builder for WooCommerce (AsanaPlugins Easy Product Bundles)', 'slugs' => array( 'easy-product-bundles-for-woocommerce', 'easy-product-bundles-for-woocommerce-pro' ), 'prefix' => array( 'asnp_wepb_' ) ),
			array( 'id' => 'adtribes', 'name' => 'Product Feed PRO for WooCommerce (AdTribes)', 'slugs' => array( 'woo-product-feed-pro', 'woo-product-feed-elite' ), 'prefix' => array( 'adt_ucp_' ) ),
			array( 'id' => 'acfw', 'name' => 'Advanced Coupons for WooCommerce', 'slugs' => array( 'advanced-coupons-for-woocommerce-free', 'advanced-coupons-for-woocommerce' ), 'prefix' => array( 'acfw_' ), 'data' => array( 'acfw_store_credits' => 'store credit entries' ), 'note' => 'Store credits are customer balances (money owed to customers). Never remove while the store honors them.' ),
			array( 'id' => 'popupbuilder', 'name' => 'Popup Builder', 'slugs' => array( 'popup-builder' ), 'prefix' => array( 'sgpb_' ), 'data' => array( 'sgpb_subscribers' => 'email subscribers' ) ),
			array( 'id' => 'rafflepress', 'name' => 'RafflePress', 'slugs' => array( 'rafflepress', 'rafflepress-pro' ), 'prefix' => array( 'rafflepress_' ), 'data' => array( 'rafflepress_contestants' => 'giveaway contestants (with email addresses)', 'rafflepress_entries' => 'giveaway entries' ) ),
			array( 'id' => 'mailchimpwc', 'name' => 'Mailchimp for WooCommerce', 'slugs' => array( 'mailchimp-for-woocommerce' ), 'prefix' => array( 'mailchimp_' ), 'note' => 'Queue of pending syncs to Mailchimp; the audience itself lives in Mailchimp, not here.' ),
			array( 'id' => 'automatewoo', 'name' => 'AutomateWoo', 'slugs' => array( 'automatewoo' ), 'prefix' => array( 'automatewoo_' ), 'data' => array( 'automatewoo_guests' => 'guest customers (with email addresses)', 'automatewoo_abandoned_carts' => 'abandoned carts', 'automatewoo_queue' => 'queued workflow emails' ) ),
			array( 'id' => 'prettylinks', 'name' => 'Pretty Links', 'slugs' => array( 'pretty-link' ), 'prefix' => array( 'prli_' ), 'data' => array( 'prli_links' => 'short links (these URLs stop redirecting if removed)' ) ),
			array( 'id' => 'wppopups', 'name' => 'WP Popups', 'slugs' => array( 'wp-popups-lite', 'wppopups-pro', 'wp-popups-pro' ), 'prefix' => array( 'wppopups_' ) ),
			array( 'id' => 'strongtestimonials', 'name' => 'Strong Testimonials', 'slugs' => array( 'strong-testimonials' ), 'exact' => array( 'strong_views' ), 'note' => 'Display settings only; the testimonials themselves are posts and are not removed.' ),
			array( 'id' => 'yithwcan', 'name' => 'YITH WooCommerce Ajax Product Filter', 'slugs' => array( 'yith-woocommerce-ajax-navigation', 'yith-woocommerce-ajax-product-filter-premium' ), 'prefix' => array( 'yith_wcan_' ) ),
			array( 'id' => 'yithwcac', 'name' => 'YITH Active Campaign for WooCommerce', 'slugs' => array( 'yith-woocommerce-active-campaign-premium', 'yith-woocommerce-active-campaign' ), 'prefix' => array( 'yith_wcac_' ) ),
			array( 'id' => 'yithwccl', 'name' => 'YITH WooCommerce Color and Label Variations', 'slugs' => array( 'yith-color-and-label-variations-for-woocommerce', 'yith-woocommerce-color-label-variations-premium' ), 'prefix' => array( 'yith_wccl_' ) ),
			array( 'id' => 'creativemail', 'name' => 'Creative Mail by Constant Contact', 'slugs' => array( 'creative-mail-by-constant-contact' ), 'prefix' => array( 'ce4wp_' ), 'data' => array( 'ce4wp_abandoned_checkout' => 'abandoned checkouts (with customer contact details)' ) ),
			array( 'id' => 'brb', 'name' => 'Business Reviews Bundle (RichPlugins)', 'slugs' => array( 'business-reviews-bundle' ), 'prefix' => array( 'brb_' ), 'note' => 'Cached copies of Google/Facebook/Yelp reviews; the originals stay on those platforms.' ),
			array( 'id' => 'wpmediafolder', 'name' => 'WP Media Folder (JoomUnited)', 'slugs' => array( 'wp-media-folder', 'wp-file-download', 'wp-table-manager' ), 'prefix' => array( 'wpmf_' ), 'exact' => array( 'ju_queue' ), 'note' => 'ju_queue is JoomUnited\'s background queue and may be shared by their other plugins (WP File Download, WP Table Manager), so this is blocked while any of them is active. Folder assignments for media may live in this plugin\'s data: removing it loses the folder structure, not the media files.' ),
			array( 'id' => 'pixelyoursite', 'name' => 'PixelYourSite', 'slugs' => array( 'pixelyoursite', 'pixelyoursite-pro' ), 'prefix' => array( 'pys_' ), 'options' => array( 'pys_' ), 'note' => 'Being replaced by MetaTrac (see recommendations). Once PixelYourSite is deleted, its tables and settings are leftovers.' ),
			array( 'id' => 'wpgomaps', 'name' => 'WP Go Maps (formerly WP Google Maps)', 'slugs' => array( 'wp-google-maps', 'wp-google-maps-pro' ), 'prefix' => array( 'wpgmza' ), 'data' => array( 'wpgmza' => 'map markers', 'wpgmza_maps' => 'maps' ), 'note' => 'Removing these deletes the maps and their markers; any page embedding a map will show nothing.' ),
			array( 'id' => 'monsterinsights', 'name' => 'MonsterInsights', 'slugs' => array( 'google-analytics-for-wordpress', 'google-analytics-premium' ), 'prefix' => array( 'monsterinsights_' ), 'note' => 'Report cache and a tracking-events table; the analytics data itself lives in Google Analytics, not here.' ),
			array( 'id' => 'photogallery', 'name' => 'Photo Gallery by 10Web', 'slugs' => array( 'photo-gallery' ), 'prefix' => array( 'bwg_' ) ),
		);

		$owners = apply_filters( 'lxjanitor_table_owners', $owners );
		foreach ( $owners as &$o ) {
			$o += array( 'prefix' => array(), 'exact' => array(), 'options' => array(), 'data' => array(), 'note' => '', 'slugs' => array() );
			// Any recognized plugin's tables can be removed once the plugin is inactive; shared libraries (no slugs) never.
			$o += array( 'removable' => (bool) $o['slugs'] );
		}
		unset( $o );
		return $owners;
	}

	/**
	 * Non-core tables grouped by owner, with plugin status and removal eligibility.
	 *
	 * @return array[] id, name, status (active|inactive|not installed|shared|unknown), tables[], size,
	 *                 removable, blocked (reason or ''), options (count), options_size, note,
	 *                 data (array of label => exact row count, for business-data tables that exist)
	 */
	public static function review() {
		global $wpdb;
		$core = array_map( 'strtolower', $wpdb->tables( 'all' ) );
		$plen = strlen( $wpdb->prefix );

		$owners = self::owners();
		$groups = array();
		foreach ( $owners as $o ) {
			$groups[ $o['id'] ] = array(
				'id'        => $o['id'],
				'name'      => $o['name'],
				'status'    => $o['slugs'] ? self::plugin_status( $o['slugs'] ) : 'shared',
				'tables'    => array(),
				'size'      => 0,
				'removable' => $o['removable'],
				'blocked'   => '',
				'options'   => 0,
				'options_size' => 0,
				'note'      => $o['note'],
				'data'      => array(),
			);
		}
		$unknown = array( 'id' => 'unknown', 'name' => 'Unknown owner', 'status' => 'unknown', 'tables' => array(), 'size' => 0, 'removable' => false, 'blocked' => '', 'options' => 0, 'options_size' => 0, 'data' => array(), 'note' => 'Confirm which plugin created these before removing them. If you know the owner is gone for good, use "Remove unknown tables manually" below.' );

		foreach ( LXJ_Metrics::tables() as $t ) {
			$lower = strtolower( $t['name'] );
			if ( in_array( $lower, $core, true ) ) {
				continue;
			}
			// Multisite: the main site's prefix also matches wp_2_posts etc.
			if ( is_multisite() && $wpdb->prefix === $wpdb->base_prefix && preg_match( '/^' . preg_quote( $wpdb->base_prefix, '/' ) . '\d+_/', $t['name'] ) ) {
				continue;
			}
			$suffix = strtolower( substr( $t['name'], $plen ) );
			$owner  = self::match( $suffix, $owners );
			if ( $owner ) {
				$groups[ $owner ]['tables'][] = $t;
				$groups[ $owner ]['size']    += $t['total'];
			} else {
				$unknown['tables'][] = $t;
				$unknown['size']    += $t['total'];
			}
		}

		foreach ( $owners as $o ) {
			if ( $o['removable'] && $o['options'] ) {
				list( $count, $size ) = self::options_stats( $o['options'] );
				$groups[ $o['id'] ]['options']      = $count;
				$groups[ $o['id'] ]['options_size'] = $size;
			}
			if ( $o['removable'] ) {
				$groups[ $o['id'] ]['blocked'] = self::blocked_reason( $o['id'], $groups[ $o['id'] ]['status'] );
			}
			// Exact counts for business-data tables, so nobody drops entries or orders without seeing them.
			if ( $o['data'] && $groups[ $o['id'] ]['tables'] ) {
				$present = array_map( 'strtolower', wp_list_pluck( $groups[ $o['id'] ]['tables'], 'name' ) );
				foreach ( $o['data'] as $suffix => $label ) {
					$table = $wpdb->prefix . $suffix;
					if ( in_array( strtolower( $table ), $present, true ) ) {
						$groups[ $o['id'] ]['data'][ $label ] = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM `' . str_replace( '`', '', $table ) . '`' ); // phpcs:ignore
					}
				}
			}
		}

		$out = array_values( array_filter( $groups, function ( $g ) {
			return $g['tables'] || $g['options'];
		} ) );
		if ( $unknown['tables'] ) {
			$out[] = $unknown;
		}
		return $out;
	}

	/**
	 * Drop a removable group's tables and leftover options.
	 *
	 * @param string $id Group id.
	 * @return array{ok:bool, message:string, tables:string[], options:int}
	 */
	public static function remove_group( $id ) {
		global $wpdb;
		$owner = null;
		foreach ( self::owners() as $o ) {
			if ( $o['id'] === $id && $o['removable'] ) {
				$owner = $o;
			}
		}
		if ( ! $owner ) {
			return array( 'ok' => false, 'message' => 'That group cannot be removed.', 'tables' => array(), 'options' => 0 );
		}
		$group = null;
		foreach ( self::review() as $g ) {
			if ( $g['id'] === $id ) {
				$group = $g;
			}
		}
		if ( ! $group ) {
			return array( 'ok' => true, 'message' => 'Nothing left to remove for ' . $owner['name'] . '.', 'tables' => array(), 'options' => 0 );
		}
		if ( $group['blocked'] ) {
			return array( 'ok' => false, 'message' => $group['blocked'], 'tables' => array(), 'options' => 0 );
		}

		$dropped = array();
		foreach ( $group['tables'] as $t ) {
			// Name came from SHOW TABLES / information_schema and matched an explicit owner rule.
			if ( false !== $wpdb->query( 'DROP TABLE IF EXISTS `' . str_replace( '`', '', $t['name'] ) . '`' ) ) { // phpcs:ignore
				$dropped[] = $t['name'];
			}
		}

		$options = 0;
		foreach ( self::option_names( $owner['options'] ) as $name ) {
			if ( delete_option( $name ) ) {
				$options++;
			}
		}

		$data = self::describe_data( $group['data'] );
		return array(
			'ok'      => true,
			'message' => sprintf( '%s: dropped %d tables (%s)%s, deleted %d options.', $owner['name'], count( $dropped ), lxj_bytes( $group['size'] ), $data ? ' including ' . $data : '', $options ),
			'tables'  => $dropped,
			'options' => $options,
			'data'    => $group['data'],
		);
	}

	/**
	 * Manual override: drop specific tables from the "Unknown owner" group.
	 *
	 * Each requested name must still be in the unknown group at the time of the request, so this
	 * can never reach core tables, other multisite sites' tables, or tables with a known owner.
	 *
	 * @param string[] $names Full table names (with prefix) as listed on the Tables tab.
	 * @return array{ok:bool, message:string, tables:string[], skipped:string[], size:int}
	 */
	public static function remove_unknown( array $names ) {
		global $wpdb;
		$allowed = array();
		foreach ( self::review() as $g ) {
			if ( 'unknown' === $g['id'] ) {
				foreach ( $g['tables'] as $t ) {
					$allowed[ $t['name'] ] = (int) $t['total'];
				}
			}
		}

		$dropped = array();
		$skipped = array();
		$size    = 0;
		foreach ( array_unique( $names ) as $name ) {
			if ( ! isset( $allowed[ $name ] ) ) {
				$skipped[] = $name;
				continue;
			}
			if ( false !== $wpdb->query( 'DROP TABLE IF EXISTS `' . str_replace( '`', '', $name ) . '`' ) ) { // phpcs:ignore
				$dropped[] = $name;
				$size     += $allowed[ $name ];
			} else {
				$skipped[] = $name;
			}
		}

		$message = sprintf( 'Permanently dropped %d unknown-owner tables (%s)', count( $dropped ), lxj_bytes( $size ) );
		$message .= $dropped ? ': ' . implode( ', ', $dropped ) . '.' : '.';
		if ( $skipped ) {
			$message .= ' Skipped (no longer unknown-owner tables, or the drop failed): ' . implode( ', ', $skipped ) . '.';
		}
		return array(
			'ok'      => ! $skipped,
			'message' => $message,
			'tables'  => $dropped,
			'skipped' => $skipped,
			'size'    => $size,
		);
	}

	/**
	 * Why a removable group cannot be dropped right now, or '' if it can.
	 *
	 * @param string $id     Group id.
	 * @param string $status Plugin status.
	 * @return string
	 */
	private static function blocked_reason( $id, $status ) {
		global $wpdb;
		if ( 'gravityforms-legacy' === $id ) {
			// Legacy tables are only leftovers once the 2.3+ tables exist. Before that they hold live forms and entries.
			if ( 'not installed' !== $status
				&& ! ( LXJ_Metrics::table_exists( $wpdb->prefix . 'gf_form' ) && LXJ_Metrics::table_exists( $wpdb->prefix . 'gf_entry' ) ) ) {
				return 'Gravity Forms is installed but its 2.3+ tables (gf_form, gf_entry) are missing, so these legacy tables may still hold live forms and entries.';
			}
			return '';
		}
		if ( 'active' === $status ) {
			return 'The plugin is active. Deactivate and delete it first.';
		}
		if ( 'shared' === $status ) {
			return 'Shared library used by several plugins.';
		}
		return '';
	}

	/**
	 * "412 form entries, 3 payments" for the non-empty business-data tables of a group, or ''.
	 *
	 * @param array $data label => count.
	 * @return string
	 */
	public static function describe_data( array $data ) {
		$parts = array();
		foreach ( $data as $label => $count ) {
			if ( $count > 0 ) {
				$parts[] = number_format_i18n( $count ) . ' ' . $label;
			}
		}
		return implode( ', ', $parts );
	}

	private static function match( $suffix, array $owners ) {
		foreach ( $owners as $o ) {
			foreach ( $o['exact'] as $e ) {
				if ( strtolower( $e ) === $suffix ) {
					return $o['id'];
				}
			}
			foreach ( $o['prefix'] as $p ) {
				if ( 0 === strpos( $suffix, strtolower( $p ) ) ) {
					return $o['id'];
				}
			}
		}
		return null;
	}

	/**
	 * @param string[] $slugs Plugin folder slugs.
	 * @return string active|inactive|not installed
	 */
	public static function plugin_status( array $slugs ) {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$status = 'not installed';
		foreach ( array_keys( get_plugins() ) as $file ) {
			$dir = dirname( $file );
			$slug = '.' === $dir ? basename( $file, '.php' ) : $dir;
			if ( in_array( $slug, $slugs, true ) ) {
				if ( is_plugin_active( $file ) ) {
					return 'active';
				}
				$status = 'inactive';
			}
		}
		return $status;
	}

	private static function option_names( array $prefixes ) {
		global $wpdb;
		$names = array();
		foreach ( $prefixes as $p ) {
			$names = array_merge(
				$names,
				$wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( $p ) . '%' ) )
			);
		}
		return array_unique( $names );
	}

	private static function options_stats( array $prefixes ) {
		global $wpdb;
		$count = 0;
		$size  = 0;
		foreach ( $prefixes as $p ) {
			$row    = $wpdb->get_row( $wpdb->prepare( "SELECT COUNT(*) AS n, COALESCE(SUM(LENGTH(option_value)),0) AS s FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( $p ) . '%' ) );
			$count += (int) $row->n;
			$size  += (int) $row->s;
		}
		return array( $count, $size );
	}
}
