<?php
/**
 * Plugin Name:       FastCGI Cache Purge and Preload for Nginx
 * Plugin URI:        https://github.com/psaux-it/nginx-fastcgi-cache-purge-and-preload
 * Description:       Manage FastCGI Cache Purge and Preload for Nginx operations directly from your WordPress admin dashboard.
 * Version:           2.0.2
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

// Define a constant for the log file path
if ( ! defined( 'NGINX_CACHE_LOG_FILE' ) ) {
    define( 'NGINX_CACHE_LOG_FILE', plugin_dir_path( __FILE__ ) . '../fastcgi_ops.log' );
}

// Plugin functions
require_once plugin_dir_path( __FILE__ ) . '../includes/enqueue-assets.php';
require_once plugin_dir_path( __FILE__ ) . '../includes/wp-filesystem.php';
require_once plugin_dir_path( __FILE__ ) . '../includes/pre-checks.php';
require_once plugin_dir_path( __FILE__ ) . '../includes/admin-bar.php';
require_once plugin_dir_path( __FILE__ ) . '../includes/log.php';
require_once plugin_dir_path( __FILE__ ) . '../includes/svg.php';
require_once plugin_dir_path( __FILE__ ) . '../includes/settings.php';
require_once plugin_dir_path( __FILE__ ) . '../includes/purge.php';
require_once plugin_dir_path( __FILE__ ) . '../includes/preload.php';
require_once plugin_dir_path( __FILE__ ) . '../includes/help.php';
require_once plugin_dir_path( __FILE__ ) . '../includes/configuration-parser.php';
require_once plugin_dir_path( __FILE__ ) . '../includes/status.php';
require_once plugin_dir_path( __FILE__ ) . '../includes/advanced.php';
require_once plugin_dir_path( __FILE__ ) . '../includes/send-mail.php';
require_once plugin_dir_path( __FILE__ ) . '../includes/schedule.php';

// Add actions and filters
add_action('load-settings_page_nginx_cache_settings', 'nppp_enqueue_nginx_fastcgi_cache_purge_preload_assets');
add_action('admin_enqueue_scripts', 'nppp_enqueue_nginx_fastcgi_cache_purge_preload_requisite_assets');
add_action('wp_enqueue_scripts', 'nppp_enqueue_nginx_fastcgi_cache_purge_preload_front_assets');
add_action('admin_bar_menu', 'nppp_add_fastcgi_cache_buttons_admin_bar', 100);
add_action('admin_init', 'nppp_handle_fastcgi_cache_actions_admin_bar');
add_action('admin_init', 'nppp_nginx_cache_settings_init');
add_action('admin_menu', 'nppp_add_nginx_cache_settings_page');
add_filter('whitelist_options', 'nppp_add_nginx_cache_settings_to_allowed_options');
add_action('load-settings_page_nginx_cache_settings', 'nppp_pre_checks');
add_action('wp_ajax_nppp_clear_nginx_cache_logs', 'nppp_clear_nginx_cache_logs');
add_action('wp_ajax_nppp_get_nginx_cache_logs', 'nppp_get_nginx_cache_logs');
add_action('wp_ajax_nppp_update_send_mail_option', 'nppp_update_send_mail_option');
add_action('wp_ajax_nppp_update_auto_preload_option', 'nppp_update_auto_preload_option');
add_action('wp_ajax_nppp_update_auto_purge_option', 'nppp_update_auto_purge_option');
add_action('wp_ajax_nppp_cache_status', 'nppp_cache_status_callback');
add_action('wp_ajax_nppp_load_premium_content', 'nppp_load_premium_content_callback');
add_action('wp_ajax_nppp_purge_cache_premium', 'nppp_purge_cache_premium_callback');
add_action('wp_ajax_nppp_preload_cache_premium', 'nppp_preload_cache_premium_callback');
add_action('wp_ajax_nppp_update_api_key_option', 'nppp_update_api_key_option');
add_action('wp_ajax_nppp_update_default_reject_regex_option', 'nppp_update_default_reject_regex_option');
add_action('wp_ajax_nppp_update_api_option', 'nppp_update_api_option');
add_action('wp_ajax_nppp_update_api_key_copy_value', 'nppp_update_api_key_copy_value');
add_action('wp_ajax_nppp_rest_api_purge_url_copy', 'nppp_rest_api_purge_url_copy');
add_action('wp_ajax_nppp_rest_api_preload_url_copy', 'nppp_rest_api_preload_url_copy');
add_action('wp_ajax_nppp_get_save_cron_expression', 'nppp_get_save_cron_expression');
add_action('wp_ajax_nppp_update_cache_schedule_option', 'nppp_update_cache_schedule_option');
add_action('wp_ajax_nppp_cancel_scheduled_event', 'nppp_cancel_scheduled_event_callback');
add_filter('cron_schedules', 'nppp_custom_monthly_schedule');
add_filter('cron_schedules', 'nppp_custom_every_min_schedule');
add_action('npp_cache_preload_event', 'nppp_create_scheduled_event_preload_callback');
add_action('npp_cache_preload_status_event', 'nppp_create_scheduled_event_preload_status_callback');
add_action('wp_ajax_nppp_get_active_cron_events_ajax', 'nppp_get_active_cron_events_ajax');
add_action('save_post', 'nppp_purge_cache_on_update');
add_action('wp', function() {
    if (is_user_logged_in() && current_user_can('administrator') && isset($_GET['nppp_front'])) {
        $nonce = isset($_GET['redirect_nonce']) ? sanitize_text_field(wp_unslash($_GET['redirect_nonce'])) : '';
        if (wp_verify_nonce($nonce, 'nppp_redirect_nonce')) {
            $status_message_key = sanitize_text_field($_GET['nppp_front']);
            $status_message_data = get_transient($status_message_key);

            if ($status_message_data) {
                // Display the modal with the message
                $notice_class = 'nppp_notice';
                if ($status_message_data['type'] === 'success') {
                    $notice_class .= ' nppp_front_success';
                } elseif ($status_message_data['type'] === 'error') {
                    $notice_class .= ' nppp_front_error';
                } elseif ($status_message_data['type'] === 'info') {
                    $notice_class .= ' nppp_front_info';
                }

                // Display the message
                echo '<div class="' . esc_attr($notice_class) . '">';
                echo '<p>' . esc_html($status_message_data['message']) . '</p>';
                echo '</div>';

                // Delete the transient so the message is only shown once
                delete_transient($status_message_key);
            }
        }
    }
});

// Register shortcodes
add_shortcode('nppp_svg_icon', 'nppp_svg_icon_shortcode');
add_shortcode('nppp_my_status', 'nppp_my_status_shortcode');
add_shortcode('nppp_my_faq', 'nppp_my_faq_shortcode');
add_shortcode('nppp_nginx_config', 'nppp_nginx_config_shortcode');
