<?php
/**
 * Layer 5 - the real container topology.   php tests/topology.php <section>
 *
 *   visitor -> nginx:443 (edge) -> varnish:80 (official image) -> nginx:8080 (fastcgi_cache) -> wordpress-fpm
 *
 * The plugin is the REAL one, driven with `wp npp ...` inside wordpress-fpm; both caches are the REAL ones. The earlier
 * layers prove the plugin code (scripted transport, then a throw-away varnishd). This layer proves the WIRING that only
 * exists in a container stack: Docker DNS names, the ACL seen from the PHP container, the Host header that the edge hands
 * to Varnish, the purge listener that is not reachable by visitors, the cache key that nginx writes when Varnish talks to it
 * over plain http. See lib/topo.php for the environment and for how the two layers are observed.
 *
 * Run it through ./actionstart-varnish.sh --topology [happy] [faults] [preload]  (stack repo); run-topology-tests.sh is
 * the entry point inside wordpress-fpm.
 *
 * Phase happy
 *   wiring      settings read back, Varnish in the path, origin answers, the cache key nginx wrote has the "http" scheme
 *   single      Purge (single URL): the page is a MISS in nginx AND in Varnish, another page stays cached in both
 *   purgeall    Purge All: every page is a MISS in both layers, the refilled objects are cached again (ban hits only old ones)
 *   outsider    a visitor can not purge: PURGE through the edge is refused, /npp_purge_* is 404 on edge and origin, cache intact
 *
 * Phase faults (what the plugin does when the Varnish side is wrong; every setting it changes is restored on exit)
 *   vclclass    which VCL is active (found by behaviour) and what each variant means for a purge; must match TOPO_VCL_VARIANT
 *   foreignhost PURGE / ban for another Host never removes an object of this site
 *   badkey      wrong or missing control key: Varnish answers 403, ONE warning, nginx is purged, Varnish keeps the stale page
 *   dns         endpoint name that does not resolve: one warning, nginx purge unaffected, breaker, a dead endpoint beside a live one
 *   refused     endpoint that refuses the connection (closed port on the Varnish container)
 *   blackhole   endpoint that swallows packets: the purge returns inside the time budget
 *
 * Phase preload (opt-in, slow: needs wget and the mitmproxy container)
 *   preload     after Purge All a full preload refills nginx AND Varnish through the edge
 *
 * Not covered here (they need the Docker socket or more containers): stopping / starting the varnish container, a hostname
 * in the ACL whose IP changes on recreate, two Varnish containers, Hitch, cache_purge_background_queue.
 *
 * Exit codes: 0 pass, 1 failures, 2 lab / usage error, 77 skipped.
 */
require __DIR__ . '/../lib/topo.php';

$S = $argv[1] ?? '';
$SECTIONS = [ 'wiring', 'single', 'purgeall', 'outsider', 'vclclass', 'foreignhost', 'badkey', 'dns', 'refused', 'blackhole', 'preload' ];
if ( ! in_array( $S, $SECTIONS, true ) ) {
    fwrite( STDERR, 'usage: php tests/topology.php <' . implode( '|', $SECTIONS ) . ">\n" );
    exit( 2 );
}
topo_boot( $S );

/** Shorthand: both layers of one page, as one string for failure details. */
function layers( string $path ): string { return 'nginx=' . topo_origin_state( $path ) . ' varnish_ids=' . topo_edge_ids( $path ); }

/** Need at least $n cached pages, else the section is skipped (exit 77) with the reason. */
function need_pages( int $n, string $S ): array {
    $p = topo_pages();
    if ( count( $p ) < $n ) {
        echo "SKIP topology/$S: needs $n page(s) that both layers cache, found " . count( $p ) . ( $p ? ' (' . implode( ', ', $p ) . ')' : '' ) . "\n";
        echo "      (a page is usable when the 2nd visitor GET is a Varnish HIT; check the response headers of / with: curl -skI https://nginx/ -H 'Host: " . $GLOBALS['TOPO']['host'] . "')\n";
        exit( 77 );
    }
    return $p;
}

/** The documented VCL must be active for this section; a deliberately broken variant is covered by 'vclclass'. */
function need_example_vcl( string $S ): void {
    if ( topo_variant() !== 'example' ) {
        echo "SKIP topology/$S: needs the documented example VCL, the active one is '" . topo_variant() . "'\n";
        exit( 77 );
    }
}

