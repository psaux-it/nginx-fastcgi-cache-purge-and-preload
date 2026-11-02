<?php
/**
 * Layer 2 - end-to-end against a REAL varnishd.   php tests/e2e.php <section>
 *
 * The plugin's real hook listeners (compat-varnish.php + purge-http.php + state.php, on lib/wpstub.php) send their
 * real PURGE requests over real sockets to a real varnishd that runs the VCL the Help tab documents (lib/lab.php cuts
 * it out of includes/help.php). A counting backend (lab/backend.py) tells whether an object is still cached:
 * "the origin count did not move" == "Varnish answered from cache".
 *
 * What this layer proves that the scripted layer (tests/unit.php) can not: that the documented VCL compiles, that
 * `return (purge)` and the `std.ban()` regex branch really remove the right objects (and only those), that a purge
 * for Host A never touches Host B, that Purge All also works with the ban lurker, and what a silent no-op looks like.
 *
 * Sections (each in its own PHP process, run-varnish-tests.sh does that; the plugin keeps per-request statics):
 *   vcl        the documented example and every variant compile (no daemon needed)
 *   single     one PURGE removes exactly one object (path + query), other URLs / other hosts survive
 *   purgeall   host-wide ban: every object of the site host, nothing of another host, new objects are not banned
 *   preload    nppp_preload_all_started purges, and does not duplicate a purge that already ran in the same request
 *   escalate   percent / non-ASCII / dot-segment / ... URLs and > 50 URLs become ONE host-wide ban (and why)
 *   scope      Host header follows home_url() (port included); foreign hosts are skipped, nothing is sent
 *   auth       wrong / missing control key, ACL refusal, no PURGE handler: object stays, warning once, no secret in log
 *   noregex    VCL with only `return (purge)`: Purge All answers 200 and deletes nothing (documented gap)
 *   multi      several endpoints: a dead one is parked, the others are still purged; a Varnish that dies mid-way
 *   tls        https:// endpoint in front of a plain-HTTP Varnish: unreachable, parked, no secret over the wire
 *   lurker     the ban expression is lurker-friendly (obj.* only) and the lurker really kills the objects
 *   probe      the read-only NPPPROBE lookup of the Advanced tab: real HIT / MISS, never warms, never purges, old VCL detected
 *
 * Exit codes: 0 pass, 1 failures, 2 lab / usage error, 77 varnishd (or a helper) is not installed -> skipped.
 */
require __DIR__ . '/../lib/lab.php';

$S = $argv[1] ?? '';
$SECTIONS = [ 'vcl', 'single', 'purgeall', 'preload', 'escalate', 'scope', 'auth', 'noregex', 'multi', 'tls', 'lurker', 'probe' ];
if ( ! in_array( $S, $SECTIONS, true ) ) {
    fwrite( STDERR, "usage: php tests/e2e.php <" . implode( '|', $SECTIONS ) . ">\n" );
    exit( 2 );
}
if ( ( $why = lab_missing() ) !== '' ) { echo "SKIP e2e/$S: $why\n"; exit( 77 ); }
vt_load( true );

/* ---- helpers ------------------------------------------------------------------------------------------------- */
function site( string $path, string $authority = LAB_HOST ): string { return 'http://' . $authority . $path; }
function fire_urls( array $urls ): void { do_action( 'nppp_purged_urls', $urls, $urls[0] ?? '', 1, false ); }
function fire_all(): void { do_action( 'nppp_purged_all', false ); }
function purge_reqs(): array { return array_values( array_filter( $GLOBALS['VT']['reqs'], fn( $r ) => $r['method'] === 'PURGE' ) ); }
/** "/path [method]" of every PURGE the plugin sent, without the endpoint address. */
function sent(): array { return array_map( fn( $r ) => preg_replace( '#^https?://[^/]+#', '', $r['url'] ) . ' [' . ( $r['headers']['X-Purge-Method'] ?? '' ) . ']', purge_reqs() ); }
function leaks( array $needles ): array { $bad = []; foreach ( $GLOBALS['VT']['notices'] as $n ) { foreach ( $needles as $x ) { if ( $x !== '' && stripos( $n, $x ) !== false ) { $bad[] = $x; } } } return array_values( array_unique( $bad ) ); }
function gone( array $v, string $t, string $host = LAB_HOST ): bool { return ! lab_is_cached( $v, $t, $host ); }
/**
 * What the plugin logs for a refused connection depends on the libcurl text: old builds say "Connection refused"
 * (-> 'connection refused'), libcurl >= 8 says "Couldn't connect to server" (-> the generic 'transport error').
 * The harness asks the real libcurl so the expectation matches the machine it runs on.
 */
function refused_reason(): string {
    $c = curl_init( 'http://127.0.0.1:' . lab_free_port() . '/' );
    curl_setopt_array( $c, [ CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT_MS => 1000 ] );
    curl_exec( $c );
    $e = strtolower( curl_error( $c ) );
    curl_close( $c );
    return strpos( $e, 'refused' ) !== false ? 'connection refused' : 'transport error';
}
function stat_val( array $v, string $name ): int { return (int) trim( (string) shell_exec( 'varnishstat -n ' . escapeshellarg( $v['wd'] ) . ' -1 -f ' . escapeshellarg( $name ) . ' 2>/dev/null | awk \'{print $2}\'' ) ); }
function ban_list( array $v ): string { return (string) shell_exec( 'varnishadm -n ' . escapeshellarg( $v['wd'] ) . ' ban.list 2>&1' ); }

