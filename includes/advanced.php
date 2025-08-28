<?php
/**
 * Advanced table for FastCGI Cache Purge and Preload for Nginx
 * Description: This file contains advanced table functions for FastCGI Cache Purge and Preload for Nginx
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

// Generate HTML
function nppp_premium_html($nginx_cache_path) {
    // initialize WP_Filesystem
    $wp_filesystem = nppp_initialize_wp_filesystem();

    if ($wp_filesystem === false) {
        return '<div style="background-color: #f9edbe; border-left: 6px solid red; padding: 10px; margin-bottom: 15px; max-width: max-content;">
                    <h2>&nbsp;' . esc_html__( 'Error Displaying Cached Content', 'fastcgi-cache-purge-and-preload-nginx' ) . '</h2>
                    <p style="margin: 0; display: flex; align-items: center;">
                        <span class="dashicons dashicons-warning" style="font-size: 22px; color: #721c24; margin-right: 8px;"></span>
                        <span style="font-size: 14px;">' . esc_html__( 'ERROR CRITICAL: Please get help from plugin support forum! (ERROR 1070)', 'fastcgi-cache-purge-and-preload-nginx' ) . '</span>
                    </p>
                </div>';
    }

    // Handle case where option doesn't exist
    if (empty($nginx_cache_path)) {
        return '<div style="background-color: #f9edbe; border-left: 6px solid red; padding: 10px; margin-bottom: 15px; max-width: max-content;">
                    <h2>&nbsp;' . esc_html__( 'Error Displaying Cached Content', 'fastcgi-cache-purge-and-preload-nginx' ) . '</h2>
                    <p style="margin: 0; display: flex; align-items: center;">
                        <span class="dashicons dashicons-warning" style="font-size: 22px; color: #721c24; margin-right: 8px;"></span>
                        <span style="font-size: 14px;">' . esc_html__( 'ERROR CRITICAL: Please get help from plugin support forum! (ERROR 1071)', 'fastcgi-cache-purge-and-preload-nginx' ) . '</span>
                    </p>
                </div>';
    }

    // Handle case where cache directory doesn't exist
    if (!$wp_filesystem->is_dir($nginx_cache_path)) {
        return '<div style="background-color: #f9edbe; border-left: 6px solid red; padding: 10px; margin-bottom: 15px; max-width: max-content;">
                    <h2>&nbsp;' . esc_html__( 'Error Displaying Cached Content', 'fastcgi-cache-purge-and-preload-nginx' ) . '</h2>
                    <p style="margin: 0; display: flex; align-items: center;">
                        <span class="dashicons dashicons-warning" style="font-size: 22px; color: #721c24; margin-right: 8px;"></span>
                        <span style="font-size: 14px;">' . esc_html__( 'ERROR PATH: The specified Nginx cache path was not found. Please verify your Nginx cache path.', 'fastcgi-cache-purge-and-preload-nginx' ) . '</span>
                    </p>
                </div>';
    }

    // Check if the directory and its contents are readable softly and recursive
    if (!$wp_filesystem->is_readable($nginx_cache_path) || !$wp_filesystem->is_writable($nginx_cache_path)) {
        return '<div style="background-color: #f9edbe; border-left: 6px solid red; padding: 10px; margin-bottom: 15px; max-width: max-content;">
                    <h2>&nbsp;' . esc_html__( 'Error Displaying Cached Content', 'fastcgi-cache-purge-and-preload-nginx' ) . '</h2>
                    <p style="margin: 0; display: flex; align-items: center;">
                        <span class="dashicons dashicons-warning" style="font-size: 22px; color: #721c24; margin-right: 8px;"></span>
                        <span style="font-size: 14px;">' . esc_html__( 'ERROR PERMISSION: Please ensure proper permissions are set for the Nginx cache directory. Refer to the "Help" tab for guidance.', 'fastcgi-cache-purge-and-preload-nginx' ) . '</span>
                    </p>
                </div>';

    } elseif (!nppp_check_permissions_recursive($nginx_cache_path)) {
        return '<div style="background-color: #f9edbe; border-left: 6px solid red; padding: 10px; margin-bottom: 15px; max-width: max-content;">
                    <h2>&nbsp;' . esc_html__( 'Error Displaying Cached Content', 'fastcgi-cache-purge-and-preload-nginx' ) . '</h2>
                    <p style="margin: 0; display: flex; align-items: center;">
                        <span class="dashicons dashicons-warning" style="font-size: 22px; color: #721c24; margin-right: 8px;"></span>
                        <span style="font-size: 14px;">' . esc_html__( 'ERROR PERMISSION: Please ensure proper permissions are set for the Nginx cache directory. Refer to the "Help" tab for guidance.', 'fastcgi-cache-purge-and-preload-nginx' ) . '</span>
                    </p>
                </div>';
    }

    // Check FastCGI Cache Key
    $config_data = nppp_parse_nginx_cache_key();

    // Get extracted URLs
    $extractedUrls = nppp_extract_cached_urls($wp_filesystem, $nginx_cache_path);

    // Check for errors
    if (isset($extractedUrls['error'])) {
        // Sanitize and allow specific HTML tags
        $error_message = wp_kses(
            $extractedUrls['error'],
            array(
                'strong' => array(),
            )
        );

        // Stop execution if no cached content is found due to an empty cache or cache key regex error.
        return '<div style="background-color: #f9edbe; border-left: 6px solid #f0c36d; padding: 10px; margin-bottom: 15px; max-width: max-content;">
                    <h2>&nbsp;' . __( 'Displaying Cached Content', 'fastcgi-cache-purge-and-preload-nginx' ) . '</h2>
                    <p style="margin: 0; display: flex; align-items: center;">
                        <span class="dashicons dashicons-warning" style="font-size: 22px; color: #ffba00; margin-right: 8px;"></span>
                        <span style="font-size: 14px;">' . $error_message . '</span>
                    </p>
                </div>';
    }

    // Warn about cache keys not found
    if ($config_data === false) {
        echo '<div class="nppp-premium-wrap">
                  <p class="nppp-advanced-error-message">' . wp_kses_post( __( 'INFO: No <span style="color: #f0c36d;">_cache_key</span> directive was found. This may indicate a <span style="color: #f0c36d;">parsing error</span> or a missing <span style="color: #f0c36d;">nginx.conf</span> file.', 'fastcgi-cache-purge-and-preload-nginx' ) ) . '</p>
              </div>';
    // Warn about cache keys not found
    } elseif (isset($config_data['cache_keys']) && $config_data['cache_keys'] === ['Not Found']) {
        echo '<div class="nppp-premium-wrap">
                  <p class="nppp-advanced-error-message">' . wp_kses_post( __( 'INFO: No <span style="color: #f0c36d;">_cache_key</span> directive was found. This may indicate a <span style="color: #f0c36d;">parsing error</span> or a missing <span style="color: #f0c36d;">nginx.conf</span> file.', 'fastcgi-cache-purge-and-preload-nginx' ) ) . '</p>
              </div>';
    // Warn about the unsupported cache keys
    } elseif (isset($config_data['cache_keys']) && !empty($config_data['cache_keys'])) {
        echo '<div class="nppp-premium-wrap">
                  <p class="nppp-advanced-error-message">' . wp_kses_post( __( 'INFO: <span style="color: #f0c36d;">Unsupported</span> cache key found!', 'fastcgi-cache-purge-and-preload-nginx' ) ) . '</p>
              </div>';
    }

    // Output the premium tab content
    ob_start();
    ?>
    <div style="background-color: #f9edbe; border-left: 6px solid #f0c36d; padding: 10px; margin-bottom: 15px; max-width: max-content;">
        <p style="margin: 0; display: flex; align-items: center;">
            <span class="dashicons dashicons-warning" style="font-size: 22px; color: #ffba00; margin-right: 8px;"></span>
            <?php echo wp_kses_post( __( 'If the <strong>Cached URL\'s</strong> are incorrect, <strong>Preload</strong> will not work as expected. Please check the <strong>Cache Key Regex</strong> option in plugin <strong>Advanced options</strong> section, ensure the regex is configured correctly, and try again.', 'fastcgi-cache-purge-and-preload-nginx' ) ); ?>
        </p>
    </div>
    <h2></h2>
    <table id="nppp-premium-table" class="display">
        <thead>
            <tr>
                <th><?php esc_html_e( 'Cached URL', 'fastcgi-cache-purge-and-preload-nginx' ); ?></th>
                <th><?php esc_html_e( 'Cache Path', 'fastcgi-cache-purge-and-preload-nginx' ); ?></th>
                <th><?php esc_html_e( 'Content Category', 'fastcgi-cache-purge-and-preload-nginx' ); ?></th>
                <th><?php esc_html_e( 'Cache Method', 'fastcgi-cache-purge-and-preload-nginx' ); ?></th>
                <th><?php esc_html_e( 'Cache Date', 'fastcgi-cache-purge-and-preload-nginx' ); ?></th>
                <th><?php esc_html_e( 'Action', 'fastcgi-cache-purge-and-preload-nginx' ); ?></th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($extractedUrls)) : ?>
                <tr><td colspan="6"><?php esc_html_e( 'No cached content available yet. Consider Preload Cache now.', 'fastcgi-cache-purge-and-preload-nginx' ); ?></td></tr>
            <?php else :
                foreach ($extractedUrls as $urlData) : ?>
                    <tr>
                        <td><?php echo esc_html($urlData['url']); ?></td>
                        <td><?php echo esc_html($urlData['file_path']); ?></td>
                        <td><?php echo esc_html($urlData['category']); ?></td>
                        <td>GET</td>
                        <td><?php echo esc_html($urlData['cache_date']); ?></td>
                        <td>
                            <button type="button" class="nppp-purge-btn" data-file="<?php echo esc_attr($urlData['file_path']); ?>"><span class="dashicons dashicons-trash" style="font-size: 16px; margin: 0; padding: 0;"></span> <?php echo esc_html__( 'Purge', 'fastcgi-cache-purge-and-preload-nginx' ); ?></button>
                            <button type="button" class="nppp-preload-btn" data-url="<?php echo esc_attr($urlData['url_encoded']); ?>"><span class="dashicons dashicons-update" style="font-size: 16px; margin: 0; padding: 0;"></span> <?php echo esc_html__( 'Preload', 'fastcgi-cache-purge-and-preload-nginx' ); ?></button>
                        </td>
                    </tr>
                <?php endforeach;
            endif; ?>
        </tbody>
    </table>
    <?php
    return ob_get_clean();
}

// Log and send error
function nppp_log_and_send_error($error_message, $log_file_path) {
    if (!empty($log_file_path)) {
        nppp_perform_file_operation(
            $log_file_path,
            'append',
            '[' . current_time('Y-m-d H:i:s') . '] ' . $error_message
        );
    } else {
        nppp_custom_error_log('Log file not found!');
    }
    wp_send_json_error($error_message);
}

// Log and send success
function nppp_log_and_send_success($success_message, $log_file_path) {
    // Make a plain-text version for the log
    $for_log  = preg_replace( '~<br\s*/?>~i', ' — ', (string) $success_message );
    $log_text = trim( wp_strip_all_tags( $for_log, false ) );

    if (!empty($log_file_path)) {
        nppp_perform_file_operation(
            $log_file_path,
            'append',
            '[' . current_time('Y-m-d H:i:s') . '] ' . $log_text
        );
    } else {
        nppp_custom_error_log('Log file not found!');
    }
    wp_send_json_success($success_message);
}

