<?php
/**
 * F-01 tests: the deferred "Purge All finished" follow-up (nppp_purged_all) while nginx's queue is still deleting.
 *
 * Usage: php purged-all-deferred.php path/to/includes/purge-http.php
 *
 * Background. A Purge All that ends with status 7 (202 accepted, nginx's background queue still deleting, no
 * filesystem purge) has NOT purged the origin yet. Unpatched, nppp_purge() fired nppp_purged_all anyway, so
 * Cloudflare purge-all (and the Redis flush) ran while nginx still held the stale files: the edge re-fetched stale
 * HTML from the origin and cached it again, and "Purge All" looked successful.
 *
 * Patched design (purge-http.php 6c, watchdog.php, main plugin file EP2/EP8):
 *   - nppp_purge() calls nppp_http_purge_fire_purged_all( $status, $auto_preload ), the ONE place that decides
 *     "fire now" or "defer".
 *   - Deferred: one option row (token, auto_preload, armed_at, waiter) + a WP-Cron fail-safe + optionally a detached
 *     shell waiter that polls the queue and then POSTs a one-shot token to admin-ajax.php (nppp_purged_all_wake).
 *   - Whoever deletes the option row first (atomic DELETE) fires the hook: at most once.
 *
 * What is covered (real plugin functions, loaded by extraction where the file needs WordPress):
 *   A. fire-or-defer decision                   nppp_http_purge_fire_purged_all / _defer_purged_all
 *   B. re-arm / supersede of a pending chain
 *   C. waiter command (spawn rules, argument round-trip, REAL shell execution against stub wget/sleep/date)
 *   D. cron fail-safe                           nppp_http_purge_run_deferred_purged_all
 *   E. atomic claim, cancel, malformed state
 *   F. wake endpoint handler                    nppp_purged_all_wake_handler (watchdog.php)
 *   G. the call site, end to end                nppp_purge() + nppp_purge_helper() + nppp_http_purge_all()
 *   H. wiring (static): who may fire the hook, EP2/EP8 gates, cron registration, cleanup on deactivate/uninstall
 *
 *
 * No WordPress needed. Section C spawns small POSIX sh processes with stub binaries in a temp PATH; it needs `sh`,
 * `sed`, `tr`, `printf`. It never touches the network and never starts a real waiter.
 */
error_reporting( E_ALL );
define( 'ABSPATH', '/tmp/' );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'DAY_IN_SECONDS', 86400 );

class WP_Error {
    public $c; public $m;
    public function __construct( $c = '', $m = '' ) { $this->c = $c; $this->m = $m; }
    public function get_error_code() { return $this->c; }
    public function get_error_message() { return $this->m; }
    public function get_error_messages() { return [ $this->m ]; }
}
class WpDie extends Exception { public $http; public function __construct( $http, $msg = '' ) { parent::__construct( $msg ); $this->http = $http; } }

/* ------------------------------------------------------------------ WordPress stand-ins */
$GLOBALS['T'] = [];
function t_reset( array $settings = [] ) {
    $GLOBALS['T'] = [
        'options'  => [ 'nginx_cache_settings' => array_merge( [ 'nppp_http_purge_enabled' => 'yes' ], $settings ) ],
        'autoload' => [], 'transients' => [], 'queue' => [], 'requests' => [], 'notices' => [], 'actions' => [],
        'filters'  => [], 'hooks' => [], 'cron' => [], 'cron_fail' => false, 'fail_update' => null,
        'wpdb_pre_delete' => null, 'wpdb_deletes' => 0, 'locks_released' => 0, 'wp_purge_calls' => 0,
        'wp_purge_result' => true, 'preload_calls' => [], 'rate_allowed' => true, 'order' => [],
    ];
    // The queue status endpoint the plugin polls (same filter run.php uses).
    $GLOBALS['T']['filters']['nppp_http_purge_queue_status_url'] = fn() => 'http://127.0.0.1/purge_status';
}
function t_filter( $tag, $fn ) { $GLOBALS['T']['filters'][ $tag ] = $fn; }
function get_option( $k, $d = false ) { return $GLOBALS['T']['options'][ $k ] ?? $d; }
function update_option( $k, $v, $autoload = null ) {
    if ( $GLOBALS['T']['fail_update'] === $k ) { return false; }
    $GLOBALS['T']['options'][ $k ] = $v; $GLOBALS['T']['autoload'][ $k ] = $autoload; return true;
}
function delete_option( $k ) { unset( $GLOBALS['T']['options'][ $k ] ); return true; }
function home_url() { return 'https://example.com'; }
function get_site_url() { return 'https://example.com'; }
function admin_url( $p = '' ) { return 'https://example.com/wp-admin/' . $p; }
function wp_parse_url( $u, $c = -1 ) { return parse_url( $u, $c ); }
function absint( $n ) { return abs( (int) $n ); }
function esc_url( $u ) { return $u; }
function esc_url_raw( $u ) { return $u; }
function __( $s, $d = '' ) { return $s; }
function _n( $a, $b, $n, $d = '' ) { return $n === 1 ? $a : $b; }
function url_to_postid( $u ) { return 0; }
function sanitize_text_field( $s ) { return trim( (string) $s ); }
function wp_unslash( $s ) { return $s; }
function is_multisite() { return false; }
function did_action( $tag ) { return 1; }
function apply_filters( $tag, $v, ...$a ) { $f = $GLOBALS['T']['filters'][ $tag ] ?? null; return $f ? $f( $v, ...$a ) : $v; }
function do_action( $tag, ...$a ) { $GLOBALS['T']['actions'][] = [ $tag, $a ]; $GLOBALS['T']['order'][] = 'action:' . $tag; }
function add_action( $tag, $cb, $p = 10, $n = 1 ) { $GLOBALS['T']['hooks'][ $tag . '|' . $cb ] = true; }
function remove_action( $tag, $cb, $p = 10 ) { unset( $GLOBALS['T']['hooks'][ $tag . '|' . $cb ] ); }
function get_transient( $k ) { return $GLOBALS['T']['transients'][ $k ] ?? false; }
function set_transient( $k, $v, $t ) { $GLOBALS['T']['transients'][ $k ] = $v; return true; }
function delete_transient( $k ) { unset( $GLOBALS['T']['transients'][ $k ] ); return true; }
function is_wp_error( $x ) { return $x instanceof WP_Error; }
function wp_cache_delete( $k, $g = '' ) { return true; }
function wp_die( $m = '', $t = '', $a = [] ) { throw new WpDie( (int) ( is_array( $a ) ? ( $a['response'] ?? 200 ) : 200 ), (string) $m ); }
function nppp_display_admin_notice( $type, $msg, $log = true, $show = true ) { $GLOBALS['T']['notices'][] = [ $type, $msg, $log, $show ]; }
function wp_remote_request( $url, $args ) {
    $GLOBALS['T']['requests'][] = [ 'url' => $url, 'args' => $args ];
    $next = array_shift( $GLOBALS['T']['queue'] );
    return $next ?? new WP_Error( 'http_request_failed', 'cURL error 7: queue empty (test bug)' );
}
function wp_remote_retrieve_response_code( $r ) { return $r['c']; }
function wp_remote_retrieve_body( $r ) { return $r['b'] ?? ''; }
function wp_remote_retrieve_header( $r, $name ) { return $r['h'][ strtolower( $name ) ] ?? ''; }

