<?php
/**
 * Enqueue custom CSS and JavaScript files for FastCGI Cache Purge and Preload for Nginx
 * Description: This file contains enqueue assets functions for FastCGI Cache Purge and Preload for Nginx
 * Version: 2.0.1
 * Author: Hasan ÇALIŞIR
 * Author Email: hasan.calisir@psauxit.com
 * Author URI: https://www.psauxit.com
 * License: GPL-2.0+
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Enqueue CSS and JavaScript files only plugin settings page load
function nppp_enqueue_nginx_fastcgi_cache_purge_preload_assets() {
    // Enqueue CSS files for jQuery UI Tabs
    wp_enqueue_style('nppp_jquery-ui-css', plugins_url('../admin/css/jquery-ui.min.css', __FILE__), array(), '1.13.2');
    wp_enqueue_style('nppp_jquery-ui-tabs-theme', plugins_url('../admin/css/jquery-ui-theme.css', __FILE__), array(), '1.13.2');

    // Enqueue CSS files for dataTables
    wp_enqueue_style('nppp_datatables-css', plugins_url('../admin/css/dataTables.min.css', __FILE__), array(), '2.0.7');

    // Enqueue CSS files for Tempus Dominus Date/Time Picker
    wp_enqueue_style('nppp_tempus-dominus-css', plugins_url('../admin/css/tempus-dominus.min.css', __FILE__), array(), '6.9.4');

    // Enqueue CSS files for Nginx FastCGI Cache Purge and Preload Plugin
    wp_enqueue_style('nppp_admin-css', plugins_url('../admin/css/fastcgi-cache-purge-and-preload-nginx.css', __FILE__), array(), '2.0.1');

    // Enqueue jQuery UI core, jQuery UI Tabs, jQuery UI Accordion
    wp_enqueue_script('jquery-ui-core');
    wp_enqueue_script('jquery-ui-tabs');
    wp_enqueue_script('jquery-ui-accordion');

    /*!
    * DataTables v2.0.7
    * https://datatables.net/
    * Copyright 2008-2024, SpryMedia Ltd.
    * License: MIT (https://datatables.net/license/mit)
    */
    // Enqueue JavaScript files for dataTables
    wp_enqueue_script('nppp_datatables-js', plugins_url('../admin/js/dataTables.min.js', __FILE__), array('jquery'), '2.0.7', true);

    /*!
    * Tempus Dominus Date Time Picker v6.9.4
    * https://getdatepicker.com/
    * Copyright 2021 Tempus Dominus
    * License: MIT (https://github.com/Eonasdan/tempus-dominus/blob/master/LICENSE)
    */
    // Enqueue JavaScript files for Tempus Dominus Date/Time Picker
    wp_enqueue_script('nppp_popper-js', plugins_url('../admin/js/popper.min.js', __FILE__), array(), '2.11.8', true);
    wp_enqueue_script('nppp_tempus-dominus-js', plugins_url('../admin/js/tempus-dominus.min.js', __FILE__), array('nppp_popper-js'), '6.9.4', true);

    // Enqueue JavaScript files for Nginx FastCGI Cache Purge and Preload Plugin
    wp_enqueue_script('nppp_admin-js', plugins_url('../admin/js/fastcgi-cache-purge-and-preload-nginx.js', __FILE__), array('jquery'), '2.0.1', true);

    // Retrieve plugin options.
    $options = get_option('nginx_cache_settings');
    // Create a nonce for clearing nginx cache logs
    $clear_nginx_cache_logs_nonce = wp_create_nonce('nppp-clear-nginx-cache-logs');
    // Create a nonce for updating send mail option
    $update_send_mail_option_nonce = wp_create_nonce('nppp-update-send-mail-option');
    // Create a nonce for the status tab
    $cache_status_nonce = wp_create_nonce('cache-status');
    // Create a nonce for auto preload option
    $update_auto_preload_option_nonce = wp_create_nonce('nppp-update-auto-preload-option');
    // Create a nonce for api key option
    $update_api_key_option_nonce = wp_create_nonce('nppp-update-api-key-option');
    // Create a nonce for default reject regex
    $update_default_reject_regex_option_nonce = wp_create_nonce('nppp-update-default-reject-regex-option');
    // Create a nonce for rest api option
    $update_api_option_nonce = wp_create_nonce('nppp-update-api-option');
    // Create a nonce for api key copy
    $update_api_key_copy_value_nonce = wp_create_nonce('nppp-update-api-key-copy-value');
    // Create a nonce for api purge url copy
    $update_rest_api_purge_url_copy_nonce = wp_create_nonce('nppp-rest-api-purge-url-copy');
    // Create a nonce for api preload url copy
    $update_rest_api_preload_url_copy_nonce = wp_create_nonce('nppp-rest-api-preload-url-copy');
    // Create a nonce for get and save cron expression
    $update_cron_expression_option_nonce = wp_create_nonce('nppp-get-save-cron-expression');
    // Create a nonce for cache schedule option
    $update_cache_schedule_option_nonce = wp_create_nonce('nppp-update-cache-schedule-option');
    // Create a nonce for cancel scheduled event
    $cancel_scheduled_event_nonce = wp_create_nonce('nppp-cancel-scheduled-event');

    // Localize nonce values for nppp_admin-js
    wp_localize_script('nppp_admin-js', 'nppp_admin_data', array(
        'ajaxurl' => admin_url('admin-ajax.php'),
        'clear_logs_nonce' => $clear_nginx_cache_logs_nonce,
        'send_mail_nonce' => $update_send_mail_option_nonce,
        'auto_preload_nonce' => $update_auto_preload_option_nonce,
        'api_key_nonce' => $update_api_key_option_nonce,
        'api_key_copy_nonce' => $update_api_key_copy_value_nonce,
        'api_preload_url_copy_nonce' => $update_rest_api_preload_url_copy_nonce,
        'api_purge_url_copy_nonce' => $update_rest_api_purge_url_copy_nonce,
        'api_nonce' => $update_api_option_nonce,
        'reject_regex_nonce' => $update_default_reject_regex_option_nonce,
        'cache_status_nonce' => $cache_status_nonce,
        'premium_nonce_purge' => wp_create_nonce('purge_cache_premium_nonce'),
        'premium_nonce_preload' => wp_create_nonce('preload_cache_premium_nonce'),
        'premium_content_nonce' => wp_create_nonce('load_premium_content_nonce'),
        'get_save_cron_nonce' => $update_cron_expression_option_nonce,
        'cache_schedule_nonce' => $update_cache_schedule_option_nonce,
        'cancel_scheduled_event_nonce' => $cancel_scheduled_event_nonce,
    ));
}

