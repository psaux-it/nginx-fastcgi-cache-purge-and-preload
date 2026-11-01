<?php
/**
 * Helpers of the topology layer (tests/topology.php).
 *
 * Unlike lib/lab.php (a throw-away varnishd in front of a counting backend) this layer talks to the REAL containers
 * of the Docker stack and drives the REAL plugin through WP-CLI:
 *
 *   visitor -> nginx:443 (edge, TLS) -> varnish:80 -> nginx:8080 (origin, fastcgi_cache) -> wordpress-fpm
 *   wp npp purge ...  ->  HTTP Purge https://nginx:8443 (nginx layer)  +  PURGE / ban to http://varnish:80 (Varnish layer)
 *
 * It runs INSIDE wordpress-fpm as root, started by actionstart-varnish.sh --topology, which passes the addresses:
 *
 *   TOPO_SITE_HOST     Host header of the site             (localhost)
 *   TOPO_EDGE          https://nginx                       visitor entry point
 *   TOPO_ORIGIN        http://nginx:8080                   what Varnish fetches from (bypasses Varnish)
 *   TOPO_PURGE         https://nginx:8443                  NPP HTTP Purge listener (informational)
 *   TOPO_VARNISH       http://varnish:80                   the Varnish container
 *   TOPO_KEY           control key written into the VCL
 *   TOPO_ACL_MODE      ip | subnet
 *   TOPO_VCL_VARIANT   example | noregex | aclnone | nopurge   (what the script installed; auto = detect)
 *   WP_PATH, WP_USER   WordPress root and the PHP-FPM pool user
 *
 * The two layers are observed separately and WITHOUT disturbing each other:
 *   Varnish layer  X-Varnish of a visitor GET: 1 id = MISS (Varnish fetched), 2 ids = HIT.
 *   nginx layer    NPP-FastCGI-Cache of a GET straight to the origin :8080 (never through Varnish: a Varnish HIT would
 *                  replay the header value of the day it was stored). A MISS there fills nginx, so the order is always
 *                  "origin first, edge second".
 *
 * Fidelity notes:
 *  - All requests send "Accept-Encoding: identity" (what GNU wget sends), so one Vary variant is in play.
 *  - Everything the plugin does goes through `wp npp ...`; settings are changed with `wp npp settings set` (the same
 *    validation as the Settings page) and fall back to a raw option patch when the CLI environment check refuses.
 *    Every setting a section changes is restored on exit (register_shutdown_function), also after a failed assertion.
 */
require_once __DIR__ . '/wpstub.php';

$GLOBALS['TOPO'] = [];

function topo_env( string $k, string $default = '' ): string {
    $v = getenv( $k );
    return ( $v === false || $v === '' ) ? $default : (string) $v;
}

/** Reads the environment, checks the lab is usable. Exits 2 (lab error) or 77 (skip) otherwise. */
function topo_boot( string $section ): void {
    global $TOPO;
    $TOPO = [
        'host'        => topo_env( 'TOPO_SITE_HOST', 'localhost' ),
        'site_url'    => rtrim( topo_env( 'WORDPRESS_SITE_URL', 'https://' . topo_env( 'TOPO_SITE_HOST', 'localhost' ) ), '/' ),
        'edge'        => rtrim( topo_env( 'TOPO_EDGE', 'https://nginx' ), '/' ),
        'origin'      => rtrim( topo_env( 'TOPO_ORIGIN', 'http://nginx:8080' ), '/' ),
        'purge'       => rtrim( topo_env( 'TOPO_PURGE', 'https://nginx:8443' ), '/' ),
        'varnish'     => rtrim( topo_env( 'TOPO_VARNISH', '' ), '/' ),
        'key'         => topo_env( 'TOPO_KEY', '' ),
        'acl'         => topo_env( 'TOPO_ACL_MODE', 'ip' ),
        'variant_env' => topo_env( 'TOPO_VCL_VARIANT', 'auto' ),
        'wp_path'     => topo_env( 'WP_PATH', '/var/www/html' ),
        'wp_user'     => topo_env( 'WP_USER', 'npp' ),
        'cache_dir'   => topo_env( 'NPP_NGINX_CACHE_PATH', '/var/cache/nginx' ),
        'saved'       => null,
        'pages'       => null,
        'variant'     => null,
    ];
    if ( $TOPO['varnish'] === '' || $TOPO['key'] === '' ) {
        fwrite( STDERR, "topology/$section: TOPO_VARNISH and TOPO_KEY are not set. Run it through: ./actionstart-varnish.sh --topology\n" );
        exit( 2 );
    }
    if ( ! extension_loaded( 'curl' ) ) { echo "SKIP topology/$section: PHP curl extension not loaded\n"; exit( 77 ); }
    if ( ! is_dir( '/tmp/wp-home' ) ) { @mkdir( '/tmp/wp-home', 0755, true ); }
    @chown( '/tmp/wp-home', $TOPO['wp_user'] );

    $r = topo_wp( [ 'option', 'pluck', 'nginx_cache_settings', 'nppp_varnish_sync_enabled' ] );
    if ( $r['rc'] !== 0 ) {
        fwrite( STDERR, "topology/$section: WP-CLI does not work as '{$TOPO['wp_user']}' in {$TOPO['wp_path']}:\n" . $r['out'] . "\n" );
        exit( 2 );
    }
    $e = topo_edge( '/' );
    if ( $e['code'] === 0 ) {
        fwrite( STDERR, "topology/$section: the edge {$TOPO['edge']} is not reachable from this container: {$e['err']}\n" );
        exit( 2 );
    }
}