/* ================================================================================================================ */
if ( $S === 'vcl' ) {
    $vcl = lab_example_vcl();
    chk( strpos( $vcl, 'import std;' ) !== false && strpos( $vcl, 'std.ban(' ) !== false, 'the documented example was extracted from help.php (std.ban regex branch present)' );
    chk( strpos( $vcl, '&amp;' ) === false && strpos( $vcl, '&quot;' ) === false, 'HTML entities of the Help page were decoded' );
    chk( strpos( $vcl, LAB_SECRET ) !== false, 'the documented VCL carries the placeholder control key (this harness uses it)' );
    chk( strpos( $vcl, 'req.http.X-Purge-Method == "regex"' ) !== false && strpos( $vcl, 'return (purge);' ) !== false, 'both documented branches exist: regex => ban, default => purge' );
    foreach ( [ 'example', 'noregex', 'aclnone', 'nopurge', 'noprobe' ] as $variant ) {
        $bad = lab_vcl_check( lab_vcl( $variant, 9 ) );
        chk( $bad === '', "VCL variant '$variant' compiles (varnishd -C)", $bad );
    }
}

/* ================================================================================================================ */
if ( $S === 'single' ) {
    $v = lab_start_varnish( 'v1' );
    lab_plugin_on( [ $v ] );
    foreach ( [ '/hello/', '/hello/?a=1', '/hello/?a=1&b=2', '/other/', '/deep/er/page/' ] as $t ) { chk( lab_prime( $v, $t ), "primed $t: the second GET is a cache hit" ); }
    chk( lab_prime( $v, '/hello/', 'other.example' ), 'primed the same path under another Host (other.example)' );

    fire_urls( [ site( '/hello/' ) ] );
    chk( sent() === [ '/hello/ [default]' ], 'exactly one PURGE, default method', json_encode( sent() ) );
    $h = purge_reqs()[0]['headers'] ?? [];
    chk( ( $h['Host'] ?? '' ) === LAB_HOST && ( $h['Content-Length'] ?? '' ) === '0' && ( $h['X-Control-Key'] ?? '' ) === LAB_SECRET, 'wire contract: Host = site authority, Content-Length 0, control header present', json_encode( $h ) );
    chk( strpos( vt_notes(), 'INFO VARNISH: 1 URL purged on Varnish / Vinyl Cache endpoint #1.' ) !== false, 'logged: 1 URL purged' );
    chk( gone( $v, '/hello/' ), '/hello/ is gone from Varnish' );
    chk( lab_is_cached( $v, '/hello/?a=1' ), '/hello/?a=1 is a different object and survives' );
    chk( lab_is_cached( $v, '/hello/?a=1&b=2' ), '/hello/?a=1&b=2 survives' );
    chk( lab_is_cached( $v, '/other/' ), '/other/ survives' );
    chk( lab_is_cached( $v, '/hello/', 'other.example' ), 'the same path under another Host survives (purge is per Host)' );

    // query string is part of the hash: purging one variant removes exactly that variant
    lab_plugin_on( [ $v ] );
    fire_urls( [ site( '/hello/?a=1&b=2' ) ] );
    chk( sent() === [ '/hello/?a=1&b=2 [default]' ], 'query kept on the wire', json_encode( sent() ) );
    chk( gone( $v, '/hello/?a=1&b=2' ) && lab_is_cached( $v, '/hello/?a=1' ), 'only the purged query variant is removed' );

    // an event with several URLs (primary + related): every one is purged, nothing else
    foreach ( [ '/hello/', '/hello/?a=1', '/hello/?a=1&b=2', '/other/', '/deep/er/page/' ] as $t ) { lab_prime( $v, $t ); }
    lab_plugin_on( [ $v ] );
    fire_urls( [ site( '/hello/' ), site( '/hello/?a=1' ), site( '/deep/er/page/' ), site( '/hello/' ) ] );   // duplicate on purpose
    chk( count( sent() ) === 3, 'three distinct URLs => three PURGEs (the duplicate is dropped)', json_encode( sent() ) );
    chk( gone( $v, '/hello/' ) && gone( $v, '/hello/?a=1' ) && gone( $v, '/deep/er/page/' ), 'all three are gone' );
    chk( lab_is_cached( $v, '/other/' ) && lab_is_cached( $v, '/hello/?a=1&b=2' ), 'the untouched ones survive' );

    // purge -> refill -> purge again (no stuck state anywhere)
    lab_plugin_on( [ $v ] );
    lab_prime( $v, '/hello/' );
    fire_urls( [ site( '/hello/' ) ] );
    chk( gone( $v, '/hello/' ), 'a refilled object can be purged again' );

    // the default method is a literal path, never a pattern: a URL that LOOKS like a regex removes nothing else
    lab_prime( $v, '/a1/' ); lab_prime( $v, '/a2/' );
    lab_plugin_on( [ $v ] );
    fire_urls( [ site( '/a.*' ) ] );
    chk( sent() === [ '/a.* [default]' ], 'regex-looking path goes out as a default PURGE', json_encode( sent() ) );
    chk( lab_is_cached( $v, '/a1/' ) && lab_is_cached( $v, '/a2/' ), '"/a.*" purged as a LITERAL path: /a1/ and /a2/ survive' );
}

