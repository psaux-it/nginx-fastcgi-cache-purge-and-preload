<?php
/**
 * HTTP Purge for Nginx Cache Purge Preload
 * Description: Optimistic HTTP fast-path using the Nginx ngx_cache_purge module.
 *              Supports single URL purge and full cache purge (purge all) via
 *              dedicated nginx location blocks, and falls back to the filesystem
 *              workflow whenever HTTP cannot prove the work is finished.
 * Version: 2.1.8
 * Author: Hasan CALISIR
 * Author Email: hasan.calisir@psauxit.com
 * Author URI: https://www.psauxit.com
 * License: GPL-2.0+
 *
 * Module Credit & Repository:
 *   This plugin utilizes the denji's ngx_cache_purge module fork.
 *   Official GitHub Repository: https://github.com/nginx-modules/ngx_cache_purge
 *
 * Module compatibility (one code path, no version switch):
 *   - ngx_cache_purge 2.3           single purge.
 *   - ngx_cache_purge 2.5.x / 3.0.x single purge + purge_all. On 2.5.x the Purge All
 *                                   location also needs fastcgi_pass, otherwise nginx
 *                                   answers 404 and the plugin silently falls back.
 *   - ngx_cache_purge 4.x           reads the "Cache-Purge-Code" response header
 *                                   (the module's own result catalog). When the
 *                                   header is absent the HTTP status table of
 *                                   v2/v3 is used, so older modules keep working.
 *
 * -------------------------------------------------------------------------------
 * Public API
 * -------------------------------------------------------------------------------
 * nppp_http_purge_try_first( $url )  returns  true | 'miss' | false
 *   true   — the entry is gone: v2/v3 "200", v4 "purged" or "invalidated", AND the
 *            URL is unambiguous (nginx and the cache file build the same key).
 *   'miss' — nginx says the URL is not cached (v2.5/v3 "412", v4 "200 no_match")
 *            AND the URL is unambiguous, so the filesystem scan may be skipped.
 *   false  — everything else, including a "purged" for an ambiguous URL (nginx may
 *            have deleted a different entry than the real one); the caller continues
 *            with index / rg / scan.
 *
 * nppp_http_purge_all()              returns  true | false
 *   true   — nginx finished the full purge (200 purged / no_match / invalidated),
 *            or a "202 accepted" background job was PROVEN finished, or a request
 *            that TIMED OUT was waited out until nginx had removed every old file: the queue
 *            status endpoint (cache_purge_queue_status) showed the queue idle
 *            twice in a row without new cleanup errors; without a status endpoint
 *            it is best effort (no cache file older than the purge is left, plus
 *            a short grace period for nginx to finish its pass).
 *   false  — fall back to the filesystem purge. After a 202 or a response timeout
 *            this is only returned AFTER waiting (see nppp_http_purge_all()), so the
 *            sweep and the preload that follow do not start while nginx is still
 *            deleting.
 *            A caller that passes the optional by-reference $pending argument gets one
 *            more outcome: a 202 whose queue is verifiably still working after the wait
 *            budget returns false with $pending filled instead. The caller must then
 *            NOT run the filesystem purge (nginx is already deleting; a second sweep
 *            only races it) and must hold the preload back until the queue is idle:
 *            nppp_http_purge_gate_command() does that inside the spawned shell, so the
 *            user's request is never blocked for the long tail.
 *
 * -------------------------------------------------------------------------------
 * What changed versus the v3.0.3-era implementation
 * -------------------------------------------------------------------------------
 *   - v4 contract: 412/404 no longer exist for a miss ("200 no_match"), every
 *     module answer carries Cache-Purge-Code, "cache_purge_response_type none"
 *     answers 204, wrong method answers 405 with an Allow header.
 *   - A "miss" is only trusted when nginx and the cache file agree on the key.
 *     nginx builds $1 from the DECODED uri while the cache key uses the raw
 *     $request_uri, so "%xx", non-ASCII, "//" and dot-segments are ambiguous.
 *   - A 200 that does not come from the module (a location that falls through
 *     to the site itself) is no longer reported as "purged".
 *   - A single-URL problem (400/414) no longer disables HTTP Purge for the site.
 *   - Locks are scoped: "single", "all" (separate nginx locations, separate
 *     allow lists) and "*" (connection level). They live in the same transient
 *     as before so status.php / uninstall.php keep clearing them.
 *   - PHP's execution limit is only ever raised, never lowered, so the
 *     filesystem fallback keeps the set_time_limit(0) that purge.php set.
 *   - Connection timeout is separate from the response timeout, so a firewall
 *     that drops packets costs seconds, not the whole 30 s Purge All budget.
 *   - Responses are capped at 16 KB.
 *   - 202 accepted (cache_purge_background_queue on) is WAITED for before the
 *     caller sweeps or preloads. The plugin cannot know that the queue is on, and
 *     measured: a preload that starts while the nginx walk is still queued loses
 *     pages to it. Proof = queue status endpoint when configured, else the cache
 *     directory (files whose ctime predates the purge are gone) + grace period.
 *   - A finished Purge All (200 purged / no_match) is cross-checked against the
 *     plugin's own cache path: files older than the purge still there means nginx
 *     purged another zone or a delete failed silently, so the filesystem purge
 *     still runs. Skipped for "invalidated" (files legitimately remain).
 *   - A URL whose host differs from the Host header the plugin sends (www alias,
 *     multisite domain) is never sent: nginx would purge the HOME host's key and
 *     the plugin would report success while the real entry stays cached.
 *   - A miss is only trusted for GET requests whose host and scheme match the
 *     real URL; an exception inside the transport counts as "unreachable".
 *   - A 202 from the SINGLE endpoint means it is bound to a wildcard / purge_all
 *     location (exact keys never answer 202): locked, instead of wiping the whole
 *     cache on every single purge.
 *   - A "purged" / "invalidated" for an AMBIGUOUS single URL is no longer a success.
 *     nginx decodes $1, so purging /p/%41/ deletes /p/A/ and answers 200 while the
 *     real entry stays HIT. Only the decode-ambiguous shapes count (percent-encoded
 *     path, non-ASCII, "//", dot segments, backslash); the index / filesystem layers
 *     purge those. Filter: nppp_http_purge_trust_purged.
 *   - A Purge All whose request TIMES OUT is waited for like a 202: the synchronous
 *     walk is still running inside nginx, and falling back at once let the sweep and
 *     the preload race it. Proof = the cache directory (a synchronous walk is not part
 *     of the queue status). Unproven -> false (filesystem purge).
 *
 * -------------------------------------------------------------------------------
 * Known limitation (Vary)
 * -------------------------------------------------------------------------------
 *   ngx_cache_purge 3.0 with cache_purge_vary_aware on answers 412 when the REQUESTED
 *   variant is not cached, without cleaning the variants that are. The plugin trusts
 *   that 412 as "miss", so stale variants can stay cached. The documented NPP config
 *   uses fastcgi_ignore_headers Vary, so only setups that honour Vary are affected;
 *   for them use the nppp_http_purge_trust_miss filter (return false) or Purge All.
 *
 * -------------------------------------------------------------------------------
 * Filters
 * -------------------------------------------------------------------------------
 *   nppp_http_purge_timeout            ( int $seconds, string $type )
 *   nppp_http_purge_url                ( string $purge_url, string $original_url )
 *   nppp_http_purge_all_url            ( string $url )
 *   nppp_http_purge_connect_timeout    ( float $seconds )              default 3.0
 *   nppp_http_purge_host_header        ( string $host )                default home host[:non-default port]
 *   nppp_http_purge_trust_miss         ( bool $trust, string $url, string $reason )
 *   nppp_http_purge_trust_purged       ( bool $trust, string $url, string $reason )
 *   nppp_http_purge_lock_ttl           ( int $ttl, string $outcome, string $scope )
 *   nppp_http_purge_retry_methods      ( array $methods )              default [ 'PURGE' ]
 *   nppp_http_purge_verify_legacy_body ( bool $verify )                default true
 *   nppp_http_purge_verify_queue       ( bool $enabled )               default true (false = old behaviour:
 *                                                                      202 / timeout -> immediate filesystem
 *                                                                      fallback)
 *   nppp_http_purge_queue_status_url   ( string $url )                 default origin + "/" + setting
 *                                                                      nginx_cache_settings
 *                                                                      [nppp_http_purge_queue_status_path]
 *                                                                      ('' = no status endpoint)
 *   nppp_http_purge_queue_wait         ( int $seconds )                default 15, capped by the Purge All lock TTL
 *   nppp_http_purge_async_settle_ms    ( int $ms )                     default 1500 (best effort mode only)
 *   nppp_http_purge_verify_empty       ( bool $enabled )               default true
 * Action:
 *   nppp_http_purge_result ( string $scope, string $outcome, string $url,
 *                            int $http_status, string $module_code )
 * -------------------------------------------------------------------------------
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Transient that remembers a broken endpoint. The key is unchanged because
 * status.php ("reset") and uninstall.php delete it by name.
 *
 * Value: array{ locks: array<scope, {reason:string, until:int}>, methods: array<scope, string> }
 * Older plugin versions stored a plain string; it is still honoured as a
 * connection-level ("*") lock until that transient expires.
 */
if ( ! defined( 'NPPP_HTTP_PURGE_BROKEN_KEY' ) ) {
    define( 'NPPP_HTTP_PURGE_BROKEN_KEY', 'nppp_http_purge_endpoint_broken' );
}

/** Response bodies larger than this are never read (module replies are tiny). */
if ( ! defined( 'NPPP_HTTP_PURGE_BODY_LIMIT' ) ) {
    define( 'NPPP_HTTP_PURGE_BODY_LIMIT', 16384 );
}

/**
 * Marker that remembers a Purge All whose nginx queue was still working when NPP stopped
 * waiting (nppp_http_purge_all() $pending). The in-request hand-off of the preload gate dies
 * with the request; this marker lets a LATER request (manual Preload All, REST, WP-CLI, cron,
 * the Mobile phase) learn that a crawl started now would still race the queue.
 *
 * It is only a hint: the queue status endpoint decides (nppp_http_purge_gate_pending()), and it
 * expires with the gate's own maximum wait (armed_at + max wait).
 *
 * Value: array{ status_url:string, host:string, since:int, armed_at:int }
 */
if ( ! defined( 'NPPP_HTTP_PURGE_PENDING_KEY' ) ) {
    define( 'NPPP_HTTP_PURGE_PENDING_KEY', 'nppp_http_purge_pending' );
}

/* ============================================================================
 * 1. Settings and URLs
 * ========================================================================= */

/**
 * Returns true when the HTTP Purge is enabled in settings.
 */
function nppp_http_purge_enabled(): bool {
    $s = get_option( 'nginx_cache_settings', [] );
    return is_array( $s )
        && isset( $s['nppp_http_purge_enabled'] )
        && $s['nppp_http_purge_enabled'] === 'yes';
}

/**
 * scheme://host[:port] of the purge endpoints: the Custom Base URL when set,
 * otherwise the site URL.
 */
function nppp_http_purge_origin(): string {
    $s      = get_option( 'nginx_cache_settings', [] );
    $custom = ( is_array( $s ) && ! empty( $s['nppp_http_purge_custom_url'] ) )
              ? trim( (string) $s['nppp_http_purge_custom_url'] ) : '';

    $parse = wp_parse_url( $custom !== '' ? $custom : home_url() );
    if ( $custom !== '' && empty( $parse['host'] ) ) {
        $parse = wp_parse_url( home_url() );
    }

    $origin = ( $parse['scheme'] ?? 'https' ) . '://' . ( $parse['host'] ?? '' );
    if ( $custom !== '' && ! empty( $parse['port'] ) ) {
        $origin .= ':' . (int) $parse['port'];
    }

    return $origin;
}

/**
 * Builds the purge base URL for single purges from settings.
 */
function nppp_http_purge_base_url(): string {
    $s      = get_option( 'nginx_cache_settings', [] );
    $suffix = ( is_array( $s ) && isset( $s['nppp_http_purge_suffix'] ) && $s['nppp_http_purge_suffix'] !== '' )
              ? (string) $s['nppp_http_purge_suffix']
              : 'npp_purge_single';

    return nppp_http_purge_origin() . '/' . trim( $suffix, '/' );
}

/**
 * Sanitized URL path segment for the dedicated Purge All nginx endpoint
 * (e.g. "npp_purge_all" in `location = /npp_purge_all`). Independent of the
 * Single Purge Path, they hit two different nginx locations.
 */
function nppp_http_purge_all_path(): string {
    $s    = get_option( 'nginx_cache_settings', [] );
    $path = ( is_array( $s ) && isset( $s['nppp_http_purge_all_path'] ) && $s['nppp_http_purge_all_path'] !== '' )
            ? (string) $s['nppp_http_purge_all_path']
            : 'npp_purge_all';

    return trim( $path, '/' );
}

/**
 * Builds the HTTP endpoint URL for the dedicated Purge All nginx block.
 * Reuses Custom Base URL for scheme+host(+port) when set.
 */
function nppp_http_purge_all_url(): string {
    return nppp_http_purge_origin() . '/' . nppp_http_purge_all_path();
}

/**
 * URL of the ngx_cache_purge v4 "cache_purge_queue_status" location ('' = none).
 *
 * Path source: nginx_cache_settings[nppp_http_purge_queue_status_path] (Settings > HTTP
 * Purge > Purge Queue Status Path). The legacy standalone option of the same name is
 * still honoured while the setting is empty.
 * With it the plugin can PROVE that a 202 background purge has finished.
 */
function nppp_http_purge_queue_status_url(): string {
    $s   = get_option( 'nginx_cache_settings', [] );
    $raw = ( is_array( $s ) && isset( $s['nppp_http_purge_queue_status_path'] ) && is_string( $s['nppp_http_purge_queue_status_path'] ) )
           ? $s['nppp_http_purge_queue_status_path'] : '';
    if ( trim( $raw, " \t\n\r/" ) === '' ) {
        $raw = (string) get_option( 'nppp_http_purge_queue_status_path', '' );
    }
    $path = trim( $raw, " \t\n\r/" );
    $path = preg_match( '#^[A-Za-z0-9_\-/]+$#', $path ) ? $path : '';

    return (string) apply_filters(
        'nppp_http_purge_queue_status_url',
        $path !== '' ? nppp_http_purge_origin() . '/' . $path : ''
    );
}