/* ============================================================================================================
 * Processes (WP-CLI)
 * ========================================================================================================= */

/** Runs a command (argv array, no shell). Output = stdout + stderr. */
function topo_run( array $cmd, float $timeout = 120.0 ): array {
    if ( trim( (string) shell_exec( 'command -v timeout 2>/dev/null' ) ) !== '' ) {
        $cmd = array_merge( [ 'timeout', (string) (int) ceil( $timeout ) ], $cmd );
    }
    $t0 = microtime( true );
    $p  = proc_open( $cmd, [ 1 => [ 'pipe', 'w' ], 2 => [ 'redirect', 1 ] ], $pipes );
    if ( ! is_resource( $p ) ) { return [ 'rc' => 127, 'out' => 'proc_open failed', 'sec' => 0.0 ]; }
    $out = (string) stream_get_contents( $pipes[1] );
    fclose( $pipes[1] );
    $rc = proc_close( $p );
    return [ 'rc' => $rc, 'out' => $out, 'sec' => microtime( true ) - $t0 ];
}

/** `wp --path=... <args>` as the PHP-FPM pool user. */
function topo_wp( array $args, float $timeout = 120.0 ): array {
    global $TOPO;
    $cmd = array_merge( [ 'runuser', '-u', $TOPO['wp_user'], '--', 'env', 'HOME=/tmp/wp-home', 'wp', '--path=' . $TOPO['wp_path'] ], $args );
    return topo_run( $cmd, $timeout );
}

function topo_opt( string $key ): string {
    $r = topo_wp( [ 'option', 'pluck', 'nginx_cache_settings', $key ] );
    return $r['rc'] === 0 ? trim( $r['out'] ) : '';
}

function topo_set( string $key, string $value ): void {
    $r = topo_wp( [ 'npp', 'settings', 'set', $key, $value ] );
    if ( $r['rc'] !== 0 ) {
        $r = topo_wp( [ 'option', 'patch', 'update', 'nginx_cache_settings', $key, $value ] );
        if ( $r['rc'] !== 0 ) { fwrite( STDERR, "cannot set $key: " . $r['out'] . "\n" ); exit( 2 ); }
    }
}

/** Clears the Varnish circuit breaker and the warning de-duplication (they live in the DB and survive between wp calls). */
function topo_reset_state(): void {
    topo_wp( [ 'eval', 'if(function_exists("nppp_state_delete")){nppp_state_delete("varnish_breaker");nppp_state_delete("varnish_warn");}' ] );
}

/** Remembers the Varnish settings and puts them back when the process ends. Call before the first topo_set(). */
function topo_snapshot(): void {
    global $TOPO;
    if ( $TOPO['saved'] !== null ) { return; }
    $saved = [];
    foreach ( [ 'nppp_varnish_sync_enabled', 'nppp_varnish_endpoints', 'nppp_varnish_header', 'nppp_http_purge_custom_url' ] as $k ) {
        $saved[ $k ] = topo_opt( $k );
    }
    $TOPO['saved'] = $saved;
    register_shutdown_function( static function () use ( $saved ) {
        foreach ( $saved as $k => $v ) {
            if ( topo_opt( $k ) !== $v ) { topo_set( $k, $v ); }
        }
        topo_reset_state();
    } );
}

/* ============================================================================================================
 * HTTP
 * ========================================================================================================= */

