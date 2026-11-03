<?php
/**
 * Status page renderer for Nginx Cache Purge Preload
 * Description: Collects and displays runtime diagnostics, cache details, and health information.
 * Version: 2.1.8
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
// With v2.1.6 replaced expensive recursive iterator scanning with a lightweight write/delete probe in nppp_check_permissions_recursive.
// As a result with v2.1.7 transient cache duration lowered 1Month to 1min for accurate real time perm issue detection.
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
        set_transient($transient_key, $result, MINUTE_IN_SECONDS);
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
        'nppp_cron_reliability_' . md5($static_key_base),
        'nppp_missing_commands_' . md5($static_key_base),
        'nppp_mail_health_' . md5($static_key_base),
        'nppp_wget_urls_cache_prev_key',
        'nppp_vo_preload_meta',
        'nppp_vo_check_lock',
        'nppp_safexec_ok',
        'nppp_category_map',
        'nppp_rg_ok',
        'nppp_wget_version_' . md5($static_key_base),
        'nppp_rg_version_' . md5($static_key_base),
        'nppp_pages_in_cache_' . md5($static_key_base),
        'nppp_obd_warned_' . md5($static_key_base),
        'nppp_vary_issue_' . md5($static_key_base),
        'nppp_cache_key_regex_probe',
        'nppp_http_probe_' . md5($static_key_base),
        'nppp_setup_strict_detect_' . md5($static_key_base),
        'nppp_nginx_conf_hit_' . md5($static_key_base),
        'nppp_requirements_met_' . md5($static_key_base),
        'nppp_f2b_rl',
        'nppp_f2b_worker_env',
    );

    // Delete each known transient
    foreach ($transients as $transient) {
        delete_transient($transient);
    }

    // Option-backed state (includes/state.php): the reset helpers also drop the pre-2.1.9 transients.
    nppp_http_purge_state_reset();
    nppp_state_delete( 'http_purge_mode' );
    nppp_state_delete( 'traffic_hint' );
    // Varnish / Vinyl Cache Sync: forget parked endpoints and warning throttles so a fixed setup is retried at once.
    nppp_state_delete( 'varnish_breaker' );
    nppp_state_delete( 'varnish_warn' );
    // Status tab card (Varnish-only mode): the stored live check is a cached probe. The purge log and the endpoint timings are history and stay.
    nppp_state_delete( 'varnish_vo_check' );
    nppp_gen_mode_flag_delete();

    // Re-arm the Vary: Accept-Encoding probe and its notice row so the
    // detection runs fresh on the next settings page load.
    delete_option( 'nppp_vary_notice_dismissed' );

    // Re-arm the DISABLE_WP_CRON notice row the same way.
    delete_option( 'nppp_cron_notice_dismissed' );

    // Job state that must not be cleared while a preload is running:
    //   nppp_preload_phase (option)       — tick monitor reads this every 5s to track desktop/mobile phase
    //   nppp_preload_cycle_start (option) — needed at completion to calculate total elapsed time
    //   nppp_preload_trigger (option)     — shown in the completion mail; read once at completion
    //   nppp_ping_token_ (transient)      — watchdog validates this token when preload finishes;
    //                                       deleting it causes watchdog to 403 and post-preload tasks never fire
    $wp_filesystem = nppp_initialize_wp_filesystem();
    if ( ! nppp_preload_job_active( $wp_filesystem ) ) {
        nppp_preload_phase_delete();
        nppp_preload_cycle_start_delete( false ); // orphan record, no crawl ran: nothing to exclude.
        nppp_preload_trigger_delete();
        $job_transients = array(
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
    $like_ep10_fail             = $wpdb->esc_like('_transient_nppp_ep10_fail_') . '%';
    $like_ep10_fail_timeout     = $wpdb->esc_like('_transient_timeout_nppp_ep10_fail_') . '%';

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
            $like_ep3_fail_timeout,
            $like_ep10_fail,
            $like_ep10_fail_timeout
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
        if (nppp_check_path() === 'Found') {
            $computed = nppp_check_permissions_recursive_with_cache();
            $result   = ($computed === 'true') ? 'true' : 'false';
        } else {
            $result = 'false';
        }
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

    if ( ! function_exists( 'shell_exec' ) ) {
        return 'Not Installed';
    }

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
    if ( empty( $path ) || ! @is_dir( $path ) ) {
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
    $raw = function_exists( 'shell_exec' ) ? shell_exec( 'du -sb ' . escapeshellarg( $path ) . ' 2>/dev/null' ) : null;
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
        $process_owner = function_exists( 'shell_exec' ) ? shell_exec($command) : null;

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
        set_transient($transient_key, "Not Determined", HOUR_IN_SECONDS);
        return "Not Determined";
    }

    // Set env
    nppp_prepare_request_env(true);

    // Check the running processes for Nginx
    $nginx_user_process = function_exists( 'shell_exec' )
        ? shell_exec("ps aux | grep -E '[a]pache|[h]ttpd|[_]www|[w]ww-data|[n]ginx' | grep -v 'root' | awk '{print $1}' | sort | uniq")
        : null;

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
            set_transient($transient_key, $nginx_user_conf, HOUR_IN_SECONDS);
            return $nginx_user_conf;
        }
    }

    // If only the configuration user is found, return it
    if (!empty($nginx_user_conf)) {
        set_transient($transient_key, $nginx_user_conf, HOUR_IN_SECONDS);
        return $nginx_user_conf;
    }

    // If only the process user is found, return it
    if (!empty($process_users)) {
        $user = reset($process_users);
        set_transient($transient_key, $user, HOUR_IN_SECONDS);
        return $user;
    }

    // If no user is found, return "Not Determined"
    set_transient($transient_key, "Not Determined", HOUR_IN_SECONDS);
    return "Not Determined";
}

// Function to get pages in cache count
function nppp_get_in_cache_page_count() {
    $nginx_cache_settings = get_option('nginx_cache_settings', []);
    $default_cache_path   = '/dev/shm/change-me-now';
    $nginx_cache_path     = isset($nginx_cache_settings['nginx_cache_path']) ? $nginx_cache_settings['nginx_cache_path'] : $default_cache_path;

    // Central getter
    $regex = nppp_get_cache_key_regex();

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
    $rg_cached = get_transient( 'nppp_rg_ok' );
    if ( $rg_cached === false ) {
        $rg_bin = function_exists( 'shell_exec' ) ? trim( (string) shell_exec( 'command -v rg 2>/dev/null' ) ) : '';
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
        // Only 0/1 are completed rg scans; anything else (e.g. a safexec
        // launch refusal) is not proof there are no redirect entries.
        if ( $redirect_exit !== 0 && $redirect_exit !== 1 ) {
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
        // Only 0/1 are completed rg scans; anything else (e.g. a safexec
        // launch refusal) is not proof the cache is empty.
        if ( $key_exit !== 0 && $key_exit !== 1 ) {
            return 'Undetermined';
        }
        if ( $key_exit === 1 || empty( $key_out ) ) {
            return 0;
        }

        $urls_count   = 0;
        $regex_tested = false;
        $gen_seen     = []; // generation mode: group => [ highest generation, files at it ]
        $gen_before   = false; // namespace state, read once at the first generation key

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

            // Generation mode: only files at the highest generation of their key count.
            $gen_info = nppp_key_generation_info( $key_line );
            if ( $gen_info !== null && $gen_before === false ) {
                $gen_before = nppp_generation_views();
            }
            if ( $gen_info === null ) {
                $urls_count++;
            } elseif ( ! isset( $gen_seen[ $gen_info['group'] ] ) || strcmp( $gen_info['gen'], $gen_seen[ $gen_info['group'] ][0] ) > 0 ) {
                $gen_seen[ $gen_info['group'] ] = [ $gen_info['gen'], 1 ];
            } elseif ( $gen_info['gen'] === $gen_seen[ $gen_info['group'] ][0] ) {
                $gen_seen[ $gen_info['group'] ][1]++;
            }
        }

        // Files at the highest generation of each key, minus groups the namespace state proves stale
        $urls_count += nppp_generation_count_live( $gen_seen, $gen_before );

        return $urls_count;
    }

    // PHP fallback — RecursiveIterator when rg is unavailable
    $head_bytes_primary  = (int) apply_filters( 'nppp_locate_head_bytes', 4096 );
    $head_bytes_fallback = (int) apply_filters( 'nppp_locate_head_bytes_fallback', 32768 );
    $urls_count          = 0;
    $gen_seen            = []; // generation mode: group => [ highest generation, files at it ]
    $gen_before          = false; // namespace state, read once at the first generation key

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
                // Generation mode: only files at the highest generation of their key count.
                $gen_info = nppp_key_generation_info($content);
                if ($gen_info !== null && $gen_before === false) {
                    $gen_before = nppp_generation_views();
                }
                if ($gen_info === null) {
                    $urls_count++;
                } elseif (!isset($gen_seen[$gen_info['group']]) || strcmp($gen_info['gen'], $gen_seen[$gen_info['group']][0]) > 0) {
                    $gen_seen[$gen_info['group']] = [$gen_info['gen'], 1];
                } elseif ($gen_info['gen'] === $gen_seen[$gen_info['group']][0]) {
                    $gen_seen[$gen_info['group']][1]++;
                }
            }
        }
    } catch (Exception $e) {
        // Return 'Undetermined' if a permission issue occurs
        return 'Undetermined';
    }

    // Files at the highest generation of each key, minus groups the namespace state proves stale
    $urls_count += nppp_generation_count_live($gen_seen, $gen_before);

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

// Short, translated reason of an armed HTTP Purge lock for the Status tab.
// $reason is what nppp_http_purge_report_failure() stored: an outcome name,
// or the HTTP status for an unexpected answer. Older plugin versions stored
// other strings ("403", "wp_error", ...), which land in the generic label.
function nppp_status_http_purge_reason_label( string $reason ): string {
    switch ( $reason ) {
        case 'unreachable':
            return __( 'connection failed', 'fastcgi-cache-purge-and-preload-nginx' );
        case 'denied':
            return __( 'access denied (403)', 'fastcgi-cache-purge-and-preload-nginx' );
        case 'method':
            return __( 'method not allowed (405)', 'fastcgi-cache-purge-and-preload-nginx' );
        case 'foreign':
            return __( 'answer not from ngx_cache_purge', 'fastcgi-cache-purge-and-preload-nginx' );
        case 'wrong_endpoint':
            return __( 'wrong purge endpoint', 'fastcgi-cache-purge-and-preload-nginx' );
        case 'config':
            return __( 'Nginx configuration', 'fastcgi-cache-purge-and-preload-nginx' );
        case 'internal':
            return __( 'Nginx internal error', 'fastcgi-cache-purge-and-preload-nginx' );
    }

    if ( ctype_digit( $reason ) ) {
        return sprintf(
            /* translators: %d: HTTP status code Nginx answered with */
            __( 'unexpected response (%d)', 'fastcgi-cache-purge-and-preload-nginx' ),
            (int) $reason
        );
    }

    return __( 'endpoint error', 'fastcgi-cache-purge-and-preload-nginx' );
}

