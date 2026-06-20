<?php
/**
 * Environment pre-checks for Nginx Cache Purge Preload
 * Description: Validates required server, filesystem, and plugin runtime prerequisites.
 * Version: 2.1.7
 * Author: Hasan CALISIR
 * Author Email: hasan.calisir@psauxit.com
 * Author URI: https://www.psauxit.com
 * License: GPL-2.0+
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// OBD safe SUID check for the safexec
if (! function_exists('nppp_safexec_ls_check')) {
    function nppp_safexec_ls_check($path) {
        if (!function_exists('shell_exec') || $path === '') {
            return null;
        }

        // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_shell_exec
        $output = (string) shell_exec('LC_ALL=C ls -lLn -- ' . escapeshellarg($path) . ' 2>/dev/null');

        // Take the first non-empty line.
        $line = '';
        foreach (explode("\n", $output) as $raw) {
            $raw = trim($raw);
            if ($raw !== '') {
                $line = $raw;
                break;
            }
        }
        if ($line === '') {
            return null;
        }

        // Parse with a strict regex that validates the format before extracting values.
        if (!preg_match('/^([-bcdlps?][rwxsStT-]{9})[+.]?\s+\d+\s+(\d+)/', $line, $m)) {
            return null;
        }

        $perm = $m[1];
        $uid  = $m[2];

        // Must be a regular file ('-').
        if ($perm[0] !== '-') {
            return null;
        }

        //   's' — SUID bit set AND execute bit set   (chmod 4755 → -rwsr-xr-x)  ← normal case
        //   'S' — SUID bit set, execute bit NOT set  (chmod 4655 → -rwSr-xr-x)  ← unusual but valid SUID
        //   'x' — execute only, no SUID
        //   '-' — neither execute nor SUID
        $perm_char = $perm[3];

        return [
            'is_root'  => ($uid === '0'),
            'has_suid' => ($perm_char === 's' || $perm_char === 'S'),
        ];
    }
}

// Detect wget compatibility required by NPP.
if (! function_exists('nppp_get_wget_compatibility')) {
    function nppp_get_wget_compatibility(): array {
        $transient_key = 'nppp_wget_compatibility_' . md5('nppp');
        $cached = get_transient($transient_key);

        if (is_array($cached) && array_key_exists('ok', $cached) && array_key_exists('reason', $cached)) {
            return $cached;
        }

        if (!function_exists('shell_exec')) {
            $cached = ['ok' => false, 'reason' => 'missing'];
            set_transient($transient_key, $cached, 300);
            return $cached;
        }

        $binary = trim((string) shell_exec('command -v wget 2>/dev/null'));
        if ($binary === '') {
            $cached = [
                'ok' => false,
                'reason' => 'missing',
            ];
            set_transient($transient_key, $cached, 300);
            return $cached;
        }

        $version_output = (string) shell_exec('wget --version 2>&1');
        $first_line     = trim((string) strtok($version_output, "\n"));

        if (stripos($first_line, 'GNU Wget2') !== false || stripos($version_output, 'GNU Wget2') !== false) {
            $cached = [
                'ok' => false,
                'reason' => 'wget2',
            ];
            set_transient($transient_key, $cached, 300);
            return $cached;
        }

        if (stripos($version_output, 'busybox') !== false || stripos($version_output, 'toybox') !== false) {
            $cached = [
                'ok' => false,
                'reason' => 'busybox_toybox',
            ];
            set_transient($transient_key, $cached, 300);
            return $cached;
        }

        if (stripos($first_line, 'GNU Wget') === false) {
            $cached = [
                'ok' => false,
                'reason' => 'non_gnu',
            ];
            set_transient($transient_key, $cached, 300);
            return $cached;
        }

        $version = '';
        if (preg_match('/GNU\s+Wget\s+([0-9]+(?:\.[0-9]+){1,2})/i', $first_line, $matches)) {
            $version = $matches[1];
        }

        if ($version === '') {
            $cached = [
                'ok' => false,
                'reason' => 'unknown_version',
            ];
            set_transient($transient_key, $cached, 300);
            return $cached;
        }

        if (version_compare($version, '1.16', '<')) {
            $cached = [
                'ok' => false,
                'reason' => 'unsupported_version',
            ];
            set_transient($transient_key, $cached, 300);
            return $cached;
        }

        $cached = [
            'ok' => true,
            'reason' => 'ok',
        ];
        set_transient($transient_key, $cached, 300);

        return $cached;
    }
}

// Nginx detector used by Setup.
if (! function_exists('nppp_precheck_nginx_detected')) {
    function nppp_precheck_nginx_detected(bool $honor_assume = true): bool {
        // Aggregate network/env signals (usable only when $honor_assume === true)
        $signal_hit = false;

        // Zero I/O: $_SERVER['SERVER_SOFTWARE'] (memory read, instant).
        // Reliable for pure-Nginx stacks where the SAPI directly reports the
        // frontend server.
        if (isset($_SERVER['SERVER_SOFTWARE'])) {
            $raw_sw = sanitize_text_field(wp_unslash($_SERVER['SERVER_SOFTWARE']));
            if (
                stripos($raw_sw, 'nginx')     !== false ||
                stripos($raw_sw, 'openresty') !== false ||
                stripos($raw_sw, 'tengine')   !== false
            ) {
                $signal_hit = true;
            }
        }

        // HTTP HEAD probe. Only reached for Docker separate-container setups
        // or exotic proxy topologies.
        if (!$signal_hit) {
            // Perform the request
            $token     = substr(dechex(hrtime(true)), -8);
            $probe_url = add_query_arg(['s' => 'nppp-' . $token, '_nppp' => $token], home_url('/'));
            $response  = wp_remote_head($probe_url, array(
                'timeout'     => 1,
                'redirection' => 0,
                'blocking'    => true,
                'headers'     => array(
                    'Cache-Control' => 'no-cache, no-store, max-age=0',
                    'Pragma'        => 'no-cache',
                    'User-Agent'    => 'NPPP-Precheck/2.1.7',
                ),
            ));

            // Check if the request was successful
            if (is_array($response) && ! is_wp_error($response)) {
                $headers = wp_remote_retrieve_headers($response);

                // Normalize WP header container -> plain array
                if (is_object($headers)) {
                    if (method_exists($headers, 'getAll')) {
                        // Requests v2/v1: preferred API
                        $headers = $headers->getAll();
                    } elseif ($headers instanceof \Traversable) {
                        // Iterable fallback
                        $headers = iterator_to_array($headers);
                    } else {
                        // Defensive: cast and peel typical 'data' payload if present
                        $maybe = (array) $headers;
                        $headers = (isset($maybe['data']) && is_array($maybe['data'])) ? $maybe['data'] : $maybe;
                    }
                } else {
                    $headers = (array) $headers;
                }

                // Case-normalize keys for consistent lookups
                if (!empty($headers)) {
                    $headers = array_change_key_case($headers, CASE_LOWER);
                }

                // Signal A — any header *name* containing 'fastcgi'.
                // Strongest signal: survives even when Server header is stripped.
                // Specific to Nginx+PHP-FPM (not Nginx+Apache proxy).
                foreach ($headers as $k => $v) {
                    // header value can be string|array; we only care about the *key* here
                    if (is_string($k) && stripos($k, 'fastcgi') !== false) {
                        $signal_hit = true;
                    }
                }

                // Signal B — 'Server' header value.
                // In Nginx+Apache proxy setups nginx overrides the upstream Server
                // header by default, so 'Apache' never reaches the client here.
                if (isset($headers['server'])) {
                    $sv = is_array($headers['server']) ? implode(' ', array_map('strval', $headers['server'])) : (string) $headers['server'];
                    if ($sv !== '' && (stripos($sv, 'nginx') !== false || stripos($sv, 'openresty') !== false || stripos($sv, 'tengine') !== false)) {
                        $signal_hit = true;
                    }
                }

                // Signal C — 'Via' header.
                // Some proxy topologies surface nginx identity here rather than
                // in the Server header (e.g. Varnish-fronted nginx).
                if (isset($headers['via'])) {
                    $via = is_array($headers['via']) ? implode(' ', array_map('strval', $headers['via'])) : (string) $headers['via'];
                    if ($via !== '' && (stripos($via, 'nginx') !== false || stripos($via, 'openresty') !== false || stripos($via, 'tengine') !== false)) {
                        $signal_hit = true;
                    }
                }
            }
        }

        // PHP_SAPI (zero I/O, weakest heuristic, absolute last resort).
        // 'fpm-fcgi' / 'cgi-fcgi' almost always means PHP-FPM paired with Nginx,
        // but Apache+mod_fcgid also uses 'cgi-fcgi', making this non-conclusive.
        // Intentionally placed last — it is only a tie-breaker when every
        // stronger signal above has failed.
        if (!$signal_hit) {
            $sapi = PHP_SAPI;
            if (stripos($sapi, 'fpm-fcgi') !== false || stripos($sapi, 'cgi-fcgi') !== false) {
                $signal_hit = true;
            }
        }

        // Expose signals result for the Setup UI
        $GLOBALS['NPPP__LAST_SIGNAL_HIT'] = (bool) $signal_hit;

        // Honor "assume Nginx" (constant or runtime option)
        if ($honor_assume && (
            (defined('NPPP_ASSUME_NGINX') && NPPP_ASSUME_NGINX === true)
            || (bool) get_option('nppp_assume_nginx_runtime')
        )) {
            return true;
        }

        // Filesystem hint FIRST (authoritative for "strict")
        if (function_exists('nppp_initialize_wp_filesystem')) {
            $fs = nppp_initialize_wp_filesystem();
            if ($fs && function_exists('nppp_get_nginx_conf_paths')) {
                $paths = nppp_get_nginx_conf_paths($fs, $honor_assume);
                // In strict mode ($honor_assume=false)
                if (!empty($paths)) {
                    return true;
                }
            }
        }

        // Only allow signal-based detection when we *honor assume*.
        // In strict mode, signals are ignored so we don't auto-disable Assume-Nginx
        // unless a real nginx.conf was actually found.
        if ($honor_assume && $signal_hit) {
            return true;
        }

        return false;
    }
}

// Check if the process is alive
function nppp_is_process_alive($pid) {
    // Set env
    nppp_prepare_request_env(true);

    // Validate that $pid is a positive integer
    if (!is_numeric($pid) || $pid <= 0 || intval($pid) != $pid) {
        return false;
    }

    // Guard: both shell_exec and exec must be available
    if (!function_exists('shell_exec') || !function_exists('exec')) {
        return false;
    }

    // Get the path
    $ps_path = trim((string) shell_exec('command -v ps'));
    if (empty($ps_path)) {
        return false;
    }
    $escaped_ps_path = escapeshellarg($ps_path);

    // Check for the process by PID
    exec($escaped_ps_path . ' -p ' . (int) $pid . ' -o pid=', $output, $return_var);

    // Process running
    return $return_var === 0 && !empty($output);
}

// Tries to determine the nginx.conf path using 'nginx -V'.
// If that fails, falls back to checking common paths.
function nppp_get_nginx_conf_paths($wp_filesystem, bool $honor_assume = true) {
    // Set env
    nppp_prepare_request_env(true);

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
            '/www/server/nginx/conf/nginx.conf',
            '/etc/nginx/conf.d/ea-nginx.conf',
            '/usr/local/openresty/nginx/conf/nginx.conf',
        ];

        foreach ($possible_paths as $path) {
            if ($wp_filesystem->exists($path) && $wp_filesystem->is_readable($path)) {
                $conf_paths[] = $path;
            }
        }
    }

    // Only consider the Assume-Nginx dummy when explicitly honoring assume mode
    if ($honor_assume && empty($conf_paths)) {
        $assume_on = (defined('NPPP_ASSUME_NGINX') && NPPP_ASSUME_NGINX === true)
                  || (bool) get_option('nppp_assume_nginx_runtime');

        if ($assume_on) {
            // Shipped dummy file
            $dummy = dirname(plugin_dir_path(__FILE__)) . '/dummy-nginx.conf';

            if ($wp_filesystem->exists($dummy) && $wp_filesystem->is_readable($dummy)) {
                $conf_paths[] = $dummy;
            } else {
                // Last-ditch writable fallback: create a minimal dummy in uploads.
                $uploads = wp_upload_dir();
                if (!empty($uploads['basedir'])) {
                    $target = trailingslashit($uploads['basedir']) . 'nppp-dummy-nginx.conf';
                    $fallback = "# nppp dummy\n"
                        . "events {}\n"
                        . "http { fastcgi_cache_path /dev/shm/nppp levels=1:2 keys_zone=nppp:10m; }\n";
                    if ($wp_filesystem->put_contents($target, $fallback, FS_CHMOD_FILE)) {
                        if ($wp_filesystem->exists($target) && $wp_filesystem->is_readable($target)) {
                            $conf_paths[] = $target;
                        }
                    }
                }
            }
        }
    }

    // Remove duplicates
    $conf_paths = array_unique($conf_paths);

    return $conf_paths;
}

// Parses the Nginx configuration files and extracts fastcgi_cache_key directives.
function nppp_parse_nginx_cache_key() {
    static $parsed_files = [];
    $parsed_files = [];
    $cache_keys = [];
    $found_keys = 0;

    // Transient caching mechanism
    $static_key_base = 'nppp';
    $transient_key = 'nppp_cache_keys_' . md5($static_key_base);

    // Check for cached result first.
    // Require matched_keys key to be present — absent on pre-patch transients;
    // if missing the transient is stale and we fall through to re-parse.
    $cached_result = get_transient($transient_key);
    if ($cached_result !== false && array_key_exists('matched_keys', $cached_result)) {
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
        set_transient('nppp_cache_keys_wpfilesystem_error', true, 5 * MINUTE_IN_SECONDS);
        return false;
    }

    // Get Nginx config file
    $conf_paths = nppp_get_nginx_conf_paths($wp_filesystem);
    if (empty($conf_paths)) {
        // Could not find any nginx.conf files
        // Store error state in cache also
        set_transient('nppp_nginx_conf_not_found', true, 5 * MINUTE_IN_SECONDS);
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
        set_transient('nppp_cache_keys_not_found', true, 5 * MINUTE_IN_SECONDS);
        return ['cache_keys' => ['Not Found']];
    }

    // All natively supported cache key formats.
    $supported_key_formats = [
        '$scheme$request_method$host$request_uri',  // fastcgi_cache — most common WP/FPM stack
        '$scheme$proxy_host$request_uri',           // proxy_cache nginx default (no request method)
        '$scheme$host$request_uri',                 // scheme+host variant (no method, no proxy_host)
        '$scheme://$host$request_uri',              // scheme with protocol separator (common in proxy_cache setups)
        '$host$request_uri',                        // host-only variant (no scheme, minimal key)
        '$host$uri$is_args$args',                   // host+uri with query string args (WooCommerce / dynamic pages)
    ];

    // Partition keys into matched (supported) and unsupported buckets.
    $matched_keys = [];
    $cache_keys = array_values(array_filter($cache_keys, function($key) use ($supported_key_formats, &$matched_keys) {
        $unquoted_value = trim($key, "'\"");
        if (in_array($unquoted_value, $supported_key_formats, true)) {
            $matched_keys[] = $unquoted_value;
            return false;
        }
        return true;
    }));

    // Deduplicate matched keys — same format on multiple vhosts counts once for display.
    $matched_keys = array_values(array_unique($matched_keys));

    // Save both buckets to transient for Status/Advanced tab display.
    set_transient($transient_key, ['cache_keys' => $cache_keys, 'matched_keys' => $matched_keys], HOUR_IN_SECONDS);

    // Reset the error transients
    delete_transient('nppp_cache_keys_wpfilesystem_error');
    delete_transient('nppp_nginx_conf_not_found');
    delete_transient('nppp_cache_keys_not_found');

    // Return both buckets; callers consume what they need.
    return ['cache_keys' => $cache_keys, 'matched_keys' => $matched_keys];
}

// Helper function to parse individual Nginx configuration files.
function nppp_parse_nginx_cache_key_file($file, $wp_filesystem, &$parsed_files) {
    // Skip symbolic links
    $realPath = realpath($file) ?: $file;
    if (in_array($realPath, $parsed_files)) {
        return false;
    }
    $parsed_files[] = $realPath;

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

/**
 * Detect aaPanel environment, for open_basedir required paths forwarding
 * 'bt' is aaPanel's exclusive CLI tool — nothing else installs it.
 * /etc/init.d/bt covers edge cases where bt was removed from PATH.
 */
