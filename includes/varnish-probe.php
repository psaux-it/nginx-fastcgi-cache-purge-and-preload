<?php
/**
 * Varnish / Vinyl Cache read-only lookup for the Advanced tab (Varnish-only mode)
 * Description: Asks Varnish whether a URL is cached right now, without fetching, caching or purging anything.
 * Version: 2.1.8
 * Author: Hasan CALISIR
 * License: GPL-2.0+
 *
 * Wire contract (answered by the probe block of the Example VCL in the Help tab):
 *
 *   NPPPROBE <path[?query]>   Host: <site authority>   <control header>
 *   -> 200, X-NPP-Probe-State: HIT | STALE | MISS | PASS, X-NPP-Probe-Age / X-NPP-Probe-TTL (HIT, STALE)
 *
 * Why a custom method and not GET / HEAD / PURGE with a marker header: a VCL without the probe block
 * must never turn the lookup into a cache fill (GET, HEAD) or a purge (PURGE). An unknown method is
 * never cached by Varnish, so the worst an old VCL can do is pass it to the origin (Varnish 7 and older)
 * or answer 501 (Varnish 8+ / Vinyl). The first answer without the marker therefore stops the batch and
 * is remembered for an hour (see nppp_varnish_probe_batch()).
 *
 * Design rules:
 *  - Read-only and side-channel: a probe problem never changes a purge or preload result.
 *  - Never arms the purge circuit breaker (a slow lookup must not make the next PURGE skip an endpoint),
 *    but a parked endpoint is not probed.
 *  - Only URLs of THIS site, and only request targets that reach Varnish byte for byte. The WordPress
 *    HTTP API rewrites percent escapes, so a URL it would rewrite is reported as unverifiable instead of
 *    being looked up under a different cache key (a false MISS is worse than "unknown").
 *  - The log never contains endpoint addresses or the control header value.
 *
 * @package Nginx Cache Purge Preload
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! function_exists( 'nppp_varnish_probe_max_urls' ) ) {
    /** Most URLs one lookup call accepts (the Advanced tab sends pages of this size). */
    function nppp_varnish_probe_max_urls(): int {
        return 50;
    }
}

if ( ! function_exists( 'nppp_varnish_probe_wire_stable' ) ) {
    /**
     * Does the WordPress HTTP API (Requests IRI normalisation) leave this path / query unchanged?
     *
     * It re-emits every percent escape in UPPER-CASE hex and decodes the escapes of unreserved characters
     * (A-Z a-z 0-9 - . _ ~). A '%' that does not start a valid escape becomes '%25'. A target that changes
     * under this rule would be looked up under another cache key than the one the crawler stored.
     */
    function nppp_varnish_probe_wire_stable( string $s ): bool {
        if ( strpos( $s, '%' ) === false ) {
            return true;
        }

        $norm = preg_replace_callback(
            '/%([0-9A-Fa-f]{2})/',
            static function ( array $m ): string {
                $c = chr( (int) hexdec( $m[1] ) );
                return preg_match( '/^[A-Za-z0-9\-._~]$/', $c ) ? $c : '%' . strtoupper( $m[1] );
            },
            $s
        );

        // A stray '%' (no two hex digits after it) is rewritten to %25 by the client.
        return $norm === $s && preg_match( '/%(?![0-9A-F]{2})/', $s ) === 0;
    }
}

