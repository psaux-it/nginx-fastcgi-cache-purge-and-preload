<?php
/**
 * Multi-spelling key tests: one page cached under several file names (%d9 / %D9 / raw UTF-8).
 *
 * Usage: php spelling-test.php [-v] path/to/includes/purge-http.php
 *
 * Background (16c0031d, 8efc87fb). nginx keys the cache on the raw $request_uri, so ONE page can be cached under
 * several KEY lines: WordPress permalinks use lower-case hex (%d9), WordPress search links and wget (the plugin's own
 * preload) use upper-case hex (%D9), and some clients send raw UTF-8 bytes. Before the fix FP2/FP3/FP4 matched the key
 * byte-for-byte: the other spellings stayed cached (silent stale) and a raw UTF-8 key produced a false "ERROR REGEX".
 * Every spelling now resolves through one canonical form (nppp_purge_canon_key()).
 *
 * What is covered (real plugin functions, loaded by extraction because the files need WordPress; real files on disk,
 * real ripgrep for FP3, which is skipped with a note when `rg` >= 14 is missing):
 *   1. nppp_purge_canon_key()          table + differential test against the two-pass reference it replaced
 *   2. nppp_purge_key_has_spellings()  which keys can exist under more than one spelling
 *   3. nppp_purge_canon_map()          canonical key -> pending key
 *   4. nppp_purge_key_rg_alt()         the ripgrep alternative, run through real rg (literal dots stay literal, ...)
 *   5. nppp_adv_encode_raw_bytes()     raw bytes >= 0x80 -> %XX, escape hex case untouched
 *   6. FP2 (index)                     multi-spelling keys are left to the scan layers; filter restores the old behaviour
 *   7. FP3 (ripgrep)                   every spelling deleted, decoys kept, rg command keeps its literal prefilter
 *   8. FP4 (PHP scan)                  same scenarios as FP3 (they are twins and must agree)
 *
 * Quiet like the other harness scripts (only FAIL lines and the summary); -v or SPELLING_VERBOSE=1 lists every check.
 * Real nginx is covered by e2e.php (scenario spell). Exit code 0 = all passed, 1 = a check failed, 2 = bad usage.
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

$VERBOSE = getenv( 'SPELLING_VERBOSE' ) === '1';
$ARGS    = [];
foreach ( array_slice( $argv, 1 ) as $a ) {
    if ( $a === '-v' ) { $VERBOSE = true; } else { $ARGS[] = $a; }
}

$GLOBALS['T'] = [];
function t_reset( array $settings = [] ) {
    $GLOBALS['T'] = [
        'options'    => [ 'nginx_cache_settings' => array_merge( [ 'nppp_http_purge_enabled' => 'yes' ], $settings ) ],
        'transients' => [], 'notices' => [], 'filters' => [], 'filter_calls' => [], 'actions' => [],
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
function __( $s, $d = '' ) { return $s; }
function _n( $a, $b, $n, $d = '' ) { return $n === 1 ? $a : $b; }
function url_to_postid( $u ) { return 0; }
function apply_filters( $tag, $v, ...$a ) {
    $GLOBALS['T']['filter_calls'][] = [ $tag, $v, $a ];
    $f = $GLOBALS['T']['filters'][ $tag ] ?? null;
    return $f ? $f( $v, ...$a ) : $v;
}
function do_action( $tag, ...$a ) { $GLOBALS['T']['actions'][] = [ $tag, $a ]; }
function add_action( $tag, $cb, $p = 10, $n = 1 ) {}
function remove_action( $tag, $cb, $p = 10 ) {}
function get_transient( $k ) { return $GLOBALS['T']['transients'][ $k ] ?? false; }
function set_transient( $k, $v, $t ) { $GLOBALS['T']['transients'][ $k ] = $v; return true; }
function delete_transient( $k ) { unset( $GLOBALS['T']['transients'][ $k ] ); return true; }
function is_wp_error( $x ) { return $x instanceof WP_Error; }
function wp_cache_delete( $k, $g = '' ) { return true; }
function nppp_display_admin_notice( $type, $msg, $log = true, $show = true ) { $GLOBALS['T']['notices'][] = [ $type, $msg, $log, $show ]; }
function notice_has( string $needle, ?string $type = null ): bool {
    foreach ( $GLOBALS['T']['notices'] as $n ) {
        if ( strpos( $n[1], $needle ) !== false && ( $type === null || $n[0] === $type ) ) { return true; }
    }
    return false;
}

// Environment the rg path calls (same stand-ins as gen-scan.php)
function nppp_prepare_request_env() {}
function nppp_check_rg_version() { return '14.1.0'; }
function nppp_fuse_source_path( $p ) { return null; }

$PASS = 0; $FAIL = 0;
function say( string $line ): void { global $VERBOSE; if ( $VERBOSE ) { echo $line; } }
function ok( $cond, $name, $detail = '' ) {
    global $PASS, $FAIL;
    if ( $cond ) { $PASS++; say( "  ok   $name\n" ); } else { $FAIL++; echo "FAIL: $name $detail\n"; }
}
function is_( $got, $want, $name ) { ok( $got === $want, $name, '-- got ' . json_encode( $got ) . ' want ' . json_encode( $want ) ); }

$PLUGIN = realpath( $ARGS[0] ?? '' ) or exit( "usage: php spelling-test.php [-v] path/to/includes/purge-http.php\n" );
$INC    = dirname( $PLUGIN );
foreach ( [ 'purge.php', 'advanced.php', 'wp-filesystem.php', 'state.php', 'preload-defaults.php' ] as $need ) {
    if ( ! is_file( "$INC/$need" ) ) { fwrite( STDERR, "FAIL $need not found next to $PLUGIN\n" ); exit( 2 ); }
}
require $PLUGIN;
require "$INC/state.php";

/** Extract one top-level function from a plugin file and define it (the files need WordPress, so they are not included). */
function load_fn( string $file, string $fn ): void {
    if ( function_exists( $fn ) ) { return; }
    if ( ! preg_match( '/^function ' . $fn . '\(.*?^}\n/ms', (string) file_get_contents( $file ), $m ) ) {
        fwrite( STDERR, "FAIL cannot find $fn in $file\n" ); exit( 2 );
    }
    eval( $m[0] );
}
foreach ( [ 'nppp_adv_encode_raw_bytes', 'nppp_gen_mode_note_seen', 'nppp_gen_mode_seen', 'nppp_gen_mode_active' ] as $fn ) { load_fn( "$INC/advanced.php", $fn ); }
foreach ( [ 'nppp_purge_canon_key', 'nppp_purge_key_has_spellings', 'nppp_purge_canon_map', 'nppp_purge_key_rg_alt',
            'nppp_purge_fp2_index', 'nppp_purge_fp3_rg', 'nppp_purge_fp4_scan', 'nppp_purge_flush_write_back', 'nppp_purge_post_purge' ] as $fn ) { load_fn( "$INC/purge.php", $fn ); }
