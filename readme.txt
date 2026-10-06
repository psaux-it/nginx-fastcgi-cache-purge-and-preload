=== Nginx Cache Purge Preload ===
Contributors: psauxit
Donate link: https://github.com/sponsors/psaux-it
Tags: nginx, cache, purge, preload, performance
Requires at least: 6.5
Requires PHP: 7.4
Tested up to: 7.0
Stable tag: 2.1.7
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Nginx cache purge and preload for WordPress (FastCGI, Proxy, SCGI, uWSGI), plus a live Fail2Ban dashboard with attack maps and abuse reports.

== Description ==

**NPP** (Nginx Cache Purge Preload) lets you purge and preload your Nginx cache (FastCGI, Proxy, SCGI, uWSGI) straight from the WordPress dashboard. Built-in cache warming re-crawls your site after a purge, so pages are served from the Nginx cache again before visitors arrive.

Purge a single URL or the whole cache using the fastest method your server supports, automate it on content changes, and control everything from the admin bar, REST API or WP-CLI.

Supports `fastcgi_cache`, `proxy_cache`, `scgi_cache` and `uwsgi_cache`, and integrates with WooCommerce, Cloudflare APO and Redis Object Cache. Running Fail2Ban? NPP also works as a security dashboard for it, see below.

= 🛡️ Fail2Ban Dashboard =

Run Fail2Ban on your Nginx server? See what it blocks, right inside WordPress. Fail2Ban keeps blocking; NPP shows you who, from where, and how often.

* **Live ban & unban feed** with jail activity and repeat offenders
* **Top Attack Countries** world map
* **Automatic network lookup**: owner, ASN, country and abuse contact
* **One-click abuse reports** to the offending network

Setup is three copy-paste steps in the Fail2Ban tab. See the FAQ below.

= 🖥️ Quick WP-CLI =

Manage your Nginx cache and Fail2Ban dashboard entirely from the command line. Perfect for automation, cron jobs, panels, and headless workflows.

* **Purge:** `wp npp purge` | `wp npp purge --page-url=<url>`
* **Preload:** `wp npp preload` | `wp npp preload --page-url=<url>` | `wp npp preload --stop`
* **Fail2Ban:** `wp npp f2b status` | `wp npp f2b offenders --min-bans=3` | `wp npp f2b events --type=ban --limit=20 --format=json`
* **Full command list:** `wp help npp` | `wp help npp <subcommand>`

= 🔗 Resources =

