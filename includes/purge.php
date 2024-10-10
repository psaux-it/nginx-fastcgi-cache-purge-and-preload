<?php
/**
 * Purge action functions for FastCGI Cache Purge and Preload for Nginx
 * Description: This file contains Purge action functions for FastCGI Cache Purge and Preload for Nginx
 * Version: 2.0.4
 * Author: Hasan ÇALIŞIR
 * Author Email: hasan.calisir@psauxit.com
 * Author URI: https://www.psauxit.com
 * License: GPL-2.0+
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Purge cache operation helper
function nppp_purge_helper($nginx_cache_path, $tmp_path) {
    $wp_filesystem = nppp_initialize_wp_filesystem();

    if ($wp_filesystem === false) {
        nppp_display_admin_notice(
            'error',
            'Failed to initialize the WordPress filesystem. Please file a bug on the plugin support page.'
        );
        return;
    }

    if ($wp_filesystem->is_dir($tmp_path)) {
        nppp_wp_remove_directory($tmp_path, true);
    }

    // Check if the cache path exists and is a directory
    if ($wp_filesystem->is_dir($nginx_cache_path)) {
        // Recursively remove the cache directory contents.
        $result = nppp_wp_purge($nginx_cache_path);

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

// Auto Purge & On page purge operations
function nppp_purge_single($nginx_cache_path, $current_page_url, $nppp_auto_purge = false) {
    // Initialize WordPress filesystem
    $wp_filesystem = nppp_initialize_wp_filesystem();

    if ($wp_filesystem === false) {
        nppp_display_admin_notice(
            'error',
            'Failed to initialize the WordPress filesystem. Please file a bug on the plugin support page.'
        );
        return;
    }

    // Get the PIDFILE location
    $this_script_path = dirname(plugin_dir_path(__FILE__));
    $PIDFILE = rtrim($this_script_path, '/') . '/cache_preload.pid';

    // Get the status of Auto Preload option
    $options = get_option('nginx_cache_settings');
    $nppp_auto_preload = isset($options['nginx_cache_auto_preload']) && $options['nginx_cache_auto_preload'] === 'yes';

    // First, check if any active cache preloading action is in progress.
    // Purging the cache for a single page or post, whether done manually (Fonrtpage) or automatically (Auto Purge) after content updates,
    // can cause issues if there is an active cache preloading process.
    if ($wp_filesystem->exists($PIDFILE)) {
        $pid = intval(nppp_perform_file_operation($PIDFILE, 'read'));

        if ($pid > 0 && posix_kill($pid, 0)) {
            nppp_display_admin_notice('info', "INFO: Auto Purge for page $current_page_url halted due to ongoing cache preloading. You can stop cache preloading anytime via Purge All.");
            return;
        }
    }

    // Valitade the sanitized url before process
    if (filter_var($current_page_url, FILTER_VALIDATE_URL) !== false) {
        // Remove http:// or https:// from the URL and append a forward slash
        $url_to_search = preg_replace('#^https?://#', '', $current_page_url);
        $url_to_search_exact = rtrim($url_to_search, '/') . '/';
    } else {
        nppp_display_admin_notice('error', "ERROR URL: HTTP_REFERRER URL can not validated.");
        return;
    }

    try {
        // Traverse the cache directory and its subdirectories
        $cache_iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($nginx_cache_path, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );

        $found = false;
        foreach ($cache_iterator as $file) {
            if ($wp_filesystem->is_file($file->getPathname())) {
                // Check read and write permissions for each file
                if (!$wp_filesystem->is_readable($file->getPathname()) || !$wp_filesystem->is_writable($file->getPathname())) {
                    nppp_display_admin_notice('error', "ERROR PERMISSION: Cache purge failed for page $current_page_url due to permission issue. Refer to -Help- tab for guidance.");
                    return;
                }

                // Read file contents
                $content = $wp_filesystem->get_contents($file->getPathname());

                // Exclude URLs with status 301 Moved Permanently
                if (strpos($content, 'Status: 301 Moved Permanently') !== false) {
                    continue;
                }

                // Check for the URL after 'KEY: httpsGET'
                if (preg_match('/^KEY:\s+https?GET' . preg_quote($url_to_search_exact, '/') . '$/m', $content)) {
                    $cache_path = $file->getPathname();
                    $found = true;

                    // Sanitize and validate the file path before delete
                    // This is an extra security layer
                    $validation_result = nppp_validate_path($cache_path, true);

                    // Check the validation result
                    if ($validation_result !== true) {
                        // Handle different validation outcomes
                        switch ($validation_result) {
                            case 'critical_path':
                                $error_message = 'ERROR PATH: The cache path appears to be a critical system directory or a first-level directory. Cannot purge cache!';
                                break;
                            case 'file_not_found_or_not_readable':
                                $error_message = 'ERROR PATH: The specified cache path does not exist. Cannot purge cache!';
                                break;
                            default:
                                $error_message = 'ERROR PATH: An invalid cache path was provided. Cannot purge cache!';
                        }
                        nppp_display_admin_notice('error', $error_message);
                        return;
                    }

                    // Perform the purge action (delete the file)
                    $deleted = $wp_filesystem->delete($cache_path);
                    if ($deleted) {
                         if (!$nppp_auto_purge && !$nppp_auto_preload) {
                             nppp_display_admin_notice('success', "SUCCESS ADMIN: Cache Purged for page $current_page_url");
                         } else {
                             if ($nppp_auto_purge && $nppp_auto_preload) {
                                 nppp_preload_cache_on_update($current_page_url, true);
                             } elseif ($nppp_auto_purge) {
                                 nppp_display_admin_notice('success', "SUCCESS ADMIN: Cache Purged for page $current_page_url");
                             } elseif ($nppp_auto_preload) {
                                  nppp_display_admin_notice('success', "SUCCESS ADMIN: Cache Purged for page $current_page_url");
                             }
                        }
                    } else {
                        nppp_display_admin_notice('error', "ERROR UNKNOWN: An unexpected error occurred while purging cache for page $current_page_url. Please file a bug on plugin support page.");
                    }
                    return;
                }
            }
        }
    } catch (Exception $e) {
        nppp_display_admin_notice('error', "ERROR PERMISSION: Cache purge failed for page $current_page_url due to permission issue. Refer to -Help- tab for guidance.");
        return;
    }

    // If the URL is not found in the cache, check auto preload status
    if (!$found) {
        // Check if auto preload is enabled
        if ($nppp_auto_preload) {
            // Trigger the preload function
            nppp_preload_cache_on_update($current_page_url, false);
        } else {
            // Display admin notice if auto preload is not enabled
            nppp_display_admin_notice('info', "INFO ADMIN: Cache purge attempted, but the page $current_page_url is not currently found in the cache.");
        }
    }
}

// Auto Purge (Single)
// Purge cache automatically for updated content (post/page)
// This function hooks into the 'save_post' action
function nppp_purge_cache_on_update($post_id) {
    // Check if this is an autosave or a post revision
    if (wp_is_post_autosave($post_id) || wp_is_post_revision($post_id)) {
        return;
    }

    // Verify if the current user can edit the post
    if (!current_user_can('edit_post', $post_id)) {
        return;
    }

    // Get the plugin options
    $nginx_cache_settings = get_option('nginx_cache_settings');

    // Check if the purge cache on update setting is enabled
    if (isset($nginx_cache_settings['nginx_cache_purge_on_update']) && $nginx_cache_settings['nginx_cache_purge_on_update'] === 'yes') {
        // Get the URL of the post/page from $post_id which should return a well-formed URL
        // no extra sanitization applied such as esc_url_raw here
        // also in nppp_purge_single function we already use FILTER_VALIDATE_URL
        $post_url = get_permalink($post_id);

        // Set default cache path to prevent any errors if the option is not set
        $default_cache_path = '/dev/shm/change-me-now';

        // Get the nginx cache path from the plugin options, or use the default path if not set
        $nginx_cache_path = isset($nginx_cache_settings['nginx_cache_path']) ? $nginx_cache_settings['nginx_cache_path'] : $default_cache_path;

        // Purge the cache for the updated post/page
        // Auto Purge true
        nppp_purge_single($nginx_cache_path, $post_url, true);
    }
}

// Auto Purge (Entire)
// Purge entire cache automatically for plugin or theme updates.
// This function hooks into the 'upgrader_process_complete' action
function nppp_purge_cache_on_theme_plugin_update($upgrader, $hook_extra) {
    // Retrieve plugin settings
    $nginx_cache_settings = get_option('nginx_cache_settings');

    // Check if auto purge on update is enabled
    if (isset($nginx_cache_settings['nginx_cache_purge_on_update']) && $nginx_cache_settings['nginx_cache_purge_on_update'] === 'yes') {
        // Determine the type of update: plugin or theme
        if (isset($hook_extra['type']) && in_array($hook_extra['type'], array('plugin', 'theme'), true)) {
            // Retrieve necessary options for purge actions
            $default_cache_path = '/dev/shm/change-me-now';
            $nginx_cache_path = isset($nginx_cache_settings['nginx_cache_path']) ? $nginx_cache_settings['nginx_cache_path'] : $default_cache_path;
            $this_script_path = dirname(plugin_dir_path(__FILE__));
            $PIDFILE = rtrim($this_script_path, '/') . '/cache_preload.pid';
            $tmp_path = rtrim($nginx_cache_path, '/') . "/tmp";

            // Trigger purge action
            nppp_purge($nginx_cache_path, $PIDFILE, $tmp_path, false, true, true);
        }
    }
}

// Auto Purge (Single)
// Purge cache automatically when a new comment exists (post/page)
// This function hooks into the 'wp_insert_comment' action
function nppp_purge_cache_on_comment($comment_id, $comment) {
    $oldstatus = '';
    $approved  = $comment->comment_approved;

    if ( null === $approved ) {
        $newstatus = false;
    } elseif ( '1' === $approved ) {
        $newstatus = 'approved';
    } elseif ( '0' === $approved ) {
        $newstatus = 'unapproved';
    } elseif ( 'spam' === $approved ) {
        $newstatus = 'spam';
    } elseif ( 'trash' === $approved ) {
        $newstatus = 'trash';
    } else {
        $newstatus = false;
    }

    nppp_purge_cache_on_comment_change($newstatus, $oldstatus, $comment);
}

// Auto Purge (Single)
// Purge cache automatically when a comment status changes (post/page)
// This function hooks into the 'transition_comment_status' action
function nppp_purge_cache_on_comment_change($newstatus, $oldstatus, $comment) {
    // Get the post ID associated with the comment
    $post_id = $comment->comment_post_ID;

    // Verify if the current user can edit the post
    if (!current_user_can('edit_post', $post_id)) {
        return;
    }

    // Get the URL of the post/page from $post_id
    $post_url = get_permalink($post_id);

    // Get the plugin options
    $nginx_cache_settings = get_option('nginx_cache_settings');

    // Set default cache path to prevent any errors if the option is not set
    $default_cache_path = '/dev/shm/change-me-now';

    // Get the nginx cache path from the plugin options, or use the default path if not set
    $nginx_cache_path = isset($nginx_cache_settings['nginx_cache_path']) ? $nginx_cache_settings['nginx_cache_path'] : $default_cache_path;

    switch ( $newstatus ) {
        case 'approved':
            if (isset($nginx_cache_settings['nginx_cache_purge_on_update']) && $nginx_cache_settings['nginx_cache_purge_on_update'] === 'yes') {
                // Purge the cache when comment status change for the post/page
                nppp_purge_single($nginx_cache_path, $post_url, true);
            }
            break;

        case 'spam':
        case 'unapproved':
        case 'trash':
            if ( 'approved' === $oldstatus && isset($nginx_cache_settings['nginx_cache_purge_on_update']) && $nginx_cache_settings['nginx_cache_purge_on_update'] === 'yes') {
                // Purge the cache when comment status change for the post/page
                nppp_purge_single($nginx_cache_path, $post_url, true);
            }
            break;
    }
}

// Purge cache operation
function nppp_purge($nginx_cache_path, $PIDFILE, $tmp_path, $nppp_is_rest_api = false, $nppp_is_admin_bar = false, $nppp_is_auto_purge = false) {
    $wp_filesystem = nppp_initialize_wp_filesystem();

    if ($wp_filesystem === false) {
        nppp_display_admin_notice(
            'error',
            'Failed to initialize the WordPress filesystem. Please file a bug on the plugin support page.'
        );
        return;
    }

    $options = get_option('nginx_cache_settings');
    $auto_preload = isset($options['nginx_cache_auto_preload']) && $options['nginx_cache_auto_preload'] === 'yes';

    // Clear the scheduled preload status event immediately
    wp_clear_scheduled_hook('npp_cache_preload_status_event');

    // Initialize variables for messages
    $message_type = '';
    $message_content = '';

    // Check if the PID file exists
    if ($wp_filesystem->exists($PIDFILE)) {
        $pid = intval(nppp_perform_file_operation($PIDFILE, 'read'));

        // Check if the preload process is alive
        if ($pid > 0 && posix_kill($pid, 0)) {
            // If process is alive, kill it
            posix_kill($pid, SIGTERM);
            usleep(50000);

            // If on-going preload action halted via purge
            // that means user restrictly wants to purge cache
            // If auto preload feature enabled this will cause recursive preload action
            // So if ongoing preload action halted by purge action set auto-reload false
            // to prevent recursive preload loop
            $auto_preload = false;

            // Call purge_helper to delete cache contents and get status
            $status = nppp_purge_helper($nginx_cache_path, $tmp_path);

            // Determine message based on status
            switch ($status) {
                case 0:
                    if ($nppp_is_rest_api) {
                        $message_type = 'success';
                        $message_content = 'SUCCESS REST: Ongoing preloading halted. All cache purged successfully.';
                    } elseif ($nppp_is_admin_bar){
                        $message_type = 'success';
                        $message_content = 'SUCCESS ADMIN: Ongoing preloading halted. All cache purged successfully.';
                    } else {
                        $message_type = 'success';
                        $message_content = 'SUCCESS: Ongoing preloading halted. All cache purged successfully.';
                    }
                    break;
                case 1:
                    $message_type = 'error';
                    $message_content = 'ERROR PERMISSION: Ongoing preloading halted but cache purge failed due to permission issue. Refer to -Help- tab for guidance.';
                    break;
                case 3:
                    $message_type = 'error';
                    $message_content = 'ERROR PATH: Your FastCGI cache PATH (' . $nginx_cache_path . ') is not found. Please check your FastCGI cache path.';
                    break;
                case 4:
                    $message_type = 'error';
                    $message_content = 'ERROR UNKNOWN: An unexpected error occurred while purging the FastCGI cache. Please file a bug on plugin support page.';
                    break;
            }

            // Remove the PID file
            nppp_perform_file_operation($PIDFILE, 'delete');
        } else {
            // Call purge_helper to delete cache contents and get status
            $status = nppp_purge_helper($nginx_cache_path, $tmp_path);

            // Determine message based on status
            switch ($status) {
                case 0:
                    // Check auto preload status and defer message accordingly
                    if (!$auto_preload) {
                        if ($nppp_is_rest_api) {
                            $message_type = 'success';
                            $message_content = 'SUCCESS REST: All cache purged successfully.';
                        } elseif ($nppp_is_admin_bar){
                            $message_type = 'success';
                            $message_content = 'SUCCESS ADMIN: All cache purged successfully.';
                        } else {
                            $message_type = 'success';
                            $message_content = 'SUCCESS: All cache purged successfully.';
                        }
                    }
                    break;
                case 1:
                    $message_type = 'error';
                    $message_content = 'ERROR PERMISSION: Cache purge failed due to permission issue. Refer to -Help- tab for guidance.';
                    break;
                case 2:
                    // Check auto preload status and defer message accordingly
                    if (!$auto_preload) {
                        $message_type = 'info';
                        $message_content = 'INFO: Cache purge attempted, but no cache found.';
                    }
                    break;
                case 3:
                    $message_type = 'error';
                    $message_content = 'ERROR PATH: Your FastCGI cache PATH (' . $nginx_cache_path . ') is not found. Please check your FastCGI cache path.';
                    break;
                case 4:
                    $message_type = 'error';
                    $message_content = 'ERROR UNKNOWN: An unexpected error occurred while purging the FastCGI cache. Please file a bug on plugin support page.';
                    break;
            }

            // Remove the PID file
            nppp_perform_file_operation($PIDFILE, 'delete');
        }
    } else {
        // Call purge_helper to delete cache contents and get status
        $status = nppp_purge_helper($nginx_cache_path, $tmp_path);

        // Determine message based on status
        switch ($status) {
            case 0:
                // Check auto preload status and defer message accordingly
                if (!$auto_preload) {
                    if ($nppp_is_rest_api) {
                        $message_type = 'success';
                        $message_content = 'SUCCESS REST: All cache purged successfully.';
                    } elseif ($nppp_is_admin_bar){
                        $message_type = 'success';
                        $message_content = 'SUCCESS ADMIN: All cache purged successfully.';
                    } else {
                        $message_type = 'success';
                        $message_content = 'SUCCESS: All cache purged successfully.';
                    }
                }
                break;
            case 1:
                $message_type = 'error';
                $message_content = 'ERROR PERMISSION: Cache purge failed due to permission issue. Refer to -Help- tab for guidance.';
                break;
            case 2:
                // Check auto preload status and defer message accordingly
                if (!$auto_preload) {
                    $message_type = 'info';
                    $message_content = 'INFO: Cache purge attempted, but no cache found.';
                }
                break;
            case 3:
                $message_type = 'error';
                $message_content = 'ERROR PATH: Your FastCGI cache PATH (' . $nginx_cache_path . ') is not found. Please check your FastCGI cache path.';
                break;
            case 4:
                $message_type = 'error';
                $message_content = 'ERROR UNKNOWN: An unexpected error occurred while purging the FastCGI cache. Please file a bug on plugin support page.';
                break;
        }
    }

    // Display the admin notice
    if (!empty($message_type) && !empty($message_content)) {
        if ($nppp_is_auto_purge) {
            nppp_display_admin_notice($message_type, $message_content, true, false);
        } else {
            nppp_display_admin_notice($message_type, $message_content);
        }
    }

    // Check if there was an error during the cache purge process
    if ($message_type === 'error') {
        return;
    }

    // If set call preload immediately after purge
    if ($auto_preload) {
        // Get the plugin options
        $nginx_cache_settings = get_option('nginx_cache_settings');

        // Set default options to prevent any error
        $default_cache_path = '/dev/shm/change-me-now';
        $default_limit_rate = 1280;
        $default_cpu_limit = 50;
        $default_reject_regex = nppp_fetch_default_reject_regex();

        // Get the necessary data for preload action from plugin options
        $nginx_cache_path = isset($nginx_cache_settings['nginx_cache_path']) ? $nginx_cache_settings['nginx_cache_path'] : $default_cache_path;
        $nginx_cache_limit_rate = isset($nginx_cache_settings['nginx_cache_limit_rate']) ? $nginx_cache_settings['nginx_cache_limit_rate'] : $default_limit_rate;
        $nginx_cache_cpu_limit = isset($nginx_cache_settings['nginx_cache_cpu_limit']) ? $nginx_cache_settings['nginx_cache_cpu_limit'] : $default_cpu_limit;
        $nginx_cache_reject_regex = isset($nginx_cache_settings['nginx_cache_reject_regex']) ? $nginx_cache_settings['nginx_cache_reject_regex'] : $default_reject_regex;

        // Extra data for preload action
        $fdomain = get_site_url();
        $this_script_path = dirname(plugin_dir_path(__FILE__));
        $PIDFILE = rtrim($this_script_path, '/') . '/cache_preload.pid';
        $tmp_path = rtrim($nginx_cache_path, '/') . "/tmp";

        // Check route of request
        $preload_is_rest_api = $nppp_is_rest_api ? true : false;
        $preload_is_admin_bar = $nppp_is_admin_bar ? true : false;

        // Start the preload action with auto preload on flag
        // This is the only route that auto preload passes "true" to preload action
        nppp_preload($nginx_cache_path, $this_script_path, $tmp_path, $fdomain, $PIDFILE, $nginx_cache_reject_regex, $nginx_cache_limit_rate, $nginx_cache_cpu_limit, true, $preload_is_rest_api, false, $preload_is_admin_bar);
    }
}