foreach ( [ 'nppp_head_fast', 'nppp_read_head' ] as $fn ) { load_fn( "$INC/wp-filesystem.php", $fn ); }

$RX = ( include "$INC/preload-defaults.php" )['cache_key_regex'];
function nppp_fetch_default_regex_for_cache_key(): string { global $RX; return $RX; }   // FP3 only prefilters for the built-in regex

$RG    = trim( (string) shell_exec( 'command -v rg 2>/dev/null' ) );
$PATH0 = getenv( 'PATH' );
if ( $RG === '' ) { echo "NOTE: rg not found, ripgrep checks skipped\n"; }

/* ------------------------------------------------------------------ fixtures */
class FakeFs {
    public function exists( $p ) { return file_exists( $p ); }
    public function is_dir( $p ) { return is_dir( $p ); }
    public function get_contents( $p ) { return @file_get_contents( $p ); }
    public function delete( $p, $r = false ) { return @unlink( $p ); }
}
function rmrf( string $d ): void { exec( 'rm -rf ' . escapeshellarg( $d ) ); }

$SEQ = 0;
/** A fresh cache directory. */
function newcache(): string {
    $d = sys_get_temp_dir() . '/nppp-sp-' . getmypid() . '-' . mt_rand();
    mkdir( $d . '/a/b', 0777, true );
    return $d;
}
/** A cache file as nginx writes it: binary header, then the KEY line, then the response. Returns its path. */
function put( string $dir, string $key, string $body = '<html>x</html>' ): string {
    global $SEQ;
    $f = $dir . '/a/b/' . md5( ( $SEQ++ ) . $key );
    file_put_contents( $f, "\x05\x00\x01hdr\nKEY: $key\n\r\nHTTP/1.1 200 OK\r\nContent-Type: text/html\r\n\r\n$body" );
    return $f;
}
/** Purge context as nppp_purge_single_init() builds it. $urls[0] is the primary target. */
function mkctx( string $dir, array $urls, array $index = [], array $settings = [] ): array {
    global $RX;
    $targets = []; $first = true;
    foreach ( $urls as $u ) {
        $targets[ preg_replace( '#^https?://#', '', $u ) ] = [ 'original' => $u, 'decoded' => rawurldecode( $u ), 'is_primary' => $first ];
        $first = false;
    }
    return [
        'wp_filesystem' => new FakeFs(), 'nppp_index' => $index, 'nginx_cache_path' => $dir,
        'primary_url' => $urls[0], 'primary_decoded' => rawurldecode( $urls[0] ),
        'chain_autopreload' => false, 'nppp_auto_purge' => false, 'regex' => $RX,
        'head_bytes' => 4096, 'head_bytes_fb' => 32768,
        'settings' => $settings + get_option( 'nginx_cache_settings' ),
        'targets' => $targets, 'pending' => $targets, 'write_back' => [], 'primary_purged' => false,
    ];
}
/** Which of $files still exist. */
function left( array $files ): array { return array_keys( array_filter( $files, 'file_exists' ) ); }

