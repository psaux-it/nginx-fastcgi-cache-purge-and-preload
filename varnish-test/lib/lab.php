<?php
/**
 * Lab for the e2e layer (tests/e2e.php): a REAL varnishd in front of a counting backend (lab/backend.py).
 *
 *   visitor (curl) -> varnishd 127.0.0.1:<port> -> lab/backend.py 127.0.0.1:<port>
 *
 * The VCL under test is NOT a copy: lab_example_vcl() cuts the "Example VCL" out of includes/help.php, i.e. the
 * exact text a site owner copies from the plugin's Help tab. A backend stanza is appended (the Help text is a
 * snippet without one). Variants of it (no regex branch, ACL that refuses us, no PURGE handler) are made by
 * substituting one line, so each variant differs from the documented VCL in exactly one thing.
 *
 * Everything lives under one work directory (VT_LAB, default /tmp/npp-vt-lab-<pid>) and is torn down on exit.
 * varnishd runs in the foreground (-F) as a child of this PHP process; it needs a C compiler to build the VCL.
 */
require_once __DIR__ . '/wpstub.php';

const LAB_SECRET = 'change-me-long-random-secret';   // the secret that is written in the documented example VCL
const LAB_HOST   = 'example.com';                     // the site authority: home_url() = http://example.com

$GLOBALS['LAB'] = [ 'dir' => '', 'procs' => [], 'backend' => 0, 'registered' => false ];

function lab_dir(): string {
    if ( $GLOBALS['LAB']['dir'] === '' ) {
        $d = getenv( 'VT_LAB' ) ?: sys_get_temp_dir() . '/npp-vt-lab-' . getmypid();
        if ( ! is_dir( $d ) && ! mkdir( $d, 0755, true ) ) { fwrite( STDERR, "cannot create $d\n" ); exit( 2 ); }
        $GLOBALS['LAB']['dir'] = $d;
    }
    if ( ! $GLOBALS['LAB']['registered'] ) {
        $GLOBALS['LAB']['registered'] = true;
        register_shutdown_function( 'lab_teardown' );
    }
    return $GLOBALS['LAB']['dir'];
}

/** Everything the lab needs is installed? Returns '' or the reason it can not run. */
function lab_missing(): string {
    foreach ( [ 'varnishd', 'varnishadm', 'python3' ] as $bin ) {
        if ( trim( (string) shell_exec( 'command -v ' . escapeshellarg( $bin ) . ' 2>/dev/null' ) ) === '' ) { return "$bin not found"; }
    }
    if ( ! extension_loaded( 'curl' ) ) { return 'PHP curl extension not loaded'; }
    if ( trim( (string) shell_exec( 'command -v cc 2>/dev/null' ) ) === '' ) { return 'no C compiler (varnishd compiles VCL with cc)'; }
    return '';
}

function lab_free_port(): int {
    $s = stream_socket_server( 'tcp://127.0.0.1:0', $en, $es );
    if ( ! $s ) { fwrite( STDERR, "no free port: $es\n" ); exit( 2 ); }
    $port = (int) substr( strrchr( stream_socket_get_name( $s, false ), ':' ), 1 );
    fclose( $s );
    return $port;
}

/* ---- VCL --------------------------------------------------------------------------------------------------- */

