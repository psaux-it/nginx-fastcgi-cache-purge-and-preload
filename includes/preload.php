<?php
/**
 * Preload action functions for Nginx FastCGI Cache Purge and Preload
 * Description: This file contains Preload action function for Nginx FastCGI Cache Purge and Preload.
 * Version: 1.0.3
 * Author: Hasan ÇALIŞIR
 * Author Email: hasan.calisir@psauxit.com
 * Author URI: https://www.psauxit.com
 * License: GPL-2.0+
 */

// Preload operation
function preload($nginx_cache_path, $this_script_path, $fdomain, $PIDFILE, $nginx_cache_reject_regex, $nginx_cache_limit_rate, $nginx_cache_cpu_limit) {
    // Check if there is an ongoing preload process active
    if (file_exists($PIDFILE)) {
        $pid = intval(perform_file_operation($PIDFILE, 'read'));

        if ($pid > 0 && posix_kill($pid, 0)) {
            display_admin_notice('info', 'INFO: FastCGI cache preloading is already running. If you want to stop it please Purge Cache now!');
            return;
        }
    }

    // Purge cache and get status
    $status = purge_helper($nginx_cache_path);

    // Handle different status codes
    if ($status === 0 || $status === 2) {
        // Create PID file
        if (!perform_file_operation($PIDFILE, 'create')) {
            display_admin_notice('error', 'FATAL PERMISSION ERROR: Failed to create PID file.');
            exit(1);
        }

        // Check cpulimit command exist
        $cpulimitPath = shell_exec('type cpulimit');

        if (!empty(trim($cpulimitPath))) {
            $cpulimit = 1;
        } else {
            $cpulimit = 0;
        }

        // Keep absolute download content in /tmp
        $tmp_path = rtrim($this_script_path, '/') . "/tmp";

        // Start cache preloading
        $command = "wget --limit-rate=\"$nginx_cache_limit_rate\"k -q -m -p -E -k -P \"$tmp_path\" --no-cookies --reject-regex '\"$nginx_cache_reject_regex\"' \"$fdomain\" >/dev/null 2>&1 & echo \$!";
        $output = shell_exec($command);

        // Write PID to PID file
        if ($output !== null) {
            $pid = trim($output);
            perform_file_operation($PIDFILE, 'write', $pid);
            // Start cpulimit if it is exist
            if ($cpulimit === 1) {
                $command = "cpulimit -p \"$pid\" -l \"$nginx_cache_cpu_limit\" >/dev/null 2>&1 &";
                shell_exec($command);
            }
        } else {
            display_admin_notice('error', 'ERROR CRITICAL: Cannot start FastCGI cache preload! Please file a bug on plugin support page.');
        }
    } elseif ($status === 1) {
        display_admin_notice('error', 'ERROR PERMISSION: Cannot Purge FastCGI cache to start Cache Preloading. Please read help section of the plugin.');
    } elseif ($status === 3) {
        display_admin_notice('error', 'ERROR PATH: Your FastCGI cache PATH (' . $nginx_cache_path . ') not found. Please check your FastCGI cache path.');
    } else {
        display_admin_notice('error', 'ERROR UNKNOWN: An unexpected error occurred while preloading the FastCGI cache. Please file a bug.');
    }
}
