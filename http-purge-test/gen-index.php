<?php
/**
 * Generation-mode INDEX tests: the URL->filepath index, FP1 and FP2 under ngx_cache_purge v4 generation mode.
 *
 * Usage: php gen-index.php path/to/includes/purge-http.php
 *
 * Background. In generation mode every invalidation gives a URL a NEW cache file name, so the append-only index
 * collects dead paths. Unpatched, FP1 counted them as "multiple variants" and skipped the HTTP purge, and FP2 then
 * took "all indexed paths gone" as a confirmed miss: it reported "not currently found in the cache (INDEX)" while
 * the page's live, unindexed file stayed on disk and kept being served.
 *
 * Everything new sits behind one gate, nppp_gen_mode_active() = HTTP Purge enabled AND generation mode observed
 * (a scan met a p4 key, or an HTTP purge answered "invalidated"). With the gate closed every layer must behave
 * exactly as before; the "gate closed" cases below pin that.
 *
 * What is covered (real plugin functions, loaded by extraction because the files need WordPress; real files on disk):
 *   1. the gate and its transient             nppp_gen_mode_active / _seen / _note_seen
 *   2. scan entries                           nppp_drop_stale_generations (the gen_mode flag)
 *   3. the index merge                        nppp_index_merge_hits
 *   4. live-path counting                     nppp_purge_live_index_paths
 *   5. FP1 (HTTP)                             nppp_purge_fp1_http
 *   6. FP2 (index)                            nppp_purge_fp2_index
 *   7. the "HTTP did not finish" notice       nppp_http_purge_try_first
 *   8. the whole chain FP1 -> FP2 -> FP4 on a real cache directory (the silent empty purge)
 *
 * Unlike gen-scan.php this file is NOT skipped on an unpatched plugin: it is the regression test, so against
 * unpatched code it must FAIL (that is how it was validated). FP3 (ripgrep) is not run here; FP4 is its PHP twin.
 * Real nginx is covered by e2e.php (scenario ns_index).
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
        'transients' => [], 'ttl' => [], 'queue' => [], 'requests' => [], 'notices' => [], 'actions' => [],
        'filters'    => [], 'hooks' => [],
    ];
}
function t_filter( $tag, $fn ) { $GLOBALS['T']['filters'][ $tag ] = $fn; }
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
function _n( $a, $b, $n, $d = '' ) { return $n === 1 ? $a : $b; }
function url_to_postid( $u ) { return 0; }
function apply_filters( $tag, $v, ...$a ) {
    $f = $GLOBALS['T']['filters'][ $tag ] ?? null;
    return $f ? $f( $v, ...$a ) : $v;
}
function do_action( $tag, ...$a ) { $GLOBALS['T']['actions'][] = [ $tag, $a ]; }
function add_action( $tag, $cb, $p = 10, $n = 1 ) { $GLOBALS['T']['hooks'][ $tag . '|' . $cb ] = true; }
function remove_action( $tag, $cb, $p = 10 ) { unset( $GLOBALS['T']['hooks'][ $tag . '|' . $cb ] ); }
function get_transient( $k ) { return $GLOBALS['T']['transients'][ $k ] ?? false; }
function set_transient( $k, $v, $t ) { $GLOBALS['T']['transients'][ $k ] = $v; $GLOBALS['T']['ttl'][ $k ] = $t; return true; }
function delete_transient( $k ) { unset( $GLOBALS['T']['transients'][ $k ], $GLOBALS['T']['ttl'][ $k ] ); return true; }
function is_wp_error( $x ) { return $x instanceof WP_Error; }
function wp_cache_delete( $k, $g = '' ) { return true; }
function nppp_display_admin_notice( $type, $msg, $log = true, $show = true ) { $GLOBALS['T']['notices'][] = [ $type, $msg, $log, $show ]; }
function wp_remote_request( $url, $args ) {
    $GLOBALS['T']['requests'][] = [ 'url' => $url, 'args' => $args ];
    $next = array_shift( $GLOBALS['T']['queue'] );
    return $next ?? new WP_Error( 'http_request_failed', 'cURL error 7: queue empty (test bug)' );
}
function wp_remote_retrieve_response_code( $r ) { return $r['c']; }
function wp_remote_retrieve_body( $r ) { return $r['b'] ?? ''; }
function wp_remote_retrieve_header( $r, $name ) { return $r['h'][ strtolower( $name ) ] ?? ''; }
function R( int $status, string $code = '', string $body = '' ) { return [ 'c' => $status, 'b' => $body, 'h' => $code !== '' ? [ 'cache-purge-code' => $code ] : [] ]; }
function E( string $m ) { return new WP_Error( 'http_request_failed', $m ); }
function Q( ...$r ) { foreach ( $r as $x ) { $GLOBALS['T']['queue'][] = $x; } }
function nreq() { return count( $GLOBALS['T']['requests'] ); }
function notice_has( $needle, $type = null ) {
    foreach ( $GLOBALS['T']['notices'] as $n ) {
        if ( strpos( $n[1], $needle ) !== false && ( $type === null || $n[0] === $type ) ) { return true; }
    }
    return false;
}
function notice_count( $needle ) { $c = 0; foreach ( $GLOBALS['T']['notices'] as $n ) { if ( strpos( $n[1], $needle ) !== false ) { $c++; } } return $c; }

/* any PHP warning/notice/deprecation while a plugin function runs is a failure of that test */
$WARN = [];
set_error_handler( function ( $no, $str, $file, $line ) use ( &$WARN ) { $WARN[] = "$str @" . basename( $file ) . ":$line"; return true; } );

$PASS = 0; $FAIL = 0;
function ok( $cond, $name, $detail = '' ) {
    global $PASS, $FAIL;
    if ( $cond ) { $PASS++; } else { $FAIL++; echo "FAIL: $name $detail\n"; }
}
function is_( $got, $want, $name ) { ok( $got === $want, $name, '-- got ' . json_encode( $got ) . ' want ' . json_encode( $want ) ); }

