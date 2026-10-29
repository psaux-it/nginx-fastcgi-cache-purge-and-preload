<?php
/**
 * Dashboard widget module for Nginx Cache Purge Preload
 * Description: Renders WordPress dashboard status widgets and recent preload/purge summaries.
 * Version: 2.1.8
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
        // Dashboard widget render — passive, not a click. Log only.
        nppp_display_admin_notice(
            'error',
            __( 'Failed to initialize the WordPress filesystem. Please file a bug on the plugin support page.', 'fastcgi-cache-purge-and-preload-nginx' ),
            true,
            false
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
        // Dashboard widget render — passive, not a click. Log only.
        nppp_display_admin_notice(
            'error',
            __('Failed to initialize the WordPress filesystem. Please file a bug on the plugin support page.', 'fastcgi-cache-purge-and-preload-nginx'),
            true,
            false
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

// Next scheduled preload run in the site timezone, null when none is scheduled
function nppp_get_next_cron_run_widget() {
    $events = _get_cron_array();
    $plugin_hook = 'npp_cache_preload_event';
    $timezone_string = wp_timezone_string();

    if (empty($events) || !is_array($events) || empty($timezone_string)) {
        return null;
    }

    // Events are ordered by timestamp, the first match is the next run
    foreach ($events as $timestamp => $cron) {
        if (is_array($cron) && isset($cron[$plugin_hook])) {
            try {
                $next_run = new DateTime('@' . $timestamp);
                $next_run->setTimezone(new DateTimeZone($timezone_string));
                return $next_run->format('Y-m-d H:i:s');
            } catch (Exception $e) {
                return null;
            }
        }
    }

    return null;
}

// NPP dashboard widget content
function nppp_dashboard_widget() {
    // Fetch the NPP plugin settings from the database
    $settings = get_option('nginx_cache_settings', []);

    // URL Normalization
    $pctnorm_mode   = isset($settings['nginx_cache_pctnorm_mode']) ? $settings['nginx_cache_pctnorm_mode'] : 'off';
    $safexec_cached = get_transient('nppp_safexec_ok');
    if ($safexec_cached === false) {
        if (function_exists('nppp_find_safexec_path') && function_exists('nppp_is_safexec_usable')) {
            $_se_path           = nppp_find_safexec_path();
            $_se_ok             = $_se_path && nppp_is_safexec_usable($_se_path, false);
            set_transient('nppp_safexec_ok', ['path' => $_se_path, 'ok' => $_se_ok], HOUR_IN_SECONDS);
            $pctnorm_available  = $_se_ok;
        } else {
            $pctnorm_available  = false;
        }
    } else {
        $pctnorm_available = ! empty($safexec_cached['ok']);
    }

    if (!$pctnorm_available) {
        $pctnorm_status = __('Unavailable', 'fastcgi-cache-purge-and-preload-nginx');
    } elseif ($pctnorm_mode !== 'off') {
        $pctnorm_status = __('Enabled', 'fastcgi-cache-purge-and-preload-nginx');
    } else {
        $pctnorm_status = __('Disabled', 'fastcgi-cache-purge-and-preload-nginx');
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

    // RG Purge
    $rg_cached = get_transient( 'nppp_rg_ok' );
    if ( $rg_cached === false ) {
        $rg_bin = function_exists( 'shell_exec' )
            ? trim( (string) shell_exec( 'command -v rg 2>/dev/null' ) )
            : '';
        $rg_available = $rg_bin !== '';
        // Enforce minimum version (≥14.0.0).
        if ( $rg_available && function_exists( 'nppp_check_rg_version' ) ) {
            $rg_ver = nppp_check_rg_version();
            if ( $rg_ver === 'Not Installed' || version_compare( $rg_ver, '14.0.0', '<' ) ) {
                $rg_available = false;
            }
        }
        // Seed the transient so subsequent requests are instant.
        set_transient( 'nppp_rg_ok', [ 'path' => $rg_bin, 'ok' => $rg_available ], HOUR_IN_SECONDS );
    } else {
        $rg_available = is_array( $rg_cached ) && (bool) $rg_cached['ok'];
    }
    $rg_on = isset( $settings['nppp_rg_purge_enabled'] ) && $settings['nppp_rg_purge_enabled'] === 'yes';

    if ( ! $rg_available ) {
        $rg_status = __( 'Unavailable', 'fastcgi-cache-purge-and-preload-nginx' );
    } elseif ( $rg_on ) {
        $rg_status = __( 'Enabled', 'fastcgi-cache-purge-and-preload-nginx' );
    } else {
        $rg_status = __( 'Disabled', 'fastcgi-cache-purge-and-preload-nginx' );
    }

    // Need setup
    $nppp_setup_loaded = class_exists('\NPPP\Setup');
    $needs_setup = $nppp_setup_loaded && \NPPP\Setup::nppp_needs_setup();
    $setup_url   = admin_url('admin.php?page=' . ( $nppp_setup_loaded ? \NPPP\Setup::PAGE_SLUG : 'nppp-setup' ));
    $settings_url = admin_url('options-general.php?page=nginx_cache_settings');

    // Fail2Ban Monitor — three states just like the Fail2Ban tab's Connection
    // pill: no toggle to be "Disabled", so it's either receiving events
    // (green) or still waiting on the first one (amber "waiting" state).
    $f2b_configured = function_exists( 'nppp_f2b_has_any_events' ) && nppp_f2b_has_any_events();

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
        'http_purge' => [
            'label' => __('HTTP Purge', 'fastcgi-cache-purge-and-preload-nginx'),
            'status' => isset($settings['nppp_http_purge_enabled']) && $settings['nppp_http_purge_enabled'] === 'yes' ? __('Enabled', 'fastcgi-cache-purge-and-preload-nginx') : __('Disabled', 'fastcgi-cache-purge-and-preload-nginx'),
            'icon' => 'dashicons-rest-api'
        ],
        'rg_purge' => [
            'label'       => __('RG Purge', 'fastcgi-cache-purge-and-preload-nginx'),
            'status'      => $rg_status,
            'icon'        => 'dashicons-superhero',
            'unavailable' => ! $rg_available,
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
        'preload_feeds' => [
            'label' => __('Preload Feeds', 'fastcgi-cache-purge-and-preload-nginx'),
            'status' => isset($settings['nginx_cache_preload_feeds']) && $settings['nginx_cache_preload_feeds'] === 'yes' ? __('Enabled', 'fastcgi-cache-purge-and-preload-nginx') : __('Disabled', 'fastcgi-cache-purge-and-preload-nginx'),
            'icon' => 'dashicons-rss'
        ],
        'preload_watchdog' => [
            'label' => __('Preload Watchdog', 'fastcgi-cache-purge-and-preload-nginx'),
            'status' => isset($settings['nginx_cache_watchdog']) && $settings['nginx_cache_watchdog'] === 'yes' ? __('Enabled', 'fastcgi-cache-purge-and-preload-nginx') : __('Disabled', 'fastcgi-cache-purge-and-preload-nginx'),
            'icon' => 'dashicons-visibility'
        ],
        'enable_proxy' => [
            'label' => __('Proxy', 'fastcgi-cache-purge-and-preload-nginx'),
            'status' => isset($settings['nginx_cache_preload_enable_proxy']) && $settings['nginx_cache_preload_enable_proxy'] === 'yes' ? __('Enabled', 'fastcgi-cache-purge-and-preload-nginx') : __('Disabled', 'fastcgi-cache-purge-and-preload-nginx'),
            'icon'   => 'dashicons-randomize'
        ],
        'url_normalization' => [
            'label'       => __('URL Normalization', 'fastcgi-cache-purge-and-preload-nginx'),
            'status'      => $pctnorm_status,
            'icon'        => 'dashicons-admin-links',
            'unavailable' => ! $pctnorm_available,
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
        'bypass_path_restriction' => [
            'label' => __('Bypass Path Restriction', 'fastcgi-cache-purge-and-preload-nginx'),
            'status' => isset($settings['nginx_cache_bypass_path_restriction']) && $settings['nginx_cache_bypass_path_restriction'] === 'yes' ? __('Enabled', 'fastcgi-cache-purge-and-preload-nginx') : __('Disabled', 'fastcgi-cache-purge-and-preload-nginx'),
            'icon' => 'dashicons-shield-alt'
        ],
        'fail2ban' => [
            'label'   => __('Fail2Ban Monitor', 'fastcgi-cache-purge-and-preload-nginx'),
            'status'  => $f2b_configured ? __('Enabled', 'fastcgi-cache-purge-and-preload-nginx') : __('Waiting', 'fastcgi-cache-purge-and-preload-nginx'),
            'icon'    => 'dashicons-shield',
            'waiting' => ! $f2b_configured,
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

        // Traffic hit ratio reported by Nginx (ngx_cache_purge v4 status endpoint); empty when unavailable.
        // Painted from the stored samples so the page never waits for the status endpoint;
        // nppp-dashboard-widget.js loads the live figures right after (nppp_traffic_refresh).
        echo '<div id="nppp-traffic-row">'
           . ( function_exists( 'nppp_http_purge_traffic_stats_stored' ) ? nppp_render_traffic_row( nppp_http_purge_traffic_stats_stored() ) : '' )
           . '</div>';

        // Build one tile per option. States: on (green), off (red),
        // na (gray, lock) and wait (amber, configured but no activity seen yet).
        $nppp_count_on    = 0;
        $nppp_count_off   = 0;
        $nppp_count_other = 0;
        $nppp_tiles_html  = '';
        $nppp_strip_html  = '';

        foreach ($statuses as $key => $status_info) {
            $status = $status_info['status'];

            if (!empty($status_info['unavailable'])) {
                $state       = 'na';
                $status_icon = 'dashicons-lock';
            } elseif ($status === __('Enabled', 'fastcgi-cache-purge-and-preload-nginx')) {
                $state       = 'on';
                $status_icon = 'dashicons-yes-alt';
            } elseif (!empty($status_info['waiting'])) {
                $state       = 'wait';
                $status_icon = 'dashicons-clock';
            } else {
                $state       = 'off';
                $status_icon = 'dashicons-dismiss';
            }

            if ($state === 'on') {
                $nppp_count_on++;
            } elseif ($state === 'off') {
                $nppp_count_off++;
            } else {
                $nppp_count_other++;
            }

            // Secondary line (already escaped) and tooltip of the tile
            $note = '';
            $tip  = '';

            // HTTP Purge: mode Nginx was last seen answering in
            if ($key === 'http_purge' && $state === 'on') {
                $nppp_http_mode = function_exists('nppp_http_purge_observed_mode') ? nppp_http_purge_observed_mode() : null;
                if ($nppp_http_mode !== null) {
                    $nppp_http_mode_label = nppp_http_purge_mode_label($nppp_http_mode['mode']);
                    $tip = sprintf(
                        /* translators: 1: "Purge All" or "single URL purge", 2: human readable time difference, e.g. "3 mins" */
                        __('Observed on %1$s, %2$s ago', 'fastcgi-cache-purge-and-preload-nginx'),
                        $nppp_http_mode['scope'] === 'all'
                            ? __('Purge All', 'fastcgi-cache-purge-and-preload-nginx')
                            : __('single URL purge', 'fastcgi-cache-purge-and-preload-nginx'),
                        human_time_diff($nppp_http_mode['at'], time())
                    );
                } else {
                    $nppp_http_mode_label = __('Not detected yet', 'fastcgi-cache-purge-and-preload-nginx');
                    $tip = __('The mode is detected from the next HTTP purge answer of Nginx.', 'fastcgi-cache-purge-and-preload-nginx');
                }

                $note = '<span class="nppp-setting-note">' . sprintf(
                    /* translators: %s: HTTP Purge mode (Sync, Async Background Queue, Invalidation) */
                    esc_html__('Mode: %s', 'fastcgi-cache-purge-and-preload-nginx'),
                    '<strong class="nppp-setting-val">' . esc_html($nppp_http_mode_label) . '</strong>'
                ) . '</span>';
            }

            // Auto Preload: completion time of the last preload
            if ($key === 'auto_preload' && $last_preload_complete_date) {
                $note = '<span class="nppp-setting-note">' . sprintf(
                    /* translators: %s: formatted preload last complete time */
                    esc_html__('Last Run: %s', 'fastcgi-cache-purge-and-preload-nginx'),
                    '<strong class="nppp-setting-val">' . esc_html($last_preload_complete_date) . '</strong>'
                ) . '</span>';
            }

            // Scheduled Cache: next scheduled preload
            if ($key === 'scheduled_cache' && $state === 'on') {
                $next_run = nppp_get_next_cron_run_widget();
                $note = '<span class="nppp-setting-note">' . sprintf(
                    /* translators: %s: formatted next run time */
                    esc_html__('Next Run: %s', 'fastcgi-cache-purge-and-preload-nginx'),
                    '<strong class="nppp-setting-val">' . ($next_run !== null
                        ? esc_html($next_run)
                        : esc_html__('No event found', 'fastcgi-cache-purge-and-preload-nginx')) . '</strong>'
                ) . '</span>';
            }

            $nppp_tiles_html .= '<div class="nppp-setting nppp-st-' . esc_attr($state) . '"'
                . ($tip !== '' ? ' title="' . esc_attr($tip) . '"' : '') . '>'
                . '<span class="dashicons ' . esc_attr($status_info['icon']) . ' nppp-setting-icon" aria-hidden="true"></span>'
                . '<span class="nppp-setting-text">'
                    . '<span class="nppp-setting-label">' . esc_html($status_info['label']) . '</span>'
                    . $note
                . '</span>'
                . '<span class="dashicons ' . esc_attr($status_icon) . ' nppp-setting-state" aria-hidden="true" title="' . esc_attr($status) . '"></span>'
                . '<span class="screen-reader-text">' . esc_html($status) . '</span>'
                . '</div>';

            $nppp_strip_html .= '<i class="nppp-seg nppp-st-' . esc_attr($state) . '" title="'
                . esc_attr($status_info['label'] . ': ' . $status) . '"></i>';
        }

        // Summary line of the collapsed header
        $nppp_summary = [
            sprintf(
                /* translators: %d: number of enabled options */
                esc_html__('%d on', 'fastcgi-cache-purge-and-preload-nginx'),
                $nppp_count_on
            ),
            sprintf(
                /* translators: %d: number of disabled options */
                esc_html__('%d off', 'fastcgi-cache-purge-and-preload-nginx'),
                $nppp_count_off
            ),
        ];
        if ($nppp_count_other > 0) {
            $nppp_summary[] = '<span title="' . esc_attr__('Unavailable or waiting', 'fastcgi-cache-purge-and-preload-nginx') . '">'
                . sprintf(
                    /* translators: %d: number of options that are unavailable or waiting */
                    esc_html__('%d other', 'fastcgi-cache-purge-and-preload-nginx'),
                    $nppp_count_other
                ) . '</span>';
        }

        // Output the settings panel, collapsed by default
        echo '<details id="nppp-settings" class="nppp-settings">';
            echo '<summary class="nppp-settings-toggle">';
                echo '<span class="nppp-settings-head">';
                    echo '<span class="dashicons dashicons-admin-settings nppp-settings-icon" aria-hidden="true"></span>';
                    echo '<span class="nppp-settings-title">' . esc_html__('Settings', 'fastcgi-cache-purge-and-preload-nginx') . '</span>';
                    echo '<span class="nppp-settings-sum">' . implode(' &middot; ', $nppp_summary) . '</span>';
                    echo '<span class="dashicons dashicons-arrow-down-alt2 nppp-settings-chev" aria-hidden="true"></span>';
                echo '</span>';
                echo '<span class="nppp-settings-strip" aria-hidden="true">' . $nppp_strip_html . '</span>';
            echo '</summary>';
            echo '<div class="nppp-settings-grid">' . $nppp_tiles_html . '</div>';
        echo '</details>';

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
        /* translators: NPP is the plugin short name — do not translate. */
        __('NPP - Nginx Cache Status', 'fastcgi-cache-purge-and-preload-nginx'),
        'nppp_dashboard_widget'
    );
}

