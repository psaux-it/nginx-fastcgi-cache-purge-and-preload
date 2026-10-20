<?php
/**
 * WordPress test harness for includes/purge-http.php
 * Usage: php tests/run.php path/to/purge-http.php
 *
 * HTTP responses are scripted, so every branch is deterministic. Real nginx runs are in tests/e2e.php.
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
        'transients' => [],
        'ttl'        => [],
        'queue'      => [],
        'requests'   => [],
        'notices'    => [],
        'actions'    => [],
        'filters'    => [],
        'hooks'      => [],
    ];
}
function t_filter( $tag, $fn ) { $GLOBALS['T']['filters'][ $tag ] = $fn; }

function get_option( $k, $d = false ) { return $GLOBALS['T']['options'][ $k ] ?? $d; }
function update_option( $k, $v, $autoload = null ) { $GLOBALS['T']['options'][ $k ] = $v; $GLOBALS['T']['autoload'][ $k ] = $autoload; return true; }
function delete_option( $k ) { unset( $GLOBALS['T']['options'][ $k ], $GLOBALS['T']['autoload'][ $k ] ); return true; }
function wp_cache_delete( $k, $g = '' ) { return true; }
function home_url() { return 'https://example.com'; }
function wp_parse_url( $u, $c = -1 ) { return parse_url( $u, $c ); }
function absint( $n ) { return abs( (int) $n ); }
function esc_url( $u ) { return $u; }
function esc_url_raw( $u ) { return $u; }
function __( $s, $d = '' ) { return $s; }
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
function nppp_display_admin_notice( $type, $msg, $log = true, $show = true ) { $GLOBALS['T']['notices'][] = [ $type, $msg, $log, $show ]; }
function wp_remote_request( $url, $args ) {
    $GLOBALS['T']['requests'][] = [ 'url' => $url, 'args' => $args, 'hooked' => $GLOBALS['T']['hooks'] ];
    $next = array_shift( $GLOBALS['T']['queue'] );
    if ( $next instanceof Throwable ) { throw $next; }
    return $next ?? new WP_Error( 'http_request_failed', 'cURL error 7: queue empty (test bug)' );
}
function wp_remote_retrieve_response_code( $r ) { return $r['c']; }
function wp_remote_retrieve_body( $r ) { return $r['b'] ?? ''; }
function wp_remote_retrieve_header( $r, $name ) { return $r['h'][ strtolower( $name ) ] ?? ''; }

/** Scripted responses */
function R( int $status, string $code = '', string $body = '', array $extra = [] ) {
    $h = $extra;
    if ( $code !== '' ) { $h['cache-purge-code'] = $code; }
    return [ 'c' => $status, 'b' => $body, 'h' => $h ];
}
function E( string $m ) { return new WP_Error( 'http_request_failed', $m ); }
function Q( ...$responses ) { foreach ( $responses as $r ) { $GLOBALS['T']['queue'][] = $r; } }

$PASS = 0; $FAIL = 0; $FAILS = [];
function ok( $cond, $name, $detail = '' ) {
    global $PASS, $FAIL, $FAILS;
    if ( $cond ) { $PASS++; } else { $FAIL++; $FAILS[] = "FAIL: $name $detail"; echo "FAIL: $name $detail\n"; }
}
function is_( $got, $want, $name ) {
    ok( $got === $want, $name, '-- got ' . var_export( $got, true ) . ' want ' . var_export( $want, true ) );
}
function nreq() { return count( $GLOBALS['T']['requests'] ); }
function lockstate() { return nppp_http_purge_state()['locks']; }
function locks_keys() { $k = array_keys( lockstate() ); sort( $k ); return $k; }
function last_ttl() { return $GLOBALS['T']['ttl'][ NPPP_HTTP_PURGE_BROKEN_KEY ] ?? null; }
function notice_has( $needle ) { foreach ( $GLOBALS['T']['notices'] as $n ) { if ( strpos( $n[1], $needle ) !== false ) return true; } return false; }
function all_log_only() { foreach ( $GLOBALS['T']['notices'] as $n ) { if ( $n[2] !== true || $n[3] !== false ) return false; } return true; }
function last_result() { $a = array_values( array_filter( $GLOBALS['T']['actions'], fn( $x ) => $x[0] === 'nppp_http_purge_result' ) ); return $a ? end( $a )[1] : null; }

require $argv[1];

$V3_HTML = '<html><head><title>Cache Purge</title></head><body bgcolor="white"><center><h1>Cache Purge</h1><p>Key: httpsGETexample.com/blog/post/</p><p>Status: purged</p></center></body></html>';
$SITE    = '<!doctype html><html><head><title>My blog</title></head><body>' . str_repeat( '<p>Hello world</p>', 50 ) . '</body></html>';
$U       = 'https://example.com/blog/post/';

/* ------------------------------------------------------------------ 1. basics */
t_reset( [ 'nppp_http_purge_enabled' => 'no' ] );
is_( nppp_http_purge_try_first( $U ), false, 'disabled single -> false' );
is_( nppp_http_purge_all(), false, 'disabled all -> false' );
is_( nreq(), 0, 'disabled makes no request' );

t_reset();
Q( R( 200, 'purged' ) );
is_( nppp_http_purge_try_first( $U . '?x=1' ), true, 'v4 200 purged -> true' );
$rq = $GLOBALS['T']['requests'][0];
is_( $rq['url'], 'https://example.com/npp_purge_single/blog/post/?x=1', 'single URL = base + path + query' );
is_( $rq['args']['method'], 'GET', 'default method GET' );
is_( $rq['args']['redirection'], 0, 'redirection 0' );
is_( $rq['args']['sslverify'], false, 'sslverify off (loopback)' );
is_( $rq['args']['limit_response_size'], 16384, 'response capped at 16 KB' );
is_( $rq['args']['timeout'], 8, 'single timeout default 8' );
is_( $rq['args']['nppp_http_purge_connect_ms'], 3000, 'connect timeout 3000 ms' );
is_( $rq['args']['headers']['Host'], 'example.com', 'Host header = site host' );
ok( isset( $rq['hooked']['http_api_curl|nppp_http_purge_curl_connect_timeout'] ), 'curl hook active during request' );
ok( ! isset( $GLOBALS['T']['hooks']['http_api_curl|nppp_http_purge_curl_connect_timeout'] ), 'curl hook removed after request' );
is_( last_result()[0] . ':' . last_result()[1], 'single:purged', 'result action payload' );
is_( locks_keys(), [], 'success arms no lock' );
is_( nppp_http_purge_state()['locks'], [], 'success writes no lock' );
is_( nppp_http_purge_state()['methods'], [], 'success learns no method' );
is_( nppp_http_purge_state()['ns'], [], 'a "purged" answer records no generation mode' );
ok( isset( nppp_http_purge_state()['probe']['single'] ), 'a working GET is remembered, so no PURGE probe is due' );

t_reset( [ 'nppp_http_purge_custom_url' => 'http://127.0.0.1:8080/ignored/path', 'nppp_http_purge_suffix' => '/zap/', 'nppp_http_purge_all_path' => '/everything/' ] );
is_( nppp_http_purge_base_url(), 'http://127.0.0.1:8080/zap', 'custom base URL + suffix' );
is_( nppp_http_purge_all_url(), 'http://127.0.0.1:8080/everything', 'custom all URL' );
t_reset();
is_( nppp_http_purge_base_url(), 'https://example.com/npp_purge_single', 'default base URL' );
is_( nppp_http_purge_all_url(), 'https://example.com/npp_purge_all', 'default all URL' );
t_reset( [ 'nppp_http_purge_timeout_all' => 99 ] );
is_( nppp_http_purge_timeout( 'all' ), 99, 'timeout from settings' );
t_reset();
t_filter( 'nppp_http_purge_timeout', fn( $v, $t ) => $t === 'all' ? 77 : $v );
is_( nppp_http_purge_timeout( 'all' ), 77, 'timeout filter' );

/* ------------------------------------------------------- 2. legacy 200 bodies */
foreach ( [
    'v3 html'  => $V3_HTML,
    'v3 json'  => '{"Key": "httpsGETexample.com/blog/post/", "Status": "purged"}',
    'v3 xml'   => '<?xml version="1.0" encoding="UTF-8"?><status><Key><![CDATA[httpsGETexample.com/blog/post/]]></Key><Status>purged</Status></status>',
    'v3 text'  => "Key: httpsGETexample.com/blog/post/\nStatus: purged\n",
    'v2.5 text (no space after colon)' => "Key:httpsGETexample.com/blog/post/\n",
    'v2.5 json (no Status)' => '{"Key": "httpsGETexample.com/blog/post/"}',
    'v2.5 xml (no Status)'  => '<?xml version="1.0" encoding="UTF-8"?><status><Key><![CDATA[httpsGETexample.com/blog/post/]]></Key></status>',
    'v4 html, header stripped' => '<!doctype html><html><head><title>Cache Purge</title></head><body><h1>Cache Purge</h1><p>Key: /blog/post/</p><p>Code: purged</p><p>Key encoding: utf-8</p></body></html>',
    'v2 html'  => '<html><head><title>Successful purge</title></head><body><center><h1>Successful purge</h1><p>Key : httpsGETexample.com/blog/post/</p></center></body></html>',
    ] as $label => $body ) {
    t_reset();
    Q( R( 200, '', $body ) );
    is_( nppp_http_purge_try_first( $U ), true, "legacy 200 ($label) -> true" );
    is_( nreq(), 1, "legacy 200 ($label) no retry" );
}

/* an empty 200 carries no Key: v2/v3 always answer with it, so it is some other page, not a purge */
t_reset();
Q( R( 200, '', '' ), R( 200, 'purged' ) );
is_( nppp_http_purge_try_first( $U ), true, 'legacy 200 (empty) is foreign, PURGE retry works -> true' );
is_( nppp_http_purge_state()['methods'], [ 'single' => 'PURGE' ], 'legacy 200 (empty): PURGE remembered' );
t_reset();
Q( R( 200, '', '' ), R( 200, '', '' ) );
is_( nppp_http_purge_try_first( $U ), false, 'legacy 200 (empty) twice -> false' );
is_( locks_keys(), [ 'single' ], 'legacy 200 (empty) twice locks single (foreign)' );

/* a "Key" marker alone is not a purge; a real v2/v3 body echoes the page path */
foreach ( [
    'li Key:'          => '<html><body><ul><li>Key: value</li></ul></body></html>',
    'line Key:'        => "<html><body>\nKey: shortcut Ctrl+K\n</body></html>",
    'inline JSON Key'  => '<script>var cfg={"Key": "abc"};</script>',
    'other URL echoed' => '<html><body><p>Key: httpsGETexample.com/other/page/</p></body></html>',
] as $label => $body ) {
    t_reset();
    Q( R( 200, '', $body ), R( 200, '', $body ) );
    is_( nppp_http_purge_try_first( $U ), false, "foreign 200 with Key marker ($label) is NOT a purge" );
    is_( locks_keys(), [ 'single' ], "foreign 200 with Key marker ($label) locks single" );
}
t_reset();
Q( R( 200, '', '<html><body><ul><li>Key: value</li></ul></body></html>' ), R( 200, 'purged' ) );
is_( nppp_http_purge_try_first( $U ), true, 'foreign 200 with Key marker, then PURGE works -> true via the retry' );