/* WP-Cron: single events only, like the plugin uses it */
function wp_schedule_single_event( $ts, $hook, $args = [] ) {
    if ( $GLOBALS['T']['cron_fail'] ) { return false; }
    $GLOBALS['T']['cron'][] = [ 'hook' => $hook, 'ts' => (int) $ts ]; return true;
}
function wp_unschedule_hook( $hook ) {
    $n = 0;
    foreach ( $GLOBALS['T']['cron'] as $i => $e ) { if ( $e['hook'] === $hook ) { unset( $GLOBALS['T']['cron'][ $i ] ); $n++; } }
    $GLOBALS['T']['cron'] = array_values( $GLOBALS['T']['cron'] );
    return $n;
}
function wp_clear_scheduled_hook( $hook ) { return wp_unschedule_hook( $hook ); }

/* $wpdb->delete(): the plugin's atomic claim. Deletes the option row; returns the affected row count. */
class FakeWpdb {
    public $options = 'wp_options';
    public function delete( $table, $where, $fmt = null ) {
        if ( is_callable( $GLOBALS['T']['wpdb_pre_delete'] ) ) { ( $GLOBALS['T']['wpdb_pre_delete'] )(); }   // a concurrent claimer wins here
        $GLOBALS['T']['wpdb_deletes']++;
        $k = $where['option_name'] ?? '';
        if ( array_key_exists( $k, $GLOBALS['T']['options'] ) ) { unset( $GLOBALS['T']['options'][ $k ] ); return 1; }
        return 0;
    }
}
$wpdb = new FakeWpdb();

function R( int $status, string $code = '', string $body = '' ) { return [ 'c' => $status, 'b' => $body, 'h' => $code !== '' ? [ 'cache-purge-code' => $code ] : [] ]; }
function E( string $m ) { return new WP_Error( 'http_request_failed', $m ); }
function Q( ...$r ) { foreach ( $r as $x ) { $GLOBALS['T']['queue'][] = $x; } }
function status_body( $pending, $active, $exh = 0, $err = 0, $scan = 0 ) {
    return json_encode( [ 'code' => 'status', 'queue' => [ 'size' => 0 ],
        'cache' => [ 'pending' => [ 'total' => $pending, 'full' => 0 ], 'active' => $active,
                     'files' => [ 'deleted' => 5, 'errors' => $err, 'bytes' => 1 ],
                     'cleanup' => [ 'scan_errors' => $scan, 'retries' => 0, 'exhausted' => $exh ] ] ] );
}
function S( ...$a ) { return R( 200, 'status', status_body( ...$a ) ); }
function notice_has( $needle, $type = null ) {
    foreach ( $GLOBALS['T']['notices'] as $n ) { if ( strpos( $n[1], $needle ) !== false && ( $type === null || $n[0] === $type ) ) { return true; } }
    return false;
}

/* any PHP warning/notice/deprecation while a plugin function runs is a failure of that test */
$WARN = [];
set_error_handler( function ( $no, $str, $file, $line ) use ( &$WARN ) { $WARN[] = "$str @" . basename( $file ) . ":$line"; return true; } );

$PASS = 0; $FAIL = 0;
function ok( $cond, $name, $detail = '' ) { global $PASS, $FAIL; if ( $cond ) { $PASS++; } else { $FAIL++; echo "FAIL: $name $detail\n"; } }
function is_( $got, $want, $name ) { ok( $got === $want, $name, '-- got ' . json_encode( $got ) . ' want ' . json_encode( $want ) ); }

$PLUGIN = realpath( $argv[1] ?? '' ) or exit( "usage: php purged-all-deferred.php path/to/includes/purge-http.php\n" );
$INC    = dirname( $PLUGIN );
$ROOT   = dirname( $INC );
require $PLUGIN;

/** Extract one top-level function from a plugin file and define it (the files need WordPress, so they are not included). */
function load_fn( string $file, string $fn ): void {
    if ( function_exists( $fn ) ) { return; }
    if ( ! preg_match( '/^function ' . $fn . '\(.*?^}\n/ms', (string) file_get_contents( $file ), $m ) ) {
        fwrite( STDERR, "FAIL cannot find $fn in $file\n" ); exit( 2 );
    }
    eval( $m[0] );
}
// F-01 itself. A plugin without them is the unpatched one: every behavioural section below then fails loudly.
foreach ( [ 'nppp_http_purge_fire_purged_all', 'nppp_http_purge_defer_purged_all', 'nppp_http_purge_run_deferred_purged_all', 'nppp_http_purge_deferred_claim', 'nppp_http_purge_purged_all_waiter_command' ] as $fn ) {
    if ( ! function_exists( $fn ) ) { fwrite( STDERR, "FAIL $fn is missing: this plugin does not contain the F-01 patch\n" ); exit( 3 ); }
}
foreach ( [ 'nppp_purged_all_wake_handler' ] as $fn ) { load_fn( "$INC/watchdog.php", $fn ); }
function nppp_watchdog_rate_limit_check(): array { return $GLOBALS['T']['rate_allowed'] ? [ 'allowed' => true, 'ip' => 'x', 'count' => 1 ] : [ 'allowed' => false, 'ip' => 'x', 'count' => 99 ]; }

/* ------------------------------------------------------------------ fixtures */
function fx( array $settings = [] ) { global $wpdb; t_reset( $settings ); $GLOBALS['T']['queue'] = []; }
function marker( array $over = [] ) {
    update_option( NPPP_HTTP_PURGE_PENDING_KEY, array_merge( [ 'status_url' => 'http://127.0.0.1/purge_status', 'host' => 'example.com', 'since' => time(), 'armed_at' => time() ], $over ), false );
}
function st() { return $GLOBALS['T']['options']['nppp_purged_all_deferred'] ?? null; }
function st_set( array $over = [] ) {
    $s = array_merge( [ 'token' => bin2hex( random_bytes( 16 ) ), 'auto_preload' => true, 'armed_at' => time(), 'waiter' => false ], $over );
    update_option( 'nppp_purged_all_deferred', $s, false ); return $s;
}
function fires() { return count( array_filter( $GLOBALS['T']['actions'], fn( $a ) => $a[0] === 'nppp_purged_all' ) ); }
function fire_args() { $r = array_values( array_filter( $GLOBALS['T']['actions'], fn( $a ) => $a[0] === 'nppp_purged_all' ) ); return array_map( fn( $a ) => $a[1], $r ); }
function crons() { return array_values( array_filter( $GLOBALS['T']['cron'], fn( $e ) => $e['hook'] === 'nppp_purged_all_deferred_event' ) ); }
/** WP-Cron semantics: the event that is running has already left the schedule when its callback runs. */
function run_cron() { wp_unschedule_hook( 'nppp_purged_all_deferred_event' ); nppp_http_purge_run_deferred_purged_all(); }
function wake( string $token ): int {
    $_POST = [ 'action' => 'nppp_purged_all_wake', 'token' => $token ];
    try { nppp_purged_all_wake_handler(); } catch ( WpDie $d ) { return $d->http; }
    return -1;
}

