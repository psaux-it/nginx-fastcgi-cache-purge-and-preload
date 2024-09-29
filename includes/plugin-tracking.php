<?php
/**
 * Plugin Tracking for FastCGI Cache Purge and Preload for Nginx
 * Description: This file handles tracking the plugin activation and deactivation status and sends this information to the main API to track plugin statistics
 * and sends this information to the FastCGI Cache Purge and Preload for Nginx API.
 * Version: 2.0.3
 * Author: Hasan ÇALIŞIR
 * Author URI: https://www.psauxit.com
 * License: GPL-2.0+
 */

// Function to track plugin status
function nppp_plugin_tracking($status = 'active') {
    if (!function_exists('get_plugin_data')) {
        require_once(ABSPATH . 'wp-admin/includes/plugin.php');
    }

    // Get plugin file path
    $plugin_file_path = plugin_dir_path(__FILE__) . '../fastcgi-cache-purge-and-preload-nginx.php';

    // Get plugin information
    $plugin_data = get_plugin_data($plugin_file_path);
    $plugin_name = $plugin_data['Name'];
    $plugin_version = $plugin_data['Version'];

    // Get token
    $response = wp_remote_post('https://api.psauxit.com/get-jwt', array(
        'body' => wp_json_encode(array(
            'site_url' => get_site_url(),
            'plugin_version' => $plugin_version
        )),
        'headers' => array('Content-Type' => 'application/json'),
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
            ));

            // Log tracking failure
            if (is_wp_error($tracking_response)) {
                error_log('Plugin tracking request failed: ' . $tracking_response->get_error_message());
            }
        }
    } else {
        error_log('Failed to retrieve JWT token: ' . $response->get_error_message());
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
            error_log('Failed to schedule plugin tracking event.');
        }
    }

    // Register the callback function for the scheduled event
    if (!has_action('npp_plugin_tracking_event', 'nppp_plugin_tracking')) {
        add_action('npp_plugin_tracking_event', 'nppp_plugin_tracking');
    }
}