/* ----------------------------------------- 3. foreign 200 (site page), PURGE retry */
t_reset();
Q( R( 200, '', $SITE ), R( 200, 'purged' ) );
is_( nppp_http_purge_try_first( $U ), true, 'foreign 200 then PURGE works -> true' );
is_( $GLOBALS['T']['requests'][1]['args']['method'], 'PURGE', 'retry used PURGE' );
is_( nppp_http_purge_state()['methods'], [ 'single' => 'PURGE' ], 'PURGE remembered for single' );
Q( R( 200, 'purged' ) );
nppp_http_purge_try_first( $U );
is_( $GLOBALS['T']['requests'][2]['args']['method'], 'PURGE', 'learned method used first next time' );
is_( nreq(), 3, 'learned method needs no retry' );
is_( nppp_http_purge_method_for( 'all' ), 'GET', 'method memory is per scope' );

t_reset();
Q( R( 200, '', $SITE ), R( 200, '', $SITE ) );
is_( nppp_http_purge_try_first( $U ), false, 'foreign 200 twice -> false (never "purged")' );
is_( locks_keys(), [ 'single' ], 'foreign arms the single lock only' );
is_( last_ttl(), 3600, 'foreign lock 1 h' );
ok( notice_has( 'does not come from ngx_cache_purge' ), 'foreign message' );
ok( all_log_only(), 'failure notices are log-only' );
$before = nreq();
is_( nppp_http_purge_try_first( $U ), false, 'locked single skips' );
is_( nreq(), $before, 'locked single makes no request' );
Q( R( 200, 'purged' ) );
is_( nppp_http_purge_all(), true, 'single lock does not block purge all' );

t_reset();
t_filter( 'nppp_http_purge_verify_legacy_body', fn() => false );
Q( R( 200, '', $SITE ) );
is_( nppp_http_purge_try_first( $U ), true, 'body verification can be disabled (legacy behaviour)' );

/* ----------------------------------------------------------- 4. single: misses */
t_reset();
Q( R( 412 ) );
is_( nppp_http_purge_try_first( $U ), 'miss', 'v3 412 -> miss' );
is_( locks_keys(), [], '412 no lock' );

t_reset();
Q( R( 200, 'no_match' ) );
is_( nppp_http_purge_try_first( $U ), 'miss', 'v4 200 no_match -> miss' );
t_reset();
Q( R( 204, 'no_match' ) );
is_( nppp_http_purge_try_first( $U ), 'miss', 'v4 204 no_match (response_type none) -> miss' );
t_reset();
Q( R( 204, 'purged' ) );
is_( nppp_http_purge_try_first( $U ), true, 'v4 204 purged (response_type none) -> true' );
is_( locks_keys(), [], '204 does not lock' );

foreach ( [
    'percent path'       => 'https://example.com/caf%C3%A9/',
    'lowercase percent'  => 'https://example.com/%d0%bc%d0%b8%d1%80/',
    'non-ascii path'     => "https://example.com/caf\xC3\xA9/",
    'non-ascii query'    => "https://example.com/p/?q=caf\xC3\xA9",
    'double slash'       => 'https://example.com/a//b/',
    'dot segment'        => 'https://example.com/a/../b/',
    'single dot'         => 'https://example.com/a/./b/',
] as $label => $url ) {
    foreach ( [ [ 412, '' ], [ 200, 'no_match' ] ] as [ $st, $cd ] ) {
        t_reset();
        Q( R( $st, $cd ) );
        is_( nppp_http_purge_try_first( $url ), false, "miss on ambiguous url ($label, $st $cd) -> false so disk decides" );
        is_( locks_keys(), [], "ambiguous miss ($label) no lock" );
    }
}
t_reset();
Q( R( 200, 'no_match' ) );
is_( nppp_http_purge_try_first( 'https://example.com/a/?x=%41' ), 'miss', 'percent only in query is not ambiguous (raw $args)' );
t_reset();
t_filter( 'nppp_http_purge_trust_miss', fn( $trust, $url, $why ) => false );
Q( R( 412 ) );
is_( nppp_http_purge_try_first( $U ), false, 'trust_miss filter can force disk verification' );
t_reset();
t_filter( 'nppp_http_purge_trust_miss', fn( $trust, $url, $why ) => true );
Q( R( 412 ) );
is_( nppp_http_purge_try_first( 'https://example.com/caf%C3%A9/' ), 'miss', 'trust_miss filter can force trust' );

/* 404 on GET is ambiguous (v3 purge-only locations 404 other methods), so one PURGE probe follows */
t_reset();
Q( R( 404 ), R( 404 ) );
is_( nppp_http_purge_try_first( $U ), false, '404 (also on the PURGE probe) -> false (ambiguous)' );
is_( nreq(), 2, '404 is followed by exactly one PURGE probe' );
is_( locks_keys(), [], '404 no lock' );
is_( $GLOBALS['T']['notices'], [], '404 silent' );
Q( R( 404 ) );
is_( nppp_http_purge_try_first( $U ), false, 'second 404 -> false' );
is_( nreq(), 3, 'a recent probe is not repeated' );
t_reset();
Q( R( 404 ), R( 200, 'purged' ) );
is_( nppp_http_purge_try_first( $U ), true, '404 then PURGE answers purged -> true (inline method location)' );
is_( $GLOBALS['T']['requests'][1]['args']['method'], 'PURGE', '404 probe uses the module method' );
is_( nppp_http_purge_state()['methods'], [ 'single' => 'PURGE' ], '404 probe: PURGE remembered' );

/* --------------------------------------------------------------- 5. single: locks */
t_reset();
Q( R( 403, 'access_denied' ) );
is_( nppp_http_purge_try_first( $U ), false, '403 -> false' );
is_( locks_keys(), [ 'single' ], '403 locks single only' );
is_( last_ttl(), 3600, '403 lock 1 h' );
Q( R( 200, 'purged' ) );
is_( nppp_http_purge_all(), true, 'purge all still works after single 403 (separate allow lists)' );

t_reset();
Q( R( 403 ) );
nppp_http_purge_try_first( $U );
is_( locks_keys(), [ 'single' ], 'bare 403 (nginx allow/deny) same' );

t_reset();
Q( R( 405, 'method_not_allowed', '', [ 'allow' => 'PURGE' ] ), R( 200, 'purged' ) );
is_( nppp_http_purge_try_first( $U ), true, '405 Allow: PURGE -> retry with PURGE -> true' );
is_( $GLOBALS['T']['requests'][1]['args']['method'], 'PURGE', '405 retry method' );
is_( nppp_http_purge_state()['methods'], [ 'single' => 'PURGE' ], '405 retry remembered' );
is_( locks_keys(), [], '405 retry success: no lock' );

t_reset();
Q( R( 405, 'method_not_allowed', '', [ 'allow' => 'PURGE' ] ), R( 405, 'method_not_allowed', '', [ 'allow' => 'PURGE' ] ) );
is_( nppp_http_purge_try_first( $U ), false, '405 twice -> false' );
is_( locks_keys(), [ 'single' ], '405 twice locks single' );
ok( notice_has( 'Allow: PURGE' ), '405 message shows Allow' );
is_( nreq(), 2, '405 retried exactly once' );

t_reset();
Q( R( 405, 'method_not_allowed', '', [ 'allow' => 'GET, HEAD' ] ) );
is_( nppp_http_purge_try_first( $U ), false, '405 Allow GET,HEAD (already tried GET) -> no retry' );
is_( nreq(), 1, 'no pointless retry' );
is_( locks_keys(), [ 'single' ], '405 no usable method locks' );

t_reset();
Q( R( 405, 'method_not_allowed', '', [ 'allow' => 'POST, DELETE' ] ) );
nppp_http_purge_try_first( $U );
is_( nreq(), 1, 'never retries with unrelated methods (POST/DELETE)' );
t_reset();
Q( R( 405, 'method_not_allowed', '', [ 'allow' => 'REFRESH' ] ), R( 200, 'refresh_complete' ) );
nppp_http_purge_try_first( $U );
is_( nreq(), 1, '405 Allow: REFRESH (module refresh endpoint) is never retried with REFRESH' );
is_( locks_keys(), [ 'single' ], '405 Allow: REFRESH locks the endpoint without probing the origin' );

t_reset();
nppp_http_purge_remember_method( 'single', 'PURGE' );
Q( R( 405, 'method_not_allowed', '', [ 'allow' => 'GET, HEAD' ] ), R( 200, 'purged' ) );
is_( nppp_http_purge_try_first( $U ), true, 'learned PURGE stops working -> falls back to GET' );
is_( nppp_http_purge_state()['methods'], [], 'learned method forgotten after GET works' );

t_reset();
Q( R( 400, 'invalid_selector' ) );
is_( nppp_http_purge_try_first( $U ), false, '400 invalid_selector -> false' );
is_( locks_keys(), [], '400 per-URL: NO lock' );
ok( notice_has( 'this URL only' ), '400 message' );
foreach ( [ 400, 414, 431 ] as $st ) {
    t_reset(); Q( R( $st ) );
    is_( nppp_http_purge_try_first( $U ), false, "bare $st -> false" );
    is_( locks_keys(), [], "bare $st no lock" );
}

t_reset(); Q( R( 429 ) );
is_( nppp_http_purge_try_first( $U ), false, '429 -> false' );
is_( locks_keys(), [], '429 no lock' );

foreach ( [ 'delete_failed', 'scan_failed' ] as $c ) {
    t_reset(); Q( R( 500, $c ) );
    is_( nppp_http_purge_try_first( $U ), false, "500 $c -> false" );
    is_( locks_keys(), [], "500 $c no lock" );
}
t_reset(); Q( R( 500 ) );
is_( nppp_http_purge_try_first( $U ), false, 'bare 500 -> false' );
is_( locks_keys(), [], 'bare 500 no lock' );

foreach ( [ [ 500, 'internal_error' ], [ 502, '' ], [ 503, '' ], [ 504, '' ] ] as [ $st, $cd ] ) {
    t_reset(); Q( R( $st, $cd ) );
    is_( nppp_http_purge_try_first( $U ), false, "$st $cd -> false" );
    is_( locks_keys(), [ 'single' ], "$st $cd short lock single" );
    is_( last_ttl(), 300, "$st $cd lock 5 min" );
}
foreach ( [ 'cache_unavailable', 'key_mismatch' ] as $c ) {
    t_reset(); Q( R( 500, $c ) );
    is_( nppp_http_purge_try_first( $U ), false, "500 $c -> false" );
    is_( locks_keys(), [ 'single' ], "500 $c locks single" );
    // The transient now also carries the day-long config issue, so check the lock itself.
    $until = lockstate()['single']['until'] - time();
    ok( $until > 3590 && $until <= 3600, "500 $c lock 1 h", "-- got $until" );
}

