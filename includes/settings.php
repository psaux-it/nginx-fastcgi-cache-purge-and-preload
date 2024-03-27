<?php
/**
 * Settings page for Nginx FastCGI Cache Purge and Preload
 * Description: This file contains settings page functions for Nginx FastCGI Cache Purge and Preload
 * Version: 1.0.2
 * Author: Hasan ÇALIŞIR
 * Author Email: hasan.calisir@psauxit.com
 * Author URI: https://www.psauxit.com
 * License: GPL-2.0+
 */

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
        'Nginx FastCGI Cache',
        'Nginx FastCGI Cache',
        'manage_options',
        'nginx_cache_settings',
        'nginx_cache_settings_page'
    );
}

// Modify the URL of the submenu page and add nonce
function modify_nginx_cache_settings_url() {
    global $submenu;
    if (isset($submenu['options-general.php'])) {
        foreach ($submenu['options-general.php'] as $key => $item) {
            if ($item[2] === 'nginx_cache_settings') {
                // Generate nonce
                $nonce = wp_create_nonce('nginx_cache_settings_nonce');

                // Change the URL and add nonce
                $url_with_nonce = add_query_arg('_wpnonce', $nonce, admin_url('options-general.php?page=nginx_cache_settings'));

                // Modify the URL
                $submenu['options-general.php'][$key][2] = $url_with_nonce;
                break;
            }
        }
    }
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
        <h2><img src="<?php echo esc_url( plugins_url( '../assets/img/logo.png', __FILE__ ) ); ?>" alt="Logo" style="vertical-align: middle; margin-right: 10px; width: 90px;">Nginx Cache Settings</h2>
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
    $php_file_path = plugin_dir_path(__FILE__) . '../index.php';
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
