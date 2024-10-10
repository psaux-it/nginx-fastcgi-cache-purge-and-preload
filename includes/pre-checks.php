<?php
/**
 * Pre-checks for FastCGI Cache Purge and Preload for Nginx
 * Description: This pre-check file contains several critical checks for FastCGI Cache Purge and Preload for Nginx
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
                if ($wp_filesystem->exists($conf_path)) {
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
            if ($wp_filesystem->exists($path)) {
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

    $wp_filesystem = nppp_initialize_wp_filesystem();

    if ($wp_filesystem === false) {
        nppp_display_admin_notice(
            'error',
            'Failed to initialize the WordPress filesystem. Please file a bug on the plugin support page.'
        );
        return false;
    }

    $conf_paths = nppp_get_nginx_conf_paths($wp_filesystem);

    if (empty($conf_paths)) {
        // Could not find any nginx.conf files
        return false;
    }

    foreach ($conf_paths as $conf_path) {
        // Parse the nginx.conf file
        $result = nppp_parse_nginx_cache_key_file($conf_path, $wp_filesystem, $parsed_files);

        if ($result !== false && isset($result['cache_keys'])) {
            $cache_keys = array_merge($cache_keys, $result['cache_keys']);
        }
    }

    // Return only found fastcgi_cache_key values
    return ['cache_keys' => $cache_keys];
}


// Helper function to parse individual Nginx configuration files.
function nppp_parse_nginx_cache_key_file($file, $wp_filesystem, &$parsed_files) {
    if (in_array($file, $parsed_files)) {
        return false;
    }
    $parsed_files[] = $file;

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

    // Regex to match fastcgi_cache_key directives
    preg_match_all('/^\s*(?!#)\s*fastcgi_cache_key\s+([^;]+);/m', $config_content, $cache_key_directives, PREG_SET_ORDER);

    foreach ($cache_key_directives as $cache_key_directive) {
        $value = trim($cache_key_directive[1]);

        // Strip leading and trailing quotes
        $unquoted_value = trim($value, "'\"");

        // Check if the unquoted value contains the required sequence in order
        $required_sequence = '\$scheme\s*\$request_method\s*\$host\s*\$request_uri';
        $pattern = '/'. $required_sequence .'/';

        if (!preg_match($pattern, $unquoted_value)) {
            // The cache key does NOT contain the required sequence
            $cache_keys[] = $value;
        }
    }

    // Regex to match include directives
    preg_match_all('/^\s*(?!#)\s*include\s+([^;]+);/m', $config_content, $include_directives, PREG_SET_ORDER);

    foreach ($include_directives as $include_directive) {
        $include_path = trim($include_directive[1]);

        // Resolve variables like ${...}
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

    // Recursively parse included files
    foreach ($included_files as $included_file) {
        $result = nppp_parse_nginx_cache_key_file($included_file, $wp_filesystem, $parsed_files);
        if ($result !== false && isset($result['cache_keys'])) {
            $cache_keys = array_merge($cache_keys, $result['cache_keys']);
        }
    }

    // Return only found fastcgi_cache_key values
    return ['cache_keys' => $cache_keys];
}

// Function to check if plugin critical requirements are met
function nppp_pre_checks_critical() {
    // Check if the operating system is Linux and the web server is nginx
    if (!nppp_is_linux()) {
        return 'GLOBAL ERROR OPT: Plugin is not functional on your environment. The plugin requires Linux operating system.';
    }

    // Initialize $server_software variable
    $server_software = '';

    // Check if $_SERVER['SERVER_SOFTWARE'] is set
    if (isset($_SERVER['SERVER_SOFTWARE'])) {
        // Unslash and sanitize $_SERVER['SERVER_SOFTWARE']
        $server_software = sanitize_text_field(wp_unslash($_SERVER['SERVER_SOFTWARE']));
    }

    // Check for Nginx-specific environment variables
    if (empty($server_software) && isset($_SERVER['NGINX_VERSION'])) {
        $server_software = 'nginx';
    } elseif (empty($server_software) && isset($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        // Likely Nginx acting as a reverse proxy
        $server_software = 'nginx';
    }

    // Check for the SAPI name to detect if Nginx is using PHP-FPM
    if (empty($server_software)) {
        $sapi_name = php_sapi_name();
        if (strpos($sapi_name, 'fpm-fcgi') !== false) {
            // Likely Nginx with PHP-FPM
            $server_software = 'nginx';
        }
    }

    // If still no server software detected, check outgoing headers
    if (empty($server_software)) {
        $headers = headers_list();
        foreach ($headers as $header) {
            if (stripos($header, 'server: nginx') !== false) {
                $server_software = 'nginx';
                break;
            } elseif (stripos($header, 'server: apache') !== false) {
                $server_software = 'apache';
                break;
            }
        }
    }

    // Check if the web server is Nginx
    if (strpos($server_software, 'nginx') === false) {
        return 'GLOBAL ERROR SERVER: Plugin is not functional on your environment. The plugin requires Nginx web server.';
    }

    // Check if shell_exec is enabled
    if (function_exists('shell_exec')) {
        // Attempt to execute a harmless command
        $output = shell_exec('echo "Test"');
        if (trim($output) !== "Test") {
            return 'GLOBAL ERROR SHELL: Plugin is not functional on your environment. The function php shell_exec() is restricted. Please check your server php settings.';
        }
    } else {
        return 'GLOBAL ERROR SHELL: Plugin is not functional on your environment. The function shell_exec() is not enabled. Please check your server php settings.';
    }

    // Check if wget is available
    $output = shell_exec('command -v wget');
    if (empty($output)) {
        return 'GLOBAL ERROR COMMAND: wget is not available. Please ensure "wget" is installed on your server. Preload action is not functional on your environment.';
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
            'Failed to initialize the WordPress filesystem. Please file a bug on the plugin support page.'
        );
        return;
    }

    $nginx_cache_settings = get_option('nginx_cache_settings');
    $default_cache_path = '/dev/shm/change-me-now';
    $nginx_cache_path = isset($nginx_cache_settings['nginx_cache_path']) ? $nginx_cache_settings['nginx_cache_path'] : $default_cache_path;

    // Check if plugin critical requirements are met
    $requirements_met = nppp_pre_checks_critical();
    if ($requirements_met !== true) {
        // Plugin requirements are not met
        nppp_display_pre_check_warning($requirements_met);
        return;
    }

    // Check if cache directory exists if not force to create it
    if (!$wp_filesystem->is_dir($nginx_cache_path)) {
        // Assign necessary variables
        $service_name = 'npp-wordpress.service';
        $service_path = '/etc/systemd/system/' . $service_name;
        $nginx_path = trim(shell_exec('command -v nginx'));
        $sudo_path = trim(shell_exec('command -v sudo'));
        $systemctl_path = trim(shell_exec('command -v systemctl'));

        // Force to create the nginx cache path that if defined in conf already
        // This code block will only run if the plugin's initial setup
        // was done using the following one-liner script:
        // [ bash <(curl -Ss https://psaux-it.github.io/install.sh) ]
        if (function_exists('exec') && function_exists('shell_exec')) {
            if (!empty($nginx_path) && !empty($sudo_path)) {
                // Construct and execute the 'nginx -T' command using 'echo "" | sudo -S' to prevent hang during  password prompt
                $nginx_command = "echo '' | sudo -S " . escapeshellcmd($nginx_path) . " -T > /dev/null 2>&1";
                exec($nginx_command, $output, $return_var);
            }
        }

        // Re-check if directory exists
        if (!$wp_filesystem->is_dir($nginx_cache_path)) {
            // Display error message for non-existent directory
            nppp_display_pre_check_warning('GLOBAL ERROR PATH: The specified Nginx Cache Directory is default one or does not exist anymore. Please check your Nginx Cache Directory.');
            return;
        } else {
            // Restart the npp-wordpress systemd service to apply setfacl to the created Nginx cache path.
            // This code block depends on the npp-wordpress.service and will only run
            // if the plugin's initial setup was done using the following one-liner script:
            // [ bash <(curl -Ss https://psaux-it.github.io/install.sh) ]
            if (!empty($systemctl_path) && !empty($sudo_path)) {
                if ($wp_filesystem->exists($service_path)) {
                    // Construct and execute the restart command
                    $restart_command = "echo '' | sudo -S " . escapeshellcmd($systemctl_path) . " restart " . escapeshellcmd($service_name);
                    exec($restart_command . ' 2>&1', $output, $return_var);
                }
            }
            // Clear recursive permission plugin cache
            $static_key_base = 'nppp';
            $transient_key_permissions_check = 'nppp_permissions_check_' . md5($static_key_base);
            $transients = array($transient_key_permissions_check);
            foreach ($transients as $transient) {
                delete_transient($transient);
            }
            // Add small delay
            usleep(500000);
        }
    }

    // Optimize performance by caching results of recursive permission checks
    $permission_check_result = nppp_check_permissions_recursive_with_cache();
    $nppp_permissions_check_result = $permission_check_result;

    if ($nppp_permissions_check_result === 'false') {
        // Handle the case where permissions are not sufficient
        nppp_display_pre_check_warning('GLOBAL ERROR PERMISSION: Insufficient permissions for Nginx Cache Path. Consult the Help tab for guidance. After making changes, clear the plugin cache in the Status tab to refresh the status.');
        return;
    }

    // Check cache is empty
    $files = $wp_filesystem->dirlist($nginx_cache_path);
    if (empty($files)) {
        nppp_display_pre_check_warning('GLOBAL WARNING CACHE: Cache is empty. For immediate cache creation consider utilizing the preload action now!');
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
