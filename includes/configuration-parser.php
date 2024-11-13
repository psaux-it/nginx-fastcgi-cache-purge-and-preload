<?php
/**
 * Nginx config parser functions for FastCGI Cache Purge and Preload for Nginx
 * Description: This file contains Nginx config parser functions for FastCGI Cache Purge and Preload for Nginx
 * Version: 2.0.4
 * Author: Hasan ÇALIŞIR
 * Author Email: hasan.calisir@psauxit.com
 * Author URI: https://www.psauxit.com
 * License: GPL-2.0+
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

// Function to execute a shell command and get the output
function nppp_get_command_output($command) {
    return trim(shell_exec($command));
}

// Function to get the latest release from GitHub API using wp_remote_get
function nppp_get_latest_version_git($url) {
    $response = wp_remote_get($url, [
        'timeout'   => 3,
        'httpversion' => '1.1',
        'user-agent' => 'PHP',
    ]);

    if (is_wp_error($response)) {
        return 'Not Determined';
    }

    $body = wp_remote_retrieve_body($response);
    return $body ? json_decode($body, true) : null;
}

// Function to check bindfs version
function nppp_check_bindfs_version() {
    // Ask result in cache first
    $static_key_base = 'nppp';
    $transient_key = 'nppp_bindfs_version_' . md5($static_key_base);
    $cached_result = get_transient($transient_key);

    // Return cached result if available
    if ($cached_result !== false) {
        return $cached_result;
    }

    // Set repo URL
    $bindfs_repo_url = "https://api.github.com/repos/mpartel/bindfs/git/refs/tags";

    // Check if bindfs is installed
    if (nppp_get_command_output('command -v bindfs')) {
        $installed_version = nppp_get_command_output('bindfs --version | head -n1 | awk \'{print $2}\'');

        // Get latest version info from GitHub API
        $response = nppp_get_latest_version_git($bindfs_repo_url);
        if ($response) {
            // Assign array_map result to a variable to avoid passing by reference warning
            $mapped_refs = array_map(function($ref) {
                return preg_replace('/^refs\/tags\//', '', $ref['ref']);
            }, $response);

            $latest_version = end($mapped_refs);

            if (version_compare($installed_version, $latest_version, '<')) {
                $result = "$installed_version ($latest_version)";
            } else {
                $result = "$installed_version";
            }
        } else {
            $result = "Not Determined";
        }
    } else {
        $result = "Not Installed";
    }

    // Store the result in the cache 1 day
    set_transient($transient_key, $result, MONTH_IN_SECONDS);

    return $result;
}

// Function to check libfuse version
function nppp_check_libfuse_version() {
    // Ask result in cache first
    $static_key_base = 'nppp';
    $transient_key = 'nppp_libfuse_version_' . md5($static_key_base);
    $cached_result = get_transient($transient_key);

    // Return cached result if available
    if ($cached_result !== false) {
        return $cached_result;
    }

    // Set repo URL
    $libfuse_repo_url = "https://api.github.com/repos/libfuse/libfuse/releases/latest";

    // Get latest version info from GitHub API
    $response = nppp_get_latest_version_git($libfuse_repo_url);
    $latest_version = $response ? str_replace('fuse-', '', $response['tag_name']) : null;

    if ($latest_version) {
        // Check for FUSE 3
        if (nppp_get_command_output('command -v fusermount3')) {
            $installed_version = preg_replace('/version:\s*/', '', nppp_get_command_output('fusermount3 -V | grep -oP \'version:\s*\K[0-9.]+\''));

            if (version_compare($installed_version, $latest_version, '<')) {
                $result = "$installed_version ($latest_version)";
            } else {
                $result = "$installed_version";
            }
        // Check for FUSE 2
        } elseif (nppp_get_command_output('command -v fusermount')) {
            $installed_version = preg_replace('/version:\s*/', '', nppp_get_command_output('fusermount -V | grep -oP \'version:\s*\K[0-9.]+\''));

            if (version_compare($installed_version, $latest_version, '<')) {
                $result = "$installed_version ($latest_version)";
            } else {
                $result = "$installed_version";
            }
        } else {
            // Neither fusermount nor fusermount3 is found
            $result = "Not Installed";
        }
    } else {
        // Latest version could not be determined
        $result = "Not Determined";
    }

    // Store the result in the cache 1 day
    set_transient($transient_key, $result, MONTH_IN_SECONDS);

    return $result;
}