/* ------------------------------------------------------------------ 1. nppp_purge_canon_key */
say( "1. canonical key\n" );
$canon = [
    'plain ASCII key is returned as is'            => [ 'example.com/p/1/',               'example.com/p/1/' ],
    'lower-case hex is upper-cased'                => [ 'example.com/p/%d9%8a/',          'example.com/p/%D9%8A/' ],
    'upper-case hex stays'                         => [ 'example.com/p/%D9%8A/',          'example.com/p/%D9%8A/' ],
    'mixed case within one escape'                 => [ 'example.com/p/%d9%8A/',          'example.com/p/%D9%8A/' ],
    'raw UTF-8 bytes become %XX'                   => [ "example.com/p/\xD9\x8A/",        'example.com/p/%D9%8A/' ],
    'raw byte next to an escape'                   => [ "example.com/p/\xD9%8a/",         'example.com/p/%D9%8A/' ],
    'digit-only escapes are untouched'             => [ 'example.com/p/%20%41/',          'example.com/p/%20%41/' ],
    'a hex letter in an ASCII escape is upper-cased' => [ 'example.com/p/%2f%7e/',        'example.com/p/%2F%7E/' ],
    'letters outside an escape keep their case'    => [ 'example.com/Foo/bar-Baz/',       'example.com/Foo/bar-Baz/' ],
    'query escapes are canonicalised too'          => [ 'example.com/s/?q=%d9%8a&x=a',    'example.com/s/?q=%D9%8A&x=a' ],
    'a lone percent sign is not an escape'         => [ 'example.com/100%/a%zz/b%4',      'example.com/100%/a%zz/b%4' ],
    'a hex pair that is not preceded by %'         => [ 'example.com/d9/8a/',             'example.com/d9/8a/' ],
    'a byte >= 0x80 that is not valid UTF-8'       => [ "example.com/\xFF/",              'example.com/%FF/' ],
    'empty key'                                    => [ '',                               '' ],
];
foreach ( $canon as $name => [ $in, $want ] ) {
    is_( nppp_purge_canon_key( $in ), $want, "canon: $name" );
    is_( nppp_purge_canon_key( $want ), $want, "canon: idempotent ($name)" );
}
$all = [ 'example.com/p/%d9%8a/', 'example.com/p/%D9%8A/', 'example.com/p/%D9%8a/', 'example.com/p/%d9%8A/', "example.com/p/\xD9\x8A/", "example.com/p/\xD9%8a/", "example.com/p/%d9\x8A/" ];
is_( count( array_unique( array_map( 'nppp_purge_canon_key', $all ) ) ), 1, 'canon: all seven spellings of one page resolve to ONE key' );

/** The two-pass implementation the strtr() table replaced (8efc87fb): the table must give the same answer for any key. */
function ref_canon( string $key ): string {
    $key = preg_replace_callback( '/[\x80-\xFF]/', static fn( array $m ): string => sprintf( '%%%02X', ord( $m[0] ) ), $key );
    return preg_replace_callback( '/%[0-9a-f]{2}/i', static fn( array $m ): string => strtoupper( $m[0] ), (string) $key );
}
mt_srand( 20261028 );
$alpha = [ '%', '%', 'a', 'A', 'd', 'D', '9', '8', 'f', 'F', '0', '2', '/', 'p', 'x', 'z', '?', '=', '&', "\x80", "\xD9", "\xFF", "\x8A", '.', 'g' ];
$bad   = 0; $shown = 0;
for ( $i = 0; $i < 6000; $i++ ) {
    $s = ''; $n = mt_rand( 0, 16 );
    for ( $j = 0; $j < $n; $j++ ) { $s .= $alpha[ mt_rand( 0, count( $alpha ) - 1 ) ]; }
    if ( nppp_purge_canon_key( $s ) !== ref_canon( $s ) ) { $bad++; if ( $shown++ < 3 ) { echo 'FAIL: canon differs from the reference for ' . json_encode( $s, JSON_INVALID_UTF8_SUBSTITUTE ) . ' ' . bin2hex( $s ) . "\n"; } }
}
ok( $bad === 0, 'canon: 6000 random keys equal the two-pass reference', "bad=$bad" );