/**
 * Resolves the response timeout (seconds) for a HTTP Purge type.
 *
 * Purge All, and a single purge with cache_purge_vary_aware on, walk the cache
 * directory inside nginx: O(file count) on a medium whose per-file cost ranges
 * from sub-millisecond (tmpfs) to tens of milliseconds (FUSE, network storage).
 * Purge All: default 30 seconds. Purge Single: default 8 seconds.
 *
 * @param string $type 'single' | 'all'
 */
function nppp_http_purge_timeout( string $type ): int {
    $s       = get_option( 'nginx_cache_settings', [] );
    $key     = 'nppp_http_purge_timeout_' . $type;
    $default = ( $type === 'all' ) ? 30 : 8;
    $value   = ( is_array( $s ) && isset( $s[ $key ] ) ) ? absint( $s[ $key ] ) : $default;
    $value   = $value > 0 ? $value : $default;

    /**
     * Filters the HTTP Purge timeout in seconds.
     *
     * @param int    $value Timeout in seconds.
     * @param string $type  'single' or 'all'.
     */
    return max( 1, (int) apply_filters( 'nppp_http_purge_timeout', $value, $type ) );
}

/**
 * Connection timeout in milliseconds, never above the overall timeout.
 * Loopback connects take microseconds; anything slower means the packets are
 * being dropped, and waiting the full response timeout would only delay the
 * filesystem fallback.
 */
function nppp_http_purge_connect_timeout_ms( int $overall_seconds ): int {
    $seconds = (float) apply_filters( 'nppp_http_purge_connect_timeout', 3.0 );
    $seconds = min( max( $seconds, 0.5 ), (float) max( 1, $overall_seconds ) );

    return (int) round( $seconds * 1000 );
}

/**
 * Raises PHP's execution limit to at least $seconds; never lowers it.
 * purge.php runs with set_time_limit(0) so the filesystem fallback can finish;
 * shrinking that to "timeout + 5" would cut the fallback short.
 */
function nppp_http_purge_extend_time_limit( int $seconds ): void {
    if ( ! function_exists( 'set_time_limit' ) ) {
        return;
    }

    $current = (int) ini_get( 'max_execution_time' );
    if ( $current === 0 || $current >= $seconds ) {
        return; // unlimited, or already generous enough
    }

    @set_time_limit( $seconds ); // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged
}

/* ============================================================================
 * 2. Lock state (one transient, scoped locks, remembered request method)
 * ========================================================================= */

/**
 * Reads the endpoint state, dropping expired locks.
 *
 * @return array{locks: array<string,array{reason:string,until:int}>, methods: array<string,string>}
 */
function nppp_http_purge_state(): array {
    $state = [ 'locks' => [], 'methods' => [], 'ns' => [], 'probe' => [], 'issue' => [], 'hint' => [] ];
    $raw   = get_transient( NPPP_HTTP_PURGE_BROKEN_KEY );

    if ( ! $raw ) {
        return $state;
    }

    // Older plugin versions stored a bare string ("403", "wp_error", ...).
    // It blocked everything, so treat it as a connection-level lock that lasts
    // until WordPress expires the transient.
    if ( ! is_array( $raw ) ) {
        $state['locks']['*'] = [ 'reason' => substr( (string) $raw, 0, 32 ), 'until' => PHP_INT_MAX ];
        return $state;
    }

    $now = time();
    foreach ( (array) ( $raw['locks'] ?? [] ) as $scope => $lock ) {
        if ( is_array( $lock ) && (int) ( $lock['until'] ?? 0 ) > $now ) {
            $state['locks'][ (string) $scope ] = [
                'reason' => substr( (string) ( $lock['reason'] ?? '' ), 0, 32 ),
                'until'  => (int) $lock['until'],
            ];
        }
    }
    foreach ( (array) ( $raw['methods'] ?? [] ) as $scope => $method ) {
        if ( is_string( $method ) && preg_match( '/^[A-Z]{3,10}$/', $method ) ) {
            $state['methods'][ (string) $scope ] = $method;
        }
    }

    foreach ( (array) ( $raw['ns'] ?? [] ) as $scope => $flag ) {
        if ( $flag && in_array( (string) $scope, [ 'single', 'all' ], true ) ) {
            $state['ns'][ (string) $scope ] = true;
        }
    }

    foreach ( (array) ( $raw['probe'] ?? [] ) as $scope => $until ) {
        if ( (int) $until > $now ) {
            $state['probe'][ (string) $scope ] = (int) $until;
        }
    }

    // Configuration faults the administrator has to fix. Unlike a lock (1 hour) this is
    // remembered for a day, so the settings page can still explain WHY HTTP Purge keeps
    // falling back after the lock expired. A successful purge of that scope clears it.
    foreach ( (array) ( $raw['issue'] ?? [] ) as $scope => $issue ) {
        if ( is_array( $issue )
            && in_array( (string) $scope, [ 'single', 'all' ], true )
            && in_array( $issue['code'] ?? '', nppp_http_purge_config_codes(), true )
            && (int) ( $issue['at'] ?? 0 ) > $now - DAY_IN_SECONDS ) {
            $state['issue'][ (string) $scope ] = [ 'code' => (string) $issue['code'], 'at' => (int) $issue['at'] ];
        }
    }

    // Advisory about the queue status endpoint (Settings > Purge Queue Status Path): what NPP
    // saw at the last Purge All. Remembered for a day, re-raised by the next Purge All.
    $hint = $raw['hint'] ?? null;
    if ( is_array( $hint )
        && in_array( $hint['code'] ?? '', nppp_http_purge_hint_codes(), true )
        && (int) ( $hint['at'] ?? 0 ) > $now - DAY_IN_SECONDS ) {
        $detail        = (string) ( $hint['detail'] ?? '' );
        $state['hint'] = [
            'code'   => (string) $hint['code'],
            'detail' => preg_match( '/^[a-z_]{1,16}$/', $detail ) ? $detail : '',
            'at'     => (int) $hint['at'],
        ];
    }

    return $state;
}

/**
 * Persists the endpoint state. Nothing left to remember removes the transient.
 */
function nppp_http_purge_state_save( array $state ): void {
    $now = time();
    $ttl = 0;

    foreach ( $state['locks'] as $scope => $lock ) {
        // Clamp: a legacy lock carries PHP_INT_MAX and must never become permanent.
        $state['locks'][ $scope ]['until'] = min( (int) $lock['until'], $now + HOUR_IN_SECONDS );
        $ttl = max( $ttl, $state['locks'][ $scope ]['until'] - $now );
    }
    if ( ! empty( $state['methods'] ) || ! empty( $state['ns'] ) || ! empty( $state['issue'] ) || ! empty( $state['hint'] ) ) {
        $ttl = max( $ttl, DAY_IN_SECONDS );
    }

    foreach ( (array) ( $state['probe'] ?? [] ) as $until ) {
        $ttl = max( $ttl, (int) $until - $now );
    }

    if ( $ttl <= 0 ) {
        delete_transient( NPPP_HTTP_PURGE_BROKEN_KEY );
        return;
    }

    set_transient( NPPP_HTTP_PURGE_BROKEN_KEY, $state, $ttl );
}

/**
 * True when HTTP Purge must be skipped for $scope ('single' | 'all').
 * A connection-level ("*") lock blocks both scopes.
 */
function nppp_http_purge_locked( string $scope ): bool {
    $locks = nppp_http_purge_state()['locks'];
    return isset( $locks[ $scope ] ) || isset( $locks['*'] );
}

/**
 * Arms a lock for $scope ('single' | 'all' | '*').
 */
function nppp_http_purge_arm( string $scope, string $reason, int $ttl ): void {
    $state                    = nppp_http_purge_state();
    $state['locks'][ $scope ] = [ 'reason' => substr( $reason, 0, 32 ), 'until' => time() + max( 1, $ttl ) ];
    nppp_http_purge_state_save( $state );
}

/**
 * Module codes that mean "the nginx configuration is wrong" (nothing the plugin can
 * retry its way out of). They get a dedicated, actionable message.
 */
function nppp_http_purge_config_codes(): array {
    return [ 'key_mismatch', 'cache_unavailable' ];
}

/**
 * Remembers a configuration fault for $scope ('single' | 'all') for a day.
 */
function nppp_http_purge_issue_set( string $scope, string $code ): void {
    if ( ! in_array( $scope, [ 'single', 'all' ], true ) || ! in_array( $code, nppp_http_purge_config_codes(), true ) ) {
        return;
    }
    $state                    = nppp_http_purge_state();
    $state['issue'][ $scope ] = [ 'code' => $code, 'at' => time() ];
    nppp_http_purge_state_save( $state );
}

/**
 * Forgets the configuration fault of $scope once the endpoint answered properly.
 */
function nppp_http_purge_issue_clear( string $scope ): void {
    $state = nppp_http_purge_state();
    if ( ! isset( $state['issue'][ $scope ] ) ) {
        return;
    }
    unset( $state['issue'][ $scope ] );
    nppp_http_purge_state_save( $state );
}

/**
 * What to change in nginx for a configuration fault. Plain text on purpose: the notice
 * pipeline runs sanitize_text_field(), which would drop anything in angle brackets.
 *
 * @param string $scope 'single' | 'all'
 * @param string $code  'key_mismatch' | 'cache_unavailable'
 */
function nppp_http_purge_config_fix( string $scope, string $code ): string {
    if ( $code === 'key_mismatch' ) {
        if ( $scope === 'all' ) {
            return __( 'ngx_cache_purge generation keys are active (cache_purge_namespace / cache_purge_key), but the cache key of the Purge All location is not $cache_purge_key (key_mismatch). Fix: in the Purge All location bind the same cache_purge_key as in the cached location and set fastcgi_cache_key $cache_purge_key; then reload nginx.', 'fastcgi-cache-purge-and-preload-nginx' );
        }
        return __( 'ngx_cache_purge generation keys are active (cache_purge_namespace / cache_purge_key), but the cache key of the single purge location is not $cache_purge_key (key_mismatch). Fix: use exactly $cache_purge_key as the cache key everywhere: fastcgi_cache_key $cache_purge_key; in the cached location and fastcgi_cache_purge ZONE_NAME $cache_purge_key; in the purge location (with the same cache_purge_key binding), then reload nginx.', 'fastcgi-cache-purge-and-preload-nginx' );
    }
    if ( $scope === 'all' ) {
        return __( 'ngx_cache_purge found no usable cache zone for the Purge All location (cache_unavailable). Fix: set fastcgi_cache ZONE_NAME; in that location, using the keys_zone name from fastcgi_cache_path, then reload nginx.', 'fastcgi-cache-purge-and-preload-nginx' );
    }
    return __( 'ngx_cache_purge found no usable cache zone for the single purge location (cache_unavailable). Fix: name an existing zone (the keys_zone of fastcgi_cache_path) in the fastcgi_cache_purge directive and reload nginx. The nginx error log names the missing zone.', 'fastcgi-cache-purge-and-preload-nginx' );
}

/**
 * Persistent explanation for the settings page: why HTTP Purge falls back although
 * nobody saw an error. '' when there is nothing to fix.
 */
function nppp_http_purge_config_notice(): string {
    if ( ! nppp_http_purge_enabled() ) {
        return '';
    }
    $issues = nppp_http_purge_state()['issue'];
    $parts  = [];
    foreach ( [ 'single', 'all' ] as $scope ) {
        if ( isset( $issues[ $scope ] ) ) {
            $parts[] = sprintf(
                /* translators: 1: Single or All, 2: what to fix in nginx */
                __( 'HTTP PURGE %1$s: %2$s', 'fastcgi-cache-purge-and-preload-nginx' ),
                $scope === 'all' ? 'ALL' : 'SINGLE',
                nppp_http_purge_config_fix( $scope, $issues[ $scope ]['code'] )
            );
        }
    }
    if ( ! $parts ) {
        return '';
    }
    return implode( ' ', $parts ) . ' ' . __( 'Until fixed, NPP purges through the filesystem (slower).', 'fastcgi-cache-purge-and-preload-nginx' );
}

/**
 * Codes of the advisory about the queue status endpoint (see nppp_http_purge_queue_status_hint()).
 *   queue_blind  Purge All answered 202 (background queue) and no status path is set.
 *   queue_dead   same, a path is set but it does not answer like a status endpoint.
 *   gen_blind    generation mode (200 invalidated) and no status path is set.
 *   gen_dead     generation mode, a path is set but it does not answer.
 */
function nppp_http_purge_hint_codes(): array {
    return [ 'queue_blind', 'queue_dead', 'gen_blind', 'gen_dead' ];
}

/**
 * Remembers the advisory (one for the whole site). An unchanged advisory younger than
 * 6 hours causes no write.
 *
 * @param string $code   One of nppp_http_purge_hint_codes().
 * @param string $detail Failure reason from nppp_http_purge_queue_snapshot() ('' when none).
 */
function nppp_http_purge_hint_set( string $code, string $detail = '' ): void {
    if ( ! in_array( $code, nppp_http_purge_hint_codes(), true ) ) {
        return;
    }
    $detail = preg_match( '/^[a-z_]{1,16}$/', $detail ) ? $detail : '';

    $state = nppp_http_purge_state();
    $cur   = $state['hint'] ?? [];
    if ( ! empty( $cur )
        && $cur['code'] === $code
        && $cur['detail'] === $detail
        && ( time() - (int) $cur['at'] ) < 6 * HOUR_IN_SECONDS ) {
        return;
    }

    $state['hint'] = [ 'code' => $code, 'detail' => $detail, 'at' => time() ];
    nppp_http_purge_state_save( $state );
}

/**
 * Forgets the advisory once Purge All showed the queue / generation state can be observed
 * (or that no background queue is in play any more). Writes only when one is stored.
 */
