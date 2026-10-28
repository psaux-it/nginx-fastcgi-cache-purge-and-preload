<?php
/**
 * Tests for the traffic hit ratio code (sampling, restart detection, preload exclusion).
 *
 * Usage: php http-purge-test/traffic-test.php [-v] [path/to/includes/purge-http.php | path/to/plugin-root]
 *
 * Same first argument as run.php, gen-scan.php, gen-index.php and purged-all-deferred.php (the runner
 * passes $PLUGIN_FILE); a plugin root is accepted too, and no argument means the checkout this file is in.
 * state.php and dashboard-widget.php are loaded from the same includes/ directory as purge-http.php.
 * Quiet like the other harness scripts (only FAIL lines and the summary); -v or TRAFFIC_VERBOSE=1 lists every check.
 * Exit code 0 = all passed, 1 = a check failed, 2 = bad usage.
 */

error_reporting( E_ALL );
set_error_handler( static function ( $no, $str, $file, $line ) {
    throw new ErrorException( $str, 0, $no, $file, $line );
} );

$VERBOSE = getenv( 'TRAFFIC_VERBOSE' ) === '1';
$args    = [];
foreach ( array_slice( $argv, 1 ) as $a ) {
    if ( $a === '-v' ) { $VERBOSE = true; } else { $args[] = $a; }
}

$arg   = realpath( $args[0] ?? dirname( __DIR__ ) );
$PURGE = null;
$INC   = null;
if ( $arg !== false && is_file( $arg ) ) {
    $PURGE = $arg;                                 // the file under test, as the other harness scripts take it
    $INC   = dirname( $arg );
} elseif ( $arg !== false && is_dir( $arg . '/includes' ) ) {
    $INC   = $arg . '/includes';                   // plugin root
    $PURGE = $INC . '/purge-http.php';
}
if ( $INC === null || ! is_file( $PURGE ) || ! is_file( $INC . '/state.php' ) || ! is_file( $INC . '/dashboard-widget.php' ) ) {
    fwrite( STDERR, "usage: php traffic-test.php [-v] path/to/includes/purge-http.php   (state.php and dashboard-widget.php must sit next to it)\n" );
    exit( 2 );
}

// ---------------------------------------------------------------------------------------------
// WordPress stubs (in memory)
// ---------------------------------------------------------------------------------------------
define( 'ABSPATH', '/tmp/' );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'DAY_IN_SECONDS', 86400 );

$GLOBALS['OPT']      = [];
$GLOBALS['TRANS']    = [];
$GLOBALS['CRON']     = [];
$GLOBALS['REQUESTS'] = 0;
$GLOBALS['NGX']      = [ 'status' => 200, 'body' => '' ];
$pagenow             = 'index.php';

class WP_Error {
    public $code;
    public $message;

    public function __construct( $code = '', $message = '' ) {
        $this->code    = $code;
        $this->message = $message;
    }

    public function get_error_messages() { return [ $this->message ]; }
}
function is_wp_error( $x ) { return $x instanceof WP_Error; }
function add_action( ...$a ) {}
function add_filter( ...$a ) {}
function remove_action( ...$a ) {}
function apply_filters( $tag, $value, ...$a ) { return $value; }
function home_url() { return 'https://example.test'; }
function wp_parse_url( $u ) { return parse_url( $u ); }
function get_option( $k, $d = false ) { return $GLOBALS['OPT'][ $k ] ?? $d; }
function update_option( $k, $v, $autoload = null ) { $GLOBALS['OPT'][ $k ] = $v; return true; }
function delete_option( $k ) { unset( $GLOBALS['OPT'][ $k ] ); return true; }
function get_transient( $k ) { $t = $GLOBALS['TRANS'][ $k ] ?? null; return ( $t && $t[1] > time() ) ? $t[0] : false; }
function set_transient( $k, $v, $ttl = 0 ) { $GLOBALS['TRANS'][ $k ] = [ $v, time() + $ttl ]; return true; }
function delete_transient( $k ) { unset( $GLOBALS['TRANS'][ $k ] ); return true; }
function wp_cache_delete( ...$a ) { return true; }
function wp_doing_ajax() { return false; }
function wp_next_scheduled( $h ) { return $GLOBALS['CRON'][ $h ]['ts'] ?? false; }
function wp_get_schedule( $h ) { return $GLOBALS['CRON'][ $h ]['rec'] ?? false; }
function wp_schedule_event( $ts, $rec, $h ) { $GLOBALS['CRON'][ $h ] = [ 'ts' => $ts, 'rec' => $rec ]; return true; }
function wp_clear_scheduled_hook( $h ) { unset( $GLOBALS['CRON'][ $h ] ); return 1; }
function wp_remote_request( $url, $args = [] ) {
    $GLOBALS['REQUESTS']++;
    if ( ! empty( $GLOBALS['NGX']['delay'] ) ) {
        sleep( (int) $GLOBALS['NGX']['delay'] ); // slow status endpoint: the clock moves on during the request.
    }
    return [ 'status' => $GLOBALS['NGX']['status'], 'body' => $GLOBALS['NGX']['body'], 'headers' => [ 'cache-purge-code' => 'status' ] ];
}
function wp_remote_retrieve_header( $r, $n ) { return $r['headers'][ strtolower( $n ) ] ?? ''; }
function wp_remote_retrieve_body( $r ) { return $r['body']; }
function wp_remote_retrieve_response_code( $r ) { return $r['status']; }

