<?php
/**
 * Plugin update check and routines for FastCGI Cache Purge and Preload for Nginx
 * Description: This file contains functions to check for plugin updates and run necessary update routines for FastCGI Cache Purge and Preload for Nginx.
 * Version: 2.0.8
 * Author: Hasan CALISIR
 * Author Email: hasan.calisir@psauxit.com
 * Author URI: https://www.psauxit.com
 * License: GPL-2.0+
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Check for plugin updates and run update routines if necessary.
function nppp_check_for_plugin_update() {
    // Get the current plugin data
    $plugin_data = get_plugin_data(NPPP_PLUGIN_FILE);
    $current_version = $plugin_data['Version'];

    // Get the saved version from the database
    $saved_version = get_option('nppp_plugin_version');

    // Compare versions
    if ($saved_version !== $current_version) {
        // Run update routines
        nppp_run_update_routines($saved_version, $current_version);

        // Update the saved version to the current version
        update_option('nppp_plugin_version', $current_version);
    }
}

// Run update routines when the plugin version changes
function nppp_run_update_routines($old_version, $new_version) {
    // Always triggers on update from [ version < 2.0.4 ]
    // Not triggers on fresh install [ version = 2.0.4 ]
    // Not triggers on update from [ version ≥ 2.0.4 ]
    if ($old_version === false || version_compare($old_version, '2.0.4', '<')) {
        // Set default opt-in status
        $options = get_option('nginx_cache_settings', array());
        if (!isset($options['nginx_cache_tracking_opt_in'])) {
            $options['nginx_cache_tracking_opt_in'] = '1';
            update_option('nginx_cache_settings', $options);
        }

        // Call API
        nppp_plugin_tracking('active');

        // Schedule the tracking event
        nppp_schedule_plugin_tracking_event();

        // Display admin notice informing the user they are opted in (GPDR) and how opt-out
        nppp_display_admin_notice(
            'info',
            'Thank you for helping us improve the plugin by collecting anonymous tracking data. If you wish to opt-out, please visit the plugin settings page.',
            false,
            true
        );
    }

    // Trigger additional updates for versions between 2.0.5 and 2.0.8 (inclusive)
    // This code will run for versions 2.0.5, 2.0.6, 2.0.7, and 2.0.8 only
    // It will NOT trigger for versions above 2.0.9 or below 2.0.5
    // With version 2.0.9 we need to force update cache key regex pattern for older versions with default
    if (version_compare($old_version, '2.0.9', '<') && version_compare($old_version, '2.0.5', '>=')) {
        // Get the current options
        $options = get_option('nginx_cache_settings', array());

        // Get the default cache key regex
        $default_cache_key_regex = nppp_fetch_default_regex_for_cache_key();

        // Override user regex setting with the default regex
        $options['nginx_cache_key_custom_regex'] = $default_cache_key_regex;

        // Update the options with the default regex value
        update_option('nginx_cache_settings', $options);

        // Optionally, display a notice or handle any further updates for this change
        nppp_display_admin_notice(
            'info',
            'The cache key regex has been reset to the default due to changes in version 2.0.9. Please review and adjust the regex, if needed, in the plugin settings under the Advanced Options section.',
            false,
            true
        );
    }

    // Clear plugin cache after update
    // Triggers on all updates
    nppp_clear_plugin_cache();
}
