<?php

// Define fastcgi_ops.sh script path
// Must be owned by instance's PHP-FPM user (websiteuser)
//////////////////////////////////////////////////////////////
$fastcgi_script_path = '/home/newwebsite1/scripts/fastcgi_ops.sh';
//////////////////////////////////////////////////////////////

// Add buttons to WordPress admin bar
function add_fastcgi_cache_buttons_admin_bar($wp_admin_bar) {
    // Check if the user has permissions to manage options
    if (!current_user_can('manage_options')) {
        return;
    }

    // Add a parent menu item for FastCGI cache operations
    $wp_admin_bar->add_menu(array(
        'id' => 'fastcgi-cache-operations',
        'title' => 'FastCGI Cache Operations',
        'href' => '#',
    ));

    // Add child menu items for purge and preload operations
    $wp_admin_bar->add_menu(array(
        'parent' => 'fastcgi-cache-operations',
        'id' => 'purge-cache',
        'title' => 'FCGI Cache Purge',
        'href' => admin_url('?purge_cache=true'),
    ));

    $wp_admin_bar->add_menu(array(
        'parent' => 'fastcgi-cache-operations',
        'id' => 'preload-cache',
        'title' => 'FCGI Cache Preload',
        'href' => admin_url('?preload_cache=true'),
    ));
}
add_action('admin_bar_menu', 'add_fastcgi_cache_buttons_admin_bar', 100);

// Handle button clicks
function handle_fastcgi_cache_actions_admin_bar() {
    global $fastcgi_script_path;
    // Check if the buttons are clicked
    if (isset($_GET['purge_cache']) || isset($_GET['preload_cache'])) {
        // Check if the bash script path is set
        if (empty($fastcgi_script_path)) {
            display_admin_notice('error', 'FastCGI operations script path is not configured in functions.php!');
            return;
        }
        // Check if the bash script exists
        if (!file_exists($fastcgi_script_path)) {
            display_admin_notice('error', 'FastCGI operations script not found in the path!');
            return;
        }

        // Determine action based on button click
        $action = isset($_GET['purge_cache']) ? '--purge' : '--preload';

        // Call the bash script with the determined action
        $output = shell_exec($fastcgi_script_path . ' ' . escapeshellarg($action));

        // Remove timestamp from output
        $output = preg_replace('/^\[\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\]/', '', $output);

        // Determine notice type based on the presence of error keywords
        $notice_type = stripos($output, 'ERROR') !== false ? 'error' : 'success';

        // Display admin notice
        display_admin_notice($notice_type, $output);
    }
}
add_action('admin_init', 'handle_fastcgi_cache_actions_admin_bar');

// Display admin notices
function display_admin_notice($type, $message) {
    echo '<div class="notice notice-' . $type . '"><p>' . esc_html($message) . '</p></div>';
}

// Function to check preload process status
function check_processes_status() {
    global $fastcgi_script_path;
    // Check if the bash script path is not empty
    if (empty($fastcgi_script_path)) {
        return;
    }

    // Extract directory from the script path
    $script_directory = dirname($fastcgi_script_path);

    // Find the PID file with the name 'fastcgi_ops_*' in the script directory
    $pid_files = glob("$script_directory/fastcgi_ops_*.pid");

    // Check the variable not empty
    if (!empty($pid_files)) {
        // Get the first PID file
        $pid_file = $pid_files[0];

        // Check if the PID file exists
        if (file_exists($pid_file)) {
            // Read the PID file
            $pids = file($pid_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

            // Check if the process is running
            $process_running = posix_kill($pids[0], 0);

            // If the process is running, cache preload in progress
            if ($process_running) {
                display_admin_notice('info', 'FastCGI cache preload is in progress...');
                return;
            } else {
                // Delete the PID and prevent further false admin messages
                unlink($pid_file);

                // Finally cache preload is completed
                display_admin_notice('success', 'FastCGI cache preload is completed!');
            }
        }
    }
}
add_action('admin_init', 'check_processes_status');