/* ------------------------------------------------------------------ stub binaries (safe PATH) */
// Stub binaries in a temp PATH: bash records the script it is asked to run, wget plays a scripted queue status and
// records POSTs, sleep is instant, date is a fake clock. Nothing here can touch the network.
$SHIM = sys_get_temp_dir() . '/nppp-f01-shim-' . getmypid();
@mkdir( $SHIM, 0700, true );
file_put_contents( "$SHIM/bash",  "#!/bin/sh\nprintf '%s' \"\$2\" > \"\$NPPP_T_CAPTURE\"\n" );
file_put_contents( "$SHIM/sleep", "#!/bin/sh\nexit 0\n" );
file_put_contents( "$SHIM/date",  "#!/bin/sh\nn=\$(cat \"\$NPPP_T_DATE\" 2>/dev/null || echo 0); n=\$((n+1)); echo \$n > \"\$NPPP_T_DATE\"\necho \$((1000000 + n * 10))\n" );
file_put_contents( "$SHIM/wget",
    "#!/bin/sh\necho \"\$*\" >> \"\$NPPP_T_LOG\"\ncase \"\$*\" in *--post-data*) exit 0;; esac\n"
  . "n=\$(cat \"\$NPPP_T_CNT\" 2>/dev/null || echo 0); n=\$((n+1)); echo \$n > \"\$NPPP_T_CNT\"\n"
  . "line=\$(sed -n \"\${n}p\" \"\$NPPP_T_SCRIPT\"); [ -z \"\$line\" ] && line=\$(tail -n1 \"\$NPPP_T_SCRIPT\")\n"
  . "case \"\$line\" in FAIL) exit 4;; *) printf '%s' \"\$line\";; esac\n" );
foreach ( [ 'bash', 'sleep', 'date', 'wget' ] as $b ) { chmod( "$SHIM/$b", 0755 ); }
$ORIG_PATH = getenv( 'PATH' );
// SAFETY: the arm path really calls shell_exec(). From here to the end of the run PATH only ever contains stub
// binaries, so even a plugin that spawns a waiter can only run the `bash` recorder below, never a real shell
// polling a real URL.
$NOWGET = "$SHIM-nowget"; @mkdir( "$NOWGET/bin", 0700, true );
file_put_contents( "$NOWGET/bash", "#!/bin/sh\nprintf '%s' \"\$2\" > \"\$NPPP_T_CAPTURE\"\n" ); chmod( "$NOWGET/bash", 0755 );
foreach ( [ 'nohup' ] as $b ) { $pth = trim( (string) shell_exec( "command -v $b 2>/dev/null" ) ); if ( $pth !== '' ) { @symlink( $pth, "$NOWGET/bin/$b" ); } }
$TMP = "$SHIM/t"; @mkdir( $TMP );
putenv( "NPPP_T_CAPTURE=$TMP/capture" ); putenv( "NPPP_T_LOG=$TMP/wget.log" ); putenv( "NPPP_T_CNT=$TMP/cnt" );
putenv( "NPPP_T_SCRIPT=$TMP/script" );   putenv( "NPPP_T_DATE=$TMP/date" );
function shim_on() { global $SHIM, $ORIG_PATH; putenv( "PATH=$SHIM:$ORIG_PATH" ); }
function shim_off() { global $NOWGET; putenv( "PATH=$NOWGET:$NOWGET/bin" ); }   // 'no wget on PATH': a waiter is impossible
function shim_clean() { global $TMP; foreach ( glob( "$TMP/*" ) ?: [] as $f ) { @unlink( $f ); } }
function wait_file( string $f, float $secs = 3.0 ): bool { $end = microtime( true ) + $secs; while ( microtime( true ) < $end ) { if ( is_file( $f ) && filesize( $f ) > 0 ) { return true; } usleep( 50000 ); } return false; }
$PEND = [ 'status_url' => 'http://127.0.0.1/purge_status', 'host' => 'example.com', 'since' => time(), 'armed_at' => time() ];
$TOK  = str_repeat( 'ab', 16 );
shim_off();   // default for every section: no waiter can be spawned

/* ================================================================== A. fire-or-defer decision */
// A purge that really finished fires at once, exactly once, with the runtime auto-preload flag.
foreach ( [ 0, 2, null ] as $status ) {
    fx(); marker();
    nppp_http_purge_fire_purged_all( $status, true );
    is_( fires(), 1, 'A1 status ' . json_encode( $status ) . ': hook fired immediately, once' );
    is_( fire_args(), [ [ true ] ], 'A1 status ' . json_encode( $status ) . ': auto_preload flag passed through' );
    is_( st(), null, 'A1 status ' . json_encode( $status ) . ': nothing deferred' );
    is_( crons(), [], 'A1 status ' . json_encode( $status ) . ': no cron armed' );
}
fx(); marker(); nppp_http_purge_fire_purged_all( 0, false );
is_( fire_args(), [ [ false ] ], 'A1b auto_preload=false reaches the listeners as false (Redis flush gate depends on it)' );

// F-01: status 7 with a live queue marker => NOT fired now.
fx(); marker();
nppp_http_purge_fire_purged_all( 7, true );
is_( fires(), 0, 'A2 status 7: nppp_purged_all NOT fired while nginx still deletes (the F-01 bug)' );
$s = st();
ok( is_array( $s ) && preg_match( '/^[a-f0-9]{32}$/', (string) ( $s['token'] ?? '' ) ), 'A2 state row holds a 32-hex one-shot token' );
is_( $s['auto_preload'] ?? null, true, 'A2 state remembers the runtime auto_preload flag' );
ok( abs( ( $s['armed_at'] ?? 0 ) - time() ) <= 2, 'A2 armed_at = now' );
is_( $GLOBALS['T']['autoload']['nppp_purged_all_deferred'] ?? 'unset', false, 'A2 state row is a non-autoloaded option (not read on every request)' );
is_( count( crons() ), 1, 'A2 exactly one cron fail-safe event armed' );
ok( notice_has( 'deferred until the queue is idle', 'info' ), 'A2 admin is told the follow-up is deferred' );

// No marker / no status URL = nothing to wait on: fail open, fire now (as before the patch).
fx(); nppp_http_purge_fire_purged_all( 7, true );
is_( fires(), 1, 'A3 status 7 but no marker: fail-open, fires immediately' );
is_( st(), null, 'A3 ... and leaves no state' );
fx(); marker( [ 'status_url' => '' ] ); nppp_http_purge_fire_purged_all( 7, true );
is_( fires(), 1, 'A3b marker without status_url: fail-open' );

// Filter opt-out restores the legacy immediate hook.
fx(); marker(); t_filter( 'nppp_defer_purged_all_while_queue_busy', fn() => false );
nppp_http_purge_fire_purged_all( 7, true );
is_( fires(), 1, 'A4 filter nppp_defer_purged_all_while_queue_busy=false: immediate hook' );
is_( st(), null, 'A4 ... nothing deferred' );

// Nothing can wake us => never swallow the follow-up.
fx(); marker(); $GLOBALS['T']['cron_fail'] = true;
nppp_http_purge_fire_purged_all( 7, true );
is_( fires(), 1, 'A5 cron cannot be scheduled and no waiter: fires immediately (fail-open)' );
is_( st(), null, 'A5 ... the half-written state is removed' );
fx(); marker(); $GLOBALS['T']['fail_update'] = 'nppp_purged_all_deferred';
nppp_http_purge_fire_purged_all( 7, true );
is_( fires(), 1, 'A6 state row cannot be written: fires immediately (fail-open)' );

