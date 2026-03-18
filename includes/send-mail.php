<?php
/**
 * Email notification handlers for Nginx Cache Purge Preload
 * Description: Sends completion and status emails for preload and related background tasks.
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

function nppp_send_mail_now(
    $mail_message,
    $elapsed_time_str,
    $final_total    = 0,
    $mobile_enabled = false,
    $last_preload_time = '',
    $download_size  = '',
    $transfer_speed = '',
    $error_count    = 0,
    $warn_count     = 0
) {
    $options           = get_option( 'nginx_cache_settings' );
    $nginx_cache_email = isset( $options['nginx_cache_email'] ) ? $options['nginx_cache_email'] : '';
    $send_mail         = isset( $options['nginx_cache_send_mail'] ) && $options['nginx_cache_send_mail'] === 'yes';
    $default_email     = 'your-email@example.com';

    if ( ! $send_mail || empty( $nginx_cache_email ) || $nginx_cache_email === $default_email ) {
        return;
    }

    $wp_filesystem = nppp_initialize_wp_filesystem();
    if ( $wp_filesystem === false ) {
        return;
    }

    // Site info
    $site_url       = get_site_url();
    $site_url_parts = wp_parse_url( $site_url );
    $domain         = str_replace( 'www.', '', $site_url_parts['host'] );

    // Cache path
    $default_cache_path = '/dev/shm/change-me-now';
    $nginx_cache_path   = isset( $options['nginx_cache_path'] ) ? $options['nginx_cache_path'] : $default_cache_path;

    // METRIC: URLs crawled
    $urls_crawled = $final_total > 0 ? number_format( $final_total ) : '–';

    // METRIC: Download size & speed
    $download_size_str  = ! empty( $download_size )  ? $download_size  : '–';
    $transfer_speed_str = ! empty( $transfer_speed ) ? $transfer_speed : '–';

    // METRIC: Connection errors & SSL warnings
    $errors_str   = number_format( max( 0, (int) $error_count ) );
    $warnings_str = number_format( max( 0, (int) $warn_count  ) );

    // METRIC: Finish timestamp
    $finish_time = ! empty( $last_preload_time ) ? $last_preload_time : '–';

    // METRIC: Cache coverage ratio
    $cache_ratio  = '–';
    $cache_hits   = '–';
    $cache_misses = '–';
    if ( function_exists( 'nppp_get_in_cache_page_count' ) && function_exists( 'nppp_parse_wget_log_urls' ) ) {
        $real_hits = nppp_get_in_cache_page_count();
        if ( is_numeric( $real_hits ) && (int) $real_hits >= 0 ) {
            $real_hits   = (int) $real_hits;
            $wget_urls   = nppp_parse_wget_log_urls( $wp_filesystem );
            $total_known = count( $wget_urls );
            if ( $total_known > 0 ) {
                $ratio        = min( 100.0, ( $real_hits / $total_known ) * 100.0 );
                $misses       = max( 0, $total_known - $real_hits );
                $cache_ratio  = number_format( $ratio, 1 ) . '%';
                $cache_hits   = number_format( $real_hits );
                $cache_misses = number_format( $misses );
            }
            // Persist for dashboard widget — scan just ran, store it
            update_option( 'nppp_last_known_hits',      $real_hits, false );
            update_option( 'nppp_last_hits_scanned_at', time(),     false );
        }
    }

    // METRIC: Cache size on disk
    $cache_size = '–';
    if ( function_exists( 'nppp_get_cache_disk_size' ) && function_exists( 'nppp_format_cache_size' ) ) {
        $disk = nppp_get_cache_disk_size( $nginx_cache_path );
        if ( is_array( $disk ) && isset( $disk['used'] ) && $disk['used'] > 0 ) {
            $cache_size = nppp_format_cache_size( (int) $disk['used'] );
        }
    }

    // METRIC: Bandwidth limit
    $limit_rate     = isset( $options['nginx_cache_limit_rate'] ) ? (int) $options['nginx_cache_limit_rate'] : 1280;
    $limit_rate_str = $limit_rate >= 1024
        ? number_format( $limit_rate / 1024, 1 ) . ' MB/s'
        : $limit_rate . ' KB/s';

    // METRIC: Mobile pass
    $mobile_pass = $mobile_enabled ? '&#10003; Yes' : '&#10007; No';

    // METRIC: Trigger
    $trigger = ( isset( $options['nginx_cache_schedule'] ) && $options['nginx_cache_schedule'] === 'yes' )
        ? 'Scheduled' : 'Manual';

    // Template
    $template_file = __DIR__ . '/mail.html';
    $image_url     = plugins_url( '/admin/img/logo-blackwhite.png', dirname( __FILE__ ) );

    $html_content = '';
    if ( $wp_filesystem->exists( $template_file ) ) {
        $html_content = $wp_filesystem->get_contents( $template_file );
        if ( ! empty( $html_content ) ) {
            $html_content = str_replace( '{{domain}}',         $domain,            $html_content );
            $html_content = str_replace( '{{site_url}}',       $site_url,          $html_content );
            $html_content = str_replace( '{{mail_message}}',   $mail_message,      $html_content );
            $html_content = str_replace( '{{elapsed_time}}',   $elapsed_time_str,  $html_content );
            $html_content = str_replace( '{{finish_time}}',    $finish_time,       $html_content );
            $html_content = str_replace( '{{urls_crawled}}',   $urls_crawled,      $html_content );
            $html_content = str_replace( '{{download_size}}',  $download_size_str, $html_content );
            $html_content = str_replace( '{{transfer_speed}}', $transfer_speed_str,$html_content );
            $html_content = str_replace( '{{cache_size}}',     $cache_size,        $html_content );
            $html_content = str_replace( '{{cache_ratio}}',    $cache_ratio,       $html_content );
            $html_content = str_replace( '{{cache_hits}}',     $cache_hits,        $html_content );
            $html_content = str_replace( '{{cache_misses}}',   $cache_misses,      $html_content );
            $html_content = str_replace( '{{errors}}',         $errors_str,        $html_content );
            $html_content = str_replace( '{{warnings}}',       $warnings_str,      $html_content );
            $html_content = str_replace( '{{limit_rate}}',     $limit_rate_str,    $html_content );
            $html_content = str_replace( '{{mobile_pass}}',    $mobile_pass,       $html_content );
            $html_content = str_replace( '{{trigger}}',        $trigger,           $html_content );
            $html_content = str_replace( '{{image_url}}',      $image_url,         $html_content );
        }
    }

    $headers = array(
        'Content-Type: text/html; charset=UTF-8',
        "From: NPP Wordpress <npp-no-reply@$domain>",
    );

    wp_mail(
        $nginx_cache_email,
        __( 'NPP Wordpress Report', 'fastcgi-cache-purge-and-preload-nginx' ),
        $html_content,
        $headers
    );
}
