<?php
/**
 * Logging & WP admin notices function for FastCGI Cache Purge and Preload for Nginx
 * Description: This file contain logging & wp admin notices function for FastCGI Cache Purge and Preload for Nginx
 * Version: 2.0.7
 * Author: Hasan ÇALIŞIR
 * Author Email: hasan.calisir@psauxit.com
 * Author URI: https://www.psauxit.com
 * License: GPL-2.0+
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Hook to display your plugin's notices
function nppp_display_admin_notice($type, $message, $log_message = true, $display_notice = true) {
    // Validate and sanitize the notice type
    $allowed_types = array('success', 'error', 'warning', 'info');
    if (!in_array($type, $allowed_types, true)) {
        $type = 'info';
    }

    // Sanitize the message
    $sanitized_message = sanitize_text_field($message);

    // Write to the log file
    if ($log_message) {
        if (!defined('NGINX_CACHE_LOG_FILE')) {
            // If the log file path is not defined or empty
            define('NGINX_CACHE_LOG_FILE', dirname(__FILE__) . '/../fastcgi_ops.log');
        }

        // Sanitize the file path to prevent directory traversal
        $log_file_path = NGINX_CACHE_LOG_FILE;
        $log_file_dir  = dirname($log_file_path);
        $log_file_name = basename($log_file_path);

        // Use realpath() to sanitize the directory
        $sanitized_dir_path = realpath($log_file_dir);

        // Check if the directory is valid and exists
        if ($sanitized_dir_path !== false) {
            // Reconstruct the sanitized path for the file
            $sanitized_path = $sanitized_dir_path . '/' . $log_file_name;

            // Attempt to create the log file before append if it doesn't exist
            nppp_perform_file_operation($sanitized_path, 'create');

            // Prepare the log entry with timestamp
            $log_entry = '[' . current_time( 'Y-m-d H:i:s' ) . '] ' . $sanitized_message;

            // Attempt to append the log entry
            $append_result = nppp_perform_file_operation($sanitized_path, 'append', $log_entry);

            if (!$append_result) {
                nppp_custom_error_log("Error appending to log file at " . $sanitized_path);
            }
        } else {
            nppp_custom_error_log("Invalid or inaccessible log file directory: " . $log_file_dir);
        }
    }

    // Allow admin notices only for NPP AJAX actions
    // to prevent interfere with core WP AJAX
    // while Auto purge triggers for COMMENT, POST/PAGE.
    if (defined('DOING_AJAX') && DOING_AJAX) {
        $allowed_actions = [
            'nppp_clear_nginx_cache_logs',
            'nppp_get_nginx_cache_logs',
            'nppp_update_send_mail_option',
            'nppp_update_auto_preload_option',
            'nppp_update_auto_purge_option',
            'nppp_cache_status',
            'nppp_load_premium_content',
            'nppp_purge_cache_premium',
            'nppp_preload_cache_premium',
            'nppp_update_api_key_option',
            'nppp_update_default_reject_regex_option',
            'nppp_update_default_reject_extension_option',
            'nppp_update_api_option',
            'nppp_update_api_key_copy_value',
            'nppp_rest_api_purge_url_copy',
            'nppp_rest_api_preload_url_copy',
            'nppp_get_save_cron_expression',
            'nppp_update_cache_schedule_option',
            'nppp_cancel_scheduled_event',
            'nppp_get_active_cron_events_ajax',
            'nppp_clear_plugin_cache',
            'nppp_restart_systemd_service',
            'nppp_update_default_cache_key_regex_option',
        ];

        $action = isset($_REQUEST['action']) ? sanitize_text_field(wp_unslash($_REQUEST['action'])) : '';
        if (empty($action) || !in_array($action, $allowed_actions, true)) {
            return;
        }
    }

    // If this is a REST API
    if (function_exists('wp_doing_rest') && wp_doing_rest()) {
        echo '<p>' . esc_html($sanitized_message) . '</p>';
        return;
    } elseif (defined('REST_REQUEST') && REST_REQUEST) {
        echo '<p>' . esc_html($sanitized_message) . '</p>';
        return;
    }

    // If this is a WP CRON prevent admin notices
    if (function_exists('wp_doing_cron') && wp_doing_cron()) {
        return '';
    } elseif (defined('DOING_CRON') && DOING_CRON) {
        return '';
    }

    // Perform the permission check for admin actions
    if (is_admin()) {
        if (! current_user_can('manage_options')) {
            echo '<div class="notice notice-error"><p>You do not have sufficient permissions to access this page.</p></div>';
            return;
        }
    }

    // Trigger the custom action to display the notice (if not prevented earlier)
    do_action('nppp_plugin_admin_notices', $type, $sanitized_message, $log_message, $display_notice);
}