/**
 * Verdict of the HTTP Purge endpoint for the Status tab "HTTP Purge" row.
 *
 * Built only from what NPP already remembers (settings, endpoint state, observed
 * mode): no HTTP request and no filesystem scan, so it costs a few option reads.
 *
 *   off   HTTP Purge is disabled in Settings.
 *   wait  Enabled, but no HTTP purge answer was seen yet (or Clear Plugin Cache forgot it).
 *   ok    Nginx answered: mode and setup are known.
 *   warn  A lock is armed (NPP purges through the filesystem until it expires), or the
 *         Cache Status Path advisory needs attention.
 *   bad   A configuration fault the administrator has to fix in Nginx.
 *
 * The time of the observed mode is deliberately NOT shown as "last purge": it is only
 * rewritten on a change or every 6 hours (nppp_http_purge_remember_mode()), so it can lag
 * the real last purge by hours. It is kept in the tooltip, with that caveat.
 *
 * 'meta' is a list of short detail lines; the row prints each one on its own line.
 *
 * @return array{state:string,icon:string,text:string,meta:string[],title:string}|null
 *         null when the HTTP Purge module is not loaded (the row is then left out).
 */
function nppp_get_http_purge_status(): ?array {
    if ( ! function_exists( 'nppp_http_purge_enabled' ) || ! function_exists( 'nppp_http_purge_state' ) ) {
        return null;
    }

    $icons = [
        'off'  => 'dashicons-minus',
        'wait' => 'dashicons-clock',
        'ok'   => 'dashicons-yes',
        'warn' => 'dashicons-warning',
        'bad'  => 'dashicons-no',
    ];

    if ( ! nppp_http_purge_enabled() ) {
        return [
            'state' => 'off',
            'icon'  => $icons['off'],
            'text'  => __( 'Disabled', 'fastcgi-cache-purge-and-preload-nginx' ),
            'meta'  => [],
            'title' => __( 'HTTP Purge is turned off in Settings. NPP uses its URL index and the filesystem to purge.', 'fastcgi-cache-purge-and-preload-nginx' ),
        ];
    }

    $scope_label = [
        'single' => __( 'Single URL', 'fastcgi-cache-purge-and-preload-nginx' ),
        'all'    => __( 'Purge All', 'fastcgi-cache-purge-and-preload-nginx' ),
        '*'      => __( 'All endpoints', 'fastcgi-cache-purge-and-preload-nginx' ),
    ];
    $now   = time();
    $state = nppp_http_purge_state();

    // Configuration faults: remembered for a day, cleared by the next successful purge of that scope.
    $issue_codes  = [];
    $issue_scopes = [];
    $tip          = [];
    foreach ( [ 'single', 'all' ] as $scope ) {
        if ( ! isset( $state['issue'][ $scope ] ) ) {
            continue;
        }
        $code            = (string) $state['issue'][ $scope ]['code'];
        $issue_codes[]   = $code === 'key_mismatch'
            ? __( 'cache key mismatch', 'fastcgi-cache-purge-and-preload-nginx' )
            : __( 'no usable cache zone', 'fastcgi-cache-purge-and-preload-nginx' );
        $issue_scopes[]  = $scope_label[ $scope ];
        $tip[]           = $scope_label[ $scope ] . ': ' . nppp_http_purge_config_fix( $scope, $code );
    }

    // Armed locks: '*' blocks both purges, so it comes first.
    $lock_reasons = [];
    $lock_scopes  = [];
    $retry_at     = 0;
    foreach ( [ '*', 'single', 'all' ] as $scope ) {
        if ( ! isset( $state['locks'][ $scope ] ) ) {
            continue;
        }
        $reason = nppp_status_http_purge_reason_label( (string) $state['locks'][ $scope ]['reason'] );
        $until  = (int) $state['locks'][ $scope ]['until'];
        $line   = $scope_label[ $scope ] . ': ' . $reason;

        // A pre-2.1.9 lock has no end of its own (PHP_INT_MAX): never print a retry time for it.
        if ( $until - $now < DAY_IN_SECONDS ) {
            $retry_at = $retry_at > 0 ? min( $retry_at, $until ) : $until;
            $line    .= ', ' . sprintf(
                /* translators: %s: human readable time difference, e.g. "42 mins" */
                __( 'retry in %s', 'fastcgi-cache-purge-and-preload-nginx' ),
                human_time_diff( $now, max( $until, $now + 1 ) )
            );
        }

        $lock_reasons[ $reason ] = true;
        $lock_scopes[]           = $scope_label[ $scope ];
        $tip[]                   = $line;
    }

    if ( $issue_codes ) {
        $tip[] = __( 'Until fixed, NPP purges through the filesystem (slower).', 'fastcgi-cache-purge-and-preload-nginx' );

        return [
            'state' => 'bad',
            'icon'  => $icons['bad'],
            'text'  => sprintf(
                /* translators: %s: what is wrong, e.g. "cache key mismatch" */
                __( 'Needs fix: %s', 'fastcgi-cache-purge-and-preload-nginx' ),
                implode( ', ', array_unique( $issue_codes ) )
            ),
            'meta'  => [
                sprintf(
                    /* translators: %s: affected purge types, e.g. "Single URL, Purge All" */
                    __( 'Affects: %s', 'fastcgi-cache-purge-and-preload-nginx' ),
                    implode( ', ', $issue_scopes )
                ),
            ],
            'title' => implode( "\n", $tip ),
        ];
    }

    if ( $lock_reasons ) {
        $tip[] = __( 'NPP purges through the filesystem (slower) until HTTP Purge is retried. "Clear Plugin Cache" at the top of this tab retries it now.', 'fastcgi-cache-purge-and-preload-nginx' );

        $meta = [
            sprintf(
                /* translators: %s: affected purge types, e.g. "Single URL, Purge All" */
                __( 'Affects: %s', 'fastcgi-cache-purge-and-preload-nginx' ),
                implode( ', ', $lock_scopes )
            ),
        ];
        if ( $retry_at > 0 ) {
            $meta[] = sprintf(
                /* translators: %s: human readable time difference, e.g. "42 mins" */
                __( 'Retry in %s', 'fastcgi-cache-purge-and-preload-nginx' ),
                human_time_diff( $now, max( $retry_at, $now + 1 ) )
            );
        }

        return [
            'state' => 'warn',
            'icon'  => $icons['warn'],
            'text'  => sprintf(
                /* translators: %s: why HTTP Purge was paused, e.g. "access denied (403)" */
                __( 'Fallback active: %s', 'fastcgi-cache-purge-and-preload-nginx' ),
                implode( ', ', array_keys( $lock_reasons ) )
            ),
            'meta'  => $meta,
            'title' => implode( "\n", $tip ),
        ];
    }

    // Advisory about the Cache Status Path (Settings > HTTP Purge). Only a "warning" level changes the verdict.
    $hint      = function_exists( 'nppp_http_purge_queue_status_hint' ) ? nppp_http_purge_queue_status_hint() : null;
    $hint_warn = is_array( $hint ) && ( $hint['level'] ?? '' ) === 'warning';

    // Nothing wrong is remembered: say what NPP last saw Nginx answer.
    $mode = function_exists( 'nppp_http_purge_observed_mode' ) ? nppp_http_purge_observed_mode() : null;
    if ( $mode === null ) {
        $tip[] = __( 'The mode is detected from the next HTTP purge answer of Nginx.', 'fastcgi-cache-purge-and-preload-nginx' );

        return [
            'state' => 'wait',
            'icon'  => $icons['wait'],
            'text'  => __( 'Not detected yet', 'fastcgi-cache-purge-and-preload-nginx' ),
            'meta'  => [ __( 'Detected on the next HTTP purge', 'fastcgi-cache-purge-and-preload-nginx' ) ],
            'title' => implode( "\n", $tip ),
        ];
    }

    $meta = [
        sprintf(
            /* translators: %s: HTTP Purge mode (Sync, Async Background Queue, Invalidation) */
            __( 'Mode: %s', 'fastcgi-cache-purge-and-preload-nginx' ),
            nppp_http_purge_mode_label( $mode['mode'] )
        ),
    ];

    $tip[] = sprintf(
        /* translators: 1: "Purge All" or "single URL purge", 2: human readable time difference, e.g. "3 mins" */
        __( 'Observed on %1$s, %2$s ago', 'fastcgi-cache-purge-and-preload-nginx' ),
        $mode['scope'] === 'all'
            ? __( 'Purge All', 'fastcgi-cache-purge-and-preload-nginx' )
            : __( 'single URL purge', 'fastcgi-cache-purge-and-preload-nginx' ),
        human_time_diff( $mode['at'], $now )
    );
    $tip[] = __( 'This time is refreshed at most every 6 hours, so it is not the time of the last purge.', 'fastcgi-cache-purge-and-preload-nginx' );

    $setup = function_exists( 'nppp_http_purge_observed_setup' ) ? nppp_http_purge_observed_setup() : '';
    if ( $setup !== '' ) {
        $meta[] = sprintf(
            /* translators: %s: how the single URL purge reaches Nginx (Inline PURGE, Dedicated location) */
            __( 'Setup: %s', 'fastcgi-cache-purge-and-preload-nginx' ),
            nppp_http_purge_setup_label( $setup )
        );
        $tip[] = $setup === 'inline'
            ? __( 'Single URL purge: inline "cache_purge PURGE" in the cached location, sent to the page URL. Purge All uses its own location.', 'fastcgi-cache-purge-and-preload-nginx' )
            : __( 'Single URL purge: dedicated purge location.', 'fastcgi-cache-purge-and-preload-nginx' );
    }

    if ( $hint_warn ) {
        $meta[] = __( 'Check the Cache Status Path in Settings', 'fastcgi-cache-purge-and-preload-nginx' );
    }
    if ( is_array( $hint ) && ! empty( $hint['text'] ) ) {
        $tip[] = (string) $hint['text'];
    }

    return [
        'state' => $hint_warn ? 'warn' : 'ok',
        'icon'  => $hint_warn ? $icons['warn'] : $icons['ok'],
        'text'  => __( 'Active', 'fastcgi-cache-purge-and-preload-nginx' ),
        'meta'  => $meta,
        'title' => implode( "\n", $tip ),
    ];
}

