<?php
/**
 * Layer 1 - unit / hook-wiring tests for includes/compat-varnish.php.   php tests/unit.php <section>
 *
 * Real plugin code (compat-varnish.php, state.php, purge-http.php) on the stub in lib/wpstub.php. The transport is
 * scripted (no Varnish needed) except in the sections `transport` and `hang`, which use real sockets.
 *
 * Sections run in their own PHP process (run-varnish-tests.sh does this) because the plugin keeps two per-request
 * statics: the endpoint circuit-breaker memo and the "Purge All runs once" guard. Endpoints are unique per test
 * (s1.test, s2.test ...) so the breaker memo of one test never leaks into the next.
 *
 * Sections: parse config classify single results budget all preload allveto allfail
 *           sslverify hosts dedupe scope exceptions logging state logreason fallback   (scripted)
 *           transport hang                                                             (real sockets)
 *           source                                                                     (static checks on the plugin sources)
 *
 * chk()   = must hold.   known() = documented limit of the plugin; passes while it still holds and FAILS when the
 * behaviour changes, so a fixed gap (or a regression hiding one) can not go unnoticed.
 */
require __DIR__ . '/../lib/wpstub.php';
$S = $argv[1] ?? 'all';
vt_load( $S !== 'fallback' );   // `fallback` loads compat-varnish.php WITHOUT purge-http.php (its own helpers only)
$ON = [ 'nppp_varnish_sync_enabled' => 'yes' ];
function on( string $eps, string $hdr = '' ): void { global $ON; vt_reset(); vt_set( $ON + [ 'nppp_varnish_endpoints' => $eps, 'nppp_varnish_header' => $hdr ] ); }
function reqs(): array { global $VT; return $VT['reqs']; }
function urls(): array { return array_map( fn( $r ) => $r['method'] . ' ' . $r['url'], reqs() ); }
function leaks( array $needles ): array { global $VT; $bad = []; foreach ( $VT['notices'] as $n ) { foreach ( $needles as $x ) { if ( $x !== '' && stripos( $n, $x ) !== false ) { $bad[] = $x; } } } return $bad; }
$OK = [ 'code' => 200, 'body' => 'Purged' ];

/* ================================================================================================================ */
if ( $S === 'parse' ) {
    $E = function ( $raw ) { $e = []; $r = nppp_varnish_parse_endpoints( $raw, $e ); return [ $r, $e ]; };
    $e = []; [ $r, $e ] = $E( '127.0.0.1:6081' );              chk( $r === [ 'http://127.0.0.1:6081' ], 'bare host:port gets http://' );
    $e = []; [ $r, $e ] = $E( 'localhost:6081' );              chk( $r === [ 'http://localhost:6081' ], 'bare name:port is not mistaken for a scheme' );
    $e = []; [ $r, $e ] = $E( 'HTTP://Cache.Local:80' );       chk( $r === [ 'http://cache.local:80' ], 'scheme + host lower-cased, explicit port kept' );
    $e = []; [ $r, $e ] = $E( 'https://cache.local' );         chk( $r === [ 'https://cache.local' ], 'https accepted' );
    $e = []; [ $r, $e ] = $E( '[::1]:6081' );                  chk( $r === [ 'http://[::1]:6081' ], 'IPv6 literal with port' );
    $e = []; [ $r, $e ] = $E( 'http://[::1]' );                chk( $r === [ 'http://[::1]' ], 'IPv6 literal without port' );
    $e = []; [ $r, $e ] = $E( 'http://h/' );                   chk( $r === [ 'http://h' ] && $e === [], 'single trailing slash is tolerated' );
    $e = []; [ $r, $e ] = $E( 'http://h:1' );                  chk( $r === [ 'http://h:1' ], 'port 1 is valid' );
    $e = []; [ $r, $e ] = $E( 'http://h:65535' );              chk( $r === [ 'http://h:65535' ], 'port 65535 is valid' );
    $e = []; [ $r, $e ] = $E( "a.test\nb.test, c.test;d.test e.test" ); chk( count( $r ) === 5 && $e === [], 'newline / comma / semicolon / space separators' );
    $e = []; [ $r, $e ] = $E( "a.test\r\nb.test\r\n" );        chk( $r === [ 'http://a.test', 'http://b.test' ] && $e === [], 'CRLF line endings (textarea) are separators, not errors' );
    $e = []; [ $r, $e ] = $E( "a.test\nb.test\nc.test\nd.test\ne.test\nf.test" ); chk( count( $r ) === 5 && $e === [ 'too_many' ], 'max 5 endpoints, too_many reported' );
    $e = []; [ $r, $e ] = $E( "a.test\nA.TEST\nhttp://a.test" ); chk( $r === [ 'http://a.test' ], 'duplicates (case / scheme default) collapse' );
    $e = []; [ $r, $e ] = $E( "a.test a.test a.test a.test a.test a.test" ); chk( count( $r ) === 1 && $e === [], 'duplicates are collapsed BEFORE the 5-endpoint cap (no false too_many)' );
    $e = []; [ $r, $e ] = $E( '' );                            chk( $r === [] && $e === [], 'empty list = no endpoints, no error' );
    $e = []; [ $r, $e ] = $E( " \n ,; " );                     chk( $r === [] && $e === [], 'separators only = no endpoints, no error' );
    foreach ( [ 'ftp://h' => 'scheme', 'file:///etc/passwd' => 'invalid', 'http://u:p@h' => 'userinfo', 'http://h/x' => 'path', 'http://h//' => 'path', 'http://h?x=1' => 'path', 'http://h#f' => 'path',
                'http://h:0' => 'port', 'http://h:65536' => 'invalid', 'http://h:abc' => 'invalid', 'http://' => 'invalid', 'http://:80' => 'invalid', '-bad.example' => 'host', '::1' => 'host' ] as $in => $code ) {
        // file:/// has an empty host, so it is rejected as "invalid" before the scheme is looked at (rejected either way).
        // A bare IPv6 address (no brackets) parses as host "::1" and fails the host-name rule: IPv6 needs [::1].
        $e = []; [ $r, $e ] = $E( $in ); chk( $r === [] && $e === [ $code ], "reject " . json_encode( $in ) . " ($code)", json_encode( [ $r, $e ] ) );
    }
    $e = []; [ $r, $e ] = $E( "http://h\x00.test" );           chk( $r === [] && $e !== [], 'NUL byte rejected' );
    $e = []; [ $r, $e ] = $E( 'http://' . str_repeat( 'a', 250 ) . '.test' ); chk( $r === [] && $e !== [], 'token > 255 chars rejected' );
    $e = []; [ $r, $e ] = $E( 'http://日本.jp' );
    if ( function_exists( 'nppp_idn_to_ascii_host' ) || function_exists( 'idn_to_ascii' ) ) { chk( $r === [ 'http://xn--wgv71a.jp' ], 'IDN host converted to punycode' ); }
    else { skip( 'IDN host to punycode (needs PHP intl: idn_to_ascii); without it the plugin rejects the host, see next check' ); chk( $r === [] && $e === [ 'host' ], 'no IDN converter available: non-ASCII host is rejected, not passed through' ); }
    // strict caller (sanitize) rejects when $errors is not empty, so a half-valid token list must carry an error
    $e = []; [ $r, $e ] = $E( 'ok.test bad host!' );           chk( $e !== [] && in_array( 'http://ok.test', $r, true ), 'valid + invalid token: valid one is returned, error reported (strict callers reject)', json_encode( [ $r, $e ] ) );
    // Typo with a space inside a host name is NOT an error: it becomes two valid endpoints (input quirk)
    $e = []; [ $r, $e ] = $E( 'http://exa mple.com' );         known( $e === [] && count( $r ) === 2, 'typo "exa mple.com" is silently split into two endpoints', json_encode( $r ) );
    $e = []; [ $r, $e ] = $E( 'http://256.1.1.1' );            known( $r === [ 'http://256.1.1.1' ], 'out-of-range IPv4 literal passes the host-name rule (harmless, fails at connect)' );
    $e = []; [ $r, $e ] = $E( 'a.example.com.' );              known( $r === [] && $e === [ 'host' ], 'FQDN with trailing dot is rejected (harmless: omit the dot)' );

    $H = function ( $raw ) { $err = ''; $r = nppp_varnish_parse_header( $raw, $err ); return [ $r, $err ]; };
    [ $r, $er ] = $H( 'X-Control-Key: s3cret' );           chk( $r === [ 'name' => 'X-Control-Key', 'value' => 's3cret' ] && $er === '', 'header parsed' );
    [ $r, $er ] = $H( "X-Key:   spaced value  " );         chk( ( $r['value'] ?? '' ) === 'spaced value', 'header value trimmed' );
    [ $r, $er ] = $H( "X-Key: a\tb" );                     chk( ( $r['value'] ?? '' ) === "a\tb", 'a TAB inside the value is allowed' );
    [ $r, $er ] = $H( 'X-Key: a:b:c' );                    chk( ( $r['value'] ?? '' ) === 'a:b:c', 'colons inside the value are kept (split at the FIRST colon)' );
    [ $r, $er ] = $H( 'X-Key: p%41ss' );                   chk( ( $r['value'] ?? '' ) === 'p%41ss', '%XX in a secret is kept as is (no URL decoding)' );
    [ $r, $er ] = $H( '' );                                chk( $r === [] && $er === '', 'empty header = none, no error' );
    [ $r, $er ] = $H( '   ' );                             chk( $r === [] && $er === '', 'blank header = none, no error' );
    foreach ( [ "X-A:b\r\nX-B: c" => 'crlf', "X-A:b\nX-B: c" => 'crlf', "X-A:b\x00" => 'crlf', 'X-Control-Key' => 'format', 'X-Control-Key:' => 'format-or-value',
                'Host: x' => 'forbidden', 'host : x' => 'forbidden', 'Content-Length: 1' => 'forbidden', 'Transfer-Encoding: chunked' => 'forbidden', 'Connection: close' => 'forbidden',
                'Keep-Alive: 1' => 'forbidden', 'TE: x' => 'forbidden', 'Trailer: x' => 'forbidden', 'Upgrade: h2c' => 'forbidden', 'Proxy-Authorization: x' => 'forbidden',
                'X-Purge-Method: regex' => 'forbidden', 'x-purge-method: default' => 'forbidden', 'X-Ü: a' => 'name', ': v' => 'name', 'X K: v' => 'name', "X-K: a\x01b" => 'value', 'X-K: ünï' => 'value' ] as $in => $code ) {
        [ $r, $er ] = $H( $in );
        $okc = $code === 'format-or-value' ? in_array( $er, [ 'value', 'format' ], true ) : $er === $code;
        chk( $r === [] && $okc, 'header reject ' . json_encode( $in ) . " ($code)", json_encode( [ $r, $er ] ) );
    }
    [ $r, $er ] = $H( 'X-K: ' . str_repeat( 'v', 512 ) ); chk( $r !== [], '512-char header value accepted' );
    [ $r, $er ] = $H( 'X-K: ' . str_repeat( 'v', 513 ) ); chk( $r === [] && $er === 'value', '513-char header value rejected' );
    [ $r, $er ] = $H( str_repeat( 'a', 64 ) . ': v' );     chk( $r !== [], '64-char header name accepted' );
    [ $r, $er ] = $H( str_repeat( 'a', 65 ) . ': v' );     chk( $r === [] && $er === 'name', '65-char header name rejected' );
    [ $r, $er ] = $H( 'Authorization: Bearer abc' );       known( $r !== [], 'Authorization / Cookie / Expect / Content-Type are NOT reserved (a typo could change PURGE semantics)' );
    foreach ( [ 'scheme', 'userinfo', 'path', 'host', 'port', 'too_many', 'crlf', 'name', 'forbidden', 'value', 'format', 'nope' ] as $c ) {
        chk( nppp_varnish_parse_error_label( $c ) !== '', "error label for '$c'" );
    }
    chk( strpos( nppp_varnish_parse_error_label( 'too_many' ), '5' ) !== false, 'too_many label names the limit (5)' );
}