// Function to parse the Nginx configuration file with included paths
// for Nginx Cache Paths
function nppp_parse_nginx_config($file, $wp_filesystem = null) {
    // Ask result in cache first
    $static_key_base = 'nppp';
    $transient_key = 'nppp_cache_paths_' . md5($static_key_base);

    $cached_result = get_transient($transient_key);
    // Return cached result if available
    if ($cached_result !== false) {
        return $cached_result;
    }

    if (is_null($wp_filesystem)) {
        $wp_filesystem = nppp_initialize_wp_filesystem();

        if ($wp_filesystem === false) {
            nppp_display_admin_notice(
                'error',
                'Failed to initialize the WordPress filesystem. Please file a bug on the plugin support page.'
            );
            return;
        }
    }

    if (!$wp_filesystem->exists($file)) {
        return false;
    }

    $config = $wp_filesystem->get_contents($file);
    $cache_paths = [];
    $included_files = [];

    // Regex to match cache path directives
    preg_match_all('/^\s*(?!#\s*)(proxy_cache_path|fastcgi_cache_path|scgi_cache_path|uwsgi_cache_path)\s+([^;]+);/m', $config, $cache_directives, PREG_SET_ORDER);

    foreach ($cache_directives as $cache_directive) {
        $directive = $cache_directive[1];
        $value = trim(preg_replace('/\s.*$/', '', $cache_directive[2]));
        if (!isset($cache_paths[$directive])) {
            $cache_paths[$directive] = [];
        }
        $cache_paths[$directive][] = $value;
    }

    // Regex to match include directives
    preg_match_all('/^\s*(?!#\s*)include\s+([^;]+);/m', $config, $include_directives, PREG_SET_ORDER);

    foreach ($include_directives as $include_directive) {
        $include_path = trim($include_directive[1]);
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
        $result = nppp_parse_nginx_config($included_file, $wp_filesystem);
        if ($result !== false && isset($result['cache_paths'])) {
            foreach ($result['cache_paths'] as $directive => $paths) {
                if (!isset($cache_paths[$directive])) {
                    $cache_paths[$directive] = [];
                }
                $cache_paths[$directive] = array_merge($cache_paths[$directive], $paths);
            }
        }
    }

    // Return empty if no Nginx cache paths are found
    if (empty($cache_paths)) {
        set_transient('nppp_cache_path_not_found', true, MONTH_IN_SECONDS);
        return ['cache_paths' => []];
    }

    // Store the result in the cache before returning
    set_transient($transient_key, ['cache_paths' => $cache_paths], MONTH_IN_SECONDS);

    // Reset the error transients
    delete_transient('nppp_cache_path_not_found');

    // Return found active Nginx Cache Paths
    return ['cache_paths' => $cache_paths];
}

// Function to get Nginx version, OpenSSL version, and modules
function nppp_get_nginx_info() {
    $output = shell_exec('nginx -V 2>&1');

    // Extract Nginx version
    if (preg_match('/nginx\/([\d.]+)/', $output, $matches)) {
        $nginx_version = $matches[1];
    } else {
        $nginx_version = 'Unknown';
    }

    // Extract OpenSSL version
    if (preg_match('/OpenSSL ([\d.]+)/', $output, $matches)) {
        $openssl_version = $matches[1];
    } else {
        $openssl_version = 'Unknown';
    }

    return [
        'nginx_version' => $nginx_version,
        'openssl_version' => $openssl_version,
    ];
}

// Check if the systemd service file exists
function nppp_is_service_file_exists() {
    // Initialize the WP Filesystem
    $wp_filesystem = nppp_initialize_wp_filesystem();

    // Check if WP Filesystem initialization failed
    if ($wp_filesystem === false) {
        nppp_display_admin_notice(
            'error',
            'Failed to initialize the WordPress filesystem. Please file a bug on the plugin support page.'
        );
        return;
    }

    $systemd_file = '/etc/systemd/system/npp-wordpress.service';
    return $wp_filesystem->exists($systemd_file);
}

