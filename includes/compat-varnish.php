<?php
/**
 * Varnish / Vinyl Cache compatibility for Nginx Cache Purge Preload
 * Description: Mirrors plugin purge actions to a Varnish / Vinyl Cache placed IN FRONT of the Nginx cache, so both layers stay synchronized.
 * Version: 2.1.8
 * Author: Hasan CALISIR
 * License: GPL-2.0+
 *
 * Topology (front-only):
 *
 *   visitor -> nginx:443 (TLS) -> Varnish / Vinyl :6081 -> nginx:8080 (fastcgi_cache) -> PHP-FPM
 *
 * Order rule: inner layer first, outer layer second. The hooks used here fire AFTER Nginx is
 * clean ("origin purged"), and BEFORE any preload crawl starts (the crawl requests the public
 * URL and therefore passes through Varnish):
 *
 *   nppp_purged_urls         single purge, after nginx, before auto-preload
 *   nppp_purged_all          Purge All, after nginx (deferred while nginx's queue is busy)
 *   nppp_preload_all_started direct Preload All, right before the crawl starts
 *
 * Wire contract (Proxy Cache Purge compatible, plain HTTP, no admin/6082 port):
 *
 *   single URL : PURGE <endpoint><path[?query]>   Host: <site authority>   X-Purge-Method: default
 *   everything : PURGE <endpoint>/.*              Host: <site authority>   X-Purge-Method: regex
 *
 * The purge-all body is a FIXED string: nothing user- or URL-derived is ever placed in a regex.
 *
 * Design rules:
 *  - Side channel. A Varnish failure never changes the purge result NPP shows; it only logs.
 *  - Synchronous, short timeouts, per-endpoint circuit breaker (no shutdown queue: the purge
 *    must be finished before the preload crawl starts).
 *  - The log never contains endpoint addresses or the control header value.
 *
 * @package Nginx Cache Purge Preload
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/* ============================================================================
 * 1. Settings access and parsing (pure helpers, shared with the sanitize layer)
 * ========================================================================= */

if ( ! function_exists( 'nppp_varnish_option' ) ) {
    /**
     * Raw string value of one key of the nginx_cache_settings array ('' when unset).
     */
    function nppp_varnish_option( string $key ): string {
        $options = get_option( 'nginx_cache_settings', array() );

        return ( is_array( $options ) && isset( $options[ $key ] ) && is_string( $options[ $key ] ) )
            ? $options[ $key ]
            : '';
    }
}

if ( ! function_exists( 'nppp_varnish_max_endpoints' ) ) {
    function nppp_varnish_max_endpoints(): int {
        return 5;
    }
}

if ( ! function_exists( 'nppp_varnish_parse_endpoints' ) ) {
    /**
     * Parses an endpoint list (newline / comma / space separated) into normalized
     * "scheme://host[:port]" strings.
     *
     * Rules: http/https only (a missing scheme becomes http://), host required, no user info,
     * no path / query / fragment, valid port. Loopback and private addresses ARE allowed
     * (Varnish normally listens on localhost). Duplicates are dropped; at most 5 endpoints.
     *
     * Valid tokens are always returned. Every problem appends a machine code to $errors, so a
     * strict caller (sanitize) can reject, while the runtime reader can simply use what is valid.
     *
     * @param string   $raw    Raw field value.
     * @param string[] $errors Filled with error codes (by reference).
     * @return string[]
     */
    function nppp_varnish_parse_endpoints( string $raw, array &$errors = array() ): array {
        $out = array();
        $raw = trim( $raw );
        if ( $raw === '' ) {
            return $out;
        }

        $tokens = preg_split( '/[\s,;]+/', $raw, -1, PREG_SPLIT_NO_EMPTY );
        if ( ! is_array( $tokens ) ) {
            $errors[] = 'invalid';
            return $out;
        }

        foreach ( $tokens as $token ) {
            $token = (string) $token;

            if ( strlen( $token ) > 255 || preg_match( '/[\x00-\x1F\x7F]/', $token ) ) {
                $errors[] = 'invalid';
                continue;
            }

            if ( ! preg_match( '#^[a-z][a-z0-9+.\-]*://#i', $token ) ) {
                $token = 'http://' . $token;
            }

            $p = wp_parse_url( $token );
            if ( ! is_array( $p ) || empty( $p['host'] ) ) {
                $errors[] = 'invalid';
                continue;
            }

            $scheme = strtolower( (string) ( $p['scheme'] ?? '' ) );
            if ( $scheme !== 'http' && $scheme !== 'https' ) {
                $errors[] = 'scheme';
                continue;
            }

            if ( array_key_exists( 'user', $p ) || array_key_exists( 'pass', $p ) ) {
                $errors[] = 'userinfo';
                continue;
            }

            $path = (string) ( $p['path'] ?? '' );
            if ( array_key_exists( 'query', $p ) || array_key_exists( 'fragment', $p ) || ( $path !== '' && $path !== '/' ) ) {
                $errors[] = 'path';
                continue;
            }

            $host = strtolower( (string) $p['host'] );

            // Non-ASCII host: convert to punycode, or reject.
            if ( preg_match( '/[^\x00-\x7F]/', $host ) ) {
                $ascii = null;
                if ( function_exists( 'nppp_idn_to_ascii_host' ) ) {
                    $ascii = nppp_idn_to_ascii_host( $host );
                } elseif ( function_exists( 'idn_to_ascii' ) ) {
                    $conv  = defined( 'INTL_IDNA_VARIANT_UTS46' )
                        ? idn_to_ascii( $host, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46 )
                        : idn_to_ascii( $host, IDNA_DEFAULT );
                    $ascii = is_string( $conv ) ? $conv : null;
                }
                if ( ! is_string( $ascii ) || $ascii === '' ) {
                    $errors[] = 'host';
                    continue;
                }
                $host = strtolower( $ascii );
            }

            if ( $host !== '' && $host[0] === '[' ) {
                // IPv6 literal.
                $inner = trim( $host, '[]' );
                if ( ! filter_var( $inner, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 ) ) {
                    $errors[] = 'host';
                    continue;
                }
                $host = '[' . $inner . ']';
            } elseif ( ! filter_var( $host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 )
                && ! preg_match( '/^(?=.{1,253}$)[a-z0-9_](?:[a-z0-9_-]{0,61}[a-z0-9_])?(?:\.[a-z0-9_](?:[a-z0-9_-]{0,61}[a-z0-9_])?)*$/', $host ) ) {
                $errors[] = 'host';
                continue;
            }

            $port = isset( $p['port'] ) ? (int) $p['port'] : 0;
            if ( isset( $p['port'] ) && ( $port < 1 || $port > 65535 ) ) {
                $errors[] = 'port';
                continue;
            }

            $endpoint = $scheme . '://' . $host . ( $port > 0 ? ':' . $port : '' );
            if ( ! in_array( $endpoint, $out, true ) ) {
                $out[] = $endpoint;
            }
        }

        if ( count( $out ) > nppp_varnish_max_endpoints() ) {
            $errors[] = 'too_many';
            $out      = array_slice( $out, 0, nppp_varnish_max_endpoints() );
        }

        return $out;
    }
}

