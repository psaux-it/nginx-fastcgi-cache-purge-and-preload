<?php
/**
 * Purge action functions for FastCGI Cache Purge and Preload for Nginx
 * Description: This file contains Purge action functions for FastCGI Cache Purge and Preload for Nginx
 * Version: 2.0.8
 * Author: Hasan CALISIR
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

    // Retrieve and decode user-defined cache key regex from the database, with a hardcoded fallback
    $nginx_cache_settings = get_option('nginx_cache_settings');

    // User defined regex in Cache Key Regex option
    $regex = isset($nginx_cache_settings['nginx_cache_key_custom_regex'])
             ? base64_decode($nginx_cache_settings['nginx_cache_key_custom_regex'])
             : nppp_fetch_default_regex_for_cache_key();

    // Validation regex that user defined regex correctly parses '$host$request_uri' from fastcgi_cache_key
    $second_regex = '#^([a-zA-Z0-9-]+\.)*[a-zA-Z0-9-]+\.(?:[a-zA-Z]{2,}(\.[a-zA-Z]{2,})?)(\/[a-zA-Z0-9\-\/\?&=%\#_]*)?(\?[a-zA-Z0-9=&\-]*)?$#';

    // First, check if any active cache preloading action is in progress.
    // Purging the cache for a single page or post, whether done manually (Fonrtpage) or automatically (Auto Purge) after content updates,
    // can cause issues if there is an active cache preloading process.
    if ($wp_filesystem->exists($PIDFILE)) {
        $pid = intval(nppp_perform_file_operation($PIDFILE, 'read'));

        if ($pid > 0 && nppp_is_process_alive($pid)) {
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
        $regex_tested = false;

        foreach ($cache_iterator as $file) {
            if ($wp_filesystem->is_file($file->getPathname())) {
                // Check read and write permissions for each file
                if (!$wp_filesystem->is_readable($file->getPathname()) || !$wp_filesystem->is_writable($file->getPathname())) {
                    nppp_display_admin_notice('error', "ERROR PERMISSION: Cache purge failed for page $current_page_url due to permission issue. Refer to -Help- tab for guidance.");
                    return;
                }

                // Read file contents
                $content = $wp_filesystem->get_contents($file->getPathname());

                // Exclude URLs with status 301 or 302
                if (strpos($content, 'Status: 301 Moved Permanently') !== false ||
                    strpos($content, 'Status: 302 Found') !== false) {
                    continue;
                }

                // Skip all request methods except GET
                if (!preg_match('/KEY:\s.*GET/', $content)) {
                    continue;
                }

                // Test regex only once
                // Regex operations can be computationally expensive,
                // especially when iterating over multiple files.
                // So here we test regex only once
                if (!$regex_tested) {
                    if (preg_match($regex, $content, $matches) && isset($matches[1], $matches[2])) {
                        // Build the URL
                        $host = trim($matches[1]);
                        $request_uri = trim($matches[2]);
                        $constructed_url = $host . $request_uri;

                        // Test if the URL is in the expected format
                        if ($constructed_url !== '' && preg_match($second_regex, $constructed_url, $second_matches)) {
                            $regex_tested = true;
                        } else {
                            nppp_display_admin_notice('error', "ERROR REGEX: Cache purge failed for page $current_page_url, please check the <strong>Cache Key Regex</strong> option in the plugin <strong>Advanced options</strong> section and ensure the <strong>regex</strong> is parsing <strong>\$host\$request_uri</strong> portion correctly.", true, false);
                            return;
                        }
                    } else {
                        nppp_display_admin_notice('error', "ERROR REGEX: Cache purge failed for page $current_page_url, please check the <strong>Cache Key Regex</strong> option in the plugin <strong>Advanced options</strong> section and ensure the <strong>regex</strong> is configured correctly.", true, false);
                        return;
                    }
                }

                // Extract the in cache URL from fastcgi_cache_key
                preg_match($regex, $content, $matches);

                // Build the URL
                $host = trim($matches[1]);
                $request_uri = trim($matches[2]);
                $constructed_url = $host . $request_uri;

                // Check extracted URL from fastcgi_cache_key and the URL attempted to purge is equal
                if ($constructed_url === $url_to_search_exact) {
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
// Purge cache automatically for updated (post/page)
// This function hooks into the 'save_post' action
function nppp_purge_cache_on_update($post_id, $post, $update) {
    // Get the plugin options
    $nginx_cache_settings = get_option('nginx_cache_settings');

    // Check if auto-purge is enabled
    if (isset($nginx_cache_settings['nginx_cache_purge_on_update']) && $nginx_cache_settings['nginx_cache_purge_on_update'] === 'yes') {
        // Ignore auto-saves, post revisions, and newly created posts (auto-draft or not published)
        if (wp_is_post_autosave($post_id) || wp_is_post_revision($post_id) || get_post_status($post_id) !== 'publish') {
            return;
        }

        // Verify if the current user can edit the post
        if (!current_user_can('edit_post', $post_id)) {
            return;
        }

        // Get the URL of the post/page
        $post_url = get_permalink($post_id);

        // Set default cache path to prevent any errors if the option is not set
        $default_cache_path = '/dev/shm/change-me-now';

        // Get the nginx cache path from the plugin options, or use the default path if not set
        $nginx_cache_path = isset($nginx_cache_settings['nginx_cache_path']) ? $nginx_cache_settings['nginx_cache_path'] : $default_cache_path;

        // Check if this is an update (not a new post)
        if ($update) {
            // Unhook the function to avoid infinite loop
            remove_action('save_post', 'nppp_purge_cache_on_update');

            // Purge the cache for the updated post/page
            nppp_purge_single($nginx_cache_path, $post_url, true);

            // Re-hook the function
            add_action('save_post', 'nppp_purge_cache_on_update', 10, 3);
        }
    }
}

// Auto Purge (Entire)
// Purge entire cache automatically for plugin or theme (active) updates.
// This function hooks into the 'upgrader_process_complete' action
function nppp_purge_cache_on_theme_plugin_update($upgrader, $hook_extra) {
    // Retrieve plugin settings
    $nginx_cache_settings = get_option('nginx_cache_settings');

    // Check if auto-purge is enabled
    if (isset($nginx_cache_settings['nginx_cache_purge_on_update']) && $nginx_cache_settings['nginx_cache_purge_on_update'] === 'yes') {
        // Retrieve necessary options for purge actions
        $default_cache_path = '/dev/shm/change-me-now';
        $nginx_cache_path = isset($nginx_cache_settings['nginx_cache_path']) ? $nginx_cache_settings['nginx_cache_path'] : $default_cache_path;
        $this_script_path = dirname(plugin_dir_path(__FILE__));
        $PIDFILE = rtrim($this_script_path, '/') . '/cache_preload.pid';
        $tmp_path = rtrim($nginx_cache_path, '/') . "/tmp";

        // Check for the theme update
        if (isset($hook_extra['type']) && $hook_extra['type'] === 'theme' &&
            isset($hook_extra['themes']) && is_array($hook_extra['themes']) && !empty($hook_extra['themes'])) {
            // Get the active theme
            $active_theme = wp_get_theme()->get_stylesheet();

            // Check if the active theme is being updated
            if (in_array($active_theme, $hook_extra['themes'], true)) {
                // Purge cache for only active theme update
                nppp_purge($nginx_cache_path, $PIDFILE, $tmp_path, false, true, true);
            }
        }

        // Check for the plugin update
        if (isset($hook_extra['type']) && $hook_extra['type'] === 'plugin' &&
            isset($hook_extra['plugins']) && is_array($hook_extra['plugins']) && !empty($hook_extra['plugins'])) {

            // Purge cache for plugin updates
            nppp_purge($nginx_cache_path, $PIDFILE, $tmp_path, false, true, true);
        }
    }
}

// Auto Purge (Entire)
// Purge entire cache automatically for plugin activation & deactivation.
// This function hooks into the 'activated_plugin-deactivated_plugin' action
function nppp_purge_cache_plugin_activation_deactivation() {
    // Retrieve plugin settings
    $nginx_cache_settings = get_option('nginx_cache_settings');

    // Check if auto-purge is enabled
    if (isset($nginx_cache_settings['nginx_cache_purge_on_update']) && $nginx_cache_settings['nginx_cache_purge_on_update'] === 'yes') {
        $default_cache_path = '/dev/shm/change-me-now';
        $nginx_cache_path = isset($nginx_cache_settings['nginx_cache_path']) ? $nginx_cache_settings['nginx_cache_path'] : $default_cache_path;
        $this_script_path = dirname(plugin_dir_path(__FILE__));
        $PIDFILE = rtrim($this_script_path, '/') . '/cache_preload.pid';
        $tmp_path = rtrim($nginx_cache_path, '/') . "/tmp";

        // Purge cache for plugin activation - deactivation
        nppp_purge($nginx_cache_path, $PIDFILE, $tmp_path, false, true, true);
    }
}

// Auto Purge (Entire)
// Purge entire cache automatically for THEME switchs.
// This function hooks into the 'switch_theme' action
function nppp_purge_cache_on_theme_switch($old_name, $old_theme) {
    // Retrieve plugin settings
    $nginx_cache_settings = get_option('nginx_cache_settings');

    // Check if auto-purge is enabled
    if (isset($nginx_cache_settings['nginx_cache_purge_on_update']) && $nginx_cache_settings['nginx_cache_purge_on_update'] === 'yes') {
        $default_cache_path = '/dev/shm/change-me-now';
        $nginx_cache_path = isset($nginx_cache_settings['nginx_cache_path']) ? $nginx_cache_settings['nginx_cache_path'] : $default_cache_path;
        $this_script_path = dirname(plugin_dir_path(__FILE__));
        $PIDFILE = rtrim($this_script_path, '/') . '/cache_preload.pid';
        $tmp_path = rtrim($nginx_cache_path, '/') . "/tmp";

        // Trigger the purge action
        nppp_purge($nginx_cache_path, $PIDFILE, $tmp_path, false, true, true);
    }
}

// Auto Purge (Single)
// Purge cache automatically when a new comment exists (post/page)
// This function hooks into the 'wp_insert_comment' action
function nppp_purge_cache_on_comment($comment_id, $comment) {
    // Get the plugin options
    $nginx_cache_settings = get_option('nginx_cache_settings');

    // Check if auto-purge is enabled
    if (isset($nginx_cache_settings['nginx_cache_purge_on_update']) && $nginx_cache_settings['nginx_cache_purge_on_update'] === 'yes') {
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
}

// Auto Purge (Single)
// Purge cache automatically when a comment status changes (post/page)
// This function hooks into the 'transition_comment_status' action
function nppp_purge_cache_on_comment_change($newstatus, $oldstatus, $comment) {
    // Get the plugin options
    $nginx_cache_settings = get_option('nginx_cache_settings');

    // Check if auto-purge is enabled
    if (isset($nginx_cache_settings['nginx_cache_purge_on_update']) && $nginx_cache_settings['nginx_cache_purge_on_update'] === 'yes') {
        // Get the post ID associated with the comment
        $post_id = $comment->comment_post_ID;

        // Verify if the current user can edit the post
        if (!current_user_can('edit_post', $post_id)) {
            return;
        }

        // Get the URL of the post/page from $post_id
        $post_url = get_permalink($post_id);

        // Set default cache path to prevent any errors if the option is not set
        $default_cache_path = '/dev/shm/change-me-now';

        // Get the nginx cache path from the plugin options, or use the default path if not set
        $nginx_cache_path = isset($nginx_cache_settings['nginx_cache_path']) ? $nginx_cache_settings['nginx_cache_path'] : $default_cache_path;

        switch ( $newstatus ) {
            case 'approved':
                // Purge the cache when comment status change for the post/page
                nppp_purge_single($nginx_cache_path, $post_url, true);
                break;

            case 'spam':
            case 'unapproved':
            case 'trash':
                // Purge the cache when comment status change for the post/page
                nppp_purge_single($nginx_cache_path, $post_url, true);
                break;
        }
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
        if ($pid > 0 && nppp_is_process_alive($pid)) {
            // Try to kill the process with SIGTERM
            if (defined('SIGTERM') && @posix_kill($pid, SIGTERM) === false) {
                // Log if SIGTERM is failed
                nppp_display_admin_notice('info', "INFO PROCESS: Failed to send SIGTERM to Preload process PID: $pid", true, false);
                sleep(1);

                // Check again if the process is still alive after SIGTERM
                if (nppp_is_process_alive($pid)) {
                    // Fallback: Use shell_exec to send SIGKILL
                    $kill_path = trim(shell_exec('command -v kill'));
                    if (!empty($kill_path)) {
                        shell_exec(escapeshellcmd("$kill_path -9 $pid"));
                        usleep(400000);

                        // Check again if the process is still alive after SIGKILL
                        if (!nppp_is_process_alive($pid)) {
                            // Log success after SIGKILL
                            nppp_display_admin_notice('success', "SUCCESS PROCESS: Fallback - SIGKILL sent to Preload process PID: $pid", true, false);
                        } else {
                            // Log failure if fallback didn't work
                            nppp_display_admin_notice('error', "ERROR PROCESS: Unable to stop the ongoing Preload process. Please wait for the Preload process to complete and try Purge All again.", true, false);
                            return;
                        }
                    } else {
                        // Log failure if the kill command is not found
                        nppp_display_admin_notice('error', "ERROR PROCESS: Unable to stop the ongoing Preload process. Please wait for the Preload process to complete and try Purge All again.", true, false);
                        return;
                    }
                }
            } else {
                // Log if SIGTERM is successfully sent
                nppp_display_admin_notice('success', "SUCCESS PROCESS: Successfully sent SIGTERM to Preload process with PID: $pid", true, false);
            }

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

    // Fire the 'nppp_purged' action, triggering any other plugin actions that are hooked into this event
    // If auto preload is enabled this hook will create both NPP cache and compatible plugin cache at the same time
    do_action('nppp_purged_all');

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

// Callback function to trigger Purge All
function nppp_purge_callback() {
    // Get the plugin options
    $nginx_cache_settings = get_option('nginx_cache_settings');

    // Get the necessary data for purge action from plugin options
    $default_cache_path = '/dev/shm/change-me-now';
    $nginx_cache_path = isset($nginx_cache_settings['nginx_cache_path']) ? $nginx_cache_settings['nginx_cache_path'] : $default_cache_path;
    $this_script_path = dirname(plugin_dir_path(__FILE__));
    $PIDFILE = rtrim($this_script_path, '/') . '/cache_preload.pid';
    $tmp_path = rtrim($nginx_cache_path, '/') . "/tmp";

    // Call the main purge function
    nppp_purge($nginx_cache_path, $PIDFILE, $tmp_path, false, false, true);
}