/* key_mismatch / cache_unavailable: an actionable message, remembered beyond the lock. */
t_reset(); Q( R( 500, 'key_mismatch' ) );
nppp_http_purge_try_first( $U );
ok( notice_has( 'key_mismatch' ) && notice_has( 'fastcgi_cache_key $cache_purge_key;' ) && notice_has( 'fastcgi_cache_purge ZONE_NAME $cache_purge_key;' ), 'key_mismatch single: log line names the fix' );
ok( all_log_only(), 'key_mismatch notice stays log-only (the settings page shows it)' );
is_( nppp_http_purge_state()['issue']['single']['code'] ?? '', 'key_mismatch', 'key_mismatch single: issue remembered' );
ok( strpos( nppp_http_purge_config_notice(), '$cache_purge_key' ) !== false, 'settings-page notice explains key_mismatch' );
t_reset(); Q( R( 500, 'key_mismatch' ) );
nppp_http_purge_all();
ok( notice_has( 'Purge All location' ) && notice_has( 'fastcgi_cache_key $cache_purge_key;' ), 'key_mismatch all: message names the Purge All location' );
is_( array_keys( nppp_http_purge_state()['issue'] ), [ 'all' ], 'key_mismatch all: issue scoped to all' );
t_reset(); Q( R( 500, 'cache_unavailable' ) );
nppp_http_purge_try_first( $U );
ok( notice_has( 'cache_unavailable' ) && notice_has( 'keys_zone' ), 'cache_unavailable: message names the zone fix' );
ok( strpos( nppp_http_purge_config_notice(), 'cache_unavailable' ) !== false, 'settings-page notice explains cache_unavailable' );

/* The issue outlives the 1 h lock, a good answer from that scope clears it, other scopes are untouched. */
t_reset(); Q( R( 500, 'key_mismatch' ) );
nppp_http_purge_try_first( $U );
$st = $GLOBALS['T']['transients'][ NPPP_HTTP_PURGE_BROKEN_KEY ];
is_( $GLOBALS['T']['ttl'][ NPPP_HTTP_PURGE_BROKEN_KEY ], 86400, 'issue keeps the state for a day' );
$st['locks']['single']['until'] = time() - 5;                 // the lock expired ...
$GLOBALS['T']['transients'][ NPPP_HTTP_PURGE_BROKEN_KEY ] = $st;
is_( nppp_http_purge_state()['locks'], [], 'lock expired' );
ok( nppp_http_purge_config_notice() !== '', '... but the settings notice is still there' );
Q( R( 200, 'purged' ) );
is_( nppp_http_purge_try_first( $U ), true, 'endpoint fixed -> purged' );
is_( nppp_http_purge_state()['issue'], [], 'a good answer clears the issue' );
is_( nppp_http_purge_config_notice(), '', 'settings notice disappears' );
t_reset(); Q( R( 500, 'key_mismatch' ) );
nppp_http_purge_all();
Q( R( 200, 'purged' ) );
$st = $GLOBALS['T']['transients'][ NPPP_HTTP_PURGE_BROKEN_KEY ];
$st['locks'] = []; $GLOBALS['T']['transients'][ NPPP_HTTP_PURGE_BROKEN_KEY ] = $st;
nppp_http_purge_try_first( $U );
is_( array_keys( nppp_http_purge_state()['issue'] ), [ 'all' ], 'single success does not clear the all issue' );

/* An issue older than a day, or an unknown code, is ignored. HTTP Purge off -> no notice. */
t_reset();
$GLOBALS['T']['transients'][ NPPP_HTTP_PURGE_BROKEN_KEY ] = [ 'issue' => [
    'single' => [ 'code' => 'key_mismatch', 'at' => time() - 90000 ],
    'all'    => [ 'code' => '<script>',      'at' => time() ],
] ];
is_( nppp_http_purge_state()['issue'], [], 'stale or unknown issue is dropped' );
t_reset( [ 'nppp_http_purge_enabled' => 'no' ] );
$GLOBALS['T']['transients'][ NPPP_HTTP_PURGE_BROKEN_KEY ] = [ 'issue' => [ 'single' => [ 'code' => 'key_mismatch', 'at' => time() ] ] ];
is_( nppp_http_purge_config_notice(), '', 'HTTP Purge disabled -> no config notice' );
t_reset(); Q( R( 200, 'status' ) );
is_( nppp_http_purge_try_first( $U ), false, 'single bound to status handler -> false' );
is_( locks_keys(), [ 'single' ], 'status-bound single locks single' );

t_reset(); Q( R( 400, 'invalid_cursor' ) );
is_( nppp_http_purge_try_first( $U ), false, '400 invalid_cursor (single bound to a REFRESH location) -> false' );
is_( locks_keys(), [ 'single' ], 'invalid_cursor = wrong endpoint: locks single' );
is_( nreq(), 1, 'invalid_cursor not retried' );
t_reset(); Q( R( 301 ), R( 301 ) );
is_( nppp_http_purge_try_first( $U ), false, '301 (also on the PURGE probe) -> false' );
is_( nreq(), 2, '301 is followed by one PURGE probe' );
is_( locks_keys(), [ '*' ], '301 locks everything (scheme/host problem)' );
is_( nppp_http_purge_all(), false, 'purge all blocked by * lock' );
is_( nreq(), 2, 'no request while * lock' );
t_reset(); Q( R( 301 ), R( 200, 'purged' ) );
is_( nppp_http_purge_try_first( $U ), true, '301 then PURGE answers purged -> true' );
is_( locks_keys(), [], '301 then purged: no lock' );
t_reset(); Q( R( 401 ) );
nppp_http_purge_try_first( $U );
is_( locks_keys(), [ '*' ], '401 locks everything' );
t_reset(); Q( R( 418 ) );
nppp_http_purge_try_first( $U );
is_( locks_keys(), [ 'single' ], 'unexpected 418 locks single' );
t_reset(); Q( R( 200, 'frobnicated' ) );
is_( nppp_http_purge_try_first( $U ), false, 'unknown future module code never counts as success' );
is_( locks_keys(), [ 'single' ], 'unknown code locks scope' );
t_reset(); Q( R( 204 ), R( 204 ) );
is_( nppp_http_purge_try_first( $U ), false, '204 without Cache-Purge-Code is not the module' );
t_reset(); Q( R( 200, 'invalidated' ) );
is_( nppp_http_purge_try_first( $U ), true, 'invalidated -> true' );
Q( R( 200, 'invalidated' ) );
nppp_http_purge_try_first( $U );
$ns = 0; foreach ( $GLOBALS['T']['notices'] as $n ) { if ( strpos( $n[1], 'generation invalidation' ) !== false ) $ns++; }
is_( $ns, 1, 'generation-mode hint logged once' );
t_reset(); Q( R( 202, 'accepted' ) );
is_( nppp_http_purge_try_first( $U ), false, 'single 202 -> false, not claimed' );
is_( locks_keys(), [ 'single' ], 'single 202 = wildcard/purge_all location behind the single endpoint: locked' );
ok( notice_has( 'wildcard / purge_all location' ), 'single 202 notice names the cause' );
t_reset(); Q( R( 202, '', '{"Key":"x"}' ) );
is_( nppp_http_purge_try_first( $U ), false, 'single v3 202 -> false' );
is_( locks_keys(), [ 'single' ], 'single v3 202 locked' );
is_( nreq(), 1, 'single 202 not retried' );
t_reset(); Q( R( 200, 'purged', '', [ 'cache-purge-code' => [ 'purged', 'purged' ] ] ) );
is_( nppp_http_purge_try_first( $U ), true, 'duplicate Cache-Purge-Code headers tolerated' );
t_reset(); Q( R( 200, '', '{"key":"/a/","key_encoding":"utf-8","code":"no_match","complete":true,"deleted":0}' ) );
is_( nppp_http_purge_try_first( $U ), 'miss', 'v4 JSON body code used when a proxy strips the header' );
t_reset(); Q( R( 200, '', '{"code":"purged"}', [ 'cache-purge-code' => 'PURGED!!' ] ) );
is_( nppp_http_purge_try_first( $U ), false, 'garbage Cache-Purge-Code header is not trusted' );

/* ------------------------------------------------- 6. single: URL guards */
foreach ( [ 'https://example.com/a/*', 'https://example.com/a/%2A', 'https://example.com/a/%2a', 'https://example.com/a/?q=*', 'https://example.com/a*' ] as $w ) {
    t_reset(); Q( R( 200, 'purged' ) );
    is_( nppp_http_purge_try_first( $w ), false, "wildcard URL refused: $w" );
    is_( nreq(), 0, "wildcard URL never sent: $w" );
}
t_reset(); Q( R( 200, 'purged' ) );
is_( nppp_http_purge_try_first( 'https://example.com/a*b/' ), true, 'star in the middle is fine' );
t_reset(); Q( R( 200, 'purged' ) );
is_( nppp_http_purge_try_first( "https://example.com/a/\r\nX: y" ), false, 'control characters refused' );
is_( nreq(), 0, 'control characters never sent' );
t_reset(); Q( R( 200, 'purged' ) );
is_( nppp_http_purge_try_first( 'not a url' ), false, 'no host -> false' );
t_reset(); Q( R( 200, 'purged' ) );
t_filter( 'nppp_http_purge_url', fn( $u, $o ) => '' );
is_( nppp_http_purge_try_first( $U ), false, 'url filter returning empty -> false' );
t_reset(); Q( R( 200, 'purged' ) );
t_filter( 'nppp_http_purge_url', fn( $u, $o ) => 'https://example.com/custom/' . ltrim( parse_url( $o, PHP_URL_PATH ), '/' ) );
nppp_http_purge_try_first( $U );
is_( $GLOBALS['T']['requests'][0]['url'], 'https://example.com/custom/blog/post/', 'url filter honoured' );

/* ------------------------------------------------------- 7. transport errors */
foreach ( [
    'Operation timed out after 8001 milliseconds with 0 bytes received',
    'cURL error 28: Operation timed out after 30000 milliseconds with 1024 bytes received',
] as $m ) {
    t_reset(); Q( E( $m ) );
    is_( nppp_http_purge_try_first( $U ), false, "read timeout -> false ($m)" );
    is_( locks_keys(), [], 'read timeout: NO lock (nginx is alive)' );
    ok( notice_has( 'timed out after 8 second' ), 'read timeout message' );
}
foreach ( [
    'cURL error 28: Connection timed out after 3001 milliseconds',
    'cURL error 28: Resolving timed out after 3000 milliseconds',
    'cURL error 7: Failed to connect to 127.0.0.1 port 80 after 0 ms: Couldn\'t connect to server',
    'cURL error 6: Could not resolve host: example.invalid',
    'cURL error 35: SSL connect error',
    'cURL error 52: Empty reply from server',
    'HTTP transport failure: RuntimeException',
] as $m ) {
    t_reset(); Q( E( $m ) );
    is_( nppp_http_purge_try_first( $U ), false, "connect failure -> false ($m)" );
    is_( locks_keys(), [ '*' ], "connect failure locks * ($m)" );
    is_( last_ttl(), 900, 'connect failure lock 15 min' );
    is_( nppp_http_purge_all(), false, 'all blocked after connect failure' );
}
t_reset(); Q( new RuntimeException( 'boom' ) );
is_( nppp_http_purge_try_first( $U ), false, 'exception in transport is contained' );
ok( ! isset( $GLOBALS['T']['hooks']['http_api_curl|nppp_http_purge_curl_connect_timeout'] ), 'hook removed even after exception' );
is_( locks_keys(), [ '*' ], 'exception counts as unreachable' );