/* ------------------------------------------------------------------ 2. nppp_purge_key_has_spellings */
say( "2. which keys have several spellings\n" );
$has = [
    '/p/%d9%8a/' => true,  '/p/%D9%8A/' => true,  '/p/%80/' => true,   '/p/%e9/' => true,   '/p/%a1/' => true,
    '/p/%2f/'    => true,  '/p/%2F/'    => true,  '/p/%7e/' => true,   '/s/?q=%d9%8a' => true,
    '/p/%7B/'    => true,  '/p/%41/'    => false, '/p/%20/' => false,  '/p/%30/' => false,  '/p/%25/' => false,
    '/p/%2/'     => false, '/p/%zz/'    => false, '/p/100%/' => false, '/plain/' => false, '/p/d9/' => false, '' => false,
];
foreach ( $has as $k => $want ) { is_( nppp_purge_key_has_spellings( 'example.com' . $k ), $want, "has_spellings: example.com$k" ); }

/* ------------------------------------------------------------------ 3. nppp_purge_canon_map */
say( "3. canonical map\n" );
$m = nppp_purge_canon_map( [ 'example.com/p/%d9%8a/' => [], 'example.com/q/1/' => [], 'example.com/p/%D9%8A/' => [] ] );
is_( $m, [ 'example.com/p/%D9%8A/' => 'example.com/p/%d9%8a/', 'example.com/q/1/' => 'example.com/q/1/' ], 'canon_map: canonical => FIRST pending key; two spellings of one page collapse' );
is_( nppp_purge_canon_map( [] ), [], 'canon_map: nothing pending' );

/* ------------------------------------------------------------------ 4. nppp_purge_key_rg_alt through real rg */
say( "4. ripgrep alternative\n" );
if ( $RG !== '' ) {
    /** Lines (KEY line payloads) that the FP3 pattern for $key matches, exactly as FP3 assembles and runs it. */
    $rg_match = function ( string $key, array $lines ) use ( $RG ): array {
        $f = tempnam( sys_get_temp_dir(), 'nppp-rg' );
        file_put_contents( $f, implode( "\n", array_map( fn( $l ) => "KEY: $l", $lines ) ) . "\n" );
        $pat = 'KEY: .*(' . nppp_purge_key_rg_alt( $key ) . '(?:[[:space:]]|$))';
        $out = []; exec( escapeshellarg( $RG ) . ' --text -E none --no-unicode --no-messages --no-ignore --no-config --no-heading --no-filename --no-line-number ' . escapeshellarg( $pat ) . ' ' . escapeshellarg( $f ) . ' 2>/dev/null', $out );
        unlink( $f );
        return array_map( fn( $l ) => substr( $l, 5 ), $out );
    };
    $k    = 'example.com/p/%d9%8a/';
    $want = [ 'httpGETexample.com/p/%d9%8a/', 'httpGETexample.com/p/%D9%8A/', 'httpGETexample.com/p/%D9%8a/', 'httpGETexample.com/p/%d9%8A/',
              "httpGETexample.com/p/\xD9\x8A/", "httpGETexample.com/p/\xD9%8a/", "httpGETexample.com/p/%d9\x8A/" ];
    is_( $rg_match( $k, $want ), $want, 'rg_alt: every spelling of the key matches (hex case and raw bytes)' );
    $no = [ 'httpGETexample.com/p/%d9%8b/', 'httpGETexample.com/p/%d9%8a/extra/', 'httpGETexample.com/p/%d9%8a', 'httpGETexample.com/p/%d9%8ax/', "httpGETexample.com/p/\xD9\x8B/", 'httpGETexample.com/p/ك/' ];
    is_( $rg_match( $k, $no ), [], 'rg_alt: a different page, a longer path and a missing trailing slash do not match' );
    is_( $rg_match( 'example.com/a.b/c+d/?x=(1)&y=2', [ 'httpGETexample.com/a.b/c+d/?x=(1)&y=2' ] ), [ 'httpGETexample.com/a.b/c+d/?x=(1)&y=2' ], 'rg_alt: regex metacharacters in the key are literal (match itself)' );
    is_( $rg_match( 'example.com/a.b/c+d/?x=(1)&y=2', [ 'httpGETexample.com/aXb/c+d/?x=(1)&y=2', 'httpGETexample.com/a.b/cc/?x=(1)&y=2', 'httpGETexample.com/a.b/c+d/?x=1&y=2' ] ), [], 'rg_alt: "." "+" "(" are not regex operators' );
    is_( $rg_match( 'example.com/p/%41/', [ 'httpGETexample.com/p/%41/', 'httpGETexample.com/p/A/' ] ), [ 'httpGETexample.com/p/%41/' ], 'rg_alt: a digit-only ASCII escape has ONE spelling (%41 is not A)' );
    is_( $rg_match( 'example.com/s/?q=%d9%8a', [ 'httpGETexample.com/s/?q=%D9%8A', "httpGETexample.com/s/?q=\xD9\x8A", 'httpGETexample.com/s/?q=%d9%8a&page=2' ] ),
         [ 'httpGETexample.com/s/?q=%D9%8A', "httpGETexample.com/s/?q=\xD9\x8A" ], 'rg_alt: query escapes follow the same rule' );
    $p4 = 'p4:objects:1760000001:0000000000000001:httpGETexample.com/p/%D9%8A/';
    is_( $rg_match( $k, [ $p4 ] ), [ $p4 ], 'rg_alt: a generation (p4) key is matched through the same alternative' );
}

