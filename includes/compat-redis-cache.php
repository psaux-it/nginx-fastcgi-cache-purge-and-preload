<?php
/**
 * Redis Object Cache compatibility for Nginx Cache Purge Preload
 * Description: Sync between NPP Nginx Cache and Redis Object Cache.
 *              NPP Purge-All + Auto-Preload ON → Flushes Redis object cache
 *              Direction (NPP → Redis) is intentionally gated on the auto-preload setting.
 *              Flushing Redis only makes sense as part of the purge+preload pair: the preload
 *              crawl rebuilds Redis from scratch, giving both caches a clean, consistent state.
 *              A purge-only operation (Auto-Preload OFF) should leave Redis warm — it is still
 *              valid and will serve DB queries faster when the next request rebuilds Nginx pages.
 *
 * Version: 2.1.5
 * Author: Hasan CALISIR
 * Author Email: hasan.calisir@psauxit.com
 * Author URI: https://www.psauxit.com
 * License: GPL-2.0+
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Detection helpers
if ( ! function_exists( 'nppp_redis_cache_is_available' ) ) {
    /**
     * Returns true when the Redis Object Cache drop-in is present and connected.
     *
     * Detection logic (in order):
     *  1. WP_REDIS_VERSION constant — defined inside object-cache.php drop-in.
     *  2. $wp_object_cache has redis_status() returning true — confirms live connection.
     *
     * We require BOTH: the constant proves the right drop-in is installed,
     * redis_status() proves Redis is actually reachable at runtime.
     */
    function nppp_redis_cache_is_available(): bool {
        if ( ! defined( 'WP_REDIS_VERSION' ) ) {
            return false;
        }

        global $wp_object_cache;

        return is_object( $wp_object_cache )
            && method_exists( $wp_object_cache, 'redis_status' )
            && $wp_object_cache->redis_status() === true;
    }
}

if ( ! function_exists( 'nppp_redis_cache_log' ) ) {
    /**
     * Writes to the NPP log file only — no admin notice is displayed.
     */
    function nppp_redis_cache_log( string $message, string $type = 'info' ): void {
        if ( function_exists( 'nppp_display_admin_notice' ) ) {
            nppp_display_admin_notice( $type, $message, true, false );
        }
    }
}

if ( ! function_exists( 'nppp_redis_cache_sync_is_on' ) ) {
    /**
     * Returns true if the Redis Cache sync toggle is enabled in NPP settings.
     */
    function nppp_redis_cache_sync_is_on(): bool {
        $options = get_option( 'nginx_cache_settings', [] );

        return isset( $options['nppp_redis_cache_sync'] )
            && $options['nppp_redis_cache_sync'] === 'yes';
    }
}

// Fired by NPP's nppp_purge() after every successful "Purge All" operation,
// whether triggered manually, via admin bar, auto-purge, REST API, or schedule.
//
// GATE: Redis is flushed only when NPP's auto-preload feature is enabled.
//
// Rationale:
//   When auto-preload is ON, the sequence is:
//     1. Nginx cache purged
//     2. Redis object cache flushed  ← this hook
//     3. NPP preload crawl starts
//     4. Each crawl request hits WordPress → Redis cache is rebuilt
//     5. Nginx cache is populated back
//   Both caches end up clean and consistent. The DB load spike during the
//   crawl is bounded and expected — Redis warms itself back up as pages
//   are rebuilt. This is the canonical "clean slate" deployment pattern.
//
//   When auto-preload is OFF, Nginx is empty after purge and the next real
//   user request rebuilds each page. Redis is still warm and valid — it will
//   serve DB queries faster during that rebuild. Flushing it here would cause
//   a double cold-start penalty (empty Nginx + empty Redis) for no benefit.
//
// This hook does NOT fire for single-page purges (nppp_purge_single).
if ( ! function_exists( 'nppp_redis_cache_on_nppp_purge_all' ) ) {
    function nppp_redis_cache_on_nppp_purge_all(): void {
        // Gate: redis cache sync enabled.
        if ( ! nppp_redis_cache_sync_is_on() ) {
            return;
        }

        // Gate: only flush Redis when auto-preload is enabled.
        $options = get_option( 'nginx_cache_settings', [] );
        if ( ( $options['nginx_cache_auto_preload'] ?? 'no' ) !== 'yes' ) {
            return;
        }

        // Gate: only act when Redis is actually reachable.
        if ( ! nppp_redis_cache_is_available() ) {
            return;
        }

        // Gate: honour the filter (allows third-party overrides).
        if ( ! apply_filters( 'nppp_sync_redis_cache_enabled', true, 'nppp_to_redis' ) ) {
            return;
        }

        // Flush redis cache
        $flushed = wp_cache_flush();

        if ( $flushed ) {
            nppp_redis_cache_log(
                __( 'Redis Object Cache flushed after Nginx cache purge.', 'fastcgi-cache-purge-and-preload-nginx' ),
                'success'
            );
        } else {
            nppp_redis_cache_log(
                __( 'Redis Object Cache flush attempted after Nginx cache purge, but wp_cache_flush() returned false.', 'fastcgi-cache-purge-and-preload-nginx' ),
                'error'
            );
        }
    }
}