function nppp_http_purge_hint_clear(): void {
    $state = nppp_http_purge_state();
    if ( empty( $state['hint'] ) ) {
        return;
    }
    $state['hint'] = [];
    nppp_http_purge_state_save( $state );
}

/**
 * Advisory for the Settings page, shown under the Purge Queue Status Path field.
 *
 * Nginx's configuration cannot be read from PHP, but the Purge All answer reveals it: 202 means
 * the background queue is on, "invalidated" means generation mode. When the queue cannot be
 * observed the administrator is told what to set. The advisory is hidden while the field no
 * longer matches the situation it describes (the next Purge All re-checks it).
 *
 * @return array{level:string,text:string}|null level is 'warning' (data-affecting) or 'info'.
 */
function nppp_http_purge_queue_status_hint(): ?array {
    if ( ! nppp_http_purge_enabled() ) {
        return null;
    }

    $hint = nppp_http_purge_state()['hint'] ?? [];
    if ( empty( $hint ) ) {
        return null;
    }

    $has_url = nppp_http_purge_queue_status_url() !== '';
    $dead    = in_array( $hint['code'], [ 'queue_dead', 'gen_dead' ], true );
    if ( $dead !== $has_url ) {
        return null; // *_blind needs an empty field, *_dead a filled one
    }

    $reasons = [
        'unreachable' => __( 'the endpoint could not be reached', 'fastcgi-cache-purge-and-preload-nginx' ),
        'not_found'   => __( 'HTTP 404, the location does not exist in Nginx (or ngx_cache_purge is older than v4)', 'fastcgi-cache-purge-and-preload-nginx' ),
        'denied'      => __( 'access was denied (HTTP 401/403), check allow/deny of that location', 'fastcgi-cache-purge-and-preload-nginx' ),
        'not_status'  => __( 'the path is served by something other than a cache_purge_queue_status location', 'fastcgi-cache-purge-and-preload-nginx' ),
        'bad_reply'   => __( 'the reply was not a queue status document', 'fastcgi-cache-purge-and-preload-nginx' ),
    ];
    $reason = $reasons[ $hint['detail'] ] ?? $reasons['bad_reply'];

    switch ( $hint['code'] ) {
        case 'queue_blind':
            return [ 'level' => 'warning', 'text' => __( 'The last Purge All was accepted by Nginx as a background job (202), but no Purge Queue Status Path is set, so NPP cannot see when the queue has finished. On a large cache the filesystem purge and the preload may start while Nginx is still deleting, and Nginx can then delete the freshly preloaded pages. Fix: add a cache_purge_queue_status location in Nginx (see Help > HTTP Purge) and enter its path here.', 'fastcgi-cache-purge-and-preload-nginx' ) ];
        case 'queue_dead':
            return [ 'level' => 'warning', 'text' => sprintf(
                /* translators: %s: why the status endpoint failed */
                __( 'The last Purge All was accepted by Nginx as a background job (202), but the configured Purge Queue Status Path did not answer like a queue status endpoint: %s. NPP cannot see the queue, so the filesystem purge and the preload may start while Nginx is still deleting and the freshly preloaded pages can be deleted. Fix the location or the path; the next Purge All re-checks it.', 'fastcgi-cache-purge-and-preload-nginx' ),
                $reason
            ) ];
        case 'gen_dead':
            return [ 'level' => 'info', 'text' => sprintf(
                /* translators: %s: why the status endpoint failed */
                __( 'ngx_cache_purge generation mode is active, but the configured Purge Queue Status Path did not answer like a queue status endpoint: %s. Purge All is not affected, but Status and Advanced may list files that Nginx has already invalidated and not yet deleted.', 'fastcgi-cache-purge-and-preload-nginx' ),
                $reason
            ) ];
        default: // gen_blind
            return [ 'level' => 'info', 'text' => __( 'ngx_cache_purge generation mode is active but no Purge Queue Status Path is set. Purge All is not affected, but Status and Advanced may list files that Nginx has already invalidated and not yet deleted. Set the path of your cache_purge_queue_status location to hide them.', 'fastcgi-cache-purge-and-preload-nginx' ) ];
    }
}

/**
 * Methods the plugin may use besides GET (only the module's own PURGE by default).
 */
function nppp_http_purge_method_pool(): array {
    $pool = [ 'GET' ];
    foreach ( (array) apply_filters( 'nppp_http_purge_retry_methods', [ 'PURGE' ] ) as $m ) {
        $m = strtoupper( trim( (string) $m ) );
        if ( preg_match( '/^[A-Z]{3,10}$/', $m ) && ! in_array( $m, $pool, true ) ) {
            $pool[] = $m;
        }
    }
    return $pool;
}

/**
 * Request method learned for $scope (GET until the endpoint proved otherwise).
 */
function nppp_http_purge_method_for( string $scope ): string {
    $learned = nppp_http_purge_state()['methods'][ $scope ] ?? 'GET';
    if ( in_array( $learned, nppp_http_purge_method_pool(), true ) ) {
        return $learned;
    }

    // A method the module itself advertised (v4 405 Allow) is not in the pool; keep it.
    // PURGE stays pool-gated so the nppp_http_purge_retry_methods filter can still drop it.
    return ( $learned !== 'PURGE' && nppp_http_purge_is_custom_method( $learned ) ) ? $learned : 'GET';
}

/**
 * Remembers the working method for $scope, or forgets it again for GET.
 */
function nppp_http_purge_remember_method( string $scope, string $method ): void {
    $state   = nppp_http_purge_state();
    $current = $state['methods'][ $scope ] ?? 'GET';

    if ( $current === $method ) {
        return; // nothing changed, no write on the hot path
    }

    if ( $method === 'GET' ) {
        unset( $state['methods'][ $scope ] );
    } else {
        $state['methods'][ $scope ] = $method;
    }
    nppp_http_purge_state_save( $state );
}

/**
 * True when $scope ('single' | 'all') answered "invalidated" the last time it was used:
 * ngx_cache_purge v4 generation mode (cache_purge_key). The cache files carry
 * module-generated keys there, so the index / ripgrep / PHP scan layers cannot find
 * them and cannot finish a purge that HTTP did not complete.
 */
function nppp_http_purge_generation_mode( string $scope ): bool {
    return ! empty( nppp_http_purge_state()['ns'][ $scope ] );
}

/**
 * Remembers (or forgets) generation mode for $scope. Writes only on change.
 */
function nppp_http_purge_remember_generation( string $scope, bool $on ): void {
    $state = nppp_http_purge_state();

    if ( ! empty( $state['ns'][ $scope ] ) === $on ) {
        return; // nothing changed, no write on the hot path
    }

    if ( $on ) {
        $state['ns'][ $scope ] = true;
    } else {
        unset( $state['ns'][ $scope ] );
    }
    nppp_http_purge_state_save( $state );
}

/**
 * True for a method token that is neither GET nor a standard HTTP verb: the module's
 * own purge method (PURGE, or whatever "xxx_cache_purge <method>" names).
 */
function nppp_http_purge_is_custom_method( string $m ): bool {
    return (bool) preg_match( '/^[A-Z]{3,10}$/', $m )
        && ! in_array( $m, [ 'GET', 'HEAD', 'POST', 'PUT', 'DELETE', 'PATCH', 'OPTIONS', 'TRACE', 'CONNECT', 'REFRESH' ], true );
}

/**
 * Should a GET answer that proves nothing (404 or a redirect) be followed by one probe
 * with the module's purge method? Not while a probe ran recently or GET is known to work.
 */
function nppp_http_purge_probe_due( string $scope, string $outcome, int $status ): bool {
    if ( $outcome !== 'not_found'
        && ! ( $outcome === 'unexpected' && $status >= 300 && $status < 400 )
    ) {
        return false;
    }

    return empty( nppp_http_purge_state()['probe'][ $scope ] );
}

/**
 * Suppresses PURGE probes for $scope for $ttl seconds. Rewrites the state at most once
 * per $ttl / 2, so a working endpoint costs no write on the hot path.
 */
function nppp_http_purge_probe_mark( string $scope, int $ttl ): void {
    $state = nppp_http_purge_state();
    $now   = time();

    if ( (int) ( $state['probe'][ $scope ] ?? 0 ) >= $now + intdiv( $ttl, 2 ) ) {
        return;
    }

    $state['probe'][ $scope ] = $now + $ttl;
    nppp_http_purge_state_save( $state );
}

/* ============================================================================
 * 3. Transport
 * ========================================================================= */

/**
 * host[:port] of $url the way a browser sends it in the Host header: the port is kept
 * only when it is not the default of the scheme. nginx's $host drops the port while
 * $http_host keeps it, so a cache key built from $http_host only matches when the
 * plugin sends the port as well.
 */
function nppp_http_purge_authority( string $url ): string {
    $p    = wp_parse_url( $url );
    $host = strtolower( (string) ( $p['host'] ?? '' ) );
    if ( $host === '' ) {
        return '';
    }

    $port    = isset( $p['port'] ) ? (int) $p['port'] : 0;
    $default = strtolower( (string) ( $p['scheme'] ?? '' ) ) === 'https' ? 443 : 80;

    return ( $port > 0 && $port !== $default ) ? $host . ':' . $port : $host;
}

/**
 * http_api_curl callback: applies the short connection timeout to our own
 * requests only (WordPress sets CONNECTTIMEOUT = overall timeout).
 *
 * @param resource|object $handle
 * @param array           $args
 */
