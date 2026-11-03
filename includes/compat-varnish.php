<?php
/**
 * Varnish / Vinyl Cache compatibility for Nginx Cache Purge Preload
 * Description: Mirrors plugin purge actions to a Varnish / Vinyl Cache. Two modes:
 *              - Sync: Varnish in front of the Nginx cache, both layers stay synchronized.
 *              - Varnish-only: Nginx has no cache, Varnish is the only cache layer.
 * Version: 2.1.8
 * Author: Hasan CALISIR
 * License: GPL-2.0+
 *
 * Topologies:
 *
 *   Sync (default, front-only):
 *     visitor -> nginx:443 (TLS) -> Varnish / Vinyl :6081 -> nginx:8080 (fastcgi_cache) -> PHP-FPM
 *
 *   Varnish-only (opt-in):
 *     visitor -> nginx:443 (TLS, no cache) -> Varnish / Vinyl :6081 -> Apache / PHP-FPM
 *
 * Varnish-only mode is requested by the Settings switch, define( 'NPPP_VARNISH_ONLY', ... ) in
 * wp-config.php (authoritative, locks the switch) or the nppp_varnish_only filter. It is active only
 * while Varnish sync is ON with at least one valid endpoint (see nppp_varnish_only_mode()).
 *
 * Order rule (Sync mode): inner layer first, outer layer second. The hooks used here fire AFTER
 * Nginx is clean ("origin purged"), and BEFORE any preload crawl starts (the crawl requests the
 * public URL and therefore passes through Varnish):
 *
 *   nppp_purged_urls         single purge, after nginx, before auto-preload
 *   nppp_purged_all          Purge All, after nginx (deferred while nginx's queue is busy)
 *   nppp_preload_all_started direct Preload All, right before the crawl starts
 *
 * Varnish-only mode has no inner layer: the Nginx purge is skipped ("nothing to purge"), the same
 * hooks fire right away, and the PURGE / ban sent here IS the purge.
 *
 * Wire contract (NPP's own PURGE contract, plain HTTP, no admin/6082 port):
 *
 *   single URL : PURGE <endpoint><path[?query]>   Host: <site authority>   X-Purge-Method: default
 *   everything : PURGE <endpoint>/.*              Host: <site authority>   X-Purge-Method: regex
 *
 * The purge-all body is a FIXED string: nothing user- or URL-derived is ever placed in a regex.
 *
 * Design rules:
 *  - Sync mode: side channel. A Varnish failure never changes the purge result NPP shows; it only logs.
 *  - Varnish-only mode: the Varnish result IS the outcome. nppp_varnish_only_report() shows it as a
 *    notice (manual actions) or logs it (automatic ones), keeps a persistent admin notice after a
 *    failure until the next success, and feeds the Status tab.
 *  - Synchronous, short timeouts, per-endpoint circuit breaker (parked 300 s in Sync mode, 15 s in
 *    Varnish-only mode; no shutdown queue: the purge must be finished before the preload crawl starts).
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
     * (Varnish listens on localhost on a single host; in Docker use its container name). Duplicates are dropped; at most 5 endpoints.
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

if ( ! function_exists( 'nppp_varnish_only_locked' ) ) {
    /**
     * Is Varnish-only mode pinned by NPPP_VARNISH_ONLY in wp-config.php?
     *
     * While the constant is defined it is authoritative, in BOTH directions (true forces the request
     * ON, false forces it OFF), and the Settings switch is shown but locked. Code that defines a
     * constant is a deliberate deployment decision; a switch in the database must not undo it.
     */
    function nppp_varnish_only_locked(): bool {
        return defined( 'NPPP_VARNISH_ONLY' );
    }
}

if ( ! function_exists( 'nppp_varnish_only_optin' ) ) {
    /**
     * Has the site owner asked for Varnish-only mode (constant, Settings switch or filter)?
     *
     * This is only the REQUEST. Whether the mode is really active also depends on the Varnish sync
     * settings, see nppp_varnish_only_mode().
     *
     * Source, in order: the NPPP_VARNISH_ONLY constant when it is defined (see
     * nppp_varnish_only_locked()), otherwise the Settings switch (nppp_varnish_only_enabled === 'yes').
     * The nppp_varnish_only filter gets the last word, as before.
     *
     * The constant is read as a boolean the way WordPress people write it: true, 1, '1', 'true',
     * 'yes', 'on' enable it; false, 0, '0', 'false', 'no', 'off', '' and anything else do not.
     * A plain truthiness test would turn define( 'NPPP_VARNISH_ONLY', 'false' ) into ON.
     */
    function nppp_varnish_only_optin(): bool {
        if ( nppp_varnish_only_locked() ) {
            $on = ( filter_var( NPPP_VARNISH_ONLY, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE ) === true );
        } else {
            $on = ( nppp_varnish_option( 'nppp_varnish_only_enabled' ) === 'yes' );
        }

        return (bool) apply_filters( 'nppp_varnish_only', $on );
    }
}

