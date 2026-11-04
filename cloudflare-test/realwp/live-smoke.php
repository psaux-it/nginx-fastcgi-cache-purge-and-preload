<?php
/**
 * LIVE smoke test against the real Cloudflare API with a FREE-plan zone. Run on the site that has the official
 * Cloudflare plugin configured (token + zone), with NPP active:
 *
 *   LIVE_URL=https://yourdomain.com/wp-content/themes/x/style.css  wp eval-file live-smoke.php
 *   LIVE_PROBE=1 LIVE_URL=...                                       also probes the per-request URL limit
 *
 * Purging by URL and purge-everything are available on every Cloudflare plan, so APO is NOT needed to prove the
 * purge path. Only the APO flag is: the compat layer reads it from the official plugin option. If APO is not
 * enabled you can set it just for the test and remove it afterwards:
 *   wp option update automatic_platform_optimization '{"value":"on"}' --format=json
 *   wp option delete automatic_platform_optimization
 */
function lv_edge( $url ) { $r = wp_remote_get( $url, array( 'timeout' => 15, 'redirection' => 0, 'headers' => array( 'Cache-Control' => 'no-transform' ) ) ); return is_wp_error( $r ) ? 'ERR ' . $r->get_error_message() : wp_remote_retrieve_response_code( $r ) . ' cf-cache-status=' . ( wp_remote_retrieve_header( $r, 'cf-cache-status' ) ?: '(none)' ) . ' age=' . ( wp_remote_retrieve_header( $r, 'age' ) ?: '-' ); }
function lv_last() { $s = nppp_cloudflare_apo_status(); return json_encode( array( 'state' => $s['state'], 'zone' => $s['zone'], 'pending' => $s['pending'], 'last' => $s['last'] ) ); }

$url = getenv( 'LIVE_URL' );
echo "status before : " . lv_last() . "\n";
if ( ! $url ) { echo "set LIVE_URL to a cacheable URL of the zone (static asset, or a page covered by a Cache Rule)\n"; exit( 1 ); }
echo "1) warm the edge:\n"; for ( $i = 0; $i < 3; $i++ ) { echo "   GET #" . ( $i + 1 ) . ": " . lv_edge( $url ) . "\n"; sleep( 1 ); }
echo "   (expect HIT on #2/#3; if you only ever see DYNAMIC/BYPASS this URL is not cached by Cloudflare and the test cannot prove anything)\n";
echo "2) purge through the compat layer:\n";
do_action( 'nppp_purged_urls', array( $url ), $url, 0, false );
echo "   status after: " . lv_last() . "\n";
echo "3) first request after purge:\n   GET: " . lv_edge( $url ) . "   (expect MISS or EXPIRED, then HIT again)\n";
echo "   GET: " . lv_edge( $url ) . "\n";

if ( getenv( 'LIVE_PROBE' ) ) {
    echo "4) per-request URL limit probe (dummy URLs, harmless):\n";
    $host = wp_parse_url( $url, PHP_URL_HOST ); $scheme = wp_parse_url( $url, PHP_URL_SCHEME );
    add_filter( 'nppp_cloudflare_chunk_size', function () { return 500; } ); // force ONE request of n URLs
    foreach ( array( 30, 31, 100, 101 ) as $n ) {
        $u = array(); for ( $i = 0; $i < $n; $i++ ) { $u[] = "$scheme://$host/nppp-probe/$n/$i/"; }
        do_action( 'nppp_purged_urls', $u, $u[0], 0, false );
        $l = nppp_cloudflare_apo_status()['last'];
        echo sprintf( "   %3d URLs in ONE request -> ok=%s http=%s code=%s\n", $n, var_export( $l['ok'] ?? null, true ), $l['status'] ?? '-', $l['code'] ?? '-' );
        nppp_cloudflare_apo_reset_state(); sleep( 1 );
    }
    echo "   If 31 is refused (http 400) the free plan limit is 30: set the chunk size to 30 (filter nppp_cloudflare_chunk_size).\n";
}
