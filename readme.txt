=== FastCGI Cache Purge and Preload for Nginx ===
Contributors: psauxit
Donate link: https://www.psauxit.com/nginx-fastcgi-cache-purge-preload-for-wordpress/
Tags: nginx, cache, purge, preload, performance
Requires at least: 6.3
Requires PHP: 7.4
Tested up to: 6.6.2
Stable tag: 2.0.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Manage Nginx Cache Purge and Preload operations directly from your WordPress admin dashboard.

== Description ==

This plugin allows WordPress users to manage Nginx Cache Purge and Preload operations directly from the WordPress admin dashboard, enhancing website performance and caching efficiency.

== Important ==

Please read the full description (What you need?) and FAQ for fully functional Nginx cache purge and preload actions provided by this plugin:

== Features ==

🚀**Purge All Nginx Cache**: Completely clear all cached data stored by Nginx.
🚀**Preload All Nginx Cache**: Populate the Nginx cache with the most recent data for the entire website.
🚀**Auto Preload Nginx Cache**: Automatically preload the cache after purging, ensuring fast page load times by caching content proactively. This feature is triggered when Auto Purge is enabled for a single POST/PAGE or when the Purge All cache action is used.
🚀**Auto Purge Nginx Cache**: Automatically purges the cached version of a POST/PAGE whenever it is updated, when new comments are approved, or when the comment status is changed. Additionally, if the Auto Preload option is enabled, the cache for this POST/PAGE will be automatically preloaded after the cached version is purged by Auto Purge.
🚀**Schedule Cache Purge & Preload via WP Cron**: Automate the purge and preload process using WordPress Cron jobs.
🚀**Remote Nginx Cache Purge & Preload via REST API**: Remotely trigger cache purging and preloading through REST API endpoints.
🚀**Manual Nginx Cache Purge & Preload**: Allow manual purging and preloading of cache through the table view in Advanced Tab.
🚀**On-Page Nginx Cache Purge & Preload**: Manually purge and preload Nginx cache for the currently visited page directly from the frontend.
🚀**Optimized Nginx Cache Preload**: Enhance Nginx cache preload performance with options to limit CPU usage, exclude endpoints, wait retrievals and rate limiting.
🚀**Monitor Plugin and Nginx Cache Status**: Monitor plugin status, cache status, and Nginx status from the Status tab.
🚀**User-Friendly Interface**: Easy-to-use AJAX-powered settings, integrated into the WordPress admin bar for quick access.
🚀**Admin Notices and Logs**: Receive handy notifications and view logs for plugin status and all cache-related actions within the WordPress admin area.
🚀**Email Notifications**: Receive email alerts upon completion of preload actions, with customizable templates to suit your needs.

= What you need? =

**Technical Difficulties:**

In properly configured Nginx servers, it is not strictly necessary to have separate **PHP-FPM-USER** (as a known WEBSITE-USER or PHP process owner) and **WEBSERVER-USER** (commonly, nginx or www-data), but there are scenarios where separating these users can enhance security and performance. Although this configuration is recommended as a standard, It leads to difficulties in purging and preloading the cache by the PHP process owner. When the **PHP-FPM-USER** and **WEBSERVER-USER** are different, the PHP process owner may not have the necessary permissions to manage cache files created by the **WEBSERVER-USER**, as the PHP process owner may be unable to read, write, or delete cache files owned by the **WEBSERVER-USER**.

**Proposed Solution by NPP**:

In case your current Nginx web server setup involves two distinct users, **WEBSERVER-USER** and **PHP-FPM-USER**, the solution proposed by NPP involves combining **Linux** server side tools **inotifywait** with **setfacl** to automatically grant read/write permissions to the **PHP-FPM-USER** for the corresponding **Nginx Cache Paths** (owned by **WEBSERVER-USER**), facilitated by server-side bash scripting. Users need to manage **inotifywait** and **setfacl** operations manually or use the provided below one liner basic automation bash script for **fully functional purge and preload actions provided by this plugin**.

`bash <(curl -Ss https://psaux-it.github.io/install.sh)`

**More in-depth Information**