/* ------------------------------------------------------------------ 5. nppp_adv_encode_raw_bytes */
say( "5. Advanced list encoder\n" );
is_( nppp_adv_encode_raw_bytes( "/p/\xD9\x8A/" ), '/p/%D9%8A/', 'encode: raw UTF-8 -> %XX (upper-case)' );
is_( nppp_adv_encode_raw_bytes( '/p/%d9%8a/' ), '/p/%d9%8a/', 'encode: existing escapes keep their hex case' );
is_( nppp_adv_encode_raw_bytes( "/p/\xD9%8a/" ), '/p/%D9%8a/', 'encode: raw byte next to an escape: only the raw byte changes' );
is_( nppp_adv_encode_raw_bytes( '/plain/?a=b' ), '/plain/?a=b', 'encode: ASCII is untouched' );
is_( nppp_adv_encode_raw_bytes( "\xFF" ), '%FF', 'encode: a byte that is not valid UTF-8 is still encoded (never null)' );
is_( nppp_adv_encode_raw_bytes( '' ), '', 'encode: empty string' );
ok( filter_var( 'https://example.com' . nppp_adv_encode_raw_bytes( "/p/\xD9\x8A/" ), FILTER_VALIDATE_URL ) !== false, 'encode: the result passes FILTER_VALIDATE_URL (the raw form does not)' );
ok( filter_var( "https://example.com/p/\xD9\x8A/", FILTER_VALIDATE_URL ) === false, 'encode: precondition - FILTER_VALIDATE_URL rejects the raw form' );

/* ------------------------------------------------------------------ 6. FP2 (index) */
say( "6. FP2\n" );
$K   = 'example.com/p/%d9%8a/';
$U   = 'https://example.com/p/%d9%8a/';
$cd  = newcache();
$fa  = put( $cd, "httpGET$K" );
t_reset();
$ctx = mkctx( $cd, [ $U ], [ $K => [ $fa ] ] );
$r   = nppp_purge_fp2_index( $ctx );
is_( $r, false, 'FP2: a key with spellings is NOT settled by the index (returns false)' );
ok( file_exists( $fa ), 'FP2: the indexed file is left for the scan layers' );
ok( isset( $ctx['pending'][ $K ] ), 'FP2: the key stays pending' );
ok( notice_has( 'INFO INDEX SKIPPED', 'info' ) && ! notice_has( 'INDEX ABSENT' ) && ! notice_has( '(INDEX)' ), 'FP2: primary target gets the INDEX SKIPPED notice, no absent / success notice' );
$calls = array_filter( $GLOBALS['T']['filter_calls'], fn( $c ) => $c[0] === 'nppp_fp2_skip_spelling_keys' );
is_( array_values( array_map( fn( $c ) => [ $c[1], $c[2] ], $calls ) ), [ [ true, [ $K ] ] ], 'FP2: filter nppp_fp2_skip_spelling_keys( true, $key ) is asked once, with the pending key' );

t_reset(); t_filter( 'nppp_fp2_skip_spelling_keys', fn( $skip, $key ) => false );
$ctx = mkctx( $cd, [ $U ], [ $K => [ $fa ] ] );
$r   = nppp_purge_fp2_index( $ctx );
is_( $r, true, 'FP2: filter returns false -> index trust restored, key settled' );
ok( ! file_exists( $fa ), 'FP2: filter returns false -> the indexed file is deleted (old behaviour)' );
ok( notice_has( 'SUCCESS ADMIN: Nginx cache purged for page', 'success' ) && notice_has( '(INDEX)' ), 'FP2: filter returns false -> success (INDEX) notice' );
rmrf( $cd );