if ( ! function_exists( 'nppp_varnish_parse_error_label' ) ) {
    /**
     * Human readable reason for a code produced by the parsers (for the settings error).
     */
    function nppp_varnish_parse_error_label( string $code ): string {
        switch ( $code ) {
            case 'scheme':
                return __( 'only http:// and https:// are allowed', 'fastcgi-cache-purge-and-preload-nginx' );
            case 'userinfo':
                return __( 'user name / password in the address is not allowed', 'fastcgi-cache-purge-and-preload-nginx' );
            case 'path':
                return __( 'use scheme and host only, without a path, query or fragment', 'fastcgi-cache-purge-and-preload-nginx' );
            case 'host':
                return __( 'invalid host name or IP address', 'fastcgi-cache-purge-and-preload-nginx' );
            case 'port':
                return __( 'invalid port', 'fastcgi-cache-purge-and-preload-nginx' );
            case 'too_many':
                return sprintf(
                    /* translators: %d: maximum number of endpoints */
                    __( 'at most %d endpoints are allowed', 'fastcgi-cache-purge-and-preload-nginx' ),
                    nppp_varnish_max_endpoints()
                );
            case 'crlf':
                return __( 'line breaks are not allowed', 'fastcgi-cache-purge-and-preload-nginx' );
            case 'name':
                return __( 'header name may contain only letters, numbers and hyphens (max 64)', 'fastcgi-cache-purge-and-preload-nginx' );
            case 'forbidden':
                return __( 'this header name is reserved and cannot be used', 'fastcgi-cache-purge-and-preload-nginx' );
            case 'value':
                return __( 'header value must be 1-512 printable ASCII characters', 'fastcgi-cache-purge-and-preload-nginx' );
            case 'format':
                return __( 'use the format "Header-Name: value"', 'fastcgi-cache-purge-and-preload-nginx' );
            default:
                return __( 'invalid value', 'fastcgi-cache-purge-and-preload-nginx' );
        }
    }
}

if ( ! function_exists( 'nppp_varnish_parse_header' ) ) {
    /**
     * Parses the optional control header ("Name: value", e.g. the secret a VCL checks).
     *
     * Header injection is prevented here: any CR / LF / control character is rejected, the
     * name is restricted to token characters and hop-by-hop / framing / contract headers are
     * refused. The value is never logged.
     *
     * @param string $raw   Raw field value.
     * @param string $error Error code on failure ('' otherwise; by reference).
     * @return array{name:string,value:string}|array{} Empty array = no (valid) header.
     */
    function nppp_varnish_parse_header( string $raw, string &$error = '' ): array {
        $error = '';

        if ( preg_match( '/[\r\n\x00]/', $raw ) ) {
            $error = 'crlf';
            return array();
        }

        $raw = trim( $raw );
        if ( $raw === '' ) {
            return array();
        }

        $pos = strpos( $raw, ':' );
        if ( $pos === false ) {
            $error = 'format';
            return array();
        }

        $name  = trim( substr( $raw, 0, $pos ) );
        $value = trim( substr( $raw, $pos + 1 ) );

        if ( ! preg_match( '/^[A-Za-z0-9-]{1,64}$/', $name ) ) {
            $error = 'name';
            return array();
        }

        $forbidden = array(
            'host', 'content-length', 'transfer-encoding', 'connection', 'keep-alive',
            'te', 'trailer', 'upgrade', 'proxy-authorization', 'x-purge-method',
        );
        if ( in_array( strtolower( $name ), $forbidden, true ) ) {
            $error = 'forbidden';
            return array();
        }

        if ( $value === '' || strlen( $value ) > 512 || ! preg_match( '/^[\x20-\x7E\t]+$/', $value ) ) {
            $error = 'value';
            return array();
        }

        return array( 'name' => $name, 'value' => $value );
    }
}

