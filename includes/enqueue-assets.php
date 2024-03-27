<?php
/**
 * Enqueue custom CSS and JavaScript files for Nginx FastCGI Cache Purge and Preload Plugin
 * Description: This file contains enqueue assets function for Nginx FastCGI Cache Purge and Preload Plugin
 * Version: 1.0.3
 * Author: Hasan ÇALIŞIR
 * Author Email: hasan.calisir@psauxit.com
 * Author URI: https://www.psauxit.com
 * License: GPL-2.0+
 */

// Enqueue custom CSS and JavaScript files
function enqueue_nginx_fastcgi_cache_purge_preload_assets() {
    // Enqueue CSS file
    wp_enqueue_style('nginx-fastcgi-cache-purge-preload', plugins_url('../assets/css/nginx-fastcgi-cache-purge-preload.css', __FILE__), array(), '1.0.3');
    // Enqueue JavaScript file
    wp_enqueue_script('nginx-fastcgi-cache-admin', plugins_url('../assets/js/nginx-fastcgi-cache-purge-preload.js', __FILE__), array('jquery'), '1.0.3', true);
    // Create a nonce for clearing nginx cache logs
    $clear_nginx_cache_logs_nonce = wp_create_nonce('clear-nginx-cache-logs');
    // Create a nonce for updating send mail option
    $update_send_mail_option_nonce = wp_create_nonce('update-send-mail-option');
    // Create a nonce for the status tab
    $status_ajax_nonce = wp_create_nonce('status_ajax_nonce');

    // Localize nonce value for JavaScript
    wp_localize_script('nginx-fastcgi-cache-admin', 'nginx_cache_ajax_object', array(
        'ajaxurl' => admin_url('admin-ajax.php'),
        'nonce' => $clear_nginx_cache_logs_nonce,
        'send_mail_nonce' => $update_send_mail_option_nonce,
        'status_ajax_nonce' => $status_ajax_nonce,
    ));
}
