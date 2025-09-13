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

// Compatible mode
if (! defined('NPPP_ASSUME_NGINX')) {
    $assume = get_option('nppp_assume_nginx_runtime');
    if ($assume) {
        define('NPPP_ASSUME_NGINX', true);
    }
}

// Define the plugin main file
if (!defined('NPPP_PLUGIN_FILE')) {
    define('NPPP_PLUGIN_FILE', __FILE__);
}

// Autoloader
require_once plugin_dir_path(__FILE__) . 'includes/autoload.php';

// Load NPP
require_once plugin_dir_path(__FILE__) . 'admin/fastcgi-cache-purge-and-preload-nginx-admin.php';

// Boot the Setup
if (class_exists('\NPPP\Setup')) {
    \NPPP\Setup::init();
}

// Activation handler
function nppp_on_activation() {
    // Set setup redirect flag
    if (class_exists('\NPPP\Setup')) {
        \NPPP\Setup::nppp_set_activation_redirect_flag();
    } else {
        update_option('nppp_redirect_to_setup_once', 1, false);
    }

    // Initialize default plugin options
    if (function_exists('nppp_defaults_on_plugin_activation')) {
        nppp_defaults_on_plugin_activation();
    }
}

// Register activation and deactivation hooks
register_activation_hook(__FILE__, 'nppp_on_activation');
register_deactivation_hook( __FILE__, 'nppp_reset_plugin_settings_on_deactivation' );
