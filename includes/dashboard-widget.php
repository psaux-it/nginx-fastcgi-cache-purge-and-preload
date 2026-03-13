<?php
/**
 * Dashboard widget module for Nginx Cache Purge Preload
 * Description: Renders WordPress dashboard status widgets and recent preload/purge summaries.
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

// Get latest Preload complete date
function nppp_get_last_preload_complete_date() {
    $static_key_base = 'nppp';
    $timestamp_transient_key = 'nppp_last_preload_time_' . md5($static_key_base);

    // Try to get from transient first
    $cached_timestamp = get_transient($timestamp_transient_key);
    if (!empty($cached_timestamp)) {
        return $cached_timestamp;
    }

    $wp_filesystem = nppp_initialize_wp_filesystem();

    if ($wp_filesystem === false) {
        nppp_display_admin_notice(
            'error',
            __( 'Failed to initialize the WordPress filesystem. Please file a bug on the plugin support page.', 'fastcgi-cache-purge-and-preload-nginx' )
        );
        return;
    }

    // Define a constant for the log file path
    if ( ! defined( 'NGINX_CACHE_LOG_FILE' ) ) {
        define('NGINX_CACHE_LOG_FILE', nppp_get_runtime_file('fastcgi_ops.log'));
    }

    // Check if the log file constant is defined
    if (defined('NGINX_CACHE_LOG_FILE')) {
        $log_file_path = NGINX_CACHE_LOG_FILE;

        // Check if log file exists
        if ($wp_filesystem->exists($log_file_path)) {
            // Read the log file
            $log_contents = $wp_filesystem->get_contents($log_file_path);

            // Split log contents into lines
            $log_lines = explode("\n", $log_contents);

            // Variable to hold the latest timestamp
            $latest_timestamp = null;

            // Regex pattern to match time strings
            $pattern = '\d{4}-\d{2}-\d{2}\s\d{2}:\d{2}:\d{2}\]\s.*\bin\s*((?:\d+\s*\w+\s*(?:,\s*)?)+(?:\s*\d+\s*\w+))';

            // Iterate through each line in the log to find a matching time format
            foreach (array_reverse($log_lines) as $line) {
                // Match the time pattern anywhere in the line
                if (preg_match('/' . $pattern . '/', $line)) {
                    // Extract the timestamp part from the line
                    preg_match('/\[(.*?)\]/', $line, $match);
                    if (isset($match[1])) {
                        $latest_timestamp = $match[1];
                        return $latest_timestamp;
                    }
                }
            }

            // Return the latest timestamp found
            return null;
        } else {
            // Log file doesn't exist
            return null;
        }
    } else {
        // Log file constant is not defined
        return null;
    }
}

// Check preload action status
function nppp_check_preload_status_widget() {
    $wp_filesystem = nppp_initialize_wp_filesystem();

    if ($wp_filesystem === false) {
        nppp_display_admin_notice(
            'error',
            __('Failed to initialize the WordPress filesystem. Please file a bug on the plugin support page.', 'fastcgi-cache-purge-and-preload-nginx')
        );
        return;
    }

    $this_script_path = dirname(plugin_dir_path(__FILE__));
    $PIDFILE = nppp_get_runtime_file('cache_preload.pid');

    if ($wp_filesystem->exists($PIDFILE)) {
        $pid = intval(nppp_perform_file_operation($PIDFILE, 'read'));

        if ($pid > 0 && nppp_is_process_alive($pid)) {
            return true;
        }
    }

    return false;
}

// Show Next Run: Scheduled event in widget
function nppp_get_active_cron_events_widget() {
    // Get all scheduled events
    $events = _get_cron_array();

    // npp plugin's schedule event hook name
    $plugin_hook = 'npp_cache_preload_event';

    // Initialize a flag to track if events are found
    $has_events = false;

    // Get the WordPress timezone string
    $timezone_string = wp_timezone_string();

    // Check if there are any scheduled events for npp
    if (!empty($events) && !empty($timezone_string)) {
        // Loop through each scheduled event
        foreach ($events as $timestamp => $cron) {
            foreach ($cron as $hook => $args) {
                // Check if the hook matches the npp's hook name
                if ($hook === $plugin_hook) {
                    // Set the flag to indicate that events are found
                    $has_events = true;

                    // Convert the timestamp to a DateTime object
                    $next_run_datetime = new DateTime('@' . $timestamp);

                    // Set the timezone to the WordPress timezone
                    $next_run_datetime->setTimezone(new DateTimeZone(wp_timezone_string()));

                    // Format the DateTime object
                    $next_run_formatted = $next_run_datetime->format('Y-m-d H:i:s');

                    // Format the scheduled event information
                    echo '<div class="nppp-scheduled-event">';
                        echo '<div class="nppp-cron-info">';
                            echo '<td style="padding: 6px 15px; width: 75%;">';
                                echo '<span class="dashicons dashicons-arrow-right-alt2" style="font-size: 18px; vertical-align: middle; margin-right: 8px;"></span>';
                                echo '<span class="nppp-next-run">' . sprintf(
                                    /* Translators: %s is the formatted next run time */
                                    esc_html__('Next Run: %s', 'fastcgi-cache-purge-and-preload-nginx'),
                                    '<strong style="color: #2196f3; font-size: 12px;">' . esc_html($next_run_formatted) . '</strong>'
                                ) . '</span>';
                            echo '</td>';
                            echo '<td style="padding: 6px 15px; text-align: center;">';
                                echo '<span class="dashicons dashicons-info" style="font-size: 18px; color: orange;"></span>';
                            echo '</td>';
                        echo '</div>';
                    echo '</div>';

                    // Exit the inner loop
                    break;
                }
            }

            // Exit the outer loop
            if ($has_events) {
                break;
            }
        }
    }

    // If no matching event was found
    if (!$has_events) {
        echo '<div class="nppp-scheduled-event">';
            echo '<div class="nppp-cron-info">';
                echo '<td style="padding: 6px 15px; width: 75%;">';
                    echo '<span class="dashicons dashicons-arrow-right-alt2" style="font-size: 18px; vertical-align: middle; margin-right: 8px;"></span>';
                    echo '<span class="nppp-next-run">' . sprintf(
                        /* Translators: %s is the formatted next run time */
                        esc_html__('Next Run: %s', 'fastcgi-cache-purge-and-preload-nginx'),
                        '<strong style="color: #2196f3; font-size: 12px;">' . esc_html__('No event found', 'fastcgi-cache-purge-and-preload-nginx') . '</strong>'
                    ) . '</span>';
                echo '</td>';
                echo '<td style="padding: 6px 15px; text-align: center;">';
                    echo '<span class="dashicons dashicons-info" style="font-size: 18px; color: orange;"></span>';
                echo '</td>';
            echo '</div>';
        echo '</div>';
    }
}

