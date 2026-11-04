<?php
/** Time budget (D2): 8 s in a request, 20 s in cron / WP-CLI. A slow API must not stall the editor. Takes ~11 s. */
$BOOT_MODE = 'new';
require __DIR__ . '/boot.php';
section( 'budget: slow API, request context (8 s)' );
t_enable(); t_script( '#GET .*/zones\?#', t_zone_ok() );
t_script( '#POST .*/purge_cache#', array( 'code' => 200, 'json' => t_ok()['json'], 'delay' => 3.2 ) );
add_filter( 'nppp_cloudflare_chunk_size', function () { return 1; } );
$urls = array(); for ( $i = 0; $i < 6; $i++ ) { $urls[] = "http://example.com/p$i/"; }
$t0 = microtime( true ); do_action( 'nppp_purged_urls', $urls, $urls[0], 1, true ); $el = microtime( true ) - $t0;
$sent = count( t_reqs( '#POST .*/purge_cache#' ) );
ok( $el < 11.0, sprintf( 'returns within the budget plus one request (%.1f s)', $el ) );
ok( $sent === 3, "sent $sent of 6 requests before the deadline (expected 3)" );
ok( count( t_state( 'cf_pending' )['urls'] ) === 3, 'the 3 unsent URLs are pending' );
ok( count( t_cron_events( NPPP_CF_APO_RETRY_HOOK ) ) === 1, 'one retry event' );
ok( count( t_logs( '#time budget exhausted, 3 URL#' ) ) === 1, 'budget warning logged' );
ok( nppp_cloudflare_apo_park_until() === 0, 'a budget stop is not a failure: no park, no try counted' );
ok( t_state( 'cf_pending' )['tries'] === 0, 'tries stay 0' );
ok( nppp_cloudflare_apo_budget_seconds() === 8.0, 'request budget = 8 s' );
$T['doing_cron'] = true; ok( nppp_cloudflare_apo_budget_seconds() === 20.0, 'cron budget = 20 s' );
finish();
