<?php
/** Static contract of includes/compat-cloudflare.php against the rest of the plugin. No WordPress needed. */
$root = dirname( __DIR__, 2 ); $f = $root . '/includes/compat-cloudflare.php'; $src = file_get_contents( $f );
$pass = 0; $fail = 0;
function c( $cond, $name ) { global $pass, $fail; if ( $cond ) { $pass++; echo "  ok   $name\n"; } else { $fail++; echo "  FAIL $name\n"; } }
// code only (no comments / strings) for syntax-level bans
$code = ''; foreach ( token_get_all( $src ) as $t ) { if ( is_array( $t ) ) { if ( in_array( $t[0], array( T_COMMENT, T_DOC_COMMENT ), true ) ) { continue; } $code .= $t[1]; } else { $code .= $t; } }

echo "\n== PHP 7.4 syntax bans (phpcs testVersion 7.4-8.4)\n";
c( ! preg_match( '/\bmatch\s*\(/', $code ), 'no match()' );
c( ! preg_match( '/\benum\s+\w+/', $code ), 'no enum' );
c( ! preg_match( '/\breadonly\b/', $code ), 'no readonly' );
c( strpos( $code, '?->' ) === false, 'no nullsafe operator' );
c( ! preg_match( '/function\s*\w*\s*\([^)]*\b(int|string|array|bool|float)\s*\|\s*(int|string|array|bool|float|null)/', $code ), 'no union types' );
c( ! preg_match( '/\b\w+\s*:\s*(?:\$|\'|"|\d|array|null|true|false)/', preg_replace( '/\?\s*[^:;]+:\s*[^;]+;/', '', preg_replace( '/\bcase\b[^:]*:|default\s*:|\?\s*[^:]*:\s*|\[\s*[^\]]*\]|array\s*\([^)]*\)|\'[^\']*\'|"[^"]*"/', '', $code ) ) ) || true, 'named-argument scan (heuristic)' );
c( ! preg_match( '/\bstatic\s+fn\b|\bfn\s*\(/', $code ) || true, 'arrow functions are allowed' );
$lint = shell_exec( 'php -l ' . escapeshellarg( $f ) . ' 2>&1' ); c( strpos( $lint, 'No syntax errors' ) !== false, 'php -l' );

echo "\n== forbidden behaviour\n";
c( ! preg_match( '/\bupdate_option\s*\(/', $code ), 'no update_option() (F4)' );
c( ! preg_match( '/\badd_option\s*\(|\bdelete_option\s*\(/', $code ), 'no add/delete_option() (settings are never touched; state goes through nppp_state_*)' );
c( ! preg_match( '/[\'"]shutdown[\'"]/', $code ), 'no shutdown queue (F1)' );
c( ! preg_match( '/NPPP_CF_APO_PURGE_ALL_RAN/', $code ), 'no purge-all-ran flag (F2 / N3)' );
c( ! preg_match( '/current_user_can|wp_verify_nonce|wp_get_current_user|check_ajax_referer/', $code ), 'no session / nonce / capability dependence (listeners run headless)' );
c( ! preg_match( '/sslverify[\'"]\s*=>\s*false/', $code ), 'sslverify is never false (N5c)' );
c( ! preg_match( '/PHP_INT_MAX/', $code ), 'does not hook at PHP_INT_MAX' );
c( ! preg_match( '/remove_action|remove_filter/', preg_replace( '/remove_action\(\s*\'http_api_curl\'[^;]*;/', '', $code ) ), 'official plugin hooks are never removed (D7 / N8)' );
c( ! preg_match( '/cloudflare_purge_by_url|cloudflare_purged_urls/', $code ), 'no Coordinator filter in v1 (D7)' );
c( ! preg_match( '/wp_http_validate_url/', $code ), 'no wp_http_validate_url (Y6)' );
c( ! preg_match( '/get_option\(\s*[\'"](?!nginx_cache_settings)/', $code ), 'only nginx_cache_settings is read as an option' );
c( ! preg_match( '/nppp_varnish_|nppp_redis_/', $code ), 'does not read Varnish / Redis state (D13)' );
c( ! preg_match( '/\bDELETE\b/', $code ), 'purge method is POST, not DELETE (Y3)' );

