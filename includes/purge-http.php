<?php
/**
 * HTTP Purge for Nginx Cache Purge Preload
 * Description: Optimistic HTTP fast-path using the Nginx ngx_cache_purge module.
 *              Supports both single URL purge and full cache purge (purge all)
 *              via dedicated nginx location blocks.
 *              Attempts to purge via HTTP before falling back to the
 *              filesystem workflow.
 * Version: 2.1.7
 * Author: Hasan CALISIR
 * Author Email: hasan.calisir@psauxit.com
 * Author URI: https://www.psauxit.com
 * License: GPL-2.0+
 *
 * Requirements:
 *   - https://github.com/nginx-modules/ngx_cache_purge
 *   - Purge Single: ngx_cache_purge module version 2.3+
 *     (recommended 2.5.6+ for reliable 412 responses).
 *   - Purge All:    ngx_cache_purge module version 3.0.2+
 *     (introduces the purge_all directive).
 *
 * -------------------------------------------------------------------------------
 * Single URL Purge (nppp_http_purge_try_first) Return values:
 * -------------------------------------------------------------------------------
 *   true   — HTTP 200: entry deleted from nginx shared memory + disk atomically.
 *   'miss' — HTTP 412 (ngx_cache_purge v2.5.x): nginx confirmed this URL is not
 *             in the cache. Filesystem scan skipped — nginx shared memory is the
 *             only authority; if shmem has no entry the disk file (if any) is
 *             stale and will never be served as a cache hit.
 *   false  — All other outcomes: fall through to Fast-Path 2 (index) or scan.
 *             HTTP 404 (ngx_cache_purge v2.3, ambiguous — could be cache miss OR
 *             config error) always returns false so filesystem provides the
 *             authoritative answer.
 *
 * -------------------------------------------------------------------------------
 * Purge All (nppp_http_purge_all) Return values:
 * -------------------------------------------------------------------------------
 *   true   — HTTP 200: nginx completed the full cache purge synchronously.
 *             SHM metadata and all cache files deleted atomically.
 *   false  — Any other outcome: fall back to filesystem-based full cache purge.
 *             HTTP 202 (background queue active) intentionally falls back because
 *             NPP's preload chain requires synchronous purge completion to avoid
 *             racing with a still-in-progress nginx deletion.
 *
 * -------------------------------------------------------------------------------
 * Transient 'nppp_http_purge_endpoint_broken' (both purge types):
 * -------------------------------------------------------------------------------
 *   Set when the purge endpoint is unreachable or misconfigured to avoid
 *   repeated failed HTTP requests. Once set, both single and purge all
 *   functions short-circuit and fall through to filesystem.
 *
 *   Specific triggers:
 *   403      — IP not in nginx whitelist (config error, 1 hour TTL)
 *   wp_error — endpoint unreachable (DNS/firewall/timeout, 15 min TTL)
 *   other    — wrong endpoint or upstream in the way (1 hour TTL)
 *
 *   NOT set for 412,202,404 — those are valid module responses, not errors.
 * -------------------------------------------------------------------------------
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Transient key used to short-circuit requests when the purge endpoint
 * is known to be broken or misconfigured.
 */
if ( ! defined( 'NPPP_HTTP_PURGE_BROKEN_KEY' ) ) {
    define( 'NPPP_HTTP_PURGE_BROKEN_KEY', 'nppp_http_purge_endpoint_broken' );
}

/**
 * Returns true when the HTTP Purge is enabled in settings.
 */
function nppp_http_purge_enabled(): bool {
    $s = get_option( 'nginx_cache_settings', [] );
    return isset( $s['nppp_http_purge_enabled'] ) && $s['nppp_http_purge_enabled'] === 'yes';
}

/**
 * Builds the purge base URL from settings.
 */