// Check plugin requirements
function nppp_plugin_requirements_met() {
    $nppp_met = false;

    // Check if the operating system is Linux and the web server is nginx
    if (PHP_OS === 'Linux' && strpos($_SERVER['SERVER_SOFTWARE'], 'nginx') !== false) {
        // Check if shell_exec is enabled
        if (function_exists('shell_exec')) {
            // Attempt to execute a harmless command
            $output = shell_exec('echo "Test"');

            // Check if the command executed successfully
            if ($output === "Test\n") {
                $nppp_met = true;
            }
        }
    }

    return $nppp_met;
}

// Enqueue CSS and JavaScript files on globally admin side
// Check plugin requirements and limit the functionality accordingly on admin side
function nppp_enqueue_nginx_fastcgi_cache_purge_preload_requisite_assets() {
    $nppp_met = nppp_plugin_requirements_met();

    // Check if wget command exists
    if ($nppp_met) {
        $output = shell_exec('type wget');
        if (empty($output)) {
            // Wget is not available
            wp_enqueue_script('preload-button-disable', plugins_url('../admin/js/preload-button-disable.js', __FILE__), array('jquery'), '2.0.1', true);
        } else {
            // Wget is available, dequeue the preload-button-disable.js if it's already enqueued
            wp_dequeue_script('preload-button-disable');
        }
        wp_dequeue_script('nppp-disable-functionality');
    } else {
        // This plugin only works on Linux with nginx
        // Disable plugin functionality to prevent unexpected behaviors.
        wp_enqueue_script('nppp-disable-functionality', plugins_url('../admin/js/nppp-disable-functionality.js', __FILE__), array('jquery'), '2.0.1', true);
    }
}

// Enqueue CSS and JavaScript files for admin logged-in front-end
function nppp_enqueue_nginx_fastcgi_cache_purge_preload_front_assets() {
    if (is_user_logged_in() && current_user_can('administrator') && isset($_GET['nppp_front'])) {
        $nonce = isset($_GET['redirect_nonce']) ? sanitize_text_field(wp_unslash($_GET['redirect_nonce'])) : '';
        if (wp_verify_nonce($nonce, 'nppp_redirect_nonce')) {
            if (!is_admin()) {
                // Enqueue CSS files for Nginx FastCGI Cache Purge and Preload Plugin
                wp_enqueue_style('nppp_admin-front-css', plugins_url('../frontend/css/fastcgi-cache-purge-and-preload-nginx-front.css', __FILE__), array(), '2.0.1');
                // Enqueue JavaScript files for Nginx FastCGI Cache Purge and Preload Plugin frontend
                wp_enqueue_script('nppp_admin-front-js', plugins_url('../frontend/js/fastcgi-cache-purge-and-preload-nginx-front.js', __FILE__), array('jquery'), '2.0.1', true);
            }
        }
    }

    // Check plugin requirements and limit the functionality accordingly on front-end
    $nppp_met = nppp_plugin_requirements_met();
    if (!$nppp_met) {
        wp_enqueue_script('nppp-disable-functionality-front', plugins_url('../frontend/js/nppp-disable-functionality-front.js', __FILE__), array('jquery'), '2.0.1', true);
    } else {
        wp_dequeue_script('nppp-disable-functionality-front');
    }

    // Enqueue CSS and JavaScript files for frontend admin bar icon style set
    wp_enqueue_script('nppp-admin-bar-icon-front', plugins_url('../frontend/js/nppp-admin-bar-icon-front.js', __FILE__), array('jquery'), '2.0.1', true);
}
