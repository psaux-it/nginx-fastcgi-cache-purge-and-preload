=== Nginx Cache Purge Preload ===
Contributors: psauxit
Donate link: https://www.psauxit.com/nginx-fastcgi-cache-purge-preload-for-wordpress/
Tags: nginx, cache, purge, preload, performance
Requires at least: 6.3
Requires PHP: 7.4
Tested up to: 6.8.1
Stable tag: 2.1.2
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

The most comprehensive solution for managing Nginx (FastCGI, Proxy, SCGI, UWSGI) cache operations directly from your WordPress dashboard.

== Description ==

This plugin, **NPP**, allows WordPress users to manage **Nginx Cache Purge and Preload** (FastCGI, Proxy, SCGI, UWSGI) operations directly from the WordPress admin dashboard, enhancing website performance and caching efficiency.

Unlike other solutions that depend on Nginx modules, **NPP** directly manages cache files without needing to interact with Nginx.

➡️ **This approach provides the following benefits:**

⚡ **Faster** – No waiting for Nginx to process cache purges; works without interacting with Nginx.
🌐 **Greater flexibility** – Works seamlessly across different architectures, including containerized environments where Nginx may run on a host, in a separate container, or distributed across systems.
🤖 **Automations** - NPP is flexible for server-side automations, making it easy to integrate into your workflow.

⚠️ **IMPORTANT:**

**NPP** is feature rich, completely free & functional and great for users who manage their own servers and have technical know-how. For those with less technical experience, pre-made Bash scripts are available, making it easy to get started and benefit from the plugin.

➡️ **For detailed integration steps and guidance:**

