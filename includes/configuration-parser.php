<?php
/**
 * Nginx config parser functions for FastCGI Cache Purge and Preload for Nginx
 * Description: This file contains Nginx config parser functions for FastCGI Cache Purge and Preload for Nginx
 * Version: 2.0.9
 * Author: Hasan CALISIR
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

    // Check if the response has an error
    if (is_wp_error($response)) {
        return 'Not Determined';
    }

    // Get the response body and decode the JSON
    $body = wp_remote_retrieve_body($response);
    $data = $body ? json_decode($body, true) : null;

    // Ensure $data is an array before proceeding
    if (is_array($data)) {
        return $data;
    } else {
        return 'Not Determined';
    }
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

    // Fetch latest version
    $bindfs_repo_url = "https://api.github.com/repos/mpartel/bindfs/git/refs/tags";
    $response = nppp_get_latest_version_git($bindfs_repo_url);

    $latest_version = 'Not Determined';
    if (is_array($response) && !empty($response)) {
        $mapped_response = array_map(function($ref) {
            return isset($ref['ref']) ? preg_replace('/^refs\/tags\//', '', $ref['ref']) : '';
        }, $response);
        // Filter out any empty results after the map
        $mapped_response = array_filter($mapped_response);
        $latest_version = !empty($mapped_response) ? end($mapped_response) : 'Not Determined';
    }

    // Check if bindfs is installed
    if (nppp_get_command_output('command -v bindfs')) {
        $installed_version = nppp_get_command_output('bindfs --version | head -n1 | awk \'{print $2}\'');
    } else {
        $installed_version = null;
    }

    // Decide the result based on install status and API fetch success
    if ($installed_version) {
        if ($latest_version && version_compare($installed_version, $latest_version, '<')) {
            $result = "$installed_version ($latest_version)";
        } else {
            $result = "$installed_version ($latest_version)";
        }
    } else {
        $result = "Not Installed";
    }

    // Store the result in the cache 1 month
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

    // Attempt to fetch the latest version
    $libfuse_repo_url = "https://api.github.com/repos/libfuse/libfuse/releases/latest";
    $response = nppp_get_latest_version_git($libfuse_repo_url);
    $latest_version = is_array($response) && isset($response['tag_name'])
                      ? str_replace('fuse-', '', $response['tag_name'])
                      : 'Not Determined';

    // Check for FUSE 3 or FUSE 2
    if (nppp_get_command_output('command -v fusermount3')) {
        $installed_version = preg_replace('/version:\s*/', '', nppp_get_command_output('fusermount3 -V | grep -oP \'version:\s*\K[0-9.]+\''));
    } elseif (nppp_get_command_output('command -v fusermount')) {
        $installed_version = preg_replace('/version:\s*/', '', nppp_get_command_output('fusermount -V | grep -oP \'version:\s*\K[0-9.]+\''));
    } else {
        $installed_version = null;
    }

    // Decide the result based on API fetch success
    if ($installed_version) {
        if ($latest_version && version_compare($installed_version, $latest_version, '<')) {
            $result = "$installed_version ($latest_version)";
        } else {
            $result = "$installed_version ($latest_version)";
        }
    } else {
        $result = "Not Installed";
    }

    // Store the result in the cache 1 month
    set_transient($transient_key, $result, MONTH_IN_SECONDS);

    return $result;
}

