<?php
/**
 * Advanced table for FastCGI Cache Purge and Preload for Nginx
 * Description: This file contains advanced table functions for FastCGI Cache Purge and Preload for Nginx
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

// Generate HTML
function nppp_premium_html($nginx_cache_path) {
    // initialize WP_Filesystem
    $wp_filesystem = nppp_initialize_wp_filesystem();

    if ($wp_filesystem === false) {
        return '<div class="nppp-premium-wrap"><h2>Error Displaying Cached Content</h2><p class="nppp-advanced-error-message">Failed to initialize the WordPress filesystem.</p></div>';
    }

    // Handle case where settings option doesn't exist
    if (empty($nginx_cache_path)) {
        return '<div class="nppp-premium-wrap"><h2>Error Displaying Cached Content</h2><p class="nppp-advanced-error-message">ERROR CRITICAL: Please file a bug on plugin support page (ERROR 1071)</p></div>';
    }

    // Handle case where directory doesn't exist
    if (!$wp_filesystem->is_dir($nginx_cache_path)) {
        return '<div class="nppp-premium-wrap"><h2>Error Displaying Cached Content</h2><p class="nppp-advanced-error-message">ERROR PATH: Cache directory not found. Please ensure the cache directory path is correct in plugin settings.</p></div>';
    }

    // Check if the directory and its contents are readable softly and recursive
    if (!$wp_filesystem->is_readable($nginx_cache_path) || !$wp_filesystem->is_writable($nginx_cache_path)) {
        return '<div class="nppp-premium-wrap"><h2>Error Displaying Cached Content</h2><p class="nppp-advanced-error-message">ERROR PERMISSION: Please ensure proper permissions are set for the cache directory. Refer to the Help tab for guidance.</p></div>';
    } elseif (!nppp_check_permissions_recursive($nginx_cache_path)) {
        return '<div class="nppp-premium-wrap"><h2>Error Displaying Cached Content</h2><p class="nppp-advanced-error-message">ERROR PERMISSION: Please ensure proper permissions are set for the cache directory. Refer to the Help tab for guidance.</p></div>';
    }

    // Check NGINX Cache Key format is in supported format If not ADVANCED tab fail
    $config_data = nppp_parse_nginx_cache_key();

    if ($config_data === false) {
        return '<div class="nppp-premium-wrap"><h2>Error Displaying Cached Content</h2><p class="nppp-advanced-error-message">ERROR CONF: Unable to locate the nginx.conf file in the specified paths or encountered a parsing error.</p></div>';
    } else {
        // Output error message if cache keys are found
        if (!empty($config_data['cache_keys'])) {
            return '<div class="nppp-premium-wrap"><h2>Error Displaying Cached Content</h2><p class="nppp-advanced-error-message">ERROR CACHE KEY: Nginx cache key format is not suitable for the plugin. Refer to the Help tab for guidance.</p></div>';
        }
    }

    // Get extracted URLs
    $extractedUrls = nppp_extract_cached_urls($wp_filesystem, $nginx_cache_path);

    // Check for errors from nppp_extract_cached_urls()
    if (isset($extractedUrls['error'])) {
        return '<div class="nppp-premium-wrap"><h2>Displaying Cached Content</h2><p class="nppp-advanced-error-message">' . esc_html($extractedUrls['error']) . '</p></div>';
    }

    // Output the premium tab content
    ob_start();
    ?>
    <div style="background-color: #f9edbe; border-left: 6px solid #f0c36d; padding: 10px; margin-bottom: 15px; max-width: max-content;">
        <p style="margin: 0; display: flex; align-items: center;">
            <span class="dashicons dashicons-warning" style="font-size: 22px; color: #ffba00; margin-right: 8px;"></span>
            <strong>Note:</strong> If the table is not visible or appears broken, please ensure that the <strong>fastcgi_cache_key</strong> format is correctly <code>$scheme$request_method$host$request_uri</code> configured.
        </p>
    </div>
    <h2></h2>
    <table id="nppp-premium-table" class="display">
        <thead>
            <tr>
                <th>Cached URL</th>
                <th>Cache Path</th>
                <th>Content Category</th>
                <th>Cache Method</th>
                <th>Cache Date</th>
                <th>Action</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($extractedUrls)) : ?>
                <tr><td colspan="6">No cached content available yet. Consider Preload Cache now.</td></tr>
            <?php else :
                foreach ($extractedUrls as $urlData) : ?>
                    <tr>
                        <td><?php echo esc_html($urlData['url']); ?></td>
                        <td><?php echo esc_html($urlData['file_path']); ?></td>
                        <td><?php echo esc_html($urlData['category']); ?></td>
                        <td>GET</td>
                        <td><?php echo esc_html($urlData['cache_date']); ?></td>
                        <td>
                            <button class="nppp-purge-btn" data-file="<?php echo esc_attr($urlData['file_path']); ?>">Purge</button>
                            <button class="nppp-preload-btn" data-url="<?php echo esc_attr($urlData['url']); ?>">Preload</button>
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
    if (!empty($log_file_path)) {
        nppp_perform_file_operation(
            $log_file_path,
            'append',
            '[' . current_time('Y-m-d H:i:s') . '] ' . $success_message
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

        // Check if posix_kill function exists
        if (function_exists('posix_kill')) {
            if ($pid > 0 && posix_kill($pid, 0)) {
                $error_message = "INFO ADMIN: Purge cache halted due to ongoing cache preloading. You can stop cache preloading anytime via Purge All.";
                nppp_log_and_send_error($error_message, $log_file_path);
            }
        } else {
            wp_send_json_error('Cannot check process status on this server.');
        }
    }

    // Get the file path from the AJAX request and sanitize it
    $file_path = isset($_POST['file_path']) ? sanitize_text_field(wp_unslash($_POST['file_path'])) : '';

    // Prevent Directory Traversal attacks
    $path_check = nppp_is_path_in_directory($file_path, $nginx_cache_path);

    if ($path_check !== true) {
        switch ($path_check) {
            case 'file_not_found':
                $error_message = 'Cache purge attempted, but the page is not currently cached.';
                break;
            case 'invalid_cache_directory':
                $error_message = 'Cache purge failed because the cache directory is invalid.';
                break;
            case 'outside_cache_directory':
                $error_message = 'Cache purge attempted but forbidden for security reasons.';
                break;
            default:
                $error_message = 'Cache purge failed due to an unexpected error. Please try again later.';
        }
        nppp_log_and_send_error($error_message, $log_file_path);
    }

    // Check permissions before purge cache
    if (!$wp_filesystem->is_readable($file_path) || !$wp_filesystem->is_writable($file_path)) {
        $error_message = 'ERROR PERMISSION: Cache purge failed  due to permission issue. Refer to -Help- tab for guidance.';
        nppp_log_and_send_error($error_message, $log_file_path);
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
        nppp_log_and_send_error($error_message, $log_file_path);
    }

    // Perform the purge action (delete the file)
    $deleted = $wp_filesystem->delete($file_path);
    if ($deleted) {
        $success_message = "SUCCESS ADMIN: Cache Purged for page $final_url";
        nppp_log_and_send_success($success_message, $log_file_path);
    } else {
        $error_message = "ERROR ADMIN: Cache cannot purged for page $final_url";
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
function nppp_extract_cached_urls($wp_filesystem, $nginx_cache_path) {
    $urls = [];

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

                // Exclude URLs with status 301 or 302
                if (strpos($content, 'Status: 301 Moved Permanently') !== false ||
                    strpos($content, 'Status: 302 Found') !== false) {
                    continue;
                }

                // Extract URLs using regex
                if (preg_match('/KEY:\s+httpsGET(.+)/', $content, $matches)) {
                    $url = trim($matches[1]);

                    // Sanitize and validate the URL
                    $sanitized_url = filter_var($url, FILTER_SANITIZE_URL);
                    $final_url = $https_enabled ? "https://$sanitized_url" : "http://$sanitized_url";

                    if (filter_var($final_url, FILTER_VALIDATE_URL) !== false) {
                        // Get the file modification time for cache date
                        $cache_timestamp = $file->getMTime();
                        $cache_date = wp_date('Y-m-d H:i:s', $cache_timestamp);

                        // Categorize URLs
                        $category = nppp_categorize_url($final_url);

                        // Store URL data
                        $urls[] = array(
                            'file_path'  => $file->getPathname(),
                            'url'        => $final_url,
                            'category'   => $category,
                            'cache_date' => $cache_date
                        );
                    }
                }
            }
        }
    } catch (Exception $e) {
        // Handle exceptions and return an error message
        return [
            'error' => 'An error occurred while accessing the cache directory. Please try again.'
        ];
    }

    // Check if any URLs were extracted
    if (empty($urls)) {
        return [
            'error' => 'No cached content available yet. Consider Preload Cache now.'
        ];
    }

    return $urls;
}

// Categorizes a URL based on WordPress permalink structures and template files.
function nppp_categorize_url($url) {
    // Static cache array to store results during the request
    static $url_cache = array();

    // Check if the URL is already cached in the static cache
    if (isset($url_cache[$url])) {
        return $url_cache[$url];
    }

    // Generate a unique cache key for the transient
    $cache_key = 'nppp_category_' . md5($url);

    // Try to get the category from the transient cache
    $category = get_transient($cache_key);

    if ($category !== false) {
        // Cache the result in the static cache as well
        $url_cache[$url] = $category;
        return $category;
    }

    // Ensure the URL is on the same host
    $site_url    = get_site_url();
    $parsed_site = wp_parse_url($site_url);
    $parsed_url  = wp_parse_url($url);

    if (!isset($parsed_url['host']) || $parsed_url['host'] !== $parsed_site['host']) {
        $category = 'EXTERNAL';
        // Cache the result
        $url_cache[$url] = $category;
        set_transient($cache_key, $category, DAY_IN_SECONDS);
        return $category;
    }

    // Remove query parameters and fragment
    $path = isset($parsed_url['path']) ? $parsed_url['path'] : '';

    // Remove leading and trailing slashes
    $request = trim($path, '/');

    // Attempt to get the post ID from the URL
    $post_id = url_to_postid($url);

    if ($post_id) {
        // Get the post type
        $post_type = get_post_type($post_id);

        // Get all registered post types (cache this as well)
        static $all_post_types = null;
        if ($all_post_types === null) {
            $all_post_types = get_post_types(array(), 'objects');
        }

        if (isset($all_post_types[$post_type])) {
            $post_type_object = $all_post_types[$post_type];
            $category = strtoupper($post_type_object->labels->singular_name);
        } else {
            $category = strtoupper($post_type);
        }

        // Cache the result
        $url_cache[$url] = $category;
        set_transient($cache_key, $category, DAY_IN_SECONDS);
        return $category;
    } else {
        global $wp_rewrite;

        // Initialize query variables
        $query_vars = array();

        // Try to match the URL to rewrite rules
        if (!empty($wp_rewrite->wp_rewrite_rules())) {
            // Get rewrite rules (cache this as well)
            static $rewrite_rules = null;
            if ($rewrite_rules === null) {
                $rewrite_rules = $wp_rewrite->wp_rewrite_rules();
            }

            foreach ($rewrite_rules as $match => $query) {
                // If the request matches a rewrite rule
                if (preg_match("#^$match#", $request, $matches)) {
                    // Build the query vars
                    $query = preg_replace("#^.+\?#", '', $query);

                    // Substitute matches into the query
                    $query = addslashes(WP_MatchesMapRegex::apply($query, $matches));

                    parse_str($query, $query_vars);

                    break;
                }
            }
        }

        // If query vars were populated, determine content type
        if (!empty($query_vars)) {
            if (!empty($query_vars['category_name']) || !empty($query_vars['cat'])) {
                $category = 'CATEGORY';
            } elseif (!empty($query_vars['tag']) || !empty($query_vars['tag_id'])) {
                $category = 'TAG';
            } elseif (!empty($query_vars['author_name']) || !empty($query_vars['author'])) {
                $category = 'AUTHOR';
            } elseif (!empty($query_vars['post_type'])) {
                $post_type = $query_vars['post_type'];

                // Handle array of post types
                if (is_array($post_type)) {
                    $post_type = reset($post_type);
                }

                // Get all registered post types (already cached above)
                if (isset($all_post_types[$post_type])) {
                    $post_type_object = $all_post_types[$post_type];
                    $category = strtoupper($post_type_object->labels->singular_name);
                } else {
                    $category = strtoupper($post_type);
                }
            } elseif (!empty($query_vars['year']) || !empty($query_vars['monthnum']) || !empty($query_vars['day'])) {
                $category = 'DATE_ARCHIVE';
            } elseif (!empty($query_vars['s'])) {
                $category = 'SEARCH_RESULTS';
            } else {
                $category = 'UNKNOWN';
            }
        } else {
            // Check for taxonomy terms
            static $taxonomies = null;
            if ($taxonomies === null) {
                $taxonomies = get_taxonomies(array(), 'objects');
            }

            $found = false;
            foreach ($taxonomies as $taxonomy) {
                $taxonomy_slug = isset($taxonomy->rewrite['slug']) ? $taxonomy->rewrite['slug'] : $taxonomy->name;

                if ($taxonomy_slug) {
                    $pattern = '#^' . preg_quote($taxonomy_slug, '#') . '/([^/]+)/?$#';

                    if (preg_match($pattern, $request, $matches)) {
                        $term_slug = $matches[1];
                        $term      = get_term_by('slug', $term_slug, $taxonomy->name);

                        if ($term && !is_wp_error($term)) {
                            $category = strtoupper($taxonomy->labels->singular_name);
                            $found = true;
                            break;
                        }
                    }
                }
            }

            if (!$found) {
                // Check for author archives
                $author_base = $wp_rewrite->author_base ?: 'author';

                $pattern = '#^' . preg_quote($author_base, '#') . '/([^/]+)/?$#';

                if (preg_match($pattern, $request, $matches)) {
                    $author_nicename = $matches[1];
                    $author          = get_user_by('slug', $author_nicename);

                    if ($author) {
                        $category = 'AUTHOR';
                    } else {
                        $category = 'UNKNOWN';
                    }
                } else {
                    // Check for date archives
                    $date_structure = $wp_rewrite->get_date_permastruct();

                    if ($date_structure) {
                        $date_regex = str_replace(
                            array('%year%', '%monthnum%', '%day%'),
                            array('([0-9]{4})', '([0-9]{1,2})', '([0-9]{1,2})'),
                            $date_structure
                        );
                        $date_regex = '!^' . trim($date_regex, '/') . '/?$!';

                        if (preg_match($date_regex, $request)) {
                            $category = 'DATE_ARCHIVE';
                        } else {
                            $category = 'UNKNOWN';
                        }
                    } else {
                        $category = 'UNKNOWN';
                    }
                }
            }
        }

        // Allow customization via filter
        $category = apply_filters('nppp_categorize_url_result', $category, $url);

        // Cache the result
        $url_cache[$url] = $category;
        set_transient($cache_key, $category, DAY_IN_SECONDS);

        return $category;
    }
}