/* ================================================================================================================ */
if ( $S === 'config' ) {
    vt_reset(); vt_set( [ 'nppp_varnish_endpoints' => '127.0.0.1:6081' ] );
    chk( nppp_varnish_sync_on() === false && nppp_varnish_endpoints() === [] && ! nppp_varnish_enabled( 'purge_all' ), 'switch key missing (upgrade from a build without it) = OFF even with stored endpoints' );
    vt_set( [ 'nppp_varnish_sync_enabled' => 'no' ] );
    chk( nppp_varnish_endpoints() === [], 'switch OFF: stored endpoints are ignored' );
    vt_set( [ 'nppp_varnish_sync_enabled' => 'yes' ] );
    chk( nppp_varnish_endpoints() === [ 'http://127.0.0.1:6081' ], 'switch ON: stored endpoints used' );
    vt_set( [ 'nppp_varnish_sync_enabled' => 'YES' ] );
    chk( nppp_varnish_sync_on() === false, 'switch value is exactly "yes" (case sensitive, like every other NPP toggle)' );
    vt_set( [ 'nppp_varnish_sync_enabled' => [ 'yes' ] ] );
    chk( nppp_varnish_sync_on() === false, 'non-string switch value = OFF' );
    vt_set( [ 'nppp_varnish_sync_enabled' => 'yes', 'nppp_varnish_endpoints' => '' ] );
    chk( ! nppp_varnish_enabled( 'purge_all' ), 'switch ON + empty list = not enabled' );
    vt_reset(); vt_set( [ 'nppp_varnish_sync_enabled' => 'yes' ] ); $GLOBALS['VT']['options']['nginx_cache_settings'] = 'corrupt';
    chk( nppp_varnish_sync_on() === false && nppp_varnish_endpoints() === [], 'corrupt (non-array) settings option = OFF, no warning/exception' );

    vt_reset(); vt_set( [ 'nppp_varnish_sync_enabled' => 'yes', 'nppp_varnish_endpoints' => 'a.test' ] );
    add_filter( 'nppp_varnish_endpoints', fn( $l ) => [ 'http://x.test:81', 'ftp://evil', 'http://p.test/path', [ 'nested' ], 42, 'http://x.test:81' ] );
    chk( nppp_varnish_endpoints() === [ 'http://x.test:81' ], 'filter output is re-validated (bad scheme / path / non-string / dupes dropped)', json_encode( nppp_varnish_endpoints() ) );
    remove_all_filters( 'nppp_varnish_endpoints' );
    add_filter( 'nppp_varnish_endpoints', fn( $l ) => 'not-an-array' );
    chk( nppp_varnish_endpoints() === [], 'filter returning a non-array = no endpoints' );
    remove_all_filters( 'nppp_varnish_endpoints' );
    add_filter( 'nppp_varnish_endpoints', fn( $l ) => array_map( fn( $i ) => "h$i.test", range( 1, 9 ) ) );
    chk( count( nppp_varnish_endpoints() ) === 5, 'filter output capped at 5 endpoints' );
    remove_all_filters( 'nppp_varnish_endpoints' );
    $got = null; add_filter( 'nppp_varnish_endpoints', function ( $l ) use ( &$got ) { $got = $l; return $l; } ); nppp_varnish_endpoints();
    chk( $got === [ 'http://a.test' ], 'filter receives the normalized list from Settings', json_encode( $got ) ); remove_all_filters( 'nppp_varnish_endpoints' );

    // by design: a developer filter can supply endpoints while the Settings switch is OFF (documented filter contract)
    vt_reset(); vt_set( [ 'nppp_varnish_sync_enabled' => 'no', 'nppp_varnish_endpoints' => 'a.test' ] );
    $got = null; add_filter( 'nppp_varnish_endpoints', function ( $l ) use ( &$got ) { $got = $l; return [ 'http://filter.test' ]; } );
    chk( nppp_varnish_enabled( 'purge_all' ) === true, 'DESIGN: filter-supplied endpoint works even with the switch OFF (filter contract)' );
    chk( $got === [], 'switch OFF: the filter is handed an EMPTY list (stored addresses stay hidden)', json_encode( $got ) );
    remove_all_filters( 'nppp_varnish_endpoints' );

    vt_reset(); vt_set( [ 'nppp_varnish_sync_enabled' => 'yes', 'nppp_varnish_endpoints' => 'a.test' ] );
    $seen = []; add_filter( 'nppp_sync_varnish_enabled', function ( $v, $ctx ) use ( &$seen ) { $seen[] = $ctx; return $ctx !== 'preload_all'; }, 10, 2 );
    chk( nppp_varnish_enabled( 'purge_urls' ) && nppp_varnish_enabled( 'purge_all' ) && ! nppp_varnish_enabled( 'preload_all' ), 'veto filter works per context' );
    chk( $seen === [ 'purge_urls', 'purge_all', 'preload_all' ], 'veto filter gets purge_urls | purge_all | preload_all', json_encode( $seen ) );
    remove_all_filters( 'nppp_sync_varnish_enabled' );
    $called = 0; add_filter( 'nppp_sync_varnish_enabled', function ( $v, $c ) use ( &$called ) { $called++; return true; }, 10, 2 );
    vt_set( [ 'nppp_varnish_endpoints' => '' ] ); nppp_varnish_enabled( 'purge_all' );
    chk( $called === 0, 'no endpoint = the veto filter is not even consulted' ); remove_all_filters( 'nppp_sync_varnish_enabled' );

    vt_reset(); vt_set( [ 'nppp_varnish_header' => 'X-Control-Key: k' ] );
    chk( nppp_varnish_extra_header() === [ 'name' => 'X-Control-Key', 'value' => 'k' ], 'control header re-validated at send time' );
    vt_set( [ 'nppp_varnish_header' => "X-A: b\r\nX-B: c" ] );
    chk( nppp_varnish_extra_header() === [], 'a poisoned stored header (edited in DB) is dropped at send time, never sent' );
    vt_set( [ 'nppp_varnish_header' => 'Host: evil.test' ] );
    chk( nppp_varnish_extra_header() === [], 'a stored "Host:" header can not override the purge Host' );
    vt_set( [ 'nppp_varnish_header' => 'X-Purge-Method: regex' ] );
    chk( nppp_varnish_extra_header() === [], 'a stored "X-Purge-Method:" header can not turn single purges into regex bans' );
    vt_set( [ 'nppp_varnish_header' => '' ] );
    chk( nppp_varnish_extra_header() === [], 'no header stored = []' );

    foreach ( [ 'http://example.com' => 'example.com', 'https://example.com' => 'example.com', 'http://example.com:8080' => 'example.com:8080', 'https://example.com:443' => 'example.com', 'http://[::1]:8080' => '[::1]:8080', 'HTTP://EXAMPLE.com/' => 'example.com',
                'http://example.com:80' => 'example.com', 'https://example.com:80' => 'example.com:80' ] as $h => $want ) {
        vt_reset(); vt_home( $h ); chk( nppp_varnish_host_header() === $want, "Host header for home_url $h = $want", nppp_varnish_host_header() );
    }
    vt_reset(); vt_home( 'http://example.com' );
    add_filter( 'nppp_varnish_host_header', fn( $d ) => 'internal.test:8080' ); chk( nppp_varnish_host_header() === 'internal.test:8080', 'host header filter override' ); remove_all_filters( 'nppp_varnish_host_header' );
    add_filter( 'nppp_varnish_host_header', fn( $d ) => "evil.test\r\nX-Injected: 1" ); chk( nppp_varnish_host_header() === 'example.com', 'host header filter with CRLF falls back to the default' ); remove_all_filters( 'nppp_varnish_host_header' );
    add_filter( 'nppp_varnish_host_header', fn( $d ) => 'evil.test x' ); chk( nppp_varnish_host_header() === 'example.com', 'host header filter with a space falls back to the default' ); remove_all_filters( 'nppp_varnish_host_header' );
    add_filter( 'nppp_varnish_host_header', fn( $d ) => '' ); chk( nppp_varnish_host_header() === 'example.com', 'host header filter returning "" falls back to the default' ); remove_all_filters( 'nppp_varnish_host_header' );
    add_filter( 'nppp_varnish_host_header', fn( $d ) => [ 'x' ] ); chk( nppp_varnish_host_header() === 'example.com', 'host header filter with non-string falls back' ); remove_all_filters( 'nppp_varnish_host_header' );

    // wiring facts
    global $VT;
    $has = fn( $tag, $fn, $prio = 10 ) => (bool) array_filter( $VT['actions'][ $tag ][ $prio ] ?? [], fn( $x ) => $x[0] === $fn );
    chk( $has( 'nppp_purged_urls', 'nppp_varnish_purge_urls' ) && $has( 'nppp_purged_all', 'nppp_varnish_purge_all' ) && $has( 'nppp_preload_all_started', 'nppp_varnish_purge_all' ), 'listeners registered on the three NPP hooks (priority 10)' );
    $a = array_values( array_filter( $VT['actions']['nppp_purged_urls'][10], fn( $x ) => $x[0] === 'nppp_varnish_purge_urls' ) )[0][1];
    chk( $a === 4, 'nppp_purged_urls listener accepts the 4 arguments NPP passes', "n=$a" );
    $a = array_values( array_filter( $VT['actions']['nppp_purged_all'][10], fn( $x ) => $x[0] === 'nppp_varnish_purge_all' ) )[0][1];
    chk( $a === 1, 'nppp_purged_all listener accepts the 1 argument NPP passes', "n=$a" );
    chk( ! isset( $VT['actions']['http_api_curl'] ) || array_filter( $VT['actions']['http_api_curl'] ) === [], 'loading the plugin does NOT leave a permanent http_api_curl callback' );
}