if ( ! function_exists( 'nppp_varnish_only_reset_state' ) ) {
    /**
     * Forget the runtime state of the previous mode after the Varnish-only switch changed.
     *
     * The parked-endpoint window differs per mode (300 s vs 15 s) and a failure recorded in one mode
     * says nothing about the other, so the breaker, the throttled warning and the wp-admin failure
     * banner start clean. Shared by the Settings save, the AJAX switch and WP-CLI.
     */
    function nppp_varnish_only_reset_state(): void {
        if ( ! function_exists( 'nppp_state_delete' ) ) {
            return;
        }
        nppp_state_delete( 'varnish_breaker' );
        nppp_state_delete( 'varnish_warn' );
        nppp_state_delete( 'varnish_vo_failure' );
        nppp_state_delete( 'varnish_vo_last_ok' );
        // Status tab card: the 24 h purge log, the per-endpoint PURGE timings and the last live cache check.
        nppp_state_delete( 'varnish_vo_activity' );
        nppp_state_delete( 'varnish_vo_ep' );
        nppp_state_delete( 'varnish_vo_check' );
        nppp_state_delete( 'varnish_vo_full' );
        nppp_state_delete( 'varnish_vo_full_run' );
        nppp_state_delete( 'varnish_vo_purged' );
        nppp_state_delete( 'varnish_vo_purged_urls' );
    }
}

if ( ! function_exists( 'nppp_varnish_only_mode' ) ) {
    /**
     * Varnish-only mode: nginx has NO cache of its own (visitor -> nginx:443 -> Varnish -> origin).
     *
     * There is no inner layer, so the "nginx first, Varnish second" order rule has nothing to order:
     * a purge reduces to the Varnish PURGE / ban that the nppp_purged_* hooks already send, and the
     * nginx cache directory is neither required nor touched by a purge.
     *
     * Strictly opt-in, so no existing install changes behaviour (default OFF everywhere):
     *   Settings > Varnish / Vinyl Cache Sync > Varnish-only mode  (switch)
     *   define( 'NPPP_VARNISH_ONLY', true );              // wp-config.php, locks the switch
     *   add_filter( 'nppp_varnish_only', '__return_true' );
     * and only while Varnish sync is ON with at least one valid endpoint. With sync OFF (or no
     * endpoint) the mode is never active, so a half-configured site keeps the stock nginx path
     * and its error messages instead of silently doing nothing (nppp_varnish_only_inactive_notice()
     * tells the admin about that state).
     */
    function nppp_varnish_only_mode(): bool {
        // nppp_varnish_endpoints() is already empty while the sync switch is OFF.
        return nppp_varnish_only_optin() && nppp_varnish_endpoints() !== array();
    }
}

if ( ! function_exists( 'nppp_varnish_breaker_seconds' ) ) {
    /**
     * How long an unreachable endpoint stays parked.
     *
     * 300 s next to an Nginx cache (the Nginx purge already cleaned the origin layer). In Varnish-only
     * mode Varnish is the ONLY layer, so every second of parking is a window in which edits are not
     * purged anywhere: 15 s there, just enough to keep a dead endpoint from stalling every save.
     */
    function nppp_varnish_breaker_seconds(): int {
        $seconds = nppp_varnish_only_mode() ? 15 : 300;

        /**
         * Filters the circuit-breaker park time in seconds (clamped to 5 - 3600).
         *
         * @param int $seconds 300 by default, 15 in Varnish-only mode.
         */
        $seconds = (int) apply_filters( 'nppp_varnish_breaker_seconds', $seconds );

        return max( 5, min( 3600, $seconds ) );
    }
}

if ( ! function_exists( 'nppp_varnish_run_count' ) ) {
    /**
     * Counts one request outcome of the current purge run (request-local).
     *
     * kind: ok | absent | denied | unreachable | fail (a request that was sent),
     *       parked (endpoint skipped, breaker open) | budget (requests skipped, time budget used up).
     */
    function nppp_varnish_run_count( string $kind ): void {
        if ( ! isset( $GLOBALS['NPPP_VARNISH_RUN'] ) || ! is_array( $GLOBALS['NPPP_VARNISH_RUN'] ) ) {
            $GLOBALS['NPPP_VARNISH_RUN'] = array();
        }
        $GLOBALS['NPPP_VARNISH_RUN'][ $kind ] = (int) ( $GLOBALS['NPPP_VARNISH_RUN'][ $kind ] ?? 0 ) + 1;
    }
}

if ( ! function_exists( 'nppp_varnish_run_take' ) ) {
    /** Returns the counters of the run that just finished and starts a new run. */
    function nppp_varnish_run_take(): array {
        $c = ( isset( $GLOBALS['NPPP_VARNISH_RUN'] ) && is_array( $GLOBALS['NPPP_VARNISH_RUN'] ) ) ? $GLOBALS['NPPP_VARNISH_RUN'] : array();
        $GLOBALS['NPPP_VARNISH_RUN'] = array();

        return $c;
    }
}

