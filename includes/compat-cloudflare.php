<?php
/**
 * Cloudflare APO compatibility for Nginx Cache Purge Preload
 * Description: Mirrors plugin purge actions to Cloudflare (APO / Plugin-Specific Cache) so the edge
 *              cache stays synchronized with the Nginx cache.
 * Version: 2.1.8
 * Author: Hasan CALISIR
 * License: GPL-2.0+
 *
 * Architecture (rewrite; the public surface of the previous v2.1.8 is unchanged):
 *
 *  - Order rule: the hooks used here fire AFTER Nginx (and the inner Redis / Varnish layers, priority 10)
 *    are clean and BEFORE any preload crawl starts. The Cloudflare layer is the outermost one, so its
 *    listeners run at priority 20. The purge is synchronous with a hard time budget; there is no
 *    deferred queue that could run after the crawl.
 *
 *      nppp_purged_urls   single purge, after nginx, before auto-preload
 *      nppp_purged_all    Purge All, after nginx (may be deferred while nginx's queue is busy)
 *
 *    nppp_preload_all_started is deliberately NOT used: a direct Preload All does not purge Cloudflare.
 *
 *  - The official Cloudflare plugin is used for credentials, settings and the zone name only. HTTP is done
 *    here (wp_remote_request) so that the status code, Retry-After and the Cloudflare error body are
 *    available. Auth headers come from the official client (Client::beforeSend()), so API-token and
 *    Global-API-Key detection is never copied. Both plugin namespaces are supported
 *    (Cloudflare\APO\ for 4.14+, CF\ for 4.12.8 - 4.13.x); the new one is tried first.
 *
 *  - Every response is classified (ok, rate, server, network, auth, not_found, rejected). A purge that
 *    fails for a retryable reason (429, 5xx, network) or that arrives while the API is parked is NEVER
 *    lost: it is kept in the "cf_pending" state row and retried by ONE cron event
 *    (NPPP_CF_APO_RETRY_HOOK) or merged into the next listener run.
 *
 *  - No user setting is ever written here. nppp_cloudflare_apo_status() reports what is going on.
 *
 *  - Cloudflare's own purge hooks are never touched (a double purge is accepted).
 *
 * @package Nginx Cache Purge Preload
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! defined( 'NPPP_CF_APO_RETRY_HOOK' ) ) {
    define( 'NPPP_CF_APO_RETRY_HOOK', 'nppp_cloudflare_apo_retry_event' );
}
if ( ! defined( 'NPPP_CF_APO_MAX_TRIES' ) ) {
    // Failed attempts before a pending purge is dropped with an error line (about 2.5 h with the backoff).
    define( 'NPPP_CF_APO_MAX_TRIES', 6 );
}
if ( ! defined( 'NPPP_CF_APO_MAX_PENDING_URLS' ) ) {
    // Above this many pending URLs the pending set collapses into one purge_everything.
    define( 'NPPP_CF_APO_MAX_PENDING_URLS', 2000 );
}

/* ============================================================================
 * 1. Detection (both Cloudflare plugin namespaces)
 * ========================================================================= */

if ( ! function_exists( 'nppp_cloudflare_apo_ns' ) ) {
    /**
     * Namespace prefix of the installed official Cloudflare plugin: 'Cloudflare\APO\' (4.14+),
     * 'CF\' (4.12.8 - 4.13.x) or '' (not installed). The new namespace is tried first: 4.14.x also
     * ships deprecated CF\ classes, and instantiating those raises a deprecation notice.
     * Only a positive answer is remembered (the plugin may load after the first call).
     */
    function nppp_cloudflare_apo_ns(): string {
        static $found = '';

        if ( $found !== '' ) {
            return $found;
        }

        if ( ! defined( 'CLOUDFLARE_PLUGIN_DIR' ) ) {
            return '';
        }

        if ( class_exists( '\\Cloudflare\\APO\\WordPress\\Hooks' ) ) {
            $found = 'Cloudflare\\APO\\';
        } elseif ( class_exists( '\\CF\\WordPress\\Hooks' ) ) {
            $found = 'CF\\';
        }

        return $found;
    }
}

if ( ! function_exists( 'nppp_cloudflare_apo_cls' ) ) {
    /**
     * Fully qualified class name (no leading backslash) for a class path relative to the plugin namespace.
     */
    function nppp_cloudflare_apo_cls( string $relative ): string {
        return nppp_cloudflare_apo_ns() . $relative;
    }
}

if ( ! function_exists( 'nppp_cloudflare_apo_is_available' ) ) {
    function nppp_cloudflare_apo_is_available(): bool {
        return nppp_cloudflare_apo_ns() !== '' && defined( 'CLOUDFLARE_PLUGIN_DIR' );
    }
}

if ( ! function_exists( 'nppp_cloudflare_apo_supported' ) ) {
    /**
     * True when the installed Cloudflare plugin exposes everything this file needs. A future release that
     * changes the constructor / beforeSend() shape yields "unsupported", never a fatal error.
     */
    function nppp_cloudflare_apo_supported(): bool {
        if ( ! nppp_cloudflare_apo_is_available() ) {
            return false;
        }

        $needed = array(
            'Integration\\DefaultConfig',
            'Integration\\DefaultLogger',
            'Integration\\DefaultIntegration',
            'WordPress\\DataStore',
            'WordPress\\WordPressAPI',
            'WordPress\\WordPressClientAPI',
            'API\\Request',
        );
        foreach ( $needed as $rel ) {
            if ( ! class_exists( nppp_cloudflare_apo_cls( $rel ) ) ) {
                return false;
            }
        }

        if ( ! method_exists( nppp_cloudflare_apo_cls( 'WordPress\\WordPressClientAPI' ), 'beforeSend' ) ) {
            return false;
        }

        return is_readable( trailingslashit( CLOUDFLARE_PLUGIN_DIR ) . 'config.json' );
    }
}

if ( ! function_exists( 'nppp_cloudflare_apo_log' ) ) {
    function nppp_cloudflare_apo_log( string $message, string $type = 'info' ): void {
        if ( function_exists( 'nppp_display_admin_notice' ) ) {
            nppp_display_admin_notice( $type, $message, true, false );
        }
    }
}

/* ============================================================================
 * 2. Store / auth (read only access to the official plugin)
 * ========================================================================= */

