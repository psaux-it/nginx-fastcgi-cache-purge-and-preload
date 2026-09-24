<?php
/**
 * Purge operation locking for Nginx Cache Purge Preload
 * Description: Atomic WP_Upgrader-based lock helpers for concurrent purge serialization
 * Version: 2.1.7
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
            // Purge All: HTTP fast path (if enabled) is near-instant (delegate nginx) or
            // direct filesystem fallback is a kernel-level recursive dir delete
            // fast even on huge caches.
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

// Lock for post-preload completion (watchdog AJAX vs. WP-Cron tick race).
if ( ! defined( 'NPPP_COMPLETION_LOCK_NAME' ) ) {
    define( 'NPPP_COMPLETION_LOCK_NAME', 'nppp_preload_completion' );
}

/**
 * Acquire the post-preload completion lock.
 *
 * Same atomic mechanism as the purge lock (single INSERT IGNORE into wp_options),
 * so exactly one of the racing callers (watchdog AJAX vs. WP-Cron tick) wins.
 * get_transient()/set_transient() is a check-then-set and lets both through.
 *
 * TTL is crash-safety only: the caller always releases explicitly via
 * nppp_release_completion_lock() on every exit path, so 120s is pure headroom
 * for a crashed PHP process (index rebuild on a large cache can run close to
 * the old 30s default).
 *
 * @param int $ttl Seconds before a crashed holder's lock may be taken over.
 * @return bool true = acquired, false = another process holds it.
 */
function nppp_acquire_completion_lock( int $ttl = 120 ): bool {
    if ( ! class_exists( 'WP_Upgrader' ) ) {
        require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
    }

    return WP_Upgrader::create_lock( NPPP_COMPLETION_LOCK_NAME, $ttl );
}

/**
 * Release the post-preload completion lock (no-op if not held).
 *
 * @return void
 */
function nppp_release_completion_lock(): void {
    if ( ! class_exists( 'WP_Upgrader' ) ) {
        require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
    }

    WP_Upgrader::release_lock( NPPP_COMPLETION_LOCK_NAME );
}

// Lock for the "Preload All" start sequence (PID check -> purge -> spawn -> PID write).
if ( ! defined( 'NPPP_PRELOAD_START_LOCK_NAME' ) ) {
    define( 'NPPP_PRELOAD_START_LOCK_NAME', 'nppp_preload_start' );
}

/**
 * Acquire the preload start lock.
 *
 * Serializes the check-then-spawn window in nppp_preload() (PID check ->
 * purge -> cpulimit probe -> premature-process test -> wget spawn -> PID
 * write) so simultaneous starts from any entry point — CLI, REST, UI,
 * admin bar, cron, auto-preload — cannot each pass the PID check before
 * any of them has written a real PID, and each spawn its own untracked
 * wget crawler. Same atomic mechanism as the purge lock: a single
 * INSERT IGNORE into wp_options via WP_Upgrader::create_lock(), so it
 * works across PHP-FPM and WP-CLI processes alike.
 *
 * TTL is crash-safety only — the caller always releases via a shutdown
 * hook on every exit path (return, exception, or hard timeout). 300s
 * mirrors the purge lock's 'single' context (180s) plus headroom for the
 * proxy probe and premature-process test that sit inside this window.
 *
 * @param int $ttl Seconds before a crashed holder's lock may be taken over.
 * @return bool true = acquired, false = another process is starting a preload.
 */
function nppp_acquire_preload_start_lock( int $ttl = 300 ): bool {
    if ( ! class_exists( 'WP_Upgrader' ) ) {
        require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
    }

    $ttl = (int) apply_filters( 'nppp_preload_start_lock_ttl', $ttl );

    return WP_Upgrader::create_lock( NPPP_PRELOAD_START_LOCK_NAME, $ttl );
}

/**
 * Release the preload start lock (no-op if not held).
 *
 * @return void
 */
function nppp_release_preload_start_lock(): void {
    if ( ! class_exists( 'WP_Upgrader' ) ) {
        require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
    }

    WP_Upgrader::release_lock( NPPP_PRELOAD_START_LOCK_NAME );
}