/**
 * Level name of a traffic hit ratio (0..1). Thresholds mirror ratioColor() in
 * nppp-dashboard-widget.js, so the Cache Coverage and Traffic cards use the same colours.
 *
 * @param float $ratio Hit ratio between 0 and 1.
 * @return string 'good' | 'fair' | 'low'
 */
function nppp_traffic_level( float $ratio ): string {
    $pct = $ratio * 100;
    if ( $pct >= 70 ) {
        return 'good';
    }
    return $pct >= 40 ? 'fair' : 'low';
}

/**
 * Plain-text tooltip of one chart step or of the "now" marker (escape at the call site).
 *
 * @param float $pct      Interval hit ratio in percent.
 * @param int   $requests HIT+MISS behind it, 0 when unknown.
 * @param bool  $held     True when the level is only carried forward up to "now".
 * @param int   $until    End of the measured interval (Unix time), 0 when unknown.
 */
function nppp_traffic_point_label( float $pct, int $requests, bool $held, int $until ): string {
    $label = number_format_i18n( $pct, 1 ) . '%';
    if ( $requests > 0 ) {
        /* translators: %s: number of HIT+MISS requests behind a hit ratio. */
        $label .= ' · ' . sprintf( _n( '%s request', '%s requests', $requests, 'fastcgi-cache-purge-and-preload-nginx' ), number_format_i18n( $requests ) );
    }
    if ( $held && $until > 0 ) {
        /* translators: %s: time of day of the last measurement. */
        $label .= ' · ' . sprintf( __( 'last measured %s, held until now', 'fastcgi-cache-purge-and-preload-nginx' ), wp_date( get_option( 'time_format' ), $until ) );
    }
    return $label;
}

