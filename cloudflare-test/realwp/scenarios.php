<?php
/**
 * Real-WordPress scenarios. Run:  wp eval-file scenarios.php   (see run-realwp.sh)
 *
 * REAL: WordPress core (WP_Http / Requests / cURL / TLS, $wpdb, options, wp-cron), the official Cloudflare plugin
 *       (its DataStore, settings and Client::beforeSend()), includes/state.php, includes/compat-cloudflare.php.
 * MOCK: only api.cloudflare.com (mock-cf.py, local CA). See mock-cf.py for what it models.
 */
$GLOBALS['PASS'] = 0; $GLOBALS['FAIL'] = 0; $GLOBALS['KNOWN'] = array();
function ok( $c, $n ) { if ( $c ) { $GLOBALS['PASS']++; echo "  ok   $n\n"; } else { $GLOBALS['FAIL']++; echo "  FAIL $n\n"; } }
function known( $c, $n ) { if ( $c ) { echo "  ok   $n\n"; $GLOBALS['PASS']++; } else { $GLOBALS['KNOWN'][] = $n; echo "  KNOWN-ISSUE $n\n"; } }
function section( $n ) { echo "\n== $n\n"; }

const CTL = 'http://127.0.0.1:18080';
function ctl( $path, $body = null ) {
    $o = array( 'http' => array( 'method' => $body === null ? 'GET' : 'POST', 'header' => "Content-Type: application/json\r\n", 'content' => $body === null ? '' : json_encode( $body ), 'timeout' => 5 ) );
    return json_decode( (string) file_get_contents( CTL . $path, false, stream_context_create( $o ) ), true );
}
function mk_reset() { ctl( '/reset', array() ); }
function mk_cfg( array $c ) { ctl( '/config', $c ); }
function mk_script( array $s ) { ctl( '/script', $s ); }
function mk_log( $kind = null ) { $l = ctl( '/log' ); return $kind ? array_values( array_filter( $l, function ( $e ) use ( $kind ) { return $e['kind'] === $kind; } ) ) : $l; }
function notices() { return is_file( NPPP_H_NOTICES ) ? file( NPPP_H_NOTICES, FILE_IGNORE_NEW_LINES ) : array(); }
function has_notice( $re ) { return count( array_filter( notices(), function ( $l ) use ( $re ) { return preg_match( $re, $l ); } ) ) > 0; }
function body_of( $e ) { return json_decode( $e['raw'], true ); }

const TOKEN = 'cfut_aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaab3c4d5e6';
function reset_all( array $o = array() ) {
    nppp_cloudflare_apo_cleanup();
    @unlink( NPPP_H_NOTICES );
    mk_reset();
    update_option( 'nginx_cache_settings', array( 'nppp_cloudflare_apo_sync' => 'yes' ) );
    update_option( 'cloudflare_api_key', $o['key'] ?? TOKEN );
    update_option( 'cloudflare_api_email', 'owner@example.com' );
    update_option( 'cloudflare_cached_domain_name', 'example.com' );
    update_option( 'automatic_platform_optimization', array( 'value' => 'on' ) );
    if ( empty( $o['device'] ) ) { delete_option( 'automatic_platform_optimization_cache_by_device_type' ); }
    else { update_option( 'automatic_platform_optimization_cache_by_device_type', array( 'value' => 'on' ) ); }
    delete_option( 'nppp_h_trust_ca' );
    wp_cache_flush();
}
function fire_urls( array $u ) { do_action( 'nppp_purged_urls', $u, $u[0] ?? '', 1, true ); }
function nurls( $n, $len = 0 ) { $u = array(); for ( $i = 0; $i < $n; $i++ ) { $u[] = "http://example.com/p$i/" . ( $len ? str_repeat( 'x', $len ) : '' ); } return $u; }
$HOOK = NPPP_CF_APO_RETRY_HOOK;
/** What wp-cron.php does: take the event out of the cron array first, then run the hook. */
function run_cron( $h ) { foreach ( (array) _get_cron_array() as $ts => $hooks ) { if ( isset( $hooks[ $h ] ) ) { foreach ( $hooks[ $h ] as $k => $ev ) { wp_unschedule_event( $ts, $h, $ev['args'] ); } } } do_action( $h ); }
function cron_count( $h ) { $n = 0; foreach ( (array) _get_cron_array() as $ts => $hooks ) { if ( isset( $hooks[ $h ] ) ) { $n += count( $hooks[ $h ] ); } } return $n; }