$PLUGIN = realpath( $argv[1] ?? '' ) or exit( "usage: php gen-index.php path/to/includes/purge-http.php\n" );
$INC    = dirname( $PLUGIN );
require $PLUGIN;
require "$INC/state.php";

/** Extract one top-level function from a plugin file and define it (the files need WordPress, so they are not included). */
function load_fn( string $file, string $fn, bool $optional = false ): bool {
    if ( function_exists( $fn ) ) { return true; }
    if ( ! preg_match( '/^function ' . $fn . '\(.*?^}\n/ms', (string) file_get_contents( $file ), $m ) ) {
        if ( $optional ) { return false; }
        fwrite( STDERR, "FAIL cannot find $fn in $file\n" ); exit( 2 );
    }
    eval( $m[0] );
    return true;
}
foreach ( [ 'nppp_key_generation_info', 'nppp_drop_stale_generations', 'nppp_generation_stale_groups', 'nppp_generation_views' ] as $fn ) {
    load_fn( "$INC/advanced.php", $fn, $fn === 'nppp_generation_views' || $fn === 'nppp_generation_stale_groups' );
}
load_fn( "$INC/advanced.php", 'nppp_gen_scan_unverified', true );   // added by 57e57608
// Added by the Gen Index patch; absent on an unpatched plugin, which is reported once and then every behaviour test runs anyway.
$HELPERS = [];
foreach ( [ 'nppp_gen_mode_note_seen', 'nppp_gen_mode_seen', 'nppp_gen_mode_active', 'nppp_index_merge_hits' ] as $fn ) { $HELPERS[ $fn ] = load_fn( "$INC/advanced.php", $fn, true ); }
foreach ( [ 'nppp_purge_live_index_paths' ] as $fn ) { $HELPERS[ $fn ] = load_fn( "$INC/purge.php", $fn, true ); }
foreach ( [ 'nppp_purge_fp1_index_file_exists', 'nppp_purge_fp1_http', 'nppp_purge_fp2_index', 'nppp_purge_fp4_scan', 'nppp_purge_flush_write_back', 'nppp_purge_post_purge' ] as $fn ) { load_fn( "$INC/purge.php", $fn ); }
// Spelling helpers (16c0031d, 8efc87fb): FP2 skips multi-spelling keys, FP3/FP4 match through the canonical key.
foreach ( [ 'nppp_purge_canon_key', 'nppp_purge_key_has_spellings', 'nppp_purge_canon_map', 'nppp_purge_key_rg_alt' ] as $fn ) { load_fn( "$INC/purge.php", $fn ); }
foreach ( [ 'nppp_head_fast', 'nppp_read_head' ] as $fn ) { load_fn( "$INC/wp-filesystem.php", $fn ); }
$RX = ( include "$INC/preload-defaults.php" )['cache_key_regex'];

/* ------------------------------------------------------------------ fixtures */
class FakeFs {
    public $deleted = [];
    public function exists( $p ) { return file_exists( $p ); }
    public function is_dir( $p ) { return is_dir( $p ); }
    public function get_contents( $p ) { return @file_get_contents( $p ); }
    public function delete( $p, $r = false ) { if ( @unlink( $p ) ) { $this->deleted[] = $p; return true; } return false; }
}

$E1 = '1760000001'; $E0 = '1750000000';
$CACHE = sys_get_temp_dir() . '/nppp-gi-' . getmypid() . '-' . mt_rand();
mkdir( $CACHE . '/a/b', 0777, true );
register_shutdown_function( function () use ( $CACHE ) { exec( 'rm -rf ' . escapeshellarg( $CACHE ) ); } );
$SEQ = 0;

/** A cache file as nginx writes it. $gen = null -> a legacy (non-generation) key. Returns its path. */
function mkfile( ?int $gen, string $path = '/p/1/', string $ns = 'objects', string $epoch = '1760000001', string $pad = '' ): string {
    global $CACHE, $SEQ;
    $key  = $gen === null ? "httpGETexample.com$path" : sprintf( 'p4:%s:%s:%016x:httpGETexample.com%s', $ns, $epoch, $gen, $path );
    $file = $CACHE . '/a/b/' . md5( ( $SEQ++ ) . $key );
    file_put_contents( $file, "\x05\x00\x01hdr$pad\nKEY: $key\n\r\nHTTP/1.1 200 OK\r\nContent-Type: text/html\r\n\r\n<html>x</html>" );
    return $file;
}
/** A path inside the cache directory that does NOT exist (a swept / superseded generation). */
function deadpath( string $tag = 'x' ): string { global $CACHE, $SEQ; return $CACHE . '/a/b/dead-' . $tag . '-' . ( $SEQ++ ); }
function pfx(): string { global $CACHE; return rtrim( $CACHE, '/' ) . '/'; }

/** Purge context as nppp_purge_single_init() builds it. $urls[0] is the primary target. */
function mkctx( array $urls, array $index, int $head = 4096, int $head_fb = 32768 ): array {
    global $CACHE, $RX;
    $targets = []; $first = true;
    foreach ( $urls as $u ) {
        $targets[ preg_replace( '#^https?://#', '', $u ) ] = [ 'original' => $u, 'decoded' => rawurldecode( $u ), 'is_primary' => $first ];
        $first = false;
    }
    return [
        'wp_filesystem' => new FakeFs(), 'nppp_index' => $index, 'nginx_cache_path' => $CACHE,
        'primary_url' => $urls[0], 'primary_decoded' => rawurldecode( $urls[0] ),
        'chain_autopreload' => false, 'nppp_auto_purge' => false, 'regex' => $RX,
        'head_bytes' => $head, 'head_bytes_fb' => $head_fb,
        'settings' => get_option( 'nginx_cache_settings' ),
        'targets' => $targets, 'pending' => $targets, 'write_back' => [], 'primary_purged' => false,
    ];
}

