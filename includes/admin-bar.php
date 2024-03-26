<?php
/**
 * WP Admin Bar code for Nginx FastCGI Cache Purge and Preload
 * Description: This file contains Admin Bar code for Nginx FastCGI Cache Purge and Preload
 * Version: 1.0.2
 * Author: Hasan ÇALIŞIR
 * Author Email: hasan.calisir@psauxit.com
 * Author URI: https://www.psauxit.com
 * License: GPL-2.0+
 */

// Add buttons to WordPress admin bar
function add_fastcgi_cache_buttons_admin_bar($wp_admin_bar) {
    // Check if the user has permissions to manage options
    if (!current_user_can('manage_options')) {
        return;
    }

    // Add a parent menu item for FastCGI cache operations
    $wp_admin_bar->add_menu(array(
        'id' => 'fastcgi-cache-operations',
        'title' => '<img style="height: 20px; margin-bottom: -4px; padding-right: 3px;" src="' . plugin_dir_url(__FILE__) . '../assets/img/bar.png" alt="NPP" title="NPP"> FastCGI Cache',
        'href' => '#',
        'meta' => array(
            'class' => 'npp-icon',
        ),
    ));

    // Add child menu items for purge and preload operations with nonces
    $purge_nonce = wp_create_nonce('purge_cache_nonce');
    $preload_nonce = wp_create_nonce('preload_cache_nonce');
    $settings_nonce = wp_create_nonce('nginx_cache_settings_nonce');

    // Add child menu items for purge and preload operations
    $wp_admin_bar->add_menu(array(
        'parent' => 'fastcgi-cache-operations',
        'id' => 'purge-cache',
        'title' => 'FCGI Cache Purge',
        'href' => add_query_arg('purge_cache', 'true', admin_url()) . '&_wpnonce=' . $purge_nonce,
    ));

    $wp_admin_bar->add_menu(array(
        'parent' => 'fastcgi-cache-operations',
        'id' => 'preload-cache',
        'title' => 'FCGI Cache Preload',
        'href' => add_query_arg('preload_cache', 'true', admin_url()) . '&_wpnonce=' . $preload_nonce,
    ));

    // Add settings submenu
    $wp_admin_bar->add_menu(array(
        'parent' => 'fastcgi-cache-operations',
        'id' => 'fastcgi-cache-settings',
        'title' => 'Settings',
        'href' => add_query_arg('_wpnonce', $settings_nonce, admin_url('options-general.php?page=nginx_cache_settings')),
    ));
}

// Handle button clicks
function handle_fastcgi_cache_actions_admin_bar() {
    // Check if the buttons are clicked and nonce is valid
    if ((isset($_GET['purge_cache']) && check_admin_referer('purge_cache_nonce')) ||
        (isset($_GET['preload_cache']) && check_admin_referer('preload_cache_nonce'))) {

        // Determine action based on button click
        $action = isset($_GET['purge_cache']) ? 'purge' : 'preload';

        // Necessary data for purge and preload actions
        $nginx_cache_settings = get_option('nginx_cache_settings');

        $default_cache_path = find_user_home_folder() . '/change-me-nginx';
        $default_limit_rate = 1280;
        $default_cpu_limit = 50;
        $default_reject_regex = fetch_default_reject_regex_from_php_file();

        $nginx_cache_path = isset($nginx_cache_settings['nginx_cache_path']) ? $nginx_cache_settings['nginx_cache_path'] : $default_cache_path;
        $nginx_cache_limit_rate = isset($nginx_cache_settings['nginx_cache_limit_rate']) ? $nginx_cache_settings['nginx_cache_limit_rate'] : $default_limit_rate;
        $nginx_cache_cpu_limit = isset($nginx_cache_settings['nginx_cache_cpu_limit']) ? $nginx_cache_settings['nginx_cache_cpu_limit'] : $default_cpu_limit;
        $nginx_cache_reject_regex = isset($nginx_cache_settings['nginx_cache_reject_regex']) ? $nginx_cache_settings['nginx_cache_reject_regex'] : $default_reject_regex;

        $PIDFILE = plugin_dir_path(__FILE__) . 'cache_preload.pid';
        $fdomain = get_site_url();
        $this_script_path = plugin_dir_path(__FILE__);

        // Call the appropriate function based on the action
        if ($action === 'purge') {
            purge($nginx_cache_path, $PIDFILE);
        } elseif ($action === 'preload') {
            preload($nginx_cache_path, $this_script_path, $fdomain, $PIDFILE, $nginx_cache_reject_regex, $nginx_cache_limit_rate, $nginx_cache_cpu_limit);
        }
    }
}
