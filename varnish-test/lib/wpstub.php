<?php
/**
 * Minimal WordPress stub for the Varnish / Vinyl Cache sync harness (varnish-test/).
 *
 * Loads the REAL plugin files (includes/state.php, includes/purge-http.php, includes/compat-varnish.php) on top of
 * just enough WordPress: options, a hook registry that really dispatches, apply_filters, wp_parse_url, WP_Error and
 * a wp_remote_request() with two transports:
 *
 *   scripted  vt_script( '#regex#', response )  - deterministic, no sockets. The regex is matched against
 *                                                 "METHOD url"; the NEWEST matching script wins. A response is
 *                                                 [ 'code' => 200, 'body' => '', 'headers' => [] ], a WP_Error,
 *                                                 or a callable( $url, $args ) returning one of those (may throw).
 *   real      no script matches -> a real curl request. http_api_curl is fired with the real handle exactly like
 *             WP_Http_Curl does, so nppp_varnish_curl_connect_timeout() acts on a real socket.
 *
 * Fidelity notes (what this stub does NOT reproduce):
 *  - WordPress' Requests library is not used. Real WordPress rewrites the request target on the wire (upper-case
 *    percent hex, decoded unreserved escapes); plain curl does not. $VT['wire_wp'] = true emulates that rewrite
 *    for the e2e "why escalation exists" control. It is an emulation of what compat-varnish.php documents.
 *  - wp_parse_url() is WordPress' own algorithm (placeholder handling for "//host" and "/path").
 *
 * The state of one test lives in the global $VT. vt_reset() returns it to "plugin just loaded": hook registrations
 * made by the plugin files are restored from the snapshot taken in vt_load(), everything a test added is dropped.
 */

error_reporting( E_ALL );
ini_set( 'display_errors', '1' );

if ( ! defined( 'ABSPATH' ) )          { define( 'ABSPATH', '/tmp/' ); }
if ( ! defined( 'MINUTE_IN_SECONDS' ) ) { define( 'MINUTE_IN_SECONDS', 60 ); }
if ( ! defined( 'HOUR_IN_SECONDS' ) )   { define( 'HOUR_IN_SECONDS', 3600 ); }
if ( ! defined( 'DAY_IN_SECONDS' ) )    { define( 'DAY_IN_SECONDS', 86400 ); }

class WP_Error {
    public $code; public $message;
    public function __construct( $code = '', $message = '' ) { $this->code = $code; $this->message = $message; }
    public function get_error_code()     { return $this->code; }
    public function get_error_message()  { return $this->message; }
    public function get_error_messages() { return [ $this->message ]; }
}

/* ============================================================================================================
 * Test state
 * ========================================================================================================= */
$GLOBALS['VT'] = [];

function vt_fresh_state(): array {
    return [
        'options'     => [],          // option name => value (nginx_cache_settings is an array)
        'transients'  => [],
        'autoload'    => [],
        'actions'     => $GLOBALS['VT_BASE_ACTIONS'] ?? [],   // tag => prio => [ [ callback, accepted_args ], ... ]
        'scripts'     => [],          // [ [ regex, response ], ... ] (newest last)
        'reqs'        => [],          // every wp_remote_request() call
        'notices'     => [],          // "type: message" strings (what the plugin logged)
        'notice_rows' => [],          // [ type, message, log, show ]
        'home'        => 'http://example.com',
        'stack'       => [],          // current_action() stack
        'wire_wp'     => false,       // emulate WordPress' request-target rewrite on the real transport
        'settings_errors' => [],
    ];
}

/** Back to "plugin just loaded", site = http://example.com, no options, no scripts. */
function vt_reset(): void {
    $GLOBALS['VT'] = vt_fresh_state();
}

/** Merge keys into the nginx_cache_settings option. */
function vt_set( array $settings ): void {
    global $VT;
    $cur = $VT['options']['nginx_cache_settings'] ?? [];
    $VT['options']['nginx_cache_settings'] = array_merge( is_array( $cur ) ? $cur : [], $settings );
}

function vt_home( string $url ): void { $GLOBALS['VT']['home'] = $url; }

/** Script a response for requests whose "METHOD url" matches $regex (newest script wins). */
function vt_script( string $regex, $response ): void { $GLOBALS['VT']['scripts'][] = [ $regex, $response ]; }