echo "WordPress " . $GLOBALS['wp_version'] . ", PHP " . PHP_VERSION . ", Cloudflare plugin dir " . CLOUDFLARE_PLUGIN_DIR . "\n";
echo "transport: " . ( function_exists( 'curl_init' ) ? 'cURL' : 'no cURL' ) . ", mock reachable: " . ( ctl( '/log' ) !== null ? 'yes' : 'NO' ) . "\n";

section( 'happy path over the real WP HTTP stack (cURL + TLS)' );
reset_all();
fire_urls( array( 'http://example.com/a/', 'http://example.com/b/?x=1' ) );
$z = mk_log( 'zones' ); $p = mk_log( 'purge' );
ok( count( $z ) === 1 && $z[0]['method'] === 'GET' && $z[0]['query'] === array( 'name' => 'example.com', 'status' => 'active' ), 'one GET /zones?name=example.com&status=active' );
ok( count( $p ) === 1 && $p[0]['method'] === 'POST' && $p[0]['status'] === 200, 'one POST purge_cache, mock answered 200 (body validated by the mock)' );
ok( body_of( $p[0] ) === array( 'files' => array( 'http://example.com/a/', 'http://example.com/b/?x=1' ) ), 'files body' );
ok( ( $p[0]['headers']['Authorization'] ?? '' ) === 'Bearer ' . TOKEN, 'Authorization: Bearer from the REAL official Client::beforeSend()' );
ok( strpos( $p[0]['headers']['Content-Type'] ?? '', 'application/json' ) === 0, 'Content-Type application/json' );
ok( strpos( $p[0]['headers']['User-Agent'] ?? '', 'cloudflare-wordpress-plugin/4.14.5' ) !== false, 'User-Agent set by the official client (' . ( $p[0]['headers']['User-Agent'] ?? '?' ) . ')' );
ok( ! in_array( 'DELETE', array_column( mk_log(), 'method' ), true ), 'no DELETE ever sent' );
ok( has_notice( '/success: Cloudflare cache purge completed: 2 URL\(s\) in 1 request\(s\)/' ), 'success notice' );
ok( ( nppp_state_get( 'cf_last', true )['ok'] ?? false ) === true, 'cf_last state row written through real options table' );
$raw = json_encode( array( notices(), get_option( 'nginx_cache_settings' ) ) );
ok( strpos( $raw, TOKEN ) === false, 'token not in notices' );
ok( nppp_cloudflare_apo_status()['state'] === 'ready' && nppp_cloudflare_apo_status()['zone'] === 'example.com', 'status ready, zone cached' );

section( 'zone cache reused; purge everything' );
fire_urls( array( 'http://example.com/c/' ) );
ok( count( mk_log( 'zones' ) ) === 1 && count( mk_log( 'purge' ) ) === 2, 'second purge: no second GET /zones' );
do_action( 'nppp_purged_all', false );
$p = mk_log( 'purge' ); ok( count( $p ) === 3 && body_of( $p[2] ) === array( 'purge_everything' => true ), 'purge_everything body {"purge_everything":true}' );

section( 'cache by device type: mobile request shape (headers must be a JSON object)' );
reset_all( array( 'device' => true ) );
fire_urls( array( 'http://example.com/a/' ) );
$p = mk_log( 'purge' );
ok( count( $p ) === 2 && $p[0]['status'] === 200 && $p[1]['status'] === 200, 'desktop + mobile requests both accepted by the schema-checking mock' );
ok( strpos( $p[1]['raw'], '"headers":{"CF-Device-Type":"mobile"}' ) !== false, 'raw JSON: "headers":{"CF-Device-Type":"mobile"}' );

