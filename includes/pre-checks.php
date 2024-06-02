<?php
/**
 * Pre-checks for FastCGI Cache Purge and Preload for Nginx
 * Description: This pre-check file contains several critical checks for FastCGI Cache Purge and Preload for Nginx
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

// Function to check if plugin critical requirements are met
function nppp_pre_checks_critical() {
    // Check if the operating system is Linux and the web server is nginx
    if (PHP_OS !== 'Linux') {
        return 'GLOBAL ERROR OPT: Plugin is not functional on your environment. The plugin requires Linux operating system.';
    }
    if (strpos($_SERVER['SERVER_SOFTWARE'], 'nginx') === false) {
        return 'GLOBAL ERROR SERVER: Plugin is not functional on your environment. The plugin requires Nginx web server.';
    }

    // Check if shell_exec is enabled
    if (function_exists('shell_exec')) {
	    // Attempt to execute a harmless command
        $output = shell_exec('echo "Test"');
		if ($output !== "Test\n") {
            return 'GLOBAL ERROR SHELL: Plugin is not functional on your environment. The function php shell_exec() is restricted. Please check your server php settings.';
        }
    } else {
        return 'GLOBAL ERROR SHELL: Plugin is not functional on your environment. The function shell_exec() is not enabled. Please check your server php settings.';
    }

    // Check if wget is available
    $output = shell_exec('type wget');
    if (empty($output)) {
        return 'GLOBAL ERROR COMMAND: wget is not available. Please ensure "wget" is installed on your server. Preload action is not functional on your environment.';
    }

    // All requirements met
    return true;
}

// Pre-checks and global warnings
function nppp_pre_checks() {
    $wp_filesystem = nppp_initialize_wp_filesystem();

    if ($wp_filesystem === false) {
        return false;
    }

    $nginx_cache_settings = get_option('nginx_cache_settings');
    $default_cache_path = '/dev/shm/change-me-now';
    $nginx_cache_path = isset($nginx_cache_settings['nginx_cache_path']) ? $nginx_cache_settings['nginx_cache_path'] : $default_cache_path;

    // Check if plugin critical requirements are met
    $requirements_met = nppp_pre_checks_critical();
    if ($requirements_met !== true) {
        // Plugin requirements are not met
        nppp_display_pre_check_warning($requirements_met);
        return;
    }

    // Check if directory exists
    if (!$wp_filesystem->is_dir($nginx_cache_path)) {
        // Display error message for non-existent directory
        nppp_display_pre_check_warning('GLOBAL ERROR PATH: The specified Nginx Cache Directory is default one or does not exist anymore. Please check your Nginx Cache Directory.');
        return;
    }

    // quick check for permisson issue
    if (!$wp_filesystem->is_readable($nginx_cache_path) || !$wp_filesystem->is_writable($nginx_cache_path)) {
        nppp_display_pre_check_warning('GLOBAL ERROR PERMISSION: Insufficient permissions. Refer to Help for guidance!');
        return;
        // recusive check  for permission issues
    } elseif (!nppp_check_permissions_recursive($nginx_cache_path)) {
        nppp_display_pre_check_warning('GLOBAL ERROR PERMISSION: Insufficient permissions. Refer to Help for guidance!');
        return;
    }

    // Check cache is empty wanring
    $files = $wp_filesystem->dirlist($nginx_cache_path);
    if (empty($files)) {
        nppp_display_pre_check_warning('GLOBAL WARNING CACHE: Cache is empty. For immediate cache creation consider utilizing the preload action now!');
        return;
    }
}

// Handle pre check messages
function nppp_display_pre_check_warning($error_message = '') {
    if (!empty($error_message)) {
        add_action('admin_notices', function() use ($error_message) {
            ?>
            <div class="notice notice-error is-dismissible">
                <p><?php echo esc_html($error_message); ?></p>
            </div>
            <?php
        });
    }
}
