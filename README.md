# LX Janitor

A WordPress plugin that runs the database steps from the LifeX **Website Speed Optimization** guide and recommends the follow-up work it should not do on its own.

## What it does by itself

Found under **Tools > LX Janitor**, or with WP-CLI (`wp lxjanitor ...`).

| Guide step | LX Janitor |
| --- | --- |
| Record DB size, uncached TTFB before/after | Snapshots with baseline vs. now comparison (DB size, autoload, revisions, Action Scheduler, cron, loopback TTFB) |
| Client report | **Download PDF report** (Overview and History tabs) opens a printable page: site name and URL, baseline vs. now with percent change, the page speed caveat, and a plain-language history with each person's display name. Save it with the browser's "Save as PDF"; no PDF library is bundled. |
| `wp action-scheduler clean` (+ failed) | Deletes complete/canceled/failed actions older than 31 days and orphaned logs. Pending actions are never touched. Overview flags `actionscheduler_actions` or `actionscheduler_logs` at 50 MB or 100,000 rows and names the hooks responsible, including whether their actions are mostly recent (cleanup can't help yet: reduce how often the hook runs) or mostly failing (fix the error). |
| WooCommerce database log (`woocommerce_log`) | **Off by default** (unticked on the Database tab; `wp lxjanitor clean` skips it unless named in `--tasks`), since most sites never logged to the database. Deletes entries older than WooCommerce's log retention setting (default 30 days). If every entry is older, the table is emptied with TRUNCATE, which frees the space immediately. This matters after a switch to file logging: WooCommerce stops pruning the table and old entries stay forever. Overview flags the table at 50 MB or 100,000 rows. |
| `wp db optimize` | Optimizes only tables with at least 1 MB or 10% reclaimable space |
| Autoload check (under 500 KB) | Top 20 autoloaded options with likely owner; reversible "stop autoloading" for non-core options. Owners are matched by plugin and theme folder slug (so `Avada_options` maps to the Avada theme), leftover `theme_mods_*` Customizer settings are labeled by theme (inactive, deleted, or an unused parent of the active child theme), plus a known-prefix list for options that don't resemble their slug (e.g. `gtm4wp-*` → GTM4WP); extend it with the `lxjanitor_option_owners` filter |
| WP Crontrol cleanup | Flags hooks with no callback plus the guide's list (`mainwp_child_*`, `rank_math/content-ai/update_prompts`, `monsterinsights_*`, `siteground_optimizer_*`). Core hooks are protected. |
| WP-Optimize revisions/trash/transients | Keeps the newest 2 revisions per post and anything from the last 14 days; trash older than 14 days; expired transients; auto-drafts; orphaned post meta |

| WP-Optimize "[not installed]" table review | **Tables** tab: non-core tables matched to ~40 known plugins, with active / inactive / not installed status |

### Removable leftover groups (Tables tab)

| Group | What is removed | Safety rule |
| --- | --- | --- |
| Gravity Forms legacy | `rg_form`, `rg_form_meta`, `rg_form_view`, `rg_lead`, `rg_lead_detail`, `rg_lead_detail_long`, `rg_lead_meta`, `rg_lead_notes`, `rg_incomplete_submissions` | Blocked if Gravity Forms is installed but its 2.3+ `gf_form` / `gf_entry` tables don't exist (the legacy tables would still hold live data) |
| Wordfence | Explicit list of Wordfence tables plus `wfls_*`, and `wordfence*` options | Blocked while Wordfence is active. Other `wf*` tables (e.g. FunnelKit's `wfacp_`) are never matched. |
| Defender Security | `defender_*` tables | Blocked while Defender is active |
| CAOS | `caos_*` / `sgal_*` options (CAOS stores options, not tables) and any `caos_*` tables | Blocked while CAOS is active |
| Media Cleaner | `mclean_*` tables (`mclean_refs`, `mclean_scan`) | Blocked while Media Cleaner (free or Pro) is active |
| MainWP | `mainwp_*` tables (e.g. `mainwp_child_changes_logs`, `mainwp_child_changes_meta`, Child Reports' `mainwp_stream*`) and `mainwp_*` options | Blocked while MainWP Child, MainWP Child Reports or the MainWP Dashboard is active |

**Every other recognized plugin** (WPForms, WooCommerce, Yoast, Relevanssi and the rest of the ~50 known owners) is also removable, with two safeguards:

1. Removal is blocked while the plugin is active. Shared libraries such as Action Scheduler are never removable.
2. Groups that hold business data show exact counts before removal (for example "Contains 412 form entries, 3 payments"), with a reminder to export first. Covered: form entries (WPForms, Gravity Forms, Formidable, Fluent Forms, CFDB7, Elementor), WooCommerce orders and customers, payments, redirects (Redirection, Rank Math), MailPoet subscribers, abandoned carts and Advanced Coupons store credits. The counts are also written to **History** when a group is removed.

The Overview lists leftover groups from plugins that are no longer installed. Add more groups, or a `data` map for an existing one, with the `lxjanitor_table_owners` filter.

**Unknown-owner tables (manual override).** Tables that match no known owner appear in a separate "Remove unknown tables manually" section. You choose individual tables, tick the backup confirmation and type `DELETE`. The server re-checks that every submitted name is still an unknown-owner table, so core tables, other multisite sites' tables and known-owner tables can never be dropped this way. Removal is permanent: the only recovery is restoring a database backup. Every drop is logged under **History**.

Recommendations also flag a Wordfence firewall still loaded through `auto_prepend_file` after Wordfence is gone, since deleting `wordfence-waf.php` first would take the site down.

Every cleanup run requires a backup confirmation, works in batches inside a ~20 second budget (safe on shared hosting), and is logged under **History**. The plugin stores its own data with autoload off.

## What it recommends (for a human to do)

- **Plugin replacements:** PixelYourSite or the Meta pixel plugin → MetaTrac; Permalink Manager Lite → WP Blog Permafix; Beaver Builder sticky section plugins → CSS snippet; HandL UTM Grabber → Trace IDs. Extend with the `lxjanitor_replacements` filter.
- **Cache problems seen on an anonymous home page request:** `Set-Cookie` (PHPSESSID / `_fbp` called out as the PixelYourSite patterns), `no-store`/`private` headers, no cache HIT on a repeat request.
- **Purge chain gaps:** Varnish + Beaver Builder (enable proxy cache clearing), Varnish + Elementor/WPBakery, Cloudflare caching HTML with nothing to purge it (FlyingPress, WP Rocket and the Cloudflare plugins count as purgers).
- **FlyingPress + Cloudflare:** Cloudflare caches only static files by default on every plan. FlyingPress's Cloudflare integration caches full HTML at the edge on all plans, including free, and purges it on content updates ([setup guide](https://docs.flyingpress.com/en/articles/11977701-flyingpress-cloudflare-integration-full-page-caching-setup-guide)). LX Janitor flags a FlyingPress site behind Cloudflare whose pages aren't cached at the edge (with the API token permissions and the orange-cloud and APO prerequisites), and flags Cloudflare APO running alongside FlyingPress, which the guide says to disable first.
- Google Fonts loaded from Google, JPG/PNG without Imagify, no persistent object cache, autoload over 500 KB, large non-core tables, big pending Action Scheduler queues.
- Perfmatters missing, or its saved settings differ from the guide (emojis, dashicons, jQuery Migrate, login URL).
- WP-Optimize / WP Crontrol can be removed once cleanup is done.

## WP-CLI

```
wp lxjanitor report                 # current metrics
wp lxjanitor snapshot               # record a before/after snapshot
wp lxjanitor clean [--tasks=a,b] [--yes]
wp lxjanitor crons [--remove]
wp lxjanitor recommend [--format=table|json|csv]
wp lxjanitor tables [--remove=<group ids from the listing>] [--yes]
```

Task keys: `action_scheduler, woocommerce_log, revisions, trash, auto_drafts, expired_transients, orphan_postmeta, optimize_tables`.

## Notes

- Requires WordPress 6.4+ and PHP 7.4+. Built for MySQL/MariaDB; database size reads 0 on SQLite (WordPress Studio).
- Uninstalling restores any autoload changes before removing the plugin's own data.
- The baseline is the first snapshot ever recorded on the site and is stored separately from History (which keeps the last 50 entries), so before-and-after comparisons and reports always use the original starting point. After a rebuild, migration or old-backup restore, **Reset baseline** on the Overview (behind a confirmation) replaces it with a fresh snapshot; the old baseline's figures are kept in History.
- The TTFB figure is measured from the server to itself with a cache-busting query string. It shows generation time trends, not what visitors see; keep using PageSpeed Insights and Client Site Monitor for that.
- The Perfmatters check confirms a custom login URL is set. To also confirm it is the agency standard, add `define( 'LXJ_LOGIN_SLUG', '...' );` to the site's `wp-config.php` (the slug is in the speed optimization guide; it is deliberately not in this public repo).

## Installing and updating

Install by uploading `lxjanitor.zip` from the [latest release](https://github.com/lifexmarketing/lxjanitor/releases/latest) under Plugins > Add New > Upload Plugin.

After that, sites update themselves from this repo:

- WordPress checks for plugin updates twice a day. On each check LX Janitor reads the latest GitHub release (cached for 12 hours, so at most two GitHub requests per site per day). When it is newer than the installed version, the update appears on the Plugins and Updates screens like any other plugin.
- Install it from there, with `wp plugin update lxjanitor`, or turn on **Enable auto-updates** for LX Janitor on the Plugins screen to have WordPress install new releases automatically.
- **Check again** on Dashboard > Updates skips the 12-hour cache.
- Development checkouts (a plugin folder containing `.git`, or a symlinked folder) never update themselves, because an update replaces the plugin folder.

### Publishing a release

1. Bump the version in both places in `lxjanitor.php` (the `Version:` header and `LXJ_VERSION`) and commit.
2. Create a GitHub release with a tag that matches, e.g. `v0.3.0`. The release notes become the changelog shown in WordPress.
3. The **Build release zip** workflow checks that the tag matches the plugin version, builds `lxjanitor.zip` and attaches it to the release. Sites see the update at their next check (within about 12 hours).