function nppp_is_aapanel(): bool {
    $wp_filesystem = nppp_initialize_wp_filesystem();

    // WP_Filesystem path (preferred)
    if ( $wp_filesystem && $wp_filesystem->exists( '/etc/init.d/bt' ) ) {
        return true;
    }
    if ( $wp_filesystem && $wp_filesystem->exists( '/usr/bin/bt' ) ) {
        return true;
    }

    // Guard
    if ( ! function_exists( 'shell_exec' ) ) {
        return false;
    }

    // Shell path — also serves as fallback when WP_Filesystem failed to init
    $bt_bin = trim( (string) shell_exec( 'command -v bt 2>/dev/null' ) );
    if ( $bt_bin !== '' ) {
        return true;
    }

    $bt_init = trim( (string) shell_exec( 'test -f /etc/init.d/bt && echo 1' ) );
    return $bt_init === '1';
}

/**
 * Parses open_basedir into a normalised path array.
 * Returns [] when OBD is inactive or set to "none".
 */
function nppp_open_basedir_paths(): array {
    $raw = trim( (string) ini_get( 'open_basedir' ) );
    if ( $raw === '' || strtolower( $raw ) === 'none' ) {
        return [];
    }
    return array_values( array_filter(
        array_map( 'trim', explode( PATH_SEPARATOR, $raw ) ),
        static fn( $p ) => $p !== '' && $p !== '.'
    ) );
}

