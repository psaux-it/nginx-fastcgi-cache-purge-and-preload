<?php
/**
 * Plugin Name:       Nginx FastCGI Cache Purge and Preload
 * Plugin URI:        https://wordpress.org/plugins/nginx-fastcgi-cache-purge-and-preload/
 * Description:       Manage Nginx FastCGI Cache Purge and Preload operations directly from your WordPress admin dashboard.
 * Version:           1.0.2
 * Author:            Hasan ÇALIŞIR
 * Author URI:        https://www.psauxit.com/
 * Author Email:      hasan.calisir@psauxit.com
 * License:           GPL-2.0+
 * License URI:       http://www.gnu.org/licenses/gpl-2.0.txt
 * Text Domain:       nginx-fastcgi-cache-purge-and-preload
 * Requires at least: 6.3
 * Requires PHP:      7.4
 */

// Define a constant for the log file path
define('NGINX_CACHE_LOG_FILE', plugin_dir_path(__FILE__) . 'fastcgi_ops.log');

// Settings page tabs
require_once plugin_dir_path( __FILE__ ) . 'includes/helper.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/status.php';

// Register activation and deactivation hooks
register_activation_hook(__FILE__, 'defaults_on_plugin_activation');
register_deactivation_hook(__FILE__, 'reset_plugin_settings_on_deactivation');

// Add actions and filters
add_action('admin_bar_menu', 'add_fastcgi_cache_buttons_admin_bar', 100);
add_action('admin_init', 'handle_fastcgi_cache_actions_admin_bar');
add_action('admin_init', 'check_processes_status');
add_action('admin_enqueue_scripts', 'enqueue_nginx_fastcgi_cache_purge_preload_assets');
add_action('admin_init', 'nginx_cache_settings_init');
add_action('admin_menu', 'add_nginx_cache_settings_page');
add_filter('whitelist_options', 'add_nginx_cache_settings_to_allowed_options');
add_action('load-settings_page_nginx_cache_settings', 'pre_checks');
add_action('load-settings_page_nginx_cache_settings', 'check_wget_availability');
add_action('wp_ajax_clear_nginx_cache_logs', 'clear_nginx_cache_logs');
add_action('wp_ajax_update_send_mail_option', 'update_send_mail_option');

// Add buttons to WordPress admin bar
function add_fastcgi_cache_buttons_admin_bar($wp_admin_bar) {
    // Check if the user has permissions to manage options
    if (!current_user_can('manage_options')) {
        return;
    }

    // Add a parent menu item for FastCGI cache operations
    $wp_admin_bar->add_menu(array(
        'id' => 'fastcgi-cache-operations',
        'title' => '<img style="height: 20px; margin-bottom: -4px; padding-right: 3px;" src="' . plugin_dir_url(__FILE__) . 'assets/img/bar.png" alt="NPP" title="NPP"> FastCGI Cache',
        'href' => '#',
        'meta' => array(
            'class' => 'npp-icon',
        ),
    ));

    // Add child menu items for purge and preload operations with nonces
    $purge_nonce = wp_create_nonce('purge_cache_nonce');
    $preload_nonce = wp_create_nonce('preload_cache_nonce');
    $settings_nonce = wp_create_nonce('nginx_cache_settings_nonce');

    // Add child menu items for purge and preload operations
    $wp_admin_bar->add_menu(array(
        'parent' => 'fastcgi-cache-operations',
        'id' => 'purge-cache',
        'title' => 'FCGI Cache Purge',
        'href' => add_query_arg('purge_cache', 'true', admin_url()) . '&_wpnonce=' . $purge_nonce,
    ));

    $wp_admin_bar->add_menu(array(
        'parent' => 'fastcgi-cache-operations',
        'id' => 'preload-cache',
        'title' => 'FCGI Cache Preload',
        'href' => add_query_arg('preload_cache', 'true', admin_url()) . '&_wpnonce=' . $preload_nonce,
    ));

    // Add settings submenu
    $wp_admin_bar->add_menu(array(
        'parent' => 'fastcgi-cache-operations',
        'id' => 'fastcgi-cache-settings',
        'title' => 'Settings',
        'href' => add_query_arg('_wpnonce', $settings_nonce, admin_url('options-general.php?page=nginx_cache_settings')),
    ));
}