// keys with ONE spelling keep the index shortcut
foreach ( [ 'plain ASCII' => 'p/1/', 'digit-only escape' => 'p/%41/', 'space escape' => 'p/a%20b/' ] as $label => $path ) {
    $cd = newcache(); $fa = put( $cd, "httpGETexample.com/$path" );
    t_reset();
    $ctx = mkctx( $cd, [ "https://example.com/$path" ], [ "example.com/$path" => [ $fa ] ] );
    is_( nppp_purge_fp2_index( $ctx ), true, "FP2: $label key still takes the index shortcut" );
    ok( ! file_exists( $fa ) && ! notice_has( 'INDEX SKIPPED' ), "FP2: $label key: file deleted from the index, no skip notice" );
    rmrf( $cd );
}

// a related (non-primary) target is skipped silently; the primary is still settled
$cd = newcache(); $fp = put( $cd, 'httpGETexample.com/p/1/' ); $fr = put( $cd, 'httpGETexample.com/r/%d9%8a/' );
t_reset();
$ctx = mkctx( $cd, [ 'https://example.com/p/1/', 'https://example.com/r/%d9%8a/' ], [ 'example.com/p/1/' => [ $fp ], 'example.com/r/%d9%8a/' => [ $fr ] ] );
$r   = nppp_purge_fp2_index( $ctx );
is_( $r, false, 'FP2: a related target with spellings stays pending -> FP2 does not finish' );
ok( ! file_exists( $fp ) && file_exists( $fr ), 'FP2: the plain primary is purged from the index, the spelled related file is left for the scan' );
is_( array_keys( $ctx['pending'] ), [ 'example.com/r/%d9%8a/' ], 'FP2: only the spelled related key is still pending' );
ok( ! notice_has( 'INDEX SKIPPED' ), 'FP2: a related target gets no INDEX SKIPPED notice (primary only)' );
rmrf( $cd );

// the skip also wins over "index has nothing": no ABSENT notice, same outcome
$cd = newcache();
t_reset();
$ctx = mkctx( $cd, [ $U ], [] );
is_( nppp_purge_fp2_index( $ctx ), false, 'FP2: spelled key, empty index -> false' );
ok( notice_has( 'INDEX SKIPPED' ) && ! notice_has( 'INDEX ABSENT' ), 'FP2: spelled key, empty index -> SKIPPED (not ABSENT)' );
rmrf( $cd );

/* ------------------------------------------------------------------ 7./8. FP3 and FP4 on real files */
/** Cache fixtures for one logical page '/p/%d9%8a/': every spelling, a p4 file, and decoys that must survive. */
function build_page_cache(): array {
    $d = newcache();
    $spelled = [
        'lower'  => put( $d, 'httpGETexample.com/p/%d9%8a/' ),
        'upper'  => put( $d, 'httpGETexample.com/p/%D9%8A/' ),
        'mixed'  => put( $d, 'httpGETexample.com/p/%D9%8a/' ),
        'mixed2' => put( $d, 'httpGETexample.com/p/%d9%8A/' ),
        'raw'    => put( $d, "httpGETexample.com/p/\xD9\x8A/" ),
        'rawesc' => put( $d, "httpGETexample.com/p/\xD9%8a/" ),
        'p4'     => put( $d, 'p4:objects:1760000001:0000000000000001:httpGETexample.com/p/%D9%8A/' ),
        'https'  => put( $d, 'httpsGETexample.com/p/%D9%8A/' ),
    ];
    $decoy = [
        'other page'      => put( $d, 'httpGETexample.com/p/%d9%8b/' ),
        'longer path'     => put( $d, 'httpGETexample.com/p/%d9%8a/extra/' ),
        'other host'      => put( $d, 'httpGETwww.example.com/p/%d9%8a/' ),   // rg matches the tail (".*" prefix), PHP must reject the host
        'host suffix'     => put( $d, 'httpGETxexample.com/p/%D9%8A/' ),
        'ascii digits'    => put( $d, 'httpGETexample.com/p/%41/' ),
        'in page body'    => put( $d, 'httpGETexample.com/other/', '<pre>see KEY: httpGETexample.com/p/%d9%8a/</pre>' ),   // mid-line "KEY:" (8efc87fb)
    ];
    return [ $d, $spelled, $decoy ];
}

