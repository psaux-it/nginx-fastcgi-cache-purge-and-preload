<?php
/**
 * wget / wget2 x safexec x URL-normalization matrix + purge-gate tests (standalone, no WordPress).
 *
 *   php matrix.php <wget|wget2|wget2-as-wget> [--static]
 *
 * Driven by run-wget-matrix.sh (it builds safexec + libnpp_norm, starts nginx, exports NPPM_* env).
 *
 * Oracle: nginx logs the raw $request_uri it received (HTTP/1.1 request-line or HTTP/2 :path). Every wget
 * command is the REAL output of nppp_wget_build_command() (the same option set each call site passes), run
 * through the same safexec environment the plugin exports (admin/...-admin.php), so safexec's NPP allowlist
 * is exercised by the very command lines production produces.
 *
 * Severity: FAIL = invariant broken (exit 1), WARN = suspicious / documented limitation, INFO = context.
 */
define( 'ABSPATH', '/' );
define( 'NPPM_T0', microtime( true ) );

$FLAVOR = $argv[1] ?? 'wget';
$STATIC = in_array( '--static', $argv, true );
$FLAVORS = [
    'wget'          => [ 'bin' => 'wget',  'flavor' => 'wget'  ],
    'wget2'         => [ 'bin' => 'wget2', 'flavor' => 'wget2' ],
    'wget2-as-wget' => [ 'bin' => 'wget',  'flavor' => 'wget2' ],   // Fedora wget2-wget: `wget` is Wget2
];
if ( ! isset( $FLAVORS[ $FLAVOR ] ) ) { fwrite( STDERR, "unknown flavor $FLAVOR\n" ); exit( 2 ); }
$FL = $FLAVORS[ $FLAVOR ];

$REPO  = getenv( 'NPPM_REPO' );
$WORK  = getenv( 'NPPM_WORK' );
$SFX   = getenv( 'NPPM_SAFEXEC' );
$SFXOLD = getenv( 'NPPM_SAFEXEC_ASIS' );
$P_PROXY = (int) getenv( 'NPPM_PORT_PROXY' );
$P_HTTP = (int) getenv( 'NPPM_PORT_HTTP' ); $P_H1S = (int) getenv( 'NPPM_PORT_H1S' ); $P_H2 = (int) getenv( 'NPPM_PORT_H2' );
$QUICK = getenv( 'NPPM_QUICK' ) === '1';
$ONLY  = array_filter( explode( ',', (string) getenv( 'NPPM_ONLY' ) ) );
if ( $FLAVOR === 'wget2-as-wget' ) { putenv( 'PATH=' . getenv( 'NPPM_ALIAS_DIR' ) . ':' . getenv( 'PATH' ) ); }

/* ------------------------------------------------------------------ WP stubs ---- */
$GLOBALS['OPT'] = []; $GLOBALS['FILTERS'] = [];
function apply_filters( $tag, $v ) { return $GLOBALS['FILTERS'][ $tag ] ?? $v; }
function get_option( $k, $d = false ) { return $GLOBALS['OPT'][ $k ] ?? $d; }
function update_option( $k, $v ) { $GLOBALS['OPT'][ $k ] = $v; return true; }
function delete_option( $k ) { unset( $GLOBALS['OPT'][ $k ] ); return true; }
function wp_cache_delete() { return true; }
function admin_url( $p = '' ) { return 'http://127.0.0.1:' . getenv( 'NPPM_PORT_POST' ) . '/wp-admin/' . $p; }
function nppp_get_runtime_file( string $f ): string { return getenv( 'NPPM_WORK' ) . '/' . $f; }
function nppp_get_wget_compatibility() { global $FL; return [ 'ok' => true, 'reason' => 'ok', 'flavor' => $FL['flavor'], 'bin' => $FL['bin'] ]; }
define( 'NPPP_HEADER_ACCEPT', 'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8' );
define( 'NPPP_USER_AGENT', 'Mozilla/5.0 (X11; Linux x86_64) NPPM' );
require $REPO . '/includes/compat-wget.php';
require $REPO . '/includes/purge-http.php';
$DEF = include $REPO . '/includes/preload-defaults.php';

/* --------------------------------------------------------------------- results ---- */
$RES = [ 'PASS' => 0, 'FAIL' => 0, 'WARN' => 0, 'INFO' => 0 ];
$TSV = fopen( "$WORK/results-$FLAVOR.tsv", 'w' );
$FAILS = [];
function rec( string $sev, string $id, string $msg ): void {
    global $RES, $TSV, $FAILS;
    $RES[ $sev ]++;
    fwrite( $TSV, "$sev\t$id\t" . str_replace( [ "\n", "\t" ], ' ', $msg ) . "\n" );
    if ( $sev !== 'PASS' ) {
        echo sprintf( "%-4s %s\n       %s\n", $sev, $id, str_replace( "\n", "\n       ", $msg ) );
        if ( $sev === 'FAIL' ) { $FAILS[] = $id; }
    }
}
function check( bool $cond, string $id, string $msg, string $sev = 'FAIL' ): bool { rec( $cond ? 'PASS' : $sev, $id, $msg ); return $cond; }
function want( string $group ): bool { global $ONLY; return ! $ONLY || in_array( $group, $ONLY, true ); }
function group( string $name ): void { echo "\n== $name\n"; }

/* --------------------------------------------------------------------- process ---- */
function sh( string $cmd, array $env = [], int $timeout = 40 ): array {
    // Reproducibility: ambient proxy variables (HTTP(S)_PROXY / NO_PROXY ... of the CI box or an agent sandbox) would make
    // wget skip or add a proxy behind the harness' back (127.0.0.1 is usually in NO_PROXY). Scrub them for every child.
    $scrub = [];
    foreach ( [ 'http_proxy', 'https_proxy', 'ftp_proxy', 'all_proxy', 'no_proxy', 'HTTP_PROXY', 'HTTPS_PROXY', 'FTP_PROXY', 'ALL_PROXY', 'NO_PROXY', 'WGETRC' ] as $k ) { $scrub[ $k ] = null; }
    $env  = $env + $scrub;
    $envp = array_merge( getenv(), $env );
    foreach ( $env as $k => $v ) { if ( $v === null ) { unset( $envp[ $k ] ); } }
    $p = proc_open( [ '/bin/sh', '-c', $cmd ], [ 0 => [ 'file', '/dev/null', 'r' ], 1 => [ 'pipe', 'w' ], 2 => [ 'pipe', 'w' ] ], $pipes, '/tmp', $envp );
    if ( ! is_resource( $p ) ) { return [ 'rc' => -1, 'out' => '', 'err' => 'proc_open failed', 'timeout' => false ]; }
    stream_set_blocking( $pipes[1], false ); stream_set_blocking( $pipes[2], false );
    $out = $err = ''; $t = microtime( true ); $to = false;
    while ( true ) {
        $out .= (string) stream_get_contents( $pipes[1] ); $err .= (string) stream_get_contents( $pipes[2] );
        $st = proc_get_status( $p );
        if ( ! $st['running'] ) { break; }
        if ( microtime( true ) - $t > $timeout ) { $to = true; proc_terminate( $p, 9 ); break; }
        usleep( 20000 );
    }
    $out .= (string) stream_get_contents( $pipes[1] ); $err .= (string) stream_get_contents( $pipes[2] );
    fclose( $pipes[1] ); fclose( $pipes[2] );
    $rc = proc_close( $p );
    return [ 'rc' => $to ? -9 : ( $st['exitcode'] >= 0 ? $st['exitcode'] : $rc ), 'out' => $out, 'err' => $err, 'timeout' => $to ];
}

