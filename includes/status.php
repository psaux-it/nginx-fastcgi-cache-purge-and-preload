<?php
/**
 * Status page for FastCGI Cache Purge and Preload for Nginx
 * Description: This file contains functions which shows information about FastCGI Cache Purge and Preload for Nginx
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

// To optimize performance and prevent redundancy, we use cached recursive permission checks.
// This technique stores the results of time-consuming (expensive) permission verifications for reuse.
// The results are cached for to reduce performance overhead, especially useful when the Nginx cache path is extensive.
function nppp_check_permissions_recursive_with_cache() {
    $nginx_cache_settings = get_option('nginx_cache_settings');
    $default_cache_path = '/dev/shm/change-me-now';
    $nginx_cache_path = isset($nginx_cache_settings['nginx_cache_path']) ? $nginx_cache_settings['nginx_cache_path'] : $default_cache_path;

    $wp_filesystem = nppp_initialize_wp_filesystem();

    if ($wp_filesystem === false) {
        nppp_display_admin_notice(
            'error',
            __('Failed to initialize the WordPress filesystem. Please file a bug on the plugin support page.', 'fastcgi-cache-purge-and-preload-nginx')
        );
        return;
    }

    // Define a static key-based transient
    $static_key_base = 'nppp';
    $transient_key = 'nppp_permissions_check_' . md5($static_key_base);

    // Check for cached result
    $result = get_transient($transient_key);
    if ($result === false) {
        // Perform the expensive recursive permission check
        $result = nppp_check_permissions_recursive($nginx_cache_path);

        // Convert boolean result to string
        $result = $result ? 'true' : 'false';

        // Cache the result for 1 hour
        set_transient($transient_key, $result, MONTH_IN_SECONDS);
    }

    return $result;
}

// Function to clear all transients related to the plugin
function nppp_clear_plugin_cache() {
    global $wpdb;

    // Static key base
    $static_key_base = 'nppp';

    // Transients to clear
    $transients = array(
        'nppp_cache_keys_wpfilesystem_error',
        'nppp_nginx_conf_not_found',
        'nppp_cache_keys_not_found',
        'nppp_cache_path_not_found',
        'nppp_fuse_path_not_found',
        'nppp_cache_keys_' . md5($static_key_base),
        'nppp_bindfs_version_' . md5($static_key_base),
        'nppp_libfuse_version_' . md5($static_key_base),
        'nppp_permissions_check_' . md5($static_key_base),
        'nppp_cache_paths_' . md5($static_key_base),
        'nppp_fuse_paths_' . md5($static_key_base),
        'nppp_webserver_user_' . md5($static_key_base),
        'nppp_est_url_counts_' . md5($static_key_base),
        'nppp_last_preload_time_' . md5($static_key_base),
        'nppp_safexec_version_' . md5($static_key_base),
    );

    // Delete each known transient
    foreach ($transients as $transient) {
        delete_transient($transient);
    }

    // Safe clean up transients directly in DB
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
    $wpdb->query("
        DELETE FROM $wpdb->options
        WHERE option_name LIKE '\\_transient_nppp_category_%'
           OR option_name LIKE '\\_transient_timeout_nppp_category_%'
           OR option_name LIKE '\\_transient_nppp_rate_limit_%'
           OR option_name LIKE '\\_transient_timeout_nppp_rate_limit_%'
    ");

    // Log all transients were cleared successfully
    nppp_display_admin_notice('success', __('SUCCESS: Plugin cache cleared successfully.', 'fastcgi-cache-purge-and-preload-nginx'), true, false);
}

// Check server side action need for cache path permissions.
function nppp_check_perm_in_cache($check_path = false, $check_perm = false, $check_fpm = false) {
    // Define a static key-based transient
    $static_key_base = 'nppp';
    $transient_key = 'nppp_permissions_check_' . md5($static_key_base);

    // Get the cached result and path status
    $result = get_transient($transient_key);

    if ($check_path) {
        $path_status = nppp_check_path();

        if ($path_status !== 'Found') {
            return 'false';
        }
    }

    if ($check_perm) {
        $path_status = nppp_check_path();

        if ($path_status !== 'Found') {
            return 'Not Found';
        }
    }

    if ($check_fpm) {
        $path_status = nppp_check_path();

        if ($path_status !== 'Found') {
            return 'Not Found';
        }
    }

    // Return the permission status from cache
    return $result;
}

// Check required command statuses
function nppp_check_command_status($command) {
    $output = shell_exec("command -v $command");
    return !empty($output) ? 'Installed' : 'Not Installed';
}

// Check preload action status
function nppp_check_preload_status() {
    $wp_filesystem = nppp_initialize_wp_filesystem();

    if ($wp_filesystem === false) {
        nppp_display_admin_notice(
            'error',
            __('Failed to initialize the WordPress filesystem. Please file a bug on the plugin support page.', 'fastcgi-cache-purge-and-preload-nginx')
        );
        return;
    }

    $this_script_path = dirname(plugin_dir_path(__FILE__));
    $PIDFILE = rtrim($this_script_path, '/') . '/cache_preload.pid';

    if ($wp_filesystem->exists($PIDFILE)) {
        $pid = intval(nppp_perform_file_operation($PIDFILE, 'read'));

        if ($pid > 0 && nppp_is_process_alive($pid)) {
            return 'progress';;
        }
    }

    // Check permission status, wget command status and cache path existence
    $cached_result = nppp_check_perm_in_cache();
    $wget_status = nppp_check_command_status('wget');
    $path_status = nppp_check_path();

    if ($cached_result === 'false' || $wget_status !== 'Installed' || $path_status !== 'Found') {
        return 'false';
    }

    return 'true';
}

// Check Nginx Cache Path status
function nppp_check_path() {
    $wp_filesystem = nppp_initialize_wp_filesystem();

    if ($wp_filesystem === false) {
        nppp_display_admin_notice(
            'error',
            __('Failed to initialize the WordPress filesystem. Please file a bug on the plugin support page.', 'fastcgi-cache-purge-and-preload-nginx')
        );
        return;
    }

    $nginx_cache_settings = get_option('nginx_cache_settings');
    $default_cache_path = '/dev/shm/change-me-now';
    $nginx_cache_path = isset($nginx_cache_settings['nginx_cache_path']) ? $nginx_cache_settings['nginx_cache_path'] : $default_cache_path;

     // Check if directory exists
    if (!$wp_filesystem->is_dir($nginx_cache_path)) {
        // Cache Directory does not exist
        return 'Not Found';
    } else {
        return 'Found';
    }
}

// Check if shell_exec is allowed or not, required for plugin
function nppp_shell_exec() {
    // Check if shell_exec is enabled
    if (function_exists('shell_exec')) {
        // Attempt to execute a harmless command
        $output = shell_exec('echo "Test"');

        // Check if the command executed successfully
        // Trim the output to handle any extra whitespace or newlines
        if (trim($output) === "Test") {
            return 'Ok';
        }
    }

    return 'Not Ok';
}

// Function to get the PHP process owner (website-user)
function nppp_get_website_user() {
    $php_process_owner = '';

    // Check if the POSIX extension is available
    if (function_exists('posix_getpwuid') && function_exists('posix_geteuid')) {
        // Get the user ID of the PHP process owner
        $php_process_uid = posix_geteuid();
        $userInfo = posix_getpwuid($php_process_uid);

        // Get the user NAME of the PHP process owner
        if ($userInfo) {
            $php_process_uid = $userInfo['name'];
        } else {
            $php_process_uid = 'Not Determined';
        }

        $php_process_owner = $php_process_uid;
    }

    // If POSIX functions are not available or user information is 'Not Determined',
    // try again to find PHP process owner more directly with help of shell

    if (empty($php_process_owner) || $php_process_owner === 'Not Determined') {
        if (defined('ABSPATH')) {
            $wordpressRoot = ABSPATH;
        } else {
            $wordpressRoot = __DIR__;
        }

        // Get the PHP process owner
        $command = "ls -ld " . escapeshellarg($wordpressRoot . '/index.php') . " | awk '{print $3}'";

        // Execute the shell command
        $process_owner = shell_exec($command);

        // Check the PHP process owner if not empty
        if (!empty($process_owner)) {
            $php_process_owner = trim($process_owner);
        } else {
            $php_process_owner = "Not Determined";
        }
    }

    // Return the PHP process owner
    return $php_process_owner;
}

// Function to get webserver user
function nppp_get_webserver_user() {
    // Ask result in cache first
    $static_key_base = 'nppp';
    $transient_key = 'nppp_webserver_user_' . md5($static_key_base);
    $cached_result = get_transient($transient_key);

    // Return cached result if available
    if ($cached_result !== false) {
        return $cached_result;
    }

    // Initialize wp_filesystem
    $wp_filesystem = nppp_initialize_wp_filesystem();
    if ($wp_filesystem === false) {
        nppp_display_admin_notice(
            'error',
            __('Failed to initialize the WordPress filesystem. Please file a bug on the plugin support page.', 'fastcgi-cache-purge-and-preload-nginx')
        );
        return;
    }

    // Find nginx.conf
    $conf_paths = nppp_get_nginx_conf_paths($wp_filesystem);
    $config_file = !empty($conf_paths) ? $conf_paths[0] : '/etc/nginx/nginx.conf';

    // Check if the config file exists
    if (!$wp_filesystem->exists($config_file)) {
        set_transient($transient_key, "Not Determined", MONTH_IN_SECONDS);
        return "Not Determined";
    }

    // Check the running processes for Nginx
    $nginx_user_process = shell_exec("ps aux | grep -E '[a]pache|[h]ttpd|[_]www|[w]ww-data|[n]ginx' | grep -v 'root' | awk '{print $1}' | sort | uniq");

    // Convert the process output to an array and filter out empty values
    if ($nginx_user_process !== null && $nginx_user_process !== '') {
        $process_users = array_filter(array_unique(array_map('trim', explode("\n", $nginx_user_process))));
    } else {
        $process_users = [];
    }

    // Try to get the user from the Nginx configuration file
    $config_contents = $wp_filesystem->get_contents($config_file);
    if ($config_contents !== false) {
        $lines = explode("\n", $config_contents);

        foreach ($lines as $line) {
            $line = trim($line);

            // Skip empty lines and comment lines
            if ($line === '' || preg_match('/^\s*#/', $line)) {
                continue;
            }

            // Match a user directive line
            if (preg_match('/^\s*user\s+(.+?);/i', $line, $matches)) {
                // Split the content after "user" into words
                $parts = preg_split('/\s+/', trim($matches[1]));

                if (!empty($parts[0])) {
                    $nginx_user_conf = trim($parts[0]);
                    break;
                }
            }
        }
    }

    // If both sources provide a user, check for consistency
    if (!empty($nginx_user_conf) && !empty($process_users)) {
        // Check if the configuration user is among the process users
        if (in_array($nginx_user_conf, $process_users)) {
            set_transient($transient_key, $nginx_user_conf, MONTH_IN_SECONDS);
            return $nginx_user_conf;
        }
    }

    // If only the configuration user is found, return it
    if (!empty($nginx_user_conf)) {
        set_transient($transient_key, $nginx_user_conf, MONTH_IN_SECONDS);
        return $nginx_user_conf;
    }

    // If only the process user is found, return it
    if (!empty($process_users)) {
        $user = reset($process_users);
        set_transient($transient_key, $user, MONTH_IN_SECONDS);
        return $user;
    }

    // If no user is found, return "Not Determined"
    set_transient($transient_key, "Not Determined", MONTH_IN_SECONDS);
    return "Not Determined";
}

// Function to get pages in cache count
function nppp_get_in_cache_page_count() {
    $nginx_cache_settings = get_option('nginx_cache_settings');
    $default_cache_path = '/dev/shm/change-me-now';
    $nginx_cache_path = isset($nginx_cache_settings['nginx_cache_path']) ? $nginx_cache_settings['nginx_cache_path'] : $default_cache_path;

    // Retrieve and decode user-defined cache key regex from the database, with a hardcoded fallback
    $regex = isset($nginx_cache_settings['nginx_cache_key_custom_regex'])
             ? base64_decode($nginx_cache_settings['nginx_cache_key_custom_regex'])
             : nppp_fetch_default_regex_for_cache_key();

    $urls_count = 0;

    // Initialize WordPress filesystem
    $wp_filesystem = nppp_initialize_wp_filesystem();

    if ($wp_filesystem === false) {
        nppp_display_admin_notice(
            'error',
            __('Failed to initialize the WordPress filesystem. Please file a bug on the plugin support page.', 'fastcgi-cache-purge-and-preload-nginx')
        );
        return;
    }

    // Check permission issue in cache
    // Cache path existence to prevent expensive directory traversal
    $cached_result = nppp_check_perm_in_cache(false, false, false);
    $path_status = nppp_check_path();

    // Return 'Not Found' if the cache path not found
    if ($path_status !== 'Found') {
        return 'Not Found';
    }

    // Return 'Undetermined' if the perm in cache returns 'false'
    if ($cached_result === 'false') {
        return 'Undetermined';
    }

    try {
        $cache_iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($nginx_cache_path, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );

        $regex_tested = false;
        foreach ($cache_iterator as $file) {
            if ($wp_filesystem->is_file($file->getPathname())) {
                // Check if the file is readable
                if (!$wp_filesystem->is_readable($file->getPathname())) {
                    return 'Undetermined';
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
                // So here we test cache key regex only once
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
                            return 'RegexError';
                        }
                    } else {
                        return 'RegexError';
                    }
                }

                // Extract URLs using regex
                if (preg_match($regex, $content, $matches)) {
                    $urls_count++;
                }
            }
        }
    } catch (Exception $e) {
        // Return 'Undetermined' if a permission issue occurs
        return 'Undetermined';
    }

    // Return the count of URLs, if no URLs found, return 0
    return $urls_count > 0 ? $urls_count : 0;
}

// Function to check for same Nginx cache path for multiple instance
function nppp_check_duplicate_nginx_cache_paths($file, $wp_filesystem) {
    // Retrieve the cached result from the transient
    $transient_key = 'nppp_cache_paths_' . md5('nppp');
    $cached_result = get_transient($transient_key);

    // Check if cached result exists else parse config
    if ($cached_result === false || empty($cached_result['cache_paths'])) {
        nppp_parse_nginx_config($file, $wp_filesystem);

        // Retrieve again the cached result
        $cached_result = get_transient($transient_key);
    }

    // Extract cache paths from the cached result
    $cache_paths = $cached_result['cache_paths'];

    // Find duplicates
    $unique_paths = [];
    $duplicates = [];

    foreach ($cache_paths as $directive => $paths) {
        foreach ($paths as $path) {
            // Normalize the path
            $normalized_path = rtrim(strtolower($path), '/');

            if (in_array($path, $unique_paths)) {
                $duplicates[] = $path;
            } else {
                $unique_paths[] = $path;
            }
        }
    }

    // Return duplicates
    if (!empty($duplicates)) {
        return $duplicates;
    }

    return false;
}

// Generate HTML for status tab
function nppp_my_status_html() {
    // Initialize wp_filesystem
    $wp_filesystem = nppp_initialize_wp_filesystem();
    if ($wp_filesystem === false) {
        nppp_display_admin_notice(
            'error',
            __('Failed to initialize the WordPress filesystem. Please file a bug on the plugin support page.', 'fastcgi-cache-purge-and-preload-nginx')
        );
        return;
    }

    // Status tab metrics heavily depends nginx.conf file
    // Try to get it first
    $conf_paths = nppp_get_nginx_conf_paths($wp_filesystem);

    // Exit early if unable to find or read the nginx.conf file
    if (empty($conf_paths)) {
        return '<div class="nppp-status-wrap">
                    <p class="nppp-advanced-error-message">' . wp_kses(__('ERROR CONF: Unable to read or locate the <span style="color: #f0c36d;">nginx.conf</span> configuration file!', 'fastcgi-cache-purge-and-preload-nginx'), ['span' => ['style' => []]]) . '</p>
                </div>
                <div style="background-color: #f9edbe; border-left: 6px solid red; padding: 10px; margin-bottom: 15px; max-width: max-content;">
                    <p style="margin: 0; align-items: center;">
                        <span class="dashicons dashicons-warning" style="font-size: 22px; color: #721c24; margin-right: 8px;"></span>' . wp_kses(__('The <strong>nginx.conf</strong> file was not found in the <strong>default paths</strong>. This may indicate a <strong>custom Nginx setup</strong> with a non-standard configuration file location or permission issue. If you still encounter this error, please get help from the plugin support forum!', 'fastcgi-cache-purge-and-preload-nginx'), ['strong' => []]) . '</p>
                    </p>
                </div>';
    }

    $perm_in_cache_status_purge = nppp_check_perm_in_cache(true, false, false);
    $perm_in_cache_status_fpm = nppp_check_perm_in_cache(false, false, true);
    $perm_in_cache_status_perm = nppp_check_perm_in_cache(false, true, false);
    $php_process_owner = nppp_get_website_user();
    $web_server_user = nppp_get_webserver_user();

    // Normalize values to handle potential inconsistencies
    $php_process_owner = trim(strtolower($php_process_owner));
    $web_server_user = trim(strtolower($web_server_user));

    // Check if either user is "Not Determined"
    if ($php_process_owner === 'not determined' || $web_server_user === 'not determined') {
        $nppp_isolation_status = 'Not Determined';
    } else {
        // Compare the two users
        $nppp_isolation_status = ($php_process_owner === $web_server_user)
            ? 'Not Isolated'
            : 'Isolated';
    }

    // Check NGINX FastCGI Cache Key
    $config_data = nppp_parse_nginx_cache_key();

    // Check same Nginx cache path for multiple instance
    $config_file = $conf_paths[0];
    $duplicates = nppp_check_duplicate_nginx_cache_paths($config_file, $wp_filesystem);

    // Warn about not found cache key
    if (isset($config_data['cache_keys']) && $config_data['cache_keys'] === ['Not Found']) {
        echo '<div class="nppp-status-wrap">
                  <p class="nppp-advanced-error-message">' . wp_kses(__('INFO: No <span style="color: #FFDEAD;">_cache_key</span> directive was found.', 'fastcgi-cache-purge-and-preload-nginx'), ['span' => ['style' => []]]) . '</p>
              </div>';
    // Warn about the unsupported cache key
    } elseif (isset($config_data['cache_keys']) && !empty($config_data['cache_keys'])) {
        echo '<div class="nppp-status-wrap">
                  <p class="nppp-advanced-error-message">' . wp_kses(__('INFO: <span style="color: #FFDEAD;">Unsupported</span> cache key found!', 'fastcgi-cache-purge-and-preload-nginx'), ['span' => ['style' => []]]) . '</p>
              </div>';
    }

    // Warn about same Nginx cache path for multiple instance
    if ($duplicates !== false) {
        echo '<div class="nppp-status-wrap">
                  <p class="nppp-advanced-error-message">' . wp_kses(__('INFO: <span style="color: #FFDEAD;">Same</span> Nginx cache path found!', 'fastcgi-cache-purge-and-preload-nginx'), ['span' => ['style' => []]]) . '</p>
              </div>';
    }

    // Details about not found cache key
    if (isset($config_data['cache_keys']) && $config_data['cache_keys'] === ['Not Found']) {
        echo '<div style="background-color: #f9edbe; border-left: 6px solid #f0c36d; padding: 10px; margin-bottom: 15px; max-width: max-content;">
                 <p style="margin: 0; align-items: center;">
                     <span class="dashicons dashicons-warning" style="font-size: 22px; color: #ffba00; margin-right: 8px;"></span>' . wp_kses(__('Please check your <strong>Nginx cache setup</strong> to ensure that the <strong>cache key</strong> directive is defined. If you continue to encounter this error, this may indicate a <strong>parsing error</strong> and can be safely ignored.', 'fastcgi-cache-purge-and-preload-nginx'), ['strong' => []]) . '
                 </p>
             </div>';
    // Details about the unsupported cache key
    } elseif (isset($config_data['cache_keys']) && !empty($config_data['cache_keys'])) {
        echo '<div style="background-color: #f9edbe; border-left: 6px solid #f0c36d; padding: 10px; margin-bottom: 15px; max-width: max-content;">
                 <p style="margin: 0; align-items: center;">
                     <span class="dashicons dashicons-warning" style="font-size: 22px; color: #ffba00; margin-right: 8px;"></span>' . sprintf(
                         /* Translators: %1$s, %2$s, %3$s, %4$s are dynamic strings */
                         wp_kses(__('If <strong>%1$s</strong> indicates <strong>%2$s</strong>, please check the <strong>%3$s</strong> option in the plugin <strong>%4$s</strong> section and try again.', 'fastcgi-cache-purge-and-preload-nginx'), ['strong' => []]),
                         wp_kses(__('Pages In Cache Count', 'fastcgi-cache-purge-and-preload-nginx'), []),
                         wp_kses(__('Regex Error', 'fastcgi-cache-purge-and-preload-nginx'), []),
                         wp_kses(__('Cache Key Regex', 'fastcgi-cache-purge-and-preload-nginx'), []),
                         wp_kses(__('Advanced Options', 'fastcgi-cache-purge-and-preload-nginx'), [])
                     ) . '
                 </p>
             </div>';
    }

    // Details about same Nginx cache path for multiple instance
    if ($duplicates !== false) {
        echo '<div style="background-color: #f9edbe; border-left: 6px solid #f0c36d; padding: 10px; margin-bottom: 15px; max-width: max-content;">
                 <p style="margin: 0; align-items: center;">
                     <span class="dashicons dashicons-warning" style="font-size: 22px; color: #ffba00; margin-right: 8px;"></span>
                     ' . wp_kses(__('Same Nginx cache path may be used for multiple WP instances. Please ensure <strong>unique Nginx cache paths</strong> are configured for each WP instance to avoid conflicts.', 'fastcgi-cache-purge-and-preload-nginx'), ['strong' => []]) . '
                 </p>
             </div>';
    }

    // Format the status string
    $perm_status_message = $perm_in_cache_status_perm === 'true'
        ? 'Granted'
        : ($perm_in_cache_status_perm === 'Not Found'
            ? 'Not Determined'
            : 'Need Action (Check Help)');
    $perm_status_message .= ' (' . esc_html($php_process_owner) . ')';

    ob_start();
    ?>
    <div class="status-and-nginx-info-container">
        <div id="nppp-status-tab" class="container">
            <header></header>
            <main>
                <!-- Clear Plugin Cache Section -->
                <section class="clear-plugin-cache" style="background-color: mistyrose;">
                    <h2><?php esc_html_e('Clear Plugin Cache', 'fastcgi-cache-purge-and-preload-nginx'); ?></h2>
                    <p style="padding-left: 10px; font-weight: 500;">
                        <?php esc_html_e(
                            'To ensure the accuracy of the displayed statuses, please clear the plugin cache. This plugin caches expensive status metrics to enhance performance. However, if you\'re in the testing stage and making frequent changes and re-checking the Status tab, clearing the cache is necessary to view the most up-to-date and accurate status.',
                            'fastcgi-cache-purge-and-preload-nginx'
                        ); ?>
                    </p>
                    <button id="nppp-clear-plugin-cache-btn" class="button button-primary" style="margin-left: 10px; margin-bottom: 15px;">
                        <?php esc_html_e('Clear Plugin Cache', 'fastcgi-cache-purge-and-preload-nginx'); ?>
                    </button>
                </section>

                <!-- Status Summary Section -->
                <section class="status-summary">
                    <h2><?php esc_html_e('Status Summary', 'fastcgi-cache-purge-and-preload-nginx'); ?></h2>
                    <table>
                        <thead>
                            <tr>
                                <th class="check-header"><span class="dashicons dashicons-admin-generic"></span> <?php esc_html_e('Check', 'fastcgi-cache-purge-and-preload-nginx'); ?></th>
                                <th class="status-header"><span class="dashicons dashicons-info"></span> <?php esc_html_e('Status', 'fastcgi-cache-purge-and-preload-nginx'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td class="action">
                                    <div class="action-wrapper"><?php esc_html_e('Server Side Action', 'fastcgi-cache-purge-and-preload-nginx'); ?></div>
                                </td>
                                <td class="status" id="npppphpFpmStatus">
                                    <span class="dashicons"></span>
                                    <span><?php echo esc_html($perm_in_cache_status_fpm); ?></span>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                    <div style="height: 20px;"></div>
                    <table>
                        <thead>
                            <tr>
                                <th class="action-header"><span class="dashicons dashicons-admin-generic"></span> <?php esc_html_e('Action', 'fastcgi-cache-purge-and-preload-nginx'); ?></th>
                                <th class="status-header"><span class="dashicons dashicons-info"></span> <?php esc_html_e('Status', 'fastcgi-cache-purge-and-preload-nginx'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td class="action"><?php esc_html_e('Purge Action', 'fastcgi-cache-purge-and-preload-nginx'); ?></td>
                                <td class="status" id="nppppurgeStatus">
                                    <span class="dashicons"></span>
                                    <span><?php echo esc_html($perm_in_cache_status_purge); ?></span>
                                </td>
                            </tr>
                            <tr>
                                <td class="action"><?php esc_html_e('Preload Action', 'fastcgi-cache-purge-and-preload-nginx'); ?></td>
                                <td class="status" id="nppppreloadStatus">
                                    <span class="dashicons"></span>
                                    <span><?php echo esc_html(nppp_check_preload_status()); ?></span>
                                </td>
                            </tr>
                            <tr id="nppp-preload-progress-row">
                                <td colspan="2">
                                    <!-- Progress Bar Container -->
                                    <div style="width: 100%; height: 20px; background-color: #e5e7eb; margin-top: 10px; overflow: hidden;">
                                        <div id="wpt-bar-inner" style="width: 0%; height: 100%; background-color: #5A9BD5; position: relative; text-align: center; color: white; font-size: 12px; line-height: 20px;">
                                            <span id="wpt-bar-text" style="position: absolute; width: 100%; left: 0;">0%</span>
                                        </div>
                                    </div>

                                    <!-- Progress Status Text -->
                                    <div id="wpt-status" class="nppp-progress-status" style="margin-top: 0px; font-size: 13px; color: #374151;">
                                        Initializing...
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </section>

                <!-- System Checks Section -->
                <section id="nppp-system-checks" class="system-checks">
                    <h2><?php esc_html_e('System Checks', 'fastcgi-cache-purge-and-preload-nginx'); ?></h2>
                    <table>
                        <thead>
                            <tr>
                                <th class="check-header"><span class="dashicons dashicons-admin-generic"></span> <?php esc_html_e('Check', 'fastcgi-cache-purge-and-preload-nginx'); ?></th>
                                <th class="status-header"><span class="dashicons dashicons-info"></span> <?php esc_html_e('Status', 'fastcgi-cache-purge-and-preload-nginx'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td class="check"><?php esc_html_e('PHP Process Owner (Website User)', 'fastcgi-cache-purge-and-preload-nginx'); ?></td>
                                <td class="status" id="npppphpProcessOwner">
                                    <span class="dashicons"></span>
                                    <span><?php echo esc_html($php_process_owner); ?></span>
                                </td>
                            </tr>
                            <tr>
                                <td class="check"><?php esc_html_e('Web Server User (nginx | www-data)', 'fastcgi-cache-purge-and-preload-nginx'); ?></td>
                                <td class="status" id="npppphpWebServer">
                                    <span class="dashicons"></span>
                                    <span><?php echo esc_html($web_server_user); ?></span>
                                </td>
                            </tr>
                            <tr>
                                <td class="check"><?php esc_html_e('Shell Execution (Required)', 'fastcgi-cache-purge-and-preload-nginx'); ?></td>
                                <td class="status" id="npppshellExec">
                                    <span class="dashicons"></span>
                                    <span><?php echo esc_html(nppp_shell_exec()); ?></span>
                                </td>
                            </tr>
                            <tr>
                                <td class="check"><?php esc_html_e('Nginx Cache Path (Required)', 'fastcgi-cache-purge-and-preload-nginx'); ?></td>
                                <td class="status" id="npppcachePath">
                                    <span class="dashicons"></span>
                                    <span><?php echo esc_html(nppp_check_path()); ?></span>
                                </td>
                            </tr>
                            <tr>
                                <td class="check"><?php esc_html_e('Cache Path Permission (Required)', 'fastcgi-cache-purge-and-preload-nginx'); ?></td>
                                <td class="status" id="npppaclStatus">
                                    <span class="dashicons"></span>
                                    <span><?php echo esc_html($perm_status_message); ?></span>
                                </td>
                            </tr>
                            <tr>
                                <td class="check"><?php esc_html_e('Permission Isolation (Optional)', 'fastcgi-cache-purge-and-preload-nginx'); ?></td>
                                <td class="status" id="nppppermIsolation">
                                    <span class="dashicons"></span>
                                    <span><?php echo esc_html($nppp_isolation_status); ?></span>
                                </td>
                            </tr>
                            <tr>
                                <td class="check"><?php esc_html_e('wget (Required command)', 'fastcgi-cache-purge-and-preload-nginx'); ?></td>
                                <td class="status" id="npppwgetStatus">
                                    <span class="dashicons"></span>
                                    <span><?php echo esc_html(nppp_check_command_status('wget')); ?></span>
                                </td>
                            </tr>
                            <tr>
                                <td class="check"><?php esc_html_e('safexec (Recommended command)', 'fastcgi-cache-purge-and-preload-nginx'); ?></td>
                                <td class="status" id="npppsafexecStatus">
                                    <span class="dashicons"></span>
                                    <span><?php echo esc_html(nppp_check_command_status('safexec')); ?></span>
                                </td>
                            </tr>
                            <tr>
                                <td class="check"><?php esc_html_e('cpulimit (Optional command)', 'fastcgi-cache-purge-and-preload-nginx'); ?></td>
                                <td class="status" id="npppcpulimitStatus">
                                    <span class="dashicons"></span>
                                    <span><?php echo esc_html(nppp_check_command_status('cpulimit')); ?></span>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </section>

                <!-- Cache Status Section -->
                <section class="cache-status">
                    <h2><?php esc_html_e('Cache Status', 'fastcgi-cache-purge-and-preload-nginx'); ?></h2>
                    <table>
                        <thead>
                            <tr>
                                <th class="check-header"><span class="dashicons dashicons-admin-generic"></span> <?php esc_html_e('Check', 'fastcgi-cache-purge-and-preload-nginx'); ?></th>
                                <th class="status-header"><span class="dashicons dashicons-info"></span> <?php esc_html_e('Status', 'fastcgi-cache-purge-and-preload-nginx'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td class="check"><?php esc_html_e('Pages In Cache Count', 'fastcgi-cache-purge-and-preload-nginx'); ?></td>
                                <td class="status" id="npppphpPagesInCache">
                                    <span class="dashicons"></span>
                                    <span><?php echo esc_html(nppp_get_in_cache_page_count()); ?></span>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </section>
            </main>
        </div>
        <div id="nppp-nginx-info" class="container">
            <?php echo do_shortcode('[nppp_nginx_config]'); ?>
        </div>
    </div>
    <?php
    return ob_get_clean();
}

// AJAX handler to clear the plugin cache
function nppp_clear_plugin_cache_callback() {
    // Check nonce
    if (isset($_POST['_wpnonce'])) {
        $nonce = sanitize_text_field(wp_unslash($_POST['_wpnonce']));
        if (!wp_verify_nonce($nonce, 'nppp-clear-plugin-cache-action')) {
            wp_die(esc_html__('Nonce verification failed.', 'fastcgi-cache-purge-and-preload-nginx'));
        }
    } else {
        wp_die(esc_html__('Nonce is missing.', 'fastcgi-cache-purge-and-preload-nginx'));
    }

    // Check user capability
    if (!current_user_can('manage_options')) {
        wp_die(esc_html__('You do not have permission to access this page.', 'fastcgi-cache-purge-and-preload-nginx'));
    }

     // Clear the plugin cache
    $message = nppp_clear_plugin_cache();

    // Return success response
    wp_send_json_success($message);
}

// AJAX handler to fetch shortcode content
function nppp_cache_status_callback() {
    // Check nonce
    if (isset($_POST['_wpnonce'])) {
        $nonce = sanitize_text_field(wp_unslash($_POST['_wpnonce']));
        if (!wp_verify_nonce($nonce, 'cache-status')) {
            wp_die(esc_html__('Nonce verification failed.', 'fastcgi-cache-purge-and-preload-nginx'));
        }
    } else {
        wp_die(esc_html__('Nonce is missing.', 'fastcgi-cache-purge-and-preload-nginx'));
    }

    // Check user capability
    if (!current_user_can('manage_options')) {
        wp_die(esc_html__('You do not have permission to access this page.', 'fastcgi-cache-purge-and-preload-nginx'));
    }

    // Call the shortcode function to get HTML content
    $shortcode_content = nppp_my_status_shortcode();

    // Return the generated HTML to AJAX
    if (!empty($shortcode_content)) {
        echo wp_kses_post($shortcode_content);
    } else {
        // Send empty string to AJAX to trigger proper error
        echo '';
    }

    // Properly exit to avoid extra output
    wp_die();
}

// Shortcode to display the Status HTML
function nppp_my_status_shortcode() {
    return nppp_my_status_html();
}
