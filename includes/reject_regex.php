<?php
/*
Description: Automatically preload Nginx cache by crawling the website using WordPress HTTP API and DOMDocument.
Author: Hasan ÇALIŞIR | hasan.calisir@psauxit.com
Author URI: https://www.psauxit.com
License: GPL2
*/

$reject_regex = '#/wp-admin/|/wp-includes/|/wp-json/|/xmlrpc.php|/wp-login.php|/wp-register.php|/wp-content/|/cart/|/checkout/|/my-account/|/wc-api/#';