// Function to generate HTML output
function nppp_generate_html($cache_paths, $nginx_info, $cache_keys) {
    // Check if the systemd service file exists
    $service_file_exists = nppp_is_service_file_exists();

    ob_start();
    //img url's
    $image_url_bar = plugins_url('/admin/img/bar.png', dirname(__FILE__));
    $image_url_ad = plugins_url('/admin/img/logo_ad.png', dirname(__FILE__));
    ?>
    <header></header>
    <main>
        <section class="nginx-status" style="background-color: mistyrose;">
            <h2>Systemd Service Management</h2>
            <p style="padding-left: 10px; font-weight: 500;">
                In case you used the one-liner automation bash script for the initial setup, you can restart the systemd service here. Restarting the service may help to fix permission issues and keep cache consistency stable. The automation script assigns passwordless sudo permissions to the PHP process owner specifically for managing the npp-wordpress service directly from the frontend.
            </p>
            <button id="nppp-restart-systemd-service-btn" class="button button-primary <?php echo !$service_file_exists ? 'disabled' : ''; ?>" style="margin-left: 10px; margin-bottom: 15px; background-color: #2271b1 !important; color: white !important;">
                Restart Service
            </button>
        </section>
        <section class="nginx-status">
            <h2>NGINX STATUS</h2>
            <table>
                <thead>
                    <tr>
                        <th class="check-header"><span class="dashicons dashicons-admin-generic"></span> Check</th>
                        <th class="status-header"><span class="dashicons dashicons-info"></span> Status</th>
                    </tr>
                </thead>
                <tbody>
                    <!-- Section for Nginx Version -->
                    <tr>
                        <td class="action">Nginx Version</td>
                        <td class="status" id="npppNginxVersion">
                            <?php if ($nginx_info['nginx_version'] === 'Unknown'): ?>
                                <span class="dashicons dashicons-arrow-right-alt" style="color: orange !important; font-size: 20px !important; font-weight: normal !important;"></span>
                                <span style="color: orange;"> <?php echo esc_html($nginx_info['nginx_version']); ?></span>
                            <?php else: ?>
                                <span class="dashicons dashicons-yes" style="font-size: 20px !important; font-weight: normal !important;"></span>
                                <span><?php echo esc_html($nginx_info['nginx_version']); ?></span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <!-- Section for OpenSSL Version -->
                    <tr>
                        <td class="action">OpenSSL Version</td>
                        <td class="status" id="npppOpenSSLVersion">
                            <?php if ($nginx_info['openssl_version'] === 'Unknown'): ?>
                                <span class="dashicons dashicons-arrow-right-alt" style="color: orange !important; font-size: 20px !important; font-weight: normal !important;"></span>
                                <span style="color: orange;"> <?php echo esc_html($nginx_info['openssl_version']); ?></span>
                            <?php else: ?>
                                <span class="dashicons dashicons-yes" style="font-size: 20px !important; font-weight: normal !important;"></span>
                                <span><?php echo esc_html($nginx_info['openssl_version']); ?></span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <!-- Section for Nginx Cache Paths -->
                    <tr>
                        <td class="action">Nginx Cache Paths</td>
                        <td class="status">
                            <?php if (empty($cache_paths) || get_transient('nppp_cache_path_not_found') !== false): ?>
                                <span class="dashicons dashicons-no" style="color: red !important; font-size: 20px !important; font-weight: normal !important;"></span>
                                <span style="color: red; font-size: 13px; font-weight: bold;"> Not Found</span>
                            <?php else: ?>
                                <table class="nginx-config-table">
                                    <tbody>
                                        <?php foreach ($cache_paths as $values): ?>
                                            <?php foreach ($values as $value): ?>
                                                <tr>
                                                    <td><span class="dashicons dashicons-yes" style="color: green; font-size: 20px !important;"></span>&nbsp;<span style="color: teal; font-size: 13px; font-weight: bold;"><?php echo esc_html($value); ?></span></td>
                                                </tr>
                                            <?php endforeach; ?>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <!-- Section for FastCGI Cache Keys -->
                    <tr>
                        <td class="action">
                            FastCGI Cache Keys
                            <?php
                            if (
                                $cache_keys !== 'Not Found' &&
                                $cache_keys !== 'Filesystem Error' &&
                                $cache_keys !== 'Conf Not Found' &&
                                $cache_keys !== 'Key Not Found'
                            ):
                                if ($cache_keys === '$scheme$request_method$host$request_uri'):
                            ?>
                                    <br><span style="font-size: 13px; color: green;">Supported</span>
                                <?php else: ?>
                                    <br><span style="font-size: 13px; color: #f0c36d;">Unsupported</span>
                                <?php endif; ?>
                            <?php endif; ?>
                        </td>
                        <td class="status">
                            <?php if ($cache_keys === 'Not Found'): ?>
                                <span class="dashicons dashicons-no" style="color: red !important; font-size: 20px !important; font-weight: normal !important;"></span>
                                <span style="color: red; font-size: 13px; font-weight: bold;">Not Found</span>
                            <?php elseif ($cache_keys === 'Filesystem Error'): ?>
                                <span class="dashicons dashicons-no" style="color: red !important; font-size: 20px !important; font-weight: normal !important;"></span>
                                <span style="color: red; font-size: 13px; font-weight: bold">System Error</span>
                            <?php elseif ($cache_keys === 'Conf Not Found'): ?>
                                <span class="dashicons dashicons-no" style="color: red !important; font-size: 20px !important; font-weight: normal !important;"></span>
                                <span style="color: red; font-size: 13px; font-weight: bold;">Conf Error</span>
                            <?php elseif ($cache_keys === 'Key Not Found'): ?>
                                <span class="dashicons dashicons-no" style="color: red !important; font-size: 20px !important; font-weight: normal !important;"></span>
                                <span style="color: red; font-size: 13px; font-weight: bold;">Not Found</span>
                            <?php elseif ($cache_keys === '$scheme$request_method$host$request_uri'): ?>
                                <span class="dashicons dashicons-yes" style="color: green !important; font-size: 20px;"></span>
                                <span style="color: teal; font-weight: bold; font-size: 13px;">
                                    <?php $key_no_quotes = trim($cache_keys, '"'); echo esc_html($key_no_quotes); ?>
                                </span>
                            <?php else: ?>
                                <table class="nginx-config-table">
                                    <tbody>
                                        <?php foreach ($cache_keys as $key): ?>
                                            <tr>
                                                <td>
                                                    <span class="dashicons dashicons-warning" style="color: orange; font-size: 18px !important;"></span>
                                                    <span style="color: teal; font-size: 13px; font-weight: bold;">
                                                        <?php
                                                        $key_no_quotes = trim($key, '"');
                                                        echo esc_html($key_no_quotes);
                                                        ?>
                                                    </span>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            <?php endif; ?>
                        </td>
                    </tr>
                </tbody>
            </table>
        </section>
        <section class="nginx-status">
            <h2 style="margin-top: 45px; !important">FUSE STATUS</h2>
            <table>
                <thead>
                    <tr>
                        <th class="check-header"><span class="dashicons dashicons-admin-generic"></span> Check</th>
                        <th class="status-header"><span class="dashicons dashicons-info"></span> Status</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td class="action">libfuse Version</td>
                        <td class="status" id="npppLibfuseVersion">
                            <?php
                            echo esc_html(nppp_check_libfuse_version());
                            ?>
                        </td>
                    </tr>
                    <tr>
                        <td class="action">bindfs Version</td>
                        <td class="status" id="npppBindfsVersion">
                            <?php
                            echo esc_html(nppp_check_bindfs_version());
                            ?>
                        </td>
                    </tr>
                </tbody>
            </table>
        </section>
    </main>
    <div class="nppp-premium-widget">
        <div id="nppp-ad" style="margin-top: 20px; margin-bottom: 0; margin-left: 0; margin-right: 0;">
            <div class="textcenter">
                <a href="https://www.psauxit.com/nginx-fastcgi-cache-purge-preload-for-wordpress/" class="open-nppp-upsell-top" data-pro-ad="sidebar-logo">
                    <img src="<?php echo esc_url($image_url_bar); ?>" alt="Nginx Cache Purge & Preload PRO" title="Nginx Cache Purge & Preload PRO" style="width: 60px !important;">
                </a>
            </div>
            <h3 class="textcenter">Hope you are enjoying NPP! Do you still need assistance with the server side integration? Get our server integration service now and optimize your website's caching performance!</h3>
            <p class="textcenter">
                <a href="https://www.psauxit.com/nginx-fastcgi-cache-purge-preload-for-wordpress/" class="open-nppp-upsell" data-pro-ad="sidebar-logo">
                    <img src="<?php echo esc_url($image_url_ad); ?>" alt="Nginx Cache Purge & Preload PRO" title="Nginx Cache Purge & Preload Pro">
                </a>
            </p>
            <p class="textcenter"><a href="https://www.psauxit.com/nginx-fastcgi-cache-purge-preload-for-wordpress/" class="button button-primary button-large open-nppp-upsell" data-pro-ad="sidebar-button">Get Service</a></p>
        </div>
    </div>
    <?php
    return ob_get_clean();
}

