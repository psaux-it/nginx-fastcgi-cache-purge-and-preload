<?php
/**
 * Enqueue custom CSS and JavaScript files for FastCGI Cache Purge and Preload for Nginx
 * Description: This file contains enqueue assets functions for FastCGI Cache Purge and Preload for Nginx
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

// Enqueue CSS and JavaScript files only plugin settings page load
function nppp_enqueue_nginx_fastcgi_cache_purge_preload_assets() {
    // Enqueue CSS files for jQuery UI Tabs
    wp_enqueue_style('nppp_jquery-ui-css', plugins_url('../admin/css/jquery-ui.min.css', __FILE__), array(), '1.13.3');
    wp_enqueue_style('nppp_jquery-ui-tabs-theme', plugins_url('../admin/css/jquery-ui.theme.min.css', __FILE__), array(), '1.13.3');

    // Enqueue CSS files for dataTables
    wp_enqueue_style('nppp_datatables-css', plugins_url('../admin/css/dataTables.min.css', __FILE__), array(), '2.3.2');

    // Enqueue CSS files for Tempus Dominus Date/Time Picker
    wp_enqueue_style('nppp_tempus-dominus-css', plugins_url('../admin/css/tempus-dominus.min.css', __FILE__), array(), '6.9.4');

    // Enqueue CSS files for Nginx FastCGI Cache Purge and Preload Plugin
    wp_enqueue_style('nppp_admin-css', plugins_url('../admin/css/fastcgi-cache-purge-and-preload-nginx.min.css', __FILE__), array(), '2.1.3');

    // Enqueue jQuery UI core, jQuery UI Tabs, jQuery UI Accordion
    wp_enqueue_script('jquery-ui-core');
    wp_enqueue_script('jquery-ui-tabs');
    wp_enqueue_script('jquery-ui-accordion');

    /*!
    * DataTables v2.3.2
    * https://datatables.net/
    * Copyright 2008-2024, SpryMedia Ltd.
    * License: MIT (https://datatables.net/license/mit)
    */
    // Enqueue JavaScript files for dataTables
    wp_enqueue_script('nppp_datatables-js', plugins_url('../admin/js/dataTables.min.js', __FILE__), array('jquery'), '2.3.2', true);

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
    wp_enqueue_script('nppp_admin-js', plugins_url('../admin/js/fastcgi-cache-purge-and-preload-nginx.min.js', __FILE__), array('jquery', 'jquery-ui-core', 'jquery-ui-tabs', 'jquery-ui-accordion', 'nppp_datatables-js', 'nppp_tempus-dominus-js', 'wp-i18n'), '2.1.3', true);

    // Set script i18n translations
    wp_set_script_translations('nppp_admin-js', 'fastcgi-cache-purge-and-preload-nginx');

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
    // Create a nonce for default cache key regex
    $update_default_cache_key_regex_option_nonce = wp_create_nonce('nppp-update-default-cache-key-regex-option');
    // Create a nonce for preload mobile option
    $update_auto_preload_mobile_option_nonce = wp_create_nonce('nppp-update-auto-preload-mobile-option');
    // Create a nonce for enable proxy option
    $update_enable_proxy_option_nonce = wp_create_nonce('nppp-update-enable-proxy-option');

    // Localize nonce values for plugin main js
    wp_localize_script('nppp_admin-js', 'nppp_admin_data', array(
        'ajaxurl' => admin_url('admin-ajax.php'),
        'clear_logs_nonce' => $clear_nginx_cache_logs_nonce,
        'send_mail_nonce' => $update_send_mail_option_nonce,
        'auto_preload_nonce' => $update_auto_preload_option_nonce,
        'auto_preload_mobile_nonce' => $update_auto_preload_mobile_option_nonce,
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
        'cache_key_regex_nonce' => $update_default_cache_key_regex_option_nonce,
        'enable_proxy_nonce' => $update_enable_proxy_option_nonce,
        'wget_progress_api' => esc_url(rest_url('nppp_nginx_cache/v2/preload-progress')),
        'preload_progress_nonce' => wp_create_nonce('wp_rest'),
        'related_purge_nonce' => wp_create_nonce('nppp-related-posts-purge'),
        'premium_nonce_locate' => wp_create_nonce('locate_cache_file_nonce'),
        'col_cache_path'   => __( 'Cache Path', 'fastcgi-cache-purge-and-preload-nginx' ),
        'col_cache_status' => __( 'Status', 'fastcgi-cache-purge-and-preload-nginx' ),
        'pctnorm_nonce' => wp_create_nonce('nppp-update-pctnorm-mode'),
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

// Checks if the specified shell commands are available in the environment, indicating
// specially whether the system is likely Dockerized
function nppp_is_dockerized() {
    // Static key base for transient
    $static_key_base = 'nppp';
    $transient_key = 'nppp_missing_commands_' . md5($static_key_base);

    // Check if transient exists and is valid
    $missing_commands = get_transient($transient_key);

    if (false === $missing_commands) {
        // Set env
        nppp_prepare_request_env(true);

        // List of commands to check
        $commands_to_check = ['ps', 'grep', 'awk', 'sort', 'uniq', 'sed', 'nohup', 'wget'];

        // Initialize the missing commands array
        $missing_commands = [];

        // Loop through each command and check if it exists
        foreach ($commands_to_check as $command) {
            // Execute 'command -v' to check if the command exists in the shell
            $result = shell_exec("command -v {$command}");

            // If the result is empty, the command doesn't exist
            if (empty($result)) {
                $missing_commands[] = $command;
            }
        }

        // Save missing commands in a transient for 10 seconds
        set_transient($transient_key, $missing_commands, 10);
    }

    // Return the list of missing commands (or an empty array if none are missing)
    return $missing_commands;
}

// Conditionally disable Nginx Cache features
function nppp_disable_features($unsupported, $preload) {
    $unsupported = (bool) $unsupported;
    $preload     = (bool) $preload;

    // Retrieve the current settings
    $options = get_option( 'nginx_cache_settings', array() );

    // Determine which features to disable
    if ( $unsupported === true ) {
        // If unsupported is true
        $features = array(
            'nginx_cache_purge_on_update',
            'nginx_cache_auto_preload',
            'nginx_cache_auto_preload_mobile',
            'nginx_cache_schedule',
            'nginx_cache_send_mail',
            'nginx_cache_api',
            'nginx_cache_preload_enable_proxy',
            'nppp_related_include_home',
            'nppp_related_include_category',
            'nppp_related_apply_manual',
            'nppp_related_preload_after_manual',
        );
    } elseif ( $preload === true ) {
        // If preload is true
        $features = array(
            'nginx_cache_api',
            'nginx_cache_auto_preload',
            'nginx_cache_auto_preload_mobile',
            'nginx_cache_schedule',
            'nginx_cache_preload_enable_proxy',
        );
    } else {
        return;
    }

    // Set the selected features to 'no'.
    foreach ( $features as $feature ) {
        $options[ $feature ] = 'no';
    }

    // Update the option in the database.
    update_option( 'nginx_cache_settings', $options );
}

// Check NPP required shell toolset for plugin and preload action
function nppp_shell_toolset_check($global_, $preload) {
    // Define the toolsets based on the arguments
    if ($global_) {
        $commands = ['ps', 'grep', 'awk', 'sort', 'uniq', 'sed'];
    } elseif ($preload) {
        $commands = ['wget', 'nohup'];
    }

    // Get the missing commands from the existing transient
    $missing_commands = nppp_is_dockerized();

    // Find the commands from the selected toolset that are missing
    $missing_toolset_commands = array_intersect($missing_commands, $commands);

    // If no commands from the selected toolset are missing
    if (empty($missing_toolset_commands)) {
        return true;
    } else {
        return false;
    }
}

// Check plugin requirements
function nppp_plugin_requirements_met() {
    $wp_filesystem = nppp_initialize_wp_filesystem();

    if ($wp_filesystem === false) {
        nppp_display_admin_notice(
            'error',
            __( 'Failed to initialize the WordPress filesystem. Please file a bug on the plugin support page.', 'fastcgi-cache-purge-and-preload-nginx' )
        );
        return;
    }

    $nppp_met = false;

    // Set env
    nppp_prepare_request_env(true);

    // Check if the operating system is Linux
    if (nppp_is_linux()) {
        // Initialize $server_software variable
        $server_software = '';

        // Check SERVER_SOFTWARE
        if (isset($_SERVER['SERVER_SOFTWARE'])) {
            $server_software = sanitize_text_field(wp_unslash($_SERVER['SERVER_SOFTWARE']));
        }

        // If no SERVER_SOFTWARE detected, check response headers
        if (empty($server_software)) {
            // Perform the request
            $response = wp_remote_get(get_site_url());

            // Check if the request was successful
            if (is_array($response) && !is_wp_error($response)) {
                // Get response headers
                $headers = wp_remote_retrieve_headers($response);

                // Scan for any header name containing 'fastcgi'
                foreach ($headers as $key => $value) {
                    if (stripos($key, 'fastcgi') !== false) {
                        $header_value = is_array($value) ? implode(' ', $value) : $value;

                        if (!empty($header_value)) {
                            $server_software = 'nginx';
                            break;
                        }
                    }
                }

                // If still empty, check the 'server' header
                if (empty($server_software) && isset($headers['server'])) {
                    $server_header = $headers['server'];
                    $server_value = is_array($server_header) ? implode(' ', $server_header) : $server_header;

                    if (stripos($server_value, 'nginx') !== false) {
                        $server_software = 'nginx';
                    }
                }
            }
        }

        // Check for the SAPI name, not reliable
        if (empty($server_software)) {
            $sapi_name = php_sapi_name();
            if (strpos($sapi_name, 'fpm-fcgi') !== false) {
                $server_software = 'nginx';
            }
        }

        // Lastly fallback the traditional check for edge cases
        if (empty($server_software)) {
            $nginx_conf_paths = nppp_get_nginx_conf_paths($wp_filesystem);
            if (!empty($nginx_conf_paths)) {
                $server_software = 'nginx';
            }
        }

        // Check if the web server is Nginx
        if (stripos($server_software, 'nginx') !== false) {
            // Initialize a flag to track the success functions
            $shell_functions_enabled = true;

            // Check if shell_exec is enabled
            if (function_exists('shell_exec')) {
                // Attempt to execute a harmless command
                $output = shell_exec('echo "Test"');

                // Check if the command executed successfully
                if (trim($output) !== "Test") {
                    $shell_functions_enabled = false;
                }
            } else {
                $shell_functions_enabled = false;
            }

            // Check if exec is enabled
            if (function_exists('exec')) {
                // Attempt to execute a harmless command with exec
                $output = exec('echo "Test"');

                // Check if the command executed successfully
                if (trim($output) !== "Test") {
                    $shell_functions_enabled = false;
                }
            } else {
                $shell_functions_enabled = false;
            }

            // NPP ready to go
            if ($shell_functions_enabled && function_exists('posix_kill')) {
                // Lastly we check shell command required by NPP
                if (nppp_shell_toolset_check(true, false)) {
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
    $current_screen = get_current_screen();

    // Handle assets while switching wp admin area and NPP plugin settings page to prevent conflicts.
    if ($current_screen->base === 'dashboard') {
        // Enqueue the NPP WP admin dashboard JS
        if (!wp_script_is('nppp-dashboard-widget-js', 'enqueued')) {
            wp_enqueue_script('nppp-dashboard-widget-js', plugins_url('../admin/js/nppp-dashboard-widget.js', __FILE__), array('jquery'), '2.1.3', true);
        }

        // Enqueue the NPP WP admin dashboard CSS
        if (!wp_style_is('nppp-dashboard-widget-css', 'enqueued')) {
            wp_enqueue_style('nppp-dashboard-widget-css', plugins_url('../admin/css/nppp-dashboard-widget.css', __FILE__), array(), '2.1.3');
        }
    } elseif ($current_screen->id === 'settings_page_nginx_cache_settings') {
        // Dequeue the NPP WP admin dashboard JS
        if (wp_script_is('nppp-dashboard-widget-js', 'enqueued')) {
            wp_dequeue_script('nppp-dashboard-widget-js');
        }

        // Dequeue the NPP WP admin dashboard CSS
        if (wp_style_is('nppp-dashboard-widget-css', 'enqueued')) {
            wp_dequeue_style('nppp-dashboard-widget-css');
        }
    }

    // Disable/limit plugin functionality to prevent unexpected behaviors
    if ($nppp_met) {
        if (!nppp_shell_toolset_check(false, true)) {
            wp_enqueue_script('nppp-disable-preload', plugins_url('../admin/js/nppp-disable-preload.js', __FILE__), array('jquery'), '2.1.3', true);
            nppp_disable_features(false, true);
        } else {
            wp_dequeue_script('nppp-disable-preload');
        }
        wp_dequeue_script('nppp-disable-functionality');
    } else {
        wp_enqueue_script('nppp-disable-functionality', plugins_url('../admin/js/nppp-disable-functionality.js', __FILE__), array('jquery'), '2.1.3', true);
        nppp_disable_features(true, false);
    }
}

// Enqueue CSS and JavaScript files for admin, logged-in, front-end
function nppp_enqueue_nginx_fastcgi_cache_purge_preload_front_assets() {
    if (is_user_logged_in() && current_user_can('administrator') && isset($_GET['nppp_front'])) {
        $nonce = isset($_GET['redirect_nonce']) ? sanitize_text_field(wp_unslash($_GET['redirect_nonce'])) : '';
        if (wp_verify_nonce($nonce, 'nppp_redirect_nonce')) {
            if (!is_admin()) {
                // Enqueue CSS files for Nginx FastCGI Cache Purge and Preload Plugin
                wp_enqueue_style('nppp_admin-front-css', plugins_url('../frontend/css/fastcgi-cache-purge-and-preload-nginx-front.css', __FILE__), array(), '2.1.3');
                // Enqueue JavaScript files for Nginx FastCGI Cache Purge and Preload Plugin frontend
                wp_enqueue_script('nppp_admin-front-js', plugins_url('../frontend/js/fastcgi-cache-purge-and-preload-nginx-front.js', __FILE__), array('jquery'), '2.1.3', true);
            }
        }
    }

    // Check plugin requirements and limit the functionality accordingly on front-end
    $nppp_met = nppp_plugin_requirements_met();
    if (!$nppp_met) {
        wp_enqueue_script('nppp-disable-functionality-front', plugins_url('../frontend/js/nppp-disable-functionality-front.js', __FILE__), array('jquery'), '2.1.3', true);
        // Make sure partial-preload disable is not also active
        wp_dequeue_script('nppp-disable-preload-front');
    } else {
        wp_dequeue_script('nppp-disable-functionality-front');
        // Extra gate: if shell/toolset missing, disable only preload actions on front-end
        $has_shell = function_exists('nppp_shell_toolset_check') ? nppp_shell_toolset_check(false, true) : false;
        if (! $has_shell) {
            wp_enqueue_script('nppp-disable-preload-front', plugins_url('../frontend/js/nppp-disable-preload-front.js', __FILE__), array('jquery'), '2.1.3', true);
        } else {
            wp_dequeue_script('nppp-disable-preload-front');
        }
    }
}

// This function adds inline CSS to hide notices from other plugins on the
// settings page of the NPP plugin. It ensures that only notices from NPP
// are visible while on the plugin settings page.
function nppp_manage_admin_notices() {
    // Register a dummy stylesheet
    wp_register_style('nppp-manage-notices', false, array(), '2.1.3');

    // Enqueue the dummy stylesheet
    wp_enqueue_style('nppp-manage-notices');

    // Add inline CSS to hide all admin notices except those with the class 'notice-nppp'
    wp_add_inline_style('nppp-manage-notices', '
        /* Hide all admin notices except those with the class \'notice-nppp\' */
        .notice,
        .update-nag,
        .notice-success,
        .notice-warning,
        .notice-error,
        .updated,
        .error,
        .is-dismissible,
        .vc_license-activation-notice {
            display: none !important;
        }

        .notice.notice-nppp,
        .updated.notice-nppp,
        .error.notice-nppp {
            display: block !important;
        }
    ');
}
