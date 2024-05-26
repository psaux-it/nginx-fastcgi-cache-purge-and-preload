<?php
/**
 * Logging & status functions for FastCGI Cache Purge and Preload for Nginx
 * Description: This file contains Logging & status functions for FastCGI Cache Purge and Preload for Nginx
 * Version: 2.0.0
 * Author: Hasan ÇALIŞIR
 * Author Email: hasan.calisir@psauxit.com
 * Author URI: https://www.psauxit.com
 * License: GPL-2.0+
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Create log file
function nppp_create_log_file($log_file_path) {
    if (!empty($log_file_path)) {
        $file_creation_result = nppp_perform_file_operation($log_file_path, 'create');
        if (is_wp_error($file_creation_result)) {
            return "Error: " . $file_creation_result->get_error_message();
        }
        return true;
    }
    return "Log file path is empty.";
}

// Display admin notices nonce verified
function nppp_display_admin_notice($type, $message, $log_message = true) {
    // Display admin notice
    echo '<div class="notice notice-' . esc_attr($type) . ' is-dismissible"><p>' . esc_html($message) . '</p></div>';

    // Write to the log file
    if ($log_message) {
        $log_file_path = NGINX_CACHE_LOG_FILE;
        nppp_perform_file_operation($log_file_path, 'create');
        !empty($log_file_path) ? nppp_perform_file_operation($log_file_path, 'append', '[' . current_time('Y-m-d H:i:s') . '] ' . $message) : die("Log file not found!");
    }
}
