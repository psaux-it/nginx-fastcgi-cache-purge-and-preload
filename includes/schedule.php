<?php
/**
 * Cron scheduling utilities for Nginx Cache Purge Preload
 * Description: Manages preload-related cron events and reports active plugin schedules.
 * Version: 2.1.5
 * Author: Hasan CALISIR
 * Author Email: hasan.calisir@psauxit.com
 * Author URI: https://www.psauxit.com
 * License: GPL-2.0+
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Retrieves and formats the active scheduled events associated with the plugin.
function nppp_get_active_cron_events() {
    // Get all scheduled events
    $events = _get_cron_array();

    // npp plugin's schedule event hook name
    $plugin_hook = 'npp_cache_preload_event';

    // Initialize a flag to track if events are found
    $has_events = false;

    // Get the WordPress timezone string
    $timezone_string = wp_timezone_string();

    // Check if timezone string is set
    if (empty($timezone_string)) {
        // Format the scheduled event information
        echo '<div class="nppp-scheduled-event">';
        echo '<h3 class="nppp-active-cron-heading">' . esc_html__('Cron Status', 'fastcgi-cache-purge-and-preload-nginx') . '</h3>';
        echo '<div class="nppp-scheduled-event" style="padding-right: 45px;">' . esc_html__('Please set your Timezone in WordPress - Options/General!', 'fastcgi-cache-purge-and-preload-nginx') . '</div>';
        echo '</div>';
        return;
    }

    // Check events are empty
    if (empty($events)) {
        // Format the scheduled event information
        echo '<div class="nppp-scheduled-event">';
        echo '<h3 class="nppp-active-cron-heading">' . esc_html__('Cron Status', 'fastcgi-cache-purge-and-preload-nginx') . '</h3>';
        echo '<div class="nppp-scheduled-event" style="padding-right: 45px;">' . esc_html__('No active scheduled events found!', 'fastcgi-cache-purge-and-preload-nginx') . '</div>';
        echo '</div>';
        return;
    }

    // Check if there are any scheduled events for npp
    if (!empty($events)) {
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
                    echo '<h3 class="nppp-active-cron-heading">' . esc_html__('Cron Status', 'fastcgi-cache-purge-and-preload-nginx') . '</h3>';
                    echo '<div class="nppp-cron-info">';
                    echo '<span class="nppp-hook-name">' . sprintf(
                        /* Translators: %s is the cron hook name */
                        esc_html__('Cron Name: %s', 'fastcgi-cache-purge-and-preload-nginx'),
                        '<strong>' . esc_html($hook) . '</strong>'
                    ) . '</span> - ';
                    echo '<span class="nppp-next-run">' . sprintf(
                        /* Translators: %s is the formatted next run time */
                        esc_html__('Next Run: %s', 'fastcgi-cache-purge-and-preload-nginx'),
                        '<strong>' . esc_html($next_run_formatted) . '</strong>'
                    ) . '</span>';
                    echo '</div>';
                    echo '<div class="nppp-cancel-btn-container">';
                    echo '<button class="nppp-cancel-btn" data-hook="' . esc_attr($hook) . '">' . esc_html__('Cancel', 'fastcgi-cache-purge-and-preload-nginx') . '</button>';
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

    // If no matching cron event is found
    if (!$has_events) {
        echo '<div class="nppp-scheduled-event">';
        echo '<h3 class="nppp-active-cron-heading">' . esc_html__('Cron Status', 'fastcgi-cache-purge-and-preload-nginx') . '</h3>';
        echo '<div class="nppp-scheduled-event" style="padding-right: 45px;">' . esc_html__('No active scheduled events found!', 'fastcgi-cache-purge-and-preload-nginx') . '</div>';
        echo '</div>';
    }
}

