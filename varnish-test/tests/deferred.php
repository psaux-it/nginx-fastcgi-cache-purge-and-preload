<?php
/**
 * Layer 4 - Varnish x HTTP Purge x background queue x generation mode x Auto Preload x "status 7" (deferred Purge All).
 *
 *   php tests/deferred.php [section]        (no section = every section, each in its own PHP process)
 *
 * WHY THIS FILE EXISTS
 * The other layers feed hooks by hand. This one runs the REAL call chain that produces the hooks:
 *
 *   nppp_purge() -> nppp_purge_helper() -> nppp_http_purge_all()  (purge.php / purge-http.php, extracted verbatim)
 *   nppp_purge() -> nppp_http_purge_fire_purged_all()             (fire now | defer while nginx's queue is busy)
 *   nppp_preload() / nppp_preload_locked()                        (preload.php, extracted verbatim; fires nppp_preload_all_started)
 *   nppp_http_purge_gate_command() / ..._waiter_command()         (the REAL shell gate and waiter, executed by a real sh)
 *   compat-varnish.php listeners                                  (real PURGE / ban over real sockets)
 *   a REAL varnishd running the documented VCL, in front of a counting backend (lab/backend.py)
 *
 * Only nginx is a stand-in: scripted ngx_cache_purge v4 answers (200 purged | 200 invalidated | 202 accepted + a queue
 * status endpoint whose busy/idle state is shared with the shell gate through a state file). The crawl is a shim `wget`
 * that really GETs the pages through Varnish. PATH is shadowed for this process only; nothing leaves 127.0.0.1.
 *
 * THE INVARIANT (compat-varnish.php, "Order rule")
 *   inner layer first, outer layer second: Varnish must be purged AFTER nginx is clean and BEFORE the crawl starts,
 *   otherwise (a) Varnish re-caches stale HTML that nginx still serves, or (b) the crawl is answered by a stale
 *   Varnish object and nginx is never warmed.
 *
 * Sections
 *   sync        control   HTTP Purge on, nginx answers 200 purged, Auto Preload on   -> 1 ban, then crawl warms origin
 *   gen         control   generation mode (200 invalidated), Auto Preload on         -> same, no gate, no deferral
 *   async       control   202 + queue drains inside the wait budget                  -> status 0, same as sync
 *   deferred    exit 7    202 + queue stays busy, Auto Preload on, live waiter       -> no ban while busy, one ban after idle
 *   waiterdead  exit 7    same, waiter died silently, cron fail-safe (+60 s)         -> crawl must still warm the origin
 *   preloadall  exit 7    DIRECT Preload All (button / cron / REST) behind a busy queue -> no ban while busy, ban after idle
 *   supersede   exit 7    re-arm, superseding Purge All, deadline, opt-out filter     -> at most one ban per Purge All, never lost
 *
 * A FAIL whose name starts with "BUG?" is a candidate defect in the plugin (not in the harness); the detail line says why.
 *
 * Exit codes: 0 pass, 1 failures, 2 lab / usage error, 77 varnishd / sh / curl / pkill missing -> skipped.
 */
require __DIR__ . '/../lib/lab.php';

$SECTIONS = [ 'sync', 'gen', 'async', 'deferred', 'waiterdead', 'preloadall', 'supersede' ];
$S        = $argv[1] ?? 'all';

/* ---- orchestrator: every section in a fresh process (the plugin keeps per-request statics) ---------------------- */
if ( $S === 'all' ) {
    $worst = 0; $tot = [ 0, 0, 0, 0 ]; $verbose = in_array( '-v', $argv, true );
    foreach ( $SECTIONS as $sec ) {
        $t0  = time();
        $out = [];
        exec( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( __FILE__ ) . ' ' . $sec . ' 2>&1', $out, $rc );
        $sum = '';
        foreach ( $out as $l ) { if ( preg_match( '/^(\d+) passed, (\d+) failed, (\d+) known-limit documented, (\d+) skipped/', $l, $m ) ) { $sum = $l; for ( $i = 0; $i < 4; $i++ ) { $tot[ $i ] += (int) $m[ $i + 1 ]; } } }
        printf( "  %-5s %-11s %s (%ds)\n", $rc === 0 ? 'PASS' : ( $rc === 77 ? 'SKIP' : ( $rc === 2 ? 'ERROR' : 'FAIL' ) ), $sec, $sum !== '' ? $sum : ( $out[0] ?? '' ), time() - $t0 );
        if ( $rc !== 0 && $rc !== 77 || $verbose ) { foreach ( $out as $l ) { echo '        ' . $l . "\n"; } }
        if ( $rc !== 0 && $rc !== 77 ) { $worst = max( $worst, $rc ); }
    }
    printf( "\n%d passed, %d failed, %d known-limit documented, %d skipped assertions\n", ...$tot );
    exit( $worst );
}
if ( ! in_array( $S, $SECTIONS, true ) ) { fwrite( STDERR, 'usage: php tests/deferred.php [' . implode( '|', $SECTIONS ) . "]\n" ); exit( 2 ); }