// Check if wget is available and handle preload button
function check_wget_availability() {
    $output = shell_exec('type wget');
    if (empty($output)) {
        // Wget is not available
        add_action('admin_notices', 'display_wget_warning');
        wp_enqueue_script('preload-button-disable', plugins_url('assets/js/preload-button-disable.js', __FILE__), array('jquery'), '1.0.2', true);
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

// Handle button clicks
function handle_fastcgi_cache_actions_admin_bar() {
    // Check if the buttons are clicked and nonce is valid
    if ((isset($_GET['purge_cache']) && check_admin_referer('purge_cache_nonce')) ||
        (isset($_GET['preload_cache']) && check_admin_referer('preload_cache_nonce'))) {

        // Determine action based on button click
        $action = isset($_GET['purge_cache']) ? 'purge' : 'preload';

        // Necessary data for purge and preload actions
        $nginx_cache_settings = get_option('nginx_cache_settings');

        $default_cache_path = find_user_home_folder() . '/change-me-nginx';
        $default_limit_rate = 1280;
        $default_cpu_limit = 50;
        $default_reject_regex = fetch_default_reject_regex_from_php_file();

        $nginx_cache_path = isset($nginx_cache_settings['nginx_cache_path']) ? $nginx_cache_settings['nginx_cache_path'] : $default_cache_path;
        $nginx_cache_limit_rate = isset($nginx_cache_settings['nginx_cache_limit_rate']) ? $nginx_cache_settings['nginx_cache_limit_rate'] : $default_limit_rate;
        $nginx_cache_cpu_limit = isset($nginx_cache_settings['nginx_cache_cpu_limit']) ? $nginx_cache_settings['nginx_cache_cpu_limit'] : $default_cpu_limit;
        $nginx_cache_reject_regex = isset($nginx_cache_settings['nginx_cache_reject_regex']) ? $nginx_cache_settings['nginx_cache_reject_regex'] : $default_reject_regex;

        $PIDFILE = plugin_dir_path(__FILE__) . 'cache_preload.pid';
        $fdomain = get_site_url();
        $this_script_path = plugin_dir_path(__FILE__);

        // Call the appropriate function based on the action
        if ($action === 'purge') {
            purge($nginx_cache_path, $PIDFILE);
        } elseif ($action === 'preload') {
            preload($nginx_cache_path, $this_script_path, $fdomain, $PIDFILE, $nginx_cache_reject_regex, $nginx_cache_limit_rate, $nginx_cache_cpu_limit);
        }
    }
}

// Verify WP file-system credentials and initialize WP_Filesystem
function initialize_wp_filesystem() {
    if (!function_exists('WP_Filesystem')) {
        require_once ABSPATH . 'wp-admin/includes/file.php';
    }

    // Verify WP file-system credentials.
    $verified_credentials = request_filesystem_credentials(site_url() . '/wp-admin/', '', false, false, null);

    if (is_wp_error($verified_credentials)) {
        return $verified_credentials;
    }

    // Initialize WP_Filesystem
    if (WP_Filesystem($verified_credentials)) {
        global $wp_filesystem;
        return $wp_filesystem;
    }

    return false; // Return false if initialization failed
}

// Perform file operations (delete, read, write, create, append)
function perform_file_operation($file_path, $operation, $data = null) {
    $wp_filesystem = initialize_wp_filesystem();

    if ($wp_filesystem === false) {
        return false; // Return false if WP_Filesystem initialization failed
    }

    switch ($operation) {
        case 'delete':
            return $wp_filesystem->delete($file_path);
        case 'read':
            return $wp_filesystem->get_contents($file_path);
        case 'write':
            return $wp_filesystem->put_contents($file_path, $data);
        case 'create':
            if (!$wp_filesystem->exists($file_path)) {
                $wp_filesystem->touch($file_path);
                $wp_filesystem->chmod($file_path, 0644);
                return true;
            }
            return false; // Return false if file already exists
        case 'append':
            $current_content = $wp_filesystem->get_contents($file_path);
            $updated_content = $current_content . "\n" . $data; // Append with newline
            return $wp_filesystem->put_contents($file_path, $updated_content, FS_CHMOD_FILE);
        default:
            return false; // Return false for unsupported operation
    }
}

// Purge cache with WP_Filesystem
function wp_purge($directory_path) {
    $wp_filesystem = initialize_wp_filesystem();

    if ($wp_filesystem === false) {
        return false; // Return false if WP_Filesystem initialization failed
    }

    // Check if the directory exists before attempting to remove its contents
    if ($wp_filesystem->is_dir($directory_path)) {
        // Get directory contents
        $contents = $wp_filesystem->dirlist($directory_path);

        // Delete files and directories in the directory
        foreach ($contents as $file) {
            $file_path = trailingslashit($directory_path) . $file['name'];
            if ($wp_filesystem->is_file($file_path) || $wp_filesystem->is_dir($file_path)) {
                // Attempt to delete file or directory
                $deleted = $wp_filesystem->delete($file_path, true);
                if (!$deleted) {
                    // Error occurred while deleting file or directory
                    return new WP_Error('delete_error', 'Error deleting file or directory: ' . $file_path);
                }
            }
        }

        return true; // Contents removed successfully
    } else {
        // Directory does not exist
        return new WP_Error('directory_not_found', 'Directory not found.');
    }
}

// Remove a directory using WP_Filesystem
function wp_remove_directory($directory_path, $recursive = true) {
    $wp_filesystem = initialize_wp_filesystem();

    if ($wp_filesystem === false) {
        return false; // Return false if WP_Filesystem initialization failed
    }

    // Check if the directory exists before attempting to remove it
    if ($wp_filesystem->is_dir($directory_path)) {
        // Attempt to remove the directory
        $result = $wp_filesystem->delete($directory_path, $recursive);

        if ($result === false) {
            // Error occurred while removing directory
            return new WP_Error('remove_directory_error', 'Error removing directory.');
        }

        return true; // Directory removed successfully
    } else {
        // Directory does not exist
        return new WP_Error('directory_not_found', 'Directory not found.');
    }
}

// Create log file
function create_log_file($log_file_path) {
    if (!empty($log_file_path)) {
        $file_creation_result = perform_file_operation($log_file_path, 'create');
        if (is_wp_error($file_creation_result)) {
            return "Error: " . $file_creation_result->get_error_message();
        }
        return true;
    }
    return "Log file path is empty.";
}

// Display admin notices
function display_admin_notice($type, $message) {
    echo '<div class="notice notice-' . esc_attr($type) . '"><p>' . esc_html($message) . '</p></div>';
    // Write to the log file
    $log_file_path = NGINX_CACHE_LOG_FILE;
    perform_file_operation($log_file_path, 'create');
    !empty($log_file_path) ? perform_file_operation($log_file_path, 'append', '[' . gmdate('Y-m-d H:i:s') . '] ' . $message) : die("Log file not found!");
}

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
    if ($status === 0) {
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
    } elseif ($status === 2) {
        display_admin_notice('error', 'ERROR PATH: Your FastCGI cache PATH (' . $nginx_cache_path . ') not found. Please check your FastCGI cache path.');
    } else {
        display_admin_notice('error', 'ERROR UNKNOWN: Cannot Purge FastCGI cache to start Cache Preloading.');
    }
}

// Purge cache operation helper
function purge_helper($nginx_cache_path) {
    $wp_filesystem = initialize_wp_filesystem();

    if ($wp_filesystem === false) {
        return false; // Return false if WP_Filesystem initialization failed
    }

    // Remove absolute downloaded content if exists
    $this_script_path = plugin_dir_path(__FILE__);
    $tmp_path = rtrim($this_script_path, '/') . "/tmp";

    if ($wp_filesystem->is_dir($tmp_path)) {
        wp_remove_directory($tmp_path, true);
    }

    // Check if the cache path exists and is a directory
    if ($wp_filesystem->is_dir($nginx_cache_path)) {
        // Recursively remove the cache directory contents.
        $result = wp_purge($nginx_cache_path);

        // Check cache purge status
        if (is_wp_error($result)) {
            return 1; // Permission issue
        } else {
            return 0; // Assume purge successful
        }
    } else {
        return 2; // Directory doesn't exist
    }
}

// Purge cache operation
function purge($nginx_cache_path, $PIDFILE) {
    // Initialize variables for messages
    $message_type = '';
    $message_content = '';

    // Check if the PID file exists
    if (file_exists($PIDFILE)) {
        $pid = intval(perform_file_operation($PIDFILE, 'read'));

        // Check if the preload process is alive
        if ($pid > 0 && posix_kill($pid, 0)) {
            // If process is alive, kill it
            posix_kill($pid, SIGTERM);
            usleep(50000); // Wait for 50 milliseconds

            // Call purge_helper to delete cache contents and get status
            $status = purge_helper($nginx_cache_path);

            // Determine message based on status
            switch ($status) {
                case 0:
                    $message_type = 'success';
                    $message_content = 'SUCCESS: FastCGI cache preloading is stopped. Purge FastCGI cache is completed.';
                    break;
                case 1:
                    $message_type = 'error';
                    $message_content = 'ERROR PERMISSION: FastCGI cache preloading is stopped but Purge FastCGI cache failed. Please read help section of the plugin.';
                    break;
                case 2:
                    $message_type = 'error';
                    $message_content = 'ERROR PATH: Your FastCGI cache PATH (' . $nginx_cache_path . ') not found. Please check your FastCGI cache path.';
                    break;
            }

            // Remove the PID file
            perform_file_operation($PIDFILE, 'delete');
        }
    } else {
        // Call purge_helper to delete cache contents and get status
        $status = purge_helper($nginx_cache_path);

        // Determine message based on status
        switch ($status) {
            case 0:
                $message_type = 'success';
                $message_content = 'SUCCESS: Purge FastCGI cache is completed.';
                break;
            case 1:
                $message_type = 'error';
                $message_content = 'ERROR PERMISSION: Purge FastCGI cache cannot completed. Please read help section of the plugin.';
                break;
            case 2:
                $message_type = 'error';
                $message_content = 'ERROR PATH: Your FastCGI cache PATH (' . $nginx_cache_path . ') is not found. Please check your FastCGI cache path.';
                break;
        }
    }

    // Display the admin notice
    display_admin_notice($message_type, $message_content);
}

// Function to check preload process status
function check_processes_status() {
    $PIDFILE = plugin_dir_path(__FILE__) . 'cache_preload.pid'; // Path to the PID file in the plugin directory
    // If the process is running, display admin notice for preload in progress
    if (file_exists($PIDFILE)) {
        $pid = intval(perform_file_operation($PIDFILE, 'read'));

        if ($pid > 0 && posix_kill($pid, 0)) {
            display_admin_notice('info', 'INFO: FastCGI cache preload is in progress...');
            return;
        } else {
            // Retrieve the Nginx Cache Email setting value
            $options = get_option('nginx_cache_settings');
            // Retrieve the Nginx Cache Email setting value
            $nginx_cache_email = isset($options['nginx_cache_email']) ? $options['nginx_cache_email'] : '';
            // Check if Send Mail is checked
            $send_mail = isset($options['nginx_cache_send_mail']) && $options['nginx_cache_send_mail'] === 'yes';
            // Only send if user customized email address and send mail enabled
            $default_email = 'your-email@example.com';
            if ($send_mail && !empty($nginx_cache_email) && $nginx_cache_email !== $default_email) {
                // Extract the domain from the WordPress site URL
                $site_url = get_site_url();
                $site_url_parts = wp_parse_url($site_url);
                $domain = str_replace('www.', '', $site_url_parts['host']);
                // Set mail_from address with user domain
                $mail_from = "From: Nginx FastCGI Cache Purge Preload Wordpress<fcgi-cache@$domain>";
                // Mail subject
                $mail_subject = "$domain-NGINX FastCGI Cache Preload";
                // Mail message
                $mail_message = "The NGINX FastCGI Preload operation has been completed for $domain.";
                // Send email
                wp_mail($nginx_cache_email, $mail_subject, $mail_message, $mail_from);
            }

            // Display admin notice for completed preload
            display_admin_notice('success', 'SUCCESS: FastCGI cache preload is completed!');

            // Remove absolute downloaded content
            $this_script_path = plugin_dir_path(__FILE__);
            $tmp_path = rtrim($this_script_path, '/') . "/tmp";
            wp_remove_directory($tmp_path, true);
            
            // If the process is not running, delete the PID file
            perform_file_operation($PIDFILE, 'delete');
        }
    }
}

// Enqueue custom CSS and JavaScript files
function enqueue_nginx_fastcgi_cache_purge_preload_assets() {
    // Enqueue CSS file
    wp_enqueue_style('nginx-fastcgi-cache-purge-preload', plugins_url('assets/css/nginx-fastcgi-cache-purge-preload.css', __FILE__), array(), '1.0.2');

    // Enqueue JavaScript file
    wp_enqueue_script('nginx-fastcgi-cache-admin', plugins_url('assets/js/nginx-fastcgi-cache-purge-preload.js', __FILE__), array('jquery'), '1.0.2', true);

    // Create a nonce for clearing nginx cache logs
    $clear_nginx_cache_logs_nonce = wp_create_nonce('clear-nginx-cache-logs');

    // Create a nonce for updating send mail option
    $update_send_mail_option_nonce = wp_create_nonce('update-send-mail-option');

    // Create a new nonce for the status tab AJAX function
    $status_ajax_nonce = wp_create_nonce('status_ajax_nonce');

    // Localize nonce value for JavaScript
    wp_localize_script('nginx-fastcgi-cache-admin', 'nginx_cache_ajax_object', array(
        'ajaxurl' => admin_url('admin-ajax.php'),
        'nonce' => $clear_nginx_cache_logs_nonce,
        'send_mail_nonce' => $update_send_mail_option_nonce,
        'status_ajax_nonce' => $status_ajax_nonce,
    ));
}

// Initializes the Nginx Cache settings by registering settings, adding settings section, and fields
function nginx_cache_settings_init() {
    // Register settings
    register_setting('nginx_cache_settings_group', 'nginx_cache_settings', 'nginx_cache_settings_sanitize');

    // Add settings section and fields
    add_settings_section('nginx_cache_settings_section', 'FastCGI Cache Purge & Preload Settings', 'nginx_cache_settings_section_callback', 'nginx_cache_settings_group');
    add_settings_field('nginx_cache_path', 'Nginx FastCGI Cache Path', 'nginx_cache_path_callback', 'nginx_cache_settings_group', 'nginx_cache_settings_section');
    add_settings_field('nginx_cache_email', 'Email Address', 'nginx_cache_email_callback', 'nginx_cache_settings_group', 'nginx_cache_settings_section');
    add_settings_field('nginx_cache_cpu_limit', 'CPU Usage Limit for Cache Preloading (0-100)', 'nginx_cache_cpu_limit_callback', 'nginx_cache_settings_group', 'nginx_cache_settings_section');
    add_settings_field('nginx_cache_reject_regex', 'Excluded endpoints from cache preloading', 'nginx_cache_reject_regex_callback', 'nginx_cache_settings_group', 'nginx_cache_settings_section');
    add_settings_field('nginx_cache_send_mail', 'Send Mail', 'nginx_cache_send_mail_callback', 'nginx_cache_settings_group', 'nginx_cache_settings_section');
    add_settings_field('nginx_cache_logs', 'Logs', 'nginx_cache_logs_callback', 'nginx_cache_settings_group', 'nginx_cache_settings_section');
    add_settings_field('nginx_cache_limit_rate', 'Limit Rate Definition', 'nginx_cache_limit_rate_callback', 'nginx_cache_settings_group', 'nginx_cache_settings_section');
}

// Add settings page
function add_nginx_cache_settings_page() {
    add_submenu_page(
        'options-general.php',
        'Nginx Cache Settings',
        'Nginx Cache Settings',
        'manage_options',
        'nginx_cache_settings',
        'nginx_cache_settings_page'
    );
}

// Add the option name to the allowed options list
function add_nginx_cache_settings_to_allowed_options($options) {
    $options['nginx_cache_settings'] = 'nginx_cache_settings';
    return $options;
}

// Displays the Nginx Cache Settings page in the WordPress admin dashboard
// Handles form submission, settings validation, and updating options
function nginx_cache_settings_page() {
    // Check if the form has been submitted
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit'])) {
        // Verify nonce
        if (isset($_POST['nginx_cache_settings_nonce']) && wp_verify_nonce($_POST['nginx_cache_settings_nonce'], 'nginx_cache_settings_nonce')) {
            // Sanitize and validate the submitted values
            $new_settings = nginx_cache_settings_sanitize($_POST['nginx_cache_settings']);

            // Check if there are any settings errors
            $errors = get_settings_errors('nginx_cache_settings_group');

            // If there are no sanitize errors, proceed to update the settings
            if (empty($errors)) {
                // Update the settings with the new values
                update_option('nginx_cache_settings', $new_settings);

                // Show success message
                echo '<div class="updated"><p>Settings saved successfully!</p></div>';
            } else {
                // Display settings errors
                foreach ($errors as $error) {
                    echo '<div class="error"><p>' . esc_html($error['message']) . '</p></div>';
                }
            }
        }
    }

    // Display the settings form
    ?>
    <div class="wrap">
        <h2><img src="<?php echo esc_url( plugins_url( 'assets/img/logo.png', __FILE__ ) ); ?>" alt="Logo" style="vertical-align: middle; margin-right: 10px; width: 90px;">Nginx Cache Settings</h2>
        <h2 class="nav-tab-wrapper">
            <a href="#settings" class="nav-tab nav-tab-active">Settings</a>
            <a href="#status" class="nav-tab">Status</a>
            <a href="#help" class="nav-tab">Help</a>
        </h2>

        <div id="settings" class="tab-content active">
            <form method="post" action="">
                <?php
                // Add nonce field
                wp_nonce_field('nginx_cache_settings_nonce', 'nginx_cache_settings_nonce');
                ?>
                <table class="form-table">
                    <tr valign="top">
                        <th scope="row"><span class="dashicons dashicons-admin-site"></span> Nginx Cache Directory</th>
                        <td>
                            <?php nginx_cache_path_callback(); ?>
                            <p class="description">Please specify the directory path for Nginx Cache Purge operation. Please note that erase operation is irreversible, so proceed with caution</p>
                            <p class="cache-path-plugin-note">
                                NOTE: The plugin author explicitly disclaims any liability for unintended deletions resulting from incorrect directory entries.<br>
                                Users are solely responsible for verifying the directory's accuracy prior to deletion.
                            </p>
                        </td>
                    </tr>
                    <tr valign="top">
                        <th scope="row"><span class="dashicons dashicons-email"></span> Email Address</th>
                        <td>
                            <?php nginx_cache_email_callback(); ?>
                            <p class="description">Enter an email address for Nginx cache operation's notifications.</p>
                        </td>
                    </tr>
                    <tr valign="top">
                        <th scope="row"><span class="dashicons dashicons-dashboard"></span> CPU Usage Limit (%)</th>
                        <td>
                            <?php nginx_cache_cpu_limit_callback(); ?>
                            <p class="description">Enter the CPU usage limit for preload operation. You need "cpulimit" installed on your system. (10-100%).</p>
                        </td>
                    </tr>
                    <tr valign="top">
                        <th scope="row"><span class="dashicons dashicons-no"></span> Exclude Endpoints</th>
                        <td>
                            <?php nginx_cache_reject_regex_callback(); ?>
                            <p class="description">Enter a regex pattern to exclude endpoints from being cached. Use | as a delimeter for new rules. </p>
                            <p class="description">Default regex pattern triggers caching only static pages as much as possible. </p>
                            <button type="submit" name="nginx-regex-reset-defaults" id="nginx-regex-reset-defaults" class="button nginx-reset-regex-button">Reset Defaults</button>
                        </td>
                    </tr>
                    <tr valign="top">
                         <th scope="row"><span class="dashicons dashicons-admin-generic"></span> Limit Rate</th>
                         <td>
                            <?php nginx_cache_limit_rate_callback(); ?>
                            <p class="description">Enter a limit rate for preload action in KB/Sec.</p>
                        </td>
                    </tr>
                    <tr valign="top">
                        <th scope="row"><span class="dashicons dashicons-email-alt"></span> Send Email Notification</th>
                        <td>
                            <?php nginx_cache_send_mail_callback(); ?>
                            <p class="description">Check this box to receive email notifications.</p>
                        </td>
                    </tr>
                    <tr valign="top">
                        <th scope="row"><span class="dashicons dashicons-archive"></span> Logs</th>
                        <td>
                            <?php nginx_cache_logs_callback(); ?>
                            <button id="clear-logs-button" class="button nginx-clear-logs-button">Clear Logs</button>
                            <p class="description">Click the button to clear logs.</p>
                        </td>
                    </tr>
                </table>
                <p class="submit">
                    <input type="submit" name="submit" class="button-primary" value="Save Changes">
                </p>
            </form>
        </div>
        
        <div id="status" class="tab-content">
        </div>

        <div id="help" class="tab-content">
            <?php echo do_shortcode('[my_faq]'); ?>
        </div>
    </div>
    <?php
}

