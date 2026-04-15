<?php
/**
 * Logging and admin notice helpers for Nginx Cache Purge Preload
 * Description: Centralizes plugin log writes and standardized WordPress admin notice rendering.
 * Version: 2.1.5
 * Author: Hasan CALISIR
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

    // Track the highest-severity type seen during any ob_start() capture.
    // Priority: error > warning > info > success
    // So once 'error' is set, a later 'success' notice won't overwrite it.
    $priority = ['success' => 0, 'info' => 1, 'warning' => 2, 'error' => 3];
    $current  = $GLOBALS['nppp_last_notice_type'] ?? 'success';
    if ( $display_notice && ($priority[$type] ?? 0) >= ($priority[$current] ?? 0)) {
        $GLOBALS['nppp_last_notice_type'] = $type;
    }

    // Sanitize the message
    $sanitized_message = sanitize_text_field($message);

    // Write to the log file
    if ($log_message) {
        if (!defined('NGINX_CACHE_LOG_FILE')) {
            // If the log file path is not defined or empty
            define('NGINX_CACHE_LOG_FILE', nppp_get_runtime_file('fastcgi_ops.log'));
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
                // Translators: %s is the path to the log file.
                nppp_custom_error_log(sprintf(__('Error appending to log file at %s', 'fastcgi-cache-purge-and-preload-nginx'), $sanitized_path));
            }
        } else {
            // Translators: %s is the path to the log file.
            nppp_custom_error_log(sprintf(__('Invalid or inaccessible log file directory: %s', 'fastcgi-cache-purge-and-preload-nginx'), $log_file_dir));
        }
    }

    /**
     *    LOGGING IS COMPLETE.
     *    NOW, PREVENT NPP ADMIN NOTICES
     *    FROM INTERFERING WITH WP AJAX, REST, CRON, AND SCREENS.
     */

    // Bail out on *all* REST requests except our own purge/preload endpoints
    if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
        // ONLY emit into a buffer that WE started.
        // We track the ob level in $GLOBALS['nppp_rest_ob_level'] set in rest-api.php
        // just before ob_start(). If the current level is exactly one above what we
        // recorded, this is our buffer — safe to echo into.
        // If it's anything else (0, or a different depth), another plugin owns the
        // active buffer and we must not touch it.
        $our_level = isset($GLOBALS['nppp_rest_ob_level'])
            ? (int) $GLOBALS['nppp_rest_ob_level'] + 1
            : -1;

        // Only echo if the current buffer level exactly matches the one we started.
        if (ob_get_level() === $our_level && $display_notice) {
            echo '<p>' . esc_html(sanitize_text_field($message)) . '</p>';
        }

        return;
    }

    // Allow admin notices only for NPP AJAX actions.
    // Prevent interference with core WordPress AJAX responses.
    // Verify nonce for WP Admin Notices
    if (defined('DOING_AJAX') && DOING_AJAX) {
        $allowed_actions = [
            'nppp_clear_nginx_cache_logs'                 => 'nppp-clear-nginx-cache-logs',
            'nppp_get_nginx_cache_logs'                   => 'nppp-clear-nginx-cache-logs',
            'nppp_update_send_mail_option'                => 'nppp-update-send-mail-option',
            'nppp_update_auto_preload_option'             => 'nppp-update-auto-preload-option',
            'nppp_refresh_cache_ratio'                    => 'nppp_refresh_cache_ratio',
            'nppp_update_watchdog_option'                 => 'nppp-update-watchdog-option',
            'nppp_update_auto_purge_option'               => 'nppp-update-auto-purge-option',
            'nppp_update_cloudflare_apo_sync_option'      => 'nppp-update-cloudflare-apo-sync-option',
            'nppp_update_redis_cache_sync_option'         => 'nppp-update-redis-cache-sync-option',
            'nppp_cache_status'                           => 'cache-status',
            'nppp_load_premium_content'                   => 'load_premium_content_nonce',
            'nppp_purge_cache_premium'                    => 'purge_cache_premium_nonce',
            'nppp_preload_cache_premium'                  => 'preload_cache_premium_nonce',
            'nppp_update_api_key_option'                  => 'nppp-update-api-key-option',
            'nppp_update_default_reject_regex_option'     => 'nppp-update-default-reject-regex-option',
            'nppp_update_default_reject_extension_option' => 'nppp-update-default-reject-extension-option',
            'nppp_update_api_option'                      => 'nppp-update-api-option',
            'nppp_update_api_key_copy_value'              => 'nppp-update-api-key-copy-value',
            'nppp_rest_api_purge_url_copy'                => 'nppp-rest-api-purge-url-copy',
            'nppp_rest_api_preload_url_copy'              => 'nppp-rest-api-preload-url-copy',
            'nppp_get_save_cron_expression'               => 'nppp-get-save-cron-expression',
            'nppp_update_cache_schedule_option'           => 'nppp-update-cache-schedule-option',
            'nppp_cancel_scheduled_event'                 => 'nppp-cancel-scheduled-event',
            'nppp_get_active_cron_events_ajax'            => 'nppp-get-save-cron-expression',
            'nppp_clear_plugin_cache'                     => 'nppp-clear-plugin-cache-action',
            'nppp_update_default_cache_key_regex_option'  => 'nppp-update-default-cache-key-regex-option',
            'nppp_update_auto_preload_mobile_option'      => 'nppp-update-auto-preload-mobile-option',
            'nppp_update_enable_proxy_option'             => 'nppp-update-enable-proxy-option',
            'nppp_update_related_fields'                  => 'nppp-related-posts-purge',
            'nppp_update_pctnorm_mode'                    => 'nppp-update-pctnorm-mode',
            'nppp_update_http_purge_option'               => 'nppp-update-http-purge-option',
            'nppp_update_rg_purge_option'                 => 'nppp-update-rg-purge-option',
        ];

        // Get the current AJAX action
        $action = isset($_REQUEST['action']) ? sanitize_text_field(wp_unslash($_REQUEST['action'])) : '';

        // Only continue if the action comes from NPP
        // Do nonce verification for Admin Notices
        if (!empty($action) && array_key_exists($action, $allowed_actions)) {
            // Check if nonce is set and is valid
            if (!isset($_REQUEST['_wpnonce'])) {
                // Translators: This message appears when a required nonce is missing.
                wp_die(esc_html__('Nonce is missing.', 'fastcgi-cache-purge-and-preload-nginx'));
            }

            // Sanitize nonce
            $nonce = sanitize_text_field(wp_unslash($_REQUEST['_wpnonce']));
            $expected_nonce = $allowed_actions[$action];

            // Verify nonce for WP Admin Notices
            if (!wp_verify_nonce($nonce, $expected_nonce)) {
                // Translators: This message appears when the provided nonce is invalid.
                wp_die(esc_html__('Invalid nonce. Request could not be verified.', 'fastcgi-cache-purge-and-preload-nginx'));
            }

            // Further security check to verify the user’s capability
            if (!current_user_can('manage_options')) {
                // Translators: This message appears when a user does not have the required permissions.
                wp_die(esc_html__('Permission denied', 'fastcgi-cache-purge-and-preload-nginx'));
            }
        } else {
            return;
        }
    }

    // Allow admin notices only for NPP CRON actions
    // Prevent interference with core WordPress Cron responses.
    if (function_exists('wp_doing_cron') && wp_doing_cron()) {
        return;
    } elseif (defined('DOING_CRON') && DOING_CRON) {
        return;
    }

    // Perform the permission check for admin actions
    if (!current_user_can('manage_options')) {
        return;
    }

    // Define the array of WP screen IDs
    $screen_ids = array(
        'dashboard',
        'post',
        'edit-post',
        'page',
        'edit-page',
        'upload',
        'edit-comments',
        'themes',
        'themes-network',
        'widgets',
        'menus',
        'customize',
        'plugins',
        'plugin-install',
        'users',
        'tools',
        'general',
        'writing',
        'reading',
        'discussion',
        'media',
        'permalink',
        'update',
        'edit-category',
        'edit-post_tag',
        'import',
        'export'
    );

    // Prevent NPP admin notices interfere with core WP screens
    if (function_exists('get_current_screen')) {
        $screen = get_current_screen();
        // Check if the current screen ID is in the array
        if ($screen && in_array($screen->id, $screen_ids)) {
            return;
        }
    }

    // All filters have passed, ready to display the admin notice
    do_action('nppp_plugin_admin_notices', $type, $sanitized_message, $log_message, $display_notice);
}
