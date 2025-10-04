<?php
/**
 * Send mail code for FastCGI Cache Purge and Preload for Nginx
 * Description: This file contains send mail code for FastCGI Cache Purge and Preload for Nginx
 * Version: 2.1.4
 * Author: Hasan CALISIR
 * Author Email: hasan.calisir@psauxit.com
 * Author URI: https://www.psauxit.com
 * License: GPL-2.0+
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Send mail function for completed preload action
function nppp_send_mail_now($mail_message, $elapsed_time_str) {
    // Retrieve the Nginx Cache Email setting value
    $options = get_option('nginx_cache_settings');

    // Retrieve the Nginx Cache Email setting value
    $nginx_cache_email = isset($options['nginx_cache_email']) ? $options['nginx_cache_email'] : '';

    // Check if Send Mail is checked
    $send_mail = isset($options['nginx_cache_send_mail']) && $options['nginx_cache_send_mail'] === 'yes';

    // Only send if user customized email address and send mail enabled
    $default_email = 'your-email@example.com';

    // Send mail
    if ($send_mail && !empty($nginx_cache_email) && $nginx_cache_email !== $default_email) {
        // Extract the domain from the WordPress site URL
        $site_url = get_site_url();
        $site_url_parts = wp_parse_url($site_url);
        $domain = str_replace('www.', '', $site_url_parts['host']);

        // Translators: NPP is the plugin shortname
        $mail_subject = __('NPP Wordpress Report', 'fastcgi-cache-purge-and-preload-nginx');

        // Set the path to the email template file
        $template_file = __DIR__ . '/mail.html';

        // Get the mail image URL
        $image_url = plugins_url('/admin/img/logo-blackwhite.png', dirname(__FILE__));

        // Initialize wp filesystem
        $wp_filesystem = nppp_initialize_wp_filesystem();
        if ($wp_filesystem === false) {
            nppp_display_admin_notice(
                'error',
                __( 'Failed to initialize the WordPress filesystem. Please file a bug on the plugin support page.', 'fastcgi-cache-purge-and-preload-nginx' )
            );
            return;
        }

        // Read the content of the HTML file
        $html_content = '';
        if ($wp_filesystem->exists($template_file)) {
            $html_content = $wp_filesystem->get_contents($template_file);

            // Ensure the HTML content is not empty before replacing placeholders
            if (!empty($html_content)) {
                // Replace placeholders with actual values
                $html_content = str_replace('{{domain}}', $domain, $html_content);
                $html_content = str_replace('{{mail_message}}', $mail_message, $html_content);
                $html_content = str_replace('{{elapsed_time_str}}', $elapsed_time_str, $html_content);
                $html_content = str_replace('{{image_url}}', $image_url, $html_content);
            }
        }

        // Set mail headers
        $headers = array(
            "Content-Type: text/html; charset=UTF-8",
            "From: NPP Wordpress <npp-no-reply@$domain>"
        );

        // Send email
        wp_mail($nginx_cache_email, $mail_subject, $html_content, $headers);
    }
}