// Generate HTML for status tab
/**
 * Varnish-only mode: state of the Varnish / Vinyl Cache purge for the Status tab row.
 *
 * Built from stored state only (circuit breaker, last failure, last success): it sends no request,
 * so rendering the tab never waits on Varnish. Returns null outside Varnish-only mode.
 *
 * @return array{state:string,icon:string,text:string,meta:string[],title:string}|null
 */
function nppp_get_varnish_vo_status(): ?array {
    if ( ! function_exists( 'nppp_varnish_only_mode' ) || ! function_exists( 'nppp_state_get' ) || ! nppp_varnish_only_mode() ) {
        return null;
    }

    $icons = [
        'ok'   => 'dashicons-yes',
        'wait' => 'dashicons-clock',
        'warn' => 'dashicons-warning',
        'bad'  => 'dashicons-no',
    ];

    $endpoints = nppp_varnish_endpoints();
    $parked    = 0;
    foreach ( $endpoints as $endpoint ) {
        if ( nppp_varnish_breaker_open( $endpoint ) ) {
            $parked++;
        }
    }

    $failure = nppp_state_get( 'varnish_vo_failure' );
    $last_ok = nppp_state_get( 'varnish_vo_last_ok' );
    $failure = ( is_array( $failure ) && ! empty( $failure['at'] ) ) ? $failure : null;
    $last_ok = ( is_array( $last_ok ) && ! empty( $last_ok['at'] ) ) ? $last_ok : null;

    $now = time();
    /* translators: %s: time span such as "3 mins" */
    $ago = static function ( int $ts ) use ( $now ): string {
        return sprintf( __( '%s ago', 'fastcgi-cache-purge-and-preload-nginx' ), human_time_diff( $ts, $now ) );
    };
    $scope_label = static function ( $scope ): string {
        return $scope === 'all'
            ? __( 'Purge All', 'fastcgi-cache-purge-and-preload-nginx' )
            : __( 'Single URL', 'fastcgi-cache-purge-and-preload-nginx' );
    };

    if ( $parked > 0 ) {
        $state = 'bad';
        $text  = __( 'Endpoint parked', 'fastcgi-cache-purge-and-preload-nginx' );
        $title = __( 'A Varnish / Vinyl Cache endpoint failed recently and is parked for a few seconds. Purges are retried automatically once the window ends.', 'fastcgi-cache-purge-and-preload-nginx' );
    } elseif ( $failure !== null ) {
        $partial = ( isset( $failure['status'] ) && $failure['status'] === 'partial' );
        $state   = $partial ? 'warn' : 'bad';
        $text    = $partial
            ? __( 'Last purge partly failed', 'fastcgi-cache-purge-and-preload-nginx' )
            : __( 'Last purge failed', 'fastcgi-cache-purge-and-preload-nginx' );
        $title   = __( 'Varnish-only mode has no other cache layer: pages that were not purged stay cached until their TTL expires. Run Purge All once Varnish is reachable.', 'fastcgi-cache-purge-and-preload-nginx' );
    } elseif ( $last_ok !== null ) {
        $state = 'ok';
        $text  = __( 'Purge OK', 'fastcgi-cache-purge-and-preload-nginx' );
        $title = __( 'The last purge sent to Varnish / Vinyl Cache was accepted.', 'fastcgi-cache-purge-and-preload-nginx' );
    } else {
        $state = 'wait';
        $text  = __( 'No purge yet', 'fastcgi-cache-purge-and-preload-nginx' );
        $title = __( 'No purge has been sent to Varnish / Vinyl Cache since the mode was switched on (or for 30 days). Run Purge All to confirm the setup.', 'fastcgi-cache-purge-and-preload-nginx' );
    }

    $meta = [];
    /* translators: %d: number of configured Varnish endpoints */
    $meta[] = sprintf( _n( '%d endpoint', '%d endpoints', count( $endpoints ), 'fastcgi-cache-purge-and-preload-nginx' ), count( $endpoints ) );
    if ( $last_ok !== null ) {
        /* translators: 1: scope (Purge All / Single URL), 2: time since */
        $meta[] = sprintf( __( 'Last success: %1$s, %2$s', 'fastcgi-cache-purge-and-preload-nginx' ), $scope_label( $last_ok['scope'] ?? '' ), $ago( (int) $last_ok['at'] ) );
    }
    if ( $failure !== null ) {
        $detail = ( ! empty( $failure['detail'] ) && is_string( $failure['detail'] ) ) ? ': ' . $failure['detail'] : '';
        /* translators: 1: scope (Purge All / Single URL), 2: time since, 3: failure details */
        $meta[] = sprintf( __( 'Last failure: %1$s, %2$s%3$s', 'fastcgi-cache-purge-and-preload-nginx' ), $scope_label( $failure['scope'] ?? '' ), $ago( (int) $failure['at'] ), $detail );
    }

    return [
        'state' => $state,
        'icon'  => $icons[ $state ],
        'text'  => $text,
        'meta'  => $meta,
        'title' => $title,
    ];
}

/**
 * Varnish-only mode, Status tab card: seconds as "42s", "4m 12s" or "1h 5m".
 */
function nppp_varnish_vo_duration( $seconds ): string {
    $s = (int) max( 0, round( (float) $seconds ) );
    if ( $s < 60 ) {
        return $s . 's';
    }
    if ( $s < 3600 ) {
        return intdiv( $s, 60 ) . 'm ' . ( $s % 60 ) . 's';
    }

    return intdiv( $s, 3600 ) . 'h ' . intdiv( $s % 3600, 60 ) . 'm';
}

/**
 * "3 mins ago" for a unix timestamp.
 */
function nppp_varnish_vo_ago( int $ts ): string {
    /* translators: %s: time span such as "3 mins" */
    return sprintf( __( '%s ago', 'fastcgi-cache-purge-and-preload-nginx' ), human_time_diff( $ts, time() ) );
}

/**
 * One status cell of the card: dashicon + verdict + optional detail lines (same markup idea as the left panel).
 *
 * @param string   $state ok | warn | bad | wait | off
 * @param string[] $meta  Detail lines, one per line, plain text.
 * @param string   $icon  Dashicon class override, e.g. dashicons-lock.
 * @return string HTML, escaped.
 */
function nppp_varnish_vo_cell( string $state, string $text, array $meta = array(), string $title = '', string $icon = '' ): string {
    $icons = array(
        'ok'   => 'dashicons-yes',
        'warn' => 'dashicons-warning',
        'bad'  => 'dashicons-no',
        'wait' => 'dashicons-clock',
        'off'  => 'dashicons-minus',
    );
    $icon  = ( $icon !== '' ) ? $icon : ( $icons[ $state ] ?? $icons['off'] );

    $html  = '<td class="status nppp-vo-st nppp-vo-' . esc_attr( $state ) . '"' . ( $title !== '' ? ' title="' . esc_attr( $title ) . '"' : '' ) . '>';
    $html .= '<span class="dashicons ' . esc_attr( $icon ) . '" aria-hidden="true"></span> ';
    $html .= '<span class="nppp-vo-text">' . esc_html( $text ) . '</span>';
    foreach ( $meta as $line ) {
        if ( is_string( $line ) && $line !== '' ) {
            $html .= '<span class="nppp-vo-meta">' . esc_html( $line ) . '</span>';
        }
    }

    return $html . '</td>';
}

