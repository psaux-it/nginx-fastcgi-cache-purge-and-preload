<?php
/**
 * Plugin Tracking for FastCGI Cache Purge and Preload for Nginx
 *
 * Description: This file handles tracking the plugin activation and deactivation status
 * and sends this information to the main API to track plugin statistics.
 *
 * Version: 2.0.4
 * Author: Hasan ÇALIŞIR
 * Author URI: https://www.psauxit.com
 * License: GPL-2.0+
 *
 * Security and API Usage:
 * =======================
 * This plugin uses two secure API endpoints for tracking plugin activation and deactivation.
 * The endpoints are used solely for the purpose of improving the plugin based on user statistics.
 *
 * 1. JWT Token Generation Endpoint
 * --------------------------------
 * - URL: https://api.psauxit.com/get-jwt
 * - Purpose: This endpoint generates a JWT token for authenticating plugin tracking requests.
 * - Data Sent:
 *   - site_url: The URL of the site using the plugin.
 *   - plugin_version: The version of the plugin in use.
 * - Security:
 *   - Data is transmitted securely over HTTPS.
 *   - No personal data is collected, only site URL and plugin version.
 *   - JWT token is valid for a short period and is used to authenticate requests.
 *
 * 2. Plugin Tracking Endpoint
 * ---------------------------
 * - URL: https://api.psauxit.com/rpc/upsert_plugin_tracking
 * - Purpose: This endpoint tracks the plugin's status (active/inactive/opt-out) for usage statistics.
 * - Data Sent:
 *   - p_plugin_name: The name of the plugin.
 *   - p_version: The version of the plugin.
 *   - p_status: The plugin status (active/inactive).
 *   - p_site_url: The site URL where the plugin is installed.
 * - Security:
 *   - Requests are made with the JWT token obtained from the previous endpoint.
 *   - All communication is encrypted over HTTPS.
 *   - Only technical information related to the plugin is collected (no personal data).
 *
 * Summary:
 * --------
 * - The plugin does not collect any personal data.
 * - Only necessary technical data is sent (site URL, plugin name, version, status).
 * - All communication is encrypted via HTTPS and authenticated using JWT tokens.
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Function to track plugin status
function nppp_plugin_tracking($status = 'active') {
    // Check if user has opted in
    $options = get_option('nginx_cache_settings');

    // Always send 'opt-out' or 'inactive' status regardless of opt-in preference
    if ($status === 'opt-out' || $status === 'inactive') {
        // Proceed to send data to API
    } else {
        // For other statuses, check if user has opted in
        if (!isset($options['nginx_cache_tracking_opt_in']) || $options['nginx_cache_tracking_opt_in'] !== '1') {
            // User has not opted in, do not send tracking data
            return;
        }
    }

    if (!function_exists('get_plugin_data')) {
        require_once(ABSPATH . 'wp-admin/includes/plugin.php');
    }

    // Get plugin file path
    $plugin_file_path = NPPP_PLUGIN_FILE;

    // Get plugin information
    $plugin_data = get_plugin_data($plugin_file_path);
    $plugin_name = $plugin_data['Name'];
    $plugin_version = $plugin_data['Version'];

    if (empty($plugin_name) || empty($plugin_version)) {
        nppp_custom_error_log('Plugin data not available. API call aborted.');
        return;
    }

    // Get token
    $response = wp_remote_post('https://api.psauxit.com/get-jwt', array(
        'body' => wp_json_encode(array(
            'site_url' => get_site_url(),
            'plugin_version' => $plugin_version
        )),
        'headers' => array('Content-Type' => 'application/json'),
        'timeout' => 15,
    ));

    // Parse token
    if (!is_wp_error($response)) {
        $body = json_decode(wp_remote_retrieve_body($response));
        if (isset($body->token)) {
            $nppp_jwt_token = $body->token;

            // Make API request with token.
            $tracking_response = wp_remote_post('https://api.psauxit.com/rpc/upsert_plugin_tracking', array(
                'body' => wp_json_encode(array(
                    'p_plugin_name' => $plugin_name,
                    'p_version' => $plugin_version,
                    'p_status' => $status,
                    'p_site_url' => get_site_url()
                )),
                'headers' => array(
                    'Content-Type' => 'application/json',
                    'Authorization' => 'Bearer ' . $nppp_jwt_token
                ),
                'timeout' => 15,
            ));

            // Log tracking failure
            if (is_wp_error($tracking_response)) {
                nppp_custom_error_log('Plugin tracking request failed: ' . $tracking_response->get_error_message());
            }
        }
    } else {
        nppp_custom_error_log('Failed to retrieve JWT token: ' . $response->get_error_message());
    }
}

// Schedule plugin tracking event
function nppp_schedule_plugin_tracking_event($status = false) {
    // If status is true, clear the scheduled hook and remove the action
    if ($status === true) {
        // Clear the scheduled event
        wp_clear_scheduled_hook('npp_plugin_tracking_event');

        // Remove the action that is tied to the event
        remove_action('npp_plugin_tracking_event', 'nppp_plugin_tracking');

        // Log the event clearing (optional)
        return;
    }

    // Create a DateTime object for the current time and modify it
    $wordpress_timezone = new DateTimeZone(wp_timezone_string());
    $selected_execution_time = new DateTime('now', $wordpress_timezone);

    // Schedule the event for the next day
    $selected_execution_time->modify('+1 day');

    // Convert DateTime object to timestamp
    $next_execution_timestamp = $selected_execution_time->getTimestamp();

    // Set recurrence
    $recurrence = 'daily';

    // Check if the event is already scheduled
    if (!wp_next_scheduled('npp_plugin_tracking_event')) {
        $scheduled = wp_schedule_event($next_execution_timestamp, $recurrence, 'npp_plugin_tracking_event');
        if (!$scheduled) {
            nppp_custom_error_log('Failed to schedule plugin tracking event.');
        }
    }

    // Register the callback function for the scheduled event
    if (!has_action('npp_plugin_tracking_event', 'nppp_plugin_tracking')) {
        add_action('npp_plugin_tracking_event', 'nppp_plugin_tracking');
    }
}

// Function to handle changes in the opt-in status
function nppp_handle_opt_in_change($opt_in_value) {
    $opt_in_value = strval($opt_in_value);
    if ($opt_in_value == '1') {
        // User opted in
        nppp_plugin_tracking('active');
        nppp_schedule_plugin_tracking_event();
    } else {
        // User opted out
        nppp_plugin_tracking('opt-out');
        nppp_schedule_plugin_tracking_event(true);
    }
}
