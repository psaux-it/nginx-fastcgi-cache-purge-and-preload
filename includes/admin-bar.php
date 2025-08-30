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

// Build the exact URL the browser is on (percent-encoded path preserved)
function nppp_get_current_front_url() {
    $scheme = is_ssl() ? 'https' : 'http';

    // Sanitize/unslash server vars; fall back to home_url() parts.
    $home_host = (string) wp_parse_url(home_url(), PHP_URL_HOST);
    $host = isset($_SERVER['HTTP_HOST'])
        ? sanitize_text_field(wp_unslash($_SERVER['HTTP_HOST']))
        : $home_host;

    // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
    $req = isset($_SERVER['REQUEST_URI'])
        ? wp_unslash($_SERVER['REQUEST_URI'])
        : '/';

    // Keep the raw path/query but strip our own args
    $req = remove_query_arg(array('nppp_front', 'redirect_nonce'), $req);

    // Normalize leading slash only
    $req = '/' . ltrim( $req, '/' );

    return $scheme . '://' . $host . $req;
}

// Punycode host + percent-encoded path
function nppp_maybe_encode_non_ascii_path_in_url(string $url): string {
    $p = wp_parse_url($url);
    if (!is_array($p) || empty($p['scheme']) || empty($p['host'])) {
        return $url;
    }

    $path = $p['path'] ?? '';

    // Only act if there are non-ASCII chars in the path
    if ($path !== '' && preg_match('/[^\x00-\x7F]/u', $path)) {
        // Encode only the non-ASCII chars to %XX
        $path = preg_replace_callback('/[^\x00-\x7F]/u', static function ($m) {
            $bytes = unpack('C*', $m[0]);
            return implode('', array_map(
                static fn($b) => sprintf('%%%02X', $b),
                $bytes
            ));
        }, $path);
    }

    // Rebuild URL with the encoded path; everything else untouched
    return $p['scheme'] . '://' . $p['host']
         . (isset($p['port']) ? ':' . (int) $p['port'] : '')
         . $path
         . (isset($p['query']) ? '?' . $p['query'] : '')
         . (isset($p['fragment']) ? '#' . $p['fragment'] : '');
}