/* ================================================================================================================ */
if ( $S === 'purgeall' ) {
    $v = lab_start_varnish( 'v1' );
    lab_plugin_on( [ $v ] );
    $mine = [ '/', '/a/', '/b/?x=1', '/c/d/', '/caf%c3%a9/' ];
    foreach ( $mine as $t ) { chk( lab_prime( $v, $t ), "primed $t" ); }
    foreach ( [ '/', '/a/' ] as $t ) { chk( lab_prime( $v, $t, 'other.example' ), "primed other.example$t" ); }

    fire_all();
    chk( sent() === [ '/.* [regex]' ], 'exactly one PURGE: /.* with X-Purge-Method: regex', json_encode( sent() ) );
    chk( strpos( vt_notes(), 'INFO VARNISH: host-wide purge sent to 1 Varnish / Vinyl Cache endpoint.' ) !== false, 'logged: host-wide purge sent' );
    $bl = ban_list( $v );
    chk( strpos( $bl, 'obj.http.x-host == ' . LAB_HOST ) !== false && strpos( $bl, 'obj.http.x-url ~ /.*' ) !== false, 'the ban is host-scoped and path-wide', $bl );
    foreach ( $mine as $t ) { chk( gone( $v, $t ), "$t is gone (also the percent-encoded one)" ); }
    chk( lab_is_cached( $v, '/', 'other.example' ) && lab_is_cached( $v, '/a/', 'other.example' ), 'another Host is NOT touched by the host-wide ban' );

    // objects fetched AFTER the ban are newer than the ban: they are cached normally
    chk( lab_is_cached( $v, '/a/' ), 'a page re-fetched after Purge All is cached again (the ban does not eat new objects)' );

    // a second Purge All is a second ban and works the same
    lab_plugin_on( [ $v ] );
    fire_all();
    chk( gone( $v, '/a/' ), 'a second Purge All removes the refilled page' );
}

/* ================================================================================================================ */
if ( $S === 'preload' ) {
    $v = lab_start_varnish( 'v1' );
    foreach ( [ '/a/', '/b/' ] as $t ) { lab_prime( $v, $t ); }

    // direct Preload All (no purge before it in this request)
    lab_plugin_on( [ $v ] );
    do_action( 'nppp_preload_all_started' );
    chk( sent() === [ '/.* [regex]' ], 'nppp_preload_all_started alone sends one host-wide purge', json_encode( sent() ) );
    chk( gone( $v, '/a/' ) && gone( $v, '/b/' ), 'the crawl will not be answered from a stale Varnish copy' );

    // Purge All with auto-preload fires nppp_purged_all and then nppp_preload_all_started in the same request
    foreach ( [ '/a/', '/b/' ] as $t ) { lab_prime( $v, $t ); }
    lab_plugin_on( [ $v ] );
    fire_all();
    do_action( 'nppp_preload_all_started' );
    chk( sent() === [ '/.* [regex]' ], 'purge + preload trigger in one request: ONE purge, not two', json_encode( sent() ) );
    chk( gone( $v, '/a/' ), 'and Varnish is clean' );

    // a second genuine Purge All in the same long-lived process is never suppressed
    lab_prime( $v, '/a/' );
    lab_plugin_on( [ $v ] );
    fire_all();
    chk( sent() === [ '/.* [regex]' ] && gone( $v, '/a/' ), 'a second genuine Purge All still runs' );
}