// Handles the AJAX request to restart the systemd service
function nppp_restart_systemd_service() {
    // Define the systemd service name
    $service_name = 'npp-wordpress.service';

    // Check nonce
    if (isset($_POST['_wpnonce'])) {
        $nonce = sanitize_text_field(wp_unslash($_POST['_wpnonce']));
        if (!wp_verify_nonce($nonce, 'nppp-restart-systemd-service')) {
            wp_send_json_error('Nonce verification failed.');
        }
    } else {
        wp_send_json_error('Nonce is missing.');
    }

    // Check user capability
    if (!current_user_can('manage_options')) {
        wp_send_json_error('You do not have permission to access this action.');
    }

    // Get full paths for sudo and systemctl
    $sudo_path = trim(shell_exec('command -v sudo'));
    $systemctl_path = trim(shell_exec('command -v systemctl'));

    if (empty($sudo_path) || empty($systemctl_path)) {
        wp_send_json_error('Required commands sudo or systemctl not found.');
    }

    $output = [];
    $return_var = 0;

    // Construct and execute the restart command
    $restart_command = "echo '' | sudo -S " . escapeshellcmd($systemctl_path) . " restart " . escapeshellcmd($service_name);
    exec($restart_command . ' 2>&1', $output, $return_var);

    // Check if sudo prompted for a password
    if ($return_var === 1 && strpos(implode("\n", $output), 'password') !== false) {
        wp_send_json_error('Sudo password prompt detected. Failed to restart the systemd service.');
    }

    // Check command output and return status
    if ($return_var !== 0) {
        wp_send_json_error('Failed to restart the systemd service. Output: ' . implode("\n", $output));
    }

    // Execute the status command
    $status_command = 'sudo ' . escapeshellcmd($systemctl_path) . ' is-active ' . escapeshellcmd($service_name);
    $status = trim(shell_exec($status_command));

    // Return response based on the service status
    if ($status === 'active') {
        wp_send_json_success('Systemd service restarted and is active.');
    } else {
        wp_send_json_error('Restart completed but the service is not active. Status: ' . $status);
    }
}