/**
 * One table row: label cell + prepared status cell.
 *
 * @param string $label_html Label, already escaped (may hold a <span class="nppp-vo-mono">).
 * @param string $cell_html  A complete <td> (nppp_varnish_vo_cell() or a custom one).
 */
function nppp_varnish_vo_row( string $label_html, string $cell_html ): string {
    return '<tr><td class="action">' . $label_html . '</td>' . $cell_html . '</tr>';
}

/**
 * One blue-banner section of the card.
 *
 * @param string $id        Element id ('' for none). The live check section is replaced by AJAX through its id.
 * @param string $head_left Column title of the first column.
 * @param string $rows      <tr> rows.
 */
function nppp_varnish_vo_section( string $id, string $title, string $head_left, string $rows ): string {
    ob_start();
    ?>
    <section class="nginx-status nppp-vo-section"<?php echo $id !== '' ? ' id="' . esc_attr( $id ) . '"' : ''; ?>>
        <h2><?php echo esc_html( $title ); ?></h2>
        <table>
            <thead>
                <tr>
                    <th class="check-header"><span class="dashicons dashicons-admin-generic"></span> <?php echo esc_html( $head_left ); ?></th>
                    <th class="status-header"><span class="dashicons dashicons-info"></span> <?php esc_html_e( 'Status', 'fastcgi-cache-purge-and-preload-nginx' ); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php echo $rows; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- every row is built from escaped parts by nppp_varnish_vo_row() / nppp_varnish_vo_cell() ?>
            </tbody>
        </table>
    </section>
    <?php
    return (string) ob_get_clean();
}

/**
 * Finish time, URL count and duration of the last completed Preload All.
 *
 * All three come from the wget summary of the completed-crawl snapshot (the same FINISHED / Downloaded / wall
 * clock lines schedule.php parses for the completion mail and for the "last preload" transient). The parsed
 * result is cached against the snapshot's mtime: the file is read once per crawl, never on a later render.
 *
 * nppp_get_last_preload_complete_date() is only the fallback for a snapshot without a FINISHED line. It is not
 * the primary source because, once its 24 h transient has expired, it re-reads the whole operations log on every call
 * and does not cache the result, which a tab that is opened often should not pay for.
 *
 * @return array{at:int,files:?int,elapsed:string}|null null when no Preload All has completed yet.
 */
function nppp_varnish_vo_last_preload(): ?array {
    $finished = '';
    $files    = null;
    $elapsed  = '';

    $wp_filesystem = nppp_initialize_wp_filesystem();
    if ( $wp_filesystem !== false ) {
        $snapshot = nppp_get_runtime_file( 'nppp-wget-snapshot.log' );
        if ( $wp_filesystem->exists( $snapshot ) ) {
            $mtime  = (int) $wp_filesystem->mtime( $snapshot );
            $cached = get_transient( 'nppp_vo_preload_meta' );

            if ( is_array( $cached ) && isset( $cached['mtime'], $cached['finished'], $cached['files'], $cached['elapsed'] ) && (int) $cached['mtime'] === $mtime ) {
                $finished = (string) $cached['finished'];
                $files    = ( $cached['files'] === null ) ? null : (int) $cached['files'];
                $elapsed  = (string) $cached['elapsed'];
            } else {
                $contents = $wp_filesystem->get_contents( $snapshot );
                if ( is_string( $contents ) && $contents !== '' ) {
                    if ( preg_match( '/^FINISHED\s+--([\d\-]+\s+[\d:]+)--$/m', $contents, $m ) ) {
                        $finished = trim( $m[1] );
                    }
                    if ( preg_match( '/^Downloaded:\s+(\d+)\s+files/m', $contents, $m ) ) {
                        $files = (int) $m[1];
                    }
                    if ( preg_match( '/^Total wall clock time:\s*((?:[0-9]+h\s*)?(?:[0-9]+m\s*)?[0-9]+(?:\.[0-9]+)?s)/im', $contents, $m ) ) {
                        $elapsed = trim( $m[1] );
                    }
                }
                set_transient( 'nppp_vo_preload_meta', array( 'mtime' => $mtime, 'finished' => $finished, 'files' => $files, 'elapsed' => $elapsed ), MONTH_IN_SECONDS );
            }
        }
    }

    if ( $finished === '' && function_exists( 'nppp_get_last_preload_complete_date' ) ) {
        $fallback = nppp_get_last_preload_complete_date();
        $finished = is_string( $fallback ) ? trim( $fallback ) : '';
    }

    $at = 0;
    if ( $finished !== '' ) {
        $dt = DateTimeImmutable::createFromFormat( 'Y-m-d H:i:s', $finished, wp_timezone() );
        $at = $dt ? $dt->getTimestamp() : 0;
    }

    return ( $at > 0 ) ? array( 'at' => $at, 'files' => $files, 'elapsed' => $elapsed ) : null;
}

/**
 * Rows of the ENDPOINTS section. Built from stored state only: no request is sent.
 */
function nppp_varnish_vo_endpoint_rows(): string {
    $endpoints = nppp_varnish_endpoints();
    $breaker   = nppp_varnish_state_map( 'varnish_breaker' );
    $rows      = '';

    foreach ( array_values( $endpoints ) as $i => $endpoint ) {
        $id   = nppp_varnish_endpoint_id( $endpoint );
        $last = nppp_varnish_ep_last( $endpoint );
        $meta = array();

        if ( nppp_varnish_breaker_open( $endpoint ) ) {
            $state = 'bad';
            $text  = __( 'Parked', 'fastcgi-cache-purge-and-preload-nginx' );
            $title = __( 'The endpoint failed recently and is skipped for a few seconds. Purges are retried automatically once the park window ends.', 'fastcgi-cache-purge-and-preload-nginx' );
            $parts = array();
            if ( isset( $breaker[ $id ] ) ) {
                /* translators: %d: seconds until the endpoint is tried again */
                $parts[] = sprintf( __( 'retry in %d s', 'fastcgi-cache-purge-and-preload-nginx' ), max( 1, (int) $breaker[ $id ] - time() ) );
            }
            if ( $last !== null && $last['kind'] === 'unreachable' && $last['reason'] !== '' ) {
                $parts[] = $last['reason'];
            }
            $meta[] = implode( ' · ', $parts );
        } else {
            $state = 'ok';
            $text  = __( 'Ready', 'fastcgi-cache-purge-and-preload-nginx' );
            $title = __( 'The endpoint is not parked: the next purge is sent to it.', 'fastcgi-cache-purge-and-preload-nginx' );

            if ( $last === null ) {
                $meta[] = __( 'no PURGE sent yet', 'fastcgi-cache-purge-and-preload-nginx' );
            } elseif ( $last['kind'] === 'ok' || $last['kind'] === 'absent' ) {
                /* translators: 1: milliseconds, 2: time since */
                $meta[] = sprintf( __( 'last PURGE answered in %1$d ms · %2$s', 'fastcgi-cache-purge-and-preload-nginx' ), (int) $last['ms'], nppp_varnish_vo_ago( $last['at'] ) );
            } elseif ( $last['kind'] === 'denied' ) {
                $state  = 'warn';
                $text   = __( 'Refused', 'fastcgi-cache-purge-and-preload-nginx' );
                $title  = __( 'The last PURGE was refused: check the VCL purge ACL, the control header and that PURGE is handled in vcl_recv (Help tab).', 'fastcgi-cache-purge-and-preload-nginx' );
                /* translators: 1: HTTP status, 2: time since */
                $meta[] = sprintf( __( 'last PURGE refused (HTTP %1$d) · %2$s', 'fastcgi-cache-purge-and-preload-nginx' ), $last['status'], nppp_varnish_vo_ago( $last['at'] ) );
            } elseif ( $last['kind'] === 'fail' ) {
                $state  = 'warn';
                /* translators: 1: HTTP status, 2: time since */
                $meta[] = sprintf( __( 'last PURGE answered HTTP %1$d · %2$s', 'fastcgi-cache-purge-and-preload-nginx' ), $last['status'], nppp_varnish_vo_ago( $last['at'] ) );
            } else {
                $state  = 'warn';
                /* translators: 1: coarse reason such as "connection refused", 2: time since */
                $meta[] = sprintf( __( 'last PURGE failed: %1$s · %2$s', 'fastcgi-cache-purge-and-preload-nginx' ), $last['reason'] !== '' ? $last['reason'] : 'transport error', nppp_varnish_vo_ago( $last['at'] ) );
            }
        }

        $rows .= nppp_varnish_vo_row(
            '<span class="nppp-vo-idx">#' . ( $i + 1 ) . '</span> <span class="nppp-vo-mono">' . esc_html( $endpoint ) . '</span>',
            nppp_varnish_vo_cell( $state, $text, $meta, $title )
        );
    }

    // Host header the PURGE and lookup requests carry.
    $rows .= nppp_varnish_vo_row(
        esc_html__( 'Host header sent', 'fastcgi-cache-purge-and-preload-nginx' ),
        '<td class="status"><span class="nppp-vo-mono">' . esc_html( nppp_varnish_host_header() ) . '</span></td>'
    );

    // Control header: the NAME only. The value is a secret and never leaves the server.
    $extra = nppp_varnish_extra_header();
    if ( ! empty( $extra['name'] ) && is_string( $extra['name'] ) ) {
        $control = nppp_varnish_vo_cell( 'ok', $extra['name'], array( __( 'value hidden', 'fastcgi-cache-purge-and-preload-nginx' ) ), '', 'dashicons-lock' );
    } else {
        $control = nppp_varnish_vo_cell( 'off', __( 'Not set', 'fastcgi-cache-purge-and-preload-nginx' ), array( __( 'PURGE is not authenticated by a header (the VCL ACL decides)', 'fastcgi-cache-purge-and-preload-nginx' ) ) );
    }
    $rows .= nppp_varnish_vo_row( esc_html__( 'Control header', 'fastcgi-cache-purge-and-preload-nginx' ), $control );

    $budget = rtrim( rtrim( number_format( nppp_varnish_budget_seconds(), 1, '.', '' ), '0' ), '.' );
    $rows  .= nppp_varnish_vo_row(
        esc_html__( 'Park window / time budget', 'fastcgi-cache-purge-and-preload-nginx' ),
        '<td class="status">' . esc_html( sprintf(
            /* translators: 1: park window in seconds, 2: time budget in seconds */
            __( '%1$d s · budget %2$s s per run', 'fastcgi-cache-purge-and-preload-nginx' ),
            nppp_varnish_breaker_seconds(),
            $budget
        ) ) . '</td>'
    );

    return $rows;
}