/** Gate states. closed: nothing observed. seen: a scan met p4 keys (transient). single/all: an HTTP purge answered "invalidated". */
function gate( string $how ): void {
    if ( $how === 'seen' )   { nppp_gen_mode_flag_set(); }
    if ( $how === 'single' ) { nppp_http_purge_remember_generation( 'single', true ); }
    if ( $how === 'all' )    { nppp_http_purge_remember_generation( 'all', true ); }
}
function warned( string $name ): void { global $WARN; ok( ! $WARN, "$name: no PHP warning/notice", '-- ' . implode( ' | ', $WARN ) ); $WARN = []; }

$U1 = 'https://example.com/p/1/'; $K1 = 'example.com/p/1/';
$U2 = 'https://example.com/p/2/'; $K2 = 'example.com/p/2/';

foreach ( $HELPERS as $fn => $present ) {
    ok( $present, "patch applied: $fn() exists", '-- the Gen Index patch is not on this tree (expected to FAIL on unpatched code)' );
}

/* ------------------------------------------------------------------ 1. the gate */
if ( $HELPERS['nppp_gen_mode_active'] ) {
    t_reset();                                   is_( nppp_gen_mode_active(), false, 'gate: HTTP Purge on, nothing observed -> closed' );
    t_reset(); gate( 'seen' );                   is_( nppp_gen_mode_active(), true,  'gate: p4 keys seen in a scan -> open' );
    t_reset(); gate( 'single' );                 is_( nppp_gen_mode_active(), true,  'gate: HTTP answered "invalidated" for a single purge -> open' );
    t_reset(); gate( 'all' );                    is_( nppp_gen_mode_active(), true,  'gate: HTTP answered "invalidated" for Purge All -> open' );
    t_reset( [ 'nppp_http_purge_enabled' => 'no' ] );  gate( 'seen' ); gate( 'single' );
    is_( nppp_gen_mode_active(), false, 'gate: HTTP Purge OFF -> closed even with a 24 h transient and the reactive flag' );
    t_reset( [ 'nppp_http_purge_enabled' => '' ] );    gate( 'seen' );
    is_( nppp_gen_mode_active(), false, 'gate: HTTP Purge empty -> closed' );
    t_reset( [] ); unset( $GLOBALS['T']['options']['nginx_cache_settings']['nppp_http_purge_enabled'] ); gate( 'seen' );
    is_( nppp_gen_mode_active(), false, 'gate: HTTP Purge setting missing -> closed' );
    t_reset(); gate( 'seen' ); nppp_gen_mode_flag_delete();
    is_( nppp_gen_mode_active(), false, 'gate: transient cleared (Clear Plugin Cache) -> closed again' );

    t_reset(); nppp_gen_mode_note_seen();
    is_( $GLOBALS['T']['options']['nppp_st_gen_mode_seen']['v'] ?? null, 1, 'note_seen: sets the option row' );
    $left = ( $GLOBALS['T']['options']['nppp_st_gen_mode_seen']['exp'] ?? 0 ) - time();
    ok( $left > DAY_IN_SECONDS - 5 && $left <= DAY_IN_SECONDS, 'note_seen: lives 24 h', "-- got $left" );
    t_reset(); $row = [ 'v' => 7, 'exp' => time() + 5 ]; update_option( 'nppp_st_gen_mode_seen', $row, false ); nppp_gen_mode_note_seen();
    is_( $GLOBALS['T']['options']['nppp_st_gen_mode_seen'], $row, 'note_seen: written only when missing (no write on the hot path)' );
    warned( 'gate' );
}

/* ------------------------------------------------------------------ 2. scan entries: the gen_mode flag */
t_reset();
$urls = [
    [ 'url_encoded' => "https://example.com/p/1/", 'file_path' => '/c/a1', 'gen_group' => "objects|$E1|/p/1/", 'gen' => sprintf( '%016x', 1 ) ],
    [ 'url_encoded' => "https://example.com/p/1/", 'file_path' => '/c/a2', 'gen_group' => "objects|$E1|/p/1/", 'gen' => sprintf( '%016x', 2 ) ],
    [ 'url_encoded' => "https://example.com/l/",   'file_path' => '/c/leg' ],
];
nppp_drop_stale_generations( $urls );
is_( array_column( $urls, 'file_path' ), [ '/c/a2', '/c/leg' ], 'drop_stale: the superseded generation is dropped, legacy entry kept' );
is_( $urls[0]['gen_mode'] ?? null, true, 'drop_stale: a kept generation entry is flagged gen_mode (lets the merge prune only those)' );
ok( ! array_key_exists( 'gen_mode', $urls[1] ), 'drop_stale: a legacy entry is NOT flagged' );
ok( ! isset( $urls[0]['gen_group'] ) && ! isset( $urls[0]['gen'] ), 'drop_stale: internal gen_group/gen keys are removed from kept entries' );
if ( $HELPERS['nppp_gen_mode_note_seen'] ) { is_( nppp_gen_mode_flag_get(), true, 'drop_stale: seeing a generation entry records generation mode' ); }
t_reset(); $leg = [ [ 'url_encoded' => 'https://example.com/l/', 'file_path' => '/c/leg' ] ]; nppp_drop_stale_generations( $leg );
is_( nppp_gen_mode_flag_get(), false, 'drop_stale: legacy-only scan does not record generation mode' );
is_( $leg, [ [ 'url_encoded' => 'https://example.com/l/', 'file_path' => '/c/leg' ] ], 'drop_stale: legacy-only entries untouched' );
warned( 'drop_stale' );