// NPP dashboard widget content
function nppp_dashboard_widget() {
    // Fetch the NPP plugin settings from the database
    $settings = get_option('nginx_cache_settings', []);

    // URL Normalization
    $pctnorm_mode = isset($settings['nginx_cache_pctnorm_mode']) ? $settings['nginx_cache_pctnorm_mode'] : 'off';
    $pctnorm_enabled = $pctnorm_mode !== 'off';

    if ($pctnorm_enabled && function_exists('nppp_find_safexec_path') && function_exists('nppp_is_safexec_usable')) {
        $safexec_path = nppp_find_safexec_path();
        $pctnorm_enabled = $safexec_path && nppp_is_safexec_usable($safexec_path, false);
    }

    // Cloudflare APO Sync — three states: Enabled / Disabled / Unavailable
    // Unavailable means the Cloudflare APO plugin is not installed/active,
    // so the sync option cannot function regardless of the saved setting.
    $cf_available = function_exists('nppp_cloudflare_apo_is_available') && nppp_cloudflare_apo_is_available();
    $cf_sync_on   = isset($settings['nppp_cloudflare_apo_sync']) && $settings['nppp_cloudflare_apo_sync'] === 'yes';

    if ( ! $cf_available ) {
        $cf_status = __('Unavailable', 'fastcgi-cache-purge-and-preload-nginx');
    } elseif ( $cf_sync_on ) {
        $cf_status = __('Enabled', 'fastcgi-cache-purge-and-preload-nginx');
    } else {
        $cf_status = __('Disabled', 'fastcgi-cache-purge-and-preload-nginx');
    }

    // Redis Object Cache Sync — three states: Enabled / Disabled / Unavailable
    // Unavailable means the Redis Object Cache plugin is not installed/active/connected,
    // so the sync option cannot function regardless of the saved setting.
    $redis_available = function_exists( 'nppp_redis_cache_is_available' ) && nppp_redis_cache_is_available();
    $redis_sync_on   = isset( $settings['nppp_redis_cache_sync'] ) && $settings['nppp_redis_cache_sync'] === 'yes';

    if ( ! $redis_available ) {
        $redis_status = __( 'Unavailable', 'fastcgi-cache-purge-and-preload-nginx' );
    } elseif ( $redis_sync_on ) {
        $redis_status = __( 'Enabled', 'fastcgi-cache-purge-and-preload-nginx' );
    } else {
        $redis_status = __( 'Disabled', 'fastcgi-cache-purge-and-preload-nginx' );
    }

    // Need setup
    $needs_setup = class_exists('\NPPP\Setup') && \NPPP\Setup::nppp_needs_setup();
    $setup_url   = admin_url('admin.php?page=' . \NPPP\Setup::PAGE_SLUG);
    $settings_url = admin_url('options-general.php?page=nginx_cache_settings');

    // Check if the preload process is running
    $is_preload_alive = nppp_check_preload_status_widget();

    // Get the latest preload complete date
    $last_preload_complete_date = nppp_get_last_preload_complete_date();

    // Prepare NPP plugin statuses data
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
        'enable_proxy' => [
            'label' => __('Proxy', 'fastcgi-cache-purge-and-preload-nginx'),
            'status' => isset($settings['nginx_cache_preload_enable_proxy']) && $settings['nginx_cache_preload_enable_proxy'] === 'yes' ? __('Enabled', 'fastcgi-cache-purge-and-preload-nginx') : __('Disabled', 'fastcgi-cache-purge-and-preload-nginx'),
            'icon'   => 'dashicons-randomize'
        ],
        'url_normalization' => [
            'label' => __('URL Normalization', 'fastcgi-cache-purge-and-preload-nginx'),
            'status' => $pctnorm_enabled ? __('Enabled', 'fastcgi-cache-purge-and-preload-nginx') : __('Disabled', 'fastcgi-cache-purge-and-preload-nginx'),
            'icon' => 'dashicons-admin-links'
        ],
        'cloudflare_apo' => [
            'label'       => __('Cloudflare APO', 'fastcgi-cache-purge-and-preload-nginx'),
            'status'      => $cf_status,
            'icon'        => 'dashicons-cloud',
            'unavailable' => ! $cf_available,
        ],
        'redis_object_cache' => [
            'label'       => __( 'Redis Object Cache', 'fastcgi-cache-purge-and-preload-nginx' ),
            'status'      => $redis_status,
            'icon'        => 'dashicons-database',
            'unavailable' => ! $redis_available,
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
    ];

    // Output the widget content with buttons
    echo '<div id="nppp-widget-placeholder" style="border: 1px solid #e5e5e5;">';

        // Add hidden element for progress status
        if ($is_preload_alive) {
            echo '<div id="nppp-preload-in-progress" style="display: none;"></div>';
        }

        // Output the preloader HTML
        echo '<div id="nppp-loader-overlay" aria-live="assertive" aria-busy="true">
                <div class="nppp-spinner-container">
                    <div class="nppp-loader"></div>
                    <div class="nppp-fill-mask">
                        <div class="nppp-loader-fill"></div>
                    </div>
                    <span class="nppp-loader-text">NPP</span>
                </div>
                <p class="nppp-loader-message">' . esc_html__('Processing, please wait...', 'fastcgi-cache-purge-and-preload-nginx') . '</p>
            </div>';

        // Output the "Purge All" and "Preload All" top buttons
        echo '<div style="display: flex; justify-content: space-between; align-items: center;">';
            // Purge All button
            echo '<a href="' . esc_url(
                    $needs_setup
                        ? $setup_url
                        : wp_nonce_url(admin_url('admin.php?action=nppp_purge_cache'), 'purge_cache_nonce')
                ) . '"
                    class="nppp-action-button"
                    data-action="nppp-widget-purge"
                    style="font-size: 14px; color: white; background-color: #d9534f; padding: 8px 12px; text-decoration: none; font-weight: 500; display: inline-flex; align-items: center; justify-content: center; transition: background-color 0.3s ease; flex: 48%;">';
                echo '<span class="dashicons dashicons-trash" style="font-size: 18px; margin-right: 8px;"></span>' . esc_html__('Purge All', 'fastcgi-cache-purge-and-preload-nginx');
            echo '</a>';
            // Preload All button
            echo '<a href="' . esc_url(
                    $needs_setup
                        ? $setup_url
                        : wp_nonce_url(admin_url('admin.php?action=nppp_preload_cache'), 'preload_cache_nonce')
                ) . '"
                    class="nppp-action-button"
                    data-action="nppp-widget-preload"
                    style="font-size: 14px; color: white; background-color: #3CB371; padding: 8px 12px; text-decoration: none; font-weight: 500; display: inline-flex; align-items: center; justify-content: center; transition: background-color 0.3s ease; flex: 48%;">';
                echo '<span class="dashicons dashicons-update" style="font-size: 18px; margin-right: 8px;"></span>' . esc_html__('Preload All', 'fastcgi-cache-purge-and-preload-nginx');
            echo '</a>';
        echo '</div>';

        // Cache Coverage Ratio.
        // Read the hit count stored by the Status or Advanced tab (zero scan cost).
        // nppp_get_cache_ratio() derives the total from the wget snapshot log via its
        // own 5-min transient.
        $nppp_widget_hits     = get_option( 'nppp_last_known_hits',      false );
        $nppp_widget_scan_at  = get_option( 'nppp_last_hits_scanned_at', false );
        $nppp_ratio_pct       = null;
        $nppp_ratio_hits      = null;
        $nppp_ratio_misses    = null;
        $nppp_ratio_total     = null;
        $nppp_ratio_na        = true;

        // Checkpoint 1: does a completed preload snapshot exist?
        // We check this directly — no option dependency needed for this state.
        $nppp_snapshot_path   = nppp_get_runtime_file( 'nppp-wget-snapshot.log' );
        $nppp_snapshot_exists = file_exists( $nppp_snapshot_path );

        if ( ! $nppp_snapshot_exists ) {
            // No snapshot at all — user needs to run Preload All first.
            $nppp_ratio_na_reason = 'no_snapshot';

        } elseif ( $nppp_widget_hits === false ) {
            // Snapshot exists but hit count option not yet written.
            $nppp_ratio_na_reason = 'not_initialized';

        } elseif ( function_exists( 'nppp_get_cache_ratio' ) ) {
            $nppp_ratio_data = nppp_get_cache_ratio( (int) $nppp_widget_hits );
            if ( is_array( $nppp_ratio_data ) ) {
                $nppp_ratio_pct       = (float) $nppp_ratio_data['ratio'];
                $nppp_ratio_hits      = (int)   $nppp_ratio_data['hits'];
                $nppp_ratio_misses    = (int)   $nppp_ratio_data['misses'];
                $nppp_ratio_total     = (int)   $nppp_ratio_data['total'];
                $nppp_ratio_na        = false;
                $nppp_ratio_na_reason = '';
            } else {
                $nppp_ratio_na_reason = 'no_snapshot';
            }
        } else {
            $nppp_ratio_na_reason = 'no_snapshot';
        }

        echo '<div id="nppp-ratio-strip" class="nppp-ratio-strip"'
           . ' data-ratio="'     . esc_attr( $nppp_ratio_na ? '' : $nppp_ratio_pct ) . '"'
           . ' data-hits="'      . esc_attr( $nppp_ratio_na ? '' : $nppp_ratio_hits ) . '"'
           . ' data-misses="'    . esc_attr( $nppp_ratio_na ? '' : $nppp_ratio_misses ) . '"'
           . ' data-total="'     . esc_attr( $nppp_ratio_na ? '' : $nppp_ratio_total ) . '"'
           . ' data-na="'        . esc_attr( $nppp_ratio_na ? '1' : '0' ) . '"'
           . ' data-na-reason="' . esc_attr( $nppp_ratio_na_reason ) . '"'
           . ' data-scanned-at="'. esc_attr( $nppp_widget_scan_at ? (string) $nppp_widget_scan_at : '' ) . '"'
           . '>';
            // SVG circular gauge  (r = 28 → circumference = 2πr ≈ 175.93)
            echo '<div class="nppp-ratio-gauge" aria-label="' . esc_attr__( 'Cache coverage ratio gauge', 'fastcgi-cache-purge-and-preload-nginx' ) . '">';
                echo '<svg class="nppp-gauge-svg" viewBox="0 0 96 96" width="96" height="96">';
                    echo '<circle class="nppp-gauge-track"    cx="48" cy="48" r="37" fill="none" stroke-width="9"/>';
                    echo '<circle class="nppp-gauge-progress" cx="48" cy="48" r="37" fill="none" stroke-width="9"'
                       . ' stroke-dasharray="232.48" stroke-dashoffset="232.48"'
                       . ' stroke-linecap="round" transform="rotate(-90 48 48)"/>';
                echo '</svg>';
                echo '<span class="nppp-gauge-pct" aria-live="polite">&ndash;</span>';
                // Show refresh whenever a snapshot exists — clicking it will also
                // initialize the hits option if it hasn't been written yet.
                if ( $nppp_snapshot_exists ) {
                    echo '<button type="button" class="nppp-ratio-refresh"'
                       . ' title="'      . esc_attr__( 'Refresh cache coverage ratio', 'fastcgi-cache-purge-and-preload-nginx' ) . '"'
                       . ' aria-label="' . esc_attr__( 'Refresh',                 'fastcgi-cache-purge-and-preload-nginx' ) . '">'
                       . '<span class="dashicons dashicons-update"></span>'
                       . '</button>';
                }
            echo '</div>';
            // Right-side text block
            echo '<div class="nppp-ratio-info">';
                echo '<span class="nppp-ratio-title">' . esc_html__( 'CACHE COVERAGE', 'fastcgi-cache-purge-and-preload-nginx' ) . '</span>';
                echo '<span id="nppp-ratio-detail" class="nppp-ratio-detail"></span>';
            echo '</div>';
        echo '</div>';

        // Output the main NPP pluging settings statuses
        echo '<table style="width: 100%; border-collapse: collapse;">';
            foreach ($statuses as $key => $status_info) {
                $status = $status_info['status'];
                $icon = $status_info['icon'];

                // Determine the Dashicon and color based on status
                // Three possible states: Enabled (green), Disabled (red), Unavailable (gray + lock)
                $is_unavailable = ! empty($status_info['unavailable']);
                if ($is_unavailable) {
                    $status_icon  = 'dashicons-lock';
                    $status_color = '#999999';
                } elseif ($status === __('Enabled', 'fastcgi-cache-purge-and-preload-nginx')) {
                    $status_icon  = 'dashicons-yes-alt';
                    $status_color = '#5cb85c';
                } else {
                    $status_icon  = 'dashicons-dismiss';
                    $status_color = '#d9534f';
                }

                echo '<tr style="border-bottom: 1px solid #f1f1f1;">';
                    echo '<td style="padding: 8px 15px; color: #555; font-weight: 500; width: 60%;">';
                        echo '<span class="dashicons ' . esc_attr( $icon ) . '" style="font-size: 18px; margin-right: 8px;"></span>';
                        echo esc_html($status_info['label']);
                    echo '</td>';
                    echo '<td style="padding: 8px 15px; text-align: center; font-size: 16px;">';
                        echo '<span class="dashicons ' . esc_attr( $status_icon ) . '" style="color: ' . esc_attr( $status_color ) . '; font-size: 18px;"></span>';
                    echo '</td>';
                echo '</tr>';

                // Show Last Run: under "Auto Preload"
                if ($key === 'auto_preload') {
                    if ($last_preload_complete_date) {
                        echo '<tr style="border-bottom: 1px solid #f1f1f1;">';
                            echo '<td style="padding: 6px 15px; width: 75%;">';
                                // Format the Preload Last complete date
                                echo '<div class="nppp-preload-complete-date">';
                                    echo '<div class="nppp-preload-widget-info">';
                                        // Use Dashicon for the right arrow icon
                                        echo '<span class="dashicons dashicons-arrow-right-alt2" style="font-size: 18px; vertical-align: middle; margin-right: 8px;"></span>';

                                        // Display the preload complete date
                                        echo '<span class="nppp-preload-last-date">' . sprintf(
                                            /* Translators: %s is the formatted preload last complete time */
                                            esc_html__('Last Run: %s', 'fastcgi-cache-purge-and-preload-nginx'),
                                            '<strong style="color: #2196f3; font-size: 12px;">' . esc_html($last_preload_complete_date) . '</strong>'
                                            ) . '</span>';
                                    echo '</div>';
                                echo '</div>';
                            echo '</td>';

                            echo '<td style="padding: 6px 15px; text-align: center;">';
                                echo '<span class="dashicons dashicons-info" style="font-size: 18px; color: orange;"></span>';
                            echo '</td>';
                        echo '</tr>';
                    }
                }

                // Show Next Run: under "Scheduled Cache" if enabled
                if ($key === 'scheduled_cache' && $status === __('Enabled', 'fastcgi-cache-purge-and-preload-nginx')) {
                    echo '<tr style="border-bottom: 1px solid #f1f1f1;">';
                        // Call the function to display the next scheduled event
                        nppp_get_active_cron_events_widget();
                    echo '</tr>';
                }
            }
        echo '</table>';

        // Output the "Give Star" and "Configure Settings" bottom buttons
        echo '<div style="display: flex; justify-content: space-between; align-items: center;">';
            // Output the "Give Star" button
            echo '<a href="' . esc_url( 'https://wordpress.org/support/plugin/fastcgi-cache-purge-and-preload-nginx/reviews/#new-post' ) . '" target="_blank" class="nppp-give-star-button" style="font-size: 14px; color: indigo; background-color: #ffcc00; padding: 8px 12px; text-decoration: none; font-weight: 500; display: inline-flex; align-items: center; justify-content: center; transition: background-color 0.3s ease; flex: 48%;">';
                echo '<span class="dashicons dashicons-star-filled" style="font-size: 18px; margin-right: 0px; color: #fff;"></span>';
                echo '<span class="dashicons dashicons-star-filled" style="font-size: 18px; margin-right: 0px; color: #fff;"></span>';
                echo '<span class="dashicons dashicons-star-filled" style="font-size: 18px; margin-right: 3px; color: #fff;"></span>' . esc_html__( 'Give Star', 'fastcgi-cache-purge-and-preload-nginx' );
            echo '</a>';

            // Output the "Configure Settings" button
            echo '<a href="' . esc_url( 'options-general.php?page=nginx_cache_settings' ) . '" class="nppp-settings-button" style="text-decoration: none; background-color: #0073aa; color: white; padding: 8px; font-size: 14px; font-weight: 500; display: inline-flex; align-items: center; justify-content: center; transition: background-color 0.3s ease; flex: 48%; text-align: center;">';
                echo '<span class="dashicons dashicons-admin-generic" style="font-size: 18px; margin-right: 3px; color: #fff;"></span>' . esc_html__( 'Configure Settings', 'fastcgi-cache-purge-and-preload-nginx' );
            echo '</a>';
        echo '</div>';
    echo '</div>';
}

