<?php
/**
 * Background URL index updater for Nginx Cache Purge Preload
 * Description: WP-Cron job that refreshes the persistent URL→filepath index
 *              by scanning the live cache directory once per day.
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

/**
 * Schedule the daily index updater cron event.
 *
 * Called on plugin activation.
 * Safe to call multiple times — wp_next_scheduled() prevents double-scheduling.
 *
 * @return void
 */
function nppp_schedule_index_updater(): void {
    if ( wp_next_scheduled( 'nppp_index_updater_event' )
        && wp_get_schedule( 'nppp_index_updater_event' ) !== 'every_3hours_npp'
    ) {
        wp_clear_scheduled_hook( 'nppp_index_updater_event' );
    }

    if ( ! wp_next_scheduled( 'nppp_index_updater_event' ) ) {
        wp_schedule_event( time(), 'every_3hours_npp', 'nppp_index_updater_event' );
    }
}

/**
 * Unschedule the daily index updater cron event.
 *
 * Called on plugin deactivation and during plugin uninstall.
 * @return void
 */
function nppp_unschedule_index_updater(): void {
    wp_clear_scheduled_hook( 'nppp_index_updater_event' );
}

/**
 * Core callback for the nppp_index_updater_event WP-Cron hook.
 *
 * Performs a full recursive scan of the Nginx cache directory and merges
 * all discovered URL→filepath mappings into the persistent index.
 *
 * @return void
 */
function nppp_run_index_updater(): void {
    // Settings not saved yet.
    $options = get_option( 'nginx_cache_settings' );
    if ( ! is_array( $options ) ) {
        return;
    }

    // Cache path not configured or still at default placeholder.
    $nginx_cache_path = $options['nginx_cache_path'] ?? '';
    if ( empty( $nginx_cache_path ) || $nginx_cache_path === '/dev/shm/change-me-now' ) {
        return;
    }

    // WP_Filesystem unavailable (cron runs headless, Direct only).
    $wp_filesystem = nppp_initialize_wp_filesystem();
    if ( $wp_filesystem === false ) {
        return;
    }

    // Cache directory does not exist on disk.
    if ( ! $wp_filesystem->is_dir( $nginx_cache_path ) ) {
        return;
    }

    // Path safety check
    if ( nppp_validate_path( $nginx_cache_path ) !== true ) {
        return;
    }

    // Purge operation is in progress.
    if ( nppp_is_purge_lock_held() ) {
        return;
    }

    // Preload process is in progress.
    if ( nppp_is_preload_running( $wp_filesystem ) ) {
        return;
    }

    // Permission check Soft check first (cheap)
    if ( ! $wp_filesystem->is_readable( $nginx_cache_path )
        || ! $wp_filesystem->is_writable( $nginx_cache_path )
    ) {
        return;
    }

    // Extract cached URLs; bail on any scan error.
    $hits = nppp_extract_cached_urls( $wp_filesystem, $nginx_cache_path );
    if ( isset( $hits['error'] ) ) {
        return;
    }

    // Update index.
    //   - Existing entries are preserved (never truncate).
    //   - New paths are appended; duplicate paths are deduplicated.
    //   - A single URL may map to multiple paths (Vary variants, mobile).
    $nppp_index = get_option( 'nppp_url_filepath_index' );
    $nppp_index = is_array( $nppp_index ) ? $nppp_index : [];

    foreach ( $hits as $nppp_entry ) {
        $nppp_key      = preg_replace( '#^https?://#', '', $nppp_entry['url_encoded'] );
        $nppp_existing = $nppp_index[ $nppp_key ] ?? [];
        if ( ! in_array( $nppp_entry['file_path'], $nppp_existing, true ) ) {
            $nppp_existing[] = $nppp_entry['file_path'];
        }
        $nppp_index[ $nppp_key ] = $nppp_existing;
    }

    update_option( 'nppp_url_filepath_index', $nppp_index, false );
    unset( $nppp_index, $nppp_entry, $nppp_key, $nppp_existing, $hits );
}