/* ================================================================================================================ */
if ( $S === 'classify' ) {
    vt_reset(); vt_home( 'http://example.com' );
    $C = fn( $u ) => nppp_varnish_classify_url( $u );
    foreach ( [ 'http://example.com/' => '/', 'http://example.com' => '/', 'http://example.com/a/' => '/a/', 'http://example.com/a/b.html' => '/a/b.html', 'http://example.com/a/?x=1&y=2' => '/a/?x=1&y=2',
                'https://example.com/a/' => '/a/', 'HTTP://EXAMPLE.COM/a/' => '/a/', 'http://example.com/a/#frag' => '/a/', 'http://example.com/a/?p=1;q=2' => '/a/?p=1;q=2',
                'http://example.com/a-b_c.d~e/' => '/a-b_c.d~e/', 'http://example.com/?s=hello+world' => '/?s=hello+world', 'http://example.com/a/?x=a:b@c,d' => '/a/?x=a:b@c,d',
                'http://example.com/a/?x=1&x=2' => '/a/?x=1&x=2', 'http://example.com/a/?x=!$\'()*' => '/a/?x=!$\'()*', '  http://example.com/a/  ' => '/a/', 'http://example.com/a.b/c..d/' => '/a.b/c..d/' ] as $u => $p ) {
        $c = $C( $u ); chk( $c['action'] === 'single' && $c['path'] === $p, "single: $u", json_encode( $c ) );
    }
    foreach ( [ 'http://example.com/%d9%85/' => 'percent-encoded path', 'http://example.com/a/%41/' => 'percent-encoded path', 'http://example.com/ü/' => 'non-ASCII characters', 'http://example.com//a/' => 'repeated slashes',
                'http://example.com/a//' => 'repeated slashes', 'http://example.com/a/?q=%20x' => 'query characters rewritten by the HTTP client', 'http://example.com/a/?a[]=1' => 'query characters rewritten by the HTTP client',
                'http://example.com/a/?x=a|b' => 'query characters rewritten by the HTTP client', 'http://example.com/a/?x=ü' => 'non-ASCII characters', 'http://example.com/a\\b/' => 'backslash',
                'http://example.com/a/../b' => 'dot segments', 'http://example.com/a/./b' => 'dot segments', 'http://example.com/a/%2e%2e/b' => 'dot segments', 'http://example.com/a/%252e%252e/b' => 'dot segments',
                'http://example.com/a/..%2fb' => 'dot segments', 'http://example.com/..' => 'dot segments', 'http://example.com/a/..' => 'dot segments',
                'http://example.com/a/ b/' => 'whitespace or control characters', "http://example.com/a/\tb/" => 'whitespace or control characters' ] as $u => $why ) {
        $c = $C( $u ); chk( $c['action'] === 'all' && $c['reason'] === $why, 'escalate: ' . json_encode( $u ) . " ($why)", json_encode( $c ) );
    }
    foreach ( [ '', '   ', 'not a url', '/just/a/path', '//example.com/a/', 'javascript:alert(1)', 'ftp://example.com/a/', 'mailto:a@example.com' ] as $u ) {
        $c = $C( $u ); chk( $c['action'] === 'skip' && $c['reason'] === 'invalid URL', 'skip invalid: ' . json_encode( $u ), json_encode( $c ) );
    }
    foreach ( [ 'http://www.example.com/a/', 'http://example.org/a/', 'http://example.com:8080/a/', 'https://example.com:444/a/', 'http://example.com.evil.test/a/', 'http://evil.test/http://example.com/a/', 'http://evil.test/%d9%85/', 'http://evil.test/../x' ] as $u ) {
        $c = $C( $u ); chk( $c['action'] === 'skip' && $c['reason'] === 'host differs from the site host', 'skip foreign host/port (SSRF + scope guard, checked BEFORE escalation): ' . $u, json_encode( $c ) );
    }
    vt_home( 'http://example.com:8080' );
    $c = $C( 'http://example.com:8080/a/' ); chk( $c['action'] === 'single', 'home with port: same port is in scope' );
    $c = $C( 'http://example.com/a/' );      chk( $c['action'] === 'skip', 'home with port: portless URL is out of scope' );
    vt_home( 'https://example.com' );
    $c = $C( 'http://example.com/a/' );      chk( $c['action'] === 'single', 'scheme is ignored, only host:port matter (Varnish sees the same Host for http and https)' );
    $c = $C( 'https://example.com:443/a/' ); chk( $c['action'] === 'single', 'explicit default port is the same authority' );
    vt_home( 'http://example.com' );
    $c = $C( 'http://user:pw@example.com/a/' ); chk( in_array( $c['action'], [ 'single', 'skip' ], true ) && ( $c['path'] ?? '' ) !== 'pw', 'userinfo URL never leaks credentials into the PURGE path', json_encode( $c ) );
    $c = $C( 'http://example.com/a/?' );     known( $c['action'] === 'single' && $c['path'] === '/a/', 'empty query "?" is dropped: PURGE /a/ cannot match an object cached as /a/? (edge case, never produced by WordPress permalinks)' );
}

/* ================================================================================================================ */
if ( $S === 'single' ) {
    global $VT, $OK, $ON;
    on( "http://s1.test:6081\nhttp://s2.test:6081", 'X-Control-Key: sekret-value' ); vt_script( '#^PURGE http://s[12]\.test#', $OK );
    do_action( 'nppp_purged_urls', [ 'http://example.com/a/', 'http://example.com/b/?x=1', 'http://example.com/a/', 'http://other.test/zz/' ], 'http://example.com/a/', 5, false );
    $u = urls();
    chk( $u === [ 'PURGE http://s1.test:6081/a/', 'PURGE http://s1.test:6081/b/?x=1', 'PURGE http://s2.test:6081/a/', 'PURGE http://s2.test:6081/b/?x=1' ], 'one PURGE per unique in-scope path per endpoint, foreign host skipped', json_encode( $u ) );
    $h = reqs()[0]['headers'];
    chk( $h['Host'] === 'example.com' && $h['X-Purge-Method'] === 'default' && $h['Content-Length'] === '0' && $h['X-Control-Key'] === 'sekret-value', 'headers: Host, X-Purge-Method default, Content-Length 0, control header', json_encode( $h ) );
    $a = reqs()[0]['args'];
    chk( $a['method'] === 'PURGE' && $a['redirection'] === 0 && $a['blocking'] === true && $a['sslverify'] === false && $a['limit_response_size'] === 4096 && $a['nppp_varnish_connect_ms'] === 1000 && $a['timeout'] <= 3.0 && $a['timeout'] >= 1.0, 'request args: PURGE, no redirects, 4 KB cap, 1 s connect, <= 3 s total', json_encode( array_diff_key( $a, [ 'headers' => 1 ] ) ) );
    chk( reqs()[0]['curl_hooked'] === true, 'the connect-timeout callback is attached to http_api_curl WHILE the request runs' );
    chk( ! isset( $VT['actions']['http_api_curl'][10] ) || $VT['actions']['http_api_curl'][10] === [], 'http_api_curl callback removed after the request (never leaks into NPP\'s own requests)' );
    chk( leaks( [ 's1.test', 's2.test', 'sekret-value', '6081' ] ) === [], 'log never contains endpoint addresses or the control header value', vt_notes() );
    chk( strpos( vt_notes(), '2 URLs purged on Varnish / Vinyl Cache endpoint #1' ) !== false, 'log says "2 URLs purged on ... endpoint #1"', vt_notes() );
    chk( strpos( vt_notes(), '1 URL(s) skipped' ) !== false, 'log reports the skipped foreign URL', vt_notes() );

    on( 'http://s3.test' ); vt_script( '#.#', $OK ); do_action( 'nppp_purged_urls', [ 'http://example.com/a/' ], '', 0, true );
    chk( count( reqs() ) === 1 && ! isset( reqs()[0]['headers']['X-Control-Key'] ), 'no control header configured = none sent' );
    chk( strpos( vt_notes(), '1 URL purged on' ) !== false, 'log uses the singular form for one URL', vt_notes() );

    // arguments / state that must be a quiet no-op
    on( 'http://s4.test' ); vt_script( '#.#', $OK );
    do_action( 'nppp_purged_urls', [], '', 0, false ); do_action( 'nppp_purged_urls', 'not-an-array' ); do_action( 'nppp_purged_urls', [ 123, null, [ 'x' ] ] ); do_action( 'nppp_purged_urls', [ 'http://other.test/x/' ] ); do_action( 'nppp_purged_urls', null );
    chk( reqs() === [], 'empty / non-array / non-string / all-foreign URL lists send nothing and do not throw' );
    vt_reset(); vt_set( [ 'nppp_varnish_sync_enabled' => 'no', 'nppp_varnish_endpoints' => 's5.test' ] ); vt_script( '#.#', $OK ); do_action( 'nppp_purged_urls', [ 'http://example.com/a/' ] );
    chk( reqs() === [], 'switch OFF: nothing is sent' );
    on( 'http://s6.test' ); vt_script( '#.#', $OK ); add_filter( 'nppp_sync_varnish_enabled', fn( $v, $c ) => false, 10, 2 ); do_action( 'nppp_purged_urls', [ 'http://example.com/a/' ] );
    chk( reqs() === [], 'veto filter: nothing is sent' ); remove_all_filters( 'nppp_sync_varnish_enabled' );

    // escalation to ONE host-wide purge
    on( "http://s7.test\nhttp://s8.test" ); vt_script( '#.#', $OK );
    do_action( 'nppp_purged_urls', [ 'http://example.com/ok/', 'http://example.com/%d9%85/', 'http://example.com/also-ok/' ] );
    chk( urls() === [ 'PURGE http://s7.test/.*', 'PURGE http://s8.test/.*' ], 'one ambiguous URL escalates the whole event to ONE host-wide purge per endpoint (no single PURGEs)', json_encode( urls() ) );
    chk( reqs()[0]['headers']['X-Purge-Method'] === 'regex', 'host-wide purge carries X-Purge-Method: regex' );
    chk( strpos( vt_notes(), 'percent-encoded path' ) !== false, 'log names the escalation reason', vt_notes() );

    on( 'http://s9.test' ); vt_script( '#.#', $OK );
    $many = array_map( fn( $i ) => "http://example.com/p$i/", range( 1, 50 ) ); do_action( 'nppp_purged_urls', $many );
    chk( count( reqs() ) === 50 && reqs()[0]['headers']['X-Purge-Method'] === 'default', '50 URLs = still single PURGEs (threshold is "more than 50")' );
    on( 'http://s10.test' ); vt_script( '#.#', $OK ); $many[] = 'http://example.com/p51/'; do_action( 'nppp_purged_urls', $many );
    chk( urls() === [ 'PURGE http://s10.test/.*' ], '51 URLs = ONE host-wide purge', json_encode( count( reqs() ) ) );
    chk( strpos( vt_notes(), 'many URLs' ) !== false, 'log names the escalation reason "many URLs"', vt_notes() );
    on( 'http://s11.test' ); vt_script( '#.#', $OK ); add_filter( 'nppp_varnish_max_urls', fn( $m ) => 3 );
    do_action( 'nppp_purged_urls', [ 'http://example.com/1/', 'http://example.com/2/', 'http://example.com/3/' ] ); $n3 = count( reqs() );
    vt_reset(); vt_set( $ON + [ 'nppp_varnish_endpoints' => 's11.test' ] ); vt_script( '#.#', $OK ); add_filter( 'nppp_varnish_max_urls', fn( $m ) => 3 );   // vt_reset() drops test-added filters
    do_action( 'nppp_purged_urls', [ 'http://example.com/1/', 'http://example.com/2/', 'http://example.com/3/', 'http://example.com/4/' ] );
    chk( $n3 === 3 && urls() === [ 'PURGE http://s11.test/.*' ], 'filter nppp_varnish_max_urls lowers the escalation threshold' ); remove_all_filters( 'nppp_varnish_max_urls' );
    on( 'http://s12.test' ); vt_script( '#.#', $OK ); add_filter( 'nppp_varnish_max_urls', fn( $m ) => 0 ); do_action( 'nppp_purged_urls', array_map( fn( $i ) => "http://example.com/q$i/", range( 1, 10 ) ) );
    chk( count( reqs() ) === 10, 'filter value < 1 falls back to the default 50' ); remove_all_filters( 'nppp_varnish_max_urls' );
    on( 'http://s13.test' ); vt_script( '#.#', $OK ); add_filter( 'nppp_varnish_max_urls', fn( $m ) => 'abc' ); do_action( 'nppp_purged_urls', array_map( fn( $i ) => "http://example.com/r$i/", range( 1, 10 ) ) );
    chk( count( reqs() ) === 10, 'non-numeric filter value falls back to the default 50' ); remove_all_filters( 'nppp_varnish_max_urls' );
}