/**
 * Returns true when open_basedir is active.
 */
function nppp_is_open_basedir_active(): bool {
    return ! empty( nppp_open_basedir_paths() );
}

/**
 * Tests whether $path is reachable under at least one open_basedir entry
 * using the same prefix-walk PHP performs internally.
 */
function nppp_obd_path_covered( string $path, array $obd_paths ): bool {
    if ( $path === '' || empty( $obd_paths ) ) {
        return false;
    }
    $norm = rtrim( $path, '/' );
    foreach ( $obd_paths as $entry ) {
        $entry = rtrim( (string) $entry, '/' );
        if ( $entry === '' ) {
            continue;
        }
        if ( $norm === $entry || strpos( $norm . '/', $entry . '/' ) === 0 ) {
            return true;
        }
    }
    return false;
}

/**
 * Master OBD compatibility check for NPP.
 *
 * Only warn when OBD is active AND at least one PHP-level file I/O path is
 * uncovered.
 */
function nppp_open_basedir_compat_check(): array {
    $result = [ 'active' => false, 'compatible' => true, 'missing' => [] ];

    $obd = nppp_open_basedir_paths();
    if ( empty( $obd ) ) {
        return $result;
    }
    $result['active'] = true;

    $options    = get_option( 'nginx_cache_settings', [] );
    $cache_path = isset( $options['nginx_cache_path'] ) ? (string) $options['nginx_cache_path'] : '';
    $uploads    = wp_upload_dir();

    // PHP file I/O paths NPP actually reads/writes directly.
    $required = [];

    if ( defined( 'ABSPATH' ) ) {
        $required[rtrim( ABSPATH, '/' )] = rtrim( ABSPATH, '/' );
        $parent = dirname( rtrim( ABSPATH, '/' ) );

        // In case in parent we need write access for assume nginx mode
        if ( $parent !== rtrim( ABSPATH, '/' ) && @file_exists( $parent . '/wp-config.php' ) ) {
            $required[$parent] = $parent;
        }
    }
    if ( defined( 'WP_CONTENT_DIR' ) ) {
        $required[WP_CONTENT_DIR] = WP_CONTENT_DIR;
    }
    if ( ! empty( $uploads['basedir'] ) ) {
        $required[(string) $uploads['basedir']] = (string) $uploads['basedir'];
    }
    if ( $cache_path === '' || $cache_path === '/dev/shm/change-me-now' ) {
        $required['<your-nginx-cache-path> (not configured yet)'] = '<your-nginx-cache-path> (not configured yet)';
    } else {
        $required[rtrim( $cache_path, '/' )] = rtrim( $cache_path, '/' );

        // When FUSE/bindfs is active we also need the real nginx cache directory.
        if ( function_exists( 'nppp_fuse_source_path' ) ) {
            $fuse_source = nppp_fuse_source_path( rtrim( $cache_path, '/' ) );
            if ( $fuse_source !== null ) {
                // Source path is only PHP-touched when rg scans it through safexec.
                $rg_cached = get_transient( 'nppp_rg_ok' );
                if ( $rg_cached === false ) {
                    $rg_bin = function_exists( 'shell_exec' )
                        ? trim( (string) shell_exec( 'command -v rg 2>/dev/null' ) )
                        : '';
                    $rg_ok  = $rg_bin !== '';
                    set_transient( 'nppp_rg_ok', [ 'path' => $rg_bin, 'ok' => $rg_ok ], HOUR_IN_SECONDS );
                } else {
                    $rg_ok = (bool) $rg_cached['ok'];
                }

                // Enforce minimum rg version — treat lower versions as unavailable.
                if ( $rg_ok && function_exists( 'nppp_check_rg_version' ) ) {
                    $rg_ver = nppp_check_rg_version();
                    if ( $rg_ver === 'Not Installed' || version_compare( $rg_ver, '14.0.0', '<' ) ) {
                        $rg_ok = false;
                    }
                }

                $sfx_usable = false;
                if ( $rg_ok
                    && function_exists( 'nppp_find_safexec_path' )
                    && function_exists( 'nppp_is_safexec_usable' )
                ) {
                    $sfx_cached = get_transient( 'nppp_safexec_ok' );
                    if ( $sfx_cached === false ) {
                        $sfx_path   = nppp_find_safexec_path();
                        $sfx_usable = (bool) ( $sfx_path && nppp_is_safexec_usable( $sfx_path, false ) );
                        set_transient( 'nppp_safexec_ok', [ 'path' => $sfx_path, 'ok' => $sfx_usable ], HOUR_IN_SECONDS );
                    } else {
                        $sfx_usable = (bool) $sfx_cached['ok'];
                    }
                }

                if ( $sfx_usable ) {
                    $required[ rtrim( $fuse_source, '/' ) ] = rtrim( $fuse_source, '/' );
                }
            }
        }
    }
    // NPP reads /proc/cpuinfo, /proc/meminfo, /proc/self/mountinfo, /proc/mounts.
    $required['/proc'] = '/proc';

    // Only required when proc_open is available; nppp_detect_premature_process()
    if (function_exists('proc_open') && is_callable('proc_open') &&
        function_exists('proc_get_status') && is_callable('proc_get_status') &&
        function_exists('proc_close') && is_callable('proc_close')) {
        $required['/dev/null'] = '/dev/null';
    }

    // safexec, WordPress core and WP_Filesystem use /tmp for temp file operations.
    $required['/tmp'] = '/tmp';

    // aaPanel specific requirements
    $is_aapanel = nppp_is_aapanel();
    if ( $is_aapanel ) {
        $required['/www/server/nginx/conf']        = '/www/server/nginx/conf';
        $required['/www/server/panel/vhost/nginx'] = '/www/server/panel/vhost/nginx';
    }

    $missing = [];

    foreach ( $required as $label => $path ) {
        if ( ! nppp_obd_path_covered( $path, $obd ) ) {
            $missing[] = $label !== $path ? $label . ' (' . $path . ')' : $path;
        }
    }

    // nginx.conf: group check
    $nginx_dirs = [
        '/etc/nginx', '/usr/local/etc/nginx', '/etc/nginx/conf',
        '/usr/local/nginx/conf', '/usr/local/etc/nginx/conf',
        '/usr/local/etc', '/opt/nginx/conf', '/www/server/nginx/conf',
        '/etc/nginx/conf.d', '/usr/local/openresty/nginx/conf',
        // full file paths — covers when open_basedir lists the .conf file directly
        '/etc/nginx/nginx.conf',
        '/usr/local/etc/nginx/nginx.conf',
        '/etc/nginx/conf/nginx.conf',
        '/usr/local/nginx/conf/nginx.conf',
        '/usr/local/etc/nginx/conf/nginx.conf',
        '/usr/local/etc/nginx.conf',
        '/opt/nginx/conf/nginx.conf',
        '/www/server/nginx/conf/nginx.conf',
        '/etc/nginx/conf.d/ea-nginx.conf',
        '/usr/local/openresty/nginx/conf/nginx.conf',
    ];
    $nginx_covered = $is_aapanel;
    foreach ( $nginx_dirs as $dir ) {
        if ( nppp_obd_path_covered( $dir, $obd ) ) {
            $nginx_covered = true;
            break;
        }
    }
    if ( ! $nginx_covered ) {
        $missing[] = 'nginx.conf directory (e.g. /etc/nginx)';
    }

    $result['compatible'] = empty( $missing );
    $result['missing']    = $missing;

    return $result;
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

    // Set env
    nppp_prepare_request_env(true);

    // Check if the operating system is Linux and the web server is nginx
    if (!nppp_is_linux()) {
        return __('GLOBAL ERROR OPT: Plugin is not functional on your environment. The plugin requires Linux operating system.', 'fastcgi-cache-purge-and-preload-nginx');
    }

    // =========================================================================
    // NGINX DETECTION — strictly ordered cheapest → most expensive.
    //
    // Each tier short-circuits the chain on first conclusive hit.
    // The HTTP probe (Tier 3) is only reached when Tier 0-2 are all blind,
    // which in practice only happens in Docker separate-container deployments
    // where nginx and PHP-FPM run in different containers with no shared
    // filesystem or PATH.
    //
    // Architecture coverage per tier:
    //   Tier 0 — pure Nginx (zero I/O, instant)
    //   Tier 1 — pure Nginx, Nginx+Apache same-host, same-container Docker
    //   Tier 2 — same-host installs where nginx binary is not on PHP's PATH
    //   Tier 3 — Docker separate-container, any topology Tier 0-2 cannot see
    //   Tier 4 — last-resort heuristic (weakest, zero I/O)
    // =========================================================================
    $server_software = '';

    // -------------------------------------------------------------------------
    // TIER 0 — Zero I/O: $_SERVER['SERVER_SOFTWARE'] (memory read, instant).
    // Reliable for pure-Nginx stacks where the SAPI directly reports the
    // frontend server.  Silently ignored for Apache values — reverse-proxy
    // stacks (Nginx → Apache → PHP) continue through cheaper tiers before
    // we ever touch the network, avoiding the HTTP probe overhead when not needed.
    // -------------------------------------------------------------------------
    if (isset($_SERVER['SERVER_SOFTWARE'])) {
        $raw_sw = sanitize_text_field(wp_unslash($_SERVER['SERVER_SOFTWARE']));
        if (
            stripos($raw_sw, 'nginx')     !== false ||
            stripos($raw_sw, 'openresty') !== false ||
            stripos($raw_sw, 'tengine')   !== false
        ) {
            $server_software = $raw_sw;
        }
    }

    // -------------------------------------------------------------------------
    // TIER 1 — Single shell fork: nginx binary on PATH.
    // Catches: pure-Nginx, Nginx+Apache on the same host, Nginx in the same
    // Docker container as PHP-FPM.
    // Misses:  Nginx in a completely separate Docker container.
    // -------------------------------------------------------------------------
    if (empty($server_software) && function_exists('shell_exec')) {
        $nginx_bin = trim((string) shell_exec('command -v nginx 2>/dev/null'));
        if (!empty($nginx_bin)) {
            $server_software = 'nginx';
        }
    }

    // -------------------------------------------------------------------------
    // TIER 2 — Filesystem scan: known Nginx config file paths (fast local I/O).
    // Catches same-host installs where the nginx binary is absent from the PHP
    // process PATH (e.g. custom compile prefix, non-standard package layout).
    // Still cheaper than a network round-trip.
    // -------------------------------------------------------------------------
    if (empty($server_software)) {
        $nginx_conf_paths = nppp_get_nginx_conf_paths($wp_filesystem);
        if (!empty($nginx_conf_paths)) {
            $server_software = 'nginx';
        }
    }

    // -------------------------------------------------------------------------
    // TIER 3 — HTTP HEAD probe (network round-trip; last resort before the
    // weakest heuristic).  Only reached for Docker separate-container setups
    // or exotic proxy topologies where Tiers 0-2 are all inconclusive.
    // -------------------------------------------------------------------------
    if (empty($server_software)) {
        // Perform the request
        $token     = substr(dechex(hrtime(true)), -8);
        $probe_url = add_query_arg(['s' => 'nppp-' . $token, '_nppp' => $token], home_url('/'));
        $response  = wp_remote_head($probe_url, array(
            'timeout'     => 1,
            'redirection' => 0,
            'blocking'    => true,
            'headers'     => array(
                'Cache-Control' => 'no-cache, no-store, max-age=0',
                'Pragma'        => 'no-cache',
                'User-Agent'    => 'NPPP-Precheck/2.1.7',
            ),
        ));

        // Check if the request was successful
        if (is_array($response) && !is_wp_error($response)) {
            // Get response headers
            $headers = wp_remote_retrieve_headers($response);

            // Normalize WP header container -> plain array
            if (is_object($headers)) {
                if (method_exists($headers, 'getAll')) {
                    // Requests v2/v1: preferred API
                    $headers = $headers->getAll();
                } elseif ($headers instanceof \Traversable) {
                    // Iterable fallback
                    $headers = iterator_to_array($headers);
                } else {
                    // Defensive: cast and peel typical 'data' payload if present
                    $maybe   = (array) $headers;
                    $headers = (isset($maybe['data']) && is_array($maybe['data'])) ? $maybe['data'] : $maybe;
                }
            } else {
                $headers = (array) $headers;
            }

            // Case-normalize keys for consistent lookups
            if (!empty($headers)) {
                $headers = array_change_key_case($headers, CASE_LOWER);
            }

            // Signal A — any header *name* containing 'fastcgi'.
            // Strongest signal: survives even when Server header is stripped.
            // Specific to Nginx+PHP-FPM (not Nginx+Apache proxy).
            foreach ($headers as $key => $value) {
                if (is_string($key) && stripos($key, 'fastcgi') !== false) {
                    $header_value = is_array($value) ? implode(' ', array_map('strval', $value)) : (string) $value;
                    if ($header_value !== '') {
                        $server_software = 'nginx';
                        break;
                    }
                }
            }

            // Signal B — 'Server' header value.
            // In Nginx+Apache proxy setups nginx overrides the upstream Server
            // header by default, so 'Apache' never reaches the client here.
            if (empty($server_software) && isset($headers['server'])) {
                $server_header = $headers['server'];
                $server_value  = is_array($server_header) ? implode(' ', array_map('strval', $server_header)) : (string) $server_header;

                if ($server_value !== '' && (
                    stripos($server_value, 'nginx') !== false ||
                    stripos($server_value, 'openresty') !== false ||
                    stripos($server_value, 'tengine') !== false
                )) {
                    $server_software = 'nginx';
                }
            }

            // Signal C — 'Via' header.
            // Some proxy topologies surface nginx identity here rather than
            // in the Server header (e.g. Varnish-fronted nginx).
            if (empty($server_software) && isset($headers['via'])) {
                $via_header = $headers['via'];
                $via_value  = is_array($via_header) ? implode(' ', array_map('strval', $via_header)) : (string) $via_header;

                if ($via_value !== '' && (
                    stripos($via_value, 'nginx') !== false ||
                    stripos($via_value, 'openresty') !== false ||
                    stripos($via_value, 'tengine') !== false
                )) {
                    $server_software = 'nginx';
                }
            }
        }
    }

    // -------------------------------------------------------------------------
    // TIER 4 — PHP_SAPI (zero I/O, weakest heuristic, absolute last resort).
    // 'fpm-fcgi' / 'cgi-fcgi' almost always means PHP-FPM paired with Nginx,
    // but Apache+mod_fcgid also uses 'cgi-fcgi', making this non-conclusive.
    // Intentionally placed last — it is only a tie-breaker when every
    // stronger signal above has failed.
    // -------------------------------------------------------------------------
    if (empty($server_software)) {
        $sapi = PHP_SAPI;
        if (stripos($sapi, 'fpm-fcgi') !== false || stripos($sapi, 'cgi-fcgi') !== false) {
            $server_software = 'nginx';
        }
    }

    // Check if the web server is Nginx
    if (stripos($server_software, 'nginx') === false) {
        return __('GLOBAL ERROR SERVER: The plugin is not functional on your environment. It requires an Nginx web server. If this detection is inaccurate, please refer to the Help tab for detailed instructions.', 'fastcgi-cache-purge-and-preload-nginx');
    }

    // Check if both shell_exec and exec are enabled (both are required)
    if (!function_exists('shell_exec') || !function_exists('exec')) {
        return __('GLOBAL ERROR PHP: Plugin is not functional on your environment. Both "shell_exec" and "exec" functions are required but one or both are not enabled. Please enable them in your server\'s PHP configuration.', 'fastcgi-cache-purge-and-preload-nginx');
    }

    // Verify shell_exec is not silently blocked
    $output = shell_exec('echo "Test"');
    if (trim($output) !== "Test") {
        return __('GLOBAL ERROR PHP: Plugin is not functional on your environment. The "shell_exec" function is required but not enabled. Please enable it in your server\'s PHP configuration.', 'fastcgi-cache-purge-and-preload-nginx');
    }

    // Verify exec is not silently blocked
    $output = exec('echo "Test"');
    if (trim($output) !== "Test") {
        return __('GLOBAL ERROR PHP: Plugin is not functional on your environment. The "exec" function is required but not enabled. Please enable it in your server\'s PHP configuration.', 'fastcgi-cache-purge-and-preload-nginx');
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
            /* translators: %s: list of missing core shell commands */
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
            /* translators: %s: list of missing core shell commands */
            return sprintf(__('GLOBAL ERROR COMMAND: Preload action is not functional on your environment. The required shell command(s) not found: %s', 'fastcgi-cache-purge-and-preload-nginx'), $missing_commands_str);
        }

        if (function_exists('nppp_get_wget_compatibility')) {
            $wget_compat = nppp_get_wget_compatibility();
            if (empty($wget_compat['ok'])) {
                $reason = isset($wget_compat['reason']) ? $wget_compat['reason'] : '';

                if ($reason === 'missing') {
                    return __('GLOBAL ERROR WGET: Preload action requires GNU Wget 1.x (>=1.16), but Wget is not installed.', 'fastcgi-cache-purge-and-preload-nginx');
                }

                if ($reason === 'busybox_toybox') {
                    return __('GLOBAL ERROR WGET: Preload action requires GNU Wget 1.x (>=1.16). BusyBox/Toybox Wget is not supported.', 'fastcgi-cache-purge-and-preload-nginx');
                }

                if ($reason === 'non_gnu') {
                    return __('GLOBAL ERROR WGET: Preload action requires GNU Wget 1.x (>=1.16). Non-GNU Wget implementations are not supported.', 'fastcgi-cache-purge-and-preload-nginx');
                }

                if ($reason === 'unknown_version') {
                    return __('GLOBAL ERROR WGET: Preload action requires GNU Wget 1.x (>=1.16), but the installed GNU Wget version could not be detected.', 'fastcgi-cache-purge-and-preload-nginx');
                }

                if ($reason === 'wget2') {
                    return __('GLOBAL ERROR WGET: Preload action requires GNU Wget 1.x (>=1.16). Your system provides GNU Wget2 (often via wget shim), which is not supported.', 'fastcgi-cache-purge-and-preload-nginx');
                }

                return __('GLOBAL ERROR WGET: Preload action requires GNU Wget 1.x (>=1.16). Older GNU Wget and Wget2 are not supported.', 'fastcgi-cache-purge-and-preload-nginx');
            }
        }
    }

    // All requirements met
    return true;
}

