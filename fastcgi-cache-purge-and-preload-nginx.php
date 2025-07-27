<?php
/*
 * Plugin Name:       Nginx Cache Purge Preload
 * Plugin URI:        https://github.com/psaux-it/nginx-fastcgi-cache-purge-and-preload
 * Description:       The most comprehensive solution for managing Nginx (FastCGI, Proxy, SCGI, UWSGI) cache operations directly from your WordPress dashboard.
 * Version:           2.1.3
 * Author:            Hasan CALISIR
 * Author URI:        https://www.psauxit.com/
 * License:           GPL-2.0+
 * License URI:       http://www.gnu.org/licenses/gpl-2.0.txt
 * Text Domain:       fastcgi-cache-purge-and-preload-nginx
 * Domain Path:       /languages
 * Requires at least: 6.3
 * Requires PHP:      7.4
 */

// Exit if accessed directly.
if (!defined('ABSPATH')) {
    exit;
}

// PHP-FPM worker (non-login user) uses a minimal PATH, extend it for shell commands
putenv('PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin');

// Define the plugin main file path
if (!defined('NPPP_PLUGIN_FILE')) {
    define('NPPP_PLUGIN_FILE', __FILE__);
}

// Load NPP
require_once plugin_dir_path(__FILE__) . 'admin/fastcgi-cache-purge-and-preload-nginx-admin.php';

// Register activation and deactivation hooks
register_activation_hook( __FILE__, 'nppp_defaults_on_plugin_activation' );
register_deactivation_hook( __FILE__, 'nppp_reset_plugin_settings_on_deactivation' );
