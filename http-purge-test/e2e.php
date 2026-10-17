<?php
/** Real-nginx e2e for purge-http.php: php e2e.php <scenario> [purge-http.php] */
error_reporting( E_ALL );
define( 'ABSPATH', '/tmp/' ); define( 'MINUTE_IN_SECONDS', 60 ); define( 'HOUR_IN_SECONDS', 3600 ); define( 'DAY_IN_SECONDS', 86400 );
class WP_Error { public $m; function __construct( $c = '', $m = '' ) { $this->m = $m; } function get_error_messages() { return [ $this->m ]; } }
$T = [ 'options' => [ 'nginx_cache_settings' => [ 'nppp_http_purge_enabled' => 'yes', 'nginx_cache_path' => '/tmp/npp-cache',
        'nppp_http_purge_custom_url' => 'http://127.0.0.1:8081' ] ], 'tr' => [], 'notices' => [], 'reqs' => [] ];
function get_option( $k, $d = false ) { global $T; return $T['options'][ $k ] ?? $d; }
function home_url() { return 'http://example.com'; }
function wp_parse_url( $u, $c = -1 ) { return parse_url( $u, $c ); }
function absint( $n ) { return abs( (int) $n ); } function esc_url( $u ) { return $u; } function __( $s, $d = '' ) { return $s; }
function apply_filters( $t, $v, ...$a ) { global $T; $f = $T['filters'][ $t ] ?? null; return $f ? $f( $v, ...$a ) : $v; }
function do_action( ...$a ) {} function add_action( ...$a ) {} function remove_action( ...$a ) {}
function get_transient( $k ) { global $T; return $T['tr'][ $k ] ?? false; }
function set_transient( $k, $v, $t ) { global $T; $T['tr'][ $k ] = $v; return true; }
function delete_transient( $k ) { global $T; unset( $T['tr'][ $k ] ); return true; }
function is_wp_error( $x ) { return $x instanceof WP_Error; }
function nppp_display_admin_notice( $t, $m, $l = true, $s = true ) { global $T; $T['notices'][] = "[$t] $m"; }
function wp_remote_request( $url, $a ) {
    global $T; $T['reqs'][] = $a['method'] . ' ' . $url;
    $ch = curl_init( $url );
    curl_setopt_array( $ch, [ CURLOPT_CUSTOMREQUEST => $a['method'], CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true,
        CURLOPT_TIMEOUT => $a['timeout'], CURLOPT_HTTPHEADER => [ 'Host: ' . $a['headers']['Host'] ], CURLOPT_FOLLOWLOCATION => false ] );
    $raw = curl_exec( $ch );
    if ( $raw === false ) { $e = curl_error( $ch ); return new WP_Error( 'x', "cURL error " . curl_errno( $ch ) . ": $e" ); }
    $hs = curl_getinfo( $ch, CURLINFO_HEADER_SIZE ); $code = curl_getinfo( $ch, CURLINFO_RESPONSE_CODE );
    $h = []; foreach ( explode( "\r\n", substr( $raw, 0, $hs ) ) as $l ) { if ( strpos( $l, ':' ) ) { [ $k, $v ] = explode( ':', $l, 2 ); $h[ strtolower( trim( $k ) ) ] = trim( $v ); } }
    return [ 'c' => $code, 'b' => substr( $raw, $hs ), 'h' => $h ];
}
function wp_remote_retrieve_response_code( $r ) { return $r['c']; } function wp_remote_retrieve_body( $r ) { return $r['b']; }
function wp_remote_retrieve_header( $r, $n ) { return $r['h'][ strtolower( $n ) ] ?? ''; }
require $argv[2] ?? dirname( __DIR__ ) . '/includes/purge-http.php';

function cnt() { return (int) trim( shell_exec( "find /tmp/npp-cache -type f -regextype posix-extended -regex '.*/[0-9a-f]{32}' | wc -l" ) ); }
function warm( $prefix, $n ) { shell_exec( "seq 1 $n | xargs -P 8 -I{} curl -s -o /dev/null -H 'Host: example.com' http://127.0.0.1:8081/$prefix/{}/" ); }
function xcache( $path ) { return trim( shell_exec( "curl -s -D- -o /dev/null -H 'Host: example.com' http://127.0.0.1:8081$path | grep -i '^x-cache' | cut -d' ' -f2" ) ); }
$F = 0; function chk( $c, $n, $d = '' ) { global $F; echo ( $c ? 'PASS ' : 'FAIL ' ) . $n . ( $c ? '' : " $d" ) . "\n"; if ( ! $c ) $F++; }
function notes() { global $T; return implode( ' || ', array_map( fn( $n ) => substr( $n, 0, 400 ), $T['notices'] ) ); }
$sc = $argv[1];

