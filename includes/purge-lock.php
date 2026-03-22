<?php
/**
 * Purge operation locking for Nginx Cache Purge Preload
 * Description: Atomic WP_Upgrader-based lock helpers for concurrent purge serialization
 * Version: 2.1.4
 * Author: Hasan CALISIR
 * Author Email: hasan.calisir@psauxit.com
 * Author URI: https://www.psauxit.com
 * License: GPL-2.0+
 */

/**
 *
 * Wraps WP_Upgrader::create_lock() / release_lock() with plugin-specific
 * TTLs and a clean API so no other file needs to know about WP_Upgrader
 * internals or class-wp-upgrader.php loading.
 *
 * All destructive cache operations share a single lock name so they
 * serialize against each other regardless of which code path triggered them.
 *
 * TTL meaning: how long a CRASHED PHP process may hold the lock before
 * another process is allowed to steal it. During normal operation the
 * lock is always released immediately via finally/explicit release — the
 * TTL is never consulted. See nppp_acquire_purge_lock() for details.
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Single lock name shared by all purge operations in the plugin.
// One name ensures purge_single, purge_all, and advanced purge
// all block each other — not just operations of the same type.
if ( ! defined( 'NPPP_PURGE_LOCK_NAME' ) ) {
    define( 'NPPP_PURGE_LOCK_NAME', 'nppp_cache_purge' );
}

/**
 * Acquire the exclusive plugin purge lock.
 *
 * Uses WP_Upgrader::create_lock() which issues a single atomic
 * INSERT IGNORE into wp_options — the DB engine guarantees only
 * one winner when two processes race simultaneously.
 *
 * TTL context presets (all filterable):
 *   'single'  — nppp_purge_single: walks entire cache dir file-by-file.
 *               On large sites with slow storage this can take > 60s.
 *               Default 180s covers ~500k files on spinning disk.
 *   'all'     — nppp_purge: deletes top-level dirs, kernel handles recursion.
 *               Almost never exceeds 15s even on huge caches.
 *               Default 60s gives crash-recovery headroom.
 *   'premium' — nppp_purge_cache_premium_callback: deletes a single
 *               pre-located file. Near-instant in all cases.
 *               Default 60s is pure crash-safety margin.
 *
 * @param string $context  'single' | 'all' | 'premium'
 * @return bool  true = lock acquired, false = already locked by another process
 */
function nppp_acquire_purge_lock( string $context = 'single' ): bool {
    if ( ! class_exists( 'WP_Upgrader' ) ) {
        require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
    }

    switch ( $context ) {
        case 'all':
            // Purge All: kernel-level recursive dir delete — fast even on huge caches.
            $ttl = (int) apply_filters( 'nppp_purge_all_lock_ttl', 60 );
            break;

        case 'premium':
            // Advanced tab single-page purge: deletes one pre-located file — near instant.
            $ttl = (int) apply_filters( 'nppp_purge_premium_lock_ttl', 60 );
            break;

        case 'single':
        default:
            // Single-page purge: walks entire cache dir file-by-file — slowest operation.
            $ttl = (int) apply_filters( 'nppp_purge_single_lock_ttl', 180 );
            break;
    }

    return WP_Upgrader::create_lock( NPPP_PURGE_LOCK_NAME, $ttl );
}

/**
 * Release the plugin purge lock.
 *
 * Safe to call even if the lock was never acquired (e.g. early-return
 * paths before the acquire call). WP_Upgrader::release_lock() is
 * internally a delete_option() which is a no-op on missing keys.
 *
 * @return void
 */
function nppp_release_purge_lock(): void {
    if ( ! class_exists( 'WP_Upgrader' ) ) {
        require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
    }

    WP_Upgrader::release_lock( NPPP_PURGE_LOCK_NAME );
}

/**
 * Non-destructive probe: returns true if a purge lock is currently held.
 *
 * WP_Upgrader::create_lock() stores the lock as a wp_options row named
 * 'lock_<name>'. It is set when a purge starts and deleted when the purge
 * finishes (or when the TTL expires after a crash). Preload callers use this
 * to abort early instead of spawning wget into a cache directory that is
 * actively being deleted.
 *
 * Implementation: attempt to acquire with a 0-second TTL. If it fails someone
 * else already holds the lock; if it succeeds release immediately — we only
 * needed the boolean result.
 *
 * @return bool  true = a purge operation is in progress, false = cache is idle
 */
function nppp_is_purge_lock_held(): bool {
    if ( ! class_exists( 'WP_Upgrader' ) ) {
        require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
    }
 
    // Try to acquire with the shortest meaningful TTL.
    $acquired = WP_Upgrader::create_lock( NPPP_PURGE_LOCK_NAME, 1 );
    if ( $acquired ) {
        // We won the race — immediately give it back.
        WP_Upgrader::release_lock( NPPP_PURGE_LOCK_NAME );
        return false;
    }
 
    return true;
}