add_action( 'nppp_purged_all', 'nppp_redis_cache_on_nppp_purge_all' );

// Fired by nppp_preload() for all direct "Preload All" operations
// (Admin button, Admin Bar, REST API, Cron)
//
// Unlike the Purge All handler above, this has NO auto-preload gate:
if ( ! function_exists( 'nppp_redis_cache_on_nppp_preload_all' ) ) {
    function nppp_redis_cache_on_nppp_preload_all(): void {
        // Gate: redis cache sync enabled.
        if ( ! nppp_redis_cache_sync_is_on() ) {
            return;
        }

        // Gate: only act when Redis is actually reachable.
        if ( ! nppp_redis_cache_is_available() ) {
            return;
        }

        // Gate: honour the filter (allows third-party overrides).
        if ( ! apply_filters( 'nppp_sync_redis_cache_enabled', true, 'nppp_to_redis' ) ) {
            return;
        }

        // Flush redis cache before the preload crawl rebuilds it.
        $flushed = wp_cache_flush();

        if ( $flushed ) {
            nppp_redis_cache_log(
                __( 'Redis Object Cache flushed before Nginx cache preload.', 'fastcgi-cache-purge-and-preload-nginx' ),
                'success'
            );
        } else {
            nppp_redis_cache_log(
                __( 'Redis Object Cache flush attempted before Nginx cache preload, but wp_cache_flush() returned false.', 'fastcgi-cache-purge-and-preload-nginx' ),
                'error'
            );
        }
    }
}

add_action( 'nppp_preload_all_started', 'nppp_redis_cache_on_nppp_preload_all' );

// Guard: auto-disable the toggle if Redis Cache is deactivated / disconnected
// If the toggle is on but the dependency
// is gone, flip it back to 'no' so the UI stays consistent.

if ( ! function_exists( 'nppp_redis_cache_sync_option_enabled' ) ) {
    /**
     * Filter callback for `nppp_sync_redis_cache_enabled`.
     *
     * @param bool   $enabled  Current enabled state.
     * @return bool
     */
    function nppp_redis_cache_sync_option_enabled( bool $enabled, string $context = '' ): bool {
        if ( ! $enabled ) {
            return false;
        }

        if ( ! nppp_redis_cache_sync_is_on() ) {
            return false;
        }

        // If the toggle is on but Redis has gone away, clear the toggle.
        if ( ! nppp_redis_cache_is_available() ) {
            $options = get_option( 'nginx_cache_settings', [] );
            if ( isset( $options['nppp_redis_cache_sync'] ) && $options['nppp_redis_cache_sync'] !== 'no' ) {
                $options['nppp_redis_cache_sync'] = 'no';
                update_option( 'nginx_cache_settings', $options );
            }
            return false;
        }

        return true;
    }
}

add_filter( 'nppp_sync_redis_cache_enabled', 'nppp_redis_cache_sync_option_enabled', 10, 2 );