// Function to check nginx cache path fuse mount points
function nppp_check_fuse_cache_paths($cache_paths) {
    // Ask result in cache first
    $static_key_base = 'nppp';
    $transient_key = 'nppp_fuse_paths_' . md5($static_key_base);

    // Return cached result if available
    $cached_result = get_transient($transient_key);
    // Return cached result if available
    if ($cached_result !== false) {
        return $cached_result;
    }

    $fuse_paths = [];

    // Loop through the cache paths to check their mount points
    foreach ($cache_paths as $directive => $paths) {
        foreach ($paths as $path) {
            if (!empty($path)) {
                // Execute a shell command to get the mount point for the given path
                $command = "mount | grep " . escapeshellarg($path);
                $output = shell_exec($command);

                // If a valid output is found, extract the fuse mount point
                if ($output) {
                    // Extract the FUSE mount point
                    if (preg_match('/on\s+([^\s]+)\s+type\s+fuse/', $output, $matches)) {
                        $fuse_paths[] = $matches[1];
                    }
                }
            }
        }
    }

    // If no fuse mount point found return empty array before setting transient
    if (empty($fuse_paths)) {
        set_transient('nppp_fuse_path_not_found', true, MONTH_IN_SECONDS);
        return ['fuse_paths' => []];
    }

    // Store the result in the cache before returning
    set_transient($transient_key, ['fuse_paths' => $fuse_paths], MONTH_IN_SECONDS);

    // Reset the error transients
    delete_transient('nppp_fuse_path_not_found');

    // Return the array of mount points
    return ['fuse_paths' => $fuse_paths];
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

    // Initialize wp_filesystem
    if (is_null($wp_filesystem)) {
        $wp_filesystem = nppp_initialize_wp_filesystem();

        if ($wp_filesystem === false) {
            nppp_display_admin_notice(
                'error',
                __( 'Failed to initialize the WordPress filesystem. Please file a bug on the plugin support page.', 'fastcgi-cache-purge-and-preload-nginx' )
            );
            return;
        }
    }

    // Check nginx.conf found
    if (!$wp_filesystem->exists($file)) {
        return false;
    }

    $config = $wp_filesystem->get_contents($file);
    $cache_paths = [];
    $included_files = [];

    // Regex to match cache path directives
    preg_match_all('/^\s*(?!#\s*)(proxy_cache_path|fastcgi_cache_path|scgi_cache_path|uwsgi_cache_path)\s+([^;]+);/m', $config, $cache_directives, PREG_SET_ORDER);

    foreach ($cache_directives as $cache_directive) {
        if (isset($cache_directive[1]) && isset($cache_directive[2])) {
            $directive = $cache_directive[1];
            $value = trim(preg_replace('/\s.*$/', '', $cache_directive[2]));
            if (!isset($cache_paths[$directive])) {
                $cache_paths[$directive] = [];
            }
            $cache_paths[$directive][] = $value;
        }
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
            __( 'Failed to initialize the WordPress filesystem. Please file a bug on the plugin support page.', 'fastcgi-cache-purge-and-preload-nginx' )
        );
        return;
    }

    $systemd_file = '/etc/systemd/system/npp-wordpress.service';
    return $wp_filesystem->exists($systemd_file);
}

