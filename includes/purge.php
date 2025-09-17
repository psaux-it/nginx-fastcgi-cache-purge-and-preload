<?php
/**
 * Purge action functions for FastCGI Cache Purge and Preload for Nginx
 * Description: This file contains Purge action functions for FastCGI Cache Purge and Preload for Nginx
 * Version: 2.1.3
 * Author: Hasan CALISIR
 * Author Email: hasan.calisir@psauxit.com
 * Author URI: https://www.psauxit.com
 * License: GPL-2.0+
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Fast head readers (minimal I/O)
if (! function_exists('nppp_head_fast')) {
    // Uses file_get_contents() length arg (C-level).
    function nppp_head_fast($path, $max = 16384) {
        $data = @file_get_contents($path, false, null, 0, $max);
        return ($data === false) ? '' : $data;
    }
}

if (! function_exists('nppp_read_head')) {
    // Partial read with WP_Filesystem fallback.
    function nppp_read_head($wp_filesystem, $path, $max = 16384) {
        $buf = nppp_head_fast($path, $max);
        if ($buf !== '') return $buf;

        // Fallback: WP_Filesystem may read via FTP/SSH; trim to $max
        $all = $wp_filesystem->get_contents($path);
        return ($all === false || $all === '') ? '' : substr($all, 0, $max);
    }
}

// Purge cache operation helper
function nppp_purge_helper($nginx_cache_path, $tmp_path) {
    $wp_filesystem = nppp_initialize_wp_filesystem();

    if ($wp_filesystem === false) {
        nppp_display_admin_notice(
            'error',
            __( 'Failed to initialize the WordPress filesystem. Please file a bug on the plugin support page.', 'fastcgi-cache-purge-and-preload-nginx' )
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
            } elseif ($error_code === 'directory_traversal') {
                return 5;
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
            __( 'Failed to initialize the WordPress filesystem. Please file a bug on the plugin support page.', 'fastcgi-cache-purge-and-preload-nginx' )
        );
        return;
    }

    // Display decoded URL to user
    $current_page_url_decoded = rawurldecode($current_page_url);

    // Get the PIDFILE location
    $this_script_path = dirname(plugin_dir_path(__FILE__));
    $PIDFILE = rtrim($this_script_path, '/') . '/cache_preload.pid';

    // Get the status of Auto Preload option
    $options = get_option('nginx_cache_settings');
    $nppp_auto_preload = isset($options['nginx_cache_auto_preload']) && $options['nginx_cache_auto_preload'] === 'yes';
    $chain_autopreload = ($nppp_auto_purge && $nppp_auto_preload);

    // Retrieve and decode user-defined cache key regex from the database, with a hardcoded fallback
    $nginx_cache_settings = get_option('nginx_cache_settings');

    // User defined regex in Cache Key Regex option
    $regex = isset($nginx_cache_settings['nginx_cache_key_custom_regex'])
             ? base64_decode($nginx_cache_settings['nginx_cache_key_custom_regex'])
             : nppp_fetch_default_regex_for_cache_key();

    // First, check if any active cache preloading action is in progress.
    // Purging the cache for a single page or post, whether done manually (Fonrtpage) or automatically (Auto Purge) after content updates,
    // can cause issues if there is an active cache preloading process.
    if ($wp_filesystem->exists($PIDFILE)) {
        $pid = intval(nppp_perform_file_operation($PIDFILE, 'read'));

        if ($pid > 0 && nppp_is_process_alive($pid)) {
            // Translators: %s is the page URL
            nppp_display_admin_notice('info', sprintf( __( 'INFO: Single-page purge for %s skipped — Nginx cache preloading is in progress. Check the Status tab to monitor; wait for completion or use "Purge All" to cancel.', 'fastcgi-cache-purge-and-preload-nginx' ), $current_page_url_decoded ));
            return;
        }
    }

    // Valitade the sanitized url before process
    if (filter_var($current_page_url, FILTER_VALIDATE_URL) !== false) {
        // Remove http:// or https:// from the URL
        $url_to_search_exact = preg_replace('#^https?://#', '', $current_page_url);
    } else {
        nppp_display_admin_notice('error', __( 'ERROR URL: HTTP_REFERRER URL can not validated.', 'fastcgi-cache-purge-and-preload-nginx' ));
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
                    // Translators: %s is the page URL
                    nppp_display_admin_notice('error', sprintf( __( 'ERROR PERMISSION: Nginx cache purge failed for page %s due to permission issue. Refer to the "Help" tab for guidance.', 'fastcgi-cache-purge-and-preload-nginx' ), $current_page_url_decoded ));
                    return;
                }

                // Read only the head (binary-safe)
                $head_bytes_primary  = (int) apply_filters('nppp_locate_head_bytes', 4096);
                $head_bytes_fallback = (int) apply_filters('nppp_locate_head_bytes_fallback', 32768);

                $pathname = $file->getPathname();
                $content  = nppp_read_head($wp_filesystem, $pathname, $head_bytes_primary);
                if ($content === '') { continue; }

                $match = [];
                if (!preg_match('/^KEY:\s([^\r\n]*)/m', $content, $match)) {
                    // Try fallback
                    if (strlen($content) >= $head_bytes_primary) {
                        $content = nppp_read_head($wp_filesystem, $pathname, $head_bytes_fallback);
                        if ($content === '' || !preg_match('/^KEY:\s([^\r\n]*)/m', $content, $match)) {
                            continue;
                        }
                    } else {
                        continue;
                    }
                }

                // Ignore redirects
                if (strpos($content, 'Status: 301 Moved Permanently') !== false ||
                    strpos($content, 'Status: 302 Found') !== false) {
                    continue;
                }

                // Accept only GET entries (HEAD/POST/etc. are not cache targets here)
                $key_line = $match[1];
                if (strpos($key_line, 'GET') === false) {
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

                        // Test parsed URL via regex with FILTER_VALIDATE_URL
                        // We need to add prefix here
                        $constructed_url_test = 'https://' . $constructed_url;

                        // Test if the URL is in the expected format
                        if ($constructed_url !== '' && filter_var($constructed_url_test, FILTER_VALIDATE_URL)) {
                            $regex_tested = true;
                        } else {
                            // Translators: %s is the page URL, $host$request_uri is just string the part of the cache key
                            nppp_display_admin_notice('error', sprintf( __( 'ERROR REGEX: Nginx cache purge failed for page %s, please check the <strong>Cache Key Regex</strong> option in the plugin <strong>Advanced options</strong> section and ensure the <strong>regex</strong> is parsing <strong>\$host\$request_uri</strong> portion correctly.', 'fastcgi-cache-purge-and-preload-nginx' ), $current_page_url_decoded ), true, false);
                            return;
                        }
                    } else {
                        // Translators: %s is the page URL
                        nppp_display_admin_notice('error', sprintf( __( 'ERROR REGEX: Nginx cache purge failed for page %s, please check the <strong>Cache Key Regex</strong> option in the plugin <strong>Advanced options</strong> section and ensure the <strong>regex</strong> is configured correctly.', 'fastcgi-cache-purge-and-preload-nginx' ), $current_page_url_decoded ), true, false);
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
                        switch ($validation_result) {
                            case 'critical_path':
                                $error_message = __( 'ERROR PATH: The Nginx cache path appears to be a critical system directory or a first-level directory. Failed to purge Nginx cache!', 'fastcgi-cache-purge-and-preload-nginx' );
                                break;
                            case 'file_not_found_or_not_readable':
                                $error_message = __( 'ERROR PATH: The specified Nginx cache path does not exist. Failed to purge Nginx cache!', 'fastcgi-cache-purge-and-preload-nginx' );
                                break;
                            default:
                                $error_message = __( 'ERROR PATH: An invalid Nginx cache path was provided. Failed to purge Nginx cache!', 'fastcgi-cache-purge-and-preload-nginx' );
                        }
                        nppp_display_admin_notice('error', $error_message);
                        return;
                    }

                    // Perform the purge action
                    $deleted = $wp_filesystem->delete($cache_path);
                    if ($deleted) {
                        if ($chain_autopreload) {
                            nppp_preload_cache_on_update($current_page_url, true);
                        } else {
                            nppp_display_admin_notice('success', sprintf(__( 'SUCCESS ADMIN: Nginx cache purged for page %s', 'fastcgi-cache-purge-and-preload-nginx' ), $current_page_url_decoded));
                        }
                    } else {
                        nppp_display_admin_notice('error', sprintf(__( "ERROR UNKNOWN: An unexpected error occurred while purging Nginx cache for page %s. Please report this issue on the plugin's support page.", 'fastcgi-cache-purge-and-preload-nginx' ), $current_page_url_decoded));
                    }

                    // Handle related homepage/category purge + optional preload
                    $is_manual = !$nppp_auto_purge;
                    $related_urls = nppp_get_related_urls_for_single($current_page_url);

                    // Purge related silently
                    foreach ($related_urls as $rel) {
                        nppp_purge_url_silent($nginx_cache_path, $rel);
                    }

                    // Decide preload policy
                    $settings = get_option('nginx_cache_settings');
                    $should_preload_related =
                        ($is_manual && !empty($settings['nppp_related_preload_after_manual']) && $settings['nppp_related_preload_after_manual'] === 'yes')
                        || (!$is_manual && !empty($settings['nginx_cache_auto_preload']) && $settings['nginx_cache_auto_preload'] === 'yes');

                    if ($should_preload_related) {
                        nppp_preload_urls_fire_and_forget($related_urls);
                    }
                    return;
                }
            }
        }
    } catch (Exception $e) {
        // Translators: %s is the page URL
        nppp_display_admin_notice('error', sprintf( __( 'ERROR PERMISSION: Nginx cache purge failed for page %s due to permission issue. Refer to the "Help" tab for guidance.', 'fastcgi-cache-purge-and-preload-nginx' ), $current_page_url_decoded ));
        return;
    }

    // If not found in the cache
    if (!$found) {
        // Check preload chain
        if ($chain_autopreload) {
            // Trigger the preload
            nppp_preload_cache_on_update($current_page_url, false);
        } else {
            // Translators: %s is the page URL
            nppp_display_admin_notice('info', sprintf( __( 'INFO ADMIN: Nginx cache purge attempted, but the page %s is not currently found in the cache.', 'fastcgi-cache-purge-and-preload-nginx' ), $current_page_url_decoded ));
        }

        // Even if the single wasn’t found in cache, keep related in sync
        $is_manual = !$nppp_auto_purge;
        $related_urls = nppp_get_related_urls_for_single($current_page_url);

        // Purge related silently
        foreach ($related_urls as $rel) {
            nppp_purge_url_silent($nginx_cache_path, $rel);
        }

        // Decide preload policy
        $settings = get_option('nginx_cache_settings');
        $should_preload_related =
            ($is_manual && !empty($settings['nppp_related_preload_after_manual']) && $settings['nppp_related_preload_after_manual'] === 'yes')
            || (!$is_manual && !empty($settings['nginx_cache_auto_preload']) && $settings['nginx_cache_auto_preload'] === 'yes');

        if ($should_preload_related) {
            nppp_preload_urls_fire_and_forget($related_urls);
        }
    }
}

// Auto Purge (Single)
// Purge cache automatically for update (post/page)
// Purge cache automatically for status changes (post/page)
// This function hooks into the 'transition_post_status' action
// Also check compat-{elementor,gutenberg}.php
function nppp_purge_cache_on_update($new_status, $old_status, $post) {
    static $did_purge = [];

    // -1) Bail on invalid post objects.
    if (! ($post instanceof WP_Post)) {
        return;
    }

    // -0.1) Early quit if auto purge disabled
    $nginx_cache_settings = get_option('nginx_cache_settings');
    if (isset($nginx_cache_settings['nginx_cache_purge_on_update']) && $nginx_cache_settings['nginx_cache_purge_on_update'] !== 'yes') {
        return;
    }

    // 0) Avoid duplicate purge with Gutenberg + metabox second request.
    //    When block editor saves legacy metaboxes it posts to post.php with this flag.
    // phpcs:ignore WordPress.Security.NonceVerification.Recommended
    if (isset($_REQUEST['meta-box-loader'])) {
        return;
    }

    // 0.1) Skip REST entirely — compat-gutenberg (rest_after_insert_*) already purges.
    if (
        ( function_exists( 'wp_is_serving_rest_request' ) && wp_is_serving_rest_request() ) ||
        ( function_exists( 'wp_is_rest_request' ) && wp_is_rest_request() ) ||
        ( defined( 'REST_REQUEST' ) && REST_REQUEST )
    ) {
        return;
    }

    // 0.2) Allow only specific AJAX save routes (Quick/Bulk Edit, WPBakery).
    $allowed_ajax_actions = [
        'inline-save', // Quick Edit
        'vc_save',     // WPBakery
        'bulk_edit',   // Bulk Edit
    ];

    // phpcs:ignore WordPress.Security.NonceVerification.Missing
    $current_ajax_action = isset( $_POST['action'] ) ? sanitize_key( wp_unslash( $_POST['action'] ) ) : '';

    if (
        ( ( function_exists( 'wp_doing_ajax' ) && wp_doing_ajax() ) || ( defined( 'DOING_AJAX' ) && DOING_AJAX ) ) &&
        ! in_array( $current_ajax_action, $allowed_ajax_actions, true )
    ) {
        return;
    }

    // 0.3) Skip WP-Cron runs.
    if ( ( function_exists( 'wp_doing_cron' ) && wp_doing_cron() ) || ( defined( 'DOING_CRON' ) && DOING_CRON ) ) {
        return;
    }

    // 1) Per-request guard
    if (isset($did_purge[$post->ID])) {
        return;
    }

    // 2) Sanity checks: no revisions, autosaves, or auto-drafts
    if (wp_is_post_revision($post)
      || wp_is_post_autosave($post)
      || $new_status === 'auto-draft'
      || (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE)
    ) {
        return;
    }

    // 3) Drop some reported CPTs
    $post_url = get_permalink($post->ID);
    $excluded_patterns = [
        'wp-global-styles-',
        'post_type=edd_license_log',
    ];

    foreach ($excluded_patterns as $pattern) {
        if (strpos($post_url, $pattern) !== false) {
            return;
        }
    }

    // 4) Prep cache path
    $nginx_cache_path = isset($nginx_cache_settings['nginx_cache_path']) ? $nginx_cache_settings['nginx_cache_path'] : '/dev/shm/change-me-now';

    // 5) Purge logic
    // Priority A: Handle Status Changes (publish from trash, draft, or pending)
    if ('publish' === $new_status) {
        // If the post was moved from trash to publish, purge the cache
        if ('trash' === $old_status) {
            nppp_purge_single($nginx_cache_path, $post_url, true);
            $did_purge[ $post->ID ] = true;
            return;
        }

        // If the post is published from draft, pending, or any other state, purge the cache
        if ('publish' !== $old_status) {
            nppp_purge_single($nginx_cache_path, $post_url, true);
            $did_purge[ $post->ID ] = true;
            return;
        }
    }

    // Priority B: Handle Content Updates (publish to publish with content change)
    if ('publish' === $new_status && 'publish' === $old_status) {
        $is_quick_edit = ( $current_ajax_action === 'inline-save' );
        $is_bulk_edit  = ( $current_ajax_action === 'bulk_edit' );

        // Quick/Bulk Edit often adjust slug/taxonomies only; always purge.
        if ($is_quick_edit || $is_bulk_edit) {
            nppp_purge_single( $nginx_cache_path, $post_url, true );
            $did_purge[ $post->ID ] = true;
            return;
        }

        // Check if the content was updated (modified time differs from the original post time)
        if (get_post_modified_time('U', true, $post) > get_post_time('U', true, $post)) {
            nppp_purge_single($nginx_cache_path, $post_url, true);
            $did_purge[ $post->ID ] = true;
            return;
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
function nppp_purge_cache_on_theme_switch($new_name, $new_theme, $old_theme) {
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
            __( 'Failed to initialize the WordPress filesystem. Please file a bug on the plugin support page.', 'fastcgi-cache-purge-and-preload-nginx' )
        );
        return;
    }

    $options = get_option('nginx_cache_settings');

    // Set env
    nppp_prepare_request_env(true);

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
            $process_user = trim(shell_exec("ps -o user= -p " . escapeshellarg($pid)));
            $killed_by_safexec = false;

            if ($process_user === 'nobody') {
                $safexec_path = '/usr/bin/safexec';

                // If not present at default location, try to discover via system path
                if (!$wp_filesystem->exists($safexec_path)) {
                    $detected = trim(shell_exec('command -v safexec 2>/dev/null'));
                    if (!empty($detected)) {
                        $safexec_path = $detected;
                    } else {
                        $safexec_path = false;
                    }
                }

                // Check for safexec binary and SUID
                if ($safexec_path && function_exists('stat')) {
                    $is_root_owner = false;
                    $has_suid      = false;

                    $info = @stat($safexec_path);
                    if ($info && isset($info['uid'], $info['mode'])) {
                        $is_root_owner = ($info['uid'] === 0);
                        $has_suid      = ($info['mode'] & 04000) === 04000;
                    }

                    if ($is_root_owner && $has_suid) {
                        $output = shell_exec(escapeshellcmd($safexec_path) . " --kill=" . escapeshellarg($pid) . " 2>&1");

                        if (strpos($output, 'Killed PID') !== false) {
                            usleep(250000);

                            if (!nppp_is_process_alive($pid)) {
                                // Translators: %s is the process PID
                                nppp_display_admin_notice('success', sprintf( __( 'SUCCESS PROCESS: The ongoing Nginx cache Preload process (PID: %s) terminated using safexec', 'fastcgi-cache-purge-and-preload-nginx' ), $pid ), true, false);
                                $killed_by_safexec = true;
                            } else {
                                // Translators: %s is the process PID
                                nppp_display_admin_notice('info', sprintf(__('INFO PROCESS: Failed to terminate using safexec, falling back to posix_kill for PID %s', 'fastcgi-cache-purge-and-preload-nginx'), $pid), true, false);
                            }
                        }
                    } else {
                        // Translators: %s is the process PID
                        nppp_display_admin_notice('info', sprintf(__('INFO PROCESS: safexec not privileged, falling back to posix_kill for PID %s', 'fastcgi-cache-purge-and-preload-nginx'), $pid), true, false);
                    }
                } else {
                    // Translators: %s is the process PID
                    nppp_display_admin_notice('info', sprintf(__('INFO PROCESS: safexec not found, falling back to posix_kill for PID %s', 'fastcgi-cache-purge-and-preload-nginx'), $pid), true, false);
                }
            }

            if (!$killed_by_safexec) {
                $signal_sent = false;

                if (defined('SIGTERM')) {
                    @posix_kill($pid, SIGTERM);
                    $signal_sent = true;
                    usleep(300000);
                }

                if (nppp_is_process_alive($pid)) {
                    // Process still alive, try kill -9
                    $kill_path = trim(shell_exec('command -v kill'));
                    if (!empty($kill_path)) {
                        shell_exec(escapeshellcmd("$kill_path -9 $pid"));
                        usleep(300000);

                        if (!nppp_is_process_alive($pid)) {
                            // Translators: %s is the process PID
                            nppp_display_admin_notice('success', sprintf(__('SUCCESS PROCESS: The ongoing Nginx cache Preload process (PID: %s) forcefully terminated (SIGKILL)', 'fastcgi-cache-purge-and-preload-nginx'), $pid), true, false);
                        } else {
                            nppp_display_admin_notice('error', __('ERROR PROCESS: Failed to stop the ongoing Nginx cache Preload process. Please wait for the Preload process to finish and try Purge All again.', 'fastcgi-cache-purge-and-preload-nginx'));
                            return;
                        }
                    } else {
                        nppp_display_admin_notice('error', __('ERROR PROCESS: "kill" command not available. Failed to stop the ongoing Nginx cache Preload process. Please wait for the Preload process to finish and try Purge All again.', 'fastcgi-cache-purge-and-preload-nginx'));
                        return;
                    }
                } else {
                    $method = $signal_sent ? 'SIGTERM' : 'check';
                    // Translators: %1$s is the PID, %2$s is the termination method (e.g., posix_kill, kill -9).
                    nppp_display_admin_notice('success', sprintf(__('SUCCESS PROCESS: Preload process (PID: %1$s) terminated using %2$s.', 'fastcgi-cache-purge-and-preload-nginx'), $pid, $method), true, false);
                }
            }

            // If on-going preload action halted via purge
            // that means user restrictly wants to purge cache
            // If auto preload feature enabled this will cause recursive preload action
            // So if ongoing preload action halted by purge action set auto-reload false
            // to prevent recursive preload loop
            // v2.0.9: CAUTION
            // If triggered by auto-purge,
            // always rely on the actual status of the option to prevent
            // stopping auto-preloading actions during concurrent auto-purge actions.
            if (!$nppp_is_auto_purge) {
                $auto_preload = false;
            }

            // Call purge_helper to delete cache contents and get status
            $status = nppp_purge_helper($nginx_cache_path, $tmp_path);

            // Determine message based on status
            switch ($status) {
                case 0:
                    if ($nppp_is_rest_api) {
                        $message_type = 'success';
                        $message_content = __( 'SUCCESS REST: The ongoing Nginx cache preloading process has been halted. All Nginx cache has been purged successfully.', 'fastcgi-cache-purge-and-preload-nginx' );
                    } elseif ($nppp_is_admin_bar){
                        $message_type = 'success';
                        $message_content = __( 'SUCCESS ADMIN: The ongoing Nginx cache preloading process has been halted. All Nginx cache has been purged successfully.', 'fastcgi-cache-purge-and-preload-nginx' );
                    } else {
                        $message_type = 'success';
                        $message_content = __( 'SUCCESS: The ongoing Nginx cache preloading process has been halted. All Nginx cache has been purged successfully.', 'fastcgi-cache-purge-and-preload-nginx' );
                    }
                    break;
                case 1:
                    $message_type = 'error';
                    $message_content = __( 'ERROR PERMISSION: The ongoing Nginx cache preloading process was halted, but Nginx cache purge failed due to permission issue. Refer to the "Help" tab for guidance.', 'fastcgi-cache-purge-and-preload-nginx' );
                    break;
                case 3:
                    $message_type = 'error';
                    // Translators: %s is the Nginx cache path
                    $message_content = sprintf( __( 'ERROR PATH: The specified Nginx cache path (%s) was not found. Please verify your Nginx cache path.', 'fastcgi-cache-purge-and-preload-nginx' ), $nginx_cache_path );
                    break;
                case 4:
                    $message_type = 'error';
                    $message_content = __( 'ERROR UNKNOWN: An unexpected error occurred while attempting to purge the Nginx cache. Please report this issue on the plugin\'s support page.', 'fastcgi-cache-purge-and-preload-nginx' );
                    break;
                case 5:
                    $message_type = 'error';
                    // Translators: %s is the Nginx cache path
                    $message_content = sprintf( __( 'ERROR SECURITY: A directory traversal issue was detected with the provided path (%s). Cache purge aborted for security reasons. Please verify your Nginx cache path.', 'fastcgi-cache-purge-and-preload-nginx' ), $nginx_cache_path );
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
                            $message_content = __( 'SUCCESS REST: All Nginx cache purged successfully.', 'fastcgi-cache-purge-and-preload-nginx' );
                        } elseif ($nppp_is_admin_bar){
                            $message_type = 'success';
                            $message_content = __( 'SUCCESS ADMIN: All Nginx cache purged successfully.', 'fastcgi-cache-purge-and-preload-nginx' );
                        } else {
                            $message_type = 'success';
                            $message_content = __( 'SUCCESS: All Nginx cache purged successfully.', 'fastcgi-cache-purge-and-preload-nginx' );
                        }
                    }
                    break;
                case 1:
                    $message_type = 'error';
                    $message_content = __( 'ERROR PERMISSION: The Nginx cache purge failed due to permission issue. Refer to the "Help" tab for guidance.', 'fastcgi-cache-purge-and-preload-nginx' );
                    break;
                case 2:
                    // Check auto preload status and defer message accordingly
                    if (!$auto_preload) {
                        $message_type = 'info';
                        $message_content = __( 'INFO: Nginx cache purge attempted, but no cache found.', 'fastcgi-cache-purge-and-preload-nginx' );
                    }
                    break;
                case 3:
                    $message_type = 'error';
                    // Translators: %s is the Nginx cache path
                    $message_content = sprintf( __( 'ERROR PATH: The specified Nginx cache path (%s) was not found. Please verify your Nginx cache path.', 'fastcgi-cache-purge-and-preload-nginx' ), $nginx_cache_path );
                    break;
                case 4:
                    $message_type = 'error';
                    $message_content = __( 'ERROR UNKNOWN: An unexpected error occurred while attempting to purge the Nginx cache. Please report this issue on the plugin\'s support page.', 'fastcgi-cache-purge-and-preload-nginx' );
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
                        $message_content = __( 'SUCCESS REST: All Nginx cache purged successfully.', 'fastcgi-cache-purge-and-preload-nginx' );
                    } elseif ($nppp_is_admin_bar){
                        $message_type = 'success';
                        $message_content = __( 'SUCCESS ADMIN: All Nginx cache purged successfully.', 'fastcgi-cache-purge-and-preload-nginx' );
                    } else {
                        $message_type = 'success';
                        $message_content = __( 'SUCCESS: All Nginx cache purged successfully.', 'fastcgi-cache-purge-and-preload-nginx' );
                    }
                }
                break;
            case 1:
                $message_type = 'error';
                $message_content = __( 'ERROR PERMISSION: The Nginx cache purge failed due to permission issue. Refer to the "Help" tab for guidance.', 'fastcgi-cache-purge-and-preload-nginx' );
                break;
            case 2:
                // Check auto preload status and defer message accordingly
                if (!$auto_preload) {
                    $message_type = 'info';
                    $message_content = __( 'INFO: Nginx cache purge attempted, but no cache found.', 'fastcgi-cache-purge-and-preload-nginx' );
                }
                break;
            case 3:
                $message_type = 'error';
                // Translators: %s is the Nginx cache path
                $message_content = sprintf( __( 'ERROR PATH: The specified Nginx cache path (%s) was not found. Please verify your Nginx cache path.', 'fastcgi-cache-purge-and-preload-nginx' ), $nginx_cache_path );
                break;
            case 4:
                $message_type = 'error';
                $message_content = __( 'ERROR UNKNOWN: An unexpected error occurred while attempting to purge the Nginx cache. Please report this issue on the plugin\'s support page.', 'fastcgi-cache-purge-and-preload-nginx' );
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
