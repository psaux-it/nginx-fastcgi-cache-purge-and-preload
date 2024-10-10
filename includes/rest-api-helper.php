<?php
/**
 * REST API Helper for FastCGI Cache Purge and Preload for Nginx
 * Description: This file contains REST API related code for FastCGI Cache Purge and Preload for Nginx
 * Version: 2.0.4
 * Author: Hasan ÇALIŞIR
 * Author Email: hasan.calisir@psauxit.com
 * Author URI: https://www.psauxit.com
 * License: GPL-2.0+
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Retrieve NPP REST API status
$options = get_option('nginx_cache_settings');
$api_status = isset($options['nginx_cache_api']) ? $options['nginx_cache_api'] : '';

// Listen for NPP REST API calls and handle dummy endpoints
function nppp_handle_dummy_endpoints($result, $server, $request) {
    $route = $request->get_route();

    // Check if the request is for the NPP plugin's endpoints
    if (strpos($route, '/nppp_nginx_cache/v2/purge') !== false || strpos($route, '/nppp_nginx_cache/v2/preload') !== false) {
        // Return a custom response when the API feature is disabled
        $status_message = 'The NPP REST API feature is disabled.';
        return new WP_REST_Response(array(
            'success' => true,
            'message' => $status_message
        ), 200);
    }

    // Return the default result if no custom handling is needed
    return $result;
}

// Check NPP REST API status
if ($api_status === 'yes') {
    // Remove the rest_pre_dispatch filter when the API is enabled
    remove_filter('rest_pre_dispatch', 'nppp_handle_dummy_endpoints', 10);

    // De-register dummy endpoints
    remove_action('rest_api_init', 'nppp_register_dummy_endpoints');

    // Load main NPP REST API code
    require_once dirname(__FILE__) . '/rest-api.php';

    // Register real NPP REST API endpoints
    if (!has_action('rest_api_init', 'nppp_nginx_cache_register_purge_endpoint')) {
        add_action('rest_api_init', 'nppp_nginx_cache_register_purge_endpoint');
    }

    if (!has_action('rest_api_init', 'nppp_nginx_cache_register_preload_endpoint')) {
        add_action('rest_api_init', 'nppp_nginx_cache_register_preload_endpoint');
    }
} else {
    // De-register real NPP REST API endpoints
    remove_action('rest_api_init', 'nppp_nginx_cache_register_purge_endpoint');
    remove_action('rest_api_init', 'nppp_nginx_cache_register_preload_endpoint');

    // Register dummy endpoints when the NPP REST API is disabled
    if (!has_action('rest_api_init', 'nppp_register_dummy_endpoints')) {
        add_action('rest_api_init', 'nppp_register_dummy_endpoints');
    }

    // Catch calls to the endpoints when the NPP REST API is disabled
    // This sends user-friendly errors instead of generic 404 responses
    add_filter('rest_pre_dispatch', 'nppp_handle_dummy_endpoints', 10, 3);
}

// Function to register dummy endpoints
function nppp_register_dummy_endpoints() {
    // Register dummy purge endpoint
    register_rest_route('nppp_nginx_cache/v2', '/purge', array(
        'methods' => 'POST',
        'callback' => '__return_null',
        'permission_callback' => '__return_true',
    ));

    // Register dummy preload endpoint
    register_rest_route('nppp_nginx_cache/v2', '/preload', array(
        'methods' => 'POST',
        'callback' => '__return_null',
        'permission_callback' => '__return_true',
    ));
}
