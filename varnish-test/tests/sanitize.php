<?php
/**
 * Layer 1b - Settings save path for the Varnish / Vinyl Cache fields.   php tests/sanitize.php
 *
 * nppp_nginx_cache_settings_sanitize() is a 600-line function that also validates the cache path, proxy hosts and
 * dozens of other settings, so it can not run on a small stub. Instead this test cuts the TWO Varnish blocks out of
 * includes/settings-sanitize.php (between fixed marker comments) and runs exactly that source text:
 *
 *   block A  "// Varnish / Vinyl Cache Sync" ... "// Sanitize bypass path restriction"   switch, endpoints, header
 *   block B  "// Varnish / Vinyl Cache Sync: a changed switch ..." ... "return $sanitized_input;"   breaker reset
 *
 * If a marker disappears (refactor) the test FAILS loudly instead of silently testing nothing.
 */
require __DIR__ . '/../lib/wpstub.php';
vt_load( true );

$src = file_get_contents( vt_includes() . '/settings-sanitize.php' );
function cut( string $src, string $from, string $to ): string {
    $a = strpos( $src, $from );
    if ( $a === false ) { chk( false, "marker found: " . json_encode( $from ) ); return ''; }
    $b = strpos( $src, $to, $a );
    if ( $b === false ) { chk( false, "end marker found: " . json_encode( $to ) ); return ''; }
    return substr( $src, $a, $b - $a );
}
$blockA = cut( $src, "// Varnish / Vinyl Cache Sync\n    // Toggle.", '// Sanitize bypass path restriction' );
$blockB = cut( $src, '// Varnish / Vinyl Cache Sync: a changed switch', 'return $sanitized_input;' );
if ( $blockA === '' || $blockB === '' ) { exit( vt_summary() ); }

// the function under test = the plugin's own text, wrapped
eval( 'function vt_sanitize_varnish( array $input, array $nppp_prev_settings ): array { $sanitized_input = [];' . "\n" . $blockA . "\n" . $blockB . "\nreturn \$sanitized_input; }" );

function san( array $input, array $stored = [] ): array {
    vt_reset();
    if ( $stored ) { vt_set( $stored ); }
    $prev = get_option( 'nginx_cache_settings', [] );
    return vt_sanitize_varnish( $input, is_array( $prev ) ? $prev : [] );
}
function errs(): array { return array_map( fn( $e ) => $e[1] . ' | ' . $e[2], $GLOBALS['VT']['settings_errors'] ); }

/* ---- switch ------------------------------------------------------------------------------------------------- */
$o = san( [ 'nppp_varnish_sync_enabled' => 'yes' ] );   chk( $o['nppp_varnish_sync_enabled'] === 'yes', 'switch "yes" is saved as yes' );
$o = san( [ 'nppp_varnish_sync_enabled' => 'no' ] );    chk( $o['nppp_varnish_sync_enabled'] === 'no', 'switch "no" is saved as no' );
$o = san( [] );                                         chk( $o['nppp_varnish_sync_enabled'] === 'no', 'switch missing (unchecked checkbox is not submitted) = no' );
foreach ( [ 'YES', 'true', '1', 'on', [ 'yes' ], true ] as $v ) {
    $o = san( [ 'nppp_varnish_sync_enabled' => $v ] );  chk( $o['nppp_varnish_sync_enabled'] === 'no', 'switch value ' . json_encode( $v ) . ' = no (only the exact string "yes" turns it on)' );
}

/* ---- endpoints ---------------------------------------------------------------------------------------------- */
$o = san( [ 'nppp_varnish_endpoints' => "127.0.0.1:6081,  HTTP://Cache.Local:80\n[::1]:6081" ] );
chk( $o['nppp_varnish_endpoints'] === "http://127.0.0.1:6081\nhttp://cache.local:80\nhttp://[::1]:6081" && errs() === [], 'valid list is stored normalized, one per line, no error', json_encode( [ $o['nppp_varnish_endpoints'], errs() ] ) );

$o = san( [ 'nppp_varnish_endpoints' => "http://ok.test\r\nhttp://ok2.test\r\n" ] );
chk( $o['nppp_varnish_endpoints'] === "http://ok.test\nhttp://ok2.test", 'CRLF textarea input becomes clean LF lines', json_encode( $o['nppp_varnish_endpoints'] ) );