// A later Purge All that FINISHED covers (and cancels) the pending chain: no late double fire.
fx(); marker(); nppp_http_purge_fire_purged_all( 7, true );
is_( fires(), 0, 'A7 setup: deferred' );
nppp_http_purge_fire_purged_all( 0, true );
is_( fires(), 1, 'A7 a later finished Purge All fires now' );
is_( st(), null, 'A7 ... and cancels the pending state' );
is_( crons(), [], 'A7 ... and its cron' );
run_cron();
is_( fires(), 1, 'A7 ... so a stale cron tick fires nothing more' );

// status values that are not the integer 7 never defer.
foreach ( [ '7', 7.0, 1, 3, 4, true ] as $v ) {
    fx(); marker(); nppp_http_purge_fire_purged_all( $v, true );
    is_( fires(), 1, 'A8 status ' . json_encode( $v ) . ' is not "queue still deleting": immediate' );
}

/* ================================================================== B. re-arm / supersede */
fx(); marker();
nppp_http_purge_fire_purged_all( 7, true );
$first = st();
$s = $first; $s['armed_at'] = time() - 100; update_option( 'nppp_purged_all_deferred', $s, false );   // pretend 100 s passed
nppp_http_purge_fire_purged_all( 7, false );
$second = st();
is_( $second['token'] ?? null, $first['token'], 'B1 re-arm keeps the token (the running waiter stays valid)' );
ok( abs( ( $second['armed_at'] ?? 0 ) - time() ) <= 2, 'B1 re-arm moves armed_at to now (latest Purge All wins)' );
is_( $second['auto_preload'] ?? null, false, 'B1 re-arm takes the latest auto_preload flag' );
is_( count( crons() ), 1, 'B1 still exactly one cron event (previous one unscheduled)' );
is_( fires(), 0, 'B1 still no hook fired' );

// An expired chain is replaced, with a new token.
fx(); marker(); $old = st_set( [ 'armed_at' => time() - ( nppp_http_purge_gate_max_wait() + 61 ) ] );
nppp_http_purge_fire_purged_all( 7, true );
isset( $GLOBALS['T']['options']['nppp_purged_all_deferred']['token'] ) || ok( false, 'B2 state missing' );
ok( ( st()['token'] ?? '' ) !== $old['token'], 'B2 a chain older than max_wait+60 is replaced by a new one (new token)' );
ok( abs( ( st()['armed_at'] ?? 0 ) - time() ) <= 2, 'B2 new armed_at' );

// Cron delay: without a waiter the cron is the poller (short), with one it is a slow second path.
is_( nppp_http_purge_defer_poll_delay( false ), 15, 'B3 no waiter: cron polls every 15 s (default)' );
is_( nppp_http_purge_defer_poll_delay( true ), 60, 'B3 live waiter: cron re-checks every 60 s' );
fx(); t_filter( 'nppp_purged_all_defer_interval', fn() => 1 );   is_( nppp_http_purge_defer_interval(), 15, 'B3 interval floor 15 s' );
fx(); t_filter( 'nppp_purged_all_defer_interval', fn() => 9999 ); is_( nppp_http_purge_defer_interval(), 120, 'B3 interval cap 120 s' );
fx(); marker(); nppp_http_purge_fire_purged_all( 7, true );
is_( ( crons()[0]['ts'] ?? 0 ) - ( st()['armed_at'] ?? 0 ), 15, 'B3 first cron tick = armed_at + 15 without a waiter' );

/* ================================================================== C. waiter command */

shim_on();   // wget + scripted stubs available from here

// C1. Spawn rules.
fx( [ 'nginx_cache_watchdog' => 'yes' ] );
$cmd = nppp_http_purge_purged_all_waiter_command( $PEND, $TOK );
ok( is_string( $cmd ), 'C1 Watchdog on + shell + wget: a waiter command is built' );
is_( nppp_http_purge_purged_all_waiter_command( $PEND, 'not-a-token' ), null, 'C1 a malformed token never builds a command' );
is_( nppp_http_purge_purged_all_waiter_command( $PEND, strtoupper( $TOK ) ), null, 'C1 token must be lowercase hex (it goes into a shell line)' );

// C2. FINDING F01-W: the waiter must not depend on the Watchdog toggle.
// EP8 (main plugin file) and the 6c doc block both say: "nppp_purged_all_wake is exempt from the Watchdog toggle ...
// so the follow-up never has to fall back to WP-Cron on default installs". nginx_cache_watchdog defaults to 'no'
// (only 'yes' with DISABLE_WP_CRON), and WP-Cron only runs on traffic. Unpatched, the waiter is never spawned on a
// default install, so the follow-up rides on WP-Cron alone: on a low-traffic site Cloudflare keeps stale HTML until
// somebody visits the site, which is the original F-01 symptom, only delayed.
foreach ( [ 'no', null ] as $wd ) {
    fx( $wd === null ? [] : [ 'nginx_cache_watchdog' => $wd ] );
    ok( nppp_http_purge_purged_all_waiter_command( $PEND, $TOK ) !== null, 'C2 F01-W: waiter is built with Watchdog ' . json_encode( $wd ) . ' (EP8 exempts this action from the toggle)' );
}

// C3. No wget => "no waiter" (so the cron stays the short-interval poller).
fx( [ 'nginx_cache_watchdog' => 'yes' ] ); shim_off();
is_( nppp_http_purge_purged_all_waiter_command( $PEND, $TOK ), null, 'C3 no wget on PATH: no waiter (the cron keeps the short interval)' );
shim_on();

// C4. The command, once the Watchdog toggle is out of the way (set it on so this section is independent of C2).
fx( [ 'nginx_cache_watchdog' => 'yes' ] ); shim_clean();
$cmd = nppp_http_purge_purged_all_waiter_command( $PEND, $TOK );
ok( strpos( (string) $cmd, 'nohup ' ) === 0 && substr( (string) $cmd, -2 ) === ' &', 'C4 detached: nohup ... > /dev/null 2>&1 &' );
shell_exec( $cmd );
ok( wait_file( "$TMP/capture" ), 'C4 the command really runs a shell with -c <script> (argument round-trip)' );
$script = (string) @file_get_contents( "$TMP/capture" );
ok( strpos( $script, 'g_end=' ) !== false && strpos( $script, "'http://127.0.0.1/purge_status'" ) !== false, 'C4 script starts with the queue gate polling the persisted status URL' );
ok( strpos( $script, "--post-data 'action=nppp_purged_all_wake&token=$TOK'" ) !== false, 'C4 script ends with one POST of the one-shot token to the wake action' );
ok( strpos( $script, "'https://example.com/wp-admin/admin-ajax.php'" ) !== false, 'C4 POST target = admin-ajax.php' );
is_( substr_count( $script, '--post-data' ), 1, 'C4 exactly one POST in the script' );
exec( 'sh -n ' . escapeshellarg( "$TMP/capture" ) . ' 2>&1', $o, $rc );
is_( $rc, 0, 'C4 the generated waiter script is valid POSIX sh' );