/** @return array{code:int,headers:array<string,string>,body:string,err:string,sec:float} */
function topo_http( string $method, string $url, array $headers = [], float $timeout = 15.0 ): array {
    $h = [];
    foreach ( $headers as $k => $v ) { $h[] = $k . ': ' . $v; }
    $resp = [];
    $ch   = curl_init( $url );
    curl_setopt_array( $ch, [
        CURLOPT_CUSTOMREQUEST => $method, CURLOPT_HTTPHEADER => $h, CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false, CURLOPT_SSL_VERIFYPEER => false, CURLOPT_SSL_VERIFYHOST => 0,
        CURLOPT_TIMEOUT_MS => (int) ( $timeout * 1000 ), CURLOPT_CONNECTTIMEOUT_MS => 3000,
        CURLOPT_PATH_AS_IS => true, CURLOPT_NOBODY => $method === 'HEAD',
        CURLOPT_HEADERFUNCTION => static function ( $c, $line ) use ( &$resp ) {
            if ( strpos( $line, ':' ) !== false ) { [ $k, $v ] = explode( ':', $line, 2 ); $resp[ strtolower( trim( $k ) ) ] = trim( $v ); }
            return strlen( $line );
        },
    ] );
    $t0   = microtime( true );
    $body = curl_exec( $ch );
    $out  = [ 'code' => (int) curl_getinfo( $ch, CURLINFO_RESPONSE_CODE ), 'headers' => $resp, 'body' => (string) $body, 'err' => curl_error( $ch ), 'sec' => microtime( true ) - $t0 ];
    curl_close( $ch );
    return $out;
}

function topo_hdrs( array $extra = [], ?string $host = null ): array {
    global $TOPO;
    return array_merge( [ 'Host' => $host ?? $TOPO['host'], 'Accept-Encoding' => 'identity' ], $extra );
}

/** GET through the edge (TLS -> Varnish -> origin). */
function topo_edge( string $path ): array {
    global $TOPO;
    return topo_http( 'GET', $TOPO['edge'] . $path, topo_hdrs() );
}

/** Number of ids in X-Varnish: 1 = MISS, 2 = HIT, 0 = the response did not pass through Varnish. */
function topo_ids( array $r ): int {
    $x = trim( $r['headers']['x-varnish'] ?? '' );
    return $x === '' ? 0 : count( preg_split( '/\s+/', $x ) );
}
function topo_edge_ids( string $path ): int { return topo_ids( topo_edge( $path ) ); }

/** nginx layer: MISS | HIT | EXPIRED | BYPASS ... (upper case), NONE = no header, UNREACHABLE. Fills nginx on a MISS. */
function topo_origin_state( string $path ): string {
    global $TOPO;
    $r = topo_http( 'GET', $TOPO['origin'] . $path, topo_hdrs() );
    if ( $r['code'] === 0 ) { return 'UNREACHABLE'; }
    return strtoupper( $r['headers']['npp-fastcgi-cache'] ?? 'NONE' );
}

/** Both layers filled, Varnish proven by a HIT on the second visitor GET. */
function topo_warm( string $path ): bool {
    topo_origin_state( $path );
    topo_edge( $path );
    $r = topo_edge( $path );
    return $r['code'] === 200 && topo_ids( $r ) === 2;
}

/** A PURGE straight to the Varnish container (as the PHP container does it). */
function topo_purge_raw( string $path, array $extra = [], ?string $host = null ): array {
    global $TOPO;
    return topo_http( 'PURGE', $TOPO['varnish'] . $path, topo_hdrs( $extra, $host ), 10.0 );
}
function topo_key_hdr(): array { global $TOPO; return [ 'X-Control-Key' => $TOPO['key'] ]; }

/* ============================================================================================================
 * Plugin actions and what the plugin logged
 * ========================================================================================================= */

function topo_url( string $path ): string { global $TOPO; return $TOPO['site_url'] . $path; }

/** @return array{token:string,rc:int,out:string,sec:float} token = success | warning | error (WP-CLI --porcelain) */
function topo_purge_single( string $path ): array {
    $r = topo_wp( [ 'npp', 'purge', '--page-url=' . topo_url( $path ), '--porcelain' ] );
    $r['token'] = topo_token( $r['out'] );
    return $r;
}
function topo_purge_all(): array {
    $r = topo_wp( [ 'npp', 'purge', '--porcelain' ] );
    $r['token'] = topo_token( $r['out'] );
    return $r;
}
function topo_token( string $out ): string {
    $lines = array_values( array_filter( array_map( 'trim', explode( "\n", $out ) ), 'strlen' ) );
    return strtolower( (string) end( $lines ) );
}

function topo_log_clear(): void { topo_wp( [ 'npp', 'log', '--clear' ] ); }

