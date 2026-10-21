<?php
/**
 * Generation-mode scan tests: namespace state from `cache_purge_queue_status` (ngx_cache_purge v4)
 * applied by the Status counter and the Advanced list.
 *
 * Usage: php gen-scan.php path/to/includes/purge-http.php
 *
 * Runs the REAL scan functions (loaded by extraction, because status.php / advanced.php need WordPress) against
 * fake cache directories, through all four scan paths: Status (ripgrep, PHP) and Advanced (ripgrep, PHP).
 * Status replies are scripted, so every branch is deterministic. Real nginx is covered by e2e.php (scenario ns_keys).
 * Needs `rg` >= 14 on PATH (the ripgrep paths are skipped with a note when it is missing).
 */
error_reporting( E_ALL );
define( 'ABSPATH', '/tmp/' );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'DAY_IN_SECONDS', 86400 );

class WP_Error {
    public $m;
    public function __construct( $c = '', $m = '' ) { $this->m = $m; }
    public function get_error_messages() { return [ $this->m ]; }
}

$GLOBALS['T'] = [];
function t_reset( array $settings = [] ) {
    $GLOBALS['T'] = [
        'options'    => [ 'nginx_cache_settings' => array_merge( [ 'nppp_http_purge_enabled' => 'yes' ], $settings ) ],
        'transients' => [], 'queue' => [], 'requests' => [], 'notices' => [], 'hooks' => [],
    ];
}
function get_option( $k, $d = false ) { return $GLOBALS['T']['options'][ $k ] ?? $d; }
function update_option( $k, $v, $autoload = null ) { $GLOBALS['T']['options'][ $k ] = $v; return true; }
function delete_option( $k ) { unset( $GLOBALS['T']['options'][ $k ] ); return true; }
function home_url() { return 'https://example.com'; }
function get_site_url() { return 'https://example.com'; }
function wp_parse_url( $u, $c = -1 ) { return parse_url( $u, $c ); }
function absint( $n ) { return abs( (int) $n ); }
function esc_url( $u ) { return $u; }
function esc_url_raw( $u ) { return $u; }
function __( $s, $d = '' ) { return $s; }
function apply_filters( $tag, $v, ...$a ) { return $v; }
function do_action( $tag, ...$a ) {}
function add_action( $tag, $cb, $p = 10, $n = 1 ) {}
function remove_action( $tag, $cb, $p = 10 ) {}
function get_transient( $k ) { return $GLOBALS['T']['transients'][ $k ] ?? false; }
function set_transient( $k, $v, $t ) { $GLOBALS['T']['transients'][ $k ] = $v; return true; }
function delete_transient( $k ) { unset( $GLOBALS['T']['transients'][ $k ] ); return true; }
function is_wp_error( $x ) { return $x instanceof WP_Error; }
function wp_cache_delete( $k, $g = '' ) { return true; }
function nppp_display_admin_notice( $type, $msg, $log = true, $show = true ) { $GLOBALS['T']['notices'][] = [ $type, $msg ]; }
function wp_remote_request( $url, $args ) {
    $GLOBALS['T']['requests'][] = [ 'url' => $url, 'args' => $args ];
    $next = array_shift( $GLOBALS['T']['queue'] );
    return $next ?? new WP_Error( 'http_request_failed', 'cURL error 7: queue empty (test bug)' );
}
function wp_remote_retrieve_response_code( $r ) { return $r['c']; }
function wp_remote_retrieve_body( $r ) { return $r['b'] ?? ''; }
function wp_remote_retrieve_header( $r, $name ) { return $r['h'][ strtolower( $name ) ] ?? ''; }

// WordPress / plugin environment the scan functions call
function wp_is_using_https() { return false; }
function nppp_prepare_request_env() {}
function nppp_categorize_url( $u ) { return 'POST'; }   // WordPress rewrite rules are out of scope here
function nppp_check_perm_in_cache( $a = false, $b = false, $c = false ) { return 'true'; }
function nppp_check_path() { return 'Found'; }
function nppp_check_rg_version() { return '14.1.0'; }
function nppp_fuse_source_path( $p ) { return null; }
class FakeFs {
    public function is_dir( $p ) { return is_dir( $p ); }
    public function get_contents( $p ) { return @file_get_contents( $p ); }
}
function nppp_initialize_wp_filesystem() { return new FakeFs(); }