// Probes one real Nginx cache file to verify the configured Cache Key Regex
// can actually parse the KEY: line format used on this site.
function nppp_probe_cache_key_regex(): string {
    $transient_key = 'nppp_cache_key_regex_probe';
    $cached        = get_transient( $transient_key );

    if ( $cached !== false && in_array( $cached, [ 'ok', 'fail', 'skip' ], true ) ) {
        return $cached;
    }

    $nginx_cache_settings = get_option( 'nginx_cache_settings', [] );
    $nginx_cache_path     = $nginx_cache_settings['nginx_cache_path'] ?? '/dev/shm/change-me-now';

    $decoded = isset( $nginx_cache_settings['nginx_cache_key_custom_regex'] )
        ? base64_decode( $nginx_cache_settings['nginx_cache_key_custom_regex'], true )
        : false;

    $regex = ( $decoded !== false && $decoded !== '' )
        ? $decoded
        : ( function_exists( 'nppp_fetch_default_regex_for_cache_key' )
            ? nppp_fetch_default_regex_for_cache_key()
            : '/^KEY:\s+https?(?:GET|HEAD)?([^\/]+)(\/[^\s]*)/m' );

    $wp_filesystem = function_exists( 'nppp_initialize_wp_filesystem' )
        ? nppp_initialize_wp_filesystem()
        : false;

    if ( $wp_filesystem === false || ! $wp_filesystem->is_dir( $nginx_cache_path ) ) {
        set_transient( $transient_key, 'skip', 10 * MINUTE_IN_SECONDS );
        return 'skip';
    }

    $head_bytes    = (int) apply_filters( 'nppp_locate_head_bytes',          4096 );
    $head_bytes_fb = (int) apply_filters( 'nppp_locate_head_bytes_fallback', 32768 );
    $result        = 'skip';

    try {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator( $nginx_cache_path, RecursiveDirectoryIterator::SKIP_DOTS ),
            RecursiveIteratorIterator::LEAVES_ONLY
        );

        foreach ( $iterator as $file ) {
            if ( ! $file->isReadable() ) {
                continue;
            }

            $pathname = $file->getPathname();
            $content  = function_exists( 'nppp_read_head' )
                ? nppp_read_head( $wp_filesystem, $pathname, $head_bytes )
                : '';

            if ( $content === '' ) {
                continue;
            }

            // Verify this is a cache file with a KEY: header
            if ( ! preg_match( '/^KEY:\s/m', $content ) ) {
                if ( strlen( $content ) >= $head_bytes ) {
                    $content = function_exists( 'nppp_read_head' )
                        ? nppp_read_head( $wp_filesystem, $pathname, $head_bytes_fb )
                        : '';
                    if ( $content === '' || ! preg_match( '/^KEY:\s/m', $content ) ) {
                        continue;
                    }
                } else {
                    continue;
                }
            }

            // We have a real cache file — test the regex against it
            $m = [];
            if ( @preg_match( $regex, $content, $m ) === false ) {
                $result = 'fail';
                break;
            }

            if ( ! isset( $m[1], $m[2] ) ) {
                $result = 'fail';
                break;
            }

            if ( filter_var( 'https://' . trim( $m[1] ) . trim( $m[2] ), FILTER_VALIDATE_URL ) === false ) {
                $result = 'fail';
                break;
            }

            $result = 'ok';
            break;
        }
    } catch ( \Exception $e ) {
        $result = 'skip';
    }

    $ttl = (int) apply_filters( 'nppp_regex_probe_ttl', 10 * MINUTE_IN_SECONDS );
    set_transient( $transient_key, $result, $ttl );
    return $result;
}