foreach ( [ 'ftp://h' => 'only http:// and https://', 'http://u:p@h' => 'user name / password', 'http://h/x' => 'without a path', 'http://h:0' => 'invalid port', '-bad.example' => 'invalid host', 'http://' => 'invalid value' ] as $in => $label ) {
    $o = san( [ 'nppp_varnish_endpoints' => $in ], [ 'nppp_varnish_endpoints' => 'http://stored.test' ] );
    $e = errs();
    chk( $o['nppp_varnish_endpoints'] === '' && count( $e ) === 1 && strpos( $e[0], 'nppp-varnish-endpoints' ) === 0 && strpos( $e[0], $label ) !== false && strpos( $e[0], 'Settings were not saved' ) !== false,
         'invalid ' . json_encode( $in ) . ': value cleared (never stored) + one settings error naming the reason', json_encode( [ $o['nppp_varnish_endpoints'], $e ] ) );
}
$o = san( [ 'nppp_varnish_endpoints' => "ok.test\nftp://bad.test" ] );
chk( $o['nppp_varnish_endpoints'] === '' && count( errs() ) === 1, 'one bad line rejects the WHOLE list (strict): nothing half-valid is stored' );
$o = san( [ 'nppp_varnish_endpoints' => "a.test b.test c.test d.test e.test f.test" ] );
chk( $o['nppp_varnish_endpoints'] === '' && strpos( errs()[0] ?? '', 'at most 5' ) !== false, 'six endpoints: rejected with "at most 5 endpoints"', json_encode( errs() ) );
$o = san( [ 'nppp_varnish_endpoints' => 'http://ok.test<script>alert(1)</script>' ] );
chk( ! preg_match( '/<|>|script/i', $o['nppp_varnish_endpoints'] ), 'markup in the textarea is stripped (sanitize_textarea_field) before parsing', json_encode( $o['nppp_varnish_endpoints'] ) );

$o = san( [ 'nppp_varnish_endpoints' => '' ], [ 'nppp_varnish_endpoints' => 'http://stored.test' ] );
chk( $o['nppp_varnish_endpoints'] === '' && errs() === [], 'an EMPTY submitted value clears the list (no error, the stored value is not resurrected)' );
$o = san( [ 'nppp_varnish_endpoints' => "  \n " ], [ 'nppp_varnish_endpoints' => 'http://stored.test' ] );
chk( $o['nppp_varnish_endpoints'] === '' && errs() === [], 'a whitespace-only value clears the list as well' );
$o = san( [], [ 'nppp_varnish_endpoints' => 'http://stored.test' ] );
chk( $o['nppp_varnish_endpoints'] === 'http://stored.test' && errs() === [], 'field NOT submitted: the stored list is kept (switch OFF hides the field, the addresses stay)' );
$o = san( [ 'nppp_varnish_endpoints' => [ 'x' ] ], [ 'nppp_varnish_endpoints' => 'http://stored.test' ] );
chk( $o['nppp_varnish_endpoints'] === 'http://stored.test', 'non-string value (array injection) is treated as "not submitted": stored list kept' );
$o = san( [] );
chk( $o['nppp_varnish_endpoints'] === '' , 'nothing submitted, nothing stored: empty' );