/* ================================================================================================================ */
if ( $S === 'results' ) {
    global $VT, $OK;
    $P3 = [ 'http://example.com/a/', 'http://example.com/b/', 'http://example.com/c/' ];
    on( 'http://r1.test' ); vt_script( '#.#', [ 'code' => 404, 'body' => '' ] ); do_action( 'nppp_purged_urls', $P3 );
    chk( count( reqs() ) === 3 && strpos( vt_notes(), '3 URLs purged' ) !== false && strpos( vt_notes(), 'WARNING' ) === false, '404 = "not in cache": harmless, counted as done, no warning', vt_notes() );
    foreach ( [ 201, 202, 204 ] as $i => $code ) {
        on( "http://ok$i.test" ); vt_script( '#.#', [ 'code' => $code, 'body' => '' ] ); do_action( 'nppp_purged_urls', $P3 );
        chk( count( reqs() ) === 3 && strpos( vt_notes(), '3 URLs purged' ) !== false && strpos( vt_notes(), 'WARNING' ) === false, "HTTP $code is a success", vt_notes() );
    }
    foreach ( [ 401, 403, 405, 501 ] as $i => $code ) {
        on( "http://d$i.test" ); vt_script( '#.#', [ 'code' => $code, 'body' => 'no' ] ); do_action( 'nppp_purged_urls', $P3 );
        chk( count( reqs() ) === 1 && preg_match( "/refused the PURGE request \(HTTP $code\)/", vt_notes() ), "HTTP $code = denied: stop this endpoint after the FIRST url, one warning naming the status", json_encode( [ count( reqs() ), vt_notes() ] ) );
    }
    on( 'http://t1.test' ); vt_script( '#.#', [ 'code' => 403, 'body' => '' ] );
    do_action( 'nppp_purged_urls', [ 'http://example.com/a/' ] ); $w1 = substr_count( vt_notes(), 'refused the PURGE request' );
    do_action( 'nppp_purged_urls', [ 'http://example.com/a/' ] ); $w2 = substr_count( vt_notes(), 'refused the PURGE request' );
    chk( $w1 === 1 && $w2 === 1 && count( reqs() ) === 2, 'denied warning is throttled (once per 5 min) but the endpoint is NOT parked: next purge still tries', "w1=$w1 w2=$w2 reqs=" . count( reqs() ) );
    chk( ! isset( $VT['options']['nppp_st_varnish_breaker'] ), 'denied does not arm the circuit breaker' );

    foreach ( [ 500, 400, 429, 502, 504 ] as $i => $code ) {
        on( "http://e$i.test" ); vt_script( '#.#', [ 'code' => $code, 'body' => '' ] ); do_action( 'nppp_purged_urls', $P3 );
        chk( count( reqs() ) === 3 && substr_count( vt_notes(), "answered the PURGE request with HTTP $code" ) === 1, "HTTP $code: every URL still attempted, ONE throttled warning", vt_notes() );
    }
    on( 'http://f1.test' ); vt_script( '#.#', [ 'code' => 500, 'body' => '' ] ); do_action( 'nppp_purged_urls', $P3 );
    chk( count( reqs() ) === 3 && substr_count( vt_notes(), 'answered the PURGE request with HTTP 500' ) === 1, 'HTTP 500: every URL still attempted, ONE throttled warning', vt_notes() );
    vt_script( '#.#', [ 'code' => 503, 'body' => '' ] ); do_action( 'nppp_purged_urls', [ 'http://example.com/z/' ] );
    chk( substr_count( vt_notes(), 'with HTTP 503' ) === 1, 'a different status warns separately (throttle key includes the status)' );
    chk( ! isset( $VT['options']['nppp_st_varnish_breaker'] ), 'HTTP 5xx does not arm the circuit breaker (the server answered)' );
    on( 'http://f2.test' ); vt_script( '#.#', [ 'code' => 301, 'body' => '', 'headers' => [ 'Location' => 'http://example.com/' ] ] ); do_action( 'nppp_purged_urls', [ 'http://example.com/a/' ] );
    chk( count( reqs() ) === 1 && strpos( vt_notes(), 'HTTP 301' ) !== false, 'a redirect is not followed and is reported as a failure' );

    // breaker
    on( "http://b1.test\nhttp://b2.test" );
    vt_script( '#b1\.test#', new WP_Error( 'http_request_failed', 'cURL error 7: Failed to connect to b1.test port 80: Connection refused' ) ); vt_script( '#b2\.test#', $OK );
    do_action( 'nppp_purged_urls', $P3 );
    chk( count( array_filter( urls(), fn( $x ) => strpos( $x, 'b1.test' ) !== false ) ) === 1 && count( array_filter( urls(), fn( $x ) => strpos( $x, 'b2.test' ) !== false ) ) === 3, 'unreachable endpoint: 1 attempt then skip; the other endpoint still gets all 3 URLs', json_encode( urls() ) );
    chk( strpos( vt_notes(), 'endpoint #1 is unreachable (connection refused)' ) !== false && leaks( [ 'b1.test', 'b2.test', 'Connection refused port' ] ) === [], 'one warning with a coarse reason, address never logged', vt_notes() );
    $before = count( reqs() ); do_action( 'nppp_purged_urls', $P3 );
    chk( count( array_filter( array_slice( urls(), $before ), fn( $x ) => strpos( $x, 'b1.test' ) !== false ) ) === 0, 'parked endpoint receives NO request while the breaker is open' );
    $before = count( reqs() ); do_action( 'nppp_purged_all', false );
    chk( count( array_filter( array_slice( urls(), $before ), fn( $x ) => strpos( $x, 'b1.test' ) !== false ) ) === 0 && count( array_filter( array_slice( urls(), $before ), fn( $x ) => strpos( $x, 'b2.test' ) !== false ) ) === 1, 'the breaker is shared with Purge All: parked endpoint skipped, healthy one purged' );
    chk( is_array( $VT['options']['nppp_st_varnish_breaker']['v'] ?? null ) && ( $VT['options']['nppp_st_varnish_breaker']['exp'] ?? 0 ) > time(), 'breaker stored in the nppp_state option store {v, exp} (survives wp_cache_flush / Redis flush)' );
    chk( ( $VT['autoload']['nppp_st_varnish_breaker'] ?? null ) === false, 'breaker row is not autoloaded' );
    chk( nppp_varnish_breaker_open( 'http://b1.test' ) && ! nppp_varnish_breaker_open( 'http://b2.test' ), 'breaker is per endpoint' );

    // breaker row expiry (state-based, fresh endpoint so the in-process memo does not mask it)
    vt_reset(); $id = nppp_varnish_endpoint_id( 'http://exp.test' );
    nppp_state_set( 'varnish_breaker', [ $id => time() - 1 ], 600 ); chk( ! nppp_varnish_breaker_open( 'http://exp.test' ), 'expired breaker row = closed again' );
    nppp_state_set( 'varnish_breaker', [ $id => time() + 250 ], 600 ); chk( nppp_varnish_breaker_open( 'http://exp.test' ), 'live breaker row = open' );
    nppp_state_set( 'varnish_breaker', [ $id => 'junk', 5 => time() + 100 ], 600 ); chk( ! nppp_varnish_breaker_open( 'http://exp.test' ), 'malformed breaker rows are ignored' );
    nppp_state_set( 'varnish_breaker', [ $id => time() + 250 ], 600 ); nppp_state_delete( 'varnish_breaker' );
    chk( ! nppp_varnish_breaker_open( 'http://exp.test' ), 'deleting the state row (Clear Plugin Cache / settings change) reopens a parked endpoint' );

    // exception inside the HTTP transport must not escape
    on( 'http://x1.test' ); vt_script( '#.#', function () { throw new RuntimeException( 'boom from an http_api filter' ); } );
    try { do_action( 'nppp_purged_urls', [ 'http://example.com/a/' ] ); $thrown = false; } catch ( Throwable $t ) { $thrown = true; }
    chk( ! $thrown && strpos( vt_notes(), 'unreachable' ) !== false && leaks( [ 'boom' ] ) === [], 'exception in the transport is contained, reported as transport error, message not logged', vt_notes() );
    chk( empty( $VT['actions']['http_api_curl'][10] ), 'the http_api_curl callback is removed even when the transport throws' );

    // DESIGN GAP (not a crash): purges skipped while an endpoint is parked are not recorded or replayed
    on( 'http://gap.test' ); $id = nppp_varnish_endpoint_id( 'http://gap.test' ); nppp_state_set( 'varnish_breaker', [ $id => time() + 250 ], 300 ); vt_script( '#.#', $OK );
    do_action( 'nppp_purged_urls', [ 'http://example.com/changed-page/' ] );
    $keys = array_keys( array_filter( $VT['options'], fn( $k ) => stripos( (string) $k, 'varnish' ) !== false || stripos( (string) $k, 'missed' ) !== false, ARRAY_FILTER_USE_KEY ) );
    known( reqs() === [] && array_diff( $keys, [ 'nppp_st_varnish_breaker', 'nppp_st_varnish_warn' ] ) === [], 'GAP: a purge skipped during the 5 min park window is lost - nothing is recorded to catch up (Varnish serves the stale copy until its TTL)', json_encode( $keys ) );
}