/* ------------------------------------------------------------------ 3. the index merge */
if ( $HELPERS['nppp_index_merge_hits'] ) {
    $fs = new FakeFs();
    $live1 = mkfile( 2 ); $live2 = mkfile( 3 ); $d1 = deadpath( 'g1' ); $d2 = deadpath( 'g2' );
    $hit = function ( string $path, bool $gen = true, string $url = 'https://example.com/p/1/' ) { return [ 'url_encoded' => $url, 'file_path' => $path ] + ( $gen ? [ 'gen_mode' => true ] : [] ); };

    // gate closed: exactly the original behaviour, nothing is ever pruned
    t_reset(); $idx = [ $K1 => [ $d1, $live1 ] ];
    $n = nppp_index_merge_hits( $idx, [ $hit( $live2 ) ], $fs );
    is_( $idx, [ $K1 => [ $d1, $live1, $live2 ] ], 'merge, gate closed: new path appended, dead path kept (original behaviour)' );
    is_( $n, 1, 'merge, gate closed: returns the number of paths added' );

    // gate open, generation file: dead paths are pruned when a NEW path is appended
    t_reset(); gate( 'seen' ); $idx = [ $K1 => [ $d1, $d2, $live1 ] ];
    $n = nppp_index_merge_hits( $idx, [ $hit( $live2 ) ], $fs );
    is_( $idx, [ $K1 => [ $live1, $live2 ] ], 'merge, gate open: dead paths pruned, live path kept, new path appended' );
    is_( $n, 1, 'merge, gate open: returns 1' );

    // nothing new -> nothing pruned (documented: pruning is tied to appending) and nothing duplicated
    t_reset(); gate( 'seen' ); $idx = [ $K1 => [ $d1, $live1 ] ];
    $n = nppp_index_merge_hits( $idx, [ $hit( $live1 ) ], $fs );
    is_( [ $idx, $n ], [ [ $K1 => [ $d1, $live1 ] ], 0 ], 'merge, gate open: path already indexed -> no change, no duplicate, returns 0' );

    // legacy entry (no gen_mode flag) is never pruned even with the gate open: a missing legacy path comes back on re-cache
    t_reset(); gate( 'seen' ); $idx = [ $K1 => [ $d1, $live1 ] ];
    nppp_index_merge_hits( $idx, [ $hit( $live2, false ) ], $fs );
    is_( $idx, [ $K1 => [ $d1, $live1, $live2 ] ], 'merge, gate open, LEGACY entry: nothing pruned' );

    // gate open but HTTP Purge off -> closed
    t_reset( [ 'nppp_http_purge_enabled' => 'no' ] ); gate( 'seen' ); $idx = [ $K1 => [ $d1, $live1 ] ];
    nppp_index_merge_hits( $idx, [ $hit( $live2 ) ], $fs );
    is_( $idx, [ $K1 => [ $d1, $live1, $live2 ] ], 'merge, HTTP Purge off: nothing pruned' );

    // first path of a URL, other URLs untouched, scheme stripped from the key
    t_reset(); gate( 'seen' ); $idx = [ 'example.com/other/' => [ $d1 ] ];
    nppp_index_merge_hits( $idx, [ $hit( $live1 ), $hit( $live2, true, 'http://example.com/p/2/' ) ], $fs );
    is_( $idx, [ 'example.com/other/' => [ $d1 ], $K1 => [ $live1 ], $K2 => [ $live2 ] ], 'merge: first path of a URL is plain append; other URLs and their dead paths are left alone; https?:// stripped' );

    // genuine Vary variants (both files exist) both survive
    t_reset(); gate( 'seen' ); $va = mkfile( 5, '/v/' ); $vb = mkfile( 5, '/v/' ); $idx = [];
    nppp_index_merge_hits( $idx, [ $hit( $va, true, 'https://example.com/v/' ), $hit( $vb, true, 'https://example.com/v/' ) ], $fs );
    is_( $idx, [ 'example.com/v/' => [ $va, $vb ] ], 'merge: two existing variants of one URL are both kept' );

    // junk already in the index is dropped only while pruning; never a crash
    t_reset(); gate( 'seen' ); $idx = [ $K1 => [ 123, null, $live1 ] ];
    nppp_index_merge_hits( $idx, [ $hit( $live2 ) ], $fs );
    is_( $idx, [ $K1 => [ $live1, $live2 ] ], 'merge, gate open: non-string junk in an old index entry is pruned with the dead paths' );
    warned( 'merge' );
}

