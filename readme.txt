=== FastCGI Cache Purge and Preload for Nginx ===
Contributors: psauxit
Donate link: https://www.psauxit.com/nginx-fastcgi-cache-purge-preload-for-wordpress/
Tags: nginx, cache, purge, preload, performance
Requires at least: 6.3
Requires PHP: 7.4
Tested up to: 6.5.3
Stable tag: 2.0.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Manage FastCGI Cache Purge and Preload for Nginx operations directly from your WordPress admin dashboard.

== Description ==

This plugin allows WordPress users to manage FastCGI Cache Purge and Preload for Nginx operations directly from the WordPress admin dashboard, enhancing website performance and caching efficiency.

== Features ==

* Purge all FastCGI Cache for Nginx
* Preload all FastCGI Cache for Nginx
* Auto Preload FastCGI Cache for Nginx
* Schedule Purge & Preload FastCGI Cache for Nginx via WP Cron
* Remotely Purge & Preload FastCGI Cache for Nginx via REST API
* Optimize FastCGI Cache Preload for Nginx performance with CPU usage limit, endpoint exclusion and rate limiting options
* Manually page Purge & Preload FastCGI Cache for Nginx on Advanced Tab
* Control plugin functionality, cache status and Nginx configuration on Status Tab
* Supports On-Page Actions: Manually Purge & Preload FastCGI Cache on visited page
* User-Friendly AJAX powered easy plugin settings inteface, integrated into the WordPress admin bar for quick access
* Handy WordPress Admin Notices and logs for plugin status and all actions
* Email notifications, alerts for completed Preload actions with ready-to-use template

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

= I am still encountering difficulties. Do you provide server-side integration services? =

Yes, please refer to the plugin settings **Help** tab.

== Screenshots ==

1. Settings Tab 1
2. Settings Tab 2
3. Status Tab
4. Advanced Tab
5. Help Tab
6. Frontend Admin Bar
7. Frontend Admin Bar 2

== Changelog ==

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

Release date: 2024-05-24

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
