<?php
// Stand-in for WordPress: every path answers 200 and is cacheable.
header( 'Cache-Control: public, max-age=3600' );
echo '<!doctype html><title>page</title>page ' . htmlspecialchars( $_SERVER['REQUEST_URI'] ) . "\n" . str_repeat( 'x', 800 );