// AJAX callback to load premium tab content
function nppp_load_premium_content_callback() {
    check_ajax_referer('load_premium_content_nonce', '_wpnonce');

    // Retrieve plugin settings
    $options = get_option('nginx_cache_settings');
    $nginx_cache_path = isset($options['nginx_cache_path']) ? $options['nginx_cache_path'] : '';

    // Generate the HTML content
    $premium_content = nppp_premium_html($nginx_cache_path);

    // Return the generated HTML to AJAX
    if (!empty($premium_content)) {
        echo wp_kses_post($premium_content);
    } else {
        // Send empty string to AJAX to trigger proper error
        echo '';
    }

    // Properly exit to avoid extra output
    wp_die();
}

// Prevent Directory Traversal attacks
function nppp_is_path_in_directory($path, $directory) {
    // Resolve real paths
    $real_path = realpath($path);
    $real_directory = realpath($directory);

    // Check if the real paths are valid
    if ($real_path === false) {
        return 'file_not_found';
    }
    if ($real_directory === false) {
        return 'invalid_cache_directory';
    }

    // Normalize paths
    $real_path = wp_normalize_path($real_path);
    $real_directory = wp_normalize_path($real_directory);

    // Add trailing slashes
    $real_path = trailingslashit($real_path);
    $real_directory = trailingslashit($real_directory);

    // Compare the directory paths
    if (strpos($real_path, $real_directory) === 0) {
        return true;
    } else {
        return 'outside_cache_directory';
    }
}