// Ajax update UI for setted new cron
function nppp_get_active_cron_events_ajax() {
    // Verify nonce to ensure the request is coming from a trusted source
    check_ajax_referer('nppp-get-save-cron-expression', '_wpnonce');

    // Check user capability
    if (!current_user_can('manage_options')) {
        wp_send_json_error(__('You do not have permission to call this action.', 'fastcgi-cache-purge-and-preload-nginx'), 403);
    }

    // Get all scheduled events
    $events = _get_cron_array();

    // npp plugin's schedule event hook name
    $plugin_hook = 'npp_cache_preload_event';

    // Initialize an empty array to store event data
    $event_data = array();

    // Check if there are any scheduled events for npp
    if (!empty($events)) {
        // Loop through each scheduled event
        foreach ($events as $timestamp => $cron) {
            foreach ($cron as $hook => $args) {
                // Check if the hook matches the npp's hook name
                if ($hook === $plugin_hook) {
                    // Convert the timestamp to a DateTime object
                    $next_run_datetime = new DateTime('@' . $timestamp);

                    // Set the timezone to the WordPress timezone
                    $next_run_datetime->setTimezone(new DateTimeZone(wp_timezone_string()));

                    // Format the DateTime object
                    $next_run_formatted = $next_run_datetime->format('Y-m-d H:i');

                    // Format the scheduled event information
                    $event_data[] = array(
                        'hook_name' => $hook,
                        'next_run' => esc_html($next_run_formatted)
                    );
                }
            }
        }
    }

    // If no events are found for the plugin, add a message to the event data
    if (empty($event_data)) {
        $event_data[] = array(
            'hook_name' => __('No active scheduled events found for NPP', 'fastcgi-cache-purge-and-preload-nginx'),
            'next_run' => ''
        );
    }

    // Return the event data as JSON response
    wp_send_json_success($event_data);
}

// Add AJAX action for canceling scheduled event
function nppp_cancel_scheduled_event_callback() {
    // Verify nonce
    if (isset($_POST['_wpnonce'])) {
        $nonce = sanitize_text_field(wp_unslash($_POST['_wpnonce']));
        if (!wp_verify_nonce($nonce, 'nppp-cancel-scheduled-event')) {
            wp_send_json_error('Nonce verification failed.');
        }
    } else {
        wp_send_json_error('Nonce is missing.');
    }

    // Check user capability
    if (!current_user_can('manage_options')) {
        wp_send_json_error('You do not have permission to access this page.');
    }

    // Get the hook name of the event to be canceled and sanitize it
    $hook = isset($_POST['hook']) ? sanitize_text_field(wp_unslash($_POST['hook'])) : '';

    // Validate the hook
    if (empty($hook) || $hook !== 'npp_cache_preload_event') {
        wp_send_json_error('Invalid hook name');
    }

    // Cancel the scheduled event
    $cleared = wp_clear_scheduled_hook('npp_cache_preload_event');
    if ($cleared) {
        // Remove the action hook associated with the scheduled event
        if (has_action('npp_cache_preload_event', 'nppp_create_scheduled_event_preload_callback')) {
            remove_action('npp_cache_preload_event', 'nppp_create_scheduled_event_preload_callback');
        }
        wp_send_json_success('Scheduled event canceled successfully');
    } else {
        wp_send_json_error('Failed to cancel scheduled event');
    }
}

// Add AJAX action for creating scheduled event
function nppp_create_scheduled_events($cron_expression) {
    // Parse the cron expression to get cron frequency and time
    list($cron_freq, $time) = explode('|', $cron_expression);

    // Create a DateTime object for the current time in WordPress timezone
    $wordpress_timezone = new DateTimeZone(wp_timezone_string());
    $current_time = new DateTime('now', $wordpress_timezone);

    // Create a DateTime object for the selected execution time in WordPress timezone
    $selected_execution_time = new DateTime('today ' . $time, $wordpress_timezone);

    // Adjust next schedule date
    switch ($cron_freq) {
        case 'daily':
            if ($selected_execution_time <= $current_time) {
                $selected_execution_time->modify('+1 day');
            }
            break;
        case 'weekly':
            if ($selected_execution_time <= $current_time) {
                $selected_execution_time->modify('+1 week');
            }
            break;
        case 'monthly':
            if ($selected_execution_time <= $current_time) {
                $selected_execution_time->modify('+1 month');
            }
            break;
        default:
            return;
    }

    // Check if there's already a scheduled event with the same hook
    $existing_timestamp = wp_next_scheduled('npp_cache_preload_event');

    // If there's an existing scheduled event, clear it
    if ($existing_timestamp) {
        $cleared = wp_clear_scheduled_hook('npp_cache_preload_event');
        if (!$cleared) {
            nppp_custom_error_log( __( 'Failed to unschedule existing event.', 'fastcgi-cache-purge-and-preload-nginx' ) );
            return;
        }
    }

    // Convert DateTime object to timestamp
    $next_execution_timestamp = $selected_execution_time->getTimestamp();

    // Schedule the recurring event using WordPress wp_schedule_event
    $recurrence = ($cron_freq === 'monthly') ? 'monthly_npp' : $cron_freq;
    $scheduled = wp_schedule_event($next_execution_timestamp, $recurrence, 'npp_cache_preload_event');

    if (!$scheduled) {
        nppp_custom_error_log( __( 'Failed to schedule new event.', 'fastcgi-cache-purge-and-preload-nginx' ) );
        return;
    }

    // Register the callback function for the scheduled event
    if (!has_action('npp_cache_preload_event', 'nppp_create_scheduled_event_preload_callback')) {
        add_action('npp_cache_preload_event', 'nppp_create_scheduled_event_preload_callback');
    }
}

