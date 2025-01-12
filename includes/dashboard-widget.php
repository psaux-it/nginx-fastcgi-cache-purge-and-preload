<?php
/**
 * Dashboard widget for FastCGI Cache Purge and Preload for Nginx
 * Description: This file contains dashboard widget functions for FastCGI Cache Purge and Preload for Nginx
 * Version: 2.0.9
 * Author: Hasan CALISIR
 * Author Email: hasan.calisir@psauxit.com
 * Author URI: https://www.psauxit.com
 * License: GPL-2.0+
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Dashboard widget content
function nppp_dashboard_widget() {
    // Fetch the plugin settings from the database
    $settings = get_option('nginx_cache_settings', []);

    // Prepare status data (added Send Mail status)
    $statuses = [
        'auto_purge' => [
            'label' => __('Auto Purge', 'fastcgi-cache-purge-and-preload-nginx'),
            'status' => isset($settings['nginx_cache_purge_on_update']) && $settings['nginx_cache_purge_on_update'] === 'yes' ? __('Enabled', 'fastcgi-cache-purge-and-preload-nginx') : __('Disabled', 'fastcgi-cache-purge-and-preload-nginx'),
            'icon' => 'dashicons-trash'
        ],
        'auto_preload' => [
            'label' => __('Auto Preload', 'fastcgi-cache-purge-and-preload-nginx'),
            'status' => isset($settings['nginx_cache_auto_preload']) && $settings['nginx_cache_auto_preload'] === 'yes' ? __('Enabled', 'fastcgi-cache-purge-and-preload-nginx') : __('Disabled', 'fastcgi-cache-purge-and-preload-nginx'),
            'icon' => 'dashicons-update'
        ],
        'preload_mobile' => [
            'label' => __('Preload Mobile', 'fastcgi-cache-purge-and-preload-nginx'),
            'status' => isset($settings['nginx_cache_auto_preload_mobile']) && $settings['nginx_cache_auto_preload_mobile'] === 'yes' ? __('Enabled', 'fastcgi-cache-purge-and-preload-nginx') : __('Disabled', 'fastcgi-cache-purge-and-preload-nginx'),
            'icon' => 'dashicons-smartphone'
        ],
        'scheduled_cache' => [
            'label' => __('Scheduled Cache', 'fastcgi-cache-purge-and-preload-nginx'),
            'status' => isset($settings['nginx_cache_schedule']) && $settings['nginx_cache_schedule'] === 'yes' ? __('Enabled', 'fastcgi-cache-purge-and-preload-nginx') : __('Disabled', 'fastcgi-cache-purge-and-preload-nginx'),
            'icon' => 'dashicons-clock'
        ],
        'rest_api' => [
            'label' => __('REST API', 'fastcgi-cache-purge-and-preload-nginx'),
            'status' => isset($settings['nginx_cache_api']) && $settings['nginx_cache_api'] === 'yes' ? __('Enabled', 'fastcgi-cache-purge-and-preload-nginx') : __('Disabled', 'fastcgi-cache-purge-and-preload-nginx'),
            'icon' => 'dashicons-admin-network'
        ],
        'send_mail' => [
            'label' => __('Send Mail', 'fastcgi-cache-purge-and-preload-nginx'),
            'status' => isset($settings['nginx_cache_send_mail']) && $settings['nginx_cache_send_mail'] === 'yes' ? __('Enabled', 'fastcgi-cache-purge-and-preload-nginx') : __('Disabled', 'fastcgi-cache-purge-and-preload-nginx'),
            'icon' => 'dashicons-email-alt'
        ],
        'opt_in' => [
            'label' => __('Opt-In', 'fastcgi-cache-purge-and-preload-nginx'),
            'status' => isset($settings['nginx_cache_tracking_opt_in']) && $settings['nginx_cache_tracking_opt_in'] === '1' ? __('Enabled', 'fastcgi-cache-purge-and-preload-nginx') : __('Disabled', 'fastcgi-cache-purge-and-preload-nginx'),
            'icon' => 'dashicons-flag'
        ],
    ];

    // Output the widget content
    echo '<div style="border: 1px solid #e5e5e5;">';
        echo '<table style="width: 100%; border-collapse: collapse;">';
            foreach ($statuses as $key => $status_info) {
                $status = $status_info['status'];
                $icon = $status_info['icon'];

                // Determine the Dashicon and color based on status
                $status_icon = ($status === __('Enabled', 'fastcgi-cache-purge-and-preload-nginx')) ? 'dashicons-yes-alt' : 'dashicons-dismiss';
                $status_color = ($status === __('Enabled', 'fastcgi-cache-purge-and-preload-nginx')) ? '#5cb85c' : '#d9534f';

                echo '<tr style="border-bottom: 1px solid #f1f1f1;">';
                    echo '<td style="padding: 8px 15px; color: #555; font-weight: 500; width: 60%;">';
                        echo '<span class="dashicons ' . esc_attr( $icon ) . '" style="font-size: 18px; margin-right: 8px;"></span>';
                        echo esc_html($status_info['label']);
                    echo '</td>';
                    echo '<td style="padding: 8px 15px; text-align: center; font-size: 16px;">';
                        // Display the Dashicon with proper styling
                        echo '<span class="dashicons ' . esc_attr( $status_icon ) . '" style="color: ' . esc_attr( $status_color ) . '; font-size: 18px;"></span>';
                    echo '</td>';
                echo '</tr>';
            }
        echo '</table>';

        // Add "Give Us a Star"
        echo '<div style="display: flex; justify-content: space-between; align-items: center;">';
            echo '<a href="' . esc_url( 'https://wordpress.org/support/plugin/fastcgi-cache-purge-and-preload-nginx/reviews/#new-post' ) . '" target="_blank" style="font-size: 14px; color: indigo; background-color: #ffcc00; padding: 8px 12px; text-decoration: none; font-weight: 500; display: inline-flex; align-items: center; justify-content: center; transition: background-color 0.3s ease; flex: 60%;">';
                echo '<span class="dashicons dashicons-star-filled" style="font-size: 18px; margin-right: 0px; color: #fff;"></span>';
                echo '<span class="dashicons dashicons-star-filled" style="font-size: 18px; margin-right: 0px; color: #fff;"></span>';
                echo '<span class="dashicons dashicons-star-filled" style="font-size: 18px; margin-right: 0px; color: #fff;"></span>';
                echo '<span class="dashicons dashicons-star-filled" style="font-size: 18px; margin-right: 0px; color: #fff;"></span>';
                echo '<span class="dashicons dashicons-star-filled" style="font-size: 18px; margin-right: 3px; color: #fff;"></span>' . esc_html__( 'Give Us a Star', 'fastcgi-cache-purge-and-preload-nginx' );
            echo '</a>';

            // Add "Configure Settings" button
            echo '<a href="' . esc_url( 'options-general.php?page=nginx_cache_settings' ) . '" style="text-decoration: none; background-color: #0073aa; color: white; padding: 8px 12px; font-size: 14px; font-weight: 500; display: inline-flex; align-items: center; justify-content: center; transition: background-color 0.3s ease; flex: 40%; text-align: center;">';
                echo esc_html( __('Configure Settings', 'fastcgi-cache-purge-and-preload-nginx') );
            echo '</a>';
        echo '</div>';
    echo '</div>';
}

// Register the dashboard widget
function nppp_add_dashboard_widget() {
    wp_add_dashboard_widget(
        'nppp_dashboard_widget',
        /* Translators: NPP is the name of the plugin. */
        __('NPP - Nginx Cache Status', 'fastcgi-cache-purge-and-preload-nginx'),
        'nppp_dashboard_widget'
    );
}