/**
 * Trend chart of the traffic hit ratio as inline SVG (numbers only, nothing user supplied).
 *
 * Time axis is fitted to the data (oldest sampled interval .. now), one step per sampled
 * interval: intervals are uneven (see nppp_http_purge_traffic_series()), so a smooth line would
 * imply precision that is not there. The value axis is fitted to the data too (a flat 0-100
 * scale squashes e.g. a 23-44% range into a strip): the chart shows the shape, the figures
 * next to it carry the level. The 70% reference is drawn only when it falls inside the axis.
 * Colours come from CSS classes (nppp-tl-good|fair|low), never from inline styles.
 *
 * The headline figure is volume weighted (sum of HITs / sum of requests) while every step on the
 * chart is one interval of equal weight, so the two can legitimately differ. $avg (the headline
 * ratio, 0..1) is therefore drawn as a labelled line, which ties the chart to the number next to
 * it. Each step and the "now" marker carry a tooltip with their request count; a hollow marker
 * is the last measured level held up to "now", not a fresh measurement.
 *
 * @param array      $series 'series' of nppp_http_purge_traffic_summarize().
 * @param float|null $avg    Headline ratio (0..1) of the same window, or null to omit the average line.
 * @return array{html:string,lo:float,hi:float,span:int}|null Null with fewer than 2 decided intervals.
 */
