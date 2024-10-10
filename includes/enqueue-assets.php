<?php
/**
 * Enqueue custom CSS and JavaScript files for FastCGI Cache Purge and Preload for Nginx
 * Description: This file contains enqueue assets functions for FastCGI Cache Purge and Preload for Nginx
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

// Enqueue CSS and JavaScript files only plugin settings page load
function nppp_enqueue_nginx_fastcgi_cache_purge_preload_assets() {
    // Enqueue CSS files for jQuery UI Tabs
    wp_enqueue_style('nppp_jquery-ui-css', plugins_url('../admin/css/jquery-ui.min.css', __FILE__), array(), '1.13.3');
    wp_enqueue_style('nppp_jquery-ui-tabs-theme', plugins_url('../admin/css/jquery-ui.theme.min.css', __FILE__), array(), '1.13.3');

    // Enqueue CSS files for dataTables
    wp_enqueue_style('nppp_datatables-css', plugins_url('../admin/css/dataTables.min.css', __FILE__), array(), '2.1.8');

    // Enqueue CSS files for Tempus Dominus Date/Time Picker
    wp_enqueue_style('nppp_tempus-dominus-css', plugins_url('../admin/css/tempus-dominus.min.css', __FILE__), array(), '6.9.4');

    // Enqueue CSS files for Nginx FastCGI Cache Purge and Preload Plugin
    wp_enqueue_style('nppp_admin-css', plugins_url('../admin/css/fastcgi-cache-purge-and-preload-nginx.min.css', __FILE__), array(), '2.0.4');

    // Enqueue the default-passive-events polyfill
    wp_enqueue_script('nppp_default-event-js', plugins_url('../admin/js/default-passive-events.min.js', __FILE__), array(), '2.0.0', false);

    // Enqueue jQuery UI core, jQuery UI Tabs, jQuery UI Accordion
    wp_enqueue_script('jquery-ui-core');
    wp_enqueue_script('jquery-ui-tabs');
    wp_enqueue_script('jquery-ui-accordion');

    /*!
    * DataTables v2.1.3
    * https://datatables.net/
    * Copyright 2008-2024, SpryMedia Ltd.
    * License: MIT (https://datatables.net/license/mit)
    */
    // Enqueue JavaScript files for dataTables
    wp_enqueue_script('nppp_datatables-js', plugins_url('../admin/js/dataTables.min.js', __FILE__), array('jquery'), '2.1.8', true);

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
    wp_enqueue_script('nppp_admin-js', plugins_url('../admin/js/fastcgi-cache-purge-and-preload-nginx.min.js', __FILE__), array('jquery'), '2.0.4', true);

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
    // Create a nonce for auto purge option
    $update_auto_purge_option_nonce = wp_create_nonce('nppp-update-auto-purge-option');
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
    // Create a nonce for plugin cache clear
    $clear_plugin_cache_nonce = wp_create_nonce('nppp-clear-plugin-cache-action');
    // Create a nonce for restart systemd service npp-wordpress
    $restart_systemd_service_nonce = wp_create_nonce('nppp-restart-systemd-service');
    // Create a nonce for default reject extension
    $update_default_reject_extension_option_nonce = wp_create_nonce('nppp-update-default-reject-extension-option');

    // Localize nonce values for nppp_admin-js
    wp_localize_script('nppp_admin-js', 'nppp_admin_data', array(
        'ajaxurl' => admin_url('admin-ajax.php'),
        'clear_logs_nonce' => $clear_nginx_cache_logs_nonce,
        'send_mail_nonce' => $update_send_mail_option_nonce,
        'auto_preload_nonce' => $update_auto_preload_option_nonce,
        'auto_purge_nonce' => $update_auto_purge_option_nonce,
        'api_key_nonce' => $update_api_key_option_nonce,
        'api_key_copy_nonce' => $update_api_key_copy_value_nonce,
        'api_preload_url_copy_nonce' => $update_rest_api_preload_url_copy_nonce,
        'api_purge_url_copy_nonce' => $update_rest_api_purge_url_copy_nonce,
        'api_nonce' => $update_api_option_nonce,
        'reject_regex_nonce' => $update_default_reject_regex_option_nonce,
        'reject_extension_nonce' => $update_default_reject_extension_option_nonce,
        'cache_status_nonce' => $cache_status_nonce,
        'premium_nonce_purge' => wp_create_nonce('purge_cache_premium_nonce'),
        'premium_nonce_preload' => wp_create_nonce('preload_cache_premium_nonce'),
        'premium_content_nonce' => wp_create_nonce('load_premium_content_nonce'),
        'get_save_cron_nonce' => $update_cron_expression_option_nonce,
        'cache_schedule_nonce' => $update_cache_schedule_option_nonce,
        'cancel_scheduled_event_nonce' => $cancel_scheduled_event_nonce,
        'plugin_cache_nonce' => $clear_plugin_cache_nonce,
        'systemd_service_nonce' => $restart_systemd_service_nonce,
    ));
}