/** One scan layer, same scenarios. $layer = 'fp3' | 'fp4'. */
function scan_scenarios( string $layer ): void {
    global $RG, $PATH0;
    $fn   = $layer === 'fp3' ? 'nppp_purge_fp3_rg' : 'nppp_purge_fp4_scan';
    $tag  = $layer === 'fp3' ? '(RG)' : '(SCAN)';
    $sets = $layer === 'fp3' ? [ 'nppp_rg_purge_enabled' => 'yes' ] : [];
    $run  = function ( array &$ctx ) use ( $fn ): string { return $fn( $ctx ); };

    // A. every spelling is deleted, every decoy kept
    [ $d, $spelled, $decoy ] = build_page_cache();
    t_reset();
    $ctx = mkctx( $d, [ 'https://example.com/p/%d9%8a/' ], [], $sets );
    $r   = $run( $ctx );
    is_( $r, 'done', "$layer: scan finishes" );
    is_( left( $spelled ), [], "$layer: all " . count( $spelled ) . ' spellings of the page are deleted (lower, upper, mixed, raw, raw+escape, p4, https)' );
    is_( left( $decoy ), array_keys( $decoy ), "$layer: decoys survive (other page, longer path, other host, host suffix, %41, \"KEY:\" inside a body)" );
    ok( $ctx['primary_purged'] && $ctx['pending'] === [], "$layer: primary marked purged, nothing pending" );
    ok( notice_has( 'SUCCESS ADMIN: Nginx cache purged for page ' . rawurldecode( 'https://example.com/p/%d9%8a/' ) . " $tag", 'success' ), "$layer: success notice $tag" );
    ok( ! notice_has( 'ERROR' ), "$layer: no error notice" );
    rmrf( $d );

    // B. the target itself is spelled upper-case (a WordPress search link): same result
    [ $d, $spelled, $decoy ] = build_page_cache();
    t_reset();
    $ctx = mkctx( $d, [ 'https://example.com/p/%D9%8A/' ], [], $sets );
    $run( $ctx );
    is_( left( $spelled ), [], "$layer: an upper-case target purges every spelling too" );
    is_( left( $decoy ), array_keys( $decoy ), "$layer: upper-case target: decoys survive" );
    rmrf( $d );

    // C. only a raw UTF-8 key on disk: it is purged and does NOT raise the false ERROR REGEX
    $d = newcache(); $raw = put( $d, "httpGETexample.com/p/\xD9\x8A/" );
    t_reset();
    $ctx = mkctx( $d, [ 'https://example.com/p/%d9%8a/' ], [], $sets );
    $run( $ctx );
    ok( ! file_exists( $raw ), "$layer: a lone raw UTF-8 key is purged" );
    ok( ! notice_has( 'ERROR REGEX' ), "$layer: a raw UTF-8 key is not a false ERROR REGEX" );
    rmrf( $d );

    // D. nothing cached for the page: INFO, decoys untouched
    $d = newcache(); $o = put( $d, 'httpGETexample.com/p/%d9%8b/' );
    t_reset();
    $ctx = mkctx( $d, [ 'https://example.com/p/%d9%8a/' ], [], $sets );
    $run( $ctx );
    ok( file_exists( $o ) && ! $ctx['primary_purged'], "$layer: page not cached: nothing deleted" );
    ok( notice_has( 'is not currently found in the cache', 'info' ), "$layer: page not cached: INFO notice" );
    rmrf( $d );

    // E. query string with escapes (WordPress search links use upper-case hex)
    $d = newcache();
    $q = [ put( $d, 'httpGETexample.com/s/?q=%d9%8a' ), put( $d, 'httpGETexample.com/s/?q=%D9%8A' ), put( $d, "httpGETexample.com/s/?q=\xD9\x8A" ) ];
    $qd = [ put( $d, 'httpGETexample.com/s/?q=%d9%8a&page=2' ), put( $d, 'httpGETexample.com/s/?q=%d9%8b' ) ];
    t_reset();
    $ctx = mkctx( $d, [ 'https://example.com/s/?q=%d9%8a' ], [], $sets );
    $run( $ctx );
    is_( left( $q ), [], "$layer: query escapes: every spelling deleted" );
    is_( count( left( $qd ) ), 2, "$layer: query escapes: other queries survive" );
    rmrf( $d );

    // F. primary + related with different escapes: each pending key gets its own spellings
    $d = newcache();
    $a = [ put( $d, 'httpGETexample.com/p/%d9%8a/' ), put( $d, 'httpGETexample.com/p/%D9%8A/' ) ];
    $b = [ put( $d, 'httpGETexample.com/r/%d8%a7/' ), put( $d, 'httpGETexample.com/r/%D8%A7/' ), put( $d, "httpGETexample.com/r/\xD8\xA7/" ) ];
    $c = [ put( $d, 'httpGETexample.com/keep/%d8%a7/' ) ];
    t_reset();
    $ctx = mkctx( $d, [ 'https://example.com/p/%d9%8a/', 'https://example.com/r/%d8%a7/' ], [], $sets );
    $run( $ctx );
    is_( [ left( $a ), left( $b ), count( left( $c ) ) ], [ [], [], 1 ], "$layer: primary and related are both purged in one pass, an unrelated page is kept" );
    ok( notice_has( 'related page ' . rawurldecode( 'https://example.com/r/%d8%a7/' ) . " $tag" ), "$layer: related target gets its own success notice" );
    rmrf( $d );

    // G. two pending keys that are the SAME page (lower + upper spelling): one scan, every file gone, no crash
    $d = newcache();
    $g = [ put( $d, 'httpGETexample.com/p/%d9%8a/' ), put( $d, 'httpGETexample.com/p/%D9%8A/' ) ];
    t_reset();
    $ctx = mkctx( $d, [ 'https://example.com/p/%d9%8a/', 'https://example.com/p/%D9%8A/' ], [], $sets );
    $r   = $run( $ctx );
    is_( [ $r, left( $g ), $ctx['primary_purged'] ], [ 'done', [], true ], "$layer: two spellings of one page pending at once: all files deleted, primary purged" );
    rmrf( $d );

    // H. ASCII regression: the normal page, plus the host-suffix decoy
    $d = newcache();
    $h = put( $d, 'httpGETexample.com/plain/' ); $hd = [ put( $d, 'httpGETxexample.com/plain/' ), put( $d, 'httpGETexample.com/plain/more/' ) ];
    t_reset();
    $ctx = mkctx( $d, [ 'https://example.com/plain/' ], [], $sets );
    $run( $ctx );
    ok( ! file_exists( $h ) && count( left( $hd ) ) === 2, "$layer: ASCII page: purged, look-alikes kept" );
    rmrf( $d );
}

