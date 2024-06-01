<?php
/**
 * Rest API for FastCGI Cache Purge and Preload for Nginx
 * Description: This file contains rest api functions for FastCGI Cache Purge and Preload for Nginx
 * Version: 2.0.0
 * Author: Hasan ÇALIŞIR
 * Author Email: hasan.calisir@psauxit.com
 * Author URI: https://www.psauxit.com
 * License: GPL-2.0+
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Permission callback function to check API key validity
function nppp_nginx_cache_authorize_endpoint($request) {
    // Sanitize API key.
    $api_key = sanitize_text_field($request->get_param('api_key'));
    if (!preg_match('/^[a-f0-9]{64}$/i', $api_key)) {
        return new WP_Error('invalid_api_key', 'Invalid API Key.', array('status' => 403));
    }

    $options = get_option('nginx_cache_settings');
    $stored_key = isset($options['nginx_cache_api_key']) ? $options['nginx_cache_api_key'] : '';
    if ($api_key !== $stored_key) {
        return new WP_Error('invalid_api_key', 'Invalid API Key.', array('status' => 403));
    }

    return true;
}

// Register the REST API endpoint for purge action.
function nppp_nginx_cache_register_purge_endpoint() {
    register_rest_route('nppp_nginx_cache/v2', '/purge', array(
        'methods' => 'POST',
        'callback' => 'nppp_nginx_cache_purge_endpoint',
        'args' => array(
            'api_key' => array(
                'required' => true,
                'description' => 'API Key for authentication.',
                'type' => 'string',
            ),
        ),
        'permission_callback' => 'nppp_nginx_cache_authorize_endpoint',
    ));
}

// Register the REST API endpoint for preload action.
function nppp_nginx_cache_register_preload_endpoint() {
    register_rest_route('nppp_nginx_cache/v2', '/preload', array(
        'methods' => 'POST',
        'callback' => 'nppp_nginx_cache_preload_endpoint',
        'args' => array(
            'api_key' => array(
                'required' => true,
                'description' => 'API Key for authentication.',
                'type' => 'string',
            ),
        ),
        'permission_callback' => 'nppp_nginx_cache_authorize_endpoint',
    ));
}

// Handle the REST API request for purge action.
function nppp_nginx_cache_purge_endpoint($request) {
    // Necessary data for purge action
    $nginx_cache_settings = get_option('nginx_cache_settings');
    $default_cache_path = '/dev/shm/change-me-now';
    $nginx_cache_path = isset($nginx_cache_settings['nginx_cache_path']) ? $nginx_cache_settings['nginx_cache_path'] : $default_cache_path;
    $PIDFILE = rtrim($this_script_path, '/') . '/cache_preload.pid';
    $tmp_path = rtrim($nginx_cache_path, '/') . "/tmp";

    // Call purge action
    ob_start();
    nppp_purge($nginx_cache_path, $PIDFILE, $tmp_path, true, false);
    // Get status message
    $status_message = wp_strip_all_tags(ob_get_clean());

    // Return status response
    return array('success' => true, 'status_message' => $status_message);
}

// Handle the REST API request for preload action.
function nppp_nginx_cache_preload_endpoint($request) {
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
    nppp_preload($nginx_cache_path, $this_script_path, $tmp_path, $fdomain, $PIDFILE, $nginx_cache_reject_regex, $nginx_cache_limit_rate, $nginx_cache_cpu_limit, false, true, false, false);
    // Get status message
    $status_message = wp_strip_all_tags(ob_get_clean());

    // Return status response.
    return array('success' => true, 'status_message' => $status_message);
}