/* ============================================================================
 * 2. Runtime configuration
 * ========================================================================= */

if ( ! function_exists( 'nppp_varnish_sync_on' ) ) {
    /**
     * State of the Settings switch (Varnish / Vinyl Cache Sync ON / OFF).
     * The endpoint list and the control header stay stored while it is OFF.
     */
    function nppp_varnish_sync_on(): bool {
        return nppp_varnish_option( 'nppp_varnish_sync_enabled' ) === 'yes';
    }
}

if ( ! function_exists( 'nppp_varnish_endpoints' ) ) {
    /**
     * Configured endpoints (normalized). An empty array means the feature is OFF.
     *
     * The addresses saved in Settings count only while the switch is ON; with the switch OFF the
     * list handed to the filter is empty (the stored addresses are kept for the next time).
     */
    function nppp_varnish_endpoints(): array {
        $errors = array();
        $raw    = nppp_varnish_option( 'nppp_varnish_endpoints' );
        $list   = ( nppp_varnish_sync_on() && $raw !== '' ) ? nppp_varnish_parse_endpoints( $raw, $errors ) : array();

        /**
         * Filters the Varnish / Vinyl Cache endpoints ("scheme://host[:port]").
         * The result is validated again, so a filter can not inject an unsafe address.
         *
         * @param string[] $list Endpoints from the settings.
         */
        $filtered = apply_filters( 'nppp_varnish_endpoints', $list );
        if ( ! is_array( $filtered ) ) {
            return array();
        }

        $strings = array();
        foreach ( $filtered as $item ) {
            if ( is_string( $item ) ) {
                $strings[] = $item;
            }
        }

        $errors = array();
        return nppp_varnish_parse_endpoints( implode( ' ', $strings ), $errors );
    }
}

if ( ! function_exists( 'nppp_varnish_extra_header' ) ) {
    /**
     * The optional control header, re-validated at send time. [] when none / invalid.
     */
    function nppp_varnish_extra_header(): array {
        $raw = nppp_varnish_option( 'nppp_varnish_header' );
        if ( $raw === '' ) {
            return array();
        }

        $error = '';
        return nppp_varnish_parse_header( $raw, $error );
    }
}

if ( ! function_exists( 'nppp_varnish_enabled' ) ) {
    /**
     * True when the switch is ON, at least one endpoint is configured and no filter vetoes the sync.
     *
     * @param string $context 'purge_urls' | 'purge_all' | 'preload_all'.
     */
    function nppp_varnish_enabled( string $context ): bool {
        if ( empty( nppp_varnish_endpoints() ) ) {
            return false;
        }

        /**
         * Filters whether the Varnish / Vinyl Cache sync runs for this event.
         *
         * @param bool   $enabled Default true when an endpoint is configured.
         * @param string $context purge_urls | purge_all | preload_all
         */
        return (bool) apply_filters( 'nppp_sync_varnish_enabled', true, $context );
    }
}

if ( ! function_exists( 'nppp_varnish_authority' ) ) {
    /**
     * host[:port] the way a browser sends it in the Host header (default port dropped).
     * Uses NPP's own helper when present so port/scheme rules stay identical.
     */
    function nppp_varnish_authority( string $url ): string {
        if ( function_exists( 'nppp_http_purge_authority' ) ) {
            return strtolower( nppp_http_purge_authority( $url ) );
        }

        $p    = wp_parse_url( $url );
        $host = strtolower( (string) ( is_array( $p ) ? ( $p['host'] ?? '' ) : '' ) );
        if ( $host === '' ) {
            return '';
        }

        $port    = isset( $p['port'] ) ? (int) $p['port'] : 0;
        $default = strtolower( (string) ( $p['scheme'] ?? '' ) ) === 'https' ? 443 : 80;

        return ( $port > 0 && $port !== $default ) ? $host . ':' . $port : $host;
    }
}

if ( ! function_exists( 'nppp_varnish_host_header' ) ) {
    /**
     * Host header sent with every PURGE: the site's authority, i.e. what Varnish sees from nginx.
     * Filterable for setups where nginx forwards a different Host (proxy_set_header Host ...).
     */
    function nppp_varnish_host_header(): string {
        $default = nppp_varnish_authority( home_url() );

        /**
         * Filters the Host header of the Varnish / Vinyl Cache PURGE requests.
         *
         * @param string $default host[:port] of home_url().
         */
        $host = apply_filters( 'nppp_varnish_host_header', $default );

        return ( is_string( $host ) && preg_match( '/^[A-Za-z0-9._\-:\[\]]{1,255}$/', $host ) ) ? $host : $default;
    }
}