say( "7. FP3 (ripgrep)\n" );
if ( $RG !== '' ) {
    scan_scenarios( 'fp3' );

    // The rg command: the pattern must not start with "^" (it makes rg lose its literal prefilter, ~6x slower on a
    // 10,000 file cache: 8efc87fb), and the flags the scan relies on stay. A shim in front of rg records its argv.
    $shim = sys_get_temp_dir() . '/nppp-shim-' . getmypid();
    mkdir( $shim );
    file_put_contents( "$shim/rg", "#!/bin/sh\nfor a in \"\$@\"; do printf '%s\\n' \"\$a\"; done > " . escapeshellarg( "$shim/argv" ) . "\nexec " . escapeshellarg( $RG ) . " \"\$@\"\n" );
    chmod( "$shim/rg", 0755 );
    [ $d, $spelled, $decoy ] = build_page_cache();
    t_reset();
    putenv( 'PATH=' . $shim . ':' . $PATH0 );
    $ctx = mkctx( $d, [ 'https://example.com/p/%d9%8a/' ], [], [ 'nppp_rg_purge_enabled' => 'yes' ] );
    nppp_purge_fp3_rg( $ctx );
    putenv( 'PATH=' . $PATH0 );
    $argv_rg = file_exists( "$shim/argv" ) ? explode( "\n", trim( (string) file_get_contents( "$shim/argv" ) ) ) : [];
    $pat     = '';
    foreach ( $argv_rg as $i => $a ) { if ( strpos( $a, 'KEY:' ) !== false ) { $pat = $a; } }
    ok( $pat !== '', 'FP3 command: the shim saw the rg call', json_encode( $argv_rg ) );
    ok( strpos( $pat, 'KEY: .*(' ) === 0, 'FP3 command: pattern starts with the literal "KEY: " (no leading ^: keeps the literal prefilter)', $pat );
    ok( strpos( $pat, '^' ) !== 0 && strpos( $pat, '(^' ) === false, 'FP3 command: no line anchor anywhere in the prefix', $pat );
    foreach ( [ '--text', '--no-unicode', '--no-messages', '--no-ignore', '--no-config', '-m', '1' ] as $flag ) { ok( in_array( $flag, $argv_rg, true ), "FP3 command: keeps $flag" ); }
    rmrf( $d ); rmrf( $shim );
} else {
    say( "  (skipped: rg missing)\n" );
}

say( "8. FP4 (PHP scan)\n" );
scan_scenarios( 'fp4' );

echo "\n$PASS passed, $FAIL failed\n";
exit( $FAIL ? 1 : 0 );
