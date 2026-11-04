<?php
/**
 * Runs includes/compat-cloudflare.php against the REAL official Cloudflare plugin source (CF_SRC, default ../../cloudflare).
 * Only WordPress is stubbed. Proves: detection, store / settings / zone-name reads, and auth headers from the real
 * Client::beforeSend() for every key format.
 */
$BOOT_MODE = 'real';
require __DIR__ . '/boot.php';
$ns = nppp_cloudflare_apo_ns();

section( 'real plugin: detection and runtime' );
ok( in_array( $ns, array( 'Cloudflare\\APO\\', 'CF\\' ), true ), "namespace detected: $ns" );
ok( nppp_cloudflare_apo_supported() === true, 'supported()' );
t_enable();
$T['options']['cloudflare_api_key']             = 'cfut_' . str_repeat( 'a', 40 ) . 'b3c4d5e6';
$T['options']['cloudflare_api_email']           = 'owner@example.com';
$T['options']['cloudflare_cached_domain_name']  = 'example.com';
$T['options']['automatic_platform_optimization'] = array( 'value' => 'on', 'editable' => true );
$rt = nppp_cloudflare_apo_runtime();
ok( is_array( $rt ) && isset( $rt['client'], $rt['store'], $rt['wp_api'] ), 'runtime objects built from the real classes' );
ok( nppp_cloudflare_apo_has_credentials( $rt ), 'credentials read through the real DataStore' );
ok( nppp_cloudflare_apo_is_html_cache_enabled( $rt ), 'APO setting read through the real DataStore' );
ok( $rt['wp_api']->getDomainList() === array( 'example.com' ), 'cached domain name read' );
ok( nppp_cloudflare_apo_status()['state'] === 'ready', 'status: ready' );

section( 'real beforeSend(): every key format (Y2)' );
$cases = array(
    'API token, new prefix cfut_'    => array( 'cfut_' . str_repeat( 'a', 40 ) . 'b3c4d5e6', 'bearer' ),
    'Account token, new prefix cfat_' => array( 'cfat_' . str_repeat( 'a', 40 ) . 'b3c4d5e6', 'bearer' ),
    'Global key, new prefix cfk_'    => array( 'cfk_' . str_repeat( 'a', 40 ) . 'b3c4d5e6', 'global' ),
    'Global key, legacy 37-char hex' => array( str_repeat( 'a1', 18 ) . 'f', 'global' ),
    'API token, legacy 40-char'      => array( 'Abcdefghijklmnopqrstuvwxyz0123456789_-XY', 'bearer' ),
);
$new_keys = method_exists( nppp_cloudflare_apo_cls( 'API\\Client' ), 'isGlobalApiKey' );
if ( ! $new_keys ) {
    // 4.12.8 / 4.13.x predate the cfk_/cfut_/cfat_ formats and decide by length alone: whatever the official
    // plugin of the SITE does is what we send, because its beforeSend() builds the headers (D3).
    $cases['Global key, new prefix cfk_'][1] = 'bearer';
}
foreach ( $cases as $label => $c ) {
    t_reset(); t_enable(); $T['options']['cloudflare_api_key'] = $c[0]; $T['options']['cloudflare_api_email'] = 'owner@example.com';
    $T['options']['cloudflare_cached_domain_name'] = 'example.com'; $T['options']['automatic_platform_optimization'] = array( 'value' => 'on' );
    t_happy(); do_action( 'nppp_purged_urls', array( 'http://example.com/a/' ), '', 1, true );
    $r = t_reqs( '#POST .*/purge_cache#' ); $h = $r ? $r[0]['args']['headers'] : array();
    if ( $c[1] === 'bearer' ) { ok( ( $h['Authorization'] ?? '' ) === 'Bearer ' . $c[0] && ! isset( $h['X-Auth-Key'] ), "$label -> Authorization: Bearer" ); }
    else { ok( ( $h['X-Auth-Key'] ?? '' ) === $c[0] && ( $h['X-Auth-Email'] ?? '' ) === 'owner@example.com' && ! isset( $h['Authorization'] ), "$label -> X-Auth-Key + X-Auth-Email" ); }
    ok( ( $h['Content-Type'] ?? '' ) === 'application/json', "$label: Content-Type from the official client" );
}

section( 'real plugin: full purge flow' );
t_reset(); t_enable(); $T['options']['cloudflare_api_key'] = 'cfut_' . str_repeat( 'a', 40 ) . 'b3c4d5e6'; $T['options']['cloudflare_cached_domain_name'] = 'example.com';
$T['options']['automatic_platform_optimization'] = array( 'value' => 'on' ); t_happy();
do_action( 'nppp_purged_urls', array( 'http://example.com/', 'http://example.com/feed/' ), 'http://example.com/', 5, true );
ok( count( t_reqs( '#POST .*/purge_cache#' ) ) === 1 && count( t_reqs( '#GET .*/zones\?name=example.com#' ) ) === 1, 'zone lookup by the real cached domain name, one purge request' );
do_action( 'nppp_purged_all', true );
ok( count( t_reqs( '#POST .*/purge_cache#' ) ) === 2, 'purge-all request' );
ok( ! in_array( 'nginx_cache_settings', $T['writes'], true ) && ! in_array( 'cloudflare_api_key', $T['writes'], true ), 'nothing written to settings / the official plugin options' );

if ( $ns === 'Cloudflare\\APO\\' ) {
    section( 'Y1: legacy CF\\ classes of 4.14.x are never instantiated' );
    ok( class_exists( '\\CF\\WordPress\\Hooks' ), 'deprecated CF\\ classes exist in 4.14.x (autoload classmap)' );
    ok( strpos( nppp_cloudflare_apo_cls( 'WordPress\\Hooks' ), 'Cloudflare\\APO\\' ) === 0, 'class names are built from the new namespace' );
}
finish();