if ( ( $why = lab_missing() ) !== '' ) { echo "SKIP deferred/$S: $why\n"; exit( 77 ); }
foreach ( [ 'curl', 'pkill', 'sh', 'bash' ] as $bin ) {
    if ( trim( (string) shell_exec( 'command -v ' . $bin . ' 2>/dev/null' ) ) === '' ) { echo "SKIP deferred/$S: $bin not found\n"; exit( 77 ); }
}
vt_load( true );

/* ================================================================================================================
 * WordPress / plugin stand-ins that wpstub.php does not have (only what the extracted real functions call)
 * ============================================================================================================== */
define( 'NPPP_USER_AGENT', 'npp-vt-agent' ); define( 'NPPP_USER_AGENT_MOBILE', 'npp-vt-mobile' ); define( 'NPPP_HEADER_ACCEPT', 'Accept: text/html' );
class WpDie extends Exception { public $http; public function __construct( $http ) { parent::__construct( '' ); $this->http = $http; } }
class FakeWpFs { public function exists( $p ) { return false; } public function is_dir( $p ) { return $p === '/cache'; } }
class FakeWpdb { public $options = 'wp_options';
    public function delete( $t, $w, $f = null ) { $k = $w['option_name'] ?? ''; if ( array_key_exists( $k, $GLOBALS['VT']['options'] ) ) { unset( $GLOBALS['VT']['options'][ $k ] ); return 1; } return 0; } }
$wpdb = new FakeWpdb();

$T2 = [ 'cron' => [], 'tl' => [], 'fs_purge' => 0, 'preload_notes' => [] ];   // per-scenario scratch, see fx()
function wp_schedule_single_event( $ts, $hook, $a = [] ) { $GLOBALS['T2']['cron'][] = [ 'hook' => $hook, 'ts' => (int) $ts ]; return true; }
function wp_unschedule_hook( $hook ) { $GLOBALS['T2']['cron'] = array_values( array_filter( $GLOBALS['T2']['cron'], fn( $e ) => $e['hook'] !== $hook ) ); return 1; }
function wp_clear_scheduled_hook( $hook ) { return wp_unschedule_hook( $hook ); }
function admin_url( $p = '' ) { return 'http://example.com/wp-admin/' . $p; }
function get_site_url() { return 'http://example.com'; }
function did_action( $t ) { return 1; }
function is_multisite() { return false; }
function wp_unslash( $s ) { return $s; }
function url_to_postid( $u ) { return 0; }
function wp_die( $m = '', $t = '', $a = [] ) { throw new WpDie( (int) ( is_array( $a ) ? ( $a['response'] ?? 200 ) : 200 ) ); }
function plugin_dir_path( $f ) { return '/plugin/includes/'; }
function nppp_initialize_wp_filesystem() { return new FakeWpFs(); }
function nppp_prepare_request_env( $x = false ) {}
function nppp_acquire_purge_lock( $k ) { return true; }
function nppp_release_purge_lock() {}
function nppp_purge_lock_in_flight() { return false; }
function nppp_wait_for_preload_start_idle() {}
function nppp_wp_remove_directory( $p, $r = false ) {}
function nppp_wp_purge( $p ) { $GLOBALS['T2']['fs_purge']++; return true; }
function nppp_kill_preload_watcher() {} function nppp_watcher_delete_token() {} function nppp_stop_watchdog() {} function nppp_cleanup_preload_state() {}
function nppp_safexec_ls_check() { return false; }   // (nppp_preload_cycle_start_delete / phase_delete / trigger_set come from the real state.php)
function nppp_perform_file_operation( $p, $op, $v = null ) { return true; }
function nppp_fetch_default_reject_regex() { return ''; } function nppp_fetch_default_reject_extension() { return ''; }
function nppp_get_runtime_file( $n ) { return lab_dir() . '/' . $n; }
function nppp_acquire_preload_start_lock() { return true; } function nppp_release_preload_start_lock() {}
function nppp_preload_preflight( ...$a ) { return true; } function nppp_detect_premature_process( ...$a ) { return 'ok'; }
function nppp_find_safexec_path() { return ''; } function nppp_is_safexec_usable( $p = '', $q = false ) { return false; }
function nppp_get_proxy_settings() { return [ 'use_proxy' => 'off', 'http_proxy' => '' ]; } function nppp_is_proxy_reachable( ...$a ) { return true; }
function nppp_is_process_alive( $pid ) { return false; }
function nppp_create_scheduled_event_preload_status() {}
function nppp_watcher_get_token() { return ''; } function nppp_spawn_preload_watcher( ...$a ) {}
function nppp_watchdog_rate_limit_check(): array { return [ 'allowed' => true, 'ip' => 'x', 'count' => 1 ]; }

