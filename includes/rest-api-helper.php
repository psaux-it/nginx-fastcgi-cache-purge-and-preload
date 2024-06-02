<?php
/**
 * REST API Helper for FastCGI Cache Purge and Preload for Nginx
 * Description: This file contains REST API related code for FastCGI Cache Purge and Preload for Nginx
 * Version: 2.0.1
 * Author: Hasan ÇALIŞIR
 * Author Email: hasan.calisir@psauxit.com
 * Author URI: https://www.psauxit.com
 * License: GPL-2.0+
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Rest API Helper
$options = get_option('nginx_cache_settings');
$api_status = isset($options['nginx_cache_api']) ? $options['nginx_cache_api'] : '';

if ($api_status === 'yes') {
    require_once plugin_dir_path( __FILE__ ) . '../includes/rest-api.php';

    // Add actions for REST API endpoints
    add_action('rest_api_init', 'nppp_nginx_cache_register_purge_endpoint');
    add_action('rest_api_init', 'nppp_nginx_cache_register_preload_endpoint');
}
