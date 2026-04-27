<?php
/**
 * Status page renderer for Nginx Cache Purge Preload
 * Description: Collects and displays runtime diagnostics, cache details, and health information.
 * Version: 2.1.6
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
    $nginx_cache_settings = get_option('nginx_cache_settings', []);
    $default_cache_path   = '/dev/shm/change-me-now';
    $nginx_cache_path     = isset($nginx_cache_settings['nginx_cache_path']) ? $nginx_cache_settings['nginx_cache_path'] : $default_cache_path;

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

        // Cache the result for 1 month
        set_transient($transient_key, $result, 30 * DAY_IN_SECONDS);
    }

    return $result;
}

// Function to clear all transients related to the plugin
function nppp_clear_plugin_cache($silent = false) {
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
        'nppp_wget_urls_cache_' . md5($static_key_base),
        'nppp_wget_compatibility_' . md5($static_key_base),
        'nppp_missing_commands_' . md5($static_key_base),
        'nppp_preload_trigger_' . md5($static_key_base),
        'nppp_http_purge_endpoint_broken',
        'nppp_wget_urls_cache_prev_key',
        'nppp_safexec_ok',
        'nppp_category_map',
        'nppp_rg_ok',
        'nppp_wget_version_' . md5($static_key_base),
        'nppp_rg_version_' . md5($static_key_base),
        'nppp_pages_in_cache_' . md5($static_key_base),
        'nppp_obd_warned_' . md5($static_key_base),
    );

    // Delete each known transient
    foreach ($transients as $transient) {
        delete_transient($transient);
    }

    // Transients that must not be cleared while a preload is running:
    //   nppp_preload_phase_        — tick monitor reads this every 5s to track desktop/mobile phase
    //   nppp_preload_cycle_start_  — needed at completion to calculate total elapsed time
    //   nppp_ping_token_           — watchdog validates this token when preload finishes;
    //                                deleting it causes watchdog to 403 and post-preload tasks never fire
    $wp_filesystem = nppp_initialize_wp_filesystem();
    if ( ! nppp_is_preload_running( $wp_filesystem ) ) {
        $job_transients = array(
            'nppp_preload_phase_'       . md5($static_key_base),
            'nppp_preload_cycle_start_' . md5($static_key_base),
            'nppp_ping_token_'          . md5($static_key_base),
        );
        foreach ($job_transients as $transient) {
            delete_transient($transient);
        }
    }

    // Safe clean up of dynamic transients directly in DB.
    $like_category              = $wpdb->esc_like('_transient_nppp_category_') . '%';
    $like_category_timeout      = $wpdb->esc_like('_transient_timeout_nppp_category_') . '%';
    $like_rate_limit            = $wpdb->esc_like('_transient_nppp_rate_limit_') . '%';
    $like_rate_limit_timeout    = $wpdb->esc_like('_transient_timeout_nppp_rate_limit_') . '%';
    $like_front_message         = $wpdb->esc_like('_transient_nppp_front_message_') . '%';
    $like_front_message_timeout = $wpdb->esc_like('_transient_timeout_nppp_front_message_') . '%';
    $like_wget_cache            = $wpdb->esc_like('_transient_nppp_wget_urls_cache_') . '%';
    $like_wget_cache_timeout    = $wpdb->esc_like('_transient_timeout_nppp_wget_urls_cache_') . '%';
    $like_ep8_fail              = $wpdb->esc_like('_transient_nppp_ep8_fail_') . '%';
    $like_ep8_fail_timeout      = $wpdb->esc_like('_transient_timeout_nppp_ep8_fail_') . '%';
    $like_ep3_fail              = $wpdb->esc_like('_transient_nppp_ep3_fail_') . '%';
    $like_ep3_fail_timeout      = $wpdb->esc_like('_transient_timeout_nppp_ep3_fail_') . '%';

    // Safe clean up transients directly in DB
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
    $wpdb->query(
        $wpdb->prepare(
            "DELETE FROM {$wpdb->options}
            WHERE option_name LIKE %s
               OR option_name LIKE %s
               OR option_name LIKE %s
               OR option_name LIKE %s
               OR option_name LIKE %s
               OR option_name LIKE %s
               OR option_name LIKE %s
               OR option_name LIKE %s
               OR option_name LIKE %s
               OR option_name LIKE %s
               OR option_name LIKE %s
               OR option_name LIKE %s",
            $like_category,
            $like_category_timeout,
            $like_rate_limit,
            $like_rate_limit_timeout,
            $like_front_message,
            $like_front_message_timeout,
            $like_wget_cache,
            $like_wget_cache_timeout,
            $like_ep8_fail,
            $like_ep8_fail_timeout,
            $like_ep3_fail,
            $like_ep3_fail_timeout
        )
    );

    // Log all transients were cleared successfully
    if (!$silent) {
        nppp_display_admin_notice('success', __('SUCCESS: Plugin cache cleared successfully.', 'fastcgi-cache-purge-and-preload-nginx'), true, false);
    }
}

// Check server side action need for cache path permissions.
function nppp_check_perm_in_cache($check_path = false, $check_perm = false, $check_fpm = false) {
    // Define a static key-based transient
    $static_key_base = 'nppp';
    $transient_key = 'nppp_permissions_check_' . md5($static_key_base);

    // Get the cached result and path status
    $result = get_transient($transient_key);

    // Normalize: callers compare against string 'true'/'false'
    if ($result === false) {
        $result = 'false';
    } else {
        // Be defensive in case something else was stored
        $result = ($result === 'true') ? 'true' : 'false';
    }

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
    // Set env
    nppp_prepare_request_env(true);

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
    $PIDFILE = nppp_get_runtime_file('cache_preload.pid');

    if ($wp_filesystem->exists($PIDFILE)) {
        $pid = intval(nppp_perform_file_operation($PIDFILE, 'read'));

        if ($pid > 0 && nppp_is_process_alive($pid)) {
            return 'progress';
        }
    }

    // Check permission status, wget command compatibility and cache path existence
    $cached_result = nppp_check_perm_in_cache();
    $path_status = nppp_check_path();

    $wget_compatible = true;
    if (function_exists('nppp_get_wget_compatibility')) {
        $wget_compat = nppp_get_wget_compatibility();
        $wget_compatible = !empty($wget_compat['ok']);
    } else {
        $wget_status = nppp_check_command_status('wget');
        $wget_compatible = ($wget_status === 'Installed');
    }

    if ($cached_result === 'false' || !$wget_compatible || $path_status !== 'Found') {
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

    $nginx_cache_settings = get_option('nginx_cache_settings', []);
    $default_cache_path   = '/dev/shm/change-me-now';
    $nginx_cache_path     = isset($nginx_cache_settings['nginx_cache_path']) ? $nginx_cache_settings['nginx_cache_path'] : $default_cache_path;

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
        // Set env
        nppp_prepare_request_env(true);

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

// Returns cache filesystem stats as array [used, free, total, dedicated], null on failure.
// dedicated=true  → path is its own mount (tmpfs, bindfs, etc.) — disk stats are exact for cache.
// dedicated=false → path is a directory on a shared partition — du gives actual cache bytes only.
function nppp_get_cache_disk_size( string $path ): ?array {
    if ( empty( $path ) || ! is_dir( $path ) ) {
        return null;
    }

    $total = @disk_total_space( $path );
    $free  = @disk_free_space( $path );

    if ( $total === false || $free === false ) {
        return null;
    }

    // Detect dedicated filesystem by comparing device IDs of path vs its parent.
    // Different device ID means path is a separate mount point (tmpfs, bindfs, NFS, etc.)
    $path_stat   = @stat( $path );
    $parent_stat = @stat( dirname( rtrim( $path, '/' ) ) );

    $is_dedicated = (
        $path_stat !== false &&
        $parent_stat !== false &&
        $path_stat['dev'] !== $parent_stat['dev']
    );

    if ( $is_dedicated ) {
        // Dedicated filesystem — disk_total_space/free are exactly for this cache.
        return [
            'used'      => (int) ( $total - $free ),
            'free'      => (int) $free,
            'total'     => (int) $total,
            'dedicated' => true,
        ];
    }

    // Shared partition — use du to get actual cache directory bytes only.
    // This prevents showing entire partition usage as "cache size".
    $raw = shell_exec( 'du -sb ' . escapeshellarg( $path ) . ' 2>/dev/null' );
    if ( $raw ) {
        $parts = explode( "\t", trim( $raw ) );
        if ( isset( $parts[0] ) && ctype_digit( $parts[0] ) ) {
            return [
                'used'      => (int) $parts[0],
                'free'      => (int) $free,
                'total'     => (int) $total,
                'dedicated' => false,
            ];
        }
    }

    // du failed — fall back to filesystem stats, flag as non-dedicated.
    return [
        'used'      => (int) ( $total - $free ),
        'free'      => (int) $free,
        'total'     => (int) $total,
        'dedicated' => false,
    ];
}

// Format bytes into human-readable string.
function nppp_format_cache_size( int $bytes ): string {
    if ( $bytes >= 1073741824 ) { return number_format( $bytes / 1073741824, 2 ) . ' GB'; }
    if ( $bytes >= 1048576 )    { return number_format( $bytes / 1048576,    2 ) . ' MB'; }
    if ( $bytes >= 1024 )       { return number_format( $bytes / 1024,       2 ) . ' KB'; }
    return $bytes . ' B';
}

// Function to get the PHP process owner (website-user)
function nppp_get_website_user() {
    // Set env
    nppp_prepare_request_env(true);

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
    $nginx_user_conf = '';

    // Check if the config file exists
    if (!$wp_filesystem->exists($config_file)) {
        set_transient($transient_key, "Not Determined", MONTH_IN_SECONDS);
        return "Not Determined";
    }

    // Set env
    nppp_prepare_request_env(true);

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
    $nginx_cache_settings = get_option('nginx_cache_settings', []);
    $default_cache_path   = '/dev/shm/change-me-now';
    $nginx_cache_path     = isset($nginx_cache_settings['nginx_cache_path']) ? $nginx_cache_settings['nginx_cache_path'] : $default_cache_path;

    // Retrieve and decode user-defined cache key regex from the database, with a hardcoded fallback
    $decoded = isset($nginx_cache_settings['nginx_cache_key_custom_regex'])
             ? base64_decode($nginx_cache_settings['nginx_cache_key_custom_regex'], true)
             : false;

    $regex   = ($decoded !== false && $decoded !== '')
             ? $decoded
             : nppp_fetch_default_regex_for_cache_key();

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

    // FP — ripgrep fast path, always use if available
    nppp_prepare_request_env();
    $rg_bin = trim( (string) shell_exec( 'command -v rg 2>/dev/null' ) );

    if ( $rg_bin !== '' ) {
        $rg_fuse_path   = $nginx_cache_path;
        $rg_source_path = nppp_fuse_source_path( $rg_fuse_path );

        // Mount table may list a FUSE source path that no longer exists on disk.
        if ( $rg_source_path !== null && ! $wp_filesystem->is_dir( $rg_source_path ) ) {
            $rg_source_path = null;
        }

        $rg_fuse_active = ( $rg_source_path !== null );
        $rg_use_safexec = false;
        $rg_safexec_bin = '';

        if ( $rg_fuse_active ) {
            // FUSE active — try to read real source path directly (faster, bypasses FUSE overhead).
            // Read-only ops only here, so FUSE mount path is never needed for file operations.
            $rg_scan_path = rtrim( $rg_source_path, '/' ) . '/';

            $probe_out  = [];
            $probe_exit = 0;
            exec(
                sprintf(
                    '%s -q \'.\' --text --no-ignore --no-config -m 1 %s 2>/dev/null',
                    escapeshellarg( $rg_bin ),
                    escapeshellarg( $rg_scan_path )
                ),
                $probe_out,
                $probe_exit
            );

            // PHP lacks read access to real source path — try safexec for elevated read.
            if ( $probe_exit === 2 ) {
                $sfx = nppp_find_safexec_path();
                if ( $sfx && nppp_is_safexec_usable( $sfx, false ) ) {
                    $rg_use_safexec = true;
                    $rg_safexec_bin = $sfx;
                } else {
                    // safexec unavailable — fall back to FUSE mount path (slower)
                    $rg_scan_path = $rg_fuse_path;
                }
            }
        } else {
            $rg_scan_path = $rg_fuse_path;
        }

        $rg_cmd_prefix = $rg_use_safexec ? escapeshellarg( $rg_safexec_bin ) . ' ' : '';

        // Debug logs for decision taken
        if ( $rg_fuse_active ) {
            if ( $rg_scan_path === $rg_fuse_path ) {
                nppp_display_admin_notice( 'info', sprintf(
                    /* translators: %s: Filesystem path being scanned by ripgrep via FUSE mount. */
                    __( 'WARNING RG SCAN: FUSE mount detected, scanning FUSE mount path (safexec unavailable, install safexec for better performance): %s', 'fastcgi-cache-purge-and-preload-nginx' ),
                    $rg_scan_path
                ), true, false );
            } elseif ( $rg_use_safexec ) {
                nppp_display_admin_notice( 'info', sprintf(
                    /* translators: %s: Original Nginx cache filesystem path being scanned by ripgrep via safexec. */
                    __( 'INFO RG SCAN: FUSE mount detected, scanning original Nginx Cache Path (safexec): %s', 'fastcgi-cache-purge-and-preload-nginx' ),
                    $rg_scan_path
                ), true, false );
            }
        }

        // SCAN 1 — build redirect file set to exclude (warms Linux dcache for SCAN 2)
        $redirect_cmd = sprintf(
            '%s%s -F --text -l -m 1 -E none --no-unicode'
            . ' --no-heading --no-ignore --no-config --no-messages -e %s -e %s %s 2>/dev/null',
            $rg_cmd_prefix,
            escapeshellarg( $rg_bin ),
            escapeshellarg( 'Status: 301' ),
            escapeshellarg( 'Status: 302' ),
            escapeshellarg( $rg_scan_path )
        );

        $redirect_out  = [];
        $redirect_exit = 0;
        exec( $redirect_cmd, $redirect_out, $redirect_exit );

        if ( $redirect_exit === 2 ) {
            return 'Undetermined';
        }

        $redirect_set = array_flip( array_filter( array_map( 'trim', $redirect_out ), 'strlen' ) );
        unset( $redirect_out );

        // SCAN 2 — extract KEY: line per cache file
        $key_cmd = sprintf(
            '%s%s --text -m 1 -E none --no-unicode'
            . ' --no-heading --no-ignore --no-config --no-messages %s %s 2>/dev/null',
            $rg_cmd_prefix,
            escapeshellarg( $rg_bin ),
            escapeshellarg( '^KEY: [^\r\n]+' ),
            escapeshellarg( $rg_scan_path )
        );

        $key_out  = [];
        $key_exit = 0;
        exec( $key_cmd, $key_out, $key_exit );

        if ( $key_exit === 2 ) {
            return 'Undetermined';
        }
        if ( $key_exit === 1 || empty( $key_out ) ) {
            return 0;
        }

        $urls_count   = 0;
        $regex_tested = false;

        foreach ( $key_out as $raw_line ) {
            $raw_line = trim( $raw_line );
            if ( $raw_line === '' ) { continue; }

            $sep = strpos( $raw_line, ':' );
            if ( $sep === false ) { continue; }

            $scan_filepath = substr( $raw_line, 0, $sep );
            $key_line      = substr( $raw_line, $sep + 1 );
            if ( $scan_filepath === '' || $key_line === '' ) { continue; }

            if ( isset( $redirect_set[ $scan_filepath ] ) ) { continue; }

            if ( ! $regex_tested ) {
                if ( ! preg_match( $regex, $key_line, $m ) || ! isset( $m[1], $m[2] ) ) {
                    return 'RegexError';
                }
                if ( filter_var( 'https://' . trim( $m[1] ) . trim( $m[2] ), FILTER_VALIDATE_URL ) === false ) {
                    return 'RegexError';
                }
                $regex_tested = true;
            } else {
                if ( ! preg_match( $regex, $key_line ) ) { continue; }
            }

            $urls_count++;
        }

        return $urls_count;
    }

    // PHP fallback — RecursiveIterator when rg is unavailable
    $head_bytes_primary  = (int) apply_filters( 'nppp_locate_head_bytes', 4096 );
    $head_bytes_fallback = (int) apply_filters( 'nppp_locate_head_bytes_fallback', 32768 );
    $urls_count          = 0;

    try {
        $cache_iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($nginx_cache_path, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::LEAVES_ONLY
        );

        $regex_tested = false;
        foreach ($cache_iterator as $file) {
            $pathname = $file->getPathname();

            if (! $file->isReadable()) {
                return 'Undetermined';
            }

            $content = nppp_read_head($wp_filesystem, $pathname, $head_bytes_primary);
            if ($content === '') { continue; }

            $match = [];
            if (!preg_match('/^KEY:\s([^\r\n]*)/m', $content, $match)) {
                // Try fallback
                if (strlen($content) >= $head_bytes_primary) {
                    $content = nppp_read_head($wp_filesystem, $pathname, $head_bytes_fallback);
                    if ($content === '' || !preg_match('/^KEY:\s([^\r\n]*)/m', $content, $match)) {
                        continue;
                    }
                } else {
                    continue;
                }
            }

            // Ignore redirects
            if (strpos($content, 'Status: 301 Moved Permanently') !== false ||
                strpos($content, 'Status: 302 Found') !== false) {
                continue;
            }

            // Test regex only once
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
    } catch (Exception $e) {
        // Return 'Undetermined' if a permission issue occurs
        return 'Undetermined';
    }

    // Return the count of URLs, if no URLs found, return 0
    return $urls_count > 0 ? $urls_count : 0;
}