/* ------------------------------------------------------------ 8. lock state */
t_reset();
set_transient( NPPP_HTTP_PURGE_BROKEN_KEY, '403', 3600 );
is_( nppp_http_purge_locked( 'single' ), true, 'legacy string lock blocks single' );
is_( nppp_http_purge_locked( 'all' ), true, 'legacy string lock blocks all' );
is_( nppp_http_purge_try_first( $U ), false, 'legacy lock -> no request' );
is_( nreq(), 0, 'legacy lock -> zero requests' );
delete_transient( NPPP_HTTP_PURGE_BROKEN_KEY );   // what status.php / uninstall.php do
is_( nppp_http_purge_locked( 'single' ), false, 'status.php reset clears every scope' );

t_reset();
nppp_http_purge_arm( 'single', 'x', 3600 );
nppp_http_purge_arm( 'all', 'y', 120 );
is_( locks_keys(), [ 'all', 'single' ], 'two scopes coexist' );
is_( last_ttl(), 3600, 'transient lives as long as the longest lock' );
nppp_http_purge_remember_method( 'single', 'PURGE' );
delete_transient( NPPP_HTTP_PURGE_BROKEN_KEY );
is_( nppp_http_purge_state(), [ 'locks' => [], 'methods' => [], 'ns' => [], 'probe' => [], 'issue' => [] ], 'one delete_transient clears locks, learned methods, generation mode, probe state and config issues' );

t_reset();
set_transient( NPPP_HTTP_PURGE_BROKEN_KEY, [ 'locks' => [ 'single' => [ 'reason' => 'x', 'until' => time() - 5 ], 'all' => [ 'reason' => 'y', 'until' => time() + 100 ] ], 'methods' => [ 'single' => 'rm -rf', 'all' => 'PURGE' ] ], 3600 );
is_( locks_keys(), [ 'all' ], 'expired scope lock ignored' );
is_( nppp_http_purge_state()['methods'], [ 'all' => 'PURGE' ], 'invalid method token dropped' );
Q( R( 200, 'purged' ) );
is_( nppp_http_purge_try_first( $U ), true, 'expired single lock allows requests' );

t_reset();
nppp_http_purge_state_save( [ 'locks' => [ '*' => [ 'reason' => 'legacy', 'until' => PHP_INT_MAX ] ], 'methods' => [] ] );
ok( last_ttl() <= 3600, 'legacy (infinite) lock is clamped when saved' );
t_reset();
nppp_http_purge_state_save( [ 'locks' => [], 'methods' => [] ] );
is_( $GLOBALS['T']['transients'], [], 'empty state removes transient' );

t_reset();
t_filter( 'nppp_http_purge_lock_ttl', fn( $ttl, $o, $s ) => $o === 'denied' ? 120 : $ttl );
Q( R( 403 ) );
nppp_http_purge_try_first( $U );
is_( last_ttl(), 120, 'lock ttl filter' );

t_reset();
t_filter( 'nppp_http_purge_retry_methods', fn() => [ 'purge', 'rm -rf', 'BAN' ] );
is_( nppp_http_purge_method_pool(), [ 'GET', 'PURGE', 'BAN' ], 'method pool sanitized' );

/* --------------------------------------------------------- 9. runtime helpers */
foreach ( [ [ 8, 3000 ], [ 2, 2000 ], [ 1, 1000 ], [ 30, 3000 ], [ 0, 1000 ] ] as [ $to, $ms ] ) {
    t_reset();
    is_( nppp_http_purge_connect_timeout_ms( $to ), $ms, "connect ms for timeout $to" );
}
t_reset(); t_filter( 'nppp_http_purge_connect_timeout', fn() => 0.1 );
is_( nppp_http_purge_connect_timeout_ms( 8 ), 500, 'connect timeout floor 0.5 s' );
t_reset(); t_filter( 'nppp_http_purge_connect_timeout', fn() => 12.0 );
is_( nppp_http_purge_connect_timeout_ms( 30 ), 12000, 'connect timeout filter' );
is_( nppp_http_purge_connect_timeout_ms( 5 ), 5000, 'connect timeout never above overall' );

ini_set( 'max_execution_time', '20' );
nppp_http_purge_extend_time_limit( 35 );
is_( (int) ini_get( 'max_execution_time' ), 35, 'time limit raised when lower' );
nppp_http_purge_extend_time_limit( 10 );
is_( (int) ini_get( 'max_execution_time' ), 35, 'time limit NEVER lowered' );
ini_set( 'max_execution_time', '0' );
nppp_http_purge_extend_time_limit( 35 );
is_( (int) ini_get( 'max_execution_time' ), 0, 'unlimited stays unlimited (purge.php set_time_limit(0))' );
ini_set( 'max_execution_time', '25' );
t_reset(); Q( R( 200, 'purged' ) );
nppp_http_purge_try_first( $U );
is_( (int) ini_get( 'max_execution_time' ), 25, 'single purge does not shrink a 25 s limit to 13' );
t_reset(); Q( R( 200, 'purged' ) );
nppp_http_purge_all();
is_( (int) ini_get( 'max_execution_time' ), 50, 'purge all raises 25 -> timeout 30 + 5 + queue wait 15' );
ini_set( 'max_execution_time', '0' );

$real = curl_init();
nppp_http_purge_curl_connect_timeout( $real, [ 'nppp_http_purge_connect_ms' => 1500 ] );
nppp_http_purge_curl_connect_timeout( $real, [] );
nppp_http_purge_curl_connect_timeout( null, [ 'nppp_http_purge_connect_ms' => 1500 ] );
nppp_http_purge_curl_connect_timeout( 'x', [ 'nppp_http_purge_connect_ms' => 1500 ] );
nppp_http_purge_curl_connect_timeout( $real, null );
ok( true, 'curl hook tolerates real handle, empty args, non-handles' );

/* ================================================================== PURGE ALL */
t_reset();
Q( R( 200, 'purged' ) );
is_( nppp_http_purge_all(), true, 'all: v4 200 purged' );
is_( $GLOBALS['T']['requests'][0]['url'], 'https://example.com/npp_purge_all', 'all: URL' );
is_( $GLOBALS['T']['requests'][0]['args']['timeout'], 30, 'all: timeout 30' );
is_( $GLOBALS['T']['requests'][0]['args']['nppp_http_purge_connect_ms'], 3000, 'all: connect 3 s, not 30 s' );
t_reset(); Q( R( 200, 'no_match' ) );
is_( nppp_http_purge_all(), true, 'all: v4 no_match (cache already empty) -> true' );
t_reset(); Q( R( 200, 'invalidated' ) );
is_( nppp_http_purge_all(), true, 'all: v4 invalidated -> true' );
t_reset(); Q( R( 200, '', $V3_HTML ) );
is_( nppp_http_purge_all(), true, 'all: v3 200 -> true' );
t_reset(); Q( R( 204, 'purged' ) );
is_( nppp_http_purge_all(), true, 'all: v4 204 purged -> true' );
is_( locks_keys(), [], 'all: 204 no lock' );

t_reset(); Q( R( 200, 'status', '{"code":"status"}' ) );
is_( nppp_http_purge_all(), false, 'all: purge_all pointed at queue status -> false' );
is_( locks_keys(), [ 'all' ], 'all: status endpoint locks all' );
Q( R( 200, 'purged' ) );
is_( nppp_http_purge_try_first( $U ), true, 'all: wrong-endpoint lock does not block single' );
t_reset(); Q( R( 412 ) );
is_( nppp_http_purge_all(), false, 'all: 412 means exact-purge endpoint -> false' );
is_( locks_keys(), [ 'all' ], 'all: 412 locks all' );
t_reset(); Q( R( 200, '', $SITE ), R( 200, '', $SITE ) );
is_( nppp_http_purge_all(), false, 'all: site page answering 200 is not a purge' );
is_( locks_keys(), [ 'all' ], 'all: foreign locks all' );
t_reset(); Q( R( 200, '', $SITE ), R( 200, 'purged' ) );
is_( nppp_http_purge_all(), true, 'all: inline PURGE location reached through the PURGE retry' );
t_reset(); Q( R( 404 ), R( 404 ) );
is_( nppp_http_purge_all(), false, 'all: 404 (also on the PURGE probe) -> false' );
is_( nreq(), 2, 'all: 404 is followed by exactly one PURGE probe' );
is_( locks_keys(), [], 'all: 404 no lock, silent' );
is_( $GLOBALS['T']['notices'], [], 'all: 404 silent' );
t_reset(); Q( R( 403, 'access_denied' ) );
is_( nppp_http_purge_all(), false, 'all: 403 -> false' );
is_( locks_keys(), [ 'all' ], 'all: 403 locks all only' );
Q( R( 200, 'purged' ) );
is_( nppp_http_purge_try_first( $U ), true, 'all: 403 does not block single (own allow list)' );
t_reset(); Q( R( 405, 'method_not_allowed', '', [ 'allow' => 'PURGE' ] ), R( 200, 'purged' ) );
is_( nppp_http_purge_all(), true, 'all: 405 -> PURGE retry -> true' );
is_( nppp_http_purge_state()['methods'], [ 'all' => 'PURGE' ], 'all: PURGE remembered' );
foreach ( [ [ 500, 'delete_failed' ], [ 500, 'scan_failed' ], [ 500, '' ] ] as [ $st, $cd ] ) {
    t_reset(); Q( R( $st, $cd ) );
    is_( nppp_http_purge_all(), false, "all: $st $cd -> false" );
    is_( locks_keys(), [], "all: $st $cd no lock" );
}
t_reset(); Q( R( 500, 'internal_error' ) );
nppp_http_purge_all();
is_( locks_keys(), [ 'all' ], 'all: internal_error short lock' );
is_( last_ttl(), 300, 'all: internal_error 5 min' );
t_reset(); Q( R( 400, 'invalid_selector' ) );
is_( nppp_http_purge_all(), false, 'all: 400 -> false' );
is_( locks_keys(), [], 'all: 400 no lock' );
t_reset(); Q( E( 'cURL error 28: Operation timed out after 30001 milliseconds with 0 bytes received' ) );
is_( nppp_http_purge_all(), false, 'all: read timeout -> false' );
is_( locks_keys(), [], 'all: read timeout no lock' );
ok( notice_has( 'timed out after 30 second' ), 'all: timeout message' );
t_reset(); Q( E( 'cURL error 28: Connection timed out after 3002 milliseconds' ) );
is_( nppp_http_purge_all(), false, 'all: firewall drop -> false quickly' );
is_( locks_keys(), [ '*' ], 'all: connect failure locks *' );

/* 202 */
t_reset(); Q( R( 202, 'accepted' ) );
is_( nppp_http_purge_all(), false, 'all: 202 accepted -> false (default)' );
is_( locks_keys(), [], 'all: 202 no lock' );
ok( notice_has( 'returned 202' ), 'all: 202 notice' );
t_reset(); Q( R( 202, '', '{"Key":"x"}' ) );
is_( nppp_http_purge_all(), false, 'all: v3 202 (background queue) -> false' );