if ( ! function_exists( 'nppp_varnish_run_summary' ) ) {
    /**
     * @return array{status:string,good:int,bad:int,detail:string}
     *         status: ok (everything accepted) | partial | failed (nothing accepted) | none (nothing was sent)
     */
    function nppp_varnish_run_summary( array $c ): array {
        $n    = static function ( string $k ) use ( $c ): int {
            return (int) ( $c[ $k ] ?? 0 );
        };
        $good = $n( 'ok' ) + $n( 'absent' );
        $bad  = $n( 'denied' ) + $n( 'unreachable' ) + $n( 'fail' ) + $n( 'parked' ) + $n( 'budget' );

        if ( $good + $bad === 0 ) {
            $status = 'none';
        } elseif ( $bad === 0 ) {
            $status = 'ok';
        } elseif ( $good > 0 ) {
            $status = 'partial';
        } else {
            $status = 'failed';
        }

        $labels = array(
            'unreachable' => 'unreachable',
            'denied'      => 'refused (ACL / control key / PURGE not handled)',
            'fail'        => 'HTTP error',
            'parked'      => 'endpoint parked after an earlier failure',
            'budget'      => 'skipped, time budget used up',
        );
        $parts  = array();
        foreach ( $labels as $k => $label ) {
            if ( $n( $k ) > 0 ) {
                $parts[] = $label . ': ' . $n( $k );
            }
        }

        return array( 'status' => $status, 'good' => $good, 'bad' => $bad, 'detail' => implode( ', ', $parts ) );
    }
}

if ( ! function_exists( 'nppp_varnish_unexpected_suffix' ) ) {
    /**
     * Tail of the "unexpected error" log line. Never throws: it runs inside a catch block, and the
     * exception being handled may have come from the very filter nppp_varnish_only_mode() applies.
     */
    function nppp_varnish_unexpected_suffix(): string {
        try {
            return nppp_varnish_only_mode()
                ? 'nothing was purged (Varnish-only mode).'
                : 'the Nginx purge is not affected.';
        } catch ( \Throwable $e ) {
            return 'the Nginx purge is not affected.';
        }
    }
}

if ( ! function_exists( 'nppp_varnish_ep_note' ) ) {
    /**
     * Remembers the outcome of the latest PURGE request per endpoint (request-local).
     *
     * nppp_varnish_send() can run dozens of times in one purge (one request per related URL), so it only
     * notes the outcome here; nppp_varnish_ep_flush() writes it ONCE per run, from nppp_varnish_only_report().
     * The Status tab reads it to show "last PURGE answered in 14 ms" per endpoint.
     *
     * @param int|null $ms Round-trip time in milliseconds, null when no answer came back.
     */
    function nppp_varnish_ep_note( string $endpoint, string $kind, int $status, ?int $ms, string $reason ): void {
        if ( ! isset( $GLOBALS['NPPP_VARNISH_EP'] ) || ! is_array( $GLOBALS['NPPP_VARNISH_EP'] ) ) {
            $GLOBALS['NPPP_VARNISH_EP'] = array();
        }
        $GLOBALS['NPPP_VARNISH_EP'][ nppp_varnish_endpoint_id( $endpoint ) ] = array(
            'at'     => time(),
            'kind'   => $kind,
            'status' => $status,
            'ms'     => $ms,
            'reason' => $reason,
        );
    }
}

if ( ! function_exists( 'nppp_varnish_ep_flush' ) ) {
    /**
     * Persists the noted endpoint outcomes (one state write per purge run) and starts a new run.
     * Rows of endpoints that are no longer configured are dropped here, so the map never outgrows the settings.
     */
    function nppp_varnish_ep_flush(): void {
        $noted = ( isset( $GLOBALS['NPPP_VARNISH_EP'] ) && is_array( $GLOBALS['NPPP_VARNISH_EP'] ) ) ? $GLOBALS['NPPP_VARNISH_EP'] : array();
        $GLOBALS['NPPP_VARNISH_EP'] = array();

        if ( empty( $noted ) || ! function_exists( 'nppp_state_set' ) || ! function_exists( 'nppp_state_get' ) ) {
            return;
        }

        $keep = array();
        foreach ( nppp_varnish_endpoints() as $endpoint ) {
            $keep[ nppp_varnish_endpoint_id( $endpoint ) ] = true;
        }

        $map = nppp_state_get( 'varnish_vo_ep' );
        $map = is_array( $map ) ? $map : array();
        $map = array_merge( $map, $noted );
        $map = array_intersect_key( $map, $keep );

        if ( ! empty( $map ) ) {
            nppp_state_set( 'varnish_vo_ep', $map, 30 * DAY_IN_SECONDS );
        }
    }
}