/* ------------------------------------------------------------------ 4. nppp_purge_live_index_paths */
if ( $HELPERS['nppp_purge_live_index_paths'] ) {
    $ctx = mkctx( [ $U1 ], [] ); $P = pfx();
    $live = function ( array $paths ) use ( $ctx, $P ) { return nppp_purge_live_index_paths( $ctx, $paths, $P ); };
    $g1 = mkfile( 1 ); $g2 = mkfile( 2 ); $g3 = mkfile( 3 ); $gone = deadpath( 'live' );

    is_( $live( [] ), [], 'live paths: empty index entry -> []' );
    is_( $live( [ $gone ] ), [], 'live paths: a path whose file is gone is not counted' );
    is_( $live( [ $gone, $g1 ] ), [ $g1 ], 'live paths: dead + live -> just the live one (the unpatched code counted 2 = "multiple variants")' );
    is_( $live( [ $g1, $g1 ] ), [ $g1, $g1 ], 'live paths: a duplicate index entry is not merged away (callers keep their own dedup)' );
    is_( $live( [ '/elsewhere/cache/x', $g1 ] ), [ $g1 ], 'live paths: a path outside the current cache directory is not counted' );
    is_( $live( [ 5, null, [ 'x' ], $g1 ] ), [ $g1 ], 'live paths: non-string junk is ignored' );
    is_( $live( [ $g1, $g2 ] ), [ $g2 ], 'live paths: older + newer generation of one key -> only the newer (the older is superseded)' );
    is_( $live( [ $g3, $g1, $g2 ] ), [ $g3 ], 'live paths: order does not matter, highest generation wins' );

    // Vary variants: same logical key AND generation -> all stay
    $va = mkfile( 7, '/v/' ); $vb = mkfile( 7, '/v/' );
    is_( $live( [ $va, $vb ] ), [ $va, $vb ], 'live paths: genuine variants (same key, same generation) all stay' );
    // different logical keys never supersede each other
    $o1 = mkfile( 1, '/o/1/' ); $o2 = mkfile( 9, '/o/2/' );
    is_( $live( [ $o1, $o2 ] ), [ $o1, $o2 ], 'live paths: different logical keys never supersede each other' );
    $n1 = mkfile( 1, '/n/', 'objects' ); $n2 = mkfile( 9, '/n/', 'pages' );
    is_( $live( [ $n1, $n2 ] ), [ $n1, $n2 ], 'live paths: different namespaces never supersede each other' );
    // different epochs are different groups: both stay (the plugin cannot order epochs; the module sweep removes the old one)
    $e0 = mkfile( 9, '/e/', 'objects', $E0 ); $e1 = mkfile( 1, '/e/', 'objects', $E1 );
    is_( $live( [ $e0, $e1 ] ), [ $e0, $e1 ], 'live paths: files of two epochs are not compared (documented: epochs are not ordered)' );

    // legacy files carry no envelope: never dropped
    $l1 = mkfile( null, '/leg/' ); $l2 = mkfile( null, '/leg/' );
    is_( $live( [ $l1, $l2 ] ), [ $l1, $l2 ], 'live paths: legacy (non-generation) files are never dropped' );
    is_( $live( [ $l1, $g1, $g2 ] ), [ $l1, $g2 ], 'live paths: legacy file kept next to a generation pair, which still collapses to the newer one' );

    // KEY line beyond the first head_bytes -> the fallback read must find it
    $far1 = mkfile( 1, '/far/', 'objects', $E1, str_repeat( 'x', 200 ) ); $far2 = mkfile( 2, '/far/', 'objects', $E1, str_repeat( 'x', 200 ) );
    $small = mkctx( [ $U1 ], [], 64, 4096 );
    is_( nppp_purge_live_index_paths( $small, [ $far1, $far2 ], $P ), [ $far2 ], 'live paths: KEY line past the first head read is found by the fallback read' );
    $nofb = mkctx( [ $U1 ], [], 64, 64 );
    is_( nppp_purge_live_index_paths( $nofb, [ $far1, $far2 ], $P ), [ $far1, $far2 ], 'live paths: key unreadable at both sizes -> treated as legacy, both kept (never guessed)' );

    // upper-case hex generation compares as lower case
    $up = $GLOBALS['CACHE'] . '/a/b/upper-' . mt_rand();
    file_put_contents( $up, "hdr\nKEY: p4:objects:$E1:" . strtoupper( sprintf( '%016x', 255 ) ) . ":httpGETexample.com/u/\n\r\nx" );
    $lo = mkfile( 255, '/u/' ); $hi = mkfile( 256, '/u/' );
    is_( $live( [ $up, $lo, $hi ] ), [ $hi ], 'live paths: upper-case hex generation is compared as lower case (255 == 0xFF < 256)' );
    warned( 'live paths' );
}

/* ------------------------------------------------------------------ 5. FP1 */
$setup_dead_live = function () use ( $K1, $U1 ) {   // index: one dead path + one live path of the same URL
    $live = mkfile( 2 ); $dead = deadpath( 'f1' );
    return [ $live, $dead, mkctx( [ $U1 ], [ $K1 => [ $dead, $live ] ] ) ];
};

// 5a gate closed: counts every path under the prefix, dead or not -> bypass, no HTTP request (original behaviour)
t_reset(); [ $live, $dead, $ctx ] = $setup_dead_live(); Q( R( 200, 'invalidated' ) );
$r = nppp_purge_fp1_http( $ctx );
is_( [ $r, nreq(), array_keys( $ctx['pending'] ) ], [ false, 0, [ $K1 ] ], 'FP1, gate closed: dead+live index paths still count as 2 variants -> bypass, no HTTP, target stays pending' );
ok( notice_has( 'BYPASS: Multiple cache variants', 'info' ), 'FP1, gate closed: logs the BYPASS info line' );
warned( 'FP1 closed' );

// 5b gate open: the dead path is not a variant -> HTTP purge runs
foreach ( [ 'seen', 'single', 'all' ] as $how ) {
    t_reset(); gate( $how ); [ $live, $dead, $ctx ] = $setup_dead_live(); Q( R( 200, 'invalidated' ) );
    $r = nppp_purge_fp1_http( $ctx );
    is_( [ $r, nreq(), $ctx['pending'] ], [ true, 1, [] ], "FP1, gate open ($how): dead+live counts as ONE -> HTTP purge runs and resolves the target" );
    ok( ! notice_has( 'BYPASS' ), "FP1, gate open ($how): no BYPASS line" );
    ok( file_exists( $live ), "FP1, gate open ($how): HTTP path never touches the file (the module sweeps later)" );
}
warned( 'FP1 open' );

// 5c gate open: HTTP could not finish -> target stays pending for the layers below, nothing claims success
t_reset(); gate( 'seen' ); gate( 'single' ); [ $live, $dead, $ctx ] = $setup_dead_live(); Q( E( 'cURL error 7: Failed to connect' ) );
$r = nppp_purge_fp1_http( $ctx );
is_( [ $r, nreq(), array_keys( $ctx['pending'] ) ], [ false, 1, [ $K1 ] ], 'FP1, gate open, HTTP unreachable: target left pending for FP2-FP4' );
ok( ! notice_has( 'SUCCESS' ), 'FP1, gate open, HTTP unreachable: no success claimed' );
ok( ! notice_has( 'cannot remove its cache entry' ) && ! notice_has( 'until a Purge All is run' ) && ! notice_has( 'does not see generated cache keys' ),
    'FP1, gate open, HTTP unreachable: no false "the filesystem purge cannot remove its cache entry" error' );
ok( notice_has( 'Falling back to direct filesystem purge', 'error' ), 'FP1, gate open, HTTP unreachable: the genuine transport error (fallback announced) is still reported' );
ok( notice_has( 'Continuing with the filesystem layers', 'info' ), 'FP1, gate open, HTTP unreachable: an info line says the filesystem layers continue' );

