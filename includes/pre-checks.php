<?php
/**
 * Pre-checks for FastCGI Cache Purge and Preload for Nginx
 * Description: This pre-check file contains several critical checks for FastCGI Cache Purge and Preload for Nginx
 * Version: 2.1.0
 * Author: Hasan CALISIR
 * Author Email: hasan.calisir@psauxit.com
 * Author URI: https://www.psauxit.com
 * License: GPL-2.0+
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Check if the process is alive
function nppp_is_process_alive($pid) {
    // Validate that $pid is a positive integer
    if (!is_numeric($pid) || $pid <= 0 || intval($pid) != $pid) {
        return false;
    }

    // Get the path to the 'ps' command
    $ps_path = trim(shell_exec('command -v ps'));

    // Escape to avoid shell injection
    $escaped_pid = escapeshellarg($pid);
    $escaped_ps_path = escapeshellarg($ps_path);

    // Check for the process by PID
    exec("$escaped_ps_path aux | grep -w $escaped_pid | grep -v 'grep'", $output);

    // Process running
    if (!empty($output)) {
        return true;
    }

    // Process is not running
    return false;
}

// Tries to determine the nginx.conf path using 'nginx -V'.
// If that fails, falls back to checking common paths.
function nppp_get_nginx_conf_paths($wp_filesystem) {
    $conf_paths = [];

    // Try to get the nginx.conf path using 'nginx -V'
    if (function_exists('exec')) {
        $output = [];
        $return_var = 0;
        exec('nginx -V 2>&1', $output, $return_var);

        if ($return_var === 0) {
            $output_str = implode("\n", $output);

            // Look for '--conf-path=' in the output
            if (preg_match('/--conf-path=(\S+)/', $output_str, $matches)) {
                $conf_path = $matches[1];
                if ($wp_filesystem->exists($conf_path) && $wp_filesystem->is_readable($conf_path)) {
                    $conf_paths[] = $conf_path;
                }
            }
        }
    }

    // If we didn't find the conf path via 'nginx -V', or if the file doesn't exist, check common paths
    if (empty($conf_paths)) {
        $possible_paths = [
            '/etc/nginx/nginx.conf',
            '/usr/local/etc/nginx/nginx.conf',
            '/etc/nginx/conf/nginx.conf',
            '/usr/local/nginx/conf/nginx.conf',
            '/usr/local/etc/nginx/conf/nginx.conf',
            '/usr/local/etc/nginx.conf',
            '/opt/nginx/conf/nginx.conf',
        ];

        foreach ($possible_paths as $path) {
            if ($wp_filesystem->exists($path) && $wp_filesystem->is_readable($path)) {
                $conf_paths[] = $path;
            }
        }
    }

    return $conf_paths;
}

// Parses the Nginx configuration files and extracts fastcgi_cache_key directives.
function nppp_parse_nginx_cache_key() {
    static $parsed_files = [];
    $cache_keys = [];
    $found_keys = 0;

    // Transient caching mechanism
    $static_key_base = 'nppp';
    $transient_key = 'nppp_cache_keys_' . md5($static_key_base);

    // Check for cached result first
    $cached_result = get_transient($transient_key);
    if ($cached_result !== false) {
        // Return the cached result if available
        return $cached_result;
    }

    // Initialize wp_filesystem
    $wp_filesystem = nppp_initialize_wp_filesystem();
    if ($wp_filesystem === false) {
        nppp_display_admin_notice(
            'error',
            __('Failed to initialize the WordPress filesystem. Please file a bug on the plugin support page.', 'fastcgi-cache-purge-and-preload-nginx')
        );
        // Store error state in cache also
        set_transient('nppp_cache_keys_wpfilesystem_error', true, MONTH_IN_SECONDS);
        return false;
    }

    // Get Nginx config file
    $conf_paths = nppp_get_nginx_conf_paths($wp_filesystem);
    if (empty($conf_paths)) {
        // Could not find any nginx.conf files
        // Store error state in cache also
        set_transient('nppp_nginx_conf_not_found', true, MONTH_IN_SECONDS);
        return false;
    }

    foreach ($conf_paths as $conf_path) {
        // Parse the nginx.conf file
        $result = nppp_parse_nginx_cache_key_file($conf_path, $wp_filesystem, $parsed_files);

        if ($result !== false && isset($result['cache_keys'])) {
            $cache_keys = array_merge($cache_keys, $result['cache_keys']);
            // Count the number of keys parsed from this file
            $found_keys += count($result['cache_keys']);
        }
    }

    // If no fastcgi_cache_key directives found
    if ($found_keys === 0) {
        set_transient('nppp_cache_keys_not_found', true, MONTH_IN_SECONDS);
        return ['cache_keys' => ['Not Found']];
    }

    // Supported key format to be removed from array
    $supported_key_format = '$scheme$request_method$host$request_uri';

    // Remove all occurrences of the supported key format and re-index the array
    $cache_keys = array_values(array_filter($cache_keys, function($key) use ($supported_key_format) {
        // Remove surrounding quotes, if any
        $unquoted_value = trim($key, "'\"");

        // Check if the unquoted value matches the supported key format
        return $unquoted_value !== $supported_key_format;
    }));

    // Save the result to transient for future use
    set_transient($transient_key, ['cache_keys' => $cache_keys], MONTH_IN_SECONDS);

    // Reset the error transients
    delete_transient('nppp_cache_keys_wpfilesystem_error');
    delete_transient('nppp_nginx_conf_not_found');
    delete_transient('nppp_cache_keys_not_found');

    // Return only unsupported cache keys
    return ['cache_keys' => $cache_keys];
}

// Helper function to parse individual Nginx configuration files.
function nppp_parse_nginx_cache_key_file($file, $wp_filesystem, &$parsed_files) {
    // Skip symbolic links
    if (in_array(realpath($file), $parsed_files) || is_link($file)) {
        return false;
    }

    // Add the real path of the file to track it
    $parsed_files[] = realpath($file);

    if (!$wp_filesystem->exists($file)) {
        return false;
    }

    // Read the file contents
    $config_content = $wp_filesystem->get_contents($file);
    if ($config_content === false) {
        return false;
    }

    $cache_keys = [];
    $included_files = [];
    $current_dir = dirname($file);

    // Regex to match (proxy,scgi,uwsgi,fastcgi)_cache_key directives
    preg_match_all('/^\s*(?!#)\s*(?:proxy_cache_key|fastcgi_cache_key|scgi_cache_key|uwsgi_cache_key)\s+([\'"]?(?:\s*\$?[^\';"\s]+)+[\'"]?)\s*;/m', $config_content, $cache_key_directives, PREG_SET_ORDER);
    foreach ($cache_key_directives as $cache_key_directive) {
        $value = trim($cache_key_directive[1]);

        // Strip leading and trailing quotes
        $unquoted_value = trim($value, "'\"");
        $cache_keys[] = $unquoted_value;
    }

    // Regex to match include directives
    preg_match_all('/^\s*(?!#\s*)include\s+(.+?);/m', $config_content, $include_directives, PREG_SET_ORDER);
    foreach ($include_directives as $include_directive) {
        $include_paths = preg_split('/\s+/', trim($include_directive[1]));

        foreach ($include_paths as $include_path) {
            $include_path = preg_replace_callback('/\$\{([^}]+)\}/', function($matches) {
                return getenv($matches[1]) ?: $matches[0];
            }, $include_path);

            // Resolve relative paths based on the current directory
            if (!preg_match('/^\//', $include_path)) {
                $include_path = $current_dir . '/' . $include_path;
            }

            // Handle wildcards
            if (strpos($include_path, '*') !== false) {
                $files = glob($include_path);
                if ($files !== false) {
                    $included_files = array_merge($included_files, $files);
                }
            } else {
                $included_files[] = $include_path;
            }
        }
    }

    // Recursively parse included files
    foreach ($included_files as $included_file) {
        $result = nppp_parse_nginx_cache_key_file($included_file, $wp_filesystem, $parsed_files);
        if ($result !== false && isset($result['cache_keys'])) {
            $cache_keys = array_merge($cache_keys, $result['cache_keys']);
        }
    }

    // Return values
    return ['cache_keys' => $cache_keys];
}

// Function to check if plugin critical requirements are met
function nppp_pre_checks_critical() {
    $wp_filesystem = nppp_initialize_wp_filesystem();

    if ($wp_filesystem === false) {
        nppp_display_admin_notice(
            'error',
            __( 'Failed to initialize the WordPress filesystem. Please file a bug on the plugin support page.', 'fastcgi-cache-purge-and-preload-nginx' )
        );
        return;
    }

    // Check if the operating system is Linux and the web server is nginx
    if (!nppp_is_linux()) {
        return __('GLOBAL ERROR OPT: Plugin is not functional on your environment. The plugin requires Linux operating system.', 'fastcgi-cache-purge-and-preload-nginx');
    }

    // Initialize $server_software variable
    $server_software = '';

    // Check SERVER_SOFTWARE
    if (isset($_SERVER['SERVER_SOFTWARE'])) {
        $server_software = sanitize_text_field(wp_unslash($_SERVER['SERVER_SOFTWARE']));
    }

    // If no SERVER_SOFTWARE detected, check response headers
    if (empty($server_software)) {
        // Perform the request
        $response = wp_remote_get(get_site_url());

        // Check if the request was successful
        if (is_array($response) && !is_wp_error($response)) {
            // Get response headers
            $headers = wp_remote_retrieve_headers($response);

            // Check cache headers
            if (isset($headers['x-fastcgi-cache'])) {
                $server_software = 'nginx';
            } elseif (isset($headers['server'])) {
                $server_software = $headers['server'];
            }
        }
    }

    // Check for the SAPI name, not reliable
    if (empty($server_software)) {
        $sapi_name = php_sapi_name();
        if (strpos($sapi_name, 'fpm-fcgi') !== false) {
            $server_software = 'nginx';
        }
    }

    // Lastly fallback the traditional check for edge cases
    if (empty($server_software)) {
        if ($wp_filesystem->exists('/etc/nginx/nginx.conf')) {
            $server_software = 'nginx';
        }
    }

    // Check if the web server is Nginx
    if (stripos($server_software, 'nginx') === false) {
        return __('GLOBAL ERROR SERVER: The plugin is not functional on your environment. It requires an Nginx web server. If this detection is inaccurate, please report a bug.', 'fastcgi-cache-purge-and-preload-nginx');
    }

    // Check if either shell_exec or exec is enabled
    if (function_exists('shell_exec') || function_exists('exec')) {
        // Attempt to execute a harmless command with shell_exec if available
        if (function_exists('shell_exec')) {
            $output = shell_exec('echo "Test"');
            if (trim($output) !== "Test") {
                return __('GLOBAL ERROR EXEC: Plugin is not functional on your environment. The "shell_exec" function is required but not enabled. Please enable it in your server\'s PHP configuration.', 'fastcgi-cache-purge-and-preload-nginx');
            }
        }

        // Fallback: Attempt to execute with exec if shell_exec is not available
        if (function_exists('exec')) {
            $output = exec('echo "Test"');
            if (trim($output) !== "Test") {
                return __('GLOBAL ERROR EXEC: Plugin is not functional on your environment. The "exec" function is required but not enabled. Please enable it in your server\'s PHP configuration.', 'fastcgi-cache-purge-and-preload-nginx');
            }
        }
    } else {
        // If neither shell_exec nor exec are available
        return __('GLOBAL ERROR EXEC: Plugin is not functional on your environment. The "shell_exec" or "exec" functions are required but not enabled. Please enable them in your server\'s PHP configuration.', 'fastcgi-cache-purge-and-preload-nginx');
    }

    // Check if POSIX extension functions are available
    if (!function_exists('posix_kill')) {
        return __('GLOBAL ERROR POSIX: Plugin is not functional on your environment. The PHP POSIX extension is required but not enabled. Please install or enable POSIX on your server.', 'fastcgi-cache-purge-and-preload-nginx');
    }

    // Check shell command requirements for plugin functionality
    if (!nppp_shell_toolset_check(true, false)) {
        // Get the missing commands directly from the transient
        $missing_commands = get_transient('nppp_missing_commands_' . md5('nppp'));
        // If there are missing commands
        if (!empty($missing_commands)) {
            $missing_commands_str = implode(', ', $missing_commands);
            // Translators: %s will be replaced with the list of missing commands
            return sprintf(__('GLOBAL ERROR COMMAND: Plugin is not functional on your environment. The required core shell command(s) not found: %s', 'fastcgi-cache-purge-and-preload-nginx'), $missing_commands_str);
        }
    }

    // Check action specific shell command requirements for Preload
    if (!nppp_shell_toolset_check(false, true)) {
        // Get the missing commands directly from the transient
        $missing_commands = get_transient('nppp_missing_commands_' . md5('nppp'));
        // If there are missing commands
        if (!empty($missing_commands)) {
            $missing_commands_str = implode(', ', $missing_commands);
            // Translators: %s will be replaced with the list of missing commands
            return sprintf(__('GLOBAL ERROR COMMAND: Preload action is not functional on your environment. The required shell command(s) not found: %s', 'fastcgi-cache-purge-and-preload-nginx'), $missing_commands_str);
        }
    }

    // All requirements met
    return true;
}

// Pre-checks and global warnings
function nppp_pre_checks() {
    $wp_filesystem = nppp_initialize_wp_filesystem();

    if ($wp_filesystem === false) {
        nppp_display_admin_notice(
            'error',
            __('Failed to initialize the WordPress filesystem. Please file a bug on the plugin support page.', 'fastcgi-cache-purge-and-preload-nginx')
        );
        return;
    }

    // Optimize performance by caching results of recursive permission checks
    $permission_check_result = nppp_check_permissions_recursive_with_cache();
    $nppp_permissions_check_result = $permission_check_result;

    $nginx_cache_settings = get_option('nginx_cache_settings');
    $default_cache_path = '/dev/shm/change-me-now';
    $nginx_cache_path = isset($nginx_cache_settings['nginx_cache_path']) ? $nginx_cache_settings['nginx_cache_path'] : $default_cache_path;

    // Check if plugin critical requirements are met
    $requirements_met = nppp_pre_checks_critical();
    if ($requirements_met !== true) {
        nppp_display_pre_check_warning($requirements_met);
        return;
    }

    // Check if cache directory exists
    if (!$wp_filesystem->is_dir($nginx_cache_path)) {
        nppp_display_pre_check_warning(__('GLOBAL ERROR PATH: The specified Nginx cache directory is default one or does not exist anymore. Please check your Nginx cache directory.', 'fastcgi-cache-purge-and-preload-nginx'));
        return;
    }

    // Check permissions are sufficient
    if ($nppp_permissions_check_result === 'false') {
        nppp_display_pre_check_warning(__('GLOBAL ERROR PERMISSION: Insufficient permissions for Nginx cache directory. Refer to the "Help" tab for guidance.', 'fastcgi-cache-purge-and-preload-nginx'));
        return;
    }

    // Check cache status
    try {
        $cache_iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($nginx_cache_path, RecursiveDirectoryIterator::SKIP_DOTS),
                RecursiveIteratorIterator::SELF_FIRST
        );

        $has_files = '';
        foreach ($cache_iterator as $file) {
            // Check if the current item is a file
            if ($wp_filesystem->is_file($file->getPathname())) {
                // Read file content
                $file_content = $wp_filesystem->get_contents($file->getPathname());

                // Check if the content contains a line with 'KEY:' (case-sensitive)
                if (preg_match('/^KEY:/m', $file_content)) {
                    $has_files = 'found';
                    break;
                }
            }
        }
    } catch (Exception $e) {
        $has_files = 'error';
    }

    // Warn about empty cache
    if ($has_files !== 'found' && $has_files !== 'error') {
        nppp_display_pre_check_warning(__('GLOBAL WARNING CACHE: The Nginx cache is empty. Consider preloading the Nginx cache now!', 'fastcgi-cache-purge-and-preload-nginx'));
        return;
    }
}

// Handle pre check messages
function nppp_display_pre_check_warning($error_message = '') {
    if (!empty($error_message)) {
        add_action('admin_notices', function() use ($error_message) {
            ?>
            <div class="notice notice-error is-dismissible notice-nppp">
                <p><?php echo esc_html($error_message); ?></p>
            </div>
            <?php
        });
    }
}
