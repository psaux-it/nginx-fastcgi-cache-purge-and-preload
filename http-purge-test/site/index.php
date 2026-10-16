<?php
// Stand-in for WordPress: every path answers 200 and is cacheable.
header( 'Cache-Control: public, max-age=3600' );
// /v/... honours Vary: the same URL is cached once per Accept-Encoding value (scenarios vary / vary_off).
if ( strpos( $_SERVER['REQUEST_URI'], '/v/' ) === 0 ) { header( 'Vary: Accept-Encoding' ); }
echo '<!doctype html><title>page</title>page ' . htmlspecialchars( $_SERVER['REQUEST_URI'] ) . "\n" . str_repeat( 'x', 800 );