/** Extracts one top-level function from a plugin file and defines it, so the REAL code runs without loading all of WordPress. */
function vt_extract( string $file, string $fn ): void {
    if ( function_exists( $fn ) ) { return; }
    if ( ! preg_match( '/^function ' . $fn . '\(.*?^}\n/ms', (string) file_get_contents( $file ), $m ) ) { fwrite( STDERR, "cannot extract $fn from $file\n" ); exit( 2 ); }
    eval( $m[0] );
}
$INC = vt_includes();
foreach ( [ 'nppp_purge', 'nppp_purge_helper' ] as $f ) { vt_extract( "$INC/purge.php", $f ); }
foreach ( [ 'nppp_preload', 'nppp_preload_locked' ] as $f ) { vt_extract( "$INC/preload.php", $f ); }
vt_extract( "$INC/watchdog.php", 'nppp_purged_all_wake_handler' );
foreach ( [ 'nppp_http_purge_fire_purged_all', 'nppp_http_purge_deferred_claim', 'nppp_http_purge_run_deferred_purged_all', 'nppp_http_purge_gate_command' ] as $f ) {
    if ( ! function_exists( $f ) ) { fwrite( STDERR, "$f missing: this is not a v2.1.9 checkout\n" ); exit( 2 ); }
}

/* ================================================================================================================
 * Shell shims: ONE `wget` first in PATH. Queue poll -> prints the status file; --post-data -> logged (the waiter's
 * wake POST); --recursive -> the crawl: it really GETs $NPPP_VT_CRAWL_PATHS through Varnish. Nothing else is shimmed.
 * ============================================================================================================== */
$SHIM = sys_get_temp_dir() . '/npp-vt-shim-' . getmypid();
@mkdir( $SHIM, 0700, true );
$GLOBALS['FILES'] = [ 'status' => "$SHIM/status.json", 'log' => "$SHIM/events.log" ];
file_put_contents( "$SHIM/wget", <<<'SH'
#!/bin/sh
case "$*" in
  *npp-vt-queue-status*) cat "$NPPP_VT_STATUS" 2>/dev/null; exit 0 ;;
  *--post-data*)         echo "POST $(date +%s.%N)" >> "$NPPP_VT_LOG"; exit 0 ;;
  *--recursive*)
      echo "CRAWL_START $(date +%s.%N)" >> "$NPPP_VT_LOG"
      for p in $NPPP_VT_CRAWL_PATHS; do curl -s -o /dev/null -H "Host: example.com" "http://127.0.0.1:$NPPP_VT_CRAWL_PORT$p"; done
      echo "CRAWL_END $(date +%s.%N)" >> "$NPPP_VT_LOG"; exit 0 ;;
esac
exit 0
SH
);
chmod( "$SHIM/wget", 0755 );
$GLOBALS['ORIG_PATH'] = getenv( 'PATH' );
// PATH for the "no waiter possible" variant: the tools the gate / harness need, but NO wget.
$GLOBALS['SHIM_DIR'] = $SHIM;
$nowget = "$SHIM/nowget"; @mkdir( $nowget, 0700, true );
foreach ( [ 'sh', 'bash', 'sed', 'tr', 'date', 'sleep', 'nohup', 'cat', 'curl', 'pkill', 'rm', 'env', 'awk', 'grep', 'head', 'tail', 'mkdir' ] as $b ) {
    $p = trim( (string) shell_exec( 'command -v ' . $b . ' 2>/dev/null' ) );
    if ( $p !== '' ) { @symlink( $p, "$nowget/$b" ); }
}
function shims( bool $wget ): void { putenv( 'PATH=' . ( $wget ? $GLOBALS['SHIM_DIR'] . ':' . $GLOBALS['ORIG_PATH'] : $GLOBALS['SHIM_DIR'] . '/nowget' ) ); }
register_shutdown_function( function () use ( $SHIM ) {
    shell_exec( "pkill -f '[n]pp-vt-queue-status' 2>/dev/null; pkill -f '[n]ppp_purged_all_wake' 2>/dev/null" );
    putenv( 'PATH=' . $GLOBALS['ORIG_PATH'] );
    shell_exec( 'rm -rf ' . escapeshellarg( $SHIM ) );
} );

/* ================================================================================================================
 * nginx stand-in (scripted ngx_cache_purge v4 answers) and its queue state
 * ============================================================================================================== */