/* ================================================================================================================ */
if ( $S === 'budget' ) {
    global $VT;
    on( 'http://slow.test' ); add_filter( 'nppp_varnish_max_urls', fn( $m ) => 500 );
    vt_script( '#.#', function () { usleep( 500000 ); return [ 'code' => 200, 'body' => '' ]; } );
    $t = microtime( true ); do_action( 'nppp_purged_urls', array_map( fn( $i ) => "http://example.com/s$i/", range( 1, 30 ) ) ); $el = microtime( true ) - $t;
    chk( $el >= 4.5 && $el <= 6.5, 'one hook call is capped by the ~5 s total budget (30 x 0.5 s requests)', sprintf( '%.2fs', $el ) );
    chk( count( reqs() ) >= 9 && count( reqs() ) <= 11, 'about 10 requests fit into the budget', (string) count( reqs() ) );
    chk( preg_match( '/time budget exhausted, (\d+) PURGE request\(s\) were skipped/', vt_notes(), $m ) && (int) $m[1] === 30 - count( reqs() ), 'warning states exactly how many PURGEs were skipped', vt_notes() );
    $to = array_map( fn( $r ) => $r['args']['timeout'], reqs() ); chk( min( $to ) >= 1.0 && max( $to ) <= 3.0, 'per-request timeout stays within 1..3 s and shrinks to the remaining budget', json_encode( [ min( $to ), max( $to ) ] ) );
    remove_all_filters( 'nppp_varnish_max_urls' );

    // Purge All: the budget is spent over the ENDPOINTS (5 x 1.5 s: the 5th starts after the 5 s deadline)
    on( "http://bu1.test\nhttp://bu2.test\nhttp://bu3.test\nhttp://bu4.test\nhttp://bu5.test" );
    vt_script( '#.#', function () { usleep( 1500000 ); return [ 'code' => 200, 'body' => '' ]; } );
    $t = microtime( true ); do_action( 'nppp_purged_all', false ); $el = microtime( true ) - $t;
    chk( count( reqs() ) === 4 && $el >= 5.5 && $el <= 7.0, 'Purge All: the 5th endpoint is not tried after the 5 s budget (4 requests)', sprintf( '%d requests, %.2fs', count( reqs() ), $el ) );
    chk( strpos( vt_notes(), 'time budget exhausted, remaining endpoints were skipped' ) !== false && strpos( vt_notes(), 'sent to 4 Varnish' ) !== false, 'Purge All: budget warning + the 4 sent endpoints are reported', vt_notes() );
}

/* ================================================================================================================ */
if ( $S === 'all' ) {
    global $VT, $OK;
    on( "http://a1.test\nhttp://a2.test", 'X-Control-Key: sekret-value' ); vt_script( '#.#', $OK );
    do_action( 'nppp_purged_all', true );
    chk( urls() === [ 'PURGE http://a1.test/.*', 'PURGE http://a2.test/.*' ], 'nppp_purged_all: ONE host-wide PURGE /.* per endpoint', json_encode( urls() ) );
    $h = reqs()[0]['headers']; chk( $h['X-Purge-Method'] === 'regex' && $h['Host'] === 'example.com' && $h['X-Control-Key'] === 'sekret-value' && $h['Content-Length'] === '0', 'regex purge headers' );
    chk( strpos( vt_notes(), 'host-wide purge sent to 2 Varnish / Vinyl Cache endpoints' ) !== false && leaks( [ 'a1.test', 'sekret-value' ] ) === [], 'log hygiene', vt_notes() );
    do_action( 'nppp_preload_all_started' );
    chk( count( reqs() ) === 2, 'GUARD: Purge All followed by preload_all_started in the same request sends no duplicate' );
    // Only the preload trigger is deduplicated; every genuine Purge All is sent (long-lived WP-CLI / cron / queue process).
    do_action( 'nppp_purged_all', false );
    chk( count( reqs() ) === 4, 'a second, separate Purge All in the SAME PHP process IS sent (only preload_all is deduplicated)', 'reqs=' . count( reqs() ) );
    do_action( 'nppp_purged_all', false );
    chk( count( reqs() ) === 6, 'and so is a third one', 'reqs=' . count( reqs() ) );
    do_action( 'nppp_preload_all_started' );
    known( count( reqs() ) === 6, 'GAP: after any Purge All, a LATER independent Preload All trigger in the same PHP process is deduplicated (static flag never resets) - only matters for long-lived processes', 'reqs=' . count( reqs() ) );
}
if ( $S === 'preload' ) {
    global $VT, $OK;
    on( 'http://p1.test' ); vt_script( '#.#', $OK ); do_action( 'nppp_preload_all_started' );
    chk( urls() === [ 'PURGE http://p1.test/.*' ] && reqs()[0]['headers']['X-Purge-Method'] === 'regex', 'nppp_preload_all_started: host-wide PURGE before the crawl' );
    chk( strpos( vt_notes(), 'host-wide purge sent' ) !== false, 'logged' );
    do_action( 'nppp_preload_all_started' );
    chk( count( reqs() ) === 1, 'a second preload trigger in the same request is deduplicated' );
    do_action( 'nppp_purged_all', false );
    chk( count( reqs() ) === 2, 'a Purge All after a preload trigger still runs' );
}
if ( $S === 'allveto' ) {
    global $VT, $OK; $ctxs = [];
    on( 'http://v1.test' ); vt_script( '#.#', $OK ); add_filter( 'nppp_sync_varnish_enabled', function ( $v, $c ) use ( &$ctxs ) { $ctxs[] = $c; return false; }, 10, 2 );
    do_action( 'nppp_purged_all', false ); do_action( 'nppp_preload_all_started' );
    chk( reqs() === [] && $ctxs[0] === 'purge_all', 'veto on purge_all sends nothing and reports context purge_all', json_encode( $ctxs ) );
    chk( ( $ctxs[1] ?? '' ) === 'preload_all', 'veto on the preload trigger reports context preload_all', json_encode( $ctxs ) );
    remove_all_filters( 'nppp_sync_varnish_enabled' ); do_action( 'nppp_purged_all', false );
    chk( count( reqs() ) === 1, 'a vetoed Purge All does not consume the once-per-request guard (a later non-vetoed trigger still runs)' );
}
if ( $S === 'allfail' ) {
    global $VT, $OK;
    on( "http://z1.test\nhttp://z2.test" ); vt_script( '#z1#', new WP_Error( 'x', 'cURL error 28: Operation timed out after 3000 milliseconds' ) ); vt_script( '#z2#', $OK );
    do_action( 'nppp_purged_all', false );
    chk( count( reqs() ) === 2 && strpos( vt_notes(), 'endpoint #1 is unreachable (connection timed out)' ) !== false && strpos( vt_notes(), 'sent to 1 Varnish' ) !== false, 'Purge All: failing endpoint warns, healthy endpoint still purged and counted', vt_notes() );

    // denied / absent / healthy mix: only answers that mean "done" are counted, denied warns once per window
    on( "http://y1.test\nhttp://y2.test\nhttp://y3.test" ); vt_script( '#y1#', [ 'code' => 403, 'body' => '' ] ); vt_script( '#y2#', [ 'code' => 404, 'body' => '' ] ); vt_script( '#y3#', $OK );
    do_action( 'nppp_purged_all', false );
    chk( count( reqs() ) === 3 && strpos( vt_notes(), 'sent to 2 Varnish' ) !== false && strpos( vt_notes(), 'endpoint #1 refused the PURGE request (HTTP 403)' ) !== false, 'Purge All: 403 warns (#1), 404 and 200 count as sent', vt_notes() );
    do_action( 'nppp_purged_all', false );
    chk( count( reqs() ) === 6 && substr_count( vt_notes(), 'refused the PURGE request' ) === 1, 'Purge All: denied endpoint is retried every time (not parked) but warns once per window', vt_notes() );

    // nothing reached any endpoint: no "sent" line at all
    on( "http://n1.test" ); vt_script( '#.#', [ 'code' => 501, 'body' => '' ] );
    do_action( 'nppp_purged_all', false );
    chk( strpos( vt_notes(), 'host-wide purge sent' ) === false && strpos( vt_notes(), 'HTTP 501' ) !== false, 'Purge All: no endpoint accepted = no "host-wide purge sent" line, only the warning', vt_notes() );

    // 5xx on Purge All
    on( "http://n2.test" ); vt_script( '#.#', [ 'code' => 503, 'body' => '' ] );
    do_action( 'nppp_purged_all', false );
    chk( strpos( vt_notes(), 'answered the PURGE request with HTTP 503' ) !== false && strpos( vt_notes(), 'host-wide purge sent' ) === false, 'Purge All: HTTP 503 is a warning, not a success', vt_notes() );
}

