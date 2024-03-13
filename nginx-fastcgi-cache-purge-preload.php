<?php
/*
Plugin Name: Nginx FastCGI Cache and Preload For Wordpress
Plugin URI: https://www.psauxit.com
Description: This plugin allows you to manage Nginx FastCGI cache and preload operations directly from your WordPress admin dashboard.
Version: 1.0
Author: Hasan ÇALIŞIR | hasan.calisir@psauxit.com
Author URI: https://www.psauxit.com
License: GPL2
*/

// Define a constant for the option key if not already defined
// Required for both preload and purge ops
if (!defined('CRAWL_AND_VISIT_OPTION')) {
    define('CRAWL_AND_VISIT_OPTION', 'crawl_and_visit_status');
}

// Function to retrieve the nginx_cache_user_agent option value
function get_nginx_cache_user_agent() {
    return get_option('nginx_cache_settings')['nginx_cache_user_agent'] ?? 'Mozilla/5.0 (compatible; NginxCachePreload/1.0; +localhost)';
}

// Define the PLUGIN_USER_AGENT constant
define('PLUGIN_USER_AGENT', get_nginx_cache_user_agent());

// Define a constant for the log file path
define('NGINX_CACHE_LOG_FILE', plugin_dir_path(__FILE__) . 'fastcgi_ops.log');

// Include the purge & preload
require_once plugin_dir_path( __FILE__ ) . 'includes/cache_preloader.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/cache_purger.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/helper.php';

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

    // Add settings submenu
    $wp_admin_bar->add_menu(array(
        'parent' => 'fastcgi-cache-operations',
        'id' => 'fastcgi-cache-settings',
        'title' => 'Settings',
        'href' => admin_url('options-general.php?page=nginx_cache_settings'),
    ));
}
add_action('admin_bar_menu', 'add_fastcgi_cache_buttons_admin_bar', 100);

// Check if wget is available and handle preload button
function check_wget_availability() {
    $output = shell_exec('which wget');
    if (empty($output)) {
        // Wget is not available
        add_action('admin_notices', 'display_wget_warning');
        wp_enqueue_script('preload-button-disable', plugins_url('js/preload-button-disable.js', __FILE__), array('jquery'), null, true);
    }
}
add_action('admin_init', 'check_wget_availability');

// Display wget warning message only on the plugin settings page
function display_wget_warning() {
    $current_page = isset($_GET['page']) ? $_GET['page'] : '';
    if ($current_page === 'nginx_cache_settings') {
        ?>
        <div class="notice notice-error">
            <p><?php _e('Warning: Preload action disabled ! The "wget" command is not available on your system. Please make sure "wget" is installed to use Nginx Cache Preload feature.', 'textdomain'); ?></p>
        </div>
        <?php
    }
}

// Handle button clicks
function handle_fastcgi_cache_actions_admin_bar() {
    // Check if the buttons are clicked
    if (isset($_GET['purge_cache']) || isset($_GET['preload_cache'])) {
        // Determine action based on button click
        $action = isset($_GET['purge_cache']) ? 'purge' : 'preload';

        // Retrieve the Nginx FastCGI Cache Path setting value
        $nginx_cache_settings = get_option('nginx_cache_settings');
        $default_cache_path = find_user_home_folder() . '/change-me-nginx';
        $nginx_cache_path = isset($nginx_cache_settings['nginx_cache_path']) ? $nginx_cache_settings['nginx_cache_path'] : $default_cache_path;

        // Retrieve the reject regex from the included file
        $reject_regex = fetch_default_reject_regex_from_php_file();

        // Call the appropriate function based on the action and pass the cache path
        if ($action === 'purge') {
            purge($nginx_cache_path);
        } elseif ($action === 'preload') {
            crawl_and_visit($reject_regex, $nginx_cache_path);
        }
    }
}
add_action('admin_init', 'handle_fastcgi_cache_actions_admin_bar');

