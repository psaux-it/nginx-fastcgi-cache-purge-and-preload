<?php
/**
 * Advanced table for FastCGI Cache Purge and Preload for Nginx
 * Description: This file contains advanced table functions for FastCGI Cache Purge and Preload for Nginx
 * Version: 2.0.0
 * Author: Hasan ÇALIŞIR
 * Author Email: hasan.calisir@psauxit.com
 * Author URI: https://www.psauxit.com
 * License: GPL-2.0+
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

function nppp_premium_html($nginx_cache_path) {
    // initialize WP_Filesystem
    $wp_filesystem = nppp_initialize_wp_filesystem();

    if ($wp_filesystem === false) {
        return false;
    }

    // Handle case where settings option doesn't exist
    if (empty($nginx_cache_path)) {
        return '<div class="nppp-premium-wrap"><h2>Advanced Tab</h2><p>ERROR CRITICAL: Please file a bug on plugin support page (ERROR 1071)</p></div>';
    }

    // Handle case where directory doesn't exist
    if (!$wp_filesystem->is_dir($nginx_cache_path)) {
        return '<div class="nppp-premium-wrap"><h2>Advanced Tab</h2><p>ERROR PATH: Cache directory not found. Please ensure the cache directory path is correct in plugin settings.</p></div>';
    }

    // Check if the directory and its contents are readable softly and recursive
    if (!$wp_filesystem->is_readable($nginx_cache_path) || !$wp_filesystem->is_writable($nginx_cache_path)) {
        return '<div class="nppp-premium-wrap"><h2>Advanced Tab</h2><p>ERROR PERMISSION: Please ensure proper permissions are set for the cache directory. Refer to -Help- tab for guidance.</p></div>';
    } elseif (!nppp_check_permissions_recursive($nginx_cache_path)) {
        return '<div class="nppp-premium-wrap"><h2>Advanced Tab</h2><p>ERROR PERMISSION: Please ensure proper permissions are set for the cache directory. Refer to -Help- tab for guidance.</p></div>';
    }

    // Get extracted URLs
    $extractedUrls = nppp_extract_cached_urls($nginx_cache_path);

    //img url's
    $image_url_bar = plugins_url('../admin/img/bar.png', __FILE__);
    $image_url_ad = plugins_url('../admin/img/logo_ad.png', __FILE__);

    // Output the premium tab content
    ob_start();
    ?>
    <h2></h2>
    <table id="nppp-premium-table" class="display">
        <thead>
            <tr>
                <th>Cached URL</th>
                <th>Cache Path</th>
                <th>Content Category</th>
                <th>Action</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($extractedUrls)) : ?>
                <tr><td colspan="4">No cached content available yet.</td></tr>
            <?php else :
                foreach ($extractedUrls as $urlData) : ?>
                    <tr>
                        <td><?php echo esc_html($urlData['url']); ?></td>
                        <td><?php echo esc_html($urlData['file_path']); ?></td>
                        <td><?php echo esc_html($urlData['category']); ?></td>
                        <td>
                            <button class="nppp-purge-btn" data-file="<?php echo esc_attr($urlData['file_path']); ?>">Purge</button>
                            <button class="nppp-preload-btn" data-url="<?php echo esc_attr($urlData['url']); ?>" style="display: none;">Preload</button>
                        </td>
                    </tr>
                <?php endforeach;
            endif; ?>
        </tbody>
    </table>
    <?php
    return ob_get_clean();
}

// AJAX callback to load premium tab content
function nppp_load_premium_content_callback() {
    check_ajax_referer('load_premium_content_nonce', '_wpnonce');

    // Retrieve plugin settings
    $options = get_option('nginx_cache_settings');
    $nginx_cache_path = isset($options['nginx_cache_path']) ? $options['nginx_cache_path'] : '';

    // Load premium content
    echo wp_kses_post(nppp_premium_html($nginx_cache_path));
    wp_die();
}

// Deletes the selected file when purging is triggered via AJAX.
function nppp_purge_cache_premium_callback() {
    // Verify nonce
    if (isset($_POST['_wpnonce'])) {
        $nonce = sanitize_text_field(wp_unslash($_POST['_wpnonce']));
        if (!wp_verify_nonce($nonce, 'purge_cache_premium_nonce')) {
            wp_send_json_error('Nonce verification failed.');
        }
    } else {
        wp_send_json_error('Nonce is missing.');
    }

    // Check user capabilities
    if (!current_user_can('manage_options')) {
        wp_send_json_error('You do not have permission to access this page.');
    }

    // log path
    $log_file_path = NGINX_CACHE_LOG_FILE;
    nppp_perform_file_operation($log_file_path, 'create');

    // Get the main path from plugin settings
    $nginx_cache_path = get_option('nginx_cache_path');

    // Initialize WordPress filesystem
    $wp_filesystem = nppp_initialize_wp_filesystem();

    if ($wp_filesystem === false) {
        wp_send_json_error('Failed to initialize WP Filesystem');
    }

    // Get the file path from the AJAX request and sanitize it
    $file_path = isset($_POST['file_path']) ? sanitize_text_field(wp_unslash($_POST['file_path'])) : '';

    // Check if the file path is empty or not accessible
    if (empty($file_path) || !$wp_filesystem->exists($file_path)) {
        $error_message = 'INFO ADMIN: Cache purge attempted, but the page is not currently found in the cache.';
        !empty($log_file_path) ? nppp_perform_file_operation($log_file_path, 'append', '[' . current_time('Y-m-d H:i:s') . '] ' . $error_message) : die("Log file not found!");
        wp_send_json_error($error_message);
    }

    // check permissions before purge cache
    if (!$wp_filesystem->is_readable($file_path) || !$wp_filesystem->is_writable($file_path)) {
        $error_message = 'ERROR PERMISSION: Cache purge failed for page due to permission issue. Refer to -Help- tab for guidance.';
        !empty($log_file_path) ? nppp_perform_file_operation($log_file_path, 'append', '[' . current_time('Y-m-d H:i:s') . '] ' . $error_message) : die("Log file not found!");
        wp_send_json_error($error_message);
    }

    // Get the purged URL
    $https_enabled = wp_is_using_https();
    $content = $wp_filesystem->get_contents($file_path);
    if (preg_match('/KEY:\s+httpsGET(.+)/', $content, $matches)) {
        $url = trim($matches[1]);
        $sanitized_url = filter_var($url, FILTER_SANITIZE_URL);
        $final_url = $https_enabled ? "https://$sanitized_url" : "http://$sanitized_url";
    }

    // Sanitize and validate the file path again deeply before purge cache
    // This is an extra security layer
    $validation_result = nppp_validate_path($file_path, true);

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
        !empty($log_file_path) ? nppp_perform_file_operation($log_file_path, 'append', '[' . current_time('Y-m-d H:i:s') . '] ' . $error_message) : die("Log file not found!");
        wp_send_json_error($error_message);
    }

    // Perform the purge action (delete the file)
    $deleted = $wp_filesystem->delete($file_path);
    if ($deleted) {
        $success_message = "SUCCESS ADMIN: Cache Purged for page $final_url";
        !empty($log_file_path) ? nppp_perform_file_operation($log_file_path, 'append', '[' . current_time('Y-m-d H:i:s') . '] ' . $success_message) : die("Log file not found!");
        wp_send_json_success($success_message);
    } else {
        $error_message = "ERROR ADMIN: Cache cannot purged for page $final_url";
        !empty($log_file_path) ? nppp_perform_file_operation($log_file_path, 'append', '[' . current_time('Y-m-d H:i:s') . '] ' . $error_message) : die("Log file not found!");
        wp_send_json_error($error_message);
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
    $cache_url = isset($_POST['cache_url']) ? sanitize_text_field(wp_unslash($_POST['cache_url'])) : '';

    // Sanitize the URL
    $clean_cache_url = filter_var($cache_url, FILTER_SANITIZE_URL);

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
    if (filter_var($clean_cache_url, FILTER_VALIDATE_URL) !== false) {
        // Start output buffering
        ob_start();

        // call single preload action
        nppp_preload_single($clean_cache_url, $PIDFILE, $tmp_path, $nginx_cache_reject_regex, $nginx_cache_limit_rate, $nginx_cache_cpu_limit, $nginx_cache_path);

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
function nppp_extract_cached_urls($nginx_cache_path) {
    $urls = [];

    // Initialize WordPress filesystem
    $wp_filesystem = nppp_initialize_wp_filesystem();

    if ($wp_filesystem === false) {
        return false;
    }

    // Determine if HTTPS is enabled
    $https_enabled = wp_is_using_https();

    try {
        // Traverse the cache directory and its subdirectories
        $cache_iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($nginx_cache_path, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($cache_iterator as $file) {
            if ($wp_filesystem->is_file($file->getPathname())) {
                // Read file contents
                $content = $wp_filesystem->get_contents($file->getPathname());

                // Exclude URLs with status 301 Moved Permanently or 302 Found
                if (strpos($content, 'Status: 301 Moved Permanently') !== false || strpos($content, 'Status: 302 Found') !== false) {
                    continue;
                }

                // Extract URLs using regex
                if (preg_match('/KEY:\s+httpsGET(.+)/', $content, $matches)) {
                    $url = trim($matches[1]);
                    // sanitize url
                    $sanitized_url = filter_var($url, FILTER_SANITIZE_URL);
                    $final_url = $https_enabled ? "https://$sanitized_url" : "http://$sanitized_url";

                    // Validate the URL
                    if (filter_var($final_url, FILTER_VALIDATE_URL) !== false) {
                        // Categorize URLs based on WordPress permalink structures
                        $category = nppp_categorize_url($final_url);

                        // Store URL, file path, and category
                        $urls[] = array(
                            'file_path' => $file->getPathname(),
                            'url' => $final_url,
                            'category' => $category
                       );
                    } else {
                        exit("Critical error: Invalid URL encountered. Script terminated.");
                    }
                }
            }
        }
    } catch (UnexpectedValueException $e) {
        return "<div class=\"nppp-premium-wrap\"><h2>Advanced Tab</h2><p>ERROR PERMISSION: Unable to access directory $nginx_cache_path. Refer to -Help- tab for guidance.</p></div>";
    }

    return $urls;
}

//Categorizes a URL based on WordPress permalink structures and template files.
function nppp_categorize_url($url) {
    // Check for patterns in the URL to determine its category

    // Check if it's a post
    if (preg_match('#/(\d{4})/(\d{2})/(\d{2})/([^/]+)/#', $url)) {
        return 'post'; // Day and name permalink structure
    } elseif (preg_match('#/(\d{4})/(\d{2})/([^/]+)/#', $url)) {
        return 'post'; // Month and name permalink structure
    } elseif (preg_match('#/(\d{4})/([^/]+)/#', $url)) {
        return 'post'; // Year and name permalink structure
    } elseif (preg_match('#/(\d+)/#', $url)) {
        return 'post'; // Numeric permalink structure (post ID)
    } elseif (preg_match('#/author/([^/]+)/#', $url)) {
        return 'author'; // Author permalink structure
    } elseif (preg_match('#/page/(\d+)/#', $url)) {
        return 'page'; // Page permalink structure
    } elseif (preg_match('#/tag/([^/]+)/#', $url)) {
        return 'tag'; // Tag permalink structure
    } elseif (preg_match('#/category/([^/]+)/#', $url)) {
        return 'category'; // Category permalink structure
    } elseif (preg_match('#/(20\d{2})/(\d{2})/(\d{2})/#', $url)) {
        return 'daily_archive'; // Daily archive
    } elseif (preg_match('#/(20\d{2})/(\d{2})/#', $url)) {
        return 'monthly_archive'; // Monthly archive
    } elseif (preg_match('#/(20\d{2})/#', $url)) {
        return 'yearly_archive'; // Yearly archive
    } elseif (preg_match('#/.+#', $url)) {
        return 'post';
    } else {
        return 'unknown'; // Unable to determine category
    }
}