/**
 * Calculate live cache coverage.
 *
 * Cache count  : pre-computed by nppp_get_in_cache_page_count() and passed in
 *              to avoid a second expensive directory scan.
 * Total count: derived from the wget snapshot (nppp-wget-snapshot.log) via
 *              nppp_parse_wget_log_urls(), which is already used by the
 *              Advanced tab and is backed by a 5-minute transient.
 *              The snapshot is only ever written when a full Preload run
 *              completes, so it always represents a coherent, complete baseline.
 *
 * Coverage = Caches ÷ Total × 100
 * Meaning: "of every URL the crawler discovered, what % is currently in cache."
 *
 * Returns a formatted string like "87.5% (35 Cached / 40 Not Cached / 40 total)" or a
 * descriptive string when the data is not yet available.
 *
 */
function nppp_get_cache_ratio( $hits_count ) {
    // If the hit count is not a usable integer, we cannot compute a ratio.
    if ( ! is_numeric( $hits_count ) ) {
        // Propagate meaningful error strings as-is so the UI can show them.
        if ( in_array( $hits_count, [ 'Not Found', 'Undetermined', 'RegexError' ], true ) ) {
            return __( 'N/A', 'fastcgi-cache-purge-and-preload-nginx' );
        }
        return __( 'N/A', 'fastcgi-cache-purge-and-preload-nginx' );
    }

    $hits = (int) $hits_count;

    // Require WP_Filesystem to call nppp_parse_wget_log_urls().
    $wp_filesystem = nppp_initialize_wp_filesystem();
    if ( $wp_filesystem === false ) {
        return __( 'N/A', 'fastcgi-cache-purge-and-preload-nginx' );
    }

    // Check whether a completed snapshot exists. Without it we have no
    // reliable denominator and should not show a misleading ratio.
    $snapshot_path = nppp_get_runtime_file( 'nppp-wget-snapshot.log' );
    if ( ! $wp_filesystem->exists( $snapshot_path ) ) {
        return __( 'N/A', 'fastcgi-cache-purge-and-preload-nginx' );
    }

    // It returns a keyed array of every URL from the completed snapshot,
    // backed by a 5-min transient, so repeated calls are cheap.
    if ( ! function_exists( 'nppp_parse_wget_log_urls' ) ) {
        return __( 'N/A', 'fastcgi-cache-purge-and-preload-nginx' );
    }

    $wget_urls = nppp_parse_wget_log_urls( $wp_filesystem );
    $total     = count( $wget_urls );

    // An empty snapshot (all URLs were filtered out by reject-regex, etc.)
    // means we cannot compute a meaningful denominator.
    if ( $total === 0 ) {
        return __( 'N/A — snapshot empty', 'fastcgi-cache-purge-and-preload-nginx' );
    }

    // The cache can contain pages not included in the snapshot (e.g. manually
    // visited pages, paginated archives created after the last crawl).
    // We cap the ratio at 100 % to keep it intuitive.
    $ratio = min( 100.0, ( $hits / $total ) * 100.0 );

    // Return raw numbers — callers format for display themselves.
    // This prevents __() translations from silently breaking regex parsers.
    $misses = max( 0, $total - $hits );

    return [
        'ratio'  => round( $ratio, 1 ),
        'hits'   => $hits,
        'misses' => $misses,
        'total'  => $total,
    ];
}