// Display admin notices
function display_admin_notice($type, $message) {
    echo '<div class="notice notice-' . $type . '"><p>' . esc_html($message) . '</p></div>';
    // Write to the log file
    $log_file_path = NGINX_CACHE_LOG_FILE; // path to the log file
    !empty($log_file_path) ? file_put_contents($log_file_path, '[' . date('Y-m-d H:i:s') . '] ' . $message . PHP_EOL, FILE_APPEND) : die("Log file not found!");
}

// Function to check preload process status
function check_processes_status() {
    // If the process is running, display admin notice for preload in progress
    if (get_option(CRAWL_AND_VISIT_OPTION) === 'in_progress') {
        display_admin_notice('info', 'INFO: FastCGI cache preload is in progress...');
        return;
    } elseif (get_option(CRAWL_AND_VISIT_OPTION) === 'completed') {
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
            $domain = str_replace('www.', '', parse_url(get_site_url(), PHP_URL_HOST));
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

        // If the process is not running, delete the PID file
        delete_option(CRAWL_AND_VISIT_OPTION);
    }
}
add_action('admin_init', 'check_processes_status');

// Enqueue custom CSS and JavaScript files
function enqueue_nginx_fastcgi_cache_purge_preload_assets() {
    // Enqueue CSS file
    wp_enqueue_style('nginx-fastcgi-cache-purge-preload', plugins_url('css/nginx-fastcgi-cache-purge-preload.css', __FILE__));

    // Enqueue JavaScript file
    wp_enqueue_script('nginx-fastcgi-cache-admin', plugins_url('js/nginx-fastcgi-cache-purge-preload.js', __FILE__), array('jquery'), null, true);

    // Localize nonce value for JavaScript
    wp_localize_script('nginx-fastcgi-cache-admin', 'nginx_cache_ajax_object', array(
        'ajaxurl' => admin_url('admin-ajax.php'),
        'nonce' => wp_create_nonce('clear-nginx-cache-logs'),
        'send_mail_nonce' => wp_create_nonce('update-send-mail-option') // Add nonce for send mail option
    ));
}
add_action('admin_enqueue_scripts', 'enqueue_nginx_fastcgi_cache_purge_preload_assets');

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
    add_settings_field('nginx_cache_user_agent', 'User Agent Definition', 'nginx_cache_user_agent_callback', 'nginx_cache_settings_group', 'nginx_cache_settings_section');
}
// Initialize settings
add_action('admin_init', 'nginx_cache_settings_init');

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
add_action('admin_menu', 'add_nginx_cache_settings_page');

// Add the option name to the allowed options list
function add_nginx_cache_settings_to_allowed_options($options) {
    $options['nginx_cache_settings'] = 'nginx_cache_settings';
    return $options;
}
add_filter('whitelist_options', 'add_nginx_cache_settings_to_allowed_options');

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
                    echo '<div class="error"><p>' . $error['message'] . '</p></div>';
                }
            }
        }
    }

    // Display the settings form
    ?>
    <div class="wrap">
        <h2><img src="<?php echo plugins_url( 'images/logo.png', __FILE__ ); ?>" alt="Logo" style="vertical-align: middle; margin-right: 10px; width: 90px;">Nginx Cache Settings</h2>
        <h2 class="nav-tab-wrapper">
            <a href="#settings" class="nav-tab nav-tab-active">Settings</a>
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
                            <p class="description">Please specify the directory path for your Nginx cache. Please note that purge operation is irreversible, so proceed with caution</p>
                        </td>
                    </tr>
                    <tr valign="top">
                        <th scope="row"><span class="dashicons dashicons-email"></span> Email Address</th>
                        <td>
                            <?php nginx_cache_email_callback(); ?>
                            <p class="description">Enter an email address for notifications or configurations related to Nginx cache.</p>
                        </td>
                    </tr>
                    <tr valign="top">
                        <th scope="row"><span class="dashicons dashicons-dashboard"></span> CPU Usage Limit (%)</th>
                        <td>
                            <?php nginx_cache_cpu_limit_callback(); ?>
                            <p class="description">Enter the CPU usage limit for cache operations (10-100%).</p>
                        </td>
                    </tr>
                    <tr valign="top">
                        <th scope="row"><span class="dashicons dashicons-no"></span> Exclude Endpoints</th>
                        <td>
                            <?php nginx_cache_reject_regex_callback(); ?>
                            <p class="description">Enter a regex pattern to exclude certain requests from being cached.</p>
                        </td>
                    </tr>
                    <tr valign="top">
                         <th scope="row"><span class="dashicons dashicons-admin-users"></span> User Agent</th>
                         <td>
                            <?php nginx_cache_user_agent_callback(); ?>
                            <p class="description">Enter a user agent to customize preload behavior for specific user agents.</p>
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
                            <button id="clear-logs-button" class="button">Clear Logs</button>
                            <p class="description">Click the button to clear logs.</p>
                        </td>
                    </tr>
                </table>
                <p class="submit">
                    <input type="submit" name="submit" class="button-primary" value="Save Changes">
                </p>
            </form>
        </div>

        <div id="help" class="tab-content">
            <?php echo do_shortcode('[my_faq]'); ?>
        </div>
    </div>
    <?php
}