// Send an error back to the *front page* (same page if safe) using your existing transient modal.
function nppp_front_error_notice(string $msg, ?string $target_url = null, string $type = 'error') : void {
    // 1) Log the message
    if (function_exists('nppp_display_admin_notice')) {
        nppp_display_admin_notice($type, $msg, true, false);
    }

    // 2) Choose a safe front-end target (http/https + same host); else fall back to home.
    $safe = home_url('/');
    if ($target_url && wp_http_validate_url($target_url)) {
        $t = wp_parse_url($target_url);
        $h = wp_parse_url($safe);
        if (!empty($t['scheme']) && in_array(strtolower($t['scheme']), ['http','https'], true)
            && !empty($t['host']) && !empty($h['host'])
            && strtolower($t['host']) === strtolower($h['host'])) {
            $safe = $target_url;
        }
    }

    // 3) Nuke any previous output so headers can be sent
    while (ob_get_level() > 0) { @ob_end_clean(); }

    // 4) Store the message and redirect
    $key   = 'nppp_front_message_' . uniqid('', true);
    $nonce = wp_create_nonce('nppp_redirect_nonce');
    set_transient($key, array('message' => $msg, 'type' => $type), 60);

    $redirect_url = add_query_arg(['nppp_front' => $key, 'redirect_nonce' => $nonce], $safe);
    wp_safe_redirect($redirect_url);
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
            $from_url = nppp_get_current_front_url();

            // Purge This Page
            $wp_admin_bar->add_menu( array(
                'parent' => 'fastcgi-cache-operations',
                'id'     => 'purge-cache-single',
                'title'  => __( 'Purge This Page', 'fastcgi-cache-purge-and-preload-nginx' ),
                'href'   => wp_nonce_url(
                    add_query_arg( 'from', $from_url, admin_url('admin.php?action=nppp_purge_cache_single') ),
                    'purge_cache_nonce'
                ),
            ));

            // Preload This Page
            $wp_admin_bar->add_menu( array(
                'parent' => 'fastcgi-cache-operations',
                'id'     => 'preload-cache-single',
                'title'  => __( 'Preload This Page', 'fastcgi-cache-purge-and-preload-nginx' ),
                'href'   => wp_nonce_url(
                    add_query_arg( 'from', $from_url, admin_url('admin.php?action=nppp_preload_cache_single') ),
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
        nppp_front_error_notice(__( 'ERROR SECURITY: Invalid or expired token.', 'fastcgi-cache-purge-and-preload-nginx' ), home_url( '/' ));
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

    // Only process URL for single-page actions
    if (in_array($action, ['nppp_purge_cache_single', 'nppp_preload_cache_single'], true)) {
        // Build URL (raw)
        // PATCH: CVE ID: CVE-2025-6213

        // 1) Require ?from=
        if (! isset($_GET['from']) || $_GET['from'] === '' || is_array($_GET['from'])) {
            nppp_front_error_notice(__('ERROR URL: Could not determine the current page URL.', 'fastcgi-cache-purge-and-preload-nginx'), home_url('/'));
        }

        // 2) Raw value (validated below; keep exact bytes)
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        $candidate_raw = wp_unslash($_GET['from']);

        // If the absolute URL starts with an encoded scheme, fully decode once.
        if (strpos($candidate_raw, '://') === false
            && preg_match('/^[a-z][a-z0-9+.\-]*%3A%2F%2F/i', $candidate_raw)) {
            $candidate_raw = rawurldecode($candidate_raw);
        }

        // Now that it’s decoded, strip our transient args if present
        $candidate_raw = remove_query_arg(array('nppp_front','redirect_nonce'), $candidate_raw);
        $front_target = $candidate_raw;

        // 3) Only encode path if it has non-ASCII; otherwise keep EXACTLY as supplied
        $candidate_ascii = nppp_maybe_encode_non_ascii_path_in_url($candidate_raw);

        // 4) Validations
        if (!filter_var($candidate_ascii, FILTER_VALIDATE_URL)) {
            nppp_front_error_notice(__('ERROR SECURITY: URL cannot be validated.', 'fastcgi-cache-purge-and-preload-nginx'), $front_target);
        }

        // 5) Security: Same-site + port
        $ref_parts   = wp_parse_url($candidate_ascii);
        $home_parts  = wp_parse_url(home_url());
        $admin_parts = wp_parse_url(admin_url());

        $norm = static function($h) {
            $h = strtolower(rtrim( (string)$h, '.'));
            if (function_exists('idn_to_ascii')) {
                $x = idn_to_ascii( $h, 0, defined('INTL_IDNA_VARIANT_UTS46') ? INTL_IDNA_VARIANT_UTS46 : 0 );
                if ($x) $h = $x;
            }
            return $h;
        };

        $allowed = array();
        if (!empty($home_parts['host']))  $allowed[$norm($home_parts['host'])]  = true;
        if (!empty($admin_parts['host'])) $allowed[$norm($admin_parts['host'])] = true;

        if (empty($ref_parts['host']) || ! isset($allowed[$norm($ref_parts['host'])])) {
            nppp_front_error_notice(__('ERROR SECURITY: URL is not from our domain.', 'fastcgi-cache-purge-and-preload-nginx'), home_url('/'));
        }

        $ref_port   = isset($ref_parts['port']) ? (int)$ref_parts['port'] : null;
        $home_port  = isset($home_parts['port']) ? (int)$home_parts['port'] : null;
        $admin_port = isset($admin_parts['port']) ? (int)$admin_parts['port'] : null;
        if (null !== $ref_port && $ref_port !== $home_port && $ref_port !== $admin_port) {
            nppp_front_error_notice(__('ERROR SECURITY: URL port mismatch.', 'fastcgi-cache-purge-and-preload-nginx'), $front_target);
        }

        // Ready to go
        $current_page_url = $candidate_ascii;
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
