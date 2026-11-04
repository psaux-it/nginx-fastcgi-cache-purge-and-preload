<?php
/** A future release that changes the shape of the official plugin yields "unsupported", never a fatal error (R4). */
$BOOT_MODE = 'none';
require __DIR__ . '/boot.php';
$dir = sys_get_temp_dir() . '/cf-fake-plugin-b'; @mkdir( $dir );   // no config.json, no classes
define( 'CLOUDFLARE_PLUGIN_DIR', $dir );
eval( 'namespace Cloudflare\APO\WordPress { class Hooks {} }' );   // looks installed, but is hollow
section( 'unsupported layout' );
t_enable(); t_happy();
ok( nppp_cloudflare_apo_ns() === 'Cloudflare\\APO\\' && nppp_cloudflare_apo_is_available(), 'detected as installed' );
ok( nppp_cloudflare_apo_supported() === false, 'supported() false' );
$threw = false; try { do_action( 'nppp_purged_all', false ); do_action( 'nppp_purged_urls', array( 'http://example.com/a/' ), '', 1, true ); } catch ( \Throwable $e ) { $threw = true; }
ok( ! $threw && count( $T['reqs'] ) === 0, 'listeners do nothing and do not fatal' );
ok( nppp_cloudflare_apo_status()['state'] === 'unsupported', 'status: unsupported' );
ok( count( t_logs( '#not supported#' ) ) === 1, 'one warning' );
finish();