if ( $sc === 'sync' ) {
    warm( 'p', 50 );
    chk( nppp_http_purge_try_first( 'http://example.com/p/1/' ) === true, 'single purge hit -> true' );
    chk( xcache( '/p/1/' ) === 'MISS', 'purged page is MISS again' );
    chk( nppp_http_purge_try_first( 'http://example.com/never/' ) === 'miss', 'single miss -> miss (authoritative)' );
    chk( nppp_http_purge_try_first( 'http://example.com/p/%41/' ) === false, 'percent-encoded miss is NOT trusted' );
    chk( nppp_http_purge_try_first( 'http://www.example.com/p/2/' ) === false && xcache( '/p/2/' ) === 'HIT', 'www URL: not claimed, home-host entry NOT purged' );
    $t = microtime( true ); $r = nppp_http_purge_all();
    chk( $r === true && cnt() === 0, 'purge_all sync -> true, cache empty', "r=" . var_export( $r, true ) . " files=" . cnt() . ' ' . notes() );
    chk( microtime( true ) - $t < 2, 'sync purge_all returns without waiting' );
    warm( 'q', 10 ); $r = nppp_http_purge_all();
    chk( $r === true && cnt() === 0, 'purge_all again -> true' );
    $r = nppp_http_purge_all(); chk( $r === true, 'purge_all on EMPTY cache (v4 no_match) -> true', notes() );
}
if ( $sc === 'ns' ) {
    warm( 'p', 20 );
    chk( nppp_http_purge_try_first( 'http://example.com/p/1/' ) === true, 'namespace single: invalidated -> true' );
    chk( xcache( '/p/1/' ) === 'MISS', 'invalidated page is MISS immediately' );
    chk( xcache( '/p/2/' ) === 'HIT', 'other page still HIT' );
    chk( nppp_http_purge_all() === true, 'namespace purge_all: invalidated -> true (no leftover check)' );
    chk( xcache( '/p/2/' ) === 'MISS', 'purge_all invalidated everything logically' );
}
if ( $sc === 'none' ) {
    warm( 'p', 5 );
    chk( nppp_http_purge_try_first( 'http://example.com/p/1/' ) === true, 'response_type none: 204 purged -> true (Cache-Purge-Code)' );
    chk( nppp_http_purge_try_first( 'http://example.com/never/' ) === 'miss', 'response_type none: 204 no_match -> miss' );
    chk( nppp_http_purge_all() === true, 'response_type none: purge_all -> true' );
}
if ( $sc === 'wrongzone' ) {
    warm( 'p', 30 ); sleep( 2 );
    $r = nppp_http_purge_all();
    chk( $r === false && cnt() === 30, 'purge_all hits another zone: 200 but files remain -> false (FS purge must run)', "r=" . var_export( $r, true ) . ' files=' . cnt() );
    chk( strpos( notes(), 'older than this purge' ) !== false, 'notice explains', notes() );
}
if ( $sc === 'single202' ) {
    warm( 'p', 5 );
    $r = nppp_http_purge_try_first( 'http://example.com/p/1/' );
    chk( $r === false && array_keys( nppp_http_purge_state()['locks'] ) === [ 'single' ], 'single endpoint answering 202 -> false + locked (not a whole-cache wipe loop)', var_export( $r, true ) . ' ' . notes() );
}
if ( $sc === 'legacy_off' ) {
    warm( 'p', 3 );
    chk( nppp_http_purge_try_first( 'http://example.com/p/1/' ) === true, 'v3.0.3 legacy off: purge hit -> true' );
    $r = nppp_http_purge_try_first( 'http://example.com/never/' );
    // v3.0.x: miss with cache_purge_legacy_status off is 404 (ambiguous) -> false. v4 ignores that directive and answers 200 no_match -> 'miss'.
    $want = ( $argv[3] ?? 'v40' ) === 'v30' ? false : 'miss';
    chk( $r === $want, 'legacy_status off: ' . ( $want === false ? 'v3.0.x miss is 404 (ambiguous) -> false, filesystem decides' : 'v4 ignores the directive: 200 no_match -> miss' ), var_export( $r, true ) );
}
if ( $sc === 'legacy_on' ) {
    warm( 'p', 3 );
    chk( nppp_http_purge_try_first( 'http://example.com/p/1/' ) === true, 'legacy on: hit -> true' );
    chk( nppp_http_purge_try_first( 'http://example.com/never/' ) === 'miss', 'legacy on: 412 -> miss' );
    warm( 'q', 5 ); $r = nppp_http_purge_all();
    chk( $r === true && cnt() === 0, 'legacy purge_all sync -> true', ' ' . notes() );
}