function nppp_traffic_chart( array $series, ?float $avg = null ): ?array {
    $w   = 220;
    $h   = 64;
    $pad = 5;

    $start = 0;
    $end   = 0;
    foreach ( $series as $p ) {
        if ( is_array( $p ) && isset( $p['from'], $p['to'] ) ) {
            $start = ( $start === 0 ) ? (int) $p['from'] : min( $start, (int) $p['from'] );
            $end   = max( $end, (int) $p['to'] );
        }
    }
    if ( $start <= 0 || $end <= $start ) {
        return null;
    }
    $span = $end - $start;

    $f = static function ( float $v ): string {
        return number_format( $v, 1, '.', '' );
    };
    $x = static function ( int $ts ) use ( $start, $span, $w ): float {
        return max( 0.0, min( (float) $w, ( $ts - $start ) / $span * $w ) );
    };

    $iv = [];
    foreach ( $series as $p ) {
        if ( ! is_array( $p ) || ! isset( $p['from'], $p['to'], $p['ratio'] ) ) {
            continue; // no ratio (gap) or malformed: nothing to draw.
        }
        $x1 = $x( (int) $p['from'] );
        $x2 = $x( (int) $p['to'] );
        if ( $x2 - $x1 < 0.5 ) {
            continue;
        }
        $iv[] = [
            $x1,
            $x2,
            max( 0.0, min( 100.0, (float) $p['ratio'] * 100 ) ),
            (int) round( (float) ( $p['hits'] ?? 0 ) + (float) ( $p['misses'] ?? 0 ) ),
            (int) $p['to'],
        ];
    }
    if ( count( $iv ) < 2 ) {
        return null;
    }

    $pcts = array_column( $iv, 2 );
    $lo   = (float) min( $pcts );
    $hi   = (float) max( $pcts );
    $avgp = $avg !== null ? max( 0.0, min( 100.0, $avg * 100 ) ) : null;

    // Value axis: data range (and the average line) plus some air, at least 20 points tall, kept inside 0..100.
    $dmin = max( 0.0, min( $lo, $avgp ?? $lo ) - 8 );
    $dmax = min( 100.0, max( $hi, $avgp ?? $hi ) + 8 );
    if ( $dmax - $dmin < 20 ) {
        $dmin = min( max( 0.0, ( $dmin + $dmax ) / 2 - 10 ), 80.0 );
        $dmax = $dmin + 20;
    }
    $y = static function ( float $pct ) use ( $h, $pad, $dmin, $dmax ): float {
        return $pad + ( $h - 2 * $pad ) * ( 1 - ( $pct - $dmin ) / ( $dmax - $dmin ) );
    };

    $shapes = '';
    $prev   = null;
    $dot    = '';

    foreach ( $iv as $s ) {
        $lvl = nppp_traffic_level( $s[2] / 100 );
        $yy  = $y( $s[2] );

        if ( $prev !== null ) {
            // Stretch without a ratio (Preload All running, or too little traffic): hold the
            // previous level as a faint dotted line so the trend does not fall apart.
            if ( $s[0] - $prev[0] >= 1.0 ) {
                $shapes .= '<line class="nppp-traffic-gap nppp-tl-' . $prev[2] . '" x1="' . $f( $prev[0] ) . '" x2="' . $f( $s[0] )
                         . '" y1="' . $f( $prev[1] ) . '" y2="' . $f( $prev[1] ) . '"/>';
            }
            // Thin joint to the next level (across a gap too).
            $shapes .= '<line class="nppp-traffic-join" x1="' . $f( $s[0] ) . '" x2="' . $f( $s[0] )
                     . '" y1="' . $f( $prev[1] ) . '" y2="' . $f( $yy ) . '"/>';
        }
        $shapes .= '<line class="nppp-tl-' . $lvl . '" x1="' . $f( $s[0] ) . '" x2="' . $f( $s[1] )
                 . '" y1="' . $f( $yy ) . '" y2="' . $f( $yy ) . '"><title>'
                 . esc_html( nppp_traffic_point_label( $s[2], $s[3], false, $s[4] ) ) . '</title></line>';

        $prev = [ $s[1], $yy, $lvl, $s[2], $s[3], $s[4] ];
    }

    // Marker on the right edge ("now"), coloured like the newest known interval.
    if ( $prev !== null ) {
        $held = ( $w - $prev[0] >= 1.0 );
        if ( $held ) {
            $shapes .= '<line class="nppp-traffic-gap nppp-tl-' . $prev[2] . '" x1="' . $f( $prev[0] ) . '" x2="' . $w
                     . '" y1="' . $f( $prev[1] ) . '" y2="' . $f( $prev[1] ) . '"/>';
        }
        $dot = '<i class="nppp-traffic-dot' . ( $held ? ' nppp-traffic-held' : '' ) . ' nppp-tl-' . $prev[2]
             . '" title="' . esc_attr( nppp_traffic_point_label( $prev[3], $prev[4], $held, $prev[5] ) )
             . '" style="top:' . $f( $prev[1] / $h * 100 ) . '%"></i>';
    }

    $has_ref   = ( $dmin <= 70 && $dmax >= 70 );
    $ref_line  = '';
    $ref_label = '';
    $ay        = $avgp !== null ? $y( $avgp ) : null;
    $ry        = $has_ref ? $y( 70.0 ) : null;
    $avg_lvl   = $avgp !== null ? nppp_traffic_level( $avgp / 100 ) : '';

    if ( $ry !== null ) {
        $ref_line = '<line class="nppp-traffic-ref" x1="0" x2="' . $w . '" y1="' . $f( $ry ) . '" y2="' . $f( $ry ) . '"/>';
    }
    if ( $ay !== null ) {
        // Drawn after the reference line so it stays on top when both are close.
        $ref_line .= '<line class="nppp-traffic-avg nppp-tl-' . $avg_lvl . '" x1="0" x2="' . $w
                   . '" y1="' . $f( $ay ) . '" y2="' . $f( $ay ) . '"/>';
    }

    // Gutter labels: keep both, always inside the chart. When the lines are closer than one
    // label height, push the labels apart instead of dropping one.
    $labels = [];
    if ( $ry !== null ) {
        $labels[] = [
            'y'     => $ry,
            'class' => 'nppp-traffic-reflabel',
            'title' => '',
            'text'  => '70%',
        ];
    }
    if ( $ay !== null ) {
        $labels[] = [
            'y'     => $ay,
            'class' => 'nppp-traffic-avglabel nppp-tl-' . $avg_lvl,
            'title' => __( 'Average of the whole period, the same figure as the large percentage', 'fastcgi-cache-purge-and-preload-nginx' ),
            'text'  => number_format_i18n( $avgp ) . '%',
        ];
    }
    $label_h      = 12.0;
    $top_limit    = $label_h / 2;
    $bottom_limit = $h - $label_h / 2;
    foreach ( $labels as $i => $l ) {
        $labels[ $i ]['y'] = max( $top_limit, min( $bottom_limit, $l['y'] ) );
    }
    if ( count( $labels ) === 2 ) {
        usort( $labels, static function ( array $a, array $b ): int {
            return $a['y'] <=> $b['y'];
        } );
        $dist = $labels[1]['y'] - $labels[0]['y'];
        if ( $dist < $label_h ) {
            $shift           = ( $label_h - $dist ) / 2;
            $labels[0]['y'] -= $shift;
            $labels[1]['y'] += $shift;
            if ( $labels[0]['y'] < $top_limit ) {
                $labels[1]['y'] += $top_limit - $labels[0]['y'];
                $labels[0]['y']  = $top_limit;
            }
            if ( $labels[1]['y'] > $bottom_limit ) {
                $labels[0]['y'] -= $labels[1]['y'] - $bottom_limit;
                $labels[1]['y']  = $bottom_limit;
            }
        }
    }
    foreach ( $labels as $l ) {
        $ref_label .= '<span class="' . esc_attr( $l['class'] ) . '"'
                    . ( $l['title'] !== '' ? ' title="' . esc_attr( $l['title'] ) . '"' : '' )
                    . ' style="top:' . $f( $l['y'] / $h * 100 ) . '%">' . esc_html( $l['text'] ) . '</span>';
    }
    $gutter = $ry !== null || $ay !== null;

    return [
        'html' => '<div class="nppp-traffic-chart' . ( $gutter ? ' nppp-traffic-hasref' : '' ) . '" aria-hidden="true">'
                . '<svg viewBox="0 0 ' . $w . ' ' . $h . '" preserveAspectRatio="none">'
                . $ref_line . $shapes . '</svg>' . $dot . $ref_label . '</div>',
        'lo'   => $lo,
        'hi'   => $hi,
        'span' => $span,
    ];
}

