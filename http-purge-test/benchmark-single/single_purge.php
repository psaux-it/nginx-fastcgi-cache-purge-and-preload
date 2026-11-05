<?php
/**
 * Purge Single worker: runs ONE real plugin purge layer, once, and prints one JSON line.
 *
 *   php single_purge.php <http|rg|scan> <cache dir> <url> [<url> ...]
 *
 *   http  nppp_purge_fp1_http()  FP1: one HTTP request per target to the ngx_cache_purge "single" location
 *   rg    nppp_purge_fp3_rg()    FP3: ONE ripgrep pass over the cache dir for all targets, then unlink
 *   scan  nppp_purge_fp4_scan()  FP4: PHP recursive iterator scan (the layer after rg), optional third contender
 *
 * The first URL is the primary target, the rest are "related" pages, exactly like the $ctx that
 * nppp_purge_single_init() builds. The plugin functions are NOT re-typed: they are extracted from the
 * plugin sources at run time (same technique as spelling-test.php / php_delete.php) and run against
 * the real nginx (HTTP) and the real cache files (rg / scan). Only WordPress itself is stubbed.
 *
 * Environment
 *   NPPP_PLUGIN        plugin root (default: repo root, two levels above this file)
 *   NPPP_BENCH_PORT    nginx port of the benchmark server (default 8081)
 *   NPPP_BENCH_INDEX   seed N entries into the nppp_url_filepath_index option and make get_option/update_option
 *                      serialize round-trip it, to mimic the DB cost of nppp_purge_flush_write_back() (default 0)
 *
 * Timing is taken INSIDE this process around the layer call, so PHP start-up is not part of it (a real
 * single purge runs inside an already-booted PHP-FPM worker). The rg exec() is additionally timed on its own
 * through a source transformation of the extracted function (asserted: exactly one exec() is rewritten).
 */
error_reporting( E_ALL );
ini_set( 'display_errors', 'stderr' );

if ( $argc < 4 ) {
    fwrite( STDERR, "usage: php single_purge.php <http|rg|scan> <cache dir> <url> [<url> ...]\n" );
    exit( 2 );
}

$METHOD = $argv[1];
$CACHE  = rtrim( $argv[2], '/' );
$URLS   = array_slice( $argv, 3 );
$PORT   = (int) ( getenv( 'NPPP_BENCH_PORT' ) ?: 8081 );
$PLUGIN = getenv( 'NPPP_PLUGIN' ) ?: dirname( __DIR__, 2 );
$INC    = $PLUGIN . '/includes';
$BOOT0  = hrtime( true );

if ( ! in_array( $METHOD, [ 'http', 'rg', 'scan' ], true ) ) {
    fwrite( STDERR, "unknown method $METHOD\n" );
    exit( 2 );
}
foreach ( [ 'purge-http.php', 'purge.php', 'advanced.php', 'wp-filesystem.php', 'state.php', 'preload-defaults.php' ] as $need ) {
    if ( ! is_file( "$INC/$need" ) ) {
        fwrite( STDERR, "FAIL $need not found in $INC\n" );
        exit( 2 );
    }
}

define( 'ABSPATH', '/tmp/' );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'DAY_IN_SECONDS', 86400 );
define( 'MONTH_IN_SECONDS', 2592000 );

/* ------------------------------------------------------------------ WordPress stand-ins */
class WP_Error {
    public $m;
    public function __construct( $c = '', $m = '' ) { $this->m = $m; }
    public function get_error_messages() { return [ $this->m ]; }
}