if ( $sc === 'v25' ) {   /* ngx_cache_purge 2.5.6: single purge only, no purge_all */
    warm( 'p', 3 );
    chk( nppp_http_purge_try_first( 'http://example.com/p/1/' ) === true, 'v2.5.6: purge hit (200) -> true' );
    chk( xcache( '/p/1/' ) === 'MISS', 'v2.5.6: purged page is MISS' );
    chk( nppp_http_purge_try_first( 'http://example.com/never/' ) === 'miss', 'v2.5.6: 412 -> miss' );
    $r = nppp_http_purge_all();
    chk( $r === false, 'v2.5.6: no purge_all location -> false (filesystem purge runs), never a false success', var_export( $r, true ) . ' ' . notes() );
}
/* Race: 202 background purge, then filesystem fallback if it returned false (as purge.php does), then preload. */
if ( strpos( $sc, 'race' ) === 0 && strpos( $sc, 'race_timeout' ) !== 0 && strpos( $sc, 'race_invalidated' ) !== 0 ) {
    if ( $sc === 'race_nostatus' ) { /* no status path option */ }
    else { $T['options']['nginx_cache_settings']['nppp_http_purge_queue_status_path'] = 'purge_status'; }
    if ( $sc === 'race_off' ) { $T['filters']['nppp_http_purge_verify_queue'] = fn() => false; }
    $T['filters']['nppp_http_purge_async_settle_ms'] = fn() => 1500;
    warm( 'p', 3000 ); sleep( 2 );
    $before = cnt(); $t = microtime( true );
    $r = nppp_http_purge_all(); $waited = round( microtime( true ) - $t, 2 );
    $left = cnt();
    if ( $r === false ) { shell_exec( 'find /tmp/npp-cache -mindepth 1 -delete' ); }   // what nppp_wp_purge() does
    echo "  cached=$before  purge_all()=" . var_export( $r, true ) . " waited={$waited}s files_after_return=$left  fs_fallback=" . ( $r === false ? 'yes' : 'no' ) . "\n";
    warm( 'warm', 400 );                                                              // preload starts immediately
    $f0 = cnt(); sleep( 4 ); $f1 = cnt();
    $hits = 0; for ( $i = 1; $i <= 20; $i++ ) { if ( xcache( "/warm/$i/" ) === 'HIT' ) $hits++; }
    echo "  preload warmed: files=$f0 -> 4 s later files=$f1, HIT on first 20 pages: $hits/20\n";
    if ( $sc === 'race_off' ) { chk( $r === false && $f1 < 400, 'OLD behaviour (verify off): preload loses pages to the late nginx walk', "files=$f1" ); }
    else { chk( $f1 === 400 && $hits === 20, "$sc: after purge_all() returns the preload is NOT touched by nginx (400/400 kept)", "files=$f1 hits=$hits notes=" . notes() ); }
    if ( $sc === 'race' ) { chk( $r === true && $waited > 0.4, 'proven by status endpoint -> true after real wait' ); }
    if ( $sc === 'race_nostatus' ) { chk( $r === true, 'best effort via directory -> true', notes() ); }
}

/* nginx decodes $1: purging /p/%41/ deletes /p/A/ and answers 200 while the real /p/%41/ entry stays cached. */
if ( $sc === 'ambig' ) {
    shell_exec( "curl -s -o /dev/null -H 'Host: example.com' http://127.0.0.1:8081/p/A/; curl -s -o /dev/null -H 'Host: example.com' http://127.0.0.1:8081/p/%41/" );
    chk( xcache( '/p/%41/' ) === 'HIT' && xcache( '/p/A/' ) === 'HIT', 'both /p/A/ and /p/%41/ are cached' );
    $r = nppp_http_purge_try_first( 'http://example.com/p/%41/' );
    chk( $r === false, 'ambiguous url: nginx answers 200 purged (it purged /p/A/) but the plugin does NOT claim success -> disk layers decide', var_export( $r, true ) . ' ' . notes() );
    chk( xcache( '/p/%41/' ) === 'HIT', 'the real /p/%41/ entry is still cached, so false is correct' );
    $r = nppp_http_purge_try_first( 'http://example.com/p/1/' );
    chk( $r === true || $r === 'miss', 'plain url is still handled by HTTP (' . var_export( $r, true ) . ')' );
}