/** One of the listed VCL variants must be active; the other (deliberately broken) variants are covered by 'vclclass'. */
function need_vcl( string $S, array $ok, string $why ): void {
    $v = topo_variant();
    if ( ! in_array( $v, $ok, true ) ) {
        echo "SKIP topology/$S: needs a VCL that $why, the active one is '$v' (a broken variant is covered by 'vclclass')\n";
        exit( 77 );
    }
}

/* ============================================================================================================
 * happy
 * ========================================================================================================= */

if ( $S === 'wiring' ) {
    chk( topo_opt( 'nppp_varnish_sync_enabled' ) === 'yes', 'NPP: Varnish sync is ON', 'run ./actionstart-varnish.sh --topology (it configures NPP)' );
    chk( stripos( topo_opt( 'nppp_varnish_endpoints' ), 'varnish' ) !== false, 'NPP: the endpoint points at the varnish container', 'endpoints=' . topo_opt( 'nppp_varnish_endpoints' ) );
    chk( topo_opt( 'nppp_http_purge_custom_url' ) === $TOPO['purge'], 'NPP: Purge Custom Base URL is the purge listener', 'got ' . topo_opt( 'nppp_http_purge_custom_url' ) . ', expected ' . $TOPO['purge'] );

    $e = topo_edge( '/' );
    chk( $e['code'] === 200, 'edge: GET / answers 200', 'code=' . $e['code'] . ' ' . $e['err'] );
    chk( ( $e['headers']['x-varnish'] ?? '' ) !== '', 'edge: the response passed through Varnish (X-Varnish present)', json_encode( $e['headers'] ) );
    $o = topo_http( 'GET', $TOPO['origin'] . '/', topo_hdrs() );
    chk( $o['code'] === 200 && isset( $o['headers']['npp-fastcgi-cache'] ), 'origin :8080: answers 200 with NPP-FastCGI-Cache', 'code=' . $o['code'] . ' ' . $o['err'] );

    $pages = topo_pages();
    chk( count( $pages ) >= 1, 'at least one page is cached by both layers (2nd visitor GET is a Varnish HIT)', 'tried / and the permalinks of the published posts and pages' );
    if ( $pages ) {
        chk( topo_origin_state( $pages[0] ) === 'HIT', 'nginx layer: ' . $pages[0] . ' is a HIT after warming', fn() => layers( $pages[0] ) );

        // The key nginx wrote: Varnish talks to the origin over plain http, so the scheme part is "http" (default.varnish.conf).
        $keys = topo_cache_keys();
        if ( $keys === null || ! $keys ) {
            skip( 'cache key on disk (' . $TOPO['cache_dir'] . ' not readable or empty in this container)' );
        } else {
            $want = 'KEY: httpGET' . $TOPO['host'] . $pages[0];
            chk( in_array( $want, $keys, true ), 'cache key on disk is "' . substr( $want, 5 ) . '" (origin sees http, key shape NPP parses)', 'keys seen: ' . implode( ' | ', array_slice( $keys, 0, 6 ) ) );
        }
    }
}

if ( $S === 'single' ) {
    need_vcl( $S, [ 'example', 'noregex' ], 'accepts an exact PURGE (aclnone / nopurge delete nothing by design)' );
    $pages = need_pages( 1, $S );
    $a = $pages[0]; $b = $pages[1] ?? null;
    chk( topo_warm( $a ), "warm $a in both layers" );
    if ( $b !== null ) { chk( topo_warm( $b ), "warm $b in both layers" ); }

    topo_log_clear();
    $r = topo_purge_single( $a );
    chk( $r['rc'] === 0 && $r['token'] !== 'error', "wp npp purge --page-url=$a succeeds", 'rc=' . $r['rc'] . ' token=' . $r['token'] . "\n" . $r['out'] );

    // Order matters: the origin is looked at first (a MISS there fills nginx), then the edge.
    chk( topo_origin_state( $a ) === 'MISS', "nginx layer: $a was purged (MISS at the origin)", 'HTTP Purge to ' . $TOPO['purge'] . ' did not remove it' );
    $ids = topo_edge_ids( $a );
    chk( $ids === 1, "Varnish layer: $a was purged (visitor GET is a MISS, X-Varnish ids=1)", "ids=$ids (2 = Varnish still serves the stale page)" );

    if ( $b !== null ) {
        chk( topo_origin_state( $b ) === 'HIT', "nginx layer: $b was NOT purged", fn() => layers( $b ) );
        chk( topo_edge_ids( $b ) === 2, "Varnish layer: $b was NOT purged", fn() => layers( $b ) );
    } else {
        skip( 'selectivity (only one cacheable page found)' );
    }

    $ev = topo_events( $r );
    chk( preg_match( '/INFO VARNISH: 1 URL purged on/', $ev ) === 1, 'log: "INFO VARNISH: 1 URL purged on ..."', substr( $ev, -600 ) );
    chk( topo_count( '/WARNING VARNISH/', $ev ) === 0, 'log: no Varnish warning', substr( $ev, -600 ) );
    chk( topo_edge_ids( $a ) === 2, "$a is cached again afterwards (HIT)", fn() => layers( $a ) );
}