/** Mirror of admin/fastcgi-cache-purge-and-preload-nginx-admin.php (guarded by static test S4). */
function sfx_env( string $mode ): array {
    $e = [ 'SAFEXEC_QUIET' => '1', 'SAFEXEC_SAFE_CWD' => '1', 'SAFEXEC_DETACH' => 'auto', 'SAFEXEC_PCTNORM' => null, 'SAFEXEC_PCTNORM_CASE' => null ];
    switch ( $mode ) {
        case 'upper':    $e['SAFEXEC_PCTNORM'] = '1'; $e['SAFEXEC_PCTNORM_CASE'] = 'upper'; break;
        case 'lower':    $e['SAFEXEC_PCTNORM'] = '1'; $e['SAFEXEC_PCTNORM_CASE'] = 'lower'; break;
        case 'preserve': $e['SAFEXEC_PCTNORM'] = '1'; $e['SAFEXEC_PCTNORM_CASE'] = 'off';   break;
        default:         $e['SAFEXEC_PCTNORM'] = '0'; break;
    }
    return $e;
}

/* ----------------------------------------------------------------------- wire ---- */
function wire_all(): array {
    $f = getenv( 'NPPM_WORK' ) . '/wire.log'; $o = [];
    foreach ( (array) @file( $f, FILE_IGNORE_NEW_LINES ) as $l ) {
        $p = explode( '|', $l );
        if ( count( $p ) < 8 ) { continue; }
        $o[] = [ 'proto' => $p[0], 'scheme' => $p[1], 'port' => $p[2], 'method' => $p[3], 'uri' => $p[4], 'ua' => $p[5], 'host' => $p[6], 't' => (float) $p[7] ];
    }
    return $o;
}
function wire_for( string $tag, bool $skip_aux = true ): array {
    $o = [];
    foreach ( wire_all() as $r ) {
        if ( strpos( $r['ua'], $tag ) === false ) { continue; }
        // wget2 2.1.0 / 2.2.1 fetch /robots.txt even with --no-robots (the plugin's log reader drops it too)
        if ( $skip_aux && preg_match( '#^/(robots\.txt|sitemap)#', $r['uri'] ) ) { continue; }
        $o[] = $r;
    }
    return $o;
}
function wait_wire( string $tag, int $min, float $max = 12.0 ): array {
    $t = microtime( true ); $last = -1; $stable = 0;
    while ( microtime( true ) - $t < $max ) {
        $n = count( wire_for( $tag ) );
        if ( $n >= $min ) { if ( $n === $last ) { $stable++; } else { $stable = 0; } if ( $stable >= 6 ) { break; } }
        $last = $n; usleep( 60000 );
    }
    return wire_for( $tag );
}

/* ---------------------------------------------------------- normalization oracle ---- */
function fold_case( string $s, string $mode ): string {
    return (string) preg_replace_callback( '/%([0-9A-Fa-f]{2})/', static fn( $m ) => '%' . ( $mode === 'upper' ? strtoupper( $m[1] ) : strtolower( $m[1] ) ), $s );
}
function path_query( string $url ): string {
    $p = preg_replace( '#^[a-z][a-z0-9+.-]*://[^/?\#]*#i', '', $url ); $p = preg_replace( '/\#.*$/', '', (string) $p );
    return (string) $p;
}
function triplets( string $s ): array { preg_match_all( '/%([0-9A-Fa-f])([0-9A-Fa-f])/', $s, $m, PREG_SET_ORDER ); return $m; }
/** PRESERVE intent: the Nth %xx of $target gets the hex-letter case of the Nth %xx of ITS OWN input URL. */
function repaint_intent( string $target, ?string $input_url ): string {
    if ( $input_url === null ) { return $target; }
    $src = triplets( path_query( $input_url ) ); $i = 0;
    return (string) preg_replace_callback( '/%([0-9A-Fa-f])([0-9A-Fa-f])/', static function ( $m ) use ( &$i, $src ) {
        $s = $src[ $i++ ] ?? null; if ( $s === null ) { return $m[0]; }
        $f = static function ( string $t, string $c ) { return ctype_alpha( $c ) ? ( ctype_upper( $c ) ? strtoupper( $t ) : strtolower( $t ) ) : $t; };
        return '%' . $f( $m[1], $s[1] ) . $f( $m[2], $s[2] );
    }, $target );
}
function key_of( string $uri ): string { return strtolower( rawurldecode( $uri ) ); }

/* ------------------------------------------------------------------ door builder ---- */
/**
 * Option sets mirror the real call sites (guarded by static test S2: the key set per call site must match).
 *   preflight    includes/preload.php  nppp_detect_premature_process()   (probe)
 *   preload_all  includes/preload.php  nppp_preload_locked() x2          (Preload All, recursive crawl)
 *   single       includes/preload.php  nppp_preload_single_locked()      (Preload single URL)
 *   on_update    includes/preload.php  nppp_preload_cache_on_update()    (auto preload on post update)
 *   related      includes/related.php  nppp_preload_urls_fire_and_forget() (several URLs, ONE wget process)
 */
function door_cmd( string $door, string $sfx_path, string $tr_base, array $paths, string $ua ): string {
    global $DEF, $WORK;
    $dir  = $sfx_path !== '' ? '/tmp' : "$WORK/dl";
    $urls = array_map( static fn( $p ) => $tr_base . $p, $paths );
    $esc  = implode( ' ', array_map( 'escapeshellarg', $urls ) );
    $b = [ 'safexec' => $sfx_path, 'use_proxy' => 'no', 'http_proxy' => '', 'https_proxy' => '', 'limit_rate' => 5120,
           'domains' => '127.0.0.1', 'read_timeout' => 60, 'user_agent' => $ua, 'urls' => $esc ];
    switch ( $door ) {
        case 'preload_all':
            return nppp_wget_build_command( $b + [ 'nohup' => true, 'verbosity' => 'log', 'recursive' => true, 'delete_after' => true,
                'ignore_case' => true, 'tries' => 2, 'dir' => $dir, 'wait' => 0, 'reject_regex' => $DEF['reject_regex'], 'reject_ext' => $DEF['reject_extension'] ] );
        case 'preflight':
            return nppp_wget_build_command( $b + [ 'exec' => true, 'verbosity' => 'quiet', 'no_cache' => true, 'output' => '/dev/null', 'delete_after' => false,
                'ignore_case' => true, 'tries' => 2, 'wait' => 0, 'reject_regex' => $DEF['reject_regex'], 'reject_ext' => $DEF['reject_extension'] ] );
        case 'single':
        case 'on_update':
            return nppp_wget_build_command( $b + [ 'nohup' => true, 'delete_after' => true, 'tries' => 2, 'dir' => $dir ] );
        case 'related':
            return nppp_wget_build_command( $b + [ 'nohup' => true, 'delete_after' => true, 'retry_http' => false, 'tries' => 1, 'dir' => $dir ] );
    }
    throw new RuntimeException( "unknown door $door" );
}
$run_seq = 0;
/** @return array{rc:int,err:string,reqs:array,cmd:string,secs:float} */
function run_door( string $door, bool $sfx, string $mode, string $tr, array $paths, int $expect, array $xenv = [] ): array {
    global $SFX, $WORK, $P_HTTP, $P_H1S, $P_H2, $run_seq;
    $base = [ 'http' => "http://127.0.0.1:$P_HTTP", 'h1s' => "https://127.0.0.1:$P_H1S", 'h2' => "https://127.0.0.1:$P_H2" ][ $tr ];
    $tag  = 'NPPM-' . getmypid() . '-' . ( ++$run_seq );
    $cmd  = door_cmd( $door, $sfx ? $SFX : '', $base, $paths, $tag );
    $t = microtime( true );
    $r = sh( '( ' . $cmd . ' ) > ' . escapeshellarg( "$WORK/last-run.log" ) . ' 2>&1 < /dev/null; echo rc=$?', $xenv + sfx_env( $mode ) );
    $rc = preg_match( '/rc=(\d+)/', $r['out'], $m ) ? (int) $m[1] : -1;
    $log = (string) @file_get_contents( "$WORK/last-run.log" );
    $reqs = wait_wire( $tag, $expect );
    return [ 'rc' => $rc, 'err' => $log, 'reqs' => $reqs, 'cmd' => $cmd, 'secs' => microtime( true ) - $t, 'tag' => $tag ];
}