section( 'global API key (legacy hex) goes out as X-Auth-Email / X-Auth-Key' );
$gk = str_repeat( 'a1', 18 ) . 'f';
reset_all( array( 'key' => $gk ) ); mk_cfg( array( 'global_keys' => array( 'owner@example.com' => $gk ) ) );
fire_urls( array( 'http://example.com/a/' ) );
$p = mk_log( 'purge' ); ok( count( $p ) === 1 && $p[0]['status'] === 200 && ! isset( $p[0]['headers']['Authorization'] ) && ( $p[0]['headers']['X-Auth-Key'] ?? '' ) === $gk, 'accepted with X-Auth-* and no Authorization header' );

section( 'chunking against the documented per-request limit (100)' );
reset_all(); mk_cfg( array( 'max_files' => 100 ) );
fire_urls( nurls( 250 ) );
$s = array_column( mk_log( 'purge' ), 'status' );
ok( $s === array( 200, 200, 200 ), '250 URLs -> 3 requests, all 200 (limit 100)' );

section( 'RISK: Cloudflare docs disagree on the free-plan limit (100 vs 30 URLs/request)' );
reset_all(); mk_cfg( array( 'max_files' => 30 ) );
fire_urls( nurls( 250 ) );
$s = array_column( mk_log( 'purge' ), 'status' );
echo "  mock limit 30, 250 URLs -> statuses sent: " . json_encode( $s ) . ", pending=" . json_encode( nppp_state_get( 'cf_pending', true ) ) . "\n";
known( count( array_filter( $s, function ( $x ) { return $x === 200; } ) ) > 0, 'with a 30-URL limit the purge still makes progress (chunks are not all lost)' );

section( 'RISK: one 400 chunk (URL > 4096 chars) vs the rest of the job' );
reset_all();
$u = nurls( 250 ); $u[5] .= str_repeat( 'y', 4200 );
fire_urls( $u );
$s = array_column( mk_log( 'purge' ), 'status' );
echo "  statuses: " . json_encode( $s ) . ", pending=" . json_encode( nppp_state_get( 'cf_pending', true ) ) . "\n";
known( count( $s ) === 3, 'valid chunks after the rejected one are still sent' );

section( '429 + Retry-After on the real stack: park, pending, ONE real cron event' );
reset_all(); mk_script( array( 'match' => 'purge', 'status' => 429, 'headers' => array( 'Retry-After' => '90' ), 'body' => array( 'success' => false, 'errors' => array( array( 'code' => 971, 'message' => 'rl' ) ), 'result' => null ), 'times' => 1 ) );
fire_urls( array( 'http://example.com/a/', 'http://example.com/b/' ) );
$until = nppp_cloudflare_apo_park_until();
ok( $until >= time() + 85 && $until <= time() + 95, 'parked for Retry-After (90 s) read from the real response header' );
ok( nppp_cloudflare_apo_status()['pending_urls'] === 2 && cron_count( $HOOK ) === 1, '2 URLs pending, exactly ONE event in the real cron array' );
$n = count( mk_log( 'purge' ) ); fire_urls( array( 'http://example.com/c/' ) );
ok( count( mk_log( 'purge' ) ) === $n, 'while parked nothing is sent' );
ok( nppp_cloudflare_apo_status()['pending_urls'] === 3 && cron_count( $HOOK ) === 1, 'URL kept (3 pending), still ONE cron event' );
nppp_state_delete( 'cf_park' ); nppp_cloudflare_apo_local_park( 0, true );
run_cron( $HOOK );
$p = mk_log( 'purge' ); $last = end( $p );
ok( $last['status'] === 200 && count( body_of( $last )['files'] ) === 3, 'retry run sends the 3 pending URLs in one request' );
ok( nppp_state_get( 'cf_pending', true ) === null && cron_count( $HOOK ) === 0, 'pending and cron cleared' );

section( '503: queued; real cron array carries the event' );
reset_all(); mk_script( array( 'match' => 'purge', 'status' => 503, 'times' => -1 ) );
fire_urls( array( 'http://example.com/a/' ) );
ok( nppp_cloudflare_apo_status()['pending'] && cron_count( $HOOK ) === 1, 'pending + one cron event' );
ok( has_notice( '/warning: Cloudflare cache purge failed \(HTTP 503/' ), 'warning names HTTP 503' );