if ( ! function_exists( 'nppp_varnish_ep_last' ) ) {
    /**
     * Latest noted PURGE outcome of one endpoint, or null when none is known.
     *
     * @return array{at:int,kind:string,status:int,ms:?int,reason:string}|null
     */
    function nppp_varnish_ep_last( string $endpoint ): ?array {
        $map = function_exists( 'nppp_state_get' ) ? nppp_state_get( 'varnish_vo_ep' ) : null;
        $row = ( is_array( $map ) && isset( $map[ nppp_varnish_endpoint_id( $endpoint ) ] ) ) ? $map[ nppp_varnish_endpoint_id( $endpoint ) ] : null;

        if ( ! is_array( $row ) || empty( $row['at'] ) || empty( $row['kind'] ) || ! is_string( $row['kind'] ) ) {
            return null;
        }

        return array(
            'at'     => (int) $row['at'],
            'kind'   => $row['kind'],
            'status' => (int) ( $row['status'] ?? 0 ),
            'ms'     => isset( $row['ms'] ) ? (int) $row['ms'] : null,
            'reason' => is_string( $row['reason'] ?? null ) ? $row['reason'] : '',
        );
    }
}

if ( ! function_exists( 'nppp_varnish_activity_window' ) ) {
    /** How far back the Status tab's purge activity looks. */
    function nppp_varnish_activity_window(): int {
        return DAY_IN_SECONDS;
    }
}

if ( ! function_exists( 'nppp_varnish_activity_max' ) ) {
    /** Hard cap of stored events: a site that purges on every save must not grow one option row without limit. */
    function nppp_varnish_activity_max(): int {
        return 200;
    }
}

if ( ! function_exists( 'nppp_varnish_activity' ) ) {
    /**
     * Purge runs of the last 24 hours, oldest first.
     *
     * @return array<int,array{at:int,scope:string,src:string,status:string,detail:string,kinds:string[]}>
     */
    function nppp_varnish_activity(): array {
        $rows = function_exists( 'nppp_state_get' ) ? nppp_state_get( 'varnish_vo_activity' ) : null;
        if ( ! is_array( $rows ) ) {
            return array();
        }

        $since = time() - nppp_varnish_activity_window();
        $out   = array();
        foreach ( $rows as $row ) {
            if ( ! is_array( $row ) || empty( $row['at'] ) || (int) $row['at'] < $since || ! in_array( $row['status'] ?? '', array( 'ok', 'partial', 'failed' ), true ) ) {
                continue;
            }
            $out[] = array(
                'at'     => (int) $row['at'],
                'scope'  => ( ( $row['scope'] ?? '' ) === 'all' ) ? 'all' : 'urls',
                'src'    => ( ( $row['src'] ?? '' ) === 'preload' ) ? 'preload' : '',
                'status' => (string) $row['status'],
                'detail' => is_string( $row['detail'] ?? null ) ? $row['detail'] : '',
                'kinds'  => is_array( $row['kinds'] ?? null ) ? array_values( array_filter( $row['kinds'], 'is_string' ) ) : array(),
            );
        }

        return $out;
    }
}

if ( ! function_exists( 'nppp_varnish_activity_add' ) ) {
    /**
     * Appends one finished purge run to the rolling 24 h log.
     *
     * Read-modify-write on one option row: two purges that finish in the same instant can lose one
     * entry (last write wins). That is accepted for a trend display; the counters are not a ledger.
     *
     * @param array $counts Request counters of the run (nppp_varnish_run_take()).
     */
    function nppp_varnish_activity_add( string $scope, bool $preload_ban, array $summary, array $counts ): void {
        if ( ! function_exists( 'nppp_state_set' ) || ! in_array( $summary['status'] ?? '', array( 'ok', 'partial', 'failed' ), true ) ) {
            return;
        }

        $kinds = array();
        foreach ( array( 'unreachable', 'denied', 'fail', 'parked', 'budget' ) as $k ) {
            if ( (int) ( $counts[ $k ] ?? 0 ) > 0 ) {
                $kinds[] = $k;
            }
        }

        $rows   = nppp_varnish_activity();
        $rows[] = array(
            'at'     => time(),
            'scope'  => $scope === 'all' ? 'all' : 'urls',
            'src'    => $preload_ban ? 'preload' : '',
            'status' => (string) $summary['status'],
            'detail' => (string) ( $summary['detail'] ?? '' ),
            'kinds'  => $kinds,
        );

        nppp_state_set( 'varnish_vo_activity', array_slice( $rows, -nppp_varnish_activity_max() ), nppp_varnish_activity_window() + HOUR_IN_SECONDS );
    }
}

