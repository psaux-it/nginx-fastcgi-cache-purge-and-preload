<?php
/**
 * Cache Purger
 *
 * Plugin Name: Nginx FastCGI Cache and Preload For Wordpress
 * Plugin URI: https://www.psauxit.com
 * Description: This plugin allows you to manage Nginx FastCGI cache and preload operations directly from your WordPress admin dashboard.
 * Description: Cache Purger clears cached content stored by Nginx FastCGI cache.
 * Version: 1.0
 * Author: Hasan CALISIR | hasan.calisir@psauxit.com
 * Author URI: https://www.psauxit.com
 * License: GPL2, WordPress copyright GPL.
 */

// Stop preload process before purge
function stop_crawl_and_visit() {
    // Check if the crawl and visit operation is in progress
    if (get_option(CRAWL_AND_VISIT_OPTION) === 'in_progress') {
        // Delete the option to stop the ongoing process
        delete_option(CRAWL_AND_VISIT_OPTION);
        // Return true to indicate success
        return true;
    } else {
        // Return false to indicate failure
        return false;
    }
}

// Function to purge cache & obsolete website content before preload
function purge_helper($nginx_cache_path) {
    // Check if the cache directory exists
    if (is_dir($nginx_cache_path)) {
        // Get all files and directories in the cache directory
        $files = scandir($nginx_cache_path);

        // Remove . and .. from the list of files
        $files = array_diff($files, array('.', '..'));

        foreach ($files as $file) {
            $file_path = $nginx_cache_path . '/' . $file;
            if (is_dir($file_path)) {
                // Recursively remove directory
                if (!purge_helper($file_path)) {
                    return 1; // Error occurred while deleting directory
                }
            } else {
                // Remove file
                if (!unlink($file_path)) {
                    return 1; // Error occurred while deleting file
                }
            }
        }

        // Remove the current directory after its contents are cleared
        if (!rmdir($nginx_cache_path)) {
            return 1; // Error occurred while deleting directory
        }

        return 0; // Success
    } else {
        return 2; // Error: Cache directory not found
    }
}

// Function to purge FastCGI cache
function purge($nginx_cache_path) {
    // Stop ongoing preload process if exist
    if (stop_crawl_and_visit()) {
        // Call purge_helper() to purge cache
        $status = purge_helper($nginx_cache_path);

        // Check the status returned by purge_helper()
        if ($status === 0) {
            display_admin_notice('success', "FastCGI cache preloading is stopped. Purge FastCGI cache is completed");
        } elseif ($status === 1) {
            display_admin_notice('error', "ERROR PERMISSION: FastCGI cache preloading is stopped but Purge FastCGI cache cannot be completed. Please restart wp-fcgi-notify.service");
        } elseif ($status === 2) {
            display_admin_notice('error', 'ERROR PATH: Your FastCGI cache PATH ( ' . $nginx_cache_path . ' ) not found. To fix it -- 1) Check plugin settings  2) Check nginx config settings and restart nginx.service 3) Restart wp-fcgi-notify.service');
        } else {
            display_admin_notice('error', "ERROR UNKNOWN: Cannot Purge FastCGI cache");
        }
    } else {
        // If preload process is not ongoing, call purge_helper() and handle the status accordingly
        $status = purge_helper($nginx_cache_path);
        if ($status === 0) {
            display_admin_notice('success', "Purge FastCGI cache is completed");
        } elseif ($status === 1) {
            display_admin_notice('error', "ERROR PERMISSION: Purge FastCGI cache cannot be completed. Please restart wp-fcgi-notify.service");
        } elseif ($status === 2) {
            display_admin_notice('error', 'ERROR PATH: Your FastCGI cache PATH ( ' . $nginx_cache_path . ' ) not found. To fix it -- 1) Check plugin settings  2) Check nginx config settings and restart nginx.service 3) Restart wp-fcgi-notify.service');
        } else {
            display_admin_notice('error', "ERROR UNKNOWN: Cannot Purge FastCGI cache");
        }
    }
}