function nppp_http_purge_base_url(): string {
    $s      = get_option( 'nginx_cache_settings', [] );
    $suffix = ( isset( $s['nppp_http_purge_suffix'] ) && $s['nppp_http_purge_suffix'] !== '' )
              ? $s['nppp_http_purge_suffix']
              : 'purge';
    $suffix = trim( $suffix, '/' );

    if ( ! empty( $s['nppp_http_purge_custom_url'] ) ) {
        $parse = wp_parse_url( trim( $s['nppp_http_purge_custom_url'] ) );
        $base  = ( $parse['scheme'] ?? 'https' ) . '://' . ( $parse['host'] ?? '' );
        if ( ! empty( $parse['port'] ) ) {
            $base .= ':' . (int) $parse['port'];
        }
        return $base . '/' . $suffix;
    }

    $parse = wp_parse_url( home_url() );
    return ( $parse['scheme'] ?? 'https' ) . '://' . ( $parse['host'] ?? '' ) . '/' . $suffix;
}

/**
 * Attempts to purge SINGLE $url via HTTP using the ngx_cache_purge module.
 *
 * @param string $url The canonical page URL to purge.
 * @return true|false|'miss'
 *   true   — HTTP 200, cache purged.
 *   'miss' — HTTP 412, nginx confirmed URL not in cache (v2.5.x).
 *   false  — Any other outcome; should fall through to filesystem.
 */
function nppp_http_purge_try_first( string $url ) {
    if ( ! nppp_http_purge_enabled() ) {
        return false;
    }

    // Endpoint previously identified as broken/misconfigured.
    // Skip the HTTP Purge entirely until the transient expires.
    if ( get_transient( NPPP_HTTP_PURGE_BROKEN_KEY ) ) {
        return false;
    }

    $parse = wp_parse_url( $url );
    if ( empty( $parse['host'] ) ) {
        return false;
    }

    $path      = $parse['path'] ?? '/';
    $query     = ( isset( $parse['query'] ) && $parse['query'] !== '' )
                 ? '?' . $parse['query'] : '';

    $purge_url = nppp_http_purge_base_url() . $path . $query;
    $purge_url = (string) apply_filters( 'nppp_http_purge_url', $purge_url, $url );

    $response = wp_remote_get( $purge_url, [
        'timeout'     => 3,
        'sslverify'   => false,
        'redirection' => 0,
        'headers'     => [
            'Host' => (string) wp_parse_url( home_url(), PHP_URL_HOST ),
        ],
    ] );

    // Endpoint is unreachable (DNS, firewall, wrong host/port, timeout).
    // Set a 15-minute transient so subsequent purges in this window skip
    if ( is_wp_error( $response ) ) {
        set_transient( NPPP_HTTP_PURGE_BROKEN_KEY, 'wp_error', 15 * MINUTE_IN_SECONDS );
        nppp_display_admin_notice(
            'error',
            sprintf(
                /* translators: %s: purge single URL */
                __( 'ERROR HTTP PURGE SINGLE: Connection to %s failed. HTTP Purge disabled for 15 minutes. Falling back to filesystem. Check that the Purge Single endpoint is reachable (DNS, firewall, proxy).', 'fastcgi-cache-purge-and-preload-nginx' ),
                esc_url( $purge_url )
            ),
            true,
            false
        );

        return false;
    }

    $code = (int) wp_remote_retrieve_response_code( $response );

    // 200 — Cache purged
    // Shared memory node marked exists=0, disk file removed.
    if ( $code === 200 ) {
        return true;
    }

    // 412 — Cache miss (ngx_cache_purge v2.5.6+)
    // In both cases nginx shared memory has no record of this URL.
    // Filesystem scan be skipped — shmem is the authority.
    if ( $code === 412 ) {
        return 'miss';
    }

    // 404 — Ambiguous (ngx_cache_purge v2.3)
    // Fall through to filesystem so it can serve as the authoritative answer.
    if ( $code === 404 ) {
        return false;
    }

    // 403 — IP not in nginx purge whitelist
    // This indicate a nginx config error (missing "from" directive or wrong IP).
    if ( $code === 403 ) {
        set_transient( NPPP_HTTP_PURGE_BROKEN_KEY, '403', HOUR_IN_SECONDS );
        nppp_display_admin_notice(
            'error',
            sprintf(
                /* translators: %s: purge single URL */
                __( 'ERROR HTTP PURGE SINGLE: Access denied (403) to %s. Your server IP is not in the Nginx "from" whitelist. HTTP Purge disabled for 1 hour. Check the allow/deny directives in your Nginx Purge Single location block.', 'fastcgi-cache-purge-and-preload-nginx' ),
                esc_url( $purge_url )
            ),
            true,
            false
        );

        return false;
    }

    // 500 — Module internal error
    if ( $code === 500 ) {
        return false;
    }

    // Any other unexpected code — 1 hour backoff, log only.
    set_transient( NPPP_HTTP_PURGE_BROKEN_KEY, (string) $code, HOUR_IN_SECONDS );
    nppp_display_admin_notice(
        'error',
        sprintf(
            /* translators: %1$d: HTTP status code, %2$s: purge single URL */
            __( 'ERROR HTTP PURGE SINGLE: Unexpected response %1$d from %2$s. HTTP Purge disabled for 1 hour. Verify your Purge Single Path or Custom Base URL settings.', 'fastcgi-cache-purge-and-preload-nginx' ),
            $code,
            esc_url( $purge_url )
        ),
        true,
        false
    );

    return false;
}

