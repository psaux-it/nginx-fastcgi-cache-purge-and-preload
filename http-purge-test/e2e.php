<?php
/** Real-nginx e2e for purge-http.php: php e2e.php <scenario> [purge-http.php] */
error_reporting( E_ALL );
define( 'ABSPATH', '/tmp/' ); define( 'MINUTE_IN_SECONDS', 60 ); define( 'HOUR_IN_SECONDS', 3600 ); define( 'DAY_IN_SECONDS', 86400 );
class WP_Error { public $m; function __construct( $c = '', $m = '' ) { $this->m = $m; } function get_error_messages() { return [ $this->m ]; } }
$T = [ 'options' => [ 'nginx_cache_settings' => [ 'nppp_http_purge_enabled' => 'yes', 'nginx_cache_path' => '/tmp/npp-cache',
        'nppp_http_purge_custom_url' => 'http://127.0.0.1:8081' ] ], 'tr' => [], 'notices' => [], 'reqs' => [] ];
function get_option( $k, $d = false ) { global $T; return $T['options'][ $k ] ?? $d; }
function update_option( $k, $v, $autoload = null ) { global $T; $T['options'][ $k ] = $v; $T['autoload'][ $k ] = $autoload; return true; }
function delete_option( $k ) { global $T; unset( $T['options'][ $k ], $T['autoload'][ $k ] ); return true; }
function wp_cache_delete( $k, $g = '' ) { return true; }
function home_url() { return 'http://example.com'; }
function wp_parse_url( $u, $c = -1 ) { return parse_url( $u, $c ); }
function absint( $n ) { return abs( (int) $n ); } function esc_url( $u ) { return $u; } function esc_url_raw( $u ) { return $u; } function __( $s, $d = '' ) { return $s; }
function apply_filters( $t, $v, ...$a ) { global $T; $f = $T['filters'][ $t ] ?? null; return $f ? $f( $v, ...$a ) : $v; }
function do_action( $tag, ...$a ) { global $T; $T['actions'][] = [ $tag, $a ]; }   /* recorded: deferred_waiter counts nppp_purged_all */
function add_action( ...$a ) {} function remove_action( ...$a ) {}
/* deferred_waiter: the waiter POSTs to admin_url('admin-ajax.php'); point it at wakelistener.py (NPP_WAKE_BASE overrides) */
function admin_url( $p = '' ) { return ( getenv( 'NPP_WAKE_BASE' ) ?: 'http://127.0.0.1:8092/wp-admin/' ) . $p; }
function wp_schedule_single_event( $ts, $hook, $args = [] ) { global $T; $T['cron'][] = [ 'hook' => $hook, 'ts' => (int) $ts ]; return true; }
function wp_unschedule_hook( $hook ) {
    global $T; $n = 0;
    foreach ( $T['cron'] ?? [] as $i => $e ) { if ( $e['hook'] === $hook ) { unset( $T['cron'][ $i ] ); $n++; } }
    $T['cron'] = array_values( $T['cron'] ?? [] ); return $n;
}
/* $wpdb->delete(): the plugin's atomic claim of the deferred state row (same contract as the mock harness) */
class E2eWpdb {
    public $options = 'wp_options';
    function delete( $table, $where, $fmt = null ) {
        global $T; $k = $where['option_name'] ?? '';
        if ( array_key_exists( $k, $T['options'] ) ) { unset( $T['options'][ $k ] ); return 1; }
        return 0;
    }
}
$wpdb = new E2eWpdb();
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

/* Plugin includes/ dir for the helper files ns_keys loads (advanced.php, preload-defaults.php). Inside the plugin
 * checkout it is ../includes; in the Docker lab the harness lives in /opt/npp-http-purge-test, so fall back to the
 * deployed plugin under WP_PATH. NPP_INCLUDES_DIR overrides both. */
function npp_includes_dir() {
    $c = [ getenv( 'NPP_INCLUDES_DIR' ) ?: '', dirname( __DIR__ ) . '/includes', rtrim( getenv( 'WP_PATH' ) ?: '/var/www/html', '/' ) . '/wp-content/plugins/fastcgi-cache-purge-and-preload-nginx/includes' ];
    foreach ( $c as $d ) { if ( $d !== '' && is_file( $d . '/advanced.php' ) && is_file( $d . '/preload-defaults.php' ) ) { return $d; } }
    fwrite( STDERR, "FAIL cannot find plugin includes/ (tried: " . implode( ', ', array_filter( $c ) ) . "); set NPP_INCLUDES_DIR\n" ); exit( 2 );
}

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

/* Slow background queue (mode async_slow, the walk takes ~35-40 s, far longer than the plugin's wait budget).
 * The caller of purge_all() (nppp_purge_helper) opts in to the pending outcome by passing $pending: after the wait budget
 * a queue that is verifiably still working returns false + $pending, and the caller must NOT run the filesystem purge.
 * The preload spawn is then wrapped by nppp_http_purge_gate_command(): the crawl starts only when nginx's queue is idle, so
 * the late walk cannot delete the preloaded pages, and the user's request never blocks on the long tail.
 * SLOWQ_WAIT = the plugin's wait budget in seconds (default 5, the plugin caps it at 40); a value above the walk time makes
 * purge_all() prove completion and return true instead (nothing pending, no gate). */
