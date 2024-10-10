<?php
/**
 * Preload action functions for FastCGI Cache Purge and Preload for Nginx
 * Description: This file contains preload action functions for FastCGI Cache Purge and Preload for Nginx
 * Version: 2.0.4
 * Author: Hasan ÇALIŞIR
 * Author Email: hasan.calisir@psauxit.com
 * Author URI: https://www.psauxit.com
 * License: GPL-2.0+
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Preload operation
function nppp_preload($nginx_cache_path, $this_script_path, $tmp_path, $fdomain, $PIDFILE, $nginx_cache_reject_regex, $nginx_cache_limit_rate, $nginx_cache_cpu_limit, $nppp_is_auto_preload = false, $nppp_is_rest_api = false, $nppp_is_wp_cron = false, $nppp_is_admin_bar = false) {
    $wp_filesystem = nppp_initialize_wp_filesystem();

    if ($wp_filesystem === false) {
        nppp_display_admin_notice(
            'error',
            'Failed to initialize the WordPress filesystem. Please file a bug on the plugin support page.'
        );
        return;
    }

    // Check if there is an ongoing preload process active
    if ($wp_filesystem->exists($PIDFILE)) {
        $pid = intval(nppp_perform_file_operation($PIDFILE, 'read'));

        if ($pid > 0 && posix_kill($pid, 0)) {
            nppp_display_admin_notice('info', 'INFO: FastCGI cache preloading is already running. If you want to stop it please use Purge All!');
            return;
        }
    }

    // Get the plugin options
    $nginx_cache_settings = get_option('nginx_cache_settings');
    $default_wait_time = 1;
    $default_reject_extension = nppp_fetch_default_reject_extension();
    $nginx_cache_reject_extension = isset($nginx_cache_settings['nginx_cache_reject_extension']) ? $nginx_cache_settings['nginx_cache_reject_extension'] : $default_reject_extension;
    $nginx_cache_wait = isset($nginx_cache_settings['nginx_cache_wait_request']) ? $nginx_cache_settings['nginx_cache_wait_request'] : $default_wait_time;

    // Here we check where preload request comes from. We have several routes.
    // If nppp_is_auto_preload is false thats mean we are here by one of following routes.
    // Preload(settings page), Preload(admin bar), Preload CRON or Preload REST API.
    // That means here we first purge cache before preload action for all these routes cause flag is false.
    // Keep in mind that auto preload feature only triggers with purge action itself with not preload action.
    if (!$nppp_is_auto_preload) {
        // Purge cache before preload and get status
        $status = nppp_purge_helper($nginx_cache_path, $tmp_path);

        // Handle different status codes
        if ($status === 0 || $status === 2) {
            // Create PID file
            if (!$wp_filesystem->exists($PIDFILE)) {
                if (!nppp_perform_file_operation($PIDFILE, 'create')) {
                    nppp_display_admin_notice('error', 'FATAL ERROR: Failed to create PID file.');
                    return;
                }
            }

            // Check cpulimit command exist
            $cpulimitPath = shell_exec('type cpulimit');

            if (!empty(trim($cpulimitPath))) {
                $cpulimit = 1;
            } else {
                $cpulimit = 0;
            }

            // Start cache preloading for whole website (Preload All)
            // 1. Some wp security plugins or manual security implementation on server side can block recursive wget requests so we use custom user-agent and robots=off to prevent this as much as possible.
            // 2. Also to prevent cache preloading interrupts as much as possible, increasing UX on different wordpress installs/env. (servers that are often misconfigured, leading to certificate issues),
            //    speeding up cache preloading via reducing latency we use --no-check-certificate .
            //    Requests comes from our local network/server where wordpress website hosted since it minimizes the risk of a MITM security vulnerability.
            $command = "nohup wget --quiet --recursive --no-cache --no-cookies --no-directories --delete-after " .
                "--no-dns-cache --no-check-certificate --no-use-server-timestamps --no-if-modified-since " .
                "--ignore-length --timeout=5 --tries=1 -e robots=off " .
                "-P \"$tmp_path\" " .
                "--limit-rate=\"$nginx_cache_limit_rate\"k " .
                "--wait=$nginx_cache_wait " .
                "--reject-regex='\"$nginx_cache_reject_regex\"' " .
                "--reject='\"$nginx_cache_reject_extension\"' " .
                "--user-agent='\"". NPPP_USER_AGENT ."\"' " .
                "\"$fdomain\" >/dev/null 2>&1 & echo \$!";
            $output = shell_exec($command);

            // Get the process ID
            if ($output !== null) {
                $parts = explode(" ", $output);
                $pid = end($parts);

                // Sleep for 1 seconds to check background process status again
                sleep(1);

                // Check if the process is still running
                $isRunning = posix_kill($pid, 0);

                // we did not get immediate exit from process
                if ($isRunning) {
                    nppp_perform_file_operation($PIDFILE, 'write', $pid);

                    // Create a DateTime object for the current time in WordPress timezone
                    $wordpress_timezone = new DateTimeZone(wp_timezone_string());
                    $current_time = new DateTime('now', $wordpress_timezone);

                    // Format the current time as the start time for the scheduled event
                    $start_time = $current_time->format('H:i:s');

                    // Call the function to schedule the status check event
                    nppp_create_scheduled_event_preload_status($start_time);

                    // Start cpulimit if it is exist
                    if ($cpulimit === 1) {
                        $command = "cpulimit -p \"$pid\" -l \"$nginx_cache_cpu_limit\" -zb >/dev/null 2>&1";
                        shell_exec($command);
                    }

                    // Define a default success message
                    $default_success_message = 'SUCCESS: Cache preloading has started in the background. Please check the --Status-- tab for progress updates.';

                    // Check the status of $nppp_is_rest_api and display success message accordingly
                    if (is_bool($nppp_is_rest_api) && $nppp_is_rest_api) {
                        nppp_display_admin_notice('success', 'SUCCESS REST: Cache preloading has started in the background. Please check the --Status-- tab for progress updates.');
                    }

                    // Check the status of $nppp_is_wp_cron and display success message accordingly
                    if (is_bool($nppp_is_wp_cron) && $nppp_is_wp_cron) {
                        nppp_display_admin_notice('success', 'SUCCESS CRON: Cache preloading has started in the background. Please check the --Status-- tab for progress updates.');
                    }

                    // Check the status of $nppp_is_admin_bar and display success message accordingly
                    if (is_bool($nppp_is_admin_bar) && $nppp_is_admin_bar) {
                        nppp_display_admin_notice('success', 'SUCCESS ADMIN: Cache preloading has started in the background. Please check the --Status-- tab for progress updates.');
                    }

                    // If none of the specific conditions were met, display the default success message
                    if (!($nppp_is_rest_api || $nppp_is_wp_cron || $nppp_is_admin_bar)) {
                        nppp_display_admin_notice('success', $default_success_message);
                    }
                } else {
                    // display admin notice
                    nppp_display_admin_notice('error', 'ERROR COMMAND: Cannot start FastCGI cache preload! Please check your exclude endpoints reject regex pattern is correct.');
                }
            } else {
                nppp_display_admin_notice('error', 'ERROR COMMAND: Cannot start FastCGI cache preload! Please report this issue on the plugin support page.');
            }
        } elseif ($status === 1) {
            nppp_display_admin_notice('error', 'ERROR PERMISSION: Cannot purge FastCGI cache to start cache preloading. Please read help section of the plugin.');
        } elseif ($status === 3) {
            nppp_display_admin_notice('error', 'ERROR PATH: Your FastCGI cache PATH (' . $nginx_cache_path . ') not found. Please check your FastCGI cache path.');
        } else {
            nppp_display_admin_notice('error', 'ERROR UNKNOWN: An unexpected error occurred while preloading the FastCGI cache. Please report this issue on the plugin support page.');
        }
    // Here preload request comes from Purge(Settings Page) or Purge(Admin Bar)
    // As mentioned before auto preload feature only triggers with purge action itself
    // Thats means here purge action already triggered directly and cache purged,
    // so we just start the preload action without purge again.
    // Also we use deferred messages here.
    } else {
        // Create PID file
        if (!$wp_filesystem->exists($PIDFILE)) {
            if (!nppp_perform_file_operation($PIDFILE, 'create')) {
                nppp_display_admin_notice('error', 'FATAL ERROR: Failed to create PID file.');
                return;
            }
        }

        // Check cpulimit command exist
        $cpulimitPath = shell_exec('type cpulimit');
        if (!empty(trim($cpulimitPath))) {
            $cpulimit = 1;
        } else {
            $cpulimit = 0;
        }

        // Start cache preloading for whole website (Preload All)
        // 1. Some wp security plugins or manual security implementation on server side can block recursive wget requests so we use custom user-agent and robots=off to prevent this as much as possible.
        // 2. Also to prevent cache preloading interrupts as much as possible, increasing UX on different wordpress installs/env. (servers that are often misconfigured, leading to certificate issues),
        //    speeding up cache preloading via reducing latency we use --no-check-certificate .
        //    Requests comes from our local network/server where wordpress website hosted since it minimizes the risk of a MITM security vulnerability.
        $command = "nohup wget --quiet --recursive --no-cache --no-cookies --no-directories --delete-after " .
                "--no-dns-cache --no-check-certificate --no-use-server-timestamps --no-if-modified-since " .
                "--ignore-length --timeout=5 --tries=1 -e robots=off " .
                "-P \"$tmp_path\" " .
                "--limit-rate=\"$nginx_cache_limit_rate\"k " .
                "--wait=$nginx_cache_wait " .
                "--reject-regex='\"$nginx_cache_reject_regex\"' " .
                "--reject='\"$nginx_cache_reject_extension\"' " .
                "--user-agent='\"". NPPP_USER_AGENT ."\"' " .
                "\"$fdomain\" >/dev/null 2>&1 & echo \$!";
        $output = shell_exec($command);

        // Get the process ID
        if ($output !== null) {
            $parts = explode(" ", $output);
            $pid = end($parts);

            // Sleep for 1 seconds to check background process status again
            sleep(1);

            // Check if the process is still running
            $isRunning = posix_kill($pid, 0);

            // We did not get immediate exit from process
            if ($isRunning) {
                nppp_perform_file_operation($PIDFILE, 'write', $pid);

                // Create a DateTime object for the current time in WordPress timezone
                $wordpress_timezone = new DateTimeZone(wp_timezone_string());
                $current_time = new DateTime('now', $wordpress_timezone);

                // Format the current time as the start time for the scheduled event
                $start_time = $current_time->format('H:i:s');

                // Call the function to schedule the status check event
                nppp_create_scheduled_event_preload_status($start_time);

                // Start cpulimit if it is exist
                if ($cpulimit === 1) {
                    $command = "cpulimit -p \"$pid\" -l \"$nginx_cache_cpu_limit\" -zb >/dev/null 2>&1";
                    shell_exec($command);
                }

                // Display the deferred message as admin notice
                if (is_bool($nppp_is_rest_api) && $nppp_is_rest_api) {
                    nppp_display_admin_notice('success', 'SUCCESS REST: Cache purged successfully. Auto preload initiated in the background. Monitor the -Status- tab for real-time updates.');
                } elseif (is_bool($nppp_is_admin_bar) && $nppp_is_admin_bar) {
                    nppp_display_admin_notice('success', 'SUCCESS ADMIN: Cache purged successfully. Auto preload initiated in the background. Monitor the -Status- tab for real-time updates.');
                } else {
                    nppp_display_admin_notice('success', 'SUCCESS: Cache purged successfully. Auto preload initiated in the background. Monitor the -Status- tab for real-time updates.');
                }
            } else {
                // Display admin notice
                nppp_display_admin_notice('error', 'ERROR COMMAND: Cannot start FastCGI cache preload! Please check your exclude endpoints reject regex pattern is correct.');
            }
        } else {
            nppp_display_admin_notice('error', 'ERROR CRITICAL: Cannot start FastCGI cache preload! Please report this issue on the plugin support page.');
        }
    }
}

// Single page preload
function nppp_preload_single($current_page_url, $PIDFILE, $tmp_path, $nginx_cache_reject_regex, $nginx_cache_limit_rate, $nginx_cache_cpu_limit, $nginx_cache_path) {
    $wp_filesystem = nppp_initialize_wp_filesystem();

    if ($wp_filesystem === false) {
        nppp_display_admin_notice(
            'error',
            'Failed to initialize the WordPress filesystem. Please file a bug on the plugin support page.'
        );
        return;
    }

    // Check if there is an ongoing preload process active
    if ($wp_filesystem->exists($PIDFILE)) {
        $pid = intval(nppp_perform_file_operation($PIDFILE, 'read'));

        if ($pid > 0 && posix_kill($pid, 0)) {
            nppp_display_admin_notice('info', 'INFO: FastCGI cache preloading is already running. If you want to stop it please use Purge All!');
            return;
        }
    } elseif (!nppp_perform_file_operation($PIDFILE, 'create')) {
        nppp_display_admin_notice('error', 'FATAL ERROR: Failed to create PID file.');
        return;
    }

    // Check for any permisson issue softly
    if (!$wp_filesystem->is_readable($nginx_cache_path) || !$wp_filesystem->is_writable($nginx_cache_path)) {
        nppp_display_admin_notice('error', "ERROR PERMISSION: Cache preload failed for page $current_page_url due to permission issue. Refer to -Help- tab for guidance.");
        return;
    // Recusive check for permission issues deeply
    } elseif (!nppp_check_permissions_recursive($nginx_cache_path)) {
        nppp_display_admin_notice('error', "ERROR PERMISSION: Cache preload failed for page $current_page_url due to permission issue. Refer to -Help- tab for guidance.");
        return;
    }

    // Valitade the sanitized url before process
    if (filter_var($current_page_url, FILTER_VALIDATE_URL) === false) {
        nppp_display_admin_notice('error', "ERROR URL: HTTP_REFERRER URL can not validated.");
        return;
    }

    // Checks if the HTTP referrer originated from our own host domain
    $referrer_parsed_url = wp_parse_url($current_page_url);
    $home_url = home_url();
    $parsed_home_url = wp_parse_url($home_url);

    if ($referrer_parsed_url['host'] !== $parsed_home_url['host']) {
        nppp_display_admin_notice('error', "ERROR URL: HTTP_REFERRER URL is not from the allowed domain.");
        return;
    }

    // Start cache preloading for single post/page (when manual On-page preload action triggers)
    // 1. Some wp security plugins or manual security implementation on server side can block recursive wget requests so we use custom user-agent and robots=off to prevent this as much as possible.
    // 2. Also to prevent cache preloading interrupts as much as possible, increasing UX on different wordpress installs/env. (servers that are often misconfigured, leading to certificate issues),
    //    speeding up cache preloading via reducing latency we use --no-check-certificate .
    //    Requests comes from our local network/server where wordpress website hosted since it minimizes the risk of a MITM security vulnerability.
    // 3. --recursive removed here that we need single URL request
    // 4. --wait removed we need single HTTP request
    // 5. --reject-regex removed that preload URL already verified
    // 6. --reject removed that we don't use --recursive
    $command = "nohup wget --quiet --no-cache --no-cookies --no-directories --delete-after " .
                "--no-dns-cache --no-check-certificate --no-use-server-timestamps --no-if-modified-since " .
                "--ignore-length --timeout=5 --tries=1 -e robots=off " .
                "-P \"$tmp_path\" " .
                "--limit-rate=\"$nginx_cache_limit_rate\"k " .
                "--user-agent='\"". NPPP_USER_AGENT ."\"' " .
                "\"$current_page_url\" >/dev/null 2>&1 & echo \$!";
    $output = shell_exec($command);

    // Get the process ID
    if ($output !== null) {
        $parts = explode(" ", $output);
        $pid = end($parts);

        // Check if the process is still running
        $isRunning = posix_kill($pid, 0);

        // let's continue if process still alive
        if ($isRunning) {
            // Write process ID to file
            nppp_perform_file_operation($PIDFILE, 'write', $pid);
            $default_success_message = "SUCCESS ADMIN: Cache preloading has started in the background for page $current_page_url";
            nppp_display_admin_notice('success', $default_success_message);
        } else {
            nppp_display_admin_notice('error', "ERROR COMMAND: Cannot start FastCGI cache preload for page $current_page_url. Please report this issue on the plugin support page.");
        }
    } else {
        nppp_display_admin_notice('error', "ERROR COMMAND: Cannot start FastCGI cache preload for page $current_page_url. Please report this issue on the plugin support page.");
    }
}

// Only triggers conditionally if Auto Purge & Auto Preload enabled at the same time
// Only preloads cache for single post/page if Auto Purge triggered before for this modified/updated post/page
// This functions not trgiggers after On-Page purge actions
function nppp_preload_cache_on_update($current_page_url, $found = false) {
    $wp_filesystem = nppp_initialize_wp_filesystem();

    if ($wp_filesystem === false) {
        nppp_display_admin_notice(
            'error',
            'Failed to initialize the WordPress filesystem. Please file a bug on the plugin support page.'
        );
        return;
    }

    // Get the plugin options
    $nginx_cache_settings = get_option('nginx_cache_settings');

    // Set default options to prevent any error
    $default_cache_path = '/dev/shm/change-me-now';
    $default_limit_rate = 1024;

    // Get the necessary data for preload action from plugin options
    $nginx_cache_path = isset($nginx_cache_settings['nginx_cache_path']) ? $nginx_cache_settings['nginx_cache_path'] : $default_cache_path;
    $nginx_cache_limit_rate = isset($nginx_cache_settings['nginx_cache_limit_rate']) ? $nginx_cache_settings['nginx_cache_limit_rate'] : $default_limit_rate;

    // Extra data for preload action
    $this_script_path = dirname(plugin_dir_path(__FILE__));
    $PIDFILE = rtrim($this_script_path, '/') . '/cache_preload.pid';
    $tmp_path = rtrim($nginx_cache_path, '/') . "/tmp";

    // Here we already purged cache successfully and we did not face any permission issue
    // So we don't need to check any permission issues again.
    // Also all url valitadation the sanitization actions have been taken before in purge cache step
    // So we don't need to valitade the sanitize url again here.
    // Also we are sure that there is no any active ongoing preload process here that we checked in purge cache step
    // So we don't need to check any on going active preload process here.

    // We just need to create a PIDFILE if it does not exist yet
    if (!$wp_filesystem->exists($PIDFILE)) {
        if (!nppp_perform_file_operation($PIDFILE, 'create')) {
            nppp_display_admin_notice('error', 'FATAL ERROR: Failed to create PID file.');
            return;
        }
    }

    // Start cache preloading for single post/page (when Auto Purge & Auto Preload enabled both)
    // 1. Some wp security plugins or manual security implementation on server side can block recursive wget requests so we use custom user-agent and robots=off to prevent this as much as possible.
    // 2. Also to prevent cache preloading interrupts as much as possible, increasing UX on different wordpress installs/env. (servers that are often misconfigured, leading to certificate issues),
    //    speeding up cache preloading via reducing latency we use --no-check-certificate .
    //    Requests comes from our local network/server where wordpress website hosted since it minimizes the risk of a MITM security vulnerability.
    // 3. --recursive removed here that we need single URL request
    // 4. --wait removed we need single HTTP request
    // 5. --reject-regex removed that preload URL already verified
    // 6. --reject removed that we don't use --recursive
    $command = "nohup wget --quiet --no-cache --no-cookies --no-directories --delete-after " .
                "--no-dns-cache --no-check-certificate --no-use-server-timestamps --no-if-modified-since " .
                "--ignore-length --timeout=5 --tries=1 -e robots=off " .
                "-P \"$tmp_path\" " .
                "--limit-rate=\"$nginx_cache_limit_rate\"k " .
                "--user-agent='\"". NPPP_USER_AGENT ."\"' " .
                "\"$current_page_url\" >/dev/null 2>&1 & echo \$!";
    $output = shell_exec($command);

    // Get the process ID
    if ($output !== null) {
        $parts = explode(" ", $output);
        $pid = end($parts);

        // Check if the process is still running
        $isRunning = posix_kill($pid, 0);

        // Check if the process is still alive
        if ($isRunning) {
            // Write process ID to file
            nppp_perform_file_operation($PIDFILE, 'write', $pid);

            // Determine the success message based on auto purge status
            if ($found) {
                $success_message = "SUCCESS ADMIN: Auto purge cache completed, Auto preload started for page $current_page_url";
            } else {
                $success_message = "SUCCESS ADMIN: Auto purge cache attempted but page not found in cache, Auto preload started for page $current_page_url";
            }

            // Display the success message
            nppp_display_admin_notice('success', $success_message);
        } else {
            // Determine the error message based on auto purge status
            if ($found) {
                $error_message = "ERROR COMMAND: Auto purge cache completed, but unable to start Auto preload for $current_page_url. Please report this issue on the plugin support page.";
            } else {
                $error_message = "ERROR COMMAND: Auto purge cache attempted but page not found in cache, unable to start Auto preload. Please report this issue on the plugin support page.";
            }

            // Display the error message
            nppp_display_admin_notice('error', $error_message);
        }
    } else {
        // Determine the error message based on auto purge status
        if ($found) {
            $error_message = "ERROR COMMAND: Auto purge cache completed, but unable to start Auto preload for $current_page_url. Please report this issue on the plugin support page.";
        } else {
            $error_message = "ERROR COMMAND: Auto purge cache attempted but page not found in cache, unable to start Auto preload. Please report this issue on the plugin support page.";
        }

        // Display the error message
        nppp_display_admin_notice('error', $error_message);
    }
}