function status_json( int $pending, int $active ): string {
    return json_encode( [ 'code' => 'status', 'queue' => [ 'size' => 0 ], 'cache' => [ 'pending' => [ 'total' => $pending, 'full' => 0 ], 'active' => $active,
        'files' => [ 'deleted' => 5, 'errors' => 0, 'bytes' => 1 ], 'cleanup' => [ 'scan_errors' => 0, 'retries' => 0, 'exhausted' => 0 ] ] ] );
}
function ngx_busy(): void { file_put_contents( $GLOBALS['FILES']['status'], status_json( 3, 1 ) ); }
function ngx_idle(): void { file_put_contents( $GLOBALS['FILES']['status'], status_json( 0, 0 ) ); }
function ngx_is_busy(): bool { $j = json_decode( (string) @file_get_contents( $GLOBALS['FILES']['status'] ), true ); return ( $j['cache']['pending']['total'] ?? 0 ) > 0 || ( $j['cache']['active'] ?? 0 ) > 0; }

/** $mode: 'purged' (sync 200) | 'invalidated' (generation mode, 200) | 'accepted' (202, queue busy) */
function ngx_script( string $mode ): void {
    $GLOBALS['NGX'] = [ 'mode' => $mode, 'drain_after' => 0, 'purge_all_calls' => 0 ];
    vt_script( '#^(GET|PURGE) http://nginx\.test/npp-vt-queue-status#', function () {
        if ( $GLOBALS['NGX']['drain_after'] > 0 && --$GLOBALS['NGX']['drain_after'] === 0 ) { ngx_idle(); }
        return [ 'code' => 200, 'headers' => [ 'cache-purge-code' => 'status' ], 'body' => (string) file_get_contents( $GLOBALS['FILES']['status'] ) ];
    } );
    vt_script( '#^(GET|PURGE|POST) http://nginx\.test/npp_purge_all#', function () {
        $GLOBALS['NGX']['purge_all_calls']++;
        switch ( $GLOBALS['NGX']['mode'] ) {
            case 'invalidated': return [ 'code' => 200, 'headers' => [ 'cache-purge-code' => 'invalidated' ], 'body' => '{"code":"invalidated"}' ];
            case 'accepted':    ngx_busy(); return [ 'code' => 202, 'headers' => [ 'cache-purge-code' => 'accepted' ], 'body' => '{"code":"accepted"}' ];
            default:            ngx_idle(); return [ 'code' => 200, 'headers' => [ 'cache-purge-code' => 'purged' ], 'body' => '{"code":"purged"}' ];
        }
    } );
}

/* ================================================================================================================
 * Fixture, observation helpers
 * ============================================================================================================== */
$PAGES = [ '/', '/a/', '/b/', '/c/' ];
$V     = lab_start_varnish( 'v1' );

function tl( string $ev ): void { $GLOBALS['T2']['tl'][] = [ 'ev' => $ev, 'busy' => ngx_is_busy(), 't' => microtime( true ) ]; }
/**
 * One scenario: Varnish emptied then primed with the "old site" (every page cached), plugin configured, nginx scripted,
 * observation hooks at priority 1 (they run right before the Varnish listeners at priority 10).
 */
