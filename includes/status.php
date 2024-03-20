<?php
/**
 * Status for Nginx FastCGI Cache Purge and Preload
 * Description: This status file shows information about plugin status.
 * Version: 1.0.2
 * Author: Hasan ÇALIŞIR
 * Author Email: hasan.calisir@psauxit.com
 * Author URI: https://www.psauxit.com
 * License: GPL-2.0+
 */

// Check purge action status
// Check ACLs status
function check_acl($flag = '') {
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
        if ($flag === 'purge') {
            return 'Not Working';
        } elseif ($flag === 'acl') {
            return 'Not Implemented';
        }
    }

    // Check if main directory has ACL permissions
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
    $files = $wp_filesystem->dirlist($nginx_cache_path);
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
function check_command_status($command) {
    $output = shell_exec("type $command");
    return !empty($output) ? 'Installed' : 'Not Installed';
}

// Check preload action status
function check_preload_status() {
    return check_command_status('wget') === 'Installed' ? 'Working' : 'Not Working';
}

// Check Nginx Cache Path status
function check_path() {
    $wp_filesystem = initialize_wp_filesystem();

    if ($wp_filesystem === false) {
        return false; // Return false if WP_Filesystem initialization failed
    }

    $nginx_cache_settings = get_option('nginx_cache_settings');
    $default_cache_path = find_user_home_folder() . '/change-me-nginx';
    $nginx_cache_path = isset($nginx_cache_settings['nginx_cache_path']) ? $nginx_cache_settings['nginx_cache_path'] : $default_cache_path;

     // Check if directory exists
    if (!$wp_filesystem->is_dir($nginx_cache_path)) {
        // Cache Directory does not exist
        return 'Not Found';
    } else {
        return 'Found';
    }
}


// Generate HTML for status tab
function my_status_html() {
    ob_start();
    ?>
    <div id="status-tab" class="container">
        <header>
            <h1>Plugin Status</h1>
        </header>
        <main>
            <section class="status-summary">
                <h2>Status Summary</h2>
                <table>
                    <thead>
                        <tr>
                            <th class="action-header">Action</th>
                            <th class="status-header">Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td class="action">Purge Action</td>
                            <td class="status" id="purgeStatus">
                                <span class="dashicons"></span>
                                <span>Working</span>
                            </td>
                        </tr>
                        <tr>
                            <td class="action">Preload Action</td>
                            <td class="status" id="preloadStatus">
                                <span class="dashicons"></span>
                                <span>Not Working</span>
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
                            <th class="check-header">Check</th>
                            <th class="status-header">Status</th>
                        </tr>
                    </thead>
                    <tbody>
                         <tr>
                            <td class="check">Cache Path (Required for Purge)</td>
                            <td class="status" id="cachePath">
                                <span class="dashicons"></span>
                                <span>Exist</span>
                            </td>
                        </tr>
                        <tr>
                            <td class="check">ACLs (Required for Purge)</td>
                            <td class="status" id="aclStatus">
                                <span class="dashicons"></span>
                                <span>Implemented</span>
                            </td>
                        </tr>
                        <tr>
                            <td class="check">wget (Required for Preload)</td>
                            <td class="status" id="wgetStatus">
                                <span class="dashicons"></span>
                                <span>Installed</span>
                            </td>
                        </tr>
                        <tr>
                            <td class="check">cpulimit (Optional for Preload)</td>
                            <td class="status" id="cpulimitStatus">
                                <span class="dashicons"></span>
                                <span>Installed</span>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </section>
        </main>
        <footer>
            <p>&copy; <?php echo esc_html(gmdate('Y')); ?> Nginx FastCGI Cache Purge and Preload</p>
        </footer>
    </div>
    <?php
    return ob_get_clean();
}

