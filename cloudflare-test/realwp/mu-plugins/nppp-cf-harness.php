<?php
/**
 * Harness glue (test only). Loads the REAL includes/state.php and includes/compat-cloudflare.php into a REAL WordPress,
 * next to the REAL official Cloudflare plugin. The only things provided here are the seams the rest of NPP would provide:
 *   - nppp_display_admin_notice()  -> appended to NPPP_H_NOTICES (file), so a scenario can assert on what the user sees
 *   - trust for the local CA that signs the mock api.cloudflare.com (unless the option nppp_h_trust_ca is 'no')
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

define( 'NPPP_H_ROOT', getenv( 'NPPP_ROOT' ) ?: dirname( __DIR__, 3 ) );
define( 'NPPP_H_NOTICES', getenv( 'NPPP_H_NOTICES' ) ?: '/tmp/nppp-notices.log' );

function nppp_display_admin_notice( $type, $message, $log_message = true, $display_notice = true ) {
    file_put_contents( NPPP_H_NOTICES, $type . ': ' . $message . "\n", FILE_APPEND );
}

add_filter( 'http_request_args', function ( $args, $url ) {
    if ( wp_parse_url( $url, PHP_URL_HOST ) === 'api.cloudflare.com' && get_option( 'nppp_h_trust_ca' ) !== 'no' ) {
        $args['sslcertificates'] = getenv( 'NPPP_H_CA' ) ?: '/tmp/certs/ca.pem';
    }
    return $args;
}, 10, 2 );

require_once NPPP_H_ROOT . '/includes/state.php';
require_once NPPP_H_ROOT . '/includes/compat-cloudflare.php';