if ( ! function_exists( 'nppp_varnish_purge_mark' ) ) {
    /**
     * Tells the Status tab card that a purge reached Varnish.
     *
     * The card shows a stored cache check (Check now / Check all). A purge removes pages after that check was made,
     * so the stored counts no longer match Varnish:
     * - Purge All (host-wide ban): the stored Check all result is wrong by definition and is dropped, together with
     *   a Check all that is still running (its chunks would mix answers from before and after the ban). The card
     *   then falls back to the Check now sample.
     * - Every purge run that went through is remembered by its time, so the card can say "N purge runs after this
     *   check". The newest 200 are kept for the 7 days a stored check lives, so a purge storm can not grow the row.
     *
     * Only runs that took effect count: a purge that failed or sent nothing changed nothing in Varnish.
     * Side channel of nppp_varnish_only_report(): it can never change what is reported.
     *
     * @param string $scope  'all' (Purge All / Preload All ban) or 'urls' (single purge).
     * @param string $status Result of the run: 'ok' | 'partial' | 'failed' | 'none'.
     */
    function nppp_varnish_purge_mark( string $scope, string $status ): void {
        if ( ! function_exists( 'nppp_state_set' ) || ! in_array( $status, array( 'ok', 'partial' ), true ) ) {
            return;
        }

        try {
            if ( $scope === 'all' ) {
                nppp_state_delete( 'varnish_vo_full' );
                nppp_state_delete( 'varnish_vo_full_run' );
                nppp_state_delete( 'varnish_vo_purged_urls' );
            }

            $now  = time();
            $keep = $now - 7 * DAY_IN_SECONDS;
            $old  = nppp_state_get( 'varnish_vo_purged' );
            $rows = array();
            foreach ( ( is_array( $old ) ? $old : array() ) as $ts ) {
                if ( is_int( $ts ) && $ts >= $keep ) {
                    $rows[] = $ts;
                }
            }
            $rows[] = $now;

            nppp_state_set( 'varnish_vo_purged', array_slice( $rows, -nppp_varnish_activity_max() ), 7 * DAY_IN_SECONDS + HOUR_IN_SECONDS );
        } catch ( \Throwable $e ) {
            return;
        }
    }
}

if ( ! function_exists( 'nppp_varnish_note_purged_urls' ) ) {
    /**
     * Varnish-only mode: remembers which URLs a single purge covered (url => time of the purge).
     *
     * The Status tab keeps only counts of the last Check all. Check now uses this list to ask Varnish again about
     * just these URLs and correct the counts (nppp_varnish_vo_reconcile_full()). One small row, newest 200 URLs,
     * not autoloaded. It records what was ASKED to be purged, not what Varnish accepted: Check now compares the
     * answers, so a refused purge simply changes nothing.
     *
     * @param array $urls Every URL the purge covered (primary + related).
     */
    function nppp_varnish_note_purged_urls( array $urls ): void {
        if ( ! function_exists( 'nppp_state_set' ) || ! function_exists( 'nppp_varnish_only_mode' ) || ! nppp_varnish_only_mode() ) {
            return;
        }

        try {
            $now  = time();
            $keep = $now - 7 * DAY_IN_SECONDS;
            $old  = nppp_state_get( 'varnish_vo_purged_urls' );
            $rows = array();
            foreach ( ( is_array( $old ) ? $old : array() ) as $u => $ts ) {
                if ( is_string( $u ) && is_int( $ts ) && $ts >= $keep ) {
                    $rows[ $u ] = $ts;
                }
            }
            foreach ( $urls as $u ) {
                if ( is_string( $u ) && $u !== '' && strlen( $u ) <= 2048 ) {
                    unset( $rows[ $u ] );   // re-add at the end: newest last
                    $rows[ $u ] = $now;
                }
            }

            nppp_state_set( 'varnish_vo_purged_urls', array_slice( $rows, -200, null, true ), 7 * DAY_IN_SECONDS + HOUR_IN_SECONDS );
        } catch ( \Throwable $e ) {
            return;
        }
    }
}