/**
 * Last live check (stored by nppp_vo_status_check_callback()), or null when none was run.
 */
function nppp_varnish_vo_check_state(): ?array {
    $c = function_exists( 'nppp_state_get' ) ? nppp_state_get( 'varnish_vo_check' ) : null;

    return ( is_array( $c ) && ! empty( $c['at'] ) && isset( $c['status'] ) && is_string( $c['status'] ) ) ? $c : null;
}

/**
 * LIVE CACHE CHECK section. Rendered from the stored result of the last check; it never sends a request.
 * The Check now button fills it (nppp_vo_status_check_callback()) and the AJAX answer is this same markup.
 */
function nppp_varnish_vo_live_section(): string {
    $chk       = nppp_varnish_vo_check_state();
    $endpoints = array_values( nppp_varnish_endpoints() );
    $status    = $chk['status'] ?? '';

    // The endpoint the lookup uses: the first one that is not parked (see nppp_varnish_probe_batch()).
    $first = '';
    $idx   = 0;
    foreach ( $endpoints as $i => $candidate ) {
        if ( ! nppp_varnish_breaker_open( $candidate ) ) {
            $first = $candidate;
            $idx   = $i;
            break;
        }
    }
    $multi = count( $endpoints ) > 1;

    // 1. Does the VCL answer the lookup? A remembered "no" (one hour, set by any lookup) needs no request to show.
    $off = ( $first !== '' ) ? ( nppp_varnish_state_map( 'varnish_probe_off' )[ nppp_varnish_endpoint_id( $first ) ] ?? 0 ) : 0;
    if ( $off > 0 || $status === 'unsupported' ) {
        $probe = nppp_varnish_vo_cell( 'warn', __( 'Not answering', 'fastcgi-cache-purge-and-preload-nginx' ), array( __( 'The VCL has no probe block: add it from the Example VCL (Help tab), then press Check now.', 'fastcgi-cache-purge-and-preload-nginx' ) ) );
    } elseif ( $first === '' && $endpoints !== array() ) {
        $probe = nppp_varnish_vo_cell( 'wait', __( 'Endpoint parked', 'fastcgi-cache-purge-and-preload-nginx' ), array( __( 'Every endpoint is parked after a failure; try again in a few seconds.', 'fastcgi-cache-purge-and-preload-nginx' ) ) );
    } elseif ( $chk === null ) {
        $probe = nppp_varnish_vo_cell( 'wait', __( 'Not checked yet', 'fastcgi-cache-purge-and-preload-nginx' ), array( __( 'Press Check now.', 'fastcgi-cache-purge-and-preload-nginx' ) ) );
    } elseif ( $status === 'ok' ) {
        $probe = nppp_varnish_vo_cell( 'ok', __( 'Answering', 'fastcgi-cache-purge-and-preload-nginx' ) );
    } elseif ( $status === 'denied' ) {
        $probe = nppp_varnish_vo_cell( 'bad', __( 'Refused', 'fastcgi-cache-purge-and-preload-nginx' ), array( __( 'Check the VCL purge ACL, the control header and the probe block (Help tab).', 'fastcgi-cache-purge-and-preload-nginx' ) ) );
    } elseif ( $status === 'unreachable' ) {
        $probe = nppp_varnish_vo_cell( 'bad', __( 'Unreachable', 'fastcgi-cache-purge-and-preload-nginx' ), array( __( 'Varnish did not answer the lookup.', 'fastcgi-cache-purge-and-preload-nginx' ) ) );
    } else {
        $probe = nppp_varnish_vo_cell( 'wait', __( 'No answer', 'fastcgi-cache-purge-and-preload-nginx' ), array( __( 'The lookup could not be completed.', 'fastcgi-cache-purge-and-preload-nginx' ) ) );
    }
    $rows = nppp_varnish_vo_row( esc_html__( 'Probe block in VCL', 'fastcgi-cache-purge-and-preload-nginx' ), $probe );

    // 2. The home page, asked with the same lookup.
    $home = ( is_array( $chk['home'] ?? null ) ) ? $chk['home'] : null;
    if ( $home === null ) {
        $home_cell = nppp_varnish_vo_cell( 'wait', __( 'Not checked yet', 'fastcgi-cache-purge-and-preload-nginx' ) );
    } else {
        $state = (string) ( $home['state'] ?? 'UNKNOWN' );
        $meta  = array();
        if ( $state === 'HIT' || $state === 'STALE' ) {
            $parts = array();
            if ( isset( $home['age'] ) && is_numeric( $home['age'] ) ) {
                /* translators: %s: duration such as "4m 12s" */
                $parts[] = sprintf( __( 'age %s', 'fastcgi-cache-purge-and-preload-nginx' ), nppp_varnish_vo_duration( $home['age'] ) );
            }
            if ( $state === 'STALE' ) {
                $parts[] = __( 'past its TTL, served stale while refreshed', 'fastcgi-cache-purge-and-preload-nginx' );
            } elseif ( isset( $home['ttl'] ) && is_numeric( $home['ttl'] ) ) {
                /* translators: %s: duration such as "5m 48s" */
                $parts[] = sprintf( __( 'TTL left %s', 'fastcgi-cache-purge-and-preload-nginx' ), nppp_varnish_vo_duration( $home['ttl'] ) );
            }
        } elseif ( $state === 'MISS' ) {
            $parts = array( __( 'not cached (or not cacheable)', 'fastcgi-cache-purge-and-preload-nginx' ) );
        } elseif ( $state === 'PASS' ) {
            $parts = array( __( 'passed to the origin (hit-for-pass)', 'fastcgi-cache-purge-and-preload-nginx' ) );
        } else {
            $parts = array( (string) ( $home['reason'] ?? '' ) );
        }
        if ( in_array( $state, array( 'HIT', 'STALE', 'MISS', 'PASS' ), true ) && isset( $home['ms'] ) ) {
            /* translators: %d: milliseconds */
            $parts[] = sprintf( __( 'lookup %d ms', 'fastcgi-cache-purge-and-preload-nginx' ), (int) $home['ms'] );
        }
        $meta[] = implode( ' · ', array_filter( $parts, 'strlen' ) );

        if ( $state === 'HIT' ) {
            $home_cell = nppp_varnish_vo_cell( 'ok', 'HIT', $meta );
        } elseif ( $state === 'UNKNOWN' ) {
            $home_cell = nppp_varnish_vo_cell( 'wait', __( 'Unknown', 'fastcgi-cache-purge-and-preload-nginx' ), $meta );
        } else {
            $home_cell = nppp_varnish_vo_cell( 'warn', $state, $meta );
        }
    }
    $rows .= nppp_varnish_vo_row( esc_html__( 'Home page', 'fastcgi-cache-purge-and-preload-nginx' ) . ' <span class="nppp-vo-mono">/</span>', $home_cell );

    // 3. Coverage of a sample of the last Preload All.
    $cov = ( is_array( $chk['cov'] ?? null ) ) ? $chk['cov'] : null;
    $n   = static function ( string $k ) use ( $cov ): int {
        return is_array( $cov ) ? (int) ( $cov[ $k ] ?? 0 ) : 0;
    };
    $sampled = $n( 'hit' ) + $n( 'stale' ) + $n( 'miss' ) + $n( 'pass' ) + $n( 'unknown' );
    $known   = $n( 'hit' ) + $n( 'stale' ) + $n( 'miss' ) + $n( 'pass' );

    $cell  = '<td class="status nppp-vo-st">';
    if ( $chk === null ) {
        $cell .= '<span class="dashicons dashicons-clock nppp-vo-wait" aria-hidden="true"></span> <span class="nppp-vo-text nppp-vo-wait">' . esc_html__( 'Not checked yet', 'fastcgi-cache-purge-and-preload-nginx' ) . '</span>';
    } elseif ( $cov === null || isset( $cov['skipped'] ) || $known === 0 ) {
        $why = (string) ( $cov['skipped'] ?? '' );
        if ( $why === 'running' ) {
            $msg = __( 'Preload All is running; coverage is checked once it has finished.', 'fastcgi-cache-purge-and-preload-nginx' );
        } elseif ( $why === 'empty' ) {
            $msg = __( 'No completed crawl snapshot yet. Run Preload All once to build the URL list.', 'fastcgi-cache-purge-and-preload-nginx' );
        } else {
            $msg = __( 'Not available: Varnish did not answer the lookup (see the probe block above).', 'fastcgi-cache-purge-and-preload-nginx' );
        }
        $cell .= '<span class="dashicons dashicons-minus nppp-vo-off" aria-hidden="true"></span> <span class="nppp-vo-text nppp-vo-off">' . esc_html( $msg ) . '</span>';
    } else {
        $cached = $n( 'hit' ) + $n( 'stale' );
        $pct    = number_format_i18n( round( $cached / $known * 100, 1 ), 1 ) . '%';
        $cell  .= '<span class="nppp-vo-covtext">' . esc_html( sprintf(
            /* translators: 1: percentage such as "92.0%", 2: cached URLs, 3: URLs with a definite answer */
            _n( '%1$s (%2$d of %3$d URL cached)', '%1$s (%2$d of %3$d URLs cached)', $known, 'fastcgi-cache-purge-and-preload-nginx' ),
            $pct,
            $cached,
            $known
        ) ) . '</span>';

        $segments = array(
            array( 'hit',     'HIT',     'nppp-vo-seg-hit' ),
            array( 'stale',   'STALE',   'nppp-vo-seg-stale' ),
            array( 'miss',    'MISS',    'nppp-vo-seg-miss' ),
            array( 'pass',    'PASS',    'nppp-vo-seg-pass' ),
            array( 'unknown', 'UNKNOWN', 'nppp-vo-seg-unknown' ),
        );
        $bar    = '';
        $legend = '';
        foreach ( $segments as $seg ) {
            $count = $n( $seg[0] );
            if ( $count < 1 ) {
                continue;
            }
            $bar    .= '<span class="nppp-vo-seg ' . esc_attr( $seg[2] ) . '" style="width:' . esc_attr( number_format( $count / $sampled * 100, 2, '.', '' ) ) . '%"></span>';
            $legend .= '<span class="nppp-vo-leg"><span class="nppp-vo-dot ' . esc_attr( $seg[2] ) . '"></span><strong>' . (int) $count . '</strong> ' . esc_html( $seg[1] ) . '</span> ';
        }
        $cell .= '<span class="nppp-vo-bar" role="img" aria-label="' . esc_attr( $pct ) . '">' . $bar . '</span>';
        $cell .= '<span class="nppp-vo-legend">' . $legend . '</span>';
    }

    $foot = ( $chk !== null )
        /* translators: %s: time since the last check, e.g. "2 mins ago" */
        ? sprintf( __( 'Checked %s', 'fastcgi-cache-purge-and-preload-nginx' ), nppp_varnish_vo_ago( (int) $chk['at'] ) ) . ' · '
        : '';
    $cell .= '<span class="nppp-vo-meta nppp-vo-foot">' . esc_html( $foot )
        . '<button type="button" id="nppp-vo-check-now" class="nppp-vo-link" title="' . esc_attr__( 'Ask Varnish for the status of the home page and of a sample of the crawled URLs (lookup only: nothing is fetched, cached or purged)', 'fastcgi-cache-purge-and-preload-nginx' ) . '">'
        . esc_html__( 'Check now', 'fastcgi-cache-purge-and-preload-nginx' ) . '</button>'
        . ' <span class="nppp-vo-checkmsg" role="status"></span></span>';
    $cell .= '</td>';

    $set = (int) ( $chk['cov']['set'] ?? 0 );
    $sub = ( $set > 0 && $set <= nppp_varnish_probe_max_urls() )
        ? __( 'every URL of last Preload All', 'fastcgi-cache-purge-and-preload-nginx' )
        : __( 'sample of last Preload All', 'fastcgi-cache-purge-and-preload-nginx' );
    if ( $multi ) {
        /* translators: %d: endpoint number */
        $sub .= ' · ' . sprintf( __( 'asked on endpoint #%d only', 'fastcgi-cache-purge-and-preload-nginx' ), (int) ( $chk['ep'] ?? ( $idx + 1 ) ) );
    }
    $rows .= nppp_varnish_vo_row( esc_html__( 'Coverage', 'fastcgi-cache-purge-and-preload-nginx' ) . '<span class="nppp-vo-sub">' . esc_html( $sub ) . '</span>', $cell );

    return nppp_varnish_vo_section( 'nppp-vo-live', __( 'LIVE CACHE CHECK', 'fastcgi-cache-purge-and-preload-nginx' ), __( 'Check', 'fastcgi-cache-purge-and-preload-nginx' ), $rows );
}