/* ---- control header ------------------------------------------------------------------------------------------ */
$o = san( [ 'nppp_varnish_header' => 'X-Control-Key: s3cret' ] );  chk( $o['nppp_varnish_header'] === 'X-Control-Key: s3cret' && errs() === [], 'valid header stored as "Name: value"' );
$o = san( [ 'nppp_varnish_header' => "  X-Key   :    spaced  " ] ); chk( $o['nppp_varnish_header'] === 'X-Key: spaced', 'header is normalized (trimmed, single ": ")', json_encode( $o['nppp_varnish_header'] ) );
$o = san( [ 'nppp_varnish_header' => 'X-Key: p%41ss%20word' ] );    chk( $o['nppp_varnish_header'] === 'X-Key: p%41ss%20word', 'a secret containing %XX is stored UNCHANGED (no sanitize_text_field octet stripping)', json_encode( $o['nppp_varnish_header'] ) );
foreach ( [ "X-A: b\r\nX-B: c" => 'line breaks', 'X-Control-Key' => 'Header-Name: value', 'Host: evil' => 'reserved', 'X-Purge-Method: regex' => 'reserved', 'X K: v' => 'letters, numbers and hyphens', 'X-K: ünï' => 'printable ASCII' ] as $in => $label ) {
    $o = san( [ 'nppp_varnish_header' => $in ], [ 'nppp_varnish_header' => 'X-Stored: 1' ] ); $e = errs();
    chk( $o['nppp_varnish_header'] === '' && count( $e ) === 1 && strpos( $e[0], 'nppp-varnish-header' ) === 0 && stripos( $e[0], $label ) !== false, 'invalid header ' . json_encode( $in ) . ': cleared + error naming the reason', json_encode( [ $o['nppp_varnish_header'], $e ] ) );
}
chk( strpos( errs()[0] ?? '', 'X-K' ) === false, 'the settings error never echoes the submitted header (it may hold the secret)' );
$o = san( [ 'nppp_varnish_header' => 'X-Key: topsecret-value!' ] ); $x = san( [ 'nppp_varnish_header' => "X-Key: topsecret\x01" ] );
chk( strpos( implode( ' ', errs() ), 'topsecret' ) === false, 'the secret value never appears in a settings error' );
$o = san( [ 'nppp_varnish_header' => '' ], [ 'nppp_varnish_header' => 'X-Stored: 1' ] );  chk( $o['nppp_varnish_header'] === '' && errs() === [], 'EMPTY header submitted clears it' );
$o = san( [], [ 'nppp_varnish_header' => 'X-Stored: 1' ] );                                chk( $o['nppp_varnish_header'] === 'X-Stored: 1', 'header not submitted: stored value is kept' );
$o = san( [ 'nppp_varnish_header' => [ 'x' ] ], [ 'nppp_varnish_header' => 'X-Stored: 1' ] ); chk( $o['nppp_varnish_header'] === 'X-Stored: 1', 'non-string header value = not submitted: stored value kept' );

/* ---- breaker / throttle reset on a change -------------------------------------------------------------------- */
function seed_state(): void { nppp_state_set( 'varnish_breaker', [ 'abc' => time() + 200 ], 300 ); nppp_state_set( 'varnish_warn', [ 'abc-1' => time() + 200 ], 300 ); }
function state_left(): array { return [ nppp_state_get( 'varnish_breaker' ) !== null, nppp_state_get( 'varnish_warn' ) !== null ]; }
$same = [ 'nppp_varnish_sync_enabled' => 'yes', 'nppp_varnish_endpoints' => 'http://a.test', 'nppp_varnish_header' => 'X-K: v' ];

vt_reset(); vt_set( $same ); seed_state(); vt_sanitize_varnish( $same, get_option( 'nginx_cache_settings' ) );
chk( state_left() === [ true, true ], 'saving the form with NOTHING changed keeps the parked-endpoint state and the warning throttle' );
foreach ( [ 'nppp_varnish_sync_enabled' => 'no', 'nppp_varnish_endpoints' => 'http://b.test', 'nppp_varnish_header' => 'X-K: other' ] as $k => $new ) {
    vt_reset(); vt_set( $same ); seed_state(); vt_sanitize_varnish( [ $k => $new ] + $same, get_option( 'nginx_cache_settings' ) );
    chk( state_left() === [ false, false ], "changing $k clears the breaker AND the throttle (a fixed setup is retried at once)", json_encode( state_left() ) );
}
vt_reset(); vt_set( $same ); seed_state(); vt_sanitize_varnish( [ 'nppp_varnish_endpoints' => 'http://a.test' ] + $same, get_option( 'nginx_cache_settings' ) );
chk( state_left() === [ true, true ], 'a re-saved but identical (re-normalized) endpoint list does not reset the state' );

/* ---- the wiring the other save paths rely on ---------------------------------------------------------------- */
// The form handler aborts a save when add_settings_error() was raised (sanitized value is '' so nothing invalid can be stored):
$o = san( [ 'nppp_varnish_endpoints' => 'ftp://x', 'nppp_varnish_header' => 'Host: x' ] );
chk( count( errs() ) === 2 && $o['nppp_varnish_endpoints'] === '' && $o['nppp_varnish_header'] === '', 'both fields invalid: two errors, both values cleared' );
chk( array_unique( array_map( fn( $e ) => $e[0], $GLOBALS['VT']['settings_errors'] ) ) === [ 'nppp_nginx_cache_settings_group' ] && array_unique( array_map( fn( $e ) => $e[3], $GLOBALS['VT']['settings_errors'] ) ) === [ 'error' ], 'errors belong to the settings group and have type "error"' );

exit( vt_summary() );