// Deletes the selected file when purging is triggered via AJAX.
function nppp_purge_cache_premium_callback() {
    // Check user capabilities
    if (!current_user_can('manage_options')) {
        wp_send_json_error('You do not have permission to access this page.');
    }

    // Verify nonce
    if (isset($_POST['_wpnonce'])) {
        $nonce = sanitize_text_field(wp_unslash($_POST['_wpnonce']));
        if (!wp_verify_nonce($nonce, 'purge_cache_premium_nonce')) {
            wp_send_json_error('Nonce verification failed.');
        }
    } else {
        wp_send_json_error('Nonce is missing.');
    }

    // Create log file
    $log_file_path = NGINX_CACHE_LOG_FILE;
    nppp_perform_file_operation($log_file_path, 'create');

    // Get the main path from plugin settings
    $options = get_option('nginx_cache_settings');
    $nginx_cache_path = isset($options['nginx_cache_path']) ? $options['nginx_cache_path'] : '';

    // Retrieve and decode user-defined cache key regex from the db, with a hardcoded fallback
    $regex = isset($options['nginx_cache_key_custom_regex'])
             ? base64_decode($options['nginx_cache_key_custom_regex'])
             : nppp_fetch_default_regex_for_cache_key();

    // Initialize WordPress filesystem
    $wp_filesystem = nppp_initialize_wp_filesystem();

    if ($wp_filesystem === false) {
        wp_send_json_error('Failed to initialize WP Filesystem');
    }

    // Get the PID file path
    $this_script_path = dirname(plugin_dir_path(__FILE__));
    $PIDFILE = rtrim($this_script_path, '/') . '/cache_preload.pid';

    // First, check if any cache preloading action is in progress.
    // Purging the cache for a single page or post in Advanced tab while cache preloading is in progress can cause issues
    if ($wp_filesystem->exists($PIDFILE)) {
        $pid = intval(nppp_perform_file_operation($PIDFILE, 'read'));

        // Check process is alive
        if ($pid > 0 && nppp_is_process_alive($pid)) {
            $error_message = __( 'INFO ADMIN: Single-page purge skipped — Nginx cache preloading is in progress. Check the Status tab to monitor; wait for completion or use "Purge All" to cancel.', 'fastcgi-cache-purge-and-preload-nginx' );
            nppp_log_and_send_error($error_message, $log_file_path);
        }
    }

    // Get the file path from the AJAX request and sanitize it
    $file_path = isset($_POST['file_path']) ? sanitize_text_field(wp_unslash($_POST['file_path'])) : '';

    // Prevent Directory Traversal attacks
    $path_check = nppp_is_path_in_directory($file_path, $nginx_cache_path);

    if ($path_check !== true) {
        switch ($path_check) {
            case 'file_not_found':
                $error_message = __( 'Nginx cache purge attempted, but no cache entry was found.', 'fastcgi-cache-purge-and-preload-nginx' );
                break;
            case 'invalid_cache_directory':
                $error_message = __( 'Nginx cache purge failed because the Nginx cache directory is invalid.', 'fastcgi-cache-purge-and-preload-nginx' );
                break;
            case 'outside_cache_directory':
                $error_message = __( 'The attempt to purge the Nginx cache was blocked due to security restrictions.', 'fastcgi-cache-purge-and-preload-nginx' );
                break;
            default:
                $error_message = __( 'An unexpected error occurred while attempting to purge the Nginx cache. Please report this issue on the plugin\'s support page.', 'fastcgi-cache-purge-and-preload-nginx' );
        }
        nppp_log_and_send_error($error_message, $log_file_path);
    }

    // Check permissions before purge cache
    if (!$wp_filesystem->is_readable($file_path) || !$wp_filesystem->is_writable($file_path)) {
        $error_message = __( 'ERROR PERMISSION: The Nginx cache purge failed due to permission issue. Refer to the "Help" tab for guidance.', 'fastcgi-cache-purge-and-preload-nginx' );
        nppp_log_and_send_error($error_message, $log_file_path);
    }

    // Get the purged URL
    $https_enabled = wp_is_using_https();
    $content = $wp_filesystem->get_contents($file_path);

    $final_url = '';
    if (preg_match($regex, $content, $matches) && isset($matches[1], $matches[2])) {
        // Build the URL
        $host = trim($matches[1]);
        $request_uri = trim($matches[2]);
        $constructed_url = $host . $request_uri;

        // Test parsed URL via regex with FILTER_VALIDATE_URL
        // We need to add prefix here
        $constructed_url_test = 'https://' . $constructed_url;

        if ($constructed_url !== '' && filter_var($constructed_url_test, FILTER_VALIDATE_URL)) {
            $sanitized_url = filter_var($constructed_url, FILTER_SANITIZE_URL);
            $final_url = $https_enabled ? "https://$sanitized_url" : "http://$sanitized_url";
        }
    }

    // Sanitize and validate the file path again deeply before purge cache
    // This is an extra security layer
    $validation_result = nppp_validate_path($file_path, true);

    // Check the validation result
    if ($validation_result !== true) {
        // Handle different validation outcomes
        switch ($validation_result) {
            case 'critical_path':
                $error_message = __( 'ERROR PATH: The Nginx cache path appears to be a critical system directory or a first-level directory. Failed to purge Nginx cache!', 'fastcgi-cache-purge-and-preload-nginx' );
                break;
            case 'file_not_found_or_not_readable':
                $error_message = __( 'ERROR PATH: The specified Nginx cache path was not found. Failed to purge Nginx cache!', 'fastcgi-cache-purge-and-preload-nginx' );
                break;
            default:
                $error_message = __( 'ERROR PATH: An invalid Nginx cache path was provided. Failed to purge Nginx cache!', 'fastcgi-cache-purge-and-preload-nginx' );
        }
        nppp_log_and_send_error($error_message, $log_file_path);
    }

    // Perform the purge action (delete the file)
    $deleted = $wp_filesystem->delete($file_path);

    // Display decoded URL to user
    $final_url_decoded = rawurldecode($final_url);

    if ($deleted) {
        // Translators: %s is the page URL
        $success_message = sprintf( __( 'SUCCESS ADMIN: Nginx cache purged for page %s', 'fastcgi-cache-purge-and-preload-nginx' ), $final_url_decoded );

        $settings = get_option('nginx_cache_settings');
        $related_urls = nppp_get_related_urls_for_single($final_url);

        // Purge related silently (no extra notices, as this is an AJAX JSON response)
        foreach ($related_urls as $rel) {
            nppp_purge_url_silent($nginx_cache_path, $rel);
        }

        // Preload policy for manual (Advanced tab is manual)
        if (!empty($settings['nppp_related_preload_after_manual']) && $settings['nppp_related_preload_after_manual'] === 'yes') {
            nppp_preload_urls_fire_and_forget($related_urls);
        }

        // Optionally make the success message clearer
        if (!empty($related_urls)) {
            $labels = [];
            if (!empty($settings['nppp_related_include_home']) && $settings['nppp_related_include_home'] === 'yes') {
                $labels[] = esc_html__('Homepage', 'fastcgi-cache-purge-and-preload-nginx');
            }
            if (!empty($settings['nppp_related_include_category']) && $settings['nppp_related_include_category'] === 'yes') {
                $labels[] = esc_html__('Category archive(s)', 'fastcgi-cache-purge-and-preload-nginx');
            }
            $label_text = implode('/', $labels);

            $preload_tail = (!empty($settings['nppp_related_preload_after_manual']) && $settings['nppp_related_preload_after_manual'] === 'yes')
                ? esc_html__(' purged & preloaded', 'fastcgi-cache-purge-and-preload-nginx')
                : esc_html__(' purged', 'fastcgi-cache-purge-and-preload-nginx');

            // Second line, colored
            $success_message .= '<br><span class="nppp-related-line">('
                . sprintf(
                    /* Translators: %s is like "homepage/category archive(s)" */
                    esc_html__('Related: %s', 'fastcgi-cache-purge-and-preload-nginx'),
                    esc_html($label_text)
                )
                . $preload_tail
                . ')</span>';
        }
        nppp_log_and_send_success($success_message, $log_file_path);
    } else {
        // Translators: %s is the page URL
        $error_message = sprintf( __( 'ERROR ADMIN: Nginx cache can not be purged for page %s', 'fastcgi-cache-purge-and-preload-nginx' ), $final_url_decoded );
        nppp_log_and_send_error($error_message, $log_file_path);
    }
}