/* 202 + queue verification */
function status_body( $pending, $active, $exh = 0, $err = 0, $scan = 0 ) {
    return json_encode( [ 'code' => 'status', 'queue' => [ 'size' => 0 ],
        'cache' => [ 'pending' => [ 'total' => $pending, 'full' => 0 ], 'active' => $active,
                     'files' => [ 'deleted' => 5, 'errors' => $err, 'bytes' => 1 ],
                     'cleanup' => [ 'scan_errors' => $scan, 'retries' => 0, 'exhausted' => $exh ] ] ] );
}
function S( ...$a ) { return R( 200, 'status', status_body( ...$a ) ); }
function verify_on( $wait = 2 ) {
    t_filter( 'nppp_http_purge_verify_queue', fn() => true );
    $GLOBALS['T']['filters']['nppp_http_purge_queue_status_url'] = fn() => 'http://127.0.0.1/purge_status';
    $GLOBALS['T']['filters']['nppp_http_purge_queue_wait'] = fn() => $wait;
}
t_reset(); verify_on();
Q( S( 0, 0 ), R( 202, 'accepted' ), S( 1, 0 ), S( 0, 1 ), S( 0, 0 ), S( 0, 0 ) );
is_( nppp_http_purge_all(), true, 'queue verify: drains -> true' );
is_( $GLOBALS['T']['requests'][0]['url'], 'http://127.0.0.1/purge_status', 'queue verify: baseline first' );
is_( $GLOBALS['T']['requests'][1]['url'], 'https://example.com/npp_purge_all', 'queue verify: purge second' );
is_( nreq(), 6, 'queue verify: polled until two idle samples' );
t_reset(); verify_on();
Q( S( 0, 0 ), R( 202, 'accepted' ), S( 0, 0 ), S( 0, 0, 1 ) );
// idle twice but exhausted rose between baseline (0) and the second sample
is_( nppp_http_purge_all(), false, 'queue verify: exhausted retries -> false (filesystem finishes the job)' );
t_reset(); verify_on();
Q( S( 0, 0, 0, 2 ), R( 202, 'accepted' ), S( 0, 0, 0, 3 ), S( 0, 0, 0, 3 ) );
is_( nppp_http_purge_all(), false, 'queue verify: file delete errors rose -> false' );
t_reset(); verify_on();
Q( S( 0, 0, 0, 2 ), R( 202, 'accepted' ), S( 0, 0, 0, 2 ), S( 0, 0, 0, 2 ) );
is_( nppp_http_purge_all(), true, 'queue verify: pre-existing errors do not matter' );
t_reset(); verify_on( 1 );
Q( S( 0, 0 ), R( 202, 'accepted' ) );
for ( $i = 0; $i < 30; $i++ ) { Q( S( 3, 1 ) ); }
$t0 = microtime( true );
is_( nppp_http_purge_all(), false, 'queue verify: never drains -> false after budget' );
ok( microtime( true ) - $t0 < 3.5, 'queue verify: bounded wait' );

/* deferring caller ($pending passed): a queue that is verifiably still working is reported, not raced */
t_reset(); verify_on( 1 );
Q( S( 0, 0 ), R( 202, 'accepted' ) );
for ( $i = 0; $i < 30; $i++ ) { Q( S( 3, 1 ) ); }
$pend = [ 'untouched' ];
is_( nppp_http_purge_all( $pend ), false, 'pending: still busy after budget -> false' );
ok( is_array( $pend ) && $pend['status_url'] === 'http://127.0.0.1/purge_status' && $pend['host'] === 'example.com' && $pend['since'] > 0, 'pending: status url, Host and start time handed to the caller' );
ok( notice_has( 'cannot race the queue' ) && ! notice_has( 'Falling back to direct filesystem purge' ), 'pending: notice says the filesystem purge is skipped' );
is_( locks_keys(), [], 'pending: no lock armed' );
t_reset(); verify_on();
Q( S( 0, 0 ), R( 202, 'accepted' ), S( 1, 0 ), S( 0, 0 ), S( 0, 0 ) );
$pend = [ 'untouched' ];
is_( nppp_http_purge_all( $pend ), true, 'pending: queue drains inside the budget -> true' );
is_( $pend, null, 'pending: nothing pending after a proven purge' );
t_reset(); verify_on();
Q( S( 0, 0 ), R( 202, 'accepted' ), S( 0, 0 ), S( 0, 0, 1 ) );
$pend = [ 'untouched' ];
is_( nppp_http_purge_all( $pend ), false, 'pending: cleanup errors -> false' );
is_( $pend, null, 'pending: errors are NOT deferred (the filesystem purge finishes the job)' );
t_reset(); verify_on();
Q( S( 0, 0 ), R( 202, 'accepted' ), E( 'cURL error 7' ) );
$pend = [ 'untouched' ];
is_( nppp_http_purge_all( $pend ), false, 'pending: status endpoint dies -> false' );
is_( $pend, null, 'pending: a dead status endpoint is NOT deferred (nothing to gate on)' );
t_reset(); verify_on();
Q( S( 0, 0 ), R( 202, 'accepted' ), E( 'cURL error 7' ) );
is_( nppp_http_purge_all(), false, 'queue verify: status endpoint dies -> false' );
is_( locks_keys(), [], 'queue verify: poll failure arms no lock' );
t_reset(); verify_on();
Q( R( 404 ), R( 202, 'accepted' ) );
is_( nppp_http_purge_all(), false, 'queue verify: no baseline -> verification skipped, falls back' );
t_reset(); verify_on();
Q( R( 200, 'status', '{"code":"status","cache":{"oops":1}}' ), R( 202, 'accepted' ) );
is_( nppp_http_purge_all(), false, 'queue verify: malformed status JSON -> skipped' );
t_reset(); verify_on();
Q( S( 0, 0 ), R( 200, 'purged' ) );
is_( nppp_http_purge_all(), true, 'queue verify: 200 purged needs no polling' );
is_( nreq(), 2, 'queue verify: no polling for synchronous result' );
t_reset();
t_filter( 'nppp_http_purge_verify_queue', fn() => true );   // enabled but no URL configured
Q( R( 202, 'accepted' ) );
is_( nppp_http_purge_all(), false, 'queue verify: enabled without URL -> inert' );
is_( nreq(), 1, 'queue verify: inert without URL' );

/* v3: 202 completion proof, 200 cross-check, host/scheme miss trust */
function mkcache( int $n = 3 ): string {
    $d = sys_get_temp_dir() . '/npp-t-' . getmypid() . '-' . mt_rand();
    @mkdir( "$d/1/ab", 0777, true ); @mkdir( "$d/f/00", 0777, true ); @mkdir( "$d/tmp", 0777, true );
    for ( $i = 0; $i < $n; $i++ ) { touch( "$d/1/ab/" . md5( "k$i" ) ); }
    touch( "$d/tmp/" . md5( 'tmpfile' ) );      // plugin tmp dir must never count
    touch( "$d/f/00/index.html" );               // non cache name must never count
    return $d;
}
function rmcache( string $d ): void { exec( 'rm -rf ' . escapeshellarg( $d ) ); }
function cache_settings( string $d ): void { $GLOBALS['T']['options']['nginx_cache_settings']['nginx_cache_path'] = $d; }

/* scanner */
$D = mkcache( 3 );
is_( nppp_http_purge_has_entries_before( $D, time() + 5 ), true, 'scan: old file present' );
is_( nppp_http_purge_has_entries_before( $D, time() - 3600 ), false, 'scan: files newer than $since are ignored (same-second refill)' );
is_( nppp_http_purge_has_entries_before( $D . '/nope', time() + 5 ), null, 'scan: missing dir -> cannot tell' );
is_( nppp_http_purge_has_entries_before( '', time() + 5 ), null, 'scan: empty path -> cannot tell' );
exec( 'rm -f ' . escapeshellarg( $D ) . '/1/ab/*' );
is_( nppp_http_purge_has_entries_before( $D, time() + 5 ), false, 'scan: tmp dir + non-hex names do not count' );
@mkdir( "$D/1/cd", 0000 );
if ( posix_geteuid() !== 0 ) {
    is_( nppp_http_purge_has_entries_before( $D, time() + 5 ), null, 'scan: unreadable subdir -> cannot tell, never "empty"' );
} else { ok( true, 'scan: unreadable subdir skipped (root can read everything)' ); }
@chmod( "$D/1/cd", 0777 ); rmcache( $D );

/* status url accessor */
t_reset( [ 'nppp_http_purge_custom_url' => 'http://nginx-internal:8080' ] );
$GLOBALS['T']['options']['nppp_http_purge_queue_status_path'] = '/purge_status/';
is_( nppp_http_purge_queue_status_url(), 'http://nginx-internal:8080/purge_status', 'status url: option path on the purge origin' );
$GLOBALS['T']['options']['nppp_http_purge_queue_status_path'] = 'a b;c';
is_( nppp_http_purge_queue_status_url(), '', 'status url: invalid path rejected' );
t_reset();
is_( nppp_http_purge_queue_status_url(), '', 'status url: unset -> none' );

/* status url: the Settings field (nginx_cache_settings[...]) is the primary source, the standalone option is a legacy fallback */
t_reset( [ 'nppp_http_purge_custom_url' => 'http://nginx-internal:8080', 'nppp_http_purge_queue_status_path' => '/purge_status/' ] );
is_( nppp_http_purge_queue_status_url(), 'http://nginx-internal:8080/purge_status', 'status url: Settings field path (primary source)' );
$GLOBALS['T']['options']['nppp_http_purge_queue_status_path'] = 'legacy_status';
is_( nppp_http_purge_queue_status_url(), 'http://nginx-internal:8080/purge_status', 'status url: Settings field wins over the legacy option' );
t_reset( [ 'nppp_http_purge_custom_url' => 'http://nginx-internal:8080', 'nppp_http_purge_queue_status_path' => '' ] );
$GLOBALS['T']['options']['nppp_http_purge_queue_status_path'] = 'legacy_status';
is_( nppp_http_purge_queue_status_url(), 'http://nginx-internal:8080/legacy_status', 'status url: empty Settings field falls back to the legacy option' );
t_reset( [ 'nppp_http_purge_custom_url' => 'http://nginx-internal:8080', 'nppp_http_purge_queue_status_path' => 'a b;c' ] );
is_( nppp_http_purge_queue_status_url(), '', 'status url: invalid Settings field path rejected' );