// C5. Run the generated script for real against the stub wget: the POST must come AFTER the queue is idle twice.
function run_waiter_script( array $wget_lines, string $script, int $max_wait = 900 ): array {
    global $TMP, $SHIM, $ORIG_PATH;
    foreach ( [ 'wget.log', 'cnt', 'date' ] as $f ) { @unlink( "$TMP/$f" ); }
    file_put_contents( "$TMP/script", implode( "\n", $wget_lines ) . "\n" );
    file_put_contents( "$TMP/run.sh", $script );
    $out = []; exec( 'PATH=' . escapeshellarg( "$SHIM:$ORIG_PATH" ) . ' timeout 60 sh ' . escapeshellarg( "$TMP/run.sh" ) . ' 2>&1', $out, $rc );
    $log = is_file( "$TMP/wget.log" ) ? file( "$TMP/wget.log", FILE_IGNORE_NEW_LINES ) : [];
    $polls = count( array_filter( $log, fn( $l ) => strpos( $l, '--post-data' ) === false ) );
    $post_at = null; foreach ( $log as $i => $l ) { if ( strpos( $l, '--post-data' ) !== false ) { $post_at = $i; break; } }
    return [ 'rc' => $rc, 'polls' => $polls, 'posts' => count( $log ) - $polls, 'post_at' => $post_at, 'log' => $log ];
}
$busy = status_body( 3, 1 ); $idle = status_body( 0, 0 );
$r = run_waiter_script( [ $busy, $busy, $idle, $idle ], $script );
is_( [ $r['rc'], $r['posts'], $r['polls'] ], [ 0, 1, 4 ], 'C5 busy,busy,idle,idle: waits for TWO idle samples, then exactly one POST' );
is_( $r['post_at'], 4, 'C5 the POST is the 5th wget call: never before the queue proved idle' );
ok( strpos( (string) ( $r['log'][4] ?? '' ), $TOK ) !== false, 'C5 the POST carries the token' );
$r = run_waiter_script( [ $idle, $busy, $idle, $idle ], $script );
is_( [ $r['posts'], $r['polls'] ], [ 1, 4 ], 'C5 idle,busy,idle,idle: an idle sample followed by a busy one resets the proof (needs two in a row)' );
$r = run_waiter_script( [ 'FAIL' ], $script );
is_( [ $r['rc'], $r['posts'], $r['polls'] ], [ 0, 1, 5 ], 'C5 status endpoint dead: 5 failed polls, then fail-open POST (follow-up is never lost)' );
$r = run_waiter_script( [ 'not json at all' ], $script );
is_( [ $r['posts'], $r['polls'] ], [ 1, 5 ], 'C5 garbage status body counts as failed polls: fail-open after 5' );
t_filter( 'nppp_http_purge_gate_max_wait', fn() => 30 );
$cmd30 = nppp_http_purge_purged_all_waiter_command( $PEND, $TOK ); shim_clean(); shell_exec( $cmd30 ); wait_file( "$TMP/capture" );
$script30 = (string) file_get_contents( "$TMP/capture" );
$r = run_waiter_script( [ $busy ], $script30 );
is_( [ $r['rc'], $r['posts'] ], [ 0, 1 ], 'C5 queue busy forever: the deadline (max wait) fires the POST anyway' );
ok( $r['polls'] >= 1 && $r['polls'] <= 6, 'C5 deadline reached after a handful of polls on the fake clock', '-- polls ' . $r['polls'] );

// C6. Spawned by the arm path: waiter flag, cron delay, token continuity, no second spawn on re-arm.
fx( [ 'nginx_cache_watchdog' => 'yes' ] ); marker(); shim_clean();
nppp_http_purge_fire_purged_all( 7, true );
$s = st();
is_( $s['waiter'] ?? null, true, 'C6 waiter flag set when a waiter was spawned' );
ok( wait_file( "$TMP/capture" ), 'C6 the arm path spawned the waiter after writing the state' );
ok( strpos( (string) @file_get_contents( "$TMP/capture" ), (string) ( $s['token'] ?? 'x' ) ) !== false, 'C6 the waiter holds the state\'s token' );
is_( ( crons()[0]['ts'] ?? 0 ) - ( $s['armed_at'] ?? 0 ), 60, 'C6 with a live waiter the cron is only the slow second path (+60 s)' );
@unlink( "$TMP/capture" );
nppp_http_purge_fire_purged_all( 7, true );
usleep( 400000 );
ok( ! is_file( "$TMP/capture" ), 'C6 re-arm does not spawn a second waiter' );
is_( st()['token'] ?? null, $s['token'], 'C6 re-arm keeps the token' );

// C7. A waiter that could not be spawned before is tried again on the next Purge All of the chain.
fx( [ 'nginx_cache_watchdog' => 'yes' ] ); marker(); shim_clean(); shim_off();   // no wget: no waiter possible
nppp_http_purge_fire_purged_all( 7, true ); $first = st();
is_( $first['waiter'] ?? null, false, 'C7 setup: first Purge All of the chain had no waiter' );
shim_on();                                              // wget appears
nppp_http_purge_fire_purged_all( 7, true );
is_( st()['token'] ?? null, $first['token'] ?? 'x', 'C7 same chain, same token' );
is_( st()['waiter'] ?? null, true, 'C7 the missing waiter is spawned on the re-arm' );
ok( wait_file( "$TMP/capture" ), 'C7 ... and it actually ran' );
shim_off();   // back to the safe default

/* ================================================================== D. cron fail-safe */
function arm_chain( array $over = [] ) { marker(); $s = st_set( $over ); wp_schedule_single_event( time() + 15, 'nppp_purged_all_deferred_event' ); return $s; }

fx(); nppp_http_purge_run_deferred_purged_all();
is_( [ fires(), crons() ], [ 0, [] ], 'D1 no state: nothing fires, nothing scheduled (a late tick after a waiter fired)' );

fx(); arm_chain(); Q( S( 3, 1 ) );
run_cron();
is_( fires(), 0, 'D2 queue busy: no hook' );
ok( st() !== null, 'D2 state kept' );
is_( count( crons() ), 1, 'D2 re-armed itself for the next check' );

fx(); $s = arm_chain( [ 'auto_preload' => true ] ); Q( S( 0, 0 ), S( 0, 0 ) );
run_cron();
is_( fires(), 1, 'D3 queue idle (two samples): the hook fires once' );
is_( fire_args(), [ [ true ] ], 'D3 with the auto_preload flag of the Purge All that armed it' );
is_( st(), null, 'D3 state consumed' );
is_( $GLOBALS['T']['options'][ NPPP_HTTP_PURGE_PENDING_KEY ] ?? null, null, 'D3 gate marker dropped once the queue proved idle' );
ok( notice_has( 'nginx queue is idle', 'info' ), 'D3 info notice' );
run_cron();
is_( fires(), 1, 'D3 a second tick fires nothing (at most once)' );

fx(); arm_chain( [ 'auto_preload' => false ] ); Q( S( 0, 0 ), S( 0, 0 ) );
run_cron();
is_( fire_args(), [ [ false ] ], 'D3b auto_preload=false is preserved through the deferral' );