/* Purge All request times out (delay proxy on :8090 holds the answer) while nginx is still deleting: the plugin waits. */
if ( strpos( $sc, 'race_timeout' ) === 0 ) {
    $T['options']['nginx_cache_settings']['nppp_http_purge_custom_url'] = 'http://127.0.0.1:8090';
    $T['filters']['nppp_http_purge_timeout'] = fn( $v, $t ) => $t === 'all' ? 1 : $v;
    $T['filters']['nppp_http_purge_async_settle_ms'] = fn() => 500;
    $T['filters']['nppp_http_purge_queue_wait'] = fn() => 15;
    warm( 'p', 3000 ); sleep( 2 );
    $before = cnt(); $t = microtime( true );
    $r = nppp_http_purge_all(); $waited = round( microtime( true ) - $t, 2 );
    $left = cnt();
    if ( $r === false ) { shell_exec( 'find /tmp/npp-cache -mindepth 1 -delete' ); }   // what nppp_wp_purge() does
    echo "  cached=$before  purge_all()=" . var_export( $r, true ) . " waited={$waited}s files_after_return=$left  fs_fallback=" . ( $r === false ? 'yes' : 'no' ) . "\n";
    warm( 'warm', 400 );                                                              // preload starts right away
    $f0 = cnt(); sleep( 6 ); $f1 = cnt();
    $hits = 0; for ( $i = 1; $i <= 20; $i++ ) { if ( xcache( "/warm/$i/" ) === 'HIT' ) $hits++; }
    echo "  preload warmed: files=$f0 -> 6 s later files=$f1, HIT on first 20 pages: $hits/20\n";
    chk( $f1 === 400 && $hits === 20, "$sc: after purge_all() returns the preload is NOT touched by nginx (400/400 kept)", "files=$f1 hits=$hits notes=" . notes() );
    chk( $r === true && $waited > 2, "$sc: timed-out request waited out (nginx finished) -> true", var_export( $r, true ) . " waited=$waited " . notes() );
}

/* v4 namespace invalidation with slow physical cleanup (mode ns_slow): entries are logically gone at once, files are removed
 * later. Checks that the late cleanup does not delete pages the preload warmed in the meantime. */
if ( $sc === 'race_invalidated' ) {
    warm( 'p', 3000 ); sleep( 2 );
    $before = cnt();
    $t = microtime( true ); $r = nppp_http_purge_all(); $waited = round( microtime( true ) - $t, 2 );
    $c0 = cnt();
    $gone = xcache( '/p/7/' );                       // logically gone; refilled in the new generation
    warm( 'warm', 400 );                             // preload after purge_all() returned true
    $f0 = cnt(); sleep( 12 ); $f1 = cnt();
    $hits = 0; for ( $i = 1; $i <= 20; $i++ ) { if ( xcache( "/warm/$i/" ) === 'HIT' ) $hits++; }
    $old = 0; for ( $i = 1; $i <= 20; $i++ ) { if ( xcache( "/p/" . ( 100 + $i ) . "/" ) === 'HIT' ) $old++; }
    echo "  cached=$before purge_all()=" . var_export( $r, true ) . " waited={$waited}s files_right_after=$c0 first_p7=$gone\n";
    echo "  preload warmed: files=$f0 -> 12 s later files=$f1, HIT on first 20 warm pages: $hits/20, old /p/ pages still served as HIT: $old/20\n";
    chk( $r === true, 'invalidated -> true', notes() );
    chk( $gone === 'MISS', 'old generation is unreachable at once (MISS right after purge_all)' );
    chk( $c0 > 400, 'physical files are really still on disk right after (cleanup pending)', "files=$c0" );
    chk( $old === 0, 'no old page is served as HIT after invalidation' );
    chk( $hits === 20 && $f1 >= 400, 'late physical cleanup does NOT delete the preloaded new-generation pages', "hits=$hits files=$f1" );
    chk( $f1 < 500, 'old generation files were eventually removed by nginx', "files=$f1" );
}
if ( $sc === 'race_invalidated_single' ) {
    warm( 'p', 3000 ); sleep( 2 );
    $r = nppp_http_purge_try_first( 'http://example.com/p/1/' );
    $x = xcache( '/p/1/' ); $x2 = xcache( '/p/1/' );   // preload re-warms the same URL
    sleep( 3 );
    $x3 = xcache( '/p/1/' );
    echo "  single invalidated -> " . var_export( $r, true ) . "  next requests: $x, $x2, after 3 s: $x3\n";
    chk( $r === true, 'single invalidated -> true', notes() );
    chk( $x === 'MISS' && $x2 === 'HIT' && $x3 === 'HIT', 'same URL re-warmed in the new generation survives the cleanup of the old one' );
}