// AJAX callback function to clear logs
function clear_nginx_cache_logs() {
    check_ajax_referer('clear-nginx-cache-logs', '_wpnonce');

    if (!current_user_can('manage_options')) {
        wp_die('You do not have permission to access this page.');
    }

    $log_file_path = NGINX_CACHE_LOG_FILE;
    if (file_exists($log_file_path)) {
        perform_file_operation($log_file_path, 'write', '');
        display_admin_notice('success', 'SUCCESS: Logs cleared successfully.');
    } else {
        display_admin_notice('error', 'ERROR: No logs available.');
    }

    wp_die();
}

// AJAX callback function to update send mail option
function update_send_mail_option() {
    // Verify nonce
    check_ajax_referer('update-send-mail-option', '_wpnonce');

    // Check user capability
    if (!current_user_can('manage_options')) {
        wp_send_json_error('You do not have permission to update this option.');
    }

    // Get the posted option value
    $send_mail = isset($_POST['send_mail']) ? $_POST['send_mail'] : '';

    // Get the current options
    $current_options = get_option('nginx_cache_settings');

    // Update the specific option within the array
    $current_options['nginx_cache_send_mail'] = $send_mail;

    // Save the updated options
    $updated = update_option('nginx_cache_settings', $current_options);

    // Check if option is updated successfully
    if ($updated) {
        wp_send_json_success('success');
    } else {
        wp_send_json_error('Error updating option.');
    }
}