// i18n / escaping stubs for the render test
function __( $s, $d = '' ) { return $s; }
function esc_html__( $s, $d = '' ) { return $s; }
function esc_attr__( $s, $d = '' ) { return $s; }
function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_attr( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function number_format_i18n( $n, $d = 0 ) { return number_format( (float) $n, $d, '.', ',' ); }
// 37e7bc53: chart tooltips (nppp_traffic_point_label) use _n(), wp_date() and the time_format option.
function _n( $single, $plural, $n, $d = '' ) { return (int) $n === 1 ? $single : $plural; }
function wp_date( $format, $ts = null, $tz = null ) { return gmdate( $format, $ts ?? time() ); }

require_once $INC . '/state.php';
require_once $PURGE;

// The real cycle getter reads an option; keep it, but make the test able to set it.
function set_cycle( int $ts ): void { nppp_preload_cycle_start_set( $ts ); }

$GLOBALS['OPT']['nginx_cache_settings'] = [
    'nppp_http_purge_enabled'           => 'yes',
    'nppp_http_purge_queue_status_path' => 'npp_status',
];

// ---------------------------------------------------------------------------------------------
// Mini framework
// ---------------------------------------------------------------------------------------------
$PASS = 0;
$FAIL = 0;
function say( string $line ): void {
    global $VERBOSE;
    if ( $VERBOSE ) { echo $line; }
}
function t( string $name, bool $ok, string $detail = '' ): void {
    global $PASS, $FAIL;
    if ( $ok ) {
        $PASS++;
        say( "  ok   $name\n" );
    } else {
        $FAIL++;
        echo "FAIL: $name" . ( $detail !== '' ? " [$detail]" : '' ) . "\n";
    }
}
function near( ?float $a, float $b, float $eps = 0.02 ): bool { return $a !== null && abs( $a - $b ) <= $eps; }
function pct( ?float $r ): string { return $r === null ? 'null' : number_format( $r * 100, 1 ) . '%'; }
function mk( int $ts, float $hit, float $miss, int $pl = 0, array $ns = [] ): array {
    $r = [ 'ts' => $ts, 'hit' => $hit, 'miss' => $miss ];
    if ( $pl ) { $r['pl'] = $pl; }
    if ( $ns ) { $r['ns'] = $ns; }
    return $r;
}

// Literal copy of the v2.1.9 algorithm (window + series): the reference for "nothing changed".
function ref_window( array $now, array $samples ): ?array {
    foreach ( $samples as $base ) {
        if ( $base['ts'] > $now['ts'] - 300 ) { break; }
        $dh = $now['hit'] - $base['hit'];
        $dm = $now['miss'] - $base['miss'];
        if ( $dh >= 0 && $dm >= 0 && ( $dh + $dm ) >= 50 ) {
            return [ 'ratio' => $dh / ( $dh + $dm ), 'hits' => $dh, 'misses' => $dm, 'seconds' => $now['ts'] - $base['ts'] ];
        }
        break;
    }
    return null;
}
function ref_series( array $now, array $samples, int $min = 10 ): array {
    $points = [];
    foreach ( $samples as $s ) { $points[] = [ 'ts' => (int) $s['ts'], 'hit' => (float) $s['hit'], 'miss' => (float) $s['miss'] ]; }
    $last = end( $points );
    if ( $last === false || (int) $now['ts'] > $last['ts'] ) {
        $points[] = [ 'ts' => (int) $now['ts'], 'hit' => (float) $now['hit'], 'miss' => (float) $now['miss'] ];
    }
    $cut = (int) $now['ts'] - DAY_IN_SECONDS;
    $out = [];
    for ( $i = 1, $n = count( $points ); $i < $n; $i++ ) {
        $a = $points[ $i - 1 ]; $b = $points[ $i ];
        if ( $b['ts'] <= $a['ts'] || $b['ts'] <= $cut ) { continue; }
        $dh = $b['hit'] - $a['hit']; $dm = $b['miss'] - $a['miss'];
        $out[] = [ 'from' => max( $a['ts'], $cut ), 'to' => $b['ts'], 'ratio' => ( $dh >= 0 && $dm >= 0 && ( $dh + $dm ) >= $min ) ? $dh / ( $dh + $dm ) : null ];
    }
    return $out;
}

// ---------------------------------------------------------------------------------------------
say( "A. Preload exclusion (organic 400 HIT / 100 MISS per day, 5000 page crawl)\n" );
// ---------------------------------------------------------------------------------------------
/**
 * 24h timeline, one sample every 10 min. A crawl runs during steps 60..63 (40 min).
 * $crawl_hit / $crawl_miss are the requests it adds in total.
 */
function timeline( int $crawl_hit, int $crawl_miss, bool $flag ): array {
    $T0    = 1800000000;
    $steps = 144;
    $cs    = $T0 + 60 * 600 + 30;
    $ce    = $T0 + 64 * 600 + 30;
    $samples = [];
    $hit = 0; $miss = 0;
    for ( $i = 0; $i <= $steps; $i++ ) {
        $oh = intdiv( 400 * $i, $steps );
        $om = intdiv( 100 * $i, $steps );
        $frac = ( $i <= 60 ) ? 0 : ( $i >= 64 ? 1 : ( $i - 60 ) / 4 );
        $h = $oh + (int) round( $crawl_hit * $frac );
        $m = $om + (int) round( $crawl_miss * $frac );
        $ts = $T0 + $i * 600;
        $pl = ( $flag && $ts > $cs && $ts < $ce ) ? $cs : 0;
        $samples[] = mk( $ts, $h, $m, $pl );
    }
    $now = array_pop( $samples );
    $gaps = [ [ $cs, $ce ] ];
    return [ $now, $samples, $gaps ];
}

foreach ( [ 'cold crawl (5000 MISS)' => [ 0, 5000, 0.8 ], 'warm crawl (5000 HIT)' => [ 5000, 0, 0.8 ] ] as $label => $c ) {
    [ $now, $samples, $gaps ] = timeline( $c[0], $c[1], true );
    $old = ref_window( $now, $samples );
    $new = nppp_http_purge_traffic_summarize( $now, $samples, $gaps );
    say( '       ' . $label . ': v2.1.9 shows ' . pct( $old['ratio'] ) . ', now shows ' . pct( $new['window']['ratio'] ?? null ) . "\n" );
    t( "$label: v2.1.9 baseline is polluted", ! near( $old['ratio'], $c[2], 0.1 ) );
    t( "$label: window is back to the organic ratio", isset( $new['window'] ) && near( $new['window']['ratio'], $c[2], 0.03 ), pct( $new['window']['ratio'] ?? null ) );
    t( "$label: crawl requests not counted", isset( $new['window'] ) && $new['window']['hits'] + $new['window']['misses'] < 600 );
    t( "$label: excluded seconds reported", ( $new['window']['excluded'] ?? 0 ) >= 2400 );
    t( "$label: not paused (organic data exists)", $new['paused'] === false );
}

// crawl still running / stopped without a mark: the per-sample flag alone must be enough.
[ $now, $samples ] = timeline( 0, 5000, true );
$flag_only = nppp_http_purge_traffic_summarize( $now, $samples, [] );
t( 'flags only (no finished-cycle mark): crawl excluded', near( $flag_only['window']['ratio'], 0.8, 0.03 ), pct( $flag_only['window']['ratio'] ) );

// crawl so short it falls between two samples: only the finished-cycle mark can see it.
[ $now, $samples, $gaps ] = timeline( 0, 5000, false );
$new_nomark = nppp_http_purge_traffic_summarize( $now, $samples, [] );
$new_mark   = nppp_http_purge_traffic_summarize( $now, $samples, $gaps );
t( 'unflagged samples + no mark: crawl leaks in (why the mark exists)', $new_nomark['window']['ratio'] < 0.2 );
t( 'unflagged samples + mark: crawl excluded', near( $new_mark['window']['ratio'], 0.8, 0.03 ), pct( $new_mark['window']['ratio'] ) );

// series: crawl intervals are gaps flagged "preload", organic ones keep their ratio
[ $now, $samples, $gaps ] = timeline( 0, 5000, true );
$ser   = nppp_http_purge_traffic_series( $now, $samples, 10, $gaps );
$dirty = array_filter( $ser, static fn( $r ) => ! empty( $r['preload'] ) );
t( 'series: preload intervals have ratio null + preload flag', count( $dirty ) >= 4 && count( array_filter( $dirty, static fn( $r ) => $r['ratio'] !== null ) ) === 0 );
t( 'series: no interval shows the crawl as a ratio drop', count( array_filter( $ser, static fn( $r ) => $r['ratio'] !== null && $r['ratio'] < 0.3 ) ) === 0 );

// a crawl running right now, nothing organic yet
$T = 1800000000;
$now = mk( $T + 1200, 1, 3000, $T + 600 );
$sm  = nppp_http_purge_traffic_summarize( $now, [ mk( $T, 0, 0 ), mk( $T + 600, 0, 1500, $T + 600 ) ], [] );
t( 'running crawl, no organic data: paused (lifetime ratio must not be shown)', $sm['paused'] === true && $sm['window'] === null );

// ---------------------------------------------------------------------------------------------
say( "B. Restart detection\n" );
// ---------------------------------------------------------------------------------------------
$T   = 1800000000;
$ep1 = [ 'main' => '0000000001' ];
$ep2 = [ 'main' => '0000000002' ];

// Nginx restarted; the new counters are already ABOVE the stored ones (counter test is blind).
$old_samples = [ mk( $T, 100, 480, 0, $ep1 ) ];
$now         = mk( $T + 7200, 2500, 500, 0, $ep2 ); // real traffic since the restart: 83.3%
$ref         = ref_window( $now, $old_samples );
say( '       v2.1.9 shows ' . pct( $ref['ratio'] ) . ' (real since restart: ' . pct( 2500 / 3000 ) . ")\n" );
t( 'v2.1.9 would report a wrong window here', near( $ref['ratio'], 0.8333, 0.05 ) === false );
$next = nppp_http_purge_traffic_next_samples( $old_samples, $now );
t( 'epoch change drops the old history', count( $next ) === 1 && $next[0]['ts'] === $now['ts'] );
$sm = nppp_http_purge_traffic_summarize( $now, $next, [] );
t( 'after the reset there is no mixed window; lifetime ratio is the post-restart truth', $sm['window'] === null && near( $sm['ratio'], 2500 / 3000, 0.001 ) );
// defence in depth: even if such a pair reached summarize, it is not compared
$sm2 = nppp_http_purge_traffic_summarize( $now, $old_samples, [] );
t( 'summarize itself refuses to compare across epochs', $sm2['window'] === null );
$ser = nppp_http_purge_traffic_series( $now, $old_samples );
t( 'series itself shows a gap across epochs', count( $ser ) === 1 && $ser[0]['ratio'] === null );

// counters moving backwards still work without namespaces
$next = nppp_http_purge_traffic_next_samples( [ mk( $T, 900, 100 ) ], mk( $T + 700, 10, 2 ) );
t( 'counter decrease (no namespaces) still resets', count( $next ) === 1 && $next[0]['ts'] === $T + 700 );

// reload that adds a namespace is not a restart
$next = nppp_http_purge_traffic_next_samples( [ mk( $T, 100, 10, 0, $ep1 ) ], mk( $T + 700, 150, 20, 0, [ 'main' => '0000000001', 'extra' => '0000000009' ] ) );
t( 'added namespace on reload keeps history', count( $next ) === 2 );
// one of two namespaces re-created
$next = nppp_http_purge_traffic_next_samples( [ mk( $T, 100, 10, 0, [ 'a' => '0000000001', 'b' => '0000000005' ] ) ], mk( $T + 700, 150, 20, 0, [ 'a' => '0000000001', 'b' => '0000000006' ] ) );
t( 'any namespace epoch change counts as restart', count( $next ) === 1 );
// no epochs anywhere
$next = nppp_http_purge_traffic_next_samples( [ mk( $T, 100, 10 ) ], mk( $T + 700, 150, 20 ) );
t( 'no namespaces at all: unchanged behaviour', count( $next ) === 2 );
t( 'epoch_changed: equality only', nppp_http_purge_traffic_epoch_changed( [ 'a' => '0000000010' ], [ 'a' => '0000000002' ] ) === true );

// ---------------------------------------------------------------------------------------------
say( "C. Regression: no preload, no restart => identical to v2.1.9 (200 random timelines)\n" );
// ---------------------------------------------------------------------------------------------
mt_srand( 1234 );
$same = true; $detail = '';
for ( $k = 0; $k < 200 && $same; $k++ ) {
    $ts = 1800000000; $h = mt_rand( 0, 5000 ); $m = mt_rand( 0, 2000 );
    $samples = [];
    $n = mt_rand( 1, 40 );
    for ( $i = 0; $i < $n; $i++ ) {
        $samples[] = mk( $ts, $h, $m );
        $ts += mt_rand( 540, 4000 ); $h += mt_rand( 0, 300 ); $m += mt_rand( 0, 100 );
    }
    $now = mk( $ts + mt_rand( 0, 700 ), $h + mt_rand( 0, 300 ), $m + mt_rand( 0, 100 ) );
    $samples = array_values( array_filter( $samples, static fn( $s ) => $s['ts'] >= $now['ts'] - DAY_IN_SECONDS ) );
    if ( ! $samples ) { continue; }

    $sm  = nppp_http_purge_traffic_summarize( $now, $samples, [] );
    $ref = ref_window( $now, $samples );
    $okw = ( $ref === null ) ? ( $sm['window'] === null )
        : ( $sm['window'] !== null && abs( $sm['window']['ratio'] - $ref['ratio'] ) < 1e-9
            && $sm['window']['hits'] == $ref['hits'] && $sm['window']['misses'] == $ref['misses']
            && $sm['window']['seconds'] === $ref['seconds'] );
    $rs  = ref_series( $now, $samples );
    // summarize() now feeds the chart from series_merged(); the strict series() stays pinned to the v2.1.9 reference.
    $ns  = array_map( static fn( $r ) => [ 'from' => $r['from'], 'to' => $r['to'], 'ratio' => $r['ratio'] ], nppp_http_purge_traffic_series( $now, $samples, 10, [] ) );
    if ( ! $okw || $ns != $rs ) { $same = false; $detail = "trial $k window=" . ( $okw ? 'same' : 'DIFF' ) . ' series=' . ( $ns == $rs ? 'same' : 'DIFF' ); }
}
t( 'window + strict series() identical to the v2.1.9 algorithm', $same, $detail );

// ---------------------------------------------------------------------------------------------
say( "C2. Window gates: >= 300 s of counted intervals and >= 50 decided requests\n" );
// ---------------------------------------------------------------------------------------------
// These two gates decide "show a percentage" vs "wait, it would be noise". The random timelines above
// never land on them (mutating either constant left every other check green), so pin the edges here.
$T   = 1800000000;
$win = static function ( int $secs, int $hit, int $miss ) use ( $T ) {
    return nppp_http_purge_traffic_summarize( mk( $T + $secs, $hit, $miss ), [ mk( $T, 0, 0 ) ], [] )['window'];
};
t( 'gate: exactly 50 decided requests => window reported', $win( 600, 40, 10 ) !== null );
t( 'gate: 49 decided requests => no window (noise)', $win( 600, 40, 9 ) === null );
t( 'gate: 50 decided requests are HIT+MISS together, one side alone counts too', $win( 600, 0, 50 ) !== null && $win( 600, 50, 0 ) !== null );
t( 'gate: exactly 300 s counted => window reported', $win( 300, 400, 100 ) !== null );
t( 'gate: 299 s counted => no window', $win( 299, 400, 100 ) === null );
t( 'gate: a reported window carries the exact ratio at the edge', ( $w = $win( 300, 40, 10 ) ) !== null && near( $w['ratio'], 0.8, 0.0001 ) );

// ---------------------------------------------------------------------------------------------
say( "D. Sample list: spacing, cap, shape, backward compatibility\n" );
// ---------------------------------------------------------------------------------------------
$s = nppp_http_purge_traffic_next_samples( [ mk( $T, 1, 1 ) ], mk( $T + 539, 2, 2 ) );
t( 'no sample closer than 9 min', count( $s ) === 1 );
$s = nppp_http_purge_traffic_next_samples( [ mk( $T, 1, 1 ) ], mk( $T + 540, 2, 2 ) );
t( 'sample accepted from 9 min on (cron jitter tolerant)', count( $s ) === 2 );
$many = [];
for ( $i = 0; $i < 300; $i++ ) { $many[] = mk( $T + $i * 300, $i, $i ); }
$s = nppp_http_purge_traffic_next_samples( $many, mk( $T + 300 * 300 + 600, 400, 400 ) );
t( 'cap is 170', count( $s ) === 170 );
$s = nppp_http_purge_traffic_next_samples( [ mk( $T, 1, 1 ) ], mk( $T + 600, 2, 2, 0, [] ) );
t( 'empty markers are not stored', ! array_key_exists( 'pl', $s[1] ) && ! array_key_exists( 'ns', $s[1] ) && ! array_key_exists( 'pl', $s[0] ) );
$s = nppp_http_purge_traffic_next_samples( [ mk( $T, 1, 1 ) ], mk( $T + 600, 2, 2, $T + 500, [ 'main' => '0000000001' ] ) );
t( 'non-empty markers are stored', $s[1]['pl'] === $T + 500 && $s[1]['ns'] === [ 'main' => '0000000001' ] );
$s = nppp_http_purge_traffic_next_samples( [ 'junk', [ 'ts' => 'x' ], mk( $T, 1, 1 ), [ 'ts' => 1, 'hit' => 1, 'miss' => 1, 'ns' => 'bad', 'pl' => 'x' ] ], mk( $T + 600, 2, 2 ) );
t( 'malformed stored entries are discarded / sanitised', count( $s ) >= 2 );
$s = nppp_http_purge_traffic_next_samples( [ mk( $T - 2 * DAY_IN_SECONDS, 1, 1 ) ], mk( $T, 2, 2 ) );
t( 'older than 24h dropped', count( $s ) === 1 );
$p = nppp_http_purge_traffic_point( [ 'ts' => 1, 'hit' => 1, 'miss' => 1, 'ns' => [ '123' => '0000000001', 'bad name' => '0000000002', 'x' => 'abc', 'ok_1' => '0000000003' ] ] );
t( 'ns sanitised (numeric name kept, bad name/epoch dropped)', $p['ns'] === [ 123 => '0000000001', 'ok_1' => '0000000003' ] || $p['ns'] === [ '123' => '0000000001', 'ok_1' => '0000000003' ] );

// ---------------------------------------------------------------------------------------------
say( "E. Status reply parsing (namespaces)\n" );
// ---------------------------------------------------------------------------------------------
$eps = nppp_http_purge_traffic_epochs( [ 'namespaces' => [
    [ 'name' => 'main', 'epoch' => '0000000042' ],
    [ 'name' => 'bad name', 'epoch' => '0000000043' ],
    [ 'name' => 'shorte', 'epoch' => '42' ],
    'junk',
    [ 'name' => 'blog', 'epoch' => '0000000044' ],
] ] );
t( 'valid namespaces kept, malformed skipped', $eps === [ 'main' => '0000000042', 'blog' => '0000000044' ] );
$big = [];
for ( $i = 0; $i < 40; $i++ ) { $big[] = [ 'name' => "n$i", 'epoch' => sprintf( '%010d', $i ) ]; }
t( 'at most 16 namespaces', count( nppp_http_purge_traffic_epochs( [ 'namespaces' => $big ] ) ) === 16 );
t( 'no namespaces key: empty map', nppp_http_purge_traffic_epochs( [] ) === [] );

// ---------------------------------------------------------------------------------------------
say( "F. Integration: stats(), stored read, cycle marks, sampler\n" );
// ---------------------------------------------------------------------------------------------
$GLOBALS['NGX']['body'] = json_encode( [ 'code' => 'status', 'cache' => [ 'responses' => [ 'hit' => 3000, 'miss' => 500, 'hit_ratio' => 0.857 ] ],
    'namespaces' => [ [ 'name' => 'main', 'epoch' => '0000000042', 'baseline' => '0000000000000001', 'serial' => '0000000000000001', 'rules' => false ] ] ] );
$GLOBALS['OPT']['nppp_traffic_samples'] = [ mk( time() - 7200, 1000, 200, 0, [ 'main' => '0000000042' ] ) ];
$GLOBALS['REQUESTS'] = 0;
$r = nppp_http_purge_traffic_stats( true );
t( 'stats(): one request, summary returned', $GLOBALS['REQUESTS'] === 1 && is_array( $r ) );
t( 'stats(): window from the stored sample', isset( $r['window'] ) && near( $r['window']['ratio'], 2000 / 2300, 0.001 ), pct( $r['window']['ratio'] ?? null ) );
t( 'stats(): epoch is stored in the new sample', ( end( $GLOBALS['OPT']['nppp_traffic_samples'] )['ns'] ?? null ) === [ 'main' => '0000000042' ] );
$GLOBALS['REQUESTS'] = 0;
$st = nppp_http_purge_traffic_stats_stored();
t( 'stored read: zero requests to Nginx', $GLOBALS['REQUESTS'] === 0 && is_array( $st ) );

// restart between two ticks: epoch changed, counters already higher
$GLOBALS['OPT']['nppp_traffic_samples'] = [ mk( time() - 7200, 100, 20, 0, [ 'main' => '0000000041' ] ) ];
delete_transient( 'nppp_traffic_raw' );
$r = nppp_http_purge_traffic_stats( true );
t( 'stats(): restart (new epoch) resets history even though counters went up', count( $GLOBALS['OPT']['nppp_traffic_samples'] ) === 1 && $r['window'] === null );

// stale stored sample is not painted
$GLOBALS['OPT']['nppp_traffic_samples'] = [ mk( time() - 7200, 1, 1 ), mk( time() - 3600, 2, 2 ) ];
t( 'stored read: newest sample older than 30 min => nothing painted', nppp_http_purge_traffic_stats_stored() === null );
$GLOBALS['OPT']['nppp_traffic_samples'] = [];
t( 'stored read: no samples => nothing painted', nppp_http_purge_traffic_stats_stored() === null );

// preload flag taken from the running cycle
delete_option( 'nppp_st_preload_cycle_start' );
t( 'preload flag: no cycle => 0', nppp_http_purge_traffic_preload_flag() === 0 );
set_cycle( time() - 120 );
$flag = nppp_http_purge_traffic_preload_flag();
t( 'preload flag: running cycle => its start time', $flag > 0 && $flag <= time() - 119 );
$GLOBALS['OPT']['nppp_traffic_samples'] = [ mk( time() - 3600, 10, 10 ) ];
delete_transient( 'nppp_traffic_raw' );
$r = nppp_http_purge_traffic_stats( true );
t( 'stats(): live sample carries the preload flag, summary is paused', ( $GLOBALS['TRANS']['nppp_traffic_raw'][0]['pl'] ?? 0 ) > 0 && $r['paused'] === true );

// cycle end records the window; orphan cleanup does not
$GLOBALS['OPT']['nppp_traffic_gaps'] = [];
set_cycle( time() - 1800 );
nppp_preload_cycle_start_delete();
$g = nppp_http_purge_traffic_gaps();
t( 'cycle end: window recorded', count( $g ) === 1 && $g[0][1] - $g[0][0] >= 1799 && $g[0][1] - $g[0][0] <= 1802 );
t( 'cycle end: cycle record removed', nppp_preload_cycle_start_get( true ) === 0 );
nppp_preload_cycle_start_delete();
t( 'cycle end without a cycle: nothing recorded', count( nppp_http_purge_traffic_gaps() ) === 1 );
set_cycle( time() - 5000 );
nppp_preload_cycle_start_delete( false );
t( 'orphan cleanup (mark=false): nothing recorded', count( nppp_http_purge_traffic_gaps() ) === 1 && nppp_preload_cycle_start_get( true ) === 0 );
$GLOBALS['OPT']['nginx_cache_settings']['nppp_http_purge_enabled'] = 'no';
set_cycle( time() - 600 );
nppp_preload_cycle_start_delete();
t( 'HTTP Purge off: nothing stored', count( nppp_http_purge_traffic_gaps() ) === 1 );
$GLOBALS['OPT']['nginx_cache_settings']['nppp_http_purge_enabled'] = 'yes';
// same cycle reported twice keeps one entry; cap 24; old pruned
$GLOBALS['OPT']['nppp_traffic_gaps'] = [];
$t0 = time() - 3000;
nppp_http_purge_traffic_mark_preload( $t0, $t0 + 100 );
nppp_http_purge_traffic_mark_preload( $t0, $t0 + 400 );
t( 'same cycle twice => one entry, newest end', nppp_http_purge_traffic_gaps() === [ [ $t0, $t0 + 400 ] ] );
for ( $i = 1; $i <= 30; $i++ ) { nppp_http_purge_traffic_mark_preload( time() - 4000 + $i, time() - 3000 + $i ); }
t( 'at most 24 windows kept', count( nppp_http_purge_traffic_gaps() ) === 24 );
$GLOBALS['OPT']['nppp_traffic_gaps'] = [ [ time() - 3 * DAY_IN_SECONDS, time() - 3 * DAY_IN_SECONDS + 600 ] ];
nppp_http_purge_traffic_mark_preload( time() - 100, time() );
t( 'windows older than ~25h pruned', count( nppp_http_purge_traffic_gaps() ) === 1 );
t( 'junk in the option is ignored', ( function () { $GLOBALS['OPT']['nppp_traffic_gaps'] = [ 'x', [ 1 ], [ 'a', 'b' ], [ 5, 3 ], [ 10, 20 ] ]; return nppp_http_purge_traffic_gaps() === [ [ 10, 20 ] ]; } )() );

// sampler scheduling
$GLOBALS['CRON'] = [];
nppp_http_purge_traffic_schedule();
t( 'scheduler: event created (every_10min_npp)', ( $GLOBALS['CRON']['nppp_traffic_sample_event']['rec'] ?? '' ) === 'every_10min_npp' );
$first = $GLOBALS['CRON']['nppp_traffic_sample_event']['ts'];
nppp_http_purge_traffic_schedule();
t( 'scheduler: idempotent', $GLOBALS['CRON']['nppp_traffic_sample_event']['ts'] === $first );
$GLOBALS['CRON']['nppp_traffic_sample_event']['rec'] = 'hourly';
nppp_http_purge_traffic_schedule();
t( 'scheduler: wrong recurrence is healed', $GLOBALS['CRON']['nppp_traffic_sample_event']['rec'] === 'every_10min_npp' );
$GLOBALS['REQUESTS'] = 0; delete_transient( 'nppp_traffic_raw' );
nppp_http_purge_traffic_sample_event();
t( 'tick: takes one sample', $GLOBALS['REQUESTS'] === 1 );
$GLOBALS['OPT']['nginx_cache_settings']['nppp_http_purge_enabled'] = 'no';
nppp_http_purge_traffic_sample_event();
t( 'tick: unschedules itself when HTTP Purge is off', ! isset( $GLOBALS['CRON']['nppp_traffic_sample_event'] ) );
nppp_http_purge_traffic_schedule();
t( 'scheduler: nothing scheduled while HTTP Purge is off', ! isset( $GLOBALS['CRON']['nppp_traffic_sample_event'] ) );
$GLOBALS['OPT']['nginx_cache_settings']['nppp_http_purge_enabled'] = 'yes';

// ---------------------------------------------------------------------------------------------
say( "H. Preload All boundary samples (sampler slower than the pause between two cycles)\n" );
// ---------------------------------------------------------------------------------------------
// pure: $force keeps a sample inside the 9 min spacing, the default does not.
$T = 1800000000;
$two = nppp_http_purge_traffic_next_samples( [ mk( $T, 10, 10 ) ], mk( $T + 60, 20, 20 ) );
t( 'next_samples: inside 9 min => not kept (unchanged)', count( $two ) === 1 );
$two = nppp_http_purge_traffic_next_samples( [ mk( $T, 10, 10 ) ], mk( $T + 60, 20, 20 ), true );
t( 'next_samples: force => kept', count( $two ) === 2 && $two[1]['ts'] === $T + 60 );
$two = nppp_http_purge_traffic_next_samples( [ mk( $T, 10, 10 ) ], mk( $T + 60, 5, 5 ), true );
t( 'next_samples: force does not bypass restart detection (counter went down)', count( $two ) === 1 && $two[0]['ts'] === $T + 60 );

// pure: the failing timeline. One sample every 3 h, a 30 min crawl in the middle of an interval.
$gap = [ [ $T - 2 * 3600, $T - 2 * 3600 + 1800 ] ];
$old = nppp_http_purge_traffic_summarize( mk( $T, 6100, 4010 ), [ mk( $T - 3 * 3600, 1000, 100 ) ], $gap );
t( 'sparse sampler, no boundary samples: whole 3 h interval is lost, card waits', $old['window'] === null && $old['paused'] === true );
$new = nppp_http_purge_traffic_summarize(
    mk( $T, 6100, 4010 ),
    [ mk( $T - 3 * 3600, 1000, 100 ), mk( $T - 2 * 3600, 1100, 110 ), mk( $T - 2 * 3600 + 1800, 6000, 4000 ) ],
    $gap
);
t( 'sparse sampler + boundary samples: window = organic traffic only', $new['window'] !== null && near( $new['window']['ratio'], 200 / 220, 0.001 ) && (int) $new['window']['hits'] === 200 && (int) $new['window']['misses'] === 20, pct( $new['window']['ratio'] ?? null ) );
t( 'sparse sampler + boundary samples: the crawl is reported as excluded', ( $new['window']['excluded'] ?? 0 ) === 1800 );

// integration: start/end of a cycle each take one clean sample.
$GLOBALS['OPT']['nginx_cache_settings']['nppp_http_purge_enabled'] = 'yes';
$GLOBALS['OPT']['nppp_traffic_gaps']    = [];
$GLOBALS['OPT']['nppp_traffic_samples'] = [];
delete_option( 'nppp_st_preload_cycle_start' );
delete_transient( 'nppp_traffic_raw' );
$GLOBALS['NGX']['body'] = json_encode( [ 'code' => 'status', 'cache' => [ 'responses' => [ 'hit' => 500, 'miss' => 50 ] ] ] );

$GLOBALS['REQUESTS'] = 0;
set_cycle( time() );
$sm = $GLOBALS['OPT']['nppp_traffic_samples'];
t( 'cycle start: one request, one sample', $GLOBALS['REQUESTS'] === 1 && count( $sm ) === 1 );
t( 'cycle start: sample is clean (taken before the cycle record exists)', ! isset( $sm[0]['pl'] ) );
t( 'cycle start: cycle record is set afterwards', nppp_preload_cycle_start_get( true ) > 0 );

$GLOBALS['NGX']['body'] = json_encode( [ 'code' => 'status', 'cache' => [ 'responses' => [ 'hit' => 900, 'miss' => 4000 ] ] ] );
$GLOBALS['REQUESTS'] = 0;
nppp_preload_cycle_start_delete();
$sm = $GLOBALS['OPT']['nppp_traffic_samples'];
t( 'cycle end: one request, one more sample inside the 9 min spacing', $GLOBALS['REQUESTS'] === 1 && count( $sm ) === 2 );
t( 'cycle end: sample is clean (taken after the cycle record is gone)', ! isset( $sm[1]['pl'] ) && nppp_preload_cycle_start_get( true ) === 0 );
t( 'cycle end: the cycle is still recorded as a left-out period', count( nppp_http_purge_traffic_gaps() ) === 1 );

$GLOBALS['REQUESTS'] = 0;
nppp_preload_cycle_start_delete();
t( 'cycle end without a cycle: no request', $GLOBALS['REQUESTS'] === 0 && count( $GLOBALS['OPT']['nppp_traffic_samples'] ) === 2 );
set_cycle( time() - 5000 );
$GLOBALS['REQUESTS'] = 0;
nppp_preload_cycle_start_delete( false );
t( 'orphan cleanup (mark=false): no request, no sample', $GLOBALS['REQUESTS'] === 0 );

// HTTP Purge off / no status path: nothing at all.
$GLOBALS['OPT']['nginx_cache_settings']['nppp_http_purge_enabled'] = 'no';
$GLOBALS['OPT']['nppp_traffic_samples'] = [];
$GLOBALS['REQUESTS'] = 0;
set_cycle( time() );
nppp_preload_cycle_start_delete();
t( 'HTTP Purge off: cycle start/end make no request and store no sample', $GLOBALS['REQUESTS'] === 0 && $GLOBALS['OPT']['nppp_traffic_samples'] === [] );
$GLOBALS['OPT']['nginx_cache_settings']['nppp_http_purge_enabled'] = 'yes';
$GLOBALS['OPT']['nginx_cache_settings']['nppp_http_purge_queue_status_path'] = '';
$GLOBALS['REQUESTS'] = 0;
set_cycle( time() );
nppp_preload_cycle_start_delete();
t( 'no status path: cycle start/end make no request', $GLOBALS['REQUESTS'] === 0 );
$GLOBALS['OPT']['nginx_cache_settings']['nppp_http_purge_queue_status_path'] = 'npp_status';

// endpoint down: cycle start/end must not fail or throw, the cycle bookkeeping is untouched.
$GLOBALS['NGX']['status'] = 503;
delete_transient( 'nppp_traffic_raw' );
$GLOBALS['OPT']['nppp_traffic_gaps'] = [];
set_cycle( time() - 600 );
nppp_preload_cycle_start_delete();
t( 'endpoint down: no exception, cycle still recorded and cleared', count( nppp_http_purge_traffic_gaps() ) === 1 && nppp_preload_cycle_start_get( true ) === 0 );
$GLOBALS['NGX']['status'] = 200;
delete_transient( 'nppp_traffic_raw' );

// slow status request: the cycle is stamped in one second, the boundary sample lands in the next.
// The cycle (left-out period) must not begin before that sample, otherwise the organic interval
// ending in it is dropped as preload and the card never gets a window.
$GLOBALS['OPT']['nppp_traffic_gaps']    = [];
$GLOBALS['OPT']['nppp_traffic_samples'] = [ mk( time() - 600, 100, 10 ) ];
delete_option( 'nppp_st_preload_cycle_start' );
delete_transient( 'nppp_traffic_raw' );
$GLOBALS['NGX']['body']  = json_encode( [ 'code' => 'status', 'cache' => [ 'responses' => [ 'hit' => 300, 'miss' => 20 ] ] ] );
$GLOBALS['NGX']['delay'] = 1;
set_cycle( time() );
$sm = $GLOBALS['OPT']['nppp_traffic_samples'];
t( 'slow status request: boundary sample stored', count( $sm ) === 2 );
t( 'slow status request: cycle record starts at or after the boundary sample', count( $sm ) === 2 && nppp_preload_cycle_start_get( true ) >= $sm[1]['ts'], nppp_preload_cycle_start_get( true ) . ' vs ' . ( $sm[1]['ts'] ?? 0 ) );
$GLOBALS['NGX']['body'] = json_encode( [ 'code' => 'status', 'cache' => [ 'responses' => [ 'hit' => 300, 'miss' => 520 ] ] ] );
nppp_preload_cycle_start_delete();
$GLOBALS['NGX']['delay'] = 0;
delete_transient( 'nppp_traffic_raw' );
$ser = nppp_http_purge_traffic_stats_stored()['series'] ?? [];
t( 'slow status request: the interval before the cycle stays organic', isset( $ser[0] ) && empty( $ser[0]['preload'] ) && near( $ser[0]['ratio'], 200 / 210, 0.001 ), json_encode( $ser[0] ?? null ) );
// failed boundary request: the cycle start is left alone.
$GLOBALS['NGX']['status'] = 503;
delete_transient( 'nppp_traffic_raw' );
set_cycle( 1800000123 );
t( 'endpoint down at cycle start: cycle record keeps the given timestamp', nppp_preload_cycle_start_get( true ) === 1800000123 );
$GLOBALS['NGX']['status'] = 200;
delete_transient( 'nppp_traffic_raw' );
nppp_preload_cycle_start_delete( false );

// ---------------------------------------------------------------------------------------------
say( "G. Dashboard card rendering\n" );
// ---------------------------------------------------------------------------------------------
require_once $INC . '/dashboard-widget.php';
$summary = [ 'hit' => 10.0, 'miss' => 5000.0, 'ratio' => 0.002, 'window' => null, 'paused' => true, 'series' => [] ];
$html = nppp_render_traffic_row( $summary );
t( 'render: paused => explanatory text, no polluted lifetime percentage', strpos( $html, 'Preload traffic is left out' ) !== false && strpos( $html, 'nppp-traffic-pct' ) === false );
$summary = [ 'hit' => 10.0, 'miss' => 5000.0, 'ratio' => 0.002, 'window' => null, 'paused' => false, 'series' => [] ];
t( 'render: not paused => lifetime fallback unchanged', strpos( nppp_render_traffic_row( $summary ), 'since Nginx restart' ) !== false );
$summary = [ 'hit' => 1.0, 'miss' => 1.0, 'ratio' => 0.5, 'window' => [ 'ratio' => 0.8, 'hits' => 400.0, 'misses' => 100.0, 'seconds' => 86000, 'excluded' => 2400 ], 'paused' => false, 'series' => [] ];
$html = nppp_render_traffic_row( $summary );
t( 'render: window with excluded preload says so', strpos( $html, 'preload excluded' ) !== false && strpos( $html, '80.0' ) !== false );
$summary['window']['excluded'] = 0;
t( 'render: nothing excluded => no extra label', strpos( nppp_render_traffic_row( $summary ), 'preload excluded' ) === false );
t( 'render: null summary => empty', nppp_render_traffic_row( null ) === '' );
t( 'render: tooltip no longer claims preload is included', strpos( $html, 'preload crawler included' ) === false );

// ---------------------------------------------------------------------------------------------
say( "H. Merged series (low-traffic sites)\n" );
// ---------------------------------------------------------------------------------------------
$M = 'nppp_http_purge_traffic_series_merged';
// Low-traffic merge: real-world sample set (a burst, then 1-4 requests per 10 min) must still draw a chart
$real = [ mk(1792863595,0,0), mk(1792864141,310,142), mk(1792864772,313,142), mk(1792865401,314,142), mk(1792866000,314,142), mk(1792866566,317,143) ];
$now  = [ 'hit' => 317.0, 'miss' => 143.0, 'ts' => 1792867166, 'ns' => [] ];
$old  = nppp_http_purge_traffic_series( $now, $real, 10, [] );
$new  = $M( $now, $real, 5, [] );
$dec  = fn( $s ) => count( array_filter( $s, fn( $r ) => $r['ratio'] !== null ) );
t( 'merge/low-traffic: old algorithm has <2 decided points (chart missing)', $dec( $old ) < 2, 'old decided=' . $dec( $old ) );
t( 'merge/low-traffic: merged algorithm has >=2 decided points (chart shows)', $dec( $new ) >= 2, 'new decided=' . $dec( $new ) );
$sm = nppp_http_purge_traffic_summarize( $now, $real, [] );
$html = nppp_render_traffic_row( $sm );
t( 'merge/low-traffic: widget renders an SVG chart, not "Collecting"', strpos( $html, 'Collecting data' ) === false && strpos( $html, '<svg' ) !== false );

// 2. Dense traffic (every step >= min, nothing dirty): must equal the old algorithm exactly
$same = true; $detail = '';
mt_srand( 7 );
for ( $k = 0; $k < 400 && $same; $k++ ) {
    $ts = 1780000000; $h = 0.0; $m = 0.0; $smp = [];
    $steps = mt_rand( 3, 30 );
    for ( $i = 0; $i < $steps; $i++ ) { $smp[] = mk( $ts, $h, $m ); $ts += mt_rand( 540, 700 ); $h += mt_rand( 5, 300 ); $m += mt_rand( 5, 100 ); }
    $nw = [ 'hit' => $h, 'miss' => $m, 'ts' => $ts, 'ns' => [] ];
    $a = array_map( fn( $r ) => [ $r['from'], $r['to'], $r['ratio'] ], nppp_http_purge_traffic_series( $nw, $smp, 10, [] ) );
    $b = array_map( fn( $r ) => [ $r['from'], $r['to'], $r['ratio'] ], $M( $nw, $smp, 10, [] ) );
    if ( $a != $b ) { $same = false; $detail = "trial $k"; }
}
t( 'dense traffic: merged == old series (no behaviour change when traffic is healthy)', $same, $detail );

// 3. Structural invariants on random sparse/dirty/restart data
$ok = true; $why = '';
mt_srand( 11 );
for ( $k = 0; $k < 600 && $ok; $k++ ) {
    $ts = 1780000000; $h = 0.0; $m = 0.0; $smp = []; $gaps = [];
    $steps = mt_rand( 2, 40 );
    for ( $i = 0; $i < $steps; $i++ ) {
        $smp[] = mk( $ts, $h, $m );
        $ts += mt_rand( 540, 700 );
        if ( mt_rand( 0, 9 ) === 0 ) { $h = 0.0; $m = 0.0; }            // counter reset (restart)
        $h += mt_rand( 0, 6 ); $m += mt_rand( 0, 3 );                    // sparse traffic
        if ( mt_rand( 0, 14 ) === 0 ) { $gaps[] = [ $ts - 700, $ts - 100 ]; }
    }
    $nw  = [ 'hit' => $h, 'miss' => $m, 'ts' => $ts, 'ns' => [] ];
    $ser = $M( $nw, $smp, 5, $gaps );
    $prev = 0;
    foreach ( $ser as $r ) {
        if ( $r['from'] < $prev ) { $ok = false; $why = "trial $k overlap"; break; }
        if ( $r['to'] <= $r['from'] ) { $ok = false; $why = "trial $k zero-length"; break; }
        if ( $r['ratio'] !== null && ( $r['ratio'] < 0 || $r['ratio'] > 1 ) ) { $ok = false; $why = "trial $k bad ratio"; break; }
        if ( $r['ratio'] !== null && ! empty( $r['preload'] ) ) { $ok = false; $why = "trial $k preload row has ratio"; break; }
        $prev = $r['to'];
    }
    if ( $ok && $ser !== [] && end( $ser )['to'] !== $ts ) { $ok = false; $why = "trial $k axis does not end at now (ends " . ( $ts - end( $ser )['to'] ) . 's early)'; }
}
t( 'invariants: ordered, non-overlapping, ratios sane, preload rows ratio-free, axis ends at now', $ok, $why );

// ---------------------------------------------------------------------------------------------
echo "\n$PASS passed, $FAIL failed\n";
exit( $FAIL === 0 ? 0 : 1 );
