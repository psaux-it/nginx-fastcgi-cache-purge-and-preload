<?php
/**
 * Plugin lifecycle hooks for Nginx Cache Purge Preload
 * Description: Handles plugin activation defaults and deactivation cleanup.
 * Version: 2.1.5
 * Author: Hasan CALISIR
 * Author Email: hasan.calisir@psauxit.com
 * Author URI: https://www.psauxit.com
 * License: GPL-2.0+
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Function to reset plugin settings on deactivation
function nppp_reset_plugin_settings_on_deactivation() {
    // Always clear preload related cron hooks unconditionally.
    wp_clear_scheduled_hook('npp_cache_preload_status_event');
    wp_clear_scheduled_hook('npp_cache_preload_event');

    // Kill the watchdog.
    if (function_exists('nppp_kill_preload_watcher')) {
        nppp_kill_preload_watcher();
    }

    // Clear all plugin transients silently — server state may change
    if (function_exists('nppp_clear_plugin_cache')) {
        nppp_clear_plugin_cache(true);
    }

    // Preload runs as a detached nohup process that survives deactivation.
    // Terminate it gracefully so it does not keep crawling after the plugin
    // is gone, then clean up the stale PID file.
    $PIDFILE = nppp_get_runtime_file('cache_preload.pid');

    $wp_filesystem = nppp_initialize_wp_filesystem();

    if ($wp_filesystem !== false && $wp_filesystem->exists($PIDFILE)) {
        $pid = intval(nppp_perform_file_operation($PIDFILE, 'read'));

        if ($pid > 0 && nppp_is_process_alive($pid)) {
            // Try SIGTERM first (graceful).
            if (function_exists('posix_kill') && defined('SIGTERM')) {
                posix_kill($pid, SIGTERM);
                usleep(300000);
            }

            // Fall back to SIGKILL if still alive.
            if (nppp_is_process_alive($pid)) {
                $kill_path = trim((string) shell_exec('command -v kill'));
                if (!empty($kill_path)) {
                    shell_exec(escapeshellarg($kill_path) . ' -9 ' . (int) $pid);
                    usleep(300000);
                }
            }
        }

        // Remove PID file regardless of whether the process was alive,
        // so a stale file from a previously crashed preload is also cleaned up.
        nppp_perform_file_operation($PIDFILE, 'delete');
    }
}

// Automatically update the default options when the plugin is activated or reactivated
function nppp_defaults_on_plugin_activation() {
    // Clear all plugin transients on activation/reactivation.
    // Ensures no stale cached state from a previous activation
    if (function_exists('nppp_clear_plugin_cache')) {
        nppp_clear_plugin_cache(true);
    }

    $new_api_key = bin2hex(random_bytes(32));

    // Define default options
    $default_options = array(
        'nginx_cache_path'                  => '/dev/shm/change-me-now',
        'nginx_cache_email'                 => 'your-email@example.com',
        'nginx_cache_cpu_limit'             => 100,
        'nginx_cache_reject_extension'      => nppp_fetch_default_reject_extension(),
        'nginx_cache_reject_regex'          => nppp_fetch_default_reject_regex(),
        'nginx_cache_key_custom_regex'      => base64_encode(nppp_fetch_default_regex_for_cache_key()),
        'nginx_cache_wait_request'          => 0,
        'nginx_cache_read_timeout'          => 60,
        'nginx_cache_limit_rate'            => 5120,
        'nginx_cache_api_key'               => $new_api_key,
        'nginx_cache_preload_proxy_host'    => '127.0.0.1',
        'nginx_cache_preload_proxy_port'    => 3434,
        'nppp_related_include_home'         => 'no',
        'nppp_related_include_category'     => 'no',
        'nppp_related_apply_manual'         => 'no',
        'nppp_related_preload_after_manual' => 'no',
        'nppp_cloudflare_apo_sync'          => 'no',
        'nppp_redis_cache_sync'             => 'no',
        'nginx_cache_purge_on_update'       => 'no',
        'nppp_autopurge_posts'              => 'no',
        'nppp_autopurge_terms'              => 'no',
        'nppp_autopurge_plugins'            => 'no',
        'nppp_autopurge_themes'             => 'no',
        'nppp_autopurge_3rdparty'           => 'no',
        'nginx_cache_auto_preload'          => 'no',
        'nginx_cache_auto_preload_mobile'   => 'no',
        'nginx_cache_mobile_user_agent'     => nppp_fetch_default_mobile_user_agent(),
        'nginx_cache_watchdog'              => 'no',
        'nginx_cache_send_mail'             => 'no',
        'nginx_cache_preload_enable_proxy'  => 'no',
        'nginx_cache_schedule'              => 'no',
        'nginx_cache_pctnorm_mode'          => 'off',
        'nppp_http_purge_enabled'           => 'no',
        'nppp_rg_purge_enabled'             => 'no',
        'nppp_http_purge_suffix'            => 'purge',
        'nppp_http_purge_custom_url'        => '',
    );

    // Retrieve existing options (if any)
    $existing_options = get_option('nginx_cache_settings', array());

    // Merge existing options with default options
    // Existing options overwrite default options
    $updated_options = array_merge($default_options, $existing_options);

    // Update options in the database
    update_option('nginx_cache_settings', $updated_options);

    // Save the current version using the compile-time constant
    update_option('nppp_plugin_version', defined('NPPP_PLUGIN_VERSION') ? NPPP_PLUGIN_VERSION : '');

    // Create the log file if it doesn't exist
    $log_file_path = NGINX_CACHE_LOG_FILE;
    if (!file_exists($log_file_path)) {
        $log_file_created = nppp_perform_file_operation($log_file_path, 'create');
        if (!$log_file_created) {
            // Log file creation failed, handle error accordingly
            nppp_custom_error_log('Failed to create log file: ' . $log_file_path);
        }
    }
}