if ( $sc === 'slowq' ) {
    $T['options']['nginx_cache_settings']['nppp_http_purge_queue_status_path'] = 'purge_status';
    $wait = max( 1, (int) ( getenv( 'SLOWQ_WAIT' ) ?: 5 ) );
    $T['filters']['nppp_http_purge_queue_wait'] = fn() => $wait;
    $q = function () { $j = json_decode( (string) shell_exec( 'curl -s http://127.0.0.1:8081/purge_status' ), true ); return $j['cache'] ?? null; };
    $idle_now = function () use ( $q ) { $s = $q(); return $s && $s['pending']['total'] === 0 && $s['active'] === 0; };
    foreach ( [ '/tmp/npp-gate-files', '/tmp/npp-gate-done', '/tmp/npp-gate.log' ] as $f ) { @unlink( $f ); }

    warm( 'p', 600 ); sleep( 2 );
    $before = cnt();
    chk( $before === 600 && $q() !== null, 'slowq: 600 pages cached, queue status endpoint answers', "cached=$before" );
    $t = microtime( true ); $pending = null; $r = nppp_http_purge_all( $pending ); $waited = round( microtime( true ) - $t, 1 );
    $left = cnt();
    echo "  cached=$before  purge_all()=" . var_export( $r, true ) . " pending=" . ( is_array( $pending ) ? 'yes' : 'no' ) . " waited={$waited}s files_after_return=$left\n";

    if ( $r === true ) {
        chk( $left === 0 && $pending === null, 'slowq: wait budget covers the queue -> true, cache empty, nothing pending (no gate needed)', "left=$left" );
    } else {
        chk( is_array( $pending ) && $left > 0, 'slowq: queue still working after the budget -> false + $pending, old files still cached (the caller must not run the filesystem purge)', "pending=" . var_export( $pending, true ) . " left=$left" );
        chk( $waited < $wait + 8, "slowq: the request waits only the budget ({$wait}s), not the ~35 s queue", "waited=$waited" );
        chk( strpos( notes(), 'still deleting' ) !== false, 'slowq: admin notice explains the skipped filesystem purge and the queued preload', 'notes=' . notes() );

        // The spawned preload: a stand-in crawl that records how many OLD files are left at its first instruction, then warms 400 pages.
        $crawl = 'sh -c ' . escapeshellarg( 'find /tmp/npp-cache -type f | wc -l > /tmp/npp-gate-files; i=1; while [ $i -le 400 ]; do curl -s -o /dev/null -H "Host: example.com" http://127.0.0.1:8081/warm/$i/; i=$((i+1)); done; date +%s > /tmp/npp-gate-done' );
        nppp_http_purge_gate_arm( is_array( $pending ) ? $pending : [] );
        $busy = ! $idle_now();
        $t2 = microtime( true ); $pid = (int) trim( (string) shell_exec( nppp_http_purge_gate_command( $crawl, '/tmp/npp-gate.log' ) ) ); $spawn = round( microtime( true ) - $t2, 2 );
        chk( $pid > 1 && $spawn < 2 && nppp_http_purge_gate_used(), 'slowq: spawn returns a PID at once and reports the gate (the user is not blocked)', "pid=$pid spawn={$spawn}s" );
        usleep( 1500000 );
        $alive = (bool) trim( (string) shell_exec( "ps -p $pid -o pid=" ) );
        chk( $busy && $alive && ! file_exists( '/tmp/npp-gate-files' ), 'slowq: while nginx is still deleting the process is alive but the crawl has NOT started', "busy=" . var_export( $busy, true ) . " alive=" . var_export( $alive, true ) );

        $t1 = microtime( true ); $idle_at_start = null; $seen = false;
        while ( microtime( true ) - $t1 < 150 && ! file_exists( '/tmp/npp-gate-done' ) ) {
            if ( ! $seen && file_exists( '/tmp/npp-gate-files' ) ) { $seen = true; $idle_at_start = $idle_now(); }
            usleep( 200000 );
        }
        $at_start = file_exists( '/tmp/npp-gate-files' ) ? (int) trim( (string) file_get_contents( '/tmp/npp-gate-files' ) ) : -1;
        chk( file_exists( '/tmp/npp-gate-done' ), 'slowq: the gated crawl ran after the queue went idle (within 150 s)' );
        chk( $at_start === 0 && ( $idle_at_start === true || ! $seen ), 'slowq: the crawl started only when nginx\'s queue was idle and no old file was left', "files_at_start=$at_start idle_at_start=" . var_export( $idle_at_start, true ) );
        sleep( 2 ); $f1 = cnt();
        $hits = 0; for ( $i = 1; $i <= 20; $i++ ) { if ( xcache( "/warm/$i/" ) === 'HIT' ) $hits++; }
        $gone = ! trim( (string) shell_exec( "ps -p $pid -o pid=" ) );
        echo "  gated crawl: waited " . round( microtime( true ) - $t2, 1 ) . "s, files=$f1 (expected 400), HIT on first 20 pages: $hits/20\n";
        chk( $f1 === 400 && $hits === 20, 'slowq: all 400 preloaded pages survive (the late nginx walk did not delete them)', "files=$f1 hits=$hits" );
        chk( $gone && $idle_now(), 'slowq: the gate process is gone and the queue is idle', "gone=" . var_export( $gone, true ) );
    }
}

/* The Purge All request ends with nginx's queue still working ($pending). Its in-request hand-off dies with the request and a
 * persistent-cache flush (NPP's Redis sync) wipes transients. A crawl spawned by a LATER request, with NO
 * nppp_http_purge_gate_arm(), must still wait for the REAL queue (status endpoint of ngx_cache_purge), also after stop + restart,
 * must start once the queue is idle, and must not be gated by a stale marker. Needs mode async_slow (queue ~35-40 s). */