// Pre-checks and global warnings
function nppp_pre_checks() {
    // Exlude Advanced and Help Tab
    $nppp_active_tab = isset( $_GET['nppp_tab'] ) ? sanitize_key( wp_unslash( $_GET['nppp_tab'] ) ) : 'settings'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
    if ( ! in_array( $nppp_active_tab, [ 'settings', 'status' ], true ) ) {
        return;
    }

    // OBD check MUST run before any early return — open_basedir is the #1 silent
    // killer that *causes* those early returns (is_dir on cache path returns false,
    // nginx.conf probes all fail, etc).  Only warn when paths are actually missing;
    // if the user already configured OBD correctly the notice is noise.
    if ( $nppp_active_tab === 'settings' ) {
        $obd_check = nppp_open_basedir_compat_check();
        if ( $obd_check['active'] && ! $obd_check['compatible'] ) {
            $missing_list = '<ul style="margin: 6px 0 6px 20px; list-style: disc;">';
            foreach ( $obd_check['missing'] as $item ) {
                $missing_list .= '<li>' . esc_html( $item ) . '</li>';
            }
            $missing_list .= '</ul>';

            nppp_display_pre_check_warning(
                /* translators: %s: list of missing open_basedir paths as an HTML <ul> */
                __( 'GLOBAL ERROR PHP: <code>open_basedir</code> is active, but required paths for NPP are missing. See the list below.', 'fastcgi-cache-purge-and-preload-nginx' )
                . $missing_list
                . '<strong>' . __( 'Plugin functionality may be broken until this is resolved.', 'fastcgi-cache-purge-and-preload-nginx' ) . '</strong>'
            );
        }
    }

    $wp_filesystem = nppp_initialize_wp_filesystem();

    if ($wp_filesystem === false) {
        nppp_display_admin_notice(
            'error',
            __('Failed to initialize the WordPress filesystem. Please file a bug on the plugin support page.', 'fastcgi-cache-purge-and-preload-nginx')
        );
        return;
    }

    if (function_exists('set_time_limit')) {
        @set_time_limit(0); // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged
    }

    $nginx_cache_settings = get_option('nginx_cache_settings');
    $default_cache_path   = '/dev/shm/change-me-now';
    $nginx_cache_path     = isset($nginx_cache_settings['nginx_cache_path']) ? $nginx_cache_settings['nginx_cache_path'] : $default_cache_path;

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

    // Permission check only after cache path is confirmed to exist — transient-cached
    // so cold-miss recursive walk only happens when it's actually needed.
    $nppp_permissions_check_result = nppp_check_permissions_recursive_with_cache();

    // Check permissions are sufficient
    if ($nppp_permissions_check_result === 'false') {
        nppp_display_pre_check_warning(__('GLOBAL ERROR PERMISSION: Insufficient permissions for Nginx cache directory. Refer to the "Help" tab for guidance.', 'fastcgi-cache-purge-and-preload-nginx'));
        return;
    }

    // Head-only read sizes (once per call)
    $head_bytes_primary  = (int) apply_filters( 'nppp_locate_head_bytes', 4096 );
    $head_bytes_fallback = (int) apply_filters( 'nppp_locate_head_bytes_fallback', 32768 );

    // Check cache status
    // Fast path: use rg always if available
    $rg_cached = get_transient( 'nppp_rg_ok' );
    if ( $rg_cached === false ) {
        $rg_bin = trim( (string) shell_exec( 'command -v rg 2>/dev/null' ) );
        $rg_ok  = $rg_bin !== '';
        set_transient( 'nppp_rg_ok', [ 'path' => $rg_bin, 'ok' => $rg_ok ], HOUR_IN_SECONDS );
    } else {
        $rg_bin = $rg_cached['path'];
        $rg_ok  = (bool) $rg_cached['ok'];
    }
    // Enforce minimum rg version — treat lower versions as unavailable.
    if ( $rg_ok && function_exists( 'nppp_check_rg_version' ) ) {
        $rg_ver = nppp_check_rg_version();
        if ( $rg_ver === 'Not Installed' || version_compare( $rg_ver, '14.0.0', '<' ) ) {
            $rg_ok = false;
        }
    }
    if ( $rg_ok ) {
        $has_files = '';
        $escaped_path = escapeshellarg( $nginx_cache_path );

        $cmd       = $rg_bin . ' -q --text --no-unicode --no-ignore --no-messages --no-mmap --no-config -e ' . escapeshellarg( '^KEY: ' ) . ' ' . $escaped_path . ' 2>/dev/null';
        $dummy     = [];
        $exit_code = null;
        exec( $cmd, $dummy, $exit_code );

        if ( $exit_code === 0 ) {
            $has_files = 'found';
        } elseif ( $exit_code === 1 ) {
            $has_files = '';
        } elseif ( $exit_code === 2 ) {
            $has_files = 'error';
        }
    } else {
        // Fallback: PHP recursive iterator (rg not available)
        try {
            $cache_iterator = new RecursiveIteratorIterator(
                    new RecursiveDirectoryIterator($nginx_cache_path, RecursiveDirectoryIterator::SKIP_DOTS),
                    RecursiveIteratorIterator::LEAVES_ONLY
            );

            $has_files = '';
            foreach ($cache_iterator as $file) {
                $pathname = $file->getPathname();

                // Read only the head (binary-safe)
                $content = nppp_read_head( $wp_filesystem, $pathname, $head_bytes_primary );
                if ( $content === '' ) { continue; }

                // Look for "KEY:" line in the head
                if ( ! preg_match( '/^KEY:\s/m', $content ) ) {
                    // If we likely truncated at primary cap, try a larger head read once
                    if ( strlen( $content ) >= $head_bytes_primary ) {
                        $content = nppp_read_head( $wp_filesystem, $pathname, $head_bytes_fallback );
                        if ( $content === '' || ! preg_match( '/^KEY:\s/m', $content ) ) {
                            continue;
                        }
                    } else {
                        continue;
                    }
                }

                $has_files = 'found';
                break;
            }
        } catch (Exception $e) {
            $has_files = 'error';
        }
    }

    // Warn about empty cache
    $this_script_path = dirname(plugin_dir_path(__FILE__));
    $PIDFILE = nppp_get_runtime_file('cache_preload.pid');

    $preload_running = false;
    $pid = 0;

    if ($wp_filesystem->exists($PIDFILE)) {
        $raw = trim((string) nppp_perform_file_operation($PIDFILE, 'read'));
        if ($raw !== '' && ctype_digit($raw)) {
            $pid = (int) $raw;
            if ($pid > 0 && nppp_is_process_alive($pid)) {
                $preload_running = true;
            }
        }
    }

    if ($has_files !== 'found' && $has_files !== 'error' && !$preload_running) {
        nppp_display_pre_check_warning(__('GLOBAL WARNING CACHE: The Nginx cache is empty. Consider preloading the Nginx cache now!', 'fastcgi-cache-purge-and-preload-nginx'));
        return;
    }

    // Regex probe — only meaningful when the cache populated to test against.
    // Non-fatal: single url purge/advanced tab break but preload, purge all still work.
    if ( $has_files === 'found' && !$preload_running) {
        $regex_probe = nppp_probe_cache_key_regex();
        if ( $regex_probe === 'fail' ) {
            nppp_display_pre_check_warning(
                wp_kses(
                    __( 'GLOBAL WARNING REGEX: The <strong>Cache Key Regex</strong> does not match the Nginx cache key format on this site. <strong>Single URL Purge</strong> and Advanced Tab operations will fail until the regex is corrected. Please update the <strong>Cache Key Regex</strong> in the <strong>Advanced Options</strong> tab.', 'fastcgi-cache-purge-and-preload-nginx' ),
                    [ 'strong' => [] ]
                )
            );
        }
    }
}