/* ===================================================================================== */
/*  STATIC GUARDS (run once, on the first flavor)                                         */
/* ===================================================================================== */
if ( $STATIC && want( 'static' ) ) {
    group( 'static guards' );

    // S1  safexec source version must satisfy the plugin's wget2 / stdbuf minimum, else wget2 never runs under safexec.
    $c = file_get_contents( "$REPO/safexec/safexec.c" );
    $ver = preg_match( '/#define\s+SAFEXEC_VERSION\s+"([0-9.]+)"/', $c, $m ) ? $m[1] : '';
    check( version_compare( $ver, NPPP_SAFEXEC_WGET2_MIN, '>=' ), 'S1a:safexec-version-vs-NPPP_SAFEXEC_WGET2_MIN',
        "safexec.c SAFEXEC_VERSION=$ver, plugin NPPP_SAFEXEC_WGET2_MIN=" . NPPP_SAFEXEC_WGET2_MIN
        . ".\nnppp_wget_safexec_supported() compares against the binary's --version, so this safexec is treated as 'too old for wget2':\n"
        . "wget2 then runs WITHOUT safexec (no sandbox, no URL normalization) and the new allowlist / libnpp_norm h2 hook never run." );
    check( version_compare( $ver, NPPP_SAFEXEC_STDBUF_MIN, '>=' ), 'S1b:safexec-version-vs-NPPP_SAFEXEC_STDBUF_MIN', "SAFEXEC_VERSION=$ver, NPPP_SAFEXEC_STDBUF_MIN=" . NPPP_SAFEXEC_STDBUF_MIN );
    // end-to-end proof of the consequence: with the as-is binary the builder silently drops safexec for wget2
    $probe_old = shell_exec( escapeshellarg( (string) $SFXOLD ) . ' --version 2>&1' );
    $built = nppp_wget_build_command( [ 'safexec' => $SFXOLD, 'nohup' => true, 'urls' => "'http://x/'", 'user_agent' => 'u', 'domains' => 'x' ] );
    if ( nppp_wget_is_v2() ) {
        check( strpos( $built, (string) $SFXOLD ) !== false, 'S1c:as-is-safexec-used-for-wget2', 'builder output with the repo-built safexec ('
            . trim( explode( "\n", (string) $probe_old )[0] ) . "): " . substr( $built, 0, 80 ) );
    }

    // S2  call-site drift: every nppp_wget_build_command() call site must be covered by a door definition.
    $expected_sites = [ 'includes/preload.php' => 7, 'includes/related.php' => 2 ];
    $found = [];
    foreach ( glob( "$REPO/includes/*.php" ) as $f ) {
        $n = 0;
        foreach ( file( $f ) as $line ) {
            if ( preg_match( '/nppp_wget_build_command\(/', $line ) && ! preg_match( '/^\s*(\*|\/\/|\/\*)/', $line ) && strpos( $line, 'function nppp_wget_build_command' ) === false ) { $n++; }
        }
        if ( $n ) { $found[ 'includes/' . basename( $f ) ] = $n; }
    }
    ksort( $found ); ksort( $expected_sites );
    check( $found === $expected_sites, 'S2a:wget-call-sites-covered', 'expected ' . json_encode( $expected_sites ) . ' found ' . json_encode( $found )
        . ' -> a new/removed nppp_wget_build_command() call site must get a door in matrix.php' );
    // option-key drift for the call sites that share a door definition
    $door_keys = [
        'single/on_update (preload.php)' => [ 'safexec','nohup','delete_after','tries','read_timeout','use_proxy','http_proxy','https_proxy','dir','limit_rate','domains','user_agent','urls' ],
        'related (related.php)'          => [ 'safexec','nohup','delete_after','retry_http','tries','read_timeout','use_proxy','http_proxy','https_proxy','dir','limit_rate','domains','urls' ],
    ];
    $pre = file_get_contents( "$REPO/includes/preload.php" ); $rel = file_get_contents( "$REPO/includes/related.php" );
    $keys_of = static function ( string $src, int $nth, string $anchor ): array {
        $p = -1; for ( $i = 0; $i <= $nth; $i++ ) { $p = strpos( $src, $anchor, $p + 1 ); if ( $p === false ) { return []; } }
        $e = strpos( $src, ']', $p ); preg_match_all( "/'([a-z_]+)'\s*=>/", substr( $src, $p, $e - $p ), $m ); return $m[1];
    };
    foreach ( [ 'single' => 3, 'on_update' => 5 ] as $nm => $nth ) {   // 0-based occurrence of the call in preload.php incl. probe/crawl sites
        $k = $keys_of( $pre, $nth, 'nppp_wget_build_command([' );
        $exp = $door_keys['single/on_update (preload.php)']; sort( $k ); sort( $exp );
        check( $k === $exp, "S2b:door-keys:$nm", "preload.php call #$nth keys=" . implode( ',', $k ) . ' door=' . implode( ',', $exp ) );
    }
    $k = []; if ( preg_match( '/\$common\s*=\s*\[(.*?)\];/s', $rel, $mm ) ) { preg_match_all( "/'([a-z_]+)'\s*=>/", $mm[1], $m2 ); $k = $m2[1]; }
    $exp = $door_keys['related (related.php)']; sort( $k ); sort( $exp );
    check( $k === $exp, 'S2b:door-keys:related', 'related.php $common keys=' . implode( ',', $k ) . ' door=' . implode( ',', $exp ) );

    // S3  hard-coded wget in command strings (must go through nppp_wget_bin()): compat-wget.php is the only place that may name it.
    $hard = [];
    foreach ( glob( "$REPO/includes/*.php" ) as $f ) {
        if ( basename( $f ) === 'compat-wget.php' || basename( $f ) === 'help.php' ) { continue; }
        foreach ( file( $f ) as $i => $line ) {
            if ( preg_match( '/^\s*(\*|\/\/|\/\*)/', $line ) ) { continue; }
            if ( preg_match( "/['\"]\s*(exec\s+)?wget2?\s+-/", $line ) || preg_match( "/shell_exec\(\s*['\"]wget/", $line ) ) { $hard[] = basename( $f ) . ':' . ( $i + 1 ); }
        }
    }
    check( ! $hard, 'S3:no-hardcoded-wget-command', $hard ? 'hard-coded wget (not nppp_wget_bin()): ' . implode( ', ', $hard ) : 'none', 'WARN' );

    // S4  admin env mapping must equal the mapping this harness uses (sfx_env)
    $adm = file_get_contents( "$REPO/admin/fastcgi-cache-purge-and-preload-nginx-admin.php" );
    $okmap = true;
    foreach ( [ 'upper' => [ '1', 'upper' ], 'lower' => [ '1', 'lower' ], 'preserve' => [ '1', 'off' ] ] as $mode => [ $a, $b ] ) {
        $okmap = $okmap && preg_match( "/case '$mode':\s*putenv\('SAFEXEC_PCTNORM=$a'\);\s*putenv\('SAFEXEC_PCTNORM_CASE=$b'\);/", $adm ) === 1;
    }
    $okmap = $okmap && preg_match( "/default:\s*putenv\('SAFEXEC_PCTNORM=0'\);/", $adm ) === 1;
    check( $okmap, 'S4:pctnorm-env-mapping', 'admin putenv mapping == harness sfx_env(): off->PCTNORM=0, preserve->CASE=off, upper/lower' );

    // S5  safexec Layer 2 must accept EXACTLY what the builder emits and refuse plugin options (all no- / abbreviated forms)
    $bad = [ '--local-plugin=/x.so', '--local-plugin /x.so', '--no-local-plugin=/x.so', '--plugin=x', '--plugin-dirs=/x', '--plugin-opt=a', '--list-plugins',
             '--plugin-help', '--local-plug=/x.so', '--no-plugin=x', '--loc=/x.so', '--no-plugin-dirs=/x' ];
    // Positive control: without it S5 passes vacuously on a safexec that refuses the whole `wget2` name.
    $r = sh( escapeshellarg( (string) $SFX ) . " wget2 --quiet --no-config --no-cookies --delete-after --no-check-certificate --max-threads=1 -- http://127.0.0.1:$P_HTTP/", sfx_env( 'off' ), 20 );
    check( $r['rc'] === 0, 'S5:positive-control-wget2-accepted', "a valid wget2 command must run under safexec, rc={$r['rc']} " . trim( substr( $r['out'] . $r['err'], -160 ) ) );
    foreach ( [ 'wget2' ] as $bin ) {
        foreach ( $bad as $opt ) {
            $r = sh( escapeshellarg( (string) $SFX ) . " $bin --no-config $opt -- http://127.0.0.1:$P_HTTP/", sfx_env( 'off' ), 15 );
            check( $r['rc'] !== 0 && ! file_exists( '/x.so' ), "S5:refuse:$bin:$opt", "rc={$r['rc']} " . trim( substr( $r['out'] . $r['err'], -120 ) ) );
        }
    }
    $r = sh( escapeshellarg( (string) $SFX ) . " wget2 --no-config --max-threads=1 -O /dev/null http://127.0.0.1:$P_HTTP/", sfx_env( 'off' ), 15 );
    check( $r['rc'] !== 0, 'S5:wget2-without-dashdash-refused', "URL not after '--' must be refused, rc={$r['rc']}" );
    $r = sh( escapeshellarg( (string) $SFX ) . " wget2 --quiet --max-threads=1 -- http://127.0.0.1:$P_HTTP/", sfx_env( 'off' ), 15 );
    check( $r['rc'] !== 0, 'S5:wget2-without-no-config-refused', "--no-config is mandatory for wget2, rc={$r['rc']}" );
}

