<?php
/**
 * Minimal WordPress + official Cloudflare plugin stub for the Cloudflare sync harness (cloudflare-test/).
 *
 * Loads the REAL includes/state.php and includes/compat-cloudflare.php. WordPress is reduced to what they use:
 * options (with a write log), a hook registry that really dispatches, cron events, wp_remote_request() with a
 * scripted transport, and a $wpdb whose delete() reports rows affected (the atomic claim). The official plugin is
 * faked in BOTH namespaces ( Cloudflare\APO\ and CF\ ); cf_fake_install( 'new' | 'old' | 'both' | 'none' ).
 *
 * State of one test lives in $GLOBALS['T']; t_reset() returns it to "plugin just loaded".
 */
error_reporting( E_ALL );
ini_set( 'display_errors', '1' );

define( 'ABSPATH', '/tmp/' );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'DAY_IN_SECONDS', 86400 );
define( 'PHP_QUERY_RFC3986_FALLBACK', 2 );

class WP_Error {
    public $code; public $message;
    public function __construct( $code = '', $message = '' ) { $this->code = $code; $this->message = $message; }
    public function get_error_messages() { return array( $this->message ); }
}
function is_wp_error( $x ) { return $x instanceof WP_Error; }

$GLOBALS['T'] = array();
function t_fresh(): array {
    return array(
        'options' => array(), 'writes' => array(), 'actions' => array(), 'filters' => array(),
        'cron' => array(), 'reqs' => array(), 'scripts' => array(), 'logs' => array(),
        'home' => 'http://example.com', 'doing_cron' => false,
        'cf' => array( 'key' => 'tok_secret_value_123456', 'email' => 'owner@example.com', 'domain' => 'example.com',
                       'settings' => array( 'automatic_platform_optimization' => array( 'value' => 'on' ) ), 'global' => false ),
        'deleted_rows' => 0,
    );
}

/* ---- options ---------------------------------------------------------------------------------------------- */
function get_option( $name, $default = false ) { global $T; return array_key_exists( $name, $T['options'] ) ? $T['options'][ $name ] : $default; }
function update_option( $name, $value, $autoload = null ) { global $T; $T['options'][ $name ] = $value; $T['writes'][] = $name; return true; }
function delete_option( $name ) { global $T; unset( $T['options'][ $name ] ); return true; }
function wp_cache_delete( $k, $g = '' ) { return true; }
class T_Wpdb { public $options = 'wp_options';
    public function delete( $table, $where, $fmt = null ) { global $T; $n = $where['option_name'];
        if ( array_key_exists( $n, $T['options'] ) ) { unset( $T['options'][ $n ] ); return 1; } return 0; } }
$GLOBALS['wpdb'] = new T_Wpdb();

/* ---- hooks ------------------------------------------------------------------------------------------------ */
function add_action( $tag, $cb, $prio = 10, $args = 1 ) { global $T; $T['actions'][ $tag ][ $prio ][] = array( $cb, $args ); return true; }
function add_filter( $tag, $cb, $prio = 10, $args = 1 ) { global $T; $T['filters'][ $tag ][ $prio ][] = array( $cb, $args ); return true; }
function remove_action( $tag, $cb, $prio = 10 ) { global $T;
    foreach ( $T['actions'][ $tag ][ $prio ] ?? array() as $i => $e ) { if ( $e[0] === $cb ) { unset( $T['actions'][ $tag ][ $prio ][ $i ] ); } } return true; }
function do_action( $tag, ...$args ) { global $T; $map = $T['actions'][ $tag ] ?? array(); ksort( $map );
    foreach ( $map as $list ) { foreach ( $list as $e ) { call_user_func_array( $e[0], array_slice( $args, 0, $e[1] ) ); } } }
