<?php
/**
 * Plugin Name:       Nginx FastCGI Cache Purge and Preload
 * Plugin URI:        https://wordpress.org/plugins/nginx-fastcgi-cache-purge-and-preload/
 * Description:       Manage Nginx FastCGI Cache Purge and Preload operations directly from your WordPress admin dashboard.
 * Version:           1.0.2
 * Author:            Hasan ÇALIŞIR
 * Author URI:        https://www.psauxit.com/
 * Author Email:      hasan.calisir@psauxit.com
 * License:           GPL-2.0+
 * License URI:       http://www.gnu.org/licenses/gpl-2.0.txt
 * Text Domain:       nginx-fastcgi-cache-purge-and-preload
 * Requires at least: 6.3
 * Requires PHP:      7.4
 */

// Define a constant for the log file path
define('NGINX_CACHE_LOG_FILE', plugin_dir_path(__FILE__) . 'fastcgi_ops.log');

// Settings page tabs & actions & helpers
require_once plugin_dir_path( __FILE__ ) . 'includes/enqueue-assets.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/wp-filesystem.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/pre-checks.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/admin-bar.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/log.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/settings.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/purge.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/preload.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/help.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/status.php';

// Register activation and deactivation hooks
register_activation_hook(__FILE__, 'defaults_on_plugin_activation');
register_deactivation_hook(__FILE__, 'reset_plugin_settings_on_deactivation');

// Add actions and filters
add_action('admin_bar_menu', 'add_fastcgi_cache_buttons_admin_bar', 100);
add_action('admin_init', 'handle_fastcgi_cache_actions_admin_bar');
add_action('admin_init', 'check_processes_status');
add_action('admin_enqueue_scripts', 'enqueue_nginx_fastcgi_cache_purge_preload_assets');
add_action('admin_init', 'nginx_cache_settings_init');
add_action('admin_menu', 'add_nginx_cache_settings_page');
add_filter('whitelist_options', 'add_nginx_cache_settings_to_allowed_options');
add_action('load-settings_page_nginx_cache_settings', 'pre_checks');
add_action('load-settings_page_nginx_cache_settings', 'check_wget_availability');
add_action('wp_ajax_clear_nginx_cache_logs', 'clear_nginx_cache_logs');
add_action('wp_ajax_update_send_mail_option', 'update_send_mail_option');
add_action('wp_ajax_my_status_ajax', 'my_status_ajax_callback');
add_action('wp_ajax_nopriv_my_status_ajax', 'my_status_ajax_callback');

// Register shortcodes
add_shortcode('my_status', 'my_status_shortcode');
add_shortcode('my_faq', 'my_faq_shortcode');