// Callback function to display the settings section description.
function nginx_cache_settings_section_callback() {
    echo 'Configure the settings for FastCGI Cache.';
}

// Callback function to display the input field for Nginx Cache Path setting
function nginx_cache_path_callback() {
    $options = get_option('nginx_cache_settings');
    $default_cache_path = find_user_home_folder() . '/change-me-now';
    echo "<input type='text' id='nginx_cache_path' name='nginx_cache_settings[nginx_cache_path]' value='" . esc_attr($options['nginx_cache_path'] ?? $default_cache_path) . "' class='regular-text' />";
}

// Callback function to display the input field for Email Address setting
function nginx_cache_email_callback() {
    $options = get_option('nginx_cache_settings');
    $default_email = 'your-email@example.com';
    echo "<input type='text' id='nginx_cache_email' name='nginx_cache_settings[nginx_cache_email]' value='" . esc_attr($options['nginx_cache_email'] ?? $default_email) . "' class='regular-text' />";
}

// Callback function to display the input field for CPU Usage Limit setting
function nginx_cache_cpu_limit_callback() {
    $options = get_option('nginx_cache_settings');
    $default_cpu_limit = 50;
    echo "<input type='number' id='nginx_cache_cpu_limit' name='nginx_cache_settings[nginx_cache_cpu_limit]' min='10' max='100' value='" . esc_attr($options['nginx_cache_cpu_limit'] ?? $default_cpu_limit) . "' class='small-text' />";
}