// Function to check for same Nginx cache path for multiple instance
function nppp_check_duplicate_nginx_cache_paths($file, $wp_filesystem) {
    // Retrieve the cached result from the transient
    $transient_key = 'nppp_cache_paths_' . md5('nppp');
    $cached_result = get_transient($transient_key);

    // If nothing cached OR cached structure is missing/invalid, attempt a parse
    $needs_parse = (
        $cached_result === false ||
        !is_array($cached_result) ||
        !isset($cached_result['cache_paths']) ||
        !is_array($cached_result['cache_paths']) ||
        empty($cached_result['cache_paths'])
    );

    if ($needs_parse) {
        // Rebuild the cache from the config
        nppp_parse_nginx_config($file, $wp_filesystem);
        $cached_result = get_transient($transient_key);
    }

    // Safely extract cache paths
    $cache_paths = [];
    if (is_array($cached_result) && isset($cached_result['cache_paths']) && is_array($cached_result['cache_paths'])) {
        $cache_paths = $cached_result['cache_paths'];
    }

    // If still nothing usable, there can't be duplicates
    if (empty($cache_paths)) {
        return false;
    }

    // Detect duplicates (case- and trailing-slash–insensitive), but return originals
    $unique_paths = [];
    $duplicates = [];

    foreach ($cache_paths as $directive => $paths) {
        if (!is_array($paths)) { continue; }
        foreach ($paths as $path) {
            if (!is_string($path) || $path === '') { continue; }

            // Normalize once
            $normalized_path = rtrim(strtolower($path), '/');

            if (in_array($normalized_path, $unique_paths, true)) {
                $duplicates[] = $path;
            } else {
                $unique_paths[] = $normalized_path;
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
                    <p class="nppp-advanced-error-message">' . wp_kses(__('ERROR CONF: Unable to read or locate the <span style="color: #f0c36d;">nginx.conf</span> configuration file!', 'fastcgi-cache-purge-and-preload-nginx'), ['span' => ['style' => true]]) . '</p>
                </div>
                <div style="background-color: #f9edbe; border-left: 6px solid red; padding: 10px; margin-bottom: 15px; max-width: max-content;">
                    <p style="margin: 0; align-items: center;">
                        <span class="dashicons dashicons-warning" style="font-size: 22px; color: #721c24; margin-right: 8px;"></span>' . wp_kses(__('The <strong>nginx.conf</strong> file was not found in the <strong>default paths</strong>. This may indicate a <strong>custom Nginx setup</strong> with a non-standard configuration file location or permission issue. If you still encounter this error, please get help from the plugin support forum!', 'fastcgi-cache-purge-and-preload-nginx'), ['strong' => []]) . '</p>
                    </p>
                </div>';
    }

    $nginx_cache_settings = get_option('nginx_cache_settings', []);
    $nginx_cache_path     = isset($nginx_cache_settings['nginx_cache_path'])
        ? $nginx_cache_settings['nginx_cache_path']
        : '/dev/shm/change-me-now';

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

    // Pages in cache — read from last known option, never scan on Status tab load.
    // nppp_last_known_hits is updated at natural points: preload completion,
    // purge all, advanced tab index scan, send-mail, dashboard widget.
    $nppp_pages_in_cache  = get_option( 'nppp_last_known_hits',      false );
    $nppp_hits_scanned_at = get_option( 'nppp_last_hits_scanned_at', false );

    // Warn about not found cache key
    if (isset($config_data['cache_keys']) && $config_data['cache_keys'] === ['Not Found']) {
        echo '<div class="nppp-status-wrap">
                  <p class="nppp-advanced-error-message">' . wp_kses(__('INFO: No <span style="color: #FFDEAD;">_cache_key</span> directive was found.', 'fastcgi-cache-purge-and-preload-nginx'), ['span' => ['style' => true]]) . '</p>
              </div>';
    // Warn about non-default cache key — severity depends on whether it affects this site
    } elseif (isset($config_data['cache_keys']) && !empty($config_data['cache_keys'])) {
        if ( $nppp_pages_in_cache === 'RegexError' ) {
            echo '<div class="nppp-status-wrap">
                      <p class="nppp-advanced-error-message">' . wp_kses(__('INFO: <span style="color: #FFDEAD;">Non-default</span> cache key detected — Cache Key Regex update required.', 'fastcgi-cache-purge-and-preload-nginx'), ['span' => ['style' => true]]) . '</p>
                  </div>';
        } else {
            echo '<div class="nppp-status-wrap">
                      <p class="nppp-advanced-error-message">' . wp_kses(__('INFO: <span style="color: #FFDEAD;">Non-default</span> cache key found on server — not affecting this site.', 'fastcgi-cache-purge-and-preload-nginx'), ['span' => ['style' => true]]) . '</p>
                  </div>';
        }
    }

    // Warn about same Nginx cache path for multiple instance
    if ($duplicates !== false) {
        echo '<div class="nppp-status-wrap">
                  <p class="nppp-advanced-error-message">' . wp_kses(__('INFO: <span style="color: #FFDEAD;">Same</span> Nginx cache path found!', 'fastcgi-cache-purge-and-preload-nginx'), ['span' => ['style' => true]]) . '</p>
              </div>';
    }

    // Details about not found cache key
    if (isset($config_data['cache_keys']) && $config_data['cache_keys'] === ['Not Found']) {
        echo '<div style="background-color: #f9edbe; border-left: 6px solid #f0c36d; padding: 10px; margin-bottom: 15px; max-width: max-content;">
                 <p style="margin: 0; align-items: center;">
                     <span class="dashicons dashicons-warning" style="font-size: 22px; color: #ffba00; margin-right: 8px;"></span>' . wp_kses(__('Please check your <strong>Nginx cache setup</strong> to ensure that the <strong>cache key</strong> directive is defined. If you continue to encounter this error, this may indicate a <strong>parsing error</strong> and can be safely ignored.', 'fastcgi-cache-purge-and-preload-nginx'), ['strong' => []]) . '
                 </p>
             </div>';
    // Details about the non-default cache key
    } elseif (isset($config_data['cache_keys']) && !empty($config_data['cache_keys'])) {
        if ( $nppp_pages_in_cache === 'RegexError' ) {
            // Situation B — regex is failing on this site's actual cache files
            echo '<div style="background-color: #f9edbe; border-left: 6px solid red; padding: 10px; margin-bottom: 15px; max-width: max-content;">
                     <p style="margin: 0; align-items: center;">
                         <span class="dashicons dashicons-warning" style="font-size: 22px; color: #721c24; margin-right: 8px;"></span>' . sprintf(
                             /* translators: %1$s: Cache Key Regex option name, %2$s: Advanced Options section name */
                             wp_kses(__('Non-default <strong>_cache_key</strong> is active on this site. Update <strong>%1$s</strong> in <strong>%2$s</strong> to match your key format.', 'fastcgi-cache-purge-and-preload-nginx'), ['strong' => []]),
                             wp_kses(__('Cache Key Regex', 'fastcgi-cache-purge-and-preload-nginx'), []),
                             wp_kses(__('Advanced Options', 'fastcgi-cache-purge-and-preload-nginx'), [])
                         ) . '
                     </p>
                 </div>';
        } else {
            // Situation A — non-default key exists on another vhost, not on this site
            echo '<div style="background-color: #f9edbe; border-left: 6px solid #f0c36d; padding: 10px; margin-bottom: 15px; max-width: max-content;">
                     <p style="margin: 0; align-items: center;">
                         <span class="dashicons dashicons-warning" style="font-size: 22px; color: #ffba00; margin-right: 8px;"></span>' . wp_kses(__('Non-default <strong>_cache_key</strong> found on another vhost — no action needed for this site.', 'fastcgi-cache-purge-and-preload-nginx'), ['strong' => []]) . '
                     </p>
                 </div>';
        }
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
                        </tbody>
                    </table>
                </section>

                <!-- Preload Progress Section -->
                <section id="nppp-preload-progress-section">
                    <h2><?php esc_html_e( 'Preload Progress', 'fastcgi-cache-purge-and-preload-nginx' ); ?></h2>
                    <div class="nppp-progress-wrap">
                        <div class="nppp-bar-track">
                            <div id="wpt-bar-inner" class="nppp-bar-fill">
                                <span id="wpt-bar-text">0%</span>
                            </div>
                        </div>
                        <div id="wpt-status" class="nppp-progress-status">
                            <?php esc_html_e( 'Initializing...', 'fastcgi-cache-purge-and-preload-nginx' ); ?>
                        </div>
                    </div>
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
                                <td class="check"><?php esc_html_e('wget (Required)', 'fastcgi-cache-purge-and-preload-nginx'); ?></td>
                                <td class="status" id="npppwgetStatus">
                                    <span class="dashicons"></span>
                                    <span><?php echo esc_html(nppp_check_command_status('wget')); ?></span>
                                </td>
                            </tr>
                            <tr>
                                <td class="check"><?php esc_html_e('safexec (Recommended)', 'fastcgi-cache-purge-and-preload-nginx'); ?></td>
                                <td class="status" id="npppsafexecStatus">
                                    <span class="dashicons"></span>
                                    <span><?php echo esc_html(nppp_check_command_status('safexec')); ?></span>
                                </td>
                            </tr>
                            <tr>
                                <td class="check"><?php esc_html_e('rg (Recommended)', 'fastcgi-cache-purge-and-preload-nginx'); ?></td>
                                <td class="status" id="nppprgStatus">
                                    <span class="dashicons"></span>
                                    <span><?php echo esc_html(nppp_check_command_status('rg')); ?></span>
                                </td>
                            </tr>
                            <tr>
                                <td class="check"><?php esc_html_e('cpulimit (Optional)', 'fastcgi-cache-purge-and-preload-nginx'); ?></td>
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
                                    <?php if ( $nppp_pages_in_cache !== false ): ?>
                                        <span><?php echo esc_html( $nppp_pages_in_cache ); ?></span>
                                        <?php if ( $nppp_hits_scanned_at ): ?>
                                            <span style="font-size:11px; color:#888;">
                                                (<?php echo esc_html( human_time_diff( $nppp_hits_scanned_at, time() ) ); ?> ago)
                                            </span>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <span style="color:#888;"><?php esc_html_e( 'N/A — run a Preload to populate', 'fastcgi-cache-purge-and-preload-nginx' ); ?></span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <tr>
                                <td class="check"><?php esc_html_e('Cache Coverage', 'fastcgi-cache-purge-and-preload-nginx'); ?></td>
                                <td class="status" id="npppCacheHitRatio">
                                    <span class="dashicons"></span>
                                    <?php
                                    $nppp_status_ratio = nppp_get_cache_ratio( $nppp_pages_in_cache );
                                    if ( is_array( $nppp_status_ratio ) ) {
                                        $nppp_status_label = number_format( $nppp_status_ratio['ratio'], 1 ) . '%  ('
                                            . $nppp_status_ratio['hits']   . ' ' . __( 'Cached',     'fastcgi-cache-purge-and-preload-nginx' ) . ' / '
                                            . $nppp_status_ratio['misses'] . ' ' . __( 'Not Cached', 'fastcgi-cache-purge-and-preload-nginx' ) . ' / '
                                            . $nppp_status_ratio['total']  . ' ' . __( 'total',      'fastcgi-cache-purge-and-preload-nginx' ) . ')';
                                        echo '<span>' . esc_html( $nppp_status_label ) . '</span>';
                                    } else {
                                        echo '<span>' . esc_html( $nppp_status_ratio ) . '</span>';
                                    }
                                    ?>
                                </td>
                            </tr>
                            <tr>
                                <td class="check"><?php esc_html_e('Cache RAM/Disk Size', 'fastcgi-cache-purge-and-preload-nginx'); ?></td>
                                <td class="status" id="npppCacheDiskSize">
                                    <span class="dashicons"></span>
                                    <?php
                                    $nppp_disk = nppp_get_cache_disk_size( $nginx_cache_path );
                                    if ( $nppp_disk === null ): ?>
                                        <span style="color: orange;"><?php esc_html_e('Unavailable', 'fastcgi-cache-purge-and-preload-nginx'); ?></span>
                                    <?php elseif ( $nppp_disk['dedicated'] ): ?>
                                        <span><?php echo esc_html(
                                            ( $nppp_disk['total'] > 0 ? number_format( ( $nppp_disk['used'] / $nppp_disk['total'] ) * 100, 1 ) : 0 )
                                            . '% ('
                                            . nppp_format_cache_size( $nppp_disk['used'] )
                                            . ' used / '
                                            . nppp_format_cache_size( $nppp_disk['total'] )
                                            . ' total)'
                                        ); ?></span>
                                    <?php else: ?>
                                        <span><?php echo esc_html(
                                            nppp_format_cache_size( $nppp_disk['used'] )
                                            . ' cache dir ('
                                            . nppp_format_cache_size( $nppp_disk['free'] )
                                            . ' free on partition)'
                                        ); ?></span>
                                    <?php endif; ?>
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
            wp_send_json_error(__('Nonce verification failed.', 'fastcgi-cache-purge-and-preload-nginx'));
        }
    } else {
        wp_send_json_error(__('Nonce is missing.', 'fastcgi-cache-purge-and-preload-nginx'));
    }

    // Check user capability
    if (!current_user_can('manage_options')) {
        wp_send_json_error(__('You do not have permission to access this action.', 'fastcgi-cache-purge-and-preload-nginx'));
    }

    // Clear the plugin cache
    nppp_clear_plugin_cache();

    // Success
    wp_send_json_success(
        __('Plugin cache cleared successfully.', 'fastcgi-cache-purge-and-preload-nginx')
    );
}