// Checks if the plugin requirements are met, specifically if the server
// is running on a Linux environment.
function nppp_is_linux() {
    // Check using PHP_OS constant
    if (PHP_OS === 'Linux') {
        return true;
    }

    // Check using PHP_OS_FAMILY (available in PHP 7.2+)
    if (defined('PHP_OS_FAMILY') && PHP_OS_FAMILY === 'Linux') {
        return true;
    }

    // Fallback check using php_uname() for edge cases or older PHP versions
    if (stripos(php_uname(), 'Linux') !== false) {
        return true;
    }

    // If none of the checks pass
    return false;
}

// Check plugin requirements
function nppp_plugin_requirements_met() {
    $nppp_met = false;

    // Check if the operating system is Linux
    if (nppp_is_linux()) {
        // Initialize $server_software variable
        $server_software = '';

        // Check if $_SERVER['SERVER_SOFTWARE'] is set
        if (isset($_SERVER['SERVER_SOFTWARE'])) {
            // Unslash and sanitize $_SERVER['SERVER_SOFTWARE']
            $server_software = sanitize_text_field(wp_unslash($_SERVER['SERVER_SOFTWARE']));
        }

        // Check for Nginx-specific environment variables
        if (empty($server_software) && isset($_SERVER['NGINX_VERSION'])) {
            $server_software = 'nginx';
        } elseif (empty($server_software) && isset($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            // Nginx acting as a reverse proxy
            $server_software = 'nginx';
        }

        // Check for the SAPI name to detect if Nginx is using PHP-FPM
        if (empty($server_software)) {
            $sapi_name = php_sapi_name();
            if (strpos($sapi_name, 'fpm-fcgi') !== false) {
                // Nginx with PHP-FPM
                $server_software = 'nginx';
            }
        }

        // If still no server software detected, check outgoing headers
        if (empty($server_software)) {
            $headers = headers_list();
            foreach ($headers as $header) {
                if (stripos($header, 'server: nginx') !== false) {
                    $server_software = 'nginx';
                    break;
                } elseif (stripos($header, 'server: apache') !== false) {
                    $server_software = 'apache';
                    break;
                }
            }
        }

        // Check if the web server is Nginx
        if (strpos($server_software, 'nginx') !== false) {
            // Check if shell_exec is enabled
            if (function_exists('shell_exec')) {
                // Attempt to execute a harmless command
                $output = shell_exec('echo "Test"');

                // Check if the command executed successfully
                if (trim($output) === "Test") {
                    $nppp_met = true;
                }
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
        $output = shell_exec('command -v wget');
        if (empty($output)) {
            // Wget is not available
            wp_enqueue_script('preload-button-disable', plugins_url('../admin/js/preload-button-disable.js', __FILE__), array('jquery'), '2.0.4', true);
        } else {
            // Wget is available, dequeue the preload-button-disable.js if it's already enqueued
            wp_dequeue_script('preload-button-disable');
        }
        wp_dequeue_script('nppp-disable-functionality');
    } else {
        // This plugin only works on Linux with nginx
        // Disable plugin functionality to prevent unexpected behaviors.
        wp_enqueue_script('nppp-disable-functionality', plugins_url('../admin/js/nppp-disable-functionality.js', __FILE__), array('jquery'), '2.0.4', true);
    }
}

// Enqueue CSS and JavaScript files for admin logged-in front-end
function nppp_enqueue_nginx_fastcgi_cache_purge_preload_front_assets() {
    if (is_user_logged_in() && current_user_can('administrator') && isset($_GET['nppp_front'])) {
        $nonce = isset($_GET['redirect_nonce']) ? sanitize_text_field(wp_unslash($_GET['redirect_nonce'])) : '';
        if (wp_verify_nonce($nonce, 'nppp_redirect_nonce')) {
            if (!is_admin()) {
                // Enqueue CSS files for Nginx FastCGI Cache Purge and Preload Plugin
                wp_enqueue_style('nppp_admin-front-css', plugins_url('../frontend/css/fastcgi-cache-purge-and-preload-nginx-front.css', __FILE__), array(), '2.0.4');
                // Enqueue JavaScript files for Nginx FastCGI Cache Purge and Preload Plugin frontend
                wp_enqueue_script('nppp_admin-front-js', plugins_url('../frontend/js/fastcgi-cache-purge-and-preload-nginx-front.js', __FILE__), array('jquery'), '2.0.4', true);
            }
        }
    }

    // Check plugin requirements and limit the functionality accordingly on front-end
    $nppp_met = nppp_plugin_requirements_met();
    if (!$nppp_met) {
        wp_enqueue_script('nppp-disable-functionality-front', plugins_url('../frontend/js/nppp-disable-functionality-front.js', __FILE__), array('jquery'), '2.0.4', true);
    } else {
        wp_dequeue_script('nppp-disable-functionality-front');
    }
}

// This function adds inline CSS to hide notices from other plugins on the
// settings page of the NPP plugin. It ensures that only notices from NPP
// are visible while on the plugin settings page.
function nppp_manage_admin_notices() {
    // Register a dummy stylesheet
    wp_register_style('nppp-manage-notices', false, array(), '2.0.4');

    // Enqueue the dummy stylesheet
    wp_enqueue_style('nppp-manage-notices');

    // Add inline CSS to hide all admin notices except those with the class 'notice-nppp'
    wp_add_inline_style('nppp-manage-notices', '
        /* Hide all admin notices except those with the class \'notice-nppp\' */
        .notice { display: none !important; }
        .notice.notice-nppp { display: block !important; }
    ');
}