// AJAX callback function to clear logs
add_action('wp_ajax_clear_nginx_cache_logs', 'clear_nginx_cache_logs');
function clear_nginx_cache_logs() {
    check_ajax_referer('clear-nginx-cache-logs', '_wpnonce');

    if (!current_user_can('manage_options')) {
        wp_die('You do not have permission to access this page.');
    }

    $log_file_path = NGINX_CACHE_LOG_FILE;
    if (file_exists($log_file_path)) {
        file_put_contents($log_file_path, '');
        display_admin_notice('success', 'SUCCESS: Logs cleared successfully.');
    } else {
        display_admin_notice('error', 'ERROR: No logs available.');
    }

    wp_die();
}

// AJAX callback function to update send mail option
add_action('wp_ajax_update_send_mail_option', 'update_send_mail_option');
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

function nginx_cache_settings_section_callback() {
    echo 'Configure the settings for FastCGI Cache.';
}

function nginx_cache_path_callback() {
    $options = get_option('nginx_cache_settings');
    $default_cache_path = find_user_home_folder() . '/change-me-now';
    echo "<input type='text' id='nginx_cache_path' name='nginx_cache_settings[nginx_cache_path]' value='" . esc_attr($options['nginx_cache_path'] ?? $default_cache_path) . "' class='regular-text' />";
}

function nginx_cache_email_callback() {
    $options = get_option('nginx_cache_settings');
    $default_email = 'your-email@example.com'; // Default email value
    echo "<input type='text' id='nginx_cache_email' name='nginx_cache_settings[nginx_cache_email]' value='" . esc_attr($options['nginx_cache_email'] ?? $default_email) . "' class='regular-text' />";
}

function nginx_cache_cpu_limit_callback() {
    $options = get_option('nginx_cache_settings');
    $default_cpu_limit = 50; // Default CPU limit value
    echo "<input type='number' id='nginx_cache_cpu_limit' name='nginx_cache_settings[nginx_cache_cpu_limit]' min='10' max='100' value='" . esc_attr($options['nginx_cache_cpu_limit'] ?? $default_cpu_limit) . "' class='small-text' />";
}

function nginx_cache_send_mail_callback() {
    $options = get_option('nginx_cache_settings');
    $send_mail_checked = isset($options['nginx_cache_send_mail']) && $options['nginx_cache_send_mail'] === 'yes' ? 'checked="checked"' : '';
    echo "<label><input type='checkbox' id='nginx_cache_send_mail' name='nginx_cache_settings[nginx_cache_send_mail]' value='yes' {$send_mail_checked} />Send email notifications</label>";
}

// Callback function to display the Reject Regex field
function nginx_cache_reject_regex_callback() {
    $default_reject_regex = fetch_default_reject_regex_from_php_file(); // Fetch default value
    $options = get_option('nginx_cache_settings');
    echo "<textarea id='nginx_cache_reject_regex' name='nginx_cache_settings[nginx_cache_reject_regex]' rows='5' cols='50' class='large-text'>" . esc_textarea($options['nginx_cache_reject_regex'] ?? $default_reject_regex) . "</textarea>";
}