function vt_notes(): string { return implode( "\n", $GLOBALS['VT']['notices'] ); }

/** Directory of the plugin's includes/ (override: NPPP_INCLUDES). */
function vt_includes(): string {
    $d = getenv( 'NPPP_INCLUDES' ) ?: dirname( __DIR__, 2 ) . '/includes';
    $r = realpath( $d );
    if ( $r === false || ! is_file( $r . '/compat-varnish.php' ) ) {
        fwrite( STDERR, "compat-varnish.php not found in '$d' - set NPPP_INCLUDES=/path/to/includes\n" );
        exit( 2 );
    }
    return $r;
}

/**
 * Loads the real plugin code.
 *
 * @param bool $http_helpers true: also load purge-http.php (compat-varnish.php then delegates to NPP's own
 *                           helpers, as in production); false: compat-varnish.php alone, to exercise its fallbacks.
 */
function vt_load( bool $http_helpers = true ): void {
    $d = vt_includes();
    vt_reset();
    require_once $d . '/state.php';
    if ( $http_helpers ) {
        require_once $d . '/purge-http.php';
    }
    require_once $d . '/compat-varnish.php';
    if ( is_file( $d . '/varnish-probe.php' ) ) {
        require_once $d . '/varnish-probe.php';   // read-only lookup of the Advanced tab (Varnish-only mode)
    }
    $GLOBALS['VT_BASE_ACTIONS'] = $GLOBALS['VT']['actions'];   // what the plugin files registered
    vt_reset();
}

/* ============================================================================================================
 * Assertions
 * ========================================================================================================= */
$GLOBALS['VT_PASS'] = 0; $GLOBALS['VT_FAIL'] = 0; $GLOBALS['VT_KNOWN'] = 0; $GLOBALS['VT_SKIP'] = 0;

function chk( $cond, string $name, $detail = '' ): void {
    if ( $cond ) { $GLOBALS['VT_PASS']++; return; }
    $GLOBALS['VT_FAIL']++;
    // $detail may be a Closure: it runs only on failure. A detail that does HTTP (layers()) must never run while the
    // assertion passes, because its GET through the edge re-fills Varnish and corrupts the NEXT assertion.
    $detail = $detail instanceof \Closure ? (string) $detail() : (string) $detail;
    echo 'FAIL: ' . $name . ( $detail !== '' ? "\n      -> " . str_replace( "\n", "\n         ", $detail ) : '' ) . "\n";
}

/**
 * Documents behaviour that is a KNOWN limit / design gap of the plugin (not a bug in the test).
 * It passes while the behaviour is still as documented. If the plugin changes and the condition no longer holds,
 * the test FAILS, so a fixed gap (or a regression that hides one) forces this line to be updated.
 */
function known( $cond, string $name, $detail = '' ): void {
    if ( $cond ) { $GLOBALS['VT_KNOWN']++; echo 'KNOWN: ' . $name . "\n"; return; }
    $GLOBALS['VT_FAIL']++;
    $detail = $detail instanceof \Closure ? (string) $detail() : (string) $detail;
    echo 'FAIL: KNOWN-BEHAVIOUR CHANGED: ' . $name . ( $detail !== '' ? "\n      -> $detail" : '' ) . "\n      (the documented gap no longer holds: update the test and the docs)\n";
}

function skip( string $name ): void { $GLOBALS['VT_SKIP']++; echo 'SKIP: ' . $name . "\n"; }

function vt_summary(): int {
    printf( "%d passed, %d failed, %d known-limit documented, %d skipped\n", $GLOBALS['VT_PASS'], $GLOBALS['VT_FAIL'], $GLOBALS['VT_KNOWN'], $GLOBALS['VT_SKIP'] );
    return $GLOBALS['VT_FAIL'] === 0 ? 0 : 1;
}

/* ============================================================================================================
 * Hooks
 * ========================================================================================================= */
function add_action( $tag, $cb, $prio = 10, $args = 1 ) {
    $GLOBALS['VT']['actions'][ $tag ][ $prio ][] = [ $cb, (int) $args ];
    return true;
}
function add_filter( $tag, $cb, $prio = 10, $args = 1 ) { return add_action( $tag, $cb, $prio, $args ); }