/**
 * PURGE ACTIVITY section: the last 24 hours of purge runs (log kept by nppp_varnish_activity_add()).
 */
function nppp_varnish_vo_activity_section(): string {
    $events = nppp_varnish_activity();
    $total  = count( $events );
    $rows   = '';

    if ( $total === 0 ) {
        $rows .= nppp_varnish_vo_row(
            esc_html__( 'Purges', 'fastcgi-cache-purge-and-preload-nginx' ),
            nppp_varnish_vo_cell( 'wait', __( 'None in the last 24 hours', 'fastcgi-cache-purge-and-preload-nginx' ) )
        );
    } else {
        $good = 0;
        $kind = array();
        foreach ( $events as $e ) {
            if ( $e['status'] === 'ok' ) {
                $good++;
                continue;
            }
            foreach ( array_unique( $e['kinds'] ) as $k ) {
                $kind[ $k ] = ( $kind[ $k ] ?? 0 ) + 1;
            }
        }
        $bad = $total - $good;

        $rows .= nppp_varnish_vo_row(
            esc_html__( 'Purges', 'fastcgi-cache-purge-and-preload-nginx' ),
            '<td class="status"><span>' . (int) $total . '</span> · <strong class="nppp-vo-okc">'
                . esc_html( sprintf( /* translators: %d: accepted purges */ __( '%d accepted', 'fastcgi-cache-purge-and-preload-nginx' ), $good ) ) . '</strong>'
                . ( $bad > 0 ? ' · <strong class="nppp-vo-badc">' . esc_html( sprintf( /* translators: %d: failed purges */ __( '%d failed', 'fastcgi-cache-purge-and-preload-nginx' ), $bad ) ) . '</strong>' : '' )
                . '</td>'
        );

        $pct   = $good / $total * 100;
        $state = ( $bad === 0 ) ? 'ok' : ( $pct >= 90 ? 'warn' : 'bad' );
        $label = array(
            'unreachable' => __( 'unreachable', 'fastcgi-cache-purge-and-preload-nginx' ),
            'denied'      => __( 'refused', 'fastcgi-cache-purge-and-preload-nginx' ),
            'fail'        => __( 'HTTP error', 'fastcgi-cache-purge-and-preload-nginx' ),
            'parked'      => __( 'endpoint parked', 'fastcgi-cache-purge-and-preload-nginx' ),
            'budget'      => __( 'time budget used up', 'fastcgi-cache-purge-and-preload-nginx' ),
        );
        $meta  = array();
        if ( $bad > 0 && $kind !== array() ) {
            $parts = array();
            foreach ( $label as $k => $text ) {
                if ( ! empty( $kind[ $k ] ) ) {
                    $parts[] = $text . ' ' . (int) $kind[ $k ];
                }
            }
            /* translators: %s: failure kinds with counts, e.g. "unreachable 2" */
            $meta[] = sprintf( __( 'failures: %s', 'fastcgi-cache-purge-and-preload-nginx' ), implode( ', ', $parts ) );
        }
        $rows .= nppp_varnish_vo_row(
            esc_html__( 'Success rate', 'fastcgi-cache-purge-and-preload-nginx' ),
            nppp_varnish_vo_cell( $state, number_format_i18n( round( $pct, 1 ), 1 ) . '%', $meta )
        );

        $list = '';
        foreach ( array_slice( array_reverse( $events ), 0, 4 ) as $e ) {
            $what = ( $e['src'] === 'preload' ) ? __( 'Preload All', 'fastcgi-cache-purge-and-preload-nginx' )
                : ( $e['scope'] === 'all' ? __( 'Purge All', 'fastcgi-cache-purge-and-preload-nginx' ) : __( 'Single URL', 'fastcgi-cache-purge-and-preload-nginx' ) );
            if ( $e['status'] === 'ok' ) {
                $res = ( $e['src'] === 'preload' ) ? __( 'ban accepted', 'fastcgi-cache-purge-and-preload-nginx' ) : __( 'accepted', 'fastcgi-cache-purge-and-preload-nginx' );
                $dot = 'nppp-vo-seg-hit';
            } else {
                $res = ( $e['status'] === 'partial' ) ? __( 'partly failed', 'fastcgi-cache-purge-and-preload-nginx' ) : __( 'failed', 'fastcgi-cache-purge-and-preload-nginx' );
                $res .= ( $e['detail'] !== '' ) ? ' (' . $e['detail'] . ')' : '';
                $dot  = ( $e['status'] === 'partial' ) ? 'nppp-vo-seg-miss' : 'nppp-vo-seg-pass';
            }
            $list .= '<span class="nppp-vo-ev"><span class="nppp-vo-evtime">' . esc_html( nppp_varnish_vo_ago( $e['at'] ) ) . '</span>'
                . '<span class="nppp-vo-dot ' . esc_attr( $dot ) . '"></span>'
                . '<span>' . esc_html( $what . ' · ' . $res ) . '</span></span>';
        }
        $rows .= nppp_varnish_vo_row( esc_html__( 'Recent events', 'fastcgi-cache-purge-and-preload-nginx' ), '<td class="status">' . $list . '</td>' );
    }

    $rows .= nppp_varnish_vo_row(
        esc_html__( 'VCL', 'fastcgi-cache-purge-and-preload-nginx' ),
        '<td class="status">' . esc_html__( 'Purge ACL, control header and the probe block: see the Help tab.', 'fastcgi-cache-purge-and-preload-nginx' ) . '</td>'
    );

    return nppp_varnish_vo_section( '', __( 'PURGE ACTIVITY · 24 H', 'fastcgi-cache-purge-and-preload-nginx' ), __( 'Check', 'fastcgi-cache-purge-and-preload-nginx' ), $rows );
}