/* ============================================================================
 * 3. Logging, warning throttle, circuit breaker
 * ========================================================================= */

if ( ! function_exists( 'nppp_varnish_log' ) ) {
    /**
     * Log-only notice (never shown in the admin UI, never affects the purge result).
     */
    function nppp_varnish_log( string $message, string $type = 'info' ): void {
        if ( function_exists( 'nppp_display_admin_notice' ) ) {
            nppp_display_admin_notice( $type, $message, true, false );
        }
    }
}

if ( ! function_exists( 'nppp_varnish_endpoint_label' ) ) {
    /**
     * Log-safe endpoint label. The address itself is never logged (it is an internal address
     * and the log file can be web-reachable).
     */
    function nppp_varnish_endpoint_label( int $index ): string {
        return 'Varnish / Vinyl Cache endpoint #' . ( $index + 1 );
    }
}

if ( ! function_exists( 'nppp_varnish_endpoint_id' ) ) {
    function nppp_varnish_endpoint_id( string $endpoint ): string {
        return substr( md5( strtolower( $endpoint ) ), 0, 12 );
    }
}

if ( ! function_exists( 'nppp_varnish_state_map' ) ) {
    /**
     * Reads a "id => unix deadline" map from the option-backed state store and drops expired rows.
     * (nppp_state_* survives wp_cache_flush(), which NPP's own Redis sync runs on every Purge
     * All; a transient would be erased by it.)
     */
    function nppp_varnish_state_map( string $key ): array {
        $map = function_exists( 'nppp_state_get' ) ? nppp_state_get( $key ) : null;
        if ( ! is_array( $map ) ) {
            return array();
        }

        $now = time();
        $out = array();
        foreach ( $map as $id => $until ) {
            if ( is_string( $id ) && is_int( $until ) && $until > $now ) {
                $out[ $id ] = $until;
            }
        }

        return $out;
    }
}

if ( ! function_exists( 'nppp_varnish_state_map_set' ) ) {
    function nppp_varnish_state_map_set( string $key, string $id, int $seconds ): void {
        if ( ! function_exists( 'nppp_state_set' ) ) {
            return;
        }

        $map        = nppp_varnish_state_map( $key );
        $map[ $id ] = time() + $seconds;
        $ttl        = max( $map ) - time();

        nppp_state_set( $key, $map, max( 1, $ttl ) );
    }
}

if ( ! function_exists( 'nppp_varnish_local_breaker' ) ) {
    /**
     * Per-request memo of tripped endpoints. It makes the breaker work inside one request even
     * when the state store is unavailable.
     *
     * @param string|null $arm_id Endpoint id to mark as tripped, or null to only read.
     */
    function nppp_varnish_local_breaker( ?string $arm_id = null ): array {
        static $tripped = array();

        if ( $arm_id !== null ) {
            $tripped[ $arm_id ] = true;
        }

        return $tripped;
    }
}

if ( ! function_exists( 'nppp_varnish_breaker_open' ) ) {
    /**
     * True while the endpoint is parked after a connection failure.
     */
    function nppp_varnish_breaker_open( string $endpoint ): bool {
        $id = nppp_varnish_endpoint_id( $endpoint );

        $local = nppp_varnish_local_breaker();
        if ( isset( $local[ $id ] ) ) {
            return true;
        }

        $map = nppp_varnish_state_map( 'varnish_breaker' );

        return isset( $map[ $id ] );
    }
}

if ( ! function_exists( 'nppp_varnish_breaker_arm' ) ) {
    /**
     * Parks an endpoint for 5 minutes.
     */
    function nppp_varnish_breaker_arm( string $endpoint ): void {
        $id = nppp_varnish_endpoint_id( $endpoint );

        nppp_varnish_local_breaker( $id );
        nppp_varnish_state_map_set( 'varnish_breaker', $id, 300 );
    }
}

if ( ! function_exists( 'nppp_varnish_warn_due' ) ) {
    /**
     * Throttle: true at most once per $seconds for the same endpoint + problem kind.
     */
    function nppp_varnish_warn_due( string $endpoint, string $kind, int $seconds = 300 ): bool {
        $id  = nppp_varnish_endpoint_id( $endpoint ) . '-' . substr( md5( $kind ), 0, 6 );
        $map = nppp_varnish_state_map( 'varnish_warn' );

        if ( isset( $map[ $id ] ) ) {
            return false;
        }

        nppp_varnish_state_map_set( 'varnish_warn', $id, $seconds );

        return true;
    }
}

/* ============================================================================
 * 4. Transport
 * ========================================================================= */