function apply_filters( $tag, $value, ...$args ) { global $T; $map = $T['filters'][ $tag ] ?? array(); ksort( $map );
    foreach ( $map as $list ) { foreach ( $list as $e ) { $value = call_user_func_array( $e[0], array_slice( array_merge( array( $value ), $args ), 0, $e[1] ) ); } } return $value; }

/* ---- cron ------------------------------------------------------------------------------------------------- */
function wp_next_scheduled( $hook ) { global $T; $ts = array(); foreach ( $T['cron'] as $e ) { if ( $e['hook'] === $hook ) { $ts[] = $e['ts']; } } return $ts ? min( $ts ) : false; }
function wp_schedule_single_event( $ts, $hook ) { global $T; $T['cron'][] = array( 'ts' => $ts, 'hook' => $hook ); return true; }
function wp_unschedule_event( $ts, $hook ) { global $T; foreach ( $T['cron'] as $i => $e ) { if ( $e['hook'] === $hook && $e['ts'] === $ts ) { unset( $T['cron'][ $i ] ); } } $T['cron'] = array_values( $T['cron'] ); return true; }
function wp_unschedule_hook( $hook ) { global $T; $T['cron'] = array_values( array_filter( $T['cron'], function ( $e ) use ( $hook ) { return $e['hook'] !== $hook; } ) ); return 1; }
function wp_doing_cron() { return $GLOBALS['T']['doing_cron']; }
function t_cron_events( string $hook ): array { return array_values( array_filter( $GLOBALS['T']['cron'], function ( $e ) use ( $hook ) { return $e['hook'] === $hook; } ) ); }
function t_run_cron( string $hook ): void { wp_unschedule_hook( $hook ); do_action( $hook ); }

/* ---- misc WordPress functions ------------------------------------------------------------------------------ */
function home_url( $p = '' ) { return $GLOBALS['T']['home'] . $p; }
function trailingslashit( $s ) { return rtrim( $s, '/\\' ) . '/'; }
function wp_json_encode( $v, $f = 0 ) { return json_encode( $v, $f ); }
function sanitize_text_field( $s ) { return trim( strip_tags( (string) $s ) ); }
function __( $s, $d = '' ) { return $s; }
function wp_parse_url( $url, $component = -1 ) { return parse_url( $url, $component ); }
function wp_remote_retrieve_response_code( $r ) { return $r['response']['code'] ?? 0; }
function wp_remote_retrieve_body( $r ) { return $r['body'] ?? ''; }
function wp_remote_retrieve_header( $r, $h ) { return $r['headers'][ strtolower( $h ) ] ?? ''; }
function nppp_display_admin_notice( $type, $message, $log_only = false, $show = true ) { $GLOBALS['T']['logs'][] = $type . ': ' . $message; }