/**
 * Varnish-only mode: replacement for the Nginx panel on the right of the Status tab.
 *
 * Four sections in the markup of that panel (blue banner, Check / Status table), so it takes the existing styles:
 * the mode and the preload, the endpoints, the live cache check and the purge activity of the last 24 hours.
 * Rendering reads stored state only and sends no request; the live check is run by the Check now button.
 */
function nppp_varnish_vo_info_card(): string {
    $settings = get_option( 'nginx_cache_settings', [] );
    $scratch  = ( is_array( $settings ) && ! empty( $settings['nginx_cache_path'] ) && is_string( $settings['nginx_cache_path'] ) )
        ? $settings['nginx_cache_path']
        : '';

    // Mode: where the switch comes from.
    $locked = nppp_varnish_only_locked();
    $mode   = '<td class="status nppp-vo-st nppp-vo-ok"><span class="dashicons dashicons-yes" aria-hidden="true"></span> <span class="nppp-vo-text">' . esc_html__( 'Active', 'fastcgi-cache-purge-and-preload-nginx' ) . '</span> '
        . '<span class="nppp-vo-chip">' . esc_html( $locked ? __( 'Locked · wp-config.php', 'fastcgi-cache-purge-and-preload-nginx' ) : __( 'Settings', 'fastcgi-cache-purge-and-preload-nginx' ) ) . '</span></td>';

    // Last Preload All.
    $lp = nppp_varnish_vo_last_preload();
    if ( $lp === null ) {
        $last = nppp_varnish_vo_cell( 'wait', __( 'No Preload All yet', 'fastcgi-cache-purge-and-preload-nginx' ) );
    } else {
        $parts = array();
        if ( $lp['files'] !== null ) {
            /* translators: %d: number of URLs */
            $parts[] = sprintf( _n( '%d URL crawled', '%d URLs crawled', $lp['files'], 'fastcgi-cache-purge-and-preload-nginx' ), $lp['files'] );
        }
        if ( $lp['elapsed'] !== '' ) {
            /* translators: %s: duration such as "2m 41s" */
            $parts[] = sprintf( __( 'duration %s', 'fastcgi-cache-purge-and-preload-nginx' ), $lp['elapsed'] );
        }
        $last = nppp_varnish_vo_cell( 'ok', nppp_varnish_vo_ago( $lp['at'] ), array( implode( ' · ', $parts ) ) );
    }

    $rows  = nppp_varnish_vo_row( esc_html__( 'Mode', 'fastcgi-cache-purge-and-preload-nginx' ), $mode );
    $rows .= nppp_varnish_vo_row( esc_html__( 'Cache layer', 'fastcgi-cache-purge-and-preload-nginx' ), '<td class="status">' . esc_html__( 'Varnish / Vinyl Cache only. No Nginx cache is involved.', 'fastcgi-cache-purge-and-preload-nginx' ) . '</td>' );
    $rows .= nppp_varnish_vo_row( esc_html__( 'Preload scratch directory', 'fastcgi-cache-purge-and-preload-nginx' ), '<td class="status"><span class="nppp-vo-mono">' . esc_html( $scratch !== '' ? $scratch : __( 'Not set', 'fastcgi-cache-purge-and-preload-nginx' ) ) . '</span></td>' );
    $rows .= nppp_varnish_vo_row( esc_html__( 'Last Preload All', 'fastcgi-cache-purge-and-preload-nginx' ), $last );

    return '<header></header><main>'
        . nppp_varnish_vo_section( '', __( 'VARNISH-ONLY MODE', 'fastcgi-cache-purge-and-preload-nginx' ), __( 'Check', 'fastcgi-cache-purge-and-preload-nginx' ), $rows )
        . nppp_varnish_vo_section( '', __( 'ENDPOINTS', 'fastcgi-cache-purge-and-preload-nginx' ), __( 'Endpoint', 'fastcgi-cache-purge-and-preload-nginx' ), nppp_varnish_vo_endpoint_rows() )
        . nppp_varnish_vo_live_section()
        . nppp_varnish_vo_activity_section()
        . '</main>';
}

/**
 * URLs of the last Preload All to ask Varnish about: at most nppp_varnish_probe_max_urls(), evenly spread over the
 * whole crawl list (not its first N), so two checks of the same snapshot are comparable and a sitemap-ordered
 * crawl is not judged by its first section only.
 *
 * @return array{urls:string[],set:int} set = size of the whole list.
 */
function nppp_varnish_vo_coverage_sample( $wp_filesystem ): array {
    $urls = array();
    foreach ( nppp_parse_wget_log_urls( $wp_filesystem ) as $row ) {
        $u = ! empty( $row['url_encoded'] ) ? $row['url_encoded'] : ( $row['url'] ?? '' );
        if ( is_string( $u ) && $u !== '' ) {
            $urls[] = $u;
        }
    }

    $set = count( $urls );
    $max = nppp_varnish_probe_max_urls();
    if ( $set <= $max ) {
        return array( 'urls' => $urls, 'set' => $set );
    }

    $pick = array();
    for ( $i = 0; $i < $max; $i++ ) {
        $pick[] = $urls[ (int) floor( $i * $set / $max ) ];
    }

    return array( 'urls' => $pick, 'set' => $set );
}

/**
 * AJAX: Check now on the Status tab card (Varnish-only mode).
 *
 * Read-only lookups against the first endpoint that is not parked: one for the home page (timed), then up to
 * 50 for a sample of the last Preload All. The result is stored (state "varnish_vo_check") and the answer is
 * the refreshed LIVE CACHE CHECK section, so rendering the tab never waits on Varnish.
 */
function nppp_vo_status_check_callback() {
    if ( ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ), 'nppp_vo_status_nonce' ) ) {
        wp_send_json_error( __( 'Nonce verification failed.', 'fastcgi-cache-purge-and-preload-nginx' ) );
    }
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( __( 'You do not have permission to access this action.', 'fastcgi-cache-purge-and-preload-nginx' ), 403 );
    }
    if ( ! function_exists( 'nppp_varnish_only_mode' ) || ! nppp_varnish_only_mode() || ! function_exists( 'nppp_varnish_probe_batch' ) ) {
        wp_send_json_error( __( 'Varnish-only mode is not active.', 'fastcgi-cache-purge-and-preload-nginx' ) );
    }

    // One check at a time: a double click (or two admins) must not double the lookups sent to Varnish.
    if ( get_transient( 'nppp_vo_check_lock' ) ) {
        wp_send_json_error( __( 'A check is already running. Try again in a few seconds.', 'fastcgi-cache-purge-and-preload-nginx' ) );
    }
    set_transient( 'nppp_vo_check_lock', 1, 30 );

    $failed = false;
    try {
        $endpoints = array_values( nppp_varnish_endpoints() );
        $ep        = 1;
        foreach ( $endpoints as $i => $candidate ) {
            if ( ! nppp_varnish_breaker_open( $candidate ) ) {
                $ep = $i + 1;
                break;
            }
        }

        // Home page, timed. $force = true: the button is the explicit "ask again", it forgets a remembered "no probe block".
        $home_url = home_url( '/' );
        $t0       = microtime( true );
        $first    = nppp_varnish_probe_batch( array( $home_url ), true );
        $ms       = (int) round( ( microtime( true ) - $t0 ) * 1000 );
        $item     = ( isset( $first['results'][ $home_url ] ) && is_array( $first['results'][ $home_url ] ) ) ? $first['results'][ $home_url ] : array();
        $status   = (string) $first['status'];

        $row = array(
            'at'     => time(),
            'status' => $status,
            'ep'     => $ep,
            'home'   => array(
                'state'  => isset( $item['state'] ) ? (string) $item['state'] : 'UNKNOWN',
                'age'    => $item['age'] ?? null,
                'ttl'    => $item['ttl'] ?? null,
                'ms'     => $ms,
                'reason' => isset( $item['reason'] ) ? (string) $item['reason'] : '',
            ),
            'cov'    => array( 'skipped' => 'status' ),
        );

        // The first answer that is not a probe answer ends the check: it would be the same for every URL.
        if ( $status === 'ok' ) {
            $wp_filesystem = nppp_initialize_wp_filesystem();
            if ( $wp_filesystem === false ) {
                $row['cov'] = array( 'skipped' => 'empty' );
            } elseif ( nppp_is_preload_running( $wp_filesystem ) ) {
                $row['cov'] = array( 'skipped' => 'running' );
            } else {
                $sample = nppp_varnish_vo_coverage_sample( $wp_filesystem );
                if ( empty( $sample['urls'] ) ) {
                    $row['cov'] = array( 'skipped' => 'empty' );
                } else {
                    $batch = nppp_varnish_probe_batch( $sample['urls'], false );
                    $count = array( 'hit' => 0, 'stale' => 0, 'miss' => 0, 'pass' => 0, 'unknown' => 0, 'set' => $sample['set'] );
                    $map   = array( 'HIT' => 'hit', 'STALE' => 'stale', 'MISS' => 'miss', 'PASS' => 'pass' );
                    foreach ( $sample['urls'] as $u ) {
                        $st = isset( $batch['results'][ $u ]['state'] ) ? (string) $batch['results'][ $u ]['state'] : 'UNKNOWN';
                        $count[ $map[ $st ] ?? 'unknown' ]++;
                    }
                    $row['cov'] = $count;
                    if ( (string) $batch['status'] !== 'ok' ) {
                        $row['status'] = (string) $batch['status'];   // e.g. the endpoint went away half way
                    }
                }
            }
        }

        nppp_state_set( 'varnish_vo_check', $row, 7 * DAY_IN_SECONDS );
    } catch ( \Throwable $e ) {
        $failed = true;
    } finally {
        // wp_send_json_*() ends the request with die, which skips a finally block: answer only after it.
        delete_transient( 'nppp_vo_check_lock' );
    }

    if ( $failed ) {
        wp_send_json_error( __( 'The live check failed.', 'fastcgi-cache-purge-and-preload-nginx' ) );
    }

    wp_send_json_success( array( 'html' => nppp_varnish_vo_live_section() ) );
}

