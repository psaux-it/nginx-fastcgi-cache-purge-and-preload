<?php
/*
 * Load NPP
 * Version:           2.1.3
 * Author:            Hasan CALISIR
 * Author URI:        https://www.psauxit.com/
 * License:           GPL-2.0+
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Define a constant for the log file path
if (! defined('NGINX_CACHE_LOG_FILE')) {
    define('NGINX_CACHE_LOG_FILE', dirname(__DIR__) . '/fastcgi_ops.log');
}

// Define a constant for the desktop user agent
if (!defined('NPPP_USER_AGENT')) {
    define('NPPP_USER_AGENT', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/92.0.4515.159 Safari/537.36');
}

// Define a constant for the mobile user agent
if (!defined('NPPP_USER_AGENT_MOBILE')) {
    define('NPPP_USER_AGENT_MOBILE', 'Mozilla/5.0 (Linux; Android 15; SM-G960U) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.6778.81 Mobile Safari/537.36');
}

// Prepare PATH and SAFEXEC_QUIET env
function nppp_prepare_request_env(bool $force = false): void {
    static $done = false;
    if ($done) return;

    if (!$force) {
        $is_rest = (
            (function_exists('wp_is_serving_rest_request') && wp_is_serving_rest_request()) ||
            (function_exists('wp_doing_rest') && wp_doing_rest()) ||
            (defined('REST_REQUEST') && REST_REQUEST)
        );
        $is_cron  = (
            (function_exists('wp_doing_cron') && wp_doing_cron()) ||
            (defined('DOING_CRON') && DOING_CRON)
        );
        $is_admin = function_exists('is_admin') ? is_admin() : false;

        $is_needed = ($is_rest || $is_cron || $is_admin);
        if (defined('NPPP_FORCE_ENV')) {
            $is_needed = (bool) NPPP_FORCE_ENV;
        }
        if (function_exists('apply_filters')) {
            $is_needed = (bool) apply_filters('nppp_prepare_env_is_needed', $is_needed);
        }
        if (!$is_needed) return;
    }

    // PATH merge
    $need = ['/usr/local/sbin','/usr/local/bin','/usr/sbin','/usr/bin','/sbin','/bin'];
    $orig_path = getenv('PATH');
    if ($orig_path === false || $orig_path === '') {
        putenv('PATH=' . implode(':', $need));
    } else {
        $parts  = array_values(array_filter(explode(':', $orig_path), fn($p) => $p !== '' && $p !== '.'));
        $merged = array_values(array_unique(array_merge($need, $parts)));
        $new    = implode(':', $merged);
        if ($new !== $orig_path) {
            putenv("PATH=$new");
        }
    }

    // Quiet flag
    $orig_quiet = getenv('SAFEXEC_QUIET');
    $quiet_was_set_by_us = false;
    if ($orig_quiet === false || $orig_quiet === '') {
        putenv('SAFEXEC_QUIET=1');
        $quiet_was_set_by_us = true;
    }

    // Restore at shutdown (don’t leak into the worker)
    register_shutdown_function(function () use ($orig_path, $orig_quiet, $quiet_was_set_by_us) {
        if ($orig_path === false) {
            putenv('PATH');
        } elseif (getenv('PATH') !== $orig_path) {
            putenv("PATH=$orig_path");
        }

        if ($quiet_was_set_by_us) {
            putenv('SAFEXEC_QUIET');
        } elseif ($orig_quiet !== false && getenv('SAFEXEC_QUIET') !== $orig_quiet) {
            putenv("SAFEXEC_QUIET=$orig_quiet");
        }
    });

    $done = true;
}

// Set env for this request
nppp_prepare_request_env();

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
require_once dirname(__DIR__) . '/includes/dashboard-widget.php';

// Get the status of Auto Purge option
$options = get_option('nginx_cache_settings');
$nppp_auto_purge = isset($options['nginx_cache_purge_on_update']) && $options['nginx_cache_purge_on_update'] === 'yes';

// Add support on well known Cache Plugins
$page_cache_purge_actions = array(
    'after_rocket_clean_domain',                // WP Rocket
    'hyper_cache_purged',                       // Hyper Cache
    'w3tc_flush_all',                           // W3 Total Cache
    'ce_action_cache_cleared',                  // Cache Enabler
    'comet_cache_wipe_cache',                   // Comet Cache
    'wp_cache_cleared',                         // WP Super Cache
    'wpfc_delete_cache',                        // WP Fastest Cache
    'swift_performance_after_clear_all_cache',  // Swift Performance
    'litespeed_purged_all'                      // LiteSpeed Cache
);

// Add actions and filters
add_action('rest_api_init', function () { nppp_prepare_request_env(true); }, 1);
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
add_action('transition_post_status', 'nppp_purge_cache_on_update', 10, 3);
add_action('wp_insert_comment', 'nppp_purge_cache_on_comment', 200, 2);
add_action('transition_comment_status', 'nppp_purge_cache_on_comment_change', 200, 3);
add_action('admin_post_save_nginx_cache_settings', 'nppp_handle_nginx_cache_settings_submission');
add_action('upgrader_process_complete', 'nppp_purge_cache_on_theme_plugin_update', 10, 2);
add_action('wp_ajax_nppp_update_default_cache_key_regex_option', 'nppp_update_default_cache_key_regex_option');
add_action('switch_theme', 'nppp_purge_cache_on_theme_switch', 10, 3);
add_action('activated_plugin', 'nppp_purge_cache_plugin_activation_deactivation');
add_action('deactivated_plugin', 'nppp_purge_cache_plugin_activation_deactivation');
add_action('wp_ajax_nppp_update_auto_preload_mobile_option', 'nppp_update_auto_preload_mobile_option');
add_action('npp_plugin_tracking_event', 'nppp_plugin_tracking', 10, 1);
add_action('wp_dashboard_setup', 'nppp_add_dashboard_widget');
add_action('wp_ajax_nppp_update_enable_proxy_option', 'nppp_update_enable_proxy_option');
$nppp_auto_purge
    ? array_map(function($purge_action) { add_action($purge_action, 'nppp_purge_callback'); }, $page_cache_purge_actions)
    : array_map(function($purge_action) { remove_action($purge_action, 'nppp_purge_callback'); }, $page_cache_purge_actions);
$nppp_auto_purge
    ? (class_exists('autoptimizeCache') && add_action('autoptimize_action_cachepurged', 'nppp_purge_callback'))
    : (class_exists('autoptimizeCache') && remove_action('autoptimize_action_cachepurged', 'nppp_purge_callback'));
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
    // 1) Bail on REST
    if (function_exists('wp_is_serving_rest_request') && wp_is_serving_rest_request()) {
        return;
    } elseif (function_exists('wp_doing_rest') && wp_doing_rest()) {
        return;
    } elseif (defined('REST_REQUEST') && REST_REQUEST) {
        return;
    }

    // 2) Bail on AJAX
    if (function_exists('wp_doing_ajax') && wp_doing_ajax()
         || (defined('DOING_AJAX') && DOING_AJAX)) {
        return;
    }

    // 3) Bail on WP-Cron
    if (function_exists('wp_doing_cron') && wp_doing_cron()
         || (defined('DOING_CRON') && DOING_CRON)) {
        return;
    }

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
add_action('init', function () {
    if ( (defined('DOING_CRON') && DOING_CRON) || (function_exists('wp_doing_cron') && wp_doing_cron()) ) {
        nppp_prepare_request_env(true);
    }
}, 1);

// Register shortcodes
add_shortcode('nppp_svg_icon', 'nppp_svg_icon_shortcode');
add_shortcode('nppp_my_status', 'nppp_my_status_shortcode');
add_shortcode('nppp_my_faq', 'nppp_my_faq_shortcode');
add_shortcode('nppp_nginx_config', 'nppp_nginx_config_shortcode');