if ( ! function_exists( 'nppp_varnish_probe_target' ) ) {
    /**
     * Request target for one URL.
     *
     * @return array{target:string,reason:string} target is '' when the URL can not be looked up reliably.
     */
    function nppp_varnish_probe_target( string $url ): array {
        $no = static function ( string $reason ): array {
            return array( 'target' => '', 'reason' => $reason );
        };

        $url = trim( $url );
        $p   = ( $url !== '' ) ? wp_parse_url( $url ) : false;

        if ( ! is_array( $p ) || empty( $p['host'] ) ) {
            return $no( 'invalid URL' );
        }

        $scheme = strtolower( (string) ( $p['scheme'] ?? '' ) );
        if ( $scheme !== 'http' && $scheme !== 'https' ) {
            return $no( 'invalid URL' );
        }

        // Scope guard: only URLs of THIS site (host AND port).
        $home = nppp_varnish_authority( home_url() );
        if ( $home === '' || nppp_varnish_authority( $url ) !== $home ) {
            return $no( 'not a URL of this site' );
        }

        if ( preg_match( '/[\x00-\x20\x7F]/', $url ) ) {
            return $no( 'whitespace or control characters' );
        }

        $path  = (string) ( $p['path'] ?? '' );
        $path  = ( $path === '' ) ? '/' : $path;
        $query = (string) ( $p['query'] ?? '' );

        if ( nppp_varnish_path_has_dot_segment( $path ) ) {
            return $no( 'dot segments' );
        }
        if ( strpos( $path, '//' ) !== false ) {
            return $no( 'repeated slashes' );
        }
        if ( preg_match( '/[^A-Za-z0-9\-._~!$&\'()*+,;=:@\/%]/', $path ) ) {
            return $no( 'characters the HTTP client would rewrite' );
        }
        if ( $query !== '' && preg_match( '/[^A-Za-z0-9\-._~!$&\'()*+,;=:@\/?%]/', $query ) ) {
            return $no( 'characters the HTTP client would rewrite' );
        }
        if ( ! nppp_varnish_probe_wire_stable( $path ) || ! nppp_varnish_probe_wire_stable( $query ) ) {
            return $no( 'percent escapes the HTTP client would rewrite' );
        }

        return array( 'target' => $path . ( $query !== '' ? '?' . $query : '' ), 'reason' => '' );
    }
}

if ( ! function_exists( 'nppp_varnish_probe_user_agent' ) ) {
    /** The desktop crawler's User-Agent: a site that varies on it stores the crawler's variant. */
    function nppp_varnish_probe_user_agent(): string {
        return defined( 'NPPP_USER_AGENT' ) ? (string) NPPP_USER_AGENT : 'Mozilla/5.0 (compatible; NPP)';
    }
}

