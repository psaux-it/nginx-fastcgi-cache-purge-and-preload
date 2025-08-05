<?php
/**
 * Uninstallation script for FastCGI Cache Purge and Preload for Nginx
 * Description: This file handles the cleanup process when the FastCGI Cache Purge and Preload for Nginx plugin is uninstalled.
 * Version: 2.1.3
 * Author: Hasan CALISIR
 * Author Email: hasan.calisir@psauxit.com
 * Author URI: https://www.psauxit.com
 * License: GPL-2.0+
 */

// Exit if uninstall not called from WordPress
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit();
}

// Clear all transients related to the NPP
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
        'nppp_cache_keys_' . md5($static_key_base),
        'nppp_bindfs_version_' . md5($static_key_base),
        'nppp_libfuse_version_' . md5($static_key_base),
        'nppp_permissions_check_' . md5($static_key_base),
        'nppp_cache_paths_' . md5($static_key_base),
        'nppp_fuse_paths_' . md5($static_key_base),
        'nppp_webserver_user_' . md5($static_key_base),
        'nppp_est_url_counts_' . md5($static_key_base),
        'nppp_last_preload_time_' . md5($static_key_base),
    );

    // Delete each known transient
    foreach ($transients as $transient) {
        delete_transient($transient);
    }

    // Safe clean up transients directly in DB
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
    $wpdb->query("
        DELETE FROM $wpdb->options
        WHERE option_name LIKE '\\_transient_nppp_category_%'
           OR option_name LIKE '\\_transient_timeout_nppp_category_%'
           OR option_name LIKE '\\_transient_nppp_rate_limit_%'
           OR option_name LIKE '\\_transient_timeout_nppp_rate_limit_%'
    ");
}

// Delete plugin transients
nppp_clear_plugin_cache_on_uninstall();

// Delete plugin options
delete_option('nginx_cache_settings');