// Callback function to display the checkbox for Send Email Notification setting
function nginx_cache_send_mail_callback() {
    $options = get_option('nginx_cache_settings');
    $send_mail_checked = isset($options['nginx_cache_send_mail']) && $options['nginx_cache_send_mail'] === 'yes' ? 'checked="checked"' : '';
    echo "<label><input type='checkbox' id='nginx_cache_send_mail' name='nginx_cache_settings[nginx_cache_send_mail]' value='yes' " . esc_html($send_mail_checked) . " />Send email notifications</label>";
}

// Callback function to display the Reject Regex field
function nginx_cache_reject_regex_callback() {
    if (isset($_POST['nginx-regex-reset-defaults'])) {
        if (isset($_POST['nginx_cache_settings_nonce']) && wp_verify_nonce($_POST['nginx_cache_settings_nonce'], 'nginx_cache_settings_nonce')) {
            $default_reject_regex = fetch_default_reject_regex_from_php_file();
            display_admin_notice('info', 'INFO: Reject Regex reset to default. Please Save Changes.');
        } else {
            // Nonce verification failed, handle accordingly (e.g., display an error message)
            echo '<div class="error"><p>Nonce verification failed for reset defaults action.</p></div>';
            return; // Exit the function to prevent further execution
        }
    } else {
        $options = get_option('nginx_cache_settings');
        $default_reject_regex = fetch_default_reject_regex_from_php_file();
        $default_reject_regex = isset($options['nginx_cache_reject_regex']) ? $options['nginx_cache_reject_regex'] : $default_reject_regex;
    }

    $reject_regex = preg_replace('/\\\\+/', '\\', $default_reject_regex);
    echo "<textarea id='nginx_cache_reject_regex' name='nginx_cache_settings[nginx_cache_reject_regex]' rows='3' cols='50' class='large-text'>" . esc_textarea($reject_regex) . "</textarea>";
}