if ( $sc === 'slowq_marker' ) {
    $T['options']['nginx_cache_settings']['nppp_http_purge_queue_status_path'] = 'purge_status';
    $wait = max( 1, (int) ( getenv( 'SLOWQ_WAIT' ) ?: 5 ) );
    $T['filters']['nppp_http_purge_queue_wait'] = fn() => $wait;
    $q = function () { $j = json_decode( (string) shell_exec( 'curl -s http://127.0.0.1:8081/purge_status' ), true ); return $j['cache'] ?? null; };
    $idle_now = function () use ( $q ) { $s = $q(); return $s && $s['pending']['total'] === 0 && $s['active'] === 0; };
    $gate_files = [ '/tmp/npp-gate-files', '/tmp/npp-gate-done', '/tmp/npp-gate.log', '/tmp/npp-gate-failopen' ];
    foreach ( $gate_files as $f ) { @unlink( $f ); }
    $crawl = 'sh -c ' . escapeshellarg( 'find /tmp/npp-cache -type f | wc -l > /tmp/npp-gate-files; i=1; while [ $i -le 400 ]; do curl -s -o /dev/null -H "Host: example.com" http://127.0.0.1:8081/warm/$i/; i=$((i+1)); done; date +%s > /tmp/npp-gate-done' );
    $spawn = function ( string $cmd ) { $t = microtime( true ); $pid = (int) trim( (string) shell_exec( $cmd ) ); return [ $pid, round( microtime( true ) - $t, 2 ) ]; };
    $marker = fn() => $GLOBALS['T']['options'][ NPPP_HTTP_PURGE_PENDING_KEY ] ?? null;
    // A killed process stays a defunct (Z) entry until the container's init reaps it: that is dead, not alive.
    $alive_p = function ( int $p ) { $s = trim( (string) shell_exec( "ps -p $p -o stat= 2>/dev/null" ) ); return $s !== '' && $s[0] !== 'Z'; };

    warm( 'p', 600 ); sleep( 2 );
    $before = cnt();
    chk( $before === 600 && $q() !== null, 'slowq_marker: 600 pages cached, queue status endpoint answers', "cached=$before" );
    $pending = null; $r = nppp_http_purge_all( $pending ); $left = cnt();
    echo "  purge_all()=" . var_export( $r, true ) . " pending=" . ( is_array( $pending ) ? 'yes' : 'no' ) . " files_after_return=$left\n";

    if ( $r === true ) {
        chk( $pending === null && $marker() === null, 'slowq_marker: wait budget covers the queue -> true, no marker (nothing to gate)', var_export( $marker(), true ) );
    } else {
        chk( is_array( $pending ) && $marker() === $pending, 'slowq_marker: pending Purge All is persisted as the marker, as handed off', 'marker=' . var_export( $marker(), true ) );
        chk( ( $GLOBALS['T']['autoload'][ NPPP_HTTP_PURGE_PENDING_KEY ] ?? 'unset' ) === false, 'slowq_marker: marker is a NON-autoloaded option' );

        // The request ends: hand-off gone, transients wiped (object-cache flush). Only the option survives.
        nppp_http_purge_gate_state( 'clear' ); $T['tr'] = []; $T['notices'] = [];
        chk( $marker() === $pending && ! $idle_now(), 'slowq_marker: marker survives the request end + cache flush while nginx is still deleting' );

        // Control: without the marker a later spawn is the plain command (what happened before this patch).
        $saved = $T['options'][ NPPP_HTTP_PURGE_PENDING_KEY ]; delete_option( NPPP_HTTP_PURGE_PENDING_KEY );
        $plain = nppp_http_purge_gate_command( $crawl, '/tmp/npp-gate.log' );
        chk( strpos( $plain, "( trap '' HUP; " ) !== 0 && ! nppp_http_purge_gate_used(), 'slowq_marker: control - no marker, no hand-off -> plain ungated command' );
        update_option( NPPP_HTTP_PURGE_PENDING_KEY, $saved, false ); nppp_http_purge_gate_state( 'clear' );

        // LATER request #1: spawn WITHOUT arm(): gated from the marker + the real queue status.
        [ $pid, $spawn_s ] = $spawn( nppp_http_purge_gate_command( $crawl, '/tmp/npp-gate.log' ) );
        chk( $pid > 1 && $spawn_s < 3 && nppp_http_purge_gate_used(), 'slowq_marker: later request spawn returns a PID at once and reports the gate', "pid=$pid spawn={$spawn_s}s" );
        usleep( 1500000 );
        $alive = $alive_p( $pid );
        chk( $alive && ! file_exists( '/tmp/npp-gate-files' ) && ! $idle_now(), 'slowq_marker: while nginx is still deleting the crawl process waits and has NOT started' );
        chk( $marker() !== null, 'slowq_marker: the gated spawn does not consume the marker' );

        // The user stops the crawl and starts it again while the queue is still working.
        shell_exec( "kill $pid" ); usleep( 500000 );
        $dead = ! $alive_p( $pid );
        nppp_http_purge_gate_state( 'clear' );
        [ $pid2, $spawn_s2 ] = $spawn( nppp_http_purge_gate_command( $crawl, '/tmp/npp-gate.log' ) );
        usleep( 1500000 );
        $alive2 = $alive_p( $pid2 );
        chk( $dead && $pid2 > 1 && $pid2 !== $pid && $alive2 && ! file_exists( '/tmp/npp-gate-files' ) && nppp_http_purge_gate_used(), 'slowq_marker: stop + restart while the queue is busy -> gated again, still not started', "dead=" . var_export( $dead, true ) . " pid2=$pid2" );

        $t1 = microtime( true ); $idle_at_start = null; $seen = false;
        while ( microtime( true ) - $t1 < 150 && ! file_exists( '/tmp/npp-gate-done' ) ) {
            if ( ! $seen && file_exists( '/tmp/npp-gate-files' ) ) { $seen = true; $idle_at_start = $idle_now(); }
            usleep( 200000 );
        }
        $at_start = file_exists( '/tmp/npp-gate-files' ) ? (int) trim( (string) file_get_contents( '/tmp/npp-gate-files' ) ) : -1;
        chk( file_exists( '/tmp/npp-gate-done' ), 'slowq_marker: the marker-gated crawl ran after the queue went idle (within 150 s)' );
        chk( $at_start === 0 && ( $idle_at_start === true || ! $seen ), 'slowq_marker: the crawl started only when the real queue was idle and no old file was left', "files_at_start=$at_start idle_at_start=" . var_export( $idle_at_start, true ) );
        sleep( 2 ); $f1 = cnt();
        $hits = 0; for ( $i = 1; $i <= 20; $i++ ) { if ( xcache( "/warm/$i/" ) === 'HIT' ) $hits++; }
        chk( $f1 === 400 && $hits === 20, 'slowq_marker: all 400 preloaded pages survive (the late nginx walk did not delete them)', "files=$f1 hits=$hits" );

        // Queue idle now: the stale marker must NOT gate (two idle samples from the real status JSON), and it is dropped.
        nppp_http_purge_gate_state( 'clear' );
        $cmd = nppp_http_purge_gate_command( $crawl, '/tmp/npp-gate.log' );
        chk( $cmd === $crawl . " > '/tmp/npp-gate.log' 2>&1 < /dev/null & echo \$!" && ! nppp_http_purge_gate_used(), 'slowq_marker: queue idle -> byte-identical plain command, no false gate', substr( $cmd, 0, 80 ) );
        chk( $marker() === null, 'slowq_marker: stale marker dropped once the real queue is idle' );

        // Unreadable status endpoint: still gated by the marker, the SHELL gate fails open after 5 failed polls.
        update_option( NPPP_HTTP_PURGE_PENDING_KEY, [ 'status_url' => 'http://127.0.0.1:9/purge_status', 'host' => 'example.com', 'since' => time(), 'armed_at' => time() ], false );
        nppp_http_purge_gate_state( 'clear' );
        $fo = 'sh -c ' . escapeshellarg( 'date +%s > /tmp/npp-gate-failopen' );
        [ $pid3, $spawn_s3 ] = $spawn( nppp_http_purge_gate_command( $fo, '/tmp/npp-gate.log' ) );
        chk( $pid3 > 1 && nppp_http_purge_gate_used() && $marker() !== null, 'slowq_marker: dead status endpoint -> still gated, marker kept (an idle verdict needs proof)' );
        $t3 = microtime( true ); while ( microtime( true ) - $t3 < 30 && ! file_exists( '/tmp/npp-gate-failopen' ) ) { usleep( 200000 ); }
        $fo_s = round( microtime( true ) - $t3, 1 );
        chk( file_exists( '/tmp/npp-gate-failopen' ) && $fo_s >= 3 && $fo_s < 25, 'slowq_marker: the shell gate fails open after 5 failed polls (crawl runs, not blocked forever)', "failopen_after={$fo_s}s" );
        delete_option( NPPP_HTTP_PURGE_PENDING_KEY );
    }
}