function nppp_http_purge_curl_connect_timeout( $handle, $args = [] ): void {
    if ( ! is_array( $args ) || empty( $args['nppp_http_purge_connect_ms'] ) ) {
        return;
    }
    if ( ! is_resource( $handle ) && ! is_object( $handle ) ) {
        return;
    }
    if ( defined( 'CURLOPT_CONNECTTIMEOUT_MS' ) ) {
        @curl_setopt( $handle, CURLOPT_CONNECTTIMEOUT_MS, (int) $args['nppp_http_purge_connect_ms'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_setopt
    }
}

/**
 * Sends one request and normalizes the outcome. Never throws.
 *
 * @return array{error:string,message:string,status:int,code:string,allow:string,body:string}
 *         error: '' (got a response) | 'timeout' (nginx alive but slow) | 'unreachable'
 */
function nppp_http_purge_fetch( string $method, string $url, int $timeout ): array {
    $out = [ 'error' => '', 'message' => '', 'status' => 0, 'code' => '', 'allow' => '', 'body' => '' ];

    add_action( 'http_api_curl', 'nppp_http_purge_curl_connect_timeout', 10, 3 );
    try {
        $response = wp_remote_request( $url, [
            'method'                     => $method,
            'timeout'                    => $timeout,
            'sslverify'                  => false,
            'redirection'                => 0,
            'limit_response_size'        => NPPP_HTTP_PURGE_BODY_LIMIT,
            'nppp_http_purge_connect_ms' => nppp_http_purge_connect_timeout_ms( $timeout ),
            'headers'                    => [
                'Host' => (string) apply_filters( 'nppp_http_purge_host_header', nppp_http_purge_authority( home_url() ) ),
            ],
        ] );
    } catch ( \Throwable $e ) {
        // "Never throws" is part of this function's contract: a misbehaving
        // http_api_* filter or transport must degrade to the filesystem fallback,
        // not abort a Purge All half way through.
        $response = new WP_Error( 'nppp_http_purge_exception', get_class( $e ) . ': ' . $e->getMessage() );
    } finally {
        remove_action( 'http_api_curl', 'nppp_http_purge_curl_connect_timeout', 10 );
    }

    if ( is_wp_error( $response ) ) {
        $out['message'] = substr( implode( ' | ', (array) $response->get_error_messages() ), 0, 160 );
        $out['error']   = nppp_http_purge_error_kind( $out['message'] );
        return $out;
    }

    $header = static function ( string $name ) use ( $response ): string {
        $v = wp_remote_retrieve_header( $response, $name );
        return is_array( $v ) ? (string) reset( $v ) : (string) $v;
    };

    $out['status'] = (int) wp_remote_retrieve_response_code( $response );
    $out['allow']  = preg_match( '/^[A-Za-z, -]{1,64}$/', $header( 'allow' ) ) ? $header( 'allow' ) : '';
    $out['body']   = substr( (string) wp_remote_retrieve_body( $response ), 0, NPPP_HTTP_PURGE_BODY_LIMIT );

    $code = $header( 'cache-purge-code' );
    if ( $code === '' && isset( $out['body'][0] ) && $out['body'][0] === '{' ) {
        // A proxy may strip unknown headers; the v4 JSON body repeats the code.
        $json = json_decode( $out['body'], true );
        $code = ( is_array( $json ) && isset( $json['code'] ) && is_string( $json['code'] )
                  && in_array( $json['code'], nppp_http_purge_known_codes(), true ) ) ? $json['code'] : '';
    }
    $out['code'] = preg_match( '/^[a-z_]{1,32}$/', $code ) ? $code : ( $code === '' ? '' : 'invalid' );

    return $out;
}

/**
 * Splits a transport failure into "slow but alive" and "cannot connect".
 *
 *   "Operation timed out ..."        nginx accepted the request and is still
 *                                    working (large cache, slow storage): alive.
 *   "Connection timed out ...",
 *   "Resolving timed out ...",
 *   refused / no route / DNS / TLS:  endpoint not reachable: a firewall that
 *                                    drops packets must not be mistaken for a
 *                                    slow healthy server.
 */
function nppp_http_purge_error_kind( string $message ): string {
    $m = strtolower( $message );

    if ( preg_match( '/(connection|connect|resolving|resolve)[a-z ]* tim(ed? ?out|eout)/', $m ) ) {
        return 'unreachable';
    }
    if ( strpos( $m, 'timed out' ) !== false || strpos( $m, 'timeout' ) !== false ) {
        return 'timeout';
    }

    return 'unreachable';
}

/**
 * Result codes defined by the v4 module (docs/HTTP_RESPONSES.md).
 */
function nppp_http_purge_known_codes(): array {
    return [
        'purged', 'no_match', 'accepted', 'invalidated', 'status',
        'refresh_complete', 'refresh_incomplete', 'invalid_selector', 'invalid_cursor',
        'access_denied', 'method_not_allowed', 'cache_unavailable', 'key_mismatch',
        'delete_failed', 'scan_failed', 'internal_error',
    ];
}

/* ============================================================================
 * 4. Classification (pure: no I/O, no WordPress calls except the opt-out filter)
 * ========================================================================= */

/**
 * v2/v3 bodies always carry the key: "Key : ..." (v2), "Key: ..." (v3 html/text),
 * {"Key": ...} (v3 json) or <Key> (v3 xml). A 200 without Cache-Purge-Code and
 * without that marker is some other page, not a purge.
 */
function nppp_http_purge_legacy_body_ok( string $body, string $expect = '' ): bool {
    if ( ! apply_filters( 'nppp_http_purge_verify_legacy_body', true ) ) {
        return true;
    }
    if ( trim( $body ) === '' ) {
        return false; // An empty unrelated 200 is not evidence of a purge.
    }
    if ( ! preg_match( '/"Key"\s*:\s*"|<Key>|(?:^|>)Key\s?:/m', $body ) ) {
        return false;
    }

    // The marker alone also matches ordinary pages ("<li>Key: ...", inline JSON with a
    // "Key" property). v2/v3 echo the cache key they built, and that key contains the
    // path of the page, so a genuine module answer for this URL contains the path too.
    // $expect is '' when the key is decode-ambiguous (nginx echoes the decoded form) or
    // when there is no single page path (Purge All).
    return $expect === '' || strpos( $body, $expect ) !== false;
}

/**
 * Maps a response to an outcome.
 *
 * Outcomes: purged, invalidated, no_match, legacy_miss, accepted, bad_selector,
 *           busy, not_found, server_error (no lock) | denied, method, foreign,
 *           wrong_endpoint, config, internal, unexpected (lock).
 *
 * @param string $scope 'single' | 'all'
 * @param array  $res   status:int, code:string (Cache-Purge-Code or ''), body:string,
 *                      expect:string (optional, page path a v2/v3 body must echo; '' = no check)
 */
function nppp_http_purge_classify( string $scope, array $res ): string {
    $status = (int) $res['status'];
    $code   = (string) $res['code'];

    // v4: the module's own result catalog is authoritative.
    switch ( $code ) {
        case 'purged':
            return ( $status === 200 || $status === 204 ) ? 'purged' : 'unexpected';
        case 'no_match':
            return ( $status === 200 || $status === 204 ) ? 'no_match' : 'unexpected';
        case 'invalidated':
            return ( $status === 200 || $status === 204 ) ? 'invalidated' : 'unexpected';
        case 'accepted':
            if ( $status !== 202 ) {
                return 'unexpected';
            }
            // Exact-key purges are always synchronous: a 202 from the SINGLE endpoint
            // means it is bound to a wildcard / purge_all location.
            return $scope === 'single' ? 'wrong_endpoint' : 'accepted';
        case 'status':
        case 'refresh_complete':
        case 'refresh_incomplete':
        case 'invalid_cursor':
            return 'wrong_endpoint'; // the location is bound to the status or REFRESH handler
        case 'invalid_selector':
            return 'bad_selector';
        case 'access_denied':
            return 'denied';
        case 'method_not_allowed':
            return 'method';
        case 'cache_unavailable':
        case 'key_mismatch':
            return 'config';
        case 'delete_failed':
        case 'scan_failed':
            return 'server_error';
        case 'internal_error':
            return 'internal';
    }

    // Unknown (future) module code: never claim success on something unrecognised.
    if ( $code !== '' ) {
        return 'unexpected';
    }

    // v2 / v3 (and anything that is not the module): HTTP status table.
    switch ( true ) {
        case $status === 200:
            return nppp_http_purge_legacy_body_ok( (string) $res['body'], (string) ( $res['expect'] ?? '' ) ) ? 'purged' : 'foreign';
        case $status === 202:
            return $scope === 'single' ? 'wrong_endpoint' : 'accepted';   // v3 background queue
        case $status === 204:
            return 'foreign';    // the module only answers 204 together with Cache-Purge-Code
        case $status === 400 || $status === 414 || $status === 431:
            return 'bad_selector';
        case $status === 403:
            return 'denied';
        case $status === 404:
            return 'not_found';  // v2.3 miss, v3 miss with legacy_status off, or no such location
        case $status === 405:
            return 'method';
        case $status === 412:
            // Exact purge: v2.5/v3 miss. A Purge All endpoint never answers a miss,
            // so 412 there means it is wired to a single-key purge location.
            return $scope === 'all' ? 'wrong_endpoint' : 'legacy_miss';
        case $status === 429:
            return 'busy';
        case $status === 500:
            return 'server_error';
        case $status === 502 || $status === 503 || $status === 504:
            return 'internal';
    }

    return 'unexpected';
}

/**
 * Why nginx and the cache file may NOT agree on the key for $url ('' = they do).
 *
 * nginx builds the key from $1 of the location regex, which is the DECODED and
 * normalized uri, while the cache file holds the raw $request_uri. They differ
 * for percent-encoding, merged slashes and dot segments, so an answer there only
 * says something about the key nginx built, not about the page's own entry:
 *   - a "miss" does not mean "the page is not cached";
 *   - a "purged" does not mean "the page is gone" (/p/%41/ deletes /p/A/).
 * A percent sign in the QUERY is not ambiguous: $args is passed through raw.
 */
function nppp_http_purge_key_ambiguity_reason( string $url ): string {
    $path  = (string) wp_parse_url( $url, PHP_URL_PATH );
    $query = (string) wp_parse_url( $url, PHP_URL_QUERY );

    if ( strpos( $path, '%' ) !== false ) {
        return 'percent-encoded path';
    }
    if ( preg_match( '/[^\x20-\x7E]/', $path . $query ) ) {
        return 'non-ASCII characters';
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

/**
 * Why a "miss" cannot be trusted for $url ('' = it can): key ambiguity first,
 * then host and scheme (see below).
 */
function nppp_http_purge_untrusted_miss_reason( string $url, string $purge_url = '' ): string {
    $reason = nppp_http_purge_key_ambiguity_reason( $url );
    if ( $reason !== '' ) {
        return $reason;
    }

    // The key holds $host and (normally) $scheme of the PURGE request. The plugin
    // sends Host: <home_url host> and takes the scheme from the purge origin, not
    // from $url, so another host (multisite, www alias) or scheme (Custom Base URL
    // over http for an https site) builds a key that can never match the page.
    $host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
    $home = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
    if ( $host !== '' && $home !== '' && $host !== $home ) {
        return 'host differs from the Host header sent to nginx';
    }
    if ( $purge_url !== '' ) {
        $from = strtolower( (string) wp_parse_url( $url, PHP_URL_SCHEME ) );
        $to   = strtolower( (string) wp_parse_url( $purge_url, PHP_URL_SCHEME ) );
        if ( $from !== '' && $to !== '' && $from !== $to ) {
            return 'scheme differs from the purge request';
        }
    }

    return '';
}

/**
 * Why a "purged" / "invalidated" answer cannot be trusted for $url ('' = it can).
 *
 * The cache key normally holds $scheme of the PURGE request, and the plugin takes the
 * scheme from the purge origin, not from $url. With a Custom Base URL on another
 * scheme (http://127.0.0.1 for an https site) nginx deleted the OTHER scheme's entry
 * and the page's own entry stays cached.
 */
function nppp_http_purge_scheme_reason( string $url, string $purge_url ): string {
    $from = strtolower( (string) wp_parse_url( $url, PHP_URL_SCHEME ) );
    $to   = strtolower( (string) wp_parse_url( $purge_url, PHP_URL_SCHEME ) );

    return ( $from !== '' && $to !== '' && $from !== $to ) ? 'scheme differs from the purge request' : '';
}

/**
 * The module turns any key ending in "*" into a wildcard purge, and nginx
 * decodes "%2A" before the key is built. Such a URL must never be sent.
 */
function nppp_http_purge_is_wildcard_url( string $url ): bool {
    $path  = (string) wp_parse_url( $url, PHP_URL_PATH );
    $query = (string) wp_parse_url( $url, PHP_URL_QUERY );
    $tail  = $path . ( $query !== '' ? '?' . $query : '' );

    return (bool) preg_match( '/(?:\*|%2a)$/i', $tail );
}

/* ============================================================================
 * 5. Exchange: request, classify, retry with the module's method, lock, log
 * ========================================================================= */

/**
 * Runs one purge exchange for $scope and returns the final result.
 *
 * Flow: send (learned method or GET) -> classify. When the answer shows that the
 * endpoint wants the module's own method (405 with Allow: PURGE, or a "200" that
 * is really the site page because the module is bound to PURGE inline) the
 * request is repeated once with that method and the working method is
 * remembered. Failures arm the matching lock and write one log line.
 *
 * @return array{outcome:string,status:int,code:string,message:string,allow:string,method:string}
 */
function nppp_http_purge_exchange( string $scope, string $url, int $timeout, string $expect = '' ): array {
    $pool   = nppp_http_purge_method_pool();
    $method = nppp_http_purge_method_for( $scope );
    $tried  = [];

    do {
        $tried[] = $method;
        $res     = nppp_http_purge_fetch( $method, $url, $timeout );

        if ( $res['error'] !== '' ) {
            $outcome = $res['error'];
            break;
        }

        $res['expect'] = $expect;
        $outcome = nppp_http_purge_classify( $scope, $res );
        $next    = '';

        if ( $outcome === 'method' || $outcome === 'foreign' ) {
            $allowed    = $outcome === 'method'
                ? array_map( 'trim', explode( ',', strtoupper( $res['allow'] ) ) )
                : $pool;
            $candidates = $pool;

            // A 405 written by the module itself (Cache-Purge-Code) lists the configured
            // purge method in Allow, possibly a custom name ("xxx_cache_purge MYPURGE").
            // The Allow list of a foreign 405 is arbitrary and never trusted beyond the pool.
            if ( $outcome === 'method' && $res['code'] === 'method_not_allowed' ) {
                foreach ( $allowed as $m ) {
                    if ( nppp_http_purge_is_custom_method( $m ) && ! in_array( $m, $candidates, true ) ) {
                        $candidates[] = $m;
                    }
                }
            }

            foreach ( $candidates as $candidate ) {
                if ( ! in_array( $candidate, $tried, true ) && in_array( $candidate, $allowed, true ) ) {
                    $next = $candidate;
                    break;
                }
            }
        } elseif ( $method === 'GET' && count( $tried ) === 1
            && nppp_http_purge_probe_due( $scope, $outcome, (int) $res['status'] )
        ) {
            // A GET answer that proves nothing (404, redirect) can come from an inline
            // "xxx_cache_purge PURGE" location: the module only reacts to its own method and
            // hands every GET to the origin (v3 purge-only: bare 404). Try the pool's purge
            // method once, then stay quiet for 6 hours.
            nppp_http_purge_probe_mark( $scope, 6 * HOUR_IN_SECONDS );
            foreach ( $pool as $candidate ) {
                if ( $candidate !== 'GET' ) {
                    $next = $candidate;
                    break;
                }
            }
        }

        if ( $next === '' || count( $tried ) >= 2 ) {
            break;
        }
        $method = $next;
    } while ( true );

    $result = [
        'outcome' => $outcome,
        'status'  => (int) $res['status'],
        'code'    => (string) $res['code'],
        'message' => (string) $res['message'],
        'allow'   => (string) $res['allow'],
        'method'  => $method,
        'body'    => (string) $res['body'],
    ];

    if ( in_array( $outcome, [ 'purged', 'invalidated', 'no_match', 'legacy_miss', 'accepted' ], true ) ) {
        nppp_http_purge_issue_clear( $scope );
        nppp_http_purge_remember_method( $scope, $method );
        if ( $method === 'GET' ) {
            nppp_http_purge_probe_mark( $scope, DAY_IN_SECONDS ); // GET works: no probe needed for a while
        }
        if ( $outcome === 'invalidated' ) {
            nppp_http_purge_remember_generation( $scope, true );
        } elseif ( $outcome !== 'accepted' ) {
            nppp_http_purge_remember_generation( $scope, false );
        }
    } else {
        nppp_http_purge_report_failure( $scope, $url, $timeout, $result );
    }

    /**
     * Fires after every HTTP purge exchange (diagnostics, tests, metrics).
     */
    do_action( 'nppp_http_purge_result', $scope, $outcome, $url, $result['status'], $result['code'] );

    return $result;
}

/**
 * Arms the lock for a failed outcome and writes one log line (log only, never a
 * dashboard notice: the filesystem result is what the admin sees).
 */
function nppp_http_purge_report_failure( string $scope, string $url, int $timeout, array $r ): void {
    $outcome = $r['outcome'];
    $label   = $scope === 'all' ? 'ALL' : 'SINGLE';
    $shown   = nppp_http_purge_log_target( $scope === 'all' ? 'all' : 'single' );
    $status  = $r['status'];

    // Outcomes that are expected module behaviour: no lock, no noise.
    if ( in_array( $outcome, [ 'not_found', 'busy', 'server_error', 'bad_selector' ], true ) ) {
        if ( $outcome === 'bad_selector' ) {
            nppp_display_admin_notice( 'info', sprintf(
                /* translators: 1: Single or All, 2: purge URL */
                __( 'INFO HTTP PURGE %1$s: nginx rejected the request for %2$s as invalid or too long (400). Falling back to filesystem purge for this URL only; HTTP Purge stays enabled.', 'fastcgi-cache-purge-and-preload-nginx' ),
                $label, $shown
            ), true, false );
        } elseif ( $outcome === 'busy' ) {
            nppp_display_admin_notice( 'info', sprintf(
                /* translators: 1: Single or All, 2: purge URL */
                __( 'INFO HTTP PURGE %1$s: %2$s answered 429 (rate limited). Falling back to filesystem purge; HTTP Purge stays enabled.', 'fastcgi-cache-purge-and-preload-nginx' ),
                $label, $shown
            ), true, false );
        } elseif ( $outcome === 'server_error' ) {
            nppp_display_admin_notice( 'info', sprintf(
                /* translators: 1: Single or All, 2: purge URL, 3: HTTP status */
                __( 'INFO HTTP PURGE %1$s: %2$s reported a server-side error (%3$d); some files may already be deleted. Falling back to filesystem purge.', 'fastcgi-cache-purge-and-preload-nginx' ),
                $label, $shown, $status
            ), true, false );
        }
        return;
    }

    if ( $outcome === 'timeout' ) {
        if ( $scope === 'all' ) {
            return; // nppp_http_purge_all() waits for nginx and reports what it saw
        }
        nppp_display_admin_notice( 'error', sprintf(
            /* translators: 1: Single or All, 2: purge URL, 3: timeout in seconds */
            __( 'INFO HTTP PURGE %1$s: The cache purge request to %2$s timed out after %3$d second(s). Nginx is probably still working (large cache or slow storage). Falling back to direct filesystem purge (It may now race with the still-running nginx operation). Raise the timeout via the "nppp_http_purge_timeout" filter for large caches or FUSE/network storage.', 'fastcgi-cache-purge-and-preload-nginx' ),
            $label, $shown, $timeout
        ), true, false );
        return;
    }

    // From here on the endpoint is considered broken: pick scope and duration.
    $lock_scope = $scope;
    $ttl        = HOUR_IN_SECONDS;

    if ( $outcome === 'unreachable' ) {
        $lock_scope = '*';
        $ttl        = 15 * MINUTE_IN_SECONDS;
    } elseif ( $outcome === 'internal' ) {
        $ttl = 5 * MINUTE_IN_SECONDS;
    } elseif ( $outcome === 'unexpected'
        && ( ( $status >= 300 && $status < 400 ) || $status === 401 || $status === 407 ) ) {
        $lock_scope = '*'; // redirect or authentication in front of nginx: affects every endpoint
    }

    $ttl = max( 60, (int) apply_filters( 'nppp_http_purge_lock_ttl', $ttl, $outcome, $scope ) );
    nppp_http_purge_arm( $lock_scope, $outcome === 'unexpected' ? (string) $status : $outcome, $ttl );
    $minutes = (int) ceil( $ttl / 60 );

    switch ( $outcome ) {
        case 'unreachable':
            nppp_display_admin_notice( 'error', sprintf(
                /* translators: 1: Single or All, 2: purge URL, 3: transport error, 4: minutes */
                __( 'ERROR HTTP PURGE %1$s: Connection to %2$s failed (%3$s). HTTP Purge disabled for %4$d minute(s). Falling back to direct filesystem purge. Check that the purge endpoint is reachable (DNS, firewall, proxy).', 'fastcgi-cache-purge-and-preload-nginx' ),
                $label, $shown, nppp_http_purge_log_reason( (string) $r['message'] ), $minutes
            ), true, false );
            break;

        case 'denied':
            nppp_display_admin_notice( 'error', sprintf(
                /* translators: 1: Single or All, 2: purge URL, 3: minutes */
                __( 'ERROR HTTP PURGE %1$s: Access denied (403) to %2$s. Your server IP is not in the Nginx "from" whitelist. HTTP Purge disabled for %3$d minute(s). Check the allow/deny directives in the matching Nginx purge location block.', 'fastcgi-cache-purge-and-preload-nginx' ),
                $label, $shown, $minutes
            ), true, false );
            break;

        case 'method':
            nppp_display_admin_notice( 'error', sprintf(
                /* translators: 1: Single or All, 2: purge URL, 3: Allow header, 4: minutes */
                __( 'ERROR HTTP PURGE %1$s: %2$s rejected the request method (405, Allow: %3$s). Use a dedicated purge location that accepts GET, or allow the PURGE method. HTTP Purge disabled for %4$d minute(s). Falling back to direct filesystem purge.', 'fastcgi-cache-purge-and-preload-nginx' ),
                $label, $shown, $r['allow'] !== '' ? $r['allow'] : '-', $minutes
            ), true, false );
            break;

        case 'foreign':
            nppp_display_admin_notice( 'error', sprintf(
                /* translators: 1: Single or All, 2: purge URL, 3: minutes */
                __( 'ERROR HTTP PURGE %1$s: %2$s answered, but the response does not come from ngx_cache_purge (it looks like a regular page), so nothing was purged. The purge location is missing or falls through to the site. HTTP Purge disabled for %3$d minute(s). Falling back to direct filesystem purge.', 'fastcgi-cache-purge-and-preload-nginx' ),
                $label, $shown, $minutes
            ), true, false );
            break;

        case 'wrong_endpoint':
            nppp_display_admin_notice( 'error', sprintf(
                /* translators: 1: Single or All, 2: purge URL, 3: HTTP status, 4: minutes */
                __( 'ERROR HTTP PURGE %1$s: %2$s answered %3$d but it is wired to a different ngx_cache_purge handler (status, REFRESH, exact-key purge, or a wildcard / purge_all location answering the single endpoint), not to this purge type. HTTP Purge disabled for %4$d minute(s). Check the Purge Path settings and the Nginx location block.', 'fastcgi-cache-purge-and-preload-nginx' ),
                $label, $shown, $status, $minutes
            ), true, false );
            break;

        case 'config':
            nppp_http_purge_issue_set( $scope, (string) $r['code'] );
            nppp_display_admin_notice( 'error', sprintf(
                /* translators: 1: Single or All, 2: purge URL, 3: what to fix in nginx (names the module code), 4: minutes */
                __( 'ERROR HTTP PURGE %1$s: %2$s answered 500. %3$s HTTP Purge is retried in %4$d minute(s); until then the filesystem purge is used.', 'fastcgi-cache-purge-and-preload-nginx' ),
                $label, $shown, nppp_http_purge_config_fix( $scope, (string) $r['code'] ), $minutes
            ), true, false );
            break;

        case 'internal':
            nppp_display_admin_notice( 'error', sprintf(
                /* translators: 1: Single or All, 2: purge URL, 3: HTTP status, 4: minutes */
                __( 'ERROR HTTP PURGE %1$s: %2$s answered %3$d (internal or upstream error). HTTP Purge paused for %4$d minute(s). Falling back to direct filesystem purge. Check the Nginx error log.', 'fastcgi-cache-purge-and-preload-nginx' ),
                $label, $shown, $status, $minutes
            ), true, false );
            break;

        default: // unexpected
            nppp_display_admin_notice( 'error', sprintf(
                /* translators: 1: Single or All, 2: HTTP status, 3: purge URL, 4: minutes */
                __( 'ERROR HTTP PURGE %1$s: Unexpected response %2$d from %3$s. HTTP Purge disabled for %4$d minute(s). Verify your Purge Path or Custom Base URL settings.', 'fastcgi-cache-purge-and-preload-nginx' ),
                $label, $status, $shown, $minutes
            ), true, false );
    }
}

/* ============================================================================
 * 6. Completion proof for Purge All (202 accepted, and a cross-check for 200)
 * ========================================================================= */

/**
 * Cache directory from the plugin settings ('' when unknown).
 */
function nppp_http_purge_cache_path(): string {
    $s = get_option( 'nginx_cache_settings', [] );
    $p = ( is_array( $s ) && ! empty( $s['nginx_cache_path'] ) ) ? (string) $s['nginx_cache_path'] : '';

    return $p !== '' ? rtrim( $p, '/' ) : '';
}

/**
 * Is there a cache file in $path that entered the directory before $since?
 *
 * ctime, never mtime: nginx sets a cache file's mtime to the upstream
 * Last-Modified, so a file written a second ago can look years old, while ctime is
 * the moment the file landed in the directory and cannot be set backwards. Files
 * of the same second as the purge are ignored on purpose (live traffic refills).
 * Only 32-hex file names under 1-2 hex-char level directories are looked at; the
 * plugin's tmp directory is never entered. Stops at the first old file.
 *
 * @return bool|null true: old file present | false: none | null: cannot tell
 *                   (missing / unreadable directory, open_basedir)
 */
function nppp_http_purge_has_entries_before( string $path, int $since ): ?bool {
    if ( $path === '' || ! @is_dir( $path ) ) {
        return null;
    }

    clearstatcache( true );
    $stack = [ $path ];
    $blind = false;

    while ( $stack ) {
        $dir = array_pop( $stack );
        $h   = @opendir( $dir );
        if ( $h === false ) {
            $blind = true;
            continue;
        }
        while ( ( $name = readdir( $h ) ) !== false ) {
            if ( $name === '.' || $name === '..' ) {
                continue;
            }
            $full = $dir . '/' . $name;
            if ( preg_match( '/^[0-9a-f]{32}$/', $name ) ) {
                $ct = @filectime( $full ); // false: vanished meanwhile, which is the goal
                if ( $ct !== false && $ct < $since ) {
                    closedir( $h );
                    return true;
                }
            } elseif ( preg_match( '/^[0-9a-f]{1,2}$/i', $name ) && @is_dir( $full ) && ! is_link( $full ) ) {
                $stack[] = $full;
            }
        }
        closedir( $h );
    }

    return $blind ? null : false;
}

/**
 * True when nginx said "done" but the plugin's own cache still holds files older
 * than the request: another cache zone was purged, or a delete failed silently.
 */
function nppp_http_purge_leftover( int $since ): bool {
    if ( ! apply_filters( 'nppp_http_purge_verify_empty', true ) ) {
        return false;
    }

    return nppp_http_purge_has_entries_before( nppp_http_purge_cache_path(), $since ) === true;
}

/**
 * Reads the aggregate counters of a `cache_purge_queue_status` location.
 *
 * @param string      $status_url
 * @param string|null $why Optional, out: why null was returned ('' on success): unreachable |
 *                         not_found | denied | not_status | bad_reply. Costs no extra request.
 * @return array{pending:int,active:int,exhausted:int,errors:int,scan_errors:int}|null
 */
function nppp_http_purge_queue_snapshot( string $status_url, ?string &$why = null ): ?array {
    $why = '';
    $res = nppp_http_purge_fetch( 'GET', $status_url, 3 );

    if ( $res['error'] !== '' || $res['status'] !== 200 || $res['code'] !== 'status' ) {
        if ( $res['error'] !== '' ) {
            $why = 'unreachable';
        } elseif ( $res['status'] === 404 ) {
            $why = 'not_found';
        } elseif ( $res['status'] === 401 || $res['status'] === 403 ) {
            $why = 'denied';
        } elseif ( $res['status'] === 200 ) {
            $why = 'not_status';
        } else {
            $why = 'bad_reply';
        }
        return null;
    }

    $json  = json_decode( $res['body'], true );
    $cache = is_array( $json ) && isset( $json['cache'] ) && is_array( $json['cache'] ) ? $json['cache'] : null;
    if ( $cache === null
        || ! isset( $cache['pending']['total'], $cache['active'] )
        || ! is_numeric( $cache['pending']['total'] ) || ! is_numeric( $cache['active'] ) ) {
        $why = 'bad_reply';
        return null;
    }

    return [
        'pending'     => (int) $cache['pending']['total'],
        'active'      => (int) $cache['active'],
        'exhausted'   => (int) ( $cache['cleanup']['exhausted'] ?? 0 ),
        'errors'      => (int) ( $cache['files']['errors'] ?? 0 ),
        'scan_errors' => (int) ( $cache['cleanup']['scan_errors'] ?? 0 ),
    ];
}

/**
 * Waits until a "202 accepted" background purge is finished.
 *
 * v4 issues no task id, so completion is read from the aggregate counters. The
 * task is queued BEFORE nginx sends the 202, so "pending + active == 0" on two
 * consecutive samples means it ran to the end.
 *
 *   status endpoint known  -> poll it (authoritative).
 *   otherwise / it died    -> poll the cache directory until no file older than the
 *                             purge is left, then give nginx a grace period to
 *                             finish its pass (best effort, see settle filter).
 *
 * @param int        $since      unix second at which the purge request was sent
 * @param string     $status_url '' when none
 * @param array|null $baseline   snapshot taken before the purge request
* @param float      $budget     seconds
 * @return string 'proven' | 'drained' | 'errors' | 'queued' | 'timeout' | 'blind'
 *   proven  queue idle twice, no new cleanup errors
 *   drained best effort: no old file left and the grace period passed
 *   errors  queue idle but cleanup errors/exhausted retries rose
 *   queued  budget over, the status endpoint still answers and the queue is still
 *           busy: nginx is verifiably working, completion can be awaited by status
 *   timeout budget over, old files still there (directory mode)
 *   blind   no status endpoint and the cache directory cannot be inspected
 */
function nppp_http_purge_await_background( int $since, string $status_url, ?array $baseline, float $budget ): string {
    $deadline   = microtime( true ) + max( 0.5, $budget );
    $use_status = $status_url !== '' && $baseline !== null;
    $path       = nppp_http_purge_cache_path();
    $settle_us  = 1000 * min( 10000, max( 0, (int) apply_filters( 'nppp_http_purge_async_settle_ms', 1500 ) ) );
    $sleep      = 100000; // 100 ms, backing off to 400 ms
    $idle       = 0;

    while ( true ) {
        usleep( $sleep );
        $sleep = min( 400000, (int) ( $sleep * 1.5 ) );

        if ( $use_status ) {
            $snap = nppp_http_purge_queue_snapshot( $status_url );
            if ( $snap === null ) {
                $use_status = false; // endpoint died: inspect the directory instead of guessing
            } else {
                $idle = ( $snap['pending'] === 0 && $snap['active'] === 0 ) ? $idle + 1 : 0;
                if ( $idle >= 2 ) {
                    return ( $snap['exhausted'] <= $baseline['exhausted']
                          && $snap['errors'] <= $baseline['errors']
                          && $snap['scan_errors'] <= $baseline['scan_errors'] ) ? 'proven' : 'errors';
                }
            }
        }

        if ( ! $use_status ) {
            $left = nppp_http_purge_has_entries_before( $path, $since );
            if ( $left === null ) {
                return 'blind';
            }
            if ( $left === false ) {
                usleep( $settle_us );
                if ( nppp_http_purge_has_entries_before( $path, $since ) === false ) {
                    return 'drained';
                }
            }
        }

        if ( microtime( true ) >= $deadline ) {
            return $use_status ? 'queued' : 'timeout';
        }
    }
}

/* ============================================================================
 * 6b. Preload gate: hold the crawl back until nginx's queue is idle
 * ========================================================================= */

/**
 * Request-scoped hand-off between a Purge All that left a live background queue
 * behind and the preload spawn that follows it in the same request.
 *
 *   arm   remember the pending purge (nppp_http_purge_all() -> purge helper)
 *   take  hand it to the spawn once (marks the gate as used)
 *   used  did this request gate a crawl? (the success notices differ)
 *   clear forget everything
 *
 * @param string     $op
 * @param array|null $pending
 * @return array|bool|null
 */
function nppp_http_purge_gate_state( string $op, ?array $pending = null ) {
    static $armed = null;
    static $used  = false;

    switch ( $op ) {
        case 'arm':
            $armed = $pending;
            $used  = false;
            return null;
        case 'take':
            $p     = $armed;
            $armed = null;
            // A stale hand-off (long-running CLI process) must not gate an unrelated crawl.
            if ( $p === null || ( time() - (int) ( $p['armed_at'] ?? 0 ) ) > 300 ) {
                // No hand-off in this request. A Purge All from an EARLIER request may still
                // have a live queue (manual Preload All, REST, WP-CLI, cron, Mobile phase):
                // the persisted marker is confirmed against the queue status first.
                $p = nppp_http_purge_gate_pending();
                if ( $p === null ) {
                    return null;
                }
            }
            $used = true;
            return $p;
        case 'used':
            return $used;
        case 'clear':
        default:
            $armed = null;
            $used  = false;
            return null;
    }
}

function nppp_http_purge_gate_arm( array $pending ): void {
    nppp_http_purge_gate_state( 'arm', $pending );
}

/** True when the crawl spawned by this request was held back behind a still-running purge queue. */
function nppp_http_purge_gate_used(): bool {
    return (bool) nppp_http_purge_gate_state( 'used' );
}

/**
 * Longest the spawned shell waits for the queue (filter nppp_http_purge_gate_max_wait, 30 s
 * floor, 7200 s cap, default 900 s). It is also the lifetime of the persisted marker, so the
 * two can never disagree.
 */
function nppp_http_purge_gate_max_wait(): int {
    return min( 7200, max( 30, (int) apply_filters( 'nppp_http_purge_gate_max_wait', 900 ) ) );
}

/**
 * Persists a pending Purge All for later requests (see NPPP_HTTP_PURGE_PENDING_KEY). Expiry is
 * enforced on read from armed_at, so a leftover row is harmless.
 *
 * @param array $pending ['status_url' => string, 'host' => string, 'since' => int, 'armed_at' => int]
 */
function nppp_http_purge_gate_remember( array $pending ): void {
    if ( empty( $pending['status_url'] ) ) {
        return;
    }
    update_option( NPPP_HTTP_PURGE_PENDING_KEY, $pending, false );
}

/**
 * The pending Purge All a crawl spawned NOW has to wait for, or null.
 *
 * No marker (the normal case) costs nothing: no HTTP request, no delay. With a marker the queue
 * status decides, with the same proof the Purge All wait uses (idle twice in a row): one busy
 * sample gates, two idle samples drop the marker. An unreadable status endpoint gates too: the
 * shell gate then applies its own fail-open (5 failed polls), so a blip cannot unprotect a crawl.
 *
 * Not consumed on purpose: a crawl that was stopped and restarted while the queue is still
 * working must be gated again.
 *
 * @return array|null ['status_url' => string, 'host' => string, 'since' => int, 'armed_at' => int]
 */
function nppp_http_purge_gate_pending(): ?array {
    // Fresh read: a request-local negative ('notoptions') must not hide a marker written by an
    // earlier request (same reasoning as nppp_purge_lock_in_flight()).
    wp_cache_delete( NPPP_HTTP_PURGE_PENDING_KEY, 'options' );
    wp_cache_delete( 'notoptions', 'options' );

    $p = get_option( NPPP_HTTP_PURGE_PENDING_KEY, false );
    if ( $p === false ) {
        return null;
    }

    if ( ! is_array( $p )
        || empty( $p['status_url'] )
        || ( time() - (int) ( $p['armed_at'] ?? 0 ) ) > nppp_http_purge_gate_max_wait() ) {
        delete_option( NPPP_HTTP_PURGE_PENDING_KEY );
        return null;
    }

    for ( $i = 0; $i < 2; $i++ ) {
        $snap = nppp_http_purge_queue_snapshot( (string) $p['status_url'] );
        if ( $snap === null ) {
            // Unknown, not idle: one failed sample proves nothing while the queue may be busy. Keep
            // the marker and let the spawned shell decide, it fails open after 5 failed polls.
            return $p;
        }
        if ( $snap['pending'] > 0 || $snap['active'] > 0 ) {
            return $p;
        }
        if ( $i === 0 ) {
            usleep( 300000 );
        }
    }

    delete_option( NPPP_HTTP_PURGE_PENDING_KEY );
    return null;
}

/**
 * Shell snippet that blocks until the nginx purge queue is idle twice in a row.
 *
 * Runs as the PHP user in front of the crawl (safexec only ever executes wget, so
 * this cannot live inside it). Fail-open on purpose: an unreachable status endpoint
 * (5 failed polls) or the maximum wait (default 900 s) lets the crawl start exactly
 * as it did before this gate existed. Nothing is written to the crawl log, because
 * the preload progress view parses it.
 *
 * @param array $pending ['status_url' => string, 'host' => string]
 */
function nppp_http_purge_gate_snippet( array $pending ): string {
    $max = nppp_http_purge_gate_max_wait();

    $poll = 'wget -q -O- --no-config --no-check-certificate -e use_proxy=no -T 3 -t 1'
          . ' --header=' . escapeshellarg( 'Host: ' . (string) ( $pending['host'] ?? '' ) )
          . ' ' . escapeshellarg( (string) $pending['status_url'] ) . ' 2>/dev/null';

    $tpl = <<<'SH'
g_end=$(( $(date +%s) + @MAX@ )); g_idle=0; g_fail=0;
while :; do
  g_j=$(@POLL@ | tr -d ' \t\r\n');
  g_p=$(printf %s "$g_j" | sed -n 's/.*"pending":{[^}]*"total":\([0-9][0-9]*\).*/\1/p');
  g_a=$(printf %s "$g_j" | sed -n 's/.*"active":\([0-9][0-9]*\).*/\1/p');
  if [ -n "$g_p" ] && [ -n "$g_a" ]; then
    g_fail=0;
    if [ "$g_p" = 0 ] && [ "$g_a" = 0 ]; then g_idle=$((g_idle+1)); else g_idle=0; fi;
    [ "$g_idle" -ge 2 ] && break;
  else
    g_fail=$((g_fail+1)); [ "$g_fail" -ge 5 ] && break;
  fi;
  [ "$(date +%s)" -ge "$g_end" ] && break;
  sleep 1;
done;
SH;

    return str_replace( [ '@MAX@', '@POLL@' ], [ (string) $max, $poll ], $tpl );
}

/**
 * Builds the complete background command for a crawl: `<crawl> > log 2>&1 < /dev/null & echo $!`.
 *
 * Without a pending purge this is byte-for-byte the command NPP always spawned. With
 * one, the crawl is wrapped as `( trap '' HUP; <gate>; exec <crawl> )`: the subshell
 * keeps its PID across the exec, so the PID file, the kill paths (plain kill while the
 * gate waits, safexec --kill once wget runs), the watchdog and cpulimit all keep
 * working unchanged, and the user's request returns at once.
 *
 * @param string $crawl    the crawl command without redirections
 * @param string $log_path wget log file
 */
function nppp_http_purge_gate_command( string $crawl, string $log_path ): string {
    $tail    = ' > ' . escapeshellarg( $log_path ) . ' 2>&1 < /dev/null & echo $!';
    $pending = nppp_http_purge_gate_state( 'take' );

    if ( ! is_array( $pending ) || empty( $pending['status_url'] ) ) {
        nppp_http_purge_gate_state( 'clear' );
        return $crawl . $tail;
    }

    return "( trap '' HUP; " . nppp_http_purge_gate_snippet( $pending ) . ' exec ' . $crawl . ' )' . $tail;
}

/* ============================================================================
 * 6c. Deferred "Purge All finished" follow-up (nppp_purged_all)
 * ========================================================================= */

/**
 * A Purge All that ends with status 7 (nginx's background queue is still deleting) has NOT
 * finished: the origin keeps serving stale files. nppp_purged_all means "the origin cache is
 * purged" and its listeners push that outwards (Cloudflare purge-all, Redis flush, third-party
 * CDN hooks). Fired now, the edge re-fetches stale HTML from nginx and caches it again.
 *
 * Same wake-up design as the preload watchdog (watchdog.php), because WP-Cron is not reliable
 * on low-traffic sites:
 *
 *   waiter   detached shell: the gate snippet (queue idle twice, 5 failed polls = fail-open,
 *            max wait = gate_max_wait()) and then ONE token-gated POST to admin-ajax.php
 *            (action nppp_purged_all_wake, EP8). Independent of Auto Preload and of the
 *            Watchdog toggle: its credential is the one-shot token of the pending state.
 *   cron     one-shot WP-Cron event, FAIL-SAFE only. Always armed: it is the poller when no
 *            waiter could be spawned, and a slow second wake-up path (60 s) while a waiter
 *            runs, so a waiter that died silently cannot hold the follow-up until its deadline.
 *            Both fire at the deadline (gate_max_wait() + 60 s) if the queue was never confirmed idle.
 *   claim    state lives in ONE option. Whoever deletes that row first (atomic DELETE) fires
 *            the hook, so the waiter and cron can never double-fire: at most once.
 *
 * A Purge All that is again status 7 while a follow-up is pending RE-ARMS the same chain: the
 * token stays, so the waiter already running stays valid (a replaced token would turn that
 * waiter's POST into a bogus EP8 strike), and only armed_at / auto_preload / the cron deadline
 * move. A Purge All that really finished fires the hook itself and cancels the pending chain.
 * Fail-open everywhere: if nothing can wake us the hook fires at once, as before.
 *
 * Option value: array{ token:string, auto_preload:bool, armed_at:int, waiter:bool }
 * The cron hook name must stay in the EP2 whitelist and the action name in the EP8 gate of
 * the main plugin file (both run BEFORE the plugin bootstrap).
 */
if ( ! defined( 'NPPP_PURGED_ALL_DEFERRED_KEY' ) ) {
    define( 'NPPP_PURGED_ALL_DEFERRED_KEY', 'nppp_purged_all_deferred' );
}
if ( ! defined( 'NPPP_PURGED_ALL_DEFERRED_HOOK' ) ) {
    define( 'NPPP_PURGED_ALL_DEFERRED_HOOK', 'nppp_purged_all_deferred_event' );
}
if ( ! defined( 'NPPP_PURGED_ALL_WAKE_ACTION' ) ) {
    define( 'NPPP_PURGED_ALL_WAKE_ACTION', 'nppp_purged_all_wake' );
}

/** Seconds between two cron-side queue polls (WP-Cron itself runs at most about once a minute). */
function nppp_http_purge_defer_interval(): int {
    return min( 120, max( 15, (int) apply_filters( 'nppp_purged_all_defer_interval', 15 ) ) );
}

/**
 * Seconds until the next cron-side check. Without a waiter the cron is the poller (short interval).
 * With a live waiter, which polls every second itself, the cron only covers a waiter that died
 * silently (php-fpm restart killing its process, OOM, a shell_exec that spawned nothing), so it
 * re-checks slowly. The atomic claim keeps both paths at-most-once.
 */
function nppp_http_purge_defer_poll_delay( bool $waiter ): int {
    $interval = nppp_http_purge_defer_interval();
    return $waiter ? max( 60, $interval ) : $interval;
}

/**
 * The follow-up runs headless (WP-Cron or the waiter's admin-ajax POST), no longer inside
 * nppp_purge(), which lifts both limits for the same listeners. The claim has already consumed
 * the one-shot state, so a request killed half way would lose the follow-up for good.
 */
function nppp_http_purge_deferred_lift_limits(): void {
    if ( function_exists( 'set_time_limit' ) ) {
        @set_time_limit( 0 ); // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged
    }
    if ( function_exists( 'ignore_user_abort' ) ) {
        ignore_user_abort( true );
    }
}

/** Fresh read of the deferred state (a request-local negative must not hide a row written elsewhere). */
function nppp_http_purge_deferred_read(): ?array {
    wp_cache_delete( NPPP_PURGED_ALL_DEFERRED_KEY, 'options' );
    wp_cache_delete( 'notoptions', 'options' );

    $s = get_option( NPPP_PURGED_ALL_DEFERRED_KEY, false );
    if ( ! is_array( $s ) || empty( $s['token'] ) || ! isset( $s['armed_at'] ) ) {
        return null;
    }
    return $s;
}

/** Drops a pending follow-up (state row + cron). A still-running waiter holds a dead token. */
function nppp_http_purge_deferred_cancel(): void {
    delete_option( NPPP_PURGED_ALL_DEFERRED_KEY );
    wp_unschedule_hook( NPPP_PURGED_ALL_DEFERRED_HOOK );
}

/**
 * Takes the follow-up for firing: exactly one caller gets the state, everybody else null.
 * The DELETE is the lock: it affects one row for the winner and zero for the loser.
 *
 * @param string|null $token When given, it must match the stored one-shot token.
 */
function nppp_http_purge_deferred_claim( ?string $token = null ): ?array {
    $state = nppp_http_purge_deferred_read();
    if ( $state === null ) {
        return null;
    }
    if ( $token !== null && ! hash_equals( (string) $state['token'], $token ) ) {
        return null;
    }

    global $wpdb;
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- atomic single-use claim
    $deleted = $wpdb->delete( $wpdb->options, array( 'option_name' => NPPP_PURGED_ALL_DEFERRED_KEY ), array( '%s' ) );

    wp_cache_delete( NPPP_PURGED_ALL_DEFERRED_KEY, 'options' );
    wp_cache_delete( 'notoptions', 'options' );

    return ( (int) $deleted === 1 ) ? $state : null;
}

/**
 * The single call site of nppp_purge(): fire nppp_purged_all now, or defer it while nginx's
 * queue is still deleting.
 *
 * @param mixed $status       Result of nppp_purge_helper() (7 = queue still deleting).
 * @param bool  $auto_preload Runtime auto-preload flag of this Purge All.
 */
function nppp_http_purge_fire_purged_all( $status, bool $auto_preload ): void {
    // Filter: return false to restore the immediate hook.
    if ( $status === 7
        && apply_filters( 'nppp_defer_purged_all_while_queue_busy', true )
        && nppp_http_purge_defer_purged_all( $auto_preload )
    ) {
        return; // armed, or re-armed on the chain that is already pending
    }

    // Firing now covers whatever an earlier Purge All still had pending.
    nppp_http_purge_deferred_cancel();

    do_action( 'nppp_purged_all', $auto_preload );
}

/**
 * Arms the follow-up: state row, waiter shell (when allowed) and the cron fail-safe.
 *
 * @return bool false when nothing could be armed (the caller then fires the hook at once).
 */
function nppp_http_purge_defer_purged_all( bool $auto_preload ): bool {
    // gate_remember() wrote the marker before the helper returned 7. No status endpoint means
    // nothing to wait on.
    $marker = get_option( NPPP_HTTP_PURGE_PENDING_KEY, false );
    if ( ! is_array( $marker ) || empty( $marker['status_url'] ) ) {
        return false;
    }

    $armed_at = time();
    $state    = nppp_http_purge_deferred_read();

    if ( $state !== null && ( $armed_at - (int) $state['armed_at'] ) < nppp_http_purge_gate_max_wait() + 60 ) {
        // A chain is still pending: keep its token so its waiter stays valid, move the clock and
        // the flag. Latest Purge All wins; the same queue status serves both. A waiter that
        // could not be spawned for the earlier Purge All is tried again now.
        $cmd = empty( $state['waiter'] )
            ? nppp_http_purge_purged_all_waiter_command( $marker, (string) $state['token'] )
            : null;

        $state['armed_at']     = $armed_at;
        $state['auto_preload'] = $auto_preload;
        $state['waiter']       = ! empty( $state['waiter'] ) || $cmd !== null;

        // update_option() returns false for an unchanged value: that is not a failure here.
        update_option( NPPP_PURGED_ALL_DEFERRED_KEY, $state, false );
    } else {
        try {
            $token = bin2hex( random_bytes( 16 ) );
        } catch ( \Throwable $e ) {
            return false;
        }

        $cmd   = nppp_http_purge_purged_all_waiter_command( $marker, $token );
        $state = array(
            'token'        => $token,
            'auto_preload' => $auto_preload,
            'armed_at'     => $armed_at,
            'waiter'       => $cmd !== null,
        );
        if ( ! update_option( NPPP_PURGED_ALL_DEFERRED_KEY, $state, false ) ) {
            return false;
        }
    }

    // The state row (and so the token) exists before the waiter can POST.
    if ( $cmd !== null ) {
        shell_exec( $cmd );
    }

    // Cron is always armed. No waiter: it polls the queue itself. Waiter alive: it re-checks
    // slowly as a second wake-up path in case the waiter died (the claim keeps it at-most-once).
    wp_unschedule_hook( NPPP_PURGED_ALL_DEFERRED_HOOK );
    $delay     = nppp_http_purge_defer_poll_delay( ! empty( $state['waiter'] ) );
    $scheduled = wp_schedule_single_event( $armed_at + $delay, NPPP_PURGED_ALL_DEFERRED_HOOK );

    if ( ! $state['waiter'] && $scheduled !== true ) {
        // Nothing can wake us: do not swallow the follow-up.
        delete_option( NPPP_PURGED_ALL_DEFERRED_KEY );
        return false;
    }

    nppp_display_admin_notice(
        'info',
        __( 'INFO HTTP PURGE ALL: nginx is still deleting the cache in its background queue. The Purge All follow-up (Cloudflare purge-all, Redis flush and other integrations) is deferred until the queue is idle.', 'fastcgi-cache-purge-and-preload-nginx' ),
        true,
        false
    );

    return true;
}

/**
 * Builds the detached waiter command: gate snippet, then one token-gated POST to admin-ajax.php.
 * Runs as the PHP user, like the preload watchdog. Needs shell_exec, a shell and wget. The
 * Watchdog toggle is NOT required: EP8 exempts this action from it, the one-shot token is the
 * credential, so default installs (Watchdog off) get the waiter too instead of relying on
 * WP-Cron alone. Has no side effect besides locating the shell: the caller runs the command
 * once the state row holding the token is written.
 *
 * @param array  $pending ['status_url' => string, 'host' => string, ...] (the persisted marker)
 * @param string $token   One-shot token (32 hex).
 * @return string|null Shell command line, or null when no waiter is possible here.
 */
function nppp_http_purge_purged_all_waiter_command( array $pending, string $token ): ?string {
    if ( ! function_exists( 'shell_exec' ) || ! preg_match( '/^[a-f0-9]{32}$/', $token ) ) {
        return null;
    }

    $shell = trim( (string) shell_exec( 'command -v bash 2>/dev/null' ) );
    if ( $shell === '' ) {
        $shell = trim( (string) shell_exec( 'command -v sh 2>/dev/null' ) );
    }
    if ( $shell === '' ) {
        return null;
    }

    // The gate snippet (queue polling) and the final POST both use wget. Without it the waiter
    // could never fire, yet a spawned waiter pushes the cron fail-safe to its slow interval:
    // report "no waiter" so the cron keeps polling at the short interval instead.
    if ( trim( (string) shell_exec( 'command -v wget 2>/dev/null' ) ) === '' ) {
        return null;
    }

    $post   = 'action=' . NPPP_PURGED_ALL_WAKE_ACTION . '&token=' . rawurlencode( $token );
    $script = nppp_http_purge_gate_snippet( $pending ) . "\n"
        . 'wget -q -O /dev/null --no-check-certificate --no-config --no-cookies --prefer-family=IPv4 '
        . '--dns-timeout=10 --connect-timeout=5 --read-timeout=60 --tries=3 --waitretry=5 --max-redirect=2 '
        . '--post-data ' . escapeshellarg( $post ) . ' ' . escapeshellarg( admin_url( 'admin-ajax.php' ) );

    return 'nohup ' . escapeshellarg( $shell ) . ' -c ' . escapeshellarg( $script ) . ' > /dev/null 2>&1 &';
}

/**
 * WP-Cron callback of NPPP_PURGED_ALL_DEFERRED_HOOK (fail-safe). Re-arms itself while the queue
 * is busy; fires the hook when the queue is idle or the waiter's deadline has passed.
 */
function nppp_http_purge_run_deferred_purged_all(): void {
    $state = nppp_http_purge_deferred_read();
    if ( $state === null ) {
        return; // the waiter already fired it, or a newer Purge All cancelled it
    }

    $waited    = max( 0, time() - (int) $state['armed_at'] );
    $timed_out = $waited >= nppp_http_purge_gate_max_wait() + 60;

    // A live marker means the queue is working, or its status is unreadable (which gates too).
    if ( ! $timed_out && nppp_http_purge_gate_pending() !== null ) {
        if ( wp_schedule_single_event( time() + nppp_http_purge_defer_poll_delay( ! empty( $state['waiter'] ) ), NPPP_PURGED_ALL_DEFERRED_HOOK ) === true ) {
            return;
        }
        // Could not re-arm: fail open below instead of losing the follow-up.
    }

    $claimed = nppp_http_purge_deferred_claim( (string) $state['token'] );
    if ( $claimed === null ) {
        return; // lost the race against the waiter
    }

    if ( $timed_out ) {
        nppp_display_admin_notice(
            'warning',
            /* translators: %d: seconds since the Purge All */
            sprintf( __( 'WARNING HTTP PURGE ALL: the nginx queue was not confirmed idle after %d second(s). Running the deferred Purge All follow-up anyway.', 'fastcgi-cache-purge-and-preload-nginx' ), $waited ),
            true,
            false
        );
    } else {
        nppp_display_admin_notice(
            'info',
            /* translators: %d: seconds since the Purge All */
            sprintf( __( 'INFO HTTP PURGE ALL: nginx queue is idle. Running the deferred Purge All follow-up (Cloudflare purge-all, Redis flush and other integrations) %d second(s) after the purge.', 'fastcgi-cache-purge-and-preload-nginx' ), $waited ),
            true,
            false
        );
    }

    nppp_http_purge_deferred_lift_limits();

    do_action( 'nppp_purged_all', (bool) $claimed['auto_preload'] );
}

/* ============================================================================
 * 7. Public API
 * ========================================================================= */

/**
 * Log-safe label for a purge endpoint. The endpoint path, the Custom Base URL and the
 * query string must never reach the log: the file is 0644 and, on Nginx, web-reachable
 * unless a deny rule exists.
 *
 * @param string $kind 'single' or 'all'.
 */
function nppp_http_purge_log_target( string $kind ): string {
    return $kind === 'all'
        ? __( 'the Purge All endpoint', 'fastcgi-cache-purge-and-preload-nginx' )
        : __( 'the Purge Single endpoint', 'fastcgi-cache-purge-and-preload-nginx' );
}

/**
 * Log-safe page URL: scheme://host[:port]/path. The query string and fragment are dropped
 * (order keys, tokens, search terms and e-mail addresses end up there).
 */
function nppp_http_purge_log_page( string $url ): string {
    $p = wp_parse_url( $url );
    if ( empty( $p['host'] ) ) {
        return '';
    }

    $out = ( $p['scheme'] ?? 'https' ) . '://' . $p['host'];
    if ( ! empty( $p['port'] ) ) {
        $out .= ':' . (int) $p['port'];
    }

    return esc_url_raw( $out . ( $p['path'] ?? '/' ) );
}

/**
 * Coarse, log-safe reason for a transport failure. The raw message (cURL text, exception
 * text) can hold internal hosts, IPs, ports and file paths: it is never logged.
 */
function nppp_http_purge_log_reason( string $message ): string {
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
    if ( preg_match( '/no route|unreachable|network/', $m ) ) {
        return 'network unreachable';
    }

    return 'transport error';
}

/**
 * Attempts to purge SINGLE $url via HTTP using the ngx_cache_purge module.
 *
 * @param string $url The canonical page URL to purge.
 * @return true|false|'miss'  See the file header.
 */
function nppp_http_purge_try_first( string $url ) {
    $result = nppp_http_purge_try_first_core( $url );

    // Generation mode: HTTP did not finish this purge. The scan layers that run next read
    // the generation KEY line and can still remove the entry; each reports its own result,
    // so this is a log line, not a failure notice.
    if ( $result === false
        && nppp_http_purge_enabled()
        && nppp_http_purge_generation_mode( 'single' )
    ) {
        nppp_display_admin_notice( 'info', sprintf(
            /* translators: %s: page URL */
            __( 'INFO HTTP PURGE SINGLE: %s could not be invalidated over HTTP while ngx_cache_purge generation mode is active. Continuing with the filesystem layers.', 'fastcgi-cache-purge-and-preload-nginx' ),
            nppp_http_purge_log_page( $url )
        ), true, false );
    }

    return $result;
}

/**
 * The single purge itself; see nppp_http_purge_try_first() for the contract.
 */
function nppp_http_purge_try_first_core( string $url ) {
    static $ns_logged = false;

    if ( ! nppp_http_purge_enabled() ) {
        return false;
    }

    // Endpoint previously identified as broken/misconfigured: skip until it expires.
    if ( nppp_http_purge_locked( 'single' ) ) {
        return false;
    }

    $parse = wp_parse_url( $url );
    if ( empty( $parse['host'] ) ) {
        return false;
    }

    // The plugin always sends "Host: <home_url host>", so nginx builds the cache key
    // for THAT host. A URL on another host (www alias, multisite domain) would purge
    // the same path of the home host and report success while its own entry stays
    // cached. Leave it to the filesystem layers, which work from the real URL.
    $home_host = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
    if ( $home_host !== '' && strtolower( (string) $parse['host'] ) !== $home_host ) {
        return false;
    }

    // Same host, other port (https://example.com:8443 on a site served on 443): the Host
    // header carries the home port, so the key nginx builds is not this page's key.
    $home_authority = nppp_http_purge_authority( home_url() );
    if ( $home_authority !== '' && nppp_http_purge_authority( $url ) !== $home_authority ) {
        return false;
    }

    $path  = $parse['path'] ?? '/';
    $query = ( isset( $parse['query'] ) && $parse['query'] !== '' ) ? '?' . $parse['query'] : '';

    if ( preg_match( '/[\x00-\x20\x7F]/', $path . $query ) ) {
        return false; // control characters or whitespace: never put those on the wire
    }

    if ( nppp_http_purge_is_wildcard_url( $url ) ) {
        nppp_display_admin_notice( 'info', sprintf(
            /* translators: %s: page URL */
            __( 'INFO HTTP PURGE SINGLE: Skipped %s because it ends with a wildcard character, which ngx_cache_purge would turn into a prefix purge. Using the filesystem purge instead.', 'fastcgi-cache-purge-and-preload-nginx' ),
            nppp_http_purge_log_page( $url )
        ), true, false );
        return false;
    }

    $purge_url = (string) apply_filters( 'nppp_http_purge_url', nppp_http_purge_base_url() . $path . $query, $url );
    if ( $purge_url === '' ) {
        return false;
    }

    // Default 8 seconds
    $timeout = nppp_http_purge_timeout( 'single' );
    // Generation mode: nginx answers after the task is ADMITTED and holds the request
    // while its queue is full. Giving up cancels the admission, and no filesystem layer
    // could finish the purge, so wait as long as for Purge All.
    if ( nppp_http_purge_generation_mode( 'single' ) ) {
        $timeout = max( $timeout, nppp_http_purge_timeout( 'all' ) );
    }
    nppp_http_purge_extend_time_limit( $timeout + 5 );

    // v2/v3 answer with the key they built; for an unambiguous URL it contains the page
    // path, which separates a genuine module answer from a page that merely says "Key:".
    $expect = nppp_http_purge_key_ambiguity_reason( $url ) === '' ? $path : '';

    $r = nppp_http_purge_exchange( 'single', $purge_url, $timeout, $expect );

    switch ( $r['outcome'] ) {
        case 'purged':
        case 'invalidated':
            // nginx decodes $1, so for a decode-ambiguous URL the key it purged may
            // belong to a DIFFERENT entry (/p/%41/ -> /p/A/) while the real one stays
            // cached. Claim success only when both sides build the same key.
            $reason = nppp_http_purge_key_ambiguity_reason( $url );
            if ( $reason === '' ) {
                $reason = nppp_http_purge_scheme_reason( $url, $purge_url );
            }
            if ( ! (bool) apply_filters( 'nppp_http_purge_trust_purged', $reason === '', $url, $reason ) ) {
                return false;
            }
            if ( $r['outcome'] === 'purged' ) {
                return true;
            }

            // invalidated: v4 generation mode, the old generation is unreachable at
            // once; the physical files are removed later by nginx.
            if ( ! $ns_logged ) {
                $ns_logged = true;
                nppp_display_admin_notice( 'info',
                    __( 'INFO HTTP PURGE SINGLE: ngx_cache_purge v4 generation invalidation is active. Entries are invalidated logically and removed by nginx later.', 'fastcgi-cache-purge-and-preload-nginx' ),
                    true, false
                );
            }
            return true;

        case 'no_match':
        case 'legacy_miss':
            // nginx confirmed the key is not cached. Trust it only when nginx and the
            // cache file are guaranteed to use the same key for this URL.
            $reason = nppp_http_purge_untrusted_miss_reason( $url, $purge_url );
            if ( $reason === '' && $r['method'] !== 'GET' ) {
                // The cache key normally contains $request_method (e.g. "GET" + scheme
                // + host + uri). A request sent as PURGE builds a key with "PURGE" in
                // it, which can never exist on disk: that "miss" says nothing about
                // the page. Only a GET-keyed miss is authoritative.
                $reason = 'non-GET purge request';
            }
            $trust  = (bool) apply_filters( 'nppp_http_purge_trust_miss', $reason === '', $url, $reason );
            return $trust ? 'miss' : false;
    }

    return false;
}

/**
 * Attempts a Purge All via HTTP using the dedicated Purge All endpoint.
 *
 *   200 purged / no_match (v4), 200 (v3)
 *         — nginx deleted the files synchronously. Cross-checked: if files older
 *         than the request are still in the plugin's cache path, nginx purged
 *         another zone or a delete failed silently -> false (filesystem purge).
 *   200 invalidated (v4 namespace)
 *         — logically empty now, physical cleanup runs in nginx: done.
 *   202 accepted — cache_purge_background_queue is on, nginx only ADMITTED the
 *         walk. The plugin cannot know that beforehand and NPP's preload fires
 *         right after a purge is reported complete, so it waits here for proof
 *         that the walk finished (see nppp_http_purge_await_background()). True
 *         when proven; false (filesystem purge) on cleanup errors, timeout or when
 *         completion cannot be observed. The filesystem purge only ever starts
 *         AFTER this wait.
 *   response timeout — nginx accepted the request and is still walking the cache
 *         synchronously. Same race as the 202, so it is waited for too: true when the
 *         cache directory holds no file older than the request any more (plus the
 *         settle period), false (filesystem purge) when it does not within the budget
 *         or the directory cannot be inspected. No lock: nginx is alive.
 *   403 / 405 / wrong handler / foreign page: HTTP Purge All is paused (lock).
 *   404 — location not configured: silent fallback, no lock.
 *
 * Failures are logged only; the admin-facing message comes from the filesystem
 * result in nppp_purge_helper().
 *
 * @return bool true when nginx completed the full purge.
 */
function nppp_http_purge_all( ?array &$pending = null ): bool {
    $can_defer = func_num_args() > 0; // the caller can hold a preload back
    $pending   = null;

    if ( ! nppp_http_purge_enabled() ) {
        return false;
    }

    // Endpoint previously identified as broken/misconfigured: honour backoff.
    if ( nppp_http_purge_locked( 'all' ) ) {
        return false;
    }

    $purge_url = (string) apply_filters( 'nppp_http_purge_all_url', nppp_http_purge_all_url() );
    if ( $purge_url === '' ) {
        return false;
    }

    // Default 30 seconds
    $timeout = nppp_http_purge_timeout( 'all' );

    $verify     = (bool) apply_filters( 'nppp_http_purge_verify_queue', true );
    $status_url = $verify ? nppp_http_purge_queue_status_url() : '';
    $wait       = min( 40, max( 1, (int) apply_filters( 'nppp_http_purge_queue_wait', 15 ) ) );
    $why        = '';
    $baseline   = $status_url !== '' ? nppp_http_purge_queue_snapshot( $status_url, $why ) : null;

    nppp_http_purge_extend_time_limit( $timeout + 5 + ( $verify ? $wait : 0 ) );

    $started = microtime( true );
    $since   = (int) floor( $started );
    $r       = nppp_http_purge_exchange( 'all', $purge_url, $timeout );
    $shown   = nppp_http_purge_log_target( 'all' );

    switch ( $r['outcome'] ) {
        case 'purged':
        case 'no_match':                  // v4: nothing left to delete, the goal is reached
            nppp_http_purge_hint_clear(); // a synchronous answer: no queue / generation advisory applies
            if ( nppp_http_purge_leftover( $since ) ) {
                nppp_display_admin_notice( 'info', sprintf(
                    /* translators: %s: Purge All endpoint URL */
                    __( 'INFO HTTP PURGE ALL: %s reported success, but cache files older than this purge are still in the configured Nginx cache path. The endpoint probably purges a different cache zone, or a deletion failed. Falling back to direct filesystem purge.', 'fastcgi-cache-purge-and-preload-nginx' ),
                    $shown
                ), true, false );
                return false;
            }
            return true;

        case 'invalidated':  // files remain on disk on purpose until nginx sweeps them
            // Purge All itself is safe here (the cleanup only removes stale generations). Without a
            // readable status endpoint Status / Advanced cannot tell invalidated files apart.
            if ( $verify ) {
                if ( $status_url === '' ) {
                    nppp_http_purge_hint_set( 'gen_blind' );
                } elseif ( $baseline === null ) {
                    nppp_http_purge_hint_set( 'gen_dead', $why );
                } else {
                    nppp_http_purge_hint_clear();
                }
            }
            return true;

        case 'timeout':
            // nginx got the request and is probably still deleting. Falling back at
            // once would start the filesystem sweep and then the preload while that
            // walk runs, and the late walk deletes the freshly preloaded pages. A
            // synchronous walk is not part of the queue status, so only the cache
            // directory can tell.
            if ( ! $verify ) {
                nppp_display_admin_notice( 'info', sprintf(
                    /* translators: 1: Purge All endpoint URL, 2: timeout in seconds */
                    __( 'INFO HTTP PURGE ALL: The request to %1$s timed out after %2$d second(s) and queue verification is disabled, so NPP does not wait for nginx. Falling back to direct filesystem purge (which may race with the still-running nginx operation). Raise the timeout via the "nppp_http_purge_timeout" filter for large caches or FUSE/network storage.', 'fastcgi-cache-purge-and-preload-nginx' ),
                    $shown, $timeout
                ), true, false );
                return false;
            }

            $ttl    = (int) apply_filters( 'nppp_purge_all_lock_ttl', 60 );
            $budget = max( 1.0, min( (float) $wait, $ttl - 10 - ( microtime( true ) - $started ) ) );
            $done   = nppp_http_purge_await_background( $since, '', null, $budget );

            if ( $done === 'drained' ) {
                return true; // nginx finished after our request gave up: no race left
            }

            if ( $done === 'blind' ) {
                nppp_display_admin_notice( 'info', sprintf(
                    /* translators: 1: Purge All endpoint URL, 2: timeout in seconds */
                    __( 'INFO HTTP PURGE ALL: The request to %1$s timed out after %2$d second(s) and nginx cannot be observed (the cache directory is not readable). Falling back to direct filesystem purge (which may race with the still-running nginx operation). Raise the timeout via the "nppp_http_purge_timeout" filter for large caches or FUSE/network storage.', 'fastcgi-cache-purge-and-preload-nginx' ),
                    $shown, $timeout
                ), true, false );
            } else {
                nppp_display_admin_notice( 'info', sprintf(
                    /* translators: 1: Purge All endpoint URL, 2: timeout in seconds, 3: extra seconds waited */
                    __( 'INFO HTTP PURGE ALL: The request to %1$s timed out after %2$d second(s) and nginx had not finished deleting the cache files after %3$d more second(s). Falling back to direct filesystem purge (which may race with the still-running nginx operation). Raise the timeout via the "nppp_http_purge_timeout" filter or the wait via "nppp_http_purge_queue_wait", especially for large caches or FUSE/network storage.', 'fastcgi-cache-purge-and-preload-nginx' ),
                    $shown, $timeout, (int) round( $budget )
                ), true, false );
            }
            return false;

        case 'accepted':
            if ( ! $verify ) {
                nppp_display_admin_notice( 'info', sprintf(
                    /* translators: %s: Purge All endpoint URL */
                    __( 'INFO HTTP PURGE ALL: %s returned 202 (async purge queued) and queue verification is disabled. Falling back to direct filesystem purge (which may race with the queued purge).', 'fastcgi-cache-purge-and-preload-nginx' ),
                    $shown
                ), true, false );
                return false;
            }

            // The queue is on. Tell the administrator when NPP cannot observe it, because then the
            // filesystem purge + preload can race Nginx's walk (Settings > Purge Queue Status Path).
            if ( $status_url === '' ) {
                nppp_http_purge_hint_set( 'queue_blind' );
            } elseif ( $baseline === null ) {
                nppp_http_purge_hint_set( 'queue_dead', $why );
            } else {
                nppp_http_purge_hint_clear();
            }

            // Stay inside the Purge All lock (crash-recovery TTL, default 60 s).
            $ttl    = (int) apply_filters( 'nppp_purge_all_lock_ttl', 60 );
            $budget = max( 1.0, min( (float) $wait, $ttl - 10 - ( microtime( true ) - $started ) ) );
            $done   = nppp_http_purge_await_background( $since, $status_url, $baseline, $budget );

            if ( $done === 'proven' || $done === 'drained' ) {
                if ( $done === 'proven' && nppp_http_purge_leftover( $since ) ) {
                    nppp_display_admin_notice( 'info', sprintf(
                        /* translators: %s: Purge All endpoint URL */
                        __( 'INFO HTTP PURGE ALL: %s finished its queued purge, but cache files older than the purge are still in the configured Nginx cache path (different cache zone, or silent deletion failure). Falling back to direct filesystem purge.', 'fastcgi-cache-purge-and-preload-nginx' ),
                        $shown
                    ), true, false );
                    return false;
                }
                return true;
            }

            if ( $done === 'queued' && $can_defer ) {
                // nginx is verifiably working through its queue. A filesystem purge now would only
                // race it, and a preload started now would be swept by the late walk: report the
                // pending purge and let the caller gate the crawl on the queue status instead.
                $pending = [
                    'status_url' => $status_url,
                    'host'       => nppp_http_purge_authority( home_url() ),
                    'since'      => $since,
                    'armed_at'   => time(),
                ];
                // The hand-off above dies with this request; a crawl started by a later one
                // (manual Preload All, REST, WP-CLI, cron) has to wait for the same queue.
                nppp_http_purge_gate_remember( $pending );
                nppp_display_admin_notice( 'info', sprintf(
                    /* translators: 1: Purge All endpoint URL, 2: seconds waited */
                    __( 'INFO HTTP PURGE ALL: %1$s returned 202 and nginx is still deleting the cache in its background queue after %2$d second(s). The filesystem purge is skipped so it cannot race the queue; a following preload starts when the queue is idle.', 'fastcgi-cache-purge-and-preload-nginx' ),
                    $shown, (int) round( $budget )
                ), true, false );
                return false;
            }

            if ( $done === 'errors' ) {
                nppp_display_admin_notice( 'info', sprintf(
                    /* translators: %s: Purge All endpoint URL */
                    __( 'INFO HTTP PURGE ALL: %s returned 202 (async purge queued) and nginx reported cleanup errors while processing it. Falling back to direct filesystem purge.', 'fastcgi-cache-purge-and-preload-nginx' ),
                    $shown
                ), true, false );
            } elseif ( $done === 'blind' ) {
                nppp_display_admin_notice( 'info', sprintf(
                    /* translators: %s: Purge All endpoint URL */
                    __( 'INFO HTTP PURGE ALL: %s returned 202 (async purge queued) and completion cannot be observed (no queue status endpoint configured and the cache directory is not readable). To ensure stable preloading, disable "cache_purge_background_queue" in Nginx or configure a "cache_purge_queue_status" location. Falling back to direct filesystem purge (which may race with the queued purge).', 'fastcgi-cache-purge-and-preload-nginx' ),
                    $shown
                ), true, false );
            } else {
                nppp_display_admin_notice( 'info', sprintf(
                    /* translators: 1: Purge All endpoint URL, 2: seconds waited */
                    __( 'INFO HTTP PURGE ALL: %1$s returned 202 (async purge queued) and was not finished after %2$d second(s). Falling back to direct filesystem purge (which may race with the still-queued purge). Raise the wait via the "nppp_http_purge_queue_wait" filter or disable "cache_purge_background_queue" in Nginx.', 'fastcgi-cache-purge-and-preload-nginx' ),
                    $shown, (int) round( $budget )
                ), true, false );
            }
            return false;
    }

    return false;
}
