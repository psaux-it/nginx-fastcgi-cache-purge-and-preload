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

// Modify your display_purge_admin_notice function to enqueue the notice to be displayed later
function enqueue_purge_admin_notice($message, $notice_type = 'success') {
    $notices = get_option('purge_admin_notices', array());
    $notices[] = array(
        'message' => $message,
        'type' => $notice_type,
    );
    update_option('purge_admin_notices', $notices);
}

// Add an action hook to display the admin notices
add_action('admin_notices', 'display_purge_admin_notices');

// Function to display manual WordPress admin notices for purge operation
function display_purge_admin_notices() {
    $notices = get_option('purge_admin_notices', array());
    foreach ($notices as $notice) {
        $message = esc_html($notice['message']);
        $notice_type = $notice['type'];
        echo '<div class="notice notice-' . $notice_type . ' is-dismissible"><p>' . $message . '</p></div>';
    }
    // Clear the notices after displaying them
    delete_option('purge_admin_notices');
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
            enqueue_purge_admin_notice("FastCGI cache preloading is stopped. Purge FastCGI cache is completed.", 'success');
        } elseif ($status === 1) {
            enqueue_purge_admin_notice("FastCGI cache preloading is stopped but Purge FastCGI cache cannot be completed. Please restart wp-fcgi-notify.service", 'error');
            exit(1);
        } elseif ($status === 2) {
            enqueue_purge_admin_notice("Your FastCGI cache PATH ($nginx_cache_path) not found. To fix it -- 1) Check plugin settings  2) Check nginx config settings and restart nginx.service 3) Restart wp-fcgi-notify.service", 'error');
            exit(1);
        } else {
            enqueue_purge_admin_notice("Cannot Purge FastCGI cache.", 'error');
            exit(1);
        }
    } else {
        // If preload process is not ongoing, call purge_helper() and handle the status accordingly
        $status = purge_helper($nginx_cache_path);
        if ($status === 0) {
            enqueue_purge_admin_notice("Purge FastCGI cache is completed.", 'success');
        } elseif ($status === 1) {
            enqueue_purge_admin_notice("Purge FastCGI cache cannot be completed. Please restart wp-fcgi-notify.service", 'error');
            exit(1);
        } elseif ($status === 2) {
            enqueue_purge_admin_notice("Your FastCGI cache PATH ($nginx_cache_path) not found. To fix it -- 1) Check plugin settings  2) Check nginx config settings and restart nginx.service 3) Restart wp-fcgi-notify.service", 'error');
            exit(1);
        } else {
            enqueue_purge_admin_notice("Cannot Purge FastCGI cache.", 'error');
            exit(1);
        }
    }
}
