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

// Include the cache preloader file
require_once(plugin_dir_path(__FILE__) . 'cache_preloader.php');

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
        $fastcgi_script_path = find_fastcgi_script_path(); // Find the path to the bash script
        // Check if the bash script path is set
        if (empty($fastcgi_script_path)) {
            display_admin_notice('error', 'FastCGI operations script path is not configured!');
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

    // Write to the log file
    $log_file_path = find_log_file(); // Get the path to the log file
    if (!empty($log_file_path)) {
        $log_message = '[' . date('Y-m-d H:i:s') . '] ' . $message . PHP_EOL;
        file_put_contents($log_file_path, $log_message, FILE_APPEND);
    }
}

// Function to find the path to the bash script
function find_fastcgi_script_path() {
    $plugin_dir = plugin_dir_path(__FILE__);
    $bash_script_path = $plugin_dir . 'scripts/fastcgi_ops.sh';
    if (file_exists($bash_script_path)) {
        return $bash_script_path;
    }
    return '';
}

// Function to check preload process status
function check_processes_status() {
    $fastcgi_script_path = find_fastcgi_script_path(); // Find the path to the bash script
    // Check if the bash script path is set and not empty
    if (empty($fastcgi_script_path)) {
        $fastcgi_script_path = find_fastcgi_script_path(); // Find the path to the bash script
        if (empty($fastcgi_script_path)) {
            return;
        }
    }

    // Extract directory from the script path
    $script_directory = dirname($fastcgi_script_path);

    // Find the PID file with the name 'fastcgi_ops_*' in the script directory
    $pid_files = glob("$script_directory/fastcgi_ops_*.pid");

    // Check if there's a PID file
    if (!empty($pid_files)) {
        // Get the first PID file
        $pid_file = $pid_files[0];

        // Check if the PID file exists
        if (file_exists($pid_file)) {
            // Read the PID file
            $pids = file($pid_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

            // Check if the process is running
            $process_running = posix_kill($pids[0], 0);

            // If the process is running, display admin notice for preload in progress
            if ($process_running) {
                display_admin_notice('info', 'FastCGI cache preload is in progress...');
                return;
            } else {
                // Display admin notice for completed preload
                $notice_message = 'FastCGI cache preload is completed!';
                display_admin_notice('success', $notice_message);

                // Write to the log file
                $log_file_path = find_log_file(); // Get the path to the log file
                !empty($log_file_path) ? file_put_contents($log_file_path, '[' . date('Y-m-d H:i:s') . '] ' . $notice_message . PHP_EOL, FILE_APPEND) : die("Log file not found!");

                // If the process is not running, delete the PID file
                unlink($pid_file);
            }
        }
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
    add_settings_field('nginx_cache_logs', 'Logs', 'nginx_cache_logs_callback', 'nginx_cache_settings_group', 'nginx_cache_settings_section');
}

function nginx_cache_settings_section_callback() {
    echo 'Configure the settings for FastCGI Cache.';
}

function nginx_cache_path_callback() {
    $options = get_option('nginx_cache_settings');
    $default_cache_path = find_user_home_folder() . '/change-me-84';
    echo "<input type='text' id='nginx_cache_path' name='nginx_cache_settings[nginx_cache_path]' value='" . esc_attr($options['nginx_cache_path'] ?? $default_cache_path) . "' />";
}

function nginx_cache_email_callback() {
    $options = get_option('nginx_cache_settings');
    echo "<input type='text' id='nginx_cache_email' name='nginx_cache_settings[nginx_cache_email]' value='" . esc_attr($options['nginx_cache_email']) . "' />";
}

function nginx_cache_cpu_limit_callback() {
    $options = get_option('nginx_cache_settings');
    echo "<input type='number' id='nginx_cache_cpu_limit' name='nginx_cache_settings[nginx_cache_cpu_limit]' min='0' max='100' value='" . esc_attr($options['nginx_cache_cpu_limit']) . "' />";
}

// Callback function to display the Reject Regex field
function nginx_cache_reject_regex_callback() {
    $default_reject_regex = fetch_default_reject_regex_from_bash_script(); // Fetch default value
    $options = get_option('nginx_cache_settings');
    echo "<textarea id='nginx_cache_reject_regex' name='nginx_cache_settings[nginx_cache_reject_regex]' rows='5' cols='50'>" . esc_textarea($options['nginx_cache_reject_regex'] ?? $default_reject_regex) . "</textarea>";
}

// Callback function to display the Logs field
function nginx_cache_logs_callback() {
    $log_file_path = find_log_file();
    if (!empty($log_file_path)) {
        // Read the log file into an array of lines
        $lines = file($log_file_path);
        // Get the latest 10 lines
        $latest_lines = array_slice($lines, -5);

        // Remove leading tab spaces and spaces from each line
        $cleaned_lines = array_map(function($line) {
            return trim($line);
        }, $latest_lines);
        ?>
        <div class="logs-container">
            <?php
            // Output the latest 10 lines
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
    } else {
        echo '<div class="logs-container">Log file not found.</div>';
    }
}

// Function to find the log file
function find_log_file() {
    $plugin_dir = plugin_dir_path(__FILE__);
    $log_files = glob($plugin_dir . 'scripts/fastcgi_ops_*.log');
    if (!empty($log_files)) {
        // Find the latest log file based on modification time
        $latest_log_file = max($log_files);
        return $latest_log_file;
    }
    return '';
}

// Function to fetch default Reject Regex from bash script
function fetch_default_reject_regex_from_bash_script() {
    $bash_script_path = find_fastcgi_script_path(); // Function to find bash script path
    if (!empty($bash_script_path)) {
        $script_content = file_get_contents($bash_script_path);
        $regex_match = preg_match('/reject_regex=\'(.+?)\'/i', $script_content, $matches);
        if ($regex_match && isset($matches[1])) {
            return $matches[1];
        }
    }
    return ''; // Default value if not found or script path is empty
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
    // Get the user's home directory
    $user_home_folder = find_user_home_folder();

    // Check if the path starts with the user's home directory
    if (strpos($path, $user_home_folder) !== 0) {
        return false;
    }

    // Check if the path is the same as the user's home directory followed by a slash
    if ($path === $user_home_folder . '/') {
        return false;
    }

    return true;
}

// Add settings page
add_action('admin_menu', function () {
    add_submenu_page(
        'options-general.php',
        'Nginx Cache Settings',
        'Nginx Cache Settings',
        'manage_options',
        'nginx_cache_settings',
        'nginx_cache_settings_page'
    );
});

// Initialize settings
add_action('admin_init', 'nginx_cache_settings_init');

// Function to reset plugin settings on deactivation
function reset_plugin_settings_on_deactivation() {
    delete_option('nginx_cache_settings');
}
register_deactivation_hook(__FILE__, 'reset_plugin_settings_on_deactivation');

// Function to prepare fastcgi_ops.sh by setting file permissions and converting line endings when the plugin is activated
function prepare_fastcgi_script() {
    $fastcgi_script_path = find_fastcgi_script_path(); // Find the path to the bash script
    if (empty($fastcgi_script_path)) {
        display_admin_notice('error', 'FastCGI script path not found.');
        return; // Cannot prepare script if path is not found
    }

    // Read the contents of the file
    $scriptContent = file_get_contents($fastcgi_script_path);
    if ($scriptContent === false) {
        display_admin_notice('error', 'Failed to read the content of the file: ' . $fastcgi_script_path);
        return;
    }

    // Convert line endings from DOS to Unix format
    $unixContent = str_replace("\r\n", "\n", $scriptContent);

    // Write the modified content back to the file
    $writeResult = file_put_contents($fastcgi_script_path, $unixContent);
    if ($writeResult === false) {
        display_admin_notice('error', 'Failed to write modified content to the file: ' . $fastcgi_script_path);
        return;
    }

    // Set file permissions using chmod()
    $chmodResult = chmod($fastcgi_script_path, 0755);
    if (!$chmodResult) {
        display_admin_notice('error', 'Failed to set file permissions for: ' . $fastcgi_script_path);
        return;
    }
}
register_activation_hook(__FILE__, 'prepare_fastcgi_script');

// Update the function to edit the bash script with user options
function update_bash_script_with_user_options() {
    $options = get_option('nginx_cache_settings');
    if ($options && isset($options['nginx_cache_path']) && isset($options['nginx_cache_email']) && isset($options['nginx_cache_cpu_limit'])) {
        $fastcgi_script_path = find_fastcgi_script_path(); // Find the path to the bash script
        if (empty($fastcgi_script_path)) {
            return; // Cannot update script if path is not found
        }

        // Determine the user's home folder for default cache path
        $default_cache_path = find_user_home_folder() . '/change-me-84';

        // Extract the domain from the WordPress site URL
        $domain = str_replace('www.', '', parse_url(get_site_url(), PHP_URL_HOST));

        // Read the current content of the bash script
        $content = file_get_contents($fastcgi_script_path);

        // Replace the specified variables with the new values
        $reject_regex = escapeshellarg($options['nginx_cache_reject_regex']);
        $reject_regex = str_replace("'", "", $reject_regex);
        $email = escapeshellarg($options['nginx_cache_email']);
        $email = str_replace("'", "", $email);
        $cache_path = escapeshellarg($options['nginx_cache_path'] ?? $default_cache_path);
        $cache_path = str_replace("'", "", $cache_path);
        $cpu_limit = intval($options['nginx_cache_cpu_limit']);
        $cpu_limit = str_replace("'", "", $cpu_limit);

        // Replace variables in the bash script content
        $content = preg_replace('/reject_regex=\'[^\']*\'/', "reject_regex='$reject_regex'", $content);
        $content = preg_replace('/fpath=\s*["\']([^"\']*)["\']/', "fpath=\"$cache_path\"", $content);
        $content = preg_replace('/mail_to=\s*["\']([^"\']*)["\']/', "mail_to=\"$email\"", $content);
        $content = preg_replace('/fdomain=\s*["\']([^"\']*)["\']/', "fdomain=\"$domain\"", $content);
        $content = preg_replace('/mail_from="From: System Automations<fcgi@[^"]*"/', "mail_from=\"From: System Automations<fcgi@$domain>\"", $content);
        $content = preg_replace('/cpulimit -l \d+/', "cpulimit -l $cpu_limit", $content);

        // Write the updated content back to the bash script
        file_put_contents($fastcgi_script_path, $content);
    }
}
add_action('update_option_nginx_cache_settings', 'update_bash_script_with_user_options');

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
    return '/home/your-php-fpm-user';
}

// Automatically update the bash script with default values when the plugin is activated or reactivated
function update_bash_script_on_plugin_activation() {
    // Extract the domain from the WordPress site URL
    $domain = str_replace('www.', '', parse_url(get_site_url(), PHP_URL_HOST));

    // Define default options
    $default_options = array(
        'nginx_cache_path' => find_user_home_folder() . '/change-me-84',
        'nginx_cache_email' => 'your-email@' . $domain,
        'nginx_cache_cpu_limit' => 50,
        'nginx_cache_reject_regex' => fetch_default_reject_regex_from_bash_script(),
    );
    update_option('nginx_cache_settings', $default_options);
    update_bash_script_with_user_options(); // Update bash script with default values
}
register_activation_hook(__FILE__, 'update_bash_script_on_plugin_activation');
