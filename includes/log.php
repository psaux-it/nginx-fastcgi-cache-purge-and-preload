<?php
/**
 * Logging & status functions for FastCGI Cache Purge and Preload for Nginx
 * Description: This file contains Logging & status functions for FastCGI Cache Purge and Preload for Nginx
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

// Create log file
function nppp_create_log_file($log_file_path) {
    // Check if log file path is provided and is a string
    if (empty($log_file_path) || !is_string($log_file_path)) {
        return "Log file path is invalid.";
    }

    // Sanitize the file path to prevent directory traversal
    $log_file_dir  = dirname($log_file_path);
    $log_file_name = basename($log_file_path);
    $sanitized_path = realpath($log_file_dir) . '/' . $log_file_name;

    // Check if realpath returned a valid directory
    if ($sanitized_path === false) {
        return "Log file directory does not exist.";
    }

    // Attempt to create the file
    $file_creation_result = nppp_perform_file_operation($sanitized_path, 'create');

    if (is_wp_error($file_creation_result)) {
        return "Error: " . $file_creation_result->get_error_message();
    }

    return true;
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

    // Write to the log file if required
    if ($log_message) {
        if (defined('NGINX_CACHE_LOG_FILE') && !empty(NGINX_CACHE_LOG_FILE)) {
            $log_file_path = NGINX_CACHE_LOG_FILE;

            // Sanitize the file path to prevent directory traversal
            $log_file_dir  = dirname($log_file_path);
            $log_file_name = basename($log_file_path);
            $sanitized_path = realpath($log_file_dir) . '/' . $log_file_name;

            // Check if realpath returned a valid directory
            if ($sanitized_path === false) {
                error_log("Invalid or inaccessible log file directory: " . $log_file_dir);
                return;
            }

            // Attempt to create the log file if it doesn't exist
            $create_result = nppp_perform_file_operation($sanitized_path, 'create');

            if (!$create_result) {
                error_log("Error creating log file at " . $sanitized_path);
                return;
            }

            // Prepare the log entry with timestamp
            $log_entry = '[' . current_time( 'Y-m-d H:i:s' ) . '] ' . $sanitized_message;

            // Attempt to append the log entry
            $append_result = nppp_perform_file_operation($sanitized_path, 'append', $log_entry);

            if (!$append_result) {
                error_log("Error appending to log file at " . $sanitized_path);
                return;
            }
        } else {
            // Log an error if the log file path is not defined or empty
            error_log("Log file path is not defined or is empty.");
            return;
        }
    }
}
