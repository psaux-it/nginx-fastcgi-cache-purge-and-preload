=== Nginx FastCGI Cache Purge and Preload ===
Contributors: Hasan ÇALIŞIR
Tags: nginx, cache, purge, preload, performance
Requires at least: 6.3
Requires PHP: 7.4
Tested up to: 6.4
Stable tag: 1.0.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Manage Nginx FastCGI Cache Purge and Preload operations directly from your WordPress admin dashboard.

== Description ==

This plugin allows WordPress users to manage Nginx FastCGI Cache Purge and Preload operations directly from the WordPress admin dashboard, enhancing website performance and caching efficiency.

== Features ==

- Purge Nginx FastCGI Cache with a single click.
- Preload Nginx FastCGI Cache to ensure faster loading times for visitors.
- Easy-to-use interface directly integrated into the WordPress admin bar.
- Settings page for configuring Nginx cache path, cache limit rate, CPU limit, and reject regex pattern.
- Notifications for permissions, path errors, and other critical issues to ensure smooth operation.

== Installation ==

1. Upload the 'nginx-fastcgi-cache-purge-preload' folder to the '/wp-content/plugins/' directory.
2. Activate the plugin through the 'Plugins' menu in WordPress.
3. Configure plugin settings under 'Settings' -> 'Nginx Cache Settings' in the WordPress admin dashboard.
4. Access FastCGI cache operations from the WordPress admin bar.

== Frequently Asked Questions ==

= How do I configure the plugin settings? =

Navigate to 'Settings' -> 'Nginx Cache Settings' in the WordPress admin dashboard to configure Nginx cache path, cache limit rate, CPU limit, and reject regex pattern.

= Why do I see warnings on the plugin settings page? =

Warnings may appear if there are permission issues or missing dependencies (e.g., wget) required for cache operations. Follow the instructions in the warnings to resolve these issues.

== Changelog ==

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

== Donate ==

If you find this plugin helpful, consider making a donation to support future development.

== License ==

This plugin is licensed under the GPLv2 or later.

== Other Notes ==

For more information, visit the plugin homepage: [Nginx FastCGI Cache Purge and Preload](https://wordpress.org/plugins/nginx-fastcgi-cache-purge-and-preload/)