if ( $S === 'purgeall' ) {
    need_example_vcl( $S );   // the host-wide purge is a regex ban: noregex / aclnone / nopurge delete nothing by design (see 'vclclass')
    $pages = need_pages( 1, $S );
    foreach ( $pages as $p ) { chk( topo_warm( $p ), "warm $p in both layers" ); }

    topo_log_clear();
    $r = topo_purge_all();
    chk( $r['rc'] === 0 && $r['token'] !== 'error', 'wp npp purge (Purge All) succeeds', 'rc=' . $r['rc'] . ' token=' . $r['token'] . "\n" . $r['out'] );

    foreach ( $pages as $p ) {
        chk( topo_origin_state( $p ) === 'MISS', "nginx layer: $p was purged (MISS at the origin)", 'HTTP Purge All on ' . $TOPO['purge'] . ' did not empty nginx' );
        $ids = topo_edge_ids( $p );
        chk( $ids === 1, "Varnish layer: $p was banned (visitor GET is a MISS)", "ids=$ids; a VCL without the regex branch answers 200 and deletes nothing" );
    }
    $ev = topo_events( $r );
    chk( preg_match( '/INFO VARNISH: host-wide purge sent to 1 Varnish/', $ev ) === 1, 'log: "INFO VARNISH: host-wide purge sent to 1 ... endpoint"', substr( $ev, -600 ) );
    chk( topo_count( '/WARNING VARNISH/', $ev ) === 0, 'log: no Varnish warning', substr( $ev, -600 ) );
    foreach ( $pages as $p ) {
        chk( topo_edge_ids( $p ) === 2, "$p cached again after the ban (the ban does not hit objects stored later)", fn() => layers( $p ) );
    }
}

if ( $S === 'outsider' ) {
    $pages = need_pages( 1, $S );
    $a = $pages[0];
    chk( topo_warm( $a ), "warm $a in both layers" );

    foreach ( [ 'with the control key' => topo_key_hdr(), 'without a key' => [], 'regex (Purge All shape)' => topo_key_hdr() + [ 'X-Purge-Method' => 'regex' ] ] as $label => $extra ) {
        $path = $label === 'regex (Purge All shape)' ? '/.*' : $a;
        $r = topo_http( 'PURGE', $TOPO['edge'] . $path, topo_hdrs( $extra ), 10.0 );
        chk( $r['code'] === 405, "visitor PURGE through the edge $label -> 405", 'code=' . $r['code'] . ' ' . $r['err'] );
    }
    chk( topo_edge_ids( $a ) === 2, "$a is still cached in Varnish after the visitor PURGEs", fn() => layers( $a ) );
    chk( topo_origin_state( $a ) === 'HIT', "$a is still cached in nginx after the visitor PURGEs", fn() => layers( $a ) );

    foreach ( [ '/npp_purge_all', '/npp_purge_single/', '/npp_purge_single' . $a ] as $p ) {
        $e = topo_http( 'GET', $TOPO['edge'] . $p, topo_hdrs(), 10.0 );
        chk( $e['code'] === 404, "edge: GET $p -> 404 (purge endpoints are not public)", 'code=' . $e['code'] );
        $o = topo_http( 'GET', $TOPO['origin'] . $p, topo_hdrs(), 10.0 );
        chk( $o['code'] === 404, "origin :8080: GET $p -> 404 (not served where Varnish connects)", 'code=' . $o['code'] );
    }
    chk( topo_origin_state( $a ) === 'HIT' && topo_edge_ids( $a ) === 2, 'nothing was purged by those GETs', fn() => layers( $a ) );
}

/* ============================================================================================================
 * faults
 * ========================================================================================================= */

