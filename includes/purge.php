<?php
/**
 * Purge action functions for Nginx FastCGI Cache Purge and Preload
 * Description: This file contains Purge action functions for Nginx FastCGI Cache Purge and Preload.
 * Version: 1.0.3
 * Author: Hasan ÇALIŞIR
 * Author Email: hasan.calisir@psauxit.com
 * Author URI: https://www.psauxit.com
 * License: GPL-2.0+
 */

// Purge cache operation helper
function purge_helper($nginx_cache_path) {
    $wp_filesystem = initialize_wp_filesystem();

    if ($wp_filesystem === false) {
        return false; // Return false if WP_Filesystem initialization failed
    }

    // Remove absolute downloaded content if exists
    $this_script_path = plugin_dir_path(__FILE__);
    $tmp_path = rtrim($this_script_path, '/') . "/tmp";

    if ($wp_filesystem->is_dir($tmp_path)) {
        wp_remove_directory($tmp_path, true);
    }

    // Check if the cache path exists and is a directory
    if ($wp_filesystem->is_dir($nginx_cache_path)) {
        // Recursively remove the cache directory contents.
        $result = wp_purge($nginx_cache_path);

        // Check cache purge status
        if (is_wp_error($result)) {
            return 1; // Permission issue
        } else {
            return 0; // Assume purge successful
        }
    } else {
        return 2; // Directory doesn't exist
    }
}

// Purge cache operation
function purge($nginx_cache_path, $PIDFILE) {
    // Initialize variables for messages
    $message_type = '';
    $message_content = '';

    // Check if the PID file exists
    if (file_exists($PIDFILE)) {
        $pid = intval(perform_file_operation($PIDFILE, 'read'));

        // Check if the preload process is alive
        if ($pid > 0 && posix_kill($pid, 0)) {
            // If process is alive, kill it
            posix_kill($pid, SIGTERM);
            usleep(50000); // Wait for 50 milliseconds

            // Call purge_helper to delete cache contents and get status
            $status = purge_helper($nginx_cache_path);

            // Determine message based on status
            switch ($status) {
                case 0:
                    $message_type = 'success';
                    $message_content = 'SUCCESS: FastCGI cache preloading is stopped. Purge FastCGI cache is completed.';
                    break;
                case 1:
                    $message_type = 'error';
                    $message_content = 'ERROR PERMISSION: FastCGI cache preloading is stopped but Purge FastCGI cache failed. Please read help section of the plugin.';
                    break;
                case 2:
                    $message_type = 'error';
                    $message_content = 'ERROR PATH: Your FastCGI cache PATH (' . $nginx_cache_path . ') not found. Please check your FastCGI cache path.';
                    break;
            }

            // Remove the PID file
            perform_file_operation($PIDFILE, 'delete');
        }
    } else {
        // Call purge_helper to delete cache contents and get status
        $status = purge_helper($nginx_cache_path);

        // Determine message based on status
        switch ($status) {
            case 0:
                $message_type = 'success';
                $message_content = 'SUCCESS: Purge FastCGI cache is completed.';
                break;
            case 1:
                $message_type = 'error';
                $message_content = 'ERROR PERMISSION: Purge FastCGI cache cannot completed. Please read help section of the plugin.';
                break;
            case 2:
                $message_type = 'error';
                $message_content = 'ERROR PATH: Your FastCGI cache PATH (' . $nginx_cache_path . ') is not found. Please check your FastCGI cache path.';
                break;
        }
    }

    // Display the admin notice
    display_admin_notice($message_type, $message_content);
}
