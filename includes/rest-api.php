<?php
/**
 * Rest API for FastCGI Cache Purge and Preload for Nginx
 * Description: This file contains rest api functions for FastCGI Cache Purge and Preload for Nginx
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

// Retrieve the client's IP address, considering proxies.
function nppp_get_client_ip() {
    // Check for HTTP_CLIENT_IP
    if (isset($_SERVER['HTTP_CLIENT_IP']) && ! empty($_SERVER['HTTP_CLIENT_IP'])) {
        return sanitize_text_field(wp_unslash($_SERVER['HTTP_CLIENT_IP']));
    }

    // Check for HTTP_X_FORWARDED_FOR (handles multiple IPs)
    if (isset($_SERVER['HTTP_X_FORWARDED_FOR']) && ! empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        // Sanitize the entire X-Forwarded-For header
        $sanitized_x_forwarded_for = sanitize_text_field(wp_unslash($_SERVER['HTTP_X_FORWARDED_FOR']));

        // Handle multiple IP addresses (comma-separated)
        $ip_addresses = explode(',', $sanitized_x_forwarded_for);

        // Trim whitespace from the first IP address
        if (isset($ip_addresses[0])) {
            $client_ip = trim($ip_addresses[0]);
            return sanitize_text_field($client_ip);
        }
    }

    // Check for REMOTE_ADDR
    if (isset($_SERVER['REMOTE_ADDR']) && ! empty($_SERVER['REMOTE_ADDR'])) {
        return sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR']));
    }

    // If none of the above, return empty string
    return '';
}

// Determine the endpoint action based on the current route.
function nppp_get_endpoint_action($route) {
    switch ( $route ) {
        case '/nppp_nginx_cache/v2/purge':
            return 'purge';
        case '/nppp_nginx_cache/v2/preload':
            return 'preload';
        default:
            return '';
    }
}

// Add CORS and No-Cache headers to specific REST API endpoints.
add_action('rest_api_init', 'nppp_register_cors_headers');

// Register the callback for adding CORS and No-Cache headers.
function nppp_register_cors_headers() {
    add_action('rest_pre_serve_request', 'nppp_add_cors_and_no_cache_headers', 10, 3);
}

// Add CORS and No-Cache headers to the response for specific endpoints.
function nppp_add_cors_and_no_cache_headers($served, $result, $request) {
    // Define the specific routes to target
    $allowed_routes = array(
        '/nppp_nginx_cache/v2/purge',
        '/nppp_nginx_cache/v2/preload',
    );

    // Retrieve the current route
    $route = $request->get_route();

    // Check if the current route is one of the allowed routes
    if (in_array( $route, $allowed_routes, true)) {
        // Add CORS headers
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Api-Key');
        header('Access-Control-Max-Age: 86400' );

        // Add No-Cache headers
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0' );
        header('Cache-Control: post-check=0, pre-check=0', false );
        header('Pragma: no-cache' );
        header('Expires: 0' );

        // Handle preflight OPTIONS request
        if (isset($_SERVER['REQUEST_METHOD']) && 'OPTIONS' === $_SERVER['REQUEST_METHOD']) {
            status_header( 200 );
            exit();
        }
    }

    return $served;
}

// Log NPP REST API calls
function nppp_log_api_request($endpoint, $status) {
    // Get the IP address
    $ip_address = nppp_get_client_ip();

    // Determine log prefix based on the status
    $log_prefix = (strpos($status, 'ERROR') !== false) ? 'ERROR API' : 'API REQUEST';

    // Create a log entry with timestamp, IP, endpoint, and status
    $log_entry = sprintf(
        "[%s] %s: IP: %s | Endpoint: %s | Status: %s",
        current_time('Y-m-d H:i:s'),
        $log_prefix,
        $ip_address,
        $endpoint,
        $status
    );

    // Check if the log file path is defined
    if (!defined('NGINX_CACHE_LOG_FILE')) {
        // If the log file path is not defined or empty
        define('NGINX_CACHE_LOG_FILE', dirname(__FILE__) . '/../fastcgi_ops.log');
    }

    // Sanitize the file path to prevent directory traversal
    $log_file_path = NGINX_CACHE_LOG_FILE;
    $log_file_dir  = dirname($log_file_path);
    $log_file_name = basename($log_file_path);

    // Use realpath() to sanitize the directory
    $sanitized_dir_path = realpath($log_file_dir);

    // Check if the directory is valid and exists
    if ($sanitized_dir_path === false) {
        nppp_custom_error_log("Invalid or inaccessible log file directory: " . $log_file_dir);
        return;
    }

    // Reconstruct the sanitized path for the file
    $sanitized_path = $sanitized_dir_path . '/' . $log_file_name;

    // Attempt to create the log file before append new log entry
    nppp_perform_file_operation($sanitized_path, 'create');

    // Attempt to append the log entry
    $append_result = nppp_perform_file_operation($sanitized_path, 'append', $log_entry);

    // Check the append log status
    if (!$append_result) {
        nppp_custom_error_log("Error appending to log file at " . $sanitized_path);
        return;
    }
}

// Rate limit API requests
function nppp_api_rate_limit_check($ip_address, $endpoint) {
    $transient_key = 'nppp_rate_limit_' . md5($ip_address . $endpoint);

    // Set rate limit based on the endpoint, 1 request in 1 Minute
    $rate_limit = ($endpoint === 'purge') ? 1 : 1;

    // Get the count of requests made by this IP and endpoint
    $request_count = get_transient($transient_key);

    if (false === $request_count) {
        // First request, set the transient with a lifespan of 1 minute
        set_transient($transient_key, 1, 60);
    } else {
        $request_count++;

        // Limit requests to 1 per minute
        if ($request_count > $rate_limit) {
            nppp_log_api_request($endpoint, "ERROR 429 TOO MANY REQUEST");
            return new WP_Error('rate_limit_error', 'NPP REST API Rate Limit Exceeded. Wait 1 Minute.', array('status' => 429));
        }

        // Update the request count
        set_transient($transient_key, $request_count, 60);
    }

    return true;
}

// Register the REST API endpoint for purge action.
function nppp_nginx_cache_register_purge_endpoint() {
    register_rest_route('nppp_nginx_cache/v2', '/purge', array(
        'methods' => 'POST',
        'callback' => 'nppp_nginx_cache_purge_endpoint',
        'permission_callback' => '__return_true',
    ));
}

// Register the REST API endpoint for preload action.
function nppp_nginx_cache_register_preload_endpoint() {
    register_rest_route('nppp_nginx_cache/v2', '/preload', array(
        'methods' => 'POST',
        'callback' => 'nppp_nginx_cache_preload_endpoint',
        'permission_callback' => '__return_true',
    ));
}

// Validation, authentication, rate limiting
function nppp_validate_and_rate_limit_endpoint($request) {
    // Retrieve API key from Authorization header
    $api_key = $request->get_header('Authorization');

    // Check if Authorization header contains a Bearer token
    if (!empty($api_key) && strpos($api_key, 'Bearer ') === 0) {
        $api_key = substr($api_key, 7);
    }

    // 2. Fallback to the X-Api-Key header if Authorization is not found
    if (empty($api_key)) {
        $api_key = $request->get_header('X-Api-Key');
    }

    // 3. Fallback to the request body 'api_key' if no header is found
    if (empty($api_key)) {
        $api_key = $request->get_param('api_key');
    }

    // 4. If no API key is provided, return an error
    // or Sanitize API Key
    if (empty($api_key)) {
        return new WP_Error('authentication_error', 'No API Key provided', array('status' => 403));
    } else {
        $api_key = sanitize_text_field($api_key);
    }

    // Get the IP address for rate limiting
    $ip_address = nppp_get_client_ip();

    // Get the current route to determine the endpoint
    $route = $request->get_route();
    $endpoint = nppp_get_endpoint_action($route);

    // Perform rate limit check
    $rate_limit = nppp_api_rate_limit_check($ip_address, $endpoint);
    if (is_wp_error($rate_limit)) {
        return $rate_limit;
    }

    // Validate API key format
    if (!preg_match('/^[a-f0-9]{64}$/i', $api_key)) {
        nppp_log_api_request($endpoint, 'ERROR 403 INVALID API KEY');
        return new WP_Error('validation_error', 'NPP REST API Invalid API Key', array('status' => 403));
    }

    // Retrieve the stored API key from options
    $options = get_option('nginx_cache_settings');
    $stored_key = isset($options['nginx_cache_api_key']) ? $options['nginx_cache_api_key'] : '';

    // Authentication check
    if (!hash_equals($stored_key, $api_key)) {
        nppp_log_api_request($endpoint, 'ERROR 403 AUTHENTICATION FAILED');
        return new WP_Error('authentication_error', 'NPP REST API Authentication Error', array('status' => 403));
    }

    // Everything passed
    return true;
}

// Handle the REST API request for purge action.
function nppp_nginx_cache_purge_endpoint($request) {
    // Validate the API key and check rate limit
    $validation = nppp_validate_and_rate_limit_endpoint($request);
    if (is_wp_error($validation)) {
        return $validation;
    }

    // Log the successful purge API call
    // Not hit the rate limit, authentication errors
    nppp_log_api_request('purge', 'SUCCESS 200 OK');

    // Necessary data for purge action
    $nginx_cache_settings = get_option('nginx_cache_settings');
    $default_cache_path = '/dev/shm/change-me-now';
    $nginx_cache_path = isset($nginx_cache_settings['nginx_cache_path']) ? $nginx_cache_settings['nginx_cache_path'] : $default_cache_path;
    $this_script_path = dirname(plugin_dir_path(__FILE__));
    $PIDFILE = rtrim($this_script_path, '/') . '/cache_preload.pid';
    $tmp_path = rtrim($nginx_cache_path, '/') . "/tmp";

    // Call purge action
    ob_start();
    nppp_purge(
        $nginx_cache_path,
        $PIDFILE,
        $tmp_path,
        true,  // $nppp_is_rest_api
        false, // $nppp_is_admin_bar
        false  // $nppp_is_auto_purge
    );

    // Get status message
    $status_message = wp_strip_all_tags(ob_get_clean());

    // Return status response
    return new WP_REST_Response(array(
        'success' => true,
        'message' => $status_message
    ), 200);
}

// Handle the REST API request for preload action.
function nppp_nginx_cache_preload_endpoint($request) {
    // Validate the API key and check rate limit
    $validation = nppp_validate_and_rate_limit_endpoint($request);
    if (is_wp_error($validation)) {
        return $validation;
    }

    // Log the successful preload API call
    // Not hit the rate limit, authentication errors
    nppp_log_api_request('preload', 'SUCCESS 200 OK');

    // Get the plugin options
    $nginx_cache_settings = get_option('nginx_cache_settings');

    // Set default options to prevent any error
    $default_cache_path = '/dev/shm/change-me-now';
    $default_limit_rate = 1280;
    $default_cpu_limit = 50;
    $default_reject_regex = nppp_fetch_default_reject_regex();

    // Get the necessary data for preload action from plugin options
    $nginx_cache_path = isset($nginx_cache_settings['nginx_cache_path']) ? $nginx_cache_settings['nginx_cache_path'] : $default_cache_path;
    $nginx_cache_limit_rate = isset($nginx_cache_settings['nginx_cache_limit_rate']) ? $nginx_cache_settings['nginx_cache_limit_rate'] : $default_limit_rate;
    $nginx_cache_cpu_limit = isset($nginx_cache_settings['nginx_cache_cpu_limit']) ? $nginx_cache_settings['nginx_cache_cpu_limit'] : $default_cpu_limit;
    $nginx_cache_reject_regex = isset($nginx_cache_settings['nginx_cache_reject_regex']) ? $nginx_cache_settings['nginx_cache_reject_regex'] : $default_reject_regex;

    // Extra data for preload action
    $fdomain = get_site_url();
    $this_script_path = dirname(plugin_dir_path(__FILE__));
    $PIDFILE = rtrim($this_script_path, '/') . '/cache_preload.pid';
    $tmp_path = rtrim($nginx_cache_path, '/') . "/tmp";

    // Call preload action
    ob_start();
    nppp_preload(
        $nginx_cache_path,
        $this_script_path,
        $tmp_path,
        $fdomain,
        $PIDFILE,
        $nginx_cache_reject_regex,
        $nginx_cache_limit_rate,
        $nginx_cache_cpu_limit,
        false, // $nppp_is_auto_preload
        true,  // $nppp_is_rest_api
        false, // $nppp_is_wp_cron
        false  // $nppp_is_admin_bar
    );

    // Get status message
    $status_message = wp_strip_all_tags(ob_get_clean());

    // Return status response.
    return new WP_REST_Response(array(
        'success' => true,
        'message' => $status_message
    ), 200);
}
