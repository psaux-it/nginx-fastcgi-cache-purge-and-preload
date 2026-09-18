<?php
/**
 * Email notification handlers for Nginx Cache Purge Preload
 * Description: Sends completion and status emails for preload and related background tasks.
 * Version: 2.1.7
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
    $final_total       = 0,
    $mobile_enabled    = false,
    $last_preload_time = '',
    $download_size     = '',
    $transfer_speed    = '',
    $error_count       = 0,
    $precomputed_hits  = null
) {
    $options           = get_option( 'nginx_cache_settings' );
    $nginx_cache_email = isset( $options['nginx_cache_email'] ) ? $options['nginx_cache_email'] : '';
    $send_mail         = isset( $options['nginx_cache_send_mail'] ) && $options['nginx_cache_send_mail'] === 'yes';
    $default_email     = 'your-email@example.com';

    if ( ! $send_mail || empty( $nginx_cache_email ) || $nginx_cache_email === $default_email ) {
        return;
    }

    // Cheap cache-first gate. Preload can be triggered from many roots
    // (cron, manual button, REST, WP-CLI) and they all funnel through this
    // one function to send mail — so this is the one place to check.
    // If the last real attempt below failed, skip straight out here for a
    // while instead of paying SMTP connect/timeout cost + building the
    // whole HTML report again on every single preload.
    $mail_health_key = 'nppp_mail_health_' . md5( 'nppp' );
    if ( 'fail' === get_transient( $mail_health_key ) ) {
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

    // METRIC: 404 Broken URLs
    $errors_str   = number_format( max( 0, (int) $error_count ) );

    // METRIC: Finish timestamp
    $finish_time = ! empty( $last_preload_time ) ? $last_preload_time : '–';

    // METRIC: Cache coverage ratio
    $cache_ratio  = '–';
    $cache_hits   = '–';
    $cache_misses = '–';

    // Use the pre-computed hit count from the caller when available
    if ( $precomputed_hits !== null && is_int( $precomputed_hits ) && $precomputed_hits >= 0 ) {
        $real_hits_resolved = $precomputed_hits;
    } elseif ( function_exists( 'nppp_get_in_cache_page_count' ) ) {
        $real_hits_resolved = nppp_get_in_cache_page_count();
    } else {
        $real_hits_resolved = null;
    }

    if ( function_exists( 'nppp_parse_wget_log_urls' ) && is_numeric( $real_hits_resolved ) && (int) $real_hits_resolved >= 0 ) {
        $real_hits   = (int) $real_hits_resolved;
        $wget_urls   = nppp_parse_wget_log_urls( $wp_filesystem );
        $total_known = count( $wget_urls );
        if ( $total_known > 0 ) {
            $ratio        = min( 100.0, ( $real_hits / $total_known ) * 100.0 );
            $misses       = max( 0, $total_known - $real_hits );
            $cache_ratio  = number_format( $ratio, 1 ) . '%';
            $cache_hits   = number_format( $real_hits );
            $cache_misses = number_format( $misses );
        }
        // Persist for dashboard widget
        update_option( 'nppp_last_known_hits',      $real_hits, false );
        update_option( 'nppp_last_hits_scanned_at', time(),     false );
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
    $trigger = get_transient( 'nppp_preload_trigger_' . md5('nppp') );
    if ( empty( $trigger ) ) {
        $trigger = '–';
    }
    delete_transient( 'nppp_preload_trigger_' . md5('nppp') );

    // Template
    $template_file = __DIR__ . '/templates/mail.html';
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

    $mail_result = nppp_wp_mail_diagnostic(
        $nginx_cache_email,
        __( 'NPP Wordpress Report', 'fastcgi-cache-purge-and-preload-nginx' ),
        $html_content,
        $headers
    );

    if ( ! $mail_result['sent'] ) {
        // Cache the failure so the next preloads hit the guard above
        // instead of retrying a transport that is already known broken.
        set_transient( $mail_health_key, 'fail', HOUR_IN_SECONDS );
        nppp_custom_error_log(
            sprintf(
                /* translators: %s: underlying mail transport error */
                __( 'Preload report email could not be sent: %s', 'fastcgi-cache-purge-and-preload-nginx' ),
                $mail_result['error']
            )
        );
        return;
    }

    // Transport is healthy again — clear any previously cached failure.
    delete_transient( $mail_health_key );
}