// Callback function to display the Logs field
function nginx_cache_logs_callback() {
    $log_file_path = NGINX_CACHE_LOG_FILE;
    perform_file_operation($log_file_path, 'create');
    if (file_exists($log_file_path) && is_readable($log_file_path)) {
        // Read the log file into an array of lines
        $lines = file($log_file_path);
        // Get the latest 5 lines
        if (is_array($lines)) {
            $latest_lines = array_slice($lines, -5);

            // Remove leading tab spaces and spaces from each line
            $cleaned_lines = array_map(function($line) {
                return trim($line);
            }, $latest_lines);
            ?>
            <div class="logs-container">
                <?php
                // Output the latest 5 lines
                foreach ($cleaned_lines as $line) {
                    if (!empty($line)) {
                        // Extract timestamp and message
                        preg_match('/^\[(.*?)\]\s*(.*?)$/', $line, $matches);
                        $timestamp = isset($matches[1]) ? $matches[1] : '';
                        $message = isset($matches[2]) ? $matches[2] : '';

                        // Apply different CSS classes based on whether it's an error line or not
                        $class = strpos($message, 'ERROR') !== false ? 'error-line' : (strpos($message, 'SUCCESS') !== false ? 'success-line' : 'normal-line');

                        // Output the line with the appropriate CSS class
                        echo '<div class="' . esc_attr($class) . '"><span class="timestamp">' . esc_html($timestamp) . '</span> ' . esc_html($message) . '</div>';
                    }
                }
                ?>
                <div class="cursor blink">#</div>
            </div>
            <?php
        } else {
             echo '<p>Unable to read log file. Please check file permissions.</p>';
        }
    } else {
        echo '<p>Log file not found or is not readable.</p>';
    }
}