function fx( string $nginx_mode, bool $auto_preload, bool $wget = true, array $extra = [] ): void {
    global $V, $PAGES, $T2;
    foreach ( [ "pkill -f '[n]pp-vt-queue-status' 2>/dev/null", "pkill -f '[n]ppp_purged_all_wake' 2>/dev/null" ] as $c ) { shell_exec( $c ); }
    lab_clear( $V );
    foreach ( $PAGES as $p ) { if ( ! lab_prime( $V, $p ) ) { fwrite( STDERR, "lab: cannot prime $p\n" ); exit( 2 ); } }   // the OLD objects, cached in Varnish before the purge
    lab_plugin_on( [ $V ] );
    vt_set( array_merge( [ 'nppp_http_purge_enabled' => 'yes', 'nppp_http_purge_custom_url' => 'http://nginx.test', 'nppp_http_purge_queue_status_path' => 'npp-vt-queue-status',
        'nginx_cache_auto_preload' => $auto_preload ? 'yes' : 'no', 'nginx_cache_path' => '/cache' ], $extra ) );
    add_filter( 'nppp_http_purge_verify_empty', fn() => false );
    add_filter( 'nppp_http_purge_queue_wait', fn() => 1 );
    $T2 = [ 'cron' => [], 'tl' => [], 'fs_purge' => 0 ];
    @unlink( $GLOBALS['FILES']['log'] );
    ngx_idle(); ngx_script( $nginx_mode );
    nppp_http_purge_gate_state( 'clear' );
    foreach ( [ 'nppp_purged_all', 'nppp_preload_all_started' ] as $h ) { add_action( $h, function () use ( $h ) { tl( 'hook:' . $h ); }, 1, 0 ); }
    putenv( 'NPPP_VT_STATUS=' . $GLOBALS['FILES']['status'] ); putenv( 'NPPP_VT_LOG=' . $GLOBALS['FILES']['log'] );
    putenv( 'NPPP_VT_CRAWL_PORT=' . $V['port'] ); putenv( 'NPPP_VT_CRAWL_PATHS=' . implode( ' ', $PAGES ) );
    shims( $wget );
}
function do_purge_all(): void { nppp_purge( '/cache', '/tmp/pid', '/cache/tmp', false, false, false ); }
function do_preload_all_direct(): void {   // what the Preload All button / WP-Cron / REST end up calling
    nppp_preload_locked( '/cache', '/plugin', '/cache/tmp', 'http://example.com', lab_dir() . '/pid', '', 1280, 100, false, false, false, false, false );
}
function varnish_purges(): array { global $V; return array_values( array_filter( $GLOBALS['VT']['reqs'], fn( $r ) => $r['method'] === 'PURGE' && strpos( $r['url'], '127.0.0.1:' . $V['port'] ) !== false ) ); }
function varnish_bans(): int { return count( array_filter( varnish_purges(), fn( $r ) => ( $r['headers']['X-Purge-Method'] ?? '' ) === 'regex' ) ); }
function hook_events( string $hook ): array { return array_values( array_filter( $GLOBALS['T2']['tl'], fn( $e ) => $e['ev'] === 'hook:' . $hook ) ); }
function log_ts( string $tag ): ?float { foreach ( (array) @file( $GLOBALS['FILES']['log'], FILE_IGNORE_NEW_LINES ) as $l ) { if ( strpos( $l, $tag . ' ' ) === 0 ) { return (float) substr( $l, strlen( $tag ) + 1 ); } } return null; }
function wait_for( callable $f, float $max = 15.0 ): bool { $end = microtime( true ) + $max; while ( microtime( true ) < $end ) { if ( $f() ) { return true; } usleep( 100000 ); } return (bool) $f(); }
function crawl_done( float $max = 20.0 ): bool { return wait_for( fn() => log_ts( 'CRAWL_END' ) !== null, $max ); }
function origin_hits( string $p ): int { return lab_origin( $p ); }
/** Pages whose crawl request reached nginx (origin count rose above the single priming fetch): i.e. the crawl WARMED nginx. */
function warmed_by_crawl(): int { global $PAGES; $n = 0; foreach ( $PAGES as $p ) { if ( origin_hits( $p ) > 1 ) { $n++; } } return $n; }
function cron_run(): void { wp_unschedule_hook( 'nppp_purged_all_deferred_event' ); nppp_http_purge_run_deferred_purged_all(); }
function wake( string $token ): int { $_POST = [ 'action' => 'nppp_purged_all_wake', 'token' => $token ]; try { nppp_purged_all_wake_handler(); } catch ( WpDie $d ) { return $d->http; } return -1; }
function state(): ?array { $s = get_option( 'nppp_purged_all_deferred', null ); return is_array( $s ) ? $s : null; }
function stale_served( string $p ): bool { return strpos( lab_get( $GLOBALS['V'], $p )['body'], 'hit=1' ) !== false; }   // hit=1 = the OLD object (primed before the purge)
function say( string $m ): void { echo "INFO: $m\n"; }

/* ================================================================================================================
 * sync / gen / async : controls. status 0, hook fires once BEFORE the crawl, one ban, crawl warms nginx.
 * ============================================================================================================== */
