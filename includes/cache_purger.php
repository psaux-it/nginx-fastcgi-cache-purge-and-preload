<?php
/*
Description: Automatically purge  Nginx cache
Author: Hasan ÇALIŞIR | hasan.calisir@psauxit.com
Author URI: https://www.psauxit.com
License: GPL2
*/

// FastCGI Cache path
$fpath = "/home/websiteuser1.com/fastcgi-cache";

// Function to find process IDs related to the preload process
function find_pid() {
    global $PIDS;
    $PIDS = array();

    exec("pgrep -a -f 'wget.*-q -m -p -E -k -P' .dirname(__FILE__).'/preload.php' | grep -v 'cpulimit' | awk '{print $1}'", $output);

    foreach ($output as $pid) {
        $PIDS[] = $pid;
    }
}

// Function to purge cache & obsolete website content before preload
function purge_helper() {
    global $fpath;

    // Check if the cache directory exists
    if (is_dir($fpath)) {
        // Remove all files and subdirectories
        $files = glob($fpath . "/*");
        foreach ($files as $file) {
            if (is_dir($file)) {
                // Remove directory recursively
                array_map('unlink', glob("$file/*.*"));
                rmdir($file);
            } else {
                unlink($file);
            }
        }

        // Check if www.fdomain directory exists and remove it
        $www_domain_dir = dirname(__FILE__) . "/www." . strstr(basename(__FILE__), ".", true);
        if (is_dir($www_domain_dir)) {
            array_map('unlink', glob("$www_domain_dir/*.*"));
            rmdir($www_domain_dir);
        }

        return 0; // Success
    } else {
        return 2; // Error: Cache directory not found
    }
}

// Function to display admin notices
function display_admin_notice($message, $type = 'info') {
    $types = array('info', 'success', 'error');
    if (!in_array($type, $types)) {
        $type = 'info'; // Default to info if invalid type
    }
    $class = ($type === 'error') ? 'error' : 'updated';
    echo "<div class='$class notice'><p>$message</p></div>";
}

// Function to purge FastCGI cache
function purge() {
    global $fpath;

    // Call find_pid() to find preload process IDs
    find_pid();

    // Stop ongoing preload process if exist
    global $PIDS;
    if (!empty($PIDS)) {
        foreach ($PIDS as $pid) {
            // Check if the process is running
            $output = array();
            exec("ps -p $pid", $output);
            if (!empty($output)) {
                // If running, kill the process
                exec("kill -9 $pid");
            }
        }

        // Remove PID file if exists
        $pidfile = dirname(__FILE__) . "/fastcgi_ops_" . substr(strstr(basename(__FILE__), "."), 1) . ".pid";
        if (file_exists($pidfile)) {
            unlink($pidfile);
        }

        // Call purge_helper() to purge cache
        $status = purge_helper();

        // Check the status returned by purge_helper()
        if ($status === 0) {
            display_admin_notice("FastCGI cache preloading is stopped. Purge FastCGI cache is completed.", 'success');
        } elseif ($status === 1) {
            display_admin_notice("ERROR PERMISSION: FastCGI cache preloading is stopped but Purge FastCGI cache cannot be completed. Please restart wp-fcgi-notify.service", 'error');
            exit(1);
        } elseif ($status === 2) {
            display_admin_notice("ERROR PATH: Your FastCGI cache PATH ($fpath) not found. To fix it -- 1) Check plugin settings  2) Check nginx config settings and restart nginx.service 3) Restart wp-fcgi-notify.service", 'error');
            exit(1);
        } else {
            display_admin_notice("ERROR UNKNOWN: Cannot Purge FastCGI cache.", 'error');
            exit(1);
        }
    } elseif (purge_helper()) {
        display_admin_notice("Purge FastCGI cache is completed.", 'success');
    } else {
        $status = purge_helper();
        if ($status === 1) {
            display_admin_notice("ERROR PERMISSION: Purge FastCGI cache cannot be completed. Please restart wp-fcgi-notify.service", 'error');
            exit(1);
        } elseif ($status === 2) {
            display_admin_notice("ERROR PATH: Your FastCGI cache PATH ($fpath) not found. To fix it -- 1) Check plugin settings  2) Check nginx config settings and restart nginx.service 3) Restart wp-fcgi-notify.service", 'error');
            exit(1);
        } else {
            display_admin_notice("ERROR UNKNOWN: Cannot Purge FastCGI cache.", 'error');
            exit(1);
        }
    }
}

// Call the purge function
// purge();