// Callback function to display the Logs field
function nginx_cache_logs_callback() {
    $log_file_path = NGINX_CACHE_LOG_FILE;
    if (!empty($log_file_path)) {
        // Read the log file into an array of lines
        $lines = file($log_file_path);
        // Get the latest 5 lines
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
                // Extract timestamp and message
                preg_match('/^\[(.*?)\]\s*(.*?)$/', $line, $matches);
                $timestamp = isset($matches[1]) ? $matches[1] : '';
                $message = isset($matches[2]) ? $matches[2] : '';

                // Apply different CSS classes based on whether it's an error line or not
                $class = strpos($message, 'ERROR') !== false ? 'error-line' : (strpos($message, 'SUCCESS') !== false ? 'success-line' : 'normal-line');

                // Output the line with the appropriate CSS class
                echo '<div class="' . $class . '"><span class="timestamp">' . esc_html($timestamp) . '</span> ' . esc_html($message) . '</div>';
            }
            ?>
            <div class="cursor blink">#</div>
        </div>
        <?php
    }
}

function nginx_cache_user_agent_callback() {
    $options = get_option('nginx_cache_settings');
    $default_user_agent = 'Mozilla/5.0 (compatible; NginxCachePreload/1.0; +https://www.example.com)';
    $user_agent_domain = parse_url(home_url(), PHP_URL_HOST);
    $default_user_agent = str_replace('www.example.com', $user_agent_domain, $default_user_agent);
    echo "<input type='text' id='nginx_cache_user_agent' name='nginx_cache_settings[nginx_cache_user_agent]' value='" . esc_attr($options['nginx_cache_user_agent'] ?? $default_user_agent) . "' class='regular-text' />";
}

// Function to fetch default Reject Regex from PHP file
function fetch_default_reject_regex_from_php_file() {
    $php_file_path = plugin_dir_path(__FILE__) . 'includes/reject_regex.php'; // Path to the PHP file
    if (file_exists($php_file_path)) {
        $file_content = file_get_contents($php_file_path);
        $regex_match = preg_match('/\$reject_regex\s*=\s*[\'"](.+?)[\'"];/i', $file_content, $matches);
        if ($regex_match && isset($matches[1])) {
            return $matches[1];
        }
    }
    return ''; // Default value if not found or file doesn't exist
}

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
            $log_file_path = NGINX_CACHE_LOG_FILE; // path to the log file
            if (!empty($log_file_path)) {
                file_put_contents($log_file_path, '[' . date('Y-m-d H:i:s') . '] ' . $log_message . PHP_EOL, FILE_APPEND);
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
            $log_file_path = NGINX_CACHE_LOG_FILE; // path to the log file
            if (!empty($log_file_path)) {
                file_put_contents($log_file_path, '[' . date('Y-m-d H:i:s') . '] ' . $log_message . PHP_EOL, FILE_APPEND);
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
            $log_file_path = NGINX_CACHE_LOG_FILE; // path to the log file
            if (!empty($log_file_path)) {
                file_put_contents($log_file_path, '[' . date('Y-m-d H:i:s') . '] ' . $log_message . PHP_EOL, FILE_APPEND);
            }
        }
    }

    // Sanitize Reject Regex field
    if (!empty($input['nginx_cache_reject_regex'])) {
        $sanitized_input['nginx_cache_reject_regex'] = sanitize_text_field($input['nginx_cache_reject_regex']);
    }

    // Checkbox handling
    $sanitized_input['nginx_cache_send_mail'] = isset($input['nginx_cache_send_mail']) && $input['nginx_cache_send_mail'] === 'yes' ? 'yes' : 'no';

    // Sanitize User Agent
    if (!empty($input['nginx_cache_user_agent'])) {
        $sanitized_input['nginx_cache_user_agent'] = sanitize_text_field($input['nginx_cache_user_agent']);
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
register_deactivation_hook(__FILE__, 'reset_plugin_settings_on_deactivation');

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
        $log_file_created = touch($log_file_path);
        if (!$log_file_created) {
            // Log file creation failed, handle error accordingly
            error_log('Failed to create log file: ' . $log_file_path);
        }
    }
}
register_activation_hook(__FILE__, 'defaults_on_plugin_activation');
