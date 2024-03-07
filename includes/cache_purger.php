<?php
/*
Description: Automatically purge Nginx cache
Author: Hasan ÇALIŞIR | hasan.calisir@psauxit.com
Author URI: https://www.psauxit.com
License: GPL2
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
                $success = remove_directory($file_path);
                if (!$success) {
                    return 1; // Error occurred while deleting directory
                }
            } else {
                // Remove file
                if (!unlink($file_path)) {
                    return 1; // Error occurred while deleting file
                }
            }
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
            display_admin_notice("FastCGI cache preloading is stopped. Purge FastCGI cache is completed.", 'success');
        } elseif ($status === 1) {
            display_admin_notice("ERROR PERMISSION: FastCGI cache preloading is stopped but Purge FastCGI cache cannot be completed. Please restart wp-fcgi-notify.service", 'error');
            exit(1);
        } elseif ($status === 2) {
            display_admin_notice("ERROR PATH: Your FastCGI cache PATH ($nginx_cache_path) not found. To fix it -- 1) Check plugin settings  2) Check nginx config settings and restart nginx.service 3) Restart wp-fcgi-notify.service", 'error');
            exit(1);
        } else {
            display_admin_notice("ERROR UNKNOWN: Cannot Purge FastCGI cache.", 'error');
            exit(1);
        }   
    } else {
        // If preload process is not ongoing, call purge_helper() and handle the status accordingly
        $status = purge_helper($nginx_cache_path);
        if ($status === 0) {
            display_admin_notice("Purge FastCGI cache is completed.", 'success');
        } elseif ($status === 1) {
            display_admin_notice("ERROR PERMISSION: Purge FastCGI cache cannot be completed. Please restart wp-fcgi-notify.service", 'error');
            exit(1);
        } elseif ($status === 2) {
            display_admin_notice("ERROR PATH: Your FastCGI cache PATH ($nginx_cache_path) not found. To fix it -- 1) Check plugin settings  2) Check nginx config settings and restart nginx.service 3) Restart wp-fcgi-notify.service", 'error');
            exit(1);
        } else {
            display_admin_notice("ERROR UNKNOWN: Cannot Purge FastCGI cache.", 'error');
            exit(1);
        }
    }
}