$PASS = 0; $FAIL = 0;
function ok( $cond, $name, $detail = '' ) {
    global $PASS, $FAIL;
    if ( $cond ) { $PASS++; } else { $FAIL++; echo "FAIL: $name $detail\n"; }
}
function is_( $got, $want, $name ) { ok( $got === $want, $name, '-- got ' . json_encode( $got ) . ' want ' . json_encode( $want ) ); }

$PLUGIN = realpath( $argv[1] ?? '' ) or exit( "usage: php gen-scan.php path/to/includes/purge-http.php\n" );
$INC    = dirname( $PLUGIN );
require $PLUGIN;
require "$INC/state.php";

function load_fn( string $file, string $fn, bool $optional = false ): void {
    if ( function_exists( $fn ) ) { return; }
    if ( ! preg_match( '/^function ' . $fn . '\(.*?^}\n/ms', (string) file_get_contents( $file ), $m ) ) {
        if ( $optional ) { return; }
        fwrite( STDERR, "FAIL cannot find $fn in $file\n" ); exit( 2 );
    }
    eval( $m[0] );
}
foreach ( [ 'nppp_key_generation_info', 'nppp_drop_stale_generations', 'nppp_annotate_variant_counts', 'nppp_extract_cached_urls',
            'nppp_extract_cached_urls_rg' ] as $fn ) { load_fn( "$INC/advanced.php", $fn ); }
// Helpers that nppp_drop_stale_generations() calls: absent on the unpatched plugin, in which case only the legacy behaviour is checked.
// nppp_gen_mode_note_seen() comes from the Gen Index patch and is needed once the two patch sets are merged.
foreach ( [ 'nppp_gen_mode_note_seen', 'nppp_generation_views', 'nppp_generation_stale_groups', 'nppp_generation_count_live' ] as $fn ) { load_fn( "$INC/advanced.php", $fn, true ); }
load_fn( "$INC/advanced.php", 'nppp_gen_scan_unverified', true );   // added by 57e57608, called by nppp_drop_stale_generations()
$HAS_NS = function_exists( 'nppp_generation_views' );
foreach ( [ 'nppp_head_fast', 'nppp_read_head' ] as $fn ) { load_fn( "$INC/wp-filesystem.php", $fn ); }
load_fn( "$INC/status.php", 'nppp_get_in_cache_page_count' );

$RX  = ( include "$INC/preload-defaults.php" )['cache_key_regex'];
function nppp_get_cache_key_regex(): string { global $RX; return $RX; }
$RG  = trim( (string) shell_exec( 'command -v rg 2>/dev/null' ) );
$PATH0 = getenv( 'PATH' );

/* ------------------------------------------------------------------ fixtures */
$G = function ( int $n ) { return sprintf( '%016x', $n ); };
$E1 = '1760000001'; $E0 = '1750000000';
$SU = 'https://example.com/purge_status';

