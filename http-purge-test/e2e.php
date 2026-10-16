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
/* The race the user asked about: 202 background purge, then (what purge.php does) FS fallback if false, then preload. */
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
    warm( 'warm', 400 );                                                              // preload starts right away
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
    chk( xcache( '/p/%41/' ) === 'HIT', 'the real /p/%41/ entry is still cached (this is why false is right)' );
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

/* v4 namespace (generation) invalidation with SLOW physical cleanup (mode ns_slow): "invalidated" = logically gone at once,
 * files removed later. Does the late cleanup delete what the preload warmed right after? */
if ( $sc === 'race_invalidated' ) {
    warm( 'p', 3000 ); sleep( 2 );
    $before = cnt();
    $t = microtime( true ); $r = nppp_http_purge_all(); $waited = round( microtime( true ) - $t, 2 );
    $c0 = cnt();
    $gone = xcache( '/p/7/' );                       // logically gone at once (and refilled in the new generation)
    warm( 'warm', 400 );                             // what the preload does right after purge_all() returned true
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
    $x = xcache( '/p/1/' ); $x2 = xcache( '/p/1/' );   // preload re-warms the same URL right away
    sleep( 3 );
    $x3 = xcache( '/p/1/' );
    echo "  single invalidated -> " . var_export( $r, true ) . "  next requests: $x, $x2, after 3 s: $x3\n";
    chk( $r === true, 'single invalidated -> true', notes() );
    chk( $x === 'MISS' && $x2 === 'HIT' && $x3 === 'HIT', 'same URL re-warmed in the new generation survives the cleanup of the old one' );
}
echo $F ? "== $sc: $F FAILED\n" : "== $sc: all passed\n";
exit( $F ? 1 : 0 );