// 5d gate open, real Vary variants (same generation, both live) -> bypass so FP3/FP4 can delete all of them atomically
t_reset(); gate( 'seen' ); $va = mkfile( 4, '/p/1/' ); $vb = mkfile( 4, '/p/1/' ); Q( R( 200, 'invalidated' ) );
$ctx = mkctx( [ $U1 ], [ $K1 => [ $va, $vb ] ] );
$r = nppp_purge_fp1_http( $ctx );
is_( [ $r, nreq(), array_keys( $ctx['pending'] ) ], [ false, 0, [ $K1 ] ], 'FP1, gate open: two live variants of one generation still bypass HTTP (all variants must go)' );

// 5e gate open: old generation + new generation both on disk and indexed -> the old one is superseded, HTTP runs
t_reset(); gate( 'seen' ); $old = mkfile( 1, '/p/1/' ); $new = mkfile( 2, '/p/1/' ); Q( R( 200, 'invalidated' ) );
$ctx = mkctx( [ $U1 ], [ $K1 => [ $old, $new ] ] );
$r = nppp_purge_fp1_http( $ctx );
is_( [ $r, nreq() ], [ true, 1 ], 'FP1, gate open: old+new generation of one page -> not "multiple variants", HTTP runs' );

// 5f gate open, no index entry / index only holds another cache directory
t_reset(); gate( 'seen' ); Q( R( 200, 'invalidated' ) ); $ctx = mkctx( [ $U1 ], [] );
is_( [ nppp_purge_fp1_http( $ctx ), nreq() ], [ true, 1 ], 'FP1, gate open: no index entry -> HTTP runs' );
t_reset(); gate( 'seen' ); Q( R( 200, 'invalidated' ) ); $ctx = mkctx( [ $U1 ], [ $K1 => [ '/old/cache/a', '/old/cache/b' ] ] );
is_( [ nppp_purge_fp1_http( $ctx ), nreq() ], [ true, 1 ], 'FP1, gate open: index paths of another cache directory are not variants -> HTTP runs' );

// 5g gate closed + legacy index with two live variants: original behaviour (bypass)
t_reset(); $la = mkfile( null, '/p/1/' ); $lb = mkfile( null, '/p/1/' ); Q( R( 200, 'purged' ) );
$ctx = mkctx( [ $U1 ], [ $K1 => [ $la, $lb ] ] );
is_( [ nppp_purge_fp1_http( $ctx ), nreq() ], [ false, 0 ], 'FP1, gate closed (legacy): two live variants bypass HTTP (original behaviour)' );
// 5h gate closed + legacy index with a dead variant path: still counted (original behaviour)
t_reset(); Q( R( 200, 'purged' ) ); $ctx = mkctx( [ $U1 ], [ $K1 => [ deadpath( 'l' ), $la ] ] );
is_( [ nppp_purge_fp1_http( $ctx ), nreq() ], [ false, 0 ], 'FP1, gate closed (legacy): a dead path still counts as a variant, exactly as before' );
// 5i HTTP Purge OFF: FP1 does nothing even with the transient
t_reset( [ 'nppp_http_purge_enabled' => 'no' ] ); gate( 'seen' ); Q( R( 200, 'invalidated' ) ); [ $live, $dead, $ctx ] = $setup_dead_live();
is_( [ nppp_purge_fp1_http( $ctx ), nreq(), array_keys( $ctx['pending'] ) ], [ false, 0, [ $K1 ] ], 'FP1, HTTP Purge off: returns false at once, no request' );
warned( 'FP1' );

/* ------------------------------------------------------------------ 6. FP2 */
// 6a THE BUG, gate closed (legacy semantics): the only indexed path is gone -> "not in cache (INDEX)", target resolved
t_reset(); $live = mkfile( 3 ); $ctx = mkctx( [ $U1 ], [ $K1 => [ deadpath( 'f2' ) ] ] );
$r = nppp_purge_fp2_index( $ctx );
is_( [ $r, $ctx['pending'] ], [ true, [] ], 'FP2, gate closed: all indexed paths gone -> target resolved as a miss (original behaviour)' );
ok( notice_has( 'is not currently found in the cache (INDEX)', 'info' ), 'FP2, gate closed: reports the INDEX miss' );
ok( file_exists( $live ), 'FP2, gate closed: ...and an unindexed file of the page is left alone (this is the hole generation mode opens)' );

// 6b gate open: the SAME situation must NOT be resolved by FP2 -> pending for the scan layers, no INDEX claim
foreach ( [ 'seen', 'single', 'all' ] as $how ) {
    t_reset(); gate( $how ); $live = mkfile( 3 ); $ctx = mkctx( [ $U1 ], [ $K1 => [ deadpath( 'f2' ) ] ] );
    $r = nppp_purge_fp2_index( $ctx );
    is_( [ $r, array_keys( $ctx['pending'] ) ], [ false, [ $K1 ] ], "FP2, gate open ($how): a dead indexed path is NOT proof of a miss -> target left to FP3/FP4" );
    ok( ! notice_has( '(INDEX)' ), "FP2, gate open ($how): no (INDEX) claim, no success" );
    ok( file_exists( $live ), "FP2, gate open ($how): the live unindexed file is still there for FP3/FP4" );
}
// 6c gate open: even a LIVE indexed path is left to the scan layers (names change per generation; the scan matches the KEY line)
t_reset(); gate( 'seen' ); $live = mkfile( 3 ); $ctx = mkctx( [ $U1 ], [ $K1 => [ $live ] ] );
is_( [ nppp_purge_fp2_index( $ctx ), array_keys( $ctx['pending'] ), file_exists( $live ) ], [ false, [ $K1 ], true ], 'FP2, gate open: does not delete by index (FP3/FP4 will, by KEY line)' );
// 6d gate open: no index entry, nothing printed
t_reset(); gate( 'seen' ); $ctx = mkctx( [ $U1 ], [] );
is_( [ nppp_purge_fp2_index( $ctx ), array_keys( $ctx['pending'] ), $GLOBALS['T']['notices'] ], [ false, [ $K1 ], [] ], 'FP2, gate open: no index entry -> pending, silent' );
// 6e gate open: related targets are skipped the same way
t_reset(); gate( 'seen' ); $rel = mkfile( 3, '/p/2/' ); $ctx = mkctx( [ $U1, $U2 ], [ $K1 => [ deadpath( 'a' ) ], $K2 => [ $rel ] ] );
is_( [ nppp_purge_fp2_index( $ctx ), array_keys( $ctx['pending'] ), file_exists( $rel ) ], [ false, [ $K1, $K2 ], true ], 'FP2, gate open: primary and related targets are all left to the scan layers' );

