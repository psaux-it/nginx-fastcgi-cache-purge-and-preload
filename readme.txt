=== FastCGI Cache Purge and Preload for Nginx ===
Contributors: Hasan ÇALIŞIR
Tags: nginx, cache, purge, preload, performance
Requires at least: 6.3
Requires PHP: 7.4
Tested up to: 6.5.3
Stable tag: 2.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Manage FastCGI Cache Purge and Preload for Nginx operations directly from your WordPress admin dashboard.

== Description ==

This plugin allows WordPress users to manage FastCGI Cache Purge and Preload for Nginx operations directly from the WordPress admin dashboard, enhancing website performance and caching efficiency.

== Features ==

- FastCGI Cache Purge All for Nginx
- FastCGI Cache Preload All for Nginx
- FastCGI Cache Auto Preload for Nginx
- FastCGI Cache Scheduled Purge & Preload for Nginx  via WP Cron
- Optimized Performance for FastCGI Cache Preload for Nginx via CPU usage limits, Endpoint exclusion and Rate limiting
- Manual Page Purge for Nginx in Advanced tab
- Manual Page Preload for Nginx in Advanced tab
- Frontend On-Page FastCGI Cache Purge and Preload for Nginx actions
- REST API support for FastCGI Cache Purge and Preload for Nginx
- Advanced Status tab for Plugin functionality check, Nginx Configuration checks
- User-Friendly Wordpress Admin Notices for actions
- Easy management with ajax powered toggle switch options 
- Easy-to-use interface directly integrated into the WordPress admin bar
- E-Mail Notifications for completed Preload actions with ready to use template

== Installation ==

1. Upload the 'fastcgi-cache-purge-and-preload-nginx' folder to the '/wp-content/plugins/' directory.
2. Activate the plugin through the 'Plugins' menu in the WordPress admin dashboard.
3. Configure plugin settings under 'Settings' -> 'FastCGI Cache Purge and Preload' in the WordPress admin dashboard.
4. Access FastCGI Cache Purge and Preload operations from the WordPress admin bar(both frontend,backend) and Plugin settings page.

== Frequently Asked Questions ==

= How do I configure the plugin settings? =

Navigate to 'Settings' -> 'FastCGI Cache Purge and Preload' in the WordPress admin dashboard or navigate to 'Admin Bar' -> 'FastCGI Cache' to configure the options.

= Why plugin not functional on my environment? =

This plugin is only compatible with Nginx web servers running on Linux-powered systems.
Additionally, shell exec must be enabled and not restricted. Simply put, the plugin may not function fully on shared hosting environments where native Linux commands are prohibited from running via PHP.
Furthermore, users must have a properly configured PHP-FPM & Nginx setup for purge and preload operations. Otherwise permission issues occurs. 
Warnings may appear if there are permission issues, unsupported environment or missing dependencies required for cache operations.
Follow the warnings and refer to plugin 'Help' tab for guidance.

== Changelog ==

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

= 1.0.0 =
Initial release.

== Screenshots ==

1. Admin bar with FastCGI cache operations.
2. Settings page for configuring plugin options.
3. Warnings displayed for permission and dependency issues.

== Credits ==

This plugin is developed and maintained by Hasan ÇALIŞIR.

== Support ==

For support and assistance, please contact Hasan ÇALIŞIR at hasan.calisir@psauxit.com.

== License ==

This plugin is licensed under the GPLv2 or later.

== Other Notes ==

For more information, visit the plugin homepage: [FastCGI Cache Purge and Preload for Nginx](https://github.com/psaux-it/nginx-fastcgi-cache-purge-and-preload)