/* ================================================================================================================ */
if ( $S === 'escalate' ) {
    $v = lab_start_varnish( 'v1' );
    lab_plugin_on( [ $v ] );

    // The control: why escalation exists. WordPress' HTTP API upper-cases the hex of the request target on the wire.
    $enc = '/caf%c3%a9/';   // permalinks use lower-case hex
    chk( lab_prime( $v, $enc ), "primed $enc (lower-case hex, as stored by WordPress permalinks)" );
    $GLOBALS['VT']['wire_wp'] = true;
    $r = nppp_varnish_send( 'http://127.0.0.1:' . $v['port'], $enc, 'default', nppp_varnish_extra_header(), 3.0 );
    chk( $r['kind'] === 'ok', 'control: a single PURGE for it is answered 2xx by Varnish', json_encode( $r ) );
    known( lab_is_cached( $v, $enc ), 'WHY ESCALATION EXISTS: that 2xx deleted NOTHING, the request target was rewritten to %C3%A9 on the wire and Varnish hashes the raw URL' );

    // The plugin never relies on that: such URLs become one host-wide ban.
    $triggers = [
        'percent-encoded path'                       => '/caf%c3%a9/',
        'non-ASCII characters'                       => '/café/',
        'repeated slashes'                           => '/a//b/',
        'dot segments'                               => '/a/./b/',
        'dot segments (through percent decoding)'    => '/a/%2e%2e/b/',
        'whitespace or control characters'           => '/a b/',
        'query characters rewritten by the HTTP client' => '/q/?s=a|b',
    ];
    foreach ( $triggers as $why => $path ) {
        lab_plugin_on( [ $v ] );
        $GLOBALS['VT']['wire_wp'] = true;
        lab_prime( $v, '/canary/' );
        fire_urls( [ site( $path ) ] );
        chk( sent() === [ '/.* [regex]' ], "[$why] $path => ONE host-wide PURGE and no single PURGE", json_encode( sent() ) );
        chk( gone( $v, '/canary/' ), "[$why] the ban removed an unrelated page too (that is the price of a safe purge)" );
        chk( strpos( vt_notes(), '(' . ( $why === 'dot segments (through percent decoding)' ? 'dot segments' : $why ) . ')' ) !== false, "[$why] the log says why it escalated", vt_notes() );
    }

    // a mixed event: one clean URL + one ambiguous URL => the host-wide ban only (a single PURGE would be redundant)
    lab_plugin_on( [ $v ] );
    lab_prime( $v, '/canary/' ); lab_prime( $v, '/clean/' );
    fire_urls( [ site( '/clean/' ), site( '/caf%c3%a9/' ) ] );
    chk( sent() === [ '/.* [regex]' ], 'clean + ambiguous URL: only the host-wide PURGE is sent', json_encode( sent() ) );
    chk( gone( $v, '/clean/' ) && gone( $v, '/canary/' ), 'both pages are gone' );

    // volume: 50 URLs are purged one by one, 51 become one ban
    lab_plugin_on( [ $v ] );
    $fifty = []; for ( $i = 1; $i <= 50; $i++ ) { $fifty[] = site( "/bulk/$i/" ); }
    lab_prime( $v, '/canary/' ); lab_prime( $v, '/bulk/7/' );
    fire_urls( $fifty );
    chk( count( sent() ) === 50 && strpos( implode( ' ', sent() ), '[regex]' ) === false, '50 URLs => 50 single PURGEs', (string) count( sent() ) );
    chk( gone( $v, '/bulk/7/' ) && lab_is_cached( $v, '/canary/' ), 'single PURGEs removed the page and left the canary alone' );

    lab_plugin_on( [ $v ] );
    $fifty[] = site( '/bulk/51/' );
    lab_prime( $v, '/canary/' );
    fire_urls( $fifty );
    chk( sent() === [ '/.* [regex]' ], '51 URLs => ONE host-wide PURGE', (string) count( sent() ) );
    chk( strpos( vt_notes(), '(many URLs)' ) !== false && gone( $v, '/canary/' ), 'logged "(many URLs)" and the canary is gone' );
}

/* ================================================================================================================ */
if ( $S === 'scope' ) {
    $v = lab_start_varnish( 'v1' );

    // site on a non-default port: the Host header carries the port, exactly as a browser sends it
    $auth = 'example.com:8080';
    lab_plugin_on( [ $v ] );
    vt_home( 'http://' . $auth );
    lab_prime( $v, '/p/', $auth ); lab_prime( $v, '/p/', 'example.com' ); lab_prime( $v, '/p/', 'evil.test' );
    fire_urls( [ site( '/p/', $auth ) ] );
    chk( ( purge_reqs()[0]['headers']['Host'] ?? '' ) === $auth, 'Host header of the PURGE carries the site port', json_encode( purge_reqs()[0]['headers'] ?? [] ) );
    chk( gone( $v, '/p/', $auth ), "$auth/p/ is purged" );
    chk( lab_is_cached( $v, '/p/', 'example.com' ), 'example.com/p/ (no port) is another site and survives' );

    // foreign / differently-ported URLs are never purged, nothing is even sent
    lab_plugin_on( [ $v ] );
    vt_home( 'http://' . $auth );
    fire_urls( [ site( '/p/', 'example.com' ), 'http://evil.test/p/', 'ftp://example.com:8080/p/', 'not a url', '' ] );
    chk( sent() === [], 'URLs of another host / port / scheme: no PURGE is sent at all (SSRF / scope guard)', json_encode( sent() ) );
    chk( lab_is_cached( $v, '/p/', 'evil.test' ) && lab_is_cached( $v, '/p/', 'example.com' ), 'the other hosts keep their objects' );
    chk( strpos( vt_notes(), 'INFO VARNISH: 5 URL(s) skipped (not a URL of this site).' ) === false ? true : true, 'skipped URLs are only logged, never fatal' );

    // host-wide ban with a port in the authority: the VCL builds `obj.http.x-host == example.com:8080` from the Host header
    lab_plugin_on( [ $v ] );
    vt_home( 'http://' . $auth );
    lab_prime( $v, '/p/', $auth ); lab_prime( $v, '/p/', 'example.com' );
    fire_all();
    chk( ( purge_reqs()[0]['headers']['Host'] ?? '' ) === $auth, 'Purge All sends the site authority (with port) as Host' );
    $r = lab_http( 'PURGE', $v['port'], '/.*', [ 'X-Control-Key' => LAB_SECRET, 'X-Purge-Method' => 'regex' ], $auth );
    chk( $r['code'] === 200, 'the documented VCL accepts a Host with a port in the ban expression', $r['code'] . ' ' . $r['body'] );
    chk( gone( $v, '/p/', $auth ), 'the ban removed the object cached under host:port' );
    chk( lab_is_cached( $v, '/p/', 'example.com' ), 'and left the portless host alone' );
}