if ( ! function_exists( 'nppp_varnish_curl_connect_timeout' ) ) {
    /**
     * http_api_curl callback: short connect timeout for OUR requests only (WordPress otherwise
     * sets CONNECTTIMEOUT to the overall timeout). Keyed on our own argument, so NPP's own
     * nppp_http_purge_connect_ms requests are never touched.
     *
     * @param resource|object $handle
     * @param array           $args
     */
    function nppp_varnish_curl_connect_timeout( $handle, $args = array() ): void {
        if ( ! is_array( $args ) || empty( $args['nppp_varnish_connect_ms'] ) ) {
            return;
        }
        if ( ! is_resource( $handle ) && ! is_object( $handle ) ) {
            return;
        }
        if ( defined( 'CURLOPT_CONNECTTIMEOUT_MS' ) ) {
            @curl_setopt( $handle, CURLOPT_CONNECTTIMEOUT_MS, (int) $args['nppp_varnish_connect_ms'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_setopt
        }
    }
}

if ( ! function_exists( 'nppp_varnish_log_reason' ) ) {
    /**
     * Coarse, log-safe reason for a transport failure (raw cURL text can hold internal hosts / IPs).
     */
    function nppp_varnish_log_reason( string $message ): string {
        if ( function_exists( 'nppp_http_purge_log_reason' ) ) {
            return nppp_http_purge_log_reason( $message );
        }

        $m = strtolower( $message );
        if ( strpos( $m, 'refused' ) !== false ) {
            return 'connection refused';
        }
        if ( preg_match( '/resolv|name or service|getaddrinfo|dns/', $m ) ) {
            return 'DNS failure';
        }
        if ( preg_match( '/ssl|tls|certificate/', $m ) ) {
            return 'TLS failure';
        }
        if ( strpos( $m, 'timed out' ) !== false || strpos( $m, 'timeout' ) !== false ) {
            return 'connection timed out';
        }

        return 'transport error';
    }
}

if ( ! function_exists( 'nppp_varnish_send' ) ) {
    /**
     * Sends ONE PURGE request. Never throws.
     *
     * @param string $endpoint     scheme://host[:port]
     * @param string $path_query   Request target, e.g. "/hello/" or "/.*".
     * @param string $purge_method X-Purge-Method value: 'default' | 'regex'.
     * @param array  $extra        Optional control header (name/value), already validated.
     * @param float  $timeout      Overall timeout in seconds.
     * @return array{kind:string,status:int,reason:string}
     *         kind: ok | absent | denied | fail | unreachable
     */
    function nppp_varnish_send( string $endpoint, string $path_query, string $purge_method, array $extra, float $timeout ): array {
        $out = array( 'kind' => 'fail', 'status' => 0, 'reason' => '' );

        $headers = array(
            'Host'           => nppp_varnish_host_header(),
            'X-Purge-Method' => $purge_method,
            // Vinyl Cache 9.x expects an explicit length on a body-less PURGE.
            'Content-Length' => '0',
        );
        if ( ! empty( $extra['name'] ) && isset( $extra['value'] ) ) {
            $headers[ $extra['name'] ] = $extra['value'];
        }

        add_action( 'http_api_curl', 'nppp_varnish_curl_connect_timeout', 10, 3 );
        try {
            $response = wp_remote_request( $endpoint . $path_query, array(
                'method'                  => 'PURGE',
                'timeout'                 => $timeout,
                'redirection'             => 0,
                'sslverify'               => false,
                'blocking'                => true,
                'limit_response_size'     => 4096,
                'nppp_varnish_connect_ms' => 1000,
                'headers'                 => $headers,
            ) );
        } catch ( \Throwable $e ) {
            // A misbehaving http_api_* filter or transport must not abort the caller.
            $response = new WP_Error( 'nppp_varnish_exception', get_class( $e ) );
        } finally {
            remove_action( 'http_api_curl', 'nppp_varnish_curl_connect_timeout', 10 );
        }

        if ( is_wp_error( $response ) ) {
            $out['kind']   = 'unreachable';
            $out['reason'] = nppp_varnish_log_reason( implode( ' | ', (array) $response->get_error_messages() ) );
            return $out;
        }

        $status        = (int) wp_remote_retrieve_response_code( $response );
        $out['status'] = $status;

        if ( $status >= 200 && $status < 300 ) {
            $out['kind'] = 'ok';
        } elseif ( $status === 404 ) {
            // "Not in cache" (depends on the VCL): harmless.
            $out['kind'] = 'absent';
        } elseif ( in_array( $status, array( 401, 403, 405, 501 ), true ) ) {
            // ACL / control key / no PURGE handler in vcl_recv (Varnish 8+ answers 501 to unknown methods).
            $out['kind'] = 'denied';
        }

        return $out;
    }
}

/* ============================================================================
 * 5. URL preparation
 * ========================================================================= */

if ( ! function_exists( 'nppp_varnish_path_ambiguity_reason' ) ) {
    /**
     * Why a single PURGE for this path/query could silently miss the cached object ('' = it is safe).
     *
     * Varnish hashes the RAW request URL. WordPress' HTTP API (Requests) rewrites the request
     * target on the wire: percent escapes are re-encoded in UPPER-CASE hex and unreserved
     * escapes are decoded, while permalinks use lower-case hex. A single PURGE for such a URL
     * can therefore be a silent no-op, and unlike NPP's Nginx path there is no filesystem
     * fallback here. Mirrors nppp_http_purge_key_ambiguity_reason().
     */
    function nppp_varnish_path_ambiguity_reason( string $path, string $query ): string {
        if ( strpos( $path, '%' ) !== false ) {
            return 'percent-encoded path';
        }
        if ( preg_match( '/[^\x20-\x7E]/', $path . $query ) ) {
            return 'non-ASCII characters';
        }
        if ( $query !== '' && preg_match( '/[^A-Za-z0-9\-._~!$&\'()*+,;=:@\/?]/', $query ) ) {
            return 'query characters rewritten by the HTTP client';
        }
        if ( strpos( $path, '//' ) !== false ) {
            return 'repeated slashes';
        }
        if ( strpos( $path, '\\' ) !== false ) {
            return 'backslash';
        }
        if ( preg_match( '#(?:^|/)\.{1,2}(?:/|$)#', $path ) ) {
            return 'dot segments';
        }

        return '';
    }
}

if ( ! function_exists( 'nppp_varnish_path_has_dot_segment' ) ) {
    /**
     * Dot segment check that also looks through repeated percent decoding (fails closed).
     */
    function nppp_varnish_path_has_dot_segment( string $path ): bool {
        if ( function_exists( 'nppp_http_purge_path_has_dot_segment' ) ) {
            return nppp_http_purge_path_has_dot_segment( $path );
        }

        $p = $path;
        for ( $i = 0; $i < 4; $i++ ) {
            if ( preg_match( '#(?:^|[/\x5c])\.{1,2}(?:[/\x5c]|$)#', $p ) ) {
                return true;
            }
            $d = rawurldecode( $p );
            if ( $d === $p ) {
                return false;
            }
            $p = $d;
        }

        return true;
    }
}

if ( ! function_exists( 'nppp_varnish_classify_url' ) ) {
    /**
     * Decides what to do with one purged URL.
     *
     * @return array{action:string,path:string,reason:string}
     *         action: 'single' (send PURGE <path>) | 'all' (needs a host-wide purge) | 'skip'
     */
    function nppp_varnish_classify_url( string $url ): array {
        $skip = static function ( string $reason ): array {
            return array( 'action' => 'skip', 'path' => '', 'reason' => $reason );
        };

        $url = trim( $url );
        $p   = ( $url !== '' ) ? wp_parse_url( $url ) : false;

        if ( ! is_array( $p ) || empty( $p['host'] ) ) {
            return $skip( 'invalid URL' );
        }

        $scheme = strtolower( (string) ( $p['scheme'] ?? '' ) );
        if ( $scheme !== 'http' && $scheme !== 'https' ) {
            return $skip( 'invalid URL' );
        }

        // SSRF / scope guard: only URLs of THIS site (host AND port) are ever purged.
        $home = nppp_varnish_authority( home_url() );
        if ( $home === '' || nppp_varnish_authority( $url ) !== $home ) {
            return $skip( 'host differs from the site host' );
        }

        $path  = (string) ( $p['path'] ?? '' );
        $path  = ( $path === '' ) ? '/' : $path;
        $query = (string) ( $p['query'] ?? '' );

        // Whitespace / control characters can not be put on the wire.
        if ( preg_match( '/[\x00-\x20\x7F]/', $url ) ) {
            return array( 'action' => 'all', 'path' => '', 'reason' => 'whitespace or control characters' );
        }

        if ( nppp_varnish_path_has_dot_segment( $path ) ) {
            return array( 'action' => 'all', 'path' => '', 'reason' => 'dot segments' );
        }

        $reason = nppp_varnish_path_ambiguity_reason( $path, $query );
        if ( $reason !== '' ) {
            return array( 'action' => 'all', 'path' => '', 'reason' => $reason );
        }

        return array(
            'action' => 'single',
            'path'   => $path . ( $query !== '' ? '?' . $query : '' ),
            'reason' => '',
        );
    }
}

/* ============================================================================
 * 6. Purge execution
 * ========================================================================= */

if ( ! function_exists( 'nppp_varnish_budget_seconds' ) ) {
    /**
     * Total time budget of ONE hook call. A hanging Varnish must not stall the editor's save.
     */
    function nppp_varnish_budget_seconds(): float {
        return 5.0;
    }
}

if ( ! function_exists( 'nppp_varnish_request_timeout' ) ) {
    /**
     * Per-request timeout: 3 s, shrunk to what is left of the budget (at least 1 s).
     */
    function nppp_varnish_request_timeout( float $deadline ): float {
        return max( 1.0, min( 3.0, $deadline - microtime( true ) ) );
    }
}

if ( ! function_exists( 'nppp_varnish_handle_result' ) ) {
    /**
     * Applies breaker / throttled logging for one result.
     *
     * @return string 'continue' | 'next_endpoint'
     */
    function nppp_varnish_handle_result( array $result, string $endpoint, int $index ): string {
        $label = nppp_varnish_endpoint_label( $index );

        switch ( $result['kind'] ) {
            case 'unreachable':
                // Park the endpoint and warn ONCE per breaker window.
                nppp_varnish_breaker_arm( $endpoint );
                if ( nppp_varnish_warn_due( $endpoint, 'unreachable' ) ) {
                    nppp_varnish_log( sprintf(
                        /* translators: 1: endpoint label, 2: coarse reason */
                        __( 'WARNING VARNISH: %1$s is unreachable (%2$s). Skipping it for 5 minutes; the Nginx purge is not affected.', 'fastcgi-cache-purge-and-preload-nginx' ),
                        $label,
                        $result['reason'] !== '' ? $result['reason'] : 'transport error'
                    ), 'warning' );
                }
                return 'next_endpoint';

            case 'denied':
                // Configuration problem (ACL, control key, missing PURGE handler): no breaker, same answer for every URL.
                if ( nppp_varnish_warn_due( $endpoint, 'denied' ) ) {
                    nppp_varnish_log( sprintf(
                        /* translators: 1: endpoint label, 2: HTTP status */
                        __( 'WARNING VARNISH: %1$s refused the PURGE request (HTTP %2$d). Check the VCL purge ACL, the control header and that PURGE is handled in vcl_recv.', 'fastcgi-cache-purge-and-preload-nginx' ),
                        $label,
                        (int) $result['status']
                    ), 'warning' );
                }
                return 'next_endpoint';

            case 'fail':
                if ( nppp_varnish_warn_due( $endpoint, 'fail-' . (int) $result['status'] ) ) {
                    nppp_varnish_log( sprintf(
                        /* translators: 1: endpoint label, 2: HTTP status */
                        __( 'WARNING VARNISH: %1$s answered the PURGE request with HTTP %2$d.', 'fastcgi-cache-purge-and-preload-nginx' ),
                        $label,
                        (int) $result['status']
                    ), 'warning' );
                }
                return 'continue';
        }

        return 'continue';
    }
}

if ( ! function_exists( 'nppp_varnish_run_purge_all' ) ) {
    /**
     * Host-wide purge on every endpoint: PURGE /.* with X-Purge-Method: regex.
     *
     * The request body of this purge is a FIXED string; nothing user- or URL-derived reaches
     * the regex. The VCL must contain the regex branch (see Help): a VCL with only
     * "return (purge)" answers 200 and deletes nothing, which NPP can not detect.
     *
     * @param string $reason Optional note for the log when this is an escalation from single URLs.
     */
    function nppp_varnish_run_purge_all( string $reason = '' ): void {
        $endpoints = nppp_varnish_endpoints();
        if ( empty( $endpoints ) ) {
            return;
        }

        $extra    = nppp_varnish_extra_header();
        $deadline = microtime( true ) + nppp_varnish_budget_seconds();
        $sent     = 0;

        foreach ( $endpoints as $index => $endpoint ) {
            if ( microtime( true ) >= $deadline ) {
                nppp_varnish_log( __( 'WARNING VARNISH: time budget exhausted, remaining endpoints were skipped.', 'fastcgi-cache-purge-and-preload-nginx' ), 'warning' );
                break;
            }
            if ( nppp_varnish_breaker_open( $endpoint ) ) {
                continue;
            }

            $result = nppp_varnish_send( $endpoint, '/.*', 'regex', $extra, nppp_varnish_request_timeout( $deadline ) );
            nppp_varnish_handle_result( $result, $endpoint, (int) $index );

            if ( $result['kind'] === 'ok' || $result['kind'] === 'absent' ) {
                $sent++;
            }
        }

        if ( $sent > 0 ) {
            nppp_varnish_log( sprintf(
                /* translators: %d: number of endpoints */
                _n(
                    'INFO VARNISH: host-wide purge sent to %d Varnish / Vinyl Cache endpoint.',
                    'INFO VARNISH: host-wide purge sent to %d Varnish / Vinyl Cache endpoints.',
                    $sent,
                    'fastcgi-cache-purge-and-preload-nginx'
                ),
                $sent
            ) . ( $reason !== '' ? ' (' . $reason . ')' : '' ), 'info' );
        }
    }
}

if ( ! function_exists( 'nppp_varnish_purge_urls' ) ) {
    /**
     * nppp_purged_urls listener: single purge finished on Nginx, before any preload starts.
     *
     * @param array  $urls        Every URL the purge covered (primary + related).
     * @param string $primary_url Primary URL (unused).
     * @param int    $post_id     Post ID (unused).
     * @param bool   $is_auto     Auto purge flag (unused).
     */
    function nppp_varnish_purge_urls( $urls = array(), $primary_url = '', $post_id = 0, $is_auto = false ): void {
        try {
            if ( ! is_array( $urls ) || empty( $urls ) || ! nppp_varnish_enabled( 'purge_urls' ) ) {
                return;
            }

            $endpoints = nppp_varnish_endpoints();
            if ( empty( $endpoints ) ) {
                return;
            }

            $paths    = array();
            $escalate = '';
            $skipped  = 0;

            foreach ( array_unique( array_filter( $urls, 'is_string' ) ) as $url ) {
                $class = nppp_varnish_classify_url( $url );

                if ( $class['action'] === 'single' ) {
                    $paths[ $class['path'] ] = true;
                } elseif ( $class['action'] === 'all' ) {
                    if ( $escalate === '' ) {
                        $escalate = $class['reason'];
                    }
                } else {
                    $skipped++;
                }
            }
            $paths = array_keys( $paths );

            /**
             * Filters the number of URLs above which one host-wide purge replaces single PURGEs.
             *
             * @param int $max Default 50.
             */
            $max = (int) apply_filters( 'nppp_varnish_max_urls', 50 );
            if ( $max < 1 ) {
                $max = 50;
            }
            if ( $escalate === '' && count( $paths ) > $max ) {
                $escalate = 'many URLs';
            }

            if ( $escalate !== '' ) {
                nppp_varnish_run_purge_all( $escalate );
                return;
            }

            if ( empty( $paths ) ) {
                return;
            }

            $extra    = nppp_varnish_extra_header();
            $deadline = microtime( true ) + nppp_varnish_budget_seconds();
            $skipped_budget = 0;

            foreach ( $endpoints as $index => $endpoint ) {
                if ( nppp_varnish_breaker_open( $endpoint ) ) {
                    continue;
                }

                $done = 0;
                foreach ( $paths as $i => $path ) {
                    if ( microtime( true ) >= $deadline ) {
                        $skipped_budget += count( $paths ) - $i;
                        break;
                    }

                    $result = nppp_varnish_send( $endpoint, $path, 'default', $extra, nppp_varnish_request_timeout( $deadline ) );
                    $next   = nppp_varnish_handle_result( $result, $endpoint, (int) $index );

                    if ( $result['kind'] === 'ok' || $result['kind'] === 'absent' ) {
                        $done++;
                    }
                    if ( $next === 'next_endpoint' ) {
                        break;
                    }
                }

                if ( $done > 0 ) {
                    nppp_varnish_log( sprintf(
                        /* translators: 1: number of URLs, 2: endpoint label */
                        _n(
                            'INFO VARNISH: %1$d URL purged on %2$s.',
                            'INFO VARNISH: %1$d URLs purged on %2$s.',
                            $done,
                            'fastcgi-cache-purge-and-preload-nginx'
                        ),
                        $done,
                        nppp_varnish_endpoint_label( (int) $index )
                    ), 'info' );
                }
            }

            if ( $skipped_budget > 0 ) {
                nppp_varnish_log( sprintf(
                    /* translators: %d: number of PURGE requests skipped */
                    __( 'WARNING VARNISH: time budget exhausted, %d PURGE request(s) were skipped.', 'fastcgi-cache-purge-and-preload-nginx' ),
                    $skipped_budget
                ), 'warning' );
            }

            if ( $skipped > 0 ) {
                nppp_varnish_log( sprintf(
                    /* translators: %d: number of URLs */
                    __( 'INFO VARNISH: %d URL(s) skipped (not a URL of this site).', 'fastcgi-cache-purge-and-preload-nginx' ),
                    $skipped
                ), 'info' );
            }
        } catch ( \Throwable $e ) {
            // A Varnish problem must never break the purge that already succeeded on Nginx.
            nppp_varnish_log( 'WARNING VARNISH: unexpected error (' . get_class( $e ) . '); the Nginx purge is not affected.', 'warning' );
        }
    }
}

if ( ! function_exists( 'nppp_varnish_purge_all' ) ) {
    /**
     * Listener for nppp_purged_all (Purge All, after Nginx) and nppp_preload_all_started
     * (direct Preload All, right before the crawl). Both mean "Nginx is clean, the next
     * request must not be answered from a stale outer copy".
     *
     * An auto-preloading Purge All fires nppp_purged_all and then nppp_preload_all_started: the
     * guard keeps that preload trigger from sending a duplicate after a purge in the same request.
     * Every nppp_purged_all is sent, so a second genuine Purge All is not suppressed.
     *
     * @param mixed $unused nppp_purged_all passes $auto_preload; nppp_preload_all_started passes nothing.
     */
    function nppp_varnish_purge_all( $unused = null ): void {
        static $done = false;

        try {
            $context = ( current_action() === 'nppp_preload_all_started' ) ? 'preload_all' : 'purge_all';

            // Only the preload trigger is deduplicated against an earlier purge in this request.
            // A second genuine Purge All (several callers, long-lived WP-CLI/cron process) must still run.
            if ( $context === 'preload_all' && $done ) {
                return;
            }

            if ( ! nppp_varnish_enabled( $context ) ) {
                return;
            }

            $done = true;
            nppp_varnish_run_purge_all();
        } catch ( \Throwable $e ) {
            nppp_varnish_log( 'WARNING VARNISH: unexpected error (' . get_class( $e ) . '); the Nginx purge is not affected.', 'warning' );
        }
    }
}

add_action( 'nppp_purged_urls', 'nppp_varnish_purge_urls', 10, 4 );
add_action( 'nppp_purged_all', 'nppp_varnish_purge_all', 10, 1 );
add_action( 'nppp_preload_all_started', 'nppp_varnish_purge_all', 10, 0 );