/* ================================================================================================================ */
if ( $S === 'sslverify' ) {
    global $VT, $OK; $seen = [];
    on( 'https://tls1.test' ); vt_script( '#.#', $OK ); do_action( 'nppp_purged_urls', [ 'http://example.com/a/' ] );
    chk( reqs()[0]['url'] === 'https://tls1.test/a/' && reqs()[0]['args']['sslverify'] === false, 'https endpoint: certificate verification is OFF by default' );
    on( 'https://tls2.test' ); vt_script( '#.#', $OK ); add_filter( 'nppp_varnish_sslverify', function ( $v, $ep ) use ( &$seen ) { $seen[] = [ $v, $ep ]; return true; }, 10, 2 );
    do_action( 'nppp_purged_urls', [ 'http://example.com/a/' ] );
    chk( reqs()[0]['args']['sslverify'] === true && $seen === [ [ false, 'https://tls2.test' ] ], 'nppp_varnish_sslverify can turn verification ON; it receives the default and the normalized endpoint', json_encode( $seen ) );
    remove_all_filters( 'nppp_varnish_sslverify' );
    on( 'https://tls3.test' ); vt_script( '#.#', $OK ); add_filter( 'nppp_varnish_sslverify', fn( $v, $ep ) => 'yes', 10, 2 ); do_action( 'nppp_purged_all', false );
    chk( reqs()[0]['args']['sslverify'] === true, 'Purge All uses the same filter; a truthy non-bool value is cast to bool', json_encode( reqs()[0]['args']['sslverify'] ) );
    remove_all_filters( 'nppp_varnish_sslverify' );
    on( 'https://tls4.test' ); vt_script( '#.#', $OK ); add_filter( 'nppp_varnish_sslverify', fn( $v, $ep ) => $ep === 'https://tls4.test', 10, 2 ); do_action( 'nppp_purged_urls', [ 'http://example.com/a/' ] );
    chk( reqs()[0]['args']['sslverify'] === true, 'the filter can decide per endpoint' );
    remove_all_filters( 'nppp_varnish_sslverify' );
}

/* ================================================================================================================ */
if ( $S === 'hosts' ) {
    global $VT, $OK;
    on( 'http://[::1]:6081' ); vt_script( '#.#', $OK ); do_action( 'nppp_purged_urls', [ 'http://example.com/a/' ] );
    chk( urls() === [ 'PURGE http://[::1]:6081/a/' ], 'IPv6 endpoint: request URL keeps the brackets', json_encode( urls() ) );
    on( "http://[::1]:6081\nhttp://127.0.0.1:6081" ); vt_script( '#.#', $OK ); do_action( 'nppp_purged_all', false );
    chk( urls() === [ 'PURGE http://[::1]:6081/.*', 'PURGE http://127.0.0.1:6081/.*' ], 'dual-stack: v6 and v4 loopback are two endpoints, both purged' );

    on( 'http://h1.test' ); vt_home( 'http://[::1]:8080' ); vt_script( '#.#', $OK ); do_action( 'nppp_purged_urls', [ 'http://[::1]:8080/p/', 'http://[::1]/p/' ] );
    chk( urls() === [ 'PURGE http://h1.test/p/' ] && reqs()[0]['headers']['Host'] === '[::1]:8080', 'site on an IPv6 literal with a port: Host = [::1]:8080, the portless twin is out of scope', json_encode( [ urls(), reqs()[0]['headers'] ?? null ] ) );

    on( 'http://h2.test' ); vt_home( 'https://example.com:8443' ); vt_script( '#.#', $OK ); do_action( 'nppp_purged_urls', [ 'https://example.com:8443/a/' ] );
    chk( reqs()[0]['headers']['Host'] === 'example.com:8443', 'non-default port is part of the Host header' );

    on( 'http://h3.test' ); vt_script( '#.#', $OK ); add_filter( 'nppp_varnish_host_header', fn( $d ) => 'internal.test:8080' ); do_action( 'nppp_purged_urls', [ 'http://example.com/a/' ] ); do_action( 'nppp_purged_all', false );
    chk( reqs()[0]['headers']['Host'] === 'internal.test:8080' && reqs()[1]['headers']['Host'] === 'internal.test:8080', 'nppp_varnish_host_header reaches the wire for single AND host-wide purges' );
    chk( urls()[0] === 'PURGE http://h3.test/a/', 'the filtered Host does not change the request target / endpoint' );
    remove_all_filters( 'nppp_varnish_host_header' );

    // the scope check still uses home_url(), not the filtered Host
    on( 'http://h4.test' ); vt_script( '#.#', $OK ); add_filter( 'nppp_varnish_host_header', fn( $d ) => 'internal.test' ); do_action( 'nppp_purged_urls', [ 'http://internal.test/a/' ] );
    chk( reqs() === [], 'URL scope is the site host (home_url), the Host filter does not widen it' );
    remove_all_filters( 'nppp_varnish_host_header' );
}

/* ================================================================================================================ */
if ( $S === 'dedupe' ) {
    global $VT, $OK;
    on( 'http://dd1.test' ); vt_script( '#.#', $OK );
    $u = array_fill( 0, 60, 'http://example.com/same/' ); $u[] = 'http://example.com/same/#x'; $u[] = 'https://example.com/same/';
    do_action( 'nppp_purged_urls', $u );
    chk( urls() === [ 'PURGE http://dd1.test/same/' ], '60 identical URLs (+ fragment / https twins) = ONE PURGE, no escalation (threshold counts UNIQUE paths)', json_encode( urls() ) );
    on( 'http://dd2.test' ); vt_script( '#.#', $OK );
    $u = array_map( fn( $i ) => 'http://example.com/u' . ( $i % 50 ) . '/', range( 1, 150 ) );
    do_action( 'nppp_purged_urls', $u );
    chk( count( reqs() ) === 50 && reqs()[0]['headers']['X-Purge-Method'] === 'default', '150 URLs but 50 unique paths = still single PURGEs' );
    on( 'http://dd3.test' ); vt_script( '#.#', $OK );
    do_action( 'nppp_purged_urls', [ 'http://example.com/q/', 'http://example.com/q/?a=1', 'http://example.com/q/?a=2', 'http://example.com/q/?a=1' ] );
    chk( urls() === [ 'PURGE http://dd3.test/q/', 'PURGE http://dd3.test/q/?a=1', 'PURGE http://dd3.test/q/?a=2' ], 'query-string variants are separate cache objects and are purged separately, order is kept', json_encode( urls() ) );
}

/* ================================================================================================================ */
if ( $S === 'scope' ) {
    global $VT, $OK;
    on( 'http://sc1.test' ); vt_script( '#.#', $OK );
    do_action( 'nppp_purged_urls', [ 'http://other.test/a/', 'http://example.com:8080/a/', 'http://sub.example.com/a/' ] );
    chk( reqs() === [], 'only foreign / other-port / sub-domain URLs: nothing is sent and nothing escalates' );
    chk( strpos( vt_notes(), 'host-wide' ) === false, 'foreign URLs never trigger a host-wide purge' );
    on( 'http://sc2.test' ); vt_script( '#.#', $OK );
    do_action( 'nppp_purged_urls', [ 'http://other.test/%d9%85/', 'http://example.com/ok/' ] );
    chk( urls() === [ 'PURGE http://sc2.test/ok/' ], 'a foreign URL with an ambiguous path is skipped BEFORE escalation (it can not widen the purge)', json_encode( urls() ) );
    on( 'http://sc3.test' ); vt_script( '#.#', $OK );
    do_action( 'nppp_purged_urls', [ 'not a url', '', 'http://example.com/ok/' ] );
    chk( urls() === [ 'PURGE http://sc3.test/ok/' ], 'garbage entries are skipped, valid ones still purged', json_encode( urls() ) );
    on( 'http://sc4.test' ); vt_script( '#.#', $OK );
    do_action( 'nppp_purged_urls', [ 'http://example.com/' ] );
    chk( urls() === [ 'PURGE http://sc4.test/' ], 'the home page purges "/" (not a host-wide regex)', json_encode( urls() ) );
}

/* ================================================================================================================ */
if ( $S === 'exceptions' ) {
    global $VT, $OK;
    // A throwing developer filter must never break the purge that already succeeded on Nginx.
    on( 'http://ex1.test' ); vt_script( '#.#', $OK );
    add_filter( 'nppp_varnish_endpoints', function ( $l ) { throw new RuntimeException( 'secret-internal-detail' ); } );
    try { do_action( 'nppp_purged_urls', [ 'http://example.com/a/' ] ); do_action( 'nppp_purged_all', false ); do_action( 'nppp_preload_all_started' ); $thrown = false; } catch ( Throwable $t ) { $thrown = true; }
    chk( ! $thrown && reqs() === [], 'a throwing nppp_varnish_endpoints filter is contained in all three listeners, nothing sent' );
    chk( strpos( vt_notes(), 'unexpected error (RuntimeException)' ) !== false && leaks( [ 'secret-internal-detail' ] ) === [], 'logged as "unexpected error (class)", the message is not logged', vt_notes() );
    remove_all_filters( 'nppp_varnish_endpoints' );

    on( 'http://ex2.test' ); vt_script( '#.#', $OK );
    add_filter( 'nppp_sync_varnish_enabled', function ( $v, $c ) { throw new Error( 'fatal-ish' ); }, 10, 2 );
    try { do_action( 'nppp_purged_all', false ); $thrown = false; } catch ( Throwable $t ) { $thrown = true; }
    chk( ! $thrown && strpos( vt_notes(), 'unexpected error (Error)' ) !== false, 'a throwing veto filter (Error, not Exception) is contained as well' );
    remove_all_filters( 'nppp_sync_varnish_enabled' );

    on( 'http://ex3.test' ); vt_script( '#.#', $OK );
    add_filter( 'nppp_varnish_max_urls', function ( $m ) { throw new LogicException( 'x' ); } );
    try { do_action( 'nppp_purged_urls', [ 'http://example.com/a/' ] ); $thrown = false; } catch ( Throwable $t ) { $thrown = true; }
    chk( ! $thrown && strpos( vt_notes(), 'unexpected error (LogicException)' ) !== false, 'a throwing nppp_varnish_max_urls filter is contained' );
    remove_all_filters( 'nppp_varnish_max_urls' );

    on( 'http://ex4.test' ); vt_script( '#.#', $OK );
    add_filter( 'nppp_varnish_sslverify', function ( $v, $e ) { throw new RuntimeException( 'ssl' ); }, 10, 2 );
    try { do_action( 'nppp_purged_urls', [ 'http://example.com/a/' ] ); $thrown = false; } catch ( Throwable $t ) { $thrown = true; }
    chk( ! $thrown && empty( $VT['actions']['http_api_curl'][10] ), 'a throwing nppp_varnish_sslverify filter is contained and leaves no http_api_curl callback behind' );
    remove_all_filters( 'nppp_varnish_sslverify' );

    // the curl callback must tolerate whatever other code passes to http_api_curl
    $bad = [ [ null, [] ], [ 'x', [] ], [ new stdClass, 'not-an-array' ], [ new stdClass, [] ], [ new stdClass, [ 'nppp_varnish_connect_ms' => 0 ] ] ];
    $ok = true; foreach ( $bad as [ $hh, $aa ] ) { try { nppp_varnish_curl_connect_timeout( $hh, $aa ); } catch ( Throwable $t ) { $ok = false; } }
    chk( $ok, 'nppp_varnish_curl_connect_timeout() ignores foreign handles / args (it only acts on its own marker)' );
}