/* Deferred Purge All follow-up (F-01) against the REAL queue. A Purge All ends with nginx's queue still deleting (status 7):
 * nppp_purged_all must NOT fire yet. Part A: the real detached waiter (real bash/wget/date/sleep, real purge_status JSON) must
 * stay silent while nginx deletes and then POST the one-shot token exactly once, to the wake endpoint (wakelistener.py stands in
 * for admin-ajax.php), only after every old file is gone. Part B: the waiter is killed (php-fpm restart, OOM) and the WP-Cron
 * fail-safe alone must fire the hook once the real queue is idle. Needs mode async_slow (queue ~35-40 s) and wget on PATH. */
if ( $sc === 'deferred_waiter' ) {
    if ( trim( (string) shell_exec( 'command -v wget 2>/dev/null' ) ) === '' ) { echo "SKIP deferred_waiter: no wget on PATH (the waiter needs it)\n"; exit( 0 ); }
    $T['options']['nginx_cache_settings']['nppp_http_purge_queue_status_path'] = 'purge_status';
    $wait = max( 1, (int) ( getenv( 'SLOWQ_WAIT' ) ?: 5 ) );
    $T['filters']['nppp_http_purge_queue_wait'] = fn() => $wait;
    $q = function () { $j = json_decode( (string) shell_exec( 'curl -s http://127.0.0.1:8081/purge_status' ), true ); return $j['cache'] ?? null; };
    $idle_now = function () use ( $q ) { $s = $q(); return $s && $s['pending']['total'] === 0 && $s['active'] === 0; };
    $fires    = fn() => count( array_filter( $GLOBALS['T']['actions'] ?? [], fn( $a ) => $a[0] === 'nppp_purged_all' ) );
    $state    = fn() => $GLOBALS['T']['options'][ NPPP_PURGED_ALL_DEFERRED_KEY ] ?? null;
    $marker   = fn() => $GLOBALS['T']['options'][ NPPP_HTTP_PURGE_PENDING_KEY ] ?? null;
    $crons    = fn() => count( array_filter( $GLOBALS['T']['cron'] ?? [], fn( $e ) => $e['hook'] === NPPP_PURGED_ALL_DEFERRED_HOOK ) );
    $posts    = function () { return is_file( '/tmp/npp-wake.log' ) ? file( '/tmp/npp-wake.log', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES ) : []; };
    // pgrep -f on the waiter's script (it carries the token). [x]rest keeps pgrep from matching the shell that runs it.
    $waiters  = function ( string $tok ) { $r = trim( (string) shell_exec( 'pgrep -f ' . escapeshellarg( '[' . $tok[0] . ']' . substr( $tok, 1 ) ) . ' 2>/dev/null' ) ); return $r === '' ? [] : array_map( 'intval', explode( "\n", $r ) ); };
    $reset    = function () { global $T; $T['actions'] = []; $T['cron'] = []; $T['notices'] = []; delete_option( NPPP_PURGED_ALL_DEFERRED_KEY ); delete_option( NPPP_HTTP_PURGE_PENDING_KEY ); nppp_http_purge_gate_state( 'clear' ); };
    $stop_listener = function () { shell_exec( "pkill -f '[w]akelistener.py' 2>/dev/null" ); };   /* [w]: do not match the shell running pkill */
    register_shutdown_function( $stop_listener );

    // The wake endpoint stand-in.
    $stop_listener(); @unlink( '/tmp/npp-wake.log' );
    shell_exec( 'setsid nohup python3 ' . escapeshellarg( __DIR__ . '/wakelistener.py' ) . ' >/dev/null 2>&1 < /dev/null &' );
    $up = false; for ( $i = 0; $i < 30 && ! $up; $i++ ) { $up = trim( (string) shell_exec( 'curl -s -o /dev/null -w %{http_code} http://127.0.0.1:8092/ 2>/dev/null' ) ) === '200'; if ( ! $up ) usleep( 100000 ); }
    chk( $up, 'deferred_waiter: wake listener answers on 127.0.0.1:8092' );

    // Brings the world to "Purge All ended with nginx still deleting". Returns the marker, or null when the wait budget covered the queue.
    $start_cycle = function ( string $label ) use ( $q, $reset, $marker ) {
        $reset(); warm( 'p', 600 ); sleep( 2 );
        $before = cnt();
        chk( $before === 600 && $q() !== null, "deferred_waiter $label: 600 pages cached, queue status endpoint answers", "cached=$before" );
        $pending = null; $r = nppp_http_purge_all( $pending );
        echo "  $label: purge_all()=" . var_export( $r, true ) . ' pending=' . ( is_array( $pending ) ? 'yes' : 'no' ) . ' files_after_return=' . cnt() . "\n";
        if ( $r === true ) { echo "  $label: wait budget covered the queue (SLOWQ_WAIT too high): nothing to defer\n"; return null; }
        chk( is_array( $pending ) && $marker() === $pending, "deferred_waiter $label: Purge All ends with status 7 and the marker is persisted", 'marker=' . var_export( $marker(), true ) );
        return $pending;
    };

    /* ---- Part A: the real waiter */
    if ( $start_cycle( 'A' ) !== null ) {
        nppp_http_purge_fire_purged_all( 7, true );
        $s = $state(); $tok = (string) ( $s['token'] ?? '' );
        chk( $fires() === 0, 'A: status 7: nppp_purged_all is NOT fired while nginx still deletes (the F-01 bug)' );
        chk( preg_match( '/^[a-f0-9]{32}$/', $tok ) === 1 && ( $s['waiter'] ?? null ) === true && ( $s['auto_preload'] ?? null ) === true, 'A: state armed with a one-shot token, waiter spawned, auto_preload remembered', json_encode( $s ) );
        chk( $crons() === 1 && ( $GLOBALS['T']['cron'][0]['ts'] ?? 0 ) - ( $s['armed_at'] ?? 0 ) === 60, 'A: cron fail-safe armed as the slow second path (+60 s) because a waiter is alive' );
        usleep( 2500000 );
        chk( count( $waiters( $tok ) ) >= 1, 'A: the detached waiter survived the spawn (nohup + & ) and is polling', 'pids=' . json_encode( $waiters( $tok ) ) );
        chk( ! $idle_now() && $posts() === [] && cnt() > 0, 'A: while nginx is still deleting the waiter has NOT POSTed (real purge_status JSON parsed as busy)', 'posts=' . count( $posts() ) . ' files=' . cnt() );

        $t1 = microtime( true ); while ( microtime( true ) - $t1 < 150 && $posts() === [] ) { usleep( 200000 ); }
        $left = cnt(); $idle = $idle_now(); $p = $posts();
        chk( count( $p ) === 1, 'A: the waiter POSTed after the real queue went idle (within 150 s)', 'posts=' . count( $p ) );
        chk( $left === 0 && $idle, 'A: ... and only when every old file was deleted and the queue idle', "files_at_post=$left idle=" . var_export( $idle, true ) );
        $parts = explode( "\t", (string) ( $p[0] ?? '' ), 3 ); parse_str( $parts[2] ?? '', $body );
        chk( ( $parts[1] ?? '' ) === '/wp-admin/admin-ajax.php' && ( $body['action'] ?? '' ) === NPPP_PURGED_ALL_WAKE_ACTION, 'A: POST target and action are admin-ajax.php / ' . NPPP_PURGED_ALL_WAKE_ACTION, (string) ( $p[0] ?? '' ) );
        chk( ( $body['token'] ?? '' ) === $tok, 'A: the POSTed token is the state\'s one-shot token' );
        sleep( 3 );
        chk( count( $posts() ) === 1 && $waiters( $tok ) === [], 'A: exactly one POST, and the waiter process is gone afterwards', 'posts=' . count( $posts() ) . ' pids=' . json_encode( $waiters( $tok ) ) );
        // What the wake handler (watchdog.php, needs WordPress) does with the POSTed token: single-use claim.
        $c1 = nppp_http_purge_deferred_claim( (string) ( $body['token'] ?? '' ) ); $c2 = nppp_http_purge_deferred_claim( (string) ( $body['token'] ?? '' ) );
        chk( is_array( $c1 ) && $c2 === null, 'A: the real POSTed token claims the real state exactly once (a retried POST cannot double-fire)' );
        $GLOBALS['T']['cron'] = []; nppp_http_purge_run_deferred_purged_all();
        chk( $fires() === 0, 'A: a late cron tick after the waiter claimed fires nothing (hook firing itself is the handler\'s job, mock section F)' );
    }

    /* ---- Part B: no waiter, WP-Cron alone */
    $t_idle = microtime( true ); while ( ! $idle_now() && microtime( true ) - $t_idle < 60 ) { usleep( 500000 ); }
    if ( $start_cycle( 'B' ) !== null ) {
        nppp_http_purge_fire_purged_all( 7, true );
        $s = $state(); $tok = (string) ( $s['token'] ?? '' );
        chk( $fires() === 0 && preg_match( '/^[a-f0-9]{32}$/', $tok ) === 1, 'B: deferred again (hook not fired)' );
        shell_exec( 'pkill -f ' . escapeshellarg( '[' . $tok[0] . ']' . substr( $tok, 1 ) ) . ' 2>/dev/null' ); usleep( 500000 );
        chk( $waiters( $tok ) === [], 'B: setup - the waiter is dead (php-fpm restart / OOM)' );
        @unlink( '/tmp/npp-wake.log' );

        $busy_ticks = 0; $t1 = microtime( true ); $fired_files = null;
        while ( microtime( true ) - $t1 < 150 && $fires() === 0 ) {
            wp_unschedule_hook( NPPP_PURGED_ALL_DEFERRED_HOOK );            // WP-Cron: the running event has left the schedule
            nppp_http_purge_run_deferred_purged_all();
            if ( $fires() === 0 ) { $busy_ticks++; usleep( 1000000 ); } else { $fired_files = cnt(); }
        }
        chk( $busy_ticks >= 1, 'B: cron ticks while the real queue is busy fire nothing and re-arm (it waited ' . $busy_ticks . ' tick(s))', "busy_ticks=$busy_ticks" );
        chk( $fires() === 1 && $fired_files === 0, 'B: the cron fail-safe fired the hook once, only after the real queue deleted every file', 'fires=' . $fires() . ' files_at_fire=' . var_export( $fired_files, true ) );
        chk( ( $GLOBALS['T']['actions'][0][1] ?? null ) === [ true ], 'B: with the auto_preload flag of the Purge All that armed it' );
        chk( $state() === null && $marker() === null, 'B: state consumed and the stale gate marker dropped once the real queue proved idle' );
        $pre = $fires(); nppp_http_purge_run_deferred_purged_all();
        chk( $fires() === $pre && $posts() === [], 'B: one more tick fires nothing (at most once) and no wake POST happened' );
    }
    $stop_listener();
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
    foreach ( [ 'nppp_key_generation_info', 'nppp_gen_mode_note_seen', 'nppp_generation_views', 'nppp_generation_stale_groups', 'nppp_drop_stale_generations', 'nppp_annotate_variant_counts' ] as $fn ) {
        if ( ! function_exists( $fn ) ) {
            preg_match( '/^function ' . $fn . '\(.*?^}\n/ms', (string) file_get_contents( npp_includes_dir() . '/advanced.php' ), $fm ) or ( print( "FAIL missing $fn\n" ) and exit( 2 ) );
            eval( $fm[0] );
        }
    }
    $rx   = ( include npp_includes_dir() . '/preload-defaults.php' )['cache_key_regex'];
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

    /* Namespace state from the real status endpoint, applied by the REAL Status counter and Advanced scan (ripgrep and PHP
     * paths) over the real cache directory. WordPress is stubbed just enough for those functions to run. */
    if ( ! function_exists( 'wp_is_using_https' ) ) {
        function wp_is_using_https() { return false; }
        function get_site_url() { return 'http://example.com'; }
        function nppp_prepare_request_env() {}
        function nppp_categorize_url( $u ) { return 'POST'; }
        function nppp_check_perm_in_cache( $a = false, $b = false, $c = false ) { return 'true'; }
        function nppp_check_path() { return 'Found'; }
        function nppp_check_rg_version() { return '14.1.0'; }
        function nppp_fuse_source_path( $p ) { return null; }
        function nppp_get_cache_key_regex(): string { return $GLOBALS['NPPP_RX']; }
        class FakeFs { function is_dir( $p ) { return is_dir( $p ); } function get_contents( $p ) { return @file_get_contents( $p ); } }
        function nppp_initialize_wp_filesystem() { return new FakeFs(); }
    }
    $GLOBALS['NPPP_RX'] = $rx;
    foreach ( [ [ 'advanced.php', [ 'nppp_extract_cached_urls', 'nppp_extract_cached_urls_rg', 'nppp_generation_views', 'nppp_generation_stale_groups', 'nppp_generation_count_live' ] ],
                [ 'wp-filesystem.php', [ 'nppp_head_fast', 'nppp_read_head' ] ], [ 'status.php', [ 'nppp_get_in_cache_page_count' ] ] ] as [ $file, $fns ] ) {
        foreach ( $fns as $fn ) {
            if ( function_exists( $fn ) ) { continue; }
            preg_match( '/^function ' . $fn . '\(.*?^}\n/ms', (string) file_get_contents( npp_includes_dir() . '/' . $file ), $fm ) or ( print( "FAIL missing $fn\n" ) and exit( 2 ) );
            eval( $fm[0] );
        }
    }
    $rgbin = trim( (string) shell_exec( 'command -v rg 2>/dev/null' ) ); $path0 = getenv( 'PATH' );
    $real  = function ( bool $with_status ) use ( $rgbin, $path0 ) {   // Status count and Advanced list size, ripgrep and PHP paths
        global $T;
        if ( $with_status ) { $T['options']['nginx_cache_settings']['nppp_http_purge_queue_status_path'] = 'purge_status'; }
        else { unset( $T['options']['nginx_cache_settings']['nppp_http_purge_queue_status_path'] ); }
        $o = [];
        foreach ( [ 'rg', 'php' ] as $m ) {
            if ( $m === 'rg' && $rgbin === '' ) { continue; }
            $T['tr']['nppp_rg_ok'] = [ 'path' => $m === 'rg' ? $rgbin : '', 'ok' => $m === 'rg' ];
            $o[ "status-$m" ] = nppp_get_in_cache_page_count();
            putenv( 'PATH=' . ( $m === 'rg' ? $path0 : '/nonexistent' ) );
            $r = nppp_extract_cached_urls( new FakeFs(), '/tmp/npp-cache' );
            putenv( 'PATH=' . $path0 );
            $o[ "adv-$m" ] = isset( $r['error'] ) ? 0 : count( $r );
        }
        return $o;
    };
    $all = function ( array $o, int $n ) { return count( array_filter( $o, fn( $v ) => $v !== $n ) ) === 0 && count( $o ) >= 2; };
    $ns  = function () { $j = json_decode( (string) shell_exec( 'curl -s http://127.0.0.1:8081/purge_status' ), true ); return $j['namespaces'][0] ?? null; };
    $T['options']['nginx_cache_settings']['nppp_http_purge_queue_status_path'] = 'purge_status';

    $v = nppp_generation_views();
    chk( isset( $v['objects'] ) && preg_match( '/^\d{10}$/', $v['objects']['epoch'] ) && preg_match( '/^[0-9a-f]{16}$/', $v['objects']['baseline'] ) && $v['objects']['serial'] >= $v['objects']['baseline'],
         'the real status endpoint lists the namespace and the plugin parses it (epoch, baseline, serial)', json_encode( $v ) );
    $n = $ns();
    chk( $n !== null && $n['rules'] === true, 'a single-page purge in this epoch leaves rules=true (files at/above baseline are then unknown to the plugin)', json_encode( $n ) );
    $a = $real( true ); $b = $real( false );
    chk( $all( $a, 3 ) && $all( $b, 3 ), 'idle cache, 3 live pages: Status and Advanced say 3 with and without the namespace state', json_encode( $a ) . json_encode( $b ) );

    // Purge All with the cleanup still pending: every old file is stale by the baseline, although most are the only file of their key
    warm( 'q', 20 ); $idle();
    $r = nppp_http_purge_all();
    chk( $r === true, 'purge_all: invalidated -> true (second round)', notes() );
    foreach ( [ 1, 2 ] as $i ) { xcache( "/p/$i/" ); }
    $raw = cnt(); $a = $real( true ); $b = $real( false ); $n = $ns();
    chk( $n !== null && $n['rules'] === false && $n['baseline'] === $n['serial'], 'purge all: rules=false and baseline == serial (the module resets the rules)', json_encode( $n ) );
    chk( $all( $a, 2 ), 'Purge All, cleanup pending: Status and Advanced (ripgrep and PHP) show only the 2 re-warmed pages', "raw=$raw " . json_encode( $a ) );
    chk( $raw < 8 || min( $b ) > 2, 'without the namespace state the not-yet-deleted stale pages are still counted (what this fixes)', "raw=$raw " . json_encode( $b ) );
    chk( $idle(), 'the cleanup queue goes idle (second round)' );
    $a = $real( true ); $b = $real( false );
    chk( $all( $a, 2 ) && $all( $b, 2 ), 'queue idle: both views agree on 2 pages', json_encode( $a ) . json_encode( $b ) );

    // Full restart: a new epoch, files of the old epoch stay on disk and are stale
    $ng  = getenv( 'NG' ) ?: ( getenv( 'NGROOT' ) ?: __DIR__ . '/ng' ) . '/nginx-run';
    $pid = trim( (string) @file_get_contents( "$ng/logs/nginx.pid" ) );
    chk( $pid !== '' && is_file( "$ng/conf/nginx.conf" ), 'restart prerequisites (pid file and conf found)', "ng=$ng" );
    $e_old = ( $ns() )['epoch'] ?? '';
    shell_exec( 'kill ' . (int) $pid ); for ( $i = 0; $i < 50 && is_dir( '/proc/' . (int) $pid ); $i++ ) { usleep( 100000 ); }
    shell_exec( escapeshellarg( "$ng/sbin/nginx" ) . ' -c ' . escapeshellarg( "$ng/conf/nginx.conf" ) . ' 2>&1' ); sleep( 1 );
    xcache( '/p/1/' );
    $e_new = ( $ns() )['epoch'] ?? '';
    $raw = cnt(); $a = $real( true ); $b = $real( false );
    chk( $e_old !== '' && $e_new !== '' && $e_old !== $e_new, 'full restart starts a new epoch', "$e_old -> $e_new" );
    chk( $all( $a, 1 ), 'after a full restart only the new-epoch page is counted (old epoch = stale)', "raw=$raw " . json_encode( $a ) );
    chk( $raw < 3 || min( $b ) > 1, 'without the namespace state the old-epoch files are counted too (what this fixes)', "raw=$raw " . json_encode( $b ) );
}