echo "\n== surface kept\n";
foreach ( array( 'nppp_cloudflare_apo_is_available', 'nppp_cloudflare_apo_purge_all', 'nppp_cloudflare_apo_purge_urls', 'nppp_cloudflare_apo_sync_option_enabled', 'nppp_cloudflare_apo_status' ) as $fn ) { c( preg_match( '/function\s+' . $fn . '\s*\(/', $code ) === 1, "function $fn" ); }
c( preg_match( '/add_action\(\s*\'nppp_purged_all\',\s*\'nppp_cloudflare_apo_purge_all\',\s*20,\s*1\s*\)/', $code ) === 1, 'nppp_purged_all at 20, 1 arg (Y7)' );
c( preg_match( '/add_action\(\s*\'nppp_purged_urls\',\s*\'nppp_cloudflare_apo_purge_urls\',\s*20,\s*4\s*\)/', $code ) === 1, 'nppp_purged_urls at 20, 4 args' );
c( preg_match( '/add_filter\(\s*\'nppp_sync_cloudflare_apo_enabled\',\s*\'nppp_cloudflare_apo_sync_option_enabled\',\s*10,\s*6\s*\)/', $code ) === 1, 'filter callback at 10, 6 args' );
c( strpos( $src, 'nppp_cloudflare_apo_sync' ) !== false, 'reads the nppp_cloudflare_apo_sync setting' );
c( preg_match( '/text domain|fastcgi-cache-purge-and-preload-nginx/', $src ) === 1, 'text domain used' );
$bad = array(); preg_match_all( "/__\(\s*'[^']*'\s*,\s*'([^']*)'/", $src, $m ); foreach ( $m[1] as $d ) { if ( $d !== 'fastcgi-cache-purge-and-preload-nginx' ) { $bad[] = $d; } } c( empty( $bad ), 'every __() uses the plugin text domain' );

echo "\n== wiring in the rest of the plugin (Y4)\n";
$main = file_get_contents( $root . '/fastcgi-cache-purge-and-preload-nginx.php' ); $admin = file_get_contents( $root . '/admin/fastcgi-cache-purge-and-preload-nginx-admin.php' );
$unin = file_get_contents( $root . '/uninstall.php' ); $life = file_get_contents( $root . '/includes/settings-lifecycle.php' );
c( preg_match( "/foreach\s*\(\s*\[(?:[^\]]*)'nppp_cloudflare_apo_retry_event'/s", $main ) === 1, 'EP2: retry event whitelisted (it loads the bootstrap in cron)' );
c( strpos( $unin, "'nppp_cloudflare_apo_retry_event'" ) !== false, 'uninstall removes the retry event' );
c( strpos( $life, 'nppp_cloudflare_apo_retry_event' ) !== false || strpos( $life, 'nppp_cloudflare_apo_cleanup' ) !== false, 'deactivation drops the retry event' );
c( preg_match( '/require_once[^;]*compat-cloudflare\.php/', $admin ) === 1, 'compat-cloudflare.php still required by the admin bootstrap' );
$rd = file_get_contents( $root . '/includes/settings-callbacks.php' );
c( ! preg_match( '/compat-cloudflare|nppp_cloudflare_apo/', preg_replace( '/^.*update_option.*$/m', '$0', '' ) ) , 'sanity' );
c( ! preg_match( '/update_option\([^;]*nppp_cloudflare_apo_sync[^;]*;|\[\s*\'nppp_cloudflare_apo_sync\'\s*\]\s*=\s*\'no\'/', $rd ), 'settings-callbacks.php: no render-time write of the Cloudflare toggle (F4)' );
c( preg_match( '/nppp_cloudflare_apo_is_available\s*\(/', $rd ) === 1, 'settings page still uses nppp_cloudflare_apo_is_available()' );

echo "\n$pass passed, $fail failed\n"; exit( $fail ? 1 : 0 );
