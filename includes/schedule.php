<?php
/**
 * Preload action related schedule cron events for FastCGI Cache Purge and Preload for Nginx
 * Description: This file contains preload action related schedule cron events functions for FastCGI Cache Purge and Preload for Nginx
 * Version: 2.1.0
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
        wp_die(esc_html__('You do not have permission to call this action.', 'fastcgi-cache-purge-and-preload-nginx'));
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
function nppp_create_scheduled_event_preload_status($start_time) {
    // Create a DateTime object for the current time in WordPress timezone
    $wordpress_timezone = new DateTimeZone(wp_timezone_string());
    $current_time = new DateTime('now', $wordpress_timezone);

    // Create a DateTime object for the selected execution time in WordPress timezone
    $selected_execution_time = new DateTime('today ' . $start_time, $wordpress_timezone);

    // Add 5 seconds to the selected execution time
    $next_execution_time = $selected_execution_time->modify('+5 seconds');

    // Get the timestamp for the next execution time
    $next_execution_timestamp = $next_execution_time->getTimestamp();

    // If there's an existing scheduled event, clear it
    wp_clear_scheduled_hook('npp_cache_preload_status_event');

    // Schedule new event
    wp_schedule_single_event($next_execution_timestamp, 'npp_cache_preload_status_event');
}

// Here we will calculate elapsed time for preload action
// So first we need the first scheduled time of event
function nppp_get_preload_start_time() {
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
        define('NGINX_CACHE_LOG_FILE', dirname(__FILE__) . '/../fastcgi_ops.log');
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

            // check preload triggered by auto preload feature
            $options = get_option('nginx_cache_settings');
            $auto_preload = isset($options['nginx_cache_auto_preload']) && $options['nginx_cache_auto_preload'] === 'yes';

            // Define the translated strings for the different variations of the preload initiation message
            $auto_preload_messages = [
                __( 'SUCCESS REST: Nginx cache purged successfully. Auto preload initiated in the background. Monitor the -Status- tab for real-time updates.', 'fastcgi-cache-purge-and-preload-nginx' ),
                __( 'SUCCESS ADMIN: Nginx cache purged successfully. Auto Preload initiated in the background. Monitor the -Status- tab for real-time updates.', 'fastcgi-cache-purge-and-preload-nginx' ),
                __( 'SUCCESS: Nginx cache purged successfully. Auto Preload initiated in the background. Monitor the -Status- tab for real-time updates.', 'fastcgi-cache-purge-and-preload-nginx' )
            ];

            // Define the translated strings for the manual preload messages
            $manual_preload_messages = [
                __( 'SUCCESS: Nginx cache preloading has started in the background. Please check the --Status-- tab for progress updates.', 'fastcgi-cache-purge-and-preload-nginx' ),
                __( 'SUCCESS REST: Nginx cache preloading has started in the background. Please check the --Status-- tab for progress updates.', 'fastcgi-cache-purge-and-preload-nginx' ),
                __( 'SUCCESS CRON: Nginx cache preloading has started in the background. Please check the --Status-- tab for progress updates.', 'fastcgi-cache-purge-and-preload-nginx' ),
                __( 'SUCCESS ADMIN: Nginx cache preloading has started in the background. Please check the --Status-- tab for progress updates.', 'fastcgi-cache-purge-and-preload-nginx' )
            ];

            // Iterate through each line in the log to find the latest preload initiation time
            foreach (array_reverse($log_lines) as $line) {
                if ($auto_preload) {
                    // Check for any of the "auto preload" initiation messages
                    foreach ($auto_preload_messages as $auto_preload_message) {
                        if (strpos($line, $auto_preload_message) !== false) {
                            // Extract the timestamp part from the line
                            preg_match('/\[(.*?)\]/', $line, $match);
                            if (isset($match[1])) {
                                $latest_timestamp = $match[1];
                                return $latest_timestamp;
                            }
                        }
                    }
                } else {
                    // Check for any of the "manual preload" initiation messages
                    foreach ($manual_preload_messages as $manual_preload_message) {
                        if (strpos($line, $manual_preload_message) !== false) {
                            // Extract the timestamp part from the line
                            preg_match('/\[(.*?)\]/', $line, $match);
                            if (isset($match[1])) {
                                $latest_timestamp = $match[1];
                                return $latest_timestamp;
                            }
                        }
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

// Function to check the status of the background process
function nppp_create_scheduled_event_preload_status_callback() {
    $wp_filesystem = nppp_initialize_wp_filesystem();

    if ($wp_filesystem === false) {
        nppp_display_admin_notice(
            'error',
            __( 'Failed to initialize the WordPress filesystem. Please file a bug on the plugin support page.', 'fastcgi-cache-purge-and-preload-nginx' )
        );
        return;
    }

    // Track status of Preload Mobile
    $mobile_enabled = false;

    // Get scheduled time
    $scheduled_time_str = nppp_get_preload_start_time();

    // Get the plugin options
    $nginx_cache_settings = get_option('nginx_cache_settings');

    // Convert the scheduled time string to a DateTime object
    $wordpress_timezone = new DateTimeZone(wp_timezone_string());

    // Check if the scheduled time string is not null
    if ($scheduled_time_str !== null) {
        $scheduled_time = DateTime::createFromFormat('Y-m-d H:i:s', $scheduled_time_str, $wordpress_timezone);
    }

    // Get preload pid file
    $PIDFILE = dirname(__FILE__) . '/../cache_preload.pid';

    // Check if there is an ongoing preload process active
    if ($wp_filesystem->exists($PIDFILE)) {
        $pid = intval(nppp_perform_file_operation($PIDFILE, 'read'));

        // Process is alive
        while ($pid > 0 && nppp_is_process_alive($pid)) {
            // Sleep for a short duration before checking again
            sleep(5);

            // If a purge action is triggered during preloading, the preload action is already stopped,
            // and the PID file is removed, so there’s no need to check the preload status anymore.
            if ($wp_filesystem->exists($PIDFILE)) {
                // Check again for pid
                $pid = intval(nppp_perform_file_operation($PIDFILE, 'read'));
            } else {
                exit(0);
            }
        }

        // Check if the Preload Mobile option is enabled
        // The ongoing Preload action has just finished!
        // If the Preload Mobile feature is enabled, safely remove the process PID first,
        // then immediately start warming the Nginx cache for mobile devices.
        if (isset($nginx_cache_settings['nginx_cache_auto_preload_mobile']) && $nginx_cache_settings['nginx_cache_auto_preload_mobile'] === 'yes') {
            $mobile_enabled = true;

            // Safely delete the PID file since the previous preload action has already completed
            nppp_perform_file_operation($PIDFILE, 'delete');

            // Set default options to prevent any error
            $default_cache_path = '/dev/shm/change-me-now';
            $default_limit_rate = 5120;
            $default_cpu_limit = 80;
            $default_reject_regex = nppp_fetch_default_reject_regex();

            // Get the necessary data for preload action from plugin options
            $nginx_cache_path = isset($nginx_cache_settings['nginx_cache_path']) ? $nginx_cache_settings['nginx_cache_path'] : $default_cache_path;
            $nginx_cache_limit_rate = isset($nginx_cache_settings['nginx_cache_limit_rate']) ? $nginx_cache_settings['nginx_cache_limit_rate'] : $default_limit_rate;
            $nginx_cache_cpu_limit = isset($nginx_cache_settings['nginx_cache_cpu_limit']) ? $nginx_cache_settings['nginx_cache_cpu_limit'] : $default_cpu_limit;
            $nginx_cache_reject_regex = isset($nginx_cache_settings['nginx_cache_reject_regex']) ? $nginx_cache_settings['nginx_cache_reject_regex'] : $default_reject_regex;

            // Extra data for preload action
            $fdomain = get_site_url();
            $this_script_path = dirname(plugin_dir_path(__FILE__));
            $PIDFILE = rtrim($this_script_path, '/') . '/cache_preload.pid';
            $tmp_path = rtrim($nginx_cache_path, '/') . "/tmp";

            // Start the preload action with $user_agent set to true
            // The cache preloading will now begin again using the mobile USER_AGENT.
            nppp_preload($nginx_cache_path, $this_script_path, $tmp_path, $fdomain, $PIDFILE, $nginx_cache_reject_regex, $nginx_cache_limit_rate, $nginx_cache_cpu_limit, false, false, true, false, true);
            sleep(1);
        }

        // Re-check if any process triggered by Preload Mobile is still running
        // If Preload Mobile was not triggered, the loop will end immediately
        // Otherwise, it will wait until the Preload Mobile process completes.
        if ($mobile_enabled) {
            // Get the new pid of Preload Mobile process
            $pid = intval(nppp_perform_file_operation($PIDFILE, 'read'));

            // Wait until the Preload Mobile process completes.
            while ($pid > 0 && nppp_is_process_alive($pid)) {
                // Sleep for a short duration before checking again
                sleep(5);

                // If a purge action is triggered during preloading, the preload action is already stopped,
                // and the PID file is removed, so there’s no need to check the preload status anymore.
                if ($wp_filesystem->exists($PIDFILE)) {
                    // Check again for pid
                    $pid = intval(nppp_perform_file_operation($PIDFILE, 'read'));
                } else {
                    exit(0);
                }
            }
        }

        /** ALL PRELOAD ACTIONS ENDED  */

        // Set default options
        $default_cache_path = '/dev/shm/change-me-now';

        // Get the necessary data for actions from plugin options
        $nginx_cache_path = isset($nginx_cache_settings['nginx_cache_path']) ? $nginx_cache_settings['nginx_cache_path'] : $default_cache_path;

        // wget downloaded content path
        $tmp_path = rtrim($nginx_cache_path, '/') . "/tmp";

        // Remove downloaded content
        nppp_wp_remove_directory($tmp_path, true);

        // Delete the PID file
        nppp_perform_file_operation($PIDFILE, 'delete');

        // Create a DateTime object for the current time in WordPress timezone
        $current_time = new DateTime('now', $wordpress_timezone);

        // Calculate elapsed time
        if (isset($scheduled_time) && $scheduled_time instanceof DateTime) {
            $elapsed_time = $current_time->diff($scheduled_time);

            // Format elapsed time as a string
            $elapsed_time_str = sprintf(
                /* Translators: %1$s, %2$s, and %3$s are numeric values representing hours, minutes, and seconds respectively. */
                __('%1$s hours, %2$s minutes, %3$s seconds', 'fastcgi-cache-purge-and-preload-nginx'),
                $elapsed_time->format('%h'),
                $elapsed_time->format('%i'),
                $elapsed_time->format('%s')
            );
        } else {
            // Process complete time can not calculated
            $elapsed_time_str = __( '(unable to calculate elapsed time)', 'fastcgi-cache-purge-and-preload-nginx' );
        }

        // Send Mail
        $mail_message = __('The Nginx cache preload operation has been completed', 'fastcgi-cache-purge-and-preload-nginx');
        nppp_send_mail_now($mail_message, $elapsed_time_str);

        // Log the preload process status
        if ($mobile_enabled) {
            // Translators: %s is the elapsed time.
            nppp_display_admin_notice('success', sprintf( __( 'SUCCESS: Nginx cache preload completed for both Mobile and Desktop in %s.', 'fastcgi-cache-purge-and-preload-nginx' ), $elapsed_time_str ), true, false);
        } else {
            // Translators: %s is the elapsed time.
            nppp_display_admin_notice('success', sprintf( __( 'SUCCESS: Nginx cache preload completed in %s.', 'fastcgi-cache-purge-and-preload-nginx' ), $elapsed_time_str ), true, false);
        }
    }

    // Gracefully exit from wp cron job
    exit(0);
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
    $nginx_cache_settings = get_option('nginx_cache_settings');

    // Set default options to prevent any error
    $default_cache_path = '/dev/shm/change-me-now';
    $default_limit_rate = 5120;
    $default_cpu_limit = 80;
    $default_reject_regex = nppp_fetch_default_reject_regex();

    // Get the necessary data for preload action from plugin options
    $nginx_cache_path = isset($nginx_cache_settings['nginx_cache_path']) ? $nginx_cache_settings['nginx_cache_path'] : $default_cache_path;
    $nginx_cache_limit_rate = isset($nginx_cache_settings['nginx_cache_limit_rate']) ? $nginx_cache_settings['nginx_cache_limit_rate'] : $default_limit_rate;
    $nginx_cache_cpu_limit = isset($nginx_cache_settings['nginx_cache_cpu_limit']) ? $nginx_cache_settings['nginx_cache_cpu_limit'] : $default_cpu_limit;
    $nginx_cache_reject_regex = isset($nginx_cache_settings['nginx_cache_reject_regex']) ? $nginx_cache_settings['nginx_cache_reject_regex'] : $default_reject_regex;

    // Extra data for preload action
    $fdomain = get_site_url();
    $this_script_path = dirname(plugin_dir_path(__FILE__));
    $PIDFILE = rtrim($this_script_path, '/') . '/cache_preload.pid';
    $tmp_path = rtrim($nginx_cache_path, '/') . "/tmp";

    // Start the preload action
    nppp_preload($nginx_cache_path, $this_script_path, $tmp_path, $fdomain, $PIDFILE, $nginx_cache_reject_regex, $nginx_cache_limit_rate, $nginx_cache_cpu_limit, false, false, true, false);
}
