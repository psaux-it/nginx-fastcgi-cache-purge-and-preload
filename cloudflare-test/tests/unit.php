<?php
/** Unit tests of includes/compat-cloudflare.php (official plugin faked in the NEW namespace Cloudflare\APO\). */
$BOOT_MODE = 'new';
require __DIR__ . '/boot.php';

$HOOK = NPPP_CF_APO_RETRY_HOOK;
$U = 'nppp_purged_urls';
function fire_urls( array $urls, string $primary = '', int $post = 1, bool $auto = true ): void { do_action( 'nppp_purged_urls', $urls, $primary ?: ( $urls[0] ?? '' ), $post, $auto ); }
function purge_reqs(): array { return t_reqs( '#POST .*/purge_cache#' ); }
function zone_reqs(): array { return t_reqs( '#GET .*/zones\?#' ); }
function reset_park(): void { t_expire( 'cf_park' ); nppp_cloudflare_apo_local_park( 0, true ); }

section( 'registration contract' );
ok( isset( $T['actions']['nppp_purged_urls'][20] ) && $T['actions']['nppp_purged_urls'][20][0][1] === 4, 'nppp_purged_urls listener: priority 20, 4 args' );
ok( isset( $T['actions']['nppp_purged_all'][20] ) && $T['actions']['nppp_purged_all'][20][0][1] === 1, 'nppp_purged_all listener: priority 20, 1 arg' );
ok( ! isset( $T['actions']['nppp_purged_urls'][10] ) && ! isset( $T['actions']['nppp_purged_all'][10] ), 'no priority-10 listeners' );
ok( isset( $T['filters']['nppp_sync_cloudflare_apo_enabled'][10] ) && $T['filters']['nppp_sync_cloudflare_apo_enabled'][10][0][1] === 6, 'sync filter callback: priority 10, 6 args' );
ok( isset( $T['actions'][ $HOOK ] ), 'retry cron hook bound' );
ok( ! isset( $T['actions']['nppp_preload_all_started'] ), 'nppp_preload_all_started is NOT listened to (D10)' );
ok( ! isset( $T['actions']['shutdown'] ), 'no shutdown queue (F1)' );
foreach ( array( 'nppp_cloudflare_apo_is_available', 'nppp_cloudflare_apo_purge_all', 'nppp_cloudflare_apo_purge_urls', 'nppp_cloudflare_apo_sync_option_enabled', 'nppp_cloudflare_apo_status' ) as $fn ) { ok( function_exists( $fn ), "public function $fn exists" ); }
ok( nppp_cloudflare_apo_is_available() === true, 'is_available() true with the plugin loaded' );

section( 'happy path: URL purge' );
t_reset(); t_enable(); t_happy();
fire_urls( array( 'http://example.com/a/', 'http://EXAMPLE.com/a/', 'http://notexample.com/x/', 'http://sub.example.com/y', 'ftp://example.com/z', 'http://example.com.evil.net/q' ), 'http://example.com/a/' );
$p = purge_reqs();
ok( count( $p ) === 1, 'exactly one purge request' );
ok( ( $p[0]['body']['files'] ?? null ) === array( 'http://example.com/a/', 'http://sub.example.com/y' ), 'files: dedupe, host lower-cased, notexample.com / ftp / foreign host rejected' );
ok( strpos( $p[0]['url'], '/zones/' . ZONE_ID . '/purge_cache' ) !== false && $p[0]['method'] === 'POST', 'POST /zones/{id}/purge_cache (Y3)' );
ok( ( $p[0]['args']['headers']['Authorization'] ?? '' ) === 'Bearer tok_secret_value_123456', 'auth header from the official beforeSend()' );
ok( $p[0]['args']['sslverify'] === true && $p[0]['args']['redirection'] === 0, 'sslverify=true, redirection=0 (N5c)' );
ok( $p[0]['args']['timeout'] <= 5.0 && $p[0]['args']['timeout'] >= 1.0 && $p[0]['args']['nppp_cf_connect_ms'] === 2000, 'request timeout <= 5 s, connect 2000 ms' );
ok( count( zone_reqs() ) === 1, 'one zone lookup' );
ok( count( t_logs( '#success: Cloudflare cache purge completed: 2 URL\(s\) in 1 request\(s\)#' ) ) === 1, 'success log line' );
ok( t_state( 'cf_last' )['ok'] === true, 'cf_last recorded' );
ok( ! in_array( 'nginx_cache_settings', $T['writes'], true ), 'no user setting written' );