fx(); arm_chain(); delete_option( NPPP_HTTP_PURGE_PENDING_KEY );
run_cron();
is_( fires(), 1, 'D4 marker gone (expired / dropped): nothing to wait for, fires' );

fx(); arm_chain(); Q( E( 'cURL error 7: refused' ) );
run_cron();
is_( fires(), 0, 'D5 status endpoint unreadable: NOT idle, keeps waiting (an unreadable queue must not unprotect the edge)' );
is_( count( crons() ), 1, 'D5 ... and re-arms' );

fx(); arm_chain( [ 'armed_at' => time() - ( nppp_http_purge_gate_max_wait() + 61 ) ] ); Q( S( 9, 9 ) );
run_cron();
is_( fires(), 1, 'D6 deadline (max_wait + 60 s) passed with a busy queue: fires anyway (fail-open)' );
ok( notice_has( 'not confirmed idle', 'warning' ), 'D6 warning notice says why' );
is_( st(), null, 'D6 state consumed' );

fx(); arm_chain(); Q( S( 3, 1 ) ); $GLOBALS['T']['cron_fail'] = true;
run_cron();
is_( fires(), 1, 'D7 queue busy but cron cannot re-arm: fail-open fire instead of losing the follow-up' );

// D8. Lost race: the waiter's POST claims the row between the cron's read and its DELETE.
fx(); arm_chain(); Q( S( 0, 0 ), S( 0, 0 ) );
$GLOBALS['T']['wpdb_pre_delete'] = function () { delete_option( 'nppp_purged_all_deferred' ); $GLOBALS['T']['wpdb_pre_delete'] = null; };   // someone else wins
run_cron();
is_( fires(), 0, 'D8 lost the claim race: the cron fires nothing (the winner does)' );

// D9. Headless limits: the claim already consumed the state, so a killed request would lose the follow-up for good.
ignore_user_abort( false );
fx(); arm_chain(); Q( S( 0, 0 ), S( 0, 0 ) );
run_cron();
is_( (bool) ignore_user_abort(), true, 'D9 ignore_user_abort(true): the follow-up survives a dropped connection' );
ignore_user_abort( false );

/* ================================================================== E. claim, cancel, malformed state */
fx(); $s = st_set();
$wrong = ( $s['token'][0] === 'a' ? 'b' : 'a' ) . substr( $s['token'], 1 );
is_( nppp_http_purge_deferred_claim( $wrong ), null, 'E1 wrong token: refused' );
ok( st() !== null, 'E1 ... and the state is kept (a wrong guess must not consume the real one)' );
$c1 = nppp_http_purge_deferred_claim( $s['token'] ); $c2 = nppp_http_purge_deferred_claim( $s['token'] );
ok( is_array( $c1 ) && $c2 === null, 'E2 claim is single-use: first caller gets the state, the second gets null' );
fx(); st_set(); $a = nppp_http_purge_deferred_claim(); $b = nppp_http_purge_deferred_claim();
ok( is_array( $a ) && $b === null, 'E3 claim without a token (cron path): single-use too' );
foreach ( [ 'string' => 'garbage', 'no token' => [ 'armed_at' => time() ], 'no armed_at' => [ 'token' => str_repeat( 'a', 32 ) ], 'empty token' => [ 'token' => '', 'armed_at' => time() ] ] as $label => $bad ) {
    fx(); update_option( 'nppp_purged_all_deferred', $bad, false );
    is_( nppp_http_purge_deferred_claim(), null, "E4 malformed state ($label): not claimable" );
    $WARN = [];
    run_cron();
    is_( [ fires(), $WARN ], [ 0, [] ], "E4 malformed state ($label): cron tick fires nothing, no PHP warnings" );
}
fx(); st_set(); wp_schedule_single_event( time() + 15, 'nppp_purged_all_deferred_event' ); nppp_http_purge_deferred_cancel();
is_( [ st(), crons() ], [ null, [] ], 'E5 cancel removes the state row and the cron' );

/* ================================================================== F. wake endpoint handler */
$tok = str_repeat( 'c', 32 );
fx(); st_set( [ 'token' => $tok, 'auto_preload' => true ] ); wp_schedule_single_event( time() + 60, 'nppp_purged_all_deferred_event' );
$GLOBALS['T']['rate_allowed'] = false;
is_( wake( $tok ), 429, 'F1 rate limited: 429' );
is_( fires(), 0, 'F1 ... nothing fired' ); ok( st() !== null, 'F1 ... state kept' );
$GLOBALS['T']['rate_allowed'] = true;
foreach ( [ '' => '', 'short' => 'abc', 'non-hex' => str_repeat( 'z', 32 ), 'sql' => "' OR 1=1 --", '33 chars' => str_repeat( 'a', 33 ) ] as $label => $bad ) {
    is_( wake( $bad ), 403, "F2 malformed token ($label): 403" );
}
is_( fires(), 0, 'F2 nothing fired by malformed tokens' ); ok( st() !== null, 'F2 state kept' );
is_( wake( str_repeat( 'd', 32 ) ), 403, 'F3 well-formed but wrong token: 403' );
is_( fires(), 0, 'F3 nothing fired' ); ok( st() !== null, 'F3 state kept (a guess cannot burn the real token)' );
is_( wake( strtoupper( $tok ) ), 200, 'F4 valid token (any case, like the pre-bootstrap gate) : 200' );
is_( fires(), 1, 'F4 hook fired once' );
is_( fire_args(), [ [ true ] ], 'F4 with the stored auto_preload flag' );
is_( [ st(), crons() ], [ null, [] ], 'F4 state consumed, cron fail-safe unscheduled' );
ok( notice_has( 'nginx queue is idle', 'info' ), 'F4 info notice' );
is_( wake( $tok ), 403, 'F5 replay of the same token: 403' );
is_( fires(), 1, 'F5 ... no second fire (a retried wget --tries=3 cannot double-fire)' );
$_POST = [];
fx(); is_( wake( $tok ), 403, 'F6 no pending follow-up at all: 403, nothing fired' ); is_( fires(), 0, 'F6 nothing fired' );

// Both wake paths race: whichever comes second is a no-op.
fx(); $s = arm_chain( [ 'token' => $tok ] ); Q( S( 0, 0 ), S( 0, 0 ) );
run_cron();
is_( wake( $tok ), 403, 'F7 cron fired first: the waiter\'s later POST is refused' );
is_( fires(), 1, 'F7 exactly one fire in total' );
fx(); $s = arm_chain( [ 'token' => $tok ] ); Q( S( 0, 0 ), S( 0, 0 ) );
is_( wake( $tok ), 200, 'F8 waiter first' );
run_cron();
is_( fires(), 1, 'F8 the cron tick afterwards fires nothing: exactly one fire in total' );

// A re-armed chain keeps the token, so the waiter that is already running is still accepted.
fx(); marker(); nppp_http_purge_fire_purged_all( 7, true ); $t1 = st()['token'];
nppp_http_purge_fire_purged_all( 7, true );
is_( wake( $t1 ), 200, 'F9 waiter armed for Purge #1 still wakes the chain re-armed by Purge #2' );
// A finished Purge All supersedes the chain: the late waiter is refused.
fx(); marker(); nppp_http_purge_fire_purged_all( 7, true ); $t1 = st()['token'];
nppp_http_purge_fire_purged_all( 0, true );
is_( fires(), 1, 'F10 setup: Purge #2 finished and fired' );
is_( wake( $t1 ), 403, 'F10 the late waiter of Purge #1 is refused: the hook does not fire a second time' );