// Create process status check event for preload action
function nppp_create_scheduled_event_preload_status() {
    // Reset phase transient on every fresh preload start so stale state
    // from any previous interrupted cycle never corrupts the new one.
    $phase_transient_key = 'nppp_preload_phase_' . md5('nppp');
    delete_transient($phase_transient_key);

    // Save cycle start timestamp for total elapsed time calculation
    $start_transient_key = 'nppp_preload_cycle_start_' . md5('nppp');
    set_transient($start_transient_key, time(), 12 * HOUR_IN_SECONDS);

    // Generate a fresh watchdog token for this preload cycle.
    // The watchdog spawned in preload will carry this token.
    nppp_watcher_generate_token();

    // Clear any existing tick and schedule the first check 5 seconds out.
    wp_clear_scheduled_hook('npp_cache_preload_status_event');
    wp_schedule_single_event(time() + 5, 'npp_cache_preload_status_event');
}

// Non-blocking tick callback for preload status monitoring.
//
// Architecture: instead of one long-lived PHP process,
// each tick wakes up, does a single PID check (~0.1s), then either reschedules
// the next tick and returns, or does the post-completion bookkeeping and exits.
//
// This means no single PHP execution ever approaches max_execution_time,
// request_terminate_timeout, or fastcgi_read_timeout — regardless of how long
// the preload takes.
//
// Phase tracking via transient:
//   nppp_preload_phase = 'desktop'  (default, set implicitly)
//   nppp_preload_phase = 'mobile'   (set after desktop finishes + mobile starts)
function nppp_create_scheduled_event_preload_status_callback() {
    $wp_filesystem = nppp_initialize_wp_filesystem();

    if ($wp_filesystem === false) {
        nppp_display_admin_notice(
            'error',
            __( 'Failed to initialize the WordPress filesystem. Please file a bug on the plugin support page.', 'fastcgi-cache-purge-and-preload-nginx' )
        );
        return;
    }

    // Get the plugin options
    $nginx_cache_settings = get_option('nginx_cache_settings', []);

    // Get preload pid file
    $PIDFILE = nppp_get_runtime_file('cache_preload.pid');

    // Determine current phase — 'desktop' (default) or 'mobile'
    $static_key_base = 'nppp';
    $phase_transient_key = 'nppp_preload_phase_' . md5($static_key_base);
    $phase = get_transient($phase_transient_key);
    if ($phase === false) {
        $phase = 'desktop';
    }

    // PIDFILE gone means a purge action stopped the preload externally.
    // Clean up our phase transient and exit — nothing more to do.
    if (!$wp_filesystem->exists($PIDFILE)) {
        delete_transient($phase_transient_key);
        delete_transient('nppp_preload_cycle_start_' . md5('nppp'));
        return;
    }

    $pid = intval(nppp_perform_file_operation($PIDFILE, 'read'));

    // Process still alive — reschedule next tick 5 seconds out
    if ($pid > 0 && nppp_is_process_alive($pid)) {
        wp_schedule_single_event(time() + 5, 'npp_cache_preload_status_event');
        return;
    }

    // Prevent duplicate execution when both watchdog and cron tick fire simultaneously.
    $completion_lock_key = 'nppp_preload_completion_lock_' . md5('nppp');
    if (get_transient($completion_lock_key)) {
        // Another process is already handling post‑preload tasks.
        return;
    }
    set_transient($completion_lock_key, 1, 30); // 30‑second lock

    // Process has finished.
    // If we just finished the desktop phase and mobile is enabled, start mobile now.
    if ($phase === 'desktop' &&
        isset($nginx_cache_settings['nginx_cache_auto_preload_mobile']) &&
        $nginx_cache_settings['nginx_cache_auto_preload_mobile'] === 'yes') {

        // Safely delete the desktop PID file since desktop preload completed
        nppp_perform_file_operation($PIDFILE, 'delete');

        // Lock phase to 'mobile' IMMEDIATELY after deleting desktop PIDFILE.
        // Any REST poll firing from this point on sees phase='mobile' and
        // cannot re-enter this branch
        set_transient($phase_transient_key, 'mobile', 12 * HOUR_IN_SECONDS);

        // Generate a fresh watchdog token for this preload cycle.
        // The watchdog spawned in preload will carry this token.
        nppp_watcher_generate_token();

        // Set default options to prevent any error
        $default_cache_path = '/dev/shm/change-me-now';
        $default_limit_rate = 5120;
        $default_cpu_limit = 100;
        $default_reject_regex = nppp_fetch_default_reject_regex();

        // Get the necessary data for mobile preload from plugin options
        $nginx_cache_path = isset($nginx_cache_settings['nginx_cache_path']) ? $nginx_cache_settings['nginx_cache_path'] : $default_cache_path;
        $nginx_cache_limit_rate = isset($nginx_cache_settings['nginx_cache_limit_rate']) ? $nginx_cache_settings['nginx_cache_limit_rate'] : $default_limit_rate;
        $nginx_cache_cpu_limit = isset($nginx_cache_settings['nginx_cache_cpu_limit']) ? $nginx_cache_settings['nginx_cache_cpu_limit'] : $default_cpu_limit;
        $nginx_cache_reject_regex = isset($nginx_cache_settings['nginx_cache_reject_regex']) ? $nginx_cache_settings['nginx_cache_reject_regex'] : $default_reject_regex;

        // Extra data for preload action
        $fdomain = get_site_url();
        $this_script_path = dirname(plugin_dir_path(__FILE__));
        $PIDFILE = nppp_get_runtime_file('cache_preload.pid');
        $tmp_path = rtrim($nginx_cache_path, '/') . "/tmp";

        // Start the preload action for Mobile
        nppp_preload($nginx_cache_path, $this_script_path, $tmp_path, $fdomain, $PIDFILE, $nginx_cache_reject_regex, $nginx_cache_limit_rate, $nginx_cache_cpu_limit, false, false, true, false, true);

        // Schedule next tick to monitor mobile PID
        wp_schedule_single_event(time() + 5, 'npp_cache_preload_status_event');
        return;
    }

    // All phases done — clean up phase transient
    $mobile_enabled = ($phase === 'mobile');
    delete_transient($phase_transient_key);

    /** ALL PRELOAD ACTIONS ENDED  */

    // Set default options
    $default_cache_path = '/dev/shm/change-me-now';

    // Get the necessary data for actions from plugin options
    $nginx_cache_path = isset($nginx_cache_settings['nginx_cache_path']) ? $nginx_cache_settings['nginx_cache_path'] : $default_cache_path;

    // wget downloaded content path
    $tmp_path = rtrim($nginx_cache_path, '/') . "/tmp";

    // Define runtime files
    $log_path = nppp_get_runtime_file('nppp-wget.log');
    $snapshot_path = nppp_get_runtime_file('nppp-wget-snapshot.log');

    // Initialize log-parsed values to safe defaults so they are always defined
    // even when the wget log is absent, incomplete, or contains unparseable data.
    // $elapsed_time_str feeds nppp_send_mail_now() and sprintf() unconditionally.
    $final_total       = 0;
    $elapsed_time_str  = '';
    $last_preload_time = '';
    $log_contents      = '';
    $nppp_mail_hits    = null;

    // Parse log file using to get total processed URL
    if ($wp_filesystem->exists($log_path) && $wp_filesystem->is_readable($log_path)) {
        $log_contents = $wp_filesystem->get_contents($log_path);

        if ($log_contents) {
            // Get processed URLs
            if (preg_match('/^Downloaded:\s+(\d+)\s+files/m', $log_contents, $m)) {
                $final_total = (int) $m[1];
            }

            // Get wall clock time
            if (preg_match('/^Total wall clock time:\s*((?:[0-9]+h\s*)?(?:[0-9]+m\s*)?[0-9]+(?:\.[0-9]+)?s)/im', $log_contents, $match)) {
                $elapsed_time_str = trim($match[1]);
            }

            // Get preload finish timestamp
            if (preg_match('/^FINISHED\s+--([\d\-]+\s+[\d:]+)--$/m', $log_contents, $match)) {
                $last_preload_time = trim($match[1]);
            }
        }
    }

    // Persist a stable snapshot only when the crawl completed successfully.
    // This snapshot is what the Advanced tab reads for MISS data. It survives
    // across purges and interrupted runs, so the table stays populated.
    if ( !empty($log_contents) && nppp_wget_log_is_complete( $log_contents ) ) {
        $wp_filesystem->put_contents( $snapshot_path, $log_contents, FS_CHMOD_FILE );

        // Build the URL→filepath index used by single-page and related purges
        // to skip expensive recursive cache directory scans.
        // Best moment to rebuild: cache is fully populated and we are already
        // inside the post-preload cleanup tick, so the directory walk costs
        // nothing extra relative to what just completed.
        // Merge strategy: existing entries are preserved, incoming entries
        // add or update. Never truncate — Preload All only crawls URLs allowed
        // by Exclude Endpoints, so pages outside that ruleset never appear in
        // $nppp_index_data. Those pages accumulate in the index over time as
        // real visitors or bots hit them and purge operations add them via
        // write-back. They must survive across Purge All + Preload All cycles
        // because nginx will always re-cache them to the same deterministic
        // path on next visit. Single URL can hold multiple PATH.
        $nppp_index_data = nppp_extract_cached_urls( $wp_filesystem, $nginx_cache_path );
        if ( is_array( $nppp_index_data ) && ! isset( $nppp_index_data['error'] ) ) {
            $nppp_index = get_option( 'nppp_url_filepath_index' );
            $nppp_index = is_array( $nppp_index ) ? $nppp_index : [];
            foreach ( $nppp_index_data as $nppp_entry ) {
                $nppp_key      = preg_replace( '#^https?://#', '', $nppp_entry['url_encoded'] );
                $nppp_existing = $nppp_index[ $nppp_key ] ?? [];
                if ( ! in_array( $nppp_entry['file_path'], $nppp_existing, true ) ) {
                    $nppp_existing[] = $nppp_entry['file_path'];
                }
                $nppp_index[ $nppp_key ] = $nppp_existing;
            }
            update_option( 'nppp_url_filepath_index', $nppp_index, false );
            $nppp_mail_hits = count( $nppp_index_data );
            unset( $nppp_index_data, $nppp_index, $nppp_entry, $nppp_key, $nppp_existing );
        }
    }

    // Save to transient for frontend preload progress
    $static_key_base = 'nppp';
    $count_transient_key = 'nppp_est_url_counts_' . md5($static_key_base);
    set_transient($count_transient_key, $final_total > 0 ? $final_total : 2000, DAY_IN_SECONDS);

    if (!empty($last_preload_time)) {
        $timestamp_transient_key = 'nppp_last_preload_time_' . md5($static_key_base);
        set_transient($timestamp_transient_key, $last_preload_time, DAY_IN_SECONDS);
    }

    // Remove downloaded content
    nppp_wp_remove_directory($tmp_path, true);

    // Delete the PID file
    nppp_perform_file_operation($PIDFILE, 'delete');

    // Elapsed time comes from wget's own wall clock line parsed above.
    // If wget did not write it (e.g. interrupted), fall back to a plain notice.
    $start_transient_key = 'nppp_preload_cycle_start_' . md5('nppp');
    $cycle_start = get_transient($start_transient_key);
    delete_transient($start_transient_key);

    if ($mobile_enabled && $cycle_start && !empty($last_preload_time)) {
        // Use wget's own FINISHED timestamp as end — immune to cron delay
        $end_time = DateTime::createFromFormat(
            'Y-m-d H:i:s',
            $last_preload_time,
            new DateTimeZone(wp_timezone_string())
        );
        if ($end_time) {
            $total_seconds = $end_time->getTimestamp() - intval($cycle_start);
            $total_seconds = max(0, $total_seconds);
            $hours   = intdiv($total_seconds, 3600);
            $minutes = intdiv($total_seconds % 3600, 60);
            $seconds = $total_seconds % 60;
            $elapsed_time_str = $hours > 0
                ? sprintf('%sh %sm %ss', $hours, $minutes, $seconds)
                : sprintf('%sm %ss', $minutes, $seconds);
        }
    } elseif (empty($elapsed_time_str)) {
        $elapsed_time_str = __('(unable to calculate elapsed time)', 'fastcgi-cache-purge-and-preload-nginx');
    }

    // Parse extra metrics — zero extra I/O
    $download_size  = '';
    $transfer_speed = '';
    $error_count    = 0;
    if ( ! empty( $log_contents ) ) {
        if ( preg_match( '/Downloaded:.*?,\s+([\d.]+\s*)([KMG])\s+in\s+[\d.]+s/i', $log_contents, $ds ) ) {
            $unit_map = [ 'K' => 'KB', 'M' => 'MB', 'G' => 'GB' ];
            $download_size = trim( $ds[1] ) . ( $unit_map[ strtoupper( $ds[2] ) ] ?? $ds[2] );
        }
        if ( preg_match( '/Downloaded:.*?\(([\d.]+\s+\w+\/s)\)/i', $log_contents, $sp ) ) {
            $transfer_speed = trim( $sp[1] );
        }
        // Same pattern preload-progress.php uses for broken URL detection
        $error_count = preg_match_all( '/ERROR\s+404/i', $log_contents );
    }

    if ( $nppp_mail_hits !== null ) {
        update_option( 'nppp_last_known_hits',      $nppp_mail_hits, false );
        update_option( 'nppp_last_hits_scanned_at', time(),          false );
    }

    $mail_message = __('The Nginx cache preload operation has been completed', 'fastcgi-cache-purge-and-preload-nginx');
    nppp_send_mail_now($mail_message, $elapsed_time_str, $final_total, $mobile_enabled, $last_preload_time, $download_size, $transfer_speed, $error_count, $nppp_mail_hits);

    // Log the preload process status
    if ($mobile_enabled) {
        // Translators: %s is the elapsed time.
        nppp_display_admin_notice('success', sprintf( __( 'SUCCESS: Nginx cache preload completed for both Mobile and Desktop in %s.', 'fastcgi-cache-purge-and-preload-nginx' ), $elapsed_time_str ), true, false);
    } else {
        // Translators: %s is the elapsed time.
        nppp_display_admin_notice('success', sprintf( __( 'SUCCESS: Nginx cache preload completed in %s.', 'fastcgi-cache-purge-and-preload-nginx' ), $elapsed_time_str ), true, false);
    }

    // Return control to WP-Cron so other due events can continue in this request.
    return;
}