if ( $S === 'vclclass' ) {
    $v = topo_variant();
    chk( $v !== 'unknown', "the active VCL is recognised (found: $v)", 'PURGE probe answers did not match any known variant' );
    if ( $TOPO['variant_env'] !== 'auto' ) {
        chk( $v === $TOPO['variant_env'], "the VCL the script installed ({$TOPO['variant_env']}) is the one that answers", "behaviour says $v" );
    }
    $pages = topo_pages();
    $probe = '/npp-topo-vclclass-' . getmypid();

    if ( $v === 'example' ) {
        chk( topo_purge_raw( $probe )['code'] === 403, 'PURGE without a control key -> 403' );
        chk( topo_purge_raw( $probe, [ 'X-Control-Key' => 'wrong' ] )['code'] === 403, 'PURGE with a wrong control key -> 403' );
        chk( topo_purge_raw( $probe, topo_key_hdr() )['code'] === 200, 'PURGE with the control key -> 200' );
        if ( $pages ) {
            topo_warm( $pages[0] );
            $r = topo_purge_raw( $pages[0], topo_key_hdr() );
            chk( $r['code'] === 200 && topo_edge_ids( $pages[0] ) === 1, "exact PURGE removes $pages[0] from Varnish", 'code=' . $r['code'] );
        }
        $ip = topo_env( 'TOPO_PHP_IP' );
        echo 'INFO: ACL mode ' . $TOPO['acl'] . ( $ip !== '' ? ", PHP container $ip" : '' ) . ": this container is allowed to PURGE (200 above)\n";
    } elseif ( $v === 'aclnone' ) {
        chk( topo_purge_raw( $probe, topo_key_hdr() )['code'] === 405, 'ACL that does not list the PHP container: PURGE with the right key -> 405' );
        if ( $pages ) {
            topo_warm( $pages[0] );
            topo_log_clear();
            $r = topo_purge_single( $pages[0] );
            $ev = topo_events( $r );
            chk( preg_match( '/WARNING VARNISH: .*refused the PURGE request \(HTTP 405\)/', $ev ) === 1, 'NPP logs "refused the PURGE request (HTTP 405)"', substr( $ev, -600 ) );
            chk( topo_origin_state( $pages[0] ) === 'MISS', 'nginx purge is not affected by the refusal', fn() => layers( $pages[0] ) );
            known( topo_edge_ids( $pages[0] ) === 2, 'Varnish keeps the stale page when the ACL refuses (the nginx purge is not rolled back)' );
        }
    } elseif ( $v === 'noregex' ) {
        if ( $pages ) {
            topo_warm( $pages[0] );
            $r = topo_purge_raw( '/.*', topo_key_hdr() + [ 'X-Purge-Method' => 'regex' ] );
            chk( $r['code'] === 200, 'regex PURGE answers 200 even though the VCL has no regex branch', 'code=' . $r['code'] );
            known( topo_edge_ids( $pages[0] ) === 2, 'regex PURGE without a ban branch deletes nothing: the page is still cached (NPP can not detect this)' );
            topo_log_clear();
            $pr = topo_purge_all();
            $ev = topo_events( $pr );
            chk( topo_origin_state( $pages[0] ) === 'MISS', 'Purge All emptied nginx', fn() => layers( $pages[0] ) );
            known( topo_edge_ids( $pages[0] ) === 2 && topo_count( '/WARNING VARNISH/', $ev ) === 0, 'Purge All: NPP reports success, Varnish still serves the stale page (silent no-op, documented in the Help tab)' );
        }
    } elseif ( $v === 'nopurge' ) {
        if ( $pages ) {
            topo_warm( $pages[0] );
            $r = topo_purge_raw( $pages[0], topo_key_hdr() );
            known( topo_edge_ids( $pages[0] ) === 2, "VCL without a PURGE handler: the PURGE (HTTP {$r['code']}) reaches the origin and Varnish keeps the page" );
        }
    }
}

if ( $S === 'foreignhost' ) {
    need_example_vcl( $S );
    $pages = need_pages( 1, $S );
    $a = $pages[0];
    chk( topo_warm( $a ), "warm $a in both layers" );
    $r = topo_purge_raw( $a, topo_key_hdr(), 'other.example' );
    chk( $r['code'] === 200, 'PURGE for Host other.example is accepted', 'code=' . $r['code'] );
    chk( topo_edge_ids( $a ) === 2, "...but $a of this site is still cached (the hash includes the Host)", fn() => layers( $a ) );
    $r = topo_purge_raw( '/.*', topo_key_hdr() + [ 'X-Purge-Method' => 'regex' ], 'other.example' );
    chk( $r['code'] === 200, 'host-wide ban for Host other.example is accepted', 'code=' . $r['code'] );
    chk( topo_edge_ids( $a ) === 2, "...but $a of this site survives it (the ban expression is host-scoped)", fn() => layers( $a ) );
}