function remove_action( $tag, $cb, $prio = 10 ) {
    $found = false;
    foreach ( $GLOBALS['VT']['actions'][ $tag ][ $prio ] ?? [] as $i => $x ) {
        if ( $x[0] === $cb ) { unset( $GLOBALS['VT']['actions'][ $tag ][ $prio ][ $i ] ); $found = true; }
    }
    if ( isset( $GLOBALS['VT']['actions'][ $tag ][ $prio ] ) ) {
        $GLOBALS['VT']['actions'][ $tag ][ $prio ] = array_values( $GLOBALS['VT']['actions'][ $tag ][ $prio ] );
    }
    return $found;
}
function remove_filter( $tag, $cb, $prio = 10 ) { return remove_action( $tag, $cb, $prio ); }
function remove_all_filters( $tag, $prio = false ) {
    if ( $prio === false ) { unset( $GLOBALS['VT']['actions'][ $tag ] ); } else { unset( $GLOBALS['VT']['actions'][ $tag ][ $prio ] ); }
    return true;
}
function remove_all_actions( $tag, $prio = false ) { return remove_all_filters( $tag, $prio ); }

function has_action( $tag, $cb = false ) {
    foreach ( $GLOBALS['VT']['actions'][ $tag ] ?? [] as $prio => $list ) {
        foreach ( $list as $x ) { if ( $cb === false || $x[0] === $cb ) { return $prio; } }
    }
    return false;
}

function vt_dispatch( string $tag, array $args, bool $is_filter ) {
    global $VT;
    $VT['stack'][] = $tag;
    $value = $args[0] ?? null;
    try {
        $prios = $VT['actions'][ $tag ] ?? [];
        ksort( $prios );
        foreach ( $prios as $list ) {
            foreach ( $list as $x ) {
                [ $cb, $n ] = $x;
                $call = $is_filter ? array_merge( [ $value ], array_slice( $args, 1 ) ) : $args;
                $r    = $cb( ...array_slice( $call, 0, $n ) );
                if ( $is_filter ) { $value = $r; }
            }
        }
    } finally {
        array_pop( $VT['stack'] );
    }
    return $value;
}
function do_action( $tag, ...$args ) { vt_dispatch( $tag, $args, false ); }
function apply_filters( $tag, $value, ...$args ) { return vt_dispatch( $tag, array_merge( [ $value ], $args ), true ); }
function current_action() { $s = $GLOBALS['VT']['stack']; return $s ? end( $s ) : false; }

/* ============================================================================================================
 * Options, transients, i18n, URL helpers
 * ========================================================================================================= */
function get_option( $k, $d = false ) { return array_key_exists( $k, $GLOBALS['VT']['options'] ) ? $GLOBALS['VT']['options'][ $k ] : $d; }
function update_option( $k, $v, $autoload = null ) { $GLOBALS['VT']['options'][ $k ] = $v; $GLOBALS['VT']['autoload'][ $k ] = $autoload; return true; }
function add_option( $k, $v = '', $dep = '', $autoload = 'yes' ) { if ( array_key_exists( $k, $GLOBALS['VT']['options'] ) ) { return false; } return update_option( $k, $v, $autoload ); }
function delete_option( $k ) { unset( $GLOBALS['VT']['options'][ $k ], $GLOBALS['VT']['autoload'][ $k ] ); return true; }
function get_transient( $k ) { return $GLOBALS['VT']['transients'][ $k ] ?? false; }
function set_transient( $k, $v, $t = 0 ) { $GLOBALS['VT']['transients'][ $k ] = $v; return true; }
function delete_transient( $k ) { unset( $GLOBALS['VT']['transients'][ $k ] ); return true; }
function wp_cache_delete( $k, $g = '' ) { return true; }

function __( $s, $d = 'default' ) { return $s; }
function _n( $single, $plural, $n, $d = 'default' ) { return ( (int) $n === 1 ) ? $single : $plural; }
function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_url_raw( $u ) { return $u; }
/** Close to WordPress' _sanitize_text_fields( $s, true ): strips tags and %XX octets, trims, keeps newlines. */
function sanitize_textarea_field( $s ) {
    $s = strip_tags( (string) $s );
    $s = preg_replace( '/%[a-f0-9]{2}/i', '', $s );
    return trim( $s );
}
function sanitize_text_field( $s ) { return trim( preg_replace( '/[\r\n\t ]+/', ' ', strip_tags( (string) $s ) ) ); }
function add_settings_error( $setting, $code, $message, $type = 'error' ) { $GLOBALS['VT']['settings_errors'][] = [ $setting, $code, $message, $type ]; }

