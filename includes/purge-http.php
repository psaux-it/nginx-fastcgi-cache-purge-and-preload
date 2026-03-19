<?php
/**
 * HTTP Purge fast-path for Nginx Cache Purge Preload
 * Description: Optimistic HTTP fast-path using the Nginx ngx_cache_purge module.
 *              Attempts to purge cached URLs via HTTP before falling back to the
 *              filesystem workflow. Returns true only on HTTP 200 (confirmed purge).
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
 * Attempts to purge $url via HTTP. Returns true ONLY on HTTP 200.
 */
function nppp_http_purge_try_first( string $url ): bool {
    if ( ! nppp_http_purge_enabled() ) {
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

    if ( is_wp_error( $response ) ) {
        nppp_display_admin_notice(
            'error',
            sprintf(
                /* translators: %s: purge URL */
                __( 'ERROR HTTP PURGE: Connection to %s failed. Falling back to filesystem Purge. Check that the purge endpoint is reachable (DNS, firewall, proxy).', 'fastcgi-cache-purge-and-preload-nginx' ),
                esc_url( $purge_url )
            ),
            true,
            false
        );

        return false;
    }

    if ( (int) wp_remote_retrieve_response_code( $response ) === 200 ) {
        set_transient( 'nppp_cache_purge_module_' . md5( 'nppp' ), 1, HOUR_IN_SECONDS );
        return true;
    }

    return false;
}