if ( in_array( $S, [ 'sync', 'gen', 'async' ], true ) ) {
    $mode = [ 'sync' => 'purged', 'gen' => 'invalidated', 'async' => 'accepted' ][ $S ];
    fx( $mode, true );
    if ( $S === 'async' ) { add_filter( 'nppp_http_purge_queue_wait', fn() => 4 ); $GLOBALS['NGX']['drain_after'] = 3; }   // 202, queue drains inside the wait budget
    do_purge_all();
    chk( crawl_done(), "$S: the Auto Preload crawl ran (through Varnish)" );

    chk( $T2['fs_purge'] === 0, "$S: HTTP Purge handled it, no filesystem purge", 'fs purges: ' . $T2['fs_purge'] );
    chk( count( hook_events( 'nppp_purged_all' ) ) === 1, "$S: nppp_purged_all fired exactly once, immediately (status 0)", json_encode( $T2['tl'] ) );
    chk( count( hook_events( 'nppp_preload_all_started' ) ) === 0, "$S: the auto-preload path does not fire nppp_preload_all_started (no duplicate)", json_encode( $T2['tl'] ) );
    chk( ! ngx_is_busy(), "$S: nginx is clean when the hook fires", '' );
    chk( varnish_bans() === 1, "$S: exactly one host-wide ban reached Varnish", 'bans: ' . varnish_bans() );
    chk( state() === null && $T2['cron'] === [], "$S: nothing deferred, no cron", json_encode( [ state(), $T2['cron'] ] ) );
    chk( ! nppp_http_purge_gate_used(), "$S: the crawl was not gated", '' );
    $h = hook_events( 'nppp_purged_all' )[0]['t'] ?? INF; $c = log_ts( 'CRAWL_START' ) ?? 0;
    chk( $h < $c, "$S: Varnish was purged BEFORE the crawl started (order rule)", sprintf( 'hook %.3f crawl %.3f', $h, $c ) );
    chk( warmed_by_crawl() === count( $PAGES ), "$S: the crawl warmed nginx for every page (it was not answered by a stale Varnish object)", 'warmed ' . warmed_by_crawl() . '/' . count( $PAGES ) );
    if ( $S === 'gen' ) { chk( nppp_http_purge_generation_mode( 'all' ), 'gen: generation mode was learned from the invalidated answer (purge-http.php)', '' ); }
    exit( vt_summary() );
}

/* ================================================================================================================
 * deferred : status 7 + Auto Preload + a LIVE waiter (real sh + shim wget). The Varnish ban must wait for nginx.
 * ============================================================================================================== */
if ( $S === 'deferred' ) {
    fx( 'accepted', true, true );
    do_purge_all();
    $st = state();
    chk( $T2['fs_purge'] === 0, 'status 7: no filesystem purge (it would race the queue)', '' );
    chk( is_array( $st ) && ! empty( $st['waiter'] ) && $st['auto_preload'] === true, 'status 7: follow-up armed with a live waiter and auto_preload=true', json_encode( $st ) );
    chk( count( hook_events( 'nppp_purged_all' ) ) === 0, 'status 7: nppp_purged_all NOT fired while nginx still deletes', json_encode( $T2['tl'] ) );
    chk( varnish_bans() === 0, 'status 7: NO ban sent to Varnish while nginx is still serving stale pages (it would be re-cached)', 'bans: ' . varnish_bans() );
    chk( count( hook_events( 'nppp_preload_all_started' ) ) === 0, 'status 7 + auto preload: nppp_preload_all_started is not fired either', json_encode( $T2['tl'] ) );
    chk( nppp_http_purge_gate_used(), 'status 7: the crawl was spawned behind the queue gate', '' );
    usleep( 2500000 );
    chk( log_ts( 'CRAWL_START' ) === null, 'while the queue is busy the gated crawl does not start', '' );
    chk( log_ts( 'POST' ) === null, 'while the queue is busy the waiter does not POST', '' );
    foreach ( $GLOBALS['PAGES'] as $p ) { chk( lab_is_cached( $GLOBALS['V'], $p ), "old object of $p is still in Varnish (expected: nothing to ban yet)", '' ); }

    ngx_idle();   // nginx finished deleting
    chk( wait_for( fn() => log_ts( 'POST' ) !== null, 15 ), 'queue idle: the waiter POSTs the one-shot wake request', '' );
    chk( wake( (string) ( $st['token'] ?? '' ) ) === 200, 'the wake handler claims the one-shot token', '' );
    chk( crawl_done(), 'queue idle: the gated crawl runs', '' );

    chk( count( hook_events( 'nppp_purged_all' ) ) === 1 && ! hook_events( 'nppp_purged_all' )[0]['busy'], 'nppp_purged_all fired once, after nginx became clean', json_encode( $T2['tl'] ) );
    chk( varnish_bans() === 1, 'exactly one ban reached Varnish, after the queue was idle', 'bans: ' . varnish_bans() );
    chk( state() === null, 'one-shot state consumed', '' );
    chk( wake( (string) ( $st['token'] ?? '' ) ) === 403, 'a second wake with the same token is refused (at most once)', '' );
    cron_run();
    chk( varnish_bans() === 1, 'the cron fail-safe after the waiter fired sends nothing (still one ban)', 'bans: ' . varnish_bans() );
    foreach ( $GLOBALS['PAGES'] as $p ) { chk( ! lab_is_cached_since_old( $p ), "final state: no pre-purge object of $p survives in Varnish", '' ); }
    $w = warmed_by_crawl();
    say( "crawl vs. ban race (live waiter): the crawl warmed nginx for $w/" . count( $GLOBALS['PAGES'] ) . ' pages; both wait for the same idle moment, the order between them is not guaranteed' );
    exit( vt_summary() );
}
/** After everything settled: is a pre-purge object still served? (the body counter hit=1 is the object primed before the purge) */
function lab_is_cached_since_old( string $p ): bool { return stale_served( $p ); }