/* ===================================================================================== */
/*  WIRE MATRIX                                                                           */
/* ===================================================================================== */
$SETS_FULL = [
    'mixed'  => [ '/m/caf%c3%A9/%e2%82%aC/?q=%c3%a9%C3%A9' ],
    'lower'  => [ '/l/caf%c3%a9/%e2%82%ac/' ],
    'upper'  => [ '/u/caf%C3%A9/%E2%82%AC/' ],
    'digits' => [ '/d/%20x%25y/' ],
    'plain'  => [ '/n/plain/' ],
    'slash'  => [ '/s/a%2fb%2Fc/' ],
    'resv'   => [ '/r/%3a%3A%40%5b%5D/' ],
    'raw8'   => [ "/raw/caf\u{e9}/" ],
    'query'  => [ '/?a=%c3%a9&b=%C3%A9' ],
    'many'   => [ '/many/' . str_repeat( '%aB', 40 ) . '/' ],
];
$SETS_QUICK = array_intersect_key( $SETS_FULL, array_flip( [ 'mixed', 'lower', 'raw8', 'plain' ] ) );
$MULTI = [   // one wget process, several argv URLs (related.php)
    'rel_mixed_lower' => [ '/a/caf%c3%A9/', '/b/x%e2%82%ac/%C3%A9/' ],
    'rel_lower_upper' => [ '/c/%c3%a9/', '/d/%C3%A9/' ],
    'rel_upper_lower' => [ '/e/%C3%A9%E2%82%AC/', '/f/%c3%a9%e2%82%ac/' ],
    'rel_short_long'  => [ '/g/%aB/', '/h/%c3%a9/%e2%82%ac/%cA%Fb/' ],
    'rel_plain_mixed' => [ '/i/plain/', '/j/%c3%A9/' ],
];
$MODES = [ 'off', 'preserve', 'upper', 'lower' ];
$TRS   = $FL['flavor'] === 'wget2' ? [ 'http', 'h1s', 'h2' ] : [ 'http', 'h1s' ];

