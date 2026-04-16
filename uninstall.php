<?php
/**
 * Uninstall cleanup routines for Nginx Cache Purge Preload
 * Description: Removes plugin options, transients, runtime artifacts, and scheduled events during uninstall.
 * Version: 2.1.5
 * Author: Hasan CALISIR
 * Author Email: hasan.calisir@psauxit.com
 * Author URI: https://www.psauxit.com
 * License: GPL-2.0+
 */

// Exit if uninstall not called from WordPress
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit();
}

/**
 * Remove transient entries and wildcarded transient records.
 */
function nppp_clear_plugin_cache_on_uninstall() {
    global $wpdb;

    // Transients to clear
    $static_key_base = 'nppp';
    $transients = array(
        'nppp_cache_keys_wpfilesystem_error',
        'nppp_nginx_conf_not_found',
        'nppp_cache_keys_not_found',
        'nppp_cache_path_not_found',
        'nppp_fuse_path_not_found',
        'nppp_assume_recently_enabled',
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
        'nppp_preload_phase_' . md5($static_key_base),
        'nppp_preload_cycle_start_' . md5($static_key_base),
        'nppp_ping_token_' . md5($static_key_base),
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

    // Delete each transient
    foreach ($transients as $transient) {
        delete_transient($transient);
    }

    // Safe clean up transients directly in DB
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
}

/**
 * Remove plugin options stored in wp_options.
 */
function nppp_delete_plugin_options_on_uninstall() {
    $option_keys = array(
        'nginx_cache_settings',                   // Main settings array
        'nginx_cache_schedule_value',             // Saved cron expression
        'nppp_assume_nginx_runtime',              // Assume-Nginx toggle (Setup::RUNTIME_OPTION)
        'nppp_plugin_version',                    // Version tracking
        'nppp_db_version',                        // DB migration version stamp
        'nppp_redirect_to_setup_once',            // One-time activation redirect flag
        'nppp_assume_nginx_auto_disabled_notice', // Auto-disable UI notice flag
        'nppp_last_known_hits',                   // Dashboard cache hit ratio — hit count
        'nppp_last_hits_scanned_at',              // Dashboard cache hit ratio — scan timestamp
        'nppp_url_filepath_index',                // URL→filepath index for single/related purge fast-path
        'nppp_cache_purge.lock',                  // Purge operation lock (WP_Upgrader)
    );

    foreach ($option_keys as $option_key) {
        delete_option($option_key);
    }
}

/**
 * Clear plugin cron hooks.
 */
function nppp_clear_scheduled_events_on_uninstall() {
    wp_clear_scheduled_hook('npp_cache_preload_event');
    wp_clear_scheduled_hook('npp_cache_preload_status_event');
    wp_clear_scheduled_hook('nppp_index_updater_event');

    // Remove tracking cron hooks left by 2.0.1–2.1.4 in case migration never ran
    wp_clear_scheduled_hook('npp_plugin_tracking_event', array('active'));
    wp_clear_scheduled_hook('npp_plugin_tracking_event');
}

/**
 * Resolve runtime directory used by plugin artifacts.
 */
function nppp_get_runtime_dir_on_uninstall() {
    // Fallback in case uninstall.php is somehow invoked without the main file.
    $runtime_subdir = defined('NPPP_RUNTIME_SUBDIR') ? NPPP_RUNTIME_SUBDIR : 'nginx-cache-purge-preload-runtime';

    $uploads_base = '';
    if (function_exists('wp_upload_dir')) {
        $uploads = wp_upload_dir();
        if (is_array($uploads) && !empty($uploads['basedir'])) {
            $uploads_base = (string) $uploads['basedir'];
        }
    }

    if ($uploads_base === '' && defined('WP_CONTENT_DIR')) {
        $uploads_base = WP_CONTENT_DIR . '/uploads';
    }

    return rtrim($uploads_base, '/\\') . '/' . $runtime_subdir;
}

/**
 * Delete runtime files created by plugin and remove runtime directory when empty.
 */
function nppp_delete_runtime_artifacts_on_uninstall() {
    $runtime_dir = nppp_get_runtime_dir_on_uninstall();
    if (!is_string($runtime_dir) || $runtime_dir === '' || !is_dir($runtime_dir)) {
        return;
    }

    $uploads = wp_upload_dir();
    $uploads_base = isset($uploads['basedir']) ? realpath($uploads['basedir']) : false;
    $runtime_real = realpath($runtime_dir);

    if ($uploads_base === false || $runtime_real === false) {
        return;
    }

    $uploads_base = rtrim($uploads_base, '/\\') . DIRECTORY_SEPARATOR;
    $runtime_with_slash = rtrim($runtime_real, '/\\') . DIRECTORY_SEPARATOR;

    // Safety: only delete runtime content under uploads.
    if (strpos($runtime_with_slash, $uploads_base) !== 0) {
        return;
    }

    $runtime_files = array(
        'fastcgi_ops.log',
        'cache_preload.pid',
        'preload_watcher.pid',
        'nppp-wget.log',
        'nppp-wget-snapshot.log',
    );

    foreach ($runtime_files as $runtime_file) {
        $file_path = $runtime_real . DIRECTORY_SEPARATOR . $runtime_file;
        if (is_file($file_path)) {
            wp_delete_file($file_path);
        }
    }

    $remaining = glob($runtime_real . DIRECTORY_SEPARATOR . '*');
    if (is_array($remaining) && empty($remaining)) {
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- WP_Filesystem unavailable in uninstall context; directory is confirmed empty above.
        rmdir($runtime_real);
    }
}

/**
 * Run uninstall cleanup for the active site context.
 */
function nppp_run_uninstall_cleanup_for_current_site() {
    nppp_clear_plugin_cache_on_uninstall();
    nppp_clear_scheduled_events_on_uninstall();
    nppp_delete_runtime_artifacts_on_uninstall();
    nppp_delete_plugin_options_on_uninstall();

    // Remove the custom purge capability from every role that holds it.
    foreach ( wp_roles()->role_objects as $role ) {
        if ( isset( $role->capabilities['nppp_purge_cache'] ) ) {
            $role->remove_cap( 'nppp_purge_cache' );
        }
    }
}

if (is_multisite()) {
    $nppp_site_ids = get_sites(array('fields' => 'ids'));
    foreach ($nppp_site_ids as $nppp_site_id) {
        switch_to_blog((int) $nppp_site_id);
        nppp_run_uninstall_cleanup_for_current_site();
        restore_current_blog();
    }
} else {
    nppp_run_uninstall_cleanup_for_current_site();
}