// AJAX handler to clear the URL→path index
function nppp_clear_url_index_callback() {
    if ( ! current_user_can('manage_options') ) {
        wp_send_json_error( __( 'Insufficient permissions.', 'fastcgi-cache-purge-and-preload-nginx' ) );
    }

    $nonce = isset( $_POST['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ) : '';
    if ( ! wp_verify_nonce( $nonce, 'nppp-clear-url-index' ) ) {
        wp_send_json_error( __( 'Nonce verification failed.', 'fastcgi-cache-purge-and-preload-nginx' ) );
    }

    delete_option( 'nppp_url_filepath_index' );
    wp_send_json_success( __( 'URL index cleared successfully.', 'fastcgi-cache-purge-and-preload-nginx' ) );
}

// AJAX handler to fetch shortcode content
function nppp_cache_status_callback() {
    // Check nonce
    if (isset($_POST['_wpnonce'])) {
        $nonce = sanitize_text_field(wp_unslash($_POST['_wpnonce']));
        if (!wp_verify_nonce($nonce, 'cache-status')) {
            wp_die('', '', ['response' => 403]);
        }
    } else {
        wp_die('', '', ['response' => 403]);
    }

    // Check user capability
    if (!current_user_can('manage_options')) {
        wp_die('', '', ['response' => 403]);
    }

    // On a large cache (100 k+ files)
    // on slow or network-attached storage this can easily exceed the default
    // 30-second ceiling that most PHP-FPM pools ship with, killing the process
    // mid-operation.

    if (function_exists('set_time_limit')) {
        @set_time_limit(0); // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged
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
    // This shortcode exposes server internals (nginx paths, filesystem permissions,
    // PHP process owner, web server user). Never render it for non-admins.
    if (! current_user_can('manage_options')) {
        return '';
    }
    return nppp_my_status_html();
}