// Register the NPP dashboard widget
function nppp_add_dashboard_widget() {
    wp_add_dashboard_widget(
        'nppp_dashboard_widget',
        /* Translators: NPP is the short name of the plugin. */
        __('NPP - Nginx Cache Status', 'fastcgi-cache-purge-and-preload-nginx'),
        'nppp_dashboard_widget'
    );
}

// AJAX handler — refresh cache coverage ratio on demand from the dashboard widget.
function nppp_refresh_cache_ratio_callback() {
    // Capability check
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( __( 'Permission denied.', 'fastcgi-cache-purge-and-preload-nginx' ), 403 );
    }

    // Nonce check
    if ( empty( $_POST['_wpnonce'] ) ||
         ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ), 'nppp_refresh_cache_ratio' ) ) {
        wp_send_json_error( __( 'Nonce verification failed.', 'fastcgi-cache-purge-and-preload-nginx' ) );
    }

    // Allow long-running scans on large caches
    if ( function_exists( 'set_time_limit' ) ) {
        @set_time_limit( 0 ); // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged
    }

    // Fresh HITs scan
    $hits = nppp_get_in_cache_page_count();

    if ( is_numeric( $hits ) ) {
        update_option( 'nppp_last_known_hits',      (int) $hits, false );
        update_option( 'nppp_last_hits_scanned_at', time(),      false );
    } elseif ( in_array( $hits, [ 'Not Found', 'Undetermined', 'RegexError' ], true ) ) {
        $na_reason_map = [
            'Not Found'    => 'path_not_found',
            'Undetermined' => 'undetermined',
            'RegexError'   => 'regex_error',
        ];
        wp_send_json_success( [ 'na' => true, 'na_reason' => $na_reason_map[ $hits ] ] );
    }

    if ( ! function_exists( 'nppp_get_cache_ratio' ) ) {
        wp_send_json_success( [ 'na' => true, 'na_reason' => 'no_snapshot' ] );
    }

    $ratio_data = nppp_get_cache_ratio( $hits );

    if ( is_array( $ratio_data ) ) {
        wp_send_json_success( [
            'na'         => false,
            'ratio'      => (float) $ratio_data['ratio'],
            'hits'       => (int)   $ratio_data['hits'],
            'misses'     => (int)   $ratio_data['misses'],
            'total'      => (int)   $ratio_data['total'],
            'scanned_at' => time(),
        ] );
    }

    // nppp_get_cache_ratio returned N/A (no snapshot, empty snapshot, etc.)
    wp_send_json_success( [ 'na' => true, 'na_reason' => 'no_snapshot' ] );
}