if ( ! function_exists( 'nppp_varnish_only_report' ) ) {
    /**
     * Varnish-only mode: tells the operator what the Varnish purge of this action really did.
     *
     * With an Nginx cache the Nginx purge result is the visible outcome and Varnish is a side channel
     * that may stay log-only. Without one the Varnish result IS the outcome, so it must be shown the way
     * the Nginx result is: a notice on manual actions (admin bar, Advanced tab, REST, WP-CLI), the log
     * for automatic ones, plus a persistent admin notice after any failure that clears on the next success.
     *
     * @param string $scope   'all' (Purge All / Preload All ban) or 'urls' (single purge).
     * @param bool   $is_auto Automatic purge (log only, no on-screen notice).
     * @param string $target  Primary URL of a single purge, for the message.
     * @param bool   $quiet_none Say nothing when no request was sent at all (deduplicated ban).
     */
    function nppp_varnish_only_report( string $scope, bool $is_auto = false, string $target = '', bool $quiet_none = false ): void {
        $counts = nppp_varnish_run_take();
        $s      = nppp_varnish_run_summary( $counts );

        // Preload All fires the ban hook right after a Purge All that already reported (the listener
        // deduplicates it). Nothing was sent THIS time, which is not a failure: stay silent.
        if ( $quiet_none && $s['status'] === 'none' ) {
            return;
        }
        $detail = $s['detail'] !== '' ? ' (' . $s['detail'] . ')' : '';

        // Status tab: per-endpoint PURGE timings and the 24 h log (one state write each per run).
        nppp_varnish_ep_flush();
        nppp_varnish_activity_add( $scope, $quiet_none, $s, $counts );
        nppp_varnish_purge_mark( $scope, (string) $s['status'] );

        switch ( $s['status'] ) {
            case 'ok':
                $type = 'success';
                if ( $scope === 'all' ) {
                    $msg = __( 'SUCCESS VARNISH: Purge All accepted by Varnish / Vinyl Cache (host-wide ban). Varnish-only mode: no Nginx cache is involved.', 'fastcgi-cache-purge-and-preload-nginx' );
                } else {
                    /* translators: %s: page URL */
                    $msg = sprintf( __( 'SUCCESS VARNISH: PURGE accepted by Varnish / Vinyl Cache for %s and its related URLs.', 'fastcgi-cache-purge-and-preload-nginx' ), $target );
                }
                break;

            case 'partial':
                $type = 'warning';
                /* translators: %s: failure details */
                $msg = sprintf( __( 'WARNING VARNISH: Only part of the Varnish / Vinyl Cache purge went through%s. What failed stays cached until its TTL expires.', 'fastcgi-cache-purge-and-preload-nginx' ), $detail );
                break;

            case 'failed':
                $type = 'error';
                /* translators: %s: failure details */
                $msg = sprintf( __( 'ERROR VARNISH: Purge FAILED on Varnish / Vinyl Cache%s. Varnish-only mode has no other cache layer, so the page stays cached until its TTL expires. Check that Varnish is running, the purge ACL, the control header and the VCL (Help tab).', 'fastcgi-cache-purge-and-preload-nginx' ), $detail );
                break;

            default:
                $type = 'warning';
                $msg  = __( 'WARNING VARNISH: No PURGE request was sent to Varnish / Vinyl Cache (no valid URL of this site, or a filter vetoed the sync). Nothing was purged.', 'fastcgi-cache-purge-and-preload-nginx' );
                break;
        }

        if ( $is_auto ) {
            nppp_display_admin_notice( $type, $msg, true, false );
        } else {
            nppp_display_admin_notice( $type, $msg );
        }

        // Persistent record for the admin notice (a failed automatic purge has no screen to show it on).
        if ( function_exists( 'nppp_state_set' ) ) {
            if ( $s['status'] === 'ok' ) {
                nppp_state_delete( 'varnish_vo_failure' );
                // Shown on the Status tab ("last successful purge"); the failure row alone can not tell "no failure" from "never purged".
                nppp_state_set( 'varnish_vo_last_ok', array( 'at' => time(), 'scope' => $scope ), 30 * DAY_IN_SECONDS );
            } elseif ( $s['status'] !== 'none' ) {   // 'none' = nothing was attempted: neither a failure nor a recovery
                nppp_state_set(
                    'varnish_vo_failure',
                    array( 'at' => time(), 'status' => $s['status'], 'scope' => $scope, 'detail' => $s['detail'] ),
                    7 * DAY_IN_SECONDS
                );
            }
        }
    }
}

if ( ! function_exists( 'nppp_varnish_on_own_screen' ) ) {
    /**
     * True only on NPP's own admin screens: the same allowlist nppp_display_admin_notice() applies in log.php.
     *
     * NPP never prints notices on other wp-admin screens (Dashboard, Plugins, Posts ...). The two
     * Varnish-only notices below use this as a cheap first gate so they neither render nor run their
     * state lookups anywhere else. Keep the ids in sync with $nppp_own_screens in log.php.
     */
    function nppp_varnish_on_own_screen(): bool {
        if ( ! function_exists( 'get_current_screen' ) ) {
            return false;
        }

        $screen = get_current_screen();

        return $screen && in_array( $screen->id, array( 'settings_page_nginx_cache_settings', 'admin_page_nppp-setup' ), true );
    }
}

