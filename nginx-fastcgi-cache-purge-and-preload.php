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
require_once plugin_dir_path( __FILE__ ) . 'includes/wp-filesystem.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/pre-checks.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/admin-bar.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/log.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/settings.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/purge.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/preload.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/helper.php';
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

// Enqueue custom CSS and JavaScript files
function enqueue_nginx_fastcgi_cache_purge_preload_assets() {
    // Enqueue CSS file
    wp_enqueue_style('nginx-fastcgi-cache-purge-preload', plugins_url('assets/css/nginx-fastcgi-cache-purge-preload.css', __FILE__), array(), '1.0.2');
    // Enqueue JavaScript file
    wp_enqueue_script('nginx-fastcgi-cache-admin', plugins_url('assets/js/nginx-fastcgi-cache-purge-preload.js', __FILE__), array('jquery'), '1.0.2', true);
    // Create a nonce for clearing nginx cache logs
    $clear_nginx_cache_logs_nonce = wp_create_nonce('clear-nginx-cache-logs');
    // Create a nonce for updating send mail option
    $update_send_mail_option_nonce = wp_create_nonce('update-send-mail-option');
    // Create a nonce for the status tab
    $status_ajax_nonce = wp_create_nonce('status_ajax_nonce');

    // Localize nonce value for JavaScript
    wp_localize_script('nginx-fastcgi-cache-admin', 'nginx_cache_ajax_object', array(
        'ajaxurl' => admin_url('admin-ajax.php'),
        'nonce' => $clear_nginx_cache_logs_nonce,
        'send_mail_nonce' => $update_send_mail_option_nonce,
        'status_ajax_nonce' => $status_ajax_nonce,
    ));
}