/* Generation mode on disk (mode ns_slow): cache files carry "KEY: p4:<namespace>:<epoch>:<generation>:<logical key>".
 * Covers: stale and live files of the same logical key coexist while cleanup is pending, only live files remain once
 * the queue is idle, and a higher generation on another key does not make a file stale (the counter is per namespace). */
if ( $sc === 'ns_keys' ) {
    $kl = function () {
        $o = [];
        foreach ( explode( "\n", trim( (string) shell_exec( "grep -a -rh '^KEY:' /tmp/npp-cache" ) ) ) as $l ) {
            if ( preg_match( '#^KEY: p4:objects:\d{10}:([0-9a-f]{16}):httpGETexample\.com(/p/\d+/)$#', $l, $m ) ) { $o[ $m[2] ][] = hexdec( $m[1] ); }
        }
        return $o;
    };
    /* What the Advanced tab / Status counter do with these files: the plugin's real default cache_key_regex (preload-defaults.php)
     * and the real generation helpers (advanced.php; pure functions, loaded by extraction because advanced.php needs WordPress). */
    foreach ( [ 'nppp_key_generation_info', 'nppp_drop_stale_generations', 'nppp_annotate_variant_counts' ] as $fn ) {
        if ( ! function_exists( $fn ) ) {
            preg_match( '/^function ' . $fn . '\(.*?^}\n/ms', (string) file_get_contents( dirname( __DIR__ ) . '/includes/advanced.php' ), $fm ) or ( print( "FAIL missing $fn\n" ) and exit( 2 ) );
            eval( $fm[0] );
        }
    }
    $rx   = ( include dirname( __DIR__ ) . '/includes/preload-defaults.php' )['cache_key_regex'];
    $scan = function () use ( $rx ) {            // returns [ rows after drop+annotate, raw row count, rows that failed regex/URL validation ]
        $urls = []; $bad = 0;
        foreach ( explode( "\n", trim( (string) shell_exec( "grep -a -r -m1 '^KEY:' /tmp/npp-cache" ) ) ) as $raw ) {
            $sep = strpos( $raw, ':' ); if ( $raw === '' || $sep === false ) { continue; }
            $kl = substr( $raw, $sep + 1 );
            if ( ! preg_match( $rx, $kl, $m ) || ! isset( $m[1], $m[2] ) || filter_var( 'http://' . trim( $m[1] ) . trim( $m[2] ), FILTER_VALIDATE_URL ) === false ) { $bad++; continue; }
            $gi = nppp_key_generation_info( $kl );
            $urls[] = [ 'file_path' => substr( $raw, 0, $sep ), 'url_encoded' => 'http://' . trim( $m[1] ) . trim( $m[2] ), 'gen_group' => $gi['group'] ?? null, 'gen' => $gi['gen'] ?? null ];
        }
        $n = count( $urls ); nppp_drop_stale_generations( $urls ); nppp_annotate_variant_counts( $urls );
        $by = []; foreach ( $urls as $u ) { $by[ parse_url( $u['url_encoded'], PHP_URL_PATH ) ][] = $u['variant_count']; }
        return [ $by, $n, $bad ];
    };
    $q    = function () { $j = json_decode( (string) shell_exec( 'curl -s http://127.0.0.1:8081/purge_status' ), true ); return $j['cache'] ?? null; };
    $idle = function () use ( $q ) {
        $n = 0;
        for ( $i = 0; $i < 200; $i++ ) {
            $c = $q(); $n = ( $c && $c['pending']['total'] === 0 && $c['active'] === 0 ) ? $n + 1 : 0;
            if ( $n >= 2 ) { return true; }
            usleep( 200000 );
        }
        return false;
    };

    warm( 'p', 30 ); sleep( 1 );
    $k0 = $kl();
    chk( count( $k0 ) === 30, 'every warmed page has a KEY line in the p4:<ns>:<epoch>:<generation>:<key> format', 'parsed=' . count( $k0 ) );
    $g0 = $k0['/p/1/'][0] ?? -1;
    [ $s0, $n0, $b0 ] = $scan();
    chk( $b0 === 0 && $n0 === 30 && count( $s0 ) === 30, 'the plugin DEFAULT cache_key_regex parses every real p4 KEY line into a valid URL (Advanced/Status would not raise ERROR REGEX)', "raw=$n0 bad=$b0 urls=" . count( $s0 ) );

    $r = nppp_http_purge_all();
    chk( $r === true, 'purge_all: invalidated -> true', notes() );
    foreach ( [ 1, 2, 3 ] as $i ) { xcache( "/p/$i/" ); }          // new generation, written while the old files are still on disk
    $k1 = $kl(); $c = $q();
    chk( count( $k1['/p/1/'] ?? [] ) === 2 && min( $k1['/p/1/'] ) === $g0 && max( $k1['/p/1/'] ) > $g0, 'while the cleanup is pending one logical key has a stale AND a live file', json_encode( $k1['/p/1/'] ?? null ) );
    chk( $c !== null && $c['pending']['total'] + $c['active'] > 0, 'the cleanup queue is busy while the stale file exists' );
    [ $s1, $n1 ] = $scan();
    chk( $n1 > 30 && ( $s1['/p/1/'] ?? null ) === [ 1 ], 'pending cleanup: raw scan sees the stale+live pair, the plugin view keeps ONE live file (Variants = Single, not Multiple)', "raw=$n1 p1=" . json_encode( $s1['/p/1/'] ?? null ) );
    chk( $idle(), 'the cleanup queue goes idle' );
    $k2   = $kl();
    $gens = array_values( array_unique( array_merge( ...array_values( $k2 ) ) ) );
    chk( count( $k2 ) === 3 && count( $gens ) === 1 && $gens[0] > $g0, 'queue idle: only the live generation is left', json_encode( $k2 ) );
    [ $s2, $n2 ] = $scan();
    chk( $n2 === 3 && count( $s2 ) === 3 && $s2['/p/1/'] === [ 1 ], 'queue idle: dropping stale generations changes nothing', "raw=$n2" );

    $gb = $k2['/p/3/'][0] ?? -1;
    $r  = nppp_http_purge_try_first( 'http://example.com/p/2/' );
    xcache( '/p/2/' ); $idle();
    $k3 = $kl();
    chk( $r === true && count( $k3['/p/2/'] ?? [] ) === 1 && $k3['/p/2/'][0] > $gb && ( $k3['/p/3/'][0] ?? -1 ) === $gb && xcache( '/p/3/' ) === 'HIT',
         'single purge raises the generation of that key only; a higher generation elsewhere does NOT make /p/3/ stale', json_encode( $k3 ) );
    [ $s3, $n3 ] = $scan();
    chk( $n3 === 3 && count( $s3 ) === 3 && $s3['/p/3/'] === [ 1 ], 'after the single purge the plugin view still lists all 3 pages once (nothing live is dropped)', "raw=$n3" );
}

