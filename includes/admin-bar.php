<?php
/**
 * WP Admin Bar code for FastCGI Cache Purge and Preload for Nginx
 * Description: This file contains Admin Bar code for FastCGI Cache Purge and Preload for Nginx
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

// Add buttons to WordPress admin bar
function nppp_add_fastcgi_cache_buttons_admin_bar($wp_admin_bar) {
    // Check if the user has permissions to manage options
    if (!current_user_can('manage_options')) {
        return;
    }

    // Add a parent menu item for FastCGI cache operations
    $wp_admin_bar->add_menu(array(
        'id' => 'fastcgi-cache-operations',
        'title' => '<img style="height: 20px; margin-bottom: -4px; padding-right: 3px;" src="' . plugin_dir_url(__FILE__) . '../admin/img/bar.png" alt="NPP" title="NPP"> FastCGI Cache',
        'href' => '#',
        'meta' => array(
            'class' => 'npp-icon',
        ),
    ));

    // Add purge submenu
    $wp_admin_bar->add_menu(array(
        'parent' => 'fastcgi-cache-operations',
        'id' => 'purge-cache',
        'title' => 'Purge All',
        'href'   => wp_nonce_url(admin_url('admin.php?action=nppp_purge_cache'), 'purge_cache_nonce'),
    ));

    // Add preload submenu
    $wp_admin_bar->add_menu(array(
        'parent' => 'fastcgi-cache-operations',
        'id' => 'preload-cache',
        'title' => 'Preload All',
        'href' => wp_nonce_url(admin_url('admin.php?action=nppp_preload_cache'), 'preload_cache_nonce'),
    ));

    // Add single purge and preload submenu only if not in wp-admin
    if (isset($_SERVER['REQUEST_URI']) && !empty($_SERVER['REQUEST_URI'])) {
        // Unslash and sanitize the REQUEST_URI
        $request_uri = sanitize_text_field(wp_unslash($_SERVER['REQUEST_URI']));

        // Check if the URI does not contain 'wp-admin'
        if (strpos($request_uri, 'wp-admin') === false) {
            // Add child menu items - Add purge single submenu
            $wp_admin_bar->add_menu(array(
                'parent' => 'fastcgi-cache-operations',
                'id' => 'purge-cache-single',
                'title' => 'Purge This Page',
                'href'  => wp_nonce_url(admin_url('admin.php?action=nppp_purge_cache_single'), 'purge_cache_nonce'),
            ));

            // Add child menu items - Add preload single submenu
            $wp_admin_bar->add_menu(array(
                'parent' => 'fastcgi-cache-operations',
                'id' => 'preload-cache-single',
                'title' => 'Preload This Page',
                'href'  => wp_nonce_url(admin_url('admin.php?action=nppp_preload_cache_single'), 'preload_cache_nonce'),
            ));
        }
    }

    // Add status submenu
    $wp_admin_bar->add_menu(array(
        'parent' => 'fastcgi-cache-operations',
        'id' => 'fastcgi-cache-status',
        'title' => 'Cache Status',
        'href' => admin_url('options-general.php?page=nginx_cache_settings#status'),
    ));

    // Add settings submenu
    $wp_admin_bar->add_menu(array(
        'parent' => 'fastcgi-cache-operations',
        'id' => 'fastcgi-cache-settings',
        'title' => 'Cache Settings',
        'href' => admin_url('options-general.php?page=nginx_cache_settings'),
    ));
}

// Handle button clicks with actions
function nppp_handle_fastcgi_cache_actions_admin_bar() {
    // Check action
    if (!isset($_GET['_wpnonce']) || !isset($_GET['action'])) {
        return;
    }

    // Sanitize and verify nonce
    $nonce = isset($_GET['_wpnonce']) ? sanitize_text_field(wp_unslash($_GET['_wpnonce'])) : '';
    $action = isset($_GET['action']) ? sanitize_text_field(wp_unslash($_GET['action'])) : '';

    if (!wp_verify_nonce($nonce, 'purge_cache_nonce') && !wp_verify_nonce($nonce, 'preload_cache_nonce')) {
        return;
    }

    // Get the plugin options
    $nginx_cache_settings = get_option('nginx_cache_settings');

    // Set default options to prevent any error
    $default_cache_path = '/dev/shm/change-me-now';
    $default_limit_rate = 1280;
    $default_cpu_limit = 50;
    $default_reject_regex = nppp_fetch_default_reject_regex();

    // Get the necessary data for actions from plugin options
    $nginx_cache_path = isset($nginx_cache_settings['nginx_cache_path']) ? $nginx_cache_settings['nginx_cache_path'] : $default_cache_path;
    $nginx_cache_limit_rate = isset($nginx_cache_settings['nginx_cache_limit_rate']) ? $nginx_cache_settings['nginx_cache_limit_rate'] : $default_limit_rate;
    $nginx_cache_cpu_limit = isset($nginx_cache_settings['nginx_cache_cpu_limit']) ? $nginx_cache_settings['nginx_cache_cpu_limit'] : $default_cpu_limit;
    $nginx_cache_reject_regex = isset($nginx_cache_settings['nginx_cache_reject_regex']) ? $nginx_cache_settings['nginx_cache_reject_regex'] : $default_reject_regex;

    // Extra data for actions
    $fdomain = get_site_url();
    $this_script_path = dirname(plugin_dir_path(__FILE__));
    $PIDFILE = rtrim($this_script_path, '/') . '/cache_preload.pid';
    $tmp_path = rtrim($nginx_cache_path, '/') . "/tmp";

    // get the current page url for purge & preload front
    // also this is the redirect URL
    if (empty($_SERVER['HTTP_REFERER'])) {
        $clean_current_page_url = home_url();
    } else {
        // Sanitize and remove slashes from the URL
        $current_page_url = sanitize_text_field(wp_unslash($_SERVER['HTTP_REFERER']));
        $clean_current_page_url = filter_var($current_page_url, FILTER_SANITIZE_URL);
    }

    // Start output buffering to capture the output of the actions
    ob_start();

    switch ($_GET['action']) {
        case 'nppp_purge_cache':
            nppp_purge($nginx_cache_path, $PIDFILE, $tmp_path, false, true, false);
            $nppp_single_action = false;
            break;
        case 'nppp_preload_cache':
            nppp_preload($nginx_cache_path, $this_script_path, $tmp_path, $fdomain, $PIDFILE, $nginx_cache_reject_regex, $nginx_cache_limit_rate, $nginx_cache_cpu_limit, false, false, false, true);
            $nppp_single_action = false;
            break;
        case 'nppp_purge_cache_single':
            nppp_purge_single($nginx_cache_path, $clean_current_page_url, false);
            $nppp_single_action = true;
            break;
        case 'nppp_preload_cache_single':
            nppp_preload_single($clean_current_page_url, $PIDFILE, $tmp_path, $nginx_cache_reject_regex, $nginx_cache_limit_rate, $nginx_cache_cpu_limit, $nginx_cache_path);
            $nppp_single_action = true;
            break;
        default:
            break;
    }

    // Get the status message from the output buffer
    $status_message = wp_strip_all_tags(ob_get_clean());

    // Determine the type of admin notice based on the status message
    $message_type = 'success';
    if (strpos($status_message, 'ERROR') !== false) {
        $message_type = 'error';
    } elseif (strpos($status_message, 'INFO') !== false) {
        $message_type = 'info';
    }

    // Generate redirect nonce
    $nonce_redirect = wp_create_nonce('nppp_redirect_nonce');

    // Redirect appropriately
    if ($nppp_single_action) {
        // Store the status message in a transient
        $status_message_transient_key = 'nppp_front_message_' . uniqid();
        set_transient($status_message_transient_key, array(
            'message' => $status_message,
            'type' => $message_type
        ), 60);
        // Add nonce as a query parameter
        $redirect_url = add_query_arg(
            array(
                'nppp_front' => $status_message_transient_key,
                'redirect_nonce' => $nonce_redirect,
            ),
            $clean_current_page_url
        );
    } else {
        // Redirect to the settings page with the status message and message type as query parameters
        $redirect_url = add_query_arg(
            array(
                'page' => 'nginx_cache_settings',
                'status_message' => urlencode($status_message),
                'message_type' => urlencode($message_type),
                'redirect_nonce' => $nonce_redirect,
            ),
            admin_url('options-general.php')
        );
    }

    wp_safe_redirect($redirect_url);
    exit();
}