if ( ! function_exists( 'nppp_varnish_only_admin_notice' ) ) {
    /**
     * Banner on NPP's own screens while the last Varnish-only purge did not fully succeed.
     * Cleared by the next fully successful purge, or after 7 days.
     *
     * Rendered through nppp_display_admin_notice() like every other NPP notice, so it follows the
     * plugin's screen allowlist and markup (notice-nppp). Never logged here: it fires on every page load.
     */
    function nppp_varnish_only_admin_notice(): void {
        if ( ! nppp_varnish_on_own_screen() ) {
            return;
        }
        if ( ! function_exists( 'current_user_can' ) || ! current_user_can( 'manage_options' ) ) {
            return;
        }
        if ( ! function_exists( 'nppp_state_get' ) || ! function_exists( 'nppp_display_admin_notice' ) || ! nppp_varnish_only_mode() ) {
            return;
        }

        $f = nppp_state_get( 'varnish_vo_failure' );
        if ( ! is_array( $f ) || empty( $f['at'] ) ) {
            return;
        }

        $ago = function_exists( 'human_time_diff' ) ? human_time_diff( (int) $f['at'], time() ) : ( ( time() - (int) $f['at'] ) . 's' );
        $txt = sprintf(
            /* translators: 1: time since the failure, 2: failure details */
            __( 'NPP Varnish-only mode: the last Varnish / Vinyl Cache purge did not fully succeed (%1$s ago%2$s). Pages may be stale until their TTL expires. Run Purge All once Varnish is reachable; this notice clears after the next successful purge.', 'fastcgi-cache-purge-and-preload-nginx' ),
            $ago,
            ( ! empty( $f['detail'] ) && is_string( $f['detail'] ) ) ? ': ' . $f['detail'] : ''
        );

        nppp_display_admin_notice( 'error', $txt, false, true );
    }
}