- [NPP plugin main development repository](https://github.com/psaux-it/nginx-fastcgi-cache-purge-and-preload)
- [NPP plugin main automation bash script source code](https://github.com/psaux-it/psaux-it.github.io/blob/main/fastcgi_ops_root.sh)
- [Optimizing Wordpress with Nginx FastCGI Cache and NPP plugin](https://www.psauxit.com/optimizing-wordpress-and-woocommerce-with-nginx-fastcgi-cache/)

== Installation ==

Manual Installation

1. Upload the 'fastcgi-cache-purge-and-preload-nginx' folder to the '/wp-content/plugins/' directory.
2. Activate the plugin through the 'Plugins' menu in the WordPress admin dashboard.
3. Configure plugin settings under 'Settings' -> 'FastCGI Cache Purge and Preload' in the WordPress admin dashboard.
4. Access FastCGI Cache Purge and Preload operations from the WordPress admin bar(both frontend,backend) and Plugin settings page.

Automatic Installation

1. Log in to your WordPress admin panel, navigate to the Plugins menu and click Add New.
2. In the search field type “FastCGI Cache Purge and Preload Nginx” and click Search Plugins. From the search results, pick "FastCGI Cache Purge and Preload for Nginx" and click Install Now. Wordpress will ask you to confirm to complete the installation.

== Frequently Asked Questions ==

= Is the plugin completely free? =

Yes, the plugin is completely free to use.

= Who is this plugin intended for? =
	
For anyone who wants to use server side Nginx’s built-in caching mechanism to serve cached content directly. It is particularly beneficial for those who wish to manage purge and preload actions directly from the WordPress admin dashboard, WP Cron or REST API

= Will this plugin slow my site down? =
	
No, this plugin does not introduce any performance overhead to your website. It operates exclusively within the WordPress admin environment and does not affect the frontend or public-facing aspects of your site.

= Will it work on my theme? =
	
Sure! Works 100% with all themes.
	
= Will it work with my plugins? =
	
Sure! It works 100% with all plugins.

= What changes will it make to my site? =

None.

= Is this plugin compatible with other Wordpress cache plugins? =

Certainly! Nginx FastCGI cache operates differently from traditional WordPress cache plugins, functioning at the server level by storing fully generated HTML pages. As such, it can be used alongside other WordPress cache plugins without any compatibility issues.

= How do I configure the plugin settings? =

Navigate to 'Settings' -> 'FastCGI Cache Purge and Preload' in the WordPress admin dashboard or navigate to 'Admin Bar' -> 'FastCGI Cache' to configure the options and use the actions.

= Why plugin not functional on my environment? =

This plugin is compatible exclusively with **Nginx** web servers running on **Linux-powered** systems. Additionally, the **shell_exec** function must be enabled and unrestricted. Consequently, the plugin may not operate fully on shared hosting environments where native Linux commands are blocked from running via PHP.

Moreover, a correctly configured PHP-FPM and Nginx setup is essential for the purge and preload operations to function properly. Otherwise, permission issues may arise.

If warnings appear, they may indicate permission issues, an unsupported environment, or missing dependencies required for cache operations. Please follow the warnings and refer to the plugin's 'Help' tab for detailed guidance.

= What Linux commands are required for the preload action? =

For the preload action to work properly, the server needs to have the **wget** command installed. The plugin uses **wget** to preload cache by fetching pages. Additionally, it's highly recommended to have the **cpulimit** command installed to manage server load effectively during the preload action.

= Why am I encountering a permission error? =

Encountering a permission error when attempting to purge cache from client side in Nginx environments is a common issue, especially when two distinct users, namely the **WEBSERVER-USER** and **PHP-FPM-USER**, are involved. This occurs due to differences in permissions between these users, often leading to access restrictions when trying to manipulate cache files. For detailed guidance on resolving this issue and automating server-side tasks using a bash script, please refer to the plugin settings **Help** tab.

= Why can't I use my preferred path for the Nginx Cache Directory? =

The Nginx Cache Directory option has restrictions on the paths you can use to prevent accidental deletions or harm to critical system files. By default, certain paths, like '/home' and other vital system directories, are blocked to safeguard your system's stability and prevent data loss.

While this might limit your options, it ensures your system's security. Recommended directories to choose from, such as '/dev/shm/' or '/var/cache/', which are commonly used for caching purposes and are generally safer.

= What is different about this plugin compared to other Nginx Cache Plugins? =

NPP offers a more direct solution without any external NGINX module such as Cache Purge module. This plugin directly traverses the cache directory and clears cache if PHP-FPM and WEBSERVER user permissions are adjusted properly. To automate and fix these permission issues, there is a pre-made bash script that needs to be run manually on host server under the root user.

`bash <(curl -Ss https://psaux-it.github.io/install.sh)`

Note that, NPP also supports Nginx cache preloading with a simple direct approach, with the help of wget. This feature is missing in other Nginx Cache plugins.

There are many cases where the external Nginx modules works fine for cache purge operations, but integrating the module with Nginx can be challenging for non-technical or regular WordPress users. Not every Linux distro packages this module or has outdated module versions, so users may need to follow extra steps to integrate it, which becomes more complicated.

Also, there are other cases where even bleeding-edge module versions are easily installed and integrated with Nginx by users, but purge operations do not work as expected.

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

This plugin is developed and maintained by Hasan ÇALIŞIR.

== Support ==

For support and assistance, please contact Hasan ÇALIŞIR at hasan.calisir@psauxit.com.

== License ==

This plugin is licensed under the GPLv2 or later.

== Other Notes ==

For more information, visit the plugin development page: [FastCGI Cache Purge and Preload for Nginx](https://github.com/psaux-it/nginx-fastcgi-cache-purge-and-preload)