function home_url( $path = '' ) { return rtrim( $GLOBALS['VT']['home'], '/' ) . ( $path !== '' ? '/' . ltrim( $path, '/' ) : '' ); }

/** WordPress' wp_parse_url() (wp-includes/http.php): placeholder handling for "//host/x" and "/x". */
function wp_parse_url( $url, $component = -1 ) {
    $to_unset = [];
    $url      = (string) $url;

    if ( strpos( $url, '//' ) === 0 ) {
        $to_unset[] = 'scheme';
        $url        = 'placeholder:' . $url;
    } elseif ( strpos( $url, '/' ) === 0 ) {
        $to_unset[] = 'scheme';
        $to_unset[] = 'host';
        $url        = 'placeholder://placeholder' . $url;
    }

    $parts = parse_url( $url );
    if ( $parts === false ) {
        return false;
    }
    foreach ( $to_unset as $key ) { unset( $parts[ $key ] ); }

    if ( $component === -1 ) { return $parts; }
    $map = [ PHP_URL_SCHEME => 'scheme', PHP_URL_HOST => 'host', PHP_URL_PORT => 'port', PHP_URL_USER => 'user', PHP_URL_PASS => 'pass', PHP_URL_PATH => 'path', PHP_URL_QUERY => 'query', PHP_URL_FRAGMENT => 'fragment' ];
    return $parts[ $map[ $component ] ?? '' ] ?? null;
}

/* The plugin's own log sink (defined elsewhere in production; compat-varnish.php only calls it when it exists). */
function nppp_display_admin_notice( $type, $message, $log = true, $show = true ) {
    $GLOBALS['VT']['notices'][]     = $type . ': ' . $message;
    $GLOBALS['VT']['notice_rows'][] = [ $type, $message, $log, $show ];
}

/* The current admin screen. Tests switch it with $GLOBALS['VT_SCREEN']; the default is NPP's own settings screen. */
function get_current_screen() {
    return (object) [ 'id' => $GLOBALS['VT_SCREEN'] ?? 'settings_page_nginx_cache_settings' ];
}

/* ============================================================================================================
 * HTTP API
 * ========================================================================================================= */
function is_wp_error( $x ) { return $x instanceof WP_Error; }

/** Normalises a scripted / real response into the WordPress array shape. */
function vt_wp_response( array $r ): array {
    return [
        'headers'  => $r['headers'] ?? [],
        'body'     => (string) ( $r['body'] ?? '' ),
        'response' => [ 'code' => (int) ( $r['code'] ?? 0 ), 'message' => '' ],
    ];
}
function wp_remote_retrieve_response_code( $r ) { return is_array( $r ) ? ( $r['response']['code'] ?? '' ) : ''; }
function wp_remote_retrieve_body( $r )          { return is_array( $r ) ? ( $r['body'] ?? '' ) : ''; }
function wp_remote_retrieve_header( $r, $name ) { return is_array( $r ) ? ( array_change_key_case( $r['headers'] ?? [] )[ strtolower( $name ) ] ?? '' ) : ''; }

/** What WordPress' Requests library does to the request target (documented in compat-varnish.php). */
function vt_wire_rewrite( string $url ): string {
    $p = parse_url( $url );
    if ( ! is_array( $p ) || ! isset( $p['path'] ) ) { return $url; }
    $fix = static function ( string $s ): string {
        // unreserved escapes are decoded, every other escape is re-emitted with UPPER-CASE hex
        return preg_replace_callback( '/%([0-9a-fA-F]{2})/', static function ( $m ) {
            $c = chr( hexdec( $m[1] ) );
            return preg_match( '/[A-Za-z0-9\-._~]/', $c ) ? $c : '%' . strtoupper( $m[1] );
        }, $s );
    };
    $path  = $fix( $p['path'] );
    $query = isset( $p['query'] ) ? '?' . $fix( $p['query'] ) : '';
    $auth  = ( $p['scheme'] ?? 'http' ) . '://' . ( $p['host'] ?? '' ) . ( isset( $p['port'] ) ? ':' . $p['port'] : '' );
    return $auth . $path . $query;
}