function rmrf( string $d ): void { exec( 'rm -rf ' . escapeshellarg( $d ) ); }
/** @param array<int,array{0:string,1:string,2:int,3:string}> $files [ns, epoch, generation, page path] */
function mkcache( array $files, array $legacy = [] ): string {
    $d = sys_get_temp_dir() . '/nppp-gen-' . getmypid() . '-' . mt_rand();
    mkdir( $d . '/a/b', 0777, true );
    $i = 0;
    foreach ( $files as [ $ns, $ep, $gen, $path ] ) {
        $key = sprintf( 'p4:%s:%s:%016x:httpGETexample.com%s', $ns, $ep, $gen, $path );
        file_put_contents( $d . '/a/b/' . md5( $i++ . $key ), "\x05\x00\x01hdr\nKEY: $key\n\r\nHTTP/1.1 200 OK\r\nContent-Type: text/html\r\n\r\n<html>x</html>" );
    }
    foreach ( $legacy as $path ) {
        file_put_contents( $d . '/a/b/' . md5( 'L' . $i++ . $path ), "\x05\x00\x01hdr\nKEY: httpGETexample.com$path\n\r\nHTTP/1.1 200 OK\r\n\r\n<html>x</html>" );
    }
    return $d;
}
function NSV( string $name, string $epoch, string $baseline, string $serial, bool $rules = false ) {
    return [ 'name' => $name, 'epoch' => $epoch, 'baseline' => $baseline, 'serial' => $serial, 'rules' => $rules ];
}
/** A status reply as ngx_cache_purge v4 sends it (code in the Cache-Purge-Code header AND the JSON body). */
function ST( array $ns, bool $complete = true ) {
    return [ 'c' => 200, 'h' => [ 'cache-purge-code' => 'status' ], 'b' => json_encode( [
        'code' => 'status', 'queue' => [ 'size' => 0 ], 'cache' => [ 'pending' => [ 'total' => 0, 'full' => 0 ], 'active' => 0 ],
        'namespaces' => $ns, 'namespaces_complete' => $complete ] ) ];
}
/** A v4 module that predates the namespace list. */
function ST_OLD() {
    return [ 'c' => 200, 'h' => [ 'cache-purge-code' => 'status' ], 'b' => json_encode( [
        'code' => 'status', 'queue' => [ 'size' => 0 ], 'cache' => [ 'pending' => [ 'total' => 0, 'full' => 0 ], 'active' => 0 ] ] ) ];
}
function RAW( int $status, string $code, string $body ) { return [ 'c' => $status, 'h' => $code !== '' ? [ 'cache-purge-code' => $code ] : [], 'b' => $body ]; }
function Q( ...$r ) { foreach ( $r as $x ) { $GLOBALS['T']['queue'][] = $x; } }
function nreq() { return count( $GLOBALS['T']['requests'] ); }

/** The four scan paths. Each returns [ Status count, sorted Advanced page paths ] for the cache dir $d. */
function scan( string $path, string $d, callable $replies ): array {
    global $RG, $PATH0;
    $S = get_option( 'nginx_cache_settings' ); $S['nginx_cache_path'] = $d; $GLOBALS['T']['options']['nginx_cache_settings'] = $S;
    $GLOBALS['T']['queue'] = []; $GLOBALS['T']['requests'] = [];
    foreach ( $replies() as $r ) { Q( $r ); }
    if ( $path === 'status-rg' || $path === 'status-php' ) {
        $GLOBALS['T']['transients']['nppp_rg_ok'] = [ 'path' => $path === 'status-rg' ? $RG : '', 'ok' => $path === 'status-rg' ];
        $v = nppp_get_in_cache_page_count();
        return [ $v, null ];
    }
    putenv( 'PATH=' . ( $path === 'adv-rg' ? $PATH0 : '/nonexistent' ) );
    $wp = new FakeFs();
    $r  = nppp_extract_cached_urls( $wp, $d );
    putenv( 'PATH=' . $PATH0 );
    if ( isset( $r['error'] ) ) { return [ null, $r['error'] === 'NPPP_EMPTY_CACHE' ? [] : [ 'ERR:' . substr( $r['error'], 0, 40 ) ] ]; }
    $p = array_map( fn( $u ) => parse_url( $u['url_encoded'], PHP_URL_PATH ), $r ); sort( $p );
    return [ null, $p ];
}

$PATHS = $RG !== '' ? [ 'status-rg', 'status-php', 'adv-rg', 'adv-php' ] : [ 'status-php', 'adv-php' ];
if ( $RG === '' ) { echo "NOTE: rg not found, ripgrep paths skipped\n"; }

/**
 * One scenario through every path.
 * @param array $settings plugin settings; @param array $files see mkcache(); @param callable $replies list of scripted replies
 * @param int $count expected Status count; @param array $pages expected Advanced page paths (sorted); @param int $requests expected status requests per scan
 */
