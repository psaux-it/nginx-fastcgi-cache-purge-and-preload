<?php
/**
 * REST API Helper for FastCGI Cache Purge and Preload for Nginx
 * Description: This file contains REST API related code for FastCGI Cache Purge and Preload for Nginx
 * Version: 2.0.3
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
        $status_message = 'The NPP API feature is currently disabled. You have reached a dummy endpoint.';
        return new WP_REST_Response(array(
            'success' => false,
            'message' => $status_message
        ), 403);
    }

    // Return the default result if no custom handling is needed
    return $result;
}

// Prevent caching of REST API responses for NPP endpoints
add_filter('rest_pre_serve_request', function($served, $result, $request, $server) {
    // Only apply to specific NPP endpoints
    $route = $request->get_route();
    if (strpos($route, '/nppp_nginx_cache/v2/purge') !== false || strpos($route, '/nppp_nginx_cache/v2/preload') !== false) {
        // Set headers to prevent caching
        header('Cache-Control: no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');
        header('Expires: Wed, 11 Jan 1984 05:00:00 GMT'); // Expired date in the past
    }
    return $served;
}, 10, 4);

// Prevent caching in W3 Total Cache for NPP endpoints
add_filter('w3tc_pgcache_cache_query', function($cache, $query_string) {
    if (strpos(esc_url_raw($_SERVER['REQUEST_URI']), '/wp-json/nppp_nginx_cache/v2/') !== false) {
        // Prevent caching of the API requests
        return false;
    }
    return $cache;
}, 10, 2);

// Prevent caching in WP Super Cache for NPP endpoints
add_filter('wpsupercache_buffer', function($buffer) {
    if (strpos(esc_url_raw($_SERVER['REQUEST_URI']), '/wp-json/nppp_nginx_cache/v2/') !== false) {
        // Prevent caching of the buffer
        return false;
    }
    return $buffer;
});

// Disable object caching for NPP endpoints
add_action('rest_api_init', function() {
    if (strpos(esc_url_raw($_SERVER['REQUEST_URI']), '/wp-json/nppp_nginx_cache/v2/') !== false) {
        wp_suspend_cache_addition(true);
    }
});

// Check NPP REST API status
if ($api_status === 'yes') {
    // Remove the rest_pre_dispatch filter when the API is enabled
    remove_filter('rest_pre_dispatch', 'nppp_handle_dummy_endpoints', 10);

    // Load main NPP REST API code
    require_once plugin_dir_path(__FILE__) . '../includes/rest-api.php';

    // Register real NPP REST API endpoints
    add_action('rest_api_init', 'nppp_nginx_cache_register_purge_endpoint');
    add_action('rest_api_init', 'nppp_nginx_cache_register_preload_endpoint');
} else {
    // Register dummy endpoints when the NPP REST API is disabled
    add_action('rest_api_init', function() {
        // Register dummy purge endpoint
        register_rest_route('nppp_nginx_cache/v2', '/purge', array(
            'methods' => 'POST',
            'callback' => '__return_null'
        ));

        // Register dummy preload endpoint
        register_rest_route('nppp_nginx_cache/v2', '/preload', array(
            'methods' => 'POST',
            'callback' => '__return_null'
        ));
    }, 10);

    // Catch calls to the endpoints when the NPP REST API is disabled
    // This sends user-friendly errors instead of generic 404 responses
    add_filter('rest_pre_dispatch', 'nppp_handle_dummy_endpoints', 10, 3);
}