/* ---- scripted transport ------------------------------------------------------------------------------------ */
/** t_script( '#POST .*purge_cache#', array( 'code' => 200, 'json' => array(...), 'headers' => array(), 'delay' => 0.0 ) ) */
function t_script( string $regex, $response ): void { $GLOBALS['T']['scripts'][] = array( $regex, $response ); }
function t_ok( $result = array( 'id' => 'x' ) ): array { return array( 'code' => 200, 'json' => array( 'success' => true, 'errors' => array(), 'messages' => array(), 'result' => $result ) ); }
function t_zone_ok( string $name = 'example.com', string $id = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa' ): array { return t_ok( array( array( 'id' => $id, 'name' => $name ) ) ); }
function t_err( int $code, int $cf_code = 0, string $msg = 'x', array $headers = array() ): array {
    return array( 'code' => $code, 'headers' => $headers, 'json' => array( 'success' => false, 'errors' => array( array( 'code' => $cf_code, 'message' => $msg ) ), 'result' => null ) ); }
function wp_remote_request( $url, $args = array() ) {
    global $T;
    $T['reqs'][] = array( 'method' => $args['method'] ?? 'GET', 'url' => $url, 'args' => $args, 'body' => isset( $args['body'] ) ? json_decode( $args['body'], true ) : null );
    $key = ( $args['method'] ?? 'GET' ) . ' ' . $url;
    for ( $i = count( $T['scripts'] ) - 1; $i >= 0; $i-- ) {
        if ( preg_match( $T['scripts'][ $i ][0], $key ) ) {
            $r = $T['scripts'][ $i ][1];
            if ( is_callable( $r ) ) { $r = $r( $url, $args ); }
            if ( $r instanceof WP_Error ) { return $r; }
            if ( ! empty( $r['delay'] ) ) { usleep( (int) ( $r['delay'] * 1000000 ) ); }
            $hdrs = array(); foreach ( $r['headers'] ?? array() as $k => $v ) { $hdrs[ strtolower( $k ) ] = $v; }
            return array( 'response' => array( 'code' => $r['code'] ), 'body' => isset( $r['json'] ) ? json_encode( $r['json'] ) : ( $r['body'] ?? '' ), 'headers' => $hdrs );
        }
    }
    return new WP_Error( 'no_script', 'no script for ' . $key );
}
function t_reqs( string $re = '#.#' ): array { return array_values( array_filter( $GLOBALS['T']['reqs'], function ( $r ) use ( $re ) { return preg_match( $re, $r['method'] . ' ' . $r['url'] ) === 1; } ) ); }
function t_logs( string $re = '#.#' ): array { return array_values( array_filter( $GLOBALS['T']['logs'], function ( $l ) use ( $re ) { return preg_match( $re, $l ) === 1; } ) ); }

/* ---- fake official plugin (both namespaces) ----------------------------------------------------------------- */
function cf_fake_install( string $mode ): void {
    if ( ! defined( 'CLOUDFLARE_PLUGIN_DIR' ) && $mode !== 'none' ) {
        $dir = sys_get_temp_dir() . '/cf-fake-plugin';
        if ( ! is_dir( $dir ) ) { mkdir( $dir ); }
        file_put_contents( $dir . '/config.json', '{"debug":false}' );
        define( 'CLOUDFLARE_PLUGIN_DIR', $dir );
    }
}
function cf_define_classes( string $ns ): void {
    $code = <<<'PHP'
namespace __NS__\Integration { class DefaultConfig { public function __construct( $raw = '[]' ) {} public function getValue( $k ) { return false; } }
  class DefaultLogger { public function __construct( $debug = false ) {} }
  class DefaultIntegration { public function __construct( $c, $a, $d, $l ) {} } }
namespace __NS__\WordPress {
  class DataStore { public function __construct( $logger ) {}
    public function getClientV4APIKey() { return $GLOBALS['T']['cf']['key']; }
    public function getCloudFlareEmail() { return $GLOBALS['T']['cf']['email']; }
    public function getPluginSetting( $id ) { return $GLOBALS['T']['cf']['settings'][ $id ] ?? false; } }
  class WordPressAPI { public function __construct( $ds ) {}
    public function getDomainList() { $d = $GLOBALS['T']['cf']['domain']; return $d === '' ? array() : array( $d ); } }
  class WordPressClientAPI { public function __construct( $i ) {}
    public function beforeSend( \__NS__\API\Request $r ) {
        $k = $GLOBALS['T']['cf']['key']; $h = array( 'Content-Type' => 'application/json', 'User-Agent' => 'fake' );
        if ( $GLOBALS['T']['cf']['global'] ) { $h['X-Auth-Email'] = $GLOBALS['T']['cf']['email']; $h['X-Auth-Key'] = $k; } else { $h['Authorization'] = 'Bearer ' . $k; }
        $r->setHeaders( $h ); return $r; } }
  class Hooks {} }
namespace __NS__\API { class Request { private $h = array(); public function __construct( $m, $u, $p, $b ) {}
  public function setHeaders( $h ) { $this->h = $h; } public function getHeaders() { return $this->h; } } }
PHP;
    eval( str_replace( '__NS__', $ns, $code ) );
}