// Callback function to display the input field for Limit Rate setting.
function nginx_cache_limit_rate_callback() {
    $options = get_option('nginx_cache_settings');
    $default_limit_rate = 1280;
    echo "<input type='number' id='nginx_cache_limit_rate' name='nginx_cache_settings[nginx_cache_limit_rate]' value='" . esc_attr($options['nginx_cache_limit_rate'] ?? $default_limit_rate) . "' class='small-text' />";
}

// Function to fetch default Reject Regex from PHP file
function fetch_default_reject_regex_from_php_file() {
    $php_file_path = plugin_dir_path(__FILE__) . 'index.php';
    if (file_exists($php_file_path)) {
        $file_content = perform_file_operation($php_file_path, 'read');
        $regex_match = preg_match('/\$reject_regex\s*=\s*[\'"](.+?)[\'"];/i', $file_content, $matches);
        if ($regex_match && isset($matches[1])) {
            return $matches[1];
        }
    }
    return '';
}

// Sanitize inputs
function nginx_cache_settings_sanitize($input) {
    $sanitized_input = array();

    // Sanitize and validate cache path
    if (!empty($input['nginx_cache_path'])) {
        // Check if the path is valid
        if (validate_path($input['nginx_cache_path'])) {
            $sanitized_input['nginx_cache_path'] = sanitize_text_field($input['nginx_cache_path']);
        } else {
            add_settings_error(
                'nginx_cache_settings_group',
                'invalid_path',
                'Restricted/Invalid path: It seems this path is critical system path and not allowed for safe purge operations',
                'error'
            );
            // Log error message
            $log_message = 'ERROR: Restricted/Invalid path: It seems this path is critical system path and not allowed for safe purge operations';
            $log_file_path = NGINX_CACHE_LOG_FILE;
            perform_file_operation($log_file_path, 'create');
            if (!empty($log_file_path)) {
                perform_file_operation($log_file_path, 'append', '[' . gmdate('Y-m-d H:i:s') . '] ' . $log_message);
            }
        }
    }

    // Sanitize and validate email
    if (!empty($input['nginx_cache_email'])) {
        // Validate email format
        $email = sanitize_email($input['nginx_cache_email']);
        if (is_email($email)) {
            $sanitized_input['nginx_cache_email'] = $email;
        } else {
            // Email is not valid, add error message
            add_settings_error(
                'nginx_cache_settings_group',
                'invalid-email',
                'Please enter a valid email address.',
                'error'
            );
            // Log error message
            $log_message = 'ERROR: Please enter a valid email address.';
            $log_file_path = NGINX_CACHE_LOG_FILE;
            perform_file_operation($log_file_path, 'create');
            if (!empty($log_file_path)) {
                perform_file_operation($log_file_path, 'append', '[' . gmdate('Y-m-d H:i:s') . '] ' . $log_message);
            }
        }
    }

    // Sanitize and validate CPU limit
    if (!empty($input['nginx_cache_cpu_limit'])) {
        // Validate CPU limit
        $cpu_limit = intval($input['nginx_cache_cpu_limit']);
        if ($cpu_limit >= 10 && $cpu_limit <= 100) {
            $sanitized_input['nginx_cache_cpu_limit'] = $cpu_limit;
        } else {
            // CPU limit is not within range, add error message
            add_settings_error(
                'nginx_cache_settings_group',
                'invalid-cpu-limit',
                'Please enter a CPU limit between 10 and 100.',
                'error'
            );
            // Log error message
            $log_message = 'ERROR: Please enter a CPU limit between 10 and 100.';
            $log_file_path = NGINX_CACHE_LOG_FILE;
            perform_file_operation($log_file_path, 'create');
            if (!empty($log_file_path)) {
                perform_file_operation($log_file_path, 'append', '[' . gmdate('Y-m-d H:i:s') . '] ' . $log_message);
            }
        }
    }

    // Sanitize Reject Regex field
    if (!empty($input['nginx_cache_reject_regex'])) {
        //$sanitized_input['nginx_cache_reject_regex'] = $input['nginx_cache_reject_regex'];
        $sanitized_input['nginx_cache_reject_regex'] = preg_replace('/\\\\+/', '\\', $input['nginx_cache_reject_regex']);
    }

    // Checkbox handling
    $sanitized_input['nginx_cache_send_mail'] = isset($input['nginx_cache_send_mail']) && $input['nginx_cache_send_mail'] === 'yes' ? 'yes' : 'no';

    // Sanitize Limit Rate
    if (!empty($input['nginx_cache_limit_rate'])) {
        $sanitized_input['nginx_cache_limit_rate'] = sanitize_text_field($input['nginx_cache_limit_rate']);
    }

    return $sanitized_input;
}

