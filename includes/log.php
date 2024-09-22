<?php
/**
 * Logging & WP admin notices function for FastCGI Cache Purge and Preload for Nginx
 * Description: This file contain logging & wp admin notices function for FastCGI Cache Purge and Preload for Nginx
 * Version: 2.0.3
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

    // Trigger the custom action to display the notice
    do_action('nppp_plugin_admin_notices', $type, $sanitized_message, $log_message, $display_notice);

    // Write to the log file
    if ($log_message) {
        if (!defined('NGINX_CACHE_LOG_FILE')) {
            // If the log file path is not defined or empty
            define('NGINX_CACHE_LOG_FILE', plugin_dir_path(__FILE__) . '../fastcgi_ops.log');
        }

        // Sanitize the file path to prevent directory traversal
        $log_file_path = NGINX_CACHE_LOG_FILE;
        $log_file_dir  = dirname($log_file_path);
        $log_file_name = basename($log_file_path);

        // Use realpath() to sanitize the directory
        $sanitized_dir_path = realpath($log_file_dir);

        // Check if the directory is valid and exists
        if ($sanitized_dir_path === false) {
            error_log("Invalid or inaccessible log file directory: " . $log_file_dir);
            return;
        }

        // Reconstruct the sanitized path for the file
        $sanitized_path = $sanitized_dir_path . '/' . $log_file_name;

        // Attempt to create the log file before append new log entry
        nppp_perform_file_operation($sanitized_path, 'create');

        // Prepare the log entry with timestamp
        $log_entry = '[' . current_time( 'Y-m-d H:i:s' ) . '] ' . $sanitized_message;

        // Attempt to append the log entry
        $append_result = nppp_perform_file_operation($sanitized_path, 'append', $log_entry);

        // Check the append log status
        if (!$append_result) {
            error_log("Error appending to log file at " . $sanitized_path);
            return;
        }
    }
}