/* ================================================================================================================
 * waiterdead : the documented fail-safe. Waiter died silently, cron is the only wake-up (>= 60 s after the purge),
 * the queue idles earlier -> the gated crawl starts first, through a Varnish that still holds the OLD objects.
 * ============================================================================================================== */
if ( $S === 'waiterdead' ) {
    fx( 'accepted', true, true );
    do_purge_all();
    $st = state();
    chk( is_array( $st ) && ! empty( $st['waiter'] ), 'setup: status 7, waiter spawned', json_encode( $st ) );
    shell_exec( "pkill -f '[n]ppp_purged_all_wake' 2>/dev/null" );   // ... and it dies silently (php-fpm restart, OOM): the documented case
    usleep( 300000 );
    $due = array_values( array_filter( $T2['cron'], fn( $e ) => $e['hook'] === 'nppp_purged_all_deferred_event' ) );
    chk( count( $due ) === 1 && $due[0]['ts'] >= (int) $st['armed_at'] + 60, 'with a waiter the cron fail-safe is the SLOW path (>= 60 s)', json_encode( $due ) );
    chk( varnish_bans() === 0, 'no ban while the queue is busy', '' );

    ngx_idle();                                   // nginx is done at, say, T+20 s; the cron tick is only due at T+60 s
    chk( crawl_done(), 'queue idle: the gated crawl starts and finishes (nothing orders it behind the pending Varnish ban)', '' );
    $pending_at_crawl_end = state() !== null && varnish_bans() === 0;
    chk( $pending_at_crawl_end, 'precondition: the crawl ran while the Varnish ban was still pending', json_encode( [ state(), varnish_bans() ] ) );
    $w = warmed_by_crawl();
    chk( $w === count( $GLOBALS['PAGES'] ), 'BUG? the crawl warmed nginx for every page although the Varnish ban was still pending',
        "warmed $w/" . count( $GLOBALS['PAGES'] ) . ": the crawl was answered by the stale Varnish objects, nginx stays cold for those pages.\n"
        . 'Cause: the crawl gate waits only for nginx\'s queue (nppp_http_purge_gate_snippet), not for the deferred nppp_purged_all that clears Varnish.' );

    cron_run();                                   // T+60 s: the fail-safe finally runs
    chk( varnish_bans() === 1 && count( hook_events( 'nppp_purged_all' ) ) === 1, 'the cron fail-safe fires the hook once and Varnish is banned (control: the follow-up itself is not lost)', 'bans: ' . varnish_bans() );
    chk( ! stale_served( '/' ), 'final state: Varnish serves no pre-purge object', '' );
    exit( vt_summary() );
}

/* ================================================================================================================
 * preloadall : DIRECT Preload All (not Auto Preload): nppp_preload_locked() purges first, then fires
 * nppp_preload_all_started (which compat-varnish.php maps to "purge everything"), then spawns the gated crawl.
 * ============================================================================================================== */
if ( $S === 'preloadall' ) {
    fx( 'accepted', false, true );
    do_preload_all_direct();
    chk( $T2['fs_purge'] === 0, 'direct Preload All behind a busy queue: no filesystem purge (status 7 inside nppp_preload_locked)', 'fs purges: ' . $T2['fs_purge'] );
    chk( nppp_http_purge_gate_used(), 'the crawl was spawned behind the queue gate', '' );
    $varnish_hooks = array_values( array_filter( $T2['tl'], fn( $e ) => strpos( $e['ev'], 'hook:' ) === 0 ) );   // every hook that makes compat-varnish.php send a purge-all
    $while_busy    = array_values( array_filter( $varnish_hooks, fn( $e ) => $e['busy'] ) );
    say( 'this path fires: ' . ( implode( ', ', array_map( fn( $e ) => substr( $e['ev'], 5 ) . ( $e['busy'] ? ' (nginx busy)' : ' (nginx clean)' ), $varnish_hooks ) ) ?: 'nothing' ) . '; deferred state: ' . ( state() ? 'armed' : 'none' ) );
    chk( $while_busy === [], 'BUG? Varnish is purged only after nginx is clean',
        'a Varnish purge-all was triggered while nginx\'s queue was still deleting: ' . implode( ', ', array_map( fn( $e ) => substr( $e['ev'], 5 ), $while_busy ) ) . ' (' . varnish_bans() . ' ban(s) sent at purge time). nginx still serves the old pages, Varnish re-caches them.' );

    // A visitor (or any client) asks Varnish for a page in the window: Varnish was just emptied, nginx still holds the OLD page -> Varnish re-caches it.
    lab_get( $V, '/a/' );
    chk( lab_is_cached( $V, '/a/' ), 'setup: a request in the busy window re-cached /a/ in Varnish (from the stale nginx)', '' );

    ngx_idle();
    chk( crawl_done(), 'queue idle: the gated crawl runs', '' );
    if ( ( $t = state() ) !== null ) { cron_run(); }          // nothing is armed on this path; a tick is harmless
    $late_bans = count( array_filter( $T2['tl'], fn( $e ) => strpos( $e['ev'], 'hook:' ) === 0 && ! $e['busy'] ) );   // each Varnish-triggering hook = one ban
    chk( $late_bans >= 1, 'BUG? a Varnish purge-all was sent AFTER nginx became clean', 'Varnish purge triggers after the queue was idle: ' . $late_bans . '. Nobody purges Varnish again when the queue finishes (the deferral exists only behind nppp_purged_all).' );
    chk( ! lab_is_cached_for_window( $V, '/a/' ), 'BUG? the page re-cached from stale nginx during the window does not survive in Varnish after the queue is idle', 'it is still served from Varnish: stale HTML until its TTL runs out.' );
    exit( vt_summary() );
}
function lab_is_cached_for_window( array $v, string $p ): bool { return lab_is_cached( $v, $p ); }