// Validate the fastcgi cache path format
function validate_path($path) {
    // Define critical system directories
    $system_paths = array(
        '/',
        '/bin',
        '/boot',
        '/dev',
        '/etc',
        '/home',
        '/lib',
        '/lib64',
        '/media',
        '/mnt',
        '/proc',
        '/root',
        '/run',
        '/sbin',
        '/srv',
        '/sys',
        '/tmp',
        '/usr',
        '/var',
        '/var',
        '/dev',
        '/opt'
    );

     // Check if the path is empty, and is not a critical system directory
    if (empty($path) || $path[0] !== '/' || in_array(rtrim($path, '/'), $system_paths) || in_array($path, $system_paths)) {
        return false;
    }

    return true;
}

// Function to reset plugin settings on deactivation
function reset_plugin_settings_on_deactivation() {
    delete_option('nginx_cache_settings');
}

// Function to find the user's home folder
function find_user_home_folder() {
    // Use $_SERVER['HOME'] if available
    if (isset($_SERVER['HOME'])) {
        return $_SERVER['HOME'];
    }
    // Use $_SERVER['HOMEDRIVE'] and $_SERVER['HOMEPATH'] if available
    if (isset($_SERVER['HOMEDRIVE']) && isset($_SERVER['HOMEPATH'])) {
        return $_SERVER['HOMEDRIVE'] . $_SERVER['HOMEPATH'];
    }
    // Fallback to a default directory
    return '/some/path';
}

// Automatically update the default options when the plugin is activated or reactivated
function defaults_on_plugin_activation() {
    // Define default options
    $default_options = array(
        'nginx_cache_path' => find_user_home_folder() . '/change-me-nginx',
        'nginx_cache_email' => 'your-email@example.com',
        'nginx_cache_cpu_limit' => 50,
        'nginx_cache_reject_regex' => fetch_default_reject_regex_from_php_file(),
    );
    update_option('nginx_cache_settings', $default_options);

    // Create the log file if it doesn't exist
    $log_file_path = NGINX_CACHE_LOG_FILE;
    if (!file_exists($log_file_path)) {
        $log_file_created = perform_file_operation($log_file_path, 'create');
        if (!$log_file_created) {
            // Log file creation failed, handle error accordingly
            error_log('Failed to create log file: ' . $log_file_path);
        }
    }
}