/** Per-request oracle. $native = requests of the same run without safexec/shim; $inputs = argv URL paths in order. */
function judge( string $id, string $door, bool $sfx, string $mode, array $res, array $native, array $inputs, string $tr ): void {
    global $FL;
    $n_exp = count( $native['reqs'] );
    if ( ! check( $res['rc'] === 0 && count( $res['reqs'] ) === $n_exp && $n_exp > 0, "$id:started",
        "rc={$res['rc']} requests=" . count( $res['reqs'] ) . " native=$n_exp" . ( $sfx ? "\n" . trim( substr( $res['err'], -300 ) ) : '' ) ) ) { return; }

    $order = $door === 'related' || count( $inputs ) <= 1;
    $mk = static fn( array $rs ) => array_map( static fn( $r ) => $r['uri'], $rs );
    $T = $mk( $res['reqs'] ); $N = $mk( $native['reqs'] );
    if ( ! $order ) { sort( $T ); sort( $N ); }
    else { /* argv order preserved by --max-threads=1 / wget1 */ }
    if ( $door === 'preload_all' ) { usort( $res['reqs'], fn( $a, $b ) => strcmp( key_of( $a['uri'] ), key_of( $b['uri'] ) ) ); usort( $native['reqs'], fn( $a, $b ) => strcmp( key_of( $a['uri'] ), key_of( $b['uri'] ) ) ); $T = $mk( $res['reqs'] ); $N = $mk( $native['reqs'] ); }

    // protocol sanity: the h2 listener must really be exercised by Wget2 (otherwise the :path hook is untested)
    if ( $tr === 'h2' && $FL['flavor'] === 'wget2' ) {
        check( $res['reqs'][0]['proto'] === 'HTTP/2.0', "$id:h2-negotiated", 'proto=' . $res['reqs'][0]['proto'] );
    }

    foreach ( $T as $i => $t ) {
        $nat = $N[ $i ] ?? '';
        $inp = null;
        foreach ( $inputs as $in ) { if ( key_of( path_query( $in ) ) === key_of( $nat ) ) { $inp = $in; break; } }
        if ( $door === 'preload_all' && $inp === null ) { $inp = $GLOBALS['CRAWL_HREFS'][ key_of( $nat ) ] ?? null; }
        if ( ! $sfx || $mode === 'off' ) {
            check( $t === $nat, "$id:identity#$i", ( $sfx ? 'safexec + PCTNORM=0' : 'no safexec' ) . ' must not alter the target' . "\n  native: $nat\n  wire:   $t" );
        } elseif ( $mode === 'upper' || $mode === 'lower' ) {
            check( $t === fold_case( $nat, $mode ), "$id:$mode#$i", "only the hex case may change\n  native: $nat\n  wire:   $t\n  want:   " . fold_case( $nat, $mode ) );
        } else {   // preserve
            $want = repaint_intent( $nat, $inp );
            if ( $t === $want ) { rec( 'PASS', "$id:preserve#$i", $t ); continue; }
            $sev = ( $door === 'preload_all' ) ? 'WARN' : 'FAIL';
            $why = $t === $nat ? 'shim left the native case' : 'shim changed the case but not to the input URL\'s case';
            rec( $sev, "$id:preserve#$i", "PRESERVE must keep each URL's own hex case ($why)\n  input:  " . ( $inp ?? '(none)' ) . "\n  native: $nat\n  wire:   $t\n  want:   $want" );
        }
    }
}

$CRAWL_HREFS = []; $DIVERGE = [];
$UTF8_ENV = [ 'LANG' => 'C.UTF-8', 'LC_CTYPE' => 'C.UTF-8' ];
if ( want( 'wire' ) ) {
    group( "wire matrix  flavor=$FLAVOR (bin={$FL['bin']})" );
    $sets = $QUICK ? $SETS_QUICK : $SETS_FULL;
    $crawl_in = [ '/crawl/', '/crawl/caf%c3%A9/', '/crawl/%e2%82%AC/x%2f/', '/crawl/ABC%3a%3A/?q=%c3%a9%C3%A9' ];
    foreach ( $crawl_in as $h ) { $CRAWL_HREFS[ key_of( $h ) ] = $h; }
    $t_start = microtime( true ); $nruns = 0;

    $door_sets = [
        'single'      => $sets,
        'on_update'   => array_intersect_key( $sets, array_flip( [ 'mixed', 'raw8' ] ) ),
        'preflight'   => array_intersect_key( $sets, array_flip( [ 'mixed', 'upper' ] ) ),
        'related'     => $MULTI + array_intersect_key( $sets, array_flip( [ 'mixed' ] ) ),
        'preload_all' => [ 'crawl' => [ '/crawl/' ] ],
    ];
    foreach ( $door_sets as $door => $dsets ) {
        if ( want( "wire:$door" ) === false && $ONLY && ! in_array( 'wire', $ONLY, true ) ) { continue; }
        foreach ( $TRS as $tr ) {
            foreach ( $dsets as $sname => $paths ) {
                $expect = $door === 'preload_all' ? 4 : count( $paths );
                $native = run_door( $door, false, 'off', $tr, $paths, $expect ); $nruns++;
                $idn = "wire:$FLAVOR:$door:$tr:$sname";
                if ( ! check( $native['rc'] === 0 && count( $native['reqs'] ) >= 1, "$idn:native-ok", "native rc={$native['rc']} requests=" . count( $native['reqs'] ) . "\n" . trim( substr( $native['err'], -300 ) ) ) ) { continue; }
                // safexec exports LANG/LC_CTYPE=C.UTF-8 to the child on purpose; wget1 (IRI) then stops upper-casing %xx.
                // Sfx runs are therefore judged against a baseline in that same locale, and the difference to the plain
                // baseline is reported once as a finding (LOCALE-DIVERGENCE), not as an identity failure.
                $natu = run_door( $door, false, 'off', $tr, $paths, count( $native['reqs'] ), $UTF8_ENV ); $nruns++;
                $a = array_map( fn( $r ) => $r['uri'], $native['reqs'] ); $b = array_map( fn( $r ) => $r['uri'], $natu['reqs'] );
                if ( $door !== 'preload_all' && $a !== $b ) { $DIVERGE[] = "$door/$tr/$sname: plain=" . implode( ' ', $a ) . "  utf8=" . implode( ' ', $b ); }
                foreach ( [ false, true ] as $sfx ) {
                    foreach ( $MODES as $mode ) {
                        // without safexec the mode is irrelevant by design: prove that on a small subset only
                        if ( ! $sfx && $mode !== 'off' && ! in_array( $sname, [ 'mixed', 'rel_mixed_lower', 'crawl' ], true ) ) { continue; }
                        $res = run_door( $door, $sfx, $mode, $tr, $paths, count( $native['reqs'] ) ); $nruns++;
                        judge( "wire:$FLAVOR:$door:$tr:$sname:" . ( $sfx ? 'sfx' : 'nosfx' ) . ":$mode", $door, $sfx, $mode, $res, $sfx ? $natu : $native, $paths, $tr );
                    }
                }
            }
        }
    }
    if ( $DIVERGE ) {
        rec( 'WARN', "wire:$FLAVOR:LOCALE-DIVERGENCE", count( $DIVERGE ) . " case(s): the SAME URL reaches nginx with a different %xx hex case when run under safexec (LANG=C.UTF-8, even with PCTNORM=0/OFF) than without safexec."
            . "\nA case-sensitive cache key therefore differs between the safexec and the no-safexec preload path. Only UPPER/LOWER/PRESERVE make it deterministic.\n  e.g. " . implode( "\n       ", array_slice( $DIVERGE, 0, 4 ) ) );
    } else {
        rec( 'PASS', "wire:$FLAVOR:locale-independent", 'wire request-target identical in plain and UTF-8 locale (no safexec)' );
    }
    rec( 'INFO', 'wire:stats', sprintf( '%d wget runs in %.1fs', $nruns, microtime( true ) - $t_start ) );
}