// 6f the filter restores index trust (opt-out)
t_reset(); gate( 'seen' ); t_filter( 'nppp_fp2_skip_generation_mode', fn( $v ) => false ); $live = mkfile( 3 ); $ctx = mkctx( [ $U1 ], [ $K1 => [ $live ] ] );
is_( [ nppp_purge_fp2_index( $ctx ), $ctx['pending'], file_exists( $live ) ], [ true, [], false ], 'FP2: filter nppp_fp2_skip_generation_mode=false restores index trust (deletes by index)' );
t_reset(); gate( 'seen' ); t_filter( 'nppp_fp2_skip_generation_mode', fn( $v ) => true ); $live = mkfile( 3 ); $ctx = mkctx( [ $U1 ], [ $K1 => [ $live ] ] );
is_( [ nppp_purge_fp2_index( $ctx ), file_exists( $live ) ], [ false, true ], 'FP2: filter returning true keeps the skip' );

// 6g legacy behaviour is intact with the gate closed: delete by index, partial variants, success lines
t_reset(); $la = mkfile( null, '/p/1/' ); $lb = mkfile( null, '/p/1/' ); $ctx = mkctx( [ $U1 ], [ $K1 => [ deadpath( 'v' ), $la, $lb ] ] );
$r = nppp_purge_fp2_index( $ctx );
is_( [ $r, $ctx['pending'], file_exists( $la ), file_exists( $lb ) ], [ true, [], false, false ], 'FP2, gate closed (legacy): deletes every existing variant by index and resolves the target' );
ok( notice_has( 'SUCCESS ADMIN: Nginx cache purged for page', 'success' ) && notice_has( '(INDEX)' ), 'FP2, gate closed (legacy): success (INDEX) line' );

// 6h THE v1 REGRESSION: HTTP Purge OFF but a stale transient -> the gate is closed, FP2 still trusts and uses the index
t_reset( [ 'nppp_http_purge_enabled' => 'no' ] ); gate( 'seen' ); $la = mkfile( null, '/p/1/' ); $ctx = mkctx( [ $U1 ], [ $K1 => [ $la ] ] );
is_( [ nppp_purge_fp2_index( $ctx ), file_exists( $la ) ], [ true, false ], 'FP2, HTTP Purge OFF + leftover transient: gate closed, index delete works as before' );
// 6i no prefix match (index from another cache dir) -> pending in both modes
t_reset(); $ctx = mkctx( [ $U1 ], [ $K1 => [ '/old/cache/x' ] ] );
is_( [ nppp_purge_fp2_index( $ctx ), array_keys( $ctx['pending'] ) ], [ false, [ $K1 ] ], 'FP2, gate closed: index only has another cache directory -> pending (unchanged)' );
warned( 'FP2' );

/* ------------------------------------------------------------------ 7. the "HTTP did not finish" notice */
$amb = 'https://example.com/caf%C3%A9/';       // decode-ambiguous: an "invalidated" answer is not trusted -> false + generation mode remembered
t_reset(); Q( R( 200, 'invalidated' ) );
is_( nppp_http_purge_try_first( $amb ), false, 'try_first: ambiguous URL + "invalidated" is not trusted -> false' );
is_( count( $GLOBALS['T']['notices'] ), 1, 'try_first: exactly one notice' );
[ $nt, $nm, $nlog, $nshow ] = $GLOBALS['T']['notices'][0] + [ null, null, null, null ];
is_( $nt, 'info', 'try_first: severity is INFO, not ERROR (the filesystem layers can still remove the entry)' );
is_( [ $nlog, $nshow ], [ true, false ], 'try_first: log line only (log=true, show=false): it must not stick in the admin notice as an error' );
ok( strpos( (string) $nm, 'Continuing with the filesystem layers' ) !== false, 'try_first: says the filesystem layers continue' );
ok( strpos( (string) $nm, 'generation mode is active' ) !== false, 'try_first: names generation mode' );
ok( strpos( (string) $nm, 'cannot remove' ) === false && strpos( (string) $nm, 'until a Purge All' ) === false, 'try_first: the old false "cannot purge / run a Purge All" text is gone' );
ok( strpos( (string) $nm, 'https://example.com/caf' ) !== false || strpos( (string) $nm, 'caf' ) !== false, 'try_first: names the page' );