/** Real curl transport, shaped like WP_Http_Curl (timeouts in ms, http_api_curl fired with the live handle). */
function vt_real_request( string $url, array $args ) {
    if ( ! empty( $GLOBALS['VT']['wire_wp'] ) ) { $url = vt_wire_rewrite( $url ); }

    $ch = curl_init( $url );
    $headers = [];
    foreach ( (array) ( $args['headers'] ?? [] ) as $k => $v ) { $headers[] = $k . ': ' . $v; }
    $ms    = max( 1, (int) round( (float) ( $args['timeout'] ?? 5 ) * 1000 ) );
    $limit = (int) ( $args['limit_response_size'] ?? 0 );
    $body  = '';
    $resp_headers = [];

    curl_setopt_array( $ch, [
        CURLOPT_CUSTOMREQUEST   => (string) ( $args['method'] ?? 'GET' ),
        CURLOPT_HTTPHEADER      => $headers,
        CURLOPT_RETURNTRANSFER  => true,
        CURLOPT_FOLLOWLOCATION  => ! empty( $args['redirection'] ),
        CURLOPT_MAXREDIRS       => (int) ( $args['redirection'] ?? 0 ),
        CURLOPT_CONNECTTIMEOUT_MS => $ms,            // WordPress: connect timeout = overall timeout ...
        CURLOPT_TIMEOUT_MS      => $ms,
        CURLOPT_SSL_VERIFYPEER  => ! empty( $args['sslverify'] ),
        CURLOPT_SSL_VERIFYHOST  => ! empty( $args['sslverify'] ) ? 2 : 0,
        CURLOPT_USERAGENT       => 'WordPress/vt-stub',
        CURLOPT_HEADERFUNCTION  => static function ( $c, $line ) use ( &$resp_headers ) {
            if ( strpos( $line, ':' ) !== false ) { [ $k, $v ] = explode( ':', $line, 2 ); $resp_headers[ strtolower( trim( $k ) ) ] = trim( $v ); }
            return strlen( $line );
        },
        CURLOPT_WRITEFUNCTION   => static function ( $c, $chunk ) use ( &$body, $limit ) {
            $body .= $chunk;
            if ( $limit > 0 && strlen( $body ) > $limit ) { $body = substr( $body, 0, $limit ); return -1; }
            return strlen( $chunk );
        },
    ] );

    do_action( 'http_api_curl', $ch, $args, $url );   // ... then the plugin's callback shortens the connect phase

    curl_exec( $ch );
    $errno = curl_errno( $ch );
    $error = curl_error( $ch );
    $code  = (int) curl_getinfo( $ch, CURLINFO_RESPONSE_CODE );
    curl_close( $ch );

    $truncated = $limit > 0 && strlen( $body ) >= $limit;
    if ( $errno && ! ( $truncated && $code > 0 ) ) {
        return new WP_Error( 'http_request_failed', 'cURL error ' . $errno . ': ' . $error );
    }
    return vt_wp_response( [ 'code' => $code, 'body' => $body, 'headers' => $resp_headers ] );
}

function wp_remote_request( $url, $args = [] ) {
    global $VT;
    $method = (string) ( $args['method'] ?? 'GET' );
    $rec    = [
        'method'      => $method,
        'url'         => $url,
        'args'        => $args,
        'headers'     => (array) ( $args['headers'] ?? [] ),
        // was the plugin's connect-timeout callback attached while this request ran?
        'curl_hooked' => has_action( 'http_api_curl', 'nppp_varnish_curl_connect_timeout' ) !== false,
    ];
    $VT['reqs'][] = $rec;

    $subject = $method . ' ' . $url;
    for ( $i = count( $VT['scripts'] ) - 1; $i >= 0; $i-- ) {
        [ $rx, $resp ] = $VT['scripts'][ $i ];
        if ( ! preg_match( $rx, $subject ) ) { continue; }
        if ( is_callable( $resp ) ) { $resp = $resp( $url, $args ); }
        if ( $resp instanceof WP_Error ) { return $resp; }
        return vt_wp_response( (array) $resp );
    }

    // No script: real socket, except for the reserved ".test" names (a missing script is a test bug, not a DNS lookup).
    $host = (string) ( parse_url( $url, PHP_URL_HOST ) ?? '' );
    if ( substr( $host, -5 ) === '.test' ) {
        return new WP_Error( 'http_request_failed', 'cURL error 6: no script for ' . $subject . ' (test bug)' );
    }
    return vt_real_request( $url, $args );
}

vt_reset();
