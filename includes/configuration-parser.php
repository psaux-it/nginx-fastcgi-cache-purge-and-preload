<?php
/**
 * Nginx configuration parser for Nginx Cache Purge Preload
 * Description: Reads server config fragments to detect cache paths, keys, and runtime settings.
 * Version: 2.1.5
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
    nppp_prepare_request_env(true);
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

// Function to check safexec version
function nppp_check_safexec_version() {
    $transient_key = 'nppp_safexec_version_' . md5('nppp');
    $cached = get_transient($transient_key);
    if ($cached !== false) {
        return $cached;
    }

    $installed_version = 'Unknown';

    // Check if safexec is in PATH
    if (nppp_get_command_output('command -v safexec')) {
        $line = nppp_get_command_output("safexec -v 2>&1 | awk 'NR==1{print \$2}'");
        if (!empty($line)) {
            $installed_version = trim($line);
        }
    }

    set_transient($transient_key, $installed_version, MONTH_IN_SECONDS);
    return $installed_version;
}

// Function to check nginx cache path fuse mount points
function nppp_check_fuse_cache_paths($cache_paths) {
    // Ask result in cache first
    $static_key_base = 'nppp';
    $transient_key = 'nppp_fuse_paths_' . md5($static_key_base);

    // Return cached result if available
    $cached_result = get_transient($transient_key);
    if ($cached_result !== false) {
        return $cached_result;
    }

    // Set env
    nppp_prepare_request_env(true);

    $fuse_paths = [];
    $fuse_map   = [];

    // Parse mount output once
    $mount_output = shell_exec('mount 2>/dev/null') ?? '';
    $mount_lines  = explode("\n", $mount_output);

    // Loop through the cache paths to check their mount points
    foreach ($cache_paths as $directive => $paths) {
        foreach ($paths as $path) {
            if (empty($path)) {
                continue;
            }

            // Anchor the search to " on <path> type fuse" to avoid partial matches
            $source = rtrim($path, '/');

            foreach ($mount_lines as $line) {
                if (preg_match('/^' . preg_quote($source, '/') . ' on (\S+) type fuse/', $line, $matches)) {
                    $fuse_paths[] = $matches[1];
                    $fuse_map[$source] = rtrim($matches[1], '/');
                }
            }
        }
    }

    // If no fuse mount point found return empty array before setting transient
    if (empty($fuse_paths)) {
        set_transient('nppp_fuse_path_not_found', true, MONTH_IN_SECONDS);
        return ['fuse_paths' => [], 'fuse_map' => []];
    }

    // Store the result in the cache before returning
    set_transient($transient_key, ['fuse_paths' => $fuse_paths, 'fuse_map' => $fuse_map], MONTH_IN_SECONDS);

    // Reset the error transients
    delete_transient('nppp_fuse_path_not_found');

    // Return the array of mount points
    return ['fuse_paths' => $fuse_paths, 'fuse_map' => $fuse_map];
}

// Function to parse Nginx cache paths from the configuration file
function nppp_parse_nginx_config($file, $wp_filesystem = null, $is_top_level = true) {
    // Ask result in cache first, but only on the top-level call
    if ($is_top_level) {
        $static_key_base = 'nppp';
        $transient_key = 'nppp_cache_paths_' . md5($static_key_base);

        $cached_result = get_transient($transient_key);
        // Return cached result if available
        if ($cached_result !== false) {
            return $cached_result;
        }
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

    // Track already parsed files to avoid re-processing duplicates
    // Reset on every fresh top-level call — stale entries from a previous
    // call in the same request would otherwise cause all files to be skipped.
    static $parsed_files = [];

    if ($is_top_level) {
        $parsed_files = [];
    }

    $canonical = realpath($file) ?: $file;
    if (in_array($canonical, $parsed_files)) {
        return ['cache_paths' => []];
    }
    $parsed_files[] = $canonical;

    $config = $wp_filesystem->get_contents($file);
    $cache_paths = [];
    $included_files = [];

    // Regex to match cache path directives
    preg_match_all('/^\s*(?!#\s*)(proxy_cache_path|fastcgi_cache_path|scgi_cache_path|uwsgi_cache_path)\s+(\S+)/m', $config, $cache_directives, PREG_SET_ORDER);

    foreach ($cache_directives as $cache_directive) {
        if (isset($cache_directive[1]) && isset($cache_directive[2])) {
            $directive = $cache_directive[1];
            $value = trim($cache_directive[2]);

            // Initialize an array for this directive if not already present
            if (!isset($cache_paths[$directive])) {
                $cache_paths[$directive] = [];
            }

            // Add path to array
            $cache_paths[$directive][] = $value;
        }
    }

    // Regex to match include directives (supports single/multiple files)
    preg_match_all('/^\s*(?!#\s*)include\s+(.+?);/m', $config, $include_directives, PREG_SET_ORDER);

    foreach ($include_directives as $include_directive) {
        // Split the included paths properly
        $include_paths = preg_split('/\s+/', trim($include_directive[1]));

        foreach ($include_paths as $include_path) {
            if ($include_path === '' || !isset($include_path[0])) {
                continue;
            }

            if (strpos($include_path, '*') !== false) {
                // Expand wildcards safely
                $files = glob($include_path, GLOB_BRACE) ?: [];
                $included_files = array_merge($included_files, $files);
            } else {
                // Handle single file includes
                $base_dir = dirname($file);
                $resolved_path = ($include_path[0] === '/') ? $include_path : $base_dir . '/' . $include_path;
                if (file_exists($resolved_path)) {
                    $included_files[] = $resolved_path;
                }
            }
        }
    }

    // Recursively parse included files
    foreach ($included_files as $included_file) {
        $result = nppp_parse_nginx_config($included_file, $wp_filesystem, false);
        if ($result !== false && isset($result['cache_paths'])) {
            foreach ($result['cache_paths'] as $directive => $paths) {
                // Merge new paths into the existing array
                if (!isset($cache_paths[$directive])) {
                    $cache_paths[$directive] = [];
                }

                foreach ($paths as $path) {
                    $cache_paths[$directive][] = $path;
                }
            }
        }
    }

    // Return empty if no Nginx cache paths are found
    if (empty($cache_paths)) {
        set_transient('nppp_cache_path_not_found', true, MONTH_IN_SECONDS);
        return ['cache_paths' => []];
    }

    // Store the result in the cache before returning (only on the top-level call)
    if ($is_top_level) {
        set_transient($transient_key, ['cache_paths' => $cache_paths], MONTH_IN_SECONDS);
        delete_transient('nppp_cache_path_not_found');
    }

    // Return found active Nginx Cache Paths
    return ['cache_paths' => $cache_paths];
}

// Function to get Nginx version, PHP version
function nppp_get_nginx_info() {
    // Set env
    nppp_prepare_request_env(true);

    $nginx_version = 'Unknown';
    $php_version = 'Unknown';

    // Get version directly via nginx binary
    if (shell_exec('command -v nginx')) {
        $output = shell_exec('nginx -V 2>&1');

        // Extract Nginx version
        if (preg_match('/nginx\/([\d.]+)/', $output, $matches)) {
            $nginx_version = $matches[1];
        }
    } else {
        // Fallback: Check SERVER_SOFTWARE for Nginx version
        if (isset($_SERVER['SERVER_SOFTWARE'])) {
            $server_software = sanitize_text_field(wp_unslash($_SERVER['SERVER_SOFTWARE']));

            // Extract Nginx version from SERVER_SOFTWARE
            if (preg_match('/nginx[\s\/]?([\d.]+)/i', $server_software, $matches)) {
                $nginx_version = $matches[1];
            }
        }
    }

    // Get PHP version
    $output = phpversion();
    if (preg_match('/(?:php\s+version:?\s*)?(\d+(?:\.\d+){0,3})/i', $output, $matches)) {
        $php_version = $matches[1];
    }

    // Sanitize output
    if ($nginx_version !== 'Unknown') {
        $nginx_version = preg_replace('/[^0-9.]/', '', $nginx_version);
    }

    if ($php_version !== 'Unknown') {
        $php_version = preg_replace('/[^0-9.]/', '', $php_version);
    }

    return [
        'nginx_version' => $nginx_version,
        'php_version' => $php_version,
    ];
}

// Mirror nppp_validate_path() allowed/blocked rules for display purposes.
// Does NOT check filesystem existence — paths come from nginx.conf, not settings input.
// Must be kept in sync with nppp_validate_path() in settings.php.
function nppp_is_cache_path_display_supported(string $directive, string $value): bool {
    $normalised = rtrim($value, '/');

    // Allowed roots — must match nppp_validate_path() $allowed_roots exactly
    $allowed_roots = ['/dev/shm/', '/tmp/', '/var/', '/cache/'];
    $allowed = false;
    foreach ($allowed_roots as $root) {
        if (strpos($normalised, $root) === 0) {
            $allowed = true;
            break;
        }
    }
    if (!$allowed) {
        return false;
    }

    // Blocked subtrees — must match nppp_validate_path() $blocked_subdirs exactly
    $blocked_subdirs = [
        '/var/log',
        '/var/spool',
        '/var/lib',
        '/var/www',
        '/var/mail',
        '/var/lock',
        '/var/backups',
        '/var/snap',
    ];
    foreach ($blocked_subdirs as $blocked) {
        if ($normalised === $blocked || strpos($normalised, $blocked . '/') === 0) {
            return false;
        }
    }

    // /var/cache root itself is blocked — subdirectories are allowed
    if ($normalised === '/var/cache' || $normalised === '/var/run' || $normalised === '/cache') {
        return false;
    }

    return true;
}

// Function to generate HTML output
function nppp_generate_html($cache_paths, $nginx_info, $cache_keys, $fuse_paths) {
    ob_start();
    //img url's
    $image_url_ad = plugins_url('/admin/img/logo_ad.png', dirname(__FILE__));
    ?>
    <header></header>
    <main>
        <section class="nginx-status" style="background-color: mistyrose;">
            <h2><?php esc_html_e('Clear URL Index', 'fastcgi-cache-purge-and-preload-nginx'); ?></h2>
            <p style="padding-left: 10px; font-weight: 500;">
                <?php esc_html_e('The URL index maps cached URLs to their filesystem paths, enabling fast single-page purges without a full directory scan. Clear it if you suspect stale entries — for example after moving the cache directory, changing the cache key, or if single-page purges are not working correctly. The index rebuilds automatically on the next purge or preload operation.', 'fastcgi-cache-purge-and-preload-nginx'); ?>
            </p>
            <button id="nppp-clear-url-index-btn" class="button button-primary" style="margin-left: 10px; margin-bottom: 15px;">
                <?php esc_html_e('Clear URL Index', 'fastcgi-cache-purge-and-preload-nginx'); ?>
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
                                <span style="color: orange; font-size: 14px; font-weight: bold;">
                                    <?php echo esc_html($nginx_info['nginx_version']); ?>
                                </span>
                            <?php else: ?>
                                <span class="dashicons dashicons-yes" style="font-size: 20px !important; font-weight: normal !important;"></span>
                                <span><?php echo esc_html($nginx_info['nginx_version']); ?></span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <!-- Section for PHP Version -->
                    <tr>
                        <td class="action"><?php esc_html_e('PHP Version', 'fastcgi-cache-purge-and-preload-nginx'); ?></td>
                        <td class="status" id="npppOpenSSLVersion">
                            <?php if ($nginx_info['php_version'] === 'Unknown'): ?>
                                <span class="dashicons dashicons-arrow-right-alt" style="color: orange !important; font-size: 20px !important; font-weight: normal !important;"></span>
                                <span style="color: orange; font-size: 14px; font-weight: bold;">
                                    <?php echo esc_html($nginx_info['php_version']); ?>
                                </span>
                            <?php else: ?>
                                <span class="dashicons dashicons-yes" style="font-size: 20px !important; font-weight: normal !important;"></span>
                                <span><?php echo esc_html($nginx_info['php_version']); ?></span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <!-- Section for safexec Version -->
                    <tr>
                        <td class="action"><?php esc_html_e('safexec Version', 'fastcgi-cache-purge-and-preload-nginx'); ?></td>
                        <td class="status" id="npppSafexecVersion">
                            <?php $safexec_version = nppp_check_safexec_version(); ?>
                            <?php if ($safexec_version === 'Unknown'): ?>
                                <span class="dashicons dashicons-arrow-right-alt" style="color: orange !important; font-size: 20px !important; font-weight: normal !important;"></span>
                                <span style="color: orange; font-size: 14px; font-weight: bold;">
                                    <?php echo esc_html($safexec_version); ?>
                                </span>
                            <?php else: ?>
                                <span class="dashicons dashicons-yes" style="font-size: 20px !important; font-weight: normal !important;"></span>
                                <span><?php echo esc_html($safexec_version); ?></span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <!-- Section for Nginx Cache Paths -->
                    <tr>
                        <td class="action">
                            <?php esc_html_e('Nginx Cache Paths', 'fastcgi-cache-purge-and-preload-nginx'); ?>
                        </td>
                        <td class="status">
                            <?php if (empty($cache_paths) || get_transient('nppp_cache_path_not_found') !== false): ?>
                                <span class="dashicons dashicons-no" style="color: red !important; font-size: 20px !important; font-weight: normal !important;"></span>
                                <span style="color: red; font-size: 13px; font-weight: bold;"><?php esc_html_e('Not Found', 'fastcgi-cache-purge-and-preload-nginx'); ?></span>
                            <?php else: ?>
                                <?php
                                $nppp_active_path = '';
                                $nppp_settings = get_option('nginx_cache_settings', []);
                                if (!empty($nppp_settings['nginx_cache_path'])) {
                                    $nppp_active_path = rtrim($nppp_settings['nginx_cache_path'], '/');
                                }
                                ?>
                                <?php
                                if ($nppp_active_path !== '') {
                                    $nppp_banner_fuse_map = isset($fuse_paths['fuse_map']) ? $fuse_paths['fuse_map'] : [];

                                    $nppp_is_active_proxy = false;
                                    foreach ($cache_paths['proxy_cache_path'] ?? [] as $nppp_p) {
                                        $nppp_pn        = rtrim($nppp_p, '/');
                                        $nppp_fuse_dest = $nppp_banner_fuse_map[$nppp_pn] ?? '';
                                        if ($nppp_pn === $nppp_active_path || $nppp_fuse_dest === $nppp_active_path) {
                                            $nppp_is_active_proxy = true;
                                            break;
                                        }
                                    }

                                    $nppp_is_active_fastcgi = false;
                                    foreach ($cache_paths['fastcgi_cache_path'] ?? [] as $nppp_p) {
                                        $nppp_pn        = rtrim($nppp_p, '/');
                                        $nppp_fuse_dest = $nppp_banner_fuse_map[$nppp_pn] ?? '';
                                        if ($nppp_pn === $nppp_active_path || $nppp_fuse_dest === $nppp_active_path) {
                                            $nppp_is_active_fastcgi = true;
                                            break;
                                        }
                                    }

                                    if ($nppp_is_active_proxy && !$nppp_is_active_fastcgi):
                                ?>
                                <div style="margin-bottom:10px; padding:8px 12px; background:#fff8e1; border-left:4px solid #f0ad4e; border-radius:0;">
                                    <span class="dashicons dashicons-warning" style="color:#e6a817; vertical-align:middle;"></span>
                                    <strong style="color:#7a4f00;"><?php esc_html_e('Reverse-Proxy Cache Detected!', 'fastcgi-cache-purge-and-preload-nginx'); ?></strong><br>
                                    <span style="font-size:13px; color:#5a3800;">
                                        <?php esc_html_e('The plugin supports this setup, but you must verify that your Cache Key Regex option. Incorrect regex will cause purge operations to fail silently.', 'fastcgi-cache-purge-and-preload-nginx'); ?>
                                    </span>
                                </div>
                                <?php
                                    endif;
                                }
                                ?>
                                <?php
                                $nppp_directive_badges = [
                                    'fastcgi_cache_path' => ['FastCGI', '#edf7ed', '#1a6e1a'],
                                    'proxy_cache_path'   => ['Proxy',   '#fdf0ff', '#6a0dad'],
                                    'scgi_cache_path'    => ['SCGI',    '#fff0f0', '#8b0000'],
                                    'uwsgi_cache_path'   => ['uWSGI',   '#f0f4ff', '#1a3a8b'],
                                ];
                                ?>
                                <table class="nginx-config-table">
                                    <tbody>
                                        <?php foreach ($cache_paths as $directive => $values): ?>
                                            <?php foreach ($values as $value): ?>
                                                <tr>
                                                    <?php
                                                    $is_supported = nppp_is_cache_path_display_supported($directive, $value);
                                                    $fuse_map     = isset($fuse_paths['fuse_map']) ? $fuse_paths['fuse_map'] : [];
                                                    $fuse_dest    = isset($fuse_map[rtrim($value, '/')]) ? $fuse_map[rtrim($value, '/')] : '';
                                                    $is_active    = ($nppp_active_path !== '') && (
                                                        rtrim($value, '/') === $nppp_active_path ||
                                                        $fuse_dest === $nppp_active_path
                                                    );
                                                    ?>
                                                    <td>
                                                        <?php if ($is_active || $is_supported): ?>
                                                            <span class="dashicons dashicons-yes" style="color: green; font-size: 20px !important;"></span>
                                                        <?php else: ?>
                                                            <span class="dashicons dashicons-warning" style="color: orange; font-size: 18px !important;"></span>
                                                        <?php endif; ?>
                                                        <span style="color: <?php echo $is_supported ? 'teal' : 'orange'; ?>; font-size: 13px; font-weight: bold;"><?php echo esc_html($value); ?></span>
                                                        <?php if ($is_active): ?>
                                                            <span style="font-size: 12px; font-weight: 500; margin-left: 5px; padding: 2px 7px; border-radius: 4px; background: #e6f1fb; color: #0c447c;"><?php esc_html_e('Active', 'fastcgi-cache-purge-and-preload-nginx'); ?></span>
                                                        <?php else: ?>
                                                            <span style="font-size: 12px; font-weight: 500; margin-left: 5px; padding: 2px 7px; border-radius: 4px; background: #f1efe8; color: #5f5e5a;"><?php esc_html_e('Other vhost', 'fastcgi-cache-purge-and-preload-nginx'); ?></span>
                                                        <?php endif; ?>
                                                        <?php if (!$is_supported): ?>
                                                            <span style="font-size: 12px; font-weight: 500; margin-left: 5px; padding: 2px 7px; border-radius: 4px; background: #faeeda; color: #633806;"><?php esc_html_e('Path Blocked', 'fastcgi-cache-purge-and-preload-nginx'); ?></span>
                                                        <?php endif; ?>
                                                        <?php
                                                        if (isset($nppp_directive_badges[$directive])):
                                                            [$nppp_badge_label, $nppp_badge_bg, $nppp_badge_color] = $nppp_directive_badges[$directive];
                                                        ?>
                                                            <span style="font-size: 12px; font-weight: 600; margin-left: 5px; padding: 2px 7px; border-radius: 4px; background: <?php echo esc_attr($nppp_badge_bg); ?>; color: <?php echo esc_attr($nppp_badge_color); ?>; letter-spacing: 0.3px;"><?php echo esc_html($nppp_badge_label); ?></span>
                                                        <?php endif; ?>
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
            <h3 class="textcenter"><?php esc_html_e('Hope you are enjoying NPP! If it saves you time, consider giving it a star or sponsoring its development.', 'fastcgi-cache-purge-and-preload-nginx'); ?></h3>
            <p class="textcenter">
                <img
                    src="<?php echo esc_url($image_url_ad); ?>"
                    alt="<?php echo esc_attr__('Give a Star', 'fastcgi-cache-purge-and-preload-nginx'); ?>"
                    title="<?php echo esc_attr__('Give a Star or Sponsor NPP', 'fastcgi-cache-purge-and-preload-nginx'); ?>"
                    width="100%"
                    height="auto">
            </p>
            <p class="textcenter">
                <a href="https://wordpress.org/support/plugin/fastcgi-cache-purge-and-preload-nginx/reviews/#new-post" target="_blank" rel="noopener" class="button button-secondary" style="margin-right: 8px;">
                    ⭐ <?php esc_html_e('Give a Star', 'fastcgi-cache-purge-and-preload-nginx'); ?>
                </a>
                <a href="https://github.com/sponsors/psaux-it" target="_blank" rel="noopener" class="button button-secondary">
                    ❤️ <?php esc_html_e('Sponsor', 'fastcgi-cache-purge-and-preload-nginx'); ?>
                </a>
            </p>
        </div>
    </div>
    <?php
    return ob_get_clean();
}

// Shortcode function to display the Nginx configuration on status tab
function nppp_nginx_config_shortcode() {
    // This shortcode reads and outputs nginx configuration file paths from the filesystem.
    // Never render it for non-admins — the output is sensitive server configuration data.
    if (! current_user_can('manage_options')) {
        return '';
    }

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