t_reset(); gate( 'single' ); Q( E( 'cURL error 7: Failed to connect' ) );
is_( nppp_http_purge_try_first( 'https://example.com/plain/' ), false, 'try_first: unreachable endpoint -> false' );
ok( notice_count( 'Continuing with the filesystem layers' ) === 1 && notice_has( 'Continuing with the filesystem layers', 'info' ), 'try_first: in generation mode an unfinished purge logs exactly one INFO line' );
t_reset(); Q( E( 'cURL error 7: Failed to connect' ) );
is_( nppp_http_purge_try_first( 'https://example.com/plain/' ), false, 'try_first: legacy mode, unreachable -> false' );
ok( ! notice_has( 'Continuing with the filesystem layers' ), 'try_first: no generation notice outside generation mode' );
t_reset( [ 'nppp_http_purge_enabled' => 'no' ] ); gate( 'single' );
is_( [ nppp_http_purge_try_first( 'https://example.com/plain/' ), nreq(), $GLOBALS['T']['notices'] ], [ false, 0, [] ], 'try_first: HTTP Purge off -> false, no request, no notice' );
t_reset(); Q( R( 200, 'invalidated' ) );
is_( nppp_http_purge_try_first( 'https://example.com/plain/' ), true, 'try_first: a trusted "invalidated" -> true' );
foreach ( $GLOBALS['T']['notices'] as $n ) { ok( strpos( $n[1], 'namespaced cache keys' ) === false && strpos( $n[1], 'do not see' ) === false, 'try_first: the generation hint no longer claims the plugin cannot see namespaced keys' ); }
warned( 'try_first' );

/* ------------------------------------------------------------------ 8. the whole chain on a real cache directory */
/** nppp_purge_single() minus locks and ripgrep: FP1 -> FP2 -> FP4, stopping at the first layer that resolves everything. */
function chain( array &$ctx ): string {
    if ( nppp_purge_fp1_http( $ctx ) ) { return 'FP1'; }
    if ( nppp_purge_fp2_index( $ctx ) ) { return 'FP2'; }
    nppp_purge_fp4_scan( $ctx );
    return 'FP4';
}

// 8a THE SILENT EMPTY PURGE. Generation 3 of the page is live on disk and NOT in the index; the index only holds
//    superseded generations. HTTP cannot finish. Unpatched: FP2 says "not in cache (INDEX)", the file stays HIT.
t_reset(); gate( 'single' ); $g1 = deadpath( 'gen1' ); $g2 = deadpath( 'gen2' ); $live = mkfile( 3 ); Q( E( 'cURL error 28: timed out' ) );
$ctx = mkctx( [ $U1 ], [ $K1 => [ $g1, $g2 ] ] );
$by = chain( $ctx );
is_( $by, 'FP4', 'chain 8a: the scan layer resolves the target (FP2 does not close it on a dead indexed path)' );
ok( ! file_exists( $live ), 'chain 8a: the live, unindexed generation file is DELETED (found by its KEY line)' );
ok( notice_has( 'SUCCESS ADMIN: Nginx cache purged for page', 'success' ) && notice_has( '(SCAN)' ), 'chain 8a: reports success from the scan layer' );
ok( ! notice_has( 'not currently found in the cache (INDEX)' ), 'chain 8a: never claims a false "not in cache (INDEX)"' );
is_( $ctx['pending'], [], 'chain 8a: nothing left pending' );

// 8b HTTP succeeds: FP1 resolves it, the disk is not touched (the module's sweep removes stale files later)
t_reset(); gate( 'single' ); $live = mkfile( 3 ); Q( R( 200, 'invalidated' ) );
$ctx = mkctx( [ $U1 ], [ $K1 => [ deadpath( 'gen1' ), deadpath( 'gen2' ) ] ] );
is_( [ chain( $ctx ), file_exists( $live ), nreq() ], [ 'FP1', true, 1 ], 'chain 8b: dead index paths no longer divert a purge away from HTTP; a successful HTTP purge leaves the file to the module sweep' );

// 8c two live variants of the current generation: bypass HTTP, FP2 steps aside, FP4 removes BOTH
t_reset(); gate( 'seen' ); $va = mkfile( 4 ); $vb = mkfile( 4 ); Q( R( 200, 'invalidated' ) );
$ctx = mkctx( [ $U1 ], [ $K1 => [ $va, $vb ] ] );
is_( [ chain( $ctx ), file_exists( $va ), file_exists( $vb ), nreq() ], [ 'FP4', false, false, 0 ], 'chain 8c: Vary variants bypass HTTP and are all removed by the scan layer' );

// 8d primary + related page, both with only dead index paths and live generation files
t_reset(); gate( 'seen' ); $p1 = mkfile( 5, '/p/1/' ); $p2 = mkfile( 5, '/p/2/' ); $other = mkfile( 5, '/p/3/' ); Q( E( 'cURL error 7' ), E( 'cURL error 7' ) );
$ctx = mkctx( [ $U1, $U2 ], [ $K1 => [ deadpath( 'a' ) ], $K2 => [ deadpath( 'b' ) ] ] );
$by = chain( $ctx );
is_( [ $by, file_exists( $p1 ), file_exists( $p2 ), file_exists( $other ) ], [ 'FP4', false, false, true ], 'chain 8d: primary and related pages are removed by KEY line; an unrelated page is untouched' );

// 8e legacy site (gate closed): the original chain, FP2 deletes by index, FP4 never runs
t_reset(); $la = mkfile( null, '/p/1/' ); $lb = mkfile( null, '/p/1/' ); $ctx = mkctx( [ $U1 ], [ $K1 => [ deadpath( 'v' ), $la, $lb ] ] );
is_( [ chain( $ctx ), file_exists( $la ), file_exists( $lb ) ], [ 'FP2', false, false ], 'chain 8e: legacy site, HTTP Purge on but never generation mode: FP1 bypasses, FP2 deletes by index (original behaviour)' );

// 8f the page is genuinely not cached: scan layer says so, once, and nothing is deleted
t_reset(); gate( 'seen' ); $other = mkfile( 5, '/p/9/' ); Q( R( 200, 'invalidated' ) ); Q( E( 'cURL error 7' ) );
$ctx = mkctx( [ $U1 ], [ $K1 => [ deadpath( 'a' ), deadpath( 'b' ) ] ] );
$by = chain( $ctx );
is_( [ $by, $ctx['pending'], file_exists( $other ) ], [ 'FP1', [], true ], 'chain 8f: uncached page with dead index paths -> HTTP answers, other pages untouched' );
warned( 'chain' );

echo "\n$PASS passed, $FAIL failed\n";
exit( $FAIL ? 1 : 0 );