function scenario( string $name, array $settings, array $files, callable $replies, int $count, array $pages, int $requests, array $legacy = [] ): void {
    global $PATHS, $HAS_NS;
    $d = mkcache( $files, $legacy );
    foreach ( $PATHS as $p ) {
        t_reset( $settings );
        [ $c, $u ] = scan( $p, $d, $replies );
        if ( strpos( $p, 'status' ) === 0 ) { is_( $c, $count, "$name [$p] Status count" ); }
        else { is_( $u, $pages, "$name [$p] Advanced pages" ); }
        if ( $HAS_NS ) { is_( nreq(), $requests, "$name [$p] status requests" ); }
    }
    rmrf( $d );
}

$ST_ON  = [ 'nppp_http_purge_queue_status_path' => 'purge_status', 'nppp_http_purge_custom_url' => '' ];
$ST_OFF = [];

/* ------------------------------------------------------------------ 1. nothing changes without the status endpoint (legacy behaviour kept) */
$files = [ [ 'objects', $E1, 1, '/p/1/' ], [ 'objects', $E1, 2, '/p/1/' ], [ 'objects', $E1, 2, '/p/2/' ], [ 'objects', $E1, 1, '/p/3/' ] ];
scenario( 'no status path: same-key old/new pair collapses, rest untouched', $ST_OFF, $files, fn() => [], 3, [ '/p/1/', '/p/2/', '/p/3/' ], 0 );
scenario( 'HTTP Purge disabled: status path ignored, no request', [ 'nppp_http_purge_enabled' => 'no' ] + $ST_ON, $files, fn() => [], 3, [ '/p/1/', '/p/2/', '/p/3/' ], 0 );
scenario( 'legacy (non-generation) keys: no request, all counted', $ST_ON, [], fn() => [], 3, [ '/a', '/b', '/c' ], 0, [ '/a', '/b', '/c' ] );
if ( ! $HAS_NS ) {
    echo "\n$PASS passed, $FAIL failed (unpatched plugin: namespace-state tests skipped)\n";
    exit( $FAIL ? 1 : 0 );
}

/* ------------------------------------------------------------------ 2. helper units */
t_reset( $ST_ON );
Q( ST( [ NSV( 'objects', $E1, $G( 2 ), $G( 3 ), true ), NSV( 'pages', $E0, $G( 1 ), $G( 1 ) ) ] ) );
is_( nppp_generation_views(), [ 'objects' => [ 'epoch' => $E1, 'baseline' => $G( 2 ), 'serial' => $G( 3 ) ],
                                'pages'   => [ 'epoch' => $E0, 'baseline' => $G( 1 ), 'serial' => $G( 1 ) ] ], 'views: one entry per namespace (rules flag not needed)' );
is_( $GLOBALS['T']['requests'][0]['url'], 'https://example.com/purge_status', 'views: asks the Purge Queue Status URL' );
is_( $GLOBALS['T']['requests'][0]['args']['timeout'], 2, 'views: 2 s budget' );
is_( $GLOBALS['T']['requests'][0]['args']['method'], 'GET', 'views: GET' );