/* ================================================================================================================
 * supersede : the follow-up chain itself (cron-only, no waiter: PATH without wget, Auto Preload off).
 * ============================================================================================================== */
if ( $S === 'supersede' ) {
    // U1: two Purge Alls behind the same busy queue re-arm ONE chain; one ban after idle, never two.
    fx( 'accepted', false, false );
    do_purge_all(); $t1 = (string) ( state()['token'] ?? '' );
    do_purge_all(); $t2 = (string) ( state()['token'] ?? '' );
    chk( $t1 !== '' && $t1 === $t2, 'U1: the second Purge All re-arms the same chain (same token)', "$t1 / $t2" );
    chk( varnish_bans() === 0 && count( $T2['cron'] ) === 1, 'U1: no ban while busy; a single cron event', json_encode( [ varnish_bans(), $T2['cron'] ] ) );
    ngx_idle(); cron_run();
    chk( varnish_bans() === 1, 'U1: one ban once the queue is idle (two Purge Alls, one follow-up)', 'bans: ' . varnish_bans() );
    chk( wake( $t1 ) === 403 && varnish_bans() === 1, 'U1: a late waiter POST is refused: still one ban', '' );

    // U2: a Purge All that finishes synchronously supersedes the pending chain: one ban now, the old chain dies.
    fx( 'accepted', false, false );
    do_purge_all(); $old = (string) ( state()['token'] ?? '' );
    ngx_script( 'purged' ); ngx_idle();
    do_purge_all();
    chk( varnish_bans() === 1 && state() === null && $T2['cron'] === [], 'U2: the finished Purge All bans immediately and cancels the pending chain', json_encode( [ varnish_bans(), state(), $T2['cron'] ] ) );
    cron_run();
    chk( wake( $old ) === 403 && varnish_bans() === 1, 'U2: neither a late cron tick nor the old waiter bans again', 'bans: ' . varnish_bans() );

    // U3: the queue never reports idle: at the deadline the follow-up runs anyway (a swallowed purge-all would be worse).
    fx( 'accepted', false, false, [] );
    add_filter( 'nppp_http_purge_gate_max_wait', fn() => 30 );
    do_purge_all();
    $s = state(); $s['armed_at'] = time() - ( 30 + 60 + 2 ); update_option( 'nppp_purged_all_deferred', $s, false );
    cron_run();
    chk( varnish_bans() === 1 && count( hook_events( 'nppp_purged_all' ) ) === 1, 'U3: deadline passed while still busy: the hook fires once and Varnish is banned (fail-open)', 'bans: ' . varnish_bans() );
    chk( notice_contains( 'not confirmed idle' ), 'U3: the admin sees the "queue not confirmed idle" warning', '' );

    // U4: the documented opt-out restores the immediate hook: the ban goes out while nginx is busy (the user chose that).
    fx( 'accepted', false, false );
    add_filter( 'nppp_defer_purged_all_while_queue_busy', fn() => false );
    do_purge_all();
    $h = hook_events( 'nppp_purged_all' );
    chk( count( $h ) === 1 && $h[0]['busy'] === true && varnish_bans() === 1 && state() === null, 'U4: filter nppp_defer_purged_all_while_queue_busy=false: immediate hook, nothing deferred (documented opt-out)', json_encode( $T2['tl'] ) );
    exit( vt_summary() );
}
function notice_contains( string $needle ): bool { foreach ( $GLOBALS['VT']['notices'] as $n ) { if ( stripos( $n, $needle ) !== false ) { return true; } } return false; }

exit( vt_summary() );