// Detect the Vary: Accept-Encoding cache issue.
//
//  RC1 — Conditional upstream Vary (PHP zlib or a plugin that only emits
//         Vary: Accept-Encoding when the client advertises gzip/deflate):
//         · Definitive signal: zlib.output_compression ini = On.
//         · Probe signal: Vary present with gzip-AE probe, absent with no-AE probe.
//         · Effect: cache thrashing — NPP-warmed entry overwritten on encoding mismatch.
//
//  RC2 — Unconditional upstream Vary (a plugin or middleware that always emits
//         Vary: Accept-Encoding regardless of the client's Accept-Encoding value):
//         · Probe signal: Vary present in no-AE probe AND zlib is Off.
//         · Caveat: nginx gzip_vary on also produces Vary in the no-AE probe but is SAFE
//           (writes only r->gzip_vary, never r->cache->vary). UI message clarifies.
//         · Effect: second independent cache file per URL — NPP-warmed entry bypassed.
if (! function_exists('nppp_detect_vary_issue')) {
    function nppp_detect_vary_issue(): array {
        // When the admin has permanently dismissed the Vary notice, skip all
        // probing.
        if ( get_option( 'nppp_vary_notice_dismissed' ) ) {
            return [ 'zlib_on' => false, 'rc1' => false, 'rc2' => false, 'issue' => false ];
        }

        $transient_key = 'nppp_vary_issue_' . md5('nppp');
        $cached        = get_transient($transient_key);
        if (is_array($cached) && array_key_exists('issue', $cached)) {
            return $cached;
        }

        // Signal 1: PHP runtime ini value
        $raw     = (string) ini_get('zlib.output_compression');
        $zlib_on = ($raw !== '' && $raw !== '0' && strtolower($raw) !== 'off');

        // Signal 2: two-probe header fingerprint
        $vary_gzip     = false;
        $vary_identity = false;

        if (function_exists('wp_remote_head') && function_exists('home_url') && function_exists('add_query_arg')) {
            $token    = substr(dechex(hrtime(true)), -10);
            $bust_url = add_query_arg(['s' => 'nppp-vary-' . $token, '_nppp' => $token], home_url('/'));

            $common_args = [
                'timeout'     => 3,
                'redirection' => 0,
                'blocking'    => true,
                'sslverify'   => false,
            ];

            // Probe A: client advertises gzip — PHP will compress → adds Vary
            $resp_gzip = wp_remote_head($bust_url, array_merge($common_args, [
                'headers' => [
                    'Cache-Control'   => 'no-cache, no-store',
                    'Pragma'          => 'no-cache',
                    'Accept-Encoding' => 'gzip, deflate',
                    'User-Agent'      => 'NPPP-VaryProbe-Gzip/2.1.7',
                ],
            ]));

            // Probe B: identity — PHP skips compression → skips Vary
            //          nginx gzip_vary STILL writes Vary (flag set before gzip_ok check)
            $resp_identity = wp_remote_head($bust_url, array_merge($common_args, [
                'headers' => [
                    'Cache-Control'   => 'no-cache, no-store',
                    'Pragma'          => 'no-cache',
                    'Accept-Encoding' => 'identity',
                    'User-Agent'      => 'NPPP-VaryProbe-Identity/2.1.7',
                ],
            ]));

            if (! is_wp_error($resp_gzip)) {
                $vary_gzip = nppp_probe_has_vary_accept_encoding($resp_gzip);
            }
            if (! is_wp_error($resp_identity)) {
                $vary_identity = nppp_probe_has_vary_accept_encoding($resp_identity);
            }
        }

        // RC1: conditional Vary — PHP zlib (ini) or a plugin firing only for gzip-capable clients.
        $rc1 = $zlib_on || ( $vary_gzip && ! $vary_identity );

        // RC2 potential: Vary present even for a no-encoding-preference request.
        // Upstream plugin emitting unconditionally = issue (creates second cache file per URL).
        // nginx gzip_vary on = safe (r->gzip_vary only, never r->cache->vary). UI clarifies.
        $rc2 = $vary_identity && ! $zlib_on;

        $result = [
            'zlib_on' => $zlib_on,
            'rc1'     => $rc1,
            'rc2'     => $rc2,
            'issue'   => $rc1 || $rc2,
        ];

        set_transient($transient_key, $result, HOUR_IN_SECONDS);

        return $result;
    }
}