/**
 * What the plugin wrote while a command ran. The log file is the source (that is what a site owner reads); when it is
 * empty the CLI output of the command is used instead. Never both: a line would be counted twice.
 */
function topo_events( array $res ): string {
    $r = topo_wp( [ 'npp', 'log', '--lines=400' ] );
    $log = $r['rc'] === 0 ? trim( $r['out'] ) : '';
    return $log !== '' && stripos( $log, 'empty or does not exist' ) === false ? $log : (string) $res['out'];
}
function topo_count( string $regex, string $text ): int { return (int) preg_match_all( $regex, $text ); }

/* ============================================================================================================
 * Pages, cache keys, VCL
 * ========================================================================================================= */

/** Up to three paths that really are cached by both layers (home + permalinks of published content). */
function topo_pages(): array {
    global $TOPO;
    if ( $TOPO['pages'] !== null ) { return $TOPO['pages']; }
    $cands = [ '/' ];
    $r = topo_wp( [ 'eval', 'foreach(get_posts(["post_type"=>["post","page"],"post_status"=>"publish","numberposts"=>6]) as $p){echo get_permalink($p),"\n";}' ] );
    if ( $r['rc'] === 0 ) {
        foreach ( explode( "\n", $r['out'] ) as $u ) {
            $u = trim( $u );
            if ( $u === '' || strpos( $u, 'http' ) !== 0 ) { continue; }
            $p = parse_url( $u );
            $path = ( $p['path'] ?? '/' ) . ( isset( $p['query'] ) ? '?' . $p['query'] : '' );
            if ( ! in_array( $path, $cands, true ) ) { $cands[] = $path; }
        }
    }
    $pages = [];
    foreach ( $cands as $path ) {
        if ( topo_warm( $path ) ) { $pages[] = $path; }
        if ( count( $pages ) >= 3 ) { break; }
    }
    return $TOPO['pages'] = $pages;
}

/** Cache keys (the "KEY:" line) of the files currently in the nginx cache. null = the directory is not readable here. */
function topo_cache_keys(): ?array {
    global $TOPO;
    if ( ! is_dir( $TOPO['cache_dir'] ) || ! is_readable( $TOPO['cache_dir'] ) ) { return null; }
    $r = topo_run( [ 'sh', '-c', 'grep -rhaoE "^KEY: [[:print:]]+" ' . escapeshellarg( $TOPO['cache_dir'] ) . ' 2>/dev/null | head -n 500' ], 30.0 );
    return array_values( array_filter( array_map( static fn( $l ) => trim( $l ), explode( "\n", $r['out'] ) ), 'strlen' ) );
}

/** Number of files in the nginx cache directory (does not touch any cache). */
function topo_cache_files(): int {
    global $TOPO;
    $r = topo_run( [ 'sh', '-c', 'find ' . escapeshellarg( $TOPO['cache_dir'] ) . ' -type f 2>/dev/null | wc -l' ], 30.0 );
    return (int) trim( $r['out'] );
}

/**
 * Which VCL is active in the Varnish container, found by behaviour (the container's VCL file is not readable from here):
 *   aclnone  PURGE with the right key is refused with 405 (our address is not in the ACL)
 *   example  PURGE needs the key (403 without, 200 with) and a regex PURGE really bans
 *   noregex  same, but a regex PURGE answers 200 and deletes nothing (the silent no-op the Help tab warns about)
 *   nopurge  PURGE is not handled by the VCL: the answer does not depend on the key
 *   unknown  anything else
 */
function topo_variant(): string {
    global $TOPO;
    if ( $TOPO['variant'] !== null ) { return $TOPO['variant']; }
    $probe = '/npp-topo-probe-' . getmypid();
    $k = topo_purge_raw( $probe, topo_key_hdr() );
    $n = topo_purge_raw( $probe );
    if ( $k['code'] === 405 ) { return $TOPO['variant'] = 'aclnone'; }
    if ( $k['code'] === 200 && $n['code'] === 403 ) {
        $pages = topo_pages();
        $v = 'example';
        if ( $pages ) {
            topo_warm( $pages[0] );
            topo_purge_raw( '/.*', topo_key_hdr() + [ 'X-Purge-Method' => 'regex' ] );
            $v = topo_edge_ids( $pages[0] ) === 1 ? 'example' : 'noregex';
        }
        return $TOPO['variant'] = $v;
    }
    if ( $k['code'] === $n['code'] ) { return $TOPO['variant'] = 'nopurge'; }
    return $TOPO['variant'] = 'unknown';
}

function topo_summary_line( string $label, int $got, int $want ): string { return "$label: got $got, expected $want"; }