/* ================================================================================================================ */
if ( $S === 'logging' ) {
    global $VT, $OK;
    on( "http://l1.test:6081\nhttp://l2.test:6081", 'X-Control-Key: topsecret' );
    vt_script( '#l1\.test#', [ 'code' => 403, 'body' => '' ] ); vt_script( '#l2\.test#', $OK );
    do_action( 'nppp_purged_urls', [ 'http://example.com/a/', 'http://other.test/x/' ] );
    do_action( 'nppp_purged_urls', [ 'http://example.com/%d9%85/' ] );
    do_action( 'nppp_purged_all', false );
    $rows = $VT['notice_rows'];
    chk( count( $rows ) >= 4, 'the scenario produced several log lines', (string) count( $rows ) );
    chk( count( array_filter( $rows, fn( $r ) => $r[2] === true && $r[3] === false ) ) === count( $rows ), 'EVERY Varnish line is log-only (log=true, show=false): nothing is shown in the admin UI' );
    chk( count( array_filter( $rows, fn( $r ) => ( $r[0] === 'info' && strpos( $r[1], 'INFO VARNISH: ' ) === 0 ) || ( $r[0] === 'warning' && strpos( $r[1], 'WARNING VARNISH: ' ) === 0 ) ) ) === count( $rows ), 'type and prefix agree (info = "INFO VARNISH:", warning = "WARNING VARNISH:")', json_encode( array_map( fn( $r ) => [ $r[0], substr( $r[1], 0, 20 ) ], $rows ) ) );
    chk( leaks( [ 'l1.test', 'l2.test', '6081', 'topsecret', 'X-Control-Key', '127.0.0.1' ] ) === [], 'no endpoint address, port, control header name or value in any line', vt_notes() );
    chk( count( array_filter( $rows, fn( $r ) => strpos( $r[1], 'endpoint #' ) !== false ) ) >= 2, 'endpoints are referred to as "endpoint #N"' );
    chk( count( array_filter( $rows, fn( $r ) => preg_match( '/[\r\n]/', $r[1] ) ) ) === 0, 'no line contains a line break (log-injection safe)' );

    // no log function available at all (compat-varnish.php loaded without NPP's logger): silently fine
    chk( function_exists( 'nppp_varnish_log' ), 'nppp_varnish_log exists' );
}

/* ================================================================================================================ */
if ( $S === 'state' ) {
    global $VT;
    vt_reset();
    chk( nppp_varnish_warn_due( 'http://w.test', 'denied' ) === true,  'first warning of a kind is due' );
    chk( nppp_varnish_warn_due( 'http://w.test', 'denied' ) === false, 'second one inside the window is throttled' );
    chk( nppp_varnish_warn_due( 'http://w.test', 'unreachable' ) === true, 'another kind for the same endpoint is independent' );
    chk( nppp_varnish_warn_due( 'http://w2.test', 'denied' ) === true, 'another endpoint is independent' );
    chk( nppp_varnish_warn_due( 'HTTP://W.TEST', 'denied' ) === false, 'endpoint identity is case-insensitive' );
    $id = nppp_varnish_endpoint_id( 'http://w.test' ) . '-' . substr( md5( 'denied' ), 0, 6 );
    nppp_state_set( 'varnish_warn', [ $id => time() - 1 ], 60 );
    chk( nppp_varnish_warn_due( 'http://w.test', 'denied' ) === true, 'an expired throttle row makes the warning due again' );
    chk( preg_match( '/^[0-9a-f]{12}$/', nppp_varnish_endpoint_id( 'http://w.test' ) ) === 1 && nppp_varnish_endpoint_id( 'http://W.test' ) === nppp_varnish_endpoint_id( 'http://w.test' ), 'endpoint id: 12 hex chars, case-insensitive' );
    chk( nppp_varnish_endpoint_id( 'http://w.test' ) !== nppp_varnish_endpoint_id( 'http://w.test:81' ), 'endpoint id differs per port' );

    vt_reset();
    nppp_varnish_breaker_arm( 'http://w3.test' );
    $row = $VT['options']['nppp_st_varnish_breaker'] ?? [];
    chk( ( $row['exp'] ?? 0 ) >= time() + 298 && ( $row['exp'] ?? 0 ) <= time() + 302, 'breaker row lives ~300 s', json_encode( $row ) );
    chk( ( $row['v'][ nppp_varnish_endpoint_id( 'http://w3.test' ) ] ?? 0 ) >= time() + 298, 'the endpoint deadline is ~5 minutes ahead' );
    chk( ( $VT['autoload']['nppp_st_varnish_breaker'] ?? null ) === false, 'state rows are not autoloaded' );
    nppp_varnish_breaker_arm( 'http://w5.test' );
    chk( count( $VT['options']['nppp_st_varnish_breaker']['v'] ) === 2, 'arming a second endpoint keeps the first one' );
    nppp_state_set( 'varnish_breaker', [ nppp_varnish_endpoint_id( 'http://old.test' ) => time() - 5, nppp_varnish_endpoint_id( 'http://live.test' ) => time() + 100 ], 100 );
    nppp_varnish_breaker_arm( 'http://w6.test' );
    chk( count( $VT['options']['nppp_st_varnish_breaker']['v'] ) === 2, 'expired rows are dropped when the map is rewritten (no growth)', json_encode( array_keys( $VT['options']['nppp_st_varnish_breaker']['v'] ) ) );
    // legacy transients are untouched by this feature (it never existed before 2.1.9)
    chk( $VT['transients'] === [], 'the Varnish sync never writes a transient' );
}

/* ================================================================================================================ */
if ( $S === 'logreason' ) {
    // with purge-http.php loaded the compat file delegates to nppp_http_purge_log_reason()
    chk( function_exists( 'nppp_http_purge_log_reason' ), 'purge-http.php is loaded in this section' );
    foreach ( [ 'cURL error 7: Failed to connect to h port 80: Connection refused' => 'connection refused',
                'cURL error 6: Could not resolve host: cache.internal' => 'DNS failure',
                'cURL error 60: SSL certificate problem: self-signed certificate' => 'TLS failure',
                'cURL error 28: Operation timed out after 3001 milliseconds with 0 bytes received' => 'connection timed out',
                'cURL error 28: Connection timeout after 1000 ms' => 'connection timed out',
                'cURL error 7: Failed to connect to h port 80: Network is unreachable' => 'network unreachable',
                'something nobody has seen' => 'transport error', '' => 'transport error' ] as $msg => $want ) {
        $got = nppp_varnish_log_reason( $msg ); chk( $got === $want, 'reason for ' . json_encode( $msg ), "got '$got' want '$want'" );
    }
    // libcurl without the errno text: 7.7x - 8.5 say "Couldn't connect to server", 8.14 says "Could not connect to server"
    foreach ( [ "Couldn't connect to server", 'Could not connect to server' ] as $w ) {
        $got = nppp_varnish_log_reason( "cURL error 7: Failed to connect to 127.0.0.1 port 1 after 0 ms: $w" );
        known( $got === 'transport error', "libcurl words a closed port \"$w\" (no \"refused\"): it is logged as \"transport error\" (cosmetic; the message also covers other connect failures, so it is not mapped to \"refused\")", "got '$got'" );
    }
    foreach ( [ 'cURL error 7: Failed to connect to h port 80: Connection refused', 'Could not resolve host: x.internal', 'cURL error 28: ...' ] as $msg ) {
        $r = nppp_varnish_log_reason( $msg ); chk( ! preg_match( '/h port|x\.internal|[0-9]+\.[0-9]+\.[0-9]+|port \d+/', $r ), 'the reason never carries host / port / IP text', $r );
    }
}

/* ================================================================================================================ */
if ( $S === 'fallback' ) {   // compat-varnish.php WITHOUT purge-http.php: its own fallbacks must behave the same
    global $VT, $OK;
    chk( ! function_exists( 'nppp_http_purge_authority' ) && ! function_exists( 'nppp_http_purge_log_reason' ) && ! function_exists( 'nppp_http_purge_path_has_dot_segment' ), 'purge-http.php helpers are NOT loaded in this section' );
    foreach ( [ 'http://example.com' => 'example.com', 'https://example.com:443' => 'example.com', 'http://example.com:8080' => 'example.com:8080', 'http://[::1]:8080' => '[::1]:8080', 'HTTP://EXAMPLE.com/' => 'example.com', '/relative' => '' ] as $u => $want ) {
        $got = nppp_varnish_authority( $u ); chk( $got === $want, "fallback authority of $u = '$want'", "got '$got'" );
    }
    vt_reset(); vt_home( 'http://example.com' );
    $C = fn( $u ) => nppp_varnish_classify_url( $u );
    chk( $C( 'http://example.com/a/?x=1' ) === [ 'action' => 'single', 'path' => '/a/?x=1', 'reason' => '' ], 'fallback: single URL' );
    foreach ( [ 'http://example.com/a/%252e%252e/b', 'http://example.com/a/..%2fb', 'http://example.com/a/%2e%2e/b', 'http://example.com/a/%252525252e/b' ] as $u ) {
        $c = $C( $u ); chk( $c['action'] === 'all', "fallback dot-segment guard sees through repeated decoding: $u", json_encode( $c ) );
    }
    chk( $C( 'http://evil.test/a/' )['action'] === 'skip', 'fallback: foreign host skipped' );
    foreach ( [ 'cURL error 7: Failed to connect: Connection refused' => 'connection refused', 'Could not resolve host: x' => 'DNS failure', 'SSL certificate problem' => 'TLS failure', 'Operation timed out' => 'connection timed out', 'zzz' => 'transport error' ] as $msg => $want ) {
        $got = nppp_varnish_log_reason( $msg ); chk( $got === $want, "fallback reason for " . json_encode( $msg ), "got '$got'" );
    }
    $got = nppp_varnish_log_reason( 'Network is unreachable' );
    known( $got === 'transport error', 'the built-in fallback has no "network unreachable" rule (purge-http.php\'s own mapping does); production always loads both', "got '$got'" );

    on( 'http://fb1.test' ); vt_script( '#.#', $OK ); do_action( 'nppp_purged_urls', [ 'http://example.com/a/', 'http://example.com/%d9%85/' ] ); do_action( 'nppp_purged_all', false );
    chk( urls() === [ 'PURGE http://fb1.test/.*', 'PURGE http://fb1.test/.*' ], 'fallback: the whole chain (escalation, Purge All) works without purge-http.php', json_encode( urls() ) );
    on( 'http://fb2.test' ); vt_script( '#.#', $OK ); do_action( 'nppp_purged_urls', [ 'http://example.com/a/' ] );
    chk( urls() === [ 'PURGE http://fb2.test/a/' ] && reqs()[0]['headers']['Host'] === 'example.com', 'fallback: single purge + Host header' );
}