/**
 * Non-destructive probe: true if the preload start lock is currently held.
 *
 * Same reconstruction approach as nppp_is_purge_lock_held() — reads the
 * raw option with zero side-effects, treats a lock older than its TTL as
 * stale. Wired into nppp_is_operation_active() below so the settings-change
 * guard also blocks during the brief in-flight window before a starting
 * preload has written its real PID — a window nppp_is_preload_running()
 * cannot see, since it also only trusts the PID file.
 *
 * @return bool true = a preload start is in progress, false = idle.
 */
function nppp_is_preload_start_lock_held(): bool {
    $lock_option = NPPP_PRELOAD_START_LOCK_NAME . '.lock';
    $lock_time   = get_option( $lock_option );

    if ( ! $lock_time ) {
        return false;
    }

    $lock_time = (int) $lock_time;

    if ( $lock_time <= 0 ) {
        return false;
    }

    $ttl = (int) apply_filters( 'nppp_preload_start_lock_ttl', 300 );

    if ( $lock_time > ( time() - $ttl ) ) {
        return true;
    }

    delete_option( $lock_option );

    return false;
}

/**
 * Returns true when any destructive cache operation is currently active.
 *
 * Combines the purge lock (nppp_is_purge_lock_held), the preload start
 * lock (nppp_is_preload_start_lock_held), and the preload PID check
 * (nppp_is_preload_running) so that callers — settings form, AJAX
 * handlers, WP-CLI — can gate option writes without duplicating logic.
 * Both current callers (settings-page.php, wp-cli.php) only ever use this
 * to block, never to allow, so widening it is safe by construction.
 *
 * Uses the Direct filesystem driver because bootstrap is always loaded
 * before this is called; nppp_initialize_wp_filesystem() is safe here.
 *
 * @return bool  true = operation in progress, false = cache is idle
 */
function nppp_is_operation_active(): bool {
    if ( nppp_is_purge_lock_held() ) {
        return true;
    }
    if ( nppp_is_preload_start_lock_held() ) {
        return true;
    }
    $wp_filesystem = nppp_initialize_wp_filesystem();
    if ( $wp_filesystem === false ) {
        return false;
    }
    return nppp_is_preload_running( $wp_filesystem );
}

/**
 * Non-destructive probe: returns true if a purge lock is currently held.
 *
 * WP_Upgrader::create_lock() stores the lock as a wp_options row named
 * '<name>.lock' with the acquisition timestamp (time()) as its value.
 * The lock is created when a purge starts and deleted when the purge
 * finishes (or stolen by the next caller after the TTL expires on crash).
 * Preload callers use this to abort early instead of spawning wget into
 * a cache directory that is actively being deleted.
 *
 * Implementation: reads the raw option directly — zero side-effects,
 * no write, no race window. Reconstructs validity using the MAX TTL
 * across all purge contexts so we never produce a false negative
 * (i.e. we never claim "no lock" while a slow single-page purge is
 * still walking the cache tree under its 180s TTL).
 *
 * @return bool  true = a purge operation is in progress, false = cache is idle
 */
function nppp_is_purge_lock_held(): bool {
    // WP core stores locks as '<name>.lock'
    $lock_option = NPPP_PURGE_LOCK_NAME . '.lock';
    $lock_time   = get_option( $lock_option );

    // No lock row present at all.
    if ( ! $lock_time ) {
        return false;
    }

    $lock_time = (int) $lock_time;

    // Sanity-check
    if ( $lock_time <= 0 ) {
        return false;
    }

    // Use the MAX TTL across all purge contexts to avoid false negatives.
    // WP stores only the start time; we must reconstruct the TTL window here.
    // These filters must mirror the values used in nppp_acquire_purge_lock().
    $ttl = max(
        (int) apply_filters( 'nppp_purge_single_lock_ttl',  180 ),
        (int) apply_filters( 'nppp_purge_all_lock_ttl',      60 ),
        (int) apply_filters( 'nppp_purge_premium_lock_ttl',  60 )
    );

    // Core WP validity logic: lock is held if acquired less than $ttl seconds ago.
    if ( $lock_time > ( time() - $ttl ) ) {
        return true;
    }

    // Stale lock left by a crashed PHP process — clean it up passively.
    delete_option( $lock_option );

    return false;
}
