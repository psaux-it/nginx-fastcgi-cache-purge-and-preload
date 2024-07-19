<?php
/**
 * Status page for FastCGI Cache Purge and Preload for Nginx
 * Description: This file contains functions which shows information about FastCGI Cache Purge and Preload for Nginx
 * Version: 2.0.2
 * Author: Hasan ÇALIŞIR
 * Author Email: hasan.calisir@psauxit.com
 * Author URI: https://www.psauxit.com
 * License: GPL-2.0+
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Check purge action status
// Check ACLs status
function nppp_check_acl($flag = '') {
    $wp_filesystem = nppp_initialize_wp_filesystem();

    if ($wp_filesystem === false) {
        return false; // Return false if WP_Filesystem initialization failed
    }

    $nginx_cache_settings = get_option('nginx_cache_settings');
    $default_cache_path = '/dev/shm/change-me-now';
    $nginx_cache_path = isset($nginx_cache_settings['nginx_cache_path']) ? $nginx_cache_settings['nginx_cache_path'] : $default_cache_path;

    // Check if directory exists
    if (!$wp_filesystem->is_dir($nginx_cache_path)) {
        // Directory does not exist
        if ($flag === 'purge') {
            return 'Not Working';
        } elseif ($flag === 'acl') {
            return 'Not Implemented';
        }
    }

    // If directory exist we can perform other checks
    $files = $wp_filesystem->dirlist($nginx_cache_path);

    // Even default ACLs applied we can face permission errors, so
    // First check the cache path is readable or not by PHP-FPM USER
    if ($files === false) {
        // We cannot read cache path cause permissons
        if ($flag === 'purge') {
            return 'Not Working';
        } elseif ($flag === 'acl') {
            return 'Not Implemented';
        }
    }

    // We are able to read cache path
    // Check ACL permissions status
    $output = shell_exec("ls -ld \"$nginx_cache_path\" | awk '{print \$1}' | grep '+'");
    if ($output === null) {
        // Main directory does not have ACL permissions
        if ($flag === 'purge') {
            return 'Not Working';
        } elseif ($flag === 'acl') {
            return 'Not Implemented';
        }
    }

    // Check if directory is empty
    if (empty($files)) {
        // Directory is empty, but main directory has ACL permissions
        if ($flag === 'purge') {
            return 'Tentative';
        } elseif ($flag === 'acl') {
            return 'Tentative';
        }
    }

    // Check ACL permissions for each file in the directory
    $output = shell_exec("find \"$nginx_cache_path\" -exec ls -ld {} + | awk '{print \$1}' | grep -v '+'");
    if (!empty($output)) {
        if ($flag === 'purge') {
            return 'Not Working';
        } elseif ($flag === 'acl') {
            return 'Not Implemented';
        }
    }

    if ($flag === 'purge') {
        return 'Working';
    } elseif ($flag === 'acl') {
        return 'Implemented';
    }
}

// Check required command statuses
function nppp_check_command_status($command) {
    $output = shell_exec("type $command");
    return !empty($output) ? 'Installed' : 'Not Installed';
}

// Check preload action status
function nppp_check_preload_status() {
    $wp_filesystem = nppp_initialize_wp_filesystem();

    if ($wp_filesystem === false) {
        return false; // Return false if WP_Filesystem initialization failed
    }

    $this_script_path = dirname(plugin_dir_path(__FILE__));
    $PIDFILE = rtrim($this_script_path, '/') . '/cache_preload.pid';

    if ($wp_filesystem->exists($PIDFILE)) {
        $pid = intval(nppp_perform_file_operation($PIDFILE, 'read'));

        if ($pid > 0 && posix_kill($pid, 0)) {
            return 'progress';;
        }
    }

    return nppp_check_command_status('wget') === 'Installed' ? 'Working' : 'Not Working';

}

// Check Nginx Cache Path status
function nppp_check_path() {
    $wp_filesystem = nppp_initialize_wp_filesystem();

    if ($wp_filesystem === false) {
        return false; // Return false if WP_Filesystem initialization failed
    }

    $nginx_cache_settings = get_option('nginx_cache_settings');
    $default_cache_path = '/dev/shm/change-me-now';
    $nginx_cache_path = isset($nginx_cache_settings['nginx_cache_path']) ? $nginx_cache_settings['nginx_cache_path'] : $default_cache_path;

     // Check if directory exists
    if (!$wp_filesystem->is_dir($nginx_cache_path)) {
        // Cache Directory does not exist
        return 'Not Found';
    } else {
        return 'Found';
    }
}

// Check shell exec allowed or not, required for plugin
function nppp_shell_exec() {
   // Check allowed to execute shell commands
    $allowed = false;
    // Check if shell_exec is enabled
    if (function_exists('shell_exec')) {
        // Attempt to execute a harmless command
        $output = shell_exec('echo "Test"');

        // Check if the command executed successfully
        if ($output === "Test\n") {
            $allowed = true;
        }
    }

    if ($allowed) {
        return 'Ok';
    } else {
        return 'Not Ok';
    }
}

// Function to get the PHP process owner (website-user)
function nppp_get_website_user() {
    $php_process_owner = '';

    if (function_exists('posix_getpwuid') && function_exists('posix_geteuid')) {
        // Get the user ID of the PHP process owner
        $php_process_uid = posix_geteuid();
        $userInfo = posix_getpwuid($php_process_uid);

        // Get the user NAME of the PHP process owner
        if ($userInfo) {
            $php_process_uid = $userInfo['name'];
        } else {
            $php_process_uid = 'Not Determined';
        }

        $php_process_owner = $php_process_uid;
    }

    // Fail? Try again to find PHP process owner more directly with help of shell
    if (empty($php_process_owner) || $php_process_owner === 'Not Determined') {
        if (defined('ABSPATH')) {
            $wordpressRoot = ABSPATH;
        } else {
            $wordpressRoot = __DIR__;
        }

        // Get the PHP process owner
        $command = "ls -ld " . escapeshellarg($wordpressRoot . '/index.php') . " | awk '{print $3}'";

        // Execute the shell command
        $process_owner = shell_exec($command);

        // Check the PHP process owner if not empty
        if (!empty($process_owner)) {
            $php_process_owner = trim($process_owner);
        } else {
            $php_process_owner = "Not Determined";
        }
    }

    // Return the PHP process owner
    return $php_process_owner;
}

// Function to get webserver user
function nppp_get_webserver_user() {
    // Execute the command to find the web server user
    $webserver_user = shell_exec("ps aux | grep -E '[a]pache|[h]ttpd|[_]www|[w]ww-data|[n]ginx' | grep -v root | head -1 | cut -d\\  -f1");
    // Return the web server user if not empty, otherwise return "Not Determined"
    return !empty($webserver_user) ? trim($webserver_user) : "Not Determined";
}

// Function to get pages in cache count
function nppp_get_in_cache_page_count() {
    $nginx_cache_settings = get_option('nginx_cache_settings');
    $default_cache_path = '/dev/shm/change-me-now';
    $nginx_cache_path = isset($nginx_cache_settings['nginx_cache_path']) ? $nginx_cache_settings['nginx_cache_path'] : $default_cache_path;

    $urls_count = 0;

    // Initialize WordPress filesystem
    $wp_filesystem = nppp_initialize_wp_filesystem();

    if ($wp_filesystem === false) {
        return false;
    }

    // Check for any permisson issue softly
    if (!$wp_filesystem->is_readable($nginx_cache_path) || !$wp_filesystem->is_writable($nginx_cache_path)) {
        return 'Undetermined';
    // Recusive check for permission issues deeply
    } elseif (!nppp_check_permissions_recursive($nginx_cache_path)) {
        return 'Undetermined';
    }

    // Traverse the cache directory and its subdirectories
    $cache_iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($nginx_cache_path, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );

    foreach ($cache_iterator as $file) {
        if ($wp_filesystem->is_file($file->getPathname())) {
            // Read file contents
            $content = $wp_filesystem->get_contents($file->getPathname());

            // Exclude URLs with status 301 Moved Permanently
            if (strpos($content, 'Status: 301 Moved Permanently') !== false) {
                continue;
            }

            // Extract URLs using regex
            if (preg_match('/KEY:\s+httpsGET(.+)/', $content, $matches)) {
                $url = trim($matches[1]);

                // Increment count
                $urls_count++;
            }
        }
    }

    // Return the count of URLs, if no URLs found, return 0
    return $urls_count > 0 ? $urls_count : 0;
}

// Generate HTML for status tab
function nppp_my_status_html() {
    ob_start();
    ?>
    <div class="status-and-nginx-info-container">
        <div id="nppp-status-tab" class="container">
            <header></header>
            <main>
                <section class="status-summary">
                    <h2>Status Summary</h2>
                    <table>
                        <tbody>
                            <tr>
                                <td class="action">
                                    <div class="action-wrapper">PHP-FPM Setup</div>
                                </td>
                                <td class="status" id="npppphpFpmStatus">
                                    <span class="dashicons"></span>
                                    <span><?php echo esc_html(nppp_get_website_user()); ?></span>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                    <div style="height: 20px;"></div>
                    <table>
                        <thead>
                            <tr>
                                <th class="action-header"><span class="dashicons dashicons-admin-generic"></span> Action</th>
                                <th class="status-header"><span class="dashicons dashicons-info"></span> Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td class="action">Purge Action</td>
                                <td class="status" id="nppppurgeStatus">
                                    <span class="dashicons"></span>
                                    <span><?php echo esc_html(nppp_check_acl('purge')); ?></span>
                                </td>
                            </tr>
                            <tr>
                                <td class="action">Preload Action</td>
                                <td class="status" id="nppppreloadStatus">
                                    <span class="dashicons"></span>
                                    <span><?php echo esc_html(nppp_check_preload_status()); ?></span>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </section>
                <section class="system-checks">
                    <h2>System Checks</h2>
                    <table>
                        <thead>
                            <tr>
                                <th class="check-header"><span class="dashicons dashicons-admin-generic"></span> Check</th>
                                <th class="status-header"><span class="dashicons dashicons-info"></span> Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td class="check">PHP-FPM-USER (Website User)</td>
                                <td class="status" id="npppphpProcessOwner">
                                    <span class="dashicons"></span>
                                    <span><?php echo esc_html(nppp_get_website_user()); ?></span>
                                </td>
                            </tr>
                            <tr>
                                <td class="check">WEB-SERVER-USER (Webserver User)</td>
                                <td class="status" id="npppphpWebServer">
                                    <span class="dashicons"></span>
                                    <span><?php echo esc_html(nppp_get_webserver_user()); ?></span>
                                </td>
                            </tr>
                            <tr>
                                <td class="check">Shell_Exec (Required for Plugin)</td>
                                <td class="status" id="npppshellExec">
                                    <span class="dashicons"></span>
                                    <span><?php echo esc_html(nppp_shell_exec()); ?></span>
                                </td>
                            </tr>
                            <tr>
                                <td class="check">Cache Path (Required for Purge)</td>
                                <td class="status" id="npppcachePath">
                                    <span class="dashicons"></span>
                                    <span><?php echo esc_html(nppp_check_path()); ?></span>
                                </td>
                            </tr>
                            <tr>
                                <td class="check">ACLs (Required for Purge)</td>
                                <td class="status" id="npppaclStatus">
                                    <span class="dashicons"></span>
                                    <span><?php echo esc_html(nppp_check_acl('acl')); ?></span>
                                </td>
                            </tr>
                            <tr>
                                <td class="check">wget (Required for Preload)</td>
                                <td class="status" id="npppwgetStatus">
                                    <span class="dashicons"></span>
                                    <span><?php echo esc_html(nppp_check_command_status('wget')); ?></span>
                                </td>
                            </tr>
                            <tr>
                                <td class="check">cpulimit (Optional for Preload)</td>
                                <td class="status" id="npppcpulimitStatus">
                                    <span class="dashicons"></span>
                                    <span><?php echo esc_html(nppp_check_command_status('cpulimit')); ?></span>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </section>
                <section class="cache-status">
                    <h2>Cache Status</h2>
                    <table>
                        <thead>
                            <tr>
                                <th class="check-header"><span class="dashicons dashicons-admin-generic"></span> Check</th>
                                <th class="status-header"><span class="dashicons dashicons-info"></span> Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td class="check">Pages In Cache Count</td>
                                <td class="status" id="npppphpPagesInCache">
                                    <span class="dashicons"></span>
                                    <span><?php echo esc_html(nppp_get_in_cache_page_count()); ?></span>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </section>
            </main>
        </div>
        <div id="nppp-nginx-info" class="container">
            <?php echo do_shortcode('[nppp_nginx_config]'); ?>
        </div>
    </div>
    <?php
    return ob_get_clean();
}

// AJAX handler to fetch shortcode content
function nppp_cache_status_callback() {
    // Check nonce
    if (isset($_POST['_wpnonce'])) {
        $nonce = sanitize_text_field(wp_unslash($_POST['_wpnonce']));
        if (!wp_verify_nonce($nonce, 'cache-status')) {
            wp_die('Nonce verification failed.');
        }
    } else {
        wp_die('Nonce is missing.');
    }

    // Check user capability
    if (!current_user_can('manage_options')) {
        wp_die('You do not have permission to access this page.');
    }

    // Call the shortcode function to get HTML content
    $shortcode_content = nppp_my_status_shortcode();

    // Return the shortcode content
    echo wp_kses_post($shortcode_content);

    // Properly exit to avoid extra output
    wp_die();
}

// Shortcode to display the Status HTML
function nppp_my_status_shortcode() {
    return nppp_my_status_html();
}