// Update status elements
function update_status() {
    ?>
    <script>
        jQuery(document).ready(function($) {
            // Fetch and update nginx cache path status
            var cachePathSpan = document.getElementById("cachePath");
            var cachePath = "<?php echo esc_js(check_path()); ?>";
            cachePathSpan.textContent = cachePath;
            cachePathSpan.style.fontSize = "14px";
            if (cachePath === "Found") {
                cachePathSpan.style.color = "green";
                cachePathSpan.innerHTML = '<span class="dashicons dashicons-yes"></span> Found';
            } else if (cachePath === "Not Found") {
                cachePathSpan.style.color = "red";
                cachePathSpan.innerHTML = '<span class="dashicons dashicons-no"></span> Not Found';
            }

            // Fetch and update purge action status
            var purgeStatusSpan = document.getElementById("purgeStatus");
            var purgeStatus = "<?php echo esc_js(check_acl('purge')); ?>";
            purgeStatusSpan.textContent = purgeStatus;
            purgeStatusSpan.style.fontSize = "14px";
            if (purgeStatus === "Working") {
                purgeStatusSpan.style.color = "green";
                purgeStatusSpan.innerHTML = '<span class="dashicons dashicons-yes"></span> Working';
            } else if (purgeStatus === "Not Working") {
                purgeStatusSpan.style.color = "red";
                purgeStatusSpan.innerHTML = '<span class="dashicons dashicons-no"></span> Not Working';
            } else {
                purgeStatusSpan.style.color = "orange";
                purgeStatusSpan.innerHTML = '<span class="dashicons dashicons-clock"></span> Tentative';
            }

            // Fetch and update ACLs status
            var aclStatusSpan = document.getElementById("aclStatus");
            var aclStatus = "<?php echo esc_js(check_acl('acl')); ?>";
            aclStatusSpan.textContent = aclStatus;
            aclStatusSpan.style.fontSize = "14px";
            if (aclStatus === "Implemented") {
                aclStatusSpan.style.color = "green";
                aclStatusSpan.innerHTML = '<span class="dashicons dashicons-yes"></span> Implemented';
            } else if (aclStatus === "Not Implemented") {
                aclStatusSpan.style.color = "red";
                aclStatusSpan.innerHTML = '<span class="dashicons dashicons-no"></span> Not Implemented';
            } else {
                aclStatusSpan.style.color = "orange";
                aclStatusSpan.innerHTML = '<span class="dashicons dashicons-clock"></span> Not Determined';
            }

            // Fetch and update preload action status
            var preloadStatusSpan = document.getElementById("preloadStatus");
            var preloadStatus = "<?php echo esc_js(check_preload_status()); ?>";
            preloadStatusSpan.textContent = preloadStatus;
            preloadStatusSpan.style.fontSize = "14px";
            if (preloadStatus === "Working") {
                preloadStatusSpan.style.color = "green";
                preloadStatusSpan.innerHTML = '<span class="dashicons dashicons-yes"></span> Working';
            } else if (preloadStatus === "Not Working") {
                preloadStatusSpan.style.color = "red";
                preloadStatusSpan.innerHTML = '<span class="dashicons dashicons-no"></span> Not Working';
            }

            // Fetch and update wget command status
            var wgetStatusSpan = document.getElementById("wgetStatus");
            var wgetStatus = "<?php echo esc_js(check_command_status('wget')); ?>";
            wgetStatusSpan.textContent = wgetStatus;
            wgetStatusSpan.style.fontSize = "14px";
            if (wgetStatus === "Installed") {
                wgetStatusSpan.style.color = "green";
                wgetStatusSpan.innerHTML = '<span class="dashicons dashicons-yes"></span> Installed';
            } else if (wgetStatus === "Not Installed") {
                wgetStatusSpan.style.color = "red";
                wgetStatusSpan.innerHTML = '<span class="dashicons dashicons-no"></span> Not Installed';
            }

            // Fetch and update cpulimit command status
            var cpulimitStatusSpan = document.getElementById("cpulimitStatus");
            var cpulimitStatus = "<?php echo esc_js(check_command_status('cpulimit')); ?>";
            cpulimitStatusSpan.textContent = cpulimitStatus;
            cpulimitStatusSpan.style.fontSize = "14px";
            if (cpulimitStatus === "Installed") {
                cpulimitStatusSpan.style.color = "green";
                cpulimitStatusSpan.innerHTML = '<span class="dashicons dashicons-yes"></span> Installed';
            } else if (cpulimitStatus === "Not Installed") {
                cpulimitStatusSpan.style.color = "red";
                cpulimitStatusSpan.innerHTML = '<span class="dashicons dashicons-no"></span> Not Installed';
            }

            // Add spin effect to icons
            document.querySelectorAll('.status').forEach(status => {
                status.addEventListener('click', () => {
                    status.querySelector('.dashicons').classList.add('spin');
                    setTimeout(() => {
                        status.querySelector('.dashicons').classList.remove('spin');
                    }, 1000);
                });
            });
        });
    </script>
    <?php
}

// AJAX handler to fetch shortcode content
add_action('wp_ajax_my_status_ajax', 'my_status_ajax_callback');
add_action('wp_ajax_nopriv_my_status_ajax', 'my_status_ajax_callback');
function my_status_ajax_callback() {
    // Check nonce
    if (isset($_POST['_wpnonce']) && wp_verify_nonce($_POST['_wpnonce'], 'status_ajax_nonce')) {
        // Call the shortcode function to get HTML content
        $shortcode_content = my_status_shortcode();

        // Return the shortcode content
        echo wp_kses_post($shortcode_content);
        
        // Update status elements
        update_status();
        exit();
    } else {
        // Nonce verification failed, reject the request
        wp_die('Nonce verification failed.', 'Error', array('response' => 403));
    }
}

// Shortcode to display the Status HTML
function my_status_shortcode() {
    return my_status_html();
}
add_shortcode('my_status', 'my_status_shortcode');