• Visit the [NPP Main Development Repository](https://github.com/psaux-it/nginx-fastcgi-cache-purge-and-preload)
• Explore [NPP Containerized](https://github.com/psaux-it/wordpress-nginx-cache-docker) for easy production deployments and testing NPP locally.
• Refer to the **FAQ** or the plugin’s **Help tab** for further instructions.

== Features ==

🧹 **Purge All Nginx Cache**: Completely clear all cached data stored by Nginx.

🔄 **Preload All Nginx Cache**: Warm the Nginx cache with the most recent data for the entire website.

🚀 **Auto Preload Nginx Cache**: Automatically preloads the cache when Auto Purge is enabled for a POST/PAGE or after the Purge All action.

🧼 **Auto Purge Nginx Cache**: Purge cache on Post/Page content changes, comment status updates, theme/plugin updates, or when compatible Cache Plugins trigger a purge. Nginx cache is preloaded automatically if Auto Preload is enabled (for the entire site or individual page).

⏰ **Schedule Nginx Cache Purge & Preload via WP Cron**: Automate the purge and preload process using WordPress Cron jobs.

🌐 **Remote Nginx Cache Purge & Preload via REST API**: Remotely trigger cache purging and preloading through REST API endpoints.

⚙️ **Manual Nginx Cache Purge & Preload**: Allow manual purging and preloading of cache through the table view in the Advanced Tab.

🔍 **On-Page Nginx Cache Purge & Preload**: Manually purge and preload Nginx cache for the currently visited page directly from the frontend.

🗝️ **Custom Cache Key Support**: Define a regex pattern to parse URLs based on your custom `_cache_key` format.

⚡ **Optimized Nginx Cache Preload**: Enhance Nginx cache preload performance with options to limit CPU usage, exclude endpoints, wait retrievals, and apply rate limiting.

📊 **Monitor Plugin and Nginx Cache Status**: Monitor plugin status, cache status, and Nginx status from the Status tab.

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

= Is the plugin completely free? =

Yes, the plugin is completely free to use.

= Who is this plugin intended for? =
	
For users looking to efficiently manage Nginx cache Purge and Preload actions, this plugin provides a seamless solution for managing these processes directly from the WordPress admin dashboard.

= Will this plugin slow my site down? =
	
No, this plugin does not introduce any performance overhead to your website. It operates exclusively within the WordPress admin environment and does not affect the frontend or public-facing aspects of your site.

= Will it work on my theme? =
	
Sure! Works 100% with all themes.
	
= Will it work with my plugins? =
	
Sure! It works 100% with all plugins.

= What changes will it make to my site? =

None.

= Is this plugin compatible with other Wordpress cache plugins? =

When using NPP with other WordPress caching plugins, consider disabing their page caching features to avoid conflicts and redundancy. These plugins can still handle frontend optimizations.

**Keep frontend optimization features active, such as**:
* Minify and combine CSS/JS files
* Lazy load images and videos
* Optimize the database
* Integrate a CDN

This combined setup ensures NPP manages server-side caching while other plugins handle frontend tasks, avoiding conflicts and improving performance.

= How do I configure the plugin settings? =

Navigate to Settings -> **Nginx Cache Purge Preload** in the WordPress admin dashboard or navigate to Admin Bar -> **Nginx Cache** to configure the options and use the actions.

= Why plugin not functional on my environment? =

This wordpress plugin is compatible exclusively with Nginx web servers running on Linux-powered systems. Additionally, the shell_exec function must be enabled and unrestricted. Consequently, the plugin may not operate fully on shared hosting environments where native Linux commands are blocked from running via PHP.

Moreover, granting the correct permissions to the PHP process owner (PHP-FPM-USER) is essential for the proper functioning of the purge and preload operations. This is necessary in isolated user environments that have two distinct user roles: the WEBSERVER-USER (nginx or www-data) and the PHP-FPM-USER.

If you see warnings or if any plugin settings or tabs are disabled, this could indicate permission issues, an unsupported environment, or missing dependencies that the plugin requires to function properly.

= What Linux commands are required for the preload action? =

For the preload action to work properly, the server must have the **wget** command installed, as the plugin uses it to preload the cache by fetching pages. Additionally, it is recommended to have the **cpulimit** command installed to effectively manage **wget** process server load during the preload action.

= Why am I encountering a permission error? =

Permission errors when purging cache in Nginx environments are common, especially when the **WEBSERVER-USER** and **PHP-FPM-USER** have different permissions. This can restrict PHP-FPM-USER access to cache files. For detailed guidance on resolving this issue and automating tasks with a pre-made bash script, refer to the plugin settings Help tab or visit the plugin's [main development repository](https://github.com/psaux-it/nginx-fastcgi-cache-purge-and-preload).

= Why can not I use my preferred path for the Nginx Cache Directory? =

The Nginx Cache Directory option has restrictions on the paths you can use to prevent accidental deletions or harm to critical system files. By default, certain paths, like '/home' and other vital system directories, are blocked to safeguard your system's stability and prevent data loss. While this might limit your options, it ensures your system's security.

**Allowed Cache Paths**

* For RAM-based: Use directories under /dev/ | /tmp/ | /var/
* For persistent disk: Use directories under /opt/
* Important: Paths must be one level deeper (e.g. /var/cache).

= What is different about this plugin compared to other Nginx Cache Plugins? =

Because NPP does not depend on external Nginx modules, It provides a simpler and more flexible solution. This plugin directly traverses the cache directory and deletes cache files If the PHP-FPM-USER (website-user or PHP process owner) has read and write permissions granted to the Nginx cache path. Note that, NPP also supports Nginx cache preloading with a simple direct approach, with the help of **wget**.

= I am still encountering difficulties. Do you provide server-side integration services? =

Yes, please refer to the plugin settings **Help** tab.

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

= 2.1.2 =

Release date: 2025-04-28

* Fix leaking HTML into WP core API responses
* Fix plugin name under Settings menu
* Fix non-public custom post types (CPTs) were being purged
* Fix mobile layout issues
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

= 2.0.1 =

* Important fixes for function/class/define/namespace/option names.
* Properly enqueued inline JavaScript.
* Internationalization and security improvements.

== Credits ==

This plugin is developed and maintained by Hasan CALISIR. 

== Support ==

For support and assistance, please contact Hasan CALISIR at hasan.calisir@psauxit.com.

== License ==

This plugin is licensed under the GPLv2 or later.

== Other Notes ==

For more information, visit the plugin development page: [Nginx Cache Purge Preload](https://github.com/psaux-it/nginx-fastcgi-cache-purge-and-preload)