section( 'zone cache (D5)' );
fire_urls( array( 'http://example.com/b/' ) );
ok( count( zone_reqs() ) === 1 && count( purge_reqs() ) === 2, 'second purge reuses the cached zone (no second GET zones)' );
t_script( '#POST .*/purge_cache#', t_err( 404, 7003, 'Could not route' ) );
fire_urls( array( 'http://example.com/c/' ) );
ok( t_state( 'cf_zone' ) === null, 'stale zone id (404/7003) is forgotten' );
ok( t_state( 'cf_pending' ) === null && count( t_cron_events( $HOOK ) ) === 0, 'not_found is not retried' );
ok( count( t_logs( '#error: Cloudflare cache purge finished with errors#' ) ) === 1, 'not_found is logged as an error' );

section( 'zone candidates: multi-level host (F6)' );
t_reset(); t_enable(); $T['home'] = 'http://shop.eu.example.com'; $T['cf']['domain'] = '';
t_script( '#GET .*/zones\?#', t_ok( array() ) ); t_script( '#GET .*name=example\.com&#', t_zone_ok() ); t_script( '#POST .*/purge_cache#', t_ok() );
fire_urls( array( 'http://shop.eu.example.com/p/' ) );
ok( count( zone_reqs() ) === 3, 'tries shop.eu.example.com, eu.example.com, example.com' );
ok( ( purge_reqs()[0]['body']['files'] ?? null ) === array( 'http://shop.eu.example.com/p/' ), 'URL accepted for zone example.com' );
ok( t_state( 'cf_zone' )['name'] === 'example.com', 'zone cached' );