/* ================================================================== G. the call site, end to end */
foreach ( [ 'nppp_purge_helper', 'nppp_purge' ] as $fn ) { load_fn( "$INC/purge.php", $fn ); }
class FakeWpFs { public function exists( $p ) { return false; } public function is_dir( $p ) { return $p === '/cache'; } }
function nppp_initialize_wp_filesystem() { return new FakeWpFs(); }
function nppp_prepare_request_env( $x = false ) {}
function nppp_acquire_purge_lock( $k ) { return true; }
function nppp_release_purge_lock() { $GLOBALS['T']['locks_released']++; }
function nppp_wait_for_preload_start_idle() {}
function nppp_wp_remove_directory( $p, $r = false ) {}
function nppp_wp_purge( $p ) { $GLOBALS['T']['wp_purge_calls']++; $GLOBALS['T']['order'][] = 'fs_purge'; return $GLOBALS['T']['wp_purge_result']; }
function nppp_kill_preload_watcher() {}
function nppp_watcher_delete_token() {}
function nppp_perform_file_operation( $p, $op ) {}
function nppp_fetch_default_reject_regex() { return ''; }
function nppp_get_runtime_file( $n ) { return "/tmp/$n"; }
function plugin_dir_path( $f ) { return '/plugin/includes/'; }
function nppp_preload( ...$a ) {
    $GLOBALS['T']['order'][] = 'preload';
    $GLOBALS['T']['preload_calls'][] = nppp_http_purge_gate_command( 'wget CRAWL', '/tmp/l.log' );   // what the real preload does with the gate
}
function purge_all_busy_queue() {   // 202, then a queue that stays busy for the whole wait budget
    t_filter( 'nppp_http_purge_verify_queue', fn() => true );
    t_filter( 'nppp_http_purge_queue_wait', fn() => 1 );
    Q( S( 0, 0 ), R( 202, 'accepted' ) );
    for ( $i = 0; $i < 40; $i++ ) { Q( S( 3, 1 ) ); }
}
function run_purge( bool $auto = false ) { nppp_http_purge_gate_state( 'clear' ); nppp_purge( '/cache', '/tmp/pid', '/cache/tmp', false, false, false ); }

// G1. The F-01 scenario itself.
fx(); purge_all_busy_queue();
run_purge( false );
is_( $GLOBALS['T']['wp_purge_calls'], 0, 'G1 status 7: no filesystem purge (it would race nginx\'s queue)' );
is_( fires(), 0, 'G1 status 7: nppp_purged_all (Cloudflare purge-all, Redis flush) NOT fired while nginx still deletes' );
ok( is_array( st() ), 'G1 the follow-up is deferred (state armed)' );
is_( count( crons() ), 1, 'G1 cron fail-safe armed' );
ok( notice_has( 'still deleting the cache', 'info' ), 'G1 admin sees the "accepted, still deleting" notice' );
is_( $GLOBALS['T']['locks_released'], 1, 'G1 purge lock released exactly once' );
ok( isset( $GLOBALS['T']['options'][ NPPP_HTTP_PURGE_PENDING_KEY ] ), 'G1 gate marker persisted' );
// ... and when nginx finishes, the follow-up runs once.
$GLOBALS['T']['queue'] = []; Q( S( 0, 0 ), S( 0, 0 ) );
run_cron();
is_( fires(), 1, 'G1 queue idle: nppp_purged_all fires once, now that the origin really is purged' );
is_( $GLOBALS['T']['wp_purge_calls'], 0, 'G1 ... and still no filesystem purge' );

// G2. With auto preload: the hook is deferred, the crawl is spawned gated, and they wait for the same queue.
fx( [ 'nginx_cache_auto_preload' => 'yes', 'nginx_cache_path' => '/cache' ] ); purge_all_busy_queue();
run_purge( true );
is_( fires(), 0, 'G2 auto preload + status 7: hook deferred' );
is_( $GLOBALS['T']['preload_calls'] !== [] && strpos( $GLOBALS['T']['preload_calls'][0], "( trap '' HUP; " ) === 0, true, 'G2 the preload crawl is still spawned behind the gate (F-01 did not consume or bypass the gate marker)' );
is_( st()['auto_preload'] ?? null, true, 'G2 deferred hook remembers auto_preload=true (Redis flush gate)' );
$GLOBALS['T']['queue'] = []; Q( S( 0, 0 ), S( 0, 0 ) );
run_cron();
is_( fire_args(), [ [ true ] ], 'G2 the deferred hook runs with auto_preload=true' );

// G3. A purge that finished still fires immediately and BEFORE the preload (hook comment: both caches warm up together).
fx( [ 'nginx_cache_auto_preload' => 'yes', 'nginx_cache_path' => '/cache' ] );
t_filter( 'nppp_http_purge_verify_queue', fn() => true ); t_filter( 'nppp_http_purge_queue_wait', fn() => 2 );
Q( S( 0, 0 ), R( 202, 'accepted' ), S( 1, 0 ), S( 0, 0 ), S( 0, 0 ) );
run_purge( true );
is_( fires(), 1, 'G3 proven purge (queue drained inside the budget): hook fires immediately' );
is_( st(), null, 'G3 ... nothing deferred' );
$o = array_values( array_filter( $GLOBALS['T']['order'], fn( $x ) => in_array( $x, [ 'action:nppp_purged_all', 'preload' ], true ) ) );
is_( $o, [ 'action:nppp_purged_all', 'preload' ], 'G3 order unchanged: hook first, then the preload spawn' );

// G4. Filesystem fallback (HTTP Purge off): unchanged behaviour, immediate hook.
fx( [ 'nppp_http_purge_enabled' => 'no' ] );
run_purge( false );
is_( [ $GLOBALS['T']['wp_purge_calls'], fires(), st() ], [ 1, 1, null ], 'G4 filesystem purge: hook fires immediately, nothing deferred' );

// G5. Errors never fire (neither now nor deferred).
fx( [ 'nppp_http_purge_enabled' => 'no' ] ); $GLOBALS['T']['wp_purge_result'] = new WP_Error( 'permission_error', 'x' );
run_purge( false );
is_( [ fires(), st() ], [ 0, null ], 'G5 permission error: no hook, no deferral' );
is_( $GLOBALS['T']['locks_released'], 1, 'G5 lock released exactly once on the error return' );

// G6. A pending chain from an earlier Purge All is superseded by a later filesystem purge that finished.
fx(); marker(); nppp_http_purge_fire_purged_all( 7, true );
$GLOBALS['T']['options']['nginx_cache_settings']['nppp_http_purge_enabled'] = 'no'; $GLOBALS['T']['wp_purge_result'] = true;
run_purge( false );
is_( [ fires(), st(), crons() ], [ 1, null, [] ], 'G6 a later finished Purge All fires once and cancels the pending chain (no late double)' );

