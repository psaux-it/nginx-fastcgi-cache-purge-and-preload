<?php
/**
 * Uninstallation script for FastCGI Cache Purge and Preload for Nginx
 * Description: This file handles the cleanup process when the FastCGI Cache Purge and Preload for Nginx plugin is uninstalled.
 * Version: 2.0.4
 * Author: Hasan ÇALIŞIR
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
    );

    // Category-related transients based on the URL cache
    $url_cache_pattern = 'nppp_category_';

    // Rate limit transients
    $rate_limit_pattern = 'nppp_rate_limit_';

    // Get all transients
    $all_transients = wp_cache_get('alloptions', 'options');
    foreach ($all_transients as $transient_key => $value) {
        // Match the category-based transients
        if (strpos($transient_key, $url_cache_pattern) !== false) {
            $transients[] = $transient_key;
        }

        // Match the rate limit-related transients
        if (strpos($transient_key, $rate_limit_pattern) !== false) {
            $transients[] = $transient_key;
        }
    }

    // Attempt to delete all transients
    foreach ($transients as $transient) {
        // Delete the transient
        delete_transient($transient);
    }
}

// Delete plugin transients
nppp_clear_plugin_cache_on_uninstall();

// Delete plugin options
delete_option('nginx_cache_settings');
