<?php
/**
 * Update routines for Nginx Cache Purge Preload
 * Description: Runs version checks and applies plugin migration or maintenance updates.
 * Version: 2.1.4
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
    // Get the current plugin version directly from the plugin file header.
    $plugin_headers  = get_file_data( NPPP_PLUGIN_FILE, array( 'Version' => 'Version' ) );
    $current_version = $plugin_headers['Version'];

    // Bail if the version string could not be read from disk.
    if ( empty( $current_version ) ) {
        return;
    }
 
    // Get the saved version from the database.
    $saved_version = get_option( 'nppp_plugin_version' );
 
    // Run update routines only when version changed.
    if ( $saved_version !== $current_version ) {
        nppp_run_update_routines( $saved_version, $current_version );
        update_option( 'nppp_plugin_version', $current_version );
    }
}
 
// Versioned DB migrations — keyed by the plugin version that introduced them.
// Each entry is an array of callback function names to run once and only once.
// NEVER remove entries from this array; only append new ones.
function nppp_get_db_migrations() {
    return array(
        '2.1.5' => array( 'nppp_migration_215_remove_tracking' ),
    );
}
 
// Run update routines when the plugin version changes.
function nppp_run_update_routines( $old_version, $new_version ) {
    // No-op when there is no effective version transition.
    if ( empty( $old_version ) || $old_version === $new_version ) {
        return;
    }
 
    // Keep cache state consistent for active installs after plugin updates.
    nppp_clear_plugin_cache();
 
    // Run any pending DB migrations that have not been applied yet.
    nppp_run_pending_migrations( $old_version );
}
 
// Execute all migrations whose version is greater than the current DB version.
function nppp_run_pending_migrations( $old_version ) {
    // Tracking existed only in 2.0.1–2.1.4. Users already on 2.1.5+
    // never had the feature so there is nothing to clean up.
    if ( version_compare( $old_version, '2.1.5', '>=' ) ) {
        return;
    }
 
    $db_version = get_option( 'nppp_db_version', '0.0.0' );
 
    foreach ( nppp_get_db_migrations() as $version => $callbacks ) {
        if ( version_compare( $db_version, $version, '<' ) ) {
            foreach ( $callbacks as $callback ) {
                if ( function_exists( $callback ) ) {
                    call_user_func( $callback );
                } else {
                    // Callback not found — likely a typo in nppp_get_db_migrations().
                    nppp_custom_error_log( 'Migration callback not found: ' . $callback );
                }
            }
            // Stamp this version into the DB immediately after its callbacks finish.
            // If a later callback crashes, the completed version block is already safe.
            update_option( 'nppp_db_version', $version );
            $db_version = $version;
        }
    }
}
 
// Migration 2.1.5: remove all opt-in tracking data left by 2.0.1–2.1.4.
function nppp_migration_215_remove_tracking() {
    // Clear cron events — may be scheduled with or without args.
    wp_clear_scheduled_hook( 'npp_plugin_tracking_event', array( 'active' ) );
    wp_clear_scheduled_hook( 'npp_plugin_tracking_event' );
 
    // Strip the opt-in key from the settings option if present.
    $options = get_option( 'nginx_cache_settings' );
    if ( is_array( $options ) && array_key_exists( 'nginx_cache_tracking_opt_in', $options ) ) {
        unset( $options['nginx_cache_tracking_opt_in'] );
        update_option( 'nginx_cache_settings', $options );
    }
}