$B = [                      // measurements collected by the stubs / the instrumented functions
    'http'    => [],        // one entry per wp_remote_request()
    'notices' => [],
    'rg'      => [],
];
$T = [
    'options' => [ 'nginx_cache_settings' => [
        'nppp_http_purge_enabled'    => 'yes',
        'nppp_http_purge_custom_url' => "http://127.0.0.1:$PORT",
        'nppp_rg_purge_enabled'      => 'yes',
        'nginx_cache_path'           => $CACHE,
    ] ],
    'tr' => [],
];
$INDEX_ENTRIES = (int) ( getenv( 'NPPP_BENCH_INDEX' ) ?: 0 );
if ( $INDEX_ENTRIES > 0 ) {
    $idx = [];
    for ( $i = 0; $i < $INDEX_ENTRIES; $i++ ) {
        $idx[ "example.com/idx/$i" ] = [ sprintf( '%s/a/b/%032x', $CACHE, $i ) ];
    }
    $T['options']['nppp_url_filepath_index'] = serialize( $idx );
    unset( $idx );
}

function get_option( $k, $d = false ) {
    global $T;
    if ( ! array_key_exists( $k, $T['options'] ) ) { return $d; }
    $v = $T['options'][ $k ];
    return ( $k === 'nppp_url_filepath_index' && is_string( $v ) ) ? unserialize( $v ) : $v;   // option read = unserialize
}
function update_option( $k, $v, $autoload = null ) {
    global $T;
    $T['options'][ $k ] = ( $k === 'nppp_url_filepath_index' ) ? serialize( $v ) : $v;           // option write = serialize
    return true;
}
function delete_option( $k ) { global $T; unset( $T['options'][ $k ] ); return true; }
function wp_cache_delete( $k, $g = '' ) { return true; }
function home_url() { return 'http://example.com'; }
function get_site_url() { return 'http://example.com'; }
function wp_parse_url( $u, $c = -1 ) { return parse_url( $u, $c ); }
function absint( $n ) { return abs( (int) $n ); }
function esc_url( $u ) { return $u; }
function esc_url_raw( $u ) { return $u; }
function __( $s, $d = '' ) { return $s; }
function _n( $a, $b, $n, $d = '' ) { return $n === 1 ? $a : $b; }
function url_to_postid( $u ) { return 0; }
function apply_filters( $tag, $v, ...$a ) { return $v; }
function do_action( $tag, ...$a ) {}
function add_action( ...$a ) {}
function remove_action( ...$a ) {}
function get_transient( $k ) { global $T; return $T['tr'][ $k ] ?? false; }
function set_transient( $k, $v, $t ) { global $T; $T['tr'][ $k ] = $v; return true; }
function delete_transient( $k ) { global $T; unset( $T['tr'][ $k ] ); return true; }
function is_wp_error( $x ) { return $x instanceof WP_Error; }
function nppp_display_admin_notice( $type, $msg, $log = true, $show = true ) { global $B; $B['notices'][] = "[$type] $msg"; }

