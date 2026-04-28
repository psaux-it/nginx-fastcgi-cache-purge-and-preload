<?php
/**
 * Settings for Nginx Cache Purge Preload
 * Description: Loader — delegates to sub-modules.
 * Version: 2.1.6
 * Author: Hasan CALISIR
 * Author Email: hasan.calisir@psauxit.com
 * Author URI: https://www.psauxit.com
 * License: GPL-2.0+
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

require_once __DIR__ . '/settings-registration.php';
require_once __DIR__ . '/settings-page.php';
require_once __DIR__ . '/settings-ajax.php';
require_once __DIR__ . '/settings-callbacks.php';
require_once __DIR__ . '/settings-sanitize.php';
require_once __DIR__ . '/settings-lifecycle.php';