/** One purge of $a while the Varnish side is broken; returns the result and what was logged. */
function broken_purge( string $a ): array {
    topo_reset_state();
    chk( topo_warm( $a ), "warm $a in both layers" );
    topo_log_clear();
    $r = topo_purge_single( $a );
    return [ $r, topo_events( $r ) ];
}

if ( $S === 'badkey' ) {
    need_example_vcl( $S );
    $pages = need_pages( 1, $S );
    $a = $pages[0];
    topo_snapshot();
    foreach ( [ 'wrong control key' => 'X-Control-Key: wrong', 'no control key' => '' ] as $label => $hdr ) {
        topo_set( 'nppp_varnish_header', $hdr );
        [ $r, $ev ] = broken_purge( $a );
        chk( $r['rc'] === 0 && $r['token'] !== 'error', "$label: the purge command itself succeeds", 'rc=' . $r['rc'] . ' token=' . $r['token'] );
        chk( topo_count( '/WARNING VARNISH: .*refused the PURGE request \(HTTP 403\)/', $ev ) === 1, "$label: exactly one \"refused the PURGE request (HTTP 403)\" warning", substr( $ev, -700 ) );
        chk( topo_origin_state( $a ) === 'MISS', "$label: nginx was purged anyway", fn() => layers( $a ) );
        known( topo_edge_ids( $a ) === 2, "$label: Varnish keeps the stale page (the nginx purge is not rolled back)" );
        chk( stripos( $ev, $TOPO['key'] ) === false, "$label: the control key is not in the log" );
    }
}

if ( $S === 'dns' ) {
    $pages = need_pages( 1, $S );
    $a = $pages[0];
    topo_snapshot();
    topo_set( 'nppp_varnish_endpoints', 'http://npp-topo-missing:80' );

    [ $r, $ev ] = broken_purge( $a );
    chk( $r['rc'] === 0 && $r['token'] !== 'error', 'unresolvable endpoint: the purge command succeeds', 'rc=' . $r['rc'] . ' token=' . $r['token'] );
    chk( topo_count( '/WARNING VARNISH: .*unreachable/', $ev ) === 1, 'exactly one "unreachable" warning', substr( $ev, -700 ) );
    chk( preg_match( '/unreachable \((DNS failure|transport error)\)/', $ev ) === 1, 'the reason is DNS failure (or the generic transport error of newer libcurl)', substr( $ev, -700 ) );
    chk( topo_origin_state( $a ) === 'MISS', 'nginx purge is not affected', fn() => layers( $a ) );
    chk( $r['sec'] < 10.0, 'the command returns inside the time budget', sprintf( '%.1fs', $r['sec'] ) );

    // Circuit breaker: the endpoint is skipped for 5 minutes, no second warning, no second wait.
    topo_warm( $a );
    topo_log_clear();
    $r2 = topo_purge_single( $a );
    $ev2 = topo_events( $r2 );
    chk( topo_count( '/WARNING VARNISH/', $ev2 ) === 0, 'second purge: breaker is open, no new warning', substr( $ev2, -600 ) );
    chk( topo_origin_state( $a ) === 'MISS', 'second purge: nginx purged as usual', fn() => layers( $a ) );

    // A dead endpoint beside a live one: the live one is still purged (needs a VCL that honours an exact PURGE).
    if ( ! in_array( topo_variant(), [ 'example', 'noregex' ], true ) ) {
        skip( "two endpoints, one dead (the active VCL '" . topo_variant() . "' refuses or ignores PURGE by design)" );
    } else {
        topo_set( 'nppp_varnish_endpoints', "http://npp-topo-missing:80\n" . $TOPO['varnish'] );
        [ $r3, $ev3 ] = broken_purge( $a );
        chk( topo_count( '/WARNING VARNISH: .*endpoint #1 is unreachable/', $ev3 ) === 1, 'two endpoints: only the dead one (#1) is reported', substr( $ev3, -700 ) );
        chk( topo_origin_state( $a ) === 'MISS', 'two endpoints: nginx purged', fn() => layers( $a ) );
        chk( topo_edge_ids( $a ) === 1, 'two endpoints: the live Varnish was still purged', fn() => layers( $a ) );
    }
}

