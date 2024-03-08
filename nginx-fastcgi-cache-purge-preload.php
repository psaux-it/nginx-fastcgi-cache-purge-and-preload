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

// Include the purge & preload
require_once plugin_dir_path( __FILE__ ) . 'includes/cache_preloader.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/cache_purger.php';

// Define a constant for the log file path
define('NGINX_CACHE_LOG_FILE', plugin_dir_path(__FILE__) . 'fastcgi_ops.log');

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

// Handle button clicks
function handle_fastcgi_cache_actions_admin_bar() {
    // Check if the buttons are clicked
    if (isset($_GET['purge_cache']) || isset($_GET['preload_cache'])) {
        // Determine action based on button click
        $action = isset($_GET['purge_cache']) ? 'purge' : 'preload';

        // Retrieve the Nginx FastCGI Cache Path setting value
        $nginx_cache_path = get_option('nginx_cache_settings')['nginx_cache_path'];

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
    !empty($log_file_path) ? file_put_contents($log_file_path, '[' . date('Y-m-d H:i:s') . '] ' . $notice_message . PHP_EOL, FILE_APPEND) : die("Log file not found!");
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

// Enqueue custom CSS file
function enqueue_nginx_fastcgi_cache_purge_preload_css() {
    wp_enqueue_style('nginx-fastcgi-cache-purge-preload', plugins_url('css/nginx-fastcgi-cache-purge-preload.css', __FILE__));
}
add_action('admin_enqueue_scripts', 'enqueue_nginx_fastcgi_cache_purge_preload_css');

// Add settings page
function nginx_cache_settings_page() {
    ?>
    <style>
        .logs-container {
            background-color: black;
            color: white;
            padding: 10px;
            border-radius: 5px;
            font-family: monospace;
            font-size: 0.9em;
            overflow-x: auto;
            max-height: 300px;
            overflow-y: auto;
            width: 800px;
        }
        .error-line {
            color: #FF5252;
        }
        .normal-line {
            color: #4CAF50;
        }
        .info-line {
            color: #BDBDBD;
        }
    </style>
    <div class="wrap">
        <h2><img src="<?php echo plugins_url( 'images/logo.png', __FILE__ ); ?>" alt="Logo" style="vertical-align: middle; margin-right: 10px; width: 90px;">Nginx Cache Settings</h2>
        <form method="post" action="options.php">
            <?php settings_fields('nginx_cache_settings_group'); ?>
            <?php do_settings_sections('nginx_cache_settings_group'); ?>
            <?php submit_button('Save Changes'); ?>
        </form>
    </div>
    <?php
}

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
}

function nginx_cache_settings_section_callback() {
    echo 'Configure the settings for FastCGI Cache.';
}

function nginx_cache_path_callback() {
    $options = get_option('nginx_cache_settings');
    $default_cache_path = find_user_home_folder() . '/change-me-now';
    echo "<input type='text' id='nginx_cache_path' name='nginx_cache_settings[nginx_cache_path]' value='" . esc_attr($options['nginx_cache_path'] ?? $default_cache_path) . "' />";
}

function nginx_cache_email_callback() {
    $options = get_option('nginx_cache_settings');
    $default_email = 'your-email@example.com'; // Default email value
    echo "<input type='text' id='nginx_cache_email' name='nginx_cache_settings[nginx_cache_email]' value='" . esc_attr($options['nginx_cache_email'] ?? $default_email) . "' />";
}

function nginx_cache_cpu_limit_callback() {
    $options = get_option('nginx_cache_settings');
    $default_cpu_limit = 50; // Default CPU limit value
    echo "<input type='number' id='nginx_cache_cpu_limit' name='nginx_cache_settings[nginx_cache_cpu_limit]' min='10' max='100' value='" . esc_attr($options['nginx_cache_cpu_limit'] ?? $default_cpu_limit) . "' />";
}

// Callback function to display the Send Mail field
function nginx_cache_send_mail_callback() {
    $options = get_option('nginx_cache_settings');
    $send_mail_checked = isset($options['nginx_cache_send_mail']) ? checked($options['nginx_cache_send_mail'], 'yes', false) : ''; // Check if the option is set and set 'checked' attribute accordingly
    echo "<input type='checkbox' id='nginx_cache_send_mail' name='nginx_cache_settings[nginx_cache_send_mail]' value='yes' $send_mail_checked />";
}

// Callback function to display the Reject Regex field
function nginx_cache_reject_regex_callback() {
    $default_reject_regex = fetch_default_reject_regex_from_php_file(); // Fetch default value
    $options = get_option('nginx_cache_settings');
    echo "<textarea id='nginx_cache_reject_regex' name='nginx_cache_settings[nginx_cache_reject_regex]' rows='5' cols='50'>" . esc_textarea($options['nginx_cache_reject_regex'] ?? $default_reject_regex) . "</textarea>";
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
                // Determine if the line is an error line
                $is_error = strpos($line, 'ERROR') !== false;
                $is_info = strpos($line, 'INFO') !== false;

                // Apply different CSS classes based on whether it's an error line or not
                 $class = $is_error ? 'error-line' : ($is_info ? 'info-line' : 'normal-line');

                // Output the line with the appropriate CSS class
                echo '<div class="' . $class . '">' . esc_html($line) . '</div>';
            }
            ?>
        </div>
        <?php
    }
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

    if (isset($input['nginx_cache_path'])) {
        // Check if the path is valid
        if (validate_path($input['nginx_cache_path'])) {
            $sanitized_input['nginx_cache_path'] = sanitize_text_field($input['nginx_cache_path']);
        } else {
            add_settings_error(
                'nginx_cache_settings_group',
                'invalid_path',
                'Restricted/Invalid path: The cache path must be in php-fpm user home and at least one level deeper for safe purge operations',
                'error'
            );
        }
    }
    if (isset($input['nginx_cache_email'])) {
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
        }
    }
    if (isset($input['nginx_cache_cpu_limit'])) {
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
        }
    }
    // Sanitize Reject Regex field
    if (isset($input['nginx_cache_reject_regex'])) {
        $sanitized_input['nginx_cache_reject_regex'] = sanitize_text_field($input['nginx_cache_reject_regex']);
    }

    return $sanitized_input;
}

// Validate the fastcgi cache path format
function validate_path($path) {
    // Check if the path is empty, starts with /, and is not just a single slash or /root/
    if (empty($path) || $path[0] !== '/' || $path === '/' || $path === '/root/') {
        return false;
    }
    // Check for any additional validation if needed
    return true;
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
add_action('admin_menu', 'add_nginx_cache_settings_page');

// Initialize settings
add_action('admin_init', 'nginx_cache_settings_init');

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