/**
 * Sanitized URL path segment for the dedicated HTTP Purge All nginx endpoint
 * (e.g. "purge_all" in `location = /purge_all`). Independent of the
 * Single Purge Path — they hit two different nginx locations.
 */
function nppp_http_purge_all_path(): string {
    $s    = get_option( 'nginx_cache_settings', [] );
    $path = ( isset( $s['nppp_http_purge_all_path'] ) && $s['nppp_http_purge_all_path'] !== '' )
            ? $s['nppp_http_purge_all_path']
            : 'purge_all';

    return trim( $path, '/' );
}

/**
 * Builds the HTTP endpoint URL for the dedicated Purge All nginx block.
 * Reuses Custom Base URL for scheme+host(+port) when set.
 */
function nppp_http_purge_all_url(): string {
    $s    = get_option( 'nginx_cache_settings', [] );
    $path = nppp_http_purge_all_path();

    if ( ! empty( $s['nppp_http_purge_custom_url'] ) ) {
        $parse = wp_parse_url( trim( $s['nppp_http_purge_custom_url'] ) );
        $base  = ( $parse['scheme'] ?? 'https' ) . '://' . ( $parse['host'] ?? '' );
        if ( ! empty( $parse['port'] ) ) {
            $base .= ':' . (int) $parse['port'];
        }
        return $base . '/' . $path;
    }

    $parse = wp_parse_url( home_url() );
    return ( $parse['scheme'] ?? 'https' ) . '://' . ( $parse['host'] ?? '' ) . '/' . $path;
}

/**
 * Attempts a Purge All via HTTP using the dedicated Purge All endpoint.
 *
 *   200 — all cache files deleted, nginx SHM metadata updated atomically.
 *   202 — cache_purge_background_queue is "on"; nginx queued the walk for
 *         a later timer tick instead of finishing it now. Treated as NOT
 *         finished: NPP's Auto Preload chain fires immediately after a
 *         purge is reported complete, and warming a cache nginx is still
 *         deleting from is a race with no good outcome. No backoff is set
 *         for 202 — the endpoint is healthy, this is a deliberate fallback.
 *   403 — server IP not in the `from` whitelist (persistent config error).
 *   404 — the Purge All location isn't configured, or its path doesn't
 *         match Purge All Path. Silent fallback, no backoff.
 *
 * Failures are logged only; the admin-facing message comes from the
 * filesystem result in nppp_purge_helper().
 *
 * @return bool true on HTTP 200 only.
 */