/**
 * wp_mail() wrapper for call sites (AJAX handlers in particular) that need
 * to know *why* a send failed, not just whether it failed.
 *
 * Three things a plain wp_mail() call cannot give an AJAX response:
 *
 * 1. The real transport error. wp_mail() only returns a bool
 * 2. A fast failure once SMTP is in use.
 * 3. A clean response body.
 *
 * wp_mail() reuses one $phpmailer instance for the entire PHP request
 * (`global $phpmailer`), so any property changed here is restored to its
 * pre-call value afterwards — otherwise a tightened Timeout would silently
 * leak into every later wp_mail() call in the same request (e.g. a core
 * notification email sent later in the same admin-ajax.php request).
 *
 * @param string|string[] $to
 * @param string          $subject
 * @param string          $message
 * @param string[]        $headers
 * @param int             $connect_timeout Seconds to allow for the SMTP
 *                                         connection before giving up.
 *                                         Only takes effect if SMTP
 *                                         transport ends up being used.
 * @return array{sent: bool, error: string} error is '' when sent === true.
 */
function nppp_wp_mail_diagnostic( $to, string $subject, string $message, array $headers = array(), int $connect_timeout = 15 ): array {
    $captured_error      = '';
    $original_timeout    = null;
    $original_keepalive  = null;

    $on_failed = static function ( $wp_error ) use ( &$captured_error ) {
        if ( $wp_error instanceof WP_Error ) {
            $captured_error = $wp_error->get_error_message();
        }
    };

    $tighten_timeout = static function ( $phpmailer ) use ( $connect_timeout, &$original_timeout, &$original_keepalive ) {
        $original_timeout = isset( $phpmailer->Timeout ) ? $phpmailer->Timeout : null;
        if ( property_exists( $phpmailer, 'SMTPKeepAlive' ) ) {
            $original_keepalive = $phpmailer->SMTPKeepAlive;
        }

        // Only tighten it — never loosen a value that is already stricter.
        if ( null === $original_timeout || (int) $original_timeout <= 0 || (int) $original_timeout > $connect_timeout ) {
            $phpmailer->Timeout = $connect_timeout;
        }
        if ( property_exists( $phpmailer, 'SMTPKeepAlive' ) ) {
            $phpmailer->SMTPKeepAlive = false;
        }
    };

    add_action( 'wp_mail_failed', $on_failed );
    // Priority 999: run after any SMTP plugin's own phpmailer_init callback
    // so we see (and tighten) its real, final Timeout value rather than a
    // default that gets overwritten right after us.
    add_action( 'phpmailer_init', $tighten_timeout, 999 );

    ob_start();
    $sent  = wp_mail( $to, $subject, $message, $headers );
    $stray = trim( (string) ob_get_clean() );

    remove_action( 'wp_mail_failed', $on_failed );
    remove_action( 'phpmailer_init', $tighten_timeout, 999 );

    // Restore the shared PHPMailer instance's original properties so this
    // diagnostic call has no side effect on any wp_mail() call that follows
    // it later in the same request.
    global $phpmailer;
    if ( is_object( $phpmailer ) ) {
        if ( null !== $original_timeout && property_exists( $phpmailer, 'Timeout' ) ) {
            $phpmailer->Timeout = $original_timeout;
        }
        if ( null !== $original_keepalive && property_exists( $phpmailer, 'SMTPKeepAlive' ) ) {
            $phpmailer->SMTPKeepAlive = $original_keepalive;
        }
    }

    if ( '' !== $stray && function_exists( 'nppp_display_admin_notice' ) ) {
        // Never let raw PHP output reach the client; log it for the admin.
        nppp_display_admin_notice(
            'warning',
            'wp_mail() produced unexpected output while sending: ' . wp_strip_all_tags( $stray ),
            true,
            false
        );
    }

    if ( $sent ) {
        return array( 'sent' => true, 'error' => '' );
    }

    if ( '' === $captured_error ) {
        $captured_error = __( 'no further detail was reported by the mail transport', 'fastcgi-cache-purge-and-preload-nginx' );
    }

    // Cap length defensively; some SMTP libraries echo the whole server
    // banner into the exception message.
    $captured_error = wp_strip_all_tags( $captured_error );
    if ( strlen( $captured_error ) > 300 ) {
        $captured_error = substr( $captured_error, 0, 297 ) . '...';
    }

    return array( 'sent' => false, 'error' => $captured_error );
}
