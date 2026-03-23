<?php
/**
 * Update routines for Nginx Cache Purge Preload
 * Description: Runs version checks and applies plugin migration or maintenance updates.
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

// Check for plugin updates and run update routines if necessary.
function nppp_check_for_plugin_update() {
    // NPPP_PLUGIN_VERSION is defined in the main plugin file
    // alongside NPPP_PLUGIN_FILE and must be kept in sync with the file header.
    if ( ! defined( 'NPPP_PLUGIN_VERSION' ) ) {
        return;
    }
    $current_version = NPPP_PLUGIN_VERSION;

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
        '2.1.5' => array( 'nppp_migration_215' ),
    );
}

// Run update routines when the plugin version changes.
function nppp_run_update_routines( $old_version, $new_version ) {
    // No-op when there is no effective version transition.
    if ( empty( $old_version ) || $old_version === $new_version ) {
        return;
    }

    // Keep cache state consistent for active installs after plugin updates.
    nppp_clear_plugin_cache(true);

    // Run any pending DB migrations that have not been applied yet.
    nppp_run_pending_migrations( $old_version, $new_version );
}

// Execute all migrations whose version is greater than the current DB version.
function nppp_run_pending_migrations( $old_version, $new_version ) {
    // All 2.1.5 migrations target installs coming from 2.0.1–2.1.4.
    // Users already on 2.1.5+ have already run them — skip entirely.
    if ( version_compare( $old_version, '2.1.5', '>=' ) ) {
        return;
    }

    $db_version     = get_option( 'nppp_db_version', '0.0.0' );
    $ran_migrations = array();

    foreach ( nppp_get_db_migrations() as $version => $callbacks ) {
        if ( version_compare( $db_version, $version, '<' ) ) {
            foreach ( $callbacks as $callback ) {
                if ( function_exists( $callback ) ) {
                    call_user_func( $callback );
                    $ran_migrations[] = $version;
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

    // Set a short-lived transient so the admin_notices hook can display the
    // migration result on the next page load.
    if ( ! empty( $ran_migrations ) ) {
        $versions_str = implode( ', ', array_unique( $ran_migrations ) );
        set_transient(
            'nppp_migration_notice',
            sprintf(
                /* translators: 1: migration version(s), 2: old version, 3: new version */
                __( 'MIGRATION: Plugin updated from %2$s to %3$s. In version %1$s: Opt-in usage tracking has been completely removed. /opt/ removed from allowed Nginx cache path roots, if your Nginx cache was stored under /opt/, please move it to a supported location and re-save in Settings.', 'fastcgi-cache-purge-and-preload-nginx' ),
                $versions_str,
                $old_version,
                $new_version
            ),
            MINUTE_IN_SECONDS
        );
    }
}

// Display a one-time admin notice after DB migrations have run.
function nppp_migration_admin_notice() {
    // Only show on the plugin settings screen.
    if ( function_exists( 'get_current_screen' ) ) {
        $screen = get_current_screen();
        if ( ! $screen || 'settings_page_nginx_cache_settings' !== $screen->id ) {
            return;
        }
    }

    $message = get_transient( 'nppp_migration_notice' );
    if ( empty( $message ) ) {
        return;
    }

    // Delete immediately — display once only.
    delete_transient( 'nppp_migration_notice' );

    // Delegate to the plugin's own notice renderer.
    nppp_display_admin_notice( 'success', $message, false, true );
}
add_action( 'admin_notices', 'nppp_migration_admin_notice' );

// Migration 2.1.5:
// 1) Remove all opt-in tracking data left by 2.0.1–2.1.4.
// 2) Also backfills 2.1.5 features for existing installs
function nppp_migration_215() {
    // Clear cron events — may be scheduled with or without args.
    wp_clear_scheduled_hook( 'npp_plugin_tracking_event', array( 'active' ) );
    wp_clear_scheduled_hook( 'npp_plugin_tracking_event' );

    // Strip the opt-in key from the settings option if present.
    $options = get_option( 'nginx_cache_settings' );
    if ( is_array( $options ) && array_key_exists( 'nginx_cache_tracking_opt_in', $options ) ) {
        unset( $options['nginx_cache_tracking_opt_in'] );
        update_option( 'nginx_cache_settings', $options );
    }

    // Backfill: grant the custom purge capability to Administrators.
    // Existing installs never got this via activation hook.
    $admin_role = get_role( 'administrator' );
    if ( $admin_role && ! isset( $admin_role->capabilities['nppp_purge_cache'] ) ) {
        $admin_role->add_cap( 'nppp_purge_cache' );
    }

    // Backfill: schedule the daily index updater introduced in 2.1.5.
    if ( function_exists( 'nppp_schedule_index_updater' ) ) {
        nppp_schedule_index_updater();
    }
}
