<?php
/** Detection per installed layout. Run once per mode, each in its own PHP process: php detect.php new|old|both|none|nocfg */
$BOOT_MODE = $argv[1] ?? 'new';
require __DIR__ . '/boot.php';
if ( $BOOT_MODE === 'nocfg' ) { /* handled below */ }

section( "detect: $BOOT_MODE" );
t_enable(); t_happy();
$expect_ns = array( 'new' => 'Cloudflare\\APO\\', 'old' => 'CF\\', 'both' => 'Cloudflare\\APO\\', 'none' => '' );
if ( isset( $expect_ns[ $BOOT_MODE ] ) ) {
    ok( nppp_cloudflare_apo_ns() === $expect_ns[ $BOOT_MODE ], 'namespace = ' . var_export( $expect_ns[ $BOOT_MODE ], true ) . ( $BOOT_MODE === 'both' ? ' (new one first: 4.14.x also ships deprecated CF\\ classes, Y1)' : '' ) );
    ok( nppp_cloudflare_apo_is_available() === ( $BOOT_MODE !== 'none' ), 'is_available()' );
}
if ( $BOOT_MODE === 'none' ) {
    do_action( 'nppp_purged_all', false ); do_action( 'nppp_purged_urls', array( 'http://example.com/a/' ), 'http://example.com/a/', 1, true );
    ok( count( $T['reqs'] ) === 0, 'plugin absent: nothing sent, no fatal' );
    ok( nppp_cloudflare_apo_status()['state'] === 'absent', 'status: absent' );
    ok( apply_filters( 'nppp_sync_cloudflare_apo_enabled', true, 'purge_all' ) === false, 'filter says no' );
    ok( nppp_state_get( 'cf_pending', true ) === null, 'nothing queued for a plugin that is not there' );
    ok( $T['writes'] === array() && ( $T['options']['nginx_cache_settings']['nppp_cloudflare_apo_sync'] ?? '' ) === 'yes', 'plugin absent: the user setting stays "yes" and nothing is written (F4)' );
} else {
    ok( nppp_cloudflare_apo_status()['state'] === 'ready', 'status: ready' );
    do_action( 'nppp_purged_urls', array( 'http://example.com/a/' ), 'http://example.com/a/', 1, true );
    ok( count( t_reqs( '#POST .*/purge_cache#' ) ) === 1, 'purge works with this layout' );
    ok( ( t_reqs( '#POST#' )[0]['args']['headers']['Authorization'] ?? '' ) !== '', 'auth header built by this layout\'s beforeSend()' );
}
if ( $BOOT_MODE === 'nocfg' ) { }
finish();