// Deletes the selected file when purging is triggered via AJAX
function nppp_preload_cache_premium_callback() {
    // Verify nonce
    if (isset($_POST['_wpnonce'])) {
        $nonce = sanitize_text_field(wp_unslash($_POST['_wpnonce']));
        if (!wp_verify_nonce($nonce, 'preload_cache_premium_nonce')) {
            wp_send_json_error('Nonce verification failed.');
        }
    } else {
        wp_send_json_error('Nonce is missing.');
    }

    // Check user capabilities
    if (!current_user_can('manage_options')) {
        wp_send_json_error('You do not have permission to access this page.');
    }

    // Get the main path from plugin settings
    $nginx_cache_path = get_option('nginx_cache_path');

    // Initialize WordPress filesystem
    $wp_filesystem = nppp_initialize_wp_filesystem();

    if ($wp_filesystem === false) {
        wp_send_json_error('Failed to initialize WP Filesystem');
    }

    // Get the file path from the AJAX request and sanitize it
    $cache_url = isset($_POST['cache_url']) ? esc_url_raw(wp_unslash($_POST['cache_url'])) : '';

    // Get the plugin options
    $nginx_cache_settings = get_option('nginx_cache_settings');

    // Set default options to prevent any error
    $default_cache_path = '/dev/shm/change-me-now';
    $default_limit_rate = 1280;
    $default_cpu_limit = 50;
    $default_reject_regex = nppp_fetch_default_reject_regex();

    // Preload action options
    $this_script_path = dirname(plugin_dir_path(__FILE__));
    $PIDFILE = rtrim($this_script_path, '/') . '/cache_preload.pid';
    $nginx_cache_path = isset($nginx_cache_settings['nginx_cache_path']) ? $nginx_cache_settings['nginx_cache_path'] : $default_cache_path;
    $tmp_path = rtrim($nginx_cache_path, '/') . "/tmp";
    $nginx_cache_reject_regex = isset($nginx_cache_settings['nginx_cache_reject_regex']) ? $nginx_cache_settings['nginx_cache_reject_regex'] : $default_reject_regex;
    $nginx_cache_limit_rate = isset($nginx_cache_settings['nginx_cache_limit_rate']) ? $nginx_cache_settings['nginx_cache_limit_rate'] : $default_limit_rate;
    $nginx_cache_cpu_limit = isset($nginx_cache_settings['nginx_cache_cpu_limit']) ? $nginx_cache_settings['nginx_cache_cpu_limit'] : $default_cpu_limit;

    // Validate the sanitized URL
    if (filter_var($cache_url, FILTER_VALIDATE_URL) !== false) {
        // Start output buffering
        ob_start();

        // call single preload action
        nppp_preload_single($cache_url, $PIDFILE, $tmp_path, $nginx_cache_reject_regex, $nginx_cache_limit_rate, $nginx_cache_cpu_limit, $nginx_cache_path);

        // Capture and clean the buffer
        $output = wp_strip_all_tags(ob_get_clean());

        // Determine if the message is a success or an error
        if (strpos($output, 'SUCCESS ADMIN') !== false) {
            wp_send_json_success($output);
        } else {
            wp_send_json_error($output);
        }
    } else {
        wp_send_json_error('Preload Cache URL validation failed.');
    }
}