// G7. The one-shot call: purge fires through nppp_http_purge_fire_purged_all() and nowhere else.
$src = (string) file_get_contents( "$INC/purge.php" );
ok( preg_match( '/function_exists\(\s*\'nppp_http_purge_fire_purged_all\'\s*\)\s*\)\s*\{\s*nppp_http_purge_fire_purged_all\(\s*isset\(\$status\)\s*\?\s*\$status\s*:\s*null\s*,\s*\(bool\)\s*\$auto_preload\s*\)/s', $src ) === 1, 'G7 nppp_purge() hands the status and the runtime auto_preload to the decision function' );

/* ================================================================== H. wiring (static) */
$main    = (string) file_get_contents( "$ROOT/fastcgi-cache-purge-and-preload-nginx.php" );
$admin   = (string) file_get_contents( "$ROOT/admin/fastcgi-cache-purge-and-preload-nginx-admin.php" );
$wd      = (string) file_get_contents( "$INC/watchdog.php" );
$httpsrc = (string) file_get_contents( $PLUGIN );
$unins   = (string) file_get_contents( "$ROOT/uninstall.php" );
$life    = (string) file_get_contents( "$INC/settings-lifecycle.php" );

// H1. Every site that fires nppp_purged_all. A new unguarded one would resurrect F-01.
$sites = [];
foreach ( glob( "$INC/*.php" ) as $f ) {
    foreach ( file( $f ) as $n => $line ) {
        if ( preg_match( '/do_action\(\s*[\'"]nppp_purged_all[\'"]/', $line ) && ! preg_match( '/^\s*(\/\/|\*)/', $line ) ) { $sites[] = basename( $f ) . ':' . trim( $line ); }
    }
}
sort( $sites );
is_( count( $sites ), 4, 'H1 exactly four places fire nppp_purged_all (purge.php fallback, fire-or-defer, cron, wake handler)', '-- ' . json_encode( $sites ) );
is_( array_map( fn( $s ) => explode( ':', $s )[0], $sites ), [ 'purge-http.php', 'purge-http.php', 'purge.php', 'watchdog.php' ], 'H1 ... in purge-http.php (x2), purge.php (function_exists fallback only), watchdog.php' );
ok( preg_match( '/else\s*\{\s*do_action\(\s*\'nppp_purged_all\'/', $src ) === 1, 'H1 the purge.php site is only the else-fallback when the decision function does not exist' );

// H2. Pre-bootstrap gates: cron hook (EP2) and wake action (EP8) are known to the main plugin file.
ok( preg_match( "/foreach\s*\(\s*\[(?:[^\]]*)'nppp_purged_all_deferred_event'/s", $main ) === 1, 'H2 EP2: the cron hook is whitelisted (it loads the bootstrap)' );
ok( strpos( $main, "\$action !== 'nppp_purged_all_wake'" ) !== false, 'H2 EP8: the wake action passes the first gate' );
ok( preg_match( "/\\\$action === 'nppp_cron_wake' && \(\\\$opts\['nginx_cache_watchdog'\]/", $main ) === 1, 'H2 EP8: the Watchdog toggle gates ONLY the preload wake action' );
ok( preg_match( '/get_option\(\s*\'nppp_purged_all_deferred\'/', $main ) === 1, 'H2 EP8: the wake token is checked against the pending state before bootstrap' );
// The constants the three files use must agree.
foreach ( [ [ 'NPPP_PURGED_ALL_DEFERRED_KEY', 'nppp_purged_all_deferred' ], [ 'NPPP_PURGED_ALL_DEFERRED_HOOK', 'nppp_purged_all_deferred_event' ], [ 'NPPP_PURGED_ALL_WAKE_ACTION', 'nppp_purged_all_wake' ] ] as [ $c, $v ] ) {
    is_( defined( $c ) ? constant( $c ) : null, $v, "H2 constant $c = $v" );
}

// H3. Cron callback registered where the bootstrap loads it; wake handler registered for logged-in and nopriv.
ok( strpos( $admin, "add_action('nppp_purged_all_deferred_event', 'nppp_http_purge_run_deferred_purged_all')" ) !== false, 'H3 the cron hook is bound to its callback in the bootstrap' );
ok( strpos( $wd, "add_action( 'wp_ajax_nopriv_nppp_purged_all_wake'" ) !== false && strpos( $wd, "add_action( 'wp_ajax_nppp_purged_all_wake'" ) !== false, 'H3 the wake action is registered for nopriv and logged-in requests (the waiter has no session)' );
ok( strpos( $admin, 'compat-cloudflare.php' ) !== false && strpos( $admin, 'compat-redis-cache.php' ) !== false, 'H3 the Cloudflare and Redis listeners are loaded by the same bootstrap that runs the cron/wake paths' );
$listeners = (string) file_get_contents( "$INC/compat-cloudflare.php" ) . (string) file_get_contents( "$INC/compat-redis-cache.php" );
ok( ! preg_match( '/nppp_cloudflare_apo_purge_all\(\)[^}]*current_user_can|nppp_redis_cache_on_nppp_purge_all[^}]*current_user_can/s', $listeners ), 'H3 the listeners do not need an admin session (the follow-up now runs headless)' );

// H4. Cleanup: deactivation and uninstall remove the state row (token) and the cron event.
ok( strpos( $life, "wp_unschedule_hook('nppp_purged_all_deferred_event')" ) !== false && strpos( $life, "delete_option('nppp_purged_all_deferred')" ) !== false, 'H4 deactivation drops the pending follow-up (a running waiter then holds a dead token)' );
ok( strpos( $unins, "'nppp_purged_all_deferred'" ) !== false && strpos( $unins, "'nppp_purged_all_deferred_event'" ) !== false, 'H4 uninstall removes the option and the cron hook' );

// H5. The doc block and the code must say the same thing about the Watchdog toggle (F01-W).
$fnsrc = ''; if ( preg_match( '/function nppp_http_purge_purged_all_waiter_command\(.*?^}\n/ms', $httpsrc, $m ) ) { $fnsrc = $m[0]; }
$mentions_toggle_in_code = (bool) preg_match( '/nginx_cache_watchdog/', $fnsrc );
$doc_says_independent    = (bool) preg_match( '/Watchdog toggle is not required/i', $httpsrc );
ok( ! ( $doc_says_independent && $mentions_toggle_in_code ), 'H5 F01-W: waiter_command() must not read nginx_cache_watchdog while its doc and EP8 say the toggle is not required' );

/* ------------------------------------------------------------------ report */
foreach ( glob( "$SHIM/t/*" ) ?: [] as $f ) { @unlink( $f ); } @rmdir( "$SHIM/t" );
foreach ( glob( "$SHIM/*" ) ?: [] as $f ) { @unlink( $f ); } @rmdir( $SHIM );
foreach ( glob( "$NOWGET/bin/*" ) ?: [] as $f ) { @unlink( $f ); } @rmdir( "$NOWGET/bin" );
foreach ( glob( "$NOWGET/*" ) ?: [] as $f ) { @unlink( $f ); } @rmdir( $NOWGET );

if ( $WARN ) { $FAIL++; echo 'FAIL: PHP warnings/notices while running: ' . implode( ' | ', array_slice( array_unique( $WARN ), 0, 5 ) ) . "\n"; }
echo "purged-all-deferred (F-01): $PASS passed, $FAIL failed\n";
exit( $FAIL ? 1 : 0 );
