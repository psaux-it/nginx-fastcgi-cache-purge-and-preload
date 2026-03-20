=== Nginx Cache Purge Preload ===
Contributors: psauxit
Donate link: https://github.com/sponsors/psaux-it
Tags: nginx, cache, purge, preload, performance
Requires at least: 6.5
Requires PHP: 7.4
Tested up to: 6.9.4
Stable tag: 2.1.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

The most comprehensive solution for managing Nginx (FastCGI, Proxy, SCGI, UWSGI) cache operations directly from your WordPress dashboard.

== Description ==

**NPP** lets WordPress users manage **Nginx Cache Purge and Preload** (FastCGI, Proxy, SCGI, UWSGI) operations directly from the WordPress admin dashboard — actively warming the cache via site crawl, so your Nginx cache is always preloaded and ready.

➡️ **Resources:**

• Visit the [NPP Main Development Repository](https://github.com/psaux-it/nginx-fastcgi-cache-purge-and-preload) - Docs, issues & contributions and more.
• Visit the [safexec Main Development Repository](https://github.com/psaux-it/nginx-fastcgi-cache-purge-and-preload/tree/main/safexec) - NPP's privilege-dropping C wrapper.
• Explore [NPP Containerized](https://github.com/psaux-it/wordpress-nginx-cache-docker) - Ready to run full stack Nginx Docker setup.
• Refer to the plugin’s **Help tab** for additional guidance.

== Features ==

🧹 **Purge All Nginx Cache**: Completely clear all cached data stored by Nginx.

🔄 **Preload All Nginx Cache**: Warm the Nginx cache with the most recent data for the entire website.

🎯 **HTTP Purge (ngx_cache_purge)**: When the Nginx cache module is available, NPP uses it as the fastest purge path. Falls back gracefully to index and filesystem purge when the module is not present.

🚀 **Auto Preload Nginx Cache**: Automatically preloads the cache when Auto Purge is enabled for a POST/PAGE or after the Purge All action.

🧼 **Auto Purge Nginx Cache**: Purge cache on Post/Page content changes, comment status updates, theme/plugin updates, or when compatible Cache Plugins trigger a purge. Nginx cache is preloaded automatically if Auto Preload is enabled (for the entire site or individual page).

🔗 **Purge Scope (Related Pages)**: Automatically purge related pages such as the Homepage, WooCommerce Shop page, and Category/Tag archives when a single URL is purged. Optionally preload those pages to keep the cache warm.

⏰ **Schedule Nginx Cache Purge & Preload via WP Cron**: Automate the purge and preload process using WordPress Cron jobs.

🧭 **Proxy Support for Preload**: Route preload requests through a proxy server for edge-case environments and containerized deployments.

⏱️ **Live Preload Progress Monitoring**: Watch the Nginx cache preload process in real time — complete with a dynamic progress bar, currently processed URL, 404 tracking, server load, and total completion time.

🌐 **Remote Nginx Cache Purge & Preload via REST API**: Remotely trigger cache purging and preloading through REST API endpoints.

⚙️ **Manual Nginx Cache Purge & Preload**: Allow manual purging and preloading of cache through the table view in the Advanced Tab.

📚 **Nginx Cache Analyzer**: Full HIT/MISS cache analyzer dashboard, from the last preload crawl with what is currently stored in the Nginx cache. Instantly spot uncached pages and Purge or Preload them directly in the Advanced Tab.

🔍 **On-Page Nginx Cache Purge & Preload**: Manually purge and preload Nginx cache for the currently visited page directly from the frontend.

🗝️ **Custom Cache Key Support**: Define a regex pattern to parse URLs based on your custom `_cache_key` format.

📊 **Monitor Plugin and Nginx Cache Status**: Monitor plugin status, cache status, and Nginx status from the Status tab.

📈 **Cache Coverage Ratio**: Live gauge in the WordPress dashboard widget showing the cache coverage ratio, based on the last preload snapshot. Refreshable on demand without a page reload.

☁️ **Cloudflare APO Sync**: Automatically mirrors NPP purge actions to Cloudflare APO to keep edge cache synchronized with your Nginx cache.

🔴 **Redis Object Cache Sync**: Bidirectional sync between NPP and Redis Object Cache. NPP Purge All flushes the Redis object cache, and a Redis flush triggers a full Nginx cache purge via NPP (when auto-purge is enabled).

🛒 **WooCommerce Auto-Purge**: Automatically purges Nginx cache when WooCommerce product stock quantity changes, stock status changes (in stock / out of stock / on backorder), or when an order is cancelled and stock is restored.

🔒 **Concurrent Purge Serialization**: Atomic lock mechanism prevents simultaneous purge operations from colliding, ensuring cache integrity during concurrent admin actions or background events.

🧩 **Modular by Design**: Easily integrate with external scripts and automation tools.

🖥️ **User-Friendly Interface**: Easy-to-use AJAX-powered settings, integrated into the WordPress admin bar and dashboard for quick access.

📋 **Admin Notices and Logs**: Receive notifications and view logs for plugin status and all cache-related actions within the WordPress admin area.

📧 **Email Notifications**: Receive email alerts upon completion of preload actions, with customizable templates to suit your needs.

== Installation ==

Manual Installation

1. Upload the "fastcgi-cache-purge-and-preload-nginx" folder to the "/wp-content/plugins/" directory.
2. Activate the plugin through the "Plugins" menu in the WordPress admin dashboard.
3. Configure plugin settings under "Settings" -> "Nginx Cache Purge Preload" in the WordPress admin dashboard.
4. Access "Nginx Cache Purge Preload" operations from the WordPress admin bar (frontend & backend), the Admin Dashboard, and the Plugin Settings page.

Automatic Installation

1. Log in to your WordPress admin panel, navigate to the "Plugins" menu and click "Add New".
2. In the search field type “Nginx Cache Purge Preload” and click "Search Plugins". From the search results, pick "Nginx Cache Purge Preload" and click "Install Now". Wordpress will ask you to confirm to complete the installation.

== Frequently Asked Questions ==

= Does this plugin require Nginx? =

Yes. NPP is designed exclusively for Nginx web servers running on Linux. It does not work on Apache, shared hosting, or environments where `shell_exec` is disabled.

= Does it require the ngx_cache_purge Nginx module? =

No. The `ngx_cache_purge` module is optional. When available, NPP uses it as the fastest purge path (HTTP Purge). If it is not present, NPP automatically falls back to a URL index lookup and then a full filesystem scan. Nothing breaks either way.

= What server dependencies are required? =

Mostly basic, built-in shell tools are required. **wget** is required for cache preloading. For hardened shell execution, **safexec** is highly recommended — see the Help tab for installation instructions.

= Why is the plugin not working on my environment? =

The most common reasons are: `shell_exec` is disabled, the PHP-FPM user lacks write permission to the Nginx cache directory, or `nginx.conf` is not detected. See the **Help tab** for a full environment checklist and solutions.

= I am getting permission errors. What should I do? =

This is the most common issue in environments where the WEBSERVER-USER (nginx/www-data) and PHP-FPM-USER are different. NPP provides a one-liner bash script to automate the fix using bindfs on monolithic servers. For containerized environments, users can review the full configuration setup via [NPP Containerized](https://github.com/psaux-it/wordpress-nginx-cache-docker) See the **Help tab → Permission Issues** section or the [GitHub repository](https://github.com/psaux-it/nginx-fastcgi-cache-purge-and-preload) for details.

= Does it work with Cloudflare? =

Yes. NPP has built-in Cloudflare APO Sync that mirrors every purge to Cloudflare’s edge cache automatically. Requires the official Cloudflare WordPress plugin. Enable it under **Settings**.

= Does it work with Redis Object Cache? =

Yes. NPP supports bidirectional sync with the Redis Object Cache plugin. A Purge All in NPP flushes Redis, and a Redis flush triggers a full Nginx cache purge. Enable it under **Settings**.

= Is it compatible with WooCommerce? =

Yes. NPP includes built-in WooCommerce Auto-Purge for stock changes and order events, and supports purging the Shop page as a related URL when a product is updated.

= Can I use it alongside other caching plugins? =

Yes, but disable page caching in other plugins to avoid conflicts. You can keep their frontend optimization features (minification, lazy loading, CDN) active. See the **Help tab** for details.

= Where can I find the allowed Nginx cache paths? =

NPP restricts cache paths to prevent accidental deletion of system files. Allowed roots are `/dev/shm/`, `/tmp/`, `/var/`, and `/cache/`. The path must be at least one level deep (e.g. `/var/cache/nginx`). Full details in the **Help tab**.

== Screenshots ==

1. Settings Tab Purge
2. Settings Tab Preload
3. Settings Tab Schedule
4. Settings Tab Mail & Logging
5. Status Tab
6. Advanced Tab
7. Help Tab
8. Front-end Admin Bar

== Changelog ==

= 2.1.5 =

Release date: 2026-03-22

* Added: MILESTONE: HTTP Purge via ngx_cache_purge module — fastest purge path when the Nginx module is present. Falls back to filesystem automatically when not available.
* Added: Purge Index — single-page purges now use a persistent URL index to skip full directory scans. Index is updated automatically after each purge.
* Added: Preload Watchdog — ensures post-preload tasks run immediately when preloading finishes, without depending on visitor traffic to trigger WP-Cron.
* Added: Mobile Floating Action Button (FAB) — logged-in admins on mobile devices get a floating action button with Purge and Preload actions on the frontend.
* Added: Frontend toast notifications — purge and preload result messages on the frontend now display as clean toast notifications.
* Added: Cache Coverage ratio in the dashboard widget and Status tab — shows live Cached / Not Cached / Total counts based on the last preload snapshot. Refreshable on demand from the dashboard widget without a page reload.
* Added: Cloudflare APO Sync — automatically mirrors NPP purge actions to Cloudflare edge cache. Requires the official Cloudflare WordPress plugin with APO or Plugin-Specific Cache enabled.(Credit: @doctorproctor)
* Added: Redis Object Cache Sync — bidirectional sync with the Redis Object Cache plugin. NPP Purge All flushes Redis; a Redis flush triggers a full Nginx cache purge.
* Added: WooCommerce Auto-Purge — automatically purges cache on stock quantity changes, stock status changes, and order cancellations.
* Added: Broken URLs list in the Status tab — tracks and displays URLs that returned 404 during the last preload crawl.
* Added: Column filter dropdowns in the Advanced tab for faster URL browsing on large sites.
* Added: Server load and PHP-FPM pool metrics in the Preload Progress section.
* Added: Cache disk/RAM size indicator in the Status tab with colour-coded thresholds.
* Added: URL Normalization status in the dashboard widget.
* Added: Persistent crawl snapshot — NPP now saves a completed preload crawl as a persistent snapshot. The Cache Analyzer (Advanced Tab) read from this snapshot, ensuring accurate data is always available between preload runs.
* Added: Expanded allowed cache paths — improved support for control panels and non-standard setups.
* Added: Non-admin users with the new nppp_purge_cache capability can trigger auto-purge on content saves without access to any NPP settings or UI.
* Added: Purge now fires on permanent post deletion and on WordPress background auto-updates.
* Added: Preload progress now shows three distinct completion states.
* Added: Smart cache key warnings based on live regex validation against the actual cache files.
* Added: Product tag and post tag archives are now included in Purge Scope related pages.
* Added: PHP Response Timeout — configurable read timeout for preload. Recommended to increase for WooCommerce stores or sites with heavy plugins.
* Added: Dashboard widget now shows HTTP Purge, Cloudflare APO, and Redis Object Cache status alongside existing indicators.
* Added: Help tab expanded with new sections covering HTTP Purge setup, Accept-Encoding, vary double-cache issue fix, Cloudflare APO Sync, Redis Object Cache Sync, Preload Watchdog, Cache Coverage Ratio, and a feature dependency map.
* Security: MILESTONE: Plugin bootstrap is now lazy-loaded — NPP stays completely dormant on requests where no cache operation is needed.
* Security: REST API now returns 403 when disabled instead of 200. Client IP resolution now validates forwarded headers against a trusted proxy list.
* Security: Cache purge is now aborted if the configured Nginx cache path is inside or overlaps the WordPress installation directory, preventing accidental deletion of WordPress files. (Credit: @doctorproctor)
* Security: CORS wildcard headers removed from REST API endpoints — cross-origin browser requests to NPP endpoints are no longer permitted.
* Fixed: Comment purge now fires only when the approved comment count actually changes, not on every comment event.
* Fixed: Published post taken offline (to draft, trash, or private) now correctly purges cache.
* Fixed: Auto-purge no longer triggers on WooCommerce orders, coupons, and other private post types that are never publicly cached.
* Fixed: Trashed post purge now uses the correct pre-trash URL instead of the WordPress-modified trashed slug.
* Fixed: Scheduled posts going live during WP-Cron now correctly purge cache.
* Fixed: Single plugin and theme updates now correctly trigger cache purge, not only bulk updates.
* Fixed: Gutenberg purge now also fires on trash and permanent delete via the block editor REST API.
* Fixed: Purge operations are now serialized with an atomic lock — concurrent purges from multiple sessions no longer collide.
* Fixed: PHP timeout is now disabled before large purge and preload operations to prevent mid-operation kills on large caches.
* Fixed: Preload flags overhauled — improved retry logic, IPv4 preference, and timeouts.
* Fixed: WP_Filesystem no longer prompts for credentials in non-interactive contexts such as WP-Cron or REST API calls.
* Fixed: MILESTONE: Advanced tab correctly retains the full MISS list immediately after a Purge All.
* Fixed: Auto-purge no longer fires on fresh install before settings have been saved.
* Fixed: GNU Wget2 (aliased as wget on some distributions) is now detected and rejected. GNU Wget 1.x is required.
* Fixed: cpulimit is now skipped entirely when the CPU limit is set to 100%
* Fixed: wp-config.php writes during Setup now use atomic temp-file replacement to prevent corruption on interrupted writes.
* Fixed: Post-preload completion tasks (email, mobile preload, cache snapshot) no longer silently fail on long preloads — replaced blocking while/sleep loop with a non-blocking tick system that never approaches PHP or Nginx execution time limits.
* Fixed: Existing tracking cron jobs and options left over from versions 2.0.1–2.1.4 are automatically cleaned up on upgrade.
* Fixed: All runtime files (PID files, logs, crawl snapshot) are now stored in wp-content/uploads instead of the plugin directory. This prevents data loss during plugin updates and avoids writing to directories that should be read-only on hardened servers.
* Fixed: safexec no longer crashes with "pathconf: Permission denied" on multi-site setups or environments where the current working directory is not traversable. safexec now switches to a safe working directory before executing.
* Fixed: REST API rate limiting now runs after authentication instead of before — unauthenticated requests can no longer exhaust rate limit slots and lock out legitimate API clients.
* Fixed: Filesystem performance significantly improved across purge, status, and cache analysis operations — related URL purges now complete in a single cache directory walk instead of one walk per URL (up to 5x less I/O on sites with Purge Scope enabled), directory iterators now use LEAVES_ONLY mode eliminating redundant directory visits, and SPL native file checks replace slower WP_Filesystem equivalents throughout.
* Fixed: Preload requests no longer fail with invalid header errors (proxy) — header and user-agent values were incorrectly wrapped in literal double-quote characters which produced malformed HTTP headers.
* Fixed: Uninstall now performs complete cleanup — all plugin options, all transient groups, runtime files (logs, PID files, crawl snapshot), the runtime directory itself, and scheduled cron hooks are all removed. Multisite installations are fully supported — cleanup runs on every site in the network.
* Fixed: Status tab Nginx Cache Paths display completely redesigned — each detected path now shows inline contextual badges (Active, Other vhost, Path Blocked) and a cache type badge (FastCGI, Proxy, SCGI, uWSGI). Active path detection now works correctly for FUSE mount setups. A reverse-proxy cache notice is shown when the active path is a proxy_cache_path (common on cPanel and Plesk). Symlinked cache paths no longer incorrectly fail the traversal check.
* Changed: Preload completion email template completely redesigned — now shows a stats dashboard with Crawl Time, URLs Crawled, Transfer Size, Average Speed, Cache Coverage, Cache Size, Broken URLs, Mobile Pass status, Trigger source, and Finish Time. Includes dark mode support and responsive mobile layout.
* Changed: Allowed Nginx cache path roots updated — /opt/ removed (too broad, risk of data loss), /cache/ added (used by GridPane, RunCloud, SpinupWP and other control panels). If your cache was stored under /opt/, move it to a supported location and re-save settings.
* Removed: All data collection and opt-in tracking completely removed. NPP collects no data whatsoever.
* Removed: Systemd service management removed — the ability to restart the npp-wordpress FUSE mount service directly from the WordPress admin has been dropped. Use standard system tools to manage the service instead.
* Compatibility: Tested with WordPress 6.9.4, PHP 8.4, Nginx 1.29.6, FUSE 3.18.2, safexec 1.9.3 and bindfs 1.18.4

= 2.1.4 =

Release date: 2025-10-04

* Major: Introduces Nginx Cache Analyzer
 * The Advanced tab is now a unified cache dashboard that makes cache status obvious and actionable.
 * See one clean list that combines URLs from your last preload and what’s currently in the cache—HITs and MISSes together.
 * Treat it like a "site crawl snapshot": You can quickly review and analyze your whole actual Nginx Cache (HIT/MISS) and take action in one window.
 * Instantly spot pages that aren’t cached (MISS) and Preload them right away (or purge specific URLs) to keep performance sharp.
* Major: Introduces safexec (privilege-dropping wrapper)
 * Hardened backend for NPP (written in C) to safely run PHP's shell_exec() commands. (Check Plugin Help tab)
 * Drops privileges (nobody) and scrubs the environment; also normalizes URLs during Preload to avoid encoding-based cache misses.
 * Control percent-encoded URLs during Preload (modes: OFF, PRESERVE, UPPER, LOWER)
 * Recommended for all users concerned about shell_exec usage in NPP.
* Major: Introduces Purge Scope (Related Pages) (Credit: @pasqualerussi)
 * Choose to always purge the Homepage, Shop page, Category archives, related to the item you just purged.
 * You can enable "Related Purge" when you manually purge a URL, and/or when WordPress auto-purges on content updates.
 * After a purge, also the plugin can immediately Preload those related URLs so they’re ready in cache again.
* Major: Introduced Setup Wizard for first-time configuration. (Credit: @frallard)
 * Added Assume-Nginx Mode for cases where Nginx detection fails or nginx.conf is inaccessible (e.g., behind proxies, in containers, or on Plesk/cPanel).
 * Automatic disable of Assume-Nginx when real nginx.conf detected.
 * Improved Nginx detection via HTTP headers and system signals.
* Major: Optimized disk I/O performance for large sites.
* UI/UX improvements: Toast notifications
* Optimized Elementor and Gutenberg compatibility on Auto Purge.
* Numerous polish updates and bug fixes, security updates, plus a new header animation that represents NPP.

= 2.1.3 =

Release date: 2025-07-22

* PATCHED: CVE-2025-6213 — Prevent command injection via ['HTTP_REFERER'] (Credit: @cynau1t)
* Fixed: UTF-8 decoded URLs are now correctly displayed in the Advanced tab for improved readability (Credit: @XCJYO)
* Fixed: Percent-encoded URL normalization (uppercase vs lowercase) to prevent cache miss via mismatched encodings (Credit: @XCJYO)
* Fixed: Fatal error in CLI context caused by undefined FS_CHMOD_FILE when running WP-CLI (Reported by: @sergeybv)
* Fixed: Preload completion time and last preload timestamp now display accurately
* Fixed: Addressed several WordPress Plugin Check (PCP) compatibility warnings and false positives
* Added: Real-time Preload Progress Monitor in the Status tab, with visual feedback and progress bar
* Added: Proxy support for preload operations, including validation and status checks
* Compatibility: Tested with WordPress 6.8.2

= 2.1.2 =

Release date: 2025-06-23

* Fix leaking HTML into WP core API responses
* Fix plugin name under Settings menu
* Fix mobile layout issues
* Fix plugin not a valid header issue
* Fix Status tab render issue
* Fix Auto Purge triggers twice
* Bump external assets to latest versions
* Tested with WordPress 6.8.1

= 2.1.1 =

Release date: 2025-03-17

* Changed plugin name to "Nginx Cache Purge Preload"
* Other minor improvements

= 2.1.0 =

Release date: 2025-02-23

Major Release: 46 files changed, 5,170 additions, 1,410 deletions.
Now fully supports internationalization,
enabling complete translation for a global user base.

* Added support for internationalization (i18n).
* Added support for Nginx cache for PROXY, SCGI, and uWSGI.
* Added support for Nginx cache status widget in the WordPress dashboard.
* Added support for deep hash linking with jQuery UI Tabs.
* Added support for better UI/UX for various elements.
* Improved compatibility with containerized environments. (Marc-Antoine Lalonde, Pawel Strzyzewski)
* Resolved issue where auto purge was not working on post/page content updates.
* Resolved issue where theme switch or theme update triggered purge and preload actions twice.
* Resolved issue where tabs were stuck and hanging on switch with admin bar and internal clicks
* Resolved issue with preload process completion time accuracy.
* Resolved issue with plugin tracking cron event handling.
* Resolved issues with false detections inside the Status Tab.
* Resolved issue with front-end action messages for better clarity.
* Resolved various PCP (Plugin Check) errors.
* Resolved issue with false positives in certain validation checks.
* Resolved issue with preload features not being disabled correctly.
* Resolved issue with WP purge handling and process exits.
* Resolved issue with page reload time.
* Updated error and success messages for clarity.
* Updated external assets to latest versions.
* Updated Plugin logo and plugin header assets.
* Updated plugin readme.txt

= 2.0.9 =

Release date: 2024-11-30

Milestone: Add support for preloading cache separately for Mobile devices
Milestone: Resolved the long-standing issue prior to version 2.0.5,
where users encountered a "Not a valid JSON response" error.

* Add support for preloading cache separately for Mobile devices
* Add support for auto purge also on POST/PAGE status changes (draft, publish, trash e.g)
* Resolved issue with cache purge when switching themes
* Resolved issues with fetching the latest libfuse and bindfs versions on the Status tab
* Resolved issue with NPP admin notices interfering with core wp REST actions (mrj0b)
* Resolved stopping auto-preloading during concurrent auto-purge actions
* Replaced posix_kill with shell_exec to determine if a process is running efficiently
* Replaced custom URL validation regex with PHP's built-in FILTER_VALIDATE_URL for improved efficiency
* Relaxed cache key regex options to allow parsing into two capture groups for increased flexibility (Tiago Bega)
* Forced update of the default cache key regex to support the new structure
* Update plugin feature descriptions on settings page

= 2.0.8 =

Release date: 2024-11-24

* Fix the plugin does not have a valid header error
* Fix admin notices interfere with core WP screens
* Add support for logging the Preload process handling

= 2.0.7 =

Release date: 2024-11-22

* Add support for a fallback mechanism to kill the ongoing preload process if SIGTERM is not defined (mrj0b)
* Add support for auto purge entire cache on plugin activation and deactivation
* Add support for auto purge entire cache when the active theme is switched
* Add support on clear plugin cache on NPP updates
* Fix auto purge entire cache triggers multiple times for bulk actions
* Fix the webserver user parsing issue with semicolons (mrj0b)
* Fix permission isolation status indicate incorrect in Status tab (mrj0b)
* Fix undefined SIGTERM for cross-platform compatibility (mrj0b)
* Fix POSIX extension is not a hard dependency
* Fix auto purge to triggers for all theme updates, not just the active one
* Fix 'Not a valid JSON response' error on Auto Purge (mrj0b)
* Update Auto Purge feature description for clarity
* Tested up to: 6.7.1

= 2.0.6 =

Release date: 2024-11-21

* Fix permission checks during cache purge
* Resolve styling issue on the Status tab
* Fix auto-purging cache for unpublished posts/pages
* Prevent admin notices from interfering with core WP AJAX responses
* Fix page cache count to process only GET request methods
* Fix cache key regex validation
* Improve compatibility with Autoptimize plugin

= 2.0.5 =

Release date: 2024-11-17

Now more powerful with custom fastcgi_cache_key support.
Here's the short changelog for version 2.0.5, with contributors proudly mentioned.

* Fixed the 'dot' issue in the cache path (@coldrealms65)
* Support for auto purge when compatible caching plugins trigger purge (@coldrealms65)
* Added support for custom fastcgi_cache_key formats with user-defined regex under the new Advanced Options section (@coldrealms65)
* Execution no longer stops in the Advanced tab if an unsupported fastcgi_cache_key is found (@mrj0b)
* Execution stops in the Status tab if nginx.conf is not found or readable
* Use FUSE mount system instead of inotifywait/setfacl to manage permission issues in the bash helper script (@coldrealms65)
* New FUSE Status in the STATUS tab showing FUSE mount related metrics
* Added new allowed Nginx Cache Paths for flexibility: /tmp for RAM-based and /opt for persistent disk caches
* Added nppp_purged_all hook for other plugins to trigger their cache purge after all Nginx cache purged
* Improved nginx cache path validation
* Improved empty cache detection
* Improved permission check logic
* Improved Help tab tutorials
* Improved Status tab to accurately highlight supported and unsupported results for UX/UI
* Store more expensive key performance metrics in cache to enhance performance
* Updated feature descriptions for clarity
* Clear plugin cache on uninstall

= 2.0.4 =

Release date: 2024-10-10

This is a massive update: 39 changed files, 3,392 additions, and 1,063 deletions.
Here the short changelog for version 2.0.4

* Add support on Auto Purge when a Theme or Plugin is updated
* REST API improvements, rate-limiting & security & logging and more
* Add new Cache Date & Cache Method columns to Advanced tab
* Better handle fastcgi_cache_key format and warn user for non standart setups
* Better handle Content Category in Advanced tab
* Keep found Content Categories in cache to optimize Advanced tab performance
* Lots of UI/UX optimizations on desktop and mobile, sticky form submission button & preloader and more
* Fix Nginx Cache Path front-end sanitization that prevent manual slash usage
* Enhance wp_filesystem initialization
* Update external assets to latest version, jQuery UI v1.13.3, datatables v2.1.8
* Use minified version of main plugin assets to optimize load times
* Optimize Preload action, don't use -m mirroring anymore, use -r instead
* Add new Preload feature, Exclude File Extensions
* If one-liner bash script used, NPP now force create Nginx Cache Path
* Use nohup to detach wget completely from PHP
* Fix plugin options deleted after deactivation
* Drop lots of redundant code to improve performance
* Improve help section and feature descriptions
* Fix Plugin Check (PCP) errors and warnings
* Add plugin tracking code to collect basic data to improve plugin development
* Improved the Status tab to more effectively determine permission status
* Prevent interfere with core wp and other plugin code

= 2.0.3 =

Release date: 2024-08-09

* Add support for Auto-Purging the Nginx cache based on comment events, such as comment approval or comment status changes
* Optimized Status Tab, handling of finding active Nginx Cache Paths, PHP process owners and other metrics
* Enhanced performance by caching results of recursive permission checks and reducing expensive directory traversals
* Add support for restarting systemd services and managing systemd-related tasks directly from front-end
* Made numerous improvements to the core plugin code to enhance UI/UX and performance
* Version bumps for external assets
* Tested up to: 6.6.1

= 2.0.2 =

Release date: 2024-06-30

* Add support on Auto Purge (POST/PAGE whenever its content is updated)
* Add support on new --wait option (Manage server load while cache preloading)
* Auto Preload now supports also single POST/PAGE cache preloading when Auto Purge enabled
* Improve Nginx cache preload performance (--no-check-certificate)
* Improve UI/UX (regroup plugin settings, add notification for saving AJAX-powered plugin options)
* Improve Help tab informations
* Globally prevent purging cache while cache preloading is in progress (Onpage Purge & Auto Purge & Manual Purge)
* Improve Help tab informations
* Improve plugin settings descriptions
* Enhance handling of disable functionality in unsupported environments
* Version bumps for assets
* Style and typo fixes
* Tested up to: 6.5.5

= 2.0.1 =

Release date: 2024-05-24

* Fix Generic function/class/define/namespace/option names
* Fix Not permitted files
* Fix properly enqueue inline js
* Fix Internationalization
* Fix Calling files remotely
* Fix Out of Date Libraries
* Fix Sanitize, Escape, and Validate

= 2.0.0 =

Release date: 2024-04-24

* Add support on Auto Preload
* Add support on WP Cron Scheduled Preload
* Add support on REST API remote Purge and Preload
* Add support on front-end on-page Purge and Preload
* Add support on manual Purge and Preload actions in Advanced tab
* New e-mail template for mail notifications
* Improved status tab checks, added Nginx status summary, cache summary 
* Style and typo fixes
* Security optimizations
* Tested up to Wordpress 6.5.3

= 1.0.3 =

Release date: 2024-03-28

* Re-organize code structer for better readability
* Security optimizations
* Change plugin icon
* Add admin bar icon
* Fix nonce verification issue
* Fix styling & typo
* Improve plugin status tab, now also checks PHP-FPM user and setup
* Tested up to Wordpress 6.5

= 1.0.2 =

Release date: 2024-03-20

* Code optimizations
* Security optimizations
* Fix styling & typo
* Add support on new plugin status tab
* Add important note for purge operation
* Better handle purge operations
* Better handle ACLs checks
* Use WP Filesystem to purge cache instead of shell find +delete
* Remove temporary downloaded content after purge & preload operation
* Escape shell commands properly
* Better handle wp filesystem

= 1.0.1 =

Release date: 2024-03-15

* Fix logs handling
* Tested up to Wordpress 6.4

= 1.0.0 =

Release date: 2024-03-14

* Initial release.

== Upgrade Notice ==

= 2.1.5 =
Security and data-safety fixes included. Upgrade immediately.

= 2.1.4 =
Introduces Nginx Cache Analyzer, safexec hardened execution, Purge Scope for related pages, and a Setup Wizard. Recommended upgrade for all users.

= 2.1.3 =
Security patch for CVE-2025-6213. Upgrade immediately.

= 2.0.1 =
Important fixes for function/class/define/namespace/option names. Internationalization and security improvements.

== Credits ==

This plugin is developed and maintained by Hasan CALISIR.

== Privacy Policy ==

Prior to version 2.1.5, NPP optionally collected basic anonymous usage data when users explicitly opted in. As of version 2.1.5, all data collection and the opt-in mechanism have been completely removed. NPP collects no data whatsoever.

== Support ==

For support and assistance, please contact Hasan CALISIR at hasan.calisir@psauxit.com.

== License ==

This plugin is licensed under the GPLv2 or later.

== Other Notes ==

For more information, visit the plugin development page: [Nginx Cache Purge Preload](https://github.com/psaux-it/nginx-fastcgi-cache-purge-and-preload)
