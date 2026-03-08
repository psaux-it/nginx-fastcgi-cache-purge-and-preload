<?php
/**
 * Redis Object Cache compatibility for Nginx Cache Purge Preload
 * Description: Bidirectional sync between NPP Nginx Cache and Redis Object Cache.
 *              - NPP Purge-All   → Flushes Redis object cache (wp_cache_flush)
 *              - Redis Flush     → Purges all Nginx cache via NPP (when auto-purge is on)
 * Version: 2.1.4
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
     * Emits an admin notice through NPP's logging system.
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

// Direction 1 – NPP Purge All → Flush Redis Object Cache
//
// Fired by NPP's nppp_purge() after every successful "Purge All" operation,
// whether triggered manually, via admin bar, auto-purge, REST API, or schedule.
//
// Flushing the object cache here ensures PHP will regenerate fresh data from
// the database on the next request, so pages rebuilt into Nginx cache will
// contain up-to-date content.
//
// The NPPP_REDIS_FLUSH_ORIGIN global is set here and checked in Direction 2
// to prevent an infinite loop:

if ( ! function_exists( 'nppp_redis_cache_on_nppp_purge_all' ) ) {
    function nppp_redis_cache_on_nppp_purge_all(): void {
        // Loop prevention: bail if this purge was triggered by Direction 2.
        if ( ! empty( $GLOBALS['NPPP_REDIS_FLUSH_ORIGIN'] ) && $GLOBALS['NPPP_REDIS_FLUSH_ORIGIN'] === 'nppp' ) {
            return;
        }

        // Gate: toggle must be on.
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

        // Mark that this flush originates from NPP so Direction 2 can bail.
        $GLOBALS['NPPP_REDIS_FLUSH_ORIGIN'] = 'nppp';

        $flushed = wp_cache_flush();

        unset( $GLOBALS['NPPP_REDIS_FLUSH_ORIGIN'] );

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

// Direction 2 – Redis Object Cache Flush → Purge All Nginx cache
//
// Fires whenever wp_cache_flush() succeeds and Redis is connected.
// This covers explicit flushes from the Redis Cache plugin dashboard,
// WP-CLI (`wp cache flush`), and any plugin calling wp_cache_flush().
//
// We only act if NPP's auto-purge setting is enabled (nginx_cache_purge_on_update)
// because:
//  a) An Nginx page-cache purge is a heavyweight filesystem operation.
//  b) It mirrors the existing $nppp_page_cache_purge_actions contract —
//     those hooks are also only registered when auto-purge is on.
//
// Loop prevention: bail immediately when NPPP_REDIS_FLUSH_ORIGIN === 'nppp',
// which Direction 1 sets before calling wp_cache_flush().

if ( ! function_exists( 'nppp_redis_cache_on_redis_flush' ) ) {
    /**
     * @param array|null $results      Flush results from Redis.
     * @param int        $deprecated   Unused (always 0).
     * @param bool|null  $selective    True if WP_REDIS_SELECTIVE_FLUSH was used.
     * @param string     $salt         WP_REDIS_PREFIX value (may be empty).
     * @param float      $execute_time Seconds taken to flush.
     */
    function nppp_redis_cache_on_redis_flush(
        $results,
        int $deprecated,
        $selective,
        ?string $salt,
        float $execute_time
    ): void {

        // Loop prevention: this flush was started by NPP itself (Direction 1).
        if ( ! empty( $GLOBALS['NPPP_REDIS_FLUSH_ORIGIN'] ) && $GLOBALS['NPPP_REDIS_FLUSH_ORIGIN'] === 'nppp' ) {
            return;
        }

        // Gate: only act if Redis actually completed the flush.
        // The hook fires before the drop-in validates its own results, so we
        // check here to avoid purging Nginx after a partial or failed Redis flush.
        if ( empty( $results ) ) {
            return;
        }

        foreach ( (array) $results as $result ) {
            if ( $result === false ) {
                return;
            }
        }

        // Gate: toggle must be on.
        if ( ! nppp_redis_cache_sync_is_on() ) {
            return;
        }

        // Gate: honour the filter.
        if ( ! apply_filters( 'nppp_sync_redis_cache_enabled', true, 'redis_to_nppp' ) ) {
            return;
        }

        // Gate: NPP auto-purge must be enabled for this direction.
        // This mirrors the $nppp_page_cache_purge_actions contract.
        $nginx_options = get_option( 'nginx_cache_settings', [] );
        if ( ( $nginx_options['nginx_cache_purge_on_update'] ?? 'no' ) !== 'yes' ) {
            return;
        }

        // Gate: NPP purge function must be available (bootstrap may not be loaded
        // in all execution contexts, e.g. WP-CLI without --url).
        if ( ! function_exists( 'nppp_purge_callback' ) ) {
            return;
        }

        // Set the origin flag BEFORE calling nppp_purge_callback() so that
        // Direction 1 (nppp_purged_all) sees it and bails, preventing a
        // redundant second Redis flush during this same operation.
        $GLOBALS['NPPP_REDIS_FLUSH_ORIGIN'] = 'nppp';

        nppp_redis_cache_log(
            __( 'Redis Object Cache was flushed — triggering Nginx cache purge.', 'fastcgi-cache-purge-and-preload-nginx' ),
            'info'
        );

        nppp_purge_callback();
        unset( $GLOBALS['NPPP_REDIS_FLUSH_ORIGIN'] );
    }
}

// redis_object_cache_flush is fired by the drop-in after every successful flush().
// Signature: ( array $results, int $deprecated, bool $selective, string $salt, float $execute_time )
add_action( 'redis_object_cache_flush', 'nppp_redis_cache_on_redis_flush', 10, 5 );

// Guard: auto-disable the toggle if Redis Cache is deactivated / disconnected
// If the toggle is on but the dependency
// is gone, flip it back to 'no' so the UI stays consistent.

if ( ! function_exists( 'nppp_redis_cache_sync_option_enabled' ) ) {
    /**
     * Filter callback for `nppp_sync_redis_cache_enabled`.
     *
     * @param bool   $enabled  Current enabled state.
     * @param string $context  'nppp_to_redis' | 'redis_to_nppp'
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
