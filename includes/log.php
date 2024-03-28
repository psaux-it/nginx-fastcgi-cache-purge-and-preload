<?php
/**
 * Logging & status functions for Nginx FastCGI Cache Purge and Preload
 * Description: This file contains Logging & status functions for Nginx FastCGI Cache Purge and Preload
 * Version: 1.0.3
 * Author: Hasan ÇALIŞIR
 * Author Email: hasan.calisir@psauxit.com
 * Author URI: https://www.psauxit.com
 * License: GPL-2.0+
 */

// Create log file
function create_log_file($log_file_path) {
    if (!empty($log_file_path)) {
        $file_creation_result = perform_file_operation($log_file_path, 'create');
        if (is_wp_error($file_creation_result)) {
            return "Error: " . $file_creation_result->get_error_message();
        }
        return true;
    }
    return "Log file path is empty.";
}

// Display admin notices
function display_admin_notice($type, $message) {
    echo '<div class="notice notice-' . esc_attr($type) . '"><p>' . esc_html($message) . '</p></div>';
    // Write to the log file
    $log_file_path = NGINX_CACHE_LOG_FILE;
    perform_file_operation($log_file_path, 'create');
    !empty($log_file_path) ? perform_file_operation($log_file_path, 'append', '[' . gmdate('Y-m-d H:i:s') . '] ' . $message) : die("Log file not found!");
}

// Function to check preload process status
function check_processes_status() {
    $PIDFILE = dirname(plugin_dir_path(__FILE__)) . '/cache_preload.pid';
    // If the process is running, display admin notice for preload in progress
    if (file_exists($PIDFILE)) {
        $pid = intval(perform_file_operation($PIDFILE, 'read'));

        if ($pid > 0 && posix_kill($pid, 0)) {
            display_admin_notice('info', 'INFO: FastCGI cache preload is in progress...');
            return;
        } else {
            // Retrieve the Nginx Cache Email setting value
            $options = get_option('nginx_cache_settings');
            // Retrieve the Nginx Cache Email setting value
            $nginx_cache_email = isset($options['nginx_cache_email']) ? $options['nginx_cache_email'] : '';
            // Check if Send Mail is checked
            $send_mail = isset($options['nginx_cache_send_mail']) && $options['nginx_cache_send_mail'] === 'yes';
            // Only send if user customized email address and send mail enabled
            $default_email = 'your-email@example.com';
            if ($send_mail && !empty($nginx_cache_email) && $nginx_cache_email !== $default_email) {
                // Extract the domain from the WordPress site URL
                $site_url = get_site_url();
                $site_url_parts = wp_parse_url($site_url);
                $domain = str_replace('www.', '', $site_url_parts['host']);
                // Set mail_from address with user domain
                $mail_from = "From: Nginx FastCGI Cache Purge Preload Wordpress<fcgi-cache@$domain>";
                // Mail subject
                $mail_subject = "$domain-NGINX FastCGI Cache Preload";
                // Mail message
                $mail_message = "The NGINX FastCGI Preload operation has been completed for $domain.";
                // Send email
                wp_mail($nginx_cache_email, $mail_subject, $mail_message, $mail_from);
            }

            // Display admin notice for completed preload
            display_admin_notice('success', 'SUCCESS: FastCGI cache preload is completed!');

            // Remove absolute downloaded content
            $this_script_path = dirname(plugin_dir_path(__FILE__));
            $tmp_path = rtrim($this_script_path, '/') . "/tmp";
            wp_remove_directory($tmp_path, true);

            // If the process is not running, delete the PID file
            perform_file_operation($PIDFILE, 'delete');
        }
    }
}