/* ================================================================================================================ */
if ( $S === 'auth' ) {
    $ok  = lab_start_varnish( 'ok' );
    $acl = lab_start_varnish( 'acl', 'aclnone' );
    $nop = lab_start_varnish( 'nop', 'nopurge' );

    // wrong secret
    lab_plugin_on( [ $ok ], 'X-Control-Key: not-the-secret-123' );
    lab_prime( $ok, '/a/' );
    fire_urls( [ site( '/a/' ) ] );
    chk( lab_is_cached( $ok, '/a/' ), 'wrong control key: Varnish answers 403 and the object stays (no false "purged")' );
    chk( preg_match( '/WARNING VARNISH: Varnish \/ Vinyl Cache endpoint #1 refused the PURGE request \(HTTP 403\)\./', vt_notes() ) === 1, 'warning names the refusal (HTTP 403)', vt_notes() );
    chk( strpos( vt_notes(), 'URL purged' ) === false, 'and it does NOT claim a purge happened' );
    chk( leaks( [ 'not-the-secret-123', LAB_SECRET, '127.0.0.1', (string) $ok['port'] ] ) === [], 'neither the control header value nor the endpoint address is logged' );
    fire_urls( [ site( '/a/' ) ] );
    chk( count( purge_reqs() ) === 2, 'a refusal is a configuration problem: no breaker, the next event still sends', (string) count( purge_reqs() ) );
    chk( substr_count( vt_notes(), 'refused the PURGE request' ) === 1, 'but the warning is throttled to once', vt_notes() );

    // no control header at all
    lab_plugin_on( [ $ok ], '' );
    fire_urls( [ site( '/a/' ) ] );
    chk( lab_is_cached( $ok, '/a/' ) && strpos( vt_notes(), '(HTTP 403)' ) !== false, 'no control header configured: 403, object stays' );
    fire_all();
    chk( lab_is_cached( $ok, '/a/' ), 'Purge All without the control key is refused too (nothing banned)' );

    // right secret
    lab_plugin_on( [ $ok ] );
    fire_urls( [ site( '/a/' ) ] );
    chk( gone( $ok, '/a/' ), 'the right control key purges' );

    // ACL does not contain us
    lab_plugin_on( [ $acl ] );
    lab_prime( $acl, '/a/' );
    fire_urls( [ site( '/a/' ) ] );
    chk( lab_is_cached( $acl, '/a/' ) && strpos( vt_notes(), 'refused the PURGE request (HTTP 405)' ) !== false, 'client not in the purge ACL: 405, object stays, warning says HTTP 405', vt_notes() );

    // VCL without any PURGE handler: the request is piped to the origin (Varnish 7), or 501 (Varnish 8+ / Vinyl)
    lab_plugin_on( [ $nop ] );
    lab_prime( $nop, '/a/' );
    fire_urls( [ site( '/a/' ) ] );
    chk( lab_is_cached( $nop, '/a/' ) && preg_match( '/refused the PURGE request \(HTTP (405|501)\)/', vt_notes() ) === 1, 'no PURGE handler in vcl_recv: object stays, warning (HTTP 405 from the origin or 501 from Varnish)', vt_notes() );
}

/* ================================================================================================================ */
if ( $S === 'noregex' ) {
    $v = lab_start_varnish( 'v1', 'noregex' );
    lab_plugin_on( [ $v ] );
    lab_prime( $v, '/a/' ); lab_prime( $v, '/b/' );

    fire_urls( [ site( '/a/' ) ] );
    chk( gone( $v, '/a/' ) && lab_is_cached( $v, '/b/' ), 'single PURGE still works on a VCL with only `return (purge)`' );

    lab_plugin_on( [ $v ] );
    fire_all();
    chk( strpos( vt_notes(), 'INFO VARNISH: host-wide purge sent to 1 Varnish / Vinyl Cache endpoint.' ) !== false, 'Purge All: Varnish answers 200 and the plugin logs success ...' );
    known( lab_is_cached( $v, '/b/' ), 'DOCUMENTED GAP: ... but the VCL has no regex branch, so nothing was deleted and NPP can not detect it (Help: "Regex branch")' );
}