section( 'filter nppp_cloudflare_apo_zone overrides discovery' );
t_reset(); t_enable(); t_script( '#POST .*/purge_cache#', t_ok() );
add_filter( 'nppp_cloudflare_apo_zone', function () { return array( 'id' => 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb', 'name' => 'Example.COM' ); }, 10, 2 );
fire_urls( array( 'http://example.com/a/' ) );
ok( count( zone_reqs() ) === 0 && strpos( purge_reqs()[0]['url'], 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb' ) !== false, 'forced zone: no discovery request' );

section( 'no zone' );
t_reset(); t_enable(); t_script( '#GET .*/zones\?#', t_ok( array() ) );
fire_urls( array( 'http://example.com/a/' ) ); fire_urls( array( 'http://example.com/b/' ) );
ok( count( purge_reqs() ) === 0, 'nothing purged' );
ok( count( zone_reqs() ) === 1, 'negative memo: the failed discovery is not repeated (10 min)' );
ok( count( t_logs( '#no active Cloudflare zone#' ) ) === 1, 'one throttled warning' );
ok( nppp_cloudflare_apo_status()['state'] === 'no_zone', 'status: no_zone' );

section( 'chunking (F7)' );
foreach ( array( 99 => 1, 100 => 1, 101 => 2, 250 => 3 ) as $n => $expect ) {
    t_reset(); t_enable(); t_happy(); $urls = array(); for ( $i = 0; $i < $n; $i++ ) { $urls[] = "http://example.com/p$i/"; }
    fire_urls( $urls ); ok( count( purge_reqs() ) === $expect, "$n URLs -> $expect request(s)" );
}
t_reset(); t_enable(); t_happy(); add_filter( 'nppp_cloudflare_chunk_size', function () { return 500; } ); $urls = array(); for ( $i = 0; $i < 101; $i++ ) { $urls[] = "http://example.com/p$i/"; }
fire_urls( $urls ); ok( count( purge_reqs() ) === 1, 'Enterprise chunk size 500 via filter' );

section( 'cache by device type' );
t_reset(); t_enable(); $T['cf']['settings']['automatic_platform_optimization_cache_by_device_type'] = array( 'value' => 'on' ); t_happy();
fire_urls( array( 'http://example.com/a/', 'http://example.com/b/' ) ); $p = purge_reqs();
ok( count( $p ) === 2 && $p[0]['body']['files'][0] === 'http://example.com/a/', 'desktop request first' );
ok( ( $p[1]['body']['files'][0]['headers']['CF-Device-Type'] ?? '' ) === 'mobile' && $p[1]['body']['files'][1]['url'] === 'http://example.com/b/', 'separate mobile request with CF-Device-Type (no mixed request)' );
ok( count( t_logs( '#Cloudflare mobile cache purge completed: 2 URL#' ) ) === 1, 'mobile log line' );

section( 'purge all' );
t_reset(); t_enable(); t_happy();
do_action( 'nppp_purged_all', true );
$p = purge_reqs();
ok( count( $p ) === 1 && $p[0]['body'] === array( 'purge_everything' => true ), 'one purge_everything request (D4)' );
ok( count( t_logs( '#Cloudflare purge-all cache completed\.#' ) ) === 1, 'log line' );
ok( ! isset( $T['writes'][0] ) || ! in_array( 'nginx_cache_settings', $T['writes'], true ), 'no user setting written' );

section( 'no skip rule after purge-all (D14 / N3)' );
fire_urls( array( 'http://example.com/after/' ) );
ok( count( purge_reqs() ) === 2, 'a URL purge after a purge-all still reaches Cloudflare' );

section( '429 Retry-After: park, pending, single cron event (N4)' );
t_reset(); t_enable(); t_script( '#GET .*/zones\?#', t_zone_ok() ); t_script( '#POST .*/purge_cache#', t_err( 429, 971, 'rate limited', array( 'Retry-After' => '120' ) ) );
fire_urls( array( 'http://example.com/a/', 'http://example.com/b/' ) );
$pend = nppp_cloudflare_apo_status();
ok( $pend['pending'] && $pend['pending_urls'] === 2, 'both URLs kept in cf_pending' );
$until = (int) t_state( 'cf_park' ); ok( $until >= time() + 118 && $until <= time() + 125, 'parked until Retry-After (120 s)' );
ok( count( t_cron_events( $HOOK ) ) === 1, 'ONE retry event' );
ok( nppp_cloudflare_apo_status()['state'] === 'parked', 'status: parked' );
$n = count( purge_reqs() );
fire_urls( array( 'http://example.com/c/' ) ); do_action( 'nppp_purged_all', false );
ok( count( purge_reqs() ) === $n, 'while parked nothing is sent' );
ok( nppp_cloudflare_apo_status()['pending_all'] === true, 'purge-all while parked is kept (absorbs the URLs)' );
ok( count( t_cron_events( $HOOK ) ) === 1, 'still ONE retry event' );
t_script( '#POST .*/purge_cache#', t_ok() ); reset_park();
t_run_cron( $HOOK );
$last = purge_reqs(); $last = end( $last );
ok( $last['body'] === array( 'purge_everything' => true ), 'retry sends one purge_everything (absorbed the URL set)' );
ok( t_state( 'cf_pending' ) === null && count( t_cron_events( $HOOK ) ) === 0, 'pending and cron cleared after success' );

section( '5xx: backoff and give-up (N4)' );
t_reset(); t_enable(); t_script( '#GET .*/zones\?#', t_zone_ok() ); t_script( '#POST .*/purge_cache#', t_err( 503, 0, 'down' ) );
fire_urls( array( 'http://example.com/a/' ) );
$want = array( 1 => 55, 2 => 295, 3 => 895, 4 => 3595, 5 => 3595 ); $okdelay = true; $one = true;
for ( $i = 1; $i <= 5; $i++ ) {
    $ev = t_cron_events( $HOOK ); $one = $one && count( $ev ) === 1;
    $okdelay = $okdelay && ( $ev[0]['ts'] - time() ) >= ( $want[ $i ] ?? 0 ) - 10 - ( $i === 1 ? 0 : 0 );
    reset_park(); t_run_cron( $HOOK );
}
ok( $one, 'always exactly one retry event' );
ok( $okdelay, 'backoff grows: ~60, ~60, ~300, ~900, ~3600 s' );
ok( count( t_logs( '#gave up after 6 attempts#' ) ) === 1, 'dropped with ONE error line after 6 attempts' );
ok( t_state( 'cf_pending' ) === null && count( t_cron_events( $HOOK ) ) === 0, 'pending and cron cleared after give-up' );

section( 'retry event moves earlier' );
t_reset(); t_enable(); wp_schedule_single_event( time() + 3600, $HOOK );
nppp_cloudflare_apo_schedule_retry( 60 );
$ev = t_cron_events( $HOOK ); ok( count( $ev ) === 1 && $ev[0]['ts'] <= time() + 65, 'a far event is replaced by a near one (still one event)' );
nppp_cloudflare_apo_schedule_retry( 3000 ); ok( count( t_cron_events( $HOOK ) ) === 1 && t_cron_events( $HOOK )[0]['ts'] <= time() + 65, 'a near event is kept' );

section( '403: no retry, park, error' );
t_reset(); t_enable(); t_script( '#GET .*/zones\?#', t_zone_ok() ); t_script( '#POST .*/purge_cache#', t_err( 403, 10000, 'Authentication error' ) );
fire_urls( array( 'http://example.com/a/' ) );
ok( t_state( 'cf_pending' ) === null && count( t_cron_events( $HOOK ) ) === 0, 'auth failure is not retried' );
ok( count( t_logs( '#error: Cloudflare cache purge denied \(HTTP 403, code 10000#' ) ) === 1, 'error log names HTTP status and Cloudflare code' );
ok( t_state( 'cf_park' ) > time() + 290, 'parked 300 s' );

section( 'network error: coarse reason, retried' );
t_reset(); t_enable(); t_script( '#GET .*/zones\?#', t_zone_ok() ); t_script( '#POST .*/purge_cache#', new WP_Error( 'http_request_failed', 'cURL error 28: Connection timed out after 5001 milliseconds to 10.0.0.5:443' ) );
fire_urls( array( 'http://example.com/a/' ) );
ok( t_state( 'cf_pending' ) !== null && count( t_cron_events( $HOOK ) ) === 1, 'queued for retry' );
$joined = implode( "\n", $T['logs'] ); ok( strpos( $joined, '10.0.0.5' ) === false, 'raw cURL text / IP not logged' );

section( 'transport that throws is contained' );
t_reset(); t_enable(); t_script( '#GET .*/zones\?#', t_zone_ok() ); t_script( '#POST .*/purge_cache#', function () { throw new RuntimeException( 'boom' ); } );
$threw = false; try { fire_urls( array( 'http://example.com/a/' ) ); } catch ( \Throwable $e ) { $threw = true; }
ok( ! $threw && t_state( 'cf_pending' ) !== null, 'no exception escapes; the purge is kept' );
$threw = false; try { nppp_cloudflare_apo_purge_urls( null ); nppp_cloudflare_apo_purge_urls( 'x', 5, 'y', null ); nppp_cloudflare_apo_purge_all(); } catch ( \Throwable $e ) { $threw = true; }
ok( ! $threw, 'listeners tolerate odd arguments (no TypeError)' );

section( 'partial progress: leftover chunks are kept' );
t_reset(); t_enable(); t_script( '#GET .*/zones\?#', t_zone_ok() );
$calls = 0; t_script( '#POST .*/purge_cache#', function () use ( &$calls ) { $calls++; return $calls === 1 ? t_ok() : t_err( 503, 0, 'down' ); } );
$urls = array(); for ( $i = 0; $i < 250; $i++ ) { $urls[] = "http://example.com/p$i/"; }
fire_urls( $urls );
ok( count( purge_reqs() ) === 2, 'stops after the first failed chunk' );
ok( count( t_state( 'cf_pending' )['urls'] ) === 150, 'only the 150 unsent URLs are pending' );

section( 'next run merges what is pending (one request)' );
t_reset(); t_enable(); t_happy();
nppp_cloudflare_apo_pending_add( false, array( 'http://example.com/old/' ), 1 );
fire_urls( array( 'http://example.com/new/' ) );
ok( count( purge_reqs() ) === 1 && count( purge_reqs()[0]['body']['files'] ) === 2, 'pending URL merged into the new request' );
ok( t_state( 'cf_pending' ) === null, 'pending consumed' );

section( 'pending set: claim, collapse' );
t_reset(); t_enable();
nppp_cloudflare_apo_pending_add( false, array( 'http://example.com/1/' ), 0 );
ok( is_array( nppp_cloudflare_apo_pending_claim() ), 'first claim wins' );
ok( nppp_cloudflare_apo_pending_claim() === null, 'second claim gets nothing (atomic)' );
$many = array(); for ( $i = 0; $i < NPPP_CF_APO_MAX_PENDING_URLS + 1; $i++ ) { $many[] = "http://example.com/u$i/"; }
nppp_cloudflare_apo_pending_add( false, $many, 0 );
$r = nppp_cloudflare_apo_pending_read(); ok( $r['all'] === true && $r['urls'] === array(), 'too many pending URLs collapse into purge_everything' );

section( 'gates: option, filter, credentials, APO' );
t_reset(); t_happy(); // sync option NOT set
fire_urls( array( 'http://example.com/a/' ) ); do_action( 'nppp_purged_all', false );
ok( count( $T['reqs'] ) === 0, 'sync option off -> nothing sent' );
t_enable(); add_filter( 'nppp_sync_cloudflare_apo_enabled', function ( $e, $ctx ) { return $ctx === 'purge_all' ? false : $e; }, 20, 2 );
do_action( 'nppp_purged_all', false ); ok( count( purge_reqs() ) === 0, 'external filter can veto purge_all' );
fire_urls( array( 'http://example.com/a/' ) ); ok( count( purge_reqs() ) === 1, '... but not purge_urls' );
t_reset(); t_enable(); t_happy(); $T['cf']['key'] = '';
fire_urls( array( 'http://example.com/a/' ) ); fire_urls( array( 'http://example.com/b/' ) );
ok( count( $T['reqs'] ) === 0 && count( t_logs( '#not authenticated#' ) ) === 1, 'no credentials: no HTTP, one throttled log line (F10)' );
ok( nppp_cloudflare_apo_status()['state'] === 'no_credentials', 'status: no_credentials' );
t_reset(); t_enable(); t_happy(); $T['cf']['settings'] = array( 'automatic_platform_optimization' => array( 'value' => 'off' ) );
fire_urls( array( 'http://example.com/a/' ) );
ok( count( $T['reqs'] ) === 0 && count( t_logs( '#APO/Plugin-Specific Cache is disabled#' ) ) === 1, 'APO off -> skipped with an info line' );
ok( nppp_cloudflare_apo_status()['state'] === 'apo_off', 'status: apo_off' );
t_reset(); t_enable(); t_happy(); $T['cf']['settings'] = array( 'plugin_specific_cache' => array( 'value' => null ) );
fire_urls( array( 'http://example.com/a/' ) ); ok( count( purge_reqs() ) === 1, 'PSC object with value null counts as ON, like the official plugin (F13)' );

section( 'filter callback never writes (F4)' );
t_reset(); t_enable(); $before = $T['options'];
$r1 = apply_filters( 'nppp_sync_cloudflare_apo_enabled', true, 'purge_urls', array(), '', 0, false );
ok( $r1 === true && $T['options'] === $before && $T['writes'] === array(), 'filter is read only' );
t_reset(); $r2 = apply_filters( 'nppp_sync_cloudflare_apo_enabled', true, 'purge_urls', array(), '', 0, false ); ok( $r2 === false, 'option off -> false, still no write' );

section( 'retry event honours the setting' );
t_reset(); t_enable(); t_happy(); nppp_cloudflare_apo_pending_add( false, array( 'http://example.com/a/' ), 1 ); t_enable( false );
t_run_cron( $HOOK ); ok( count( $T['reqs'] ) === 0 && t_state( 'cf_pending' ) === null, 'feature switched off: pending dropped, nothing sent' );

section( 'global API key headers (Y2)' );
t_reset(); t_enable(); t_happy(); $T['cf']['global'] = true; fire_urls( array( 'http://example.com/a/' ) );
$h = purge_reqs()[0]['args']['headers'];
ok( isset( $h['X-Auth-Key'], $h['X-Auth-Email'] ) && ! isset( $h['Authorization'] ), 'X-Auth-* headers come from the official client' );

section( 'secrets never leave' );
t_reset(); t_enable(); t_script( '#GET .*/zones\?#', t_zone_ok() ); t_script( '#POST .*/purge_cache#', t_err( 403, 10000, 'bad' ) ); fire_urls( array( 'http://example.com/a/' ) );
$dump = json_encode( array( $T['logs'], $T['options'] ) );
ok( strpos( $dump, 'tok_secret_value_123456' ) === false && strpos( $dump, 'owner@example.com' ) === false, 'token / e-mail absent from logs and state rows' );

section( 'IDN host' );
t_reset(); t_enable(); t_happy(); fire_urls( array( 'http://münchen.example.com/x' ) );
ok( ( purge_reqs()[0]['body']['files'][0] ?? '' ) === 'http://xn--mnchen-3ya.example.com/x', 'host sent as punycode' );

section( 'path and query are kept byte for byte (R2/N11)' );
t_reset(); t_enable(); t_happy(); fire_urls( array( 'http://example.com/Türkçe-Yazı/?Q=Aa%C3%A7' ) );
ok( ( purge_reqs()[0]['body']['files'][0] ?? '' ) === 'http://example.com/Türkçe-Yazı/?Q=Aa%C3%A7', 'no re-encoding of path / query' );

section( 'reset / cleanup' );
t_reset(); t_enable(); nppp_cloudflare_apo_park( 100 ); nppp_cloudflare_apo_state_set( 'cf_warn', array( 'x' => time() + 99 ), 99 ); nppp_cloudflare_apo_state_set( 'cf_zone_miss', array( 'fp' => 'x' ), 99 );
nppp_cloudflare_apo_pending_add( false, array( 'http://example.com/a/' ), 0 ); nppp_cloudflare_apo_state_set( 'cf_zone', array( 'id' => 'x', 'name' => 'example.com', 'fp' => 'x' ), 99 );
nppp_cloudflare_apo_reset_state();
ok( t_state( 'cf_park' ) === null && t_state( 'cf_warn' ) === null && t_state( 'cf_zone_miss' ) === null && nppp_cloudflare_apo_park_until() === 0, 'reset clears park / warn / zone-miss' );
ok( t_state( 'cf_pending' ) !== null && t_state( 'cf_zone' ) !== null, '... and keeps pending + zone' );
wp_schedule_single_event( time() + 60, $HOOK ); nppp_cloudflare_apo_cleanup();
ok( t_state( 'cf_pending' ) === null && t_state( 'cf_zone' ) === null && count( t_cron_events( $HOOK ) ) === 0, 'cleanup removes rows and the event' );

section( 'no setting write anywhere in this run' );
ok( ! in_array( 'nginx_cache_settings', $T['writes'], true ), 'update_option( nginx_cache_settings ) never called' );

finish();