// Recursively traverses directories and extracts necessary data from files.
// We already sanitized and validated the $nginx_cache_path
// so for file_path we don't apply any sanitize and validate
// we only sanitize and validate the urls parsed from files
function nppp_extract_cached_urls($wp_filesystem, $nginx_cache_path) {
    $urls = [];

    // Determine if HTTPS is enabled
    $https_enabled = wp_is_using_https();

    // Retrieve and decode user-defined cache key regex from the database, with a hardcoded fallback
    $nginx_cache_settings = get_option('nginx_cache_settings');

    // User defined regex in Cache Key Regex option
    $regex = isset($nginx_cache_settings['nginx_cache_key_custom_regex'])
             ? base64_decode($nginx_cache_settings['nginx_cache_key_custom_regex'])
             : nppp_fetch_default_regex_for_cache_key();

    try {
        // Traverse the cache directory and its subdirectories
        $cache_iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($nginx_cache_path, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );

        $regex_tested = false;
        foreach ($cache_iterator as $file) {
            if ($wp_filesystem->is_file($file->getPathname())) {
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

                        // Normalize percent-encoded sequences to lowercase to prevent cache misses
                        $request_uri = preg_replace_callback('/%[0-9A-F]{2}/i', function ($matches) {
                            return strtolower($matches[0]);
                        }, $request_uri);

                        $constructed_url = $host . $request_uri;

                        // Test parsed URL via regex with FILTER_VALIDATE_URL
                        // We need to add prefix here
                        $constructed_url_test = 'https://' . $constructed_url;

                        // Test if the URL is in the expected format
                        if ($constructed_url !== '' && filter_var($constructed_url_test, FILTER_VALIDATE_URL)) {
                            $regex_tested = true;
                        } else {
                            return [
                                'error' => sprintf(
                                    /* Translators: %1$s and %2$s are dynamic strings, $host$request_uri is string */
                                    __( 'ERROR REGEX: Please check the <strong>%1$s</strong> option in the plugin <strong>%2$s</strong> section and ensure the <strong>regex</strong> is parsing the string <strong>\$host\$request_uri</strong> correctly.', 'fastcgi-cache-purge-and-preload-nginx'),
                                    __( 'Cache Key Regex', 'fastcgi-cache-purge-and-preload-nginx'),
                                    __( 'Advanced Options', 'fastcgi-cache-purge-and-preload-nginx')
                                )
                            ];
                        }
                    } else {
                        return [
                            'error' => sprintf(
                                /* Translators: %1$s and %2$s are dynamic strings */
                                __( 'ERROR REGEX: Please check the <strong>%1$s</strong> option in the plugin <strong>%2$s</strong> section and ensure the <strong>regex</strong> is configured correctly.', 'fastcgi-cache-purge-and-preload-nginx'),
                                __( 'Cache Key Regex', 'fastcgi-cache-purge-and-preload-nginx'),
                                __( 'Advanced Options', 'fastcgi-cache-purge-and-preload-nginx')
                            )
                        ];
                    }
                }

                // Extract URLs using regex
                if (preg_match($regex, $content, $matches)) {
                    // Build the URL
                    $host = trim($matches[1]);
                    $request_uri = trim($matches[2]);

                    // Keep the encoded URI for internal consistency
                    $constructed_url_encoded = $host . $request_uri;

                    // Sanitize and validate the encoded URL
                    $sanitized_url = filter_var($constructed_url_encoded, FILTER_SANITIZE_URL);
                    $final_url_encoded = $https_enabled ? "https://$sanitized_url" : "http://$sanitized_url";

                    if (filter_var($final_url_encoded, FILTER_VALIDATE_URL) !== false) {
                        // Decode URI only for displaying URLs in human-readable form
                        $decoded_uri = rawurldecode($request_uri);
                        $constructed_url_decoded = $host . $decoded_uri;
                        $final_url = $https_enabled ? "https://$constructed_url_decoded" : "http://$constructed_url_decoded";

                        // Get the file modification time for cache date
                        $cache_timestamp = $file->getMTime();
                        $cache_date = wp_date('Y-m-d H:i:s', $cache_timestamp);

                        // Categorize URLs
                        $category = nppp_categorize_url($final_url);

                        // Store URL data
                        $urls[] = array(
                            'file_path'   => $file->getPathname(),
                            'url'         => $final_url,
                            'url_encoded' => $final_url_encoded,
                            'category'    => $category,
                            'cache_date'  => $cache_date
                        );
                    }
                }
            }
        }
    } catch (Exception $e) {
        // Handle exceptions and return an error message
        return [
            'error' => __( 'An error occurred while accessing the Nginx cache directory. Please report this issue on the plugin\'s support page.', 'fastcgi-cache-purge-and-preload-nginx' )
        ];
    }

    // Check if any URLs were extracted
    if (empty($urls)) {
        return [
            'error' => __( 'No cached content found. Please use <strong>Preload All</strong> to warm Nginx cache first and try again.', 'fastcgi-cache-purge-and-preload-nginx' )
        ];
    }

    return $urls;
}