function nppp_http_purge_all(): bool {
    if ( ! nppp_http_purge_enabled() ) {
        return false;
    }

    // Endpoint previously identified as broken/misconfigured — honour backoff.
    if ( get_transient( NPPP_HTTP_PURGE_BROKEN_KEY ) ) {
        return false;
    }

    $purge_url = (string) apply_filters( 'nppp_http_purge_all_url', nppp_http_purge_all_url() );

    $response = wp_remote_get( $purge_url, [
        'timeout'     => 5,
        'sslverify'   => false,
        'redirection' => 0,
        'headers'     => [
            'Host' => (string) wp_parse_url( home_url(), PHP_URL_HOST ),
        ],
    ] );

    // Unreachable endpoint — 15-minute backoff, log only (no admin notice).
    if ( is_wp_error( $response ) ) {
        set_transient( NPPP_HTTP_PURGE_BROKEN_KEY, 'wp_error', 15 * MINUTE_IN_SECONDS );
        nppp_display_admin_notice(
            'error',
            sprintf(
                /* translators: %s: Purge All endpoint URL */
                __( 'ERROR HTTP PURGE ALL: Connection to %s failed. HTTP Purge disabled for 15 minutes. Falling back to filesystem. Check that the Purge All endpoint is reachable (DNS, firewall, proxy).', 'fastcgi-cache-purge-and-preload-nginx' ),
                esc_url( $purge_url )
            ),
            true,
            false
        );
        return false;
    }

    $code = (int) wp_remote_retrieve_response_code( $response );

    // 200 — all cache purged synchronously (SHM + disk atomically).
    if ( $code === 200 ) {
        return true;
    }

    // 202 — nginx accepted the purge_all into its background queue
    // (cache_purge_background_queue on). The actual directory walk happens
    // asynchronously, one tick of throttle_ms (default 10 ms) later, and
    // may take seconds for a large cache. NPP's preload chain requires
    // synchronous purge completion before any preload request fires, so
    // returning true here would cause preload to warm a cache that is still
    // being deleted in a background nginx worker — a guaranteed race.
    // Fall back to filesystem. NPP does not support background purge queues.
    // Do NOT set NPPP_HTTP_PURGE_BROKEN_KEY — the endpoint is healthy.
    if ( $code === 202 ) {
        nppp_display_admin_notice(
            'info',
            sprintf(
                /* translators: %s: Purge All endpoint URL */
                __( 'INFO HTTP PURGE ALL: %s returned 202 (nginx background purge queue is active). NPP does not support background purge queues; falling back to filesystem purge.', 'fastcgi-cache-purge-and-preload-nginx' ),
                esc_url( $purge_url )
            ),
            true,
            false
        );
        return false;
    }

    // 403 — server IP not in `from` whitelist; persistent config error — 1 hour backoff.
    if ( $code === 403 ) {
        set_transient( NPPP_HTTP_PURGE_BROKEN_KEY, '403', HOUR_IN_SECONDS );
        nppp_display_admin_notice(
            'error',
            sprintf(
                /* translators: %s: Purge All endpoint URL */
                __( 'ERROR HTTP PURGE ALL: Access denied (403) to %s. Your server IP is not in the Nginx "from" whitelist. HTTP Purge disabled for 1 hour. Check the allow/deny directives in your Nginx Purge All location block.', 'fastcgi-cache-purge-and-preload-nginx' ),
                esc_url( $purge_url )
            ),
            true,
            false
        );
        return false;
    }

    // 500 — Module internal error
    if ( $code === 500 ) {
        return false;
    }

    // 404 — `location = /purge_all` not configured in nginx. Silent fallback;
    // not a persistent error (admin may not have added the block yet).
    if ( $code === 404 ) {
        return false;
    }

    // Any other unexpected code — 1 hour backoff, log only.
    set_transient( NPPP_HTTP_PURGE_BROKEN_KEY, (string) $code, HOUR_IN_SECONDS );
    nppp_display_admin_notice(
        'error',
        sprintf(
            /* translators: %1$d: HTTP status code, %2$s: Purge All endpoint URL */
            __( 'ERROR HTTP PURGE ALL: Unexpected response %1$d from %2$s. HTTP Purge disabled for 1 hour. Verify your Purge All Path or Custom Base URL settings.', 'fastcgi-cache-purge-and-preload-nginx' ),
            $code,
            esc_url( $purge_url )
        ),
        true,
        false
    );

    return false;
}
