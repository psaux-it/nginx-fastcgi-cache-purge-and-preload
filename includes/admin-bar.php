<?php
/**
 * WP Admin Bar code for FastCGI Cache Purge and Preload for Nginx
 * Description: This file contains Admin Bar code for FastCGI Cache Purge and Preload for Nginx
 * Version: 2.1.3
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
            // phpcs:ignore PluginCheck.CodeAnalysis.ImageFunctions.NonEnqueuedImage -- Image is internal plugin asset, not from media library
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
            // Add current page URL
            $raw_from = home_url(add_query_arg(null, null));
            $from_url = remove_query_arg(array('nppp_front', 'redirect_nonce'), $raw_from);
            $from_url = esc_url_raw($from_url);

            // Purge
            $wp_admin_bar->add_menu( array(
                'parent' => 'fastcgi-cache-operations',
                'id'     => 'purge-cache-single',
                'title'  => __( 'Purge This Page', 'fastcgi-cache-purge-and-preload-nginx' ),
                'href'   => wp_nonce_url(
                    add_query_arg(
                        'from',
                        $from_url,
                        admin_url('admin.php?action=nppp_purge_cache_single')
                    ),
                    'purge_cache_nonce'
                ),
            ));

            // Preload
            $wp_admin_bar->add_menu( array(
                'parent' => 'fastcgi-cache-operations',
                'id'     => 'preload-cache-single',
                'title'  => __( 'Preload This Page', 'fastcgi-cache-purge-and-preload-nginx' ),
                'href'   => wp_nonce_url(
                    add_query_arg(
                        'from',
                        $from_url,
                        admin_url( 'admin.php?action=nppp_preload_cache_single' )
                    ),
                    'preload_cache_nonce'
                ),
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

    // Always re-check capability
    if (! current_user_can('manage_options')) {
        return;
    }

    // Bind nonce to the specific action
    $action = sanitize_key(wp_unslash($_GET['action'] ?? '' ));
    $nonce  = sanitize_text_field(wp_unslash($_GET['_wpnonce'] ?? '' ));

    $action_nonce_map = array(
        'nppp_purge_cache'          => 'purge_cache_nonce',
        'nppp_purge_cache_single'   => 'purge_cache_nonce',
        'nppp_preload_cache'        => 'preload_cache_nonce',
        'nppp_preload_cache_single' => 'preload_cache_nonce',
    );

    if (! isset($action_nonce_map[$action]) || ! wp_verify_nonce($nonce, $action_nonce_map[$action])) {
        return;
    }

    // Validate actions
    if (! in_array($action, array_keys($action_nonce_map), true)) { return; }

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

    // Helper for host normalization
    $normalize_host = static function( $host ) {
        $h = strtolower( rtrim( (string) $host, '.' ) );
        if ( function_exists( 'idn_to_ascii' ) ) {
            $converted = idn_to_ascii( $h, 0, defined( 'INTL_IDNA_VARIANT_UTS46' ) ? INTL_IDNA_VARIANT_UTS46 : 0 );
            if ( $converted ) {
                $h = $converted;
            }
        }
        return $h;
    };

    // Only process URL for single-page actions
    if (in_array($action, ['nppp_purge_cache_single', 'nppp_preload_cache_single'], true)) {
        // Build candidate URL (raw), in priority: ?from -> wp_get_referer() -> HTTP_REFERER
        // PATCH: CVE ID: CVE-2025-6213
        // https://github.com/psaux-it/nginx-fastcgi-cache-purge-and-preload/security/advisories/GHSA-636g-ww4c-2j54

        if (isset($_GET['from']) && $_GET['from'] !== '' && ! is_array($_GET['from'])) {
            // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- URL is sanitized with esc_url_raw() below.
            $candidate_raw = wp_unslash($_GET['from']);
        } elseif ($ref = wp_get_referer()) {
            $candidate_raw = $ref;
        } elseif (! empty($_SERVER['HTTP_REFERER']) && ! is_array($_SERVER['HTTP_REFERER'])) {
            // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- URL is sanitized with esc_url_raw() below.
            $candidate_raw = wp_unslash($_SERVER['HTTP_REFERER']);
        } else {
            $candidate_raw = '';
        }

        // Remove query_args
        $candidate_raw = remove_query_arg(array('nppp_front', 'redirect_nonce'), $candidate_raw);

        if ($candidate_raw === '') {
            nppp_display_admin_notice('error', __('ERROR URL: Could not determine the page URL.', 'fastcgi-cache-purge-and-preload-nginx'), true, false);
            return;
        }

        if (! filter_var($candidate_raw, FILTER_VALIDATE_URL)) {
            nppp_display_admin_notice('error', __('ERROR SECURITY: URL cannot be validated.', 'fastcgi-cache-purge-and-preload-nginx'), true, false);
            return;
        }

        // Now sanitize --> esc_url_raw: sanitize without altering percent-encoded characters
        // PATCH: CVE ID: CVE-2025-6213
        // https://github.com/psaux-it/nginx-fastcgi-cache-purge-and-preload/security/advisories/GHSA-636g-ww4c-2j54
        $candidate = esc_url_raw($candidate_raw);

        // Start security checks
        // PATCH: CVE ID: CVE-2025-6213
        // https://github.com/psaux-it/nginx-fastcgi-cache-purge-and-preload/security/advisories/GHSA-636g-ww4c-2j54
        $ref_parts = wp_parse_url($candidate);
        if (! is_array($ref_parts) || empty($ref_parts['host']) || empty($ref_parts['scheme'])) {
            nppp_display_admin_notice('error', __('ERROR SECURITY: URL cannot be parsed.', 'fastcgi-cache-purge-and-preload-nginx'), true, false);
            return;
        }

        // Scheme check
        $scheme = strtolower($ref_parts['scheme']);
        if (! in_array($scheme, array('http', 'https'), true)) {
            nppp_display_admin_notice('error', __('ERROR SECURITY: Invalid URL scheme.', 'fastcgi-cache-purge-and-preload-nginx'), true, false);
            return;
        }

        // Compare host with both home and admin hosts
        $home_parts  = wp_parse_url(home_url());
        $admin_parts = wp_parse_url(admin_url());

        $allowed_hosts = array();
        foreach (array($home_parts, $admin_parts) as $p) {
            if (is_array($p) && ! empty($p['host'])) {
                $allowed_hosts[$normalize_host($p['host'])] = true;
            }
        }

        // Enforce same-site origin
        $url_host = $normalize_host($ref_parts['host']);
        if (! isset($allowed_hosts[$url_host])) {
            nppp_display_admin_notice('error', __('ERROR SECURITY: URL is not from the our domain.', 'fastcgi-cache-purge-and-preload-nginx'), true, false);
            return;
        }

        // Port consistency check
        $ref_port   = isset( $ref_parts['port'] ) ? (int) $ref_parts['port'] : null;
        $home_port  = isset( $home_parts['port'] ) ? (int) $home_parts['port'] : null;
        $admin_port = isset( $admin_parts['port'] ) ? (int) $admin_parts['port'] : null;
        $same_port  = ( null === $ref_port ) || $ref_port === $home_port || $ref_port === $admin_port;

        if (! $same_port) {
            nppp_display_admin_notice('error', __('ERROR SECURITY: URL port mismatch.', 'fastcgi-cache-purge-and-preload-nginx'), true, false);
            return;
        }

        // Ready to go
        $current_page_url = $candidate;
    }

    // Start output buffering to capture the output of the actions
    ob_start();

    switch ($action) {
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

    wp_safe_redirect($redirect_url);
    exit();
}
