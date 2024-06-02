<?php
/**
 * Preload action functions for FastCGI Cache Purge and Preload for Nginx
 * Description: This file contains preload action functions for FastCGI Cache Purge and Preload for Nginx
 * Version: 2.0.1
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
        return false;
    }

    // Check if there is an ongoing preload process active
    if ($wp_filesystem->exists($PIDFILE)) {
        $pid = intval(nppp_perform_file_operation($PIDFILE, 'read'));

        if ($pid > 0 && posix_kill($pid, 0)) {
            nppp_display_admin_notice('info', 'INFO: FastCGI cache preloading is already running. If you want to stop it please use Purge All!');
            return;
        }
    }

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
                    nppp_display_admin_notice('error', 'FATAL PERMISSION ERROR: Failed to create PID file.');
                    exit(1);
                }
            }

            // Check cpulimit command exist
            $cpulimitPath = shell_exec('type cpulimit');

            if (!empty(trim($cpulimitPath))) {
                $cpulimit = 1;
            } else {
                $cpulimit = 0;
            }

            // Start cache preloading
            $command = "wget --limit-rate=\"$nginx_cache_limit_rate\"k -q -m -p -E -k -P \"$tmp_path\" --no-cookies --reject-regex '\"$nginx_cache_reject_regex\"' --no-use-server-timestamps --wait=1 --timeout=5 --tries=1 -e robots=off \"$fdomain\" >/dev/null 2>&1 & echo \$!";
            $output = shell_exec($command);

            // Write PID to file
            if ($output !== null) {
                $parts = explode(" ", $output);
                $pid = end($parts);

                // Sleep for 2 seconds to check background process status again
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
                        $command = "cpulimit -p \"$pid\" -l \"$nginx_cache_cpu_limit\" >/dev/null 2>&1 &";
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
                nppp_display_admin_notice('error', 'ERROR COMMAND: Cannot start FastCGI cache preload! Please file a bug on plugin support page.');
            }
        } elseif ($status === 1) {
            nppp_display_admin_notice('error', 'ERROR PERMISSION: Cannot purge FastCGI cache to start cache preloading. Please read help section of the plugin.');
        } elseif ($status === 3) {
            nppp_display_admin_notice('error', 'ERROR PATH: Your FastCGI cache PATH (' . $nginx_cache_path . ') not found. Please check your FastCGI cache path.');
        } else {
            nppp_display_admin_notice('error', 'ERROR UNKNOWN: An unexpected error occurred while preloading the FastCGI cache. Please file a bug on plugin support page.');
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
                nppp_display_admin_notice('error', 'FATAL PERMISSION ERROR: Failed to create PID file.');
                exit(1);
            }
        }

        // Check cpulimit command exist
        $cpulimitPath = shell_exec('type cpulimit');
        if (!empty(trim($cpulimitPath))) {
            $cpulimit = 1;
        } else {
            $cpulimit = 0;
        }

        // Start cache preloading
        $command = "wget --limit-rate=\"$nginx_cache_limit_rate\"k -q -m -p -E -k -P \"$tmp_path\" --no-cookies --reject-regex '\"$nginx_cache_reject_regex\"' --no-use-server-timestamps --wait=1 --timeout=5 --tries=1 -e robots=off \"$fdomain\" >/dev/null 2>&1 & echo \$!";
        $output = shell_exec($command);

        // Write PID to file
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
                    $command = "cpulimit -p \"$pid\" -l \"$nginx_cache_cpu_limit\" >/dev/null 2>&1 &";
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
            nppp_display_admin_notice('error', 'ERROR CRITICAL: Cannot start FastCGI cache preload! Please file a bug on plugin support page.');
        }
    }
}

// single page preload
function nppp_preload_single($current_page_url, $PIDFILE, $tmp_path, $nginx_cache_reject_regex, $nginx_cache_limit_rate, $nginx_cache_cpu_limit, $nginx_cache_path) {
    $wp_filesystem = nppp_initialize_wp_filesystem();

    if ($wp_filesystem === false) {
        return false;
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
    $referrer_parsed_url = parse_url($current_page_url);
    $home_url = home_url();
    $parsed_home_url = parse_url($home_url);

    if ($referrer_parsed_url['host'] !== $parsed_home_url['host']) {
        nppp_display_admin_notice('error', "ERROR URL: HTTP_REFERRER URL is not from the allowed domain.");
        return;
    }

    // Start cache preloading
    $command = "wget --limit-rate=\"$nginx_cache_limit_rate\"k -q -p -E -k -P \"$tmp_path\" --no-cookies --reject-regex '\"$nginx_cache_reject_regex\"' --no-use-server-timestamps --timeout=5 --tries=1 -e robots=off \"$current_page_url\" >/dev/null 2>&1 & echo \$!";
    $output = shell_exec($command);

    // Write PID to file
    if ($output !== null) {
        $parts = explode(" ", $output);
        $pid = end($parts);

        // Check if the process is still running
        $isRunning = posix_kill($pid, 0);

        // let's continue if process still alive after two sec
        if ($isRunning) {
            nppp_perform_file_operation($PIDFILE, 'write', $pid);
            $default_success_message = "SUCCESS ADMIN: Cache preloading has started in the background for page $current_page_url";
            nppp_display_admin_notice('success', $default_success_message);
        } else {
            nppp_display_admin_notice('error', "ERROR COMMAND: Cannot start FastCGI cache preload for page $current_page_url, Please file a bug on plugin support page.");
        }
    } else {
        nppp_display_admin_notice('error', "ERROR COMMAND: Cannot start FastCGI cache preload for page $current_page_url, Please file a bug on plugin support page.");
    }
}
