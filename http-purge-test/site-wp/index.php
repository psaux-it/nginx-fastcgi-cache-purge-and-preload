<?php
// Stand-in for a real WordPress: a path that is not a page answers 404 (the purge prefix is not a page), every other
// path answers 200 and is cacheable. Used by the inline* / noloc scenarios; site/index.php keeps answering 200 everywhere.
header( 'Cache-Control: public, max-age=3600' );
if ( strpos( $_SERVER['REQUEST_URI'], '/npp_purge' ) === 0 ) { http_response_code( 404 ); }
echo '<!doctype html><title>page</title>page ' . htmlspecialchars( $_SERVER['REQUEST_URI'] ) . ' ' . $_SERVER['REQUEST_METHOD'] . "\n" . str_repeat( 'x', 800 );