section( 'bad token: 403, not retried, parked' );
reset_all( array( 'key' => 'cfut_' . str_repeat( 'z', 40 ) . 'b3c4d5e6' ) );
fire_urls( array( 'http://example.com/a/' ) );
ok( count( mk_log( 'zones' ) ) === 1 && count( mk_log( 'purge' ) ) === 0, 'stops at the zone lookup (403)' );
ok( ! nppp_cloudflare_apo_status()['pending'] && cron_count( $HOOK ) === 0, 'not queued' );
ok( has_notice( '/error: Cloudflare cache purge denied \(HTTP 403, code 10000/' ), 'error notice with Cloudflare code 10000' );
ok( nppp_cloudflare_apo_park_until() > time() + 290, 'parked 300 s' );

section( 'real request timeout (mock stalls 7 s, client gives up at <= 5 s)' );
reset_all();
mk_script( array( 'match' => 'purge', 'delay' => 7, 'times' => 1 ) );
$t0 = microtime( true ); fire_urls( array( 'http://example.com/a/' ) ); $el = microtime( true ) - $t0;
ok( $el >= 4.5 && $el <= 6.5, sprintf( 'returned after %.1f s (per-request timeout 5 s)', $el ) );
ok( nppp_cloudflare_apo_status()['pending'] && cron_count( $HOOK ) === 1, 'timed-out purge is queued for retry' );
ok( has_notice( '/failed \((?:network|connection timed out|transport error)/i' ) || has_notice( '/Cloudflare cache purge failed/' ), 'warning logged' );
ok( ! has_notice( '/127\.0\.0\.1|api\.cloudflare\.com/' ), 'no raw cURL text / host in the notice' );
sleep( 3 ); // let the stalled mock thread finish

section( 'TLS verification is really on (CA not trusted -> failure, never a silent insecure send)' );
reset_all(); update_option( 'nppp_h_trust_ca', 'no' );
fire_urls( array( 'http://example.com/a/' ) );
ok( count( mk_log( 'purge' ) ) === 0 && count( mk_log( 'zones' ) ) === 0, 'handshake rejected: the mock saw no request' );
ok( nppp_cloudflare_apo_status()['pending'], 'queued for retry as a network failure' );
ok( has_notice( '/TLS failure/' ) || has_notice( '/failed \(/' ), 'notice: ' . ( notices() ? preg_replace( '/^.*?: /', '', notices()[0] ) : '(none)' ) );

section( 'real $wpdb atomic claim: 8 PHP processes race for one pending row' );
reset_all(); nppp_cloudflare_apo_pending_add( false, array( 'http://example.com/a/' ), 0 );
$procs = array();
for ( $i = 0; $i < 8; $i++ ) { $procs[] = popen( 'cd ' . escapeshellarg( ABSPATH ) . ' && NPPP_ROOT=' . escapeshellarg( NPPP_H_ROOT ) . ' wp eval \'echo nppp_cloudflare_apo_pending_claim() ? "WIN" : "lose";\' --allow-root 2>/dev/null', 'r' ); }
$res = array(); foreach ( $procs as $pp ) { $res[] = trim( stream_get_contents( $pp ) ); pclose( $pp ); }
ok( count( array_keys( $res, 'WIN', true ) ) === 1, 'exactly one winner: ' . implode( ',', $res ) );

section( 'nothing written to settings or the official plugin options' );
reset_all(); $before = array( get_option( 'nginx_cache_settings' ), get_option( 'cloudflare_api_key' ), get_option( 'automatic_platform_optimization' ) );
fire_urls( array( 'http://example.com/a/' ) ); do_action( 'nppp_purged_all', false );
ok( $before === array( get_option( 'nginx_cache_settings' ), get_option( 'cloudflare_api_key' ), get_option( 'automatic_platform_optimization' ) ), 'settings / credentials / APO flag unchanged' );

echo "\n" . $GLOBALS['PASS'] . " passed, " . $GLOBALS['FAIL'] . " failed, " . count( $GLOBALS['KNOWN'] ) . " known-issue(s)\n";
foreach ( $GLOBALS['KNOWN'] as $k ) { echo "  KNOWN: $k\n"; }
exit( $GLOBALS['FAIL'] ? 1 : 0 );