* Visit the [NPP Main Development Repository](https://github.com/psaux-it/nginx-fastcgi-cache-purge-and-preload) - Docs, issues & contributions and more.
* Visit the [safexec Main Development Repository](https://github.com/psaux-it/nginx-fastcgi-cache-purge-and-preload/tree/main/safexec) - NPP's privilege-dropping C wrapper.
* Explore [NPP Containerized](https://github.com/psaux-it/wordpress-nginx-cache-docker) - Ready to run full stack Nginx Docker setup.
* Follow the [Complete Setup & Guide](https://www.psauxit.com/nginx-fastcgi-cache-wordpress-guide/) - Nginx config, permissions, purge, preload and troubleshooting, step by step.
* Read the [NPP Main Architecture](https://www.psauxit.com/nginx-fastcgi-cache-purge-preload-for-wordpress/) - Technical deep dive article.
* Refer to the plugin’s **Help tab** for more deeper additional guidance.

= Features =

**Purge All Nginx Cache**: Completely clear all cached data stored by Nginx.

**Preload All Nginx Cache**: Warm the Nginx cache with the most recent data for the entire website.

**HTTP Purge (ngx_cache_purge)**: When the Nginx cache module (ngx_cache_purge) is available, NPP uses it as the fastest purge path. Falls back gracefully to index and filesystem purge when the module is not present.

**INDEX Purge**: Leverages the preload-generated URL → Filepath index to directly resolve and purge cache without filesystem traversal. If the cache exists and is valid, purge is executed immediately for near-instant performance.

**RG Purge**: ripgrep-powered cache file lookup that replaces slow PHP directory scanning for single-URL purges, enabling near-instant deletes on large or FUSE-mounted caches. Automatically falls back to standard scan if unavailable.

**Auto Preload Nginx Cache**: Automatically preloads the cache when Auto Purge is enabled for a POST/PAGE or after the Purge All action.

**Auto Purge Nginx Cache**: Purge cache on Post/Page content changes, comment status updates, theme/plugin updates, or when compatible Cache Plugins trigger a purge. Nginx cache is preloaded automatically if Auto Preload is enabled (for the entire site or individual page).

**Purge Scope (Related Pages)**: Automatically purge related pages such as the Homepage, WooCommerce Shop page, and Category/Tag archives when a single URL is purged. Optionally preload those pages to keep the cache warm.

**Preload Feeds**: Take full control of RSS/Atom feed caching. Decide site‑wide or per‑URL whether the main feed, per‑post comment feeds, and taxonomy RSS feeds are included in the preload process.

**Schedule Nginx Cache Purge & Preload via WP Cron**: Automate the purge and preload process using WordPress Cron jobs.

**Proxy Support for Preload**: Route preload requests through a proxy server for edge-case environments and containerized deployments.

**Live Preload Progress Monitoring**: Watch the Nginx cache preload process in real time — complete with a dynamic progress bar, currently processed URL, 404 tracking, server load, and total completion time.

**Remote Nginx Cache Purge & Preload via REST API**: Remotely trigger cache purging and preloading through REST API endpoints.

**Manual Nginx Cache Purge & Preload**: Allow manual purging and preloading of cache through the table view in the Advanced Tab.

**Nginx Cache Analyzer**: Full HIT/MISS cache analyzer dashboard, from the last preload crawl with what is currently stored in the Nginx cache. Instantly spot uncached pages and Purge or Preload them directly in the Advanced Tab.

**Preload All MISS**: A targeted replacement for the expensive Preload All. Instead of purging and re‑caching everything (Preload All), it targets and populates only the cache entries that are not yet warmed. Perfect for sites with high cache coverage (>50%) where a full Preload All would be wasteful.

**On-Page Nginx Cache Purge & Preload**: Manually purge and preload Nginx cache for the currently visited page directly from the frontend.

**Custom Cache Key Support**: Define a regex pattern to parse URLs based on your custom `_cache_key` format.

**Monitor Plugin and Nginx Cache Status**: Monitor plugin status, cache status, and Nginx status from the Status tab.

**Fail2Ban Dashboard**: Live ban feed, repeat offenders, attack-country map, automatic IP network lookup and optional abuse reports. Fail2Ban keeps blocking; NPP adds the visibility.

**Cache Coverage Ratio**: Live gauge in the WordPress dashboard widget showing the cache coverage ratio, based on the last preload snapshot. Refreshable on demand without a page reload.

**Cloudflare APO Sync**: Automatically mirrors NPP purge actions to Cloudflare APO to keep edge cache synchronized with your Nginx cache.

**Redis Object Cache Sync**: Coordinates cache invalidation between NPP and Redis Object Cache during NPP-driven operations. NPP Purge All and Preload workflows handle Redis flushing at the appropriate stage to ensure both cache layers stay aligned.

**WooCommerce Auto-Purge**: Automatically purges Nginx cache when WooCommerce product stock quantity changes, stock status changes (in stock / out of stock / on backorder), or when an order is cancelled and stock is restored.

**WP-CLI Support**: Manage Nginx cache directly from the command line. Purge and preload cache, view status reports, inspect logs, update settings, flush cache, and manage scheduled tasks without accessing the WordPress admin dashboard.

**Concurrent Purge Serialization**: Atomic lock mechanism prevents simultaneous purge operations from colliding, ensuring cache integrity during concurrent admin actions or background events.

**Modular by Design**: Easily integrate with external scripts and automation tools.

**User-Friendly Interface**: Easy-to-use AJAX-powered settings, integrated into the WordPress admin bar and dashboard for quick access.

**Admin Notices and Logs**: Receive notifications and view logs for plugin status and all cache-related actions within the WordPress admin area.

**Email Notifications**: Receive email alerts upon completion of preload actions, with customizable templates to suit your needs.

== Installation ==

Manual Installation

1. Upload the "fastcgi-cache-purge-and-preload-nginx" folder to the "/wp-content/plugins/" directory.
2. Activate the plugin through the "Plugins" menu in the WordPress admin dashboard.
3. Configure plugin settings under "Settings" -> "Nginx Cache Purge Preload" in the WordPress admin dashboard.
4. Access "Nginx Cache Purge Preload" operations from the WordPress admin bar (frontend & backend), the Admin Dashboard, and the Plugin Settings page.

Automatic Installation

1. Log in to your WordPress admin panel, navigate to the "Plugins" menu and click "Add New".
2. In the search field type “Nginx Cache Purge Preload” and click "Search Plugins". From the search results, pick "Nginx Cache Purge Preload" and click "Install Now". WordPress will ask you to confirm to complete the installation.

== Frequently Asked Questions ==

= Is NPP a trustworthy and secure plugin? =

Yes. NPP is engineered with a highly defensive, multi-layered "Narrow Gate" architecture using a lazy bootstrap mechanism to minimize your server's attack surface. To minimize performance overhead and vulnerability surfaces, NPP stays dormant on anonymous public traffic, verifying entry paths before heavy core files ever load into memory.

For hardened environment security, it also ships with its own secure privilege-dropping wrapper, `safexec`—a custom C-binary utility designed to isolate system commands and execute them under a low-privilege runtime user.

= Does NPP require Nginx? =

Yes. Nginx must be part of your web server stack. It does not matter if Nginx is configured as a frontend reverse proxy or as the direct backend server—as long as Nginx is handling the caching layer on a Linux environment, the plugin works perfectly.

It does not work on standalone Apache setups, restricted shared hosting, or environments where `shell_exec` and `exec` are disabled.

= Can I monitor Fail2Ban from WordPress? =

Yes. NPP shows your Fail2Ban bans and unbans in a live dashboard with jail activity, repeat offenders, a Top Attack Countries map and network details per IP. It is read-only: Fail2Ban keeps blocking as before, and NPP never bans, unbans or edits your jails. The feature is optional and separate from cache management.

= How do I connect Fail2Ban to NPP? =

Open the Fail2Ban tab, copy the generated `nppp-webhook` action file to `/etc/fail2ban/action.d/`, add the generated line under each jail in `jail.local`, run `sudo systemctl reload fail2ban`, then press Test Connection. The Fail2Ban host needs `curl` and `flock` and must be able to reach your site. Several servers or jails can report to one dashboard.

= Is the Fail2Ban webhook secure? =

Yes. It requires a secret token you can regenerate at any time, validates every field, ignores replayed events and rate-limits requests. The tab also provides an optional Nginx rate-limit snippet, and rejected requests are listed in the Endpoint Attacks card.

= Are abuse reports sent automatically? =

No. A report is sent only when an administrator clicks Send in the Fail2Ban tab or runs the WP-CLI command. You can preview each report, send a test email, use dry-run mode, and set a minimum ban count and a per-IP cooldown.

= What does the Fail2Ban dashboard store, and for how long? =

The banned IP, jail, event type, timestamp and looked-up network details, in a dedicated database table. Events are deleted after 90 days by default, can be cleared from the Fail2Ban tab, and are removed on uninstall. See the Privacy Policy and External services sections.

= Does NPP require the ngx_cache_purge Nginx module? =

No. The `ngx_cache_purge` module is optional. When available, NPP uses it as the first purge path (HTTP Purge). If it is not present, NPP automatically falls back to a URL index lookup and then a full filesystem scan. Nothing breaks either way.

Currently relying on `ngx_cache_purge` module today introduces unnecessary security and stability risks to your server and not recommended.

* **Unproven Security (Lack of updates)**: The original module project has not been actively maintained for several years. It receives no routine security reviews, vulnerability patches, or bug fixes.
* **Unreliable Codebase (Fragmented availability)**: Because the original project stalled-abandoned, the community has created multiple independent "forks" just to keep it working. This fragmentation makes it nearly impossible to guarantee which version is actually secure or fully compatible with your nginx config and version.
* **Vulnerable Defaults (Outdated system packages)**: Due to the lack of an proven, and trusted release, most Linux distributions default to shipping outdated, decade-old versions of this module. These legacy builds lack modern security standards and frequently experience compatibility issues in real-world production scenarios.

= Which command-line utilities and tools are required? =

Mostly basic, built-in shell tools are required. **wget** is required for cache preloading. For hardened shell execution, **safexec** is highly recommended — see the Help tab for installation instructions. For large cache-heavy websites, especially when Nginx cache paths are located on FUSE-based mounts (such as bindfs to solve permission issues), **ripgrep (rg)** is strongly recommended for significantly faster cache purge performance.

= Which PHP functions must be removed from disable_functions? =

**Hard Dependencies**

* **`shell_exec`** – The primary dependency used for core plugin functionality.
* **`exec`** – Required for executing `ripgrep` RG Purge.

**Optional Dependencies:**

* **`proc_open`, `proc_close`, `proc_get_status`** – Required for the health check of background preload process.
* **`putenv`, `getenv`** – Required to pass critical runtime flags to the `safexec` and drive the **URL Normalization** feature.

= Why NPP not working on my environment? =

The most common reasons are: `shell_exec` and `exec` is disabled, `open_basedir` restrictions prevent required filesystem access, the PHP-FPM user lacks write permission to the Nginx cache directory, or Nginx detection fails (`nginx.conf` cannot be located). See the **Help tab** for a full environment checklist and solutions.

= I am getting permission errors. What should I do? =

This is the most common issue in environments where the WEBSERVER-USER (nginx/www-data) and PHP-FPM-USER are different. NPP provides a one-liner bash script to automate the fix using **bindfs** on monolithic servers. For containerized environments, users can review the full configuration setup via [NPP Containerized](https://github.com/psaux-it/wordpress-nginx-cache-docker) See the **Help tab → Permission Issues** section or the [GitHub repository](https://github.com/psaux-it/nginx-fastcgi-cache-purge-and-preload) for details.

= Does NPP work with Cloudflare? =

Yes. NPP has built-in Cloudflare APO Sync that mirrors every purge to Cloudflare’s edge cache automatically. Requires the official Cloudflare WordPress plugin. Enable it under **Settings**.

= Does NPP work with Redis Object Cache? =

Yes. NPP supports **Redis Object Cache** Sync, which keeps Redis and the Nginx cache aligned during purge and preload operations. When enabled, NPP flushes the Redis object cache at the correct point in the Nginx Purge + Preload chain to ensure fresh content is used when rebuilding cache. Enable it under **Settings**.

= Is NPP compatible with WooCommerce? =

Yes. NPP includes built-in WooCommerce Auto-Purge for stock changes and order events, and supports purging the Shop page as a related URL when a product is updated.

= Can I use NPP alongside other caching plugins? =

Yes, but disable page caching in other plugins to avoid conflicts. You can keep their frontend optimization features (minification, lazy loading) active.

For the best results, NPP pairs best with lightweight, minification-only trusted plugins. Because Nginx already handles your page caching instantly at the server level, using heavy, code-bloated page caching plugins only introduces unnecessary overhead even you disable page caching features. Keeping your frontend optimization layer as lean as possible is the ideal match for high-speed Nginx caching.

= Where can I find the allowed Nginx cache paths? =

NPP restricts cache paths by default to prevent accidental deletion of system files. Allowed roots are `/dev/shm/`, `/tmp/`, `/var/`, and `/cache/`. The path must be at least one level deep (e.g. `/var/cache/nginx`). These restrictions can be completely disabled using the **Bypass Path Restriction** feature, which removes all path safety guardrails and allows any directory to be used as the Nginx cache path. Full details in the **Help tab**.

= Does NPP support WP-CLI? =

Yes. NPP includes full WP-CLI integration. You can purge, preload, check status, view logs, update settings, flush transients, and manage scheduled events directly from the terminal — no admin dashboard access required. Run `wp help npp` and `wp help npp <subcommand>` to see all available commands.

= What Nginx cache types are supported by NPP? =

NPP supports **FastCGI**, **Proxy**, **SCGI**, and **uWSGI** cache methods. The plugin automatically applies the appropriate purge strategy (HTTP Purge, INDEX Purge, or RG Purge).

= What is HTTP Purge and how do I set it up? =

HTTP Purge is an optional fast‑path that uses Nginx's `ngx_cache_purge` module to delete cache entries via a simple HTTP request. It is the fastest possible single‑URL purge method because it avoids any filesystem scan.

= What is RG Purge and should I enable it? =

RG Purge is a much faster alternative to the traditional PHP filesystem scan. It uses `ripgrep` (rg) — line-oriented search tool written in Rust — to locate nginx cache files in a fraction of the time.

On a typical website with thousands of cached URLs, the built‑in PHP recursive iterator can take anywhere from 10 to 60 seconds to scan the disk and locate cache files for a single purge event. RG Purge reduces this cleanup time to **1–2 seconds regardless of cache size** by using parallel directory traversal and memory‑mapped I/O.

* **When to enable:** Strongly recommended for large or high-traffic sites with over 10,000 URLs, or any environment where Nginx cache paths are stored on slower disk arrays or FUSE-based mounts (like bindfs).

= What is Index Purge and how does it speed up single‑URL purges? =

During every cache preload, NPP quietly builds a persistent URL‑to‑filepath index. When you later purge a specific page (manually, or via auto‑purge), the plugin first checks this index. If the URL is found, NPP instantly knows the exact cache file location and deletes it immediately — **with zero disk scanning**. This is the second‑fastest purge path (FP2) and works even on sites with hundreds of thousands of cached files. The index is automatically updated after each purge and can be cleared manually from the Status tab if your cache structure changes.

= What is safexec and why is it used? =

**safexec** is a small, privilege‑dropping wrapper (written in C) created specifically for NPP. It is used to safely execute system commands like `wget`, and `rg` while enforcing strict security controls.

**What it does?**

* Drops privileges to a low‑privilege user (`nobody`) before running the shell command.
* Scrubs the environment to prevent information leaks.
* Optionally normalizes percent‑encoded characters in URLs during cache preloading — solving a common cause of duplicate cache entries (e.g., `%2F` vs `/`).

Without `safexec`, traditionally the plugin runs shell commands with the same permissions as the PHP process owner. **It adds an extra layer of isolation.**

* In environments where you want to mitigate the risk and impact of potential command injections.
* In FUSE environments where the PHP user lacks access to the underlying cache directory — `safexec` can temporarily assume the Nginx user's identity to read directly from the real path for faster Purge operations.

**URL Normalization**

In Preload Settings you can choose how `safexec` handles percent‑encoded URLs.

* **OFF** – No normalization (default).
* **UPPER** – Convert all percent‑encoded sequences to uppercase (e.g., `%2f` → `%2F`).
* **LOWER** – Convert to lowercase.
* **PRESERVE** – Keep original casing but still normalize unreserved characters.

This is crucial if your Nginx `$request_uri` cache key treats different entries, which can lead to cache fragmentation. Choose the mode that matches your Nginx configuration.

**safexec** is entirely optional. NPP works without it, but for production sites, especially those with FUSE mounts or strict security requirements, it is highly recommended.

= Does NPP cache pages that contain a question mark (?), like filters or UTM tags? =

Yes. NPP's preload engine no longer blindly ignores all query‑string URLs. It uses a smart list of unsafe parameters (cart, checkout, admin, preview, etc.) and allows everything else — sorting options, product filters, language switches, UTM marketing tags — to be preloaded and served from cache. This dramatically increases cache coverage for WooCommerce stores and busy content sites.

= Can the cache be automatically preloaded after a purge? =

Yes. When Auto Purge is enabled and Auto Preload is switched on (globally or per‑page), NPP will immediately begin preloading the affected pages after a purge — no manual steps required. This keeps the cache warm even after content updates, stock changes, or theme/plugin upgrades.

= Does NPP preload RSS and Atom Feeds? =

Yes. NPP includes a "Preload Feeds" option that warms your main RSS2, Atom, per‑post comment feeds, and taxonomy‑specific feeds (categories, tags, custom taxonomies). This ensures feed readers, RSS‑to‑email services, and search engine crawlers always receive a fast, cached response — without waking up PHP on every refresh.

= How is NPP's purge system different from other Nginx cache plugins? =

Most Nginx cache plugins rely on a single purge method — usually a slow, recursive PHP scan of every file in the cache directory, or they require a special Nginx cache module. NPP uses a **layered fast‑path chain** that automatically picks the fastest available method for every single‑URL purge, without you needing to configure anything.

* **FP1 – HTTP Purge** – If the `ngx_cache_purge` module is present, NPP instructs Nginx to delete the cache entry via a simple HTTP request. Near‑instant.
* **FP2 – Index Purge** – If the URL is already indexed from a previous preload, NPP finds the cache file path in memory — zero disk scanning.
* **FP3 – RG Purge** – If `ripgrep` is installed, a single, highly optimized system command locates all matching cache files in 1–2 seconds, even on directories with 50,000+ files.
* **FP4 – PHP recursive scan** – The traditional method; used only as a silent fallback when nothing faster is available.

Key benefits of this approach!

* No single point of failure — if one method isn’t available, the next one takes over automatically.
* Your purges are always as fast as your server environment allows, **without changing any settings**.
* You can improve purge speed later simply by installing `ripgrep` or enabling the Nginx cache module (not currently recommended) — the plugin adapts instantly.

This layered approach keeps purges fast and safe even on large WooCommerce sites and high-traffic news platforms.

== Screenshots ==

1. Purge Options (Cache Path Settings)
2. Purge Options (Auto Purge - Cloudflare - Redis)
3. Purge Scope (Related Page Purge)
4. Preload Options (Auto Preload, Preload Mobile, Mobile User Agent)
5. Preload Options (Preload Watchdog, Proxy, URL Normalization, CPU Limit)
6. Preload Options (Exclude Endpoints, Exclude Extensions, Limit Rate, Wait Time)
7. Schedule Options (WP-Cron, REST API)
8. Advanced Options (Cache Key Regex)
9. Advanced Options (HTTP Purge, RG Purge)
10. Mail Options - Logging Options
11. Status Tab (Clear Plugin Cache, Clear URL Index)
12. Status Tab (Live Preload Progress, Nginx FUSE Status)
13. Status Tab (System Checks, Cache Status)
14. Advanced Tab
15. Help Tab
16. Dashboard Widget
17. Setup Page
18. Mail Template

== Changelog ==

= 2.1.8 (2026-09-25) =

* NEW: "Fail2Ban Dashboard" — a token-authenticated webhook turns fail2ban ban/unban events into a live jail activity dashboard with a "Repeat Offenders" panel and a "Top Attack Countries" map, plus an optional Abuse Reporter that emails the offending network's abuse desk using RIPEstat-resolved contacts.
* Fixed: "Purge All" now terminates the preload watchdog before stopping the main preload process, closing a post-completion race window that could leave stale preload state behind.
* Fixed: WP-CLI "preload --stop" no longer clears the preload pid when the underlying process termination fails (safexec), preventing broken preload state and subsequent workflow failures.
* Fixed: WP-CLI "preload --stop" now performs complete preload state cleanup, including scheduled cron events, transients, and watchdog tokens.
* Fixed: Cancelling the schedule (CLI or Settings) no longer interrupts an already-running preload's completion tick — it now only prevents future scheduled runs. (Credit: @claude sonnet)
* Fixed: Active WP cron events are now cancelled immediately when plugin requirements fail mid-operation, preventing orphaned cron jobs from firing after functionality is disabled.
* Fixed: Several disable-functionality and disable-preload guard were missing coverage for new and existing options introduced before.
* Fixed: Setup hooks and Settings field registrations were both running on every admin AJAX request and non-Settings admin page, firing redundant HEAD probes on each admin tab switch and registering fields that had no consumers outside the Settings page.
* Fixed: Removed redundant "jquery-ui.theme.min.css" enqueue and deleted the unused asset file.
* Fixed: (autoload) Removed PHP 8-only named argument breaking PHP 7.4 compatibility.
* Fixed: (wp-cli) Replaced PHP 8 match() expressions with if/elseif for PHP 7.4 backward compatibility.
* Fixed: Coalesced multiple automatic full cache purges triggered within the same request lifecycle into a single execution during HTTP shutdown. Bulk plugin/theme actions previously executed sequential full purges and triggered competing auto-preloads; purges are now deduplicated per cache path and run once at priority -1. Manual purges (Admin Bar, REST API, WP-CLI) remain immediate and synchronous.
* Fixed: Suppressed automatic preloading when the plugin itself is deactivated during a bulk-deactivation request, while ensuring the initial cache flush still completes cleanly.
* Fixed: Gutenberg draft saves, including those triggered by "Edit with Elementor", no longer cause unnecessary cache purges or target the homepage when the draft has no slug.
* Fixed: First-time publishing through Elementor now skips single-page and related cache purges, matching the existing auto-purge policy while preserving purges for published content updates and global templates.
* Fixed: Auto-purge listeners for CPTs failed to register during admin sessions, causing Gutenberg publish and deletion actions to skip cache purging. (Credit: @claude sonnet)
* Fixed: Updating the parent of an active child theme now correctly triggers the themes auto-purge.
* Fixed: Deactivating the plugin no longer leaves a safexec-owned (privilege-dropped) preload process running.
* Fixed: The ripgrep fast-path for single-page purges could silently report a cached page as not found — with a custom Cache Key Regex, a whitespace-suffixed key, or a ripgrep exit code other than match/no-match. All three now fall back to the PHP scanner instead of silently no-op'ing. (Credit: @claude sonnet)
* Fixed: Related URL purge now covers the blog index and custom post type archives. On sites using a static front page with a separate Posts page, updating a post now also purges (and preloads, when enabled) the Posts page under "Always Purge the Homepage". Custom post types with a public archive now have that archive purged under "Always Purge Archives & Related URLs". WooCommerce products continue to use the Shop setting.
* Fixed: Preload pre-flight check no longer hangs when safexec runs in pass-through mode (nosuid / no_new_privs); the probe process is now reliably terminated.
* Fixed: Concurrent Preload and Purge operations could race before a starting Preload wrote its PID, leaving untracked crawlers that "Purge All" and "preload --stop" could not find. Preload starts are now serialized with an atomic start lock, and Purge All, single-page purge and settings changes respect an in-flight start.
* Security: "Purge All" and the preload temp-directory cleanup no longer follow symbolic links inside the Nginx cache tree. Previously, a symlink planted in the cache directory could make a purge delete the contents of the link's target outside the cache. Links are now removed themselves and never traversed. (Credit: @claude sonnet)
* Security: Removed the option to atomic write directly to wp-config.php from the Setup page to enable "Assume Nginx Mode".
* Security: Runtime directory in uploads is now protected from direct web access (index.php + Apache .htaccess, applied automatically). Nginx ignores .htaccess, so users must add a location rule; see the new Help entry.
* Security: `nppp_custom_error_log()` no longer lets `wp_trigger_error()` write debug output into REST/AJAX/CRON/WP-CLI response bodies — routed to the PHP error log instead.
* Security: Updated safexec to 1.9.7, which includes an important security fix. Updating is strongly recommended, and users running an older safexec should upgrade urgently.
* Performance: Merged redirect‑ and KEY‑line cache scans into a single ripgrep pass using -m 2 dual‑pattern matching, cutting directory‑walk cost in half on large caches. (Credit: @apoorva-01)
* Improved: HTTP Purge timeouts no longer falsely arm the lockout, and the timeout is now configurable via the `nppp_http_purge_timeout` filter.
* Improved: Reduced open_basedir requirements – the plugin now needs fewer paths in the whitelist, eliminating unnecessary warnings on strict PHP configurations.
* Improved: Nginx detection refactored — redundant inline detection code removed in favour of the centralised detection logic.
* Improved: Setup page overhauled — "Assume-Nginx mode" renamed to "Manual Bypass", notices and cards simplified to be less alarming and more actionable.
* Improved: Moved the Accept-Encoding vary header check to an asynchronous AJAX request to prevent settings page rendering delays.
* Improved: Preload completion emails now use a connection-bounded mail diagnostic.
* Improved: Bumped bundled jQuery UI assets to v1.14.2 (WP 7.1).
* Improved: Bumped bundled DataTables assets to v3.0.3.
* Added: HTTP Purge support extended to "Purge All" (requires ngx_cache_purge module v3.0.2+) — previously HTTP Purge only accelerated single-page purges; it now also covers full cache purges. Configurable via the new "Purge All Path" and "Custom Base URL" settings.
* Added: "Stop Preload" button in the Status tab and a dedicated Admin Bar link. Previously, stopping an active preload process required using "Purge All" on UI, which also cleared the entire cache. The new button stops the preload process immediately while preserving the existing cache.
* Added: Nginx detection results to the Status Tab.
* Added: WP-Cron Reliability check to the Status tab and WP-CLI status output to detect loopback failures or custom system cron setups that could delay post-preload status refreshes.
* Added: Automatic fallback to enable the Preload Watchdog on fresh plugin activations when DISABLE_WP_CRON is active.
* Added: Bundled jsVectorMap to power the new Fail2Ban "Top Attack Countries" bubble map.
* Added: Bundled flag-icons for the country flag icons shown in the Fail2Ban dashboard's Live Feed, Repeat Offenders, and Top Attack Countries panels.
* Added: Help entry explaining how to block direct web access to the runtime directory on Nginx, with a rule generated from your actual uploads path and a verification command.
* Added: A manual Refresh button to the Advanced tab, allowing users to reload the premium table data directly without switching tabs.
* UI/UX: Preload All now redirects to the Status tab instead of Settings, taking users directly to the live preload progress section.
* Updated: Confirmed compatibility with WordPress 7.1.2.
* Tested: Tested with Nginx (1.31.6), FUSE (3.18.3), bindfs (1.18.4), safexec (1.9.7), ripgrep (15.2.0), wget (1.25.0) and aaPanel (8.0.6).

= 2.1.7 (2026-06-08) =

2.1.7 is the cache coverage release. The core motivation: cache every URL 
that is safe to cache — not blindly deny everything with a query string. 
This release fixes that at both layers: the preload engine and the Nginx 
skip-cache rules it complements, turning previously invisible URLs into 
cache HITs.

WP-CLI support lands as the headline milestone, making full cache 
management available from the terminal for the first time.

Before upgrading, three things to be aware of:
  1. Verify your Nginx skip-cache rules match the new preload regex approach 
     and use "Reset Default" on the "Exclude Endpoints" setting.
  2. Use "Reset Default" on "Cache Key Regex" to activate expanded cache key 
     format support.
  3. Confirm ripgrep >= 14.0.0 and that both "shell_exec" and "exec" are 
     enabled in your PHP configuration.

* WP-CLI Support (Milestone): Introduced full CLI integration. You can now manage cache purges, preloading, status reporting, logging, settings updates, flush, and schedules directly from the terminal.
* NEW: "Preload Feeds" – Users can now fully manage feed caching at both site‑wide and per‑URL levels. Feeds (main RSS/Atom, per‑post comment feeds, and taxonomy RSS feeds) are controlled via the "Preload Feeds" option.
* NEW: Advanced Tab "Preload All MISS" – A lighter alternative to "Preload All". When your cache is already mostly warm (>50%), it skips the expensive full‑purge step (Preload All always do full purge) and only preloads the cache that are currently missing. Ideal for high cache coverage sites where "Preload All" would be wasteful.
* NEW: Walkie – a tiny animated character that lives in the plugin header, walks along the ribbons, and cracks cache‑related jokes. Purely for fun during long preloads to keep users company – not a real AI assistant. (He fully believes he’s a real AI, though.)
* Fixed: A false-negative in Nginx detection on Nginx+Apache proxy stacks, which rendered the plugin completely non-functional even when Nginx was active as the front proxy.
* Fixed: Unprotected (function_exists) shell_exec and exec calls across the REST API, WP Cron, Dashboard Widget, and all other relevant execution paths.
* Fixed: Missing check for getenv / putenv for URL Normalization (safexec).
* Fixed: An edgecase where users are locked out by the transient cache because the Status tab and "Clear Plugin Cache" button are inaccessible when plugin functionality is disabled.
* Fixed: Broken Nginx cache keys listing in the Status tab.
* Fixed: Prevent settings/option updates (REST/WP-CLI/UI) during active purge/preload operations to ensure process state consistency.
* Fixed: Separated safexec and native kill paths to prevent redundant process-killing attempts in premature process detection logic.
* Fixed: Recurring cron events (nppp_index_updater_event, npp_cache_preload_event) silently dying after their first run.
* Fixed: nppp_index_updater_event self-healing — event now auto-reschedules on next admin load if wiped externally by WP-CLI, a cron manager plugin, or a database import.
* Fixed: URL→Filepath index is now automatically flushed on WordPress permalink structure changes.
* Fixed: URL→Filepath index is now automatically flushed when Nginx Cache Path changes.
* Fixed: Default Cache key regex no longer embeds non-GET/HEAD HTTP methods (POST, PUT, DELETE, PATCH, OPTIONS) into the host segment when parsing $scheme$request_method$host$request_uri keys caused silent purge failures.
* Resolved: Multiple PHP 8+ deprecation warnings regarding passing null or false values to string and array functions (trim(), end()).
* Performance (Critical Fix): Advanced Tab single Purge action no longer freezes the browser on large caches (30,000+ rows) when Purge Scope sub-triggers enabled. Replaced O(N) full‑table scans with an O(1) URL→row index cache, batched DataTable redraws.
* Performance: Added --no-mmap flag to ripgrep cache scans for faster I/O on large directories of small binary cache files.
* Performance: Restructured the Related Preload engine into a single process, collapsing dozens of related URL preload into a maximum of 2 background operations (Desktop, Mobile).
* Improved (Requires Nginx Config Update): Overhauled the preload engine reject regex from a blanket query-string exclusion to a selective named-parameter denylist. Sites upgrading from prior versions will see a significant increase in preload scope and cache coverage. Verify your Nginx skip-cache rules are also compatible with new changes. To activate the new preload default rules, use the "Reset Default" button for "Exclude Endpoints" in plugin settings. Preload behavior changes significantly.
* Improved: Nginx detection and Setup (Assume Nginx Mode) process. Nginx detection and setup page redirection is now prioritized before all other environment checks with clean instructions.
* Improved: UI/UX on Setup Page.
* Improved: Compatibility on aaPanel. Fully tested and functional (Single and Multi WebServer arches). See github aaPanel/issues/270 and aaPanel/issues/276 for ongoing issues reported. (Thanks to @neikoloves)
* Improved: Status tab diagnostic reporting to explicitly flag outdated ripgrep binaries.
* Improved: Degrade into the PHP recursive scanner fallback if an outdated version of ripgrep is detected on FP3 Purge.
* Extended: Purge Scope now purges Author archives when a post is saved or updated.
* Extended: Purge Scope now purges Date-based archives (year, month, day) when a post is saved or updated.
* Extended: Purge Scope now purges RSS feeds on relevant purge events: main site feed on post publish/update, per-post comments feed when comments are open or present, and per-taxonomy RSS feeds alongside their archive pages.
* Extended: Purge Scope now purges paginated comment URLs for posts when WordPress comment pagination is enabled, including both pretty-permalink and query-string comment page variants.
* Extended: Purge Scope taxonomy archive purging now covers all public registered taxonomies generically (custom taxonomies, WooCommerce product attribute archives).
* Extended: Default Cache Key regex is now also support "$scheme://$host$request_uri | $host$request_uri | $host$uri$is_args$args". To activate the new cache key regex rules, use the "Reset Default" button for "Cache Key Regex" in plugin settings.
* Changed: Hard dependency extended to require both shell_exec and exec (rg). REGRESSION!
* Added: Proper open_basedir compatibility detection and admin warning for missing required paths.
* Added: Detection for Vary: Accept-Encoding may cause double‑cache issue (dismissable completely).
* Added: Strict minimum version requirement for ripgrep (`>= 14.0.0`)
* Added: Cache Key Regex validation checks now trigger global warnings when custom or default regex fail.
* Added: "Test Regex" button to live-verify the cache key regex against a real cache file.
* Removed: False-positive and redundant warning messages on the Status and Advanced tab.
* Removed: /feed/ and comment-page- from the preload defaults; feeds and comment pages are now preloaded by default.
* Balanced: Lowered TTLs of several internal plugin transients to make the UI reflect changes more quickly. Reduces the need for manual “Clear Plugin Cache” after configuration changes.
* Developer: nppp_purged_all action hook — fired after every successful full Nginx cache purge, enabling third-party plugins to trigger their own cache flush in sync.
* Updated: DataTables assets bump to version 2.3.8 for improved Advanced Tab rendering and stability.
* Updated: Tested up to WordPress 7.0
* Tested: Tested with Nginx (1.31.1), FUSE (3.18.2), bindfs (1.18.4), safexec (1.9.6), ripgrep (15.1.0), wget (1.25.0) and aaPanel (8.0.3).

= 2.1.6 (2026-04-27) =

* Performance: MILESTONE: Replaced heavy recursive PHP iterators with ripgrep (rg) as the primary fast-path for cache lookups. This dramatically accelerates cache lookups and purges, tab load timings, specifically optimized for large-scale sites with over 10,000+ URLs. (See Help Tab for requirements)
* Performance: Optimized permission checks by replacing recursive filesystem iteration with a lightweight targeted write/delete probe.
* Performance: Eliminated redundant recursive PHP filesystem iterations across Purge and Preload processes.
* Fixed: Resolved an long living issue where strict $request_method (GET) filtering caused NPP to only partially function on cache keys lacking a defined $request_method. (Huge thanks to Slava Shevchenko @kaganvmz)
* Fixed: Auto Preload failing to trigger after manual single-URL purges on the front page and Advanced Tab.
* Fixed: Added safeguards around GLOB_BRACE usage to ensure compatibility with Alpine Linux and musl libc environments.
* Fixed: Resolved an issue that prevented the index updater cron job from running.
* Fixed: Resolved a visual bug where the Preload Progress bar reached 100% while background preloading was still in progress.
* Added: MILESTONE: Completely revamped the Auto Purge system. Replaced the single global Auto Purge toggle with a new suite of granular, event-based controls to give you precise management.
* Added: MILESTONE: Provides power users the ability to override default cache directory restrictions. Use with CAUTION! 
* Added: Support for custom Mobile User Agent.
* Added: Important documentation and warnings regarding open_basedir restrictions. (Thanks to @tvarga77)
* Added: Pre-bootstrap Abuse Logger for NPP endpoints.
* Improved: Purge Single pipeline for performance (FP1-FP4) - RG Purge
* Improved: Auto Purge compatibility with Gutenberg
* Improved: Auto Purge compatibility with Elementor
* Improved: Auto Purge compatibility with Classic Editor
* Improved: Auto Purge compatibility with Redis Cache
* Improved: Auto Purge compatibility with WooCommerce
* Improved: compatibility with FUSE mount cache paths with safexec
* Improved: Compatibility with diverse Nginx cache_key formats, including better handling of custom $request_method and URI structures.
* Improved: Enhanced $request_method filtering; NPP now purges both GET and HEAD requests instead of being limited to GET only.
* Improved: Significantly optimized Advanced and Status Tab load timings by refining background data retrieval and ripgrep.
* Updated: safexec 1.9.6 - Update required!

= 2.1.5 (2026-03-23) =

* Added: HTTP Purge via ngx_cache_purge module — fastest purge path when the Nginx module is present. Falls back to filesystem automatically when not available.
* Added: MILESTONE: Index Purge — single-page purges now use a persistent URL index to skip full directory scans. The base index replicates the snapshot, and the index is automatically updated after each purge if the URL is not already indexed.
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
* Added: Clear URL Index button in the Status tab — allows manual reset of the persistent URL→filepath index when cache paths change or index entries become stale.
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
* Fixed: Preload now checks the purge lock before spawning — concurrent Purge All and Preload operations no longer race against each other on the cache directory.
* Fixed: MILESTONE: Advanced tab correctly retains the full MISS list immediately after a Purge All.
* Fixed: Auto-purge no longer fires on fresh install before settings have been saved.
* Fixed: GNU Wget2 (aliased as wget on some distributions-Fedora) is now detected and rejected. GNU Wget 1.x is required.
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
* Fixed: Index now stores an array of paths per URL instead of a single path — correctly handles multiple cache variants for the same URL (Vary: Accept-Encoding variants, mobile cache entries when the Nginx cache key includes a mobile variable).
* Fixed: Single-page purge scan loop now continues past the first URL match instead of returning — all cache variants for a URL (Vary, mobile) are deleted in a single directory walk.
* Fixed: HTTP Purge is now bypassed when the index holds more than one path for a URL — HTTP purge can only remove one shmem/disk entry; index-based purge is used instead to ensure all variants are removed.
* Changed: Preload completion email template completely redesigned — now shows a stats dashboard with Crawl Time, URLs Crawled, Transfer Size, Average Speed, Cache Coverage, Cache Size, Broken URLs, Mobile Pass status, Trigger source, and Finish Time. Includes dark mode support and responsive mobile layout.
* Changed: Allowed Nginx cache path roots updated — /opt/ removed (too broad, risk of data loss), /cache/ added (used by GridPane, RunCloud, SpinupWP and other control panels). If your cache was stored under /opt/, move it to a supported location and re-save settings.
* Removed: All data collection and opt-in tracking completely removed. NPP collects no data whatsoever.
* Removed: Systemd service management removed — the ability to restart the npp-wordpress FUSE mount service directly from the WordPress admin has been dropped. Use standard system tools to manage the service instead.
* Compatibility: Tested with WordPress 6.9.4, PHP 8.4, Nginx 1.29.6, FUSE 3.18.2, safexec 1.9.3 and bindfs 1.18.4

= 2.1.4 (2025-10-04) =

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

= 2.1.3 (2025-07-22) =

* PATCHED: CVE-2025-6213 — Prevent command injection via ['HTTP_REFERER'] (Credit: @cynau1t)
* Fixed: UTF-8 decoded URLs are now correctly displayed in the Advanced tab for improved readability (Credit: @XCJYO)
* Fixed: Percent-encoded URL normalization (uppercase vs lowercase) to prevent cache miss via mismatched encodings (Credit: @XCJYO)
* Fixed: Fatal error in CLI context caused by undefined FS_CHMOD_FILE when running WP-CLI (Reported by: @sergeybv)
* Fixed: Preload completion time and last preload timestamp now display accurately
* Fixed: Addressed several WordPress Plugin Check (PCP) compatibility warnings and false positives
* Added: Real-time Preload Progress Monitor in the Status tab, with visual feedback and progress bar
* Added: Proxy support for preload operations, including validation and status checks
* Compatibility: Tested with WordPress 6.8.2

= 2.1.2 (2025-06-23) =

* Fix leaking HTML into WP core API responses
* Fix plugin name under Settings menu
* Fix mobile layout issues
* Fix plugin not a valid header issue
* Fix Status tab render issue
* Fix Auto Purge triggers twice
* Bump external assets to latest versions
* Tested with WordPress 6.8.1

For the complete changelog, see
[changelog.txt](https://github.com/psaux-it/nginx-fastcgi-cache-purge-and-preload/blob/main/changelog.txt).

== Upgrade Notice ==

= 2.1.8 =
New Fail2Ban dashboard! | URGENT: Update safexec to 1.9.7 | URGENT: Block direct web access to the runtime directory (see Help tab).

= 2.1.7 =
Cache coverage release. Read Changelog for best results.

= 2.1.6 =
Performance/functionality fixes included. Please Reset Default the Cache Key Regex. Upgrade immediately.

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

Bundled libraries, all MIT licensed: DataTables (datatables.net), Tempus Dominus (github.com/Eonasdan/tempus-dominus), Popper.js (github.com/popperjs/popper-core), jsVectorMap (github.com/themustafaomar/jsvectormap), and flag-icons (github.com/lipis/flag-icons).

== Privacy Policy ==

NPP does not collect usage telemetry. The optional anonymous usage reporting available before version 2.1.5, including its opt-in mechanism, has been removed.

When configured, the optional Fail2Ban integration stores reported ban and unban events in your WordPress database. Network lookups and administrator-initiated emails may send data to third parties as described below.

== External services ==

The optional Fail2Ban integration uses RIPEstat for network lookups. It also provides optional, administrator-initiated email features.

= RIPEstat Data API (stat.ripe.net) =

Provides network registration details, including network name, country, ASN, and abuse contacts, for public IP addresses reported as banned by Fail2Ban.

* Data sent: The public IP address reported as banned, sent to RIPEstat's whois and abuse-contact lookup endpoints, plus the "sourceapp" identifier described below.
* When: From a background worker or WP-Cron after a ban event arrives and no cached result is available. These lookups require you to configure Fail2Ban to send events to the plugin's webhook.
* Provider: RIPE NCC.
* Identifier: The plugin sends "npp-wp-plugin-fail2ban-monitor" by default. An administrator can optionally append a suffix, such as a domain or project name, under Fail2Ban > RIPEstat Lookups. The suffix is empty by default.

= Optional emails =

* Abuse reports: Sent only when an administrator clicks Send or runs the WP-CLI command. The email contains the reported IP, ban timestamps and jail names, your site's hostname and server IP, and the sender and contact details you configured. It goes to the abuse contact RIPEstat returns for that network.
* RIPEstat registration: When an administrator reviews and sends a registration email, the configured identifier and contact details are sent to the RIPEstat team at RIPE NCC.

Neither abuse reports nor registration emails are sent automatically.

== Support ==

For support and assistance, please contact Hasan CALISIR at hasan.calisir@psauxit.com.

== License ==

This plugin is licensed under the GPLv2 or later.

== Other Notes ==

For more information, visit the plugin development page: [Nginx Cache Purge Preload](https://github.com/psaux-it/nginx-fastcgi-cache-purge-and-preload)
