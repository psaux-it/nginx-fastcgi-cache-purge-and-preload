<?php
/**
 * Plugin lifecycle hooks for Nginx Cache Purge Preload
 * Description: Handles plugin activation defaults and deactivation cleanup.
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

// Function to reset plugin settings on deactivation
function nppp_reset_plugin_settings_on_deactivation() {
    // Always clear preload related cron hooks unconditionally.
    wp_clear_scheduled_hook('npp_cache_preload_status_event');
    wp_clear_scheduled_hook('npp_cache_preload_event');

    // Fail2ban retention cron. The recorded events themselves are kept — only
    // uninstall wipes data — but the scheduled job must not survive
    // deactivation.
    wp_clear_scheduled_hook('nppp_f2b_cleanup_event');

    // Fail2ban RDAP enrichment worker: stop its reconciliation tick and
    // terminate the detached CLI process. Like preload, it is a nohup child
    // that would otherwise outlive deactivation.
    wp_clear_scheduled_hook('nppp_f2b_worker_event');
    if (function_exists('nppp_f2b_kill_worker')) {
        nppp_f2b_kill_worker();
    }

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
            // safexec-aware termination
            $process_user = function_exists('shell_exec')
                ? trim((string) shell_exec('ps -o user= -p ' . escapeshellarg((string) $pid) . ' 2>/dev/null'))
                : '';

            if ($process_user === 'nobody') {
                $sfx = '/usr/bin/safexec';
                if (!file_exists($sfx) && function_exists('shell_exec')) {
                    $detected = trim((string) shell_exec('command -v safexec 2>/dev/null'));
                    $sfx = ($detected !== '') ? $detected : '';
                }

                $sfx_ls = ($sfx !== '' && function_exists('nppp_safexec_ls_check')) ? nppp_safexec_ls_check($sfx) : null;
                if ($sfx_ls && $sfx_ls['is_root'] && $sfx_ls['has_suid']) {
                    shell_exec(escapeshellarg($sfx) . ' --kill=' . (int) $pid . ' 2>&1');
                    usleep(300000);
                }

                if (nppp_is_process_alive($pid)) {
                    // Could not verify the safexec-owned process was stopped.
                    // Do not delete the PID file — it is the only ownership
                    // evidence that a `nobody`-owned crawler is still running.
                    nppp_display_admin_notice(
                        'error',
                        sprintf(
                            /* translators: 1: process ID that could not be stopped, 2: same process ID for the safexec --kill example */
                            __('ERROR DEACTIVATE: Could not stop safexec-owned preload process (PID %1$d); it may still be running as `nobody`. Stop it manually with: safexec --kill=%2$d', 'fastcgi-cache-purge-and-preload-nginx'),
                            $pid,
                            $pid
                        ),
                        true,
                        false
                    );
                    return;
                }
            } else {
                // Standard (non-safexec) process — SIGTERM, verify, SIGKILL, verify.
                if (function_exists('posix_kill') && defined('SIGTERM')) {
                    posix_kill($pid, SIGTERM);
                    usleep(300000);
                }

                if (nppp_is_process_alive($pid)) {
                    $kill_path = function_exists( 'shell_exec' ) ? trim((string) shell_exec('command -v kill')) : '';
                    if (!empty($kill_path)) {
                        shell_exec(escapeshellarg($kill_path) . ' -9 ' . (int) $pid);
                        usleep(300000);
                    }
                }

                if (nppp_is_process_alive($pid)) {
                    // Still alive after SIGTERM + SIGKILL — keep the PID file
                    // so a leaked process is not silently forgotten.
                    nppp_display_admin_notice(
                        'error',
                        sprintf(
                            /* translators: %d: process ID that could not be stopped */
                            __('ERROR DEACTIVATE: Failed to stop preload process (PID %d) after SIGTERM and SIGKILL.', 'fastcgi-cache-purge-and-preload-nginx'),
                            $pid
                        ),
                        true,
                        false
                    );
                    return;
                }
            }
        }

        // Reached only when there was no live process to begin with, or
        // termination was just confirmed above — safe to remove the PID file.
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

    // If the site relies on a real system cron instead of WP-Cron's opportunistic
    // HTTP-triggered pseudo-cron (DISABLE_WP_CRON), the periodic status-refresh
    // event that reports preload completion can lag far behind the actual
    // preload finishing — the site's normal traffic never wakes it up.
    // Default the Watchdog on for fresh installs in that case so post-preload
    // status stays accurate without depending on WP-Cron timing. This only
    // seeds the default for a *new* activation — array_merge() below lets any
    // pre-existing user choice win on reactivation/upgrade, so a user who
    // explicitly turned Watchdog off is never overridden.
    $nppp_default_watchdog = ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ) ? 'yes' : 'no';

    // Define default options
    $default_options = array(
        'nginx_cache_path'                    => '/dev/shm/change-me-now',
        'nginx_cache_email'                   => 'your-email@example.com',
        'nginx_cache_cpu_limit'               => 100,
        'nginx_cache_reject_extension'        => nppp_fetch_default_reject_extension(),
        'nginx_cache_reject_regex'            => nppp_fetch_default_reject_regex(),
        'nginx_cache_key_custom_regex'        => base64_encode(nppp_fetch_default_regex_for_cache_key()),
        'nginx_cache_wait_request'            => 0,
        'nginx_cache_read_timeout'            => 60,
        'nginx_cache_limit_rate'              => 5120,
        'nginx_cache_api_key'                 => $new_api_key,
        'nginx_cache_preload_proxy_host'      => '127.0.0.1',
        'nginx_cache_preload_proxy_port'      => 3434,
        'nppp_related_include_home'           => 'no',
        'nppp_related_include_category'       => 'no',
        'nppp_related_apply_manual'           => 'no',
        'nppp_related_preload_after_manual'   => 'no',
        'nppp_cloudflare_apo_sync'            => 'no',
        'nppp_redis_cache_sync'               => 'no',
        'nginx_cache_purge_on_update'         => 'no',
        'nppp_autopurge_posts'                => 'no',
        'nppp_autopurge_terms'                => 'no',
        'nppp_autopurge_plugins'              => 'no',
        'nppp_autopurge_themes'               => 'no',
        'nppp_autopurge_3rdparty'             => 'no',
        'nginx_cache_auto_preload'            => 'no',
        'nginx_cache_auto_preload_mobile'     => 'no',
        'nginx_cache_preload_feeds'           => 'no',
        'nginx_cache_mobile_user_agent'       => nppp_fetch_default_mobile_user_agent(),
        'nginx_cache_watchdog'                => $nppp_default_watchdog,
        'nginx_cache_send_mail'               => 'no',
        'nginx_cache_preload_enable_proxy'    => 'no',
        'nginx_cache_schedule'                => 'no',
        'nginx_cache_pctnorm_mode'            => 'off',
        'nppp_http_purge_enabled'             => 'no',
        'nppp_rg_purge_enabled'               => 'no',
        'nppp_http_purge_suffix'              => 'purge',
        'nppp_http_purge_custom_url'          => '',
        'nppp_http_purge_all_path'            => 'purge_all',
        'nginx_cache_bypass_path_restriction' => 'no',
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