if ( ! function_exists( 'nppp_varnish_probe_request' ) ) {
    /**
     * Sends ONE lookup request. Never throws.
     *
     * @param string $endpoint scheme://host[:port]
     * @param string $target   Request target, e.g. "/hello/?a=1".
     * @param array  $extra    Optional control header (name/value), already validated.
     * @param float  $timeout  Overall timeout in seconds.
     * @return array{kind:string,status:int,state:string,age:?float,ttl:?float,reason:string}
     *         kind: ok | nomarker | denied | fail | unreachable
     */
    function nppp_varnish_probe_request( string $endpoint, string $target, array $extra, float $timeout ): array {
        $out = array( 'kind' => 'fail', 'status' => 0, 'state' => '', 'age' => null, 'ttl' => null, 'reason' => '' );

        $headers = array(
            'Host'            => nppp_varnish_host_header(),
            // The crawler's wget sends identity: the variant it stored is the one to look for.
            'Accept-Encoding' => 'identity',
            'Cache-Control'   => 'no-cache',
        );
        if ( ! empty( $extra['name'] ) && isset( $extra['value'] ) ) {
            $headers[ $extra['name'] ] = $extra['value'];
        }

        /** This filter is documented in includes/compat-varnish.php */
        $sslverify = (bool) apply_filters( 'nppp_varnish_sslverify', false, $endpoint );

        add_action( 'http_api_curl', 'nppp_varnish_curl_connect_timeout', 10, 3 );
        try {
            $response = wp_remote_request( $endpoint . $target, array(
                'method'                  => 'NPPPROBE',
                'timeout'                 => $timeout,
                'redirection'             => 0,
                'sslverify'               => $sslverify,
                'blocking'                => true,
                'limit_response_size'     => 1024,
                'user-agent'              => nppp_varnish_probe_user_agent(),
                'nppp_varnish_connect_ms' => 1000,
                'headers'                 => $headers,
            ) );
        } catch ( \Throwable $e ) {
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

        if ( $status === 200 ) {
            $state = strtoupper( trim( (string) wp_remote_retrieve_header( $response, 'x-npp-probe-state' ) ) );
            if ( in_array( $state, array( 'HIT', 'STALE', 'MISS', 'PASS' ), true ) ) {
                $out['kind']  = 'ok';
                $out['state'] = $state;
                $age          = trim( (string) wp_remote_retrieve_header( $response, 'x-npp-probe-age' ) );
                $ttl          = trim( (string) wp_remote_retrieve_header( $response, 'x-npp-probe-ttl' ) );
                $out['age']   = is_numeric( $age ) ? max( 0.0, (float) $age ) : null;
                $out['ttl']   = is_numeric( $ttl ) ? (float) $ttl : null;
            } else {
                // Answered, but not by the probe block: an old VCL passed the method to the origin.
                $out['kind'] = 'nomarker';
            }
        } elseif ( in_array( $status, array( 401, 403, 405, 501 ), true ) ) {
            // ACL / control key / no handler (Varnish 8+ answers 501 to unknown methods).
            $out['kind'] = 'denied';
        }

        return $out;
    }
}

if ( ! function_exists( 'nppp_varnish_probe_unsupported_seconds' ) ) {
    /** How long an endpoint that answered without the probe marker is not asked again (Check all asks again). */
    function nppp_varnish_probe_unsupported_seconds(): int {
        return HOUR_IN_SECONDS;
    }
}

if ( ! function_exists( 'nppp_varnish_probe_batch' ) ) {
    /**
     * Live status of up to 50 URLs of this site on the first configured, not parked endpoint.
     *
     * The first answer that is not a probe answer ends the batch: asking an old VCL once per URL would pass
     * every lookup to the origin. Its verdict is remembered per endpoint (nppp_state, 1 hour) so that opening
     * the tab again does not repeat it; $force (the "Check all" button) forgets it first.
     *
     * @param string[] $urls  URLs of this site.
     * @param bool     $force Ask again even if the endpoint was marked as not answering the lookup.
     * @return array{status:string,results:array<string,array>}
     *         status: ok | unsupported | denied | unreachable | parked | none
     *         results: url => array{state:string,age:?float,ttl:?float,stale:bool,reason:string}
     *                  state: HIT | STALE | MISS | PASS | UNKNOWN
     */
    function nppp_varnish_probe_batch( array $urls, bool $force = false ): array {
        $result = array( 'status' => 'none', 'results' => array() );

        $unknown = static function ( string $reason ): array {
            return array( 'state' => 'UNKNOWN', 'age' => null, 'ttl' => null, 'stale' => false, 'reason' => $reason );
        };

        try {
            $urls = array_slice( array_values( array_unique( array_filter( $urls, 'is_string' ) ) ), 0, nppp_varnish_probe_max_urls() );
            if ( empty( $urls ) ) {
                return $result;
            }

            // First configured endpoint that is not parked. Several endpoints are several caches; the table shows one.
            $endpoint = '';
            $parked   = false;
            foreach ( nppp_varnish_endpoints() as $candidate ) {
                if ( nppp_varnish_breaker_open( $candidate ) ) {
                    $parked = true;
                    continue;
                }
                $endpoint = $candidate;
                break;
            }
            if ( $endpoint === '' ) {
                $result['status'] = $parked ? 'parked' : 'none';
                foreach ( $urls as $u ) {
                    $result['results'][ $u ] = $unknown( $parked ? 'endpoint parked' : 'no endpoint' );
                }
                return $result;
            }

            $id = nppp_varnish_endpoint_id( $endpoint );
            if ( $force && function_exists( 'nppp_state_get' ) ) {
                $map = nppp_varnish_state_map( 'varnish_probe_off' );
                if ( isset( $map[ $id ] ) ) {
                    unset( $map[ $id ] );
                    if ( empty( $map ) ) {
                        nppp_state_delete( 'varnish_probe_off' );
                    } else {
                        nppp_state_set( 'varnish_probe_off', $map, max( 1, max( $map ) - time() ) );
                    }
                }
            }
            if ( ! $force && isset( nppp_varnish_state_map( 'varnish_probe_off' )[ $id ] ) ) {
                $result['status'] = 'unsupported';
                foreach ( $urls as $u ) {
                    $result['results'][ $u ] = $unknown( 'VCL without the probe block' );
                }
                return $result;
            }

            $extra    = nppp_varnish_extra_header();
            $deadline = microtime( true ) + 10.0;
            $result['status'] = 'ok';
            $stop     = '';   // set by the first answer that ends the batch: unsupported | denied | unreachable

            foreach ( $urls as $u ) {
                if ( $stop !== '' ) {
                    $result['results'][ $u ] = $unknown( 'lookup stopped' );
                    continue;
                }
                if ( microtime( true ) >= $deadline ) {
                    $result['results'][ $u ] = $unknown( 'time budget used up' );
                    continue;
                }

                $t = nppp_varnish_probe_target( $u );
                if ( $t['target'] === '' ) {
                    $result['results'][ $u ] = $unknown( $t['reason'] );
                    continue;
                }

                $r = nppp_varnish_probe_request( $endpoint, $t['target'], $extra, nppp_varnish_request_timeout( $deadline ) );

                if ( $r['kind'] === 'ok' ) {
                    $result['results'][ $u ] = array(
                        'state'  => $r['state'],
                        'age'    => $r['age'],
                        'ttl'    => $r['ttl'],
                        'stale'  => $r['state'] === 'STALE',
                        'reason' => '',
                    );
                    continue;
                }

                // Anything else ends the batch: the answer is the same for every URL.
                $label = nppp_varnish_endpoint_label( 0 );
                if ( $r['kind'] === 'nomarker' ) {
                    $stop = 'unsupported';
                    nppp_varnish_state_map_set( 'varnish_probe_off', $id, nppp_varnish_probe_unsupported_seconds() );
                    if ( nppp_varnish_warn_due( $endpoint, 'probe-nomarker', 3600 ) ) {
                        nppp_varnish_log( sprintf(
                            /* translators: %s: endpoint label */
                            __( 'INFO VARNISH: %s did not answer the live status lookup (the VCL has no probe block). The Advanced tab shows the last crawl only; add the probe block of the Example VCL (Help tab) for real HIT / MISS.', 'fastcgi-cache-purge-and-preload-nginx' ),
                            $label
                        ), 'info' );
                    }
                } elseif ( $r['kind'] === 'denied' ) {
                    $stop = 'denied';
                    if ( nppp_varnish_warn_due( $endpoint, 'probe-denied' ) ) {
                        nppp_varnish_log( sprintf(
                            /* translators: 1: endpoint label, 2: HTTP status */
                            __( 'WARNING VARNISH: %1$s refused the live status lookup (HTTP %2$d). Check the VCL purge ACL, the control header and the probe block (Help tab).', 'fastcgi-cache-purge-and-preload-nginx' ),
                            $label,
                            (int) $r['status']
                        ), 'warning' );
                    }
                } elseif ( $r['kind'] === 'unreachable' ) {
                    $stop = 'unreachable';
                    if ( nppp_varnish_warn_due( $endpoint, 'probe-unreachable' ) ) {
                        nppp_varnish_log( sprintf(
                            /* translators: 1: endpoint label, 2: coarse reason */
                            __( 'WARNING VARNISH: %1$s is unreachable for the live status lookup (%2$s).', 'fastcgi-cache-purge-and-preload-nginx' ),
                            $label,
                            $r['reason'] !== '' ? $r['reason'] : 'transport error'
                        ), 'warning' );
                    }
                } else {
                    // Another HTTP status: report it for this URL only.
                    $result['results'][ $u ] = $unknown( 'HTTP ' . (int) $r['status'] );
                    continue;
                }

                $result['status']        = $stop;
                $result['results'][ $u ] = $unknown( $stop === 'unsupported' ? 'VCL without the probe block' : $stop );
            }
        } catch ( \Throwable $e ) {
            nppp_varnish_log( 'WARNING VARNISH: unexpected error in the live status lookup (' . get_class( $e ) . ').', 'warning' );
            $result['status'] = ( $result['status'] === 'none' ) ? 'unreachable' : $result['status'];
            foreach ( $urls ?? array() as $u ) {
                if ( ! isset( $result['results'][ $u ] ) ) {
                    $result['results'][ $u ] = $unknown( 'unexpected error' );
                }
            }
        }

        return $result;
    }
}
