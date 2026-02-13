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
    // Get the current plugin data.
    $plugin_data     = get_plugin_data( NPPP_PLUGIN_FILE );
    $current_version = $plugin_data['Version'];

    // Get the saved version from the database.
    $saved_version = get_option( 'nppp_plugin_version' );

    // Run update routines only when version changed.
    if ( $saved_version !== $current_version ) {
        nppp_run_update_routines( $saved_version, $current_version );
        update_option( 'nppp_plugin_version', $current_version );
    }
}

// Run update routines when the plugin version changes.
function nppp_run_update_routines( $old_version, $new_version ) {
    // No-op when there is no effective version transition.
    if ( empty( $old_version ) || $old_version === $new_version ) {
        return;
    }

    // Keep cache state consistent for active installs after plugin updates.
    nppp_clear_plugin_cache();
}