// Extract and normalise response headers, then check whether Vary contains Accept-Encoding.
if (! function_exists('nppp_probe_has_vary_accept_encoding')) {
    function nppp_probe_has_vary_accept_encoding($response): bool {
        $headers = wp_remote_retrieve_headers($response);

        if (is_object($headers)) {
            if (method_exists($headers, 'getAll')) {
                $headers = $headers->getAll();
            } elseif ($headers instanceof Traversable) {
                $headers = iterator_to_array($headers);
            } else {
                $maybe   = (array) $headers;
                $headers = (isset($maybe['data']) && is_array($maybe['data'])) ? $maybe['data'] : $maybe;
            }
        } else {
            $headers = (array) $headers;
        }

        if (empty($headers)) {
            return false;
        }

        $headers = array_change_key_case($headers, CASE_LOWER);

        if (! isset($headers['vary'])) {
            return false;
        }

        $vary = is_array($headers['vary'])
            ? implode(', ', array_map('strval', $headers['vary']))
            : (string) $headers['vary'];

        return (stripos($vary, 'accept-encoding') !== false);
    }
}

// Handle pre check messages
function nppp_display_pre_check_warning($error_message = '') {
    if (!empty($error_message)) {
        add_action('admin_notices', function() use ($error_message) {
            ?>
            <div class="notice notice-error is-dismissible notice-nppp">
                <div style="padding: 8px 0;"><?php echo wp_kses_post($error_message); ?></div>
            </div>
            <?php
        });
    }
}