/* v4 error-code scenarios: real module error responses against the plugin, one error code per scenario. */
function locks() { return array_keys( nppp_http_purge_state()['locks'] ); }
function nreqs() { global $T; return count( $T['reqs'] ); }
function warmv( $path, array $encodings ) { foreach ( $encodings as $ae ) { shell_exec( "curl -s -o /dev/null -H 'Host: example.com' -H 'Accept-Encoding: $ae' http://127.0.0.1:8081$path" ); } }

/* 403: nginx allow/deny answers a bare 403 (no Cache-Purge-Code); the module's own "from" ACL answers 403 access_denied. */
if ( $sc === 'denied' ) {
    warm( 'p', 5 );
    $r = nppp_http_purge_try_first( 'http://example.com/p/1/' );
    chk( $r === false && locks() === [ 'single' ], 'single: bare nginx 403 -> false, locks single only', var_export( $r, true ) . ' ' . implode( ',', locks() ) );
    $n = nreqs(); nppp_http_purge_try_first( 'http://example.com/p/2/' );
    chk( nreqs() === $n, 'single: locked endpoint is not hit again' );
    $r = nppp_http_purge_all();
    chk( $r === false && locks() === [ 'single', 'all' ], 'purge_all: module 403 access_denied -> false, locks all (separate allow list)', var_export( $r, true ) . ' ' . implode( ',', locks() ) );
    chk( cnt() === 5, 'nothing was purged over HTTP (the filesystem purge has to do it)', 'files=' . cnt() );
}