/** Real HTTP to the benchmark nginx (what WP_Http/curl does for the plugin), timed per request. */
function wp_remote_request( $url, $a ) {
    global $B;
    $t0 = hrtime( true );
    $ch = curl_init( $url );
    curl_setopt_array( $ch, [
        CURLOPT_CUSTOMREQUEST  => $a['method'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER         => true,
        CURLOPT_TIMEOUT        => $a['timeout'],
        CURLOPT_HTTPHEADER     => [ 'Host: ' . $a['headers']['Host'] ],
        CURLOPT_FOLLOWLOCATION => false,
    ] );
    $raw = curl_exec( $ch );
    if ( $raw === false ) {
        $e = curl_error( $ch );
        $B['http'][] = [ 'ms' => ( hrtime( true ) - $t0 ) / 1e6, 'status' => 0, 'error' => $e ];
        return new WP_Error( 'x', 'cURL error ' . curl_errno( $ch ) . ": $e" );
    }
    $hs   = curl_getinfo( $ch, CURLINFO_HEADER_SIZE );
    $code = curl_getinfo( $ch, CURLINFO_RESPONSE_CODE );
    $h    = [];
    foreach ( explode( "\r\n", substr( $raw, 0, $hs ) ) as $l ) {
        if ( strpos( $l, ':' ) ) { [ $k, $v ] = explode( ':', $l, 2 ); $h[ strtolower( trim( $k ) ) ] = trim( $v ); }
    }
    $B['http'][] = [
        'ms'        => round( ( hrtime( true ) - $t0 ) / 1e6, 3 ),
        'curl_ms'   => round( curl_getinfo( $ch, CURLINFO_TOTAL_TIME ) * 1000, 3 ),
        'connect_ms' => round( curl_getinfo( $ch, CURLINFO_CONNECT_TIME ) * 1000, 3 ),
        'status'    => $code,
        'code'      => $h['cache-purge-code'] ?? '',
    ];
    return [ 'c' => $code, 'b' => substr( $raw, $hs ), 'h' => $h ];
}
function wp_remote_retrieve_response_code( $r ) { return $r['c']; }
function wp_remote_retrieve_body( $r ) { return $r['b']; }
function wp_remote_retrieve_header( $r, $n ) { return $r['h'][ strtolower( $n ) ] ?? ''; }

/** WP_Filesystem_Direct semantics: delete() of a file is is_file() + @unlink(). */
class DirectFs {
    public function exists( $p ) { return file_exists( $p ); }
    public function is_dir( $p ) { return is_dir( $p ); }
    public function is_file( $p ) { return is_file( $p ); }
    public function get_contents( $p ) { return @file_get_contents( $p ); }
    public function delete( $p, $r = false, $t = false ) { return is_file( $p ) ? @unlink( $p ) : false; }
}

// Environment the rg / scan layers call (same stand-ins as spelling-test.php / gen-scan.php)
function nppp_prepare_request_env() {}                 // once-per-request PATH hygiene (putenv), negligible
function nppp_check_rg_version() { return '14.1.0'; }  // cached in a month-long transient in production
function nppp_fuse_source_path( $p ) { return null; }  // no FUSE mount

/* ------------------------------------------------------------------ real plugin code */
require "$INC/purge-http.php";
require "$INC/state.php";

/** Source of one top-level function of a plugin file. */
function fn_source( string $file, string $fn ): string {
    if ( ! preg_match( '/^function ' . $fn . '\(.*?^}\n/ms', (string) file_get_contents( $file ), $m ) ) {
        fwrite( STDERR, "FAIL cannot find $fn in $file\n" );
        exit( 2 );
    }
    return $m[0];
}
function load_fn( string $file, string $fn ): void {
    if ( ! function_exists( $fn ) ) { eval( fn_source( $file, $fn ) ); }
}

foreach ( [ 'nppp_gen_mode_note_seen', 'nppp_gen_mode_seen', 'nppp_gen_mode_active', 'nppp_adv_encode_raw_bytes' ] as $fn ) {
    load_fn( "$INC/advanced.php", $fn );
}
foreach ( [ 'nppp_purge_canon_key', 'nppp_purge_key_has_spellings', 'nppp_purge_canon_map', 'nppp_purge_key_rg_alt',
            'nppp_purge_fp1_index_file_exists', 'nppp_purge_live_index_paths', 'nppp_purge_fp1_http',
            'nppp_purge_fp4_scan', 'nppp_purge_flush_write_back', 'nppp_purge_post_purge' ] as $fn ) {
    load_fn( "$INC/purge.php", $fn );
}
foreach ( [ 'nppp_head_fast', 'nppp_read_head' ] as $fn ) { load_fn( "$INC/wp-filesystem.php", $fn ); }

$RX = ( include "$INC/preload-defaults.php" )['cache_key_regex'];
function nppp_fetch_default_regex_for_cache_key(): string { global $RX; return $RX; }   // FP3 only prefilters for the built-in regex
function nppp_get_cache_key_regex(): string { global $RX; return $RX; }

// FP3, with its one rg exec() wrapped so the scan itself is timed apart from the PHP work around it.
$fp3 = fn_source( "$INC/purge.php", 'nppp_purge_fp3_rg' );
$needle = 'exec( $cmd, $out, $exit );';
$INSTRUMENTED = substr_count( $fp3, $needle ) === 1;
if ( $INSTRUMENTED ) {
    $fp3 = str_replace( $needle,
        '$__t = hrtime( true ); exec( $cmd, $out, $exit ); $GLOBALS["B"]["rg"] = [ "exec_ms" => ( hrtime( true ) - $__t ) / 1e6, '
        . '"exit" => $exit, "lines" => count( $out ), "cmd" => $cmd ];', $fp3 );
}
eval( $fp3 );

/* ------------------------------------------------------------------ context, as nppp_purge_single_init() builds it */
$targets = [];
$first   = true;
foreach ( $URLS as $u ) {
    $targets[ preg_replace( '#^https?://#', '', $u ) ] = [ 'original' => $u, 'decoded' => rawurldecode( $u ), 'is_primary' => $first ];
    $first = false;
}
$ctx = [
    'wp_filesystem'     => new DirectFs(),
    'nppp_index'        => [],                 // FP1 reads it only to detect multi-variant URLs
    'nginx_cache_path'  => $CACHE,
    'primary_url'       => $URLS[0],
    'primary_decoded'   => rawurldecode( $URLS[0] ),
    'chain_autopreload' => false,
    'nppp_auto_purge'   => false,
    'regex'             => $RX,
    'head_bytes'        => 4096,
    'head_bytes_fb'     => 32768,
    'settings'          => get_option( 'nginx_cache_settings' ),
    'targets'           => $targets,
    'pending'           => $targets,
    'write_back'        => [],
    'primary_purged'    => false,
];

/* ------------------------------------------------------------------ the measured call */
$boot_ms = ( hrtime( true ) - $BOOT0 ) / 1e6;
$mem0    = memory_get_peak_usage( true );
$t0      = hrtime( true );
switch ( $METHOD ) {
    case 'http': $result = nppp_purge_fp1_http( $ctx ); break;
    case 'rg':   $result = nppp_purge_fp3_rg( $ctx );   break;
    default:     $result = nppp_purge_fp4_scan( $ctx ); break;
}
$t1       = hrtime( true );
$layer_ms = ( $t1 - $t0 ) / 1e6;

$out = [
    'method'         => $METHOD,
    'n_targets'      => count( $URLS ),
    'layer_ms'       => round( $layer_ms, 3 ),
    't0_ns'          => $t0,          // CLOCK_MONOTONIC, same clock as Python's time.perf_counter(): lets the harness cut the stall window
    't1_ns'          => $t1,
    'result'         => $result,
    'pending_left'   => count( $ctx['pending'] ),
    'primary_purged' => (bool) $ctx['primary_purged'],
    'boot_ms'        => round( $boot_ms, 2 ),
    'peak_mem_kb'    => (int) ( max( memory_get_peak_usage( true ), $mem0 ) / 1024 ),
    'php'            => PHP_VERSION,
    'notices'        => array_map( static fn( $n ) => substr( $n, 0, 220 ), array_slice( $B['notices'], 0, 6 ) ),
];
if ( $METHOD === 'http' ) {
    $out['http_requests'] = count( $B['http'] );
    $out['http_ms']       = round( array_sum( array_column( $B['http'], 'ms' ) ), 3 );
    $out['http_detail']   = $B['http'];
    $out['non_http_ms']   = round( $layer_ms - $out['http_ms'], 3 );
}
if ( $METHOD === 'rg' ) {
    $out['instrumented'] = $INSTRUMENTED;
    if ( $INSTRUMENTED && $B['rg'] ) {
        $out['rg_exec_ms']   = round( $B['rg']['exec_ms'], 3 );
        $out['rg_exit']      = $B['rg']['exit'];
        $out['rg_hits']      = $B['rg']['lines'];
        $out['rg_cmd']       = substr( $B['rg']['cmd'], 0, 400 );
        $out['non_rg_ms']    = round( $layer_ms - $B['rg']['exec_ms'], 3 );   // command -v rg, parse, unlink, write-back
    }
}
echo json_encode( $out, JSON_UNESCAPED_SLASHES ), "\n";