/**
 * Escaped "N ago" label for the left end of the trend chart.
 *
 * @param int $seconds Length of the charted period.
 */
function nppp_traffic_ago( int $seconds ): string {
    if ( $seconds < HOUR_IN_SECONDS ) {
        /* translators: %d: number of minutes. */
        return sprintf( esc_html__( '%dm ago', 'fastcgi-cache-purge-and-preload-nginx' ), max( 1, (int) round( $seconds / MINUTE_IN_SECONDS ) ) );
    }
    /* translators: %d: number of hours. */
    return sprintf( esc_html__( '%dh ago', 'fastcgi-cache-purge-and-preload-nginx' ), (int) round( $seconds / HOUR_IN_SECONDS ) );
}

/**
 * Escaped HTML of the "Traffic hit ratio" card; '' when the status endpoint gave nothing.
 *
 * @param array|null $t Result of nppp_http_purge_traffic_stats().
 */
function nppp_render_traffic_row( ?array $t ): string {
    if ( $t === null ) {
        return '';
    }

    $tip    = esc_attr__( 'Counted by Nginx for every request with a native cache HIT or MISS (periods when a Preload All was running are left out). BYPASS, EXPIRED, STALE, UPDATING and REVALIDATED are not counted. Counters reset when Nginx is restarted.', 'fastcgi-cache-purge-and-preload-nginx' );
    $ratio  = null;
    $hits   = 0.0;
    $misses = 0.0;
    $scope  = '';
    $chart  = null;

    if ( $t['window'] !== null ) {
        $w      = $t['window'];
        $ratio  = $w['ratio'];
        $hits   = $w['hits'];
        $misses = $w['misses'];
        $hours  = max( 1, (int) round( $w['seconds'] / HOUR_IN_SECONDS ) );
       /* translators: %d: number of hours covered. */
        $scope  = sprintf( esc_html__( 'over last %dh', 'fastcgi-cache-purge-and-preload-nginx' ), $hours );
        if ( ! empty( $w['excluded'] ) ) {
            $scope .= ' &middot; ' . esc_html__( 'preload excluded', 'fastcgi-cache-purge-and-preload-nginx' );
        }
        $chart  = nppp_traffic_chart( ( isset( $t['series'] ) && is_array( $t['series'] ) ) ? $t['series'] : [], (float) $w['ratio'] );
    } elseif ( $t['ratio'] !== null && empty( $t['paused'] ) ) {
        $ratio  = $t['ratio'];
        $hits   = $t['hit'];
        $misses = $t['miss'];
        $scope  = esc_html__( 'since Nginx restart', 'fastcgi-cache-purge-and-preload-nginx' );
    }

    $head = '<div class="nppp-traffic-head"><span class="dashicons dashicons-chart-line"></span>'
          . '<span class="nppp-traffic-title">' . esc_html__( 'Traffic hit ratio', 'fastcgi-cache-purge-and-preload-nginx' ) . '</span>'
          . '<span class="nppp-traffic-rule"></span>'
          . ( $scope !== '' ? '<span class="nppp-traffic-scope">' . $scope . '</span>' : '' )
          . '<span class="nppp-traffic-info" tabindex="0" role="img" aria-label="' . $tip . '" data-tip="' . $tip . '">i</span>'
          . '</div>';

    if ( $ratio === null ) {
        $idle = empty( $t['paused'] )
            ? esc_html__( 'No cache traffic observed yet', 'fastcgi-cache-purge-and-preload-nginx' )
            : esc_html__( 'Preload traffic is left out; waiting for regular traffic', 'fastcgi-cache-purge-and-preload-nginx' );
        return '<div class="nppp-traffic-box nppp-traffic-idle">' . $head
             . '<span class="nppp-traffic-sub">' . $idle . '</span></div>';
    }

    $left = '<div class="nppp-traffic-left">'
          . '<strong class="nppp-traffic-pct">' . esc_html( number_format_i18n( $ratio * 100, 1 ) ) . '<small>%</small></strong>'
          . '<span class="nppp-traffic-counts">'
          . '<span class="nppp-traffic-hit">&#x2714; ' . sprintf(
              /* translators: %s: number of HIT requests. */
              esc_html__( '%s HIT', 'fastcgi-cache-purge-and-preload-nginx' ),
              esc_html( number_format_i18n( $hits ) )
          ) . '</span>'
          . '<span class="nppp-traffic-miss">&#x2718; ' . sprintf(
              /* translators: %s: number of MISS requests. */
              esc_html__( '%s MISS', 'fastcgi-cache-purge-and-preload-nginx' ),
              esc_html( number_format_i18n( $misses ) )
          ) . '</span>'
          . '</span></div>';

    if ( $chart !== null ) {
        $right = '<div class="nppp-traffic-right">' . $chart['html'] . '<div class="nppp-traffic-foot">'
               . '<span>' . nppp_traffic_ago( (int) $chart['span'] ) . '</span>'
               . '<span class="nppp-traffic-mid">' . sprintf(
                   /* translators: 1: lowest interval hit ratio, 2: highest (with % sign). */
                   esc_html__( 'range %1$s–%2$s', 'fastcgi-cache-purge-and-preload-nginx' ),
                   esc_html( number_format_i18n( $chart['lo'] ) ) . '%',
                   esc_html( number_format_i18n( $chart['hi'] ) ) . '%'
               ) . '</span>'
               . '<span>' . esc_html__( 'now', 'fastcgi-cache-purge-and-preload-nginx' ) . '</span>'
               . '</div></div>';
    } else {
        $right = '<div class="nppp-traffic-right"><div class="nppp-traffic-chart nppp-traffic-collect"><span>'
               . esc_html__( 'Collecting data for a 24h window…', 'fastcgi-cache-purge-and-preload-nginx' )
               . '</span></div></div>';
    }

    return '<div class="nppp-traffic-box nppp-traffic-' . nppp_traffic_level( (float) $ratio ) . '">'
         . $head . '<div class="nppp-traffic-split">' . $left . $right . '</div></div>';
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

    // Explicit refresh: bypass the 20 s / 60 s caches of the Nginx counters.
    $traffic_html = function_exists( 'nppp_http_purge_traffic_stats' )
        ? nppp_render_traffic_row( nppp_http_purge_traffic_stats( true ) ) : '';

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
        wp_send_json_success( [ 'na' => true, 'na_reason' => $na_reason_map[ $hits ], 'traffic_html' => $traffic_html ] );
    }

    if ( ! function_exists( 'nppp_get_cache_ratio' ) ) {
        wp_send_json_success( [ 'na' => true, 'na_reason' => 'no_snapshot', 'traffic_html' => $traffic_html ] );
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
            'traffic_html' => $traffic_html,
        ] );
    }

    // nppp_get_cache_ratio returned N/A (no snapshot, empty snapshot, etc.)
    wp_send_json_success( [ 'na' => true, 'na_reason' => 'no_snapshot', 'traffic_html' => $traffic_html ] );
}

// AJAX handler — live traffic hit ratio card. Cheap by design (one status request, cached 20 s),
// unlike nppp_refresh_cache_ratio which also rescans the cache directory.
function nppp_traffic_refresh_callback() {
    // Capability check
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( __( 'Permission denied.', 'fastcgi-cache-purge-and-preload-nginx' ), 403 );
    }

    // Nonce check
    if ( empty( $_POST['_wpnonce'] ) ||
         ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ), 'nppp_traffic_refresh' ) ) {
        wp_send_json_error( __( 'Nonce verification failed.', 'fastcgi-cache-purge-and-preload-nginx' ), 403 );
    }

    wp_send_json_success( [
        'traffic_html' => function_exists( 'nppp_http_purge_traffic_stats' )
            ? nppp_render_traffic_row( nppp_http_purge_traffic_stats() ) : '',
    ] );
}
