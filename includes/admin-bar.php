<?php
/**
 * WP Admin Bar code for FastCGI Cache Purge and Preload for Nginx
 * Description: This file contains Admin Bar code for FastCGI Cache Purge and Preload for Nginx
 * Version: 2.1.2
 * Author: Hasan CALISIR
 * Author Email: hasan.calisir@psauxit.com
 * Author URI: https://www.psauxit.com
 * License: GPL-2.0+
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Add NPP menu to WordPress admin-bar
function nppp_add_fastcgi_cache_buttons_admin_bar($wp_admin_bar) {
    // Check if the user has permissions to manage options
    if (!current_user_can('manage_options')) {
        return;
    }

    // Add top admin-bar menu for NPP
    $wp_admin_bar->add_menu(array(
        'id'    => 'fastcgi-cache-operations',
        'title' => sprintf(
            '<img style="height: 20px; margin-bottom: -5px; width: 20px;" src="%s"> %s',
            esc_url(plugin_dir_url(__FILE__) . '../admin/img/bar.png'),
            esc_html__('Nginx Cache', 'fastcgi-cache-purge-and-preload-nginx')
        ),
        'href' => admin_url('options-general.php?page=nginx_cache_settings'),
    ));

    // Add "Purge All" admin-bar parent menu for NPP
    $wp_admin_bar->add_menu(array(
        'parent' => 'fastcgi-cache-operations',
        'id' => 'purge-cache',
        'title' => __('Purge All', 'fastcgi-cache-purge-and-preload-nginx'),
        'href'   => wp_nonce_url(admin_url('admin.php?action=nppp_purge_cache'), 'purge_cache_nonce'),
        'meta'   => array('class' => 'nppp-action-trigger'),
    ));

    // Add "Preload All" admin-bar parent menu for NPP
    $wp_admin_bar->add_menu(array(
        'parent' => 'fastcgi-cache-operations',
        'id' => 'preload-cache',
        'title' => __('Preload All', 'fastcgi-cache-purge-and-preload-nginx'),
        'href' => wp_nonce_url(admin_url('admin.php?action=nppp_preload_cache'), 'preload_cache_nonce'),
        'meta'   => array('class' => 'nppp-action-trigger'),
    ));

    // Add single "Purge" and "Preload" admin-bar parent menus for front-end
    if (isset($_SERVER['REQUEST_URI']) && !empty($_SERVER['REQUEST_URI'])) {
        $request_uri = sanitize_text_field(wp_unslash($_SERVER['REQUEST_URI']));

        // Check if the URI does not contain 'wp-admin'
        if (strpos($request_uri, 'wp-admin') === false) {
            // Purge
            $wp_admin_bar->add_menu(array(
                'parent' => 'fastcgi-cache-operations',
                'id' => 'purge-cache-single',
                'title' => __('Purge This Page', 'fastcgi-cache-purge-and-preload-nginx'),
                'href'  => wp_nonce_url(admin_url('admin.php?action=nppp_purge_cache_single'), 'purge_cache_nonce'),
            ));

            // Preload
            $wp_admin_bar->add_menu(array(
                'parent' => 'fastcgi-cache-operations',
                'id' => 'preload-cache-single',
                'title' => __('Preload This Page', 'fastcgi-cache-purge-and-preload-nginx'),
                'href'  => wp_nonce_url(admin_url('admin.php?action=nppp_preload_cache_single'), 'preload_cache_nonce'),
            ));
        }
    }

    // Add "Status" admin-bar parent menu for NPP
    $wp_admin_bar->add_menu(array(
        'parent' => 'fastcgi-cache-operations',
        'id' => 'fastcgi-cache-status',
        'title' => __('Status', 'fastcgi-cache-purge-and-preload-nginx'),
        'href' => admin_url('options-general.php?page=nginx_cache_settings#status'),
    ));

    // Add "Advanced" admin-bar parent menu for NPP
    $wp_admin_bar->add_menu(array(
        'parent' => 'fastcgi-cache-operations',
        'id' => 'fastcgi-cache-advanced',
        'title' => __('Advanced', 'fastcgi-cache-purge-and-preload-nginx'),
        'href' => admin_url('options-general.php?page=nginx_cache_settings#premium'),
    ));

    // Add "Settings" admin-bar parent menu for NPP
    $wp_admin_bar->add_menu(array(
        'parent' => 'fastcgi-cache-operations',
        'id' => 'fastcgi-cache-settings',
        'title' => __('Settings', 'fastcgi-cache-purge-and-preload-nginx'),
        'href' => admin_url('options-general.php?page=nginx_cache_settings'),
    ));

    // Add "Help" admin-bar parent menu for NPP
    $wp_admin_bar->add_menu(array(
        'parent' => 'fastcgi-cache-operations',
        'id' => 'fastcgi-cache-help',
        'title' => __('Help', 'fastcgi-cache-purge-and-preload-nginx'),
        'href' => admin_url('options-general.php?page=nginx_cache_settings#help'),
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

    // Validate actions
    $allowed_actions = ['nppp_purge_cache', 'nppp_preload_cache', 'nppp_purge_cache_single', 'nppp_preload_cache_single'];
    if (!in_array($action, $allowed_actions, true)) {
        return;
    }

    // Get the plugin options
    $nginx_cache_settings = get_option('nginx_cache_settings');

    // Set default data for purge & preload actions
    $default_cache_path = '/dev/shm/change-me-now';
    $default_limit_rate = 1280;
    $default_cpu_limit = 50;
    $default_reject_regex = nppp_fetch_default_reject_regex();

    // Get the necessary data for purge & preload actions
    $nginx_cache_path = isset($nginx_cache_settings['nginx_cache_path']) ? $nginx_cache_settings['nginx_cache_path'] : $default_cache_path;
    $nginx_cache_limit_rate = isset($nginx_cache_settings['nginx_cache_limit_rate']) ? $nginx_cache_settings['nginx_cache_limit_rate'] : $default_limit_rate;
    $nginx_cache_cpu_limit = isset($nginx_cache_settings['nginx_cache_cpu_limit']) ? $nginx_cache_settings['nginx_cache_cpu_limit'] : $default_cpu_limit;
    $nginx_cache_reject_regex = isset($nginx_cache_settings['nginx_cache_reject_regex']) ? $nginx_cache_settings['nginx_cache_reject_regex'] : $default_reject_regex;

    // Get extra data for purge & preload actions
    $fdomain = get_site_url();
    $this_script_path = dirname(plugin_dir_path(__FILE__));
    $PIDFILE = rtrim($this_script_path, '/') . '/cache_preload.pid';
    $tmp_path = rtrim($nginx_cache_path, '/') . "/tmp";

    $nppp_single_action = false;
    $current_page_url = '';

    // Only process URL for single-page actions
    if (in_array($action, ['nppp_purge_cache_single', 'nppp_preload_cache_single'], true)) {
        if (isset($_SERVER['HTTP_REFERER']) && !empty($_SERVER['HTTP_REFERER'])) {
            $raw_url           = wp_unslash($_SERVER['HTTP_REFERER']);
            $current_page_url  = esc_url_raw($raw_url);

            // Validate format
            // PATCH: CVE ID: CVE-2025-6213
            if (! filter_var($current_page_url, FILTER_VALIDATE_URL)) {
                return;
            }

            // Enforce same-site origin
            // PATCH: CVE ID: CVE-2025-6213
            $site_host = wp_parse_url(home_url(), PHP_URL_HOST);
            $url_host  = wp_parse_url($current_page_url, PHP_URL_HOST);

            if ($site_host !== $url_host) {
                return;
            }

            // Defense-in-depth: check for command injection
            // PATCH: CVE ID: CVE-2025-6213
            // NOTE: (escapeshellarg) not used, breaks percent-encoded URLs
            if (preg_match('/[;&|`$<>"]/', $current_page_url)) {
                return;
            }

        } else {
            global $wp;
            if (empty($wp->request)) {
                $wp->parse_request();
            }
            $current_page_url = esc_url_raw(trailingslashit(home_url(add_query_arg($_GET, $wp->request))));
        }
    }

    // Start output buffering to capture the output of the actions
    ob_start();

    switch ($_GET['action']) {
        case 'nppp_purge_cache':
            nppp_purge($nginx_cache_path, $PIDFILE, $tmp_path, false, true, false);
            break;
        case 'nppp_preload_cache':
            nppp_preload($nginx_cache_path, $this_script_path, $tmp_path, $fdomain, $PIDFILE, $nginx_cache_reject_regex, $nginx_cache_limit_rate, $nginx_cache_cpu_limit, false, false, false, true);
            break;
        case 'nppp_purge_cache_single':
            nppp_purge_single($nginx_cache_path, $current_page_url, false);
            $nppp_single_action = true;
            break;
        case 'nppp_preload_cache_single':
            nppp_preload_single($current_page_url, $PIDFILE, $tmp_path, $nginx_cache_reject_regex, $nginx_cache_limit_rate, $nginx_cache_cpu_limit, $nginx_cache_path);
            $nppp_single_action = true;
            break;
        default:
            return;
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
            $current_page_url
        );
    } else {
        // Redirect to the settings page with the status message and message type as query parameters
        $redirect_url = add_query_arg(
            array(
                'page' => 'nginx_cache_settings',
                'status_message' => esc_html(urlencode($status_message)),
                'message_type' => esc_html(urlencode($message_type)),
                'redirect_nonce' => $nonce_redirect,
            ),
            admin_url('options-general.php')
        );
    }

    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    wp_safe_redirect($redirect_url);
    exit();
}