// Categorizes a URL based on WordPress permalink structures and template files.
function nppp_categorize_url($url) {
    static $url_cache = [];
    static $rewrite_rules = null;
    static $all_post_types = null;
    static $taxonomies = null;

    if (isset($url_cache[$url])) {
        return $url_cache[$url];
    }

    $cache_key = 'nppp_category_' . md5($url);
    $cached = get_transient($cache_key);
    if ($cached !== false) {
        $url_cache[$url] = $cached;
        return $cached;
    }

    $site_url = get_site_url();
    $parsed_site = wp_parse_url($site_url);
    $parsed_url = wp_parse_url($url);

    if (!isset($parsed_url['host']) || $parsed_url['host'] !== $parsed_site['host']) {
        $category = 'EXTERNAL';
        set_transient($cache_key, $category, MONTH_IN_SECONDS);
        $url_cache[$url] = $category;
        return $category;
    }

    $path = isset($parsed_url['path']) ? $parsed_url['path'] : '';
    $request = trim($path, '/');
    $request = preg_replace('#/page/\d+/?$#', '', $request);

    if ($request === '') {
        $category = 'HOMEPAGE';
    } else {
        $post_id = url_to_postid($url);
        if ($post_id) {
            $post_type = get_post_type($post_id);
            if ($all_post_types === null) {
                $all_post_types = get_post_types([], 'objects');
            }

            if (isset($all_post_types[$post_type])) {
                $category = strtoupper($all_post_types[$post_type]->labels->singular_name);
            } else {
                $category = strtoupper($post_type);
            }
        } else {
            global $wp_rewrite;
            $query_vars = [];

            if (!empty($wp_rewrite->wp_rewrite_rules())) {
                if ($rewrite_rules === null) {
                    $rewrite_rules = $wp_rewrite->wp_rewrite_rules();
                }

                foreach ($rewrite_rules as $match => $query) {
                    if (preg_match("#^$match#", $request, $matches)) {
                        $query = preg_replace("#^.+\?#", '', $query);
                        $query = addslashes(WP_MatchesMapRegex::apply($query, $matches));
                        parse_str($query, $query_vars);
                        break;
                    }
                }
            }

            if (!empty($query_vars)) {
                if (!empty($query_vars['category_name']) || !empty($query_vars['cat'])) {
                    $category = 'CATEGORY';
                } elseif (!empty($query_vars['tag']) || !empty($query_vars['tag_id'])) {
                    $category = 'TAG';
                } elseif (!empty($query_vars['author_name']) || !empty($query_vars['author'])) {
                    $category = 'AUTHOR';
                } elseif (!empty($query_vars['post_type'])) {
                    $post_type = is_array($query_vars['post_type']) ? reset($query_vars['post_type']) : $query_vars['post_type'];
                    if ($all_post_types === null) {
                        $all_post_types = get_post_types([], 'objects');
                    }
                    if (isset($all_post_types[$post_type])) {
                        $category = strtoupper($all_post_types[$post_type]->labels->singular_name);
                    } else {
                        $category = strtoupper($post_type);
                    }
                } elseif (!empty($query_vars['year']) || !empty($query_vars['monthnum']) || !empty($query_vars['day'])) {
                    $category = 'DATE_ARCHIVE';
                } elseif (!empty($query_vars['s'])) {
                    $category = 'SEARCH_RESULTS';
                }
            }

            if (empty($category)) {
                if ($taxonomies === null) {
                    $taxonomies = get_taxonomies(['public' => true], 'objects');
                    foreach (['product_cat', 'product_tag'] as $tax) {
                        if (!isset($taxonomies[$tax]) && taxonomy_exists($tax)) {
                            $taxonomies[$tax] = get_taxonomy($tax);
                        }
                    }
                }

                foreach ($taxonomies as $taxonomy) {
                    $slug = $taxonomy->rewrite['slug'] ?? $taxonomy->name;
                    if (!$slug) continue;

                    $pattern = '#^' . preg_quote($slug, '#') . '(/[^/]+(?:/[^/]+)*)?(?:/page/\d+)?/?$#';
                    if (preg_match($pattern, $request, $matches)) {
                        $term_path = isset($matches[1]) ? trim($matches[1], '/') : '';

                        if ($taxonomy->hierarchical) {
                            $slugs = explode('/', $term_path);
                            $parent = 0;
                            $term = null;

                            foreach ($slugs as $slug_part) {
                                $term = get_term_by('slug', $slug_part, $taxonomy->name);
                                if (!$term || is_wp_error($term) || (int) $term->parent !== (int) $parent) {
                                    $term = null;
                                    break;
                                }
                                $parent = $term->term_id;
                            }

                            if ($term) {
                                $category = strtoupper($taxonomy->labels->singular_name);
                                break;
                            }
                        } else {
                            $slug_part = basename($term_path);
                            $term = get_term_by('slug', $slug_part, $taxonomy->name);
                            if ($term && !is_wp_error($term)) {
                                $category = strtoupper($taxonomy->labels->singular_name);
                                break;
                            }
                        }
                    }
                }
            }

            if (empty($category)) {
                $author_base = $wp_rewrite->author_base ?: 'author';
                if (preg_match('#^' . preg_quote($author_base, '#') . '/([^/]+)/?$#', $request, $matches)) {
                    $author = get_user_by('slug', $matches[1]);
                    $category = $author ? 'AUTHOR' : 'UNKNOWN';
                } else {
                    $date_structure = $wp_rewrite->get_date_permastruct();
                    if ($date_structure) {
                        $date_regex = str_replace(
                            ['%year%', '%monthnum%', '%day%'],
                            ['([0-9]{4})', '([0-9]{1,2})', '([0-9]{1,2})'],
                            $date_structure
                        );
                        $date_regex = '!^' . trim($date_regex, '/') . '/?$!';
                        $category = preg_match($date_regex, $request) ? 'DATE_ARCHIVE' : 'UNKNOWN';
                    } else {
                        $category = 'UNKNOWN';
                    }
                }
            }
        }
    }

    $category = apply_filters('nppp_categorize_url_result', $category ?: 'UNKNOWN', $url);

    $url_cache[$url] = $category;
    set_transient($cache_key, $category, MONTH_IN_SECONDS);

    return $category;
}