if ( $S === 'refused' ) {
    $pages = need_pages( 1, $S );
    $a = $pages[0];
    topo_snapshot();
    $u = parse_url( $TOPO['varnish'] );
    topo_set( 'nppp_varnish_endpoints', ( $u['scheme'] ?? 'http' ) . '://' . ( $u['host'] ?? 'varnish' ) . ':81' );   // the container is there, nothing listens on :81
    [ $r, $ev ] = broken_purge( $a );
    chk( $r['rc'] === 0 && $r['token'] !== 'error', 'closed port: the purge command succeeds', 'rc=' . $r['rc'] . ' token=' . $r['token'] );
    chk( preg_match( '/unreachable \((connection refused|transport error)\)/', $ev ) === 1 && topo_count( '/WARNING VARNISH/', $ev ) === 1, 'exactly one warning: unreachable (connection refused)', substr( $ev, -700 ) );
    chk( topo_origin_state( $a ) === 'MISS', 'nginx purge is not affected', fn() => layers( $a ) );
    chk( $r['sec'] < 10.0, 'the command returns inside the time budget', sprintf( '%.1fs', $r['sec'] ) );
}

if ( $S === 'blackhole' ) {
    $pages = need_pages( 1, $S );
    $a = $pages[0];
    topo_snapshot();
    topo_set( 'nppp_varnish_endpoints', 'http://192.0.2.1:80' );   // TEST-NET-1: routed to the default gateway and dropped
    [ $r, $ev ] = broken_purge( $a );
    chk( $r['rc'] === 0 && $r['token'] !== 'error', 'black-holed endpoint: the purge command succeeds', 'rc=' . $r['rc'] . ' token=' . $r['token'] );
    chk( topo_count( '/WARNING VARNISH/', $ev ) === 1, 'exactly one warning', substr( $ev, -700 ) );
    chk( topo_origin_state( $a ) === 'MISS', 'nginx purge is not affected', fn() => layers( $a ) );
    // 1 s connect + 3 s total per request, ~6 s budget, plus the WP-CLI bootstrap. A hang would show as the 120 s timeout.
    chk( $r['sec'] < 12.0, 'the command is not held hostage by a dead endpoint (budget ~6 s + WP-CLI start)', sprintf( '%.1fs', $r['sec'] ) );
    echo sprintf( "INFO: black-holed endpoint: purge took %.1fs\n", $r['sec'] );
}

/* ============================================================================================================
 * preload (opt-in)
 * ========================================================================================================= */

if ( $S === 'preload' ) {
    need_example_vcl( $S );
    $dry = topo_wp( [ 'npp', 'preload', '--dry-run' ] );
    if ( $dry['rc'] !== 0 ) {
        echo "SKIP topology/preload: preload is locked in this container: " . trim( $dry['out'] ) . "\n";
        exit( 77 );
    }
    $pages = need_pages( 1, $S );
    $a = $pages[0];
    register_shutdown_function( static function () { topo_wp( [ 'npp', 'preload', '--stop' ] ); } );

    $r = topo_purge_all();
    chk( $r['rc'] === 0 && $r['token'] !== 'error', 'Purge All before the preload succeeds', $r['out'] );
    $before = topo_cache_files();
    chk( $before === 0, 'nginx cache directory is empty after Purge All', "files=$before" );

    $r = topo_wp( [ 'npp', 'preload', '--porcelain' ] );
    $r['token'] = topo_token( $r['out'] );
    chk( $r['rc'] === 0 && $r['token'] !== 'error', 'wp npp preload starts', 'rc=' . $r['rc'] . "\n" . $r['out'] );

    // Watch the cache DIRECTORY, not the pages: a GET from here would fill the very caches we want to inspect.
    $deadline = time() + 240; $last = -1; $stable = 0;
    while ( time() < $deadline ) {
        sleep( 5 );
        $n = topo_cache_files();
        $stable = ( $n === $last && $n > 0 ) ? $stable + 1 : 0;
        $last = $n;
        if ( $stable >= 3 ) { break; }
    }
    chk( $last > 0, 'the crawl filled the nginx cache', "files=$last after 240 s" );
    if ( $last > 0 ) {
        // First touch of every page: HIT in both layers means the crawl came in through the edge and Varnish stored it.
        $ids = topo_edge_ids( $a );
        chk( $ids === 2, "first visitor GET of $a after the preload is a Varnish HIT (the crawl went through Varnish)", "ids=$ids (1 = the crawl bypassed Varnish or did not reach this page)" );
        chk( topo_origin_state( $a ) === 'HIT', "nginx layer: $a is a HIT", fn() => layers( $a ) );
    }
}

exit( vt_summary() );