/* 405: the purge locations only accept PURGE. The module answers 405 + Allow: PURGE; the plugin retries once with that method. */
if ( $sc === 'method405' ) {
    warm( 'p', 5 );
    $r = nppp_http_purge_try_first( 'http://example.com/p/1/' );
    chk( $r === true && xcache( '/p/1/' ) === 'MISS', 'single: GET -> 405 Allow PURGE -> retry PURGE -> purged', var_export( $r, true ) . ' ' . notes() );
    chk( array_slice( $T['reqs'], 0, 2 ) === [ 'GET http://127.0.0.1:8081/purge/p/1/', 'PURGE http://127.0.0.1:8081/purge/p/1/' ], 'single: exactly GET then PURGE', implode( ' | ', $T['reqs'] ) );
    chk( nppp_http_purge_state()['methods'] === [ 'single' => 'PURGE' ], 'single: PURGE remembered', json_encode( nppp_http_purge_state()['methods'] ) );
    $n = nreqs(); nppp_http_purge_try_first( 'http://example.com/p/2/' );
    chk( nreqs() === $n + 1 && substr( $T['reqs'][ $n ], 0, 5 ) === 'PURGE', 'single: the learned method is used straight away' );
    $r = nppp_http_purge_try_first( 'http://example.com/never/' );
    chk( $r === false, 'single: a miss answered to a PURGE request is NOT trusted (key may carry the method) -> false', var_export( $r, true ) );
    $r = nppp_http_purge_all();
    chk( $r === true && cnt() === 0, 'purge_all: GET -> 405 -> retry PURGE -> purged, cache empty', var_export( $r, true ) . ' files=' . cnt() . ' ' . notes() );
    chk( locks() === [], 'no lock was set by a recoverable 405', implode( ',', locks() ) );
}

/* 500 cache_unavailable: the Purge All location has no cache zone. A configuration fault: lock, fall back, single keeps working. */
if ( $sc === 'nocache' ) {
    warm( 'p', 5 );
    $r = nppp_http_purge_all();
    chk( $r === false && locks() === [ 'all' ], 'purge_all: 500 cache_unavailable -> false + lock all', var_export( $r, true ) . ' ' . implode( ',', locks() ) );
    chk( nppp_http_purge_state()['issue']['all']['code'] === 'cache_unavailable' && strpos( nppp_http_purge_config_notice(), 'keys_zone' ) !== false, 'cache_unavailable: the settings page gets an actionable message', nppp_http_purge_config_notice() );
    $n = nreqs(); nppp_http_purge_all();
    chk( nreqs() === $n, 'purge_all: not retried while locked' );
    chk( nppp_http_purge_try_first( 'http://example.com/p/1/' ) === true, 'single purge is unaffected by the all lock' );
}

/* 500 key_mismatch: v4 namespace mode, but the Purge All location builds a different native key than $cache_purge_key. */
if ( $sc === 'keymismatch' ) {
    warm( 'p', 5 );
    $r = nppp_http_purge_all();
    chk( $r === false && locks() === [ 'all' ], 'purge_all: 500 key_mismatch -> false + lock all', var_export( $r, true ) . ' ' . implode( ',', locks() ) );
    chk( nppp_http_purge_state()['issue']['all']['code'] === 'key_mismatch' && strpos( nppp_http_purge_config_notice(), 'fastcgi_cache_key $cache_purge_key;' ) !== false, 'key_mismatch: the settings page names the exact fix', nppp_http_purge_config_notice() );
    chk( nppp_http_purge_try_first( 'http://example.com/p/1/' ) === true && xcache( '/p/1/' ) === 'MISS', 'namespace single purge still works (invalidated)' );
}

/* 200 status: the single endpoint is bound to cache_purge_queue_status. It answers 200 + code "status", not a purge. */
if ( $sc === 'wrongep' ) {
    warm( 'p', 5 );
    $r = nppp_http_purge_try_first( 'http://example.com/p/1/' );
    chk( $r === false && locks() === [ 'single' ], 'single bound to the status handler: 200 "status" is NOT a purge -> false + lock', var_export( $r, true ) . ' ' . implode( ',', locks() ) );
    chk( xcache( '/p/1/' ) === 'HIT', 'the page is still cached (nothing was purged)' );
}