/* ===================================================================================== */
/*  GATE (Purge All preload gate), WAITER, WATCHDOG, NETWORK PROBE                        */
/* ===================================================================================== */
function qs( string $json ): void { file_put_contents( getenv( 'NPPM_WORK' ) . '/queue-status.json', $json ); }
function qs_remove(): void { @unlink( getenv( 'NPPM_WORK' ) . '/queue-status.json' ); }
const QS_IDLE = '{"pending":{"total":0},"active":0}';
const QS_BUSY = '{"pending":{"total":7},"active":2}';

/** Run nppp_http_purge_gate_command() for a real crawl and return timing facts. */
function gate_case( bool $sfx, string $dirmode = 'normal' ): array {
    global $WORK, $SFX, $P_HTTP, $run_seq;
    $tag = 'NPPM-G' . getmypid() . '-' . ( ++$run_seq );
    $url = "http://127.0.0.1:$P_HTTP/gate/" . $tag . '/';
    $cmd = door_cmd( 'preload_all', $sfx ? $SFX : '', "http://127.0.0.1:$P_HTTP", [ '/gate/' . $tag . '/' ], $tag );
    if ( $dirmode === 'semicolon' && ! $sfx ) {   // the d`;` in the path regression that broke the regex-based exec detection
        $d = "$WORK/dl;x y"; @mkdir( $d, 0777, true );
        $cmd = str_replace( escapeshellarg( "$WORK/dl" ), escapeshellarg( $d ), $cmd );
        $cmd = str_replace( "-P '$WORK/dl'", "-P " . escapeshellarg( $d ), $cmd );
    }
    nppp_http_purge_gate_state( 'arm', [ 'status_url' => "http://127.0.0.1:$P_HTTP/queue-status", 'host' => 'queue.test.local', 'since' => time(), 'armed_at' => time() ] );
    $log = "$WORK/gate-$tag.log";
    $full = nppp_http_purge_gate_command( $cmd, $log );
    return [ 'tag' => $tag, 'full' => $full, 'crawl' => $cmd, 'log' => $log ];
}
function gate_start( array $g ): array {
    $t = microtime( true );
    $r = sh( $g['full'], sfx_env( 'upper' ), 10 );
    return [ 't0' => $t, 'pid' => trim( $r['out'] ), 'rc' => $r['rc'], 'returned_in' => microtime( true ) - $t ];
}

if ( want( 'gate' ) ) {
    group( "purge gate  flavor=$FLAVOR (bin={$FL['bin']})" );
    $polls = static function ( float $since ): array { return array_values( array_filter( wire_all(), static fn( $r ) => $r['uri'] === '/queue-status' && $r['t'] >= $since - 0.05 ) ); };
    foreach ( [ false, true ] as $sfx ) {
        $lbl = "gate:$FLAVOR:" . ( $sfx ? 'sfx' : 'nosfx' );

        // GA idle queue: user request returns at once, 2 idle polls, crawl starts promptly
        qs( QS_IDLE ); $g = gate_case( $sfx ); $s = gate_start( $g );
        $reqs = wait_wire( $g['tag'], 1, 15 );
        check( $s['returned_in'] < 3.0 && ctype_digit( $s['pid'] ), "$lbl:A-idle:returns-immediately", "spawn returned in " . round( $s['returned_in'], 2 ) . "s pid='{$s['pid']}'" );
        check( count( $reqs ) >= 1 && ( $reqs[0]['t'] - $s['t0'] ) < 8, "$lbl:A-idle:crawl-starts", 'crawl request after ' . ( $reqs ? round( $reqs[0]['t'] - $s['t0'], 2 ) : 'never' ) . 's' );
        $pl = $polls( $s['t0'] );
        check( count( $pl ) >= 2, "$lbl:A-idle:two-idle-polls", 'queue polls seen: ' . count( $pl ) );
        if ( $pl ) { check( $pl[0]['host'] === 'queue.test.local', "$lbl:A-idle:poll-host-header", "Host seen by nginx='{$pl[0]['host']}' want queue.test.local (wget -> {$FL['bin']})" ); }
        check( $reqs && count( array_filter( $reqs, fn( $r ) => $r['uri'] === '/gate/' . substr( $g['tag'], 0, 0 ) . $g['tag'] . '/' ) ) >= 1, "$lbl:A-idle:crawl-url", 'crawl hit its own start URL' );

        // GB busy -> idle after 4s: crawl must NOT start before the flip
        qs( QS_BUSY ); $g = gate_case( $sfx ); $s = gate_start( $g ); $flip = microtime( true ) + 4.0;
        while ( microtime( true ) < $flip ) { usleep( 50000 ); }
        $early = wire_for( $g['tag'] ); qs( QS_IDLE );
        $reqs = wait_wire( $g['tag'], 1, 15 );
        check( count( $early ) === 0, "$lbl:B-busy:crawl-held-while-busy", 'requests before the queue went idle: ' . count( $early ) );
        check( $reqs && $reqs[0]['t'] >= $flip - 0.05, "$lbl:B-busy:crawl-after-idle", $reqs ? 'crawl started ' . round( $reqs[0]['t'] - $flip, 2 ) . 's after idle' : 'crawl never started' );

        // GC unreadable status (404) -> fail-open after 5 failed polls, never a stuck crawl
        qs_remove(); $g = gate_case( $sfx ); $s = gate_start( $g );
        $reqs = wait_wire( $g['tag'], 1, 25 );
        check( $reqs && ( $reqs[0]['t'] - $s['t0'] ) < 22, "$lbl:C-404:fail-open", $reqs ? 'crawl started after ' . round( $reqs[0]['t'] - $s['t0'], 1 ) . 's' : 'crawl never started (gate hung)' );
        qs( QS_IDLE );

        // GD garbage body (HTML error page from a proxy): same fail-open
        qs( '<html><body>502 Bad Gateway</body></html>' ); $g = gate_case( $sfx ); $s = gate_start( $g );
        $reqs = wait_wire( $g['tag'], 1, 25 );
        check( $reqs && ( $reqs[0]['t'] - $s['t0'] ) < 22, "$lbl:D-garbage:fail-open", $reqs ? 'crawl started after ' . round( $reqs[0]['t'] - $s['t0'], 1 ) . 's' : 'crawl never started' );
        qs( QS_IDLE );

        // GE hold file: deferred follow-up pending -> wait until the deadline / release
        $hold = nppp_http_purge_hold_file(); file_put_contents( $hold, (string) ( time() + 5 ) );
        $g = gate_case( $sfx ); $s = gate_start( $g ); $rel = microtime( true ) + 2.0;
        while ( microtime( true ) < $rel ) { usleep( 50000 ); }
        $early = wire_for( $g['tag'] ); @unlink( $hold );
        $reqs = wait_wire( $g['tag'], 1, 15 );
        check( count( $early ) === 0, "$lbl:E-hold:crawl-held", 'requests while hold file present: ' . count( $early ) );
        check( count( $reqs ) >= 1, "$lbl:E-hold:crawl-after-release", 'crawl started after hold release: ' . count( $reqs ) );
        file_put_contents( $hold, (string) ( time() - 30 ) );   // expired deadline -> fail open at once
        $g = gate_case( $sfx ); $s = gate_start( $g ); $reqs = wait_wire( $g['tag'], 1, 15 ); @unlink( $hold );
        check( $reqs && ( $reqs[0]['t'] - $s['t0'] ) < 8, "$lbl:E-hold:expired-fails-open", $reqs ? 'started after ' . round( $reqs[0]['t'] - $s['t0'], 1 ) . 's' : 'never' );
        file_put_contents( $hold, 'garbage' );                    // unreadable epoch -> fail open
        $g = gate_case( $sfx ); $s = gate_start( $g ); $reqs = wait_wire( $g['tag'], 1, 15 ); @unlink( $hold );
        check( $reqs && ( $reqs[0]['t'] - $s['t0'] ) < 8, "$lbl:E-hold:garbage-fails-open", $reqs ? 'started after ' . round( $reqs[0]['t'] - $s['t0'], 1 ) . 's' : 'never' );

        // GF shape: exec detection (wget2 + dir -> "mkdir -p ..; cd .. && exec", wget1 -> plain command) and path with ';'
        $g = gate_case( $sfx );
        $has_exec = nppp_wget_command_has_exec( $g['crawl'] );
        check( $has_exec === ( nppp_wget_is_v2() && true ), "$lbl:F-shape:exec-prefix-detection", 'has_exec=' . var_export( $has_exec, true ) . ' crawl=' . substr( $g['crawl'], 0, 70 ) );
        check( substr_count( $g['full'], ' exec ' ) === 1, "$lbl:F-shape:single-exec", 'exactly one exec in the gated command, found ' . substr_count( $g['full'], ' exec ' ) );
        $s = gate_start( $g ); qs( QS_IDLE ); wait_wire( $g['tag'], 1, 15 );
        if ( ! $sfx ) {
            $g = gate_case( false, 'semicolon' ); $s = gate_start( $g ); $reqs = wait_wire( $g['tag'], 1, 15 );
            check( count( $reqs ) >= 1, "$lbl:F-shape:semicolon-in-dir", 'crawl with ";" and space in the download dir started: ' . count( $reqs ) );
        }
    }
    // gate disarmed (no pending purge): byte-for-byte the plain spawn command
    nppp_http_purge_gate_state( 'clear' );
    $plain = nppp_http_purge_gate_command( 'X', '/tmp/l' );
    check( $plain === "X > '/tmp/l' 2>&1 < /dev/null & echo \$!", "gate:$FLAVOR:no-pending:plain-command", $plain );
}

if ( want( 'aux' ) ) {
    group( "waiter / watchdog / network probe  flavor=$FLAVOR (bin={$FL['bin']})" );
    // POST receiver (python) lives on NPPM_PORT_POST; it records "METHOD path body"
    $post_log = "$WORK/post.log"; $tok = bin2hex( random_bytes( 16 ) );

    // W1 deferred-Purge-All waiter: idle gate then ONE token POST
    qs( QS_IDLE ); @unlink( $post_log );
    $cmd = nppp_http_purge_purged_all_waiter_command( [ 'status_url' => "http://127.0.0.1:$P_HTTP/queue-status", 'host' => 'queue.test.local' ], $tok );
    if ( check( $cmd !== null, "aux:$FLAVOR:W1:waiter-command-built", 'waiter command for ' . $FL['bin'] ) ) {
        sh( $cmd, [], 10 );
        $t = microtime( true ); while ( microtime( true ) - $t < 15 && ! is_file( $post_log ) ) { usleep( 100000 ); }
        $got = (string) @file_get_contents( $post_log );
        check( strpos( $got, 'POST' ) === 0 && strpos( $got, "token=$tok" ) !== false && strpos( $got, 'action=' . NPPP_PURGED_ALL_WAKE_ACTION ) !== false,
            "aux:$FLAVOR:W1:waiter-posts-token", 'receiver saw: ' . substr( trim( $got ), 0, 160 ) );
        check( substr_count( $got, "\n" ) <= 1, "aux:$FLAVOR:W1:waiter-posts-once", 'POST count=' . substr_count( $got, "\n" ) );
    }
    // W1b busy -> idle: the POST must wait for the queue
    qs( QS_BUSY ); @unlink( $post_log );
    $cmd = nppp_http_purge_purged_all_waiter_command( [ 'status_url' => "http://127.0.0.1:$P_HTTP/queue-status", 'host' => 'queue.test.local' ], $tok );
    sh( $cmd, [], 10 ); $flip = microtime( true ) + 3.5; while ( microtime( true ) < $flip ) { usleep( 50000 ); }
    $early = is_file( $post_log ); qs( QS_IDLE );
    $t = microtime( true ); while ( microtime( true ) - $t < 15 && ! is_file( $post_log ) ) { usleep( 100000 ); }
    check( ! $early && is_file( $post_log ), "aux:$FLAVOR:W1b:waiter-waits-for-queue", 'POST before idle=' . var_export( $early, true ) . ' after idle=' . var_export( is_file( $post_log ), true ) );

    // W2 watchdog POST (flags copied from includes/watchdog.php; drift-guarded)
    $wd = file_get_contents( "$REPO/includes/watchdog.php" );
    $flags = '-q -O /dev/null --no-check-certificate --no-config --no-cookies --prefer-family=IPv4 --dns-timeout=10 --connect-timeout=5 --read-timeout=60 --tries=2 --max-redirect=2 --post-data';
    $drift = [];
    foreach ( [ "'--no-config --no-cookies --prefer-family=IPv4 '", "'--dns-timeout=10 --connect-timeout=5 --read-timeout=60 '", "'--tries=2 --max-redirect=2 '", "'--post-data %3\$s %4\$s'", "' -q -O /dev/null --no-check-certificate '" ] as $frag ) {
        if ( strpos( $wd, trim( $frag, "' " ) ) === false ) { $drift[] = $frag; }
    }
    check( ! $drift, "aux:$FLAVOR:W2:watchdog-flags-drift-guard", $drift ? 'watchdog.php changed: ' . implode( ' | ', $drift ) : 'flags unchanged' );
    @unlink( $post_log );
    sh( $FL['bin'] . ' ' . $flags . ' ' . escapeshellarg( 'action=nppp_test&token=' . $tok ) . ' ' . escapeshellarg( admin_url( 'admin-ajax.php' ) ), [], 25 );
    $got = (string) @file_get_contents( $post_log );
    check( strpos( $got, 'POST' ) === 0 && strpos( $got, "token=$tok" ) !== false, "aux:$FLAVOR:W2:watchdog-posts", 'receiver saw: ' . substr( trim( $got ), 0, 160 ) );

    // N1 nppp_check_network_env() probes (verbatim command + verdict logic from includes/preload.php)
    $pre = file_get_contents( "$REPO/includes/preload.php" );
    check( strpos( $pre, "' --no-check-certificate --tries=2 -O /dev/null '" ) !== false && strpos( $pre, "' -q --no-check-certificate --server-response --spider '" ) !== false,
        "aux:$FLAVOR:N1:probe-commands-drift-guard", 'preload.php nppp_check_network_env() commands unchanged' );
    $verdict = static function ( string $url ) use ( $FL ): array {
        $t = microtime( true );
        if ( $FL['flavor'] === 'wget2' ) {
            $o = (string) shell_exec( $FL['bin'] . ' --no-check-certificate --tries=2 -O /dev/null ' . escapeshellarg( $url ) . ' 2>&1; echo $?' );
            $l = explode( "\n", trim( $o ) ); $ok = trim( (string) end( $l ) ) === '0' && (bool) preg_match( '/^HTTP response [23]\d\d\b/m', $o );
        } else {
            $o = (string) shell_exec( $FL['bin'] . ' -q --no-check-certificate --server-response --spider ' . escapeshellarg( $url ) . ' 2>&1; echo $?' );
            $l = explode( "\n", trim( $o ) ); $ok = trim( (string) end( $l ) ) === '0';
        }
        return [ $ok, microtime( true ) - $t, $o ];
    };
    foreach ( [ 'http' => "http://127.0.0.1:$P_HTTP/?nppp_probe=x", 'https' => "https://127.0.0.1:$P_H1S/?nppp_probe=x", 'h2' => "https://127.0.0.1:$P_H2/?nppp_probe=x" ] as $nm => $u ) {
        [ $ok, $dt, $o ] = $verdict( $u );
        check( $ok, "aux:$FLAVOR:N1:probe-reachable:$nm", 'verdict ok=' . var_export( $ok, true ) . ' in ' . round( $dt, 1 ) . 's ' . substr( trim( $o ), -100 ) );
    }
    [ $ok, $dt, $o ] = $verdict( 'http://127.0.0.1:1/?nppp_probe=x' );
    check( ! $ok, "aux:$FLAVOR:N1:probe-unreachable-detected", 'closed port must be reported as network failure, got ok=' . var_export( $ok, true ) . ' ' . substr( trim( $o ), -120 ) );
    check( $dt < 20, "aux:$FLAVOR:N1:probe-unreachable-fast", 'unreachable probe took ' . round( $dt, 1 ) . 's (blocks the admin request)', 'WARN' );
}


/* ===================================================================================== */
/*  PROXY door (use_proxy=yes): flag translation + normalization of the absolute-form target */
/* ===================================================================================== */
function proxy_reqs( string $tag ): array {
    $o = [];
    foreach ( (array) @file( getenv( 'NPPM_WORK' ) . '/proxy.log', FILE_IGNORE_NEW_LINES ) as $l ) {
        $p = explode( '|', $l );
        if ( count( $p ) < 4 || strpos( $p[0], $tag ) === false ) { continue; }
        $o[] = [ 'proto' => $p[3], 'uri' => path_query( $p[2] ), 'abs' => $p[2], 'method' => $p[1], 'ua' => $p[0] ];
    }
    return $o;
}
function run_proxy( bool $sfx, string $mode, array $paths, array $xenv = [] ): array {
    global $SFX, $WORK, $P_HTTP, $P_PROXY, $run_seq;
    $tag = 'NPPM-P' . getmypid() . '-' . ( ++$run_seq );
    $purl = "http://127.0.0.1:$P_PROXY";
    $esc = implode( ' ', array_map( static fn( $p ) => escapeshellarg( "http://127.0.0.1:$P_HTTP$p" ), $paths ) );
    $cmd = nppp_wget_build_command( [ 'safexec' => $sfx ? $SFX : '', 'nohup' => true, 'delete_after' => true, 'tries' => 2, 'read_timeout' => 60,
        'use_proxy' => 'yes', 'http_proxy' => $purl, 'https_proxy' => $purl, 'dir' => $sfx ? '/tmp' : "$WORK/dl", 'limit_rate' => 5120,
        'domains' => '127.0.0.1', 'user_agent' => $tag, 'urls' => $esc ] );
    $r = sh( '( ' . $cmd . ' ) > ' . escapeshellarg( "$WORK/last-run.log" ) . ' 2>&1 < /dev/null; echo rc=$?', $xenv + sfx_env( $mode ) );
    $rc = preg_match( '/rc=(\d+)/', $r['out'], $m ) ? (int) $m[1] : -1;
    $t = microtime( true ); $last = -1; $stable = 0;
    while ( microtime( true ) - $t < 10 ) { $n = count( proxy_reqs( $tag ) ); if ( $n >= count( $paths ) ) { if ( $n === $last ) { $stable++; } else { $stable = 0; } if ( $stable >= 5 ) { break; } } $last = $n; usleep( 60000 ); }
    return [ 'rc' => $rc, 'err' => (string) @file_get_contents( "$WORK/last-run.log" ), 'reqs' => proxy_reqs( $tag ), 'origin_hits' => count( wire_for( $tag, false ) ), 'tag' => $tag, 'cmd' => $cmd ];
}
if ( want( 'proxy' ) ) {
    group( "proxy door  flavor=$FLAVOR (bin={$FL['bin']})" );
    $UENV = [ 'LANG' => 'C.UTF-8', 'LC_CTYPE' => 'C.UTF-8' ];
    foreach ( [ 'one' => [ '/px/caf%c3%A9/%e2%82%aC/?q=%c3%a9' ], 'two' => [ '/pa/%c3%a9/', '/pb/%C3%A9/' ] ] as $sn => $paths ) {
        $nat  = run_proxy( false, 'off', $paths ); $natu = run_proxy( false, 'off', $paths, $UENV );
        $id0  = "proxy:$FLAVOR:$sn";
        if ( ! check( $nat['rc'] === 0 && count( $nat['reqs'] ) === count( $paths ), "$id0:native-via-proxy", 'rc=' . $nat['rc'] . ' proxy saw ' . count( $nat['reqs'] ) . ' of ' . count( $paths ) . "\n" . trim( substr( $nat['err'], -250 ) ) ) ) { continue; }
        check( $nat['origin_hits'] === 0, "$id0:proxy-honored", 'origin nginx saw ' . $nat['origin_hits'] . ' direct request(s): the proxy flags were ignored' );
        check( strpos( $nat['reqs'][0]['abs'], 'http://127.0.0.1:' . $P_HTTP ) === 0, "$id0:absolute-form-target", 'target sent to the proxy: ' . $nat['reqs'][0]['abs'] );
        foreach ( [ false, true ] as $sfx ) {
            foreach ( $MODES as $mode ) {
                if ( ! $sfx && $mode !== 'off' ) { continue; }
                $res = run_proxy( $sfx, $mode, $paths );
                judge( "$id0:" . ( $sfx ? 'sfx' : 'nosfx' ) . ":$mode", 'related', $sfx, $mode, $res, $sfx ? $natu : $nat, $paths, 'http' );
                check( $res['origin_hits'] === 0, "$id0:" . ( $sfx ? 'sfx' : 'nosfx' ) . ":$mode:proxy-honored", 'origin nginx saw ' . $res['origin_hits'] . ' direct request(s)' );
            }
        }
    }
}

/* ---------------------------------------------------------------------- summary ---- */
echo sprintf( "\n-- %s: %d PASS, %d FAIL, %d WARN (%.0fs)\n", $FLAVOR, $RES['PASS'], $RES['FAIL'], $RES['WARN'], microtime( true ) - NPPM_T0 );
if ( $FAILS ) { echo "   failing: " . implode( ', ', array_slice( $FAILS, 0, 12 ) ) . ( count( $FAILS ) > 12 ? ' …' : '' ) . "\n"; }
exit( $RES['FAIL'] ? 1 : 0 );