// Shortcode function to display the Nginx configuration on status tab
function nppp_nginx_config_shortcode() {
    $wp_filesystem = nppp_initialize_wp_filesystem();

    if ($wp_filesystem === false) {
        return '<p>Failed to initialize filesystem.</p>';
    }

    // Use the nppp_get_nginx_conf_paths function to find Nginx configuration paths
    $conf_paths = nppp_get_nginx_conf_paths($wp_filesystem);

    // Check if Nginx configuration file found
    if (empty($conf_paths)) {
        return '<p>Nginx configuration file not found.</p>';
    }

    // Parse Nginx configuration file for Nginx Cache Paths
    // Check if parsing the configuration file failed
    $config_file = $conf_paths[0];
    $config_data = nppp_parse_nginx_config($config_file, $wp_filesystem);
    if ($config_data === false) {
        return '<p>Failed to parse Nginx configuration file.</p>';
    }

    // Get cache keys from cache as status &advanced tab already parsed them
    $static_key_base = 'nppp';
    $transient_key = 'nppp_cache_keys_' . md5($static_key_base);

    // Attempt to retrieve the cached result
    $cached_result = get_transient($transient_key);

    // Attempt to retrieve the error status
    $error_conf = get_transient('nppp_nginx_conf_not_found');
    $error_parse = get_transient('nppp_cache_keys_not_found');
    $error_wpfilesystem = get_transient('nppp_cache_keys_wpfilesystem_error');

    // Handle return cases
    if ($cached_result === false) {
        // Case 1: Transient not found

        if ($error_wpfilesystem) {
            // Check if there was a filesystem error
            $cache_keys = 'Filesystem Error';
        } elseif ($error_conf) {
            // If no nginx.conf files were found
           $cache_keys = 'Conf Not Found';
        } elseif ($error_parse) {
            // Check if no cache keys were found
           $cache_keys = 'Key Not Found';
        } else {
            $cache_keys = 'Not Found';
        }
    } else {
        // Case 2: Transient found

        // 2.1: Array is empty, return a default fastcgi_cache_key
        if (empty($cached_result['cache_keys'])) {
            $cache_keys = '$scheme$request_method$host$request_uri';
        } else {
            // Case 2.2: Unsupported Cache keys exist
            $cache_keys = $cached_result['cache_keys'];

            // Trim whitespace from all elements in the cache_keys array
            if (is_array($cache_keys)) {
                $cache_keys = array_map('trim', $cache_keys);
            }
        }
    }

    // Get Nginx version, OpenSSL version, and other info
    $nginx_info = nppp_get_nginx_info();

    // Generate HTML output based on parsed data and Nginx info
    return nppp_generate_html($config_data['cache_paths'], $nginx_info, $cache_keys);
}
