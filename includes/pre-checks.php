<?php
/**
 * Pre-checks for Nginx FastCGI Cache Purge and Preload
 * Description: This pre-check file contains several checks for FastCGI Cache Purge and Preload plugin.
 * Version: 1.0.3
 * Author: Hasan ÇALIŞIR
 * Author Email: hasan.calisir@psauxit.com
 * Author URI: https://www.psauxit.com
 * License: GPL-2.0+
 */

// Check if wget is available and handle preload button
function check_wget_availability() {
    $output = shell_exec('type wget');
    if (empty($output)) {
        // Wget is not available
        add_action('admin_notices', 'display_wget_warning');
        wp_enqueue_script('preload-button-disable', plugins_url('assets/js/preload-button-disable.js', __FILE__), array('jquery'), '1.0.3', true);
    } else {
        // Wget is available, dequeue the preload-button-disable.js if it's already enqueued
        wp_dequeue_script('preload-button-disable');
    }
}

// Check ACL properly configured for purge operations
// Check the cache path is exist or not
function pre_checks($directory_exists_flag = false, $empty_directory_flag = false) {
    $wp_filesystem = initialize_wp_filesystem();

    if ($wp_filesystem === false) {
        return false; // Return false if WP_Filesystem initialization failed
    }

    $nginx_cache_settings = get_option('nginx_cache_settings');
    $default_cache_path = find_user_home_folder() . '/change-me-nginx';
    $nginx_cache_path = isset($nginx_cache_settings['nginx_cache_path']) ? $nginx_cache_settings['nginx_cache_path'] : $default_cache_path;

    // Check if directory exists
    if (!$wp_filesystem->is_dir($nginx_cache_path)) {
        // Directory does not exist
        if (!$directory_exists_flag) {
            $directory_exists_flag = true;
        }
        if ($directory_exists_flag) {
            // Display different message for non-existent directory
            display_pre_check_warning(true, false, false);
        }
        return; // No need to continue further checks
    }

    // Check if main directory has ACL permissions
    $output = shell_exec("ls -ld \"$nginx_cache_path\" | awk '{print \$1}' | grep '+'");
    if ($output === null) {
        // Main directory does not have ACL permissions
        display_pre_check_warning(false, false, false);
        return;
    }

    // Check if directory is empty
    $files = $wp_filesystem->dirlist($nginx_cache_path);
    if (empty($files)) {
        // Directory is empty, but main directory has ACL permissions
        if (!$empty_directory_flag) {
            $empty_directory_flag = true;
        }
        if ($empty_directory_flag) {
            display_pre_check_warning(false, true, true);
            return;
        }
    }

    // Check ACL permissions for each file in the directory
    $output = shell_exec("find \"$nginx_cache_path\" -exec ls -ld {} + | awk '{print \$1}' | grep -v '+'");
    if (!empty($output)) {
        display_pre_check_warning(false, false, false);
        return;
    }
}

// Handle pre check messages
function display_pre_check_warning($directory_exists_flag = false, $empty_directory_flag = false, $empty_directory_warning = false) {
    // Verify nonce
    if (isset($_GET['_wpnonce']) && check_admin_referer('nginx_cache_settings_nonce', '_wpnonce')) {
        $current_page = isset($_GET['page']) ? $_GET['page'] : '';
        if ($current_page === 'nginx_cache_settings') {
            ?>
            <div class="notice <?php echo $empty_directory_warning ? 'notice-warning' : 'notice-error'; ?>">
                <p><?php
                    if ($directory_exists_flag) {
                        esc_html_e('ERROR PATH: The specified Nginx Cache Directory does not exist.');
                    } elseif ($empty_directory_flag) {
                        esc_html_e('WARNING PERMISSION: Nginx cache directory is empty. Please reload the WordPress site to confirm ACL status is OK.');
                    } else {
                        esc_html_e('ERROR PERMISSION: Purge action will fail due to ACL permission constraints. Apply ACLs to Nginx Cache Folder for PHP-FPM user access. Refer to the plugins help section for assistance!');
                    }
                ?></p>
            </div>
            <?php
        }
    } else {
        echo '<div class="error"><p>Nonce verification failed.</p></div>';
    }
}

// Display wget warning message only on the plugin settings page
function display_wget_warning() {
    // Verify nonce
    if (isset($_GET['_wpnonce']) && check_admin_referer('nginx_cache_settings_nonce', '_wpnonce')) {
        $current_page = isset($_GET['page']) ? $_GET['page'] : '';
        if ($current_page === 'nginx_cache_settings') {
            ?>
            <div class="notice notice-error">
                <p><?php esc_html_e('ERROR COMMAND: Preload action disabled! The "wget" command is not available on your system. Please make sure "wget" is installed to use Nginx Cache Preload feature.', 'textdomain'); ?></p>
            </div>
            <?php
        }
    } else {
        echo '<div class="error"><p>Nonce verification failed.</p></div>';
    }
}