// Function to generate HTML output
function nppp_generate_html($cache_paths, $nginx_info, $cache_keys, $fuse_paths) {
    // Check if the systemd service file exists
    $service_file_exists = nppp_is_service_file_exists();

    ob_start();
    //img url's
    $image_url_ad = plugins_url('/admin/img/logo_ad.png', dirname(__FILE__));
    ?>
    <header></header>
    <main>
        <section class="nginx-status" style="background-color: mistyrose;">
            <h2><?php esc_html_e('Systemd Service Management', 'fastcgi-cache-purge-and-preload-nginx'); ?></h2>
            <p style="padding-left: 10px; font-weight: 500;">
                <?php esc_html_e('In case you used the one-liner automation bash script for the initial setup, you can restart the systemd service here. Restarting the service may help to fix permission issues and keep cache consistency stable. The automation script assigns passwordless sudo permissions to the PHP process owner specifically for managing the npp-wordpress service directly from the frontend.', 'fastcgi-cache-purge-and-preload-nginx'); ?>
            </p>
            <button id="nppp-restart-systemd-service-btn" class="button button-primary <?php echo !$service_file_exists ? 'disabled' : ''; ?>" style="margin-left: 10px; margin-bottom: 15px; background-color: #2271b1 !important; color: white !important;">
                <?php esc_html_e('Restart Service', 'fastcgi-cache-purge-and-preload-nginx'); ?>
            </button>
        </section>
        <section class="nginx-status">
            <h2><?php esc_html_e('NGINX & FUSE STATUS', 'fastcgi-cache-purge-and-preload-nginx'); ?></h2>
            <table>
                <thead>
                    <tr>
                        <th class="check-header"><span class="dashicons dashicons-admin-generic"></span> <?php esc_html_e('Check', 'fastcgi-cache-purge-and-preload-nginx'); ?></th>
                        <th class="status-header"><span class="dashicons dashicons-info"></span> <?php esc_html_e('Status', 'fastcgi-cache-purge-and-preload-nginx'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <!-- Section for Nginx Version -->
                    <tr>
                        <td class="action"><?php esc_html_e('Nginx Version', 'fastcgi-cache-purge-and-preload-nginx'); ?></td>
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
                        <td class="action"><?php esc_html_e('OpenSSL Version', 'fastcgi-cache-purge-and-preload-nginx'); ?></td>
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
                        <td class="action">
                            <?php esc_html_e('Nginx Cache Paths', 'fastcgi-cache-purge-and-preload-nginx'); ?>
                            <?php
                            if (!empty($cache_paths) && get_transient('nppp_cache_path_not_found') === false):
                                $all_supported = true;
                                foreach ($cache_paths as $values) {
                                    foreach ($values as $value) {
                                        $path_parts = explode('/', trim($value, '/'));
                                        if (!(
                                            (isset($path_parts[0]) && $path_parts[0] === 'dev' && isset($path_parts[1])) ||
                                            (isset($path_parts[0]) && $path_parts[0] === 'var' && isset($path_parts[1])) ||
                                            (isset($path_parts[0]) && $path_parts[0] === 'opt' && isset($path_parts[1])) ||
                                            (isset($path_parts[0]) && $path_parts[0] === 'tmp' && isset($path_parts[1]))
                                        )) {
                                           $all_supported = false;
                                           break 2;
                                        }
                                    }
                                }
                                if ($all_supported): ?>
                                    <br><span style="font-size: 13px; color: green;"><?php esc_html_e('All Supported', 'fastcgi-cache-purge-and-preload-nginx'); ?></span>
                                <?php else: ?>
                                    <br><span style="font-size: 13px; color: #f0c36d;"><?php esc_html_e('Found Unsupported', 'fastcgi-cache-purge-and-preload-nginx'); ?></span>
                                <?php endif;
                            endif; ?>
                        </td>
                        <td class="status">
                            <?php if (empty($cache_paths) || get_transient('nppp_cache_path_not_found') !== false): ?>
                                <span class="dashicons dashicons-no" style="color: red !important; font-size: 20px !important; font-weight: normal !important;"></span>
                                <span style="color: red; font-size: 13px; font-weight: bold;"><?php esc_html_e('Not Found', 'fastcgi-cache-purge-and-preload-nginx'); ?></span>
                            <?php else: ?>
                                <table class="nginx-config-table">
                                    <tbody>
                                        <?php foreach ($cache_paths as $values): ?>
                                            <?php foreach ($values as $value): ?>
                                                <tr>
                                                    <?php
                                                    $path_parts = explode('/', trim($value, '/'));
                                                    $is_supported = (
                                                        (isset($path_parts[0]) && $path_parts[0] === 'dev' && isset($path_parts[1])) ||
                                                        (isset($path_parts[0]) && $path_parts[0] === 'var' && isset($path_parts[1])) ||
                                                        (isset($path_parts[0]) && $path_parts[0] === 'opt' && isset($path_parts[1])) ||
                                                        (isset($path_parts[0]) && $path_parts[0] === 'tmp' && isset($path_parts[1]))
                                                    );
                                                    ?>
                                                    <td>
                                                        <?php if ($is_supported): ?>
                                                            <span class="dashicons dashicons-yes" style="color: green; font-size: 20px !important;"></span>
                                                        <?php else: ?>
                                                            <span class="dashicons dashicons-warning" style="color: orange; font-size: 18px !important;"></span>
                                                        <?php endif; ?>
                                                        <span style="color: <?php echo $is_supported ? 'teal' : 'orange'; ?>; font-size: 13px; font-weight: bold;"><?php echo esc_html($value); ?></span>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <!-- Section for Nginx Cache Keys -->
                    <tr>
                        <td class="action">
                            <?php esc_html_e('Nginx Cache Keys', 'fastcgi-cache-purge-and-preload-nginx'); ?>
                            <?php
                            if (
                                $cache_keys !== 'Not Found' &&
                                $cache_keys !== 'Filesystem Error' &&
                                $cache_keys !== 'Conf Not Found' &&
                                $cache_keys !== 'Key Not Found'
                            ):
                                if ($cache_keys === '$scheme$request_method$host$request_uri'):
                            ?>
                                    <br><span style="font-size: 13px; color: green;"><?php esc_html_e('All Supported', 'fastcgi-cache-purge-and-preload-nginx'); ?></span>
                                <?php else: ?>
                                    <br><span style="font-size: 13px; color: #f0c36d;"><?php esc_html_e('Found Unsupported', 'fastcgi-cache-purge-and-preload-nginx'); ?></span>
                                <?php endif; ?>
                            <?php endif; ?>
                        </td>
                        <td class="status">
                            <?php if ($cache_keys === 'Not Found'): ?>
                                <span class="dashicons dashicons-no" style="color: red !important; font-size: 20px !important; font-weight: normal !important;"></span>
                                <span style="color: red; font-size: 13px; font-weight: bold;"><?php esc_html_e('Not Found', 'fastcgi-cache-purge-and-preload-nginx'); ?></span>
                            <?php elseif ($cache_keys === 'Filesystem Error'): ?>
                                <span class="dashicons dashicons-no" style="color: red !important; font-size: 20px !important; font-weight: normal !important;"></span>
                                <span style="color: red; font-size: 13px; font-weight: bold;"><?php esc_html_e('System Error', 'fastcgi-cache-purge-and-preload-nginx'); ?></span>
                            <?php elseif ($cache_keys === 'Conf Not Found'): ?>
                                <span class="dashicons dashicons-no" style="color: red !important; font-size: 20px !important; font-weight: normal !important;"></span>
                                <span style="color: red; font-size: 13px; font-weight: bold;"><?php esc_html_e('Conf Error', 'fastcgi-cache-purge-and-preload-nginx'); ?></span>
                            <?php elseif ($cache_keys === 'Key Not Found'): ?>
                                <span class="dashicons dashicons-no" style="color: red !important; font-size: 20px !important; font-weight: normal !important;"></span>
                                <span style="color: red; font-size: 13px; font-weight: bold;"><?php esc_html_e('Not Found', 'fastcgi-cache-purge-and-preload-nginx'); ?></span>
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
        <!-- Section for FUSE Status -->
        <section class="nginx-status">
            <table>
                <tbody>
                    <tr>
                        <td class="action highlight-metric"><?php esc_html_e('libfuse Version', 'fastcgi-cache-purge-and-preload-nginx'); ?></td>
                        <td class="status highlight-metric" id="npppLibfuseVersion">
                            <?php
                            echo esc_html(nppp_check_libfuse_version());
                            ?>
                        </td>
                    </tr>
                    <tr>
                        <td class="action highlight-metric"><?php esc_html_e('bindfs Version', 'fastcgi-cache-purge-and-preload-nginx'); ?></td>
                        <td class="status highlight-metric" id="npppBindfsVersion">
                            <?php
                            echo esc_html(nppp_check_bindfs_version());
                            ?>
                        </td>
                    </tr>
                    <!-- Section for FUSE Cache Paths -->
                    <tr>
                        <td class="action highlight-metric"><?php esc_html_e('FUSE Mounts', 'fastcgi-cache-purge-and-preload-nginx'); ?><br><span style="font-size: 13px; color: green;"><?php esc_html_e('Nginx Cache Paths', 'fastcgi-cache-purge-and-preload-nginx'); ?></span></td>
                        <td class="status highlight-metric" id="npppFuseMountStatus">
                            <?php if (empty($fuse_paths['fuse_paths']) || get_transient('nppp_cache_path_not_found') !== false): ?>
                                <span class="dashicons dashicons-clock" style="color: orange !important; font-size: 20px !important; font-weight: normal !important;"></span>
                                <span style="color: orange; font-size: 13px; font-weight: bold;"><?php esc_html_e('Not Mounted', 'fastcgi-cache-purge-and-preload-nginx'); ?></span>
                            <?php else: ?>
                                <table class="nginx-config-table">
                                    <tbody>
                                        <?php foreach ($fuse_paths['fuse_paths'] as $fuse_path): ?>
                                            <tr>
                                                <td class="highlight-metric"><span class="dashicons dashicons-yes" style="color: green; font-size: 20px !important;"></span>&nbsp;<span style="color: teal; font-size: 13px; font-weight: bold;"><?php echo esc_html($fuse_path); ?></span></td>
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
    </main>
    <div class="nppp-premium-widget">
        <div id="nppp-ad" style="margin-top: 20px; margin-bottom: 0; margin-left: 0; margin-right: 0;">
            <h3 class="textcenter"><?php esc_html_e('Hope you are enjoying NPP! Do you still need assistance with the server-side integration? Get our server integration service now and optimize your website\'s speed!', 'fastcgi-cache-purge-and-preload-nginx'); ?></h3>
            <p class="textcenter">
                <a href="https://www.psauxit.com/nginx-fastcgi-cache-purge-preload-for-wordpress/" class="open-nppp-upsell" data-pro-ad="sidebar-logo">
                    <img
                        src="<?php echo esc_url($image_url_ad); ?>"
                        alt="<?php echo esc_attr__('Nginx Cache Purge & Preload PRO', 'fastcgi-cache-purge-and-preload-nginx'); ?>"
                        title="<?php echo esc_attr__('Nginx Cache Purge & Preload PRO', 'fastcgi-cache-purge-and-preload-nginx'); ?>"
                        width="90"
                        height="90">
                </a>
            </p>
            <p class="textcenter">
                <a href="https://www.psauxit.com/nginx-fastcgi-cache-purge-preload-for-wordpress/" class="button button-primary button-large open-nppp-upsell" data-pro-ad="sidebar-button"><?php esc_html_e('Get Service', 'fastcgi-cache-purge-and-preload-nginx'); ?></a>
            </p>
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
        nppp_display_admin_notice(
            'error',
            __( 'Failed to initialize the WordPress filesystem. Please file a bug on the plugin support page.', 'fastcgi-cache-purge-and-preload-nginx' )
        );
        return '<p>' . esc_html__('Failed to initialize filesystem.', 'fastcgi-cache-purge-and-preload-nginx') . '</p>';
    }

    // Use the nppp_get_nginx_conf_paths function to find Nginx configuration paths
    $conf_paths = nppp_get_nginx_conf_paths($wp_filesystem);

    // Check if Nginx configuration file found
    if (empty($conf_paths)) {
        return '<p>' . esc_html__('Nginx configuration file not found.', 'fastcgi-cache-purge-and-preload-nginx') . '</p>';
    }

    // Parse Nginx configuration file for Nginx Cache Paths
    // Check if parsing the configuration file failed
    $config_file = $conf_paths[0];
    $config_data = nppp_parse_nginx_config($config_file, $wp_filesystem);
    if ($config_data === false) {
        return '<p>' . esc_html__('Failed to parse Nginx configuration file.', 'fastcgi-cache-purge-and-preload-nginx') . '</p>';
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

    // Get fuse paths if exists
    $fuse_paths = nppp_check_fuse_cache_paths($config_data['cache_paths']);

    // Generate HTML output based on parsed data and Nginx info
    return nppp_generate_html($config_data['cache_paths'], $nginx_info, $cache_keys, $fuse_paths);
}
