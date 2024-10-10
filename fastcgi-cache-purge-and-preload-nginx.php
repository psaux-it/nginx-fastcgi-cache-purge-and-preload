<?php
/**
 * Plugin Name:       FastCGI Cache Purge and Preload for Nginx
 * Plugin URI:        https://github.com/psaux-it/nginx-fastcgi-cache-purge-and-preload
 * Description:       Manage FastCGI Cache Purge and Preload for Nginx operations directly from your WordPress admin dashboard.
 * Version:           2.0.4
 * Author:            Hasan ÇALIŞIR
 * Author URI:        https://www.psauxit.com/
 * Author Email:      hasan.calisir@psauxit.com
 * License:           GPL-2.0+
 * License URI:       http://www.gnu.org/licenses/gpl-2.0.txt
 * Text Domain:       fastcgi-cache-purge-and-preload-nginx
 * Requires at least: 6.3
 * Requires PHP:      7.4
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Define the plugin main file path
if (! defined('NPPP_PLUGIN_FILE')) {
    define('NPPP_PLUGIN_FILE', __FILE__);
}

// Load NPP
require_once plugin_dir_path(__FILE__) . 'admin/fastcgi-cache-purge-and-preload-nginx-admin.php';

// Register activation and deactivation hooks
register_activation_hook( __FILE__, 'nppp_defaults_on_plugin_activation' );
register_deactivation_hook( __FILE__, 'nppp_reset_plugin_settings_on_deactivation' );