/**
 * Renders the Vary: Accept-Encoding notice markup for a given detection result.
 * Shared by the AJAX handler so the markup only lives in one place.
 *
 * @param array|null $nppp_vary Result of nppp_detect_vary_issue(), or null on probe failure.
 * @return string HTML fragment for #nppp-vary-result.
 */
if ( ! function_exists( 'nppp_render_vary_notice_html' ) ) {
    function nppp_render_vary_notice_html( $nppp_vary ) {
        ob_start();

        if ( $nppp_vary !== null && ! empty( $nppp_vary['rc1'] ) ) :
            ?>
            <div style="background:#fef2f2; border-left:4px solid #dc2626; padding:10px 14px; max-width:500px; position:relative;">
                <button type="button" id="nppp-dismiss-vary" title="<?php esc_attr_e( 'Dismiss permanently', 'fastcgi-cache-purge-and-preload-nginx' ); ?>" style="position:absolute; top:6px; right:8px; background:none; border:none; cursor:pointer; font-size:16px; line-height:1; color:#991b1b; padding:0;" aria-label="<?php esc_attr_e( 'Dismiss Vary notice permanently', 'fastcgi-cache-purge-and-preload-nginx' ); ?>">&#x2715;</button>
                <strong style="color:#991b1b;"><?php esc_html_e( '⚠ RC1 Detected: Cache Thrashing Risk', 'fastcgi-cache-purge-and-preload-nginx' ); ?></strong><br>
                <span style="font-size:13px; color:#7f1d1d;">
                    <?php
                    if ( ! empty( $nppp_vary['zlib_on'] ) ) {
                        esc_html_e( 'PHP zlib.output_compression is On — PHP emits Vary: Accept-Encoding for gzip-capable requests, causing Nginx to thrash the cache when NPP and browser requests alternate.', 'fastcgi-cache-purge-and-preload-nginx' );
                    } else {
                        esc_html_e( 'A plugin or middleware proxy is conditionally emitting Vary: Accept-Encoding for gzip-capable requests, causing Nginx to thrash the cache when NPP and browser requests alternate.', 'fastcgi-cache-purge-and-preload-nginx' );
                    }
                    ?>
                    <a href="?page=nginx_cache_settings&nppp_tab=help#help" style="font-size:13px; color:#991b1b; font-weight:600; text-decoration:none; display:block; margin-top:4px;">
                        <?php esc_html_e( '→ See Help tab for the required fix', 'fastcgi-cache-purge-and-preload-nginx' ); ?>
                    </a>
                </span>
            </div>
            <?php
        elseif ( $nppp_vary !== null && ! empty( $nppp_vary['rc2'] ) ) :
            ?>
            <div style="background:#fef2f2; border-left:4px solid #dc2626; padding:10px 14px; max-width:500px; position:relative;">
                <button type="button" id="nppp-dismiss-vary" title="<?php esc_attr_e( 'Dismiss permanently', 'fastcgi-cache-purge-and-preload-nginx' ); ?>" style="position:absolute; top:6px; right:8px; background:none; border:none; cursor:pointer; font-size:16px; line-height:1; color:#991b1b; padding:0;" aria-label="<?php esc_attr_e( 'Dismiss Vary notice permanently', 'fastcgi-cache-purge-and-preload-nginx' ); ?>">&#x2715;</button>
                <strong style="color:#991b1b;"><?php esc_html_e( '⚠ RC2 Potential: Double Cache Risk', 'fastcgi-cache-purge-and-preload-nginx' ); ?></strong><br>
                <span style="font-size:13px; color:#7f1d1d;">
                    <?php esc_html_e( 'Vary: Accept-Encoding is present in responses. A plugin or upstream proxy may be emitting this unconditionally — Nginx may create a second cache per URL and NPP-warmed cache are never reached by real visitors.', 'fastcgi-cache-purge-and-preload-nginx' ); ?><br><br>
                    <?php esc_html_e( 'To verify if this affects you: Run Preload All, then visit the page in a browser for the first time. If you see HIT, you\'re fine. If you see MISS, the double cache issue is affecting you.', 'fastcgi-cache-purge-and-preload-nginx' ); ?><br><br>
                    <span style="font-size:12.5px; color:#06402B; font-weight: bold;"><?php esc_html_e( 'Note: If nginx "gzip_vary on" is your only Vary source, this detection is a false positive and no action is needed. You can dismiss permanently.', 'fastcgi-cache-purge-and-preload-nginx' ); ?></span><br><br>
                    <a href="?page=nginx_cache_settings&nppp_tab=help#help" style="font-size:13px; color:#991b1b; font-weight:600; text-decoration:none; display:block; margin-top:4px;">
                        <?php esc_html_e( '→ See Help tab for the required fix', 'fastcgi-cache-purge-and-preload-nginx' ); ?>
                    </a>
                </span>
            </div>
            <?php
        elseif ( $nppp_vary !== null && empty( $nppp_vary['issue'] ) ) :
            ?>
            <div style="background:#f0fdf4; border-left:4px solid #16a34a; padding:10px 14px; max-width:500px; position:relative;">
                <button type="button" id="nppp-dismiss-vary" title="<?php esc_attr_e( 'Dismiss permanently', 'fastcgi-cache-purge-and-preload-nginx' ); ?>" style="position:absolute; top:6px; right:8px; background:none; border:none; cursor:pointer; font-size:16px; line-height:1; color:#14532d; padding:0;" aria-label="<?php esc_attr_e( 'Dismiss Vary notice permanently', 'fastcgi-cache-purge-and-preload-nginx' ); ?>">&#x2715;</button>
                <strong style="color:#14532d;"><?php esc_html_e( '✔ Not Affected', 'fastcgi-cache-purge-and-preload-nginx' ); ?></strong><br>
                <span style="font-size:13px; color:#166534;"><?php esc_html_e( 'No upstream Vary: Accept-Encoding source detected. Single cache per URL confirmed.', 'fastcgi-cache-purge-and-preload-nginx' ); ?></span>
            </div>
            <?php
        else :
            ?>
            <div style="background:#fff8e1; border-left:4px solid #f0ad4e; padding:10px 14px; max-width:500px; position:relative;">
                <button type="button" id="nppp-dismiss-vary" title="<?php esc_attr_e( 'Dismiss permanently', 'fastcgi-cache-purge-and-preload-nginx' ); ?>" style="position:absolute; top:6px; right:8px; background:none; border:none; cursor:pointer; font-size:16px; line-height:1; color:#7a4f00; padding:0;" aria-label="<?php esc_attr_e( 'Dismiss Vary notice permanently', 'fastcgi-cache-purge-and-preload-nginx' ); ?>">&#x2715;</button>
                <strong style="color:#7a4f00;"><?php esc_html_e( 'Vary Cache Issue', 'fastcgi-cache-purge-and-preload-nginx' ); ?></strong><br>
                <span style="font-size:13px; color:#5a3800;">
                    <?php esc_html_e( 'Could not verify. ', 'fastcgi-cache-purge-and-preload-nginx' ); ?>
                    <a href="?page=nginx_cache_settings&nppp_tab=help#help" style="font-size:13px; color:#7a4f00; font-weight:600; text-decoration:none;">
                        <?php esc_html_e( '→ See Help tab for fix and full explanation', 'fastcgi-cache-purge-and-preload-nginx' ); ?>
                    </a>
                </span>
            </div>
            <?php
        endif;

        return (string) ob_get_clean();
    }
}