// Custom cron schedule for monthly recurrence
function nppp_custom_monthly_schedule($schedules) {
    $schedules['monthly_npp'] = array(
        'interval' => 30 * DAY_IN_SECONDS,
        'display'  => 'Monthly-NPP'
    );
    return $schedules;
}

// Custom cron schedule for 1 min recurrence
function nppp_custom_every_min_schedule($schedules) {
    $schedules['every_min_npp'] = array(
        'interval' => 60,
        'display'  => 'Every Minute-NPP'
    );
    return $schedules;
}

// Callback function for the scheduled event
function nppp_create_scheduled_event_preload_callback() {
    // Get the plugin options
    $nginx_cache_settings = get_option('nginx_cache_settings', []);

    // Set default options to prevent any error
    $default_cache_path = '/dev/shm/change-me-now';
    $default_limit_rate = 5120;
    $default_cpu_limit = 100;
    $default_reject_regex = nppp_fetch_default_reject_regex();

    // Get the necessary data for preload action from plugin options
    $nginx_cache_path = isset($nginx_cache_settings['nginx_cache_path']) ? $nginx_cache_settings['nginx_cache_path'] : $default_cache_path;
    $nginx_cache_limit_rate = isset($nginx_cache_settings['nginx_cache_limit_rate']) ? $nginx_cache_settings['nginx_cache_limit_rate'] : $default_limit_rate;
    $nginx_cache_cpu_limit = isset($nginx_cache_settings['nginx_cache_cpu_limit']) ? $nginx_cache_settings['nginx_cache_cpu_limit'] : $default_cpu_limit;
    $nginx_cache_reject_regex = isset($nginx_cache_settings['nginx_cache_reject_regex']) ? $nginx_cache_settings['nginx_cache_reject_regex'] : $default_reject_regex;

    // Extra data for preload action
    $fdomain = get_site_url();
    $this_script_path = dirname(plugin_dir_path(__FILE__));
    $PIDFILE = nppp_get_runtime_file('cache_preload.pid');
    $tmp_path = rtrim($nginx_cache_path, '/') . "/tmp";

    // Start the preload action
    nppp_preload($nginx_cache_path, $this_script_path, $tmp_path, $fdomain, $PIDFILE, $nginx_cache_reject_regex, $nginx_cache_limit_rate, $nginx_cache_cpu_limit, false, false, true, false);
}