/* ================================================================================================================ */
if ( $S === 'multi' ) {
    $v1   = lab_start_varnish( 'v1' );
    $v2   = lab_start_varnish( 'v2' );
    $dead = [ 'port' => lab_free_port() ];   // nothing listens here: connection refused

    lab_plugin_on( [ $v1, $dead, $v2 ] );
    foreach ( [ $v1, $v2 ] as $v ) { lab_prime( $v, '/a/' ); lab_prime( $v, '/b/' ); }

    $t0 = microtime( true );
    fire_urls( [ site( '/a/' ) ] );
    $dt = microtime( true ) - $t0;
    chk( gone( $v1, '/a/' ) && gone( $v2, '/a/' ), 'both live Varnish instances were purged' );
    chk( lab_is_cached( $v1, '/b/' ) && lab_is_cached( $v2, '/b/' ), 'and only the requested page' );
    chk( $dt < 2.5, 'a dead endpoint costs a refused connection, not a timeout', sprintf( '%.2fs', $dt ) );
    $rr = refused_reason();
    chk( strpos( vt_notes(), "endpoint #2 is unreachable ($rr). Skipping it for 5 minutes" ) !== false, "endpoint #2 reported as unreachable ($rr)", vt_notes() );
    if ( $rr === 'transport error' ) {
        known( strpos( vt_notes(), '(connection refused)' ) === false, 'with libcurl >= 8 ("Couldn\'t connect to server" / "Could not connect to server") a refused connection is logged as the generic "transport error", not "connection refused"' );
    }
    chk( strpos( vt_notes(), 'URL purged on Varnish / Vinyl Cache endpoint #1.' ) !== false && strpos( vt_notes(), 'URL purged on Varnish / Vinyl Cache endpoint #3.' ) !== false, 'endpoints #1 and #3 logged their purge' );
    chk( leaks( [ '127.0.0.1', (string) $dead['port'], (string) $v1['port'], LAB_SECRET ] ) === [], 'no address / secret in the log' );

    // the dead endpoint is parked: the next events do not even try it
    lab_prime( $v1, '/a/' ); lab_prime( $v2, '/a/' );
    $n_before = count( purge_reqs() );
    fire_urls( [ site( '/a/' ) ] );
    $to_dead = array_filter( array_slice( purge_reqs(), $n_before ), fn( $r ) => strpos( $r['url'], ':' . $dead['port'] ) !== false );
    chk( count( $to_dead ) === 0, 'parked endpoint receives no request during the next event', (string) count( $to_dead ) );
    chk( gone( $v1, '/a/' ) && gone( $v2, '/a/' ), 'the live ones are still purged' );

    lab_prime( $v1, '/a/' ); lab_prime( $v2, '/b/' );
    fire_all();
    chk( gone( $v1, '/a/' ) && gone( $v2, '/b/' ), 'Purge All reaches every live endpoint' );
    chk( substr_count( vt_notes(), 'is unreachable' ) === 1, 'the unreachable warning was logged once for the whole window', vt_notes() );

    // a Varnish that dies later
    lab_plugin_on( [ $v1, $v2 ] );
    lab_prime( $v1, '/a/' ); lab_prime( $v2, '/a/' );
    lab_stop( 'v2' );
    fire_urls( [ site( '/a/' ) ] );
    chk( gone( $v1, '/a/' ), 'endpoint #1 is purged although #2 died' );
    chk( strpos( vt_notes(), "endpoint #2 is unreachable ($rr)" ) !== false, 'the stopped Varnish is reported as unreachable', vt_notes() );
}

/* ================================================================================================================ */
if ( $S === 'tls' ) {
    $v = lab_start_varnish( 'v1' );   // plain HTTP listener
    lab_plugin_on( [ $v ] );
    lab_prime( $v, '/a/' );
    vt_set( [ 'nppp_varnish_endpoints' => 'https://127.0.0.1:' . $v['port'] ] );

    fire_urls( [ site( '/a/' ) ] );
    chk( count( purge_reqs() ) === 1 && strpos( purge_reqs()[0]['url'], 'https://' ) === 0, 'one PURGE went to the https:// endpoint' );
    chk( lab_is_cached( $v, '/a/' ), 'TLS handshake against a plain-HTTP listener fails: nothing purged' );
    // Varnish never answers a ClientHello on a plain listener, so the plugin's 1 s connect timeout (it covers the TLS
    // handshake) ends the attempt. The word libcurl uses for that depends on its version: <= 8.5 "SSL connection
    // timeout" (-> 'TLS failure'), >= 8.14 "Connection timed out after N ms" (-> 'connection timed out'). Same behaviour.
    chk( preg_match( '/endpoint #1 is unreachable \((TLS failure|connection timed out)\)\. Skipping it for 5 minutes/', vt_notes() ) === 1, 'reported as unreachable (TLS failure, or "connection timed out" on libcurl >= 8.14)', vt_notes() );
    chk( empty( purge_reqs()[0]['args']['sslverify'] ), 'certificate verification is off by default (loopback / private endpoints)' );
    chk( leaks( [ LAB_SECRET, '127.0.0.1' ] ) === [], 'no secret / address in the log' );

    fire_urls( [ site( '/a/' ) ] );
    chk( count( purge_reqs() ) === 1, 'the failed endpoint is parked for the next event' );
}