if ( ! function_exists( 'nppp_varnish_only_inactive_notice' ) ) {
    /**
     * Varnish-only mode was requested (constant / filter) but is NOT active.
     *
     * The mode needs Varnish sync ON and at least one valid endpoint. Until then NPP runs the stock
     * Nginx-cache path and reports a missing cache path, which looks like a plugin bug. Say why.
     */
    function nppp_varnish_only_inactive_notice(): void {
        if ( ! nppp_varnish_on_own_screen() ) {
            return;
        }
        if ( ! function_exists( 'current_user_can' ) || ! current_user_can( 'manage_options' ) ) {
            return;
        }
        if ( ! function_exists( 'nppp_display_admin_notice' ) || ! nppp_varnish_only_optin() || nppp_varnish_only_mode() ) {
            return;
        }

        $txt = __( 'NPP Varnish-only mode is requested but NOT active: turn ON "Varnish / Vinyl Cache Sync" in NPP Settings and add at least one valid endpoint (or switch Varnish-only mode off there). Until then NPP runs in the stock Nginx-cache mode and purges do not reach Varnish.', 'fastcgi-cache-purge-and-preload-nginx' );

        nppp_display_admin_notice( 'warning', $txt, false, true );
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
     * (nppp_state_* survives wp_cache_flush(), which NPP's own Redis sync can run on a Purge All
     * that is followed by a preload and on Preload All; a transient would be erased by it.)
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
     * Parks an endpoint for nppp_varnish_breaker_seconds() (300 s, 15 s in Varnish-only mode).
     */
    function nppp_varnish_breaker_arm( string $endpoint ): void {
        $id = nppp_varnish_endpoint_id( $endpoint );

        nppp_varnish_local_breaker( $id );
        nppp_varnish_state_map_set( 'varnish_breaker', $id, nppp_varnish_breaker_seconds() );
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
            // Explicit zero length keeps the body-less PURGE unambiguous for every HTTP/1.1 hop.
            'Content-Length' => '0',
        );
        if ( ! empty( $extra['name'] ) && isset( $extra['value'] ) ) {
            $headers[ $extra['name'] ] = $extra['value'];
        }

        /**
         * Filters TLS certificate verification for https:// Varnish / Vinyl Cache endpoints.
         * Default false: the endpoint is normally a loopback or private address without a public
         * certificate. Return true to verify it, e.g. when the control header crosses a network.
         *
         * @param bool   $verify   Default false.
         * @param string $endpoint scheme://host[:port]
         */
        $sslverify = (bool) apply_filters( 'nppp_varnish_sslverify', false, $endpoint );

        $started = microtime( true );
        add_action( 'http_api_curl', 'nppp_varnish_curl_connect_timeout', 10, 3 );
        try {
            $response = wp_remote_request( $endpoint . $path_query, array(
                'method'                  => 'PURGE',
                'timeout'                 => $timeout,
                'redirection'             => 0,
                'sslverify'               => $sslverify,
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

        $ms = (int) round( ( microtime( true ) - $started ) * 1000 );

        if ( is_wp_error( $response ) ) {
            $out['kind']   = 'unreachable';
            $out['reason'] = nppp_varnish_log_reason( implode( ' | ', (array) $response->get_error_messages() ) );
            nppp_varnish_ep_note( $endpoint, $out['kind'], 0, null, $out['reason'] );
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

        nppp_varnish_ep_note( $endpoint, $out['kind'], $status, $ms, '' );

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
     * The last request can run past it by up to its 1 s minimum timeout (about 6 s worst case).
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

        nppp_varnish_run_count( (string) $result['kind'] );

        switch ( $result['kind'] ) {
            case 'unreachable':
                // Park the endpoint and warn ONCE per breaker window.
                nppp_varnish_breaker_arm( $endpoint );
                if ( nppp_varnish_warn_due( $endpoint, 'unreachable' ) ) {
                    if ( nppp_varnish_only_mode() ) {
                        nppp_varnish_log( sprintf(
                            /* translators: 1: endpoint label, 2: coarse reason, 3: seconds */
                            __( 'WARNING VARNISH: %1$s is unreachable (%2$s). Varnish-only mode: edits are NOT purged anywhere while it is down; retrying in %3$d seconds.', 'fastcgi-cache-purge-and-preload-nginx' ),
                            $label,
                            $result['reason'] !== '' ? $result['reason'] : 'transport error',
                            nppp_varnish_breaker_seconds()
                        ), 'warning' );
                    } else {
                        nppp_varnish_log( sprintf(
                            /* translators: 1: endpoint label, 2: coarse reason */
                            __( 'WARNING VARNISH: %1$s is unreachable (%2$s). Skipping it for 5 minutes; the Nginx purge is not affected.', 'fastcgi-cache-purge-and-preload-nginx' ),
                            $label,
                            $result['reason'] !== '' ? $result['reason'] : 'transport error'
                        ), 'warning' );
                    }
                }
                return 'next_endpoint';

            case 'denied':
                // Configuration problem (ACL, control key, missing PURGE handler): no breaker, same answer for every URL.
                if ( nppp_varnish_warn_due( $endpoint, 'denied' ) ) {
                    nppp_varnish_log( sprintf(
                        nppp_varnish_only_mode()
                            /* translators: 1: endpoint label, 2: HTTP status */
                            ? __( 'WARNING VARNISH: %1$s refused the PURGE request (HTTP %2$d). Varnish-only mode: nothing was purged. Check the VCL purge ACL, the control header and that PURGE is handled in vcl_recv.', 'fastcgi-cache-purge-and-preload-nginx' )
                            /* translators: 1: endpoint label, 2: HTTP status */
                            : __( 'WARNING VARNISH: %1$s refused the PURGE request (HTTP %2$d). Check the VCL purge ACL, the control header and that PURGE is handled in vcl_recv.', 'fastcgi-cache-purge-and-preload-nginx' ),
                        $label,
                        (int) $result['status']
                    ), 'warning' );
                }
                return 'next_endpoint';

            case 'fail':
                if ( nppp_varnish_warn_due( $endpoint, 'fail-' . (int) $result['status'] ) ) {
                    nppp_varnish_log( sprintf(
                        nppp_varnish_only_mode()
                            /* translators: 1: endpoint label, 2: HTTP status */
                            ? __( 'WARNING VARNISH: %1$s answered the PURGE request with HTTP %2$d. Varnish-only mode: the page may still be cached.', 'fastcgi-cache-purge-and-preload-nginx' )
                            /* translators: 1: endpoint label, 2: HTTP status */
                            : __( 'WARNING VARNISH: %1$s answered the PURGE request with HTTP %2$d.', 'fastcgi-cache-purge-and-preload-nginx' ),
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
                nppp_varnish_run_count( 'budget' );
                break;
            }
            if ( nppp_varnish_breaker_open( $endpoint ) ) {
                nppp_varnish_run_count( 'parked' );
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

            nppp_varnish_note_purged_urls( $urls );   // Status tab: Check now asks these again

            $extra    = nppp_varnish_extra_header();
            $deadline = microtime( true ) + nppp_varnish_budget_seconds();
            $skipped_budget = 0;

            foreach ( $endpoints as $index => $endpoint ) {
                if ( nppp_varnish_breaker_open( $endpoint ) ) {
                    nppp_varnish_run_count( 'parked' );
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
                nppp_varnish_run_count( 'budget' );
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
            nppp_varnish_run_count( 'fail' );
            nppp_varnish_log( 'WARNING VARNISH: unexpected error (' . get_class( $e ) . '); ' . nppp_varnish_unexpected_suffix(), 'warning' );
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
            nppp_varnish_run_count( 'fail' );
            nppp_varnish_log( 'WARNING VARNISH: unexpected error (' . get_class( $e ) . '); ' . nppp_varnish_unexpected_suffix(), 'warning' );
        }
    }
}

add_action( 'nppp_purged_urls', 'nppp_varnish_purge_urls', 10, 4 );
add_action( 'nppp_purged_all', 'nppp_varnish_purge_all', 10, 1 );
add_action( 'nppp_preload_all_started', 'nppp_varnish_purge_all', 10, 0 );
add_action( 'admin_notices', 'nppp_varnish_only_admin_notice' );
add_action( 'admin_notices', 'nppp_varnish_only_inactive_notice' );