/* 500 delete_failed: one cache file cannot be unlinked (chattr +i). The module deletes the rest and reports the failure. */
if ( $sc === 'deletefail' ) {
    warm( 'p', 30 );
    $f = trim( shell_exec( "find /tmp/npp-cache -type f -regextype posix-extended -regex '.*/[0-9a-f]{32}' | head -1" ) );
    shell_exec( 'chattr +i ' . escapeshellarg( $f ) . ' 2>&1' );
    if ( strpos( (string) shell_exec( 'lsattr ' . escapeshellarg( $f ) . ' 2>&1' ), 'i' ) === false ) { echo "SKIP deletefail: chattr +i is not supported here\n"; exit( 0 ); }
    $r = nppp_http_purge_all();
    shell_exec( 'chattr -i ' . escapeshellarg( $f ) );
    chk( $r === false, 'purge_all: 500 delete_failed -> false (the filesystem purge must finish the job)', var_export( $r, true ) . ' ' . notes() );
    chk( cnt() === 1, 'the module deleted everything it could; only the undeletable file is left', 'files=' . cnt() );
    chk( locks() === [], 'delete_failed is transient: no lock', implode( ',', locks() ) );
}

/* 500 scan_failed: a cache directory cannot be read (mode 000, nginx worker is not root: nginx.sh mode scanfail). */
if ( $sc === 'scanfail' ) {
    warm( 'p', 40 );
    chk( cnt() > 0, 'precondition: worker (nobody) can write to /tmp/npp-cache', 'files=' . cnt() );
    $d = trim( shell_exec( "find /tmp/npp-cache -mindepth 1 -maxdepth 1 -type d | head -1" ) );
    shell_exec( 'chmod 000 ' . escapeshellarg( $d ) );
    $r = nppp_http_purge_all();
    shell_exec( 'chmod 700 ' . escapeshellarg( $d ) );
    chk( $r === false, 'purge_all: 500 scan_failed -> false (the filesystem purge must finish the job)', var_export( $r, true ) . ' ' . notes() );
    chk( cnt() > 0, 'the unreadable directory kept its files', 'files=' . cnt() );
    chk( locks() === [], 'scan_failed is transient: no lock', implode( ',', locks() ) );
}

/* Vary: the same URL is cached once per Accept-Encoding. The purge request has none, so the "primary" entry does not exist. */
if ( $sc === 'vary' ) {
    warmv( '/v/1/', [ 'gzip', 'br' ] );
    chk( cnt() === 2, 'two Vary variants of /v/1/ are cached', 'files=' . cnt() );
    $r = nppp_http_purge_try_first( 'http://example.com/v/1/' );
    chk( $r === true && cnt() === 0, 'cache_purge_vary_aware on: primary absent, variants removed -> purged -> true (v4 does not 412 here)', var_export( $r, true ) . ' files=' . cnt() . ' ' . notes() );
    $r = nppp_http_purge_try_first( 'http://example.com/v/never/' );
    chk( $r === 'miss', 'nothing cached under that key at all -> no_match -> miss', var_export( $r, true ) );
}
if ( $sc === 'vary_off' ) {
    warmv( '/v/1/', [ 'gzip', 'br' ] );
    $r = nppp_http_purge_try_first( 'http://example.com/v/1/' );
    echo "  KNOWN LIMITATION (Vary honoured, cache_purge_vary_aware off): purge answered -> " . var_export( $r, true ) . ", variants left: " . cnt() . "\n";
    chk( $r === 'miss' && cnt() === 2, 'vary_aware off: module answers no_match, the plugin reports a miss and BOTH variants stay cached (documented)', var_export( $r, true ) . ' files=' . cnt() );
    $T['filters']['nppp_http_purge_trust_miss'] = fn() => false;
    $r = nppp_http_purge_try_first( 'http://example.com/v/1/' );
    chk( $r === false, 'workaround: nppp_http_purge_trust_miss => false makes the plugin fall through to the disk layers', var_export( $r, true ) );
}
/* 400 invalid_selector: with vary_aware on an exact key is limited to 511 bytes. Per-URL fault: no lock, the next URL still works. */
if ( $sc === 'badsel' ) {
    warm( 'p', 3 );
    $long = 'http://example.com/' . str_repeat( 'a', 600 ) . '/';
    $r = nppp_http_purge_try_first( $long );
    chk( $r === false && locks() === [], 'key over 511 bytes: 400 invalid_selector -> false, NO lock', var_export( $r, true ) . ' ' . implode( ',', locks() ) );
    chk( nppp_http_purge_try_first( 'http://example.com/p/1/' ) === true, 'the next (short) URL is still purged over HTTP' );
}
echo $F ? "== $sc: $F FAILED\n" : "== $sc: all passed\n";
exit( $F ? 1 : 0 );