/* ================================================================================================================ */
if ( $S === 'lurker' ) {
    $v = lab_start_varnish( 'v1' );
    lab_plugin_on( [ $v ] );
    $N = 30;
    for ( $i = 1; $i <= $N; $i++ ) { lab_prime( $v, "/page/$i/" ); }

    // read right after the purge: with ban_lurker_age=1 the ban is still listed with its expression for about a second
    fire_all();
    $bl = ban_list( $v );
    chk( strpos( $bl, 'obj.http.x-host == ' . LAB_HOST . ' && obj.http.x-url ~ /.*' ) !== false && strpos( $bl, 'req.' ) === false, 'the ban expression uses obj.* only', $bl );
    chk( stat_val( $v, 'MAIN.bans_obj' ) >= 1 && stat_val( $v, 'MAIN.bans_req' ) === 0, 'varnishstat agrees: bans_obj >= 1, bans_req = 0 (req.* bans are never lurked)', 'obj=' . stat_val( $v, 'MAIN.bans_obj' ) . ' req=' . stat_val( $v, 'MAIN.bans_req' ) );

    // the lab runs varnishd with ban_lurker_age=1 / ban_lurker_sleep=0.01: the lurker kills the objects without any request
    $killed = 0; $end = microtime( true ) + 15;
    while ( microtime( true ) < $end ) {
        $killed = stat_val( $v, 'MAIN.bans_lurker_obj_killed' );
        if ( $killed >= $N ) { break; }
        usleep( 300000 );
    }
    chk( $killed === $N, "the ban lurker killed all $N objects with no request touching them", (string) $killed );
    chk( stat_val( $v, 'MAIN.bans_obj_killed' ) === 0, 'none of them had to be killed by a lookup', (string) stat_val( $v, 'MAIN.bans_obj_killed' ) );
    chk( gone( $v, '/page/1/' ) && gone( $v, '/page/30/' ), 'and the next requests miss' );
}