/* ================================================================================================================ */
if ( $S === 'transport' ) {   // real sockets, no script
    global $VT;
    // what does THIS libcurl say for a closed port? (older: "... Connection refused", 7.7x - 8.5: "Couldn't connect to server",
    // >= 8.1x: "Could not connect to server" - the apostrophe form was reworded, so match both)
    $raw    = vt_real_request( 'http://127.0.0.1:1/', [ 'method' => 'PURGE', 'timeout' => 2 ] );
    $rawmsg = is_wp_error( $raw ) ? $raw->get_error_message() : '';
    $reason = stripos( $rawmsg, 'refused' ) !== false ? 'connection refused' : 'transport error';
    on( 'http://127.0.0.1:1' ); $t = microtime( true ); do_action( 'nppp_purged_urls', [ 'http://example.com/a/' ] ); $el = microtime( true ) - $t;
    chk( strpos( vt_notes(), "unreachable ($reason)" ) !== false && $el < 2.0, "real closed port: warning with reason '$reason', fast", sprintf( '%.2fs %s | libcurl said: %s', $el, vt_notes(), $rawmsg ) );
    if ( $reason === 'transport error' ) {
        known( preg_match( "/could(?:n't| not) connect/i", $rawmsg ) === 1, 'this libcurl ' . curl_version()['version'] . ' does not say "refused" for a closed port, so the log shows "transport error" (cosmetic)', $rawmsg );
    }
    chk( nppp_varnish_breaker_open( 'http://127.0.0.1:1' ), 'real failure arms the breaker' );
    // blackhole: the 1 s connect timeout must cut a SYN that is never answered (3 s total would be the WordPress default)
    on( 'http://10.255.255.1:6081' ); $t = microtime( true ); do_action( 'nppp_purged_urls', [ 'http://example.com/a/' ] ); $el = microtime( true ) - $t;
    if ( $el < 0.3 ) { skip( "blackhole connect-timeout check (network answers 'unreachable' immediately: " . sprintf( '%.2fs', $el ) . ')' ); }
    else { chk( $el < 1.9, 'connect timeout 1 s applied to the real curl handle through http_api_curl', sprintf( '%.2fs', $el ) ); }
    // DNS failure on a real lookup (.invalid is reserved and never resolves)
    on( 'http://no-such-host.invalid' ); $t = microtime( true ); do_action( 'nppp_purged_urls', [ 'http://example.com/a/' ] ); $el = microtime( true ) - $t;
    chk( strpos( vt_notes(), 'unreachable (DNS failure)' ) !== false && leaks( [ 'no-such-host' ] ) === [], 'real DNS failure: coarse reason, host name not logged', sprintf( '%.2fs %s', $el, vt_notes() ) );
}
if ( $S === 'hang' ) {         // accepts the connection and never answers
    global $VT;
    $probe = stream_socket_server( 'tcp://127.0.0.1:0' ); $port = (int) substr( strrchr( stream_socket_get_name( $probe, false ), ':' ), 1 ); fclose( $probe );
    $p = proc_open( [ 'python3', '-c', "import socket,time\ns=socket.socket();s.setsockopt(socket.SOL_SOCKET,socket.SO_REUSEADDR,1);s.bind(('127.0.0.1',$port));s.listen(8)\nc=[]\nwhile True:\n  x,_=s.accept();c.append(x);time.sleep(0)" ], [ 1 => [ 'file', '/dev/null', 'w' ], 2 => [ 'file', '/dev/null', 'w' ] ], $pipes );
    usleep( 400000 );
    on( "http://127.0.0.1:$port" ); $t = microtime( true ); do_action( 'nppp_purged_urls', [ 'http://example.com/a/', 'http://example.com/b/', 'http://example.com/c/' ] ); $el = microtime( true ) - $t;
    chk( $el >= 2.8 && $el <= 4.2, 'hanging Varnish costs ONE 3 s timeout, not 3 x 3 s (breaker parks it after the first)', sprintf( '%.2fs', $el ) );
    chk( count( reqs() ) === 1 && strpos( vt_notes(), 'connection timed out' ) !== false, 'only the first URL was attempted; reason "connection timed out"', vt_notes() );
    proc_terminate( $p ); proc_close( $p );
}

/* ================================================================================================================ */
if ( $S === 'source' ) {   // static guarantees about the chain, checked against the plugin sources
    $d = vt_includes();
    $p = file_get_contents( $d . '/purge.php' );
    preg_match( '/function nppp_purge_post_purge\(.*?\n}\n/s', $p, $m ); $body = $m[0] ?? '';
    $hook = strpos( $body, "'nppp_purged_urls'" ); $pre1 = strpos( $body, 'nppp_preload_cache_on_update(' ); $pre2 = strpos( $body, 'nppp_preload_urls_fire_and_forget(' );
    chk( $hook !== false && $pre1 !== false && $pre2 !== false && $hook < $pre1 && $hook < $pre2, 'single purge: nppp_purged_urls fires BEFORE the primary preload and the related preload' );
    chk( substr_count( $p, "'nppp_purged_urls'" ) === 1, 'nppp_purged_urls has exactly one fire point in purge.php' );
    chk( preg_match( '/return;[^}]*?do_action\(\s*\'nppp_purged_urls\'/s', substr( $body, 0, $hook ) ) === 0, 'no early return between the start of nppp_purge_post_purge and the hook' );
    // Purge All: hook before the auto-preload call
    $pa = file_get_contents( $d . '/purge.php' ); $h1 = strpos( $pa, 'nppp_http_purge_fire_purged_all(isset($status)' ); $pl = strpos( $pa, "nppp_preload(\$nginx_cache_path, \$this_script_path, \$tmp_path, \$fdomain, \$PIDFILE, \$nginx_cache_reject_regex, \$nginx_cache_limit_rate, \$nginx_cache_cpu_limit, true" );
    chk( $h1 !== false && $pl !== false && $h1 < $pl, 'Purge All: nppp_purged_all (or its deferral) happens before the auto-preload starts' );
    $pr = file_get_contents( $d . '/preload.php' ); $a = strpos( $pr, "do_action( 'nppp_preload_all_started' )" ); $b = strpos( $pr, '$output = shell_exec($command);' );
    chk( $a !== false && $b !== false && $a < $b, 'Preload All: nppp_preload_all_started fires before the crawl command is executed' );
    chk( substr_count( $pr, "nppp_preload_all_started" ) === 2 /* comment + do_action */ || substr_count( $pr, "do_action( 'nppp_preload_all_started' )" ) === 1, 'nppp_preload_all_started has one fire point' );
    $v = file_get_contents( $d . '/status.php' ); chk( strpos( $v, "nppp_state_delete( 'varnish_breaker' )" ) !== false && strpos( $v, "nppp_state_delete( 'varnish_warn' )" ) !== false, 'Clear Plugin Cache resets the Varnish breaker and warning throttle' );
    foreach ( [ 'settings-sanitize.php', 'settings-ajax.php', 'wp-cli.php' ] as $f ) {
        $s = file_get_contents( $d . '/' . $f );
        chk( strpos( $s, "nppp_state_delete( 'varnish_breaker' )" ) !== false && strpos( $s, "nppp_state_delete( 'varnish_warn' )" ) !== false, "$f resets the breaker and the warning throttle when switch / endpoints / header change" );
    }
    $lc = file_get_contents( $d . '/settings-lifecycle.php' );
    chk( preg_match( "/'nppp_varnish_sync_enabled'\s*=>\s*'no'/", $lc ) === 1 && preg_match( "/'nppp_varnish_endpoints'\s*=>\s*''/", $lc ) === 1 && preg_match( "/'nppp_varnish_header'\s*=>\s*''/", $lc ) === 1, 'defaults: switch "no", endpoints "", header "" (feature is OFF on a fresh install)' );
    $un = file_get_contents( dirname( $d ) . '/uninstall.php' );
    chk( strpos( $un, "esc_like('nppp_st_')" ) !== false, 'uninstall.php removes every nppp_st_* row (the Varnish breaker / throttle rows included)' );
    // the contract in the file header is what the listeners are wired to
    $c = file_get_contents( $d . '/compat-varnish.php' );
    chk( strpos( $c, "add_action( 'nppp_purged_urls', 'nppp_varnish_purge_urls', 10, 4 );" ) !== false && strpos( $c, "add_action( 'nppp_purged_all', 'nppp_varnish_purge_all', 10, 1 );" ) !== false && strpos( $c, "add_action( 'nppp_preload_all_started', 'nppp_varnish_purge_all', 10, 0 );" ) !== false, 'the three add_action() lines are exactly the documented wiring' );
    chk( strpos( $c, 'nppp_varnish_run_purge_all' ) !== false && substr_count( $c, "'/.*', 'regex'" ) === 1, 'the host-wide purge body is the single fixed literal "/.*" (nothing URL-derived reaches the regex)' );
    chk( preg_match( '/\$[a-z_]+\s*\.\s*\'\/\.\*\'|\'\/\.\*\'\s*\.\s*\$/', $c ) === 0, 'no code path concatenates anything onto the "/.*" pattern' );
    chk( substr_count( $c, 'wp_remote_request(' ) === 1, 'compat-varnish.php has exactly one HTTP call site (nppp_varnish_send)' );
    chk( strpos( $c, "'redirection'             => 0" ) !== false, 'redirects are disabled on the PURGE request' );
    // load order: compat files must be required so that the Varnish listener sits in the intended place relative to Redis / Cloudflare
    $m = file_get_contents( dirname( $d ) . '/admin/fastcgi-cache-purge-and-preload-nginx-admin.php' );
    preg_match_all( "#includes/(compat-[a-z\-]+|purge-http|purge|preload|state)\.php#", $m, $mm ); $order = array_values( array_unique( $mm[1] ) );
    chk( in_array( 'compat-varnish', $order, true ), 'compat-varnish.php is loaded by the admin bootstrap', json_encode( $order ) );
    chk( array_search( 'state', $order, true ) < array_search( 'compat-varnish', $order, true ), 'state.php is loaded before compat-varnish.php', json_encode( $order ) );
    echo "INFO include order: " . implode( ' > ', $order ) . "\n";
}
exit( vt_summary() );