/** The "Example VCL" from includes/help.php, HTML-decoded. Exits 2 when the block can not be found (refactor). */
function lab_example_vcl(): string {
    $help = file_get_contents( vt_includes() . '/help.php' );
    if ( ! preg_match( '#<pre>(vcl 4\.1;.*?)</pre>#s', (string) $help, $m ) ) {
        fwrite( STDERR, "Example VCL block (<pre>vcl 4.1; ...</pre>) not found in includes/help.php\n" );
        exit( 2 );
    }
    return html_entity_decode( strip_tags( $m[1] ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
}

/**
 * VCL text for a variant.
 *   example   the documented VCL unchanged
 *   noregex   only `return (purge)`: X-Purge-Method: regex falls through to it (the gap the Help text warns about)
 *   aclnone   the ACL does not contain 127.0.0.1 -> every PURGE gets 405
 *   nopurge   no PURGE handler at all
 */
function lab_vcl( string $variant, int $backend_port ): string {
    $v = lab_example_vcl();
    $sub = static function ( string $v, string $from, string $to ): string {
        if ( strpos( $v, $from ) === false ) { fwrite( STDERR, "example VCL no longer contains " . json_encode( $from ) . " - update lab_vcl()\n" ); exit( 2 ); }
        return str_replace( $from, $to, $v );
    };
    switch ( $variant ) {
        case 'example': break;
        case 'noregex': $v = $sub( $v, 'req.http.X-Purge-Method == "regex"', 'req.http.X-Purge-Method == "__never__"' ); break;
        case 'aclnone': $v = $sub( $v, 'acl purge { "127.0.0.1"; "::1"; }', 'acl purge { "203.0.113.7"; }' ); break;
        case 'nopurge': $v = $sub( $v, 'req.method == "PURGE"', 'req.method == "__never__"' ); break;
        default: fwrite( STDERR, "unknown VCL variant $variant\n" ); exit( 2 );
    }
    return $v . "\nbackend default { .host = \"127.0.0.1\"; .port = \"$backend_port\"; }\n";
}

/** `varnishd -C` compile check. Returns '' when it compiles, else the compiler output. */
function lab_vcl_check( string $vcl_text ): string {
    $f = lab_dir() . '/check-' . md5( $vcl_text ) . '.vcl';
    file_put_contents( $f, $vcl_text );
    $out = []; $rc = 0;
    exec( 'varnishd -n ' . escapeshellarg( lab_dir() . '/check-wd' ) . ' -C -f ' . escapeshellarg( $f ) . ' 2>&1 >/dev/null', $out, $rc );
    return $rc === 0 ? '' : implode( "\n", $out );
}

/* ---- processes --------------------------------------------------------------------------------------------- */

function lab_spawn( string $name, array $cmd ) {
    $log = lab_dir() . "/$name.log";
    $h = proc_open( $cmd, [ 0 => [ 'file', '/dev/null', 'r' ], 1 => [ 'file', $log, 'a' ], 2 => [ 'file', $log, 'a' ] ], $pipes );
    if ( ! is_resource( $h ) ) { fwrite( STDERR, "cannot start $name\n" ); exit( 2 ); }
    $GLOBALS['LAB']['procs'][ $name ] = $h;
    return $h;
}

function lab_wait_port( int $port, float $max = 15.0 ): bool {
    $end = microtime( true ) + $max;
    while ( microtime( true ) < $end ) {
        $s = @stream_socket_client( "tcp://127.0.0.1:$port", $en, $es, 0.3 );
        if ( $s ) { fclose( $s ); return true; }
        usleep( 100000 );
    }
    return false;
}

function lab_start_backend(): int {
    if ( $GLOBALS['LAB']['backend'] ) { return $GLOBALS['LAB']['backend']; }
    $port = lab_free_port();
    lab_spawn( 'backend', [ 'python3', dirname( __DIR__ ) . '/lab/backend.py', (string) $port ] );
    if ( ! lab_wait_port( $port ) ) { fwrite( STDERR, "backend did not start, see " . lab_dir() . "/backend.log\n" ); exit( 2 ); }
    return $GLOBALS['LAB']['backend'] = $port;
}

/**
 * Starts a varnishd with the given VCL variant. Returns [ 'name', 'port', 'admin', 'wd' ].
 * The cache child must be running (a VCL that does not compile makes this exit 2 with the compiler output).
 */
function lab_start_varnish( string $name, string $variant = 'example' ): array {
    $bp   = lab_start_backend();
    $vcl  = lab_vcl( $variant, $bp );
    $bad  = lab_vcl_check( $vcl );
    if ( $bad !== '' ) { fwrite( STDERR, "VCL variant '$variant' does not compile:\n$bad\n" ); exit( 2 ); }

    $dir  = lab_dir();
    $file = "$dir/$name.vcl";
    file_put_contents( $file, $vcl );
    $port = lab_free_port();
    $adm  = lab_free_port();
    $wd   = "$dir/$name-wd";
    lab_spawn( $name, [ 'varnishd', '-F', '-n', $wd, '-a', "127.0.0.1:$port", '-T', "127.0.0.1:$adm", '-f', $file, '-s', 'malloc,32m', '-p', 'thread_pool_min=5', '-p', 'thread_pool_max=50', '-p', 'ban_lurker_age=1', '-p', 'ban_lurker_sleep=0.01' ] );

    if ( ! lab_wait_port( $port ) ) { fwrite( STDERR, "varnishd '$name' did not start:\n" . @file_get_contents( "$dir/$name.log" ) . "\n" ); exit( 2 ); }
    // the listener can be up a moment before the child says "running"
    $end = microtime( true ) + 10;
    while ( microtime( true ) < $end ) {
        if ( strpos( (string) shell_exec( 'varnishadm -n ' . escapeshellarg( $wd ) . ' status 2>&1' ), 'running' ) !== false ) { break; }
        usleep( 100000 );
    }
    return [ 'name' => $name, 'port' => $port, 'admin' => $adm, 'wd' => $wd ];
}

function lab_stop( string $name ): void {
    $h = $GLOBALS['LAB']['procs'][ $name ] ?? null;
    if ( ! is_resource( $h ) ) { return; }
    proc_terminate( $h, 15 );
    $end = microtime( true ) + 6;
    while ( microtime( true ) < $end ) {
        if ( ! proc_get_status( $h )['running'] ) { break; }
        usleep( 100000 );
    }
    if ( proc_get_status( $h )['running'] ) { proc_terminate( $h, 9 ); }
    proc_close( $h );
    unset( $GLOBALS['LAB']['procs'][ $name ] );
}

function lab_teardown(): void {
    foreach ( array_reverse( array_keys( $GLOBALS['LAB']['procs'] ) ) as $n ) { lab_stop( $n ); }
    $d = $GLOBALS['LAB']['dir'];
    if ( $d !== '' && ! getenv( 'VT_KEEP_LAB' ) && strpos( $d, 'npp-vt-lab-' ) !== false ) {
        exec( 'rm -rf ' . escapeshellarg( $d ) );
    }
}

/* ---- HTTP -------------------------------------------------------------------------------------------------- */

/**
 * One raw request to 127.0.0.1:$port with an exact request target (dot segments and percent hex are NOT touched).
 * @return array{code:int,body:string,headers:array<string,string>,err:string}
 */
function lab_http( string $method, int $port, string $target, array $headers = [], string $host = LAB_HOST, float $timeout = 5.0 ): array {
    $h = [ 'Host: ' . $host ];
    foreach ( $headers as $k => $v ) { $h[] = $k . ': ' . $v; }
    $resp = [];
    $ch = curl_init( "http://127.0.0.1:$port$target" );
    curl_setopt_array( $ch, [
        CURLOPT_CUSTOMREQUEST => $method, CURLOPT_HTTPHEADER => $h, CURLOPT_RETURNTRANSFER => true,
        CURLOPT_PATH_AS_IS => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT_MS => (int) ( $timeout * 1000 ),
        CURLOPT_CONNECTTIMEOUT_MS => 2000, CURLOPT_NOBODY => $method === 'HEAD',
        CURLOPT_HEADERFUNCTION => static function ( $c, $line ) use ( &$resp ) {
            if ( strpos( $line, ':' ) !== false ) { [ $k, $v ] = explode( ':', $line, 2 ); $resp[ strtolower( trim( $k ) ) ] = trim( $v ); }
            return strlen( $line );
        },
    ] );
    $body = curl_exec( $ch );
    $out  = [ 'code' => (int) curl_getinfo( $ch, CURLINFO_RESPONSE_CODE ), 'body' => (string) $body, 'headers' => $resp, 'err' => curl_error( $ch ) ];
    curl_close( $ch );
    return $out;
}

function lab_get( array $v, string $target, string $host = LAB_HOST ): array { return lab_http( 'GET', $v['port'], $target, [], $host ); }

/** Requests that reached the backend, as "host/target" => n (read from the backend port, never through Varnish). */
function lab_counts(): array {
    $r = lab_http( 'GET', $GLOBALS['LAB']['backend'], '/__counts' );
    $j = json_decode( $r['body'], true );
    return is_array( $j ) ? $j : [];
}
function lab_origin( string $target, string $host = LAB_HOST ): int { return lab_counts()[ $host . $target ] ?? 0; }
function lab_reset_backend(): void { lab_http( 'GET', $GLOBALS['LAB']['backend'], '/__reset' ); }

/** Empties one varnishd completely (a plain `ban obj.status >= 0` through the admin port, NOT through the plugin). */
function lab_clear( array $v ): void {
    shell_exec( 'varnishadm -n ' . escapeshellarg( $v['wd'] ) . ' ban "obj.status >= 0" 2>&1' );
    lab_reset_backend();
}

/**
 * Fetches $target through Varnish until the answer was produced by the backend (a MISS), then once more to prove it is
 * now cached. Returns true when the object is cached afterwards: origin count 1 after two GETs.
 */
function lab_prime( array $v, string $target, string $host = LAB_HOST ): bool {
    $before = lab_origin( $target, $host );
    lab_get( $v, $target, $host );
    lab_get( $v, $target, $host );
    return lab_origin( $target, $host ) === $before + 1;
}

/** Is the object served from Varnish right now? (does the GET stay away from the backend?) Re-primes the cache as a side effect. */
function lab_is_cached( array $v, string $target, string $host = LAB_HOST ): bool {
    $before = lab_origin( $target, $host );
    lab_get( $v, $target, $host );
    return lab_origin( $target, $host ) === $before;
}

/* ---- plugin side ------------------------------------------------------------------------------------------- */

/** Plugin configured for these Varnish instances (endpoints) with the documented control header. */
function lab_plugin_on( array $varnishes, string $header = 'X-Control-Key: ' . LAB_SECRET ): void {
    $eps = implode( "\n", array_map( fn( $v ) => 'http://127.0.0.1:' . $v['port'], $varnishes ) );
    vt_reset();
    vt_home( 'http://' . LAB_HOST );
    vt_set( [ 'nppp_varnish_sync_enabled' => 'yes', 'nppp_varnish_endpoints' => $eps, 'nppp_varnish_header' => $header ] );
}