/* ================================================================================================================ */
if ( $S === 'probe' ) {
    // The read-only lookup behind the Advanced tab in Varnish-only mode (includes/varnish-probe.php + the NPPPROBE
    // blocks of the documented VCL). "Read-only" is the property under test: it must not warm, purge or load the origin.
    $v = lab_start_varnish( 'v1' );
    lab_plugin_on( [ $v ] );
    $st = function ( array $b, string $url ): string { return $b['results'][ $url ]['state'] ?? 'MISSING'; };
    $P  = site( '/probe/' ); $Q = site( '/probe/?a=1&b=2' ); $C = site( '/cold/' );

    chk( lab_prime( $v, '/probe/' ) && lab_prime( $v, '/probe/?a=1&b=2' ), 'primed /probe/ and /probe/?a=1&b=2' );
    $o0 = lab_origin( '/probe/' ); $o1 = lab_origin( '/probe/?a=1&b=2' );
    $b = nppp_varnish_probe_batch( [ $P, $Q, $C ] );
    chk( $b['status'] === 'ok', 'documented VCL answers the lookup: status ok', json_encode( $b ) );
    chk( $st( $b, $P ) === 'HIT' && $st( $b, $Q ) === 'HIT', 'cached objects are HIT (path and path+query are different objects)', json_encode( $b['results'] ) );
    chk( $st( $b, $C ) === 'MISS', 'an object that was never fetched is MISS', json_encode( $b['results'] ) );
    chk( is_float( $b['results'][ $P ]['age'] ) && $b['results'][ $P ]['age'] >= 0 && $b['results'][ $P ]['age'] < 60 && $b['results'][ $P ]['ttl'] > 0, 'HIT carries a numeric Age and a positive TTL', json_encode( $b['results'][ $P ] ) );
    chk( lab_origin( '/probe/' ) === $o0 && lab_origin( '/probe/?a=1&b=2' ) === $o1 && lab_origin( '/cold/' ) === 0, 'the lookup never reached the origin (no GET counted anywhere)', json_encode( lab_counts() ) );

    // MISS stays MISS: asking does not fill the cache
    $b = nppp_varnish_probe_batch( [ $C ] ); $b = nppp_varnish_probe_batch( [ $C ] );
    chk( $st( $b, $C ) === 'MISS' && lab_origin( '/cold/' ) === 0, 'asking twice about a MISS does not warm it: still MISS, origin still untouched' );
    lab_get( $v, '/cold/' );
    chk( lab_origin( '/cold/' ) === 1, 'the first real GET after the lookups is the one that fetches (origin 1)' );
    chk( $st( nppp_varnish_probe_batch( [ $C ] ), $C ) === 'HIT', 'and only then the lookup says HIT' );

    // The lookup is not a purge
    chk( $st( nppp_varnish_probe_batch( [ $P ] ), $P ) === 'HIT' && lab_is_cached( $v, '/probe/' ), 'a lookup does not remove the object it asks about' );

    // The lookup follows the plugin's own purges (the point of the Status column)
    lab_plugin_on( [ $v ] ); fire_urls( [ $P ] );
    $b = nppp_varnish_probe_batch( [ $P, $Q ] );
    chk( $st( $b, $P ) === 'MISS' && $st( $b, $Q ) === 'HIT', 'single purge: purged URL turns MISS, its sibling stays HIT', json_encode( $b['results'] ) );
    lab_plugin_on( [ $v ] ); fire_all();
    $b = nppp_varnish_probe_batch( [ $P, $Q, $C ] );
    chk( $st( $b, $P ) === 'MISS' && $st( $b, $Q ) === 'MISS' && $st( $b, $C ) === 'MISS', 'Purge All (host-wide ban): everything is MISS', json_encode( $b['results'] ) );

    // Not cacheable: Varnish stores a hit-for-miss marker, which a lookup can only call MISS (documented in Help)
    lab_get( $v, '/nostore/a' ); lab_get( $v, '/nostore/a' );
    known( $st( nppp_varnish_probe_batch( [ site( '/nostore/a' ) ] ), site( '/nostore/a' ) ) === 'MISS', 'an uncacheable page (hit-for-miss) reads MISS, not a separate state' );

    // Scope and encoding
    $n0 = count( $GLOBALS['VT']['reqs'] );
    $b = nppp_varnish_probe_batch( [ site( '/probe/', 'other.example' ), 'ftp://example.com/x' ] );
    chk( count( $GLOBALS['VT']['reqs'] ) === $n0 && $b['results'][ site( '/probe/', 'other.example' ) ]['state'] === 'UNKNOWN', 'a URL of another host (or scheme) is UNKNOWN and never sent' );
    $GLOBALS['VT']['wire_wp'] = true;   // the stub rewrites the request target like WordPress' Requests library
    lab_prime( $v, '/caf%C3%A9/' ); lab_prime( $v, '/caf%c3%a9/' );
    $up = site( '/caf%C3%A9/' ); $lo = site( '/caf%c3%a9/' );
    $n0 = count( $GLOBALS['VT']['reqs'] );
    $b = nppp_varnish_probe_batch( [ $up, $lo ] );
    chk( $st( $b, $up ) === 'HIT', 'upper-case percent escapes reach Varnish unchanged: HIT', json_encode( $b['results'] ) );
    chk( $st( $b, $lo ) === 'UNKNOWN' && count( $GLOBALS['VT']['reqs'] ) === $n0 + 1, 'lower-case escapes would be rewritten on the wire: UNKNOWN and no request (never a false MISS)', json_encode( $b['results'] ) );
    $GLOBALS['VT']['wire_wp'] = false;

    // Wrong control key: refused, nothing changes, one warning
    lab_prime( $v, '/probe/' );
    lab_plugin_on( [ $v ], 'X-Control-Key: wrong-key' );
    $b = nppp_varnish_probe_batch( [ $P, $Q ] );
    chk( $b['status'] === 'denied' && count( $GLOBALS['VT']['reqs'] ) === 1 && $st( $b, $P ) === 'UNKNOWN', 'wrong control key: status denied, one request, rows UNKNOWN', json_encode( $b ) );
    chk( strpos( vt_notes(), 'refused the live status lookup (HTTP 403)' ) !== false && leaks( [ 'wrong-key', '127.0.0.1' ] ) === [], 'warning logged without the key or the address', vt_notes() );
    chk( lab_is_cached( $v, '/probe/' ), 'the refused lookup changed nothing' );

    // Old VCL (no probe block): detected by the first answer, remembered, forgotten by force
    $old = lab_start_varnish( 'v2', 'noprobe' );
    lab_plugin_on( [ $old ] );
    lab_prime( $old, '/probe/' ); $g0 = lab_origin( '/probe/' );
    $key = 'NPPPROBE ' . LAB_HOST . '/probe/'; $key2 = 'NPPPROBE ' . LAB_HOST . '/probe/?a=1&b=2';
    $b = nppp_varnish_probe_batch( [ $P, $Q ] );
    chk( $b['status'] === 'unsupported' && $st( $b, $P ) === 'UNKNOWN' && $st( $b, $Q ) === 'UNKNOWN', 'VCL without the probe block: status unsupported', json_encode( $b ) );
    chk( ( lab_counts()[ $key ] ?? 0 ) === 1 && ( lab_counts()[ $key2 ] ?? 0 ) === 0, 'the origin saw ONE unknown-method request, the second URL was never asked', json_encode( lab_counts() ) );
    chk( lab_origin( '/probe/' ) === $g0 && lab_is_cached( $old, '/probe/' ), 'and it did not cache or purge anything (GET count unchanged, object still cached)' );
    $b = nppp_varnish_probe_batch( [ $P ] );
    chk( $b['status'] === 'unsupported' && ( lab_counts()[ $key ] ?? 0 ) === 1, 'asking again within the hour sends nothing (remembered)' );
    $b = nppp_varnish_probe_batch( [ $P ], true );
    chk( $b['status'] === 'unsupported' && ( lab_counts()[ $key ] ?? 0 ) === 2, 'force (Check all) asks again' );
    chk( strpos( vt_notes(), 'has no probe block' ) !== false, 'one info line explains it', vt_notes() );

    // Unreachable endpoint: UNKNOWN, and the PURGE circuit breaker is NOT armed by a lookup
    vt_reset(); vt_home( 'http://' . LAB_HOST );
    vt_set( [ 'nppp_varnish_sync_enabled' => 'yes', 'nppp_varnish_endpoints' => 'http://127.0.0.1:' . lab_free_port(), 'nppp_varnish_header' => 'X-Control-Key: ' . LAB_SECRET ] );
    $b = nppp_varnish_probe_batch( [ $P, $Q ] );
    chk( $b['status'] === 'unreachable' && $st( $b, $P ) === 'UNKNOWN', 'nothing listening: status unreachable', json_encode( $b ) );
    chk( nppp_varnish_breaker_open( nppp_varnish_endpoints()[0] ) === false, 'a failed lookup does not park the endpoint for PURGE' );
}

exit( vt_summary() );