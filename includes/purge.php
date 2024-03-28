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
function purge_helper($nginx_cache_path, $tmp_path) {
    $wp_filesystem = initialize_wp_filesystem();

    if ($wp_filesystem === false) {
        return false; // Return false if WP_Filesystem initialization failed
    }

    if ($wp_filesystem->is_dir($tmp_path)) {
        wp_remove_directory($tmp_path, true);
    }

    // Check if the cache path exists and is a directory
    if ($wp_filesystem->is_dir($nginx_cache_path)) {
        // Recursively remove the cache directory contents.
        $result = wp_purge($nginx_cache_path);

        // Check cache purge status
        if (is_wp_error($result)) {
            $error_code = $result->get_error_code();
            if ($error_code === 'permission_error') {
                return 1;
            } elseif ($error_code === 'empty_directory') {
                return 2;
            } elseif ($error_code === 'directory_not_found') {
                return 3;
            } else {
                return 4;
            }
        } else {
            return 0;
        }
    } else {
        return 3;
    }
}

// Purge cache operation
function purge($nginx_cache_path, $PIDFILE, $tmp_path) {
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
            $status = purge_helper($nginx_cache_path, $tmp_path);

            // Determine message based on status
            switch ($status) {
                case 0:
                    $message_type = 'success';
                    $message_content = 'SUCCESS: FastCGI cache preloading is stopped. Purge FastCGI cache is completed.';
                    break;
                case 1:
                    $message_type = 'error';
                    $message_content = 'ERROR PERMISSION: FastCGI cache preloading is stopped but Purge FastCGI cache failed due to permission issues. Please read the help section of the plugin.';
                    break;
                case 3:
                    $message_type = 'error';
                    $message_content = 'ERROR PATH: Your FastCGI cache PATH (' . $nginx_cache_path . ') is not found. Please check your FastCGI cache path.';
                    break;
                case 4:
                    $message_type = 'error';
                    $message_content = 'ERROR UNKNOWN: An unexpected error occurred while purging the FastCGI cache. Please file a bug.';
                    break;
            }

            // Remove the PID file
            perform_file_operation($PIDFILE, 'delete');
        }
    } else {
        // Call purge_helper to delete cache contents and get status
        $status = purge_helper($nginx_cache_path, $tmp_path);

        // Determine message based on status
        switch ($status) {
            case 0:
                $message_type = 'success';
                $message_content = 'SUCCESS: Purge FastCGI cache is completed.';
                break;
            case 1:
                $message_type = 'error';
                $message_content = 'ERROR PERMISSION: Purge FastCGI cache cannot be completed due to permission issues. Please read the help section of the plugin.';
                break;
            case 2:
                $message_type = 'info';
                $message_content = 'INFO: Your FastCGI cache directory is empty.';
                break;
            case 3:
                $message_type = 'error';
                $message_content = 'ERROR PATH: Your FastCGI cache PATH (' . $nginx_cache_path . ') is not found. Please check your FastCGI cache path.';
                break;
            case 4:
                $message_type = 'error';
                $message_content = 'ERROR UNKNOWN: An unexpected error occurred while purging the FastCGI cache. Please file a bug.';
                break;
        }
    }

    // Display the admin notice
    display_admin_notice($message_type, $message_content);
}