function nppp_my_status_html() {
    // Varnish-only mode: there is no Nginx cache, and the Nginx-specific parts of this tab are skipped.
    $nppp_vo = function_exists( 'nppp_varnish_only_mode' ) && nppp_varnish_only_mode();

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
    // Try to get it first (Varnish-only mode has no Nginx cache: nginx.conf is not needed)
    $conf_paths = $nppp_vo ? [] : nppp_get_nginx_conf_paths($wp_filesystem);

    // Exit early if unable to find or read the nginx.conf file
    if (!$nppp_vo && empty($conf_paths)) {
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
    $config_data = $nppp_vo ? null : nppp_parse_nginx_cache_key();

    // Check same Nginx cache path for multiple instance
    $duplicates = false;
    if (!$nppp_vo) {
        $config_file = $conf_paths[0];
        $duplicates = nppp_check_duplicate_nginx_cache_paths($config_file, $wp_filesystem);
    }

    // Pages in cache — read from last known option, never scan on Status tab load.
    // nppp_last_known_hits is updated at natural points: preload completion,
    // purge all, advanced tab index scan, send-mail, dashboard widget.
    $nppp_pages_in_cache  = get_option( 'nppp_last_known_hits',      false );
    $nppp_hits_scanned_at = get_option( 'nppp_last_hits_scanned_at', false );

    // Warn about same Nginx cache path for multiple instance
    if ($duplicates !== false) {
        echo '<div class="nppp-status-wrap">
                  <p class="nppp-advanced-error-message">' . wp_kses(__('INFO: <span style="color: #FFDEAD;">Same</span> Nginx cache path found!', 'fastcgi-cache-purge-and-preload-nginx'), ['span' => ['style' => true]]) . '</p>
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
        <div id="nppp-status-tab" class="container"<?php echo $nppp_vo ? ' data-nppp-vo="1"' : ''; ?>>
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
                            <?php if ( ! $nppp_vo ) : ?>
                            <tr>
                                <td class="action"><?php esc_html_e('Purge Action', 'fastcgi-cache-purge-and-preload-nginx'); ?></td>
                                <td class="status" id="nppppurgeStatus">
                                    <span class="dashicons"></span>
                                    <span><?php echo esc_html($perm_in_cache_status_purge); ?></span>
                                </td>
                            </tr>
                            <?php endif; ?>
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
                        <?php if ( nppp_is_preload_running( $wp_filesystem ) ) : ?>
                        <!-- Stop Preload Button -->
                        <div class="nppp-stop-preload-wrap">
                            <a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin.php?action=nppp_stop_preload' ), 'stop_preload_nonce' ) ); ?>"
                               class="nppp-button"
                               id="nppp-stop-preload-button">
                                <span class="dashicons dashicons-controls-pause" style="font-size: 18px;"></span>
                                <?php esc_html_e( 'Stop Preload', 'fastcgi-cache-purge-and-preload-nginx' ); ?>
                            </a>
                        </div>
                        <?php endif; ?>
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
                                <td class="check"><?php echo $nppp_vo ? esc_html__('Preload Scratch Directory (Required)', 'fastcgi-cache-purge-and-preload-nginx') : esc_html__('Nginx Cache Path (Required)', 'fastcgi-cache-purge-and-preload-nginx'); ?></td>
                                <td class="status" id="npppcachePath">
                                    <span class="dashicons"></span>
                                    <span><?php echo esc_html(nppp_check_path()); ?></span>
                                </td>
                            </tr>
                            <tr>
                                <td class="check"><?php echo $nppp_vo ? esc_html__('Scratch Directory Permission (Required)', 'fastcgi-cache-purge-and-preload-nginx') : esc_html__('Cache Path Permission (Required)', 'fastcgi-cache-purge-and-preload-nginx'); ?></td>
                                <td class="status" id="npppaclStatus">
                                    <span class="dashicons"></span>
                                    <span><?php echo esc_html($perm_status_message); ?></span>
                                </td>
                            </tr>
                            <?php if ( ! $nppp_vo ) : ?>
                            <tr>
                                <td class="check"><?php esc_html_e('Cache Key Regex Test (Required)', 'fastcgi-cache-purge-and-preload-nginx'); ?></td>
                                <td class="status" id="npppCacheKeyRegex">
                                    <span class="dashicons"></span>
                                    <span><?php echo esc_html( nppp_probe_cache_key_regex() ); ?></span>
                                </td>
                            </tr>
                            <?php endif; ?>
                            <?php $nppp_varnish_vo = $nppp_vo ? nppp_get_varnish_vo_status() : null; ?>
                            <?php if ( is_array( $nppp_varnish_vo ) ) : ?>
                            <tr>
                                <td class="check"><?php esc_html_e('Varnish / Vinyl Cache Purge', 'fastcgi-cache-purge-and-preload-nginx'); ?></td>
                                <td class="status nppp-hp nppp-hp-<?php echo esc_attr( $nppp_varnish_vo['state'] ); ?>" id="npppVarnishVo" title="<?php echo esc_attr( $nppp_varnish_vo['title'] ); ?>">
                                    <span class="dashicons <?php echo esc_attr( $nppp_varnish_vo['icon'] ); ?>"></span>
                                    <span class="nppp-hp-text"><?php echo esc_html( $nppp_varnish_vo['text'] ); ?></span>
                                    <?php foreach ( $nppp_varnish_vo['meta'] as $nppp_varnish_vo_line ) : ?>
                                        <span class="nppp-hp-meta"><?php echo esc_html( $nppp_varnish_vo_line ); ?></span>
                                    <?php endforeach; ?>
                                </td>
                            </tr>
                            <?php endif; ?>
                            <?php $nppp_http_purge = nppp_get_http_purge_status(); ?>
                            <?php if ( is_array( $nppp_http_purge ) ) : ?>
                            <tr>
                                <td class="check"><?php esc_html_e('HTTP Purge (Optional)', 'fastcgi-cache-purge-and-preload-nginx'); ?></td>
                                <td class="status nppp-hp nppp-hp-<?php echo esc_attr( $nppp_http_purge['state'] ); ?>" id="npppHttpPurge" title="<?php echo esc_attr( $nppp_http_purge['title'] ); ?>">
                                    <span class="dashicons <?php echo esc_attr( $nppp_http_purge['icon'] ); ?>"></span>
                                    <span class="nppp-hp-text"><?php echo esc_html( $nppp_http_purge['text'] ); ?></span>
                                    <?php foreach ( $nppp_http_purge['meta'] as $nppp_http_purge_line ) : ?>
                                        <span class="nppp-hp-meta"><?php echo esc_html( $nppp_http_purge_line ); ?></span>
                                    <?php endforeach; ?>
                                </td>
                            </tr>
                            <?php endif; ?>
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
                            <tr>
                                <td class="check"><?php esc_html_e('WP-Cron Status', 'fastcgi-cache-purge-and-preload-nginx'); ?></td>
                                <td class="status" id="npppCronReliability" title="<?php echo function_exists('nppp_get_cron_reliability_label') ? esc_attr(nppp_get_cron_reliability_label()) : ''; ?>">
                                    <span class="dashicons"></span>
                                    <span><?php echo esc_html( function_exists('nppp_get_cron_reliability_short_label') ? nppp_get_cron_reliability_short_label() : 'N/A' ); ?></span>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </section>

                <?php if ( ! $nppp_vo ) : ?>
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
                <?php endif; ?>
            </main>
        </div>
        <div id="nppp-nginx-info" class="container">
            <?php echo $nppp_vo ? nppp_varnish_vo_info_card() : do_shortcode('[nppp_nginx_config]'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside ?>
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