/* 200 cross-check (needs files that are older than the request second) */
$D = mkcache( 3 ); sleep( 2 );
foreach ( [ 'purged', 'no_match' ] as $cd ) {
    t_reset(); cache_settings( $D ); Q( R( 200, $cd ) );
    is_( nppp_http_purge_all(), false, "200 $cd but old files remain in nginx_cache_path -> false (other zone / silent failure)" );
    ok( notice_has( 'older than this purge' ), "200 $cd leftover notice" );
    is_( locks_keys(), [], "200 $cd leftover arms no lock" );
}
t_reset(); cache_settings( $D ); Q( R( 200, '', $V3_HTML ) );
is_( nppp_http_purge_all(), false, 'v3 200 + leftover -> false' );
t_reset(); cache_settings( $D ); Q( R( 200, 'invalidated' ) );
is_( nppp_http_purge_all(), true, 'invalidated: files legitimately remain -> true, not cross-checked' );
t_reset(); cache_settings( $D ); t_filter( 'nppp_http_purge_verify_empty', fn() => false ); Q( R( 200, 'purged' ) );
is_( nppp_http_purge_all(), true, 'verify_empty filter off -> old behaviour' );
t_reset(); cache_settings( $D . '/missing' ); Q( R( 200, 'purged' ) );
is_( nppp_http_purge_all(), true, 'unreadable / missing cache path -> trust nginx' );
exec( 'rm -f ' . escapeshellarg( $D ) . '/1/ab/*' );
t_reset(); cache_settings( $D ); Q( R( 200, 'purged' ) );
is_( nppp_http_purge_all(), true, 'cache empty after 200 -> true' );
$sec = time(); touch( "$D/1/ab/" . md5( 'fresh' ) );
t_reset(); cache_settings( $D ); Q( R( 200, 'purged' ) );
if ( time() === $sec ) { is_( nppp_http_purge_all(), true, 'file created in the same second (live refill) is not "left over"' ); }
else { ok( true, 'second boundary crossed, skipped' ); }
exec( 'rm -f ' . escapeshellarg( $D ) . '/1/ab/*' );

/* 202 without a status endpoint: best effort through the cache directory */
for ( $i = 0; $i < 3; $i++ ) { touch( "$D/1/ab/" . md5( "z$i" ) ); } sleep( 2 );
t_reset(); cache_settings( $D ); Q( R( 202, 'accepted' ) );
t_filter( 'nppp_http_purge_async_settle_ms', fn() => 200 );
exec( '(sleep 0.8; rm -f ' . escapeshellarg( $D ) . '/1/ab/*) >/dev/null 2>&1 &' );
$t0 = microtime( true );
is_( nppp_http_purge_all(), true, '202, no status url: waits until nginx removed the old files -> true' );
$el = microtime( true ) - $t0;
ok( $el >= 0.7 && $el < 4, '202 wait is real and bounded (' . round( $el, 2 ) . ' s)' );
is_( nreq(), 1, '202 best effort: no extra HTTP requests' );
is_( locks_keys(), [], '202 best effort: no lock' );

for ( $i = 0; $i < 3; $i++ ) { touch( "$D/1/ab/" . md5( "y$i" ) ); } sleep( 2 );
t_reset(); cache_settings( $D ); Q( R( 202, 'accepted' ) );
t_filter( 'nppp_http_purge_queue_wait', fn() => 1 );
$t0 = microtime( true );
is_( nppp_http_purge_all(), false, '202, files never go away -> false after the budget' );
ok( microtime( true ) - $t0 < 3.5, '202 timeout bounded' );
ok( notice_has( 'not finished after 1 second' ), '202 timeout notice' );

t_reset(); cache_settings( $D ); Q( R( 202, 'accepted' ) );
t_filter( 'nppp_http_purge_verify_queue', fn() => false );
$t0 = microtime( true );
is_( nppp_http_purge_all(), false, 'verify_queue off: 202 -> immediate false (old behaviour)' );
ok( microtime( true ) - $t0 < 0.5, 'verify_queue off: no wait' );
ok( notice_has( 'verification is disabled' ), 'verify_queue off notice' );

t_reset(); Q( R( 202, 'accepted' ) );     // no cache path, no status endpoint
is_( nppp_http_purge_all(), false, '202 blind -> false' );
ok( notice_has( 'cannot be observed' ), '202 blind notice' );

/* 202 + status endpoint says idle, but the plugin cache still holds old files */
t_reset(); cache_settings( $D ); verify_on();
Q( S( 0, 0 ), R( 202, 'accepted' ), S( 0, 0 ), S( 0, 0 ) );
is_( nppp_http_purge_all(), false, '202 proven by status but old files remain (wrong zone) -> false' );
ok( notice_has( 'finished its queued purge' ), '202 proven + leftover notice' );
exec( 'rm -f ' . escapeshellarg( $D ) . '/1/ab/*' );
t_reset(); cache_settings( $D ); verify_on();
Q( S( 0, 0 ), R( 202, 'accepted' ), S( 1, 0 ), S( 0, 1 ), S( 0, 0 ), S( 0, 0 ) );
is_( nppp_http_purge_all(), true, '202 proven by status, cache empty -> true' );

/* status endpoint dies mid-wait: falls back to the directory, never to a blind true */
for ( $i = 0; $i < 2; $i++ ) { touch( "$D/1/ab/" . md5( "w$i" ) ); } sleep( 2 );
t_reset(); cache_settings( $D ); verify_on( 1 );
Q( S( 0, 0 ), R( 202, 'accepted' ), E( 'cURL error 7' ) );
is_( nppp_http_purge_all(), false, 'status dies + old files remain -> false' );

/* wait is capped by the Purge All lock TTL */
t_reset(); verify_on( 40 ); t_filter( 'nppp_purge_all_lock_ttl', fn() => 13 );
$GLOBALS['T']['filters']['nppp_http_purge_queue_wait'] = fn() => 40;
Q( S( 0, 0 ), R( 202, 'accepted' ) ); for ( $i = 0; $i < 40; $i++ ) { Q( S( 5, 1 ) ); }
$t0 = microtime( true );
is_( nppp_http_purge_all(), false, 'lock TTL cap: never drains -> false' );
ok( microtime( true ) - $t0 < 5, 'lock TTL 13 s caps the wait at ~3 s, not 40 s (' . round( microtime( true ) - $t0, 2 ) . ' s)' );
rmcache( $D );

/* miss trust: host and scheme */
t_reset(); Q( R( 200, 'no_match' ) );
is_( nppp_http_purge_try_first( 'https://example.com/a/' ), 'miss', 'miss trusted: same host + scheme, GET' );
t_reset(); Q( R( 200, 'no_match' ) );
is_( nppp_http_purge_try_first( 'https://www.example.com/a/' ), false, 'host differs from the Host header: not claimed' );
is_( nreq(), 0, 'host differs: no request is sent at all (it would purge the home host key)' );
t_reset();
is_( nppp_http_purge_try_first( 'https://EXAMPLE.com/a/' ) !== null, true, 'host compare is case-insensitive' );
is_( nreq(), 1, 'host compare is case-insensitive: request sent' );
t_reset(); Q( R( 200, 'no_match' ) );
is_( nppp_http_purge_try_first( 'http://example.com/a/' ), false, 'miss NOT trusted: scheme differs from the purge request' );
t_reset( [ 'nppp_http_purge_custom_url' => 'http://nginx-internal:8080' ] ); Q( R( 200, 'no_match' ) );
is_( nppp_http_purge_try_first( 'https://example.com/a/' ), false, 'miss NOT trusted: Custom Base URL over http for an https page' );
t_reset( [ 'nppp_http_purge_custom_url' => 'https://nginx-internal:8443' ] ); Q( R( 200, 'no_match' ) );
is_( nppp_http_purge_try_first( 'https://example.com/a/' ), 'miss', 'miss trusted: Custom Base URL, same scheme' );
t_reset(); Q( R( 412 ) );
is_( nppp_http_purge_try_first( 'https://www.example.com/a/' ), false, 'v2.5/v3 412 miss obeys the same host rule' );
t_reset(); Q( R( 200, 'no_match' ) );
is_( nppp_http_purge_try_first( 'http://example.com/a/' ), false, 'scheme differs from purge origin: miss not trusted' );
t_reset(); Q( R( 200, 'purged' ) );
is_( nppp_http_purge_try_first( 'https://www.example.com/a/' ), false, 'www URL: a "purged" for the home host key is never claimed for the www page' );

/* v4: "purged" on decode-ambiguous URLs, Purge All response timeout */
/* purged/invalidated on an ambiguous URL is not trusted: nginx decodes $1 and may have purged a different entry */
foreach ( [
    'percent path'       => 'https://example.com/caf%C3%A9/',
    'lowercase percent'  => 'https://example.com/%d0%bc%d0%b8%d1%80/',
    'encoded letter'     => 'https://example.com/p/%41/',
    'non-ascii path'     => "https://example.com/caf\xC3\xA9/",
    'non-ascii query'    => "https://example.com/p/?q=caf\xC3\xA9",
    'double slash'       => 'https://example.com/a//b/',
    'dot segment'        => 'https://example.com/a/../b/',
    'single dot'         => 'https://example.com/a/./b/',
    'backslash'          => 'https://example.com/a\\b/',
] as $label => $url ) {
    foreach ( [ [ 200, 'purged' ], [ 204, 'purged' ], [ 200, 'invalidated' ], [ 200, '' ] ] as [ $st, $cd ] ) {
        t_reset();
        Q( R( $st, $cd, $cd === '' ? $V3_HTML : '' ) );
        is_( nppp_http_purge_try_first( $url ), false, "purged on ambiguous url ($label, $st $cd) -> false so the real entry is purged on disk" );
        is_( locks_keys(), [], "ambiguous purged ($label, $st $cd) no lock" );
        if ( $cd === 'invalidated' ) {
            // generation mode: disk purge cannot match generated keys, so a notice is logged
            ok( notice_has( 'generation mode is active' ), "ambiguous invalidated ($label) reports that the disk purge cannot help" );
            is_( count( $GLOBALS['T']['notices'] ), 1, "ambiguous invalidated ($label) exactly one notice" );
            is_( nppp_http_purge_state()['ns'], [ 'single' => true ], "ambiguous invalidated ($label) generation mode remembered" );
        } else {
            is_( $GLOBALS['T']['notices'], [], "ambiguous purged ($label, $st $cd) silent" );
        }
    }
}
t_reset(); Q( R( 200, 'purged' ) );
is_( nppp_http_purge_try_first( 'https://example.com/a/?x=%41' ), true, 'percent only in the query is not ambiguous (raw $args): purged -> true' );
t_reset(); Q( R( 200, 'purged' ) );
is_( nppp_http_purge_try_first( 'https://example.com/plain/path/?a=1&b=2' ), true, 'plain url: purged -> true' );
t_reset(); Q( R( 200, 'invalidated' ) );
is_( nppp_http_purge_try_first( 'https://example.com/plain/' ), true, 'plain url: invalidated -> true' );
t_reset(); t_filter( 'nppp_http_purge_trust_purged', fn( $trust, $url, $why ) => true ); Q( R( 200, 'purged' ) );
is_( nppp_http_purge_try_first( 'https://example.com/p/%41/' ), true, 'trust_purged filter can force trust' );
t_reset(); t_filter( 'nppp_http_purge_trust_purged', fn( $trust, $url, $why ) => false ); Q( R( 200, 'purged' ) );
is_( nppp_http_purge_try_first( 'https://example.com/plain/' ), false, 'trust_purged filter can force disk verification' );
t_reset(); $seen = [];
t_filter( 'nppp_http_purge_trust_purged', function ( $trust, $url, $why ) use ( &$seen ) { $seen = [ $trust, $why ]; return $trust; } ); Q( R( 200, 'purged' ) );
nppp_http_purge_try_first( 'https://example.com/a//b/' );
is_( $seen, [ false, 'repeated slashes' ], 'trust_purged filter receives the default and the reason' );
is_( nppp_http_purge_key_ambiguity_reason( 'https://www.example.com/a/' ), '', 'ambiguity is about the key only: host/scheme are handled elsewhere' );
is_( nppp_http_purge_untrusted_miss_reason( 'https://example.com/a//b/' ), 'repeated slashes', 'miss reason still reports the ambiguity first' );

