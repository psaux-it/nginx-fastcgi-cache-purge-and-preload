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

// Transient key — shared across all functions in this file.
define( 'NPPP_HTTP_PURGE_DETECT_KEY', 'nppp_http_purge_module_ok' );

// ---------------------------------------------------------------------------
// 1.  SETTINGS HELPERS
// ---------------------------------------------------------------------------

/**
 * Returns true when the HTTP purge fast-path is enabled in NPP settings.
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

// ---------------------------------------------------------------------------
// 2.  MODULE DETECTION
// ---------------------------------------------------------------------------

/**
 * Probes whether the ngx_cache_purge module endpoint is reachable and active.
 *
 * Sends one GET request to <base>/nppp-probe-<random> — a path that can never
 * be in cache.
 */
function nppp_http_purge_detect( bool $force_fresh = false ): bool {
    if ( ! $force_fresh ) {
        $cached = get_transient( NPPP_HTTP_PURGE_DETECT_KEY );
        if ( $cached !== false ) {
            return (bool) $cached;
        }
    }

    // Random suffix ensures this path is never in cache and is not guessable.
    $probe_url = nppp_http_purge_base_url()
                 . '/nppp-probe-' . substr( md5( uniqid( '', true ) ), 0, 12 );

    $response = wp_remote_get( $probe_url, [
        'timeout'     => 3,
        'sslverify'   => false,
        'redirection' => 0,
    ] );

    // Connection-level failure
    if ( is_wp_error( $response ) ) {
        set_transient( NPPP_HTTP_PURGE_DETECT_KEY, 0, 12 * HOUR_IN_SECONDS );
        return false;
    }

    $code = (int) wp_remote_retrieve_response_code( $response );
    $body = (string) wp_remote_retrieve_body( $response );

    // 412 with small body is the ONLY real proof-of-life signal.
    // The probe URL is a guaranteed cache miss — the module will always
    // return 412 (not found in shared memory) and never 200 for this path.
    // Small body (≤ 512 B) = Nginx's own error page → module answered.
    // Large body           = intermediate proxy intercepted → unavailable.
    // Every other code (200 defensive, 3xx, 403, 404, 5xx) means the module
    // is not handling the request — treat as unavailable.
    $active = ( $code === 412 && strlen( $body ) <= 512 );

    set_transient( NPPP_HTTP_PURGE_DETECT_KEY, (int) $active, 12 * HOUR_IN_SECONDS );
    return $active;
}

// ---------------------------------------------------------------------------
// 3.  CORE FAST-PATH FUNCTION
// ---------------------------------------------------------------------------

/**
 * Attempts to purge $url via HTTP.  Returns true ONLY on HTTP 200.
 *
 *   true  → HTTP 200 confirmed.  Cache entry is gone.  Skip filesystem work.
 *   false → Any other outcome.  Fall through to NPP's filesystem workflow.
 *           3xx / 403 / 404 also invalidate the detection transient so the
 *           next purge re-probes immediately rather than waiting 12 hours.
 *           412 and 5xx fall through silently without touching the transient.
 */
function nppp_http_purge_try_first( string $url, bool $silent = false ): bool {
    // Bail 1: feature disabled
    if ( ! nppp_http_purge_enabled() ) {
        return false;
    }

    // Bail 2 + 3: module detection
    if ( ! nppp_http_purge_detect() ) {
        return false;
    }

    // Build the purge endpoint URL
    $parse = wp_parse_url( $url );
    if ( empty( $parse['host'] ) ) {
        return false;
    }

    $path      = $parse['path'] ?? '/';
    $query     = ( isset( $parse['query'] ) && $parse['query'] !== '' )
                 ? '?' . $parse['query'] : '';
    $purge_url = nppp_http_purge_base_url() . $path . $query;

    /**
     * Filters the assembled purge endpoint URL before the request fires.
     * Use this to adjust the URL for non-standard Nginx setups.
     */
    $purge_url = (string) apply_filters( 'nppp_http_purge_url', $purge_url, $url );

    $response = wp_remote_get( $purge_url, [
        'timeout'   => 3,
        'sslverify' => false,
        'redirection' => 0,
    ] );

    // Connection failure — invalidate detection so next purge re-probes
    // instead of continuing to fire dead requests for 12 hours.
    if ( is_wp_error( $response ) ) {
        delete_transient( NPPP_HTTP_PURGE_DETECT_KEY );

        if ( ! $silent ) {
            nppp_display_admin_notice( 'error',
                sprintf(
                    /* translators: %1$s: purge URL, %2$s: error detail */
                    __( 'ERROR HTTP PURGE: Request to %1$s failed — %2$s. Falling back to filesystem purge. Check your Nginx purge location block or disable HTTP purge in settings.', 'fastcgi-cache-purge-and-preload-nginx' ),
                    esc_url( $purge_url ),
                    esc_html( implode( ' | ', $response->get_error_messages() ) )
                ),
                true,
                false
            );
        }
        return false;
    }

    $code = (int) wp_remote_retrieve_response_code( $response );

    // Invalidate transient so next purge re-probes immediately.
    if ( ( $code >= 300 && $code < 400 ) || $code === 403 || $code === 404 ) {
        delete_transient( NPPP_HTTP_PURGE_DETECT_KEY );
        return false;
    }

    if ( $code === 200 ) {
        if ( ! $silent ) {
            nppp_display_admin_notice( 'success',
                sprintf(
                    /* translators: %s: page URL */
                    __( 'SUCCESS HTTP PURGE: Nginx module purged %s — filesystem scan skipped.', 'fastcgi-cache-purge-and-preload-nginx' ),
                    esc_url( $url )
                ),
                true,
                false
            );
        }
        return true;
    }

    return false;
}

// ---------------------------------------------------------------------------
// 4.  AJAX HANDLER — "Test Connection" button
// ---------------------------------------------------------------------------

/**
 * AJAX callback for the Test Connection button in Settings.
 * Runs a fresh detection probe and returns the result as JSON.
 */
function nppp_ajax_test_http_purge(): void {
    check_ajax_referer( 'nppp_test_http_purge_nonce', '_wpnonce' );

    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( [
            'message' => __( 'Permission denied.', 'fastcgi-cache-purge-and-preload-nginx' ),
        ] );
    }

    $ok = nppp_http_purge_detect( true );

    if ( $ok ) {
        wp_send_json_success( [
            'message' => __( 'Module detected. Nginx ngx_cache_purge endpoint is active — HTTP fast-path enabled.', 'fastcgi-cache-purge-and-preload-nginx' ),
        ] );
    } else {
        wp_send_json_error( [
            'message' => __( 'Module not detected. Check ngx_cache_purge is compiled, purge location block exists. Filesystem purge only.', 'fastcgi-cache-purge-and-preload-nginx' ),
        ] );
    }
}