/* Generation-mode INDEX on real nginx (mode ns_slow): the "silent empty purge".
 * In generation mode every invalidation gives a URL a NEW cache file name, so the append-only URL->filepath index collects
 * dead paths. Unpatched: FP1 counted them as "multiple variants" and skipped the HTTP purge, and FP2 took "all indexed paths
 * gone" as a confirmed miss ("not currently found in the cache (INDEX)") while the page's live, unindexed file stayed on
 * disk and kept being served (X-Cache: HIT). Mock-level twin of this scenario: gen-index.php.
 *
 * Chain: cache a page, index it (the plugin's scan -> nppp_index_merge_hits), purge it, re-cache, let the nginx sweep run,
 * repeat; then purge it through the real FP1 -> FP2 -> FP4 layers (nppp_purge_single() minus locks and ripgrep):
 *   A) HTTP reachable   -> must be answered by FP1 (HTTP) and the page is MISS afterwards, never an INDEX "miss".
 *   B) HTTP unreachable -> FP2 steps aside, FP4 finds the live file by its KEY line and deletes it; the page is MISS.
 * Unlike the other scenarios this one is meant to FAIL on an unpatched plugin (that is how it was validated). */
if ( $sc === 'ns_index' ) {
    $inc  = npp_includes_dir();
    $load = function ( string $file, string $fn, bool $optional = false ) use ( $inc ): bool {
        if ( function_exists( $fn ) ) { return true; }
        if ( ! preg_match( '/^function ' . $fn . '\(.*?^}\n/ms', (string) file_get_contents( "$inc/$file" ), $fm ) ) {
            if ( $optional ) { return false; }
            echo "FAIL cannot find $fn in $file\n"; exit( 2 );
        }
        eval( $fm[0] );
        return true;
    };
    foreach ( [ 'nppp_key_generation_info', 'nppp_drop_stale_generations', 'nppp_generation_stale_groups', 'nppp_generation_views' ] as $fn ) {
        $load( 'advanced.php', $fn, $fn === 'nppp_generation_views' || $fn === 'nppp_generation_stale_groups' );
    }
    // Added by the Gen Index patch; absent on an unpatched plugin, which is reported once and then every behaviour check runs anyway.
    $have = [];
    foreach ( [ 'nppp_gen_mode_note_seen', 'nppp_gen_mode_seen', 'nppp_gen_mode_active', 'nppp_index_merge_hits' ] as $fn ) { $have[ $fn ] = $load( 'advanced.php', $fn, true ); }
    $have['nppp_purge_live_index_paths'] = $load( 'purge.php', 'nppp_purge_live_index_paths', true );
    foreach ( [ 'nppp_purge_fp1_index_file_exists', 'nppp_purge_fp1_http', 'nppp_purge_fp2_index', 'nppp_purge_fp4_scan', 'nppp_purge_flush_write_back', 'nppp_purge_post_purge' ] as $fn ) { $load( 'purge.php', $fn ); }
    foreach ( [ 'nppp_head_fast', 'nppp_read_head' ] as $fn ) { $load( 'wp-filesystem.php', $fn ); }
    if ( ! function_exists( 'url_to_postid' ) ) { function url_to_postid( $u ) { return 0; } }
    if ( ! function_exists( '_n' ) ) { function _n( $a, $b, $n, $d = '' ) { return $n === 1 ? $a : $b; } }
    if ( ! class_exists( 'NsIdxFs' ) ) {
        class NsIdxFs {
            function exists( $p ) { return file_exists( $p ); }
            function is_dir( $p ) { return is_dir( $p ); }
            function get_contents( $p ) { return @file_get_contents( $p ); }
            function delete( $p, $r = false ) { return @unlink( $p ); }
        }
    }
    $rx   = ( include $inc . '/preload-defaults.php' )['cache_key_regex'];
    $fs   = new NsIdxFs();
    $K1   = 'example.com/p/1/'; $K2 = 'example.com/p/2/';
    $miss = [ 'nppp_gen_mode_active', 'nppp_index_merge_hits', 'nppp_purge_live_index_paths' ];
    $miss = array_values( array_filter( $miss, fn( $f ) => empty( $have[ $f ] ) ) );
    chk( ! $miss, 'patch applied: the Gen Index helpers exist', '(missing: ' . implode( ', ', $miss ) . ' - expected to FAIL on an unpatched plugin)' );

    $idle = function () {
        $n = 0;
        for ( $i = 0; $i < 200; $i++ ) {
            $j = json_decode( (string) shell_exec( 'curl -s http://127.0.0.1:8081/purge_status' ), true ); $c = $j['cache'] ?? null;
            $n = ( $c && $c['pending']['total'] === 0 && $c['active'] === 0 ) ? $n + 1 : 0;
            if ( $n >= 2 ) { return true; }
            usleep( 200000 );
        }
        return false;
    };
    /** Files on disk whose KEY line is the logical key $path (e.g. /p/1/): every generation of it, stale or live. */
    $files = function ( string $path ) {
        $o = [];
        foreach ( explode( "\n", trim( (string) shell_exec( "grep -a -r -m1 '^KEY:' /tmp/npp-cache" ) ) ) as $raw ) {
            $sep = strpos( $raw, ':' );
            if ( $raw !== '' && $sep !== false && preg_match( '#^KEY: p4:objects:\d{10}:[0-9a-f]{16}:httpGETexample\.com' . preg_quote( $path, '#' ) . '$#', substr( $raw, $sep + 1 ) ) ) { $o[] = substr( $raw, 0, $sep ); }
        }
        return $o;
    };
    /** The plugin's index update: scan the cache directory, drop superseded generations, merge into the URL->filepath index. */
    $index = [];
    $build = function () use ( &$index, $rx, $fs ) {
        $urls = [];
        foreach ( explode( "\n", trim( (string) shell_exec( "grep -a -r -m1 '^KEY:' /tmp/npp-cache" ) ) ) as $raw ) {
            $sep = strpos( $raw, ':' ); if ( $raw === '' || $sep === false ) { continue; }
            $kl = substr( $raw, $sep + 1 );
            if ( ! preg_match( $rx, $kl, $m ) || ! isset( $m[1], $m[2] ) ) { continue; }
            $gi = nppp_key_generation_info( $kl );
            $urls[] = [ 'file_path' => substr( $raw, 0, $sep ), 'url_encoded' => 'http://' . trim( $m[1] ) . trim( $m[2] ), 'gen_group' => $gi['group'] ?? null, 'gen' => $gi['gen'] ?? null ];
        }
        nppp_drop_stale_generations( $urls );
        if ( function_exists( 'nppp_index_merge_hits' ) ) { return nppp_index_merge_hits( $index, $urls, $fs ); }
        foreach ( $urls as $e ) {   // unpatched: the original append-only merge
            $k = preg_replace( '#^https?://#', '', $e['url_encoded'] );
            if ( ! in_array( $e['file_path'], $index[ $k ] ?? [], true ) ) { $index[ $k ][] = $e['file_path']; }
        }
        return 0;
    };
    /** Purge context as nppp_purge_single_init() builds it for $K1 (primary). */
    $mkctx = function () use ( &$index, $rx, $fs, $K1 ) {
        global $T;
        $t = [ $K1 => [ 'original' => 'http://example.com/p/1/', 'decoded' => 'http://example.com/p/1/', 'is_primary' => true ] ];
        return [
            'wp_filesystem' => $fs, 'nppp_index' => $index, 'nginx_cache_path' => '/tmp/npp-cache',
            'primary_url' => 'http://example.com/p/1/', 'primary_decoded' => 'http://example.com/p/1/',
            'chain_autopreload' => false, 'nppp_auto_purge' => false, 'regex' => $rx, 'head_bytes' => 4096, 'head_bytes_fb' => 32768,
            'settings' => $T['options']['nginx_cache_settings'], 'targets' => $t, 'pending' => $t, 'write_back' => [], 'primary_purged' => false,
        ];
    };
    $chain = function ( array &$ctx ): string {   // nppp_purge_single() minus locks and ripgrep: stop at the first layer that resolves everything
        if ( nppp_purge_fp1_http( $ctx ) ) { return 'FP1'; }
        if ( nppp_purge_fp2_index( $ctx ) ) { return 'FP2'; }
        nppp_purge_fp4_scan( $ctx );
        return 'FP4';
    };

    // Three rounds of: index the live file, purge it over HTTP (a new generation), re-cache, let nginx sweep the old file.
    warm( 'p', 5 ); sleep( 1 );
    $cycle_ok = true;
    for ( $round = 1; $round <= 3; $round++ ) {
        $build();
        $r  = nppp_http_purge_try_first( 'http://example.com/p/1/' );
        xcache( '/p/1/' );
        $cycle_ok = $cycle_ok && $r === true && $idle();
    }
    chk( $cycle_ok, 'setup: 3 x (index, purge over HTTP, re-cache, sweep) finish; HTTP answered invalidated each time', notes() );
    $live = $files( '/p/1/' ); $idx1 = $index[ $K1 ] ?? [];
    $alive = array_values( array_filter( $idx1, 'file_exists' ) );
    chk( count( $live ) === 1 && ! array_intersect( $live, $idx1 ), 'precondition: the page has ONE live file and it is NOT in the index (every indexed path is a swept generation)', 'live=' . json_encode( $live ) . ' index=' . json_encode( $idx1 ) );
    chk( ! $alive, 'precondition: no indexed path exists on disk any more (the nginx sweep removed them)', json_encode( $alive ) );
    chk( count( $idx1 ) <= 1, 'the index does not collect one dead path per invalidation (merge prunes dead generation paths)', 'paths=' . count( $idx1 ) . ' ' . json_encode( $idx1 ) );
    chk( xcache( '/p/1/' ) === 'HIT' && xcache( '/p/2/' ) === 'HIT', 'precondition: both pages are served from the cache (HIT)' );
    // A site upgraded from the append-only index: the stored index already holds several dead paths of this page (the merge above only prunes what it touches).
    $index[ $K1 ] = array_merge( [ '/tmp/npp-cache/0/00/' . str_repeat( 'a', 32 ), '/tmp/npp-cache/0/01/' . str_repeat( 'b', 32 ) ], $idx1 );
    chk( count( array_filter( $index[ $K1 ], 'file_exists' ) ) === 0 && count( $index[ $K1 ] ) >= 3, 'precondition: the stored index holds 3+ paths of this page and none exists any more', json_encode( $index[ $K1 ] ) );

    // A) HTTP reachable. Unpatched: FP1 counts the dead paths as variants (bypass), FP2 reports the page "not in cache (INDEX)", the page stays HIT.
    $T['notices'] = []; $q0 = count( $T['reqs'] );
    $ctx = $mkctx(); $by = $chain( $ctx );
    $after = xcache( '/p/1/' ); $other = xcache( '/p/2/' );
    chk( $after === 'MISS', 'A: purge with only dead paths in the index -> the page is MISS afterwards (the silent empty purge is gone)', "x-cache=$after by=$by " . notes() );
    chk( strpos( notes(), 'not currently found in the cache (INDEX)' ) === false, 'A: no false "not currently found in the cache (INDEX)" claim', notes() );
    chk( $by === 'FP1' && count( $T['reqs'] ) - $q0 === 1 && $ctx['pending'] === [], 'A: FP1 (HTTP) answers it with exactly one request; dead index paths do not divert it', "by=$by reqs=" . ( count( $T['reqs'] ) - $q0 ) );
    chk( $other === 'HIT' && file_exists( $files( '/p/2/' )[0] ?? '/nonexistent' ), 'A: an unrelated page is untouched', "x-cache=$other" );

    // B) HTTP cannot finish (connection refused). The filesystem layers must remove the live, unindexed file.
    $idle(); xcache( '/p/1/' );
    $live = $files( '/p/1/' );
    chk( count( $live ) === 1 && ! array_intersect( $live, $index[ $K1 ] ?? [] ) && xcache( '/p/1/' ) === 'HIT', 'B precondition: the page is cached again, its live file is not in the index', json_encode( $live ) );
    $T['options']['nginx_cache_settings']['nppp_http_purge_custom_url'] = 'http://127.0.0.1:9';   // nothing listens here
    $T['notices'] = []; $q0 = count( $T['reqs'] );
    $ctx = $mkctx(); $by = $chain( $ctx );
    $gone = ! file_exists( $live[0] ?? '/nonexistent' );
    chk( $by === 'FP4' && $ctx['pending'] === [], 'B: HTTP unreachable -> FP2 does not close the target on dead index paths, FP4 (KEY scan) resolves it', "by=$by pending=" . json_encode( array_keys( $ctx['pending'] ) ) . ' ' . notes() );
    chk( $gone, 'B: the live, unindexed generation file is DELETED (found by its KEY line)', json_encode( $live ) );
    chk( strpos( notes(), '(SCAN)' ) !== false && strpos( notes(), 'not currently found in the cache (INDEX)' ) === false, 'B: success is reported by the scan layer, never as an INDEX miss', notes() );
    chk( strpos( notes(), 'cannot remove its cache entry' ) === false && strpos( notes(), 'until a Purge All' ) === false, 'B: no false "cannot purge / run a Purge All" error', notes() );
    chk( xcache( '/p/1/' ) === 'MISS', 'B: the page is MISS right after the filesystem purge' );
    chk( xcache( '/p/2/' ) === 'HIT', 'B: an unrelated page is untouched' );
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