/* Purge All response timeout: waited for like a 202 (directory proof) */
$D = mkcache( 3 ); sleep( 2 );
$TO = 'cURL error 28: Operation timed out after 30001 milliseconds with 0 bytes received';

t_reset(); cache_settings( $D ); Q( E( $TO ) );
t_filter( 'nppp_http_purge_async_settle_ms', fn() => 200 );
exec( '(sleep 0.8; rm -f ' . escapeshellarg( $D ) . '/1/ab/*) >/dev/null 2>&1 &' );
$t0 = microtime( true );
is_( nppp_http_purge_all(), true, 'timeout: waits until nginx removed the old files -> true (no race with FS purge/preload)' );
$el = microtime( true ) - $t0;
ok( $el >= 0.7 && $el < 4, 'timeout wait is real and bounded (' . round( $el, 2 ) . ' s)' );
is_( nreq(), 1, 'timeout wait: no extra HTTP requests (directory proof only)' );
is_( locks_keys(), [], 'timeout wait: no lock (nginx is alive)' );
is_( $GLOBALS['T']['notices'], [], 'timeout proven by the directory: silent' );

t_reset(); cache_settings( $D ); Q( E( $TO ) ); t_filter( 'nppp_http_purge_async_settle_ms', fn() => 100 );
$t0 = microtime( true );
is_( nppp_http_purge_all(), true, 'timeout, nginx had finished: empty directory -> true' );
ok( microtime( true ) - $t0 < 3, 'timeout, finished: only the settle period is paid' );

for ( $i = 0; $i < 3; $i++ ) { touch( "$D/1/ab/" . md5( "t$i" ) ); } sleep( 2 );
t_reset(); cache_settings( $D ); Q( E( $TO ) ); t_filter( 'nppp_http_purge_queue_wait', fn() => 1 );
$t0 = microtime( true );
is_( nppp_http_purge_all(), false, 'timeout, files never go away -> false after the budget' );
ok( microtime( true ) - $t0 < 3.5, 'timeout wait bounded (' . round( microtime( true ) - $t0, 2 ) . ' s)' );
ok( notice_has( 'timed out after 30 second' ) && notice_has( 'had not finished deleting' ), 'timeout not-finished notice' );
ok( all_log_only(), 'timeout notices are log-only' );
is_( locks_keys(), [], 'timeout not finished: still no lock' );
is_( count( $GLOBALS['T']['notices'] ), 1, 'timeout: exactly one notice (the exchange stays silent for Purge All)' );

t_reset(); cache_settings( $D ); Q( E( $TO ) ); t_filter( 'nppp_http_purge_verify_queue', fn() => false );
$t0 = microtime( true );
is_( nppp_http_purge_all(), false, 'timeout, verify_queue off -> immediate false (old behaviour)' );
ok( microtime( true ) - $t0 < 0.5, 'timeout, verify_queue off: no wait' );
ok( notice_has( 'verification is disabled' ), 'timeout, verify_queue off notice' );

t_reset(); Q( E( $TO ) );   // no cache path configured
is_( nppp_http_purge_all(), false, 'timeout, directory cannot be inspected -> false' );
ok( notice_has( 'cannot be observed' ), 'timeout blind notice' );

t_reset(); cache_settings( $D ); Q( E( 'cURL error 28: Connection timed out after 3002 milliseconds' ) );
is_( nppp_http_purge_all(), false, 'connection timeout is still "unreachable": no wait' );
is_( locks_keys(), [ '*' ], 'connection timeout still arms the connection lock' );

t_reset(); cache_settings( $D ); Q( E( $TO ) ); t_filter( 'nppp_purge_all_lock_ttl', fn() => 13 );
t_filter( 'nppp_http_purge_queue_wait', fn() => 40 );
$t0 = microtime( true );
is_( nppp_http_purge_all(), false, 'timeout: lock TTL 13 s caps the wait' );
ok( microtime( true ) - $t0 < 5, 'timeout wait capped by the Purge All lock TTL, not 40 s (' . round( microtime( true ) - $t0, 2 ) . ' s)' );

/* single purge timeout keeps its own notice and does NOT wait */
t_reset(); cache_settings( $D ); Q( E( $TO ) );
$t0 = microtime( true );
is_( nppp_http_purge_try_first( $U ), false, 'single timeout -> false, no directory wait' );
ok( microtime( true ) - $t0 < 0.5, 'single timeout: no wait' );
ok( notice_has( 'timed out after 8 second' ), 'single timeout notice unchanged' );
rmcache( $D );

/* ------------------------------------------------------------------ preload gate */
t_reset(); nppp_http_purge_gate_state( 'clear' );
$plain = "wget X > '/tmp/l.log' 2>&1 < /dev/null & echo \$!";
is_( nppp_http_purge_gate_command( 'wget X', '/tmp/l.log' ), $plain, 'gate: no pending purge -> byte-identical to the command NPP always spawned' );
is_( nppp_http_purge_gate_used(), false, 'gate: not used without a pending purge' );

$pp = [ 'status_url' => 'http://127.0.0.1:8081/purge_status', 'host' => 'example.com:8081', 'armed_at' => time() ];
nppp_http_purge_gate_arm( $pp );
$g = nppp_http_purge_gate_command( 'wget X', '/tmp/l.log' );
ok( strpos( $g, "( trap '' HUP; " ) === 0 && substr( $g, -strlen( " exec wget X ) > '/tmp/l.log' 2>&1 < /dev/null & echo \$!" ) ) === " exec wget X ) > '/tmp/l.log' 2>&1 < /dev/null & echo \$!", 'gate: armed -> ( trap; <poll>; exec <crawl> ) with the same redirections and PID echo' );
ok( strpos( $g, "'http://127.0.0.1:8081/purge_status'" ) !== false && strpos( $g, "'Host: example.com:8081'" ) !== false && strpos( $g, 'use_proxy=no' ) !== false, 'gate: polls the status URL with the Host header and no proxy' );
is_( nppp_http_purge_gate_used(), true, 'gate: reports that it gated the crawl' );
is_( nppp_http_purge_gate_command( 'wget X', '/tmp/l.log' ), $plain, 'gate: the hand-off is consumed once (the next spawn is not gated)' );

nppp_http_purge_gate_arm( [ 'status_url' => 'http://127.0.0.1:8081/purge_status', 'host' => 'h', 'armed_at' => time() - 400 ] );
is_( nppp_http_purge_gate_command( 'wget X', '/tmp/l.log' ), $plain, 'gate: a stale hand-off (> 300 s) never gates an unrelated crawl' );
nppp_http_purge_gate_arm( [ 'status_url' => '', 'host' => 'h', 'armed_at' => time() ] );
is_( nppp_http_purge_gate_command( 'wget X', '/tmp/l.log' ), $plain, 'gate: no status URL -> not gated' );

t_reset(); t_filter( 'nppp_http_purge_gate_max_wait', fn() => 999999 );
ok( strpos( nppp_http_purge_gate_snippet( $pp ), '+ 7200 ' ) !== false, 'gate: maximum wait is capped at 7200 s' );
t_reset(); t_filter( 'nppp_http_purge_gate_max_wait', fn() => 1 );
ok( strpos( nppp_http_purge_gate_snippet( $pp ), '+ 30 ' ) !== false, 'gate: maximum wait has a 30 s floor' );
t_reset(); ok( strpos( nppp_http_purge_gate_snippet( $pp ), '+ 900 ' ) !== false, 'gate: default maximum wait is 900 s' );
$sn = nppp_http_purge_gate_snippet( $pp ); $rc = -1; exec( 'sh -n -c ' . escapeshellarg( $sn ) . ' 2>&1', $o, $rc );
is_( $rc, 0, 'gate: the generated shell snippet is valid POSIX sh' );
nppp_http_purge_gate_state( 'clear' );

/* ------------------------------------------------------------------ gate marker (survives the request) */
// A Purge All that left a live queue behind persists a marker; a crawl spawned by a LATER request
// (manual Preload All, REST, WP-CLI, cron, Mobile phase) is gated on it, after the status endpoint
// confirms the queue is still working. "Later request" = hand-off gone, transient kept.
function gm_marker() { return $GLOBALS['T']['options'][ NPPP_HTTP_PURGE_PENDING_KEY ] ?? null; }
function gm_set( array $over = [] ) {
    update_option( NPPP_HTTP_PURGE_PENDING_KEY, array_merge( [ 'status_url' => 'http://127.0.0.1/purge_status', 'host' => 'example.com', 'since' => time(), 'armed_at' => time() ], $over ), false );
}
function gm_gated( string $cmd ): bool { return strpos( $cmd, "( trap '' HUP; " ) === 0; }
function gm_new_request() { nppp_http_purge_gate_state( 'clear' ); $GLOBALS['T']['queue'] = []; $GLOBALS['T']['requests'] = []; }

/* the deferring Purge All persists the pending queue; nothing else does */
t_reset(); gm_new_request(); verify_on( 1 );
Q( S( 0, 0 ), R( 202, 'accepted' ) );
for ( $i = 0; $i < 30; $i++ ) { Q( S( 3, 1 ) ); }
$pend = null;
is_( nppp_http_purge_all( $pend ), false, 'marker: busy queue after the budget -> false + pending' );
ok( gm_marker() === $pend && is_array( $pend ), 'marker: the pending hand-off is persisted as is' );
is_( array_key_exists( NPPP_HTTP_PURGE_PENDING_KEY, $GLOBALS['T']['autoload'] ?? [] ) ? $GLOBALS['T']['autoload'][ NPPP_HTTP_PURGE_PENDING_KEY ] : 'unset', false, 'marker: a non-autoloaded option, so a persistent-cache flush cannot drop it' );
t_reset(); gm_new_request(); verify_on( 1 );
Q( S( 0, 0 ), R( 202, 'accepted' ) );
for ( $i = 0; $i < 30; $i++ ) { Q( S( 3, 1 ) ); }
nppp_http_purge_all();
is_( gm_marker(), null, 'marker: a caller that cannot defer (no $pending) leaves no marker' );
t_reset(); gm_new_request(); verify_on();
Q( S( 0, 0 ), R( 202, 'accepted' ), S( 1, 0 ), S( 0, 0 ), S( 0, 0 ) );
$pend = null;
is_( nppp_http_purge_all( $pend ), true, 'marker: proven purge -> true' );
is_( gm_marker(), null, 'marker: a proven purge leaves no marker' );
t_reset(); gm_new_request(); verify_on();
Q( S( 0, 0 ), R( 202, 'accepted' ), E( 'cURL error 7' ) );
$pend = null; nppp_http_purge_all( $pend );
is_( gm_marker(), null, 'marker: a dead status endpoint leaves no marker (nothing to gate on)' );

