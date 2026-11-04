<?php
/**
 * Boots the REAL includes/state.php + includes/compat-cloudflare.php on the WordPress / Cloudflare-plugin stub.
 * Usage: require boot.php after setting $BOOT_MODE ( 'new' | 'old' | 'both' | 'none' | 'broken' ).
 */
require_once __DIR__ . '/../lib/wpstub.php';

$GLOBALS['T'] = t_fresh();
$mode = $BOOT_MODE ?? 'new';
if ( $mode === 'real' ) {
    // The REAL official plugin source ( env CF_SRC = unpacked plugin dir, with its vendor/ ).
    define( 'CLOUDFLARE_PLUGIN_DIR', rtrim( getenv( 'CF_SRC' ) ?: dirname( __DIR__, 3 ) . '/cloudflare', '/' ) . '/' );
    if ( getenv( 'CF_LAYOUT' ) === 'psr4' ) {
        // 4.12.8 / 4.13.x ship no vendor/ in git: CF\ is PSR-4 over src/, psr/log is stubbed.
        require_once __DIR__ . '/../lib/psrlog-stub.php';
        spl_autoload_register( function ( $c ) { if ( strpos( $c, 'CF\\' ) === 0 ) { $f = CLOUDFLARE_PLUGIN_DIR . 'src/' . str_replace( '\\', '/', substr( $c, 3 ) ) . '.php'; if ( is_file( $f ) ) { require_once $f; } } } );
    } else {
        require_once CLOUDFLARE_PLUGIN_DIR . 'vendor/autoload.php';
    }
} else {
    cf_fake_install( $mode );
    if ( $mode === 'new' || $mode === 'both' ) { cf_define_classes( 'Cloudflare\\APO' ); }
    if ( $mode === 'old' || $mode === 'both' ) { cf_define_classes( 'CF' ); }
}

$root = dirname( __DIR__, 2 );
require_once $root . '/includes/state.php';
require_once $root . '/includes/compat-cloudflare.php';

$GLOBALS['BASE'] = array( 'actions' => $GLOBALS['T']['actions'], 'filters' => $GLOBALS['T']['filters'] );
function t_reset(): void {
    $GLOBALS['T'] = t_fresh();
    $GLOBALS['T']['actions'] = $GLOBALS['BASE']['actions'];
    $GLOBALS['T']['filters'] = $GLOBALS['BASE']['filters'];
    nppp_cloudflare_apo_local_park( 0, true );
}
/** Rewinds a state row's deadline into the past (the harness cannot move time(); this is the same effect). */
function t_expire( string $key ): void { $n = nppp_state_option_name( $key ); if ( isset( $GLOBALS['T']['options'][ $n ] ) ) { $GLOBALS['T']['options'][ $n ]['exp'] = time() - 1; } }
function t_enable( bool $on = true ): void { $GLOBALS['T']['options']['nginx_cache_settings'] = array( 'nppp_cloudflare_apo_sync' => $on ? 'yes' : 'no' ); }
function t_state( string $key ) { return nppp_state_get( $key, true ); }

$GLOBALS['PASS'] = 0; $GLOBALS['FAIL'] = 0;
function ok( $cond, string $name ): void {
    if ( $cond ) { $GLOBALS['PASS']++; echo "  ok   $name\n"; } else { $GLOBALS['FAIL']++; echo "  FAIL $name\n"; }
}
function section( string $n ): void { echo "\n== $n\n"; }
function finish(): void { echo "\n" . $GLOBALS['PASS'] . " passed, " . $GLOBALS['FAIL'] . " failed\n"; exit( $GLOBALS['FAIL'] ? 1 : 0 ); }

const ZONE_ID = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
/** Script the happy API: zone lookup + purge. */
function t_happy(): void { t_script( '#GET .*/zones\?#', t_zone_ok() ); t_script( '#POST .*/purge_cache#', t_ok() ); }