t_reset( $ST_ON ); Q( ST( [] ) );
is_( nppp_generation_views(), [], 'views: module without namespaces -> empty map (not null)' );
t_reset( $ST_ON ); Q( ST_OLD() );
is_( nppp_generation_views(), null, 'views: older module without the list -> null' );
t_reset( $ST_ON ); Q( RAW( 200, 'status', '{"code":"status","namespaces":"nope"}' ) );
is_( nppp_generation_views(), null, 'views: namespaces not a list -> null' );
t_reset( $ST_ON ); Q( RAW( 200, 'status', '{"code":"status","namespaces":[{"name":"objects"' ) );
is_( nppp_generation_views(), null, 'views: truncated JSON -> null' );
t_reset( $ST_ON ); Q( RAW( 403, 'access_denied', '{"code":"access_denied"}' ) );
is_( nppp_generation_views(), null, 'views: 403 -> null' );
t_reset( $ST_ON ); Q( RAW( 200, 'purged', '{"code":"purged"}' ) );
is_( nppp_generation_views(), null, 'views: a non-status code (wrong location) -> null' );
t_reset( $ST_ON ); Q( new WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out after 2000 milliseconds' ) );
is_( nppp_generation_views(), null, 'views: timeout -> null' );
t_reset( $ST_ON ); Q( new WP_Error( 'http_request_failed', 'cURL error 7: Failed to connect' ) );
is_( nppp_generation_views(), null, 'views: unreachable -> null' );
t_reset( $ST_OFF ); Q( ST( [ NSV( 'objects', $E1, $G( 2 ), $G( 2 ) ) ] ) );
is_( nppp_generation_views(), null, 'views: no status path -> null' ); is_( nreq(), 0, 'views: no status path -> no request' );
t_reset( [ 'nppp_http_purge_enabled' => 'no' ] + $ST_ON ); Q( ST( [ NSV( 'objects', $E1, $G( 2 ), $G( 2 ) ) ] ) );
is_( nppp_generation_views(), null, 'views: HTTP Purge off -> null' ); is_( nreq(), 0, 'views: HTTP Purge off -> no request' );

t_reset( $ST_ON );
Q( ST( [ NSV( 'objects', $E1, $G( 2 ), $G( 2 ) ), NSV( 'bad epoch', $E1, $G( 2 ), $G( 2 ) ), NSV( 'e', '123', $G( 2 ), $G( 2 ) ), NSV( 'f', $E1, '2', $G( 2 ) ),
          NSV( 'g', $E1, strtoupper( $G( 10 ) ), $G( 2 ) ), NSV( 'h', $E1, $G( 2 ), 'zz' ), NSV( str_repeat( 'n', 33 ), $E1, $G( 2 ), $G( 2 ) ),
          NSV( 'dup', $E1, $G( 1 ), $G( 1 ) ), NSV( 'dup', $E1, $G( 2 ), $G( 2 ) ), [ 'name' => 'nobase', 'epoch' => $E1 ], 'junk', [ 'name' => [ 'x' ], 'epoch' => $E1, 'baseline' => $G( 1 ), 'serial' => $G( 1 ) ] ] ) );
is_( array_keys( nppp_generation_views() ), [ 'objects' ], 'views: malformed, uppercase-hex, over-long and duplicated entries are left out (= unknown)' );
t_reset( $ST_ON ); Q( ST( [ NSV( '123', $E1, $G( 2 ), $G( 2 ) ) ] ) );
is_( array_keys( nppp_generation_views() ), [ 123 ], 'views: an all-digit namespace name is accepted (PHP int key, looked up the same way)' );

// stale groups: one namespace, live epoch E1, baseline 2
function stale( array $gg, $before, array $after ) {
    t_reset( [ 'nppp_http_purge_queue_status_path' => 'purge_status' ] ); $GLOBALS['T']['queue'] = [];
    if ( $after !== [ '__none__' ] ) { Q( ST( $after ) ); }
    return array_keys( nppp_generation_stale_groups( $gg, $before ) );
}
function V( string $name, string $e, string $b, string $s ) { return [ $name => [ 'epoch' => $e, 'baseline' => $b, 'serial' => $s ] ]; }
$live = [ NSV( 'objects', $E1, $G( 2 ), $G( 3 ) ) ];
$bf   = V( 'objects', $E1, $G( 2 ), $G( 3 ) );
$gg   = [ "objects|$E1|/a" => $G( 1 ), "objects|$E1|/b" => $G( 2 ), "objects|$E1|/c" => $G( 9 ), "objects|$E0|/d" => $G( 9 ), 'pages|' . $E1 . '|/e' => $G( 1 ) ];
is_( stale( $gg, $bf, $live ), [ "objects|$E1|/a", "objects|$E0|/d" ], 'stale: below baseline and foreign epoch are stale; at baseline, above it and unknown namespace are kept' );
is_( stale( $gg, $bf, [ NSV( 'objects', $E1, $G( 2 ), $G( 4 ) ) ] ), [], 'stale: serial moved during the scan -> namespace verdicts dropped' );
is_( stale( $gg, $bf, [ NSV( 'objects', $E1, $G( 3 ), $G( 3 ) ) ] ), [], 'stale: baseline moved during the scan -> namespace verdicts dropped' );
is_( stale( $gg, $bf, [ NSV( 'objects', $E0, $G( 2 ), $G( 3 ) ) ] ), [], 'stale: epoch changed during the scan -> namespace verdicts dropped' );
is_( stale( $gg, $bf, [] ), [], 'stale: namespace gone from the second reply -> unknown' );
is_( stale( $gg, [], $live ), [], 'stale: no first reading -> no verdicts' ); is_( nreq(), 0, 'stale: no first reading -> no request' );
is_( stale( $gg, $bf, [ '__none__' ] ), [], 'stale: second read fails -> no verdicts' );
is_( stale( [], $bf, $live ), [], 'stale: nothing to judge -> []' ); is_( nreq(), 0, 'stale: nothing to judge -> no request' );
is_( stale( $gg, $bf + V( 'pages', $E1, $G( 1 ), $G( 1 ) ), array_merge( $live, [ NSV( 'pages', $E1, $G( 1 ), $G( 1 ) ) ] ) ), [ "objects|$E1|/a", "objects|$E0|/d" ],
     'stale: another namespace with a lower baseline judges only its own groups' );
is_( stale( [ "objects|$E1|/a|b" => $G( 1 ) ], $bf, $live ), [ "objects|$E1|/a|b" ], 'stale: a logical key containing | is parsed with limit 3' );
is_( stale( $gg, 'x', $live ), [], 'stale: junk "before" value -> no verdicts' );
is_( stale( $gg, false, $live ), [], 'stale: before=false (never read) -> no verdicts' );
$gs = [ "objects|$E1|/a" => [ $G( 1 ), 4 ], "objects|$E1|/b" => [ $G( 2 ), 3 ], "objects|$E0|/c" => [ $G( 7 ), 5 ] ];
t_reset( $ST_ON ); Q( ST( $live ) ); is_( nppp_generation_count_live( $gs, $bf ), 3, 'count_live: only the live group counts' );
t_reset( $ST_ON ); is_( nppp_generation_count_live( $gs, false ), 12, 'count_live: without a first reading every group counts (legacy)' ); is_( nreq(), 0, 'count_live: legacy -> no request' );
t_reset( $ST_ON ); is_( nppp_generation_count_live( [], $bf ), 0, 'count_live: empty -> 0' ); is_( nreq(), 0, 'count_live: empty -> no request' );

/* ------------------------------------------------------------------ 3. real scans, all four paths */
$two = fn( $a, $b = null ) => fn() => [ ST( $a ), ST( $b ?? $a ) ];

// 3a. Purge All happened (baseline 2): old generation files are stale though each is the only file of its key
$files = [ [ 'objects', $E1, 1, '/p/1/' ], [ 'objects', $E1, 1, '/p/2/' ], [ 'objects', $E1, 2, '/p/3/' ], [ 'objects', $E1, 3, '/p/4/' ] ];
scenario( 'purge all: old files are stale by baseline', $ST_ON, $files, $two( [ NSV( 'objects', $E1, $G( 2 ), $G( 3 ) ) ] ), 2, [ '/p/3/', '/p/4/' ], 2 );

// 3b. old + new file of one key while cleanup is pending: pair rule and baseline agree
$files = [ [ 'objects', $E1, 1, '/p/1/' ], [ 'objects', $E1, 2, '/p/1/' ], [ 'objects', $E1, 2, '/p/2/' ] ];
scenario( 'pending cleanup: stale+live pair of one page counted once', $ST_ON, $files, $two( [ NSV( 'objects', $E1, $G( 2 ), $G( 2 ) ) ] ), 2, [ '/p/1/', '/p/2/' ], 2 );

// 3c. full restart: files of the old epoch are stale; the same page in two epochs is no longer counted twice
$files = [ [ 'objects', $E0, 5, '/p/1/' ], [ 'objects', $E0, 5, '/p/2/' ], [ 'objects', $E1, 1, '/p/1/' ] ];
scenario( 'full restart: old-epoch files are stale', $ST_ON, $files, $two( [ NSV( 'objects', $E1, $G( 1 ), $G( 1 ) ) ] ), 1, [ '/p/1/' ], 2 );
scenario( 'full restart without status: both epochs counted (legacy behaviour)', $ST_OFF, $files, fn() => [], 3, [ '/p/1/', '/p/1/', '/p/2/' ], 0 );

// 3d. a single-page purge left rules=true: nothing at or above baseline can be called stale, so it is kept
$files = [ [ 'objects', $E1, 1, '/p/1/' ], [ 'objects', $E1, 3, '/p/2/' ], [ 'objects', $E1, 7, '/p/3/' ] ];
scenario( 'rules=true: files at/above baseline stay (unknown), below baseline go', $ST_ON, $files, $two( [ NSV( 'objects', $E1, $G( 2 ), $G( 8 ), true ) ] ), 2, [ '/p/2/', '/p/3/' ], 2 );

// 3e. state moved while scanning -> namespace verdicts dropped, disk-only rule applies
$files = [ [ 'objects', $E1, 1, '/p/1/' ], [ 'objects', $E1, 2, '/p/2/' ] ];
scenario( 'baseline moved during the scan: fall back to the disk-only rule', $ST_ON, $files,
          $two( [ NSV( 'objects', $E1, $G( 1 ), $G( 2 ) ) ], [ NSV( 'objects', $E1, $G( 2 ), $G( 3 ) ) ] ), 2, [ '/p/1/', '/p/2/' ], 2 );
scenario( 'serial moved during the scan: fall back', $ST_ON, $files, $two( [ NSV( 'objects', $E1, $G( 2 ), $G( 2 ) ) ], [ NSV( 'objects', $E1, $G( 2 ), $G( 3 ) ) ] ), 2, [ '/p/1/', '/p/2/' ], 2 );
scenario( 'epoch moved during the scan: fall back', $ST_ON, $files, $two( [ NSV( 'objects', $E1, $G( 2 ), $G( 2 ) ) ], [ NSV( 'objects', $E0, $G( 2 ), $G( 2 ) ) ] ), 2, [ '/p/1/', '/p/2/' ], 2 );

// 3f. unknown namespace
scenario( 'namespace missing from the list (cut short): kept', $ST_ON, $files, fn() => [ ST( [ NSV( 'other', $E1, $G( 9 ), $G( 9 ) ) ], false ), ST( [ NSV( 'other', $E1, $G( 9 ), $G( 9 ) ) ], false ) ], 2, [ '/p/1/', '/p/2/' ], 2 );
$mixed = [ [ 'objects', $E1, 1, '/p/1/' ], [ 'pages', $E1, 1, '/q/1/' ], [ 'pages', $E1, 3, '/q/2/' ] ];
scenario( 'two namespaces: only the listed one is judged', $ST_ON, $mixed, $two( [ NSV( 'objects', $E1, $G( 5 ), $G( 5 ) ) ] ), 2, [ '/q/1/', '/q/2/' ], 2 );

// 3g. old module / unusable endpoint: one request, then the disk-only rule
scenario( 'older v4 module without namespaces: 1 request, legacy result', $ST_ON, $files, fn() => [ ST_OLD(), ST_OLD() ], 2, [ '/p/1/', '/p/2/' ], 1 );
scenario( 'status endpoint 403: 1 request, legacy result', $ST_ON, $files, fn() => [ RAW( 403, 'access_denied', '{"code":"access_denied"}' ) ], 2, [ '/p/1/', '/p/2/' ], 1 );
scenario( 'status endpoint unreachable: 1 request, legacy result', $ST_ON, $files, fn() => [ new WP_Error( 'http_request_failed', 'cURL error 7: Failed to connect' ) ], 2, [ '/p/1/', '/p/2/' ], 1 );
scenario( 'second read fails: 2 requests, legacy result', $ST_ON, $files, fn() => [ ST( [ NSV( 'objects', $E1, $G( 2 ), $G( 2 ) ) ] ), new WP_Error( 'http_request_failed', 'cURL error 28' ) ], 2, [ '/p/1/', '/p/2/' ], 2 );

// 3h. no generation key in the cache -> the endpoint is never asked
scenario( 'no p4 key anywhere: zero status requests', $ST_ON, [], fn() => [ ST( [ NSV( 'objects', $E1, $G( 9 ), $G( 9 ) ) ] ) ], 2, [ '/a', '/b' ], 0, [ '/a', '/b' ] );
// mixed legacy + generation files: legacy ones always count
scenario( 'legacy and generation files together', $ST_ON, [ [ 'objects', $E1, 1, '/p/1/' ], [ 'objects', $E1, 2, '/p/2/' ] ], $two( [ NSV( 'objects', $E1, $G( 2 ), $G( 2 ) ) ] ), 2, [ '/l/1', '/p/2/' ], 2, [ '/l/1' ] );
// everything stale: Status counts 0; Advanced shows an empty list (never an error)
scenario( 'everything stale', $ST_ON, [ [ 'objects', $E0, 1, '/p/1/' ], [ 'objects', $E1, 1, '/p/2/' ] ], $two( [ NSV( 'objects', $E1, $G( 4 ), $G( 4 ) ) ] ), 0, [], 2 );

/* ------------------------------------------------------------------ 4. randomised: the scan equals a per-file oracle of the documented rules */
mt_srand( 20260718 );
$bad = 0; $cases = 0;
for ( $it = 0; $it < 120; $it++ ) {
    $live = [];   // ns => [epoch, baseline]
    foreach ( [ 'objects', 'pages' ] as $ns ) {
        if ( mt_rand( 0, 5 ) > 0 ) { $b = mt_rand( 1, 4 ); $live[ $ns ] = [ $E1, $b, $b + mt_rand( 0, 3 ), (bool) mt_rand( 0, 1 ) ]; }
    }
    $files = []; $n = mt_rand( 1, 14 );
    for ( $i = 0; $i < $n; $i++ ) { $files[] = [ mt_rand( 0, 1 ) ? 'objects' : 'pages', mt_rand( 0, 3 ) ? $E1 : $E0, mt_rand( 1, 6 ), '/p/' . mt_rand( 1, 4 ) . '/' ]; }
    // oracle
    $max = [];
    foreach ( $files as [ $ns, $ep, $g, $pp ] ) { $k = "$ns|$ep|$pp"; $max[ $k ] = max( $max[ $k ] ?? 0, $g ); }
    $want = 0;
    foreach ( $files as [ $ns, $ep, $g, $pp ] ) {
        if ( $g !== $max[ "$ns|$ep|$pp" ] ) { continue; }
        if ( isset( $live[ $ns ] ) && ( $ep !== $live[ $ns ][0] || $g < $live[ $ns ][1] ) ) { continue; }
        $want++;
    }
    $views = []; foreach ( $live as $ns => [ $e, $b, $s, $r ] ) { $views[] = NSV( $ns, $e, $G( $b ), $G( $s ), $r ); }
    $d = mkcache( $files );
    foreach ( $PATHS as $p ) {
        if ( strpos( $p, 'status' ) !== 0 ) { continue; }
        t_reset( $ST_ON ); [ $c ] = scan( $p, $d, fn() => [ ST( $views ), ST( $views ) ] );
        $cases++; if ( $c !== $want ) { $bad++; if ( $bad < 4 ) { echo "FAIL: random #$it [$p] got " . json_encode( $c ) . " want $want files=" . json_encode( $files ) . ' live=' . json_encode( $live ) . "\n"; } }
    }
    rmrf( $d );
}
ok( $bad === 0, "randomised: $cases Status scans equal the per-file oracle", "bad=$bad" );

echo "\n$PASS passed, $FAIL failed\n";
exit( $FAIL ? 1 : 0 );
