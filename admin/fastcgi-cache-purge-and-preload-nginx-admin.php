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

// Define a constant for the log file path
if (! defined('NGINX_CACHE_LOG_FILE')) {
    define('NGINX_CACHE_LOG_FILE', dirname(__DIR__) . '/fastcgi_ops.log');
}

// Define a constant for the user agent
if (!defined('NPPP_USER_AGENT')) {
    define('NPPP_USER_AGENT', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/92.0.4515.159 Safari/537.36');
}

// Include plugin files
require_once dirname(__DIR__) . '/includes/enqueue-assets.php';
require_once dirname(__DIR__) . '/includes/wp-filesystem.php';
require_once dirname(__DIR__) . '/includes/pre-checks.php';
require_once dirname(__DIR__) . '/includes/admin-bar.php';
require_once dirname(__DIR__) . '/includes/log.php';
require_once dirname(__DIR__) . '/includes/svg.php';
require_once dirname(__DIR__) . '/includes/settings.php';
require_once dirname(__DIR__) . '/includes/purge.php';
require_once dirname(__DIR__) . '/includes/preload.php';
require_once dirname(__DIR__) . '/includes/help.php';
require_once dirname(__DIR__) . '/includes/configuration-parser.php';
require_once dirname(__DIR__) . '/includes/status.php';
require_once dirname(__DIR__) . '/includes/advanced.php';
require_once dirname(__DIR__) . '/includes/send-mail.php';
require_once dirname(__DIR__) . '/includes/schedule.php';
require_once dirname(__DIR__) . '/includes/rest-api-helper.php';
require_once dirname(__DIR__) . '/includes/plugin-tracking.php';
require_once dirname(__DIR__) . '/includes/update.php';

// Add actions and filters
add_action('load-settings_page_nginx_cache_settings', 'nppp_enqueue_nginx_fastcgi_cache_purge_preload_assets');
add_action('load-settings_page_nginx_cache_settings', 'nppp_check_for_plugin_update');
add_action('admin_enqueue_scripts', 'nppp_enqueue_nginx_fastcgi_cache_purge_preload_requisite_assets');
add_action('wp_enqueue_scripts', 'nppp_enqueue_nginx_fastcgi_cache_purge_preload_front_assets');
add_action('admin_bar_menu', 'nppp_add_fastcgi_cache_buttons_admin_bar', 100);
add_action('admin_init', 'nppp_handle_fastcgi_cache_actions_admin_bar');
add_action('admin_init', 'nppp_nginx_cache_settings_init');
add_action('admin_menu', 'nppp_add_nginx_cache_settings_page');
add_action('load-settings_page_nginx_cache_settings', 'nppp_pre_checks');
add_action('load-settings_page_nginx_cache_settings', 'nppp_manage_admin_notices');
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
add_action('wp_ajax_nppp_update_default_reject_extension_option', 'nppp_update_default_reject_extension_option');
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
add_action('wp_ajax_nppp_clear_plugin_cache', 'nppp_clear_plugin_cache_callback');
add_action('wp_ajax_nppp_restart_systemd_service', 'nppp_restart_systemd_service');
add_action('save_post', 'nppp_purge_cache_on_update');
add_action('wp_insert_comment', 'nppp_purge_cache_on_comment', 200, 2);
add_action('transition_comment_status', 'nppp_purge_cache_on_comment_change', 200, 3);
add_action('admin_post_save_nginx_cache_settings', 'nppp_handle_nginx_cache_settings_submission');
add_action('upgrader_process_complete', 'nppp_purge_cache_on_theme_plugin_update', 10, 2);
add_action('nppp_plugin_admin_notices', function($type, $message, $log_message, $display_notice) {
    // Check if admin notice should be displayed
    if (!$display_notice) {
        return;
    }

    // Define allowed notice types to prevent unexpected classes
    $allowed_types = array('success', 'error', 'warning', 'info');

    // Validate and sanitize the notice type
    if (!in_array($type, $allowed_types, true)) {
        $type = 'info';
    } else {
        $type = sanitize_key($type);
    }

    // Sanitize the message
    $sanitized_message = sanitize_text_field($message);

    // Output the notice directly with proper escaping
    ?>
    <div class="notice notice-<?php echo esc_attr($type); ?> is-dismissible notice-nppp">
        <p><?php echo esc_html($sanitized_message); ?></p>
    </div>
    <?php
}, 10, 4);
add_action('wp', function() {
    if (is_user_logged_in() && current_user_can('administrator') && isset($_GET['nppp_front'])) {
        $nonce = isset($_GET['redirect_nonce']) ? sanitize_text_field(wp_unslash($_GET['redirect_nonce'])) : '';
        if (wp_verify_nonce($nonce, 'nppp_redirect_nonce')) {
            $status_message_key = sanitize_text_field(wp_unslash($_GET['nppp_front']));
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
