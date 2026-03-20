<?php
/**
 * HTTP Purge for Nginx Cache Purge Preload
 * Description: Optimistic HTTP fast-path using the Nginx ngx_cache_purge module.
 *              Attempts to purge cached URLs via HTTP before falling back to the
 *              filesystem workflow.
 * -------------------------------------------------------------------------------
 * Return values:
 * ------------------------------------------------------------------------------
 *   true   — HTTP 200: entry deleted from nginx shared memory + disk atomically.
 *   'miss' — HTTP 412 (ngx_cache_purge v2.5.x): nginx confirmed this URL is not
 *             in the cache. Filesystem scan skipped — nginx shared memory is the only
 *             authority; if shmem has no entry the disk file (if any) is stale and
 *             will never be served as a cache hit.
 *   false  — All other outcomes: fall through to Fast-Path 2 (index) or scan.
 *             HTTP 404 (ngx_cache_purge v2.3, ambiguous — could be cache miss OR
 *             config error) always returns false so filesystem provides the
 *             authoritative answer.
 *
 * -------------------------------------------------------------------------------
 * Transient 'nppp_http_purge_endpoint_broken':
 * -------------------------------------------------------------------------------
 *   403      — IP not in nginx whitelist (config error, 1 hour TTL)
 *   wp_error — endpoint unreachable (DNS/firewall/timeout, 15 min TTL)
 *   other    — wrong endpoint or upstream in the way (1 hour TTL)
 *   NOT set for 412 or 404 — those are valid module responses, not endpoint errors.
 * -------------------------------------------------------------------------------
 *
 * Version: 2.1.4
 * Author: Hasan CALISIR
 * Author Email: hasan.calisir@psauxit.com
 * Author URI: https://www.psauxit.com
 * License: GPL-2.0+
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
    $s = get_option( 'nginx_cache_settings', [] );

    if ( ! empty( $s['nppp_http_purge_custom_url'] ) ) {
        return untrailingslashit( $s['nppp_http_purge_custom_url'] );
    }

    $parse  = wp_parse_url( home_url() );
    $suffix = ( isset( $s['nppp_http_purge_suffix'] ) && $s['nppp_http_purge_suffix'] !== '' )
              ? $s['nppp_http_purge_suffix']
              : 'purge';
    $suffix = trim( $suffix, '/' );

    return $parse['scheme'] . '://' . $parse['host'] . '/' . $suffix;
}

/**
 * Attempts to purge $url via HTTP using the ngx_cache_purge module endpoint.
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
                /* translators: %s: purge URL */
                __( 'ERROR HTTP PURGE: Connection to %s failed. HTTP Purge disabled for 15 minutes. Falling back to filesystem. Check that the purge endpoint is reachable (DNS, firewall, proxy).', 'fastcgi-cache-purge-and-preload-nginx' ),
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
                /* translators: %s: purge URL */
                __( 'ERROR HTTP PURGE: Access denied (403) to %s. Your server IP is not in the "from" whitelist. HTTP Purge disabled for 1 hour. Check the "from" directive in your nginx purge location.', 'fastcgi-cache-purge-and-preload-nginx' ),
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

    // 301, 302, 401, 405, 502, 503
    set_transient( NPPP_HTTP_PURGE_BROKEN_KEY, (string) $code, HOUR_IN_SECONDS );
    nppp_display_admin_notice(
        'error',
        sprintf(
            /* translators: %1$d: HTTP status code, %2$s: purge URL */
            __( 'ERROR HTTP PURGE: Unexpected response %1$d from %2$s. This is not a valid purge response. HTTP Purge disabled for 1 hour. Verify your Purge URL Suffix or Custom Base URL settings.', 'fastcgi-cache-purge-and-preload-nginx' ),
            $code,
            esc_url( $purge_url )
        ),
        true,
        false
    );

    return false;
}