/* later request, queue still busy: gated, marker kept, a restart is gated again */
t_reset(); gm_new_request(); gm_set(); Q( S( 2, 0 ) );
$g = nppp_http_purge_gate_command( 'wget X', '/tmp/l.log' );
ok( gm_gated( $g ) && substr( $g, -strlen( " exec wget X ) > '/tmp/l.log' 2>&1 < /dev/null & echo \$!" ) ) === " exec wget X ) > '/tmp/l.log' 2>&1 < /dev/null & echo \$!", 'marker: later request while the queue is busy -> gated, same redirections and PID echo' );
ok( strpos( $g, "'http://127.0.0.1/purge_status'" ) !== false && strpos( $g, "'Host: example.com'" ) !== false, 'marker: the gate polls the persisted status URL with the persisted Host' );
is_( $GLOBALS['T']['requests'][0]['url'] ?? '', 'http://127.0.0.1/purge_status', 'marker: the queue status was asked before gating' );
is_( nppp_http_purge_gate_used(), true, 'marker: the gate reports it held the crawl back (notices)' );
ok( gm_marker() !== null, 'marker: not consumed by a gated spawn' );
gm_new_request(); Q( S( 0, 1 ) );
ok( gm_gated( nppp_http_purge_gate_command( 'wget X', '/tmp/l.log' ) ), 'marker: crawl stopped and restarted while the queue is busy -> gated again' );

/* idle twice -> plain command, marker dropped; one busy sample among idle ones still gates */
t_reset(); gm_new_request(); gm_set(); Q( S( 0, 0 ), S( 0, 0 ) );
is_( nppp_http_purge_gate_command( 'wget X', '/tmp/l.log' ), $plain, 'marker: queue idle twice -> byte-identical plain command' );
is_( nppp_http_purge_gate_used(), false, 'marker: idle queue -> no "still deleting" notice' );
is_( gm_marker(), null, 'marker: dropped once the queue is idle' );
is_( nreq(), 2, 'marker: two status samples, no more' );
t_reset(); gm_new_request(); gm_set(); Q( S( 0, 0 ), S( 1, 0 ) );
ok( gm_gated( nppp_http_purge_gate_command( 'wget X', '/tmp/l.log' ) ), 'marker: idle then busy (queue blip) -> still gated' );
ok( gm_marker() !== null, 'marker: kept after a blip' );

/* fail open: dead endpoint, expired or malformed marker, no marker at all */
t_reset(); gm_new_request(); gm_set(); Q( E( 'cURL error 7' ) );
ok( gm_gated( nppp_http_purge_gate_command( 'wget X', '/tmp/l.log' ) ), 'marker: unreadable status endpoint -> still gated (the shell gate applies its own fail-open)' );
ok( gm_marker() !== null, 'marker: kept when the endpoint could not be read' );
t_reset(); gm_new_request(); gm_set(); Q( S( 0, 0 ), E( 'cURL error 7' ) );
ok( gm_gated( nppp_http_purge_gate_command( 'wget X', '/tmp/l.log' ) ), 'marker: idle then unreadable -> gated (one idle sample is not proof)' );
t_reset(); gm_new_request(); gm_set( [ 'armed_at' => time() - 901 ] );
is_( nppp_http_purge_gate_command( 'wget X', '/tmp/l.log' ), $plain, 'marker: older than the gate maximum wait -> not gated' );
is_( nreq(), 0, 'marker: an expired marker costs no HTTP request' );
is_( gm_marker(), null, 'marker: expired marker dropped' );
t_reset(); gm_new_request(); gm_set( [ 'status_url' => '' ] );
is_( nppp_http_purge_gate_command( 'wget X', '/tmp/l.log' ), $plain, 'marker: no status URL -> not gated' );
is_( nreq(), 0, 'marker: no status URL costs no HTTP request' );
t_reset(); gm_new_request(); update_option( NPPP_HTTP_PURGE_PENDING_KEY, 'garbage', false );
is_( nppp_http_purge_gate_command( 'wget X', '/tmp/l.log' ), $plain, 'marker: malformed marker -> not gated' );
is_( gm_marker(), null, 'marker: malformed marker dropped' );
t_reset(); gm_new_request();
is_( nppp_http_purge_gate_command( 'wget X', '/tmp/l.log' ), $plain, 'marker: no marker -> byte-identical plain command' );
is_( nreq(), 0, 'marker: no marker -> zero HTTP requests, zero delay (the normal case is untouched)' );
is_( nppp_http_purge_gate_used(), false, 'marker: no marker -> not used' );

/* the in-request hand-off still wins, without asking the queue; a stale one falls back to the marker */
t_reset(); gm_new_request(); gm_set( [ 'status_url' => 'http://127.0.0.1/other' ] );
nppp_http_purge_gate_arm( $pp );
$g = nppp_http_purge_gate_command( 'wget X', '/tmp/l.log' );
ok( gm_gated( $g ) && strpos( $g, "'http://127.0.0.1:8081/purge_status'" ) !== false && strpos( $g, '/other' ) === false, 'marker: the hand-off of this request wins over the marker' );
is_( nreq(), 0, 'marker: the hand-off is trusted without an extra status request (as before)' );
t_reset(); gm_new_request(); gm_set(); Q( S( 1, 0 ) );
nppp_http_purge_gate_arm( [ 'status_url' => 'http://127.0.0.1:8081/purge_status', 'host' => 'h', 'armed_at' => time() - 400 ] );
$g = nppp_http_purge_gate_command( 'wget X', '/tmp/l.log' );
ok( gm_gated( $g ) && strpos( $g, "'http://127.0.0.1/purge_status'" ) !== false, 'marker: a stale hand-off is ignored, the live marker gates instead' );

/* a persistent-cache flush (NPP's Redis sync, any plugin) must not drop the marker: it is an option, not a transient */
t_reset(); gm_new_request(); nppp_http_purge_gate_remember( [ 'status_url' => 'http://127.0.0.1/purge_status', 'host' => 'example.com', 'since' => time(), 'armed_at' => time() ] );
$GLOBALS['T']['transients'] = []; // what wp_cache_flush() does to transients when an object cache holds them
Q( S( 2, 0 ) );
ok( gm_gated( nppp_http_purge_gate_command( 'wget X', '/tmp/l.log' ) ), 'marker: survives an object-cache flush (stop + restart is still gated)' );

/* expiry follows the gate's own bounds (armed_at + max wait), enforced on read */
t_reset(); gm_new_request(); t_filter( 'nppp_http_purge_gate_max_wait', fn() => 1800 ); gm_set( [ 'armed_at' => time() - 1000 ] ); Q( S( 1, 0 ) );
ok( gm_gated( nppp_http_purge_gate_command( 'wget X', '/tmp/l.log' ) ), 'marker: expiry follows the nppp_http_purge_gate_max_wait filter (1000 s old, limit 1800 s)' );
t_reset(); gm_new_request(); t_filter( 'nppp_http_purge_gate_max_wait', fn() => 999999 ); gm_set( [ 'armed_at' => time() - 7201 ] );
is_( nppp_http_purge_gate_command( 'wget X', '/tmp/l.log' ), $plain, 'marker: expiry capped at 7200 s like the gate' );
t_reset(); gm_new_request(); t_filter( 'nppp_http_purge_gate_max_wait', fn() => 999999 ); gm_set( [ 'armed_at' => time() - 7000 ] ); Q( S( 1, 0 ) );
ok( gm_gated( nppp_http_purge_gate_command( 'wget X', '/tmp/l.log' ) ), 'marker: still live just under the 7200 s cap' );
t_reset(); gm_new_request(); t_filter( 'nppp_http_purge_gate_max_wait', fn() => 1 ); gm_set( [ 'armed_at' => time() - 31 ] );
is_( nppp_http_purge_gate_command( 'wget X', '/tmp/l.log' ), $plain, 'marker: expiry has the same 30 s floor' );
t_reset(); nppp_http_purge_gate_remember( [ 'status_url' => '', 'host' => 'h', 'armed_at' => time() ] );
is_( gm_marker(), null, 'marker: nothing is stored without a status URL' );
gm_new_request();

/* end to end: Purge All #1 leaves its queue behind (status 7), a LATER request spawns a crawl.
   gm_helper() mirrors nppp_purge_helper(): 0 = proven, 7 = pending (hand-off armed), 'fs' = filesystem fallback. */
function gm_helper() {
    $p = null;
    if ( nppp_http_purge_all( $p ) ) { return 0; }
    if ( is_array( $p ) ) { nppp_http_purge_gate_arm( $p ); return 7; }
    return 'fs';
}
function gm_busy( int $n = 30 ) { for ( $i = 0; $i < $n; $i++ ) { Q( S( 3, 1 ) ); } }
$gm_to = 'cURL error 28: Operation timed out after 30001 milliseconds with 0 bytes received';

t_reset(); gm_new_request(); verify_on( 1 );
Q( S( 3, 1 ), R( 202, 'accepted' ) ); gm_busy();
is_( gm_helper(), 7, 'flow: Purge All #1, queue still busy -> 7 (no filesystem purge, no crawl)' );
ok( gm_marker() !== null, 'flow: the queue is remembered beyond this request' );

gm_new_request(); Q( S( 3, 1 ), R( 202, 'accepted' ) ); gm_busy();
is_( gm_helper(), 7, 'flow: later Preload All, its own Purge All is still 202 + busy -> 7 again' );
ok( gm_gated( nppp_http_purge_gate_command( 'wget X', '/tmp/l.log' ) ), 'flow: crawl gated by the re-armed hand-off' );

gm_new_request(); Q( S( 3, 1 ), E( $gm_to ), S( 3, 1 ) );
is_( gm_helper(), 'fs', 'flow: later Preload All, its Purge All times out -> filesystem fallback, nothing pending' );
ok( gm_gated( nppp_http_purge_gate_command( 'wget X', '/tmp/l.log' ) ), 'flow: crawl still gated (marker + busy queue)' );

gm_new_request(); Q( S( 3, 1 ), R( 202, 'accepted' ), E( 'cURL error 7' ), S( 3, 1 ) );
is_( gm_helper(), 'fs', 'flow: status endpoint blips during the 202 wait -> filesystem fallback, nothing pending' );
ok( gm_gated( nppp_http_purge_gate_command( 'wget X', '/tmp/l.log' ) ), 'flow: crawl still gated (marker + busy queue)' );

gm_new_request(); Q( S( 2, 0 ) );
ok( gm_gated( nppp_http_purge_gate_command( 'wget X', '/tmp/l.log' ) ), 'flow: spawn without any purge in the request (Mobile phase, CLI mobile) while busy -> gated' );

gm_new_request(); Q( S( 3, 1 ), R( 202, 'accepted' ), S( 0, 0 ), S( 0, 0 ), S( 0, 0 ), S( 0, 0 ) );
is_( gm_helper(), 0, 'flow: later Preload All, queue drained inside the budget -> proven (0)' );
is_( nppp_http_purge_gate_command( 'wget X', '/tmp/l.log' ), $plain, 'flow: idle queue -> plain crawl, no false gate' );
is_( gm_marker(), null, 'flow: the marker is dropped once the queue is idle' );
gm_new_request();

/* ------------------------------------------------------------------ summary */
echo "\n$PASS passed, $FAIL failed\n";
exit( $FAIL ? 1 : 0 );
