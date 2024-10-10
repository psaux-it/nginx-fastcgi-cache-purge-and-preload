<?php
/**
 * Uninstallation script for FastCGI Cache Purge and Preload for Nginx
 * Description: This file handles the cleanup process when the FastCGI Cache Purge and Preload for Nginx plugin is uninstalled.
 * Version: 2.0.4
 * Author: Hasan ÇALIŞIR
 * Author Email: hasan.calisir@psauxit.com
 * Author URI: https://www.psauxit.com
 * License: GPL-2.0+
 */

// Exit if uninstall not called from WordPress
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit();
}

// Delete plugin options
delete_option('nginx_cache_settings');