if ( ! function_exists( 'nppp_cloudflare_apo_runtime' ) ) {
    /**
     * Objects of the official plugin used for reading credentials / settings and for building auth headers.
     * Nothing here talks to the network. Only a success is cached for the request.
     *
     * @return array{client:object,store:object,wp_api:object}|null
     */
    function nppp_cloudflare_apo_runtime(): ?array {
        static $rt = null;

        if ( is_array( $rt ) ) {
            return $rt;
        }

        if ( ! nppp_cloudflare_apo_supported() ) {
            return null;
        }

        try {
            $raw = file_get_contents( trailingslashit( CLOUDFLARE_PLUGIN_DIR ) . 'config.json' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
            if ( ! is_string( $raw ) || $raw === '' ) {
                return null;
            }

            $c_config = nppp_cloudflare_apo_cls( 'Integration\\DefaultConfig' );
            $c_logger = nppp_cloudflare_apo_cls( 'Integration\\DefaultLogger' );
            $c_integ  = nppp_cloudflare_apo_cls( 'Integration\\DefaultIntegration' );
            $c_store  = nppp_cloudflare_apo_cls( 'WordPress\\DataStore' );
            $c_wpapi  = nppp_cloudflare_apo_cls( 'WordPress\\WordPressAPI' );
            $c_client = nppp_cloudflare_apo_cls( 'WordPress\\WordPressClientAPI' );

            $config      = new $c_config( $raw );
            $logger      = new $c_logger( $config->getValue( 'debug' ) );
            $store       = new $c_store( $logger );
            $wp_api      = new $c_wpapi( $store );
            $integration = new $c_integ( $config, $wp_api, $store, $logger );
            $client      = new $c_client( $integration );
        } catch ( \Throwable $e ) {
            return null;
        }

        $rt = array(
            'client' => $client,
            'store'  => $store,
            'wp_api' => $wp_api,
        );

        return $rt;
    }
}

if ( ! function_exists( 'nppp_cloudflare_apo_has_credentials' ) ) {
    function nppp_cloudflare_apo_has_credentials( array $rt ): bool {
        try {
            $key = $rt['store']->getClientV4APIKey();
        } catch ( \Throwable $e ) {
            return false;
        }

        return is_string( $key ) && trim( $key ) !== '';
    }
}

if ( ! function_exists( 'nppp_cloudflare_apo_credential_fingerprint' ) ) {
    /**
     * Short, non-reversible id of "which credentials + which site" a cached zone belongs to. A changed
     * token / e-mail / site address invalidates the zone cache without the secret ever being stored.
     */
    function nppp_cloudflare_apo_credential_fingerprint( array $rt ): string {
        $key   = '';
        $email = '';
        try {
            $key = (string) $rt['store']->getClientV4APIKey();
            if ( method_exists( $rt['store'], 'getCloudFlareEmail' ) ) {
                $email = (string) $rt['store']->getCloudFlareEmail();
            }
        } catch ( \Throwable $e ) {
            $key = '';
        }

        $home = (string) wp_parse_url( home_url(), PHP_URL_HOST );

        return substr( md5( $key . '|' . $email . '|' . strtolower( $home ) ), 0, 12 );
    }
}

if ( ! function_exists( 'nppp_cloudflare_apo_setting_on' ) ) {
    /**
     * Same semantics as the official plugin (Hooks::isAutomaticPlatformOptimizationEnabled() and
     * friends): the setting object must exist, and its "value" is on unless it is false or 'off'.
     * A missing "value" key counts as ON there, so it does here.
     */
    function nppp_cloudflare_apo_setting_on( array $rt, string $setting_id ): bool {
        try {
            $setting = $rt['store']->getPluginSetting( $setting_id );
        } catch ( \Throwable $e ) {
            return false;
        }

        if ( ! is_array( $setting ) || empty( $setting ) ) {
            return false;
        }

        $value = array_key_exists( 'value', $setting ) ? $setting['value'] : null;

        return $value !== false && $value !== 'off';
    }
}

if ( ! function_exists( 'nppp_cloudflare_apo_is_html_cache_enabled' ) ) {
    /**
     * Only purge the managed HTML cache when APO or Plugin-Specific Cache is on (as the official plugin does).
     */
    function nppp_cloudflare_apo_is_html_cache_enabled( array $rt ): bool {
        return nppp_cloudflare_apo_setting_on( $rt, 'automatic_platform_optimization' )
            || nppp_cloudflare_apo_setting_on( $rt, 'plugin_specific_cache' );
    }
}

if ( ! function_exists( 'nppp_cloudflare_apo_device_type_enabled' ) ) {
    function nppp_cloudflare_apo_device_type_enabled( array $rt ): bool {
        return nppp_cloudflare_apo_setting_on( $rt, 'automatic_platform_optimization_cache_by_device_type' );
    }
}

if ( ! function_exists( 'nppp_cloudflare_apo_option_on' ) ) {
    /**
     * The user's NPP setting. Read only; this file never writes it.
     */
    function nppp_cloudflare_apo_option_on(): bool {
        $options = get_option( 'nginx_cache_settings', array() );

        return is_array( $options )
            && isset( $options['nppp_cloudflare_apo_sync'] )
            && $options['nppp_cloudflare_apo_sync'] === 'yes';
    }
}

/* ============================================================================
 * 3. State helpers (option-backed nppp_state_*, survives wp_cache_flush())
 * ========================================================================= */

if ( ! function_exists( 'nppp_cloudflare_apo_state_get' ) ) {
    function nppp_cloudflare_apo_state_get( string $key, bool $fresh = false ) {
        return function_exists( 'nppp_state_get' ) ? nppp_state_get( $key, $fresh ) : null;
    }
}

if ( ! function_exists( 'nppp_cloudflare_apo_state_set' ) ) {
    function nppp_cloudflare_apo_state_set( string $key, $value, int $ttl = 0 ): void {
        if ( function_exists( 'nppp_state_set' ) ) {
            nppp_state_set( $key, $value, $ttl );
        }
    }
}

if ( ! function_exists( 'nppp_cloudflare_apo_state_delete' ) ) {
    function nppp_cloudflare_apo_state_delete( string $key ): void {
        if ( function_exists( 'nppp_state_delete' ) ) {
            nppp_state_delete( $key );
        }
    }
}

if ( ! function_exists( 'nppp_cloudflare_apo_reset_state' ) ) {
    /**
     * Forgets the park window, the warning throttle and the "no zone" memo. Called from the same places
     * that reset the Varnish breaker (settings toggle, WP-CLI, Status, sanitize) so a fixed setup is retried
     * immediately. The pending purges and the resolved zone are kept.
     */
    function nppp_cloudflare_apo_reset_state(): void {
        nppp_cloudflare_apo_local_park( 0, true );
        foreach ( array( 'cf_park', 'cf_warn', 'cf_zone_miss' ) as $key ) {
            nppp_cloudflare_apo_state_delete( $key );
        }
    }
}

if ( ! function_exists( 'nppp_cloudflare_apo_local_park' ) ) {
    /**
     * Per-request memo of the park deadline: the breaker keeps working inside one request even when
     * the state store is unavailable.
     */
    function nppp_cloudflare_apo_local_park( int $until = 0, bool $clear = false ): int {
        static $local = 0;

        if ( $clear ) {
            $local = 0;
        } elseif ( $until > $local ) {
            $local = $until;
        }

        return $local;
    }
}

if ( ! function_exists( 'nppp_cloudflare_apo_park_until' ) ) {
    /**
     * Unix time until which the API is parked (0 = not parked).
     */
    function nppp_cloudflare_apo_park_until(): int {
        $until = nppp_cloudflare_apo_local_park();
        $row   = nppp_cloudflare_apo_state_get( 'cf_park' );
        if ( is_int( $row ) && $row > $until ) {
            $until = $row;
        }

        return $until > time() ? $until : 0;
    }
}

if ( ! function_exists( 'nppp_cloudflare_apo_park' ) ) {
    function nppp_cloudflare_apo_park( int $seconds ): void {
        $seconds = max( 1, min( 3600, $seconds ) );
        $until   = time() + $seconds;

        nppp_cloudflare_apo_local_park( $until );
        nppp_cloudflare_apo_state_set( 'cf_park', $until, $seconds );
    }
}

if ( ! function_exists( 'nppp_cloudflare_apo_warn_due' ) ) {
    /**
     * Throttle: true at most once per $seconds for the same problem kind.
     */
    function nppp_cloudflare_apo_warn_due( string $kind, int $seconds = 300 ): bool {
        $id  = substr( md5( $kind ), 0, 10 );
        $now = time();
        $raw = nppp_cloudflare_apo_state_get( 'cf_warn' );
        $map = array();

        if ( is_array( $raw ) ) {
            foreach ( $raw as $k => $until ) {
                if ( is_string( $k ) && is_int( $until ) && $until > $now ) {
                    $map[ $k ] = $until;
                }
            }
        }

        if ( isset( $map[ $id ] ) ) {
            return false;
        }

        $map[ $id ] = $now + $seconds;
        nppp_cloudflare_apo_state_set( 'cf_warn', $map, max( 1, max( $map ) - $now ) );

        return true;
    }
}

if ( ! function_exists( 'nppp_cloudflare_apo_note_last' ) ) {
    /**
     * Remembers the outcome of the last purge for the Status API / WP-CLI. No secrets, no URLs.
     *
     * @param array $info kind, status, code, ctx ('urls'|'all'|'retry'), n (URL count)
     */
    function nppp_cloudflare_apo_note_last( bool $ok, array $info ): void {
        nppp_cloudflare_apo_state_set(
            'cf_last',
            array(
                't'      => time(),
                'ok'     => $ok,
                'kind'   => isset( $info['kind'] ) ? (string) $info['kind'] : '',
                'status' => isset( $info['status'] ) ? (int) $info['status'] : 0,
                'code'   => isset( $info['code'] ) ? (int) $info['code'] : 0,
                'ctx'    => isset( $info['ctx'] ) ? (string) $info['ctx'] : '',
                'n'      => isset( $info['n'] ) ? (int) $info['n'] : 0,
            ),
            30 * DAY_IN_SECONDS
        );
    }
}

/* ============================================================================
 * 4. Pending purges (retry set) and the single retry cron event
 * ========================================================================= */

if ( ! function_exists( 'nppp_cloudflare_apo_pending_read' ) ) {
    /**
     * @return array{all:bool,urls:string[],tries:int,since:int}|null
     */
    function nppp_cloudflare_apo_pending_read(): ?array {
        $row = nppp_cloudflare_apo_state_get( 'cf_pending', true );
        if ( ! is_array( $row ) ) {
            return null;
        }

        $urls = array();
        if ( isset( $row['urls'] ) && is_array( $row['urls'] ) ) {
            foreach ( $row['urls'] as $u ) {
                if ( is_string( $u ) && $u !== '' ) {
                    $urls[] = $u;
                }
            }
        }

        $all = ! empty( $row['all'] );
        if ( ! $all && empty( $urls ) ) {
            return null;
        }

        return array(
            'all'   => $all,
            'urls'  => $urls,
            'tries' => isset( $row['tries'] ) ? max( 0, (int) $row['tries'] ) : 0,
            'since' => isset( $row['since'] ) ? (int) $row['since'] : time(),
        );
    }
}

if ( ! function_exists( 'nppp_cloudflare_apo_pending_add' ) ) {
    /**
     * Adds a purge to the pending set. purge_everything absorbs all URLs; too many URLs collapse into it.
     */
    function nppp_cloudflare_apo_pending_add( bool $all, array $urls, int $tries ): void {
        $cur     = nppp_cloudflare_apo_pending_read();
        $all_new = $all || ( $cur !== null && $cur['all'] );
        $set     = array();

        if ( ! $all_new ) {
            $merged = $cur !== null ? array_merge( $cur['urls'], $urls ) : $urls;
            foreach ( $merged as $u ) {
                if ( is_string( $u ) && $u !== '' ) {
                    $set[ $u ] = true;
                }
            }
            if ( count( $set ) > NPPP_CF_APO_MAX_PENDING_URLS ) {
                $all_new = true;
                $set     = array();
            }
        }

        nppp_cloudflare_apo_state_set(
            'cf_pending',
            array(
                'all'   => $all_new,
                'urls'  => array_map( 'strval', array_keys( $set ) ),
                'tries' => max( $cur !== null ? $cur['tries'] : 0, $tries ),
                'since' => $cur !== null ? $cur['since'] : time(),
            ),
            DAY_IN_SECONDS
        );
    }
}

if ( ! function_exists( 'nppp_cloudflare_apo_pending_claim' ) ) {
    /**
     * Takes the pending set: exactly one caller gets it, everybody else null. The DELETE is the lock
     * (one row affected for the winner, zero for the loser), the same pattern as
     * nppp_http_purge_deferred_claim().
     *
     * @return array{all:bool,urls:string[],tries:int,since:int}|null
     */
    function nppp_cloudflare_apo_pending_claim(): ?array {
        if ( ! function_exists( 'nppp_state_option_name' ) ) {
            return null;
        }

        $pending = nppp_cloudflare_apo_pending_read();
        if ( $pending === null ) {
            return null;
        }

        global $wpdb;
        $name = nppp_state_option_name( 'cf_pending' );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- atomic single-use claim
        $deleted = $wpdb->delete( $wpdb->options, array( 'option_name' => $name ), array( '%s' ) );

        wp_cache_delete( $name, 'options' );
        wp_cache_delete( 'notoptions', 'options' );

        return ( (int) $deleted === 1 ) ? $pending : null;
    }
}

if ( ! function_exists( 'nppp_cloudflare_apo_retry_delay' ) ) {
    /**
     * Backoff in seconds before the delivery attempt that follows failed attempt number $tries.
     */
    function nppp_cloudflare_apo_retry_delay( int $tries ): int {
        $steps = array( 60, 60, 300, 900, 3600, 3600 );

        return $steps[ max( 0, min( count( $steps ) - 1, $tries ) ) ];
    }
}

if ( ! function_exists( 'nppp_cloudflare_apo_schedule_retry' ) ) {
    /**
     * Schedules THE retry event: at most one exists at any time. If one is already waiting for a much
     * later time than needed (a long backoff, then a fresh failure), it is moved earlier.
     */
    function nppp_cloudflare_apo_schedule_retry( int $delay ): void {
        $want = time() + max( 15, min( 3600, $delay ) );
        $next = wp_next_scheduled( NPPP_CF_APO_RETRY_HOOK );

        if ( $next !== false ) {
            if ( (int) $next <= $want + 30 ) {
                return;
            }
            wp_unschedule_event( (int) $next, NPPP_CF_APO_RETRY_HOOK );
        }

        wp_schedule_single_event( $want, NPPP_CF_APO_RETRY_HOOK );
    }
}

if ( ! function_exists( 'nppp_cloudflare_apo_defer' ) ) {
    /**
     * Keeps a purge that could not be sent. $count_try is false for a purge that only waited (parked,
     * budget used up): that is not a failed attempt.
     */
    function nppp_cloudflare_apo_defer( bool $all, array $urls, int $tries, int $delay, bool $count_try ): void {
        if ( $count_try ) {
            $tries++;
            $delay = max( $delay, nppp_cloudflare_apo_retry_delay( $tries ) );
        }

        if ( $tries >= NPPP_CF_APO_MAX_TRIES ) {
            nppp_cloudflare_apo_log(
                sprintf(
                    /* translators: %d: number of attempts */
                    __( 'Cloudflare cache purge gave up after %d attempts; the pending purge was dropped.', 'fastcgi-cache-purge-and-preload-nginx' ),
                    (int) $tries
                ),
                'error'
            );
            return;
        }

        nppp_cloudflare_apo_pending_add( $all, $urls, $tries );
        nppp_cloudflare_apo_schedule_retry( $delay );
    }
}

/* ============================================================================
 * 5. Host / URL planning
 * ========================================================================= */

if ( ! function_exists( 'nppp_cloudflare_apo_normalize_host' ) ) {
    /**
     * Lower-case ASCII (punycode) host without trailing dot, or '' when it is not a valid hostname.
     */
    function nppp_cloudflare_apo_normalize_host( string $host ): string {
        $host = strtolower( trim( $host ) );
        $host = rtrim( $host, '.' );

        if ( $host === '' ) {
            return '';
        }

        if ( preg_match( '/[^\x00-\x7F]/', $host ) ) {
            $ascii = null;
            if ( function_exists( 'nppp_idn_to_ascii_host' ) ) {
                $ascii = nppp_idn_to_ascii_host( $host );
            } elseif ( function_exists( 'idn_to_ascii' ) ) {
                $ascii = defined( 'INTL_IDNA_VARIANT_UTS46' )
                    ? idn_to_ascii( $host, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46 )
                    : idn_to_ascii( $host, IDNA_DEFAULT );
            }
            if ( ! is_string( $ascii ) || $ascii === '' ) {
                return '';
            }
            $host = strtolower( $ascii );
        }

        return preg_match( '/^[a-z0-9]([a-z0-9._-]*[a-z0-9])?$/', $host ) ? $host : '';
    }
}

if ( ! function_exists( 'nppp_cloudflare_apo_host_in_zone' ) ) {
    /**
     * True if host is exactly the zone name or a real subdomain of it (strict '.' boundary, so
     * "notexample.com" is NOT in "example.com", and "example.com.example.com" IS).
     */
    function nppp_cloudflare_apo_host_in_zone( string $host, string $zone_base ): bool {
        $host      = nppp_cloudflare_apo_normalize_host( $host );
        $zone_base = nppp_cloudflare_apo_normalize_host( $zone_base );

        if ( $host === '' || $zone_base === '' ) {
            return false;
        }

        if ( $host === $zone_base ) {
            return true;
        }

        $suffix = '.' . $zone_base;
        $len    = strlen( $suffix );

        return strlen( $host ) > $len && substr( $host, -$len ) === $suffix;
    }
}

if ( ! function_exists( 'nppp_cloudflare_apo_normalize_url' ) ) {
    /**
     * The URL as NPP gave it, with only the host lower-cased / punycoded. Path and query are kept
     * byte-for-byte. Returns null for anything that is not an http(s) URL of the zone.
     * (Own validation: wp_http_validate_url() resolves DNS and silently drops hosts that do not resolve.)
     */
    function nppp_cloudflare_apo_normalize_url( $url, string $zone_name ): ?string {
        if ( ! is_string( $url ) ) {
            return null;
        }

        $url = trim( $url );
        if ( $url === '' || preg_match( '/[\x00-\x20\x7f]/', $url ) ) {
            return null;
        }

        $p = wp_parse_url( $url );
        if ( ! is_array( $p ) || empty( $p['scheme'] ) || empty( $p['host'] ) ) {
            return null;
        }

        $scheme = strtolower( (string) $p['scheme'] );
        if ( $scheme !== 'http' && $scheme !== 'https' ) {
            return null;
        }

        if ( isset( $p['user'] ) || isset( $p['pass'] ) ) {
            return null;
        }

        $host = nppp_cloudflare_apo_normalize_host( (string) $p['host'] );
        if ( $host === '' || ! nppp_cloudflare_apo_host_in_zone( $host, $zone_name ) ) {
            return null;
        }

        $out  = $scheme . '://' . $host;
        $out .= isset( $p['port'] ) ? ':' . (int) $p['port'] : '';
        $out .= isset( $p['path'] ) && $p['path'] !== '' ? $p['path'] : '/';
        $out .= isset( $p['query'] ) && $p['query'] !== '' ? '?' . $p['query'] : '';

        return $out;
    }
}

if ( ! function_exists( 'nppp_cloudflare_apo_plan_urls' ) ) {
    /**
     * @return array{urls:string[],skipped:int} Valid, de-duplicated URLs of the zone, and how many inputs were not.
     */
    function nppp_cloudflare_apo_plan_urls( array $urls, string $zone_name ): array {
        $seen    = array();
        $skipped = 0;

        foreach ( $urls as $url ) {
            $n = nppp_cloudflare_apo_normalize_url( $url, $zone_name );
            if ( $n === null ) {
                $skipped++;
                continue;
            }
            $seen[ $n ] = true;
        }

        return array(
            'urls'    => array_map( 'strval', array_keys( $seen ) ),
            'skipped' => $skipped,
        );
    }
}

if ( ! function_exists( 'nppp_cloudflare_apo_chunk_size' ) ) {
    /**
     * Cloudflare accepts 100 files per purge request (500 on Enterprise).
     */
    function nppp_cloudflare_apo_chunk_size(): int {
        $size = (int) apply_filters( 'nppp_cloudflare_chunk_size', 100 );

        return max( 1, min( 500, $size ) );
    }
}

/* ============================================================================
 * 6. Transport
 * ========================================================================= */

if ( ! function_exists( 'nppp_cloudflare_apo_endpoint' ) ) {
    function nppp_cloudflare_apo_endpoint(): string {
        return 'https://api.cloudflare.com/client/v4/';
    }
}

if ( ! function_exists( 'nppp_cloudflare_apo_budget_seconds' ) ) {
    /**
     * Total time budget of ONE listener run: a hanging API must not stall the editor's save.
     * Cron / WP-CLI have nobody waiting, so they get more. The last request can run past it by up
     * to its 1 s minimum timeout.
     */
    function nppp_cloudflare_apo_budget_seconds(): float {
        $background = ( function_exists( 'wp_doing_cron' ) && wp_doing_cron() )
            || ( defined( 'WP_CLI' ) && WP_CLI );

        return $background ? 20.0 : 8.0;
    }
}

if ( ! function_exists( 'nppp_cloudflare_apo_request_timeout' ) ) {
    /**
     * Per-request timeout: 5 s, shrunk to what is left of the budget (at least 1 s).
     */
    function nppp_cloudflare_apo_request_timeout( float $deadline ): float {
        return max( 1.0, min( 5.0, $deadline - microtime( true ) ) );
    }
}

if ( ! function_exists( 'nppp_cloudflare_apo_curl_connect_timeout' ) ) {
    /**
     * http_api_curl callback: 2 s connect timeout for OUR requests only (WordPress otherwise sets
     * CONNECTTIMEOUT to the overall timeout). Keyed on our own request argument.
     *
     * @param resource|object $handle
     * @param array           $args
     */
    function nppp_cloudflare_apo_curl_connect_timeout( $handle, $args = array() ): void {
        if ( ! is_array( $args ) || empty( $args['nppp_cf_connect_ms'] ) ) {
            return;
        }
        if ( ! is_resource( $handle ) && ! is_object( $handle ) ) {
            return;
        }
        if ( defined( 'CURLOPT_CONNECTTIMEOUT_MS' ) ) {
            @curl_setopt( $handle, CURLOPT_CONNECTTIMEOUT_MS, (int) $args['nppp_cf_connect_ms'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_setopt
        }
    }
}

if ( ! function_exists( 'nppp_cloudflare_apo_log_reason' ) ) {
    /**
     * Coarse, log-safe reason for a transport failure (raw cURL text can hold internal hosts / IPs).
     */
    function nppp_cloudflare_apo_log_reason( string $message ): string {
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

if ( ! function_exists( 'nppp_cloudflare_apo_retry_after' ) ) {
    /**
     * Retry-After header: delta-seconds or an HTTP date. 0 when absent / unusable.
     */
    function nppp_cloudflare_apo_retry_after( $header ): int {
        if ( is_array( $header ) ) {
            $header = reset( $header );
        }
        if ( ! is_string( $header ) || trim( $header ) === '' ) {
            return 0;
        }

        $header = trim( $header );
        if ( ctype_digit( $header ) ) {
            return max( 0, min( 3600, (int) $header ) );
        }

        $ts = strtotime( $header );
        if ( $ts === false ) {
            return 0;
        }

        return max( 0, min( 3600, $ts - time() ) );
    }
}

if ( ! function_exists( 'nppp_cloudflare_apo_send' ) ) {
    /**
     * Sends ONE Cloudflare API request. Never throws, never logs.
     *
     * kind: ok | rate (429) | server (5xx) | network | auth (401/403) | not_found | rejected | internal
     *
     * @param array      $rt      nppp_cloudflare_apo_runtime()
     * @param string     $method  GET | POST
     * @param string     $path    Relative to the v4 endpoint, e.g. "zones/<id>/purge_cache"
     * @param array|null $body    JSON body (POST) or null
     * @param array      $query   Query parameters
     * @param float      $timeout Overall timeout in seconds
     * @return array{kind:string,status:int,code:int,message:string,retry_after:int,result:mixed}
     */
    function nppp_cloudflare_apo_send( array $rt, string $method, string $path, ?array $body, array $query, float $timeout ): array {
        $out = array(
            'kind'        => 'network',
            'status'      => 0,
            'code'        => 0,
            'message'     => '',
            'retry_after' => 0,
            'result'      => null,
        );

        // Auth headers come from the official client (it knows Global API Key vs API token).
        try {
            $c_request = nppp_cloudflare_apo_cls( 'API\\Request' );
            $request   = new $c_request( $method, $path, array(), $body );
            $request   = $rt['client']->beforeSend( $request );
            $headers   = ( is_object( $request ) && method_exists( $request, 'getHeaders' ) ) ? $request->getHeaders() : array();
        } catch ( \Throwable $e ) {
            $out['kind'] = 'internal';
            return $out;
        }

        if ( ! is_array( $headers ) ) {
            $out['kind'] = 'internal';
            return $out;
        }

        $has_auth = false;
        foreach ( $headers as $name => $value ) {
            $lname = strtolower( (string) $name );
            if ( ( $lname === 'authorization' || $lname === 'x-auth-key' ) && is_string( $value ) && trim( $value ) !== '' ) {
                $has_auth = true;
                break;
            }
        }
        if ( ! $has_auth ) {
            $out['kind'] = 'auth';
            return $out;
        }

        $url = nppp_cloudflare_apo_endpoint() . ltrim( $path, '/' );
        if ( ! empty( $query ) ) {
            $url .= '?' . http_build_query( $query, '', '&', PHP_QUERY_RFC3986 );
        }

        $args = array(
            'method'              => $method,
            'timeout'             => $timeout,
            'redirection'         => 0,
            'sslverify'           => true,
            'blocking'            => true,
            'limit_response_size' => 262144,
            'nppp_cf_connect_ms'  => 2000,
            'headers'             => $headers,
        );
        if ( $body !== null ) {
            $args['body'] = wp_json_encode( $body );
        }

        add_action( 'http_api_curl', 'nppp_cloudflare_apo_curl_connect_timeout', 10, 3 );
        try {
            $response = wp_remote_request( $url, $args );
        } catch ( \Throwable $e ) {
            // A misbehaving http_api_* filter or transport must not abort the caller.
            $response = new WP_Error( 'nppp_cf_exception', get_class( $e ) );
        } finally {
            remove_action( 'http_api_curl', 'nppp_cloudflare_apo_curl_connect_timeout', 10 );
        }

        if ( is_wp_error( $response ) ) {
            $out['message'] = nppp_cloudflare_apo_log_reason( implode( ' | ', (array) $response->get_error_messages() ) );
            return $out;
        }

        $status        = (int) wp_remote_retrieve_response_code( $response );
        $out['status'] = $status;
        $json          = json_decode( (string) wp_remote_retrieve_body( $response ), true );

        if ( is_array( $json ) && ! empty( $json['errors'][0] ) && is_array( $json['errors'][0] ) ) {
            $out['code'] = isset( $json['errors'][0]['code'] ) ? (int) $json['errors'][0]['code'] : 0;
            if ( isset( $json['errors'][0]['message'] ) && is_string( $json['errors'][0]['message'] ) ) {
                $out['message'] = substr( sanitize_text_field( $json['errors'][0]['message'] ), 0, 160 );
            }
        }

        if ( $status >= 200 && $status < 300 ) {
            if ( is_array( $json ) && ! empty( $json['success'] ) ) {
                $out['kind']   = 'ok';
                $out['result'] = $json['result'] ?? null;
            } else {
                $out['kind'] = 'rejected';
                if ( $out['message'] === '' ) {
                    $out['message'] = is_array( $json ) ? 'not successful' : 'invalid response';
                }
            }
        } elseif ( $status === 429 ) {
            $out['kind']        = 'rate';
            $out['retry_after'] = nppp_cloudflare_apo_retry_after( wp_remote_retrieve_header( $response, 'retry-after' ) );
        } elseif ( $status >= 500 ) {
            $out['kind'] = 'server';
        } elseif ( $status === 401 || $status === 403 ) {
            $out['kind'] = 'auth';
        } elseif ( $status === 404 || in_array( $out['code'], array( 7000, 7003 ), true ) ) {
            $out['kind'] = 'not_found';
        } else {
            $out['kind'] = 'rejected';
        }

        return $out;
    }
}

if ( ! function_exists( 'nppp_cloudflare_apo_failure_text' ) ) {
    /**
     * Short log-safe description of a failed result.
     */
    function nppp_cloudflare_apo_failure_text( array $res ): string {
        $parts = array();
        if ( ! empty( $res['status'] ) ) {
            $parts[] = 'HTTP ' . (int) $res['status'];
        }
        if ( ! empty( $res['code'] ) ) {
            $parts[] = 'code ' . (int) $res['code'];
        }
        if ( ! empty( $res['message'] ) ) {
            $parts[] = (string) $res['message'];
        }

        return empty( $parts ) ? (string) $res['kind'] : implode( ', ', $parts );
    }
}

if ( ! function_exists( 'nppp_cloudflare_apo_classify' ) ) {
    /**
     * Turns a failed result into a retry decision and arms the park window.
     *
     * @return array{retry:bool,delay:int}
     */
    function nppp_cloudflare_apo_classify( array $res ): array {
        switch ( $res['kind'] ) {
            case 'rate':
                $delay = max( 30, (int) $res['retry_after'] > 0 ? (int) $res['retry_after'] : 60 );
                nppp_cloudflare_apo_park( $delay );
                return array( 'retry' => true, 'delay' => $delay + 2 );

            case 'server':
            case 'network':
                nppp_cloudflare_apo_park( 60 );
                return array( 'retry' => true, 'delay' => 62 );

            case 'auth':
                // Retrying cannot help until the credentials are fixed; do not hammer the API meanwhile.
                nppp_cloudflare_apo_park( 300 );
                return array( 'retry' => false, 'delay' => 0 );

            default:
                return array( 'retry' => false, 'delay' => 0 );
        }
    }
}

if ( ! function_exists( 'nppp_cloudflare_apo_log_failure' ) ) {
    /**
     * One throttled log line for a failed API result.
     */
    function nppp_cloudflare_apo_log_failure( array $res, bool $retry, int $delay ): void {
        $kind = (string) $res['kind'];

        if ( ! nppp_cloudflare_apo_warn_due( 'fail-' . $kind ) ) {
            return;
        }

        $text = nppp_cloudflare_apo_failure_text( $res );

        if ( $retry ) {
            nppp_cloudflare_apo_log(
                sprintf(
                    /* translators: 1: short failure description, 2: seconds until the retry */
                    __( 'Cloudflare cache purge failed (%1$s); it was queued and will be retried in about %2$d s.', 'fastcgi-cache-purge-and-preload-nginx' ),
                    $text,
                    (int) $delay
                ),
                'warning'
            );
            return;
        }

        if ( $kind === 'auth' ) {
            nppp_cloudflare_apo_log(
                sprintf(
                    /* translators: %s: short failure description */
                    __( 'Cloudflare cache purge denied (%s). Check the API credentials of the Cloudflare plugin.', 'fastcgi-cache-purge-and-preload-nginx' ),
                    $text
                ),
                'error'
            );
            return;
        }

        if ( $kind === 'internal' ) {
            nppp_cloudflare_apo_log(
                __( 'Cloudflare cache purge skipped: the installed Cloudflare plugin version is not supported.', 'fastcgi-cache-purge-and-preload-nginx' ),
                'warning'
            );
            return;
        }

        nppp_cloudflare_apo_log(
            sprintf(
                /* translators: %s: short failure description */
                __( 'Cloudflare cache purge was rejected (%s).', 'fastcgi-cache-purge-and-preload-nginx' ),
                $text
            ),
            'error'
        );
    }
}

/* ============================================================================
 * 7. Zone resolver
 * ========================================================================= */

if ( ! function_exists( 'nppp_cloudflare_apo_zone_candidates' ) ) {
    /**
     * Zone names to try, most likely first: the Cloudflare plugin's own cached domain name, then the
     * site host shortened label by label (never below two labels). At most 6.
     */
    function nppp_cloudflare_apo_zone_candidates( array $rt ): array {
        $out = array();

        try {
            $domains = $rt['wp_api']->getDomainList();
        } catch ( \Throwable $e ) {
            $domains = array();
        }
        if ( is_array( $domains ) && isset( $domains[0] ) && is_string( $domains[0] ) ) {
            $h = nppp_cloudflare_apo_normalize_host( $domains[0] );
            if ( $h !== '' ) {
                $out[] = $h;
            }
        }

        $home = nppp_cloudflare_apo_normalize_host( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
        if ( $home !== '' ) {
            $labels = explode( '.', $home );
            while ( count( $labels ) >= 2 ) {
                $out[] = implode( '.', $labels );
                array_shift( $labels );
            }
        }

        return array_slice( array_values( array_unique( $out ) ), 0, 6 );
    }
}

if ( ! function_exists( 'nppp_cloudflare_apo_resolve_zone' ) ) {
    /**
     * Resolves the Cloudflare zone of this site.
     *
     * Order: filter nppp_cloudflare_apo_zone, cached zone (12 h), negative memo (10 min), then
     * GET /zones?name= for each candidate. Only a transport / auth / rate failure is returned as 'fail';
     * "no such zone" is a plain miss.
     *
     * @return array{zone:?array{id:string,name:string},fail:?array}
     */
    function nppp_cloudflare_apo_resolve_zone( array $rt, float $deadline ): array {
        $home = nppp_cloudflare_apo_normalize_host( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );

        /**
         * Overrides the zone. Return array( 'id' => <32 hex zone id>, 'name' => <zone name> ) to skip discovery.
         *
         * @param array|null $zone Default null.
         * @param string     $host Site host (ASCII).
         */
        $forced = apply_filters( 'nppp_cloudflare_apo_zone', null, $home );
        if ( is_array( $forced ) && isset( $forced['id'], $forced['name'] )
            && is_string( $forced['id'] ) && preg_match( '/^[0-9a-f]{32}$/i', $forced['id'] )
            && nppp_cloudflare_apo_normalize_host( (string) $forced['name'] ) !== ''
        ) {
            return array(
                'zone' => array(
                    'id'   => strtolower( $forced['id'] ),
                    'name' => nppp_cloudflare_apo_normalize_host( (string) $forced['name'] ),
                ),
                'fail' => null,
            );
        }

        $fp     = nppp_cloudflare_apo_credential_fingerprint( $rt );
        $cached = nppp_cloudflare_apo_state_get( 'cf_zone' );
        if ( is_array( $cached ) && isset( $cached['id'], $cached['name'], $cached['fp'] )
            && $cached['fp'] === $fp && is_string( $cached['id'] ) && is_string( $cached['name'] )
        ) {
            return array( 'zone' => array( 'id' => $cached['id'], 'name' => $cached['name'] ), 'fail' => null );
        }

        $miss = nppp_cloudflare_apo_state_get( 'cf_zone_miss' );
        if ( is_array( $miss ) && isset( $miss['fp'] ) && $miss['fp'] === $fp ) {
            return array( 'zone' => null, 'fail' => null );
        }

        foreach ( nppp_cloudflare_apo_zone_candidates( $rt ) as $candidate ) {
            if ( microtime( true ) >= $deadline ) {
                // Out of budget: neither a hit nor a miss; the caller defers.
                return array( 'zone' => null, 'fail' => array( 'kind' => 'budget', 'status' => 0, 'code' => 0, 'message' => '', 'retry_after' => 0, 'result' => null ) );
            }

            $res = nppp_cloudflare_apo_send(
                $rt,
                'GET',
                'zones',
                null,
                array( 'name' => $candidate, 'status' => 'active' ),
                nppp_cloudflare_apo_request_timeout( $deadline )
            );

            if ( $res['kind'] !== 'ok' ) {
                if ( $res['kind'] === 'not_found' ) {
                    continue;
                }
                return array( 'zone' => null, 'fail' => $res );
            }

            if ( ! is_array( $res['result'] ) ) {
                continue;
            }
            foreach ( $res['result'] as $zone ) {
                if ( is_array( $zone ) && isset( $zone['id'], $zone['name'] ) && is_string( $zone['id'] ) && is_string( $zone['name'] )
                    && nppp_cloudflare_apo_normalize_host( $zone['name'] ) === $candidate
                ) {
                    nppp_cloudflare_apo_state_set(
                        'cf_zone',
                        array( 'id' => $zone['id'], 'name' => $candidate, 'fp' => $fp ),
                        12 * HOUR_IN_SECONDS
                    );
                    nppp_cloudflare_apo_state_delete( 'cf_zone_miss' );

                    return array( 'zone' => array( 'id' => $zone['id'], 'name' => $candidate ), 'fail' => null );
                }
            }
        }

        nppp_cloudflare_apo_state_set( 'cf_zone_miss', array( 'fp' => $fp ), 10 * MINUTE_IN_SECONDS );

        return array( 'zone' => null, 'fail' => null );
    }
}

/* ============================================================================
 * 8. Purge execution
 * ========================================================================= */

if ( ! function_exists( 'nppp_cloudflare_apo_gate' ) ) {
    /**
     * Where the integration stands, without any network call.
     *
     * @return array{state:string,rt:?array} state: absent | unsupported | no_credentials | apo_off | ready
     */
    function nppp_cloudflare_apo_gate(): array {
        if ( ! nppp_cloudflare_apo_is_available() ) {
            return array( 'state' => 'absent', 'rt' => null );
        }

        $rt = nppp_cloudflare_apo_runtime();
        if ( $rt === null ) {
            return array( 'state' => 'unsupported', 'rt' => null );
        }

        if ( ! nppp_cloudflare_apo_has_credentials( $rt ) ) {
            return array( 'state' => 'no_credentials', 'rt' => $rt );
        }

        if ( ! nppp_cloudflare_apo_is_html_cache_enabled( $rt ) ) {
            return array( 'state' => 'apo_off', 'rt' => $rt );
        }

        return array( 'state' => 'ready', 'rt' => $rt );
    }
}

if ( ! function_exists( 'nppp_cloudflare_apo_log_gate' ) ) {
    function nppp_cloudflare_apo_log_gate( string $state ): void {
        if ( ! nppp_cloudflare_apo_warn_due( 'gate-' . $state ) ) {
            return;
        }

        switch ( $state ) {
            case 'unsupported':
                nppp_cloudflare_apo_log(
                    __( 'Cloudflare cache purge skipped: the installed Cloudflare plugin version is not supported.', 'fastcgi-cache-purge-and-preload-nginx' ),
                    'warning'
                );
                break;
            case 'no_credentials':
                nppp_cloudflare_apo_log(
                    __( 'Cloudflare cache purge skipped: Cloudflare plugin is not authenticated.', 'fastcgi-cache-purge-and-preload-nginx' ),
                    'warning'
                );
                break;
            case 'apo_off':
                nppp_cloudflare_apo_log(
                    __( 'Cloudflare cache purge skipped: APO/Plugin-Specific Cache is disabled.', 'fastcgi-cache-purge-and-preload-nginx' ),
                    'info'
                );
                break;
        }
    }
}

if ( ! function_exists( 'nppp_cloudflare_apo_execute' ) ) {
    /**
     * Runs one purge job: everything, or a list of URLs. Synchronous, time budgeted, never throws.
     * A retryable failure (429, 5xx, network), a parked API or an exhausted budget keeps the not yet
     * purged part in the pending set for the retry event / the next run.
     *
     * @param bool     $all     True: purge_everything. False: purge $urls.
     * @param string[] $urls    URLs (ignored when $all).
     * @param string   $context 'purge_urls' | 'purge_all' | 'retry'
     * @param int      $tries   Failed attempts so far (retry runs).
     */
    function nppp_cloudflare_apo_execute( bool $all, array $urls, string $context, int $tries = 0 ): void {
        $gate = nppp_cloudflare_apo_gate();
        if ( $gate['state'] !== 'ready' ) {
            nppp_cloudflare_apo_log_gate( $gate['state'] );
            return;
        }
        $rt       = $gate['rt'];
        $deadline = microtime( true ) + nppp_cloudflare_apo_budget_seconds();
        $ctx      = $all ? 'all' : 'urls';

        if ( ! $all && empty( $urls ) && $context !== 'retry' && nppp_cloudflare_apo_pending_read() === null ) {
            nppp_cloudflare_apo_log( __( 'Cloudflare cache purge skipped: no URLs provided for purge.', 'fastcgi-cache-purge-and-preload-nginx' ), 'info' );
            return;
        }

        // While the API is parked nothing is sent; the job waits in the pending set.
        $park = nppp_cloudflare_apo_park_until();
        if ( $park > 0 ) {
            if ( $context !== 'retry' ) {
                nppp_cloudflare_apo_defer( $all, $urls, $tries, ( $park - time() ) + 2, false );
                if ( nppp_cloudflare_apo_warn_due( 'parked' ) ) {
                    nppp_cloudflare_apo_log(
                        sprintf(
                            /* translators: %d: seconds until the API is used again */
                            __( 'Cloudflare cache purge queued: the Cloudflare API is paused for about %d s.', 'fastcgi-cache-purge-and-preload-nginx' ),
                            max( 1, $park - time() )
                        ),
                        'warning'
                    );
                }
            } else {
                nppp_cloudflare_apo_defer( $all, $urls, $tries, ( $park - time() ) + 2, false );
            }
            return;
        }

        // Fold anything that is waiting into this run (one request instead of two).
        if ( $context !== 'retry' ) {
            $pending = nppp_cloudflare_apo_pending_claim();
            if ( $pending !== null ) {
                $tries = max( $tries, $pending['tries'] );
                if ( $pending['all'] ) {
                    $all = true;
                    $ctx = 'all';
                } elseif ( ! $all ) {
                    $urls = array_merge( $urls, $pending['urls'] );
                }
            }
        }
        if ( $all ) {
            $urls = array();
        }

        // Zone.
        $z = nppp_cloudflare_apo_resolve_zone( $rt, $deadline );
        if ( $z['fail'] !== null ) {
            if ( $z['fail']['kind'] === 'budget' ) {
                nppp_cloudflare_apo_defer( $all, $urls, $tries, 30, false );
                return;
            }
            $decision = nppp_cloudflare_apo_classify( $z['fail'] );
            nppp_cloudflare_apo_log_failure( $z['fail'], $decision['retry'], $decision['delay'] );
            nppp_cloudflare_apo_note_last( false, array_merge( $z['fail'], array( 'ctx' => $ctx, 'n' => count( $urls ) ) ) );
            if ( $decision['retry'] ) {
                nppp_cloudflare_apo_defer( $all, $urls, $tries, $decision['delay'], true );
            }
            return;
        }
        if ( $z['zone'] === null ) {
            if ( nppp_cloudflare_apo_warn_due( 'no-zone' ) ) {
                nppp_cloudflare_apo_log(
                    __( 'Cloudflare cache purge skipped: no active Cloudflare zone was found for this site.', 'fastcgi-cache-purge-and-preload-nginx' ),
                    'warning'
                );
            }
            nppp_cloudflare_apo_note_last( false, array( 'kind' => 'no_zone', 'ctx' => $ctx, 'n' => count( $urls ) ) );
            return;
        }
        $zone = $z['zone'];

        if ( microtime( true ) >= $deadline ) {
            nppp_cloudflare_apo_defer( $all, $urls, $tries, 30, false );
            return;
        }

        $path = 'zones/' . rawurlencode( $zone['id'] ) . '/purge_cache';

        /* ---- purge everything ---- */
        if ( $all ) {
            $res = nppp_cloudflare_apo_send( $rt, 'POST', $path, array( 'purge_everything' => true ), array(), nppp_cloudflare_apo_request_timeout( $deadline ) );

            if ( $res['kind'] === 'ok' ) {
                nppp_cloudflare_apo_state_delete( 'cf_park' );
                nppp_cloudflare_apo_log( __( 'Cloudflare purge-all cache completed.', 'fastcgi-cache-purge-and-preload-nginx' ), 'success' );
                nppp_cloudflare_apo_note_last( true, array( 'kind' => 'ok', 'status' => $res['status'], 'ctx' => 'all' ) );
                return;
            }

            nppp_cloudflare_apo_handle_failed_job( $res, true, array(), $tries, $zone, $ctx );
            return;
        }

        /* ---- purge URLs ---- */
        $plan = nppp_cloudflare_apo_plan_urls( $urls, $zone['name'] );
        if ( empty( $plan['urls'] ) ) {
            nppp_cloudflare_apo_log(
                sprintf(
                    /* translators: %d: input URL count */
                    __( 'Cloudflare cache purge skipped: no URLs matched the active zone (input=%d).', 'fastcgi-cache-purge-and-preload-nginx' ),
                    count( $urls )
                ),
                'info'
            );
            return;
        }

        $device    = nppp_cloudflare_apo_device_type_enabled( $rt );
        $chunks    = array_chunk( $plan['urls'], nppp_cloudflare_apo_chunk_size() );
        $requests  = 0;
        $done      = 0;
        $mobile    = 0;
        $leftover  = array();
        $fail      = null;
        $budget_hit = false;

        foreach ( $chunks as $chunk ) {
            if ( $fail !== null ) {
                $leftover = array_merge( $leftover, $chunk );
                continue;
            }
            if ( microtime( true ) >= $deadline ) {
                $budget_hit = true;
                $leftover   = array_merge( $leftover, $chunk );
                continue;
            }

            $res = nppp_cloudflare_apo_send( $rt, 'POST', $path, array( 'files' => $chunk ), array(), nppp_cloudflare_apo_request_timeout( $deadline ) );
            $requests++;
            if ( $res['kind'] !== 'ok' ) {
                $fail     = $res;
                $leftover = array_merge( $leftover, $chunk );
                continue;
            }

            if ( $device ) {
                if ( microtime( true ) >= $deadline ) {
                    $budget_hit = true;
                    $leftover   = array_merge( $leftover, $chunk );
                    continue;
                }

                $files = array();
                foreach ( $chunk as $u ) {
                    $files[] = array( 'url' => $u, 'headers' => array( 'CF-Device-Type' => 'mobile' ) );
                }
                $res2 = nppp_cloudflare_apo_send( $rt, 'POST', $path, array( 'files' => $files ), array(), nppp_cloudflare_apo_request_timeout( $deadline ) );
                $requests++;
                if ( $res2['kind'] !== 'ok' ) {
                    $fail     = $res2;
                    $leftover = array_merge( $leftover, $chunk );
                    continue;
                }
                $mobile += count( $chunk );
            }

            $done += count( $chunk );
        }

        if ( $done > 0 ) {
            nppp_cloudflare_apo_state_delete( 'cf_park' );
            nppp_cloudflare_apo_log(
                sprintf(
                    /* translators: 1: URL count, 2: request count */
                    __( 'Cloudflare cache purge completed: %1$d URL(s) in %2$d request(s).', 'fastcgi-cache-purge-and-preload-nginx' ),
                    (int) $done,
                    (int) $requests
                ),
                'success'
            );
            if ( $mobile > 0 ) {
                nppp_cloudflare_apo_log(
                    sprintf(
                        /* translators: %d: URL count */
                        __( 'Cloudflare mobile cache purge completed: %d URL(s).', 'fastcgi-cache-purge-and-preload-nginx' ),
                        (int) $mobile
                    ),
                    'success'
                );
            }
        }

        if ( $fail !== null ) {
            nppp_cloudflare_apo_handle_failed_job( $fail, false, $leftover, $tries, $zone, 'urls', $plan['urls'], $requests );
            return;
        }

        if ( $budget_hit && ! empty( $leftover ) ) {
            nppp_cloudflare_apo_log(
                sprintf(
                    /* translators: %d: number of URLs */
                    __( 'Cloudflare cache purge: time budget exhausted, %d URL(s) were queued for retry.', 'fastcgi-cache-purge-and-preload-nginx' ),
                    count( $leftover )
                ),
                'warning'
            );
            nppp_cloudflare_apo_defer( false, $leftover, $tries, 30, false );
        }

        nppp_cloudflare_apo_note_last( true, array( 'kind' => 'ok', 'ctx' => 'urls', 'n' => $done ) );
    }
}

if ( ! function_exists( 'nppp_cloudflare_apo_handle_failed_job' ) ) {
    /**
     * Common tail of a failed purge request: classify, log, remember, and either keep the not yet purged
     * part for a retry or drop it.
     *
     * @param array    $res       The failed result.
     * @param bool     $all       The job was purge_everything.
     * @param string[] $leftover  URLs not purged (URL jobs).
     * @param int      $tries     Failed attempts so far.
     * @param array    $zone      array( id, name )
     * @param string   $ctx       'all' | 'urls'
     * @param string[] $planned   All planned URLs of the job (URL jobs, for the summary line).
     * @param int      $requests  Requests sent (URL jobs, for the summary line).
     */
    function nppp_cloudflare_apo_handle_failed_job( array $res, bool $all, array $leftover, int $tries, array $zone, string $ctx, array $planned = array(), int $requests = 0 ): void {
        if ( $res['kind'] === 'not_found' ) {
            // The cached zone id is stale (zone deleted / moved): rediscover on the next run.
            nppp_cloudflare_apo_state_delete( 'cf_zone' );
        }

        $decision = nppp_cloudflare_apo_classify( $res );
        $text     = nppp_cloudflare_apo_failure_text( $res );

        if ( $decision['retry'] ) {
            nppp_cloudflare_apo_log_failure( $res, true, $decision['delay'] );
        } elseif ( ! $all && ! empty( $planned ) && in_array( $res['kind'], array( 'rejected', 'not_found' ), true ) ) {
            nppp_cloudflare_apo_log(
                sprintf(
                    /* translators: 1: URL count, 2: request count, 3: failed count, 4: error */
                    __( 'Cloudflare cache purge finished with errors: URLs=%1$d, requests=%2$d, failed=%3$d. %4$s', 'fastcgi-cache-purge-and-preload-nginx' ),
                    count( $planned ),
                    (int) $requests,
                    1,
                    $text
                ),
                'error'
            );
        } elseif ( $all && ! $decision['retry'] && ( $res['kind'] === 'rejected' || $res['kind'] === 'not_found' ) ) {
            nppp_cloudflare_apo_log(
                sprintf(
                    /* translators: %s: short failure description */
                    __( 'Cloudflare purge-all cache failed (%s).', 'fastcgi-cache-purge-and-preload-nginx' ),
                    $text
                ),
                'error'
            );
        } else {
            nppp_cloudflare_apo_log_failure( $res, false, 0 );
        }

        nppp_cloudflare_apo_note_last( false, array_merge( $res, array( 'ctx' => $ctx, 'n' => count( $leftover ) ) ) );

        if ( $decision['retry'] ) {
            nppp_cloudflare_apo_defer( $all, $leftover, $tries, $decision['delay'], true );
        }
    }
}

if ( ! function_exists( 'nppp_cloudflare_apo_run_retry' ) ) {
    /**
     * The retry cron event (NPPP_CF_APO_RETRY_HOOK). Takes the pending set and runs it again. It does not
     * depend on a session; the bootstrap is loaded for this hook by the EP2 whitelist.
     */
    function nppp_cloudflare_apo_run_retry(): void {
        try {
            if ( ! nppp_cloudflare_apo_option_on() ) {
                // The feature was switched off while purges were waiting: they are no longer wanted.
                nppp_cloudflare_apo_pending_claim();
                return;
            }

            $park = nppp_cloudflare_apo_park_until();
            if ( $park > 0 ) {
                nppp_cloudflare_apo_schedule_retry( ( $park - time() ) + 2 );
                return;
            }

            $pending = nppp_cloudflare_apo_pending_claim();
            if ( $pending === null ) {
                return;
            }

            nppp_cloudflare_apo_execute( $pending['all'], $pending['urls'], 'retry', $pending['tries'] );
        } catch ( \Throwable $e ) {
            nppp_cloudflare_apo_log( 'WARNING CLOUDFLARE: unexpected error (' . get_class( $e ) . ') in the retry run.', 'warning' );
        }
    }
}

if ( ! function_exists( 'nppp_cloudflare_apo_cleanup' ) ) {
    /**
     * Drops the retry event and every row this file keeps. For plugin deactivation (uninstall.php removes
     * all nppp_st_* rows on its own). The user's settings are not touched.
     */
    function nppp_cloudflare_apo_cleanup(): void {
        wp_unschedule_hook( NPPP_CF_APO_RETRY_HOOK );
        foreach ( array( 'cf_zone', 'cf_zone_miss', 'cf_park', 'cf_warn', 'cf_pending', 'cf_last' ) as $key ) {
            nppp_cloudflare_apo_state_delete( $key );
        }
        nppp_cloudflare_apo_local_park( 0, true );
    }
}

/* ============================================================================
 * 9. Status API (read only, no network)
 * ========================================================================= */

if ( ! function_exists( 'nppp_cloudflare_apo_status' ) ) {
    /**
     * Where the Cloudflare sync stands. Used by the settings page, the dashboard and WP-CLI.
     *
     * state: absent | unsupported | no_credentials | no_zone | apo_off | parked | ready
     *
     * @return array{state:string,enabled:bool,parked_until:int,pending:bool,pending_all:bool,pending_urls:int,zone:string,last:?array}
     */
    function nppp_cloudflare_apo_status(): array {
        $status = array(
            'state'        => 'absent',
            'enabled'      => nppp_cloudflare_apo_option_on(),
            'parked_until' => 0,
            'pending'      => false,
            'pending_all'  => false,
            'pending_urls' => 0,
            'zone'         => '',
            'last'         => null,
        );

        $pending = nppp_cloudflare_apo_pending_read();
        if ( $pending !== null ) {
            $status['pending']      = true;
            $status['pending_all']  = $pending['all'];
            $status['pending_urls'] = count( $pending['urls'] );
        }

        $last = nppp_cloudflare_apo_state_get( 'cf_last' );
        if ( is_array( $last ) ) {
            $status['last'] = $last;
        }

        $gate = nppp_cloudflare_apo_gate();
        if ( $gate['state'] !== 'ready' ) {
            $status['state'] = $gate['state'];
            return $status;
        }

        $rt     = $gate['rt'];
        $fp     = nppp_cloudflare_apo_credential_fingerprint( $rt );
        $zone   = nppp_cloudflare_apo_state_get( 'cf_zone' );
        $miss   = nppp_cloudflare_apo_state_get( 'cf_zone_miss' );
        $parked = nppp_cloudflare_apo_park_until();

        if ( is_array( $zone ) && isset( $zone['fp'], $zone['name'] ) && $zone['fp'] === $fp ) {
            $status['zone'] = (string) $zone['name'];
        }

        if ( $status['zone'] === '' && is_array( $miss ) && isset( $miss['fp'] ) && $miss['fp'] === $fp ) {
            $status['state'] = 'no_zone';
        } elseif ( $parked > 0 ) {
            $status['state']        = 'parked';
            $status['parked_until'] = $parked;
        } else {
            $status['state'] = 'ready';
        }

        return $status;
    }
}

/* ============================================================================
 * 10. Listeners and filter
 * ========================================================================= */

if ( ! function_exists( 'nppp_cloudflare_apo_purge_all' ) ) {
    /**
     * Listener for nppp_purged_all (Purge All, after Nginx). Runs without an admin session.
     * Sends ONE purge_everything; a failure is kept for the retry event.
     *
     * @param bool $auto_preload Passed by nppp_purged_all; not used here.
     */
    function nppp_cloudflare_apo_purge_all( $auto_preload = false ): void {
        try {
            if ( ! nppp_cloudflare_apo_is_available() ) {
                return;
            }

            if ( ! apply_filters( 'nppp_sync_cloudflare_apo_enabled', true, 'purge_all' ) ) {
                return;
            }

            nppp_cloudflare_apo_execute( true, array(), 'purge_all' );
        } catch ( \Throwable $e ) {
            nppp_cloudflare_apo_log( 'WARNING CLOUDFLARE: unexpected error (' . get_class( $e ) . ') during purge-all.', 'warning' );
        }
    }
}

if ( ! function_exists( 'nppp_cloudflare_apo_purge_urls' ) ) {
    /**
     * Listener for nppp_purged_urls (single purge, after Nginx, before auto-preload).
     * Purges the same URLs on Cloudflare synchronously, so the crawl that follows meets a clean edge.
     */
    function nppp_cloudflare_apo_purge_urls( $urls = array(), $primary_url = '', $post_id = 0, $is_auto = false ): void {
        try {
            if ( ! is_array( $urls ) || ! nppp_cloudflare_apo_is_available() ) {
                return;
            }

            $primary_url = is_string( $primary_url ) ? $primary_url : '';
            $post_id     = (int) $post_id;
            $is_auto     = (bool) $is_auto;

            if ( ! apply_filters( 'nppp_sync_cloudflare_apo_enabled', true, 'purge_urls', $urls, $primary_url, $post_id, $is_auto ) ) {
                return;
            }

            nppp_cloudflare_apo_execute( false, $urls, 'purge_urls' );
        } catch ( \Throwable $e ) {
            nppp_cloudflare_apo_log( 'WARNING CLOUDFLARE: unexpected error (' . get_class( $e ) . ') during URL purge.', 'warning' );
        }
    }
}

if ( ! function_exists( 'nppp_cloudflare_apo_sync_option_enabled' ) ) {
    /**
     * Filter callback for nppp_sync_cloudflare_apo_enabled. Read only: it never writes the setting.
     * (The Status API reports "plugin missing"; a toggle that disappears for one request, for example
     * under WP-CLI --skip-plugins, must not be turned off for good.)
     */
    function nppp_cloudflare_apo_sync_option_enabled( $enabled = true, $context = '', $urls = null, $primary_url = '', $post_id = 0, $is_auto = false ): bool {
        if ( ! $enabled ) {
            return false;
        }

        if ( ! nppp_cloudflare_apo_option_on() ) {
            return false;
        }

        return nppp_cloudflare_apo_is_available();
    }
}

add_filter( 'nppp_sync_cloudflare_apo_enabled', 'nppp_cloudflare_apo_sync_option_enabled', 10, 6 );
add_action( 'nppp_purged_all', 'nppp_cloudflare_apo_purge_all', 20, 1 );
add_action( 'nppp_purged_urls', 'nppp_cloudflare_apo_purge_urls', 20, 4 );
add_action( NPPP_CF_APO_RETRY_HOOK, 'nppp_cloudflare_apo_run_retry